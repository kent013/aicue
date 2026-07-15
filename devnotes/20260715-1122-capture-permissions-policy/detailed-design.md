# 詳細設計: capture-permissions-policy

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項
1. テストなしの実装完了報告（不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き（DTO / JsonResource / Inertia）
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

### セキュリティ不変条件
tenant キー不信 / 子は親に属する (認可より前に 404) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性 / 外部 URL 取得は SSRF 検査経由。

### コーディングルール
- PHPStan level 10 必須（`composer phpstan`）
- Pest（`composer test`）、RefreshDatabase + `--parallel`（グローバル適用、個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く（Service 委譲）
- コードフォーマット: `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（概念設計 APPROVED / conceptual-review-round-3）

## 背景（要約）

`config/security.php` の `permissions_policy` 既定は `geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")`。`SecurityHeaders` middleware がこれを全 web レスポンスに常時送出する。撮影 recorder (`resources/js/components/features/capture/CameraRecorder.svelte` L177-179) は `getUserMedia({ video, audio: true })` を要求するが、`camera=()` / `microphone=()` は空 allowlist（self すら不許可）のため、Permissions-Policy で許可されない kind が 1 つでも含まれると getUserMedia 全体が reject され、**本番の実機でも撮影が起動できない**（F-1-04）。

recorder を描画するのは `pages/Capture/Show.svelte`（route `capture.manuals.show`）のみ（grep 確認済み）。よって**この撮影 document route に限り** camera/microphone を `(self)` に緩め、他ルート（非 capture・capture 内の非 recorder・未解決 404）は厳格値を維持する（least-privilege）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| T1 | config に capture 用 Permissions-Policy 値と route allowlist を追加 | `config/security.php` | High |
| T2 | SecurityHeaders で撮影 document route のみ capture 用値を送出 | `app/Http/Middleware/SecurityHeaders.php` | High |
| T3 | Feature テスト（capture 緩和 / 非対象維持 / fail-secure / opt-out / 回帰） | `tests/Feature/Security/SecurityHeadersTest.php` | High |

---

## T1. config に capture 用 Permissions-Policy 値と route allowlist を追加

### 変更箇所
- ファイル: `config/security.php`（`permissions_policy` 定義の直後、L31-34 付近）

### 波及変更
- TypeScript 型定義: なし（サーバ側 config のみ）
- API Resource/DTO: なし（レスポンス body 不変。HTTP ヘッダのみ）
- テストファイル: T3 で参照

### 現行コード
```php
'permissions_policy' => env(
    'SECURITY_PERMISSIONS_POLICY',
    'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
),
```

### 変更後コード
```php
'permissions_policy' => env(
    'SECURITY_PERMISSIONS_POLICY',
    'geolocation=(), microphone=(), camera=(), payment=(self "https://js.stripe.com")',
),

/*
| 撮影 document route 専用の Permissions-Policy override。撮影 recorder (CameraRecorder) が
| getUserMedia({ video, audio: true }) を要求するため camera/microphone を (self) に緩める
| (同一オリジン PWA のみ許可 = v1 スコープ)。geolocation / payment 等の他 directive は baseline
| と同一に保つ。env 上書き可。null / 空文字でヘッダ非送出 (opt-out, env による一時 rollback)。
*/
'capture_permissions_policy' => env(
    'SECURITY_CAPTURE_PERMISSIONS_POLICY',
    'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")',
),

/*
| capture 用 Permissions-Policy を適用する route 名 allowlist (least-privilege)。
| Permissions-Policy は document 単位に効くため、recorder を描画する撮影 document route のみ
| 緩和し、他の capture HTML document (一覧等) や未解決 404 は baseline (厳格値) のままにする。
| 将来撮影画面が増えたら本 allowlist へ明示追加する (追加はレビュー対象になる)。
*/
'capture_permissions_policy_routes' => ['capture.manuals.show'],
```

### PHPStan適合チェック
- [x] 追加は config 配列リテラルのみ。型影響なし
- [x] allowlist は静的 `list<string>` リテラル（env 由来の緩い型を混ぜない）

### テスト計画
- T3 で config 値経由の挙動を検証

### リスク
- `capture_permissions_policy` の値が `permissions_policy` と drift する可能性 → T3 の回帰テスト（geolocation/payment 不変、camera/microphone のみ差分）で固定。

---

## T2. SecurityHeaders で撮影 document route のみ capture 用値を送出

### 変更箇所
- ファイル: `app/Http/Middleware/SecurityHeaders.php`（L38-42 の Permissions-Policy 送出部＋新規 private helper）

