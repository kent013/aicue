<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Billing\TicketSource;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Models\Organization;
use App\Services\Notification\NotificationCenterService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * チケット台帳 (2 フェーズ消費プリミティブ) の唯一の窓口。
 *
 * - 残高 = SUM(未失効 ledger.delta) − SUM(active 予約.amount)。直接デクリメントは書かない
 * - 消費を伴う処理は必ず reserve → (成功) commit / (失敗) release
 * - 全操作 transaction + organizations 行ロック (lockForUpdate) で残高判定の
 *   TOCTOU を防止する (並行 reserve のオーバーセル防止)
 * - reserve TTL 超過は billing:release-stale-reservations cron (releaseStale) が解放する
 * - webhook 由来の付与 (grantMonthly / grantSignupGrant / grantPurchased) と
 *   返金逆仕訳 (clawback) は idempotency_key UNIQUE の冪等 insert で二重計上を防ぐ
 */
class TicketLedgerService
{
    /** reserve の TTL (分)。入口の二重起動・放置予約による残高死蔵を防ぐ */
    private const int RESERVATION_TTL_MINUTES = 30;

    public function __construct(
        private readonly NotificationCenterService $notifications,
    ) {}

    /** チケットを付与する (運用調整の正エントリ。冪等付与は grantMonthly / grantPurchased を使う) */
    public function grant(Organization $organization, int $amount, string $description): TicketLedgerEntry
    {
        Assert::positiveInteger($amount, 'grant の amount は正の整数のみ');

        return DB::transaction(function () use ($organization, $amount, $description): TicketLedgerEntry {
            $this->lockOrganizationRow($organization);

            return $this->appendEntry($organization, $amount, TicketLedgerKind::Grant, null, $description);
        });
    }

    /**
     * サブスク由来の期限付き付与 (invoice.paid の月次付与 / signup grant の下位実装)。
     *
     * $idempotencyKey (UNIQUE) で冪等: 同一キーの再実行 (webhook の event_id 違い再送等) は no-op。
     * $expiresAt = null は無期限。
     */
    public function grantMonthly(
        Organization $organization,
        int $amount,
        ?CarbonImmutable $expiresAt,
        string $idempotencyKey,
        string $description,
    ): void {
        Assert::positiveInteger($amount, 'grantMonthly の amount は正の整数のみ');
        Assert::stringNotEmpty($idempotencyKey);

        $this->insertIdempotent($organization, $idempotencyKey, [
            'delta' => $amount,
            'kind' => TicketLedgerKind::Grant->value,
            'source' => TicketSource::Monthly->value,
            'description' => $description,
            'granted_at' => CarbonImmutable::now(),
            'expires_at' => $expiresAt,
        ]);
    }

    /**
     * 初回 signup grant (「まず触れる」導線の無償チケット)。
     *
     * 通常登録の完了時 (個人組織生成直後) と、Stripe サブスク作成の支払い確定時
     * (invoice.paid, billing_reason=subscription_create) の双方から呼ばれる。
     * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
     *
     * **1 組織につき高々 1 回**の不変条件は、冪等キー ($idempotencyKey) の UNIQUE と、
     * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
     * が DB レベルで原子的に保証する。旧キー (signup_grant:{subId}) 行が既にある組織でも、部分 index が
     * 同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
     *
     * $idempotencyKey は経路を表す `signup_grant:` 接頭辞付きのキーを呼び出し側が渡す
     * (登録経路 = `signup_grant:org:{orgId}` / free 有効化 = `signup_grant:personal:{orgId}`)。
     * 部分 UNIQUE index が述語 `LIKE 'signup_grant:%'` で経路を跨いで org 生涯 1 回に閉じるため、
     * キーの違いは監査上の由来表現であって二重付与の窓にはならない。
     */
    public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
    {
        $count = config('billing.signup_grant_tickets');
        Assert::integer($count, 'config billing.signup_grant_tickets は整数で設定してください');
        Assert::greaterThan($count, 0, 'signup_grant_tickets は 1 以上で設定してください');

        $expiryDays = config('billing.signup_grant_expiry_days');
        Assert::integer($expiryDays, 'config billing.signup_grant_expiry_days は整数で設定してください');
        Assert::greaterThan($expiryDays, 0, 'signup_grant_expiry_days は 1 以上で設定してください');

        $this->grantMonthly(
            $organization,
            $count,
            CarbonImmutable::now()->addDays($expiryDays),
            $idempotencyKey,
            '初回 signup grant',
        );
    }

