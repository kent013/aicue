# Round 3: Round 2 の指摘への対応

Round 2 の [Warning] 3 件 / [Suggestion] 2 件を**すべて対応**した。
[Warning] の 1 件 (`report()` を検証していない) を追跡したところ、**実装側の欠陥**が見つかった。

## 対応マトリクス

# 対応マトリクス: impl-review Round 2

## [Warning] 2FA 脱出テストが「同一ユーザー」の連鎖を証明していない

- **判断**: 対応する (指摘は正しい)
- **根拠**: 別ユーザーで代用すると「元の未準拠ユーザーが本当に脱出できるか」を 1 行も証明せず、
  実在した Critical (相互ブロックの詰み) の回帰防止として空振りになる。
- **対応内容**:
  - `UserFactory::withTwoFactor()` の実装を `UserFactory::enableTwoFactorFor(User $user)` へ切り出し、
    state と helper が**同一実装を共有**する形にした (2 箇所に書かない)。
  - テストを「未準拠 → `settings.security` 到達 → 同一ユーザーを準拠へ遷移 → 同一ユーザーが取消成功」の
    連鎖に書き換えた。

## [Warning] `queuedJobClasses()` の名前と「業務ジョブ」という主張が広すぎる

- **判断**: 対応する
- **対応内容**: `queuedJobClassesExceptDeletionNotice()` へ改名し、docblock に
  「退会通知**以外**の queued class であって『業務ジョブ』の一般的分類ではない」
  「新しい非業務通知が増えたら赤くなる。そのときは除外を増やす前に、凍結中にその通知が
  積まれてよいのかを先に考えること」を明記。禁止対象 (`AutoRechargeTriggerJob`) の
  名指し検査と併用する 2 段構えにした。

## [Warning] 「report + FAILURE」というテスト名なのに `report()` を検証していない

- **判断**: 対応する。**追跡した結果、実装側の欠陥が見つかった**
- **根拠**: `Exceptions::fake()` + `assertReported` を入れたところ、
  **`report(new ValidationException)` が 1 件も記録されない**ことが判明した。
  Laravel の既定 dontReport が `ValidationException` を握り潰すため、設計どおりに書いた
  「保留を report する」は**実際には無効**で、監視契約が保留について嘘になっていた。
- **対応内容**:
  - `catch (ValidationException)` の中の `report($e)` を削除し (無効であることをコメントに明記)、
    走査後に `blocked > 0` なら**件数を載せた `RuntimeException`** を 1 回 report する形へ変更。
  - `Exceptions::assertReported` / `assertReportedCount` を 4 テストへ追加
    (想定外例外 / 片列非正規 / 順序非正規 / 保留)。
  - `docs/architecture.md` と `docs/account-deletion-runbook.md` の監視契約の記述を実装に合わせた。
  - `mutation-evidence.md` に実測として記録。

## [Suggestion] `isNormalized()` の名前が DB CHECK 制約全体と一致しない

- **判断**: 対応する
- **根拠**: 指摘どおり。DB の制約は「両列とも null」も正常と認めるが、この述語は false を返す。
  実際に見ているのは「**予約として扱ってよい組か**」である。
- **対応内容**: `isValidPendingRequest()` へ改名し、docblock に
  「DB の CHECK 制約を満たすかではない。未予約 (両列 null) には false を返す」を明記。

## [Suggestion] `matches()` も非正規状態では false にする余地がある

- **判断**: 対応する
- **根拠**: 「非正規状態では外部副作用 (メール送信) も出さない」方が fail-closed で一貫する。
  コストはゼロ (述語の差し替えのみ)。
- **対応内容**: `matches()` の前提を `isPending()` → `isValidPendingRequest()` へ変更し、
  docblock に「非正規な組では false = 外部通知も出さない (fail-closed)」を明記。


## 修正後の差分 (Round 2 から変わったファイルのみ)

