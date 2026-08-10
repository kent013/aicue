<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\Enums\Billing\BillingRetentionTarget;
use App\Enums\Billing\TicketLedgerKind;
use App\Enums\Billing\TicketSource;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
 *
 * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
 * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
 * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
 * **繰越行 1 行**へ置換する。
 *
 * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
 *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
 *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
 *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
 *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
 *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
 *
 * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
 *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
 *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
 *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
 *   (`organization_id` / `source` / `expires_at`) だけである。
 *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
 *
 * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
 *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
 *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
 *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
 *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
 *
 * ★**保証しないもの (誇張しない)**:
 *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
 *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
 *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
 *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
 *     畳み込み前の行までである (signup grant の**正本**は
 *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
 *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
 *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
 *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
 */
final class TicketLedgerCarryForwardService
{
    /** 繰越行の冪等キーの接頭辞。 */
    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';

    /**
     * 繰越行の説明。
     *
     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
     *   「個別取引が復元不能」という要件は満たす。
     */
    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';

    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';

    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
    private const string NULL_TOKEN = 'null';

    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
    public function countExpired(CarbonImmutable $threshold): int
    {
        return TicketLedgerEntry::query()
            ->where('created_at', '<=', $threshold)
            ->count();
    }

    /**
     * 繰越行の冪等キー。
     *
     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
     *
     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
     *   なので、キーは入力である閾値で決める。
     *
     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
     * 接頭辞が異なるため衝突しない。
     */
    public static function idempotencyKeyFor(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): string {
        return implode(':', [
            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
            (string) $organizationId,
            $source === null ? self::NULL_TOKEN : $source->value,
            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
            $threshold->utc()->format(self::KEY_TIME_FORMAT),
        ]);
    }

    /**
     * 保持期限より古い台帳行を組織ごとに畳み込む。
     *
     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
     */
    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        $candidates = $this->countExpired($threshold);
        $processed = 0;
        $unexpectedFailures = 0;

        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
            try {
                $processed += DB::transaction(
                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
                );
            } catch (Throwable $e) {
                $unexpectedFailures++;
                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
                Log::warning('ticket ledger carry forward failed', [
                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
                    'organization_id' => $organization->getKey(),
                    'error_class' => $e::class,
                ]);
            }
        }

        return new BillingRetentionPurgeResultDto(
            target: BillingRetentionTarget::TicketLedgerEntry,
            candidates: $candidates,
            processed: $processed,
            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
            // (「安全のため残した」ではなく「決着できなかった」である)。
            failClosed: 0,
            unexpectedFailures: $unexpectedFailures,
            expiredRemaining: $this->countExpired($threshold),
        );
    }

    /**
     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
     *
     * @return Collection<int, Organization>
     */
    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
    {
        return Organization::query()
            ->whereHas(
                'ticketLedgerEntries',
                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
     *
     * @return int 畳み込んだ (置換で消えた) 行数
     */
    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
    {
        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
        // (畳み込みの最中に同じ組織の残高が動かないようにする)
        Organization::query()
            ->whereKey($organization->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $organizationId = $organization->getKey();
        if (! is_int($organizationId)) {
            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
        }

        $processed = 0;
        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
            $processed += $this->carryForwardGroup(
                $organizationId,
                $group->source,
                $group->expires_at,
                $threshold,
            );
        }

        return $processed;
    }

    /**
     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
     *
     * @return Collection<int, TicketLedgerEntry>
     */
    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
    {
        return TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->select(['source', 'expires_at'])
            ->distinct()
            ->get();
    }

    /**
     * 1 group を繰越行へ置換する。
     *
     * @return int 置換で消えた行数
     */
    private function carryForwardGroup(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): int {
        // **件数・合計・前回終端は 1 文で取る**。3 回に分けると文ごとに snapshot が変わり
        // (READ COMMITTED)、「合計には入っていないが件数には入っている」行が生まれうる。
        $aggregate = $this->aggregateGroup($organizationId, $source, $expiresAt, $threshold);
        $total = $aggregate['total'];
        $through = $this->resolveThrough($aggregate['previousThrough'], $threshold);

        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
        if ($total !== 0) {
            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
                'organization_id' => $organizationId,
                'delta' => $total,
                'kind' => TicketLedgerKind::CarryForward->value,
                'source' => $source?->value,
                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
                'reservation_id' => null,
                'description' => self::CARRY_FORWARD_DESCRIPTION,
                'granted_at' => null,
                'stripe_checkout_session_id' => null,
                'stripe_invoice_id' => null,
                'payment_intent_id' => null,
                'purchase_amount' => null,
                // --- 残高の粒度と集約終端 ---
                'expires_at' => $expiresAt?->toDateTimeString(),
                'carried_forward_through' => $through->toDateTimeString(),
                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
                'created_at' => CarbonImmutable::now()->toDateTimeString(),
            ]);

            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
            if ($inserted !== 1) {
                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
            }
        }

        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
        $deleted = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();

        // **集計した集合と削除した集合が一致することを確認する**。
        // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
        // ロックを取らない冪等 insert であり、backfill / 取り込みも同様)。集計と削除の間に
        // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を削除が巻き込む** =
        // その枚数ぶん残高が消える。件数の不一致で検出し、トランザクションごと巻き戻す。
        if ($deleted !== $aggregate['rows']) {
            throw new RuntimeException(
                '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
            );
        }

        return $deleted;
    }

    /**
     * group の件数・合計・前回終端を **1 文で** 取る。
     *
     * 分けて発行すると文ごとに snapshot が変わる (READ COMMITTED) ため、
     * 「合計には入っていないが件数には入っている」行が生まれ、残高保存の検査そのものが壊れる。
     *
     * @return array{rows: int, total: int, previousThrough: string|null}
     */
    private function aggregateGroup(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): array {
        $row = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(delta), 0) AS delta_total, MAX(carried_forward_through) AS previous_through')
            ->first();

        if (! $row instanceof stdClass) {
            throw new RuntimeException('台帳 group の集計に失敗しました (畳み込みを中止する)');
        }

        Assert::numeric($row->row_count);
        Assert::numeric($row->delta_total);
        Assert::nullOrString($row->previous_through);

        return [
            'rows' => (int) $row->row_count,
            'total' => (int) $row->delta_total,
            'previousThrough' => $row->previous_through,
        ];
    }

    /**
     * この繰越が集約した期間の終端。
     *
     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
     * 単調に進むことを保証する (保持年数を延ばすと閾値は過去へ動くため、閾値をそのまま
     * 採ると集約済みの範囲を過小申告することになる)。
     */
    private function resolveThrough(?string $previous, CarbonImmutable $threshold): CarbonImmutable
    {
        if ($previous === null || $previous === '') {
            return $threshold;
        }

        $parsed = CarbonImmutable::parse($previous);

        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
    }

    /**
     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
     *
     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
     *   「どこで消しているか」をコードで見えるようにする。
     */
    private function groupQuery(
        int $organizationId,
        ?TicketSource $source,
        ?CarbonImmutable $expiresAt,
        CarbonImmutable $threshold,
    ): QueryBuilder {
        $query = DB::table('ticket_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold);

        if ($source === null) {
            $query->whereNull('source');
        } else {
            $query->where('source', $source->value);
        }

        if ($expiresAt === null) {
            $query->whereNull('expires_at');
        } else {
            $query->where('expires_at', $expiresAt);
        }

        return $query;
    }
}
