<?php

declare(strict_types=1);

namespace App\Services\Billing\Retention;

use App\DataTransferObjects\Billing\BillingRetentionPurgeResultDto;
use App\DataTransferObjects\Billing\CarryForwardGroup;
use App\Enums\Billing\BillingRetentionTarget;
use App\Enums\Billing\TicketLedgerKind;
use App\Models\Billing\TicketLedgerEntry;
use App\Models\Organization;
use App\Services\Billing\TicketLedgerService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;
use Throwable;
use Webmozart\Assert\Assert;

/**
 * 保持期限以前のチケット台帳の畳み込み。
 *
 * **台帳の行を物理削除し、残高スナップショット 1 行へ置換する唯一の経路**である
 * (「台帳への変更の唯一の経路」ではない — `TicketLedgerService` は通常の追記と、
 * `payment_intent_id` を null → 値で埋める限定 backfill を持つ)。
 *
 * `ticket_ledger_entries` は delta 型の追記専用台帳で、残高は
 * 「未失効行の delta 合計 − reserved 予約の合計」である。古い行を単純に消すと残高が変わるため、
 * **判定を 2 段**に分ける。
 *
 *  - 第 1 段 (適格性): `created_at <= 閾値`。**これを満たさない行は 1 行も触らない**
 *  - 第 2 段 (処理方式。実行開始時に 1 度だけ確定した `$now` で判定する)
 *    - 寄与しない (`expires_at` が非 null かつ `expires_at <= now`) → **物理削除**
 *    - 寄与する (`expires_at` が null または `> now`) → **(組織, 出所, 失効時刻) ごとに
 *      delta を合算した繰越 1 行へ畳み込む**
 *
 * 第 2 段の述語は {@see TicketLedgerService} の残高集計条件
 * (`expires_at IS NULL OR expires_at > now`) の**厳密な補集合**である。ずらすと
 * 「どちらの枝にも入らない行」か「両方に入る行」が生まれる。
 * ★補集合であるのは**同一スナップショット上の述語として**である。削除と集約は別の SQL 文なので、
 *   その間に (組織行ロックを取らない追記経路が) commit した**失効済みの行**
 *   (`expires_at <= now`) は、今回の削除を通過済みで寄与側にも入らないため**次回へ持ち越される**
 *   (無期限 / 未来失効の行はその後の集約に入るので持ち越されない)。これは仕様であり、
 *   `expires_at = now` の境界でそうなることを Feature テスト N1c が固定する。
 *
 * 繰越行は説明・決済事業者の識別子・冪等キー・予約への参照・個別の付与時刻を一切引き継がない。
 * `created_at` は**畳み込んだ行の最大 `created_at`** = 集約の基準時刻である (実行時刻ではない)。
 * 実行時刻にすると繰越行が次回以降ずっと保持期限より新しい側に居座り、実行のたびに増える。
 * 集約の基準時刻なら次回も保持期限以前に留まるので、**集約キーごとに 1 行へ収束する**。
 * 合計 delta が 0 の集約キーは繰越行を作らず削除だけ行う。
 *
 * ★**決着対象 (settlement scope) は 1 つの述語で定義する**。定義がずれると
 *   「数えているのに処理されない行」が生まれる。**共有の範囲は正確に言う** —
 *   `settlementPredicate()` を直接共有するのは
 *   **組織の列挙 (`organizationsWithSettlementTargets`) と件数・監視 (`settlementScope`)** の 2 経路である。
 *   **行の処理側は同じ集合を「厳密な補集合となる 2 枝」で実装する**
 *   (`expiredScope()` = 失効済み / `contributingGroups()` + `groupScope()` = 寄与する行)。
 *   処理側を同じ述語にできないのは、削除と集約で必要な形が違うからである
 *   (前者は 1 本の DELETE、後者は集約キーごとの GROUP BY)。
 *   **補集合であること (どちらの枝にも入らない行が無い / 両方に入る行が無い) は
 *   N1・N18・境界時刻テスト・変異表が固定する**。
 *
 *       created_at <= 閾値
 *       AND ( kind != carry_forward                                   -- 取引明細
 *             OR (expires_at IS NOT NULL AND expires_at <= now) )     -- 失効した繰越行
 *
 *   繰越行のうち**まだ寄与している (無期限 or 未来に失効) もの**だけが決着対象から外れる
 *   (継続状態を表す集約レコードであり、保持期限が消す対象ではない。
 *   語の正本は {@see BillingRetentionPurgeResultDto} の docblock)。
 *   **失効した繰越行は決着対象に戻る** — 残高に寄与しなくなった瞬間に物理削除の対象であり、
 *   これを外すと「失効済みの繰越行しか持たない組織」が永久に処理されない
 *   (= 失効窓の有界化が成立しない)。
 *
 * ★**母集団は論理削除済み組織も含む**。`Organization` は `SoftDeletes` であり、
 *   global scope の効く経路で組織を列挙すると**退会済み組織の台帳が永久に畳まれない**
 *   (期限超過が残り続けて保持期限の宣言が満たせなくなる)。よって列挙とロックの両方を
 *   `withTrashed()` 起点にする。`withTrashed(` の出現は
 *   `TicketLedgerMutationSiteGateTest` が本ファイルへ件数まで固定する
 *   (テナント境界を迂回する一般的な主キー取得へ転用させない)。
 *
 * 直列化は組織行の排他ロック ({@see TicketLedgerService} が
 * 残高判定の前に取るのと同じ点) で行う。組織 1 件 = 1 トランザクションで、
 * 1 組織の失敗は他の組織を止めない。
 *
 * ★**ロックが守る範囲を誇張しない**。組織行ロックが直列化するのは
 *   **同じロックを取る経路だけ**である — 畳み込み同士と、`TicketLedgerService` のうち
 *   残高判定を伴う操作 (`grant` / `reserve` / `commit` / `release`)。
 *   一方 `grantMonthly` / `grantPurchased` / `grantSignupGrant` / `clawback` の**冪等 insert は
 *   このロックを取らない**ので、集計と削除の間に `created_at <= 閾値` の行が commit されうる。
 *   その窓を閉じるのは**ロックではなく件数照合とトランザクションの巻き戻し**である
 *   (`carryForwardOrganization` の手順 7)。二重の繰越行を防ぐのは
 *   「**同一トランザクション内で削除 → 追記**」という順序であり、
 *   ロックはそこへ他の畳み込みが割り込まないことだけを保証する。
 *
 * **append-only との関係**: モデルは `updating` / `deleting` を例外化しているが、
 * Eloquent の一括削除はモデルイベントを発火しない。append-only は
 * 「業務経路では追記しかしない」という不変条件であり、その例外は 2 種類ある —
 * **行の削除・置換は保持期限の決着 (本ファイル) だけ**、
 * **限定 metadata backfill は `TicketLedgerService::backfillPaymentIntentId()` だけ**である。
 * 許容される変更サイトの正本は
 * `Tests\Support\Architecture\TicketLedgerMutationInventory` である。
 *
 * **保証しないこと**: 真の並行実行 (別 connection + barrier) での排他の実効性は測っていない。
 * 静的に pin できるのは**トークン順の構造まで**である —
 * `TicketLedgerMutationSiteGateTest` (TLM-5) が見るのは
 * 「変更操作が同一の `DB::transaction(` の引数範囲の内側に閉じており、
 * ロック語彙がその中の最初の変更操作より前に現れる」ことだけで、
 * **ロックの受け手が組織モデルか / 削除の対象が台帳かは見ない**
 * (限界の正本は `Tests\Support\Architecture\TicketLedgerMutationScanner` の docblock)。
 */