    /**
     * 買い切りチケット付与 (checkout.session.completed 由来。無期限)。
     *
     * 冪等キーは `purchase:{checkout session id}`。返金逆仕訳の正本キー (payment_intent_id) と
     * 按分分母 (purchaseAmount = 元決済額) を同一エントリに記録する。
     */
    public function grantPurchased(
        Organization $organization,
        int $amount,
        string $stripeSessionId,
        ?string $paymentIntentId = null,
        ?int $purchaseAmount = null,
    ): void {
        Assert::positiveInteger($amount, 'grantPurchased の amount は正の整数のみ');
        Assert::stringNotEmpty($stripeSessionId);

        $this->insertIdempotent($organization, "purchase:{$stripeSessionId}", [
            'delta' => $amount,
            'kind' => TicketLedgerKind::Grant->value,
            'source' => TicketSource::Purchased->value,
            'description' => "チケット購入 (checkout session: {$stripeSessionId})",
            'granted_at' => CarbonImmutable::now(),
            'expires_at' => null,
            'stripe_checkout_session_id' => $stripeSessionId,
            'payment_intent_id' => $paymentIntentId,
            'purchase_amount' => $purchaseAmount,
        ]);
    }

    /**
     * charge.refunded 受信時に買い切りチケットを逆仕訳 (clawback) する。
     *
     * charge.payment_intent → purchased 付与エントリ (正本) を引き、累積返金額 $amountRefunded に
     * 対応する「逆仕訳すべき累積枚数 (target)」から既逆仕訳枚数 (already) を差し引いた delta のみ
     * 計上する。複数回の部分返金・順序逆転・再送に対して冪等 (冪等キー `refund:{PI}:{target}`)。
     *
     * fail-closed: 1 PI が複数 org の購入に一致する異常時は report + no-op (越境逆仕訳を防ぐ)。
     * 明細が引けない (サブスク返金 / 台帳導入前の購入) は no-op。
     */
    public function clawbackPurchasedByPaymentIntent(string $paymentIntentId, int $amountRefunded): void
    {
        Assert::stringNotEmpty($paymentIntentId);
        Assert::greaterThanEq($amountRefunded, 0);

        DB::transaction(function () use ($paymentIntentId, $amountRefunded): void {
            $purchases = TicketLedgerEntry::query()
                ->where('payment_intent_id', $paymentIntentId)
                ->where('source', TicketSource::Purchased)
                ->where('delta', '>', 0)
                ->lockForUpdate()
                ->get();

            if ($purchases->isEmpty()) {
                return; // サブスク返金 / 未正本化 → 逆仕訳対象なし
            }

            // 実運用は 1 PI = 1 purchase。複数一致は異常 (PI 取り違え / データ汚染) で、全件処理すると
            // 別 org の購入まで逆仕訳し得る。fail-closed: report + no-op で運用調査へ回す
            if ($purchases->count() > 1) {
                report(new RuntimeException(
                    "ticket clawback: 単一 payment_intent {$paymentIntentId} に複数の購入明細が一致 (越境防止のため中止)",
                ));

                return;
            }

            $purchase = $purchases->firstOrFail();

            $purchaseAmount = $purchase->purchase_amount;
            if ($purchaseAmount === null) {
                report(new RuntimeException(
                    "ticket clawback: purchase {$purchase->id} に purchase_amount 欠落で按分不可",
                ));

                return;
            }

            $targetClawback = $this->refundedTicketCount($purchaseAmount, $purchase->delta, $amountRefunded);
            if ($targetClawback < 1) {
                return; // floor で 0 枚 (少額部分返金) → 計上なし
            }

            $alreadyClawed = $this->clawedBackCount($purchase->organization_id, $paymentIntentId);
            $delta = $targetClawback - $alreadyClawed;
            if ($delta < 1) {
                return; // 同一 / 古い累積額の再送・順序逆転 → 冪等 no-op
            }

            $organization = $purchase->organization;
            Assert::isInstanceOf($organization, Organization::class);

            $this->insertIdempotent($organization, "refund:{$paymentIntentId}:{$targetClawback}", [
                'delta' => -$delta,
                'kind' => TicketLedgerKind::Clawback->value,
                'source' => TicketSource::Purchased->value,
                'description' => "返金逆仕訳 (payment_intent: {$paymentIntentId})",
                'granted_at' => null,
                'expires_at' => null,
                'stripe_checkout_session_id' => $purchase->stripe_checkout_session_id,
                'payment_intent_id' => $paymentIntentId,
            ]);
        });
    }

