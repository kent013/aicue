Round 2 の指摘に対する対応が完了しました。再レビュー (最終ラウンド) をお願いします。

## 対応マトリクス
# 対応マトリクス: impl-review Round 2 (T120)

## [Warning] `str_contains($source, '$this->rateLimit(')` はファイル内のどこかにあれば合格してしまう
- 判断: **対応する**
- 根拠: 指摘のとおり、コメント化 / 別メソッドへの移動 / 文字列リテラル中の記述でも通る。
  deny-by-default の検査で誤合格は最悪の失敗モードであり、この形では前提を固定できていない。
- 対応内容: `throttlePremiseMethodRateLimits(class, method)` を追加。`ReflectionMethod` で
  **対象メソッドの本体だけ**を切り出し、コメント / 文字列リテラル / 空白を token 段階で除去してから
  `-> rateLimit (` の並びを探す (`AuthorizationMarkerScanner` と同じ流儀)。固定対象は
  panel が公開する credential 操作の**実行メソッド** 5 本:
  `Login::authenticate` / `EditProfile::save` / `SetUpAppAuthenticationAction::make` /
  `DisableAppAuthenticationAction::make` / `RegenerateAppAuthenticationRecoveryCodesAction::make`。
  あわせて **negative control** (`Login::mount` では false になること) を同テスト内に置き、
  「常に true を返す検査」に退化していないことも固定した。

## [Warning] `filament.admin.auth.multi-factor-authentication.set-up-required` の component 制限が未確認
- 判断: **対応する**
- 根拠: panel は `AppAuthentication` (TOTP, recoverable) を有効にしており、MFA セットアップ画面は
  Livewire POST 上で credential 操作 (TOTP 登録 / 無効化 / リカバリコード再生成) を提供する。
  exemption の射程に入る以上、確認しないのは不整合。
- 対応内容: 上記 5 本の固定対象に MFA の 3 Action (`SetUp` / `Disable` / `Regenerate`) を含めた。
  `Email` 系 Action は panel が `AppAuthentication` のみを登録しているため対象外
  (有効化されれば auth ページ集合の固定テストが先に fail して再検討を強制する)。
  なぜ set-up-required 画面自体ではなく Action を固定するのかを、集合固定テストのコメントに明記。

## [Suggestion] logout の `assertRedirect()` だけでは「本体へ到達していない」証明にならない
- 判断: **対応する**
- 根拠: 根拠 (`auth 必須`) とテスト (`redirect する`) が一致していない。
- 対応内容: `logout` / `filament.admin.auth.logout` の**実効 middleware 列**に
  `Illuminate\Auth\Middleware\Authenticate` があること (構造) を検査し、
  そのうえで未認証 POST が redirect されること (実挙動) を確認する 2 段構成にした。

## [Warning] `RouteThrottleBinder` のクラス docblock が実装と矛盾している (第 2 段 / cached 起動で冪等 no-op)
- 判断: **対応する**
- 根拠: セキュリティ機構の契約説明が実装と食い違うのは、将来の改修時に誤った前提を与える。
- 対応内容: クラス docblock を全面的に書き直した。位置づけを**第 3 段**
  (第 1 段 = 自前 route の定義 / 第 2 段 = package の設定 / 第 3 段 = 本 binder) と明記し、
  route:cache との関係を「生成時に焼き込む + cached 起動では skip」に修正。
  冪等性の説明も「同一 bootstrap 内の重複呼び出し」に修正した。

## [Suggestion] `attachByName()` の「route:cache 由来の再適用」コメントが不正確
- 判断: **対応する**
- 対応内容: 「期待どおりの throttle が既にある = 冪等 no-op (同一 bootstrap 内での重複呼び出し /
  既に route 定義側で貼られている場合)」に修正。

