<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\TicketBalanceDto;
use App\Enums\Billing\TicketCommitResult;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketReservationStatus;
use App\Enums\Billing\TicketSource;
use App\Exceptions\Billing\InsufficientTicketsException;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Billing\TicketReservation;
use App\Models\Organization;
use App\Services\Notification\NotificationCenterService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;
use RuntimeException;
use Webmozart\Assert\Assert;

/**
 * チケット台帳 (2 フェーズ消費プリミティブ) の唯一の窓口。
 *
 * - 残高は **出所 (source) ごとのバケット会計**。バケットは
 *   monthly (`source = 'monthly'`) と purchased (`source = 'purchased' OR source IS NULL`) の 2 つ。
 *   `source IS NULL` 行 (P5 以前の消費行 / 手動 grant / adjustment / release) は
 *   purchased へ畳む (いずれも無期限で寿命特性が一致。両バケットから落とすと過去消費が
 *   帳消しになり over-grant する)
 * - 各バケットは `expires_at IS NULL OR expires_at > now` の行のみ合算する。消費行 (reserve_commit)
 *   は**消費した grant と同じ expires_at を載せる**ため、失効時に +grant と −consume が同時に
 *   合算から落ちる (「全額失効」近似が無い)
 * - 直接デクリメントは書かない。消費を伴う処理は必ず reserve → (成功) commit / (失敗) release
 * - 全操作 transaction + organizations 行ロック (lockForUpdate) で残高判定の
 *   TOCTOU を防止する (並行 reserve のオーバーセル防止)
 * - reserve TTL 超過と失効 monthly hold は billing:release-stale-reservations cron
 *   (releaseStale) が解放する
 * - webhook 由来の付与 (grantMonthly / grantSignupGrant / grantPurchased) と
 *   返金逆仕訳 (clawback) は idempotency_key UNIQUE の冪等 insert で二重計上を防ぐ
 * - commit は **commit-wins**: reserve TTL 超過や stale releaser 先着でも生存 hold は課金する
 *   (二重課金は `consume:{reservationId}` の UNIQUE が防ぐ。課金の真実源は台帳)
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
     * 付与契機は**プラン有効化時のみ** (P6/F2): free = PersonalPlanService::activate /
     * paid = customer.subscription.created (SubscriptionService::grantSignupInitialTickets)。
     * 登録 (CreateNewUser) と invoice.paid はこの経路を呼ばない。
     * 枚数は config('billing.signup_grant_tickets')、期限は now + config('billing.signup_grant_expiry_days') 日。
     *
     * **1 組織につき高々 1 回**の不変条件は、呼び出し側が先取する marker
     * (organizations.signup_tickets_granted_at) を主とし、冪等キー ($idempotencyKey) の UNIQUE と
     * ticket_ledger_entries の部分 UNIQUE index (organization_id WHERE idempotency_key LIKE 'signup_grant:%')
     * が DB レベルで原子的に保証する (保険)。旧キー (signup_grant:org:{orgId}) 行が既にある組織でも、
     * 部分 index が同一述語でカバーするため insertOrIgnore が二重付与を弾く (アプリ層の存在チェックは不要)。
     *
     * $idempotencyKey は経路を表す `signup_grant:` 接頭辞付きのキーを呼び出し側が渡す
     * (free 有効化 = `signup_grant:personal:{orgId}` / paid = `signup_grant:{stripeSubId}`)。
     * 部分 UNIQUE index が述語 `LIKE 'signup_grant:%'` で経路を跨いで org 生涯 1 回に閉じるため、
     * キーの違いは監査上の由来表現であって二重付与の窓にはならない。
     */
    public function grantSignupGrant(Organization $organization, string $idempotencyKey): void
    {
        // 接頭辞は部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と対応する契約。外れたキーで
        // 付与すると「org 生涯 1 回」の DB 保証をすり抜けるため fail-closed で停止する。
        Assert::stringNotEmpty($idempotencyKey);
        Assert::startsWith($idempotencyKey, 'signup_grant:', 'signup grant の冪等キーは signup_grant: で始めてください');

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
     * 表示用の per-source 残高。
     *
     * monthlyRemaining / purchasedRemaining は出所ごとの生残高を max(…, 0) で clamp した
     * **表示値** (hold は控除しない)。activeReservations は Reserved 予約の拘束枚数
     * (SUM(amount)。legacy 行も計上する保守側)。
     *
     * **判定 (与信・閾値) には使わないこと** — clamp が返金逆仕訳による負残高を隠すため、
     * 判定に使うと誤判定する。判定は availableTrueBalance() を使う。
     */
    public function balance(Organization $organization): TicketBalanceDto
    {
        $now = CarbonImmutable::now();

        $monthly = $this->sumBalance($organization, TicketSource::Monthly, $now);
        $purchased = $this->sumBalance($organization, TicketSource::Purchased, $now);

        // 拘束「枚数」。sumActiveHolds と完全に同一条件で集計する (与信の単一真実源)。
        // reserve TTL 切れでも Reserved は枠を保持し (commit-wins と対称)、失効 monthly hold のみ
        // 除外する。expires_at>now ガードは付けない (30 分超ジョブ中の同枠二重予約 = オーバーセル防止)
        $activeReservations = (int) TicketReservation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', TicketReservationStatus::Reserved)
            ->whereNot(fn (Builder $query) => $this->expiredMonthlyHoldCondition($query, $now))
            ->sum('amount');

        $nextExpire = TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('delta', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->orderBy('expires_at')
            ->value('expires_at');

        return new TicketBalanceDto(
            monthlyRemaining: max($monthly, 0),
            purchasedRemaining: max($purchased, 0),
            activeReservations: $activeReservations,
            nextExpireAt: $nextExpire instanceof CarbonInterface
                ? CarbonImmutable::instance($nextExpire)->toIso8601String()
                : null,
        );
    }

    /**
     * 与信・判定用の真値残高。出所ごとに「生残高 (負許容) − active 予約」を max(…, 0) して
     * から合算するため **戻り値は常に 0 以上**。monthly の余剰が purchased の負 (返金債務) を
     * 埋めない / その逆もしない真値判定で、reserve() の availableMonthly + availablePurchased と
     * 同一意味論。
     *
     * **この契約 (非負性 + per-source clamp 後の合算) には P8a のオートリチャージが依存する** —
     * 閾値判定と数量確定 (quantity = max_count − balance) の双方がこの真値を使い、非負性が
     * quantity <= max_count (同意上限の不変条件) の根拠になる。変更時は P8a 側の契約も見直すこと。
     *
     * UI 表示には balance() を使うこと (表示 DTO は clamp 済みで、判定に使うと負残高で誤判定する)。
     */
    public function availableTrueBalance(Organization $organization): int
    {
        $now = CarbonImmutable::now();
        [$availableMonthly, $availablePurchased] = $this->availableBySource($organization, $now);

        return $availableMonthly + $availablePurchased;
    }

    /**
     * チケットを予約する (2 フェーズ消費の前半)。
     *
     * 消費優先順位は monthly (期限付き = 先に失効する) → purchased (無期限)。予約時に
     * 「どの出所をどの期限で消費するか」を consume_source / consume_expires_at へ固定し、
     * commit は再探索しない。残高不足は InsufficientTicketsException。
     */
    public function reserve(Organization $organization, int $amount): TicketReservation
    {
        Assert::positiveInteger($amount, 'reserve の amount は正の整数のみ');

        return DB::transaction(function () use ($organization, $amount): TicketReservation {
            // 残高判定の直列化点: organizations 行ロックで並行 reserve の TOCTOU を防ぐ
            $this->lockOrganizationRow($organization);

            $now = CarbonImmutable::now();
            [$availableMonthly, $availablePurchased] = $this->availableBySource($organization, $now);

            // 予約行は単一 consume_source を持つ (source ごとの分割配賦をしない) ため、実際に
            // 賄える容量は **max 側**。sum 形にすると「どちらの source も単独では amount を
            // 賄えない」ケースで選んだ source を超過消費し、clamp がそれを隠して最大 amount−1 枚の
            // タダ配りになる (aigenba は amount=1 固定のため sum 形と max 形が同値)
            $capacity = max($availableMonthly, $availablePurchased);
            if ($capacity < $amount) {
                throw InsufficientTicketsException::forReserve($amount, $capacity);
            }

            $consumeSource = $availableMonthly >= $amount ? TicketSource::Monthly : TicketSource::Purchased;
            // monthly は最短の生きた月次期限を境界にする。AI-CUE には無期限 monthly grant
            // (BughuntBillingSeeder / monthly_ticket_grant を戻した場合の invoice.paid) が実在するため
            // null を許容する (null = 無期限 monthly からの消費 = 失効しない hold)
            $consumeExpiresAt = $consumeSource === TicketSource::Monthly
                ? $this->nearestMonthlyExpiry($organization, $now)
                : null;

            $reservation = new TicketReservation;
            // 所有権・状態キーは明示代入 (mass assignment しない)
            $reservation->organization()->associate($organization);
            $reservation->amount = $amount;
            $reservation->status = TicketReservationStatus::Reserved;
            $reservation->expires_at = $now->addMinutes(self::RESERVATION_TTL_MINUTES);
            $reservation->consume_source = $consumeSource;
            $reservation->consume_expires_at = $consumeExpiresAt;
            $reservation->save();

            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: 実効残高が減る唯一の
            // 消費イベントは reserve (Reserved→Committed の commit は拘束 -amount と台帳 -amount が
            // 相殺し実効残高は不変)。reserve は org 行ロック下で直列化済みのため、並行 reserve でも
            // クロスを観測するのはちょうど 1 回 (release/grant で回復して再度跨げば再通知 = 仕様)
            $balance = $availableMonthly + $availablePurchased; // = availableTrueBalance と同一意味論
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

    /**
     * 予約を確定する (台帳に負 delta を記録し、予約を committed にする)。
     *
     * **commit-wins**: 完了時は必ず課金する。reserve TTL 超過 (30 分超ジョブ) でも、stale releaser
     * が先着で Released 化していても、生存 hold は消費行を計上して確定する (status は一方向遷移を
     * 壊さないため Released のまま据え置き、課金は台帳が真実源)。reserve TTL は「reserve 入口の
     * 二重起動防止」専用と再定義し、二重課金は `consume:{reservationId}` の UNIQUE が防ぐ。
     *
     * 例外は失効 monthly hold (consume_expires_at 経過) のみで、これは課金せず Released に倒して
     * ReleasedExpired を返す (stale job の実行タイミングに依らず決定的 no-charge)。
     * **戻り値は可観測性のためのもので、呼び出し側は分岐に使わない**。
     */
    public function commit(TicketReservation $reservation): TicketCommitResult
    {
        $result = DB::transaction(function () use ($reservation): TicketCommitResult {
            // status guard を撤去 (commit-wins)。行ロックは維持する
            $locked = $this->lockReservationRow($reservation, requireReserved: false);

            if ($locked->status === TicketReservationStatus::Committed) {
                return TicketCommitResult::AlreadyCommitted; // 冪等 no-op
            }

            $organization = $locked->organization;
            Assert::isInstanceOf($organization, Organization::class);
            $this->lockOrganizationRow($organization);

            $now = CarbonImmutable::now();

            if ($this->isExpiredMonthlyHold($locked, $now)) {
                if ($locked->status === TicketReservationStatus::Reserved) {
                    $locked->status = TicketReservationStatus::Released;
                    $locked->save();
                    Log::warning('ticket commit: monthly hold expired at commit, released without charge', [
                        'reservation_id' => $locked->id,
                        'organization_id' => $locked->organization_id,
                        'consume_expires_at' => $locked->consume_expires_at?->toIso8601String(),
                        'committed_at' => $now->toIso8601String(),
                    ]);
                } else {
                    // stale releaser が先に Released 化済 (= 消費行は元々無い)。可観測性のため記録
                    Log::info('ticket commit: monthly hold already released as expired, no charge', [
                        'reservation_id' => $locked->id,
                        'organization_id' => $locked->organization_id,
                    ]);
                }

                return TicketCommitResult::ReleasedExpired; // 台帳行を書かない (決定的 no-charge)
            }

            $source = $locked->consume_source ?? TicketSource::Monthly; // legacy 既定
            $expiresAt = $this->consumeExpiresAtFor($locked, $source);

            // 消費行に「消費した grant と同じ expires_at」を載せる。バケット失効時に
            // +grant と −consume が同時に合算から落ちる (「全額失効」近似の解消)
            $inserted = $this->insertIdempotent($organization, "consume:{$locked->id}", [
                'delta' => -$locked->amount,
                'kind' => TicketLedgerKind::ReserveCommit->value,
                'source' => $source->value,
                'reservation_id' => $locked->getKey(),
                'description' => "予約 {$locked->id} の消費確定",
                'granted_at' => null,
                'expires_at' => $expiresAt,
            ]);

            if ($inserted === 0) {
                // Committed を返すのに消費行が書かれなかった = 既存 consume 行が存在。冪等としては
                // 正しい (二重課金しない) が、不整合検知のため可観測化する
                Log::warning('ticket commit: consume ledger already existed, no consume entry written', [
                    'reservation_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                ]);
            }

            if ($locked->status === TicketReservationStatus::Reserved) {
                $locked->status = TicketReservationStatus::Committed;
                $locked->save();
            } else {
                // stale releaser に先着 Released された生存予約。commit-wins で課金済。
                // 一方向遷移 (Released→Committed) を壊さず status は据え置き、課金は台帳で確定
                Log::info('ticket commit: released-then-charged (stale release before completion)', [
                    'reservation_id' => $locked->id,
                    'organization_id' => $locked->organization_id,
                ]);
            }

            return TicketCommitResult::Committed;
        });

        $reservation->refresh();

        return $result;
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
     * TTL (expires_at) 超過、または失効 monthly hold (consume_expires_at 経過) の reserved 予約を
     * 解放する (routes/console.php の billing:release-stale-reservations が 5 分毎に実行)。
     *
     * 失効 monthly hold を含めるのは、消費元の grant が既に失効している hold を拘束として
     * 残すと翌期間の残高を侵食するため (commit-wins も当該 hold は no-charge にする)。
     *
     * @return int 解放した予約数
     */
    public function releaseStale(): int
    {
        $now = CarbonImmutable::now();

        $staleIds = TicketReservation::query()
            ->where('status', TicketReservationStatus::Reserved)
            ->where(function (Builder $query) use ($now): void {
                $query->where('expires_at', '<=', $now)
                    ->orWhere(fn (Builder $expired) => $this->expiredMonthlyHoldCondition($expired, $now));
            })
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

    /**
     * 予約行をロックする。
     *
     * $requireReserved = true (既定) は reserved 状態を検証する (release の一方向遷移の強制)。
     * commit は commit-wins のため false で呼び、status 検査を行わない
     * (二重課金は consume:{id} の UNIQUE が防ぐ)。
     */
    private function lockReservationRow(TicketReservation $reservation, bool $requireReserved = true): TicketReservation
    {
        $locked = TicketReservation::query()
            ->whereKey($reservation->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        if ($requireReserved && $locked->status !== TicketReservationStatus::Reserved) {
            throw new LogicException(
                "予約 {$locked->id} は reserved ではありません (status: {$locked->status->value})",
            );
        }

        return $locked;
    }

    /**
     * 出所ごとの利用可能枚数 (生残高 − active hold を出所ごとに clamp)。
     *
     * monthly の余剰が purchased の負 (返金債務) を埋めない / その逆もしない。
     * reserve() / availableTrueBalance() の単一定義点。
     *
     * @return array{int, int} [availableMonthly, availablePurchased]
     */
    private function availableBySource(Organization $organization, CarbonImmutable $now): array
    {
        $monthly = $this->sumBalance($organization, TicketSource::Monthly, $now);
        $purchased = $this->sumBalance($organization, TicketSource::Purchased, $now);

        return [
            max($monthly - $this->sumActiveHolds($organization, TicketSource::Monthly, $now), 0),
            max($purchased - $this->sumActiveHolds($organization, TicketSource::Purchased, $now), 0),
        ];
    }

    /**
     * 出所バケットの生残高 (未失効行の delta 合計。負を許容)。
     *
     * purchased バケットは `source IS NULL` 行を畳み込む。AI-CUE の台帳には出所を持たない行
     * (P5 以前の消費行 / 手動 grant / adjustment / release) が既存し、台帳は append-only で
     * backfill できないため。両バケットから落とすと過去消費が帳消しになり over-grant する
     * (null 行はいずれも無期限で purchased と寿命特性が一致する)。
     */
    private function sumBalance(Organization $organization, TicketSource $source, CarbonImmutable $now): int
    {
        return (int) TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where(function (Builder $query) use ($source): void {
                $query->where('source', $source);
                if ($source === TicketSource::Purchased) {
                    $query->orWhereNull('source');
                }
            })
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->sum('delta');
    }

    /**
     * 当該出所を消費する active hold の拘束枚数。
     *
     * reserve TTL 切れ (expires_at <= now) でも Reserved である限り枠を保持する: commit-wins は
     * TTL 超過でも課金するため、与信側で枠を再開放すると 30 分超ジョブ中に同じ枠が二重予約され
     * 両方 commit でオーバーセルになる。枠の解放は releaseStale の Released 化に委ねる。
     * 失効 monthly hold のみ除外する (grant 自体が消えており commit-wins も no-charge のため)。
     *
     * legacy 行 (consume_source = null) はどちらの出所にも計上されない (aigenba verbatim)。
     * その結果 legacy 行が reserve を拘束しない窓が TTL 30 分だけ開くが、balance() の
     * activeReservations は legacy も計上するため表示は保守側になる。
     */
    private function sumActiveHolds(Organization $organization, TicketSource $source, CarbonImmutable $now): int
    {
        return (int) TicketReservation::query()
            ->where('organization_id', $organization->getKey())
            ->where('status', TicketReservationStatus::Reserved)
            ->where('consume_source', $source)
            ->whereNot(fn (Builder $query) => $this->expiredMonthlyHoldCondition($query, $now))
            ->sum('amount');
    }

    /**
     * 「失効 monthly hold」の PHP 述語。query 版 expiredMonthlyHoldCondition と同一定義を共有し、
     * commit / hold 集計 / releaseStale の判定を揃える。
     *
     * legacy 行 (consume_source = null) は先頭で false になる。
     * consume_source = monthly かつ consume_expires_at = null は「無期限 monthly からの消費」で、
     * 失効しない (AI-CUE には無期限 monthly grant が実在するため空き枝をここに割り当てる)。
     */
    private function isExpiredMonthlyHold(TicketReservation $reservation, CarbonImmutable $now): bool
    {
        if ($reservation->consume_source !== TicketSource::Monthly) {
            return false;
        }
        if ($reservation->consume_expires_at === null) {
            return false;
        }

        return $reservation->consume_expires_at->lessThanOrEqualTo($now);
    }

    /**
     * query 版「失効 monthly hold」条件。isExpiredMonthlyHold と同一定義。
     *
     * whereNotNull で確定 boolean にする (NULL 伝播で whereNot が 3 値論理 NULL になり
     * legacy 行が誤って除外される事故を防ぐ)。
     *
     * @param  Builder<TicketReservation>  $query
     */
    private function expiredMonthlyHoldCondition(Builder $query, CarbonImmutable $now): void
    {
        $query->where('consume_source', TicketSource::Monthly->value)
            ->whereNotNull('consume_expires_at')
            ->where('consume_expires_at', '<=', $now);
    }

    /**
     * 生きている (未失効の) monthly 付与のうち最短の失効時刻。無期限のみなら null。
     *
     * **既知窓 (設計上の残余リスク。変更は設計改訂事項)**: 消費境界を 1 値で固定するため、
     * 生きた有限期限 monthly grant が 2 本以上あり期限が異なると、消費行の expires_at が実際の
     * 供給元と一致しない。最短期限の到達時に消費行が grant より多く落ちて over-grant が残り
     * (最大 `amount − 最短期限バケットの残高` 枚)、最短期限を跨ぐ長時間ジョブの commit は残高が
     * 潤沢でも ReleasedExpired (no-charge) になる。窓を閉じるには expiry 粒度の分割配賦
     * (consume_monthly_amount) が要るが、これは v1 の発明として設計で撤回済み。
     *
     * **現行は構造的に到達不能**: D28 で全 tier の monthly_ticket_grant = 0
     * (PlanSeederPriceInvariantTest が pin) のため、有限期限の monthly は org 生涯 1 回の
     * signup grant のみ。BughuntBillingSeeder の 100 枚は無期限で本メソッドの対象外。
     * **Filament PlanResource で monthly_ticket_grant を 1 以上へ戻すと窓が開く** ので、
     * その際は本メソッドの契約から見直すこと。挙動は TicketBalanceAccountingTest の
     * 「[既知窓]」2 本が機械的に固定している。
     */
    private function nearestMonthlyExpiry(Organization $organization, CarbonImmutable $now): ?CarbonImmutable
    {
        $value = TicketLedgerEntry::query()
            ->where('organization_id', $organization->getKey())
            ->where('source', TicketSource::Monthly)
            ->where('delta', '>', 0)
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', $now)
            ->orderBy('expires_at')
            ->value('expires_at');

        return $value instanceof CarbonInterface ? CarbonImmutable::instance($value) : null;
    }

    /**
     * 消費行に載せる失効境界。
     *
     * monthly は予約時に固定した consume_expires_at をそのまま使う (再探索しない。
     * null = 無期限 monthly)。legacy 行 (consume_source = null → monthly 既定) は予約 TTL を
     * 境界として一回限り採用し、null-expiry の不滅ゴーストを作らない。purchased は無期限 (null)。
     */
    private function consumeExpiresAtFor(TicketReservation $reservation, TicketSource $source): ?CarbonImmutable
    {
        if ($source !== TicketSource::Monthly) {
            return null;
        }

        if ($reservation->consume_source === null) {
            Log::warning('ticket commit: legacy reservation without consume_source', [
                'reservation_id' => $reservation->id,
                'organization_id' => $reservation->organization_id,
            ]);

            return $reservation->expires_at;
        }

        return $reservation->consume_expires_at;
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
     * @return int 実際に挿入された行数 (0 = 冪等 skip)
     */
    private function insertIdempotent(Organization $organization, string $idempotencyKey, array $attributes): int
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

        return DB::table('ticket_ledger_entries')->insertOrIgnore($row);
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