final class TicketLedgerCarryForwardService
{
    /** 繰越行の説明 (個別明細を引き継がない集約状態であることを示す固定文言)。 */
    public const string DESCRIPTION = '保持期限以前の明細の繰越 (集約)';

    /**
     * 繰越行が値を持つ列 (集約キー + 固定文言 + 主キー・時刻)。
     *
     * ★この 2 定数が「繰越行は明細を持たない」の**正本**である。
     *   `tests/Feature/Billing/TicketLedgerCarryForwardTest.php` の列分類検査が
     *   「両者の和 == 実スキーマの全列」を deny-by-default で突き合わせるので、
     *   表に列を足したら必ずどちらかへ分類することになる。
     *
     * @var list<string>
     */
    public const array VALUED_COLUMNS = [
        'id', 'organization_id', 'delta', 'kind', 'source', 'expires_at', 'description', 'created_at',
    ];

    /**
     * 繰越行では必ず NULL になる列 (取引の明細・決済事業者の識別子・冪等キー・予約参照)。
     *
     * @var list<string>
     */
    public const array NULL_COLUMNS = [
        'reservation_id', 'granted_at', 'stripe_checkout_session_id', 'stripe_invoice_id',
        'payment_intent_id', 'purchase_amount', 'idempotency_key',
    ];

