# Round 2: Round 1 の指摘への対応

Round 1 の [Critical] 1 件 / [Warning] 4 件 / [Suggestion] 1 件を**すべて対応**した。
追跡の結果、[Warning] の 1 件 (2FA) からは **実在の詰み (Critical 相当)** が見つかったので併せて直した。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

## [Critical] 順序異常行 (`purge_after < requested_at`) が due 抽出に残り削除されうる

- **判断**: 対応する (指摘は正しい)
- **根拠**: `unexpected` として report しながら**その行を物理削除していた**。CHECK 制約が壊れた
  ときに「猶予が経過していないユーザーを早期に消す」向きへ倒れる fail-open で、
  defense-in-depth の意図と真逆だった。
- **対応内容**:
  - `AccountDeletionStateDto::isNormalized()` を新設し (両列非 null かつ `purgeAfter >= requestedAt`)、
    `isDue()` の前提を `isPending()` から `isNormalized()` へ変更 = **DTO 層で fail-closed**。
  - 執行バッチの due 抽出に `whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')` を追加
    (クエリ層でも同じ判断。二重防御)。
  - テスト 2 本を追加:
    - `PurgeDeletionRequestsCommandTest`「期限 < 予約時刻の非正規行は削除されず report + FAILURE」
    - `AccountDeletionGraceTest`「期限 < 予約時刻の非正規な組は執行されない (fail-closed)」
  - **mutation M30 で赤化を実測**して `mutation-evidence.md` に記録。

## [Warning] AutoRechargeTriggerJob の検証が `jobs` 全体の件数になっている

- **判断**: 対応する
- **根拠**: 指摘どおり (a) 退会通知 job で汚染されうる、(b) `reserve()` へ到達する経路を
  1 本も叩いていない、の 2 点で主張を証明できていなかった。
- **対応内容**:
  - `queuedJobClasses()` ヘルパを追加し、`jobs.payload` の `displayName` を復元して
    **クラス名で判定**する (退会通知 job は業務ジョブでないため除外)。sweep 側の assertion も置換。
  - **`reserve()` に至る業務経路を実際に叩くテストを追加**:
    予約中ユーザーで `POST /projects/{p}/manuals/{m}/analyze` (fixture 一式を作成) →
    **409** / `AnalysisJob` 0 件 / `AutoRechargeTriggerJob` 不在 / 業務 job 0 件。

## [Warning] 2FA 必須組織の到達性テストが準拠済みユーザーで、詰みを検出できない

- **判断**: 対応する。**追跡した結果、実在の詰み (Critical 相当) が見つかった**
- **根拠**: 2FA 強制ゲートは priority list で凍結より**前**に走る。未準拠ユーザーの取消 DELETE は
  2FA ゲートが `settings.security` へ倒すが、その `settings.security` は**凍結の allowlist に
  無かった**ため凍結が `/settings` へ倒し返す = **行き先のない相互ブロック**になっていた
  (設計の allowlist の見落とし)。
- **対応内容**:
  - `AccountDeletionFreezeAllowance::SettingsSecurity` を追加 (30 文字以上の根拠つき)。
    件数 pin を 16 → 17 に更新。
  - テストを書き換え: 未準拠ユーザーは取消が `settings.security` へ倒れること、
    `settings.security` / `settings` に**到達できる**こと、準拠を達成すれば取消できることを固定。
  - `docs/architecture.md` の §退会の猶予期間つき削除 に「2FA 必須組織との相互作用」を追記。
  - **mutation M31 で赤化を実測**して記録。

## [Warning] 同一秒内の取消 → 再予約は tuple が一致し古い job も送られる

- **判断**: 対応する (**主張を狭める**方向。実装は変えない)
- **根拠**: 指摘は正しい。ただし同一秒内の再予約では新旧の `purgeAfter` が**同一の値**になるため、
  利用者に誤った期日が届くことはない (実害がない)。秒未満まで比較しても DB 側が
  `timestamp(0)` で丸めるため解決にならず、精度を上げる改修は効果に対して複雑さが勝る
  (思考原則 2)。
- **対応内容**:
  - `AccountDeletionStateDto::matches()` と通知クラスの docblock に
    「保証するのは**値が変わった**予約に対して古い job を送らないことまで」「同一秒内の
    取消 → 再予約は区別できないが、その場合は期日が同一なので誤情報にならない」を明記。
  - テスト名を「値が変わった再予約では古い通知 job が送られない (同一秒内の再予約は区別しない)」へ変更し、
    **同一秒内の再予約では `['mail']` が返る**ことを対照として固定 (誇張しない)。

## [Warning] `U - A` の実 HTTP sweep が parameterless route だけ

- **判断**: 対応する
- **根拠**: 指摘どおり、有効な自組織 parameter を持つ route は 1 本も実 HTTP で測っていなかった。
- **対応内容**: 代表 route を behavioral に追加 —
  `projects.show` / `projects.edit` / `projects.update` を**自組織の実在 project** で叩いて
  `/settings` へ 302 することを固定 (さらに上記の `projects.manuals.analyze` も加わる)。
  全件 sweep を parameterized まで広げないのは、ダミー id ではテナント境界 404 が先に閉じる
  (それが正しい順序である) ため。この限界はテストの docblock に明記済み。

## [Suggestion] 検査 3 の役割をコメントで明確化

- **判断**: 対応する
- **対応内容**: `AccountDeletionFreezeRouteGateTest` 検査 3 の冒頭に、
  「守るのは宣言と実装の一致であり、allowlist の増加は件数 pin / 名指し pin が担う
  (mutation M5 で実測)」を追記。


## 修正後の該当ファイル全文 (git diff。Round 1 から変わったファイルのみ)

