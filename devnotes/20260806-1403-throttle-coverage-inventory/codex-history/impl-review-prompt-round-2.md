Round 1 の指摘に対する対応が完了しました。再レビューをお願いします。

## 対応マトリクス
# 実装レビュー Round 1 対応マトリクス (T120)

| # | 分類 | 指摘 | 判断 | 対応内容 / 根拠 |
|---|------|------|------|------------------|
| 1 | Critical | `default-livewire.update` の exemption 前提 (component 内 rateLimit) が未固定。vendor 更新で消えても inventory は通り続ける | **対応** | `ThrottleExemptionPremiseTest` に 2 本追加。(a) Filament `Auth/Pages/Login.php` / `Auth/Pages/EditProfile.php` の source に `$this->rateLimit(` が実在すること、(b) panel が公開する `filament.admin.auth.*` route 名の集合が期待どおりであること (新しい credential ページが有効化されたら exemption の射程が黙って広がるため fail させる)。exemption 理由文も実態 (Login / EditProfile) に合わせて修正。設計の例示にあった Register / ResetPassword / EmailVerificationPrompt は本 panel では **route 登録されていない**ことを実査で確認したため、存在しないものを根拠に書くのを止めた |
| 2 | Critical | 同上を `ThrottleExemptionPremiseTest` に追加すべき | **対応** | 上記と同一 |
| 3 | Warning | `routesAreCached()` 時の全面 skip により、stale な route cache が残ると起動時検査が一切走らない | **一部対応 (残リスクは明記)** | 「cached 起動でも検査する」は構造的に不可能 (compiled route collection は本 callback より**後**の booted callback で読まれるため、その時点では 1 本も解決できない)。framework 自身も `loadRoutesFrom()` を cache 時に skip する。代わりに **(a)** binder docblock に「守れないのは stale cache のまま起動する場合だけ」と残リスクを明記し、**(b)** `php artisan route:cache` を毎デプロイ再生成することを運用要件として `AGENTS.md` / `docs/app-integration-guide.md §7b` / `AppServiceProvider` コメントの 3 箇所に書いた。`route:cache` 自体は `route:clear` 後の再 bootstrap で後付けを完全実行するため、route 名の消失は**デプロイ時に必ず止まる** |
| 4 | Warning | `routeThrottleEntries()` が controller middleware を見ない弱点を設計差分として明記すべき | **対応** | binder の method docblock に「なぜ見ないか (boot 中の container 解決が request scope singleton を早期確定させる。実測で `ConfirmablePasswordController` → `StatefulGuard` → `session.store` が確定し既存テストが壊れた)」と「見落としが穴にならない根拠 (controller 側 throttle があれば目録検査が『2 本以上』で fail)」を記載済み。加えて `docs/app-integration-guide.md §7b` にも明記し、`RouteThrottleBinderTest` に「boot 中の後付けが controller を解決していない」回帰テストを追加 |
| 5 | Warning | `logout` / `filament.admin.auth.logout` の exemption 前提が未検証 | **対応** | `ThrottleExemptionPremiseTest` に「未認証の POST /logout・POST /admin/logout は本体へ到達せず redirect される」を追加 |
| 6 | Warning | ガイドの「3 段優先順」が実装コメントと逆に読める | **対応** | 順序を「1. route 定義に直接書く → 2. package の設定で貼る → 3. binder で後付け」に修正 (設定で貼れるものは設定のまま、が正)。あわせて route:cache の運用要件と controller middleware 非参照の注記を追記 |
| 7 | Warning | 異常入力テストが `password-reset-request` のみ | **対応** | dataset 化して `password-reset-request` / `password-reset-submit` / `account-register` の 3 レーンすべてで IP レーン共有を固定 (route と limiter の配線ミスも検出できる) |
| 8 | Suggestion | `.well-known/oauth-*/{path}` は主要値も比較すべき | **対応 (ただし実態に合わせて修正)** | 実測の結果 `protected-resource` の `resource` フィールドは `url()` でリクエスト path を echo しており、**「{path} は応答内容に影響しない」という inventory の理由が事実と異なっていた**。テストは (a) authorization-server は値まで完全一致、(b) protected-resource は `resource` が `url(path)` と一致し **それ以外は完全一致**、という形に強化し、exemption 理由も「resource へ echo されるだけで DB/暗号/外部呼び出しは起きない」に訂正した |
| 9 | Suggestion | `attachThrottleToVendorRoutes()` のコメントが `attachOnBooted()` 化後の前提を反映していない | **対応** | cached 起動 skip と route:cache 再生成の運用条件をコメントに追記 |
| 10 | Suggestion | `FortifyServiceProvider` / `routes/web.php` は問題なし | — | 対応不要 |

## Round 1 以降の追加差分 (git diff の該当部分)
diff --git a/AGENTS.md b/AGENTS.md
index 0da753a..54cbbc2 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -291,3 +291,22 @@ ## ドメイン固有規約
    専用画面で受ける** (行き先のない詰みを作らない)。運用契約は `docs/architecture.md`
    §サブスク契約 Checkout とオンボーディング着地、デプロイ順序は
    `docs/billing-gate-inversion-runbook.md`
