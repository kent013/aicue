【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。
- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。
本改善(T031)は、認証要素(email)変更経路に step-up(recent-auth)を課し、アカウント乗っ取り(メール差し替え→パスワードリセット)を塞ぐことで信頼基盤を守る。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(migrate:fresh 等)をエージェント判断で実行すること
4. response()->json() の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き(resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

セキュリティ不変条件(関連): PII(email/name)は CipherSweet、検索は whereBlind()。権限判定は laratrust_team_id を明示。認証要素変更の前段に recent-auth を課す(本改善が拡張)。

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解してから動け。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。設計そのものを見直せ。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel 12 + Fortify + Svelte 5 + Inertia のシニアコードレビュアーです。TODO T031「メールアドレス変更の recent-auth 保護 + 旧アドレス通知」の実装差分をレビューしてください。

レビュー観点:
1. **設計との一致性**: 下記詳細設計書(施策 S1〜S7)と実装が一致しているか。
2. **正確性**: middleware の email 変更判定契約(action の early-return と同一の raw `!==` 比較)にドリフト/bypass がないか。fail-safe(非 string は gate せず後続へ)が本当に安全側か。
3. **PHPStan L10 適合**: 型の widen/baseline なし。`is_string` narrowing、`Assert`、`@var` の妥当性。
4. **DTO/JsonResource パターン**: 独自 response()->json() を作らず委譲先 RequireRecentAuth の RecentAuthRequiredResource に応答生成を集約しているか。
5. **テスト網羅性**: テストマトリクス(1a/1b/2/3/5 + case 4 listener + case 6 client)が分岐を固定しているか。回帰(EmailChangeTest)を壊していないか。二重送信回帰の捕捉。
6. **セキュリティ**: stale セッション(remember-me 復元)からの email 変更 bypass を塞げているか。open redirect/情報漏洩がないか。
7. **Atomic Design 準拠**: Svelte 変更は新規 component を足さず既存 atom/organism(Input/RecentAuthModal/guardWithRecentAuth)を再利用。SVG 直書き・階層逆流なし。

出力形式: ファイルごとに判定を述べ、指摘を **[Critical] / [Warning] / [Suggestion]** に分類。最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記してください。

---

## user: 詳細設計書

(施策 S1: 条件付き middleware 新設 / S2: alias 登録 / S3: FortifyServiceProvider 後付け配線 / S4: Architecture allowlist / S5: client precheck / S6: Feature テストマトリクス / S7: listener case4 + client case6)

詳細設計書全文は `devnotes/20260714-0159-profile-update-recent-auth/detailed-design.md` を参照してください(ファイル読み込み可)。要点:

- **email 同一性判定契約**: middleware は action `UpdateUserProfileInformation::update` の early-return 条件 `$email === $user->email` と完全に同一の raw 文字列比較を使う。gate 条件は (1) submitted が is_string かつ (2) submitted !== 現行 email の両方を満たす時のみ委譲。欠落/非 string は gate せず後続の Validator 422 に委ねる(非 string は email 変更を起こせないため fail-safe)。
- **応答生成は委譲先が担う**: 409/302 分岐・intended 保持・dropped_mutation flag は全て RequireRecentAuth。本 middleware は独自 JSON を作らない。
- **修正方針(2)「旧アドレス通知 + email_verified_at null 化」は既に UpdateUserProfileInformation に実装済み**。本設計では action 本体を変更せず回帰(EmailChangeTest)を維持する。
- **client precheck**: email 変更時のみ guardWithRecentAuth(既存・account 削除で使用中)を挟む。氏名のみ変更は即 put。precheck はサーバ最終ゲートの UX 補助。
- **テストマトリクス**: 1a(stale+email変更 Inertia=409) / 1b(stale+email変更 通常=confirm redirect) / 2(stale+氏名のみ=gate されず成功) / 3(fresh+email変更=成功+旧アドレス通知+再検証) / 5(欠落/非string=gate されない)。case4(viaRemember listener は stamp しない/対照で stamp する) / case6(client: stale で put せず再認証後 1 回だけ put)。

## user: design system 参照 (resources/js 変更あり)

Svelte 変更は `resources/js/pages/Settings/Index.svelte` の `submitProfile` のみ。新規 component 追加なし。既存の atom(`Input`)/organism(`RecentAuthModal`)/lib(`withRecentAuth`, `guardWithRecentAuth`, `resumePendingAction`)を再利用。DS token・hex 直書き・SVG 内包の変更なし。Atomic Design 階層(atoms→molecules→organisms→features→templates→pages)の逆流なし。

## user: テスト結果

- composer test: 1617 passed, 2 skipped (全 PHP スイート green)
- pnpm test: 527 passed (全 JS スイート green)。SettingsIndex.test.ts は 9 passed
- composer phpstan: No errors (L10)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck: green
- pnpm build: 成功
- 個別確認: ProfileEmailChangeRecentAuthTest / RecentAuthTest / EmailChangeTest / RecentAuthRouteTest = 34 passed
- 実装過程の発見: case5 の「email 非 string(配列)」入力は Fortify ProfileInformationController::update が `Str::lower(array)` で 500 になる(本タスク以前からの Fortify 既存挙動、recent-auth とは無関係)。middleware は非 string を gate せず後続へ流す(fail-safe)ため、テストは「recent-auth ゲート応答でない(409/redirect でない)+ email 不変」を不変条件として固定した(422 断定を避けた)。

## user: 実装差分 (git diff)

```diff
diff --git a/app/Http/Middleware/RequireRecentAuthOnEmailChange.php b/app/Http/Middleware/RequireRecentAuthOnEmailChange.php
new file mode 100644
index 0000000..073875b
--- /dev/null
+++ b/app/Http/Middleware/RequireRecentAuthOnEmailChange.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Middleware;
+
+use App\Models\User;
+use Closure;
+use Illuminate\Http\Request;
+use Symfony\Component\HttpFoundation\Response;
+use Webmozart\Assert\Assert;
+
+/**
+ * profile 更新 (PUT /user/profile-information) のうち **メールアドレス変更を伴う場合のみ**
+ * recent-auth (step-up) を要求する条件付きゲート。alias: `recent-auth.on-email-change`。
+ *
+ * 氏名のみの変更は乗っ取りベクタではないため素通しし、日常操作の摩擦を増やさない。
+ * email 変更は「認証要素変更」であり、UpdateUserProfileInformation が旧アドレス通知 +
+ * email_verified_at null 化を行う経路。ここを stale セッション (remember-me 復元で
+ * recent_auth_at 未 stamp) から素通しさせない。
+ *
+ * 判定契約 (UpdateUserProfileInformation::update の early-return と同一の raw 比較):
+ *   - submitted email が is_string かつ現行 email と `!==` の時のみ RequireRecentAuth へ委譲。
+ *   - 欠落 / 非 string は gate せず後続 (Validator の required/email 422) に委ねる。
+ *     非 string は email 変更を起こせないため fail-safe (bypass 不可)。
+ *
+ * 応答 (409 + RecentAuthRequiredResource / 302 → recent-auth.confirm) は委譲先が生成する。
+ */
+final class RequireRecentAuthOnEmailChange
+{
+    public function __construct(private readonly RequireRecentAuth $requireRecentAuth) {}
+
+    public function handle(Request $request, Closure $next): Response
+    {
+        if ($this->changesEmail($request)) {
+            return $this->requireRecentAuth->handle($request, $next);
+        }
+
+        $response = $next($request);
+        Assert::isInstanceOf($response, Response::class);
+
+        return $response;
+    }
+
+    /**
+     * 送信 email が現行 email を変更するか (action の early-return と同一の raw 比較)。
+     */
+    private function changesEmail(Request $request): bool
+    {
+        $submitted = $request->input('email');
+        if (! is_string($submitted)) {
+            return false; // 欠落 / 非 string → 変更を起こせない。Validator に委ねる
+        }
+
+        $user = $request->user();
+        if (! $user instanceof User) {
+            return false; // auth 前段は 'auth' middleware が担保。非 User なら gate 対象外
+        }
+
+        return $submitted !== $user->email;
+    }
+}
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 794330c..0e2d6d9 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -21,6 +21,7 @@
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
+use Illuminate\Routing\RouteCollectionInterface;
 use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\RateLimiter;
 use Illuminate\Support\ServiceProvider;
@@ -62,6 +63,16 @@ class FortifyServiceProvider extends ServiceProvider
         'two-factor.disable',
     ];
 
+    /**
+     * email 変更時のみ recent-auth を課す条件付き付与 (氏名のみ変更は素通し)。
+     * profile 更新は Fortify 登録ルートのため booted で後付けする。
+     *
+     * @var array<string, string> route name => middleware alias
+     */
+    private const CONDITIONAL_RECENT_AUTH_ROUTES = [
+        'user-profile-information.update' => 'recent-auth.on-email-change',
+    ];
+
     public function register(): void
     {
         // Fortify Response contract の差し替え (redirect + flash の Inertia 整合化)。
@@ -115,16 +126,30 @@ private function attachRecentAuthToSensitiveRoutes(): void
             $routes->refreshNameLookups();
 
             foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
-                $route = $routes->getByName($name);
-                // 長寿命プロセス等で callback が同一 Route instance に複数回届いても
-                // 重複付与しない (idempotent)
-                if ($route !== null && ! in_array('recent-auth', $route->middleware(), true)) {
-                    $route->middleware('recent-auth');
-                }
+                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
+            }
+
+            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
+                self::appendMiddlewareIfMissing($routes, $name, $alias);
             }
         });
     }
 
+    /**
+     * named route に middleware alias を idempotent に append する (未登録時のみ)。
+     *
+     * booted callback (static クロージャ) から呼ぶため **static** で定義し
+     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
+     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
+     */
+    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
+    {
+        $route = $routes->getByName($name);
+        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
+            $route->middleware($alias);
+        }
+    }
+
     private function configureRateLimiters(): void
     {
         RateLimiter::for('login', function (Request $request) {
diff --git a/bootstrap/app.php b/bootstrap/app.php
index c84dfa9..a380d04 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -15,6 +15,7 @@
 use App\Http\Middleware\RequireActiveSubscription;
 use App\Http\Middleware\RequireApiKeyAbility;
 use App\Http\Middleware\RequireRecentAuth;
+use App\Http\Middleware\RequireRecentAuthOnEmailChange;
 use App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations;
 use App\Http\Middleware\ResolveApiActor;
 use App\Http\Middleware\SecurityHeaders;
@@ -102,6 +103,8 @@
         // require-active-subscription は業務 route の課金ゲート (判定は BillingAccess 経由のみ)
         $middleware->alias([
             'recent-auth' => RequireRecentAuth::class,
+            // profile 更新の email 変更時のみ step-up を課す条件付きゲート
+            'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
             'require-active-subscription' => RequireActiveSubscription::class,
             // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
             // (context 別文言は EmailVerificationGateContext)。organizations.store /
diff --git a/resources/js/pages/Settings/Index.svelte b/resources/js/pages/Settings/Index.svelte
index 234583c..3a29196 100644
--- a/resources/js/pages/Settings/Index.svelte
+++ b/resources/js/pages/Settings/Index.svelte
@@ -47,14 +47,36 @@
         email: initialUser?.email ?? "",
     });
 
-    function submitProfile(event: SubmitEvent): void {
-        event.preventDefault();
+    // baseline email。更新成功のたびに最新値へ同期し、連続操作 (変更→再編集) 時の
+    // precheck 判定ドリフトを抑える。
+    let baselineEmail = $state(initialUser?.email ?? "");
+
+    function putProfile(): void {
+        // 送信時点の email をスナップショット。onSuccess で「サーバが受理した値」を
+        // baseline にするため、送信後〜応答前に入力が変わっても現在入力値で baseline を汚さない。
+        const submittedEmail = profileForm.email;
         profileForm.put("/user/profile-information", {
             errorBag: "updateProfileInformation",
             preserveScroll: true,
+            onSuccess: () => {
+                // 成功時、受理された送信値を baseline に (連続操作の判定ズレ防止)
+                baselineEmail = submittedEmail;
+            },
         });
     }
 
+    function submitProfile(event: SubmitEvent): void {
+        event.preventDefault();
+        // email 変更時のみ step-up precheck (氏名のみ変更は従来通り即 put)。
+        // サーバ側 recent-auth.on-email-change が最終ゲート、これは UX 補助。
+        const emailChanged = profileForm.email !== baselineEmail;
+        if (emailChanged) {
+            guardWithRecentAuth(putProfile);
+            return;
+        }
+        putProfile();
+    }
+
     const passwordForm = useForm({
         current_password: "",
         password: "",
diff --git a/tests/Architecture/RecentAuthRouteTest.php b/tests/Architecture/RecentAuthRouteTest.php
index c030111..bc6517e 100644
--- a/tests/Architecture/RecentAuthRouteTest.php
+++ b/tests/Architecture/RecentAuthRouteTest.php
@@ -36,6 +36,10 @@ function recentAuthRequiredRouteNames(): array
         'two-factor.regenerate-recovery-codes',
         // 2FA 無効化 (第二要素そのものの除去。bug-hunt F-H3。同じく後付け配線)
         'two-factor.disable',
+        // profile 更新 (email 変更時のみ条件付き step-up。配線は
+        // FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()。
+        // routeHasRecentAuth は 'recent-auth.on-email-change' も str_starts_with で検出)
+        'user-profile-information.update',
     ];
 }
 
diff --git a/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php b/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php
new file mode 100644
index 0000000..79d91e7
--- /dev/null
+++ b/tests/Feature/Auth/ProfileEmailChangeRecentAuthTest.php
@@ -0,0 +1,123 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\User;
+use App\Notifications\EmailChangedSecurityNotification;
+use Illuminate\Notifications\AnonymousNotifiable;
+use Illuminate\Support\Facades\Notification;
+
+/*
+ * profile 更新 (PUT /user/profile-information) の email 変更時 step-up ゲート
+ * (RequireRecentAuthOnEmailChange)。氏名のみ変更は素通し、email 変更は recent-auth
+ * を要求する条件付きゲートの分岐を固定する (詳細設計 T031 テストマトリクス 1a/1b/2/3/5)。
+ *
+ * 委譲先 RequireRecentAuth の 409/302 生成ロジックは RecentAuthTest が担保するため、
+ * ここでは「email 変更で gate される / 氏名のみ・欠落・非 string は gate されない」の
+ * 条件付き委譲契約と、旧アドレス通知 + email_verified_at null 化の回帰を検証する。
+ */
+
+test('1a: stale + email 変更 (Inertia mutation) は 409 で反映されない', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'old@example.com']);
+
+    $response = $this->actingAs($user)
+        ->withHeaders(['X-Inertia' => 'true'])
+        ->put('/user/profile-information', [
+            'name' => '新しい名前',
+            'email' => 'new@example.com',
+        ]);
+
+    $response->assertStatus(409)->assertJsonPath('code', 'recent_auth_required');
+
+    $user->refresh();
+    expect($user->email)->toBe('old@example.com');
+    expect($user->email_verified_at)->not->toBeNull();
+    Notification::assertNothingSent();
+});
+
+test('1b: stale + email 変更 (通常) は confirm へ redirect で反映されない', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'old@example.com']);
+
+    $response = $this->actingAs($user)->put('/user/profile-information', [
+        'name' => '新しい名前',
+        'email' => 'new@example.com',
+    ]);
+
+    $response->assertRedirect(route('recent-auth.confirm'));
+    $response->assertSessionHas('url.intended');
+
+    $user->refresh();
+    expect($user->email)->toBe('old@example.com');
+    expect($user->email_verified_at)->not->toBeNull();
+    Notification::assertNothingSent();
+});
+
+test('2: stale + 氏名のみ変更は gate されず成功し email 不変', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'me@example.com']);
+
+    $response = $this->actingAs($user)->put('/user/profile-information', [
+        'name' => '新しい名前',
+        'email' => 'me@example.com',
+    ]);
+
+    // recent-auth confirm への redirect ではない (= gate されていない)
+    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));
+    $response->assertSessionHasNoErrors();
+
+    $user->refresh();
+    expect($user->name)->toBe('新しい名前');
+    expect($user->email)->toBe('me@example.com');
+    Notification::assertNothingSent();
+});
+
+test('3: fresh + email 変更は成功し旧アドレス通知 + 再検証要求', function (): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'old@example.com']);
+
+    $response = $this->actingAs($user)
+        ->withSession(['recent_auth_at' => time()])
+        ->put('/user/profile-information', [
+            'name' => $user->name,
+            'email' => 'new@example.com',
+        ]);
+
+    // gate を通過して action が実行される (confirm への redirect ではない)
+    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));
+    $response->assertSessionHasNoErrors();
+
+    $user->refresh();
+    expect($user->email)->toBe('new@example.com');
+    expect($user->email_verified_at)->toBeNull();
+
+    Notification::assertSentTo(
+        new AnonymousNotifiable,
+        EmailChangedSecurityNotification::class,
+        fn ($notification, $channels, $notifiable): bool => $notifiable->routes['mail'] === 'old@example.com',
+    );
+});
+
+test('5: stale + email 欠落/非string は recent-auth で gate されず email 不変', function (array $payload): void {
+    Notification::fake();
+    $user = User::factory()->create(['email' => 'me@example.com']);
+
+    $response = $this->actingAs($user)->putJson('/user/profile-information', $payload);
+
+    // 検証する不変条件は「recent-auth ゲート応答 (409 / recent-auth.confirm への redirect)
+    // ではないこと」= middleware が非 string email を email 変更とみなして委譲しない (fail-safe)。
+    // 非 string email は email 変更を起こせないため後続へ素通しする:
+    //   - 欠落: action の Validator が 422 (required)
+    //   - 配列: Fortify ProfileInformationController が Str::lower(array) で 500
+    //     (本タスク以前からの Fortify 既存挙動。recent-auth gate ではない点は同じ)
+    // いずれも email は変更されず通知も飛ばない。ここでは gate されない契約を固定する。
+    expect($response->status())->not->toBe(409);
+    expect($response->headers->get('Location'))->not->toBe(route('recent-auth.confirm'));
+
+    expect($user->refresh()->email)->toBe('me@example.com');
+    Notification::assertNothingSent();
+})->with([
+    'email 欠落' => [['name' => '新しい名前']],
+    'email 非string (配列)' => [['name' => '新しい名前', 'email' => ['x@example.com']]],
+]);
diff --git a/tests/Feature/Auth/RecentAuthTest.php b/tests/Feature/Auth/RecentAuthTest.php
index 2029d01..2c0557e 100644
--- a/tests/Feature/Auth/RecentAuthTest.php
+++ b/tests/Feature/Auth/RecentAuthTest.php
@@ -2,8 +2,13 @@
 
 declare(strict_types=1);
 
+use App\Listeners\Auth\StampRecentAuthOnLogin;
 use App\Models\SocialAccount;
 use App\Models\User;
+use App\Security\RecentAuthState;
+use Illuminate\Auth\Events\Login;
+use Illuminate\Auth\SessionGuard;
+use Illuminate\Contracts\Auth\Factory as AuthFactory;
 use Illuminate\Support\Facades\DB;
 use Laravel\Socialite\Contracts\Provider;
 use Laravel\Socialite\Contracts\User as SocialiteUserContract;
@@ -284,6 +289,46 @@ function linkGoogleAccount(User $user, string $providerUserId): void
     expect(session('recent_auth_method'))->toBe('login');
 });
 
+test('case 4: viaRemember の web login は recent-auth を stamp しない (remember-me 復元 = stale)', function (): void {
+    // remember-me cookie による自動ログイン復元 (SessionGuard::viaRemember()===true) は
+    // fresh 認証扱いしない契約 (StampRecentAuthOnLogin docblock) を listener 単位で固定する。
+    $user = User::factory()->create();
+
+    /** @var SessionGuard&MockInterface $guard */
+    $guard = Mockery::mock(SessionGuard::class);
+    $guard->shouldReceive('viaRemember')->andReturn(true);
+
+    /** @var AuthFactory&MockInterface $authFactory */
+    $authFactory = Mockery::mock(AuthFactory::class);
+    $authFactory->shouldReceive('guard')->with('web')->andReturn($guard);
+
+    $listener = new StampRecentAuthOnLogin(app(RecentAuthState::class), $authFactory);
+    $listener->handle(new Login('web', $user, true));
+
+    expect(session('recent_auth_at'))->toBeNull();
+    expect(session('recent_auth_method'))->toBeNull();
+});
+
+test('case 4 対照: viaRemember でない web login は recent-auth を stamp する', function (): void {
+    // 通常 credential login (viaRemember()===false) では fresh 扱いで stamp される契約を
+    // 両側から固定する。
+    $user = User::factory()->create();
+
+    /** @var SessionGuard&MockInterface $guard */
+    $guard = Mockery::mock(SessionGuard::class);
+    $guard->shouldReceive('viaRemember')->andReturn(false);
+
+    /** @var AuthFactory&MockInterface $authFactory */
+    $authFactory = Mockery::mock(AuthFactory::class);
+    $authFactory->shouldReceive('guard')->with('web')->andReturn($guard);
+
+    $listener = new StampRecentAuthOnLogin(app(RecentAuthState::class), $authFactory);
+    $listener->handle(new Login('web', $user, false));
+
+    expect(session('recent_auth_at'))->toBeInt();
+    expect(session('recent_auth_method'))->toBe('login');
+});
+
 /* ---------------------------------------------------------------- SSO step-up satisfier */
 
 test('step-up intent の開始は未ログインなら 403', function (): void {
diff --git a/tests/js/pages/SettingsIndex.test.ts b/tests/js/pages/SettingsIndex.test.ts
index 8d9c4ab..c9ee7c2 100644
--- a/tests/js/pages/SettingsIndex.test.ts
+++ b/tests/js/pages/SettingsIndex.test.ts
@@ -11,18 +11,41 @@ import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/sv
  * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
  */
 
-const { pageState, routerDeleteMock } = vi.hoisted(() => ({
+const { pageState, routerDeleteMock, formHolder } = vi.hoisted(() => ({
     pageState: {
         props: {} as Record<string, unknown>,
         url: "/settings",
     },
     routerDeleteMock: vi.fn(),
+    // profileForm (email キーを持つ form) を捕捉する holder。case 6 で put を検証する。
+    formHolder: { profile: null as Record<string, unknown> | null },
 }));
 
 vi.mock("@inertiajs/svelte", async (importOriginal) => ({
     ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
     router: { delete: routerDeleteMock },
     page: pageState,
+    // useForm を最小 fake に差し替え、profileForm.put を spy する (case 6)。
+    // email キーを持つ form を profileForm とみなし holder に記録する。
+    useForm: (initial: Record<string, unknown>) => {
+        const form: Record<string, unknown> = {
+            ...initial,
+            errors: {},
+            processing: false,
+            get: vi.fn(),
+            post: vi.fn(),
+            put: vi.fn(),
+            patch: vi.fn(),
+            delete: vi.fn(),
+            submit: vi.fn(),
+            reset: vi.fn(),
+            clearErrors: vi.fn(),
+        };
+        if ("email" in initial) {
+            formHolder.profile = form;
+        }
+        return form;
+    },
 }));
 
 // eslint-disable-next-line import/first
@@ -57,6 +80,37 @@ function stubRecentAuthFresh(): void {
     );
 }
 
+/**
+ * recent-auth precheck を stale (/recent-auth/status → recent:false) にし、
+ * satisfier (/recent-auth/password) は 204 成功を返すスタブ (case 6 用)。
+ */
+function stubRecentAuthStaleThenConfirm(): void {
+    vi.stubGlobal(
+        "fetch",
+        vi.fn((input: RequestInfo | URL) => {
+            const url = typeof input === "string" ? input : input.toString();
+            if (url.includes("/recent-auth/status")) {
+                return Promise.resolve({
+                    ok: true,
+                    status: 200,
+                    json: () =>
+                        Promise.resolve({
+                            recent: false,
+                            passwordSet: true,
+                            availableProviders: [],
+                            canSatisfy: true,
+                            confirmedAt: null,
+                        }),
+                });
+            }
+            if (url.includes("/recent-auth/password")) {
+                return Promise.resolve({ status: 204 });
+            }
+            return Promise.reject(new Error(`unexpected fetch: ${url}`));
+        }),
+    );
+}
+
 /** router.delete 第2引数 (visit options) の onError を取り出す */
 interface DeleteVisitOptions {
     onError?: () => void;
@@ -66,6 +120,7 @@ interface DeleteVisitOptions {
 
 beforeEach(() => {
     setProps();
+    formHolder.profile = null;
 });
 
 afterEach(() => {
@@ -158,3 +213,71 @@ describe("Settings/Index 唯一オーナー削除ガード", () => {
         );
     });
 });
+
+describe("Settings/Index プロフィール更新の recent-auth precheck", () => {
+    it("email 変更 + stale は precheck 段階で put せず、再認証後に 1 回だけ put する", async () => {
+        stubRecentAuthStaleThenConfirm();
+        render(Index, { props: {} });
+
+        const profileForm = formHolder.profile;
+        expect(profileForm).not.toBeNull();
+        const putMock = profileForm?.put as ReturnType<typeof vi.fn>;
+
+        // 名前と email を編集 (email 変更で precheck が発火する)
+        await fireEvent.input(screen.getByLabelText("名前"), {
+            target: { value: "新しい名前" },
+        });
+        await fireEvent.input(screen.getByLabelText("メールアドレス"), {
+            target: { value: "new@example.com" },
+        });
+
+        // 保存 → email 変更 → precheck stale → 再認証モーダル
+        const saveButton = screen.getByRole("button", { name: "保存" });
+        const form = saveButton.closest("form");
+        expect(form).not.toBeNull();
+        await fireEvent.submit(form as HTMLFormElement);
+
+        await waitFor(() =>
+            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument(),
+        );
+        // precheck 段階では put されない (二重送信回帰の捕捉)
+        expect(putMock).not.toHaveBeenCalled();
+
+        // モーダルで再認証 → 204 → onConfirmed → resumePendingAction → put
+        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
+            target: { value: "password" },
+        });
+        await fireEvent.submit(
+            screen.getByTestId("recent-auth-submit").closest("form") as HTMLFormElement,
+        );
+
+        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
+        const call = putMock.mock.calls.at(-1);
+        expect(call?.[0]).toBe("/user/profile-information");
+        // 編集済み name/email が保持されたまま再送される
+        expect(profileForm?.name).toBe("新しい名前");
+        expect(profileForm?.email).toBe("new@example.com");
+    });
+
+    it("氏名のみ変更 (email 不変) は precheck を経ず直ちに put する", async () => {
+        // status を叩いたら失敗させ、precheck が発火しないことを保証する
+        vi.stubGlobal(
+            "fetch",
+            vi.fn(() => Promise.reject(new Error("precheck should not run"))),
+        );
+        render(Index, { props: {} });
+
+        const profileForm = formHolder.profile;
+        const putMock = profileForm?.put as ReturnType<typeof vi.fn>;
+
+        await fireEvent.input(screen.getByLabelText("名前"), {
+            target: { value: "氏名だけ変更" },
+        });
+
+        const saveButton = screen.getByRole("button", { name: "保存" });
+        await fireEvent.submit(saveButton.closest("form") as HTMLFormElement);
+
+        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
+        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
+    });
+});

```
