# Round 2: Round 1 指摘への対応

Round 1 の全体判定は CHANGES_REQUESTED (Critical 0 / Warning 2 / Suggestion 1) でした。
3 件すべてに対応しました。対応マトリクスと差分を送ります。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex 全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 2 / Suggestion 1)

## [Warning] `carryForwardGroup()` の集計対象と削除対象が固定されていない

- 判断: **対応する** (指摘は正しい)
- 根拠: `organizations` 行ロックは台帳への insert を止めない。`grantMonthly` /
  `grantPurchased` / `grantAutoRecharge` は**ロックを取らない冪等 insert** であり、
  backfill / 取り込みも同様である。READ COMMITTED では文ごとに snapshot が変わるため、
  `sum()` と `delete()` の間に `created_at <= 閾値` の行が commit されると
  **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
  PR-C2 の最重要不変条件 (残高を 1 枚も動かさない) に直接触れる。
- 対応内容 (3 点):
  1. **件数・合計・前回終端を 1 文で取る** (`aggregateGroup()` の
     `COUNT(*) / COALESCE(SUM(delta),0) / MAX(carried_forward_through)`)。
     3 回に分けると「合計には入っていないが件数には入っている」行が生まれ、
     検査そのものが壊れるため。
  2. **削除件数と集計件数の一致を検査**し、不一致ならトランザクションごと巻き戻す
     (`$deleted !== $aggregate['rows']` で `RuntimeException`)。
     ID 集合を固定する案 (`whereIn('id', …)`) は採らなかった —
     `ModelDirectFetchInvariantTest` の主キー同一性クエリの母集団に入り、
     目録登録という別の摩擦を課金バッチに持ち込むことになる。
     件数一致検査は同じ窓を fail-closed で閉じ、主キー述語を増やさない。
  3. **決定的な挙動テストを追加**
     (`集計の後に古い行が割り込んだら fail-closed`)。繰越行の INSERT を `DB::listen` で
     観測した瞬間に「閾値より古い行」を差し込み、`unexpectedFailures = 1` /
     `processed = 0` / 元の残高が 1 枚も減らないことを固定した。
     mutation で guard を外すと赤くなることも実測した (MU11)。

## [Warning] `TicketLedgerKind::CarryForward` 追加に対する TS 側確認の証跡が無い

- 判断: **対応する** (証跡を機械固定へ格上げする)
- 根拠: 実読では `resources/js` に台帳 kind の対応型も表示分岐も存在せず
  (`ledger` / `reserve_commit` / `clawback` の grep が 0 件)、`label()` の呼び出し元も 0 件で
  あった。よって TS 同期テストの**追加は不要**だが、「確認した」が差分に残らないのは
  指摘のとおりである。散文で書くより機械で固定する方が腐らない。
- 対応内容: `TicketLedgerReaderInventoryTest` に 2 検査を追加した。
  - 検査 7: `resources/js` (ts / svelte) に `TicketLedgerKind` / `reserve_commit` /
    `carry_forward` が現れないことを deny-by-default で固定する。
    フロントへ持ち込むなら PHP enum ⇔ TS union の同期テストを同時に足させる。
    空振り検知として `types/manual.ts` に `export type` が実在することも見る。
  - 検査 8: 全 case が非空の表示ラベルを持ち、case 数を**現在値ちょうど** (6) で pin する
    (case を足したら必ずこの数字を書き換える = 表示分岐を見直す契機になる)。

## [Suggestion] `TicketLedgerEntryFactory::legacy()` のコメントが誤読を招く

- 判断: **対応する**
- 根拠: 指摘のとおり。`source IS NULL` は**表示残高の集計では purchased バケットに含まれる**
  が、**畳み込みでは独立した group** として扱う。1 行のコメントで両方を混同させていた。
- 対応内容: docblock を「表示残高では purchased に含まれるが、畳み込み group としては
  null のまま扱う」と両側を書き分ける形へ直した (`sumBalance()` への参照つき)。

## 補足 (次ラウンドで伝える)

- `--apply` / horizon / PII / registry の子→親順・runbook については Codex も OK 判定。
- mutation 記録に「設計の予測と実測がずれた点」5 件を残しており、今回の修正で
  MU11 (削除集合の guard) を追加実測した。


## 修正後の `TicketLedgerCarryForwardService.php` 全文

```php
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

```

## 修正差分 (Round 1 からの差分。service / gate / 挙動テスト / factory)