```diff
diff --git a/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
new file mode 100644
index 0000000..2639055
--- /dev/null
+++ b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
@@ -0,0 +1,111 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Console\Commands\Account;
+
+use App\Models\User;
+use App\Services\Organization\OrganizationMembershipService;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Command;
+use Illuminate\Database\Eloquent\Builder;
+use Illuminate\Database\Eloquent\Collection;
+use Illuminate\Validation\ValidationException;
+use RuntimeException;
+use Throwable;
+
+/**
+ * 退会予約 (猶予期間つき削除) の日次執行。
+ *
+ * ★**判定コードを分岐させない**。期限到来の再確認は
+ *   `OrganizationMembershipService::executeAccountDeletionRequest()` が行い、削除そのものは
+ *   既存の `deleteAccount()` をそのまま呼ぶ (課金ガードのロック下再評価をそのまま継承する)。
+ *
+ * ★終了コードは **2 分類**である。退会ブロッカー (ValidationException) は**業務上の保留**で
+ *   SUCCESS のまま次へ進み、インフラ障害や不変条件違反は `unexpected` として FAILURE を返す。
+ *   全件 DB 障害でも SUCCESS を返すと scheduler の失敗通知も終了コード監視も機能しなくなる
+ *   (`report()` の成功自体も保証されない)。
+ *
+ * ★ログには **件数のみ**。user id / email を出さない (PII 非出力。既存
+ *   `billing:detect-orphan-billing-organizations` の報告契約と同水準)。
+ *
+ * ★`chunkById` を使う (走査中に行が消えても飛ばない)。`chunk` は使わない。
+ */
+class PurgeDeletionRequestsCommand extends Command
+{
+    protected $signature = 'account:purge-deletion-requests
+        {--apply : 実削除する (未指定は dry-run)}';
+
+    protected $description = '猶予期間を過ぎた退会予約を執行する (既定 dry-run)';
+
+    public function handle(OrganizationMembershipService $membership): int
+    {
+        $apply = $this->option('apply') === true;
+        $due = 0;
+        $deleted = 0;
+        $blocked = 0;      // 業務上の保留 (ValidationException)
+        $unexpected = 0;   // インフラ障害 / 不変条件違反
+
+        // 片列だけの非正規行を **due 走査より前に** 数える。DB の CHECK 制約に対する
+        // defense-in-depth であり、制約の代替ではない (状態機械を閉じているのは DB 側)。
+        // 件数だけを report し、user id は出さない。
+        $invalidStateCount = User::query()
+            ->where(function (Builder $query): void {
+                $query->whereNull('deletion_requested_at')->whereNotNull('deletion_purge_after');
+            })
+            ->orWhere(function (Builder $query): void {
+                $query->whereNotNull('deletion_requested_at')->whereNull('deletion_purge_after');
+            })
+            // CHECK 制約 2 本と対称にする (制約が無効化されたとき、期限が予約時刻より前の行が
+            // 早期削除候補に入る異常も検知できる)
+            ->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
+            ->count();
+        if ($invalidStateCount > 0) {
+            $unexpected += $invalidStateCount;
+            report(new RuntimeException(
+                "退会予約列が非正規な行を検出: count={$invalidStateCount}",
+            ));
+        }
+
+        // 片列だけの非正規行を due に数えないため両列を条件にする
+        // (DTO の pending 定義「両列が揃う」と一致させる)。
+        User::query()
+            ->whereNotNull('deletion_requested_at')
+            ->whereNotNull('deletion_purge_after')
+            // ★**非正規な組 (期限 < 予約時刻) を due に入れない** (fail-closed)。
+            //   入れると「猶予が経過していない行を早期に物理削除する」向きに倒れる。
+            //   同じ判断は AccountDeletionStateDto::isDue() 側にもあり (二重防御)、
+            //   非正規行は上の invalidStateCount が件数だけ report して FAILURE にする。
+            ->whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')
+            ->where('deletion_purge_after', '<=', CarbonImmutable::now())
+            ->orderBy('id')
+            ->chunkById(100, function (Collection $users) use (&$due, &$deleted, &$blocked, &$unexpected, $apply, $membership): void {
+                /** @var Collection<int, User> $users */
+                foreach ($users as $user) {
+                    $due++;
+                    if (! $apply) {
+                        continue;
+                    }
+                    try {
+                        // ロック取得後に「予約が生きているか」「期限到来か」を再確認する
+                        // (抽出後に取消されたユーザーを古いスナップショットで消さない)。
+                        if ($membership->executeAccountDeletionRequest($user)) {
+                            $deleted++;
+                        }
+                    } catch (ValidationException $e) {
+                        // 退会ブロッカー = **業務上の保留**。予約は維持し次へ進む。
+                        $blocked++;
+                        report($e);
+                    } catch (Throwable $e) {
+                        // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
+                        $unexpected++;
+                        report($e);
+                    }
+                }
+            });
+
+        $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");
+
+        return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
+    }
+}
diff --git a/app/DataTransferObjects/Account/AccountDeletionStateDto.php b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
new file mode 100644
index 0000000..c8ba17e
--- /dev/null
+++ b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
@@ -0,0 +1,127 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Account;
+
+use App\Models\User;
+use Carbon\CarbonImmutable;
+
+/**
+ * 退会予約 (猶予期間つき削除・凍結方式) の状態スナップショット。
+ *
+ * users 行の 2 列 (`deletion_requested_at` / `deletion_purge_after`) をそのまま写した値
+ * オブジェクトで、**予約中かどうかの判定はこの DTO に一本化する** (middleware / Service /
+ * Command / 画面 props が同じ述語を見る)。
+ *
+ * ★`isPending()` は**両列が揃っているときだけ** true を返す = 片列だけの非正規状態を
+ *   「予約中」と認めない。DB 側の CHECK 制約 (users_deletion_request_pair_check) と同じ定義で、
+ *   制約が無効化された場合でもアプリ側の判定がぶれない。
+ * ★猶予日数は列を持たず `purgeAfter - requestedAt` から**導出**する (2 つの表現を持たない)。
+ */
+final readonly class AccountDeletionStateDto
+{
+    public function __construct(
+        public ?CarbonImmutable $requestedAt,
+        public ?CarbonImmutable $purgeAfter,
+    ) {}
+
+    /**
+     * users 行から組み立てる。
+     *
+     * cast は `immutable_datetime` だが、`CarbonImmutable::instance()` で明示変換して
+     * **cast 設定の変更に対して二重に守る** (cast が 'datetime' へ戻されても型が崩れない)。
+     */
+    public static function fromUser(User $user): self
+    {
+        $requestedAt = $user->deletion_requested_at;
+        $purgeAfter = $user->deletion_purge_after;
+
+        return new self(
+            $requestedAt === null ? null : CarbonImmutable::instance($requestedAt),
+            $purgeAfter === null ? null : CarbonImmutable::instance($purgeAfter),
+        );
+    }
+
+    /** 予約中か (両列が揃っているときだけ true = 片方だけの非正規状態を pending と認めない)。 */
+    public function isPending(): bool
+    {
+        return $this->requestedAt !== null && $this->purgeAfter !== null;
+    }
+
+    /**
+     * 列の組が**正規**か (両列が揃い、かつ期限が予約時刻以降)。
+     *
+     * DB の CHECK 制約 2 本 (`users_deletion_request_pair_check` /
+     * `users_deletion_purge_after_order_check`) と同じ定義で、制約が無効化された場合でも
+     * アプリ側が同じ判断をするための述語。
+     */
+    public function isNormalized(): bool
+    {
+        return $this->requestedAt !== null
+            && $this->purgeAfter !== null
+            && $this->purgeAfter->greaterThanOrEqualTo($this->requestedAt);
+    }
+
+    /**
+     * 執行期限が到来しているか (比較演算子ではなく Carbon API を使う。意図と型が明確)。
+     *
+     * ★**非正規な組 (期限 < 予約時刻) は決して due にしない** (fail-closed)。
+     *   `isPending()` ではなく `isNormalized()` を前提にするのは、CHECK 制約が壊れた場合に
+     *   「猶予が経過していない行を早期に物理削除する」向きに倒れるのを防ぐためである
+     *   (非正規行は日次バッチが件数だけ report し、削除せず FAILURE で終わる)。
+     */
+    public function isDue(CarbonImmutable $now): bool
+    {
+        return $this->isNormalized()
+            && $this->purgeAfter?->lessThanOrEqualTo($now) === true;
+    }
+
+    /**
+     * 予約が「この (requestedAt, purgeAfter) の組」と一致するか。
+     *
+     * キュー実行時の再確認に使う (取消済み / 再予約で値が変わった場合に古い通知を送らない)。
+     * 秒未満の丸め差で偽陰性にならないよう、**秒精度**で比較する。
+     *
+     * ★**保証範囲 (誇張しない)**: 秒精度で比較するため、**同一秒内に取消 → 再予約**が起きると
+     *   組が一致し、古い job も「現在の予約」と判定される。ただしその場合の値は新しい予約と
+     *   同一 (= 案内する期日も同一) なので、利用者に誤った期日が届くことはない。
+     *   ここが保証するのは「**値が変わった**予約に対して古い job を送らない」ことまでである。
+     */
+    public function matches(CarbonImmutable $requestedAt, CarbonImmutable $purgeAfter): bool
+    {
+        return $this->isPending()
+            && $this->requestedAt?->startOfSecond()->equalTo($requestedAt->startOfSecond()) === true
+            && $this->purgeAfter?->startOfSecond()->equalTo($purgeAfter->startOfSecond()) === true;
+    }
+
+    /** 猶予日数 (表示用。導出値であり列を持たない)。未予約なら null。 */
+    public function graceDays(): ?int
+    {
+        if ($this->requestedAt === null || $this->purgeAfter === null) {
+            return null;
+        }
+
+        return (int) round($this->requestedAt->diffInDays($this->purgeAfter));
+    }
+
+    /** 執行予定日のラベル (flash 文言用)。未予約なら null。 */
+    public function purgeAfterLabel(): ?string
+    {
+        return $this->purgeAfter?->format('Y年n月j日');
+    }
+
+    /**
+     * Inertia props 形。日時は **ISO 8601 文字列** (クライアントで Date に起こす)。
+     *
+     * @return array{requestedAt: string|null, purgeAfter: string|null, graceDays: int|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'requestedAt' => $this->requestedAt?->toIso8601String(),
+            'purgeAfter' => $this->purgeAfter?->toIso8601String(),
+            'graceDays' => $this->graceDays(),
+        ];
+    }
+}
diff --git a/app/Enums/Account/AccountDeletionFreezeAllowance.php b/app/Enums/Account/AccountDeletionFreezeAllowance.php
new file mode 100644
index 0000000..f62c2de
--- /dev/null
+++ b/app/Enums/Account/AccountDeletionFreezeAllowance.php
@@ -0,0 +1,118 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Account;
+
+/**
+ * 退会予約中 (凍結) に**通してよい route 名**の目録。**deny-by-default**。
+ *
+ * ここに無い route は予約中に遮断され `/settings` (取消ボタンのある画面) へ 302 する。
+ *
+ * ★**wildcard を書かない** (route 名の exact case のみ)。`billing.*` のような namespace 指定を
+ *   許すと購入・新規契約・自動チャージ有効化まで一緒に通り、凍結の意味が消える。
+ * ★母集団 (`U` = 凍結 middleware が付いた全 route) との関係は **`A ⊆ U`**。
+ *   `U` に無い route 名は書けない (死に登録の防止)。実装と宣言の一致・母集団の内外は
+ *   `tests/Architecture/AccountDeletionFreezeRouteGateTest.php` が機械固定する。
+ *
+ * ★**`settings.account.destroy` (即時削除) は入れない**。予約中のユーザーが表明した意思は
+ *   「30 日後に削除」であり、その状態で即時削除の口を開けておくと**猶予が守ろうとしているもの
+ *   (誤操作) をそのまま通してしまう** (30 日猶予の迂回口になる)。「今すぐ消したい」なら
+ *   **取消 → 即時削除**の 2 手を踏む (一貫した状態機械でありユーザーに説明できる)。
+ * ★**`notifications.open` は入れない**。POST + 303 で通知の遷移先へ飛ばす route であり、
+ *   入れると「通知経由なら業務 route / dashboard / checkout に到達できる」抜け道になる。
+ *   通知は `notifications.index` で読めるので rescue surface の役割は満たされる
+ *   (「遷移先ごとに判定する」分岐は作らない = 凍結の判定点を 2 箇所に増やさない)。
+ * ★**`billing.auto-recharge.update` は入れない**。同じ更新 endpoint が有効化・閾値変更・
+ *   数量変更を受けるため、通すと**新しい課金責務を作る入口**になる。凍結中に自動チャージが
+ *   発火する経路は構造的に存在しない (`AutoRechargeTriggerJob` を dispatch するのは
+ *   `TicketLedgerService::reserve()` だけで、それを呼ぶ業務 route は凍結で全部止まる)。
+ */
+enum AccountDeletionFreezeAllowance: string
+{
+    // --- 取消に到達するための step-up (satisfier) ---
+    case RecentAuthConfirm = 'recent-auth.confirm';
+    case RecentAuthStatus = 'recent-auth.status';
+    case RecentAuthPassword = 'recent-auth.password';
+    // --- 取消 UI と取消そのもの ---
+    case Settings = 'settings';
+    case SettingsSecurity = 'settings.security';
+    case DeletionRequestDestroy = 'settings.account.deletion-request.destroy';
+    // --- 退会ブロッカー (生きた課金責務) の解消 ---
+    case BillingIndex = 'billing.index';
+    case BillingPortal = 'billing.portal';
+    // --- 退会ブロッカー (孤児メンバー) の解消 ---
+    case OrganizationSwitch = 'organizations.switch';
+    case OrganizationSettings = 'organizations.settings';
+    case TransferOwnership = 'organizations.transfer-ownership';
+    case MemberUpdate = 'organizations.members.update';
+    case MemberDestroy = 'organizations.members.destroy';
+    case InvitationRevoke = 'organizations.invitations.revoke';
+    // --- 予約・執行不能を知る手段 (読むだけ) ---
+    case NotificationsIndex = 'notifications.index';
+    case NotificationsReadAll = 'notifications.read-all';
+    case NotificationsRead = 'notifications.read';
+
+    /**
+     * 通す根拠 (**30 文字以上**。gate が長さを検査する)。
+     *
+     * 「凍結中でもこれが無いと詰む」を 1 case ずつ書く。書けないなら通してはいけない。
+     */
+    public function rationale(): string
+    {
+        return match ($this) {
+            self::RecentAuthConfirm => '取消自体に step-up は不要だが、ブロッカー解消経路である'
+                .'オーナー移譲 (organizations.transfer-ownership) が recent-auth を持つため、'
+                .'この確認画面に到達できないと移譲ができず退会も取消後の再削除もできず詰む。',
+            self::RecentAuthStatus => 'クライアント主導 step-up の precheck (XHR)。これを塞ぐと '
+                .'/settings 上の各操作が鮮度判定に失敗し、再認証モーダルを出せないまま'
+                .'無反応になる (押したのに何も起きない詰み)。読み取りのみで状態を変えない。',
+            self::RecentAuthPassword => 'step-up の password satisfier。確認画面に到達できても'
+                .'ここが塞がると再認証が完了せず、オーナー移譲によるブロッカー解消が不可能になる。'
+                .'認証手段の増減はせず、鮮度を更新するだけの経路である。',
+            self::Settings => '退会予約バナーと**取消ボタン**が置かれた画面そのもの。凍結の着地先で'
+                .'あり、ここを通さないと 302 が自分自身へ無限ループし、誤操作救済が成立しない。',
+            self::SettingsSecurity => '2FA 必須組織の**未準拠**ユーザーにとって唯一の脱出口。'
+                .'2FA 強制ゲートは凍結より前に走り、未準拠だと取消 DELETE を settings.security へ倒す。'
+                .'ここを通さないと「取消は 2FA ゲートに阻まれ、2FA 設定は凍結に阻まれる」'
+                .'相互ブロックの詰みになる (実測して発見)。ログイン手段の管理面であり課金責務を作らない。',
+            self::DeletionRequestDestroy => '退会予約の取消そのもの。誤操作救済の本体であり、'
+                .'凍結中に必ず実行できなければ猶予期間を設けた意味が消える (取り消せない詰み)。',
+            self::BillingIndex => '退会ブロッカーのひとつ「生きた課金責務」を解消する導線の起点。'
+                .'解約手段に到達できないと、ブロックされたまま 30 日後の執行も失敗し続ける。',
+            self::BillingPortal => 'Customer Portal のセッション生成。PortalConfigurationSpec が '
+                .'subscription_update=false / subscription_cancel=at_period_end を宣言しており、'
+                .'Portal からは**解約と支払い方法更新だけ**ができる = 責務を減らす方向のみ。'
+                .'**この spec が変われば通してよい前提が崩れる** (gate が spec を pin する)。',
+            self::OrganizationSwitch => '課金・組織設定は current org スコープ (route parameter を'
+                .'持たない) のため、別組織のブロッカーを解消するには切替が必須。切替自体は'
+                .'所属組織の間の移動でしかなく、新しい責務を作らない。',
+            self::OrganizationSettings => 'オーナー移譲・メンバー整理の操作 UI が置かれた画面。'
+                .'ブロッカー解消の入口であり、閲覧できないと「次の一手」が押せず詰む。',
+            self::TransferOwnership => '退会ブロッカー「唯一 Owner かつ他メンバーが残る」の唯一の'
+                .'解消手段。凍結中に実行できないとブロッカーが永久に残り、執行が毎日失敗し続ける。',
+            self::MemberUpdate => 'メンバー整理 (ロール変更) によるブロッカー解消経路。'
+                .'組織の owner 集合を正す操作であり、新しい課金責務も新しいデータも作らない。',
+            self::MemberDestroy => '孤児化するメンバーを外すことでブロッカーを解消する経路。'
+                .'退会条件を満たすための除去操作であり、責務を増やす方向には働かない。',
+            self::InvitationRevoke => '送信済み招待の取り消し。予約中に新しいメンバーが増えると'
+                .'ブロッカーが再発するため、**招待の送信は通さず取り消しだけ**を通す非対称にする。',
+            self::NotificationsIndex => '予約内容 (いつ削除されるか) と執行結果を本人が読む手段。'
+                .'メールが届かない環境でも状況を把握できる rescue surface として必要。',
+            self::NotificationsReadAll => '通知一覧の一括既読化。既読フラグを進めるだけで業務状態も'
+                .'課金も動かさず、一覧が読める以上ここを塞ぐ理由がない (未読が永久に残る)。',
+            self::NotificationsRead => '通知 1 件の既読化。read-all と同じく既読フラグのみを'
+                .'更新する読み取り面の操作で、遷移も伴わない (遷移する open は通さない)。',
+        };
+    }
+
+    /**
+     * 通してよい route 名の集合。
+     *
+     * @return list<string>
+     */
+    public static function values(): array
+    {
+        return array_map(static fn (self $case): string => $case->value, self::cases());
+    }
+}
diff --git a/app/Notifications/Account/AccountDeletionRequestedNotification.php b/app/Notifications/Account/AccountDeletionRequestedNotification.php
new file mode 100644
index 0000000..0b21e66
--- /dev/null
+++ b/app/Notifications/Account/AccountDeletionRequestedNotification.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Notifications\Account;
+
+use App\DataTransferObjects\Account\AccountDeletionStateDto;
+use App\Models\User;
+use Carbon\CarbonImmutable;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Notifications\Messages\MailMessage;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Config;
+
+/**
+ * 退会 (猶予期間つき削除) を予約したことのメール通知。
+ *
+ * 本人が意図していない予約 (セッション奪取 / 誤操作) に**気づく**ための経路であり、
+ * 取消の期日と導線を必ず載せる。
+ *
+ * 【`ShouldQueue` + 予約 tx 内 dispatch】
+ * AGENTS.md ドメイン規約 11 に従い、業務状態の保存とキュー投入は同一トランザクション内で行う
+ * (`afterCommit` に依存しない)。`ShouldBeUnique` は使わない — unique lock は dispatch 時に
+ * 取得され rollback で解放されないため業務 tx 内 dispatch と両立しない
+ * (`AutoRechargeTriggerJob` から撤去済みの先例がある)。送達台帳も新設しない。
+ *
+ * 【保証範囲 (誇張しない)】
+ * 保証するのは **「予約操作からの job 生成は最大 1 件」**だけである
+ * (`OrganizationMembershipService::requestAccountDeletion()` が予約中なら冪等 no-op で
+ * 通知を発火しないため、二重 POST でも job は 1 つしか作られない)。
+ * **job の実行と外部配送は重複しうる best-effort** — 外部メールサービスが受理した後に
+ * worker が完了記録の前で停止すれば retry で再送されうる。「at-most-once」ではないし、
+ * 「同一 payload の job を 2 つ投入しても 1 通」でもない。
+ * 再確認は**秒精度**の値一致で行うため、**同一秒内の取消 → 再予約**は区別できない
+ * (ただしその場合は新旧の期日が同一なので、誤った期日が届くことはない)。
+ */
+final class AccountDeletionRequestedNotification extends Notification implements ShouldQueue
+{
+    use Queueable;
+
+    public function __construct(
+        private readonly CarbonImmutable $requestedAt,
+        private readonly CarbonImmutable $purgeAfter,
+    ) {}
+
+    /**
+     * 送信直前に予約の生存を再確認する。**これは誤通知の防止であって dedup ではない**。
+     *
+     * dispatch の位置だけでは誤通知を防げない — 「dispatch がどこか」と「job が参照する状態・
+     * 実行可能時点」は別問題である。aicue は `QueueDispatchAtomicityGuard` が
+     * driver=database / キュー DB = 業務 DB / after_commit=false を全環境の起動時に
+     * fail-closed 検査するため commit 前実行は構造的に起きないが、**それは前提であって
+     * 保証ではない**。
+     *
+     * ★**フォールバックしない**。`fresh()` が null = 執行済みで user 行が無い、という意味なので、
+     *   シリアライズ済みの削除前スナップショットへ倒すと「執行済みなのに送る」逆転が起きる。
+     *
+     * @return list<string>
+     */
+    public function via(object $notifiable): array
+    {
+        if (! $notifiable instanceof User) {
+            return [];
+        }
+
+        $fresh = $notifiable->fresh();
+        if (! $fresh instanceof User) {
+            return [];
+        }
+
+        return AccountDeletionStateDto::fromUser($fresh)->matches($this->requestedAt, $this->purgeAfter)
+            ? ['mail']
+            : [];
+    }
+
+    public function toMail(object $notifiable): MailMessage
+    {
+        $appName = Config::string('app.name');
+        $deadline = $this->purgeAfter->format('Y年n月j日 H:i');
+
+        return (new MailMessage)
+            ->subject('【'.$appName.'】退会のお手続きを受け付けました')
+            ->line("{$appName} の退会 (アカウント削除) を受け付けました。")
+            ->line("削除を実行する予定日時: {$deadline}")
+            ->line('それまでは設定画面からいつでも取り消せます。心当たりがない場合は、'
+                .'取り消したうえでパスワードの変更をご検討ください。')
+            ->action('退会を取り消す', route('settings'))
+            ->line('削除後はデータを復元できません。');
+    }
+}
diff --git a/tests/Feature/Auth/AccountDeletionFreezeTest.php b/tests/Feature/Auth/AccountDeletionFreezeTest.php
new file mode 100644
index 0000000..7089ec1
--- /dev/null
+++ b/tests/Feature/Auth/AccountDeletionFreezeTest.php
@@ -0,0 +1,344 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Account\AccountDeletionFreezeAllowance;
+use App\Enums\OrganizationRole;
+use App\Http\Middleware\EnsureAccountNotPendingDeletion;
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Models\AnalysisJob;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * 退会予約中の**凍結** (deny-by-default) の振る舞い固定 (T142 / PR-B の B4)。
+ *
+ * 構造 (母集団 `U` と allowlist `A` の一致・enum の形式) は
+ * tests/Architecture/AccountDeletionFreezeRouteGateTest.php が固定する。
+ * 本テストは **実 HTTP** で「遮断されること / 到達できること」を測る
+ * (Architecture lane は DB を持てないため 2 本立てにしている)。
+ *
+ * 凍結の契約:
+ *   - 遮断は **302 → /settings** (403 で突き放さない = 行き先のない詰みを作らない)
+ *   - JSON/XHR は **409 Conflict** (課金ゲートの 402 とは別事由)
+ *   - **認証回復と離脱の手段は凍結しない** (ログアウトは group の外)
+ *   - **即時削除 (settings.account.destroy) は遮断する** (30 日猶予の迂回口を作らない)
+ */
+
+/** 予約中のユーザーを作り、認証主体として使える形で返す。 */
+function frozenUser(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($owner);
+    // actingAs は in-memory インスタンスを認証主体にするため DB の予約状態を読み直す
+    $owner->refresh();
+
+    return [$organization, $owner];
+}
+
+/**
+ * 凍結母集団のうち **route parameter を持たない** route を [名前 => [method, uri]] で返す。
+ *
+ * ★parameter を持つ route を sweep から外すのは、ダミー id を与えるとテナント境界 404 が
+ *   先に閉じる (それが正しい順序である) ため。順序そのものは下の「404 が 302 より前」と
+ *   TenantBoundaryOrderingTest が固定する。
+ *
+ * @return array<string, array{string, string}>
+ */
+function freezeSweepTargets(): array
+{
+    $router = app('router');
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    $targets = [];
+    /** @var RoutingRoute $route */
+    foreach ($routes as $route) {
+        $middleware = $route->gatherMiddleware();
+        if (! in_array(EnsureAccountNotPendingDeletion::class, $middleware, true)
+            && ! in_array('not-pending-deletion', $middleware, true)) {
+            continue;
+        }
+        $name = $route->getName();
+        if ($name === null || $route->parameterNames() !== []) {
+            continue;
+        }
+        if (in_array($name, AccountDeletionFreezeAllowance::values(), true)) {
+            continue;
+        }
+        $method = collect($route->methods())->first(fn (string $m): bool => $m !== 'HEAD');
+        if (! is_string($method)) {
+            continue;
+        }
+        $targets[$name] = [$method, '/'.ltrim($route->uri(), '/')];
+    }
+
+    return $targets;
+}
+
+test('凍結母集団 U − A の parameterless route はすべて /settings へ 302 する', function (): void {
+    [, $owner] = frozenUser();
+    $targets = freezeSweepTargets();
+
+    expect(count($targets))->toBeGreaterThan(20); // 空振り防止 (sweep が 0 件でも緑にならない)
+
+    $violations = [];
+    foreach ($targets as $name => [$method, $uri]) {
+        $response = $this->actingAs($owner)->call($method, $uri);
+        if ($response->getStatusCode() !== 302 || $response->headers->get('Location') !== url('/settings')) {
+            $violations[] = "{$name} ({$method} {$uri}): "
+                .$response->getStatusCode().' '.(string) $response->headers->get('Location');
+        }
+    }
+
+    expect($violations)->toBe([],
+        '凍結対象の route が /settings へ遮断されていません。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+
+    // ★sweep を通した限り、業務ジョブは 1 件も投入されない。**Queue::fake() は使わず実 jobs 表**を
+    //   payload まで見て判定する (jobs 全体の件数だと退会予約の通知 job で汚染される)。
+    expect(queuedJobClasses())->toBe([]);
+});
+
+/**
+ * 実 `jobs` 表に積まれた job のクラス名一覧 (退会予約の通知 job は除く)。
+ *
+ * `Queue::fake()` を使わないのはドメイン規約 11 の作法 (fake は enqueueUsing を通らない)。
+ *
+ * @return list<string>
+ */
+function queuedJobClasses(): array
+{
+    $classes = [];
+    foreach (DB::table('jobs')->pluck('payload') as $payload) {
+        $decoded = json_decode((string) $payload, true);
+        $name = is_array($decoded) ? ($decoded['displayName'] ?? null) : null;
+        if (! is_string($name) || $name === AccountDeletionRequestedNotification::class) {
+            continue; // 退会予約そのものの通知 job は業務ジョブではない
+        }
+        $classes[] = $name;
+    }
+    sort($classes);
+
+    return array_values(array_unique($classes));
+}
+
+test('予約中は自組織の {project} を持つ業務 route も遮断される (parameter 付きの代表 route)', function (): void {
+    [$organization, $owner] = frozenUser();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    // ★parameterless sweep では測れない「有効な自組織 parameter を持つ route」を代表 3 本で固定する
+    $this->actingAs($owner)->get("/projects/{$project->id}")->assertRedirect('/settings');
+    $this->actingAs($owner)->get("/projects/{$project->id}/edit")->assertRedirect('/settings');
+    $this->actingAs($owner)->patch("/projects/{$project->id}", ['name' => 'x'])->assertRedirect('/settings');
+});
+
+test('予約中は解析トリガー (チケット予約に至る業務経路) が遮断され、自動チャージ job も積まれない', function (): void {
+    [$organization, $owner] = frozenUser();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    SourceDocument::factory()->forManual($manual)->create();
+    app(TicketLedgerService::class)->grant($organization, 1, 'テスト残高');
+
+    // AutoRechargeTriggerJob を dispatch するのは TicketLedgerService::reserve() だけで、
+    // reserve() を呼ぶのは解析・レンダ等の業務フローである。その入口が凍結で止まることを実測する。
+    $this->actingAs($owner)->postJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/analyze",
+    )->assertStatus(409);
+
+    expect(AnalysisJob::query()->count())->toBe(0);
+    expect(queuedJobClasses())->not->toContain(AutoRechargeTriggerJob::class);
+    expect(queuedJobClasses())->toBe([]);
+});
+
+test('予約中でも /settings は 200 で、そこから取消できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->get('/settings')->assertOk();
+
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('予約中は即時削除が遮断され、取消してからなら削除できる', function (): void {
+    [, $owner] = frozenUser();
+
+    // ★allowlist に settings.account.destroy を足すとこのテストが赤くなる (M17)
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect('/settings');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeTrue();
+
+    $this->actingAs($owner)->delete('/settings/account/deletion-request');
+    $owner->refresh();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/settings/account')
+        ->assertRedirect('/');
+    expect(User::query()->whereKey($owner->id)->exists())->toBeFalse();
+});
+
+test('予約中でもログアウトできる (認証回復・離脱の手段は母集団の外)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->post('/logout')->assertRedirect();
+    $this->assertGuest();
+});
+
+test('予約中でも session.status は読める (bfcache 再検証の前提を凍結しない)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->getJson('/session/status')->assertOk();
+});
+
+test('予約中でも解約導線 (billing.index / billing.portal) に到達できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->get('/billing')->assertOk();
+
+    // portal は Stripe セッション生成へ進むため、ここでは「凍結で 302 されない」ことだけを見る
+    $response = $this->actingAs($owner)->post('/billing/portal');
+    expect($response->headers->get('Location'))->not->toBe(url('/settings'));
+});
+
+test('予約中でもオーナー移譲 (ブロッカー解消) の画面と操作に到達できる', function (): void {
+    [$organization, $owner] = frozenUser();
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($owner)->get("/organizations/{$organization->slug}/settings")->assertOk();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->from("/organizations/{$organization->slug}/settings")
+        ->post("/organizations/{$organization->slug}/transfer-ownership", ['user_id' => $member->id])
+        ->assertRedirect("/organizations/{$organization->slug}/settings");
+});
+
+test('予約中でも step-up 確認画面に到達できる (移譲に必要な satisfier)', function (): void {
+    [, $owner] = frozenUser();
+
+    // ★recent-auth.confirm を allowlist から外すとここが赤くなる (M25)
+    $this->actingAs($owner)->get('/recent-auth/confirm')->assertOk();
+    $this->actingAs($owner)->getJson('/recent-auth/status')->assertOk();
+});
+
+test('セッションが切れても再ログインしてから取消できる', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->get('/settings')->assertRedirect('/login');
+
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('recent-auth の鮮度が切れていても取消できる (救済経路に step-up を課さない)', function (): void {
+    [, $owner] = frozenUser();
+
+    // recent_auth_at を一切持たないセッションで取消する
+    $this->actingAs($owner)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($owner->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('2FA 必須組織のユーザーでも取消できる (satisfier の到達性)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+
+    // 2FA 準拠済みユーザー (未準拠だと 2FA 強制ゲートが先に短絡し、凍結の検証にならない)
+    $user = User::factory()->withTwoFactor()->create();
+    $organization->users()->attach($user);
+    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
+    $user->forceFill(['current_organization_id' => $organization->id])->save();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
+    $user->refresh();
+
+    $this->actingAs($user)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($user->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('2FA 未準拠ユーザーは 2FA ゲートが先に効くが、設定画面へ到達できる (詰みではない)', function (): void {
+    // ★凍結より **前** に走る 2FA 強制ゲート (priority list) の方が優先されるため、
+    //   未準拠ユーザーは取消 DELETE に直接到達できない。これは詰みではなく、
+    //   2FA 設定を済ませれば取消できる (準拠済みの取消は上のテストが固定している)。
+    //   この非対称を「取消はいつでもできる」と誇張しないために明示的に固定する。
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    $user = User::factory()->create(); // 2FA 未準拠
+    $organization->users()->attach($user);
+    $user->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
+    $user->forceFill(['current_organization_id' => $organization->id])->save();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($user);
+    $user->refresh();
+
+    // 取消は 2FA ゲートに阻まれる (凍結の 302 先ではなく 2FA 設定ページへ倒れる)
+    $this->actingAs($user)->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings/security');
+    expect($user->fresh()?->deletion_requested_at)->not->toBeNull();
+
+    // ★準拠達成の入口 (settings.security) に到達できることが**詰みでないことの条件**。
+    //   ここを凍結すると「取消は 2FA ゲート / 2FA 設定は凍結」の相互ブロックになる。
+    $this->actingAs($user)->get('/settings/security')->assertOk();
+    $this->actingAs($user)->get('/settings')->assertOk();
+
+    // 準拠を達成すれば取消できる (脱出経路が実在する)
+    $compliant = User::factory()->withTwoFactor()->create();
+    $organization->users()->attach($compliant);
+    $compliant->addRole(OrganizationRole::Admin->value, $organization->laratrust_team_id);
+    $compliant->forceFill(['current_organization_id' => $organization->id])->save();
+    app(OrganizationMembershipService::class)->requestAccountDeletion($compliant);
+    $compliant->refresh();
+
+    $this->actingAs($compliant)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($compliant->fresh()?->deletion_requested_at)->toBeNull();
+});
+
+test('XHR は 409 Conflict で遮断される (302 に倒さない)', function (): void {
+    [, $owner] = frozenUser();
+
+    $this->actingAs($owner)->getJson('/dashboard')->assertStatus(409);
+});
+
+test('未予約ユーザーには一切影響しない (全 parameterless route が従来どおり)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+
+    $redirectedToSettings = [];
+    foreach (freezeSweepTargets() as $name => [$method, $uri]) {
+        $response = $this->actingAs($owner)->call($method, $uri);
+        if ($response->getStatusCode() === 302 && $response->headers->get('Location') === url('/settings')) {
+            $redirectedToSettings[] = $name;
+        }
+    }
+
+    expect($redirectedToSettings)->toBe([],
+        '未予約ユーザーが凍結されています (middleware が予約状態を見ていない疑い): '
+        .implode(', ', $redirectedToSettings));
+});
+
+test('テナント境界 404 が凍結 302 より前に閉じる (存在オラクルを作らない)', function (): void {
+    [, $owner] = frozenUser();
+    [$otherOrganization] = createOrganizationWithOwner('他組織');
+    $foreign = Project::factory()->forOrganization($otherOrganization)->create();
+
+    // ★凍結 middleware を priority list でテナント境界より前へ動かすとここが 302 になる (M6)
+    $this->actingAs($owner)->get("/projects/{$foreign->id}")->assertNotFound();
+    $this->actingAs($owner)->get('/projects/999999999')->assertNotFound();
+});
diff --git a/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
new file mode 100644
index 0000000..fb3bcfb
--- /dev/null
+++ b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
@@ -0,0 +1,199 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\OrganizationRole;
+use App\Models\User;
+use App\Notifications\Account\AccountDeletionRequestedNotification;
+use App\Services\Billing\AccountDeletionBillingGuard;
+use App\Services\Billing\Contracts\StripeGatewayInterface;
+use App\Services\Notification\NotificationCenterService;
+use App\Services\Organization\OrganizationMembershipService;
+use App\Services\Project\DefaultProjectResolver;
+use App\Services\Security\SecurityEventRecorder;
+use Carbon\CarbonImmutable;
+use Illuminate\Console\Scheduling\Schedule;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Notification;
+use Symfony\Component\Console\Command\Command as SymfonyCommand;
+
+/*
+ * 退会予約の日次執行バッチ (`account:purge-deletion-requests`)。
+ *
+ * 終了コードの契約 (2 分類):
+ *   - 退会ブロッカー (ValidationException) = **業務上の保留**。予約は維持し SUCCESS のまま次へ
+ *   - インフラ障害 / 不変条件違反 = **想定外**。走査は続けるが FAILURE で終わる
+ */
+
+/** 期限到来済みの予約ユーザー。 */
+function dueUser(): User
+{
+    return User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(31),
+        CarbonImmutable::now()->subSecond(),
+    )->create();
+}
+
+test('dry-run は 1 人も削除しない', function (): void {
+    $user = dueUser();
+
+    $this->artisan('account:purge-deletion-requests')
+        ->expectsOutputToContain('due=1 deleted=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('--apply で期限到来ユーザーが削除され、未到来は残る (境界: 1 秒前 / 1 秒後)', function (): void {
+    // 1 秒境界を測るので時計を固定する (実行時間が 1 秒を超えると未到来が到来に化けるため)
+    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-30 12:00:00'));
+    $due = dueUser();
+    $notDue = User::factory()->pendingDeletion(
+        CarbonImmutable::now()->subDays(29),
+        CarbonImmutable::now()->addSecond(),
+    )->create();
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($due->id)->exists())->toBeFalse();
+    expect(User::query()->whereKey($notDue->id)->exists())->toBeTrue();
+});
+
+test('抽出後に取り消されたユーザーは削除されない', function (): void {
+    $user = dueUser();
+    // 抽出とロック取得の間に取消された状況の代理として、コマンド実行前に列を消す
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => null,
+        'deletion_purge_after' => null,
+    ]);
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('同日 2 回実行しても二重削除・二重通知が起きない', function (): void {
+    Notification::fake();
+    $user = dueUser();
+
+    $this->artisan('account:purge-deletion-requests --apply')->assertExitCode(SymfonyCommand::SUCCESS);
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0 deleted=0')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeFalse();
+    Notification::assertNothingSentTo($user);
+    Notification::assertNotSentTo([$user], AccountDeletionRequestedNotification::class);
+});
+
+test('1 人目でブロッカー例外が出ても 2 人目は削除される (失敗分離・SUCCESS)', function (): void {
+    // ブロッカー付き (唯一 Owner + 他メンバーが残る) の予約ユーザーを先に作る
+    [$organization, $blockedOwner] = createOrganizationWithOwner();
+    attachOrganizationMember($organization, OrganizationRole::Admin);
+    DB::table('users')->where('id', $blockedOwner->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
+        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
+    ]);
+    $deletable = dueUser();
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=2 deleted=1 blocked=1 unexpected=0')
+        // ★ブロッカーだけなら終了コードは SUCCESS (業務上の保留であって障害ではない)
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+
+    expect(User::query()->whereKey($blockedOwner->id)->exists())->toBeTrue();
+    expect(User::query()->whereKey($deletable->id)->exists())->toBeFalse();
+    // ブロックされたユーザーの予約は維持される (翌日また試す)
+    expect(User::query()->whereKey($blockedOwner->id)->first()?->deletion_purge_after)->not->toBeNull();
+});
+
+test('想定外例外が 1 件でもあれば FAILURE になり、走査は最後まで続く', function (): void {
+    dueUser();
+    dueUser();
+
+    $this->instance(OrganizationMembershipService::class, new class(app(SecurityEventRecorder::class), app(DefaultProjectResolver::class), app(NotificationCenterService::class), app(AccountDeletionBillingGuard::class)) extends OrganizationMembershipService
+    {
+        public function executeAccountDeletionRequest(User $user): bool
+        {
+            throw new RuntimeException('インフラ障害の代理');
+        }
+    });
+
+    // ★終了コードを常に SUCCESS にすると赤くなる (M7)
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=2 deleted=0 blocked=0 unexpected=2')
+        ->assertExitCode(SymfonyCommand::FAILURE);
+});
+
+test('片列だけの非正規行があれば report + FAILURE になり、その行は削除もされない', function (): void {
+    $user = User::factory()->create();
+    // CHECK 制約が無効化された / DB が壊れた状況の再現 (defense-in-depth の検証)
+    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_request_pair_check');
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
+    ]);
+
+    // ★抽出条件から whereNotNull('deletion_requested_at') を外すと due=1 になり赤くなる (M20)
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0 deleted=0 blocked=0 unexpected=1')
+        ->assertExitCode(SymfonyCommand::FAILURE);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('期限 < 予約時刻の非正規行は削除されず report + FAILURE になる (fail-closed)', function (): void {
+    // CHECK 制約 (順序) が無効化された状況の再現。両列とも埋まっており期限は過去なので、
+    // 順序の検査が無いと **猶予が経過していないのに物理削除される** (fail-open)。
+    $user = User::factory()->create();
+    DB::statement('ALTER TABLE users DROP CONSTRAINT users_deletion_purge_after_order_check');
+    DB::table('users')->where('id', $user->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now()->addDays(10),
+        'deletion_purge_after' => CarbonImmutable::now()->subDay(),
+    ]);
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->expectsOutputToContain('due=0 deleted=0 blocked=0 unexpected=1')
+        ->assertExitCode(SymfonyCommand::FAILURE);
+
+    expect(User::query()->whereKey($user->id)->exists())->toBeTrue();
+});
+
+test('決済事業者 API を 1 回も呼ばない (解約を代行しない)', function (): void {
+    // 課金責務のある組織 (ブロッカー) と、素直に消えるユーザーの両方を通す
+    [$organization, $owner] = createOrganizationWithOwner();
+    createFakeSubscription($organization, status: 'active');
+    DB::table('users')->where('id', $owner->id)->update([
+        'deletion_requested_at' => CarbonImmutable::now()->subDays(31),
+        'deletion_purge_after' => CarbonImmutable::now()->subSecond(),
+    ]);
+    dueUser();
+    // mock は「1 度も呼ばれない」ことを期待する (呼ばれたら Mockery が fail させる)。
+    // 外向き HTTP 自体はレーン既定の StrayHttpRequestGuard が拒否する。
+    $this->mock(StripeGatewayInterface::class);
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+});
+
+test('出力に user id / email が出ない (件数のみ)', function (): void {
+    $user = dueUser();
+    $email = $user->email;
+
+    $this->artisan('account:purge-deletion-requests --apply')
+        ->doesntExpectOutputToContain((string) $email)
+        ->doesntExpectOutputToContain('id='.$user->id)
+        ->assertExitCode(SymfonyCommand::SUCCESS);
+});
+
+test('日次スケジュールに --apply つきで登録されている', function (): void {
+    $commands = collect(app(Schedule::class)->events())
+        ->map(fn ($event): string => (string) $event->command)
+        ->filter(fn (string $command): bool => str_contains($command, 'account:purge-deletion-requests'))
+        ->values();
+
+    expect($commands)->toHaveCount(1);
+    expect($commands->first())->toContain('--apply');
+});

```