+5. **流量制限 (throttle) の付与規約**: 保護対象群 (未認証で到達しうる変更系 /
+   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` / 認証面の変更系) は
+   **throttle をちょうど 1 本**持つか、`ThrottleCoverageExemption` + 30 文字以上の根拠付きで
+   exemption inventory へ登録する (`ThrottleCoverageInventoryTest` が deny-by-default で強制。
+   exemption の**前提**は `ThrottleExemptionPremiseTest` が behavioral に固定する)。
+   - named limiter のキーは **`{レーン}:{種別}:{値}`** (`RateLimiterKeyConventionTest` が
+     全 limiter を実評価して検査)。email は `EmailNormalizer` → `EmailHash` を通し、
+     平文をキャッシュキーに残さない。**`Str::transliterate()` は使わない**
+     (legitimate な Unicode email を別 user へ collapse させ巻き添えロックアウトになる)。
+     inline throttle (`throttle:6,1`) は「認証済みかつ actor 自身に閉じる操作」限定
+   - vendor 登録 route への後付けは **`RouteThrottleBinder::attachOnBooted()`** 経由
+     (route 名が消えたら起動時 fail-fast)。**`php artisan route:cache` は毎デプロイ再生成する**
+     (後付けは cache 生成時に焼き込まれ cached 起動では skip されるため、stale cache は
+     古い付与状態のまま起動する)
+   - **閾値は既存値を変えない**。新しい面には既に本番稼働中の同性質エンドポイントと同値を充てる
+   - 未認証 webhook に**固定キーの全体天井を置かない** (throttle は署名検証より前に走るため、
+     無効 body の連打で正当通知を 429 にできる = 攻撃者が業務を止められる口になる)。
+     IP 単位は署名検証コストの上限であり正当通知の保護ではない (429 発生率を監視する)
+   - 詳細は `docs/app-integration-guide.md` §7b
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 39c946e..d7374cd 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -34,7 +34,9 @@
 use App\Services\Render\FfmpegVideoComposer;
 use App\Services\Render\VideoComposer;
 use App\Support\CriticalActionContext;
+use App\Support\EmailHash;
 use App\Support\EmailNormalizer;
+use App\Support\Http\RouteThrottleBinder;
 use App\Support\PasswordPolicy;
 use App\Support\ProductionEnvGuard;
 use Aws\Sns\SnsClient;
@@ -234,6 +236,53 @@ public function boot(): void
         $this->configureApiRateLimiters();
         $this->configureInquiryRateLimiter();
         $this->configureRenderRateLimiter();
+        $this->configureWebhookRateLimiters();
+        $this->attachThrottleToVendorRoutes();
+    }
+
+    /**
+     * 未認証 webhook (SES/SNS 通知・Stripe) の RateLimiter。
+     *
+     * ★固定キーの全体天井は**置かない**。throttle middleware は署名検証より前に走るため、
+     *   固定キーのバケットを署名前に消費させると「無効 body の連打で正当な通知を 429 にできる」
+     *   = 攻撃者が任意に業務を止められる口になる。
+     *
+     * ★レーンは送信元ごとに分ける。SES への攻撃で Stripe を止めない。
+     *
+     * ★これは**署名検証コストの上限**であり、正当通知を守る全体天井ではない。
+     *   IP キーである以上、共有クラウド出口 / proxy 設定の誤りでは巻き添え 429 がありうる
+     *   (運用は送信元 IP の分布と 429 発生率を監視すること)。
+     *   正当通知の保護は「送信元の署名済み identity で bucket を切る」設計が要る (後続 TODO)。
+     *
+     * 閾値の根拠: 正常時ピークは分あたり数件〜数十件 (SES bounce/complaint、Stripe イベント)。
+     * 単一送信元からの署名検証コスト増幅 (SNS は証明書取得を伴う) を有界にする値として
+     * ピークの 1〜2 桁上の 300/min を置く。429 は SNS も Stripe も再送対象であり
+     * 恒久喪失しない (Stripe は最大 3 日間の指数バックオフ)。
+     */
+    private function configureWebhookRateLimiters(): void
+    {
+        RateLimiter::for('webhook-ses', fn (Request $request): Limit => Limit::perMinute(300)
+            ->by('webhook-ses:ip:'.($request->ip() ?? 'unknown')));
+
+        RateLimiter::for('webhook-stripe', fn (Request $request): Limit => Limit::perMinute(300)
+            ->by('webhook-stripe:ip:'.($request->ip() ?? 'unknown')));
+    }
+
+    /**
+     * vendor が自動登録する route への throttle 後付け (設定で貼れないため第 2 段)。
+     *
+     * Cashier の POST /stripe/webhook は middleware が 1 本も無い状態で公開されており、
+     * 署名検証 (VerifyWebhookSignature) は Cashier 側の設定次第で外れうる。
+     * 後付けは冪等で、route 名が消えていれば起動時 fail-fast する。
+     *
+     * ★運用条件: `php artisan route:cache` を**毎デプロイ再生成する**こと。
+     *   後付けは route cache 生成時 (route:clear 後の再 bootstrap) に焼き込まれ、
+     *   cached 起動では skip される (詳細は RouteThrottleBinder::attachOnBooted の docblock)。
+     *   stale な route cache を残すと古い付与状態のまま起動する。
+     */
+    private function attachThrottleToVendorRoutes(): void
+    {
+        RouteThrottleBinder::attachOnBooted($this->app, ['cashier.webhook' => 'webhook-stripe']);
     }
 
     /**
@@ -251,14 +300,15 @@ private function configureRenderRateLimiter(): void
                 ? (string) $user->current_organization_id
                 : 'none';
 
-            return Limit::perMinute(6)->by("render-trigger:{$userId}:{$orgId}");
+            return Limit::perMinute(6)->by("render-trigger:actor-org:{$userId}:{$orgId}");
         });
     }
 
     /**
      * 公開問い合わせフォーム (POST /contact) の RateLimiter。IP 単独 + IP+email の 2 系統。
      * email 正規化は保存・検索と同一の EmailNormalizer に集約 (大文字小文字での limiter 回避防止)。
-     * email はキャッシュキーへの平文残存を避けるため sha256 でハッシュ化する。
+     * email はキャッシュキーへの平文残存を避けるため EmailHash (app.key 鍵付き HMAC-SHA256) で
+     * ハッシュ化する (単純 sha256 は低エントロピーな email に対して辞書攻撃に弱い)。
      * limiter は validation 前に走るため email が非 string で来うる → is_string ガード必須。
      */
     private function configureInquiryRateLimiter(): void
@@ -266,8 +316,8 @@ private function configureInquiryRateLimiter(): void
         RateLimiter::for('inquiry', function (Request $request): array {
             $rawEmail = $request->input('email', '');
             $email = is_string($rawEmail) && $rawEmail !== '' ? EmailNormalizer::normalize($rawEmail) : '';
-            $emailKey = $email !== '' ? hash('sha256', $email) : 'anon';
-            $ip = (string) $request->ip();
+            $emailKey = $email !== '' ? EmailHash::compute($email) : 'anon';
+            $ip = $request->ip() ?? 'unknown';
 
             return [
                 Limit::perMinute(5)->by('inquiry:ip:'.$ip),
@@ -297,17 +347,17 @@ private function apiRateKey(Request $request): string
     {
         $apiKey = $request->attributes->get('api_key');
         if ($apiKey instanceof ApiKey) {
-            return 'api-key:'.$apiKey->id;
+            return 'api:api-key:'.$apiKey->id;
         }
 
         // dual guard の OAuth user-token 経路 (throttle は resolve.api-actor より前段の
         // ため guard から直接引く)。actor 単位で数える (IP 共有環境での巻き添え防止)
         $oauthUser = $request->user('api-oauth');
         if ($oauthUser instanceof User) {
-            return 'oauth-user:'.$oauthUser->id;
+            return 'api:oauth-user:'.$oauthUser->id;
         }
 
-        return 'ip:'.($request->ip() ?? 'unknown');
+        return 'api:ip:'.($request->ip() ?? 'unknown');
     }
 
     /**
@@ -321,6 +371,6 @@ private function mcpRateKey(Request $request): string
             return 'mcp:user:'.$user->id;
         }
 
-        return 'ip:mcp:'.($request->ip() ?? 'unknown');
+        return 'mcp:ip:'.($request->ip() ?? 'unknown');
     }
 }
diff --git a/app/Support/Http/RouteThrottleBinder.php b/app/Support/Http/RouteThrottleBinder.php
new file mode 100644
index 0000000..248a4c0
--- /dev/null
+++ b/app/Support/Http/RouteThrottleBinder.php
@@ -0,0 +1,260 @@
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
+ * vendor が登録した named route へ throttle middleware を後付けする binder
+ * (「貼る仕組みの 3 段優先順」の第 2 段。設定で貼れない route 専用)。
+ *
+ * ★冪等性の契約 (route:cache と両立させるため必須):
+ *   `php artisan route:cache` の RouteCacheCommand::getFreshApplicationRoutes() は
+ *   **アプリを再 bootstrap してから** router->getRoutes() を直列化する。
+ *   provider の boot → booted callback も走るため、本 binder が付けた throttle は
+ *   **route cache に焼き込まれる**。その cache を読んだ次回起動でも booted は走るので、
+ *   「既存があれば常に例外」にすると cached 起動が必ず落ちる。
+ *   したがって「同じ limiter がちょうど 1 本なら no-op」を正とする。
+ *
+ * ★判定は文字列の完全一致にしない:
+ *   gatherRouteMiddleware() の entry は `{class}:{params}` 形式で出る。
+ *   class 部は cache driver によって ThrottleRequests / ThrottleRequestsWithRedis の
+ *   どちらにもなりうる (後者は前者を継承)。class 部は is_a() で、params 部は
+ *   limiter 名の完全一致で比較する。
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
+                return; // route:cache 由来の再適用 = 冪等 no-op
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
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index f1b0aa2..ca83eda 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -284,9 +284,69 @@ ### 新規 route(特に変更系)を足すときのチェックリスト
    「権限の無い actor が実際に 403 になること」は Feature テストの責務
    (見本: `tests/Feature/Api/V1/ItemAuthorizationTest.php`)。
    この 2 層(入口 = Architecture / 実挙動 = Feature)はセットで維持する
-6. `composer test` で 3 つの gate
+6. **流量制限 (throttle) を付ける**。保護対象群(未認証で到達しうる変更系 /
+   ステートレスな機械向け経路 `api/`・`oauth/`・`.well-known/oauth-` /
+   認証面の変更系)は **throttle をちょうど 1 本**持つか、
+   `ThrottleCoverageInventoryTest` の exemption inventory へ
+   `ThrottleCoverageExemption` + 30 文字以上の根拠付きで登録する(deny-by-default)。
+   詳細は下の「§7b 流量制限の付与規約」
+7. `composer test` で 4 つの gate
    (`ControllerAuthorizationGateTest` / `NestedRouteIdorDefenseTest` /
-   `ProjectRouteCurrentOrgGuardTest`)が green であることを確認する
+   `ProjectRouteCurrentOrgGuardTest` / `ThrottleCoverageInventoryTest`)が
+   green であることを確認する
+
+### §7b 流量制限の付与規約
+
+**貼る仕組みの 3 段優先順**(上から順に検討し、**上で貼れるなら下は使わない**):
+
+1. **route 定義に直接書く**(自前 route)。`->middleware('throttle:{limiter}')`
+2. **package の設定で貼る**(vendor 登録 route。`config/fortify.php` の `limiters` など)。
+   受け付けるキーが限られる(Fortify は login / two-factor / passkeys / verification の 4 つだけ)ため、
+   賄えない分だけ 3 に落とす
+3. **`RouteThrottleBinder::attachOnBooted()` で後付けする**(2 でも貼れない vendor route 専用)。
+   `$this->app->booted()` の中で走り、route 名が消えていれば**起動時に fail-fast** する
+   (silent degradation = 無音の無防備を作らない)。付与は冪等
+   (実装: `app/Support/Http/RouteThrottleBinder.php`)
+   - **`php artisan route:cache` を毎デプロイ再生成すること**。後付けは route cache 生成時
+     (`route:clear` 後の再 bootstrap) に焼き込まれ、**cached 起動では skip される**
+     (compiled route collection が booted callback より後に読まれるため参照できない)。
+     stale な route cache を残すと古い付与状態のまま起動する
+   - 後付け側の判定は controller middleware を見ない
+     (boot 中に controller を container 解決すると request scope の singleton が
+      早すぎるタイミングで確定するため)。controller 側 throttle との二重付与は
+     目録検査が「2 本以上」として検出する
+
+**キー規約**: named limiter のキーは `{レーン}:{種別}:{値}`
+(例 `login:email-ip:{hash}:{ip}` / `webhook-ses:ip:{ip}`)。
+`RateLimiterKeyConventionTest` が全 limiter を実際に評価して機械検査する。
+
+- **email をキーに入れるときは `EmailNormalizer::normalize()` → `EmailHash::compute()`**。
+  平文も正規化済み平文もキャッシュキーに残さない。
+  `Str::transliterate()` は**使わない**(legitimate な Unicode email を別 user へ
+  collapse させ、無関係アカウントの巻き添えロックアウトになる)
+- **inline throttle (`throttle:6,1`) を使ってよいのは「認証済みかつ actor 自身に
+  閉じる操作」だけ**。フレームワーク既定のキー(認証済み = user id)が
+  ちょうど求める数える単位になる場合に限る。未認証面 / 主体が IP や email に
+  なる面は必ず named limiter を作る
+- **limiter キーに route parameter を入れない**(`NamedRateLimiterKeyTest`)。
+  bucket が id ごとに分かれると「429 になるまでの回数」が実在を漏らす
+
+**閾値**: プロダクト依存のため既存値を勝手に変えない。新しい面には
+**既に本番稼働している同性質エンドポイントと同値**を充てる
+(公開フォーム = IP 5/min + IP+email 10/60min、自分の credential 操作 = 6/min、
+認証済みの管理操作 = 10/min)。
+
+**未認証 webhook の注意**: throttle は署名検証より**先**に走る。したがって
+固定キー(全体天井)を置くと「無効 body の連打で正当な通知を 429 にできる」
+= 攻撃者が業務を止められる口になる。IP 単位に留め、これは
+**署名検証コストの上限であって正当通知を守る全体天井ではない**と理解する
+(共有クラウド出口では巻き添え 429 がありうるため、送信元 IP の分布と
+429 発生率を監視項目に入れる)。
+
+**exemption を書くときの原則**: exemption は「throttle が無いことが**正しい**」
+という主張であり、その**前提**(署名で短絡する / 定数応答である /
+production では登録されない)は `ThrottleExemptionPremiseTest` で
+behavioral に固定する。前提が崩れたのに気づけない状態を作らない。
 
 ## 8. 設計ドキュメントの書き方(このテンプレ上の流儀)
 
diff --git a/tests/Architecture/ThrottleCoverageInventoryTest.php b/tests/Architecture/ThrottleCoverageInventoryTest.php
new file mode 100644
index 0000000..cf0d526
--- /dev/null
+++ b/tests/Architecture/ThrottleCoverageInventoryTest.php
@@ -0,0 +1,286 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Security\ThrottleCoverageExemption;
+use App\Support\Http\RouteThrottleBinder;
+use Illuminate\Auth\Middleware\Authenticate;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Session\Middleware\StartSession;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Support\Str;
+
+/*
+ * 流量制限 (throttle) の付与漏れ invariant (deny-by-default)。
+ *
+ * 「保護対象群に属する route は throttle をちょうど 1 本持つ」を機械強制する。
+ * 持たないものは理由付きで exemption inventory へ明示登録させる。
+ *
+ * ★保護対象群 (S1 ∪ S2 ∪ S3) は意図的に**過大に**取る:
+ *   S1 は「未認証で本体に到達する」ことを主張しない。signed / 定数 405 スタブ /
+ *   LocalOnly / 署名検証など、Authenticate 以外で本体到達を閉じる route も S1 に入る。
+ *   **exemption の役割は「本体到達しない根拠を固定すること」**である
+ *   (過小なセレクタはすり抜けを生むが、過大なセレクタは exemption 理由という形で
+ *    根拠が文書化されるだけで済む)。
+ *
+ * ★実効 middleware 列は Router::gatherRouteMiddleware() で取得する
+ *   (`route:list --json` は group 名 'web' が展開されず誤判定するため使わない)。
+ *   throttle 判定は RouteThrottleBinder::isThrottleEntry() を唯一の判定点として共有する。
+ */
+
+/** 変更系 HTTP メソッド。 */
+function throttleCoverageMutatingMethods(): array
+{
+    return ['POST', 'PUT', 'PATCH', 'DELETE'];
+}
+
+/** 認証面の route 名パターン (S3)。 */
+function throttleCoverageAuthSurfacePattern(): string
+{
+    return '#^(login|logout|register|password\.|user-password\.|two-factor\.|passkey\.|verification\.'
+        .'|recent-auth\.|invitations\.|settings\.password\.|social\.|filament\.admin\.auth\.)#';
+}
+
+/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
+function throttleCoverageRouteFloor(): int
+{
+    return 40;
+}
+
+/** exemption 件数の上限 (形骸化ガード)。 */
+function throttleCoverageExemptionCap(): int
+{
+    return 14;
+}
+
+/** exemption 理由の最低文字数 (「同上」「N/A」を機械的に弾く)。 */
+function throttleCoverageReasonMinLength(): int
+{
+    return 30;
+}
+
+/**
+ * throttle を持たないことが正しいと裁定した route の inventory (型付き + 具体的根拠必須)。
+ *
+ * @return array<string, array{ThrottleCoverageExemption, string}>
+ */
+function throttleCoverageExemptions(): array
+{
+    $metadata = ThrottleCoverageExemption::StaticMetadataResponse;
+    $stub = ThrottleCoverageExemption::VendorMethodNotAllowedStub;
+    $teardown = ThrottleCoverageExemption::SessionTeardownOnly;
+    $localOnly = ThrottleCoverageExemption::LocalOnlyDebugRoute;
+    $component = ThrottleCoverageExemption::ComponentLevelLimiter;
+    $signature = ThrottleCoverageExemption::SignatureRequiredBeforeEffect;
+
+    return [
+        'mcp.oauth.authorization-server' => [$metadata,
+            'Laravel\Mcp\Server\Registrar::authorizationServerMetadata() が config と url() と route() だけで'
+            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出し・メール送信を一切伴わないため、'
+            .'連打しても増幅する処理コストが存在しない。前提は ThrottleExemptionPremiseTest が固定する。'],
+
+        'mcp.oauth.authorization-server.nested' => [$metadata,
+            '上記 authorization-server と同一ハンドラ。{path} は応答内容に影響せず (RFC 8414 の'
+            .'path-insertion 形式に対応するためだけの別 URI)、定数 JSON を返す点も同じ。'],
+
+        'mcp.oauth.protected-resource' => [$metadata,
+            'Laravel\Mcp\Server\Registrar::protectedResourceMetadata() が同様に config と url() だけで'
+            .'組む定数 JSON を返す。DB アクセス・暗号処理・外部呼び出しを伴わない。'],
+
+        'mcp.oauth.protected-resource.nested' => [$metadata,
+            '上記 protected-resource と同一ハンドラ。{path} は resource フィールドへ url() で'
+            .'echo されるだけで、DB アクセス・暗号処理・外部呼び出しは一切起きない'
+            .'(ThrottleExemptionPremiseTest が DB クエリ 0 件と resource 以外の不変を固定する)。'],
+
+        'GET /api/v1/mcp' => [$stub,
+            'Laravel\Mcp\Server\Registrar::web() が登録する response(\'\', 405)->header(\'Allow\', \'POST\') の'
+            .'固定応答。MCP 仕様上の SSE 非対応表明であり、ハンドラは本体処理へ一切到達しない。'],
+
+        'DELETE /api/v1/mcp' => [$stub,
+            'GET と同じく Registrar::web() の定数 405 スタブ (Allow: POST)。session 終了 API 非対応の'
+            .'表明であり本体処理へ到達しない。'],
+
+        'logout' => [$teardown,
+            'auth:web 必須。セッション破棄と Inertia::clearHistory() のみを行い、'
+            .'推測可能な秘密を一切扱わないため失敗しても攻撃者が得る情報が無い。'],
+
+        'filament.admin.auth.logout' => [$teardown,
+            'Filament panel の logout。認証済みでのみ到達でき、セッション破棄以外の副作用が無い。'
+            .'秘密の推測に使えないため連打しても攻撃者の利得が無い。'],
+
+        'debug.login-as' => [$localOnly,
+            'routes/web.php の if (app()->isLocal() || app()->runningUnitTests()) により'
+            .'**production では route 登録自体が起きない** (testing では登録されるため母集団に現れる)。'
+            .'加えて LocalOnly middleware (local 以外 404 + Basic 認証 + 未設定 404) が二重防御。'],
+
+        'default-livewire.update' => [$component,
+            'Filament 管理画面の全 Livewire 操作が相乗りする単一 endpoint。route 単位の bucket を貼ると'
+            .'無関係な管理操作を巻き添えにする。実際の制限は component 内にあり'
+            .'(Auth/Pages/Login.php の $this->rateLimit(5) / Auth/Pages/EditProfile.php の同 5)、'
+            .'panel が公開する credential 面はそこで有界化されている。'
+            .'この前提 (rateLimit の実在 + 公開される auth ページの集合) は'
+            .'ThrottleExemptionPremiseTest が固定する。'],
+
+        'storage.local.upload' => [$signature,
+            'Illuminate\Filesystem\ReceiveFile::__invoke() が本体到達前に abort_unless('
+            .'$request->boolean(\'upload\') && $request->hasValidRelativeSignature(), ...) で短絡し、'
+            .'署名が無ければファイル書込を含む副作用がゼロになる。前提は ThrottleExemptionPremiseTest が固定する。'],
+    ];
+}
+
+/** 解決後 middleware 列 (Closure を除いた文字列 entry のみ)。 */
+function throttleCoverageResolvedMiddleware(RoutingRoute $route): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+
+    return array_values(array_filter(
+        $router->gatherRouteMiddleware($route),
+        static fn (mixed $entry): bool => is_string($entry),
+    ));
+}
+
+/** 解決後 middleware 列に指定クラス (パラメータ付き entry を含む) があるか。 */
+function throttleCoverageHasMiddlewareClass(RoutingRoute $route, string $class): bool
+{
+    foreach (throttleCoverageResolvedMiddleware($route) as $entry) {
+        if (is_a(Str::before($entry, ':'), $class, true)) {
+            return true;
+        }
+    }
+
+    return false;
+}
+
+/**
+ * route の inventory キー (名前があれば名前、無ければ `{METHOD} /{uri}`)。
+ * HEAD は methods() から除外して主メソッドを使う。
+ */
+function throttleCoverageRouteLabel(RoutingRoute $route): string
+{
+    $name = $route->getName();
+    if ($name !== null && $name !== '') {
+        return $name;
+    }
+
+    $methods = array_values(array_diff($route->methods(), ['HEAD']));
+
+    return implode('|', $methods).' /'.$route->uri();
+}
+
+/** @return list<RoutingRoute> 保護対象群 (S1 ∪ S2 ∪ S3)。 */
+function throttleCoverageProtectedRoutes(): array
+{
+    $mutating = throttleCoverageMutatingMethods();
+    $pattern = throttleCoverageAuthSurfacePattern();
+    $protected = [];
+
+    foreach (Route::getRoutes() as $route) {
+        $isMutating = array_intersect($mutating, $route->methods()) !== [];
+        $uri = $route->uri();
+        $name = $route->getName() ?? '';
+
+        // S1: 未認証で到達可能な可能性がある変更系
+        $s1 = $isMutating
+            && ! throttleCoverageHasMiddlewareClass($route, Authenticate::class);
+
+        // S2: ステートレスな機械向け経路
+        $s2 = (str_starts_with($uri, 'api/') || str_starts_with($uri, 'oauth/')
+                || str_starts_with($uri, '.well-known/oauth-'))
+            && ! throttleCoverageHasMiddlewareClass($route, StartSession::class);
+
+        // S3: 認証済み側も含む credential 面
+        $s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
+
+        if ($s1 || $s2 || $s3) {
+            $protected[] = $route;
+        }
+    }
+
+    return $protected;
+}
+
+test('保護対象 route の母集団が下限を下回らない (セレクタの空振り検出)', function (): void {
+    $count = count(throttleCoverageProtectedRoutes());
+
+    expect($count)->toBeGreaterThanOrEqual(
+        throttleCoverageRouteFloor(),
+        "保護対象 route が {$count} 件しか検出されませんでした。"
+        .'セレクタ (S1/S2/S3) が空振りしている可能性があります。',
+    );
+});
+
+test('保護対象 route は throttle をちょうど 1 本持つか exemption inventory に明示分類されている (未知は fail)', function (): void {
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $inventory = throttleCoverageExemptions();
+    $violations = [];
+
+    foreach (throttleCoverageProtectedRoutes() as $route) {
+        $label = throttleCoverageRouteLabel($route);
+        $entries = RouteThrottleBinder::throttleEntries($router, $route);
+
+        if (count($entries) === 1) {
+            continue;
+        }
+
+        if ($entries === [] && array_key_exists($label, $inventory)) {
+            continue;
+        }
+
+        $violations[] = $entries === []
+            ? "{$label}: throttle が 1 本も無く exemption inventory にも未登録"
+            : "{$label}: throttle が ".count($entries).' 本ある ('.implode(', ', $entries).')';
+    }
+
+    expect($violations)->toBe([],
+        '保護対象 route の throttle 付与が不正です。throttle を貼るか、'
+        .'貼らないことが正しい理由を throttleCoverageExemptions() に'
+        .'ThrottleCoverageExemption + 具体的根拠付きで登録してください。'
+        .PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption inventory の key は現存する保護対象 route (stale 検出)', function (): void {
+    $labels = [];
+    foreach (throttleCoverageProtectedRoutes() as $route) {
+        $labels[throttleCoverageRouteLabel($route)] = true;
+    }
+
+    $stale = [];
+    foreach (array_keys(throttleCoverageExemptions()) as $key) {
+        if (! isset($labels[$key])) {
+            $stale[] = $key;
+        }
+    }
+
+    expect($stale)->toBe([],
+        'exemption inventory に現存しない route ラベル (削除/rename 済、または throttle 付与済で'
+        .'exemption が不要になったもの) があります: '.implode(', ', $stale));
+});
+
+test('exemption inventory の値は enum + 実質的な理由文字列', function (): void {
+    $minLength = throttleCoverageReasonMinLength();
+    $violations = [];
+
+    foreach (throttleCoverageExemptions() as $label => [$exemption, $reason]) {
+        if (! $exemption instanceof ThrottleCoverageExemption) {
+            $violations[] = "{$label}: 第 1 要素が ThrottleCoverageExemption ではありません";
+        }
+        if (mb_strlen($reason) < $minLength) {
+            $violations[] = "{$label}: 理由が {$minLength} 文字未満です (「同上」「N/A」で埋める運用を止めます)";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('exemption 件数が上限を超えない (形骸化ガード)', function (): void {
+    $count = count(throttleCoverageExemptions());
+
+    expect($count)->toBeLessThanOrEqual(
+        throttleCoverageExemptionCap(),
+        "exemption が {$count} 件あります。セレクタが広すぎるか、throttle を貼るべき route を"
+        .'exemption で逃がしている可能性があります (上限を上げる前に必ず再検討すること)。',
+    );
+});
diff --git a/tests/Feature/Security/AuthThrottleCoverageTest.php b/tests/Feature/Security/AuthThrottleCoverageTest.php
new file mode 100644
index 0000000..cd7a003
--- /dev/null
+++ b/tests/Feature/Security/AuthThrottleCoverageTest.php
@@ -0,0 +1,211 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\RequireRecentAuth;
+use App\Http\Middleware\VerifySnsSignature;
+use Illuminate\Routing\Middleware\ThrottleRequests;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * T120 で新設した認証系 / webhook throttle の behavioral proof。
+ *
+ * 目録検査 (ThrottleCoverageInventoryTest) は「throttle が付いているか」までしか見ない。
+ * 実際に 429 で止まるか・どの単位で数えるか・どの middleware より先に走るかは
+ * 実挙動でしか固定できないため、ここで契約として固定する。
+ *
+ * cache store はテスト実行時 array に強制されている (phpunit.xml) ため、
+ * app を作り直す各テストで RateLimiter のバケットは空から始まる。
+ */
+
+/** 何回叩いても同じ結果になる POST helper。 */
+function throttleProbePost(string $uri, array $payload = []): TestResponse
+{
+    return test()->post($uri, $payload);
+}
+
+test('POST /forgot-password は 5 回目まで通り 6 回目で 429 (IP レーン 5/min)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で既に 429 になりました");
+    }
+
+    expect(throttleProbePost('/forgot-password', ['email' => 'probe@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('429 応答は Retry-After と X-RateLimit-* ヘッダを持つ (既定ヘッダを削らない)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+    }
+    $response = throttleProbePost('/forgot-password', ['email' => 'probe@example.com']);
+
+    expect($response->getStatusCode())->toBe(429);
+    expect($response->headers->get('Retry-After'))->not->toBeNull();
+    expect($response->headers->get('X-RateLimit-Limit'))->not->toBeNull();
+    expect($response->headers->get('X-RateLimit-Remaining'))->not->toBeNull();
+});
+
+/*
+ * IP+email レーン (10/60min) は 2 番目の Limit のため、応答ヘッダの残数はこのレーンを表す
+ * (ThrottleRequests は limits を順に処理し、ヘッダは最後の Limit で上書きする)。
+ * 大文字小文字違いで残数が連続して減れば「同じ bucket を消費した」= 正規化が効いている。
+ */
+test('POST /forgot-password は大文字小文字違いの email で同じ bucket を消費する (正規化の証明)', function (): void {
+    $first = throttleProbePost('/forgot-password', ['email' => 'Probe.User@Example.COM']);
+    $second = throttleProbePost('/forgot-password', ['email' => 'probe.user@example.com']);
+
+    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
+        (int) $first->headers->get('X-RateLimit-Remaining') - 1,
+        '大文字小文字違いで残数が戻った = 別 bucket に分かれている (throttle bypass)',
+    );
+});
+
+test('POST /forgot-password は同一 IP なら email を変えても IP レーンで止まる (メール爆撃の抑制)', function (): void {
+    // email レーン (10/60min) はそれぞれ余裕があるが、IP レーン (5/min) が先に尽きる
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/forgot-password', ['email' => "probe{$i}@example.com"]);
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/forgot-password', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('POST /reset-password も 6 回目で 429 (reset token 総当りの抑制)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com', 'password' => 'Password123!', 'password_confirmation' => 'Password123!']);
+    }
+
+    expect(throttleProbePost('/reset-password', ['token' => 'invalid', 'email' => 'probe@example.com'])->getStatusCode())->toBe(429);
+});
+
+test('POST /register も 6 回目で 429 (アカウント量産の抑制)', function (): void {
+    for ($i = 1; $i <= 5; $i++) {
+        throttleProbePost('/register', ['email' => "probe{$i}@example.com"]);
+    }
+
+    expect(throttleProbePost('/register', ['email' => 'probe6@example.com'])->getStatusCode())->toBe(429);
+});
+
+/*
+ * 異常入力の契約は 3 つに分ける。
+ * 極端に長い文字列も有効な string なので EmailHash が計算され、anon bucket とは別になる。
+ */
+test('login limiter は username が配列 / 空文字のとき anon fallback として同じ bucket を消費する', function (): void {
+    $payloads = [
+        ['email' => ['array-value'], 'password' => 'x'],
+        ['email' => '', 'password' => 'x'],
+        ['password' => 'x'],
+        ['email' => ['a'], 'password' => 'x'],
+        ['email' => '', 'password' => 'x'],
+    ];
+
+    foreach ($payloads as $payload) {
+        expect(throttleProbePost('/login', $payload)->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/login', ['email' => '', 'password' => 'x'])->getStatusCode())->toBe(429);
+});
+
+test('login limiter は極端に長い文字列でも 500 にならず、同一値の反復では同じ bucket を消費する', function (): void {
+    $long = str_repeat('a', 10000).'@example.com';
+
+    for ($i = 1; $i <= 5; $i++) {
+        $response = throttleProbePost('/login', ['email' => $long, 'password' => 'x']);
+        expect($response->getStatusCode())->toBeLessThan(500, '極端に長い入力で 500 になりました');
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost('/login', ['email' => $long, 'password' => 'x'])->getStatusCode())->toBe(429);
+});
+
+test('認証フォーム系 limiter は異なる異常文字列でも IP レーンを共有する', function (string $uri, string $field): void {
+    // IP 単独レーンは email に依存しない (IP-email レーンは値ごとに分かれるのが正しい挙動)。
+    // 3 レーンすべてで確認することで route と limiter の配線ミスも検出する。
+    $weird = [['array'], '', str_repeat('z', 500), 12345, null];
+
+    foreach ($weird as $value) {
+        $response = throttleProbePost($uri, $value === null ? [] : [$field => $value]);
+        expect($response->getStatusCode())->not->toBe(429);
+    }
+
+    expect(throttleProbePost($uri, [$field => 'probe@example.com'])->getStatusCode())->toBe(429);
+})->with([
+    'password-reset-request' => ['/forgot-password', 'email'],
+    'password-reset-submit' => ['/reset-password', 'email'],
+    'account-register' => ['/register', 'email'],
+]);
+
+/*
+ * Unicode で異なる 2 つの email が同じ bucket に落ちると、無関係アカウントが
+ * 巻き添えでロックアウトされる (Str::transliterate 廃止の回帰テスト)。
+ */
+test('login limiter は Unicode で異なる 2 つの email を同じ bucket に collapse させない', function (): void {
+    // transliterate はどちらも "cafe@example.com" へ潰す
+    $first = throttleProbePost('/login', ['email' => 'café@example.com', 'password' => 'x']);
+    $second = throttleProbePost('/login', ['email' => 'cafe@example.com', 'password' => 'x']);
+
+    expect((int) $second->headers->get('X-RateLimit-Remaining'))->toBe(
+        (int) $first->headers->get('X-RateLimit-Remaining'),
+        'Unicode の異なる email が同じ bucket に collapse しています (巻き添えロックアウト)',
+    );
+});
+
+/** 解決後 middleware 列のクラス名リスト。 */
+function throttleProbeResolvedClasses(string $routeName): array
+{
+    /** @var Router $router */
+    $router = Route::getFacadeRoot();
+    $routes = $router->getRoutes();
+    $routes->refreshNameLookups();
+    $route = $routes->getByName($routeName);
+    expect($route)->not->toBeNull("route [{$routeName}] が存在しない");
+
+    return array_map(
+        static fn (mixed $entry): string => is_string($entry) ? explode(':', $entry, 2)[0] : '(closure)',
+        $router->gatherRouteMiddleware($route),
+    );
+}
+
+test('POST /ses/notification は throttle が署名検証より先に走る (実効順の固定)', function (): void {
+    $resolved = throttleProbeResolvedClasses('webhooks.ses');
+
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+    $signatureIndex = array_search(VerifySnsSignature::class, $resolved, true);
+
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
+    expect($signatureIndex)->not->toBeFalse('VerifySnsSignature が実効列に無い');
+    expect($throttleIndex)->toBeLessThan(
+        $signatureIndex,
+        '署名検証が throttle より先だと、署名検証コスト (証明書取得を伴う) が無制限に増幅する',
+    );
+});
+
+test('POST /ses/notification は不正 body でも上限を超えると 400 ではなく 429 になる', function (): void {
+    // 上限未満では VerifySnsSignature まで到達して 400 (envelope 不正)。
+    // 署名不正 (403) は証明書取得を伴うため対照には使わない (テストから外部通信を出さない)。
+    expect(throttleProbePost('/ses/notification')->getStatusCode())->toBe(400);
+
+    $status = 400;
+    // webhook-ses は 300/min。上限 + 1 まで叩くと throttle が先に短絡する
+    for ($i = 2; $i <= 301; $i++) {
+        $status = throttleProbePost('/ses/notification')->getStatusCode();
+        if ($status === 429) {
+            break;
+        }
+    }
+
+    expect($status)->toBe(429);
+})->group('slow');
+
+test('2FA 管理 route は throttle が recent-auth より先に走る', function (): void {
+    $resolved = throttleProbeResolvedClasses('two-factor.disable');
+
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
+
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が実効列に無い');
+    expect($recentAuthIndex)->not->toBeFalse('RequireRecentAuth が実効列に無い');
+    expect($throttleIndex)->toBeLessThan($recentAuthIndex);
+});
diff --git a/tests/Feature/Security/ThrottleExemptionPremiseTest.php b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
new file mode 100644
index 0000000..c281bf4
--- /dev/null
+++ b/tests/Feature/Security/ThrottleExemptionPremiseTest.php
@@ -0,0 +1,175 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\LocalOnly;
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
+test('default-livewire.update の前提: Filament の credential ページが component 内で rateLimit を掛けている', function (): void {
+    $pages = [
+        'vendor/filament/filament/src/Auth/Pages/Login.php',
+        'vendor/filament/filament/src/Auth/Pages/EditProfile.php',
+    ];
+
+    foreach ($pages as $relative) {
+        $source = file_get_contents(base_path($relative));
+        expect($source)->toBeString("{$relative} を読み取れません (vendor 更新でパスが変わった?)");
+        expect(is_string($source) && str_contains($source, '$this->rateLimit('))->toBeTrue(
+            "{$relative} から component 内 rate limit が消えています。"
+            .'default-livewire.update の exemption 根拠が崩れているため、route 側の防御を設計し直すこと。',
+        );
+    }
+});
+
+test('default-livewire.update の前提: panel が公開する auth ページの集合が変わっていない', function (): void {
+    // 新しい credential ページ (register / password-reset 等) が有効化されると
+    // exemption の射程が黙って広がる。集合を固定して再検討を強制する。
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
+test('logout 系の前提: 未認証では本体に到達せずログイン画面へ差し戻される', function (): void {
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
- composer test (該当 3 ファイル): 30 tests, 30 passed, 105 assertions
- 全体 composer test は最終確認で再実行予定

## 判定のお願い
残る指摘があれば [Critical]/[Warning]/[Suggestion] で挙げ、最後に **APPROVED** か
**CHANGES_REQUESTED** を明記してください。とくに #3 (route:cache の残リスクを
運用要件 + docblock で受容した判断) が妥当かを見てください。