## route cache の残リスク評価について (Codex コメントへの応答)
- 判断: **現状の受容で確定 (追加実装はしない)**
- 根拠: 「コード内で完結する fail-fast から、デプロイ手順を含む保証へ変わっている」という整理に同意する。
  ただし本リポジトリにはデプロイパイプラインが同梱されておらず、`route:cache` の再生成を
  機械強制する場所が存在しない (CI script に入れることは詳細設計で明示的に禁止されている)。
  現時点で作れるのは「文書化 + 残リスクの明記」までであり、これを超える仕組み
  (デプロイ検証コマンド等) は本 TODO の射程外の新規機構になる (AGENTS.md 思考原則 2)。
  なお **route 名の消失に対する fail-fast は失われていない** (route:cache 生成時に必ず走る)。
  受容している残リスクは「stale な route cache のまま起動する」場合に限られ、
  その旨を binder docblock / AGENTS.md / docs/app-integration-guide.md §7b の 3 箇所に明記した。

## Round 2 以降の追加差分
diff --git a/app/Support/Http/RouteThrottleBinder.php b/app/Support/Http/RouteThrottleBinder.php
new file mode 100644
index 0000000..2282d3c
--- /dev/null
+++ b/app/Support/Http/RouteThrottleBinder.php
@@ -0,0 +1,268 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Http;
+
+use Illuminate\Contracts\Foundation\Application;
+use Illuminate\Contracts\Foundation\CachesRoutes;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Route;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Str;
+use RuntimeException;
+
+/**
+ * vendor が登録した named route へ throttle middleware を後付けする binder。
+ *
+ * ★位置づけ: 「貼る仕組みの 3 段優先順」(docs/app-integration-guide.md §7b) の**第 3 段**。
+ *   第 1 段 = 自前 route の定義に直接書く / 第 2 段 = package の設定で貼る
+ *   (`config/fortify.php` の `limiters` 等)。**第 2 段でも貼れない vendor route 専用**であり、
+ *   設定で貼れるものをここへ持ってこない。
+ *
+ * ★route:cache との関係 (契約):
+ *   - `php artisan route:cache` は `route:clear` 後に**アプリを再 bootstrap** して route を
+ *     直列化するため、その再 bootstrap で本後付けが完全に走り、throttle は cache へ焼き込まれる。
+ *     route 名が消えていればここで**デプロイが止まる** (fail-fast はここで効く)。
+ *   - **cached 起動では後付けを skip する**。compiled route collection が本 binder の
+ *     booted callback より後に読まれ、named route を 1 本も解決できないため
+ *     (詳細と残リスクは {@see attachOnBooted})。
+ *
+ * ★判定は文字列の完全一致にしない:
+ *   実効 middleware の entry は `{class}:{params}` 形式で出る。
+ *   class 部は cache driver によって ThrottleRequests / ThrottleRequestsWithRedis の
+ *   どちらにもなりうる (後者は前者を継承)。class 部は is_a() で、params 部は
+ *   limiter 名の完全一致で比較する。
+ *
+ * ★付与は冪等: 同一 bootstrap 内で同じ (route, limiter) を複数回渡しても 1 本のままにする。
+ */
+final class RouteThrottleBinder
+{
+    /** named limiter 名の形式。 */
+    private const NAMED_LIMITER_PATTERN = '/^[a-z][a-z0-9-]*$/';
+
+    /** inline throttle (`{max},{decay}`) の形式。 */
+    private const INLINE_LIMITER_PATTERN = '/^\d+,\d+$/';
+
+    /**
+     * 起動完了後に named route 群へ throttle を後付けする (登録の唯一の入口)。
+     *
+     * ★route:cache 起動では **skip する**。実測した provider 順序:
+     *   framework の RouteServiceProvider は `withRouting()` が booting callback で
+     *   登録するため **最後に boot** され、compiled route の読み込み
+     *   (`loadCachedRoutes()`) はさらにその中の `$app->booted()` へ積まれる。
+     *   よって本 callback が走る時点では compiled route collection がまだ読まれておらず、
+     *   named route を 1 本も解決できない (`loadRoutesFrom()` が cache 時に require を
+     *   飛ばすのと同じ事情)。
+     *
+     * ★skip が穴にならない根拠 (fail-fast は失われない):
+     *   `php artisan route:cache` は `route:clear` してから**アプリを再 bootstrap** して
+     *   route を直列化する。その再 bootstrap は cache 無しなので本後付けが完全に走り、
+     *   route 名が消えていればそこで**デプロイが止まる**。付与済みの throttle は
+     *   そのまま cache へ焼き込まれる。CI (テスト) も cache 無しで走るため、
+     *   目録検査 (ThrottleCoverageInventoryTest) の deny-by-default も素通りしない。
+     *
+     * ★残るリスクと運用要件 (誇張しない):
+     *   守れないのは「**stale な route cache のまま起動する**」場合だけである。
+     *   その cache は古い付与状態を保持しているため、`php artisan route:cache` を
+     *   **毎デプロイ再生成する**ことが本機構の前提条件になる
+     *   (docs/app-integration-guide.md §7b にも運用要件として明記)。
+     *
+     * @param  array<string, string>  $routes  route 名 => limiter (named 名 or `{max},{decay}`)
+     */
+    public static function attachOnBooted(Application $app, array $routes): void
+    {
+        $app->booted(static function (Application $app) use ($routes): void {
+            self::attachAll(
+                $app->make(Router::class),
+                $routes,
+                $app instanceof CachesRoutes && $app->routesAreCached(),
+            );
+        });
+    }
+
+    /**
+     * named route 群へ throttle を後付けする (`$routesAreCached` なら何もしない)。
+     *
+     * skip 判定を引数で受けることで、判定と後付けの両方を純粋関数として検証できる
+     * ({@see attachOnBooted} が実アプリの状態を渡す唯一の配線点)。
+     *
+     * @param  array<string, string>  $routes  route 名 => limiter
+     */
+    public static function attachAll(Router $router, array $routes, bool $routesAreCached): void
+    {
+        if ($routesAreCached) {
+            return; // 後付けは route:cache 生成時に焼き込み済み
+        }
+
+        foreach ($routes as $name => $limiter) {
+            self::attachByName($router, $name, $limiter);
+        }
+    }
+
+    /**
+     * named route へ `throttle:{$limiter}` を冪等に後付けする。
+     *
+     * @param  string  $routeName  Fortify / Cashier 等が登録した route 名
+     * @param  string  $limiter  named limiter 名 または `{max},{decay}` 形式
+     *
+     * @throws RuntimeException route が引けない / 別の throttle が既に付いている / 2 本以上ある
+     */
+    public static function attachByName(Router $router, string $routeName, string $limiter): void
+    {
+        // ★期待値の検証を最初に行う (route 解決や既存 entry の有無に依存させない)。
+        //   ここを後回しにすると「初回呼び出しでは `6,1,9` のような不正形式を素通しする」
+        //   非対称な穴になる。
+        self::assertValidLimiter($limiter, "throttle の期待値 [{$limiter}] (route [{$routeName}])");
+
+        $routes = $router->getRoutes();
+        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
+        // (FortifyServiceProvider::attachRecentAuthToSensitiveRoutes と同じ前提)
+        $routes->refreshNameLookups();
+
+        $route = $routes->getByName($routeName);
+        if (! $route instanceof Route) {
+            throw new RuntimeException(
+                "throttle を後付けすべき route [{$routeName}] が見つかりません。"
+                .'vendor package が update で route 名を変えた可能性があります。'
+                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
+            );
+        }
+
+        $entries = self::routeThrottleEntries($router, $route);
+        if ($entries === []) {
+            $route->middleware('throttle:'.$limiter);
+
+            // ★memoization の破棄が必須:
+            //   Route::gatherMiddleware() は結果を $computedMiddleware に memoize し、
+            //   dispatch 時の Router::gatherRouteMiddleware() もこの値を読む。
+            //   直前の throttleEntries() が memo を温めてしまうため、破棄しないと
+            //   「middleware() には載っているのに実行されない throttle」= 無音の無防備になる。
+            $route->computedMiddleware = null;
+
+            return;
+        }
+
+        if (count($entries) === 1) {
+            // 既存 entry 側の params も形式検証する (想定外の throttle を素通ししない)
+            $parsed = self::parseThrottleEntry($entries[0], "route [{$routeName}] の既存 throttle [{$entries[0]}]");
+            if ($parsed['params'] === $limiter) {
+                // 期待どおりの throttle が既にある = 冪等 no-op
+                // (同一 bootstrap 内での重複呼び出し / 既に route 定義側で貼られている場合)
+                return;
+            }
+        }
+
+        throw new RuntimeException(
+            "route [{$routeName}] に想定外の throttle が付いています: ".implode(', ', $entries)
+            .' (期待: throttle:'.$limiter.')。二重付与は実効上限を半減させるため起動を止めます。',
+        );
+    }
+
+    /**
+     * 実効 middleware 列 (controller middleware 込み) のうち throttle entry を返す。
+     *
+     * 目録検査 (ThrottleCoverageInventoryTest) が使う**完全な**判定点。
+     * `Route::gatherMiddleware()` は controller を container から解決するため、
+     * **boot 中に呼んではならない** ({@see routeThrottleEntries} を使うこと)。
+     *
+     * @return list<string> `{class}:{params}` 形式の entry (params なしなら class のみ)
+     */
+    public static function throttleEntries(Router $router, Route $route): array
+    {
+        return self::filterThrottleEntries($router->gatherRouteMiddleware($route));
+    }
+
+    /**
+     * route 自身 (group 展開込み) の middleware のうち throttle entry を返す。
+     *
+     * ★controller middleware を見ない理由 (boot 中の副作用を避ける):
+     *   `Route::gatherMiddleware()` は controller middleware を集めるために
+     *   **controller を container から解決する**。boot 中にこれを行うと、
+     *   controller が constructor injection で要求する request scope の singleton
+     *   (`StatefulGuard` → `session.store` 等) が boot 時点で確定してしまい、
+     *   その後の設定変更・request 生成に追随しなくなる
+     *   (実測: Fortify の ConfirmablePasswordController が StatefulGuard を要求する)。
+     *
+     * ★見落としが穴にならない根拠:
+     *   controller middleware が throttle を足していた場合、本 binder は二重付与になるが、
+     *   目録検査 ({@see throttleEntries} を使う ThrottleCoverageInventoryTest) が
+     *   「throttle 2 本以上」として必ず fail させる。
+     *
+     * @return list<string>
+     */
+    public static function routeThrottleEntries(Router $router, Route $route): array
+    {
+        return self::filterThrottleEntries(
+            $router->resolveMiddleware($route->middleware(), $route->excludedMiddleware()),
+        );
+    }
+
+    /**
+     * 解決済み middleware 列から throttle entry だけを取り出す。
+     *
+     * @param  iterable<mixed>  $resolved
+     * @return list<string>
+     */
+    private static function filterThrottleEntries(iterable $resolved): array
+    {
+        $entries = [];
+
+        foreach ($resolved as $middleware) {
+            // 解決後の列には Closure middleware も混ざりうる (throttle ではない)
+            if (is_string($middleware) && self::isThrottleEntry($middleware)) {
+                $entries[] = $middleware;
+            }
+        }
+
+        return $entries;
+    }
+
+    /** entry の class 部が throttle middleware か。 */
+    public static function isThrottleEntry(string $middlewareEntry): bool
+    {
+        $class = Str::before($middlewareEntry, ':'); // class 名に ':' は含まれない
+
+        return is_a($class, ThrottleRequests::class, true);
+    }
+
+    /**
+     * throttle entry を class 部 / params 部に分解し、params の形式まで検証する。
+     *
+     * @return array{class: string, params: string}
+     *
+     * @throws RuntimeException params が named / inline のどちらの形式にも一致しない場合
+     */
+    private static function parseThrottleEntry(string $entry, string $context): array
+    {
+        $class = Str::before($entry, ':');
+        // ★`:` を含まない entry (パラメータなし throttle) は params = '' になり、
+        //   assertValidLimiter が必ず例外側へ落とす (意図どおり)。
+        $params = Str::contains($entry, ':') ? Str::after($entry, ':') : '';
+
+        self::assertValidLimiter($params, $context);
+
+        return ['class' => $class, 'params' => $params];
+    }
+
+    /**
+     * limiter 指定の形式を検証する (開発時ミス / 想定外 throttle の検出)。
+     *
+     * @throws RuntimeException named / inline のどちらの形式にも一致しない場合
+     */
+    private static function assertValidLimiter(string $limiter, string $context): void
+    {
+        if (preg_match(self::NAMED_LIMITER_PATTERN, $limiter) === 1) {
+            return;
+        }
+        if (preg_match(self::INLINE_LIMITER_PATTERN, $limiter) === 1) {
+            return;
+        }
+
+        throw new RuntimeException(
+            $context.' が throttle の許容形式に一致しません。'
+            .'named limiter 名 (`[a-z][a-z0-9-]*`) か inline 形式 (`{max},{decay}`) のいずれかで指定してください。'
+            .'想定外の形式を素通しすると、意図しない上限のまま公開される事故になります。',
+        );
+    }
+}
diff --git a/tests/Feature/Security/ThrottleExemptionPremiseTest.php b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
new file mode 100644
index 0000000..d220b47
--- /dev/null
+++ b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
@@ -0,0 +1,265 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\LocalOnly;
+use Filament\Auth\MultiFactor\App\Actions\DisableAppAuthenticationAction;
+use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
+use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
+use Filament\Auth\Pages\EditProfile as FilamentEditProfile;
+use Filament\Auth\Pages\Login as FilamentLogin;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Arr;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Facades\Storage;
+
+/*
+ * ThrottleCoverageInventoryTest の exemption が依拠する**前提**の behavioral proof。
+ *
+ * exemption は「throttle を持たないことが**正しい**」という主張であり、
+ * その根拠 (署名で短絡する / 定数応答である / production には存在しない) が
+ * vendor 更新やリファクタで崩れたら検出できなければならない。
+ * 崩れたのに気づけない = 「対処済みに見える無防備」であり最悪の失敗モードになる。
+ */
+
+test('署名なしの PUT /storage/{path} は本体に到達しない (副作用ゼロで短絡する)', function (): void {
+    // storage.local.upload の exemption 根拠 = SignatureRequiredBeforeEffect
+    $disk = config('filesystems.default');
+    expect($disk)->toBeString();
+    Storage::fake($disk);
+
+    $response = $this->call('PUT', '/storage/probe.txt', content: 'payload');
+
+    // 非 production では 403 (production は 404)。いずれにせよ本体へ到達しない
+    expect($response->getStatusCode())->toBe(403);
+    Storage::disk($disk)->assertMissing('probe.txt');
+});
+
+test('GET /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
+    $response = $this->get('/api/v1/mcp');
+
+    expect($response->getStatusCode())->toBe(405);
+    expect($response->headers->get('Allow'))->toBe('POST');
+});
+
+test('DELETE /api/v1/mcp は定数 405 スタブ (Allow: POST) を返す', function (): void {
+    $response = $this->delete('/api/v1/mcp');
+
+    expect($response->getStatusCode())->toBe(405);
+    expect($response->headers->get('Allow'))->toBe('POST');
+});
+
+/** OAuth メタデータ route の URI 一覧 (定数応答であることの検証対象)。 */
+function throttlePremiseMetadataUris(): array
+{
+    return [
+        '/.well-known/oauth-authorization-server',
+        '/.well-known/oauth-authorization-server/mcp',
+        '/.well-known/oauth-protected-resource',
+        '/.well-known/oauth-protected-resource/mcp',
+    ];
+}
+
+test('.well-known/oauth-* の 4 route はいずれも DB クエリ 0 件で応答する', function (): void {
+    // StaticMetadataResponse の exemption 根拠 = 「DB アクセスを伴わない定数 JSON」
+    foreach (throttlePremiseMetadataUris() as $uri) {
+        $queries = [];
+        DB::listen(static function ($query) use (&$queries): void {
+            $queries[] = $query->sql;
+        });
+
+        $response = $this->getJson($uri);
+
+        expect($response->getStatusCode())->toBe(200, "{$uri} が 200 を返しません");
+        expect($queries)->toBe([], "{$uri} が DB クエリを発行しました: ".implode(' / ', $queries));
+    }
+});
+
+test('.well-known/oauth-*/{path} は path 由来の処理をしない (route parameter 非依存)', function (): void {
+    // authorization-server: 値まで完全に同一 ({path} は RFC 8414 の URI 形式のためだけに存在する)
+    $a1 = $this->getJson('/.well-known/oauth-authorization-server/mcp');
+    $a2 = $this->getJson('/.well-known/oauth-authorization-server/some/other/path');
+    expect($a2->getStatusCode())->toBe($a1->getStatusCode());
+    expect($a2->json())->toBe($a1->json(), 'authorization-server の応答が path に依存しています');
+
+    // protected-resource: `resource` だけが url() でリクエスト path を echo する。
+    // これは文字列組み立てであって「path 由来の処理」ではない
+    // (DB クエリ 0 件は上のテストが固定しており、定数メタデータという主張は保たれる)。
+    $p1 = $this->getJson('/.well-known/oauth-protected-resource/mcp');
+    $p2 = $this->getJson('/.well-known/oauth-protected-resource/some/other/path');
+    expect($p2->getStatusCode())->toBe($p1->getStatusCode());
+    expect($p2->json('resource'))->toBe(url('/some/other/path'));
+    expect(Arr::except($p2->json(), ['resource']))->toBe(
+        Arr::except($p1->json(), ['resource']),
+        'protected-resource の応答が resource 以外でも path に依存しています',
+    );
+});
+
+/*
+ * `default-livewire.update` (ComponentLevelLimiter) の前提。
+ *
+ * 「防御は route ではなく component 内にある」という主張は、Filament 側の
+ * `$this->rateLimit(...)` が実在することに全面的に依存している。vendor 更新で消えると
+ * **広い Livewire POST が無防備なまま inventory は通り続ける** (deny-by-default の最悪失敗)。
+ */
+/**
+ * 指定メソッドの**本体**に `->rateLimit(...)` 呼び出しがあるか (token 走査)。
+ *
+ * ファイル全体の文字列検索では、コメント化 / 別メソッドへの移動 / 文字列リテラル中の記述でも
+ * 合格してしまう (deny-by-default では誤合格が最悪の失敗モード)。
+ * ReflectionMethod で**対象メソッドの本体だけ**を切り出し、コメント / 文字列を
+ * token 段階で除去してから `-> rateLimit (` の並びを探す。
+ *
+ * @param  class-string  $class
+ */
+function throttlePremiseMethodRateLimits(string $class, string $method): bool
+{
+    $reflection = new ReflectionMethod($class, $method);
+    $file = $reflection->getFileName();
+    if ($file === false) {
+        return false;
+    }
+    $lines = file($file);
+    if ($lines === false) {
+        return false;
+    }
+
+    $start = $reflection->getStartLine();
+    $end = $reflection->getEndLine();
+    if ($start === false || $end === false) {
+        return false;
+    }
+
+    $ignored = [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_WHITESPACE];
+    $tokens = [];
+    foreach (token_get_all('<?php '.implode('', array_slice($lines, $start - 1, $end - $start + 1))) as $token) {
+        if (is_array($token)) {
+            if (! in_array($token[0], $ignored, true)) {
+                $tokens[] = $token[1];
+            }
+
+            continue;
+        }
+        $tokens[] = $token;
+    }
+
+    $count = count($tokens);
+    for ($i = 0; $i < $count - 2; $i++) {
+        if ($tokens[$i] === '->' && $tokens[$i + 1] === 'rateLimit' && $tokens[$i + 2] === '(') {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+test('default-livewire.update の前提: Filament の credential 操作が component 内で rateLimit を掛けている', function (): void {
+    // panel が公開する credential 面 (login / profile / MFA 管理) の**実行メソッド**に
+    // rate limit があること。1 つでも消えたら route 側の防御を設計し直す必要がある。
+    $targets = [
+        [FilamentLogin::class, 'authenticate'],
+        [FilamentEditProfile::class, 'save'],
+        [SetUpAppAuthenticationAction::class, 'make'],
+        [DisableAppAuthenticationAction::class, 'make'],
+        [RegenerateAppAuthenticationRecoveryCodesAction::class, 'make'],
+    ];
+
+    foreach ($targets as [$class, $method]) {
+        expect(throttlePremiseMethodRateLimits($class, $method))->toBeTrue(
+            "{$class}::{$method}() から component 内 rate limit が消えています。"
+            .'default-livewire.update の exemption 根拠が崩れているため、route 側の防御を設計し直すこと。',
+        );
+    }
+
+    // negative control: 走査器が「どのメソッドでも true」になっていないこと
+    // (常に true を返す検査は deny-by-default を無意味にする)
+    expect(throttlePremiseMethodRateLimits(FilamentLogin::class, 'mount'))->toBeFalse(
+        '走査器がメソッド本体を絞れていません (ファイル全体を見ている可能性)',
+    );
+});
+
+test('default-livewire.update の前提: panel が公開する auth ページの集合が変わっていない', function (): void {
+    // 新しい credential ページ (register / password-reset 等) が有効化されると
+    // exemption の射程が黙って広がる。集合を固定して再検討を強制する。
+    // multi-factor-authentication.set-up-required は AppAuthentication (TOTP) の
+    // セットアップ画面で、実操作は SetUp/Disable/Regenerate の各 Action が担う
+    // (それらの rateLimit は上のテストが固定している)。
+    $expected = [
+        'filament.admin.auth.login',
+        'filament.admin.auth.logout',
+        'filament.admin.auth.multi-factor-authentication.set-up-required',
+        'filament.admin.auth.profile',
+    ];
+
+    $actual = [];
+    foreach (Route::getRoutes() as $route) {
+        $name = $route->getName();
+        if ($name !== null && str_starts_with($name, 'filament.admin.auth.')) {
+            $actual[$name] = true;
+        }
+    }
+    $actual = array_keys($actual);
+    sort($actual);
+    sort($expected);
+
+    expect($actual)->toBe($expected,
+        'Filament panel が公開する auth ページの集合が変わりました。'
+        .'default-livewire.update の exemption は「公開される credential 面が component 内で'
+        .'有界化されている」ことに依存するため、増えたページの rate limit を確認してから集合を更新すること。');
+});
+
+/*
+ * `logout` / `filament.admin.auth.logout` (SessionTeardownOnly) の前提。
+ * 「認証済みでのみ到達でき、失敗しても攻撃者が得る情報が無い」ことを実挙動で固定する。
+ */
+test('logout 系の前提: 認証必須であり、未認証では本体に到達しない', function (): void {
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+
+    foreach (['logout', 'filament.admin.auth.logout'] as $name) {
+        $route = $routes->getByName($name);
+        expect($route)->not->toBeNull("route [{$name}] が存在しない");
+
+        // 「認証済みでのみ到達できる」= 実効列に Authenticate があること (構造)
+        $hasAuthenticate = false;
+        foreach ($router->gatherRouteMiddleware($route) as $entry) {
+            if (is_string($entry) && is_a(explode(':', $entry, 2)[0], Authenticate::class, true)) {
+                $hasAuthenticate = true;
+            }
+        }
+        expect($hasAuthenticate)->toBeTrue("route [{$name}] に Authenticate がありません (SessionTeardownOnly の前提が崩れています)");
+    }
+
+    // 未認証は本体へ到達せず差し戻される (実挙動)
+    $this->post('/logout')->assertRedirect();
+    $this->post('/admin/logout')->assertRedirect();
+});
+
+test('debug.login-as は testing 環境では登録される (母集団に現れる前提の固定)', function (): void {
+    // LocalOnlyDebugRoute の exemption 根拠は「production では登録自体が起きない」であり、
+    // 「テストから見えない」ではない。testing で登録されること自体が前提の一部。
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+
+    expect($routes->getByName('debug.login-as'))->not->toBeNull();
+});
+
+test('debug.login-as の登録は isLocal || runningUnitTests で囲われている (production 不在の根拠)', function (): void {
+    $source = file_get_contents(base_path('routes/web.php'));
+    expect($source)->toBeString();
+
+    // 登録条件そのものをソース上で固定する (条件が外れれば production にも生える)
+    expect($source)->toContain('if (app()->isLocal() || app()->runningUnitTests()) {');
+    expect($source)->toContain("->name('debug.login-as')");
+
+    // 二重防御 (LocalOnly middleware) が実効列に残っていること
+    $routes = Route::getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName('debug.login-as');
+    expect($route)->not->toBeNull();
+    expect($route->gatherMiddleware())->toContain(LocalOnly::class);
+});

## 検証結果
- composer phpstan: level 10 No errors
- vendor/bin/pint --test: passed
- composer test (ThrottleExemptionPremiseTest): 10 tests, 10 passed, 39 assertions

最後に **APPROVED** か **CHANGES_REQUESTED** を明記してください。