```diff
diff --git a/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
new file mode 100644
index 0000000..cc5eed7
--- /dev/null
+++ b/app/Console/Commands/Account/PurgeDeletionRequestsCommand.php
@@ -0,0 +1,122 @@
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
+                        // ★ここで `report($e)` はしない。Laravel の既定 dontReport が
+                        //   ValidationException を握り潰すため**何も起きない** (実測)。
+                        //   保留は走査後に件数だけを集約 report する (下記)。
+                        $blocked++;
+                    } catch (Throwable $e) {
+                        // インフラ障害 / 不変条件違反 = **想定外**。継続はするが終了コードは FAILURE。
+                        $unexpected++;
+                        report($e);
+                    }
+                }
+            });
+
+        if ($blocked > 0) {
+            // 業務上の保留は終了コードを FAILURE にしない (障害ではない) が、
+            // **放置されると 30 日を過ぎた予約が消えないまま滞留する**ので観測はさせる。
+            // 件数のみ (user id / email は載せない)。
+            report(new RuntimeException(
+                "退会予約の執行を保留 (退会ブロッカーあり): count={$blocked}",
+            ));
+        }
+
+        $this->info("due={$due} deleted={$deleted} blocked={$blocked} unexpected={$unexpected}");
+
+        return $unexpected > 0 ? self::FAILURE : self::SUCCESS;
+    }
+}
diff --git a/app/DataTransferObjects/Account/AccountDeletionStateDto.php b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
new file mode 100644
index 0000000..9d24c1c
--- /dev/null
+++ b/app/DataTransferObjects/Account/AccountDeletionStateDto.php
@@ -0,0 +1,129 @@
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
+     * **予約中の状態として正規**か (両列が揃い、かつ期限が予約時刻以降)。
+     *
+     * ★「DB の CHECK 制約を満たすか」ではない — 制約は「両列とも null」も正常と認めるが、
+     *   本述語は未予約 (両列 null) に対して false を返す。見ているのは
+     *   **「予約として扱ってよい組か」**である (名前もそれに合わせている)。
+     *   制約が無効化された場合でもアプリ側が同じ判断をするための述語。
+     */
+    public function isValidPendingRequest(): bool
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
+     *   `isPending()` ではなく `isValidPendingRequest()` を前提にするのは、CHECK 制約が壊れた場合に
+     *   「猶予が経過していない行を早期に物理削除する」向きに倒れるのを防ぐためである
+     *   (非正規行は日次バッチが件数だけ report し、削除せず FAILURE で終わる)。
+     */
+    public function isDue(CarbonImmutable $now): bool
+    {
+        return $this->isValidPendingRequest()
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
+     * ★非正規な組 (期限 < 予約時刻) では **false** を返す = 外部通知も出さない (fail-closed)。
+     */
+    public function matches(CarbonImmutable $requestedAt, CarbonImmutable $purgeAfter): bool
+    {
+        return $this->isValidPendingRequest()
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
diff --git a/database/factories/UserFactory.php b/database/factories/UserFactory.php
index cb232db..38b9c1c 100644
--- a/database/factories/UserFactory.php
+++ b/database/factories/UserFactory.php
@@ -5,6 +5,8 @@
 namespace Database\Factories;
 
 use App\Models\User;
+use App\Support\Account\AccountDeletionGrace;
+use Carbon\CarbonImmutable;
 use Illuminate\Database\Eloquent\Factories\Factory;
 use Illuminate\Support\Collection;
 use Illuminate\Support\Facades\Hash;
@@ -62,6 +64,25 @@ public function ssoOnly(): static
         ]);
     }
 
+    /**
+     * 退会予約中 (凍結方式) のユーザー。**users 行の生死は変えない**ので、埋めるのは予約列 2 本だけ。
+     *
+     * 両列は同時に埋まる (DB の CHECK 制約 users_deletion_request_pair_check が片列だけを拒否する)。
+     * `$purgeAfter` 未指定なら猶予日数の SSOT (AccountDeletionGrace) から導出する
+     * = テストが猶予日数を独自に持たない。
+     */
+    public function pendingDeletion(?CarbonImmutable $requestedAt = null, ?CarbonImmutable $purgeAfter = null): static
+    {
+        return $this->state(function (array $attributes) use ($requestedAt, $purgeAfter): array {
+            $requested = $requestedAt ?? CarbonImmutable::now();
+
+            return [
+                'deletion_requested_at' => $requested,
+                'deletion_purge_after' => $purgeAfter ?? AccountDeletionGrace::purgeAfter($requested),
+            ];
+        });
+    }
+
     /**
      * 2FA 有効・confirmed 状態のユーザーを生成する。
      *
@@ -72,19 +93,29 @@ public function ssoOnly(): static
      */
     public function withTwoFactor(): static
     {
-        return $this->afterCreating(function (User $user): void {
-            $secret = app(Google2FA::class)->generateSecretKey();
+        return $this->afterCreating(static fn (User $user) => self::enableTwoFactorFor($user));
+    }
 
-            /** @var Collection<int, string> $codes */
-            $codes = Collection::times(8, fn (): string => RecoveryCode::generate());
+    /**
+     * 既存ユーザーを 2FA 準拠 (confirmed) 状態へ遷移させる。
+     *
+     * `withTwoFactor()` state と**同一の実装**を共有する (2 箇所に書かない)。
+     * 「未準拠のまま作ったユーザーが、途中で準拠を達成する」導線を検証するテスト
+     * (2FA 必須組織での退会予約の取消など) から呼ぶ。
+     */
+    public static function enableTwoFactorFor(User $user): void
+    {
+        $secret = app(Google2FA::class)->generateSecretKey();
 
-            $user->forceFill([
-                'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
-                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
-                    (string) json_encode($codes->all()),
-                ),
-                'two_factor_confirmed_at' => now(),
-            ])->save();
-        });
+        /** @var Collection<int, string> $codes */
+        $codes = Collection::times(8, fn (): string => RecoveryCode::generate());
+
+        $user->forceFill([
+            'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
+            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
+                (string) json_encode($codes->all()),
+            ),
+            'two_factor_confirmed_at' => now(),
+        ])->save();
     }
 }
diff --git a/tests/Feature/Auth/AccountDeletionFreezeTest.php b/tests/Feature/Auth/AccountDeletionFreezeTest.php
new file mode 100644
index 0000000..87a9560
--- /dev/null
+++ b/tests/Feature/Auth/AccountDeletionFreezeTest.php
@@ -0,0 +1,349 @@
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
+use Database\Factories\UserFactory;
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
+    // ★sweep を通した限り、退会通知**以外**の job は 1 件も投入されない。
+    //   **Queue::fake() は使わず実 jobs 表**を payload (displayName) まで見て判定する
+    //   (jobs 全体の件数だと退会予約の通知 job そのもので汚染される)。
+    expect(queuedJobClassesExceptDeletionNotice())->toBe([]);
+});
+
+/**
+ * 実 `jobs` 表に積まれた job のクラス名一覧から、**退会予約の通知 job だけ**を除いたもの。
+ *
+ * ★名前どおり「退会通知**以外**の queued class」であって「業務ジョブ」の一般的な分類ではない。
+ *   凍結中に新しい非業務通知が増えたらこの検査は赤くなる (そのときは除外を増やすのではなく、
+ *   「凍結中にその通知が積まれてよいのか」を先に考えること)。
+ * ★`Queue::fake()` を使わないのはドメイン規約 11 の作法 (fake は enqueueUsing を通らない)。
+ *
+ * @return list<string>
+ */
+function queuedJobClassesExceptDeletionNotice(): array
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
+    // 名指しの禁止対象 + 「退会通知以外は 0 件」の 2 段で見る
+    expect(queuedJobClassesExceptDeletionNotice())->not->toContain(AutoRechargeTriggerJob::class);
+    expect(queuedJobClassesExceptDeletionNotice())->toBe([]);
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
+    // ★**同一ユーザー**で脱出の連鎖を固定する
+    //   (未準拠 → settings.security → 2FA 準拠 → 取消)。別ユーザーで代用すると
+    //   「元のユーザーが本当に脱出できるか」を証明しないため、詰みの回帰防止にならない。
+    //   準拠状態への遷移は UserFactory::withTwoFactor() と同一実装を共有する helper で行う。
+    UserFactory::enableTwoFactorFor($user);
+    $user->refresh();
+
+    $this->actingAs($user)->from('/settings')
+        ->delete('/settings/account/deletion-request')
+        ->assertRedirect('/settings');
+    expect($user->fresh()?->deletion_requested_at)->toBeNull();
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
index 0000000..ef352c9
--- /dev/null
+++ b/tests/Feature/Console/PurgeDeletionRequestsCommandTest.php
@@ -0,0 +1,223 @@
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
+use Illuminate\Support\Facades\Exceptions;
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
+    Exceptions::fake();
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
+    // 保留も report される (SUCCESS だが運用者が気づけること = 監視契約の一部)。
+    // ★ValidationException を素で report しても Laravel の既定 dontReport が握り潰す (実測) ため、
+    //   件数を載せた RuntimeException に集約している。ここが緑であることがその実装の証拠になる。
+    Exceptions::assertReported(
+        fn (RuntimeException $reported): bool => $reported->getMessage() === '退会予約の執行を保留 (退会ブロッカーあり): count=1',
+    );
+});
+
+test('想定外例外が 1 件でもあれば report + FAILURE になり、走査は最後まで続く', function (): void {
+    Exceptions::fake();
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
+
+    // 監視契約は「終了コード + report()」の 2 本立てなので report() 側も固定する
+    // (終了コードだけを見ていると report を消しても緑のままになる)。
+    Exceptions::assertReportedCount(2);
+    Exceptions::assertReported(
+        fn (RuntimeException $reported): bool => $reported->getMessage() === 'インフラ障害の代理',
+    );
+});
+
+test('片列だけの非正規行があれば report + FAILURE になり、その行は削除もされない', function (): void {
+    Exceptions::fake();
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
+    Exceptions::assertReported(
+        fn (RuntimeException $reported): bool => str_contains($reported->getMessage(), '退会予約列が非正規な行を検出: count=1'),
+    );
+});
+
+test('期限 < 予約時刻の非正規行は削除されず report + FAILURE になる (fail-closed)', function (): void {
+    Exceptions::fake();
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
+    Exceptions::assertReported(
+        fn (RuntimeException $reported): bool => str_contains($reported->getMessage(), '退会予約列が非正規な行を検出: count=1'),
+    );
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

## 検証結果 (修正後)

- `composer phpstan` (level 10): **OK / エラー 0 件**
- `vendor/bin/pint --test`: passed
- 該当テスト全 green: `AccountDeletionFreezeRouteGateTest` 9 / `AccountDeletionGraceConfigTest` 9 /
  `AccountDeletionGraceTest` 29 / `AccountDeletionFreezeTest` 17 /
  `PurgeDeletionRequestsCommandTest` 11 (計 75 tests / 247 assertions)
- 全体 `composer test` は最終確認として再実行する

## 確認してほしいこと

1. 保留の報告を「件数を載せた `RuntimeException` の集約 1 件」に変えた判断は妥当か。
   (per-item の `report(new ValidationException)` は Laravel の既定 dontReport で無効だった。
    user id / email を載せない契約は維持している。)
2. `isValidPendingRequest()` への改名と `matches()` の fail-closed 化に副作用がないか。
3. 2FA 脱出の連鎖テストが、実在した詰みの回帰防止として十分か。
4. **[Critical] が残っていないか。**

**全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。**