### 波及変更
- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Feature/Security/SecurityHeadersTest.php`（T3）

### 現行コード
```php
// Permissions-Policy は常時送出。null / 空文字は opt-out (非送出、env で一時 rollback)
$permissionsPolicy = config('security.permissions_policy');
if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
    $response->headers->set('Permissions-Policy', $permissionsPolicy);
}
```

### 変更後コード
```php
// Permissions-Policy は常時送出。撮影 document route (config allowlist に一致) のみ
// camera/microphone を (self) に緩めた capture 用値を送る。null / 空文字は opt-out (非送出)。
$permissionsPolicy = $this->resolvePermissionsPolicy($request);
if (is_string($permissionsPolicy) && $permissionsPolicy !== '') {
    $response->headers->set('Permissions-Policy', $permissionsPolicy);
}
```

新規 private helper（`applyMetadataSubset` の近くに追加）:
```php
/**
 * 送出する Permissions-Policy 値を決める。
 *
 * 撮影 document route (security.capture_permissions_policy_routes の allowlist に一致) では
 * camera/microphone を (self) に緩めた capture 用値 (security.capture_permissions_policy) を、
 * それ以外は baseline 値 (security.permissions_policy) を返す。null / 空文字は呼び出し側で
 * opt-out (非送出) として扱う。allowlist が空、または route 未解決 (例: /app 配下の 404) では
 * routeIs が false となり baseline に落ちる (fail-secure)。
 */
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

### 設計上の判断
- **判定タイミング**: `SecurityHeaders` は web group の `append`（`bootstrap/app.php` L82-90）に登録され `$next($request)` 実行後に走るため、route は解決済みで `$request->routeIs()` が正しく評価できる。
- **allowlist 外 = baseline / 空 allowlist**: `$captureRoutes !== []` guard + `routeIs()` の false で baseline に落ちる（camera を余計に開かない）。`routeIs()` を空 variadic で呼ぶと常に false のため guard は二重防御。allowlist 外の matched route は全て baseline（T3 の 2・3 で担保）。
- **404 応答の扱い（実測で確定）**: `SecurityHeaders` は web group で `SubstituteBindings` より内側 (append) にある。したがって **binding 失敗 404**（matched な show route で存在しない manual id）は `SubstituteBindings` が `$next()` 前に `ModelNotFoundException` を投げ `SecurityHeaders` に到達しない → Permissions-Policy なし。**未マッチ 404** も web group 非起動で Permissions-Policy なし。つまり **404 には capture 緩和が一切漏れない（fail-safe）**。SecurityHeaders は正常にレスポンスが返る（例外で短絡しない）経路でのみ適用され、その場合 allowlist 外 matched route は baseline（T3 の 2・3）、allowlist 一致は capture 値（T3 の 1）。

#### 検証ログ（middleware 順序の実測）
一時的な probe テストで現行挙動を実測（コミットせず削除済み）:
- 撮影 show の 200 応答: `Permissions-Policy` = baseline（現行値。改修後は capture 値になる）→ SecurityHeaders は 200 で適用される
- 撮影 show の binding 失敗 404: `Permissions-Policy` = **null（ヘッダなし）** → SecurityHeaders は binding 失敗時に到達しない
- `/app/nonexistent` 未マッチ 404: `Permissions-Policy` = **null（ヘッダなし）**
この実測により「binding 失敗 404 → capture 値」「未マッチ 404 → baseline」という前ラウンドの記述は誤りと確定し、上記へ訂正した。
- **opt-out contract 踏襲**: helper が返す `?string` を既存と同じ `is_string() && !== ''` で narrow。`SECURITY_CAPTURE_PERMISSIONS_POLICY=''` で capture でも非送出（rollback）。
- **他ヘッダ不変**: CSP / HSTS / X-Frame-Options / metadata subset のロジックは無改変。

### PHPStan適合チェック
- [x] 戻り値の型が明示（`?string`）
- [x] `config()->array(...)` を `array_filter(is_string(...))` + `array_values` で `list<string>` に narrow（`routeIs(...)` の `string ...$patterns` に適合）
- [x] `config($key)` の `mixed` を `is_string()` で narrow してから返す（配列返却なし）
- [x] null 安全（route 未解決でも `routeIs` が false、例外なし）

### テスト計画
- 再現/新規テストは T3 に集約
- 個別 `DatabaseTransactions` は使わない（グローバル RefreshDatabase）

### リスク
- 撮影以外のルートに誤って緩和が漏れる → allowlist を config に固定し T3 の非対象テスト（`/`・`capture.manuals.index`・`/app` 404）で厳格値維持を検証。
- 将来撮影画面追加時に allowlist 追加を忘れると当該画面で撮影不能 → コメントで明示 + allowlist 集中管理。