```diff
diff --git a/app/Services/Billing/TicketLedgerCarryForwardService.php b/app/Services/Billing/TicketLedgerCarryForwardService.php
new file mode 100644
index 0000000..c3fe184
--- /dev/null
+++ b/app/Services/Billing/TicketLedgerCarryForwardService.php
@@ -0,0 +1,376 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Billing;
+
+use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
+use App\Enums\Billing\BillingRetentionTarget;
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Database\Query\Builder as QueryBuilder;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
+use RuntimeException;
+use stdClass;
+use Throwable;
+use Webmozart\Assert\Assert;
+
+/**
+ * チケット台帳 (`ticket_ledger_entries`) の**保持期間 (7 年) の畳み込み**。
+ *
+ * 台帳は append-only の残高の真実源であり、古い行を物理削除すると**残高が変わる**。
+ * よって保持期間の決着は削除ではなく畳み込み — 保持期限より古い行を
+ * `(organization_id, source, expires_at)` の組ごとに合算し、合計 `delta` を持つ
+ * **繰越行 1 行**へ置換する。
+ *
+ * ★**`organization_id` を group key に必ず含める**。含め忘れると組織を跨いで残高を
+ *   合算する重大バグになる。残高の粒度が実際にこの 3 つで閉じることは
+ *   {@see TicketLedgerService::balance()} の集計条件 (organization_id + source
+ *   (purchased は `source IS NULL` も含む) + `expires_at IS NULL or > now`) と対応する。
+ *   **`source IS NULL` (legacy 行) は独立した group** として扱う (purchased へ寄せると
+ *   `sumActiveHolds` の legacy 除外規則と意味がズレる)。
+ *
+ * ★**繰越行は「取引記録」ではなく「現在残高のスナップショット」である**。
+ *   原取引の識別子 (説明 / stripe id / payment intent / 予約 id / 冪等キー / 個別日時) を
+ *   1 つも引き継がない — 引き継いだら「7 年より古い取引の情報が残る」ことになり、
+ *   保持期間の意味が消える。引き継ぐのは残高の粒度を決める 3 つ
+ *   (`organization_id` / `source` / `expires_at`) だけである。
+ *   性質の違いは `kind = carry_forward` として型に出す (既存 kind へ相乗りしない)。
+ *
+ * ★**append-only 不変条件との関係**: 本サービスは Eloquent の delete guard を迂回する
+ *   Query Builder 直書きで行を消す**唯一の**経路である ({@see TicketLedgerService} の
+ *   `backfillPaymentIntentId` と同じ閉じ込め方)。「計上の事後改竄をしない」という
+ *   append-only の意図は保たれる — 個別行の値を書き換えるのではなく、
+ *   **保持期限を超えた区間ごと残高スナップショットへ置換する**操作だからである。
+ *
+ * ★**保証しないもの (誇張しない)**:
+ *   - 畳み込み後は**原取引が復元できない**。返金逆仕訳 (`clawbackPurchasedByPaymentIntent`) /
+ *     消費の冪等キー (`consume:{reservationId}`) / signup grant の部分 UNIQUE index は
+ *     いずれも**畳み込まれた行に対しては効かなくなる**。7 年より古い決済への遅延返金や
+ *     7 年前の予約の commit は現実には起きないが、「index が守っている」と言えるのは
+ *     畳み込み前の行までである (signup grant の**正本**は
+ *     `organizations.signup_tickets_granted_at` の条件付き UPDATE で、これは畳み込まれない)
+ *   - **合計 0 の group は繰越行を作らない**ため、その group の `expires_at` は
+ *     台帳から消える。未失効の monthly が完全に消費済みという組み合わせでのみ
+ *     `nearestMonthlyExpiry` の探索結果が変わる (残高は不変。既知窓としてテストで固定)
+ */
+final class TicketLedgerCarryForwardService
+{
+    /** 繰越行の冪等キーの接頭辞。 */
+    public const string IDEMPOTENCY_KEY_PREFIX = 'carry_forward:';
+
+    /**
+     * 繰越行の説明。
+     *
+     * ★詳細設計は `description` も null にすると書いているが、実列は **NOT NULL** である
+     *   (`2026_06_11_091400_create_ticket_tables.php` を実読で確認)。列を nullable へ変える
+     *   代わりに**取引追跡情報を一切含まない固定文言**を入れる。原取引の説明は残らないため
+     *   「個別取引が復元不能」という要件は満たす。
+     */
+    public const string CARRY_FORWARD_DESCRIPTION = '保持期間の繰越 (残高スナップショット)';
+
+    /** 冪等キー / 集約終端の日時表現 (UTC 正規化)。 */
+    private const string KEY_TIME_FORMAT = 'Y-m-d\TH:i:s\Z';
+
+    /** 冪等キーで null を表す明示トークン (空文字との衝突を避ける)。 */
+    private const string NULL_TOKEN = 'null';
+
+    /** 起算済み (台帳は `created_at` が起算点) かつ期限超過の行数。 */
+    public function countExpired(CarbonImmutable $threshold): int
+    {
+        return TicketLedgerEntry::query()
+            ->where('created_at', '<=', $threshold)
+            ->count();
+    }
+
+    /**
+     * 繰越行の冪等キー。
+     *
+     * 形は `carry_forward:{orgId}:{source}:{expiresAt}:{threshold}` で固定する。
+     * **null は明示トークン `'null'`**、日時は **UTC 正規化**。
+     * **同一 group を同じ閾値で再実行すれば同じキーになる** (= UNIQUE が二重の繰越行を弾く)。
+     *
+     * ★キーの第 4 要素は**その実行の閾値**であって `carried_forward_through` (集約終端) では
+     *   ない。両者は普段一致するが、保持年数を延ばして閾値が過去へ動いた場合だけ食い違う
+     *   (終端は単調に進むので前回値を保つ)。**冪等の単位は「同じ入力で同じ実行をしたか」**
+     *   なので、キーは入力である閾値で決める。
+     *
+     * 既存の signup grant 部分 UNIQUE index の述語 (`idempotency_key LIKE 'signup_grant:%'`) とは
+     * 接頭辞が異なるため衝突しない。
+     */
+    public static function idempotencyKeyFor(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): string {
+        return implode(':', [
+            rtrim(self::IDEMPOTENCY_KEY_PREFIX, ':'),
+            (string) $organizationId,
+            $source === null ? self::NULL_TOKEN : $source->value,
+            $expiresAt === null ? self::NULL_TOKEN : $expiresAt->utc()->format(self::KEY_TIME_FORMAT),
+            $threshold->utc()->format(self::KEY_TIME_FORMAT),
+        ]);
+    }
+
+    /**
+     * 保持期限より古い台帳行を組織ごとに畳み込む。
+     *
+     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
+     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
+     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
+     */
+    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
+    {
+        $candidates = $this->countExpired($threshold);
+        $processed = 0;
+        $unexpectedFailures = 0;
+
+        foreach ($this->organizationsWithExpiredEntries($threshold) as $organization) {
+            try {
+                $processed += DB::transaction(
+                    fn (): int => $this->carryForwardOrganization($organization, $threshold),
+                );
+            } catch (Throwable $e) {
+                $unexpectedFailures++;
+                // 例外 message は載せない (外部生成の可変文字列)。target と例外クラスだけ。
+                Log::warning('ticket ledger carry forward failed', [
+                    'target' => BillingRetentionTarget::TicketLedgerEntry->value,
+                    'organization_id' => $organization->getKey(),
+                    'error_class' => $e::class,
+                ]);
+            }
+        }
+
+        return new BillingRetentionPurgeResultDto(
+            target: BillingRetentionTarget::TicketLedgerEntry,
+            candidates: $candidates,
+            processed: $processed,
+            // 台帳は補助時計 (起算不能の異常) を持たず、参照されて消せない行も無い。
+            // 失敗した組織は fail-closed ではなく unexpectedFailures として報告する
+            // (「安全のため残した」ではなく「決着できなかった」である)。
+            failClosed: 0,
+            unexpectedFailures: $unexpectedFailures,
+            expiredRemaining: $this->countExpired($threshold),
+        );
+    }
+
+    /**
+     * 期限超過の台帳行を持つ組織 (id 昇順 = ロック順序の固定)。
+     *
+     * @return Collection<int, Organization>
+     */
+    private function organizationsWithExpiredEntries(CarbonImmutable $threshold): Collection
+    {
+        return Organization::query()
+            ->whereHas(
+                'ticketLedgerEntries',
+                fn (Builder $query): Builder => $query->where('created_at', '<=', $threshold),
+            )
+            ->orderBy('id')
+            ->get();
+    }
+
+    /**
+     * 1 組織ぶんの畳み込み (organizations 行ロック下)。
+     *
+     * @return int 畳み込んだ (置換で消えた) 行数
+     */
+    private function carryForwardOrganization(Organization $organization, CarbonImmutable $threshold): int
+    {
+        // 残高判定・台帳追記の直列化点。reserve / commit と同じロックを取る
+        // (畳み込みの最中に同じ組織の残高が動かないようにする)
+        Organization::query()
+            ->whereKey($organization->getKey())
+            ->lockForUpdate()
+            ->firstOrFail();
+
+        $organizationId = $organization->getKey();
+        if (! is_int($organizationId)) {
+            throw new RuntimeException('組織 id が解決できません (畳み込みは中止する)');
+        }
+
+        $processed = 0;
+        foreach ($this->expiredGroups($organizationId, $threshold) as $group) {
+            $processed += $this->carryForwardGroup(
+                $organizationId,
+                $group->source,
+                $group->expires_at,
+                $threshold,
+            );
+        }
+
+        return $processed;
+    }
+
+    /**
+     * 期限超過行の group key 一覧 (`source` / `expires_at` の相異なる組)。
+     *
+     * @return Collection<int, TicketLedgerEntry>
+     */
+    private function expiredGroups(int $organizationId, CarbonImmutable $threshold): Collection
+    {
+        return TicketLedgerEntry::query()
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold)
+            ->select(['source', 'expires_at'])
+            ->distinct()
+            ->get();
+    }
+
+    /**
+     * 1 group を繰越行へ置換する。
+     *
+     * @return int 置換で消えた行数
+     */
+    private function carryForwardGroup(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): int {
+        // **件数・合計・前回終端は 1 文で取る**。3 回に分けると文ごとに snapshot が変わり
+        // (READ COMMITTED)、「合計には入っていないが件数には入っている」行が生まれうる。
+        $aggregate = $this->aggregateGroup($organizationId, $source, $expiresAt, $threshold);
+        $total = $aggregate['total'];
+        $through = $this->resolveThrough($aggregate['previousThrough'], $threshold);
+
+        // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
+        if ($total !== 0) {
+            $inserted = DB::table('ticket_ledger_entries')->insertOrIgnore([
+                'organization_id' => $organizationId,
+                'delta' => $total,
+                'kind' => TicketLedgerKind::CarryForward->value,
+                'source' => $source?->value,
+                // --- ここから下は取引追跡情報。繰越行は 1 つも引き継がない ---
+                'reservation_id' => null,
+                'description' => self::CARRY_FORWARD_DESCRIPTION,
+                'granted_at' => null,
+                'stripe_checkout_session_id' => null,
+                'stripe_invoice_id' => null,
+                'payment_intent_id' => null,
+                'purchase_amount' => null,
+                // --- 残高の粒度と集約終端 ---
+                'expires_at' => $expiresAt?->toDateTimeString(),
+                'carried_forward_through' => $through->toDateTimeString(),
+                'idempotency_key' => self::idempotencyKeyFor($organizationId, $source, $expiresAt, $threshold),
+                'created_at' => CarbonImmutable::now()->toDateTimeString(),
+            ]);
+
+            // 冪等キーの衝突 = 同一 group を同一閾値で二重に畳み込もうとしている
+            // (通常は起きない。同じ閾値の再実行では対象行が既に消えているため)。
+            // 起きうるのは「畳み込み済みの group へ、閾値より古い created_at の行が
+            // 後から入った」ときで、既存の繰越行へ足し込むには UPDATE が要る。
+            // ここで原取引を消すと繰越行 1 行ぶんの残高が失われるため fail-closed で中止する
+            // (トランザクションごと巻き戻り、この組織は unexpectedFailures として報告される)。
+            if ($inserted !== 1) {
+                throw new RuntimeException('繰越行の冪等キーが衝突しました (畳み込みを中止して巻き戻す)');
+            }
+        }
+
+        // 繰越行の created_at は now (= 閾値より後) なので、この削除の対象にならない
+        $deleted = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)->delete();
+
+        // **集計した集合と削除した集合が一致することを確認する**。
+        // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+        // ロックを取らない冪等 insert であり、backfill / 取り込みも同様)。集計と削除の間に
+        // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を削除が巻き込む** =
+        // その枚数ぶん残高が消える。件数の不一致で検出し、トランザクションごと巻き戻す。
+        if ($deleted !== $aggregate['rows']) {
+            throw new RuntimeException(
+                '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
+            );
+        }
+
+        return $deleted;
+    }
+
+    /**
+     * group の件数・合計・前回終端を **1 文で** 取る。
+     *
+     * 分けて発行すると文ごとに snapshot が変わる (READ COMMITTED) ため、
+     * 「合計には入っていないが件数には入っている」行が生まれ、残高保存の検査そのものが壊れる。
+     *
+     * @return array{rows: int, total: int, previousThrough: string|null}
+     */
+    private function aggregateGroup(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): array {
+        $row = $this->groupQuery($organizationId, $source, $expiresAt, $threshold)
+            ->selectRaw('COUNT(*) AS row_count, COALESCE(SUM(delta), 0) AS delta_total, MAX(carried_forward_through) AS previous_through')
+            ->first();
+
+        if (! $row instanceof stdClass) {
+            throw new RuntimeException('台帳 group の集計に失敗しました (畳み込みを中止する)');
+        }
+
+        Assert::numeric($row->row_count);
+        Assert::numeric($row->delta_total);
+        Assert::nullOrString($row->previous_through);
+
+        return [
+            'rows' => (int) $row->row_count,
+            'total' => (int) $row->delta_total,
+            'previousThrough' => $row->previous_through,
+        ];
+    }
+
+    /**
+     * この繰越が集約した期間の終端。
+     *
+     * 既に繰越行を含む group (再畳み込み) では**前回の終端と今回の閾値の大きい方**を採り、
+     * 単調に進むことを保証する (保持年数を延ばすと閾値は過去へ動くため、閾値をそのまま
+     * 採ると集約済みの範囲を過小申告することになる)。
+     */
+    private function resolveThrough(?string $previous, CarbonImmutable $threshold): CarbonImmutable
+    {
+        if ($previous === null || $previous === '') {
+            return $threshold;
+        }
+
+        $parsed = CarbonImmutable::parse($previous);
+
+        return $parsed->greaterThan($threshold) ? $parsed : $threshold;
+    }
+
+    /**
+     * group を指す Query Builder (呼ぶたびに作り直す = 集計で汚れない)。
+     *
+     * ★Eloquent ではなく Query Builder を使う。台帳モデルは delete を例外化しており
+     *   (append-only guard)、畳み込みはその唯一の例外だからである。迂回を 1 箇所に閉じ込め、
+     *   「どこで消しているか」をコードで見えるようにする。
+     */
+    private function groupQuery(
+        int $organizationId,
+        ?TicketSource $source,
+        ?CarbonImmutable $expiresAt,
+        CarbonImmutable $threshold,
+    ): QueryBuilder {
+        $query = DB::table('ticket_ledger_entries')
+            ->where('organization_id', $organizationId)
+            ->where('created_at', '<=', $threshold);
+
+        if ($source === null) {
+            $query->whereNull('source');
+        } else {
+            $query->where('source', $source->value);
+        }
+
+        if ($expiresAt === null) {
+            $query->whereNull('expires_at');
+        } else {
+            $query->where('expires_at', $expiresAt);
+        }
+
+        return $query;
+    }
+}
diff --git a/database/factories/Billing/TicketLedgerEntryFactory.php b/database/factories/Billing/TicketLedgerEntryFactory.php
new file mode 100644
index 0000000..f3e4521
--- /dev/null
+++ b/database/factories/Billing/TicketLedgerEntryFactory.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Database\Factories\Billing;
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerService;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Eloquent\Factories\Factory;
+
+/**
+ * 台帳エントリ (残高の真実源) の fixture。
+ *
+ * 既定は purchased バケットの無期限付与 (+1)。保持期間の畳み込み (PR-C2) の検証で
+ * 「7 年より古い取引行」を任意の出所・失効時刻で並べるために使う。
+ *
+ * ★台帳は append-only (update / delete が Model イベントで例外化されている)。
+ *   factory は insert しか行わないため不変条件に触れない。
+ *
+ * @extends Factory<TicketLedgerEntry>
+ */
+class TicketLedgerEntryFactory extends Factory
+{
+    protected $model = TicketLedgerEntry::class;
+
+    /**
+     * @return array<string, mixed>
+     */
+    public function definition(): array
+    {
+        return [
+            'organization_id' => Organization::factory(),
+            'delta' => 1,
+            'kind' => TicketLedgerKind::Grant,
+            'source' => TicketSource::Purchased,
+            'description' => 'テスト付与',
+            'granted_at' => CarbonImmutable::now(),
+            'expires_at' => null,
+            'created_at' => CarbonImmutable::now(),
+        ];
+    }
+
+    public function forOrganization(Organization $organization): static
+    {
+        return $this->state(fn (): array => ['organization_id' => $organization->getKey()]);
+    }
+
+    /** 取引成立日時 (保持期間の起算点)。 */
+    public function createdAt(CarbonImmutable $createdAt): static
+    {
+        return $this->state(fn (): array => ['created_at' => $createdAt]);
+    }
+
+    /** monthly バケットの期限付き付与。 */
+    public function monthly(?CarbonImmutable $expiresAt): static
+    {
+        return $this->state(fn (): array => [
+            'source' => TicketSource::Monthly,
+            'expires_at' => $expiresAt,
+        ]);
+    }
+
+    /** purchased バケット (無期限)。 */
+    public function purchased(): static
+    {
+        return $this->state(fn (): array => [
+            'source' => TicketSource::Purchased,
+            'expires_at' => null,
+        ]);
+    }
+
+    /**
+     * P5 以前の出所を持たない行 (`source = null`)。
+     *
+     * **表示残高の集計では purchased バケットに含まれる**が
+     * ({@see TicketLedgerService::sumBalance()})、
+     * **保持期間の畳み込みでは purchased へ寄せず独立した group として扱う**
+     * (寄せると `sumActiveHolds` の legacy 除外規則と意味がズレる)。
+     */
+    public function legacy(): static
+    {
+        return $this->state(fn (): array => ['source' => null]);
+    }
+
+    /** 消費行 (負 delta)。消費した grant と同じ失効時刻を載せる。 */
+    public function consumed(int $amount, ?CarbonImmutable $expiresAt = null): static
+    {
+        return $this->state(fn (): array => [
+            'delta' => -$amount,
+            'kind' => TicketLedgerKind::ReserveCommit,
+            'granted_at' => null,
+            'expires_at' => $expiresAt,
+        ]);
+    }
+
+    /** 枚数 (正: 付与 / 負: 消費)。 */
+    public function delta(int $delta): static
+    {
+        return $this->state(fn (): array => ['delta' => $delta]);
+    }
+
+    /** 冪等キー (二重付与防止キー) を持つ行。 */
+    public function idempotencyKey(string $key): static
+    {
+        return $this->state(fn (): array => ['idempotency_key' => $key]);
+    }
+}
diff --git a/tests/Architecture/TicketLedgerReaderInventoryTest.php b/tests/Architecture/TicketLedgerReaderInventoryTest.php
new file mode 100644
index 0000000..0d4da78
--- /dev/null
+++ b/tests/Architecture/TicketLedgerReaderInventoryTest.php
@@ -0,0 +1,395 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketLedgerKind;
+
+/*
+ * Architecture invariant: **チケット台帳 (`ticket_ledger_entries`) を読む場所は deny-by-default の目録制**。
+ *
+ * SoT = devnotes/20260809-0908-account-deletion-grace/detailed-design.md の PR-C2 (C2a)。
+ *
+ * ★なぜ要るか:
+ *   保持期間 (7 年) の決着は**畳み込み** — 期限超過の個別取引行を消し、
+ *   `(organization_id, source, expires_at)` ごとの**残高スナップショット 1 行**へ置き換える。
+ *   帰結として、7 年より古い**個別取引の情報は復元できなくなる** (それが保持期間の意味である)。
+ *   よって「台帳の個別行を読む場所」が宣言なしに増えると、ある日その画面 / 集計だけが
+ *   静かに壊れる (行が消えているのに例外は起きない = 気付けない壊れ方)。
+ *   目録は「増やすときに必ず読み方 (集計 / 個別行) を宣言させる」ための摩擦である。
+ *
+ * ★走査入口は 4 つ (詳細設計 C2a):
+ *   1. モデル参照 (`TicketLedgerEntry` の識別子)
+ *   2. table 名リテラル (`'ticket_ledger_entries'`)
+ *   3. relation 名 (`ticketLedgerEntries`)
+ *   4. 主要列名リテラル (`'delta'` / `'source'` / `'expires_at'`)
+ *
+ * ★この gate が保証するもの:
+ *   - 検査 1: 走査で検出したファイルが目録と **exact-fit** (未登録 = fail / 幽霊登録 = fail)
+ *   - 検査 2: 全 entry が読み方 (`aggregate` / `row_detail` / `other_table`) を宣言し、
+ *     根拠が 30 文字以上
+ *   - 検査 3: 空振り検知 (走査ファイル数 / 検出件数を**現在値ちょうど**で pin)
+ *   - 検査 4: 自己参照コントロール (コメント・docblock 内の言及は 0 件 = 説明文で偽赤にならない)
+ *   - 検査 5: 正のコントロール (4 入口それぞれが実際に点灯する = 検出器が死んでいない)
+ *   - 検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)
+ *   - 検査 7: **フロント側に台帳 kind の対応型が無い**ことを deny-by-default で固定する
+ *     (持ち込むなら PHP enum ⇔ TS union の同期テストを同時に足させる)
+ *   - 検査 8: 台帳 kind の全 case が表示ラベルを持ち、件数が現在値ちょうどで pin されている
+ *
+ * ★この gate が保証しないもの (誇張しない):
+ *   - **目録が保証するのは「読んでいる場所を宣言なしに増やせない」ことだけ**である。
+ *     動的 relation (`$org->{$name}`) / 変数 table 名 (`DB::table($t)`) /
+ *     文字列を組み立てる raw SQL は**取りこぼす**。
+ *     **最終保証は畳み込みの挙動テスト側** (tests/Feature/Billing/TicketLedgerCarryForwardTest.php) である
+ *   - 宣言した読み方 (`aggregate` / `row_detail`) が**実際のコードと一致するか**は機械では見ない
+ *     (人間の申告である)。gate が強制できるのは「宣言があること」まで
+ *   - **列名リテラル (入口 4) の走査範囲は課金ディレクトリに限る**
+ *     (`app/Models/Billing` / `app/Services/Billing` / `app/Console/Commands/Billing` /
+ *      `app/Enums/Billing`)。`source` / `expires_at` は `ticket_reservations` /
+ *      `ticket_checkout_sessions` / `api_keys` / `organization_invitations` 等が**同名の列**を
+ *      持ち、app/ 全体を走査すると台帳と無関係な hit が大量に出て信号が死ぬ。
+ *      **課金ディレクトリの外**で台帳の列名だけを使う経路には**沈黙する**
+ *   - vendor / database/migrations / tests は母集団外 (migration は列定義そのものであり、
+ *     tests は台帳を読むのが仕事である)
+ */
+
+/** 台帳モデルの短縮名 (識別子として現れる形)。 */
+const TICKET_LEDGER_MODEL_IDENTIFIER = 'TicketLedgerEntry';
+
+/** 台帳の table 名。 */
+const TICKET_LEDGER_TABLE = 'ticket_ledger_entries';
+
+/** 台帳への relation 名。 */
+const TICKET_LEDGER_RELATION = 'ticketLedgerEntries';
+
+/** 台帳の主要列名 (入口 4)。 */
+const TICKET_LEDGER_COLUMNS = ['delta', 'source', 'expires_at'];
+
+/**
+ * 入口 4 (列名リテラル) の走査範囲。
+ *
+ * `source` / `expires_at` は他テーブルにも実在する一般名のため、app/ 全体では信号が死ぬ。
+ * 課金ディレクトリに限ることで「台帳の近所で列名だけ使う新規経路」を捕まえる。
+ */
+const TICKET_LEDGER_COLUMN_SCAN_DIRS = [
+    'Models/Billing',
+    'Services/Billing',
+    'Console/Commands/Billing',
+    'Enums/Billing',
+];
+
+/**
+ * 台帳を読む / 触る場所の目録 (app_path からの相対パス => [読み方, 根拠])。
+ *
+ * 読み方の語彙:
+ * - `aggregate`   … 集計 (SUM / COUNT / MAX) でしか読まない。畳み込みに影響されない
+ * - `row_detail`  … 個別取引行の属性に依存する。畳み込みで**情報が失われる**側
+ * - `other_table` … 台帳ではない同名列を持つ別テーブルの経路 (入口 4 の巻き添え)
+ *
+ * @var array<string, array{string, string}>
+ */
+const TICKET_LEDGER_READER_INVENTORY = [
+    'Models/Billing/TicketLedgerEntry.php' => [
+        'row_detail',
+        '台帳モデルそのもの。列定義と append-only guard (update/delete の例外化) を持つ',
+    ],
+    'Models/Organization.php' => [
+        'aggregate',
+        'relation 定義 (ticketLedgerEntries) のみ。行の中身は読まず件数・合算の入口を提供する',
+    ],
+    'Enums/Billing/BillingRetentionTarget.php' => [
+        'aggregate',
+        '保持期間の目録で台帳を target として宣言する。モデルクラスと起算列名の参照のみ',
+    ],
+    'Services/Billing/TicketLedgerService.php' => [
+        'aggregate',
+        '台帳の唯一の書き込み窓口。残高は source / expires_at 別の SUM で読み、個別取引行の識別子には依存しない',
+    ],
+    'Services/Billing/TicketLedgerCarryForwardService.php' => [
+        'row_detail',
+        '保持期間の畳み込み本体。期限超過の個別取引行を残高スナップショット 1 行へ置換する唯一の経路',
+    ],
+    'Services/Billing/Retention/TicketLedgerEntryPurger.php' => [
+        'aggregate',
+        '保持期間 purger の adapter。件数の集計と畳み込みサービスへの委譲だけを行う',
+    ],
+    'Models/Billing/TicketReservation.php' => [
+        'other_table',
+        'ticket_reservations の expires_at (予約 TTL) であり台帳の失効時刻ではない。入口 4 の巻き添え',
+    ],
+    'Models/Billing/TicketCheckoutSession.php' => [
+        'other_table',
+        'ticket_checkout_sessions の expires_at (Checkout Session の失効) であり台帳ではない',
+    ],
+    'Services/Billing/TicketCheckoutService.php' => [
+        'other_table',
+        'ticket_checkout_sessions の expires_at を扱う購入手続きの経路であり台帳は読まない',
+    ],
+];
+
+/** 読み方の語彙 (exact-fit)。 */
+const TICKET_LEDGER_READ_MODES = ['aggregate', 'row_detail', 'other_table'];
+
+/** 走査ファイル数の下限 (degenerate PASS 防止)。 */
+const TICKET_LEDGER_SCAN_FLOOR = 200;
+
+/**
+ * PHP ソースから台帳への参照入口を検出する。
+ *
+ * コメント / docblock は code token ではないので拾わない (説明文で偽赤にならない)。
+ * 文字列リテラルは table 名・relation 名・列名の照合に要るので**値だけ**見る
+ * (中身を PHP として解釈はしない)。
+ *
+ * @param  bool  $scanColumns  入口 4 (列名リテラル) を有効にするか
+ * @return list<string> 検出した入口のラベル (重複排除済み)
+ */
+function ticketLedgerReferenceEntries(string $source, bool $scanColumns): array
+{
+    /** @var list<PhpToken> $tokens */
+    $tokens = PhpToken::tokenize($source);
+
+    $found = [];
+    foreach ($tokens as $token) {
+        if ($token->is([T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML])) {
+            continue;
+        }
+
+        if ($token->is(T_STRING)) {
+            if ($token->text === TICKET_LEDGER_MODEL_IDENTIFIER) {
+                $found['model'] = 'model';
+            }
+            if ($token->text === TICKET_LEDGER_RELATION) {
+                $found['relation'] = 'relation';
+            }
+
+            continue;
+        }
+
+        if (! $token->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE])) {
+            continue;
+        }
+
+        $value = trim($token->text, "'\"");
+        if ($value === TICKET_LEDGER_TABLE) {
+            $found['table'] = 'table';
+        }
+        if ($value === TICKET_LEDGER_RELATION) {
+            $found['relation'] = 'relation';
+        }
+        if ($scanColumns && in_array($value, TICKET_LEDGER_COLUMNS, true)) {
+            $found['column'] = 'column';
+        }
+    }
+
+    return array_values($found);
+}
+
+/**
+ * app/ 配下の PHP ファイル (app_path からの相対パス)。
+ *
+ * @return list<string>
+ */
+function ticketLedgerScanFiles(): array
+{
+    $base = app_path();
+    $files = [];
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $files[] = str_replace($base.'/', '', $file->getPathname());
+    }
+
+    sort($files);
+
+    return $files;
+}
+
+/**
+ * 走査結果 (相対パス => 検出した入口ラベル)。
+ *
+ * @return array<string, list<string>>
+ */
+function ticketLedgerDetected(): array
+{
+    $detected = [];
+    foreach (ticketLedgerScanFiles() as $relative) {
+        $source = file_get_contents(app_path($relative));
+        if ($source === false) {
+            continue;
+        }
+
+        $scanColumns = false;
+        foreach (TICKET_LEDGER_COLUMN_SCAN_DIRS as $dir) {
+            if (str_starts_with($relative, $dir.'/')) {
+                $scanColumns = true;
+
+                break;
+            }
+        }
+
+        $entries = ticketLedgerReferenceEntries($source, $scanColumns);
+        if ($entries !== []) {
+            $detected[$relative] = $entries;
+        }
+    }
+
+    ksort($detected);
+
+    return $detected;
+}
+
+test('検査 1: 台帳を読む場所が目録と exact-fit である', function (): void {
+    $detected = array_keys(ticketLedgerDetected());
+    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);
+    sort($declared);
+
+    $missing = array_values(array_diff($detected, $declared));
+    $phantom = array_values(array_diff($declared, $detected));
+
+    expect($missing)->toBe([],
+        '台帳を読む場所が目録に登録されていません。読み方 (aggregate / row_detail / other_table) と '
+        .'30 文字以上の根拠を TICKET_LEDGER_READER_INVENTORY へ登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $missing));
+
+    expect($phantom)->toBe([],
+        '目録にあるが実在しない / 台帳を参照しなくなったファイルです (残置を消してください): '
+        .implode(', ', $phantom));
+});
+
+test('検査 2: 全 entry が読み方を宣言し根拠が 30 文字以上である', function (): void {
+    $violations = [];
+    foreach (TICKET_LEDGER_READER_INVENTORY as $path => [$mode, $rationale]) {
+        if (! in_array($mode, TICKET_LEDGER_READ_MODES, true)) {
+            $violations[] = $path.': 未知の読み方 '.$mode;
+        }
+        if (mb_strlen($rationale) < 30) {
+            $violations[] = $path.': 根拠が 30 文字未満';
+        }
+    }
+
+    expect($violations)->toBe([], implode(PHP_EOL, $violations));
+});
+
+test('検査 3: 空振り検知 (走査ファイル数と検出件数を pin する)', function (): void {
+    expect(count(ticketLedgerScanFiles()))->toBeGreaterThan(TICKET_LEDGER_SCAN_FLOOR);
+    expect(ticketLedgerDetected())->toHaveCount(count(TICKET_LEDGER_READER_INVENTORY));
+    expect(TICKET_LEDGER_READER_INVENTORY)->not->toBeEmpty();
+
+    // 正の自己検証: 実ファイルで検出器が実際に点灯する (検出器が死んでいない)
+    $service = file_get_contents(app_path('Services/Billing/TicketLedgerService.php'));
+    expect($service)->toBeString();
+    expect(ticketLedgerReferenceEntries((string) $service, true))->toContain('model');
+});
+
+test('検査 4: 自己参照コントロール (コメント・docblock 内の言及は検出しない)', function (): void {
+    $fixture = <<<'PHP'
+        <?php
+        /**
+         * 残高の真実源は ledger (TicketLedgerEntry) である。
+         * table 名は ticket_ledger_entries、relation は ticketLedgerEntries。
+         */
+        final class Documented
+        {
+            // delta / source / expires_at の意味はここに書く
+            public function noop(): void {}
+        }
+        PHP;
+
+    expect(ticketLedgerReferenceEntries($fixture, true))->toBe([]);
+
+    // 実在の証拠: コメントでしか台帳に触れないファイルは目録に載らない
+    expect(TICKET_LEDGER_READER_INVENTORY)
+        ->not->toHaveKey('Services/Billing/AutoRechargeService.php');
+    expect(TICKET_LEDGER_READER_INVENTORY)
+        ->not->toHaveKey('Models/Billing/TicketAutoRecharge.php');
+});
+
+test('検査 5: 正のコントロール (4 入口それぞれが点灯する)', function (): void {
+    $model = <<<'PHP'
+        <?php
+        use App\Models\Billing\TicketLedgerEntry;
+        final class R { public function f(): void { TicketLedgerEntry::query()->get(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($model, false))->toBe(['model']);
+
+    $table = <<<'PHP'
+        <?php
+        final class R { public function f(): void { DB::table('ticket_ledger_entries')->get(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($table, false))->toBe(['table']);
+
+    $relation = <<<'PHP'
+        <?php
+        final class R { public function f($org): void { $org->ticketLedgerEntries()->count(); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($relation, false))->toBe(['relation']);
+
+    $column = <<<'PHP'
+        <?php
+        final class R { public function f($q): void { $q->sum('delta'); } }
+        PHP;
+    expect(ticketLedgerReferenceEntries($column, true))->toBe(['column']);
+
+    // 入口 4 は走査範囲を絞っている (無効時は点灯しない)
+    expect(ticketLedgerReferenceEntries($column, false))->toBe([]);
+});
+
+test('検査 7: フロント側に台帳 kind の対応型が無い (増やすなら TS 同期テストが要る)', function (): void {
+    // C2b で `TicketLedgerKind` に `carry_forward` を足した。**現時点で `resources/js` 側に
+    // 台帳 kind の対応型も表示分岐も存在しない**ため TS 同期テストは不要である
+    // (ManualEnumTsSyncInvariantTest / NotificationTypeTsSyncInvariantTest のような
+    //  literal union が 1 つも無い)。この「不在」を deny-by-default で固定する —
+    // フロントに台帳 kind を持ち込むなら、同時に enum ⇔ TS union の同期テストを足させる。
+    $hits = [];
+    $base = resource_path('js');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
+    );
+    /** @var SplFileInfo $file */
+    foreach ($iterator as $file) {
+        if (! $file->isFile() || ! in_array($file->getExtension(), ['ts', 'svelte'], true)) {
+            continue;
+        }
+        $source = file_get_contents($file->getPathname());
+        if ($source === false) {
+            continue;
+        }
+        foreach (['TicketLedgerKind', 'reserve_commit', 'carry_forward'] as $needle) {
+            if (str_contains($source, $needle)) {
+                $hits[] = str_replace($base.'/', '', $file->getPathname()).' => '.$needle;
+            }
+        }
+    }
+
+    expect($hits)->toBe([],
+        'フロントに台帳 kind の対応型 / 表示分岐が現れました。PHP enum ⇔ TS union の '
+        .'同期テスト (Tests\Support\TsUnionValues) を同時に追加してください。'
+        .PHP_EOL.implode(PHP_EOL, $hits));
+
+    // 空振り検知: 走査が実際にファイルへ届いている
+    expect(is_dir($base))->toBeTrue();
+    expect(file_get_contents(resource_path('js/types/manual.ts')))->toContain('export type');
+});
+
+test('検査 8: 台帳 kind は全 case が表示ラベルを持つ (表示分岐の網羅)', function (): void {
+    foreach (TicketLedgerKind::cases() as $case) {
+        expect($case->label())->not->toBe('');
+    }
+
+    // 現在値ちょうどで pin (case を足したら必ずこの数字を書き換える = 表示分岐を見直す契機)
+    expect(TicketLedgerKind::cases())->toHaveCount(6);
+    expect(TicketLedgerKind::CarryForward->value)->toBe('carry_forward');
+});
+
+test('検査 6: 負のコントロール (未登録ファイルを混ぜると検査 1 が点灯する)', function (): void {
+    $detected = array_keys(ticketLedgerDetected());
+    $detected[] = 'Services/Billing/UndeclaredLedgerReader.php';
+    $declared = array_keys(TICKET_LEDGER_READER_INVENTORY);
+
+    expect(array_values(array_diff($detected, $declared)))
+        ->toBe(['Services/Billing/UndeclaredLedgerReader.php']);
+});
diff --git a/tests/Feature/Billing/TicketLedgerCarryForwardTest.php b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
new file mode 100644
index 0000000..050277e
--- /dev/null
+++ b/tests/Feature/Billing/TicketLedgerCarryForwardTest.php
@@ -0,0 +1,485 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\TicketLedgerKind;
+use App\Enums\Billing\TicketSource;
+use App\Models\Billing\TicketLedgerEntry;
+use App\Models\Organization;
+use App\Services\Billing\TicketLedgerCarryForwardService;
+use App\Services\Billing\TicketLedgerService;
+use App\Support\Legal\BillingRetention;
+use Carbon\CarbonImmutable;
+use Illuminate\Database\Events\QueryExecuted;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 保持期間 (7 年) の台帳畳み込み (PR-C2 / C2b) の挙動。
+ *
+ * ★畳み込みは**会計上の残高を保存する操作**である。1 枚でも増減したら重大な不具合なので、
+ *   「畳み込み前後で 7 種の観測値が一致する」ことを本ファイルが機械固定する
+ *   (詳細設計 C2b の検証 1〜7)。
+ *
+ * ★繰越行は「取引記録」ではなく**現在残高のスナップショット**である。原取引の識別子
+ *   (説明 / stripe id / payment intent / 予約 id / 冪等キー) は 1 つも引き継がない
+ *   — 引き継ぐと「7 年より古い取引の情報が残る」ことになり保持期間の意味が消える。
+ */
+
+/**
+ * 台帳の残高粒度ごとの合計 (organization_id / source / expires_at)。
+ *
+ * **合計 0 の group は落とす**。畳み込みは残高に寄与しない行を作らないため、
+ * 「0 の group が消えること」は残高の変化ではない。
+ *
+ * @return array<string, int>
+ */
+function ledgerBalanceByGroup(): array
+{
+    $totals = [];
+    foreach (TicketLedgerEntry::query()->get() as $entry) {
+        $key = implode('|', [
+            $entry->organization_id,
+            $entry->source?->value ?? 'null',
+            $entry->expires_at?->toIso8601String() ?? 'null',
+        ]);
+        $totals[$key] = ($totals[$key] ?? 0) + $entry->delta;
+    }
+
+    ksort($totals);
+
+    return array_filter($totals, static fn (int $total): bool => $total !== 0);
+}
+
+/**
+ * 組織ごとの表示残高 + 与信残高。
+ *
+ * @return array<int, array{monthly: int, purchased: int, holds: int, available: int}>
+ */
+function ledgerBalancesByOrganization(): array
+{
+    $service = app(TicketLedgerService::class);
+    $out = [];
+    foreach (Organization::query()->orderBy('id')->get() as $organization) {
+        $balance = $service->balance($organization);
+        $id = $organization->getKey();
+        expect($id)->toBeInt();
+        $out[$id] = [
+            'monthly' => $balance->monthlyRemaining,
+            'purchased' => $balance->purchasedRemaining,
+            'holds' => $balance->activeReservations,
+            'available' => $service->availableTrueBalance($organization),
+        ];
+    }
+
+    return $out;
+}
+
+/**
+ * 3 組織ぶんの「7 年より古い取引 + 新しい取引」を並べる。
+ *
+ * @return array{Organization, Organization, Organization}
+ */
+function seedCarryForwardLedger(CarbonImmutable $threshold): array
+{
+    $old = $threshold->subYearNoOverflow();
+
+    // --- 組織 A: 失効済み monthly の付与 / 消費 + 無期限 purchased + legacy (source null)
+    [$a] = createOrganizationWithOwner('組織A');
+    $expiredMonthly = $threshold->subMonthsNoOverflow(6);
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($expiredMonthly)->delta(100)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($expiredMonthly)->consumed(40, $expiredMonthly)->create();
+    // **同じ source で失効時刻だけが違う group** を必ず 2 つ置く。
+    // これが無いと「group key から expires_at を落とす」変異が検出できない (実測済み)。
+    $otherExpiry = $threshold->subMonthsNoOverflow(3);
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->monthly($otherExpiry)->delta(70)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->purchased()->delta(50)->create();
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt($old)
+        ->legacy()->delta(10)->create();
+    // 新しい取引 (畳み込みの対象外)
+    TicketLedgerEntry::factory()->forOrganization($a)->createdAt(CarbonImmutable::now())
+        ->purchased()->delta(5)->create();
+
+    // --- 組織 B: 7 年より古いが**まだ失効していない** monthly (残高に効いている)
+    [$b] = createOrganizationWithOwner('組織B');
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->monthly($liveExpiry)->delta(30)->create();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->purchased()->delta(80)->create();
+    TicketLedgerEntry::factory()->forOrganization($b)->createdAt($old)
+        ->purchased()->consumed(20)->create();
+
+    // --- 組織 C: 新しい取引しか無い (畳み込みが 1 行も触らない対照)
+    [$c] = createOrganizationWithOwner('組織C');
+    TicketLedgerEntry::factory()->forOrganization($c)->createdAt(CarbonImmutable::now())
+        ->purchased()->delta(7)->create();
+
+    return [$a, $b, $c];
+}
+
+test('検証 1〜4・7: 畳み込み前後で残高が 1 枚も変わらない (組織 / source / 失効時刻の粒度)', function (): void {
+    $threshold = BillingRetention::threshold();
+    seedCarryForwardLedger($threshold);
+
+    $groupsBefore = ledgerBalanceByGroup();
+    $balancesBefore = ledgerBalancesByOrganization();
+    $rowsBefore = TicketLedgerEntry::query()->count();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 空振り検知: 実際に畳み込まれた (0 件で green になっていない)
+    expect($result->candidates)->toBeGreaterThan(0);
+    expect($result->processed)->toBe($result->candidates);
+    expect($result->unexpectedFailures)->toBe(0);
+    expect($result->expiredRemaining)->toBe(0);
+    expect($result->failClosed)->toBe(0);
+
+    expect(ledgerBalanceByGroup())->toBe($groupsBefore);
+    expect(ledgerBalancesByOrganization())->toBe($balancesBefore);
+
+    // 行数は必ず減る (畳み込みが実際に起きた証拠)
+    expect(TicketLedgerEntry::query()->count())->toBeLessThan($rowsBefore);
+});
+
+test('検証 5: 畳み込み後も消費の出所と失効境界の選択が変わらない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [, $b] = seedCarryForwardLedger($threshold);
+
+    $service = app(TicketLedgerService::class);
+
+    // 畳み込み前の選択を観測する (monthly が生きているので monthly から消費する)
+    $before = $service->reserve($b, 1);
+    $beforeSource = $before->consume_source;
+    $beforeExpiry = $before->consume_expires_at?->toIso8601String();
+    $service->release($before);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $after = $service->reserve($b, 1);
+
+    expect($after->consume_source)->toBe($beforeSource);
+    expect($after->consume_expires_at?->toIso8601String())->toBe($beforeExpiry);
+    expect($beforeSource)->toBe(TicketSource::Monthly); // 空振り検知
+});
+
+test('繰越行は残高の粒度 3 つだけを引き継ぎ、取引追跡情報を 1 つも残さない', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(40)->idempotencyKey('purchase:cs_test_secret')
+        ->create(['description' => 'チケット購入 (checkout session: cs_test_secret)']);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $entries = TicketLedgerEntry::query()->get();
+    expect($entries)->toHaveCount(1);
+
+    $carry = $entries->firstOrFail();
+    expect($carry->kind)->toBe(TicketLedgerKind::CarryForward);
+    expect($carry->delta)->toBe(40);
+    expect($carry->source)->toBe(TicketSource::Purchased);
+    expect($carry->expires_at)->toBeNull();
+    expect($carry->carried_forward_through?->toDateTimeString())
+        ->toBe($threshold->toDateTimeString());
+
+    // 取引追跡情報は 1 つも残っていない (原取引が復元不能である)
+    expect($carry->reservation_id)->toBeNull();
+    expect($carry->granted_at)->toBeNull();
+    expect($carry->stripe_checkout_session_id)->toBeNull();
+    expect($carry->payment_intent_id)->toBeNull();
+    expect($carry->purchase_amount)->toBeNull();
+    expect($carry->stripe_invoice_id)->toBeNull();
+    expect($carry->description)->not->toContain('cs_test_secret');
+    expect($carry->idempotency_key)->not->toContain('cs_test_secret');
+    expect($carry->created_at->greaterThan($threshold))->toBeTrue();
+});
+
+test('group key は (organization_id, source, expires_at) の 3 つで、組織を跨いで合算しない', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$first] = createOrganizationWithOwner('第一組織');
+    [$second] = createOrganizationWithOwner('第二組織');
+
+    TicketLedgerEntry::factory()->forOrganization($first)->createdAt($old)->purchased()->delta(11)->create();
+    TicketLedgerEntry::factory()->forOrganization($second)->createdAt($old)->purchased()->delta(22)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect(TicketLedgerEntry::query()->count())->toBe(2);
+    expect((int) TicketLedgerEntry::query()->where('organization_id', $first->getKey())->sum('delta'))->toBe(11);
+    expect((int) TicketLedgerEntry::query()->where('organization_id', $second->getKey())->sum('delta'))->toBe(22);
+});
+
+test('source が null の legacy 行は独立した group として畳み込まれる (purchased へ寄せない)', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(9)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->legacy()->delta(4)->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    $entries = TicketLedgerEntry::query()->orderBy('id')->get();
+    expect($entries)->toHaveCount(2);
+    expect($entries->firstWhere('source', TicketSource::Purchased)?->delta)->toBe(9);
+    expect($entries->first(fn (TicketLedgerEntry $e): bool => $e->source === null)?->delta)->toBe(4);
+});
+
+test('合計 0 の group は繰越行を作らない (残高に寄与しない行を増やさない)', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->delta(12)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)->purchased()->consumed(12)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->processed)->toBe(2);
+    expect(TicketLedgerEntry::query()->count())->toBe(0);
+});
+
+test('冪等キーは group と閾値で決まり、再実行で同じ値になる (null は明示トークン / 日時は UTC)', function (): void {
+    $through = CarbonImmutable::parse('2019-03-04 05:06:07', 'Asia/Tokyo');
+    $expiresAt = CarbonImmutable::parse('2018-12-31 15:00:00', 'UTC');
+
+    $withValues = TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through);
+    $withNulls = TicketLedgerCarryForwardService::idempotencyKeyFor(42, null, null, $through);
+
+    expect($withValues)->toBe('carry_forward:42:monthly:2018-12-31T15:00:00Z:2019-03-03T20:06:07Z');
+    expect($withNulls)->toBe('carry_forward:42:null:null:2019-03-03T20:06:07Z');
+
+    // 再実行で同じ値になる (同一入力 → 同一キー)
+    expect(TicketLedgerCarryForwardService::idempotencyKeyFor(42, TicketSource::Monthly, $expiresAt, $through))
+        ->toBe($withValues);
+
+    // 既存の signup_grant 部分 UNIQUE index の述語 (LIKE 'signup_grant:%') と衝突しない
+    expect($withValues)->not->toStartWith('signup_grant:');
+});
+
+test('繰越行はさらに畳み込める (carried_forward_through が単調に進む)', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->subYearsNoOverflow(2))->purchased()->delta(15)->create();
+
+    // 1 回目: 2 年前の閾値で畳み込む (繰越行の created_at はその時点)
+    $firstThreshold = $threshold->subYearNoOverflow();
+    app(TicketLedgerCarryForwardService::class)->carryForward($firstThreshold);
+
+    $first = TicketLedgerEntry::query()->sole();
+    expect($first->kind)->toBe(TicketLedgerKind::CarryForward);
+    $firstThrough = $first->carried_forward_through;
+    expect($firstThrough)->not->toBeNull();
+
+    // 繰越行を「古い行」に見せるため created_at だけを過去へずらす (append-only guard を迂回する
+    // Query Builder 直書き。fixture の都合であり本番経路には無い操作である)
+    DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->update(['created_at' => $threshold->subMonthNoOverflow()]);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->subMonthsNoOverflow(2))->purchased()->delta(5)->create();
+
+    // 2 回目: 現在の閾値で再畳み込み
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->processed)->toBe(2);
+
+    $second = TicketLedgerEntry::query()->sole();
+    expect($second->delta)->toBe(20);
+    expect($second->carried_forward_through?->greaterThan($firstThrough))->toBeTrue();
+});
+
+test('畳み込み済み group に古い行が後から入ったら fail-closed (残高を失わない)', function (): void {
+    // 冪等キーは (group, 閾値) で決まるので、同じ閾値で 2 度目の繰越行は insert されない。
+    // そこで原取引だけ消すと**繰越行 1 行ぶんの残高が消える**ため、丸ごと巻き戻して報告する。
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(30)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    $service->carryForward($threshold);
+    expect(TicketLedgerEntry::query()->sole()->delta)->toBe(30);
+
+    // 同じ group へ「閾値より古い」行が後から入る (取り込み遅延 / 手動投入)
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(7)->create();
+
+    $result = $service->carryForward($threshold);
+
+    expect($result->unexpectedFailures)->toBe(1);
+    expect($result->processed)->toBe(0);
+    expect($result->expiredRemaining)->toBe(1);
+    // 残高は 1 枚も失われていない (30 + 7)
+    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(37);
+});
+
+test('集計の後に古い行が割り込んだら fail-closed (削除が合計に無い行を巻き込まない)', function (): void {
+    // organizations 行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
+    // ロックを取らない冪等 insert)。集計と削除の間に `created_at <= 閾値` の行が入ると、
+    // **合計に入っていない行を削除が巻き込む** = その枚数ぶん残高が消える。
+    // ここでは繰越行の INSERT を観測した瞬間に割り込み行を差し込んで、その窓を再現する。
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(30)->create();
+
+    $injected = false;
+    DB::listen(function (QueryExecuted $query) use ($organization, $old, &$injected): void {
+        if ($injected || ! str_contains($query->sql, 'insert into "ticket_ledger_entries"')) {
+            return;
+        }
+        $injected = true;
+        DB::table('ticket_ledger_entries')->insert([
+            'organization_id' => $organization->getKey(),
+            'delta' => 9,
+            'kind' => TicketLedgerKind::Grant->value,
+            'source' => TicketSource::Purchased->value,
+            'description' => '割り込みで入った古い取引',
+            'expires_at' => null,
+            'created_at' => $old->toDateTimeString(),
+        ]);
+    });
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($injected)->toBeTrue(); // 空振り検知: 割り込みが実際に起きた
+    expect($result->unexpectedFailures)->toBe(1);
+    expect($result->processed)->toBe(0);
+
+    // **元の 30 枚は 1 枚も失われていない** (削除が巻き戻った)。
+    // 割り込み行の 9 枚が残っていないのは、テストが**同一トランザクション内**に差し込んで
+    // いるためで、実運用の割り込み (別トランザクションの commit) なら残る。
+    // ここで固定したいのは「合計に入っていない行を削除が巻き込まない」ことである。
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect((int) TicketLedgerEntry::query()->sum('delta'))->toBe(30);
+});
+
+test('閾値が過去へ戻っても carried_forward_through は後退しない (単調性)', function (): void {
+    // 保持年数を延ばす (7 年 → もっと長く) と閾値は過去へ動く。既に「ここまで畳み込んだ」と
+    // 記録した終端を、後から短い値で上書きすると**集約済みの範囲を過小申告する**ことになる。
+    [$organization] = createOrganizationWithOwner();
+    $now = CarbonImmutable::now();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(12))->purchased()->delta(15)->create();
+
+    // 1 回目: 新しい方の閾値 (now - 5 年) で畳み込む
+    $laterThreshold = $now->subYearsNoOverflow(5);
+    app(TicketLedgerCarryForwardService::class)->carryForward($laterThreshold);
+    expect(TicketLedgerEntry::query()->sole()->carried_forward_through?->toDateTimeString())
+        ->toBe($laterThreshold->toDateTimeString());
+
+    // 繰越行を「古い行」に見せる (fixture の都合。append-only guard を迂回する直書き)
+    DB::table('ticket_ledger_entries')
+        ->where('organization_id', $organization->getKey())
+        ->update(['created_at' => $now->subYearsNoOverflow(10)]);
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($now->subYearsNoOverflow(11))->purchased()->delta(5)->create();
+
+    // 2 回目: **過去へ戻った**閾値 (now - 9 年) で再畳み込み
+    $earlierThreshold = $now->subYearsNoOverflow(9);
+    app(TicketLedgerCarryForwardService::class)->carryForward($earlierThreshold);
+
+    $carry = TicketLedgerEntry::query()->sole();
+    expect($carry->delta)->toBe(20);
+    expect($carry->carried_forward_through?->toDateTimeString())
+        ->toBe($laterThreshold->toDateTimeString()); // 後退していない
+});
+
+test('新しい取引 (閾値より後) は 1 行も畳み込まれない', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold->addSecond())->purchased()->delta(3)->create();
+
+    $result = app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    expect($result->candidates)->toBe(0);
+    expect($result->processed)->toBe(0);
+    expect(TicketLedgerEntry::query()->count())->toBe(1);
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::Grant);
+});
+
+test('境界: created_at が閾値ちょうどの行は畳み込まれる (<= で判定する)', function (): void {
+    $threshold = BillingRetention::threshold();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)
+        ->createdAt($threshold)->purchased()->delta(3)->create();
+
+    $service = app(TicketLedgerCarryForwardService::class);
+    expect($service->countExpired($threshold))->toBe(1);
+
+    $service->carryForward($threshold);
+
+    expect(TicketLedgerEntry::query()->sole()->kind)->toBe(TicketLedgerKind::CarryForward);
+});
+
+test('検証 6: 畳み込み後も signup grant の org 生涯 1 回は marker が守る', function (): void {
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['signup_tickets_granted_at' => $old])->save();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($threshold->subMonthsNoOverflow(3))->delta(20)
+        ->idempotencyKey('signup_grant:org:'.$organization->getKey())->create();
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 畳み込みで signup_grant 行 (= 部分 UNIQUE index が守っていた行) は消える。
+    // 「org 生涯 1 回」の**正本は organizations.signup_tickets_granted_at の条件付き UPDATE** であり、
+    // それは畳み込みの対象ではないので残る (index は保険であって正本ではない)。
+    expect(TicketLedgerEntry::query()->where('idempotency_key', 'like', 'signup_grant:%')->count())->toBe(0);
+    expect($organization->fresh()?->signup_tickets_granted_at)->not->toBeNull();
+});
+
+test('[既知窓] 合計 0 の未失効 monthly group は畳み込みで失効境界の情報を失う', function (): void {
+    // 7 年より古い付与と消費が相殺し、かつ失効時刻が**まだ未来**という組み合わせでのみ起きる。
+    // 残高は変わらない (0 のまま) が、消費境界の探索 (nearestMonthlyExpiry) が見る
+    // 「delta>0 の未失効 monthly 行」が消えるため、次の予約の consume_expires_at が変わる。
+    // 残高保存を優先し、この窓は受容する (詳細設計 C2b「合計 0 の繰越行を作らない」)。
+    $threshold = BillingRetention::threshold();
+    $old = $threshold->subYearNoOverflow();
+    $liveExpiry = CarbonImmutable::now()->addYearNoOverflow();
+    [$organization] = createOrganizationWithOwner();
+
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->delta(25)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->monthly($liveExpiry)->consumed(25, $liveExpiry)->create();
+    TicketLedgerEntry::factory()->forOrganization($organization)->createdAt($old)
+        ->purchased()->delta(10)->create();
+
+    $service = app(TicketLedgerService::class);
+    $balanceBefore = $service->availableTrueBalance($organization);
+
+    app(TicketLedgerCarryForwardService::class)->carryForward($threshold);
+
+    // 残高は保存される (これが最優先の不変条件)
+    expect($service->availableTrueBalance($organization))->toBe($balanceBefore);
+
+    // 一方で「未失効 monthly の失効境界」は消えている (既知窓)
+    expect(TicketLedgerEntry::query()
+        ->where('source', TicketSource::Monthly)
+        ->where('delta', '>', 0)
+        ->whereNotNull('expires_at')
+        ->count())->toBe(0);
+});

```

