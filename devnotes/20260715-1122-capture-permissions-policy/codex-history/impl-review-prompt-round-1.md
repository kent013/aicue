## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件
tenant キー不信 / 子は親に属する (認可より前に 404) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性 / 外部 URL 取得は SSRF 検査経由。

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考えてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

---

## System: あなたの役割

あなたは Laravel + Svelte アプリのコードレビュアーです。以下の改善実装をレビューしてください。

### レビュー観点
- **設計との一致性**: 詳細設計書 (下記) の施策 T1/T2/T3 が正しく実装されているか
- **正確性**: Permissions-Policy の allowlist マッチング / fail-secure / opt-out ロジックにバグがないか
- **PHPStan 適合性 (level 10)**: `config()->array()` の緩い型を `list<string>` に narrow する処理、`?string` 戻り値の型安全性
- **DTO/JsonResource パターン**: 本変更は HTTP ヘッダのみ (body 不変) だが逸脱がないか
- **テスト網羅性**: capture 緩和 / 非対象維持 / fail-secure (404) / opt-out / 型安全 fail-safe の各ケースが Feature テストで固定されているか
- **セキュリティ**: least-privilege (撮影 document route のみ緩和)、cross-origin を開いていないか (`(self)` のみ)、404 への緩和漏れがないか

### 出力形式
- ファイルごとに判定
- 指摘は Critical / Warning / Suggestion に分類
- 最後に全体判定: **APPROVED** または **CHANGES_REQUESTED**

---

## User

### 詳細設計書

（施策 T1: config/security.php に `capture_permissions_policy` と `capture_permissions_policy_routes` を追加。T2: SecurityHeaders に `resolvePermissionsPolicy` helper を追加し撮影 document route (`capture.manuals.show`) のみ camera=(self),microphone=(self) を送出。T3: SecurityHeadersTest に capture 緩和 / 非 recorder 維持 / binding 失敗 404 でヘッダなし / opt-out 空文字 / allowlist 非文字列 fail-safe の Feature テストを追加。）

背景: `SecurityHeaders` middleware は web group の append 登録で `SubstituteBindings` より内側にあるため、binding 失敗 404 では middleware に到達せず Permissions-Policy が付かない (fail-safe)。撮影 recorder は `getUserMedia({ video, audio: true })` を要求するが baseline の `camera=(), microphone=()` (空 allowlist) では reject される。撮影を描画するのは `capture.manuals.show` route のみ。

### 実装差分 (git diff)