    /**
     * 保持期限以前の**決着対象**の件数 (寄与中の繰越行は数えない / 失効した繰越行は数える)。
     *
     * ★`BillingRetentionPurger` の署名に合わせて `$now` を受け取らない。dry-run 用の
     *   単発の観測なので、ここでは呼び出し時点の現在時刻で判定する。
     *   **1 回の実行の中で母集団を揃える必要がある `carryForward()` は、
     *   自分が確定した `$now` を `settlementScope()` へ直接渡す** (下記)。
     *
     * 論理削除済み組織の行も数える (組織を結合しないので global scope は効かない)。
     * 列挙側 (`organizationsWithSettlementTargets`) も `withTrashed()` なので
     * **両者の母集団は一致する**。
     */
    public function countExpired(CarbonImmutable $threshold): int
    {
        return $this->settlementScope($threshold, CarbonImmutable::now())->count();
    }

    /**
     * 保持期限以前の台帳を組織ごとに畳み込む。
     *
     * 組織 1 件 = 1 トランザクション。途中で失敗した組織は**まるごと巻き戻る**ので
     * 「繰越行だけ入って原取引が残る (= 二重計上)」も「原取引だけ消えて繰越行が無い
     * (= 残高消失)」も起きない。失敗は件数として報告し、残りの組織は進む。
     */
    public function carryForward(CarbonImmutable $threshold): BillingRetentionPurgeResultDto
    {
        // ★`$now` は 1 度だけ確定して全組織・全集約キーへ渡す。実行中に時計が進むと
        //   「失効済み」と「寄与する」のどちらの枝にも入らない行が生まれる。
        $now = CarbonImmutable::now();
        $candidates = $this->settlementScope($threshold, $now)->count();
        $processed = 0;
        $unexpectedFailures = 0;

        foreach ($this->organizationsWithSettlementTargets($threshold, $now) as $organization) {
            try {
                $processed += $this->carryForwardOrganization($organization, $threshold, $now);
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
            // 残数も**同じ `$now`** で数える (実行中に時計が進むと候補と残数の母集団がずれる)
            expiredRemaining: $this->settlementScope($threshold, $now)->count(),
        );
    }

    /**
     * **決着対象**の述語 (この 1 か所が唯一の定義。列挙・件数・監視が共有する)。
     *
     * 第 1 段の適格性 (`created_at <= 閾値`) を満たし、かつ
     * 「取引明細である」または「失効した繰越行である」行。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function settlementScope(CarbonImmutable $threshold, CarbonImmutable $now): EloquentBuilder
    {
        return TicketLedgerEntry::query()
            ->where('created_at', '<=', $threshold)
            ->where(fn (EloquentBuilder $query): EloquentBuilder => $this->settlementPredicate($query, $now));
    }

    /**
     * 決着対象の内側の述語 (relation の `whereHas` からも同じものを使う)。
     *
     * ★モデルの型引数で汎用化してある。`whereHas` の closure は
     *   `EloquentBuilder<Model>` として渡ってくるので、台帳モデルに固定すると
     *   列挙側と件数側で**同じ述語を共有できなくなる** (述語が 2 本に割れる)。
     *
     * @template TModel of Model
     *
     * @param  EloquentBuilder<TModel>  $query
     * @return EloquentBuilder<TModel>
     */
    private function settlementPredicate(EloquentBuilder $query, CarbonImmutable $now): EloquentBuilder
    {
        return $query
            ->where('kind', '!=', TicketLedgerKind::CarryForward->value)
            ->orWhere(fn (EloquentBuilder $expired): EloquentBuilder => $expired
                ->where('kind', TicketLedgerKind::CarryForward->value)
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', $now));
    }

    /**
     * 決着対象を持つ組織 (id 昇順 = ロック順序の固定)。
     *
     * ★`withTrashed()` が必須である。退会 (論理削除) は課金記録の寿命を縮めない
     * (`docs/template-divergence.md` D23)。
     * ★述語は `settlementPredicate()` を共有する (列挙と件数で条件が分岐しない)。
     *
     * @return Collection<int, Organization>
     */
    private function organizationsWithSettlementTargets(
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): Collection {
        return Organization::withTrashed()
            ->whereHas(
                'ticketLedgerEntries',
                fn (EloquentBuilder $query): EloquentBuilder => $query
                    ->where('created_at', '<=', $threshold)
                    ->where(fn (EloquentBuilder $inner): EloquentBuilder => $this->settlementPredicate($inner, $now)),
            )
            ->orderBy('id')
            ->get();
    }

    /**
     * 1 組織ぶんの畳み込み。**順序が契約である**:
     *   1. トランザクションを開く
     *   2. 組織行を `lockForUpdate`
     *   3. 寄与しない (失効済み) 行の物理削除
     *   4. 寄与する行を集約キーごとに **1 文**で集計 (件数 / 合計 / 最大 created_at / 繰越行数)
     *   5. 既に繰越 1 行だけの集約キーは短絡 (収束)
     *   6. 集約キーの行を削除
     *   7. **件数照合** (不一致は例外 → 組織ごと巻き戻る)
     *   8. 繰越行の追記 (合計 0 は作らない)
     *
     * ★手順 7 の照合には**削除した行数の全量**を使い、`processed` には**決着対象の行数**を使う。
     *   2 つは意味が違う (前者は「集計した集合と削除した集合が同じか」、
     *   後者は「保持期限の決着が何行進んだか」)。混ぜると
     *   `candidates = processed + expiredRemaining` が成り立たなくなる。
     *
     * @return int 決着した行数 (**決着対象のうち消えた行数**。再集約のために消して
     *             作り直した寄与中の繰越行は数えない)
     */
    private function carryForwardOrganization(
        Organization $organization,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): int {
        // ★closure へは **`Organization` モデルそのもの**を渡す (id を先に取り出さない)。
        //   `whereKey($organization->getKey())` の形にすることで、識別子が
        //   **解決済みモデル由来**であることが走査器から見え、`DirectFetchInventory` の
        //   母集団に入らない (id を捕まえた `whereKey($organizationId)` にすると候補になる)。
        return DB::transaction(function () use ($organization, $threshold, $now): int {
            // 残高判定・台帳追記の直列化点 (TicketLedgerService::lockOrganizationRow と同じ点)。
            // 論理削除済み組織も対象なので withTrashed で取る。
            Organization::withTrashed()->whereKey($organization->getKey())->lockForUpdate()->firstOrFail();

            $organizationId = $organization->getKey();
            Assert::integer($organizationId, '組織 id が解決できません (畳み込みは中止する)');

            // (a) 残高に寄与しない期限以前の行 (失効済み) → 物理削除。
            //     繰越行が失効済みになった場合もここで消える (= 失効窓の有界化)。
            $processed = $this->deletedCount($this->expiredScope($organizationId, $threshold, $now)->delete());

            // (b) 残高に寄与する期限以前の行 → 集約キーごとに畳み込む。
            //     処理順は**決定的**にする (集約キーの並び順)。1 つの集約キーで失敗したときに
            //     どこまで進んでいたかが実行のたびに変わると、巻き戻しの契約を測れない。
            foreach ($this->contributingGroups($organizationId, $threshold, $now) as $group) {
                // 既に繰越 1 行だけなら何もしない (無駄な入れ替えを避ける = 収束の短絡)
                if ($group->rowCount === 1 && $group->carryForwardRows === 1) {
                    continue;
                }

                $deleted = $this->deletedCount(
                    $this->groupScope($organizationId, $threshold, $now, $group)->delete(),
                );

                // **集計した集合と削除した集合が一致することを確認する**。
                // 組織行ロックは台帳への insert を止めない (grantMonthly / grantPurchased は
                // ロックを取らない冪等 insert である)。集計と削除の間に
                // `created_at <= 閾値` の行が commit されると、**合計に入っていない行を
                // 削除が巻き込む** = その枚数ぶん残高が消える。件数の不一致で検出し、
                // トランザクションごと巻き戻す (次回の実行で同じ組織を再処理して収束する)。
                if ($deleted !== $group->rowCount) {
                    throw new RuntimeException(
                        '畳み込みの集計対象と削除対象が一致しません (残高を失わないため巻き戻す)',
                    );
                }

                // ★`processed` は**決着対象のうち決着した件数**である (削除した行数ではない)。
                //   寄与中の繰越行は「再集約のために消して作り直した行」であって決着ではないので
                //   数えない — 数えると `candidates` と母集団がずれ、
                //   `candidates = processed + expiredRemaining` の恒等式が壊れる。
                //   寄与する群に入る繰越行は定義上すべて寄与中なので、決着した明細の数は
                //   `rowCount - carryForwardRows` である。
                $processed += $group->rowCount - $group->carryForwardRows;

                // 合計 0 の繰越行は作らない (残高に寄与しない行を増やさない)
                if ($group->deltaSum !== 0) {
                    $this->appendCarryForward($organizationId, $group);
                }
            }

            return $processed;
        });
    }

    /** Eloquent の一括削除は driver 実装まで型が確定しないので境界で数値に確定させる。 */
    private function deletedCount(mixed $result): int
    {
        Assert::integer($result, '削除件数が整数で返らない (畳み込みを中止する)');

        return $result;
    }

    /**
     * 残高に寄与しない (既に失効した) 期限以前の行。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function expiredScope(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): EloquentBuilder {
        return TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $now);
    }

    /**
     * 集約キーごとの集計結果。
     *
     * ★**クエリビルダで集計する** (Eloquent 経由だと `source` が列挙型へ cast され、
     *   その値をさらに `TicketSource::from()` へ渡す二重変換で実行時に落ちる)。
     * ★**件数・合計・最大 created_at・繰越行数を 1 文で取る**。分けて発行すると文ごとに
     *   snapshot が変わり (READ COMMITTED)、「合計には入っていないが件数には入っている」行が
     *   生まれて残高保存の検査そのものが壊れる。
     *
     * @return list<CarryForwardGroup>
     */
    private function contributingGroups(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
    ): array {
        $rows = DB::table('ticket_ledger_entries')
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->where(function (QueryBuilder $query) use ($now): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->groupBy('source', 'expires_at')
            ->selectRaw(
                'source, expires_at, SUM(delta) AS delta_sum, MAX(created_at) AS max_created_at, '
                .'COUNT(*) AS row_count, SUM(CASE WHEN kind = ? THEN 1 ELSE 0 END) AS carry_forward_rows',
                [TicketLedgerKind::CarryForward->value],
            )
            ->orderBy('source')
            ->orderBy('expires_at')
            ->get();

        $groups = [];
        foreach ($rows as $row) {
            // クエリビルダの行は stdClass である。境界 DTO は stdClass だけを受けるので
            // ここで型を確定させる (driver 差で別の型が来たら fail-closed で落とす)。
            Assert::isInstanceOf($row, stdClass::class, '集約行が stdClass ではない (畳み込みを中止する)');
            $groups[] = CarryForwardGroup::fromRow($row);
        }

        return $groups;
    }

    /**
     * 集約キー 1 件ぶんの行 (削除対象)。**繰越行も含む** (合算して 1 行へ置き換えるため)。
     *
     * @return EloquentBuilder<TicketLedgerEntry>
     */
    private function groupScope(
        int $organizationId,
        CarbonImmutable $threshold,
        CarbonImmutable $now,
        CarryForwardGroup $group,
    ): EloquentBuilder {
        $query = TicketLedgerEntry::query()
            ->where('organization_id', $organizationId)
            ->where('created_at', '<=', $threshold)
            ->where(function (EloquentBuilder $inner) use ($now): void {
                $inner->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            });

        $query = $group->source === null
            ? $query->whereNull('source')
            : $query->where('source', $group->source->value);

        return $group->expiresAt === null
            ? $query->whereNull('expires_at')
            : $query->where('expires_at', $group->expiresAt);
    }

    /**
     * 繰越行の追記 (生成点で初期状態を明示代入する。AGENTS.md 実装規約)。
     *
     * 所有権キー (`organization_id`) と FK (`reservation_id`) は relation 経由で代入する。
     */
    private function appendCarryForward(int $organizationId, CarryForwardGroup $group): void
    {
        $entry = new TicketLedgerEntry;
        $entry->organization()->associate($organizationId);
        $entry->delta = $group->deltaSum;
        $entry->kind = TicketLedgerKind::CarryForward;
        $entry->source = $group->source;               // 出所は保存する (集約キー)
        $entry->expires_at = $group->expiresAt;        // 残高の窓は保存する (集約キー)
        $entry->description = self::DESCRIPTION;
        $entry->reservation()->associate(null);        // 予約への参照は引き継がない
        $entry->granted_at = null;                     // 個別の付与時刻は引き継がない
        $entry->stripe_checkout_session_id = null;     // 決済事業者の識別子は引き継がない
        $entry->stripe_invoice_id = null;
        $entry->payment_intent_id = null;
        $entry->purchase_amount = null;
        $entry->idempotency_key = null;                // 冪等キーは引き継がない
        // created_at を明示代入してから save する (Eloquent は CREATED_AT が dirty なら上書きしない)。
        // これは集約の基準時刻であり、実行時刻ではない (収束の要)。
        $entry->created_at = $group->maxCreatedAt;
        $entry->save();
    }
}