## mutation 記録 (M30 / M31 を追加。Round 1 の指摘由来)

# T142 (PR-B: 猶予期間つき削除 / 凍結方式) mutation 実測記録

> 実装完了の条件は「テストが緑」ではなく「**壊すと赤くなることを実測した**」。
> 詳細設計 `devnotes/20260809-0908-account-deletion-grace/detailed-design.md` §共通/mutation の
> **PR-B 該当分**を 1 つずつ適用 → 対象テストが赤いことを実測 → 変異を戻した。
> 全変異は適用後に `git checkout --` で復元済み (最終 `git status --short` が空であることを確認)。
>
> **設計の予測と実測がずれたものは、辻褄を合わせずそのまま記録する。**

## 実測サマリ

| # | 変異 | 設計の予測 | 実測 | 判定 |
|---|------|-----------|------|------|
| M4 | `AccountDeletionFreezeAllowance` から `Settings` を削る | 到達性テスト (取消に到達できない) | **赤**。`AccountDeletionFreezeTest`「予約中でも /settings は 200」が 302 になり、gate の件数 pin (16→15) も赤 | 予測どおり (+ 件数 pin も点灯) |
| M5 | 同 enum に `dashboard` を足す | exact-fit 検査 3 | **赤 — ただし赤くなったのは件数 pin だけ**。検査 3 は「宣言 (enum) と実装 (middleware の分岐) の一致」を測るので、enum に足すと**両側が同時に動く**ため点灯しない | **予測とずれ (記録)** |
| M6 | 凍結 middleware を priority list でテナント境界より前へ | `TenantBoundaryOrderingTest` + 他組織 `{project}` が 302 | **赤**。検査 2 (binding と guard の間に短絡)・検査 5 (列の完全一致)・behavioral (404 期待が 302) の 3 本 | 予測どおり |
| M7 | 執行バッチの終了コードを常に SUCCESS | 「想定外例外で FAILURE」 | **赤** (2 本: 想定外例外 / 非正規行) | 予測どおり |
| M8 | `deleteAccount` の precondition をブロッカー判定の後へ | 「抽出後に取消 → 削除しない」 | **2 形を実測**。(a) `$blockers = …` の**直後** (throw より前) へ動かす形は**緑のまま** = 検出できない。(b) `throw` ブロックの**後**へ動かす形は**赤** (取消済みユーザーに `ValidationException` が出る) | **予測とずれ (記録)**。設計の「判定の後」は (b) の意味であり、(a) の窓は本テストでは検出できない |
| M9 | 通知 `via()` から予約生存の再確認を外す | 「予約 → 即取消 → メール 0 通」 | **赤** (2 本: 即取消 / 再予約時の古い job) | 予測どおり |
| M17 | 同 enum に `settings.account.destroy` を足す | 「予約中は即時削除できない」 | **赤**。gate 検査 8 (名指し pin) + 件数 pin + behavioral (即時削除が `/` へ 302 = 実際に消えた) | 予測どおり |
| M18 | `logout` を `auth`+`verified` group の中へ移す | 凍結 gate 検査 6 (`U` に含まれないこと) | **赤**。※Fortify 登録 route を物理的に動かす代わりに、group 内へ `->name('logout')` の route を足して同値の状況を作った | 予測どおり (再現手段のみ代替) |
| M19 | `requestAccountDeletion` の冪等 no-op を外す | 「予約 POST 2 回でメール 1 通」 | **赤** (2 本: purge_after が 3 日延びる / メールが 2 通) | 予測どおり |
| M20 | 執行バッチの抽出条件から `whereNotNull('deletion_requested_at')` を外す | 「片列だけの非正規行を due に数えない」 | **赤** (`due=0` 期待が満たされない) | 予測どおり |
| M21 | `config/account.php` の `deletion_grace_days` を 0 に | `AccountDeletionGraceConfigTest` の fail-fast | **赤** (検査 2 の値 pin + 検査 3/7/8/9 が `Assert::greaterThan` で例外) | 予測どおり |
| M22 | `purgeAfter()` を `addDaysNoOverflow` に戻す | 「2026-01-31 の 30 日後 = 2026-03-02」 | **赤 — ただし理由が違う**。本リポジトリの Carbon に `addDaysNoOverflow` は**存在せず** `Method addDaysNoOverflow does not exist.` で落ちる。設計が想定した「静かに 28 日へ丸められる」壊れ方は**起きない** | **予測とずれ (記録)**。所見はコードの docblock にも反映済み |
| M23 | 通知 `via()` を `fresh() ?? $notifiable` へ戻す | 「執行済み user へ送らない」 | **赤** | 予測どおり |
| M25 | `recent-auth.confirm` を allowlist から外す | 到達性 (d) 移譲画面へ到達できない | **赤** (step-up 確認画面が 302 + 件数 pin) | 予測どおり |
| M27 | 同 enum に `billing.auto-recharge.update` を足す | 「予約中に auto-recharge 更新が遮断される」 | **赤** (gate 検査 8 の名指し pin + 件数 pin) | 予測どおり |
| M28 | users の CHECK 制約を外し片列だけ UPDATE | migration の DB 制約テスト | **赤** (`QueryException` が飛ばない) | 予測どおり |
| M29 | `PortalConfigurationSpec` の `subscription_update` を `true` に | 凍結 gate の**前提検査 3 点** | **赤**。赤くなったのは `AccountDeletionFreezeRouteGateTest` 検査 7 (`subscription_update.enabled === false`) | 予測どおり。**`billing:ensure-portal-configuration --verify` は spec との一致しか見ないため、この前提 pin が無ければ気づけなかった** |

