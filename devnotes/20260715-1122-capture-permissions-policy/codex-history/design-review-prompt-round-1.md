【アプリの使命 (North Star) — AGENTS.md より】
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】
1. テストなしの実装完了報告 2. PHPStan エラーの widen・baseline 化 3. dev DB への破壊操作 4. response()->json() の直書き 5. LLM 呼び出しの Prism 直呼び 6. prompt 文字列のコード直書き 7. 操作系 POST の応答での redirect()->intended() 8. 必須条件未充足でボタン disabled。

【セキュリティ不変条件】
tenant キー不信 / 子は親に属する (認可より前に 404) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性 / 外部 URL 取得は SSRF 検査経由。

【思考原則】仮説を立てろ。データに真摯に向き合え。先人の知恵 (Laravel/Svelte) を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、提供テキストの分析に集中 (ファイル読み込みは許可)。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO + JsonResource / Laratrust RBAC。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全）
2. 既存コードとの整合性（命名・パターン・API）
3. PHPStan level 10 適合性（型安全、generics、Assert）
4. テスト計画の網羅性（各施策に Pest、RefreshDatabase グローバル）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ（認可・入力検証・OWASP・セキュリティ不変条件。特に Permissions-Policy 緩和の妥当性）
10. DESIGN.md 準拠（該当なし: UI/frontend 変更なし）
11. Atomic Design 準拠（該当なし）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（devnotes/20260715-1122-capture-permissions-policy/detailed-design.md 全文。以下抜粋）

### 施策概要
- T1: config/security.php に capture 用 Permissions-Policy 値 (capture_permissions_policy) と route allowlist (capture_permissions_policy_routes=['capture.manuals.show']) を追加。
- T2: SecurityHeaders.php に resolvePermissionsPolicy(Request): ?string helper を追加し、撮影 document route (allowlist 一致) のみ capture 用値を送る。
- T3: SecurityHeadersTest.php に Feature テスト追加。

### T1 変更後 (config/security.php)
```php
'permissions_policy' => env(
    'SECURITY_PERMISSIONS_POLICY',
    'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
),
'capture_permissions_policy' => env(
    'SECURITY_CAPTURE_PERMISSIONS_POLICY',
    'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
),
'capture_permissions_policy_routes' => ['capture.manuals.show'],
```

### T2 変更後 (SecurityHeaders.php)
```php
// Permissions-Policy は常時送出。撮影 document route (config allowlist に一致) のみ
// camera/microphone を (self) に緩めた capture 用値を送る。null / 空文字は opt-out (非送出)。
$permissionsPolicy = $this->resolvePermissionsPolicy($request);
if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
    $response->headers->set('Permissions-Policy', $permissionsPolicy);
}
```
```php
private function resolvePermissionsPolicy(Request $request): ?string
{
    /** @var list<string> $captureRoutes */
    $captureRoutes = array_values(array_filter(
        config()->array('security.capture_permissions_policy_routes'),
        is_string(...),
    ));

    $key = ($captureRoutes !== [] && $request->routeIs(...$captureRoutes))
        ? 'security.capture_permissions_policy'
        : 'security.permissions_policy';

    $value = config($key);

    return is_string($value) ? $value : null;
}
```

### 設計判断
- SecurityHeaders は web group の append (bootstrap/app.php L82-90) に登録され $next($request) 後に走る = route 解決済みのため routeIs() が正しく評価できる。
- route 未解決 (/app 配下 404) / 空 allowlist では routeIs が false → baseline に落ちる (fail-secure)。
- opt-out contract 踏襲: ?string を is_string() && !== '' で narrow。SECURITY_CAPTURE_PERMISSIONS_POLICY='' で capture でも非送出。
- 他ヘッダ (CSP/HSTS/X-Frame-Options/metadata subset) は無改変。

### 緩和を撮影 document 1 route に限定する根拠
- recorder (CameraRecorder.svelte L177-179 で getUserMedia({video, audio:true})) を描画するのは pages/Capture/Show.svelte (= capture.manuals.show) のみ (grep 確認)。
- Permissions-Policy は document 単位に効くため、他の capture HTML document (manuals.index) や 404 まで緩めると、そこで XSS が成立した際に camera/microphone を要求できる。よって撮影 document route に限定 (least-privilege)。

### T3 テスト計画 (SecurityHeadersTest.php に追記, Factory + RefreshDatabase グローバル)
セットアップは既存 CaptureManualBrowsingTest と同型 (createOrganizationWithOwner → Project::factory()->forOrganization → VideoManual::factory()->forProject(['status'=>'ready']) → actingAs->get show URL)。
1. capture.manuals.show 応答の Permissions-Policy が完全一致で 'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")'
2. 非 capture (/) は baseline 維持 (既存 L16-19 が担保 = 非退行)
3. capture.manuals.index は baseline (camera=(), microphone=()) 維持 (least-privilege)
4. /app 配下の未解決 404 (存在しない manual id) は baseline 維持 (fail-secure)
5. config security.capture_permissions_policy='' の下で capture.manuals.show 応答に Permissions-Policy が付かない (opt-out)
6. 既存 SecurityHeadersTest 非退行

### 実装モード: standalone (3 ファイルに閉じた独立変更)

---

## 関連する現行コード

### app/Http/Middleware/SecurityHeaders.php (抜粋 L25-69)
```php
public function handle(Request $request, Closure $next): Response
{
    $response = $next($request);

    if ($request->is('.well-known/oauth-*')) {
        return $this->applyMetadataSubset($response);
    }

    $response->headers->set('X-Frame-Options', 'DENY');
    $response->headers->set('X-Content-Type-Options', 'nosniff');
    $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');

    // Permissions-Policy は常時送出。null / 空文字は opt-out
    $permissionsPolicy = config('security.permissions_policy');
    if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
        $response->headers->set('Permissions-Policy', $permissionsPolicy);
    }

    if (config()->boolean('security.hsts.enabled')) {
        $response->headers->set('Strict-Transport-Security', $this->buildHstsValue());
    }

    if (config()->boolean('security.csp.enabled')) {
        $directives = config()->array('security.csp.directives');
        if (GoogleTagManager::isEnabled()) {
            $directives = array_merge($directives, config()->array('security.csp.gtm_directives'));
        }
        $parts = [];
        foreach ($directives as $directive => $value) {
            if (is_string($directive) && is_string($value)) {
                $parts[] = trim($directive.' '.$value);
            }
        }
        if ($parts !== []) {
            $response->headers->set('Content-Security-Policy', implode('; ', $parts));
        }
    }

    return $response;
}
```

### routes/web.php (capture group L477-505)
```php
Route::middleware(['require-active-subscription', 'project.in-route-org'])
    ->prefix('app')->as('capture.')->group(function (): void {
        Route::get('/', [CaptureManualController::class, 'home'])->name('home');
        Route::get('/csrf-cookie', fn () => response()->noContent())->name('csrf-cookie');
        Route::get('/projects/{project}/manuals', [CaptureManualController::class, 'index'])->name('manuals.index');
        Route::scopeBindings()->group(function (): void {
            Route::get('/projects/{project}/manuals/{manual}', [CaptureManualController::class, 'show'])->name('manuals.show');
            // ... takes.* (POST/PATCH/DELETE, XHR JSON) ...
        });
    });
```

### 既存テスト SecurityHeadersTest.php (L9-20)
```php
test('全レスポンスに baseline セキュリティヘッダが付く', function (): void {
    $response = $this->get('/');
    $response->assertHeader('Permissions-Policy',
        'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")');
});
```

以上をレビューし、施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