## 検証結果 (Round 1 対応後に再実行)

- `composer phpstan` (level 10): **OK (No errors)**
- `vendor/bin/pint --test` 相当 (`composer fix`): passed
- `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` (15 本) +
  `tests/Architecture/TicketLedgerReaderInventoryTest.php` (8 本) +
  `BillingRetentionPurgeTest` + `BillingRetentionHorizonTest` +
  `BillingRetentionTargetInventoryTest`: **65 passed / 289 assertions**
- `composer test` (全レーン): **4218 passed / 2 skipped / 0 failed**
- mutation MU11 (削除件数一致検査を外す) で「集計の後に古い行が割り込んだら fail-closed」が
  **赤くなることを実測**した。

## 確認してほしい点

1. `aggregateGroup()` (1 文で COUNT / SUM / MAX) + 削除件数一致検査という形で、
   Round 1 の [Warning] 1 が指す窓 (集計に入っていない行を削除が巻き込む) が
   **本当に閉じているか**。閉じ切っていない経路が残っていれば具体的に指摘してほしい。
2. ID 集合を固定する案を採らず件数一致検査にした判断
   (`whereIn('id', …)` は `ModelDirectFetchInvariantTest` の主キー同一性クエリ母集団に入り、
   課金バッチに目録登録の摩擦を持ち込むため) が妥当か。
3. 検査 7 / 検査 8 (フロントに台帳 kind の対応型が無いことの deny-by-default 固定) が
   Round 1 の [Warning] 2 に対する十分な証跡になっているか。

他に [Critical] / [Warning] が残っていなければ **APPROVED** を、
残っていれば具体的な箇所と修正案を挙げて **CHANGES_REQUESTED** を明記してください。