## Codex 実装レビュー Round 1 を受けて追加した mutation

| # | 変異 (実施後は必ず戻す) | 赤くなるべきテスト | 実測 |
|---|------|-----------|------|
| M30 | `isDue()` の前提を `isNormalized()` → `isPending()` に戻し、執行バッチの抽出条件から `whereColumn('deletion_purge_after', '>=', 'deletion_requested_at')` を外す | 「期限 < 予約時刻の非正規行は削除されず report + FAILURE」(Feature 2 本) | **赤** (バッチ側で `due=1` になり、Service 側でも `executeAccountDeletionRequest` が true を返して削除された) |
| M31 | `AccountDeletionFreezeAllowance` から `settings.security` を外す | 「2FA 未準拠ユーザーが設定画面へ到達できる (詰みではない)」 | **赤** (`/settings/security` が 302 に倒れ、取消は 2FA ゲート・2FA 設定は凍結という**相互ブロックの詰み**が再現する) |

M30 / M31 はいずれも **Codex レビューの指摘を追ったところ実在の欠陥が見つかった**もので、
指摘そのままではなく「本当に壊れる形」を作ってから修正している。

- **M30 (Critical)**: CHECK 制約が壊れて `deletion_purge_after < deletion_requested_at` の行ができた場合、
  `unexpected` として report はするのに**その行が due 抽出に残って物理削除されていた** (fail-open)。
  猶予が経過していないユーザーが早期に消える向きの欠陥。DTO 側 (`isNormalized()`) と
  クエリ側の**両方**を fail-closed にした。