    /**
     * 利用可能残高 (= 未失効の台帳合計 − reserved 予約合計)。
     *
     * 期限付き付与は expires_at 到達で合算から外れる。消費 (reserve_commit / clawback) 行は
     * 期限を持たず残るため、失効は「未消費分も含めた全額失効」として保守的に働く
     * (失効前に消費した分だけ残高が下振れし得るが、over-grant にはならない)。
     * バケット (出所×期限) 単位の厳密な失効会計が必要な派生アプリは
     * source / expires_at 列を使って balance を差し替えること。
     */
    public function balance(Organization $organization): int
    {
        $ledgerTotal = (int) TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where(function ($query): void {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', CarbonImmutable::now());
            })
            ->sum('delta');

        $reserved = (int) TicketReservation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', TicketReservationStatus::Reserved)
            ->sum('amount');

        return $ledgerTotal - $reserved;
    }

    /**
     * チケットを予約する (2 フェーズ消費の前半)。
     * 残高不足は InsufficientTicketsException。
     */
    public function reserve(Organization $organization, int $amount): TicketReservation
    {
        Assert::positiveInteger($amount, 'reserve の amount は正の整数のみ');

        return DB::transaction(function () use ($organization, $amount): TicketReservation {
            // 残高判定の直列化点: organizations 行ロックで並行 reserve の TOCTOU を防ぐ
            $this->lockOrganizationRow($organization);

            $balance = $this->balance($organization);
            if ($balance < $amount) {
                throw InsufficientTicketsException::forReserve($amount, $balance);
            }

            $reservation = new TicketReservation;
            // 所有権・状態キーは明示代入 (mass assignment しない)
            $reservation->organization()->associate($organization);
            $reservation->amount = $amount;
            $reservation->status = TicketReservationStatus::Reserved;
            $reservation->expires_at = CarbonImmutable::now()->addMinutes(self::RESERVATION_TTL_MINUTES);
            $reservation->save();

            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: balance() は
            // 「有効台帳合計 − Reserved 拘束」であり、実効残高が減る唯一の消費イベントは reserve
            // (Reserved→Committed の commit は拘束 -amount と台帳 -amount が相殺し balance() 不変)。
            // reserve は org 行ロック下で直列化済みのため、並行 reserve でもクロスを観測するのは
            // ちょうど 1 回 (release/grant で回復して再度跨げば再通知される = 仕様)
            $threshold = config()->integer('billing.ticket_low_balance_threshold');
            $after = $balance - $amount;
            if ($balance >= $threshold && $after < $threshold) {
                // afterCommit: reserve は pipeline の startJob tx 内から savepoint で呼ばれ得るため、
                // 最外層 commit 成立後にのみ通知する (rollback 時は発火しない)
                DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
            }

            return $reservation;
        });
    }

