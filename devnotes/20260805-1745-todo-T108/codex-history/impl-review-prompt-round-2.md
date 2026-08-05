Round 1 の指摘 3 件すべてに対応しました。対応マトリクスと修正差分を送ります。

# 対応マトリクス: impl-review Round 1

## [Critical] `0.0.0.0/0` / `::/0` が production でも通過する (TrustedProxyToken / TrustedProxiesConfigValidator / TrustedProxyTokenTest)

- 判断: **対応する**
- 根拠: 完全に正しい指摘。prefix 長 0 の CIDR は全アドレスを含むため `*` と意味的に同値であり、
  「`*` を禁止する」という High-2 の是正が迂回できる。しかも書式としては正当な CIDR なので
  `filter_var` + prefix 範囲チェックだけの書式検査では素通りする。
  Unit テストで `0.0.0.0/0` を **valid として固定していた**ため、偽グリーンでもあった
  (「テストが不変条件を落とせるか」の観点で最も悪い形)。
- 対応内容:
  - `TrustedProxyToken::isAllAddresses(string): bool` を新設。
    `*` / `**` に加え、**valid CIDR かつ prefix が 0** のものを全アドレス等価と判定する
    (`0.0.0.0/0` / `::/0` / 完全展開表記の `0000:...:0000/0` を含む)。
  - `isTrustableAddress()` の先頭で `isAllAddresses()` を弾く
    → **どの環境でも** framework に渡らない (fail-secure)。local/dev でも `0.0.0.0/0` は無効。
  - `TrustedProxiesConfigValidator` の検査 1 を `['*','**']` の in_array から
    `isAllAddresses()` 走査へ置換 → production では専用メッセージ
    ("Trusting every address lets clients forge X-Forwarded-For…") で reject。
    判定を `TrustedProxyToken` に一本化したので config 段と validator 段のズレも起きない。
  - テスト:
    - `TrustedProxyTokenTest`: `0.0.0.0/0` を valid リストから削除し **invalid リストへ移動**。
      `::/0` / 完全展開表記も追加。`isAllAddresses` の正/負データセットを新設。
    - `TrustedProxiesConfigValidatorTest`: 検査 1 のデータセットに `0.0.0.0/0` / `::/0` を追加。
      さらに「実 hop と併記していても reject」ケースを追加 (最優先で落ちることの固定)。

## [Warning] ResponseSignature が ETag / Last-Modified まで volatile 除外している

- 判断: **対応する**
- 根拠: 妥当。設計で除外を意図していたのは「連続リクエストで必ず差分が出る」ヘッダだけで、
  `ETag` / `Last-Modified` は**リソース内容・更新時刻に由来する安定した差分**になりうる。
  存在オラクル検査の本体は「2 応答が観測上同一か」なので、安定差分を除外すると検査が空洞化する。
- 対応内容: `VOLATILE_EXACT` から `etag` / `last-modified` を削除し比較対象に戻した。
  `expires` / `age` は「現在時刻から導出される値」で連続リクエストでは必ずズレるため除外を維持し、
  その区別の理由をコメントに明記した。除外を戻しても全テスト green (偽陽性は出ていない)。

## [Warning] pre-binding 静的検査が `$request->route($param)` (変数引数) を見逃す

- 判断: **対応する**
- 根拠: 妥当。deny-by-default テストとしては「文字列リテラルで書いた場合だけ落ちる」のは弱い。
  現在の登録対象には該当が無いが、このテストの目的は**将来の追加**を落とすことなので、
  検出パターンが書き方に依存するのは設計意図に反する。
- 対応内容: 禁止パターンに `->route($` を追加。
  `ThrottleRequests` の `$route = $request->route()` (引数なし = Route オブジェクト取得)
  は引き続き通る = 意図した区別を保っている (Route の `getDomain()` は URL 上の id と無関係)。

## 検証

- `composer test`: **2992 passed / 0 failed / 2 skipped** (2994 tests, 11766 assertions)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed


---

## 修正差分 (Round 1 からの追加分のみ / git diff)

注: 差分は Round 1 の diff からの **追加変更分** です
(TrustedProxyToken / TrustedProxiesConfigValidator / 関連 Unit テスト /
ResponseSignature / TenantBoundaryOrderingTest の 4(a) 検査)。