- **M31 (Critical / 設計の allowlist 漏れ)**: 2FA 必須組織の**未準拠**ユーザーは、
  2FA 強制ゲート (凍結より前に走る) が取消 DELETE を `settings.security` へ倒す一方、
  その `settings.security` を凍結が `/settings` へ倒すため、**行き先のない詰み**になっていた。
  設計の allowlist には `settings.security` が入っていない。実測して発見し追加した
  (これは設計の見落としであり、実装での逸脱ではない)。

## 予測とのずれ (3 件) の詳細

### 1. M5 — 「exact-fit 検査 3」は allowlist の**増加**を捕まえない

検査 3 は `U` の全 route に対して middleware を実際に駆動し、「bypass した集合」と
「enum が宣言する集合」が一致することを見る。enum に case を足すと **middleware の挙動も同時に変わる**
ため、両辺が同じだけ動いて一致は保たれる。増加を捕まえるのは
**件数の exact-fit pin (`FREEZE_ALLOWANCE_COUNT`)** と **名指しの pin (検査 8)** の 2 つである。

検査 3 が本当に守るのは「宣言と実装がずれること」— たとえば middleware に prefix 一致や
wildcard を実装で持ち込む改変であり、そちらは検査 3 でしか落ちない。役割が違うので両方残す。