    /** 予約を確定する (台帳に負 delta を記録し、予約を committed にする) */
    public function commit(TicketReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = $this->lockReservationRow($reservation);
            $organization = $locked->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $this->lockOrganizationRow($organization);

            $this->appendEntry(
                $organization,
                -$locked->amount,
                TicketLedgerKind::ReserveCommit,
                $locked,
                "予約 {$locked->id} の消費確定",
            );

            $locked->status = TicketReservationStatus::Committed;
            $locked->save();
        });

        $reservation->refresh();
    }

    /** 予約を解放する (残高拘束を解く。台帳には監査用の 0 行を残す) */
    public function release(TicketReservation $reservation): void
    {
        DB::transaction(function () use ($reservation): void {
            $locked = $this->lockReservationRow($reservation);
            $organization = $locked->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $this->lockOrganizationRow($organization);

            $this->appendEntry(
                $organization,
                0,
                TicketLedgerKind::Release,
                $locked,
                "予約 {$locked->id} の解放",
            );

            $locked->status = TicketReservationStatus::Released;
            $locked->save();
        });

        $reservation->refresh();
    }

    /**
     * TTL (expires_at) 超過の reserved 予約を解放する
     * (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
     *
     * @return int 解放した予約数
     */
    public function releaseStale(): int
    {
        $staleIds = TicketReservation::query()
            ->where('status', TicketReservationStatus::Reserved)
            ->where('expires_at', '<=', CarbonImmutable::now())
            ->pluck('id');

        $released = 0;
        foreach ($staleIds as $id) {
            $reservation = TicketReservation::query()->whereKey($id)->first();
            if ($reservation === null) {
                continue;
            }
            // release 内で行ロック + 状態再検証するため、競合した予約はそこで弾かれる
            try {
                $this->release($reservation);
                $released++;
            } catch (LogicException) {
                // 並行 commit / release 済み: 解放不要
            }
        }

        return $released;
    }

    /** 残高判定・台帳追記の直列化点 (organizations 行ロック) */
    private function lockOrganizationRow(Organization $organization): void
    {
        Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    /** 予約行をロックして reserved 状態であることを検証する (一方向遷移の強制) */
    private function lockReservationRow(TicketReservation $reservation): TicketReservation
    {
        $locked = TicketReservation::query()
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($locked->status !== TicketReservationStatus::Reserved) {
            throw new LogicException(
                "予約 {$locked->id} は reserved ではありません (status: {$locked->status->value})",
            );
        }

        return $locked;
    }

    /**
     * idempotency_key UNIQUE による冪等 insert (webhook 由来の付与・逆仕訳専用)。
     *
     * insertOrIgnore (pgsql: ON CONFLICT DO NOTHING / sqlite: INSERT OR IGNORE / mysql: INSERT IGNORE)
     * で二重付与を skip する。exists()+create()+catch 方式は pgsql で 2 回目の 23505 が
     * 親 TX 全体を abort し後続クエリが連鎖失敗する (webhook 500) ため採らない。
     * Query Builder 直書きは Eloquent の caster / append-only イベントを通らないが、
     * insert のみ (update/delete なし) なので append-only 不変条件は保たれる。
     *
     * @param  array<string, mixed>  $attributes  DB 期待型へ正規化済みの列値 (enum は ->value、日時は Carbon 可)
     */
    private function insertIdempotent(Organization $organization, string $idempotencyKey, array $attributes): void
    {
        $now = CarbonImmutable::now();
        $row = [
            ...$attributes,
            'organization_id' => $organization->getKey(),
            'idempotency_key' => $idempotencyKey,
            // append-only のため updated_at 列は存在しない (created_at のみ)
            'created_at' => $now,
        ];

        // Query Builder 直書きは caster を通らないため日時を DB 期待型 (string) へ正規化する
        $row = array_map(
            static fn (mixed $value): mixed => $value instanceof CarbonImmutable ? $value->toDateTimeString() : $value,
            $row,
        );

        DB::table('ticket_ledger_entries')->insertOrIgnore($row);
    }

    /**
     * 累積返金額に対応する「逆仕訳すべき累積枚数」。全額は全枚数を強制、部分は整数按分 (floor)。
     *
     * zero-division ガードを最優先 (purchaseAmount<=0 で全量逆仕訳になる事故を防ぐ)。
     * 浮動小数を避け intdiv で按分し min(count, …) で上限固定。
     */
    private function refundedTicketCount(int $purchaseAmount, int $count, int $amountRefunded): int
    {
        if ($purchaseAmount <= 0) {
            return 0; // zero-division / 異常明細ガード (最優先)
        }
        if ($amountRefunded >= $purchaseAmount) {
            return $count; // 全額 (以上) 返金 → 全枚数 (端数で 1 枚残る事故を防ぐ)
        }

        return min($count, intdiv($amountRefunded * $count, $purchaseAmount));
    }

    /** 当該 payment_intent に対し既に逆仕訳済みの累積枚数 (負 delta の絶対値合計) */
    private function clawedBackCount(int $organizationId, string $paymentIntentId): int
    {
        $sum = (int) TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('source', TicketSource::Purchased)
            ->where('payment_intent_id', $paymentIntentId)
            ->where('delta', '<', 0)
            ->sum('delta');

        return abs($sum);
    }

    /** 台帳エントリの追記 (append-only。所有権・状態キーは明示代入) */
    private function appendEntry(
        Organization $organization,
        int $delta,
        TicketLedgerKind $kind,
        ?TicketReservation $reservation,
        string $description,
    ): TicketLedgerEntry {
        $entry = new TicketLedgerEntry;
        $entry->organization()->associate($organization);
        $entry->delta = $delta;
        $entry->kind = $kind;
        $entry->reservation()->associate($reservation);
        $entry->description = $description;
        $entry->save();

        return $entry;
    }
}