```diff
diff --git a/app/Support/TrustedProxiesConfigValidator.php b/app/Support/TrustedProxiesConfigValidator.php
new file mode 100644
index 0000000..4d16f2c
--- /dev/null
+++ b/app/Support/TrustedProxiesConfigValidator.php
@@ -0,0 +1,101 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+use RuntimeException;
+
+/**
+ * TrustProxies allowlist (config/trustedproxy.php) の production 起動時検証。
+ *
+ * `TrustedHostsConfigValidator` と同形 (final / 純粋クラス / RuntimeException)。
+ * 検証ロジックを純粋クラスに切り出して unit test で直接検証可能にする。
+ *
+ * 背景: かつて `trustProxies(at: '*')` だった。全アドレスを trusted proxy 扱いにすると
+ * `$request->ip()` が X-Forwarded-For の最左 = **クライアントが自由に書ける値**になり、
+ * IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化される (audit-cycle-2 High-2)。
+ * production では「hop を明示宣言する」ことを起動条件にする。
+ *
+ * ⚠ 本 validator は **意図的にデプロイ時の破壊的変更**である。`TRUSTED_PROXIES` を
+ * 宣言せずに production を起動すると fail-fast する。rollback は `at: '*'` へ戻すことでは
+ * なく、正しい CIDR を設定すること。運用契約は docs/trusted-proxies-runbook.md。
+ */
+final class TrustedProxiesConfigValidator
+{
+    /**
+     * @param  list<string>  $proxies  検証通過後の proxy 列 (config 通過後)
+     * @param  list<string>  $rawProxies  生 token (空白 trim のみ、format validation 前)
+     *
+     * @throws RuntimeException
+     */
+    public function validateForProduction(array $proxies, array $rawProxies): void
+    {
+        $tokens = array_values(array_filter(
+            array_map('trim', $rawProxies),
+            static fn (string $v): bool => $v !== '',
+        ));
+
+        // 1. 全アドレス信頼は無条件で拒否する (これが High-2 の元の状態)。
+        //    `*` / `**` だけでなく prefix 長 0 の CIDR (`0.0.0.0/0` / `::/0`) も同値。
+        //    後者は書式として正当な CIDR なので、書式検査だけでは通り抜ける。
+        foreach ($tokens as $token) {
+            if (TrustedProxyToken::isAllAddresses($token)) {
+                throw new RuntimeException(sprintf(
+                    'TRUSTED_PROXIES contains "%s". Trusting every address lets clients forge '
+                    .'X-Forwarded-For (client IP, rate limits and audit logs become attacker-controlled). '
+                    .'Enumerate the actual proxy hops as IP/CIDR instead.',
+                    $token,
+                ));
+            }
+        }
+
+        // 2. `none` sentinel (プロキシ無し構成の明示宣言) を **書式検査より先に**処理する。
+        //    順序が逆だと `none` 自身が「config 段で落ちた不正値」として reject される。
+        if (in_array(TrustedProxyToken::NONE, $tokens, true)) {
+            if (count($tokens) !== 1) {
+                throw new RuntimeException(
+                    'TRUSTED_PROXIES declares "none" together with other values. '
+                    .'"none" means "there is no proxy in front of this app" and must be declared alone.'
+                );
+            }
+            if ($proxies !== []) {
+                throw new RuntimeException(
+                    'TRUSTED_PROXIES declares "none" but the resolved proxy list is not empty. '
+                    .'This indicates a configuration inconsistency (check config/trustedproxy.php).'
+                );
+            }
+
+            return; // プロキシ無し構成の明示宣言 = 正常
+        }
+
+        // 3. production で REMOTE_ADDR (直接接続元の一括信頼) は許さない。
+        if (in_array(TrustedProxyToken::REMOTE_ADDR, $tokens, true)) {
+            throw new RuntimeException(
+                'TRUSTED_PROXIES contains "REMOTE_ADDR". Trusting the immediate peer unconditionally '
+                .'is a local-development convenience and must not be used in production. '
+                .'Enumerate the actual proxy hops as IP/CIDR instead.'
+            );
+        }
+
+        // 4. 書式不正 (config 段の silent drop を起動時に表面化させる)。
+        foreach ($tokens as $token) {
+            if (! TrustedProxyToken::isTrustableAddress($token)) {
+                throw new RuntimeException(sprintf(
+                    'TRUSTED_PROXIES contains an invalid value "%s". '
+                    .'Each entry must be a single IP address or a CIDR block (e.g. 10.0.0.0/8).',
+                    $token,
+                ));
+            }
+        }
+
+        // 5. 未設定 (空) は production では宣言漏れとして扱う。
+        if ($proxies === []) {
+            throw new RuntimeException(
+                'TRUSTED_PROXIES is not set in production. Enumerate every proxy hop as IP/CIDR, '
+                .'or declare "none" explicitly when the app is not behind a proxy. '
+                .'See docs/trusted-proxies-runbook.md.'
+            );
+        }
+    }
+}
diff --git a/app/Support/TrustedProxyToken.php b/app/Support/TrustedProxyToken.php
new file mode 100644
index 0000000..89f1cb2
--- /dev/null
+++ b/app/Support/TrustedProxyToken.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+/**
+ * `TRUSTED_PROXIES` の 1 token の妥当性判定 (config 段と validator 段で共有する純粋クラス)。
+ *
+ * 判定をここに一本化するのは、config 段の filter と起動時 validator が別ロジックだと
+ * 「config では落ちるのに validator は通す (= silent drop)」「その逆 (= 誤 reject)」の
+ * ズレが生まれるため。正規表現による緩い判定 (`999.999.999.999/999` を通す) は使わず、
+ * IP 部は `filter_var(FILTER_VALIDATE_IP)`、prefix 長は数値範囲で検証する。
+ */
+final class TrustedProxyToken
+{
+    /** 「プロキシは無い」の明示宣言 (空 list に写す sentinel)。 */
+    public const string NONE = 'none';
+
+    /** 直接の接続元を信頼する予約値 (framework が REMOTE_ADDR に展開。production では禁止)。 */
+    public const string REMOTE_ADDR = 'REMOTE_ADDR';
+
+    /**
+     * 「全アドレス信頼」と等価な宣言か。
+     *
+     * `*` / `**` だけでなく **prefix 長 0 の CIDR** (`0.0.0.0/0` / `::/0`) も
+     * 全アドレスを含むため同値である。書式としては正当な CIDR なので素朴な
+     * 書式検査だけでは通り抜け、`*` を禁止した意味が消える (impl-review R1 Critical)。
+     */
+    public static function isAllAddresses(string $token): bool
+    {
+        if ($token === '*' || $token === '**') {
+            return true;
+        }
+        if (! self::isCidr($token)) {
+            return false;
+        }
+
+        return (int) explode('/', $token)[1] === 0;
+    }
+
+    /**
+     * framework に渡してよい値か (単一 IP / CIDR / REMOTE_ADDR)。
+     *
+     * 全アドレス等価の宣言は **どの環境でも** framework に渡さない (fail-secure)。
+     * production での明示的な reject 理由は TrustedProxiesConfigValidator が出す。
+     */
+    public static function isTrustableAddress(string $token): bool
+    {
+        if (self::isAllAddresses($token)) {
+            return false;
+        }
+        if ($token === self::REMOTE_ADDR) {
+            return true;
+        }
+        if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
+            return true;
+        }
+
+        return self::isCidr($token);
+    }
+
+    /** CIDR 書式か (IP 部は FILTER_VALIDATE_IP、prefix は IPv4 0-32 / IPv6 0-128)。 */
+    public static function isCidr(string $token): bool
+    {
+        $parts = explode('/', $token);
+        if (count($parts) !== 2) {
+            return false;
+        }
+        [$address, $prefix] = $parts;
+        if ($prefix === '' || ctype_digit($prefix) === false) {
+            return false;
+        }
+        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
+            return (int) $prefix <= 32;
+        }
+        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
+            return (int) $prefix <= 128;
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Architecture/TenantBoundaryOrderingTest.php b/tests/Architecture/TenantBoundaryOrderingTest.php
new file mode 100644
index 0000000..fab0189
--- /dev/null
+++ b/tests/Architecture/TenantBoundaryOrderingTest.php
@@ -0,0 +1,484 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\NestedRouteDefenseMode;
+use App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations;
+use App\Http\Middleware\BughuntCoverageMiddleware;
+use App\Http\Middleware\EnforceMcpTransport;
+use App\Http\Middleware\EnsureEmailIsVerifiedOrBack;
+use App\Http\Middleware\EnsureLoginMethodRemains;
+use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
+use App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization;
+use App\Http\Middleware\HandleInertiaRequests;
+use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\LocalOnly;
+use App\Http\Middleware\McpConsentOrganizationBinder;
+use App\Http\Middleware\NoStoreCacheHeadersForAuthenticatedPages;
+use App\Http\Middleware\NoStoreResponse;
+use App\Http\Middleware\RequireActiveSubscription;
+use App\Http\Middleware\RequireApiKeyAbility;
+use App\Http\Middleware\RequireRecentAuth;
+use App\Http\Middleware\RequireRecentAuthOnEmailChange;
+use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
+use App\Http\Middleware\ResolveApiActor;
+use App\Http\Middleware\SecurityHeaders;
+use App\Http\Middleware\VerifyMcpOrigin;
+use App\Http\Middleware\VerifySnsSignature;
+use App\Http\Routing\RouteBindingTypes;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
+use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
+use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
+use Illuminate\Cookie\Middleware\EncryptCookies;
+use Illuminate\Database\Eloquent\Model;
+use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
+use Illuminate\Routing\Middleware\SubstituteBindings;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Middleware\ValidateSignature;
+use Illuminate\Routing\Router;
+use Illuminate\Session\Middleware\AuthenticateSession;
+use Illuminate\Session\Middleware\StartSession;
+use Illuminate\View\Middleware\ShareErrorsFromSession;
+use Inertia\Middleware\EncryptHistory;
+use Tests\Support\Routing\NestedRouteDefenseInventory;
+
+/**
+ * テナント境界 404 の位置に関する順序不変条件 (audit-cycle-2 High-1 / T108 S4)。
+ *
+ * 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
+ * 境界 404 より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
+ * 応答 / 不在 = 404」という 1 bit の存在オラクルになる。監査時点では
+ * 課金ゲート 402/302・verified 302・2FA 強制 302・Inertia version mismatch 409・
+ * api-key.ability 403 のすべてがテナント境界より先に走っていた。
+ *
+ * 本テストは **解決後 (priority 適用後) の実行順** を測る。宣言順 (gatherMiddleware) を
+ * 見ていたことが、audit-cycle-2 で実測されるまで穴が見えなかった直接の原因である。
+ * 順序の正本は bootstrap/app.php の priority list であり、route の宣言順ではない。
+ *
+ * **例外機構は設けない (違反は無条件 fail)**。allowlist を作ると、そこへ逃がした route から
+ * 存在オラクルが再発する。将来やむを得ない例外が必要になったら、その時点で設計判断として
+ * 本テスト自体を変更すること (= 人間のレビューを必ず通す)。
+ *
+ * 正規化の仕様: {@see NestedRouteDefenseInventory::resolvedMiddleware()} が
+ * `Class:param` の parameter を落とし、alias 解決後の具象クラス名で返す。
+ * Inertia の middleware はアプリの具象 class (App\Http\Middleware\HandleInertiaRequests) と
+ * vendor class (Inertia\Middleware\EncryptHistory) の両方が現れる。
+ * closure 要素は分類不能として fail させる。
+ */
+
+/**
+ * 解決済み middleware クラス => 短絡しうるか (由来を問わず全件分類必須)。
+ *
+ * `true` = 3xx/4xx を返して $next を呼ばない分岐を持つ。
+ * **既定は true 側に倒す** (疑わしきは短絡扱い)。`false` を宣言してよいのは
+ * 「$next を必ず呼び、応答の加工しかしない」ことを実装で確認したときだけ。
+ * 未登録クラスの既定も true 扱い (検査 2 / 3b は `?? true`) なので、
+ * 分類漏れが偽陰性にはならない。
+ *
+ * @return array<class-string, bool>
+ */
+function middlewareShortCircuitInventory(): array
+{
+    return [
+        // --- 短絡しうる ---
+        Authenticate::class => true,
+        RedirectIfAuthenticated::class => true,
+        EnsureEmailIsVerified::class => true,
+        ThrottleRequests::class => true,
+        ValidateSignature::class => true,
+        PreventRequestForgery::class => true,
+        AuthenticateSession::class => true,
+        // binding 失敗そのものが 404 (短絡の基準点)
+        SubstituteBindings::class => true,
+        // Inertia の asset version mismatch は 409 で短絡する
+        HandleInertiaRequests::class => true,
+        RequireActiveSubscription::class => true,
+        RequireTwoFactorForEnforcedOrganizations::class => true,
+        BlockTwoFactorDisableForEnforcedOrganizations::class => true,
+        RequireRecentAuth::class => true,
+        RequireRecentAuthOnEmailChange::class => true,
+        RequireApiKeyAbility::class => true,
+        ResolveApiActor::class => true,
+        IdempotentRequest::class => true,
+        EnsureProjectBelongsToCurrentOrganization::class => true,
+        EnsureProjectBelongsToApiOrganization::class => true,
+        EnsureEmailIsVerifiedOrBack::class => true,
+        EnsureLoginMethodRemains::class => true,
+        LocalOnly::class => true,
+        McpConsentOrganizationBinder::class => true,
+        VerifyMcpOrigin::class => true,
+        EnforceMcpTransport::class => true,
+        VerifySnsSignature::class => true,
+        // --- 透過 (必ず $next を呼び、応答の加工のみ) ---
+        EncryptCookies::class => false,
+        AddQueuedCookiesToResponse::class => false,
+        StartSession::class => false,
+        ShareErrorsFromSession::class => false,
+        EncryptHistory::class => false,
+        SecurityHeaders::class => false,
+        NoStoreCacheHeadersForAuthenticatedPages::class => false,
+        NoStoreResponse::class => false,
+        BughuntCoverageMiddleware::class => false,
+    ];
+}
+
+/**
+ * SubstituteBindings より前に走る短絡 middleware => 「生 route parameter を読まない」宣言。
+ *
+ * pre-binding の短絡は全 id で同一の応答を返すため存在オラクルにならない。
+ * その前提が「route parameter を読まない」ことなので、宣言 + 静的検査で固定する。
+ *
+ * @return array<class-string, string>
+ */
+function preBindingShortCircuitInventory(): array
+{
+    return [
+        Authenticate::class => '認証状態のみで判定。route param を読まない',
+        ThrottleRequests::class => 'limiter キーは actor / IP。route param を読まない',
+        PreventRequestForgery::class => 'CSRF token のみ',
+        AuthenticateSession::class => 'session の password_hash のみ',
+        ResolveApiActor::class => 'api_key attribute / api-oauth guard のみ',
+    ];
+}
+
+/** テナント guard middleware の具象クラス (web / API の 2 本立て)。 */
+function tenantGuardMiddlewareClasses(): array
+{
+    return [
+        EnsureProjectBelongsToCurrentOrganization::class,
+        EnsureProjectBelongsToApiOrganization::class,
+    ];
+}
+
+/** route の inventory 宣言に指定モードが含まれるか。 */
+function tenantBoundaryHasMode(string $routeName, NestedRouteDefenseMode $mode): bool
+{
+    foreach (NestedRouteDefenseInventory::inventory()[$routeName] ?? [] as $declared) {
+        if ($declared === $mode) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+// --- 検査 1: 解決済み middleware の deny-by-default 分類 ---
+
+test('検査1: 検査対象 route の解決済み middleware は全件が短絡分類 inventory にある', function (): void {
+    $inventory = middlewareShortCircuitInventory();
+    $violations = [];
+
+    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
+        foreach (NestedRouteDefenseInventory::resolvedMiddleware($route) as $middleware) {
+            if ($middleware === '(closure)') {
+                $violations[] = "{$name}: 解決後の middleware に closure がある (短絡するか分類不能)";
+
+                continue;
+            }
+            if (! array_key_exists($middleware, $inventory)) {
+                $violations[] = "{$name}: {$middleware} が未分類";
+            }
+        }
+    }
+
+    $violations = array_values(array_unique($violations));
+    expect($violations)->toBe([],
+        '新しい middleware は必ず middlewareShortCircuitInventory() に分類してください '
+        .'(短絡しうるなら true。疑わしきは true 側に倒すこと)。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査1 補: 短絡分類 inventory に実在しないクラスを残さない', function (): void {
+    $stale = [];
+    foreach (array_keys(middlewareShortCircuitInventory()) as $class) {
+        if (! class_exists($class)) {
+            $stale[] = $class;
+        }
+    }
+
+    expect($stale)->toBe([], '実在しないクラスが分類 inventory に残っています: '.implode(', ', $stale));
+});
+
+// --- 検査 2: テナント guard は binding の直後 (間に短絡なし) ---
+
+test('検査2: TenantGuardMiddleware の route は binding とテナント guard の間に短絡が無い', function (): void {
+    $shortCircuits = middlewareShortCircuitInventory();
+    $violations = [];
+    $checked = 0;
+
+    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
+        if (! tenantBoundaryHasMode($name, NestedRouteDefenseMode::TenantGuardMiddleware)) {
+            continue;
+        }
+
+        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
+        if ($bindingIndex === false) {
+            $violations[] = "{$name}: SubstituteBindings が解決後の列に無い";
+
+            continue;
+        }
+
+        $guardIndex = false;
+        foreach (tenantGuardMiddlewareClasses() as $guard) {
+            $index = array_search($guard, $resolved, true);
+            if ($index !== false) {
+                $guardIndex = $index;
+
+                break;
+            }
+        }
+        if ($guardIndex === false) {
+            $violations[] = "{$name}: テナント guard middleware が解決後の列に無い";
+
+            continue;
+        }
+        if ($guardIndex < $bindingIndex) {
+            $violations[] = "{$name}: テナント guard が SubstituteBindings より前 (binding 済みモデルを読めない)";
+
+            continue;
+        }
+
+        foreach (array_slice($resolved, $bindingIndex + 1, $guardIndex - $bindingIndex - 1) as $between) {
+            if (($shortCircuits[$between] ?? true) === true) {
+                $violations[] = "{$name}: binding とテナント guard の間に短絡しうる {$between} がある"
+                    .' (他組織に実在 = その短絡の応答 / 不在 = 404 の存在オラクルになります)';
+            }
+        }
+        $checked++;
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
+});
+
+// --- 検査 3a: 手動解決 param は binding 段で解決されない ---
+
+test('検査3a: ManualOwnerScopedResolution の param は binding 段で解決されない', function (): void {
+    $inventory = NestedRouteDefenseInventory::inventory();
+    /** @var Router $router */
+    $router = app('router');
+    $violations = [];
+    $checked = 0;
+
+    foreach (NestedRouteDefenseInventory::registeredRoutes() as $name => $route) {
+        foreach ($inventory[$name] as $param => $mode) {
+            if ($mode !== NestedRouteDefenseMode::ManualOwnerScopedResolution) {
+                continue;
+            }
+
+            // 条件 1: controller action の対応引数の型が Eloquent Model 派生でないこと
+            // (Model 型だと ImplicitRouteBinding が binding 段で解決してしまう)
+            $signature = null;
+            foreach ($route->signatureParameters() as $parameter) {
+                if ($parameter->getName() === $param) {
+                    $signature = $parameter;
+
+                    break;
+                }
+            }
+            $type = $signature?->getType();
+            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
+            if ($typeName !== null && is_a($typeName, Model::class, true)) {
+                $violations[] = "{$name} の {{$param}}: action 引数が Model 型 ({$typeName}) = implicit binding が"
+                    .'復活しており、不在 id だけが binding 段で 404 になる (存在オラクル)';
+            }
+
+            // 条件 2: RouteBindingTypes の手動解決 exclusion に route identity ごと登録済みであること
+            $registered = RouteBindingTypes::MANUALLY_RESOLVED[$param]['routes'] ?? [];
+            if (! in_array($name, $registered, true)) {
+                $violations[] = "{$name} の {{$param}}: RouteBindingTypes::MANUALLY_RESOLVED に未登録";
+            }
+
+            // 条件 3: explicit binder (Route::bind / Route::model) が登録されていないこと
+            if ($router->getBindingCallback($param) !== null) {
+                $violations[] = "{$name} の {{$param}}: explicit binder が登録されている = binding 段で解決される";
+            }
+
+            $checked++;
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+    expect($checked)->toBeGreaterThan(0);
+});
+
+// --- 検査 3b: inline guard route は binding より後に短絡が無い ---
+
+test('検査3b: UrlIntegrityGuard の route は binding より後に短絡が無い', function (): void {
+    $shortCircuits = middlewareShortCircuitInventory();
+    $violations = [];
+
+    foreach (NestedRouteDefenseInventory::registeredRoutes() as $name => $route) {
+        if (! tenantBoundaryHasMode($name, NestedRouteDefenseMode::UrlIntegrityGuard)) {
+            continue;
+        }
+
+        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
+        if ($bindingIndex === false) {
+            continue;
+        }
+
+        foreach (array_slice($resolved, $bindingIndex + 1) as $after) {
+            if (($shortCircuits[$after] ?? true) === true) {
+                $violations[] = "{$name}: inline guard は controller まで到達して初めて 404 になるのに、"
+                    ."binding より後に短絡しうる {$after} がある (存在オラクル)";
+            }
+        }
+    }
+
+    // S3 完了後は該当 route が 0 件になる見込みだが、将来の再導入を落とすためテストは残す
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+// --- 検査 4: pre-binding 短絡の性質固定 ---
+
+test('検査4: binding より前に走る短絡 middleware は inventory 登録済み', function (): void {
+    $shortCircuits = middlewareShortCircuitInventory();
+    $preBinding = preBindingShortCircuitInventory();
+    $violations = [];
+
+    foreach (NestedRouteDefenseInventory::tenantDefenseRoutes() as $name => $route) {
+        $resolved = NestedRouteDefenseInventory::resolvedMiddleware($route);
+        $bindingIndex = array_search(SubstituteBindings::class, $resolved, true);
+        if ($bindingIndex === false) {
+            continue;
+        }
+
+        foreach (array_slice($resolved, 0, $bindingIndex) as $before) {
+            if (($shortCircuits[$before] ?? true) !== true) {
+                continue;
+            }
+            if (! array_key_exists($before, $preBinding)) {
+                $violations[] = "{$name}: binding より前に走る短絡 {$before} が"
+                    .' preBindingShortCircuitInventory() に未登録';
+            }
+        }
+    }
+
+    $violations = array_values(array_unique($violations));
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+/*
+ * 検査 4(a): 「生 route parameter を読まない」ことの静的検査。
+ *
+ * **限界の明示**: 呼び出し先クラス経由の間接参照は静的には証明できない
+ * (例: ThrottleRequests 自体は param を読まないが、named limiter の closure が読みうる)。
+ * そのため二段構えにしている:
+ *   - 静的: 本テスト (直接の $request->route(...) 参照を落とす)
+ *   - 振る舞い: tests/Feature/Security/TenantBoundaryPrecedenceTest (実在 id と不在 id の
+ *     応答同一性) と tests/Feature/Security/NamedRateLimiterKeyTest (bucket 共有の証明)
+ */
+test('検査4(a): pre-binding 短絡 middleware のソースは生 route parameter を読まない', function (): void {
+    /*
+     | 禁じるのは「route **parameter** の読み取り」であって Route オブジェクトの参照ではない。
+     | 例: ThrottleRequests は `$request->route()` を取得して `getDomain()` だけを読む。
+     | これは URL 上の id と無関係なので存在オラクルにならない。
+     | したがって引数付きの `route('x')` / `parameter(` / `input(` / `segment(` を検出する。
+     */
+    $forbidden = [
+        "->route('",
+        '->route("',
+        // 変数引数 ($request->route($param)) も落とす。文字列リテラルだけを見ていると
+        // 「param 名を変数で渡す」書き方で素通りする (impl-review R1 Warning)
+        '->route($',
+        '->parameter(',
+        '->parameters(',
+        'Route::input(',
+        '->segment(',
+        '->segments(',
+    ];
+    $violations = [];
+
+    foreach (array_keys(preBindingShortCircuitInventory()) as $class) {
+        $file = (new ReflectionClass($class))->getFileName();
+        expect($file)->not->toBeFalse("{$class} のソースを取得できない");
+        $raw = file_get_contents((string) $file);
+        expect($raw)->not->toBeFalse();
+
+        // コメント / docblock を除いた実行コードだけを対象にする
+        // (「読まない」と説明する docblock 自身が偽陽性を出さないようにする)
+        $code = '';
+        foreach (token_get_all((string) $raw) as $token) {
+            if (is_array($token)) {
+                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
+                    continue;
+                }
+                $code .= $token[1];
+
+                continue;
+            }
+            $code .= $token;
+        }
+
+        foreach ($forbidden as $needle) {
+            if (str_contains($code, $needle)) {
+                $violations[] = "{$class} が `{$needle}` を使っている"
+                    .' (binding 前に route parameter を読むと存在オラクルになりうる)';
+            }
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+// --- 検査 5: 完全順序の pin ---
+
+test('検査5: 代表 route の解決後 middleware 列を完全一致で固定する', function (): void {
+    $webHead = [
+        EncryptCookies::class,
+        AddQueuedCookiesToResponse::class,
+        StartSession::class,
+        ShareErrorsFromSession::class,
+        PreventRequestForgery::class,
+        Authenticate::class,
+        AuthenticateSession::class,
+        SubstituteBindings::class,
+    ];
+    $webAppend = [
+        HandleInertiaRequests::class,
+        SecurityHeaders::class,
+        RequireTwoFactorForEnforcedOrganizations::class,
+        BlockTwoFactorDisableForEnforcedOrganizations::class,
+        NoStoreCacheHeadersForAuthenticatedPages::class,
+        EncryptHistory::class,
+        EnsureEmailIsVerified::class,
+    ];
+    $guard = EnsureProjectBelongsToCurrentOrganization::class;
+    $billing = RequireActiveSubscription::class;
+
+    $apiHead = [
+        Authenticate::class,
+        ThrottleRequests::class,
+        ResolveApiActor::class,
+        SubstituteBindings::class,
+        EnsureProjectBelongsToApiOrganization::class,
+        RequireApiKeyAbility::class,
+    ];
+
+    $expected = [
+        // API: actor 解決 → binding → テナント境界 404 → ability 403 → idempotency
+        'api.v1.projects.items.store' => [...$apiHead, IdempotentRequest::class],
+        'api.v1.projects.items.index' => $apiHead,
+        // {project} を持たない route でも guard は列に載る (no-op。group 一括付与の許容)
+        'api.v1.me' => $apiHead,
+        // web: テナント境界 404 が Inertia / 2FA / verified / 課金ゲートより前
+        'projects.update' => [...$webHead, $guard, ...$webAppend, $billing],
+        'capture.manuals.show' => [...$webHead, $guard, ...$webAppend, $billing],
+        // guard を持たない web route の列は変化しない (priority 追加の副作用が無いことの pin)
+        'organizations.settings' => [...$webHead, ...$webAppend],
+    ];
+
+    $routes = app('router')->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach ($expected as $name => $expectedChain) {
+        $route = $routes->getByName($name);
+        expect($route)->not->toBeNull("route '{$name}' が存在しない");
+        expect(NestedRouteDefenseInventory::resolvedMiddleware($route))
+            ->toBe($expectedChain, "route '{$name}' の解決後 middleware 列");
+    }
+});
diff --git a/tests/Support/ResponseSignature.php b/tests/Support/ResponseSignature.php
new file mode 100644
index 0000000..6e2bd18
--- /dev/null
+++ b/tests/Support/ResponseSignature.php
@@ -0,0 +1,97 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use Illuminate\Testing\TestResponse;
+
+/**
+ * 「2 つの応答が観測上まったく同じか」を比較するための正規化ヘルパ。
+ *
+ * 存在オラクル (実在 id / 不在 id で応答が分岐すること) の不成立を検証するには
+ * status / body だけでなく**ヘッダも**一致していなければならない
+ * (302 同士でも Location が違えば 1 bit 漏れる)。
+ * ただし連続リクエストで必ず差分が出る volatile ヘッダ (Date / Set-Cookie /
+ * X-RateLimit-* / Retry-After / request id 系) を含めた生の完全一致比較は
+ * 恒常的に flaky になるため、それらを除外した signature で比較する。
+ *
+ * **除外は「観測者にとって意味を持たない差分」に限定する**。
+ * Location / Content-Type / Content-Length など、遷移先や中身を示すヘッダは
+ * 必ず比較対象に残す (ここを緩めると検証が空洞化する)。
+ */
+final class ResponseSignature
+{
+    /**
+     * 連続リクエストで必ず差分が出る (= 存在の証拠にならない) ヘッダ名 (小文字)。
+     *
+     * @var list<string>
+     */
+    private const VOLATILE_EXACT = [
+        'date',
+        'set-cookie',
+        'retry-after',
+        // Expires / Age は「現在時刻から導出される値」なので連続リクエストで必ずズレる。
+        // ETag / Last-Modified は **除外しない** — リソース内容や更新時刻に由来する
+        // 安定した差分になりうるため、存在オラクル検査の対象に残す (impl-review R1 Warning)。
+        'expires',
+        'age',
+    ];
+
+    /**
+     * 上記に加え、prefix 一致で除外するヘッダ名 (小文字)。
+     *
+     * @var list<string>
+     */
+    private const VOLATILE_PREFIX = [
+        'x-ratelimit-',
+        'x-request-id',
+        'x-correlation-id',
+        'request-id',
+    ];
+
+    /**
+     * 応答の観測可能な signature (status + 正規化ヘッダ + body)。
+     *
+     * @return array{status: int, headers: array<string, list<string>>, body: string}
+     */
+    public static function of(TestResponse $response): array
+    {
+        /** @var array<string, list<string>> $headers */
+        $headers = [];
+        foreach ($response->headers->all() as $name => $values) {
+            $lower = strtolower((string) $name);
+            if (self::isVolatile($lower)) {
+                continue;
+            }
+            $normalized = [];
+            foreach ($values as $value) {
+                $normalized[] = (string) $value;
+            }
+            sort($normalized);
+            $headers[$lower] = $normalized;
+        }
+        ksort($headers);
+
+        return [
+            'status' => $response->getStatusCode(),
+            'headers' => $headers,
+            'body' => $response->getContent() === false ? '' : $response->getContent(),
+        ];
+    }
+
+    private static function isVolatile(string $lowerName): bool
+    {
+        if (in_array($lowerName, self::VOLATILE_EXACT, true)) {
+            return true;
+        }
+
+        foreach (self::VOLATILE_PREFIX as $prefix) {
+            if (str_starts_with($lowerName, $prefix)) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+}
diff --git a/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php b/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php
new file mode 100644
index 0000000..a5790b8
--- /dev/null
+++ b/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php
@@ -0,0 +1,84 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\TrustedProxiesConfigValidator;
+
+/*
+ * production 起動時の TRUSTED_PROXIES 検証 (検査 1-5)。
+ *
+ * 検査の**順序**が load-bearing: `none` sentinel を書式検査より先に処理しないと、
+ * `none` 自身が「config 段で落ちた不正値」として reject され、
+ * 「プロキシ無し構成の明示宣言」という逃げ道が塞がってしまう。
+ */
+
+/** @param list<string> $raw */
+function assertProxyValidationFails(array $proxies, array $raw, string $expectedFragment): void
+{
+    $validator = new TrustedProxiesConfigValidator;
+
+    try {
+        $validator->validateForProduction($proxies, $raw);
+        expect(false)->toBeTrue('RuntimeException が投げられなかった');
+    } catch (RuntimeException $e) {
+        expect($e->getMessage())->toContain($expectedFragment);
+    }
+}
+
+test('検査1: * / ** / prefix 0 の CIDR は全アドレス信頼として reject', function (string $wildcard): void {
+    // `0.0.0.0/0` / `::/0` は書式として正当な CIDR だが全アドレスを含む = `*` と同値。
+    // 書式検査だけでは通り抜けるため、専用の判定で最優先に落とす (impl-review R1 Critical)
+    assertProxyValidationFails([], [$wildcard], 'Trusting every address');
+})->with(['*', '**', '0.0.0.0/0', '::/0']);
+
+test('検査1: prefix 0 の CIDR は実 hop と併記していても reject', function (): void {
+    assertProxyValidationFails(['10.0.0.0/8'], ['10.0.0.0/8', '0.0.0.0/0'], 'Trusting every address');
+});
+
+test('検査1: * は他の値と併記していても reject (最優先で落とす)', function (): void {
+    assertProxyValidationFails(['10.0.0.0/8'], ['10.0.0.0/8', '*'], 'Trusting every address');
+});
+
+test('検査2: none 単独は正常終了 (プロキシ無し構成の明示宣言)', function (): void {
+    $validator = new TrustedProxiesConfigValidator;
+    $validator->validateForProduction([], ['none']);
+
+    // 例外が出なければ成功。空要素の混在 (末尾カンマ等) も trim/除外される
+    $validator->validateForProduction([], ['none', '', '  ']);
+    expect(true)->toBeTrue();
+});
+
+test('検査2: none + 他 token は曖昧宣言として reject', function (): void {
+    assertProxyValidationFails(['10.0.0.0/8'], ['none', '10.0.0.0/8'], 'must be declared alone');
+});
+
+test('検査2: none 宣言なのに proxies が非空なら設定不整合として reject', function (): void {
+    assertProxyValidationFails(['10.0.0.0/8'], ['none'], 'resolved proxy list is not empty');
+});
+
+test('検査3: REMOTE_ADDR は production では reject', function (): void {
+    assertProxyValidationFails(['REMOTE_ADDR'], ['REMOTE_ADDR'], 'REMOTE_ADDR');
+});
+
+test('検査4: 書式不正は config 段の silent drop を表面化させて reject', function (): void {
+    assertProxyValidationFails(
+        ['10.0.0.0/8'],
+        ['10.0.0.0/8', '999.999.999.999/99'],
+        'invalid value "999.999.999.999/99"',
+    );
+});
+
+test('検査5: 未設定 (空) は宣言漏れとして reject', function (): void {
+    assertProxyValidationFails([], [], 'TRUSTED_PROXIES is not set');
+    assertProxyValidationFails([], [''], 'TRUSTED_PROXIES is not set');
+});
+
+test('正常系: 実 hop の CIDR 列挙は通過する', function (): void {
+    $validator = new TrustedProxiesConfigValidator;
+    $validator->validateForProduction(
+        ['10.0.0.0/8', '172.16.0.0/12', '2001:db8::/32'],
+        ['10.0.0.0/8', '172.16.0.0/12', '2001:db8::/32'],
+    );
+
+    expect(true)->toBeTrue();
+});
diff --git a/tests/Unit/Support/TrustedProxyTokenTest.php b/tests/Unit/Support/TrustedProxyTokenTest.php
new file mode 100644
index 0000000..b008a41
--- /dev/null
+++ b/tests/Unit/Support/TrustedProxyTokenTest.php
@@ -0,0 +1,67 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Support\TrustedProxyToken;
+
+/*
+ * TRUSTED_PROXIES の 1 token 判定。config 段の filter と起動時 validator が
+ * **同じ関数**を使う前提なので、ここが正しくないと silent drop / 誤 reject の
+ * どちらかが必ず起きる。
+ */
+
+test('単一 IP / CIDR / REMOTE_ADDR は信頼可能な token', function (string $token): void {
+    expect(TrustedProxyToken::isTrustableAddress($token))->toBeTrue();
+})->with([
+    '10.0.0.0/8',
+    '192.168.1.1',
+    '172.16.0.0/12',
+    '2001:db8::/32',
+    '::1',
+    '2001:db8::/128',
+    TrustedProxyToken::REMOTE_ADDR,
+]);
+
+test('書式不正な token は信頼できない (正規表現の緩い判定に落ちない)', function (string $token): void {
+    expect(TrustedProxyToken::isTrustableAddress($token))->toBeFalse();
+})->with([
+    '999.999.999.999/999',
+    '10.0.0.0/33',
+    '2001:db8::/129',
+    '10.0.0.0/',
+    '10.0.0.0/abc',
+    '10.0.0.0/8/16',
+    '*',
+    '**',
+    // prefix 長 0 の CIDR は全アドレス = `*` と同値 (impl-review R1 Critical)
+    '0.0.0.0/0',
+    '::/0',
+    '0000:0000:0000:0000:0000:0000:0000:0000/0',
+    'none',
+    'example.com',
+    '',
+    ' ',
+]);
+
+test('isCidr は prefix 長の上限を IP バージョンごとに判定する', function (): void {
+    expect(TrustedProxyToken::isCidr('10.0.0.0/32'))->toBeTrue()
+        ->and(TrustedProxyToken::isCidr('10.0.0.0/33'))->toBeFalse()
+        ->and(TrustedProxyToken::isCidr('2001:db8::/128'))->toBeTrue()
+        ->and(TrustedProxyToken::isCidr('2001:db8::/129'))->toBeFalse()
+        // prefix 無しの単一 IP は CIDR ではない (isTrustableAddress 側で許可される)
+        ->and(TrustedProxyToken::isCidr('10.0.0.1'))->toBeFalse();
+});
+
+test('none sentinel は framework に渡す値ではない (空 list へ写すためのマーカー)', function (): void {
+    expect(TrustedProxyToken::isTrustableAddress(TrustedProxyToken::NONE))->toBeFalse();
+});
+
+test('全アドレス等価の宣言 (* / ** / prefix 0 の CIDR) は isAllAddresses が true', function (string $token): void {
+    expect(TrustedProxyToken::isAllAddresses($token))->toBeTrue();
+    // framework へ渡す候補からも必ず外れる (fail-secure)
+    expect(TrustedProxyToken::isTrustableAddress($token))->toBeFalse();
+})->with(['*', '**', '0.0.0.0/0', '::/0', '0000:0000:0000:0000:0000:0000:0000:0000/0']);
+
+test('実 hop の CIDR は isAllAddresses が false', function (string $token): void {
+    expect(TrustedProxyToken::isAllAddresses($token))->toBeFalse();
+})->with(['10.0.0.0/8', '10.0.0.1', '10.0.0.0/32', '2001:db8::/32', '2001:db8::/128', 'REMOTE_ADDR']);

```

---

## 再レビュー依頼

1. Critical (全アドレス等価の宣言) の塞ぎ方が十分か。
   ほかに「`*` と実質同値だが書式検査を通る」表現が残っていないか
   (例: IPv4-mapped IPv6 / 短縮表記の揺れ / `REMOTE_ADDR` の扱い)
2. Warning 2 件の対応が意図どおりか (過剰・不足がないか)
3. Round 1 で OK 判定だった箇所に、この修正が新たな問題を持ち込んでいないか

全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。