### 2. M8 — precondition の「判定の後」には 2 つの位置がある

- `$blockers = $this->organizationsBlockingDeletion(...)` の**直後 / throw の前**:
  ブロッカー例外は出ないので**テストは緑のまま**。実害は「取消済みユーザーに対して
  無駄なブロッカー評価クエリが走る」ことだけで、観測可能な契約は壊れない。
- `throw` ブロックの**後**: 取消済みユーザーが `ValidationException` を受け、バッチが
  「業務上の保留 (blocked)」と誤分類する。**テストは赤**。

実装は前者よりさらに前 (fresh 取得の直後) に置いてある。テストが固定しているのは
**「ブロッカー例外より前であること」**であり、「ブロッカー評価クエリより前であること」は
固定していない。誇張しないためここに明記する。

### 3. M22 — `addDaysNoOverflow` はこの Carbon に存在しない

設計は「`addDaysNoOverflow` は月末丸めで 30 日未満になるため禁止」と書いていたが、実測では
`Method addDaysNoOverflow does not exist.` で即座に落ちる (静かに壊れる経路ではない)。
したがって現実の危険は *NoOverflow ではなく **日加算を月単位の式へ書き換えること**の側にあり、
それは `AccountDeletionGraceConfigTest` の behavioral 検査
(2026-01-31 + 30 日 = 2026-03-02 / うるう年跨ぎ) が担う。
`CarbonOverflowArithmeticGateTest` の禁止語彙は月・年・四半期のみで日は母集団外である
(gate の定数を実読して確認済み)。