---

## T3. Feature テスト

### 変更箇所
- ファイル: `tests/Feature/Security/SecurityHeadersTest.php`（既存テストに追記）

### 波及変更
- 既存テスト L9-20「全レスポンスに baseline セキュリティヘッダが付く」は `/`（非 capture）を検証 → **非退行**（変更不要）。

### テスト内容（Pest / Factory 生成 / RefreshDatabase グローバル）

撮影 document route のセットアップは既存 `tests/Feature/Capture/CaptureManualBrowsingTest.php` と同型:
`createOrganizationWithOwner()` → `Project::factory()->forOrganization()` → `VideoManual::factory()->forProject()->create(['status' => 'ready'])` → `actingAs($owner)->get("/app/projects/{$project->id}/manuals/{$manual->id}")`。

1. **撮影 document は camera/microphone を (self) に緩める**
   - `capture.manuals.show` の GET 応答 `Permissions-Policy` に `camera=(self)` と `microphone=(self)` を含む
   - 併せて `geolocation=()`・`payment=(self "https://js.stripe.com")` も含む（他 directive 不変 = drift 検出）
   - アサートは完全一致（`assertHeader('Permissions-Policy', 'geolocation=(), microphone=(self), camera=(self), payment=(self "https://js.stripe.com")')`）
2. **非 capture ルートは厳格値を維持**（既存 L16-19 が `/` で担保 = 非退行。明示追加は任意）
3. **capture 内の非 recorder ルートは厳格値を維持**（least-privilege）
   - `capture.manuals.index`（`/app/projects/{project}/manuals`）の応答 `Permissions-Policy` が `camera=(), microphone=()` を含む baseline 値
4. **404 応答には Permissions-Policy が一切付かない（緩和の漏れなし）**
   - `actingAs($owner)->get("/app/projects/{$project->id}/manuals/999999999")`（scopeBindings で存在しない
     manual id → 404）は `assertNotFound()` かつ **`assertHeaderMissing('Permissions-Policy')`**。
   - 理由（実測で確認済み。下記「検証ログ」参照）: `SecurityHeaders` は web group で `SubstituteBindings`
     より**内側 (append)**。binding 失敗時 `SubstituteBindings` は `$next()` 呼び出し前に
     `ModelNotFoundException` を投げるため `SecurityHeaders` に到達せず、ヘッダは付かない。未マッチ 404
     （`/app/nonexistent`）も web group 自体が起動せず同様にヘッダなし。よって capture 緩和が error 応答に
     漏れることはない（fail-safe）。route null での baseline fallback という当初の記述は誤りのため撤回する。
5. **capture 用 config が空文字（opt-out）なら非送出**
   - `config()->set('security.capture_permissions_policy', '')` の下で `capture.manuals.show` 応答に `Permissions-Policy` が付かない（`assertHeaderMissing`）
6. **allowlist の非文字列要素は無視される（型安全 fail-safe）**
   - `config()->set('security.capture_permissions_policy_routes', ['capture.manuals.show', 123, null])` の下でも
     `capture.manuals.show` は capture 値、非 recorder（`capture.manuals.index`）は baseline。
     `array_filter(is_string(...))` narrowing が非文字列を落とすことを固定。
7. **既存 SecurityHeadersTest の非退行**（baseline / CSP / HSTS / metadata subset は無改変で green）

### PHPStan適合チェック
- [x] テストは Factory 生成（`Model::create()` 手組みなし）
- [x] `actingAs` + 既存 helper（`createOrganizationWithOwner`）流用

### リスク
- capture show の 200 化に必要な前提（project 所属・subscription 等）は既存 CaptureManualBrowsingTest と同条件で満たせる（同 helper 使用）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `config/security.php` + `SecurityHeaders.php` + `SecurityHeadersTest.php` の 3 ファイルに閉じた独立変更。他施策と共有面がなく、単独 PR で完結する。 |
| 競合リスク | 低。SecurityHeaders / security config を触る並行作業がなければ衝突しない。 |

## 使命・禁止事項チェック（最終）

- 使命寄与: 本番で塞がっていた PWA ナビ撮影（中核機能）を回復。○
- 禁止事項: `response()->json()` 直書きなし（ヘッダのみ、body 不変）/ PHPStan widen なし / テスト必須を T3 で充足 / 既存テスト削除なし。○
- セキュリティ不変条件: cross-origin を開かず（`(self)` のみ）、緩和は撮影 document 1 route に限定（least-privilege）、非対象・fail-secure をテストで固定。○