```diff
diff --git a/app/Http/Middleware/SecurityHeaders.php b/app/Http/Middleware/SecurityHeaders.php
index ed4269f..6368286 100644
--- a/app/Http/Middleware/SecurityHeaders.php
+++ b/app/Http/Middleware/SecurityHeaders.php
@@ -35,8 +35,9 @@ public function handle(Request $request, Closure $next): Response
         $response->headers->set('X-Content-Type-Options', 'nosniff');
         $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
 
-        // Permissions-Policy は常時送出。null / 空文字は opt-out (非送出、env で一時 rollback)
-        $permissionsPolicy = config('security.permissions_policy');
+        // Permissions-Policy は常時送出。撮影 document route (config allowlist に一致) のみ
+        // camera/microphone を (self) に緩めた capture 用値を送る。null / 空文字は opt-out (非送出)。
+        $permissionsPolicy = $this->resolvePermissionsPolicy($request);
         if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
             $response->headers->set('Permissions-Policy', $permissionsPolicy);
         }
@@ -68,6 +69,32 @@ public function handle(Request $request, Closure $next): Response
         return $response;
     }
 
+    /**
+     * 送出する Permissions-Policy 値を決める。
+     *
+     * 撮影 document route (security.capture_permissions_policy_routes の allowlist に一致) では
+     * camera/microphone を (self) に緩めた capture 用値 (security.capture_permissions_policy) を、
+     * それ以外は baseline 値 (security.permissions_policy) を返す。null / 空文字は呼び出し側で
+     * opt-out (非送出) として扱う。allowlist が空、または route 未解決 (例: /app 配下の 404) では
+     * routeIs が false となり baseline に落ちる (fail-secure)。
+     */
+    private function resolvePermissionsPolicy(Request $request): ?string
+    {
+        /** @var list<string> $captureRoutes */
+        $captureRoutes = array_values(array_filter(
+            config()->array('security.capture_permissions_policy_routes'),
+            is_string(...),
+        ));
+
+        $key = ($captureRoutes !== [] && $request->routeIs(...$captureRoutes))
+            ? 'security.capture_permissions_policy'
+            : 'security.permissions_policy';
+
+        $value = config($key);
+
+        return is_string($value) ? $value : null;
+    }
+
     /**
      * metadata endpoint (`.well-known/oauth-*`) 向けの最小 subset を当てる。
      *
diff --git a/config/security.php b/config/security.php
index d6cc08b..e19768f 100644
--- a/config/security.php
+++ b/config/security.php
@@ -33,6 +33,25 @@
         'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
     ),
 
+    /*
+    | 撮影 document route 専用の Permissions-Policy override。撮影 recorder (CameraRecorder) が
+    | getUserMedia({ video, audio: true }) を要求するため camera/microphone を (self) に緩める
+    | (同一オリジン PWA のみ許可 = v1 スコープ)。geolocation / payment 等の他 directive は baseline
+    | と同一に保つ。env 上書き可。null / 空文字でヘッダ非送出 (opt-out, env による一時 rollback)。
+    */
+    'capture_permissions_policy' => env(
+        'SECURITY_CAPTURE_PERMISSIONS_POLICY',
+        'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
+    ),
+
+    /*
+    | capture 用 Permissions-Policy を適用する route 名 allowlist (least-privilege)。
+    | Permissions-Policy は document 単位に効くため、recorder を描画する撮影 document route のみ
+    | 緩和し、他の capture HTML document (一覧等) や未解決 404 は baseline (厳格値) のままにする。
+    | 将来撮影画面が増えたら本 allowlist へ明示追加する (追加はレビュー対象になる)。
+    */
+    'capture_permissions_policy_routes' => ['capture.manuals.show'],
+
     'csp' => [
         'enabled' => (bool) env('SECURITY_CSP_ENABLED', true),
         /*
diff --git a/tests/Feature/Security/SecurityHeadersTest.php b/tests/Feature/Security/SecurityHeadersTest.php
index 7b2ba07..9eaf9ac 100644
--- a/tests/Feature/Security/SecurityHeadersTest.php
+++ b/tests/Feature/Security/SecurityHeadersTest.php
@@ -6,6 +6,10 @@
  * SecurityHeaders / RedirectToHttps の挙動検証。
  */
 
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+
 test('全レスポンスに baseline セキュリティヘッダが付く', function (): void {
     $response = $this->get('/');
 
@@ -115,3 +119,85 @@
     // guard の各検査項目は tests/Feature/Support/ProductionEnvGuardTest.php が網羅する
     $this->artisan('production:preflight', ['--strict' => true])->assertFailed();
 });
+
+/*
+ * 撮影 PWA のカメラ許可 (T057): 撮影 document route (capture.manuals.show) のみ
+ * Permissions-Policy で camera/microphone を (self) に緩め、他ルート・404 は baseline 厳格値を維持する。
+ */
+
+/**
+ * @return array{User, Project, VideoManual}
+ */
+function captureShowContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    return [$owner, $project, $manual];
+}
+
+test('撮影 document route は camera/microphone を (self) に緩める', function (): void {
+    [$owner, $project, $manual] = captureShowContext();
+
+    // 完全一致で検証: camera/microphone のみ (self)、geolocation / payment は baseline のまま (drift 検出)
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertHeader(
+            'Permissions-Policy',
+            'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
+        );
+});
+
+test('capture 内の非 recorder ルート (index) は厳格な baseline を維持する', function (): void {
+    [$owner, $project] = captureShowContext();
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
+        ->assertOk()
+        ->assertHeader(
+            'Permissions-Policy',
+            'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
+        );
+});
+
+test('binding 失敗 404 には Permissions-Policy が一切付かない (緩和の漏れなし)', function (): void {
+    [$owner, $project] = captureShowContext();
+
+    // 存在しない manual id → scopeBindings 失敗で 404。SecurityHeaders は SubstituteBindings より
+    // 内側 (append) のため到達せず、ヘッダは付かない (fail-safe)。
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/999999999")
+        ->assertNotFound()
+        ->assertHeaderMissing('Permissions-Policy');
+});
+
+test('capture 用 config が空文字 (opt-out) なら撮影 route でも非送出になる', function (): void {
+    [$owner, $project, $manual] = captureShowContext();
+
+    config()->set('security.capture_permissions_policy', '');
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertHeaderMissing('Permissions-Policy');
+});
+
+test('allowlist の非文字列要素は無視される (型安全 fail-safe)', function (): void {
+    [$owner, $project, $manual] = captureShowContext();
+
+    config()->set('security.capture_permissions_policy_routes', ['capture.manuals.show', 123, null]);
+
+    // 撮影 route は capture 値 (非文字列要素を落としても route は生き残る)
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertOk()
+        ->assertHeader(
+            'Permissions-Policy',
+            'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
+        );
+
+    // 非 recorder は baseline のまま
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
+        ->assertOk()
+        ->assertHeader(
+            'Permissions-Policy',
+            'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
+        );
+});
```

### テスト結果
- SecurityHeadersTest: 17 passed, 52 assertions (うち T057 追加分 5 テスト)
- composer test (全体): 1775 passed, 2 skipped, 0 failed
- composer phpstan: No errors (level 10)
- vendor/bin/pint --test: clean
- pnpm typecheck / lint / build / test: すべて green (748 JS tests passed)