## この PR で実施していない mutation

M1 / M2 / M3 / M10〜M16 / M24 / M26 は PR-A・PR-C1・PR-C2・PR-C3 の担当 (本 PR の変更対象外)。
M1〜M3 の実測は `devnotes/20260810-1004-todo-T141/mutation-evidence.md` にある。


## 検証結果 (修正後)

- `composer phpstan` (level 10): **OK / エラー 0 件**
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- 変更した Feature / Architecture テスト: 全 green
  (`AccountDeletionFreezeTest` 17 / `AccountDeletionGraceTest` 29 /
   `PurgeDeletionRequestsCommandTest` 11 / `AccountDeletionFreezeRouteGateTest` 9)
- 全体 `composer test` は最終確認として再実行する (Round 1 時点で 4269 tests 全 green)

## 確認してほしいこと

1. [Critical] の fail-closed 修正 (`isNormalized()` + `whereColumn`) が十分か。
   他に「CHECK 制約が壊れたときに fail-open へ倒れる」経路が残っていないか。
2. `settings.security` を凍結 allowlist に足したことで**新しい穴**が開いていないか
   (この route は 2FA / ソーシャル連携 / パスキーの管理画面である。GET 1 本のみで、
   実際の変更操作は Fortify / Passkeys の route = 凍結 group の外にある)。
   「凍結中に認証手段を増減できる」ことが猶予の迂回や課金責務の生成につながらないか。
3. 同一秒内の再予約について、主張の狭め方 (docblock + テスト名 + 対照アサーション) が
   誇張・過小になっていないか。
4. 他に [Critical] が残っていないか。

**全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。**
