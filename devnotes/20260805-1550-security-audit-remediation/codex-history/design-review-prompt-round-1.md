【アプリの使命 (North Star)】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【セキュリティ不変条件(アプリ都合で緩めない)】

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）

【本件の背景】
多角監査 (セキュリティ観点) で検出された欠陥の是正。監査レポートは
`devnotes/20260805-1600-audit-cycle-2/security.md`、概念設計は
`devnotes/20260805-1550-security-audit-remediation/conceptual-design.md` (APPROVED)。
リポジトリのファイルは読んでよい。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. DESIGN.md準拠 / Atomic Design準拠 (本件は frontend 変更を含まないため該当なしなら明示)

【特に厳しく見てほしい点】
- Laravel の middleware priority list の挙動理解 (appendToPriorityList / prependToPriorityList /
  SortedMiddleware) が正しいか。設計が主張する最終実行順は本当にそうなるか
- `Route::scopeBindings()` を belongsToMany (`Organization::users()`) に適用する S3-a が
  実際に動くか (pivot の id 列との曖昧参照、resolveChildRouteBindingQuery の field 修飾)
- `config('trustedproxy.proxies')` fallback に乗せる S5 が Laravel 12 で本当に効くか。
  config:cache・テスト環境・`TrustProxies::at()` 未呼び出しの前提が正しいか
- S4 の Architecture テストが実装可能な粒度になっているか (74 route / 全解決済み middleware の
  分類が現実的か、偽陰性が入り込む余地がないか)
- 既存テストを壊す箇所の洗い出しが十分か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: security-audit-remediation

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用済み、`--parallel` 実行。
  個別 `DatabaseTransactions` 使用禁止
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `composer fix`（Pint）
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260805-1550-security-audit-remediation/conceptual-design.md` (APPROVED / Round 5)
- 監査レポート: `devnotes/20260805-1600-audit-cycle-2/security.md`

## 唯一の順序契約（設計全体の正本）

```
[global]  TrustProxies → RedirectToHttps → TrustHosts → ...
[web]     EncryptCookies → StartSession → ShareErrors → PreventRequestForgery
            → Authenticate → AuthenticateSession
            → SubstituteBindings
            → EnsureProjectBelongsToCurrentOrganization      ← テナント境界 404
            → HandleInertiaRequests → SecurityHeaders
            → RequireTwoFactorForEnforcedOrganizations → BlockTwoFactorDisable
            → NoStoreCacheHeaders → EncryptHistory
            → EnsureEmailIsVerified → RequireActiveSubscription → (recent-auth 等)
            → controller
[api]     Authenticate → ThrottleRequests
            → ResolveApiActor                                 ← binding より前 (401/403)
            → SubstituteBindings
            → EnsureProjectBelongsToApiOrganization           ← テナント境界 404
            → RequireApiKeyAbility                            ← 403
            → IdempotentRequest → controller
```

> **不変条件**: `SubstituteBindings` と テナント guard の間に、3xx/4xx で短絡する middleware を置かない。

Laravel 既定の `$middlewarePriority`
(`vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php:103-115`) は
`... AuthenticatesRequests → ThrottleRequests → ThrottleRequestsWithRedis →
AuthenticatesSessions → SubstituteBindings → Authorize` であり、
S2 の 3 行を足すと最終的に

```
... AuthenticatesSessions
  → ResolveApiActor
  → SubstituteBindings
  → EnsureProjectBelongsToApiOrganization
  → EnsureProjectBelongsToCurrentOrganization
  → Authorize
```

となる (挿入位置の算術は `Kernel::addToMiddlewarePriorityRelative()` で確認済み。
appends → prepends の順に適用されるため、鎖状 append の anchor は解決可能)。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | API v1 の ability / テナント guard 順序反転 | `routes/api.php`, `app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php`, `app/Http/Middleware/ResolveApiActor.php`(docblock), `bootstrap/app.php`(コメント) | High |
| S2 | テナント guard を priority list で pin | `bootstrap/app.php` | High |
| S3 | 組織/プロジェクトメンバー route の `{user}` 解決を binding 段へ | `routes/web.php`, `app/Http/Controllers/Projects/ProjectMemberController.php`, `app/Http/Routing/RouteBindingTypes.php` | High |
| S4 | 順序不変条件の Architecture テスト新設 + 分類 inventory 拡張 | `app/Enums/Security/NestedRouteDefenseMode.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php`, `tests/Architecture/TenantBoundaryOrderingTest.php`(新), `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` | High |
| S5 | `trustProxies` の env allowlist 化 | `bootstrap/app.php`, `config/trustedproxy.php`(新), `app/Support/TrustedProxiesConfigValidator.php`(新), `app/Support/ProductionEnvGuard.php`, `.env.example`, `docs/trusted-proxies-runbook.md`(新) | High |
| S6 | `RedirectToHttps` を `TrustProxies` の後ろへ | `bootstrap/app.php` | Medium |
| S7 | passkey 増減の監査記録 | `app/Enums/SecurityEventType.php`, `app/Listeners/RecordSecurityEvent.php` | Medium |
| S8 | `passkey.destroy` の throttle | `app/Providers/PasskeyServiceProvider.php` | Medium |

**実装順序**: S1 → S2 → S3 → S4（S4 は S1-S3 の完了後でないと green にならない）→ S5 → S6 → S7 → S8。

---

## S1: API v1 の ability / テナント guard 順序反転

### 変更箇所

- `routes/api.php` L12-48 (ヘッダコメント), L68-70 (read group), L85-87 (write group)
- `app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php` L28-31 (順序契約 docblock)
- `app/Http/Middleware/ResolveApiActor.php` L39-40 (順序契約 docblock)
- `bootstrap/app.php` L146-157 (alias コメント)

### 波及変更

- TypeScript 型定義: **なし** (API のエラー envelope 形状は不変)
- API Resource/DTO: **なし** (`ApiErrorResource` / `ApiError` は不変)
- テストファイル: `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` (S4 で扱う),
  `tests/Feature/Api/V1/ItemAuthorizationTest.php` (新規ケース追加),
  `tests/Feature/Api/ApiKeyTest.php` / `tests/Feature/Api/OAuthDualGuardTest.php`
  (自組織 project に対する 403 は不変 = 変更不要だが回帰確認対象)
- CLI (`packages/cli`): **なし** (自組織の project id しか扱わない)

### 現行コード

```php
// routes/api.php L68-70
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read',
        'api.project-in-org'])

// routes/api.php L85-87
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write',
        'api.project-in-org', 'idempotent'])
```

### 変更後コード

```php
// 読み取り (read ability)
// 順序契約: テナント境界の 404 (api.project-in-org) は ability の 403 より **前**。
// 逆順だと read-only キーで write route を叩いたとき「他組織に実在 = 403 /
// 不在 = 404」と分岐し、project id の存在オラクルになる (audit-cycle-2 High-1)。
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor',
        'api.project-in-org', 'api-key.ability:read'])

// 書き込み (write ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor',
        'api.project-in-org', 'api-key.ability:write', 'idempotent'])
```

ヘッダコメント L21 の順序図を、§唯一の順序契約 の api 行に置換する。
`EnsureProjectBelongsToApiOrganization` の docblock L28-31 も同様に置換し、
「**この順序は宣言順ではなく `bootstrap/app.php` の priority list が正本**」を追記する。

`ResolveApiActor` の docblock L39-40 は
「順序契約: auth → throttle → **resolve.api-actor → SubstituteBindings** →
api.project-in-org → api-key.ability:X → (idempotent) → controller」に更新し、
「本 middleware は route binding に依存しない (`$request->route(...)` を読まない)。
この性質が `SubstituteBindings` より前に置ける根拠であり、
`TenantBoundaryOrderingTest` が静的検査で固定する」を追記する。

### PHPStan適合チェック

- [x] 型変更なし (route 宣言と docblock のみ)
- [x] `response()->json()` 直書きなし

### テスト計画

- [ ] **先に red を確認**: 下記「新規テスト 1」を現行コードで走らせて fail することを確認してから着手
- [ ] 新規テスト 1 (`tests/Feature/Api/V1/ItemAuthorizationTest.php`):
      `read のみの API キーで write route を叩くと、cross-org 実在 project と不在 project id が完全に同一応答`
      — `POST /api/v1/projects/{crossOrg}/items` と `POST /api/v1/projects/999999999/items` の
      status と `json()` が完全一致 (どちらも 404 `not_found`)、かつヘッダ差分なし
- [ ] 新規テスト 2: `write のみの API キーで read route を叩いても同一 404`
      (`GET /api/v1/projects/{crossOrg}/items` vs 不在 id)
- [ ] 新規テスト 3: `自組織 project + ability 不足は 403 insufficient_ability のまま`
      (エラー契約の維持。read-only キー × 自組織 project への POST)
- [ ] 新規テスト 4: `OAuth CLI トークン (read scope のみ) でも同じ 3 ケースが成立する`
- [ ] 既存テスト `ApiKeyTest.php:122-134` / `OAuthDualGuardTest.php:107-122` が green のまま
      (自組織 project に対する `insufficient_ability` は不変)
- [ ] 既存テスト `ItemAuthorizationTest.php:264` (cross-org と不在の同一応答) が green のまま

### リスク

- `read` のみのキーで cross-org project を叩いていたクライアントは 403 → 404 になる。
  正当なクライアントには発生しない (概念設計 §API クライアントへの影響)。
- `api.project-in-org` が ability より前に走るため、ability 不足リクエストでも
  組織解決の 1 クエリが走る。throttle はさらに前段のため DoS 面の悪化はない。

---

## S2: テナント guard を priority list で pin

### 変更箇所

- `bootstrap/app.php` L166-171 (`appendToPriorityList` の直後に追記)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` の
  「priority を導入していないので宣言順 = 実行順」という docblock 前提が**偽になる**ため書き換え必須 (S4)

### 現行コード

```php
// bootstrap/app.php L168-171
$middleware->appendToPriorityList(
    AuthenticatesRequests::class,
    McpConsentOrganizationBinder::class,
);
```

### 変更後コード

```php
$middleware->appendToPriorityList(
    AuthenticatesRequests::class,
    McpConsentOrganizationBinder::class,
);

/*
 | テナント境界 404 の位置を priority list で確定させる (audit-cycle-2 High-1)。
 |
 | 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
 | guard より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
 | 応答 / 不在 = 404」という 1 bit の存在オラクルになる (課金ゲート 402/302・
 | verified 302・2FA 強制 302・Inertia version mismatch 409・ability 403 が該当した)。
 |
 | 対処は 2 段:
 |   1. ResolveApiActor を **binding より前**へ。actor 解決失敗 (401/403) を
 |      「不在 404 がまだ起きていない時点」で返す。同 middleware は route binding に
 |      依存しない ($request->route(...) を読まない) ため前倒し可能。
 |   2. テナント guard を **binding の直後**へ。以降のすべての短絡より前になる。
 |
 | 副作用: guard が 404 で短絡すると内側 (HandleInertiaRequests / SecurityHeaders /
 | NoStoreCacheHeaders / EncryptHistory) は走らない。これは binding 失敗 404 と同じ
 | 扱いであり、既存契約 (SecurityHeadersTest「binding 失敗 404 には Permissions-Policy が
 | 一切付かない」) と一致する = 不在と cross-org が応答ヘッダまで同一になる。
 |
 | 順序の実測は TenantBoundaryOrderingTest が解決後の middleware 列で固定する。
 */
$middleware->prependToPriorityList(
    SubstituteBindings::class,
    ResolveApiActor::class,
);
$middleware->appendToPriorityList(
    SubstituteBindings::class,
    EnsureProjectBelongsToApiOrganization::class,
);
$middleware->appendToPriorityList(
    EnsureProjectBelongsToApiOrganization::class,
    EnsureProjectBelongsToCurrentOrganization::class,
);
```

`use Illuminate\Routing\Middleware\SubstituteBindings;` を import に追加する。

### PHPStan適合チェック

- [x] `appendToPriorityList(array|string $after, string $append)` / `prependToPriorityList` の
      引数型は class-string で満たす
- [x] 戻り値未使用 (fluent) — 既存 `appendToPriorityList` 呼び出しと同形

### テスト計画

S4 の `TenantBoundaryOrderingTest` で以下を固定する (解決後 = `Router::gatherRouteMiddleware()`)。

- [ ] `api.v1.projects.items.store` の**完全な middleware 列**
      (API キー actor / OAuth actor の両方でルート解決は同一のため列は 1 本。
      両 actor で実際の応答も Feature テストで検証する)
- [ ] `api.v1.projects.items.index` (read group)
- [ ] `api.v1.me` / `api.v1.projects.index` — `{project}` を持たない同一 group の route で
      guard が列に載っていても no-op であること (Feature テストで 200 を確認)
- [ ] `projects.update` — `EnsureProjectBelongsToCurrentOrganization` が
      `EnsureEmailIsVerified` / `RequireActiveSubscription` /
      `RequireTwoFactorForEnforcedOrganizations` / `HandleInertiaRequests` より前
- [ ] `capture.manuals.show`
- [ ] `organizations.settings` — guard を持たない web route の列が変化しないこと
- [ ] Feature: メール未確認ユーザー / 未契約組織ユーザー / 2FA 強制未準拠ユーザーの 3 状態 ×
      (cross-org 実在 project / 不在 project id) が**すべて同一 404**
- [ ] Feature: 同 3 状態 × 自組織 project では**従来どおりの 302 着地**
      (課金ゲートの「行き先のない詰みを作らない」契約の維持)

### リスク

- cross-org 404 応答に `SecurityHeaders` / `NoStore` / `EncryptHistory` が付かなくなる。
  → 既存契約と一致 (`SecurityHeadersTest.php:163-171`)。**新規テストで明示的に固定**する。
- priority list への追加は全 route に影響しうる。ただし priority list は
  「その route に実在する middleware の相対順序」しか変えないため、
  guard を持たない route には無影響 (テストで固定)。
- `EnsureProjectBelongsToCurrentOrganization` が `verified` より前に走るため、
  `current_organization_id` が null のユーザーは `{project}` route で 404 になる。
  変更前も `RequireActiveSubscription` が素通しして同じ 404 に落ちていたため挙動不変
  (`RequireActiveSubscription.php:73-75` + `ResolvesCurrentOrganization::resolveCurrentOrganization`)。

---

## S3: メンバー route の `{user}` 解決を binding 段へ

`{user}` はグローバル implicit binding (`RouteBindingTypes::BIGINT['user'] => User::class`) のため
**不在 id は binding で 404 / 実在の非メンバーは controller まで到達**という非対称が残る。
S2 の pin はテナント guard を持つ route にしか効かないため、個別に閉じる。

### S3-a: `organizations.members.*` を `scopeBindings()` 化

#### 変更箇所

- `routes/web.php` L229-238 (3 route)
- `tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory (S4 で扱う)

#### 現行コード

```php
Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
    ->name('organizations.members.update');
Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
    ->name('organizations.members.destroy');
Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
    ->middleware('recent-auth')
    ->name('organizations.members.two-factor.reset');
```

#### 変更後コード

```php
/*
| {user} は scopeBindings で $organization->users() 経由に解決する。
| 非メンバー / 不在 id は **binding 段で等しく 404** になり、recent-auth (302/409) を
| 含む binding 後のどの短絡 middleware よりも前に閉じる (audit-cycle-2 High-1 横断)。
| controller の inline guard (resolveOrganizationMember) は二重防御として残す。
*/
Route::scopeBindings()->group(function (): void {
    Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
        ->name('organizations.members.update');
    Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
        ->name('organizations.members.destroy');
    Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
        ->middleware('recent-auth')
        ->name('organizations.members.two-factor.reset');
});
```

**実装根拠**: Laravel の `Model::resolveChildRouteBindingQuery()` は
関係が `BelongsToMany` のとき field を `users.id` に**修飾する**ため、
`organization_user` pivot が `id` 列を持っていても曖昧参照エラーにならない。
子 relation 名は `Str::plural('user')` = `users()` で、`Organization::users()`
(`app/Models/Organization.php:106-109`) が実在する。
親 `{organization:slug}` は `MembershipScopedOrganizationBinder` の explicit binder が
引き続き担当する (scopeBindings は子解決のみに作用)。

### S3-b: `projects.members.destroy` を手動解決へ

`{user}` の意味的な親は `{project}` ではなく**現在組織**であり、
`Str::plural('user')` に対応する `Project::users()` は存在しない
(あるのは `Project::members()` = 明示メンバーのみで、意味が狭くなる)。
そこで scopeBindings ではなく**手動解決** (`{notification}` と同じ既存パターン) を採る。

#### 変更箇所

- `app/Http/Controllers/Projects/ProjectMemberController.php` L67-79
- `app/Http/Routing/RouteBindingTypes.php` の手動解決 exclusion 登録
- `routes/web.php` の該当 route (コメント追記のみ)

#### 現行コード

```php
public function destroy(Request $request, Project $project, User $user): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project);
    abort_unless(
        $organization->users()->whereKey($user->getKey())->exists(),
        404,
    );
    Gate::authorize('update', $project);

    $project->members()->detach($user->getKey());

    return back()->with('success', 'プロジェクトメンバーを削除しました');
}
```

#### 変更後コード

```php
/**
 * メンバー削除 (explicit member の detach。暗黙メンバー = org owner/admin は対象外)。
 *
 * {user} は **implicit binding を使わない** (action 引数を string で受ける)。
 * implicit binding だと「不在 id = binding 段の 404 / 実在の非メンバー = 後段 middleware の
 * 302/402」という差分が users.id の存在オラクルになるため、
 * 組織メンバーに閉じた relation から手動解決して両者を同一の 404 に落とす。
 * 型制約 (数値・18 桁上限) は RouteBindingTypes の pattern が担保する。
 */
public function destroy(Request $request, Project $project, string $user): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404
    $this->resolveOrganizationProject($organization, $project);

    /** @var User $member 組織メンバー以外・不在 id はここで等しく 404 */
    $member = $organization->users()->whereKey($user)->firstOrFail();

    Gate::authorize('update', $project);

    $project->members()->detach($member->getKey());

    return back()->with('success', 'プロジェクトメンバーを削除しました');
}
```

`RouteBindingTypes` の「controller が implicit binding を使わず手動解決する param」
inventory に `projects.members.destroy` × `user` を登録する (route identity 単位)。

### PHPStan適合チェック

- [x] `firstOrFail()` は `BelongsToMany<User, Organization>` から `User` を返す (generics 通過)
- [x] action 引数の型が `string` に変わるため、`RouteBindingTypes` の IV-9(a) 検査を
      exclusion 登録で明示的に外す (baseline 化ではなく inventory 登録)
- [x] `whereKey(string)` は許容 (pattern が数値を保証)

### テスト計画

- [ ] **先に red を確認**: 下記 Feature テストを現行コードで走らせて fail することを確認
- [ ] Feature (`tests/Feature/Organizations/`): stale recent-auth のユーザーで
      `DELETE /organizations/{org}/members/{存在するが非メンバーの user}/two-factor` と
      `DELETE /organizations/{org}/members/999999999/two-factor` が**同一 404**
- [ ] Feature: 正常系 (メンバー + fresh recent-auth) が従来どおり成功する
- [ ] Feature: `organizations.members.update` / `.destroy` の非メンバー / 不在も同一 404
- [ ] Feature (`tests/Feature/Projects/`): 未契約組織のユーザーで
      `DELETE /projects/{自組織project}/members/{非メンバー user}` と
      `.../members/999999999` が**同一 404** (302/402 ではない)
- [ ] Feature: `projects.members.destroy` の正常系 (明示メンバーの削除) が従来どおり成功
- [ ] Feature: 暗黙メンバー (org owner/admin で pivot 無し) を指定した場合、
      **従来どおり成功応答** (detach は no-op) — 挙動非退行の確認

### リスク

- `scopeBindings()` の子解決は `firstOrFail()` を使うため、
  非メンバー指定時の例外が `ModelNotFoundException` になる (従来は `NotFoundHttpException`)。
  どちらも `errors/4xx` に collapse され web 応答は同一。API 経路は無い。
- `projects.members.destroy` の action 引数型変更は `RouteBindingTypes` の
  Architecture テスト (IV-9(a)) に触れる。exclusion 登録を同一 PR で行う。

---

## S4: 順序不変条件の Architecture テスト新設

### 変更箇所

- `app/Enums/Security/NestedRouteDefenseMode.php` (case 追加)
- `tests/Architecture/NestedRouteIdorDefenseTest.php` (inventory を parameter 単位へ、母集団を 1+param へ)
- `tests/Architecture/TenantBoundaryOrderingTest.php` (**新規**)
- `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` (順序契約の反転 + 実行順検査へ)
- `docs/app-integration-guide.md` §7 不変条件 9 / 新規 route チェックリスト

### S4-a: `NestedRouteDefenseMode` の拡張

```php
enum NestedRouteDefenseMode: string
{
    // --- テナント防御モード (子/リソースが親テナントに属することを担保する) ---

    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** Route::bind() の explicit binder が actor スコープで解決 (不整合は binding 段で 404)。 */
    case ScopedBinder = 'scoped_binder';

    /** テナント guard middleware (project.in-current-org / api.project-in-org) が担う。 */
    case TenantGuardMiddleware = 'tenant_guard_middleware';

    /** implicit binding を使わず controller が owner-scoped relation から手動解決する。 */
    case ManualOwnerScopedResolution = 'manual_owner_scoped_resolution';

    /** route-model binding + inline 親子整合 guard (authorize より前に検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';

    // --- 非テナントモード (テナント防御の対象ではないことを明示宣言する) ---

    /** リソース id ではない param (provider / intent / token / path 等)。 */
    case NonResourceParameter = 'non_resource_parameter';

    /** テナントに属さない公開リソース。 */
    case PublicGlobalResource = 'public_global_resource';

    /** テナント防御モードか (順序不変条件の検査対象か)。 */
    public function isTenantDefense(): bool
    {
        return match ($this) {
            self::NonResourceParameter, self::PublicGlobalResource => false,
            default => true,
        };
    }
}
```

### S4-b: `NestedRouteIdorDefenseTest` の inventory を parameter 単位へ

母集団を `count(parameterNames()) >= 2` から **`>= 1`** に広げ、
inventory の値を `array<parameter名, NestedRouteDefenseMode>` にする。
vendor prefix 除外に `cashier.` を追加する (`cashier.payment` は Cashier 所有)。

登録内容 (param 名 → モード) の骨子:

| param | モード | 根拠 |
|---|---|---|
| `project` | `TenantGuardMiddleware` | `project.in-current-org` / `api.project-in-org` が binding 直後に走る (S2) |
| `organization` | `ScopedBinder` | `MembershipScopedOrganizationBinder` |
| `passkey` | `ScopedBinder` | `SelfScopedPasskeyBinder` |
| `notification` | `ManualOwnerScopedResolution` | controller が `$user->notifications()` から解決 (implicit binding なし) |
| `user` (organizations.members.*) | `ScopeBindings` | S3-a |
| `user` (projects.members.destroy) | `ManualOwnerScopedResolution` | S3-b |
| `item` / `category` / `manual` / `cut` / `take` / `analysisJob` / `renderJob` / `apiKey` / `oauthSession` / `invitation` | `ScopeBindings` | 既存 |
| `provider` / `intent` / `token` / `path` / `userId` / `id` / `hash` | `NonResourceParameter` | 固定集合・署名付き・非モデル |

`nestedRoutePrefixExemptAllowlist()` は route 単位の逃げ道として残すが、
新方式では param 単位に `NonResourceParameter` を宣言できるため、
既存 2 件 (`social.redirect` / `verification.verify`) は param 単位分類へ移行し
**allowlist は空にする** (思考原則 3: 並走を残さない)。
vendor 所有 route (`cashier.payment` / `mcp.oauth.*.nested` / `storage.local*`) は
候補除外 or `NonResourceParameter` / `PublicGlobalResource` として明示登録する。

### S4-c: `TenantBoundaryOrderingTest` (新規)

```php
/**
 * テナント境界 404 の位置に関する順序不変条件。
 *
 * 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
 * 境界 404 より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
 * 応答 / 不在 = 404」という 1 bit の存在オラクルになる。
 *
 * 本テストは **解決後 (priority 適用後) の実行順** を測る。宣言順 (gatherMiddleware) を
 * 見ていたことが、audit-cycle-2 で実測されるまで穴が見えなかった直接の原因である。
 *
 * 例外機構は設けない (違反は無条件 fail)。将来やむを得ない例外が必要になったら、
 * その時点で設計判断としてテストを変更する。
 */
```

検査 1 — **解決済み middleware の deny-by-default 分類**

```php
/** middleware クラス => 短絡しうるか (由来を問わず全件分類必須) */
function middlewareShortCircuitInventory(): array
{
    return [
        // --- 短絡しうる (3xx/4xx を返して $next を呼ばない分岐を持つ) ---
        Illuminate\Auth\Middleware\Authenticate::class => true,
        Illuminate\Routing\Middleware\ThrottleRequests::class => true,
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => true,
        Illuminate\Session\Middleware\AuthenticateSession::class => true,
        Illuminate\Auth\Middleware\EnsureEmailIsVerified::class => true,
        Inertia\Middleware::class /* HandleInertiaRequests */ => true,
        App\Http\Middleware\RequireActiveSubscription::class => true,
        App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations::class => true,
        App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations::class => true,
        App\Http\Middleware\RequireRecentAuth::class => true,
        App\Http\Middleware\RequireApiKeyAbility::class => true,
        App\Http\Middleware\ResolveApiActor::class => true,
        App\Http\Middleware\IdempotentRequest::class => true,
        App\Http\Middleware\EnsureProjectBelongsToCurrentOrganization::class => true,
        App\Http\Middleware\EnsureProjectBelongsToApiOrganization::class => true,
        // ... (実装時に解決済み列を走査して全件を埋める)
        // --- 透過 ---
        Illuminate\Cookie\Middleware\EncryptCookies::class => false,
        Illuminate\Session\Middleware\StartSession::class => false,
        App\Http\Middleware\SecurityHeaders::class => false,
        // ...
    ];
}
```

- 検査対象 route (= inventory でテナント防御モードが 1 つ以上宣言された route) の
  `Router::gatherRouteMiddleware()` に現れた**全クラス**が map の key にあること。
  無い場合は fail (「新しい middleware は必ず分類する」)。
- 解決後の要素が closure (`Closure`) の場合も fail (分類不能)。

検査 2 — **境界規則**

- `TenantGuardMiddleware` モードの param を持つ route: 解決後の列で
  `index(SubstituteBindings) < index(guard)` かつ
  その間に `ShortCircuits = true` のクラスが存在しないこと。

検査 3 — **inline guard route の制約**

- `UrlIntegrityGuard` モードの param を持つ route: `index(SubstituteBindings)` より後に
  `ShortCircuits = true` のクラスが存在しないこと
  (S3 完了後は該当 route が 0 件になる見込み。0 件でもテストは残す = 将来の再導入を落とす)。

検査 4 — **pre-binding 短絡の性質固定** (概念設計 S4-6)

```php
/** SubstituteBindings より前に走る短絡 middleware => 「生 route parameter を読まない」宣言 */
function preBindingShortCircuitInventory(): array
{
    return [
        Illuminate\Auth\Middleware\Authenticate::class => '認証状態のみで判定。route param を読まない',
        Illuminate\Routing\Middleware\ThrottleRequests::class => 'limiter キーは actor / IP。route param を読まない',
        Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class => 'CSRF token のみ',
        Illuminate\Session\Middleware\AuthenticateSession::class => 'session の password_hash のみ',
        App\Http\Middleware\ResolveApiActor::class => 'api_key attribute / api-oauth guard のみ',
    ];
}
```

- (a) **静的検査**: 各登録クラスのソースに `$request->route(`, `->route()->parameter(`,
  `Route::input(`, `$request->segment(` が出現しないこと
- (b) 未登録の pre-binding 短絡 middleware が現れたら fail
- (c) **限界の明示**: 呼び出し先クラス経由の間接 DB 参照は静的には証明できない。
  実応答の同一性は Feature テスト (下記) が担保する — この二段構えを docblock に書く

検査 5 — **完全順序の pin**

指定 route の解決後 middleware 列を**完全一致**で固定する
(`api.v1.projects.items.store` / `api.v1.projects.items.index` / `api.v1.me` /
`projects.update` / `capture.manuals.show` / `organizations.settings`)。

### S4-d: `ProjectRouteCurrentOrgGuardTest` の書き換え

- 「注意: … priority リストに含まれないため宣言順 = 実行順である」の docblock を削除し、
  「順序の正本は `bootstrap/app.php` の priority list。本テストは解決後の実行順を測る」に置換
- 順序契約表を反転:
  | 破られる契約 | 起きること |
  |---|---|
  | `resolve.api-actor` が `api.project-in-org` **より後** | `organization` attribute 未設定で Assert → 500 |
  | `api-key.ability:*` が `api.project-in-org` **より前** | **ability 不足時に cross-org の実在が 403 で漏れる** |
  | `idempotent` が `api.project-in-org` **より前** | cross-org リクエストで idempotency 行が作られる |
- 判定を `gatherMiddleware()` (alias 文字列) から
  `Router::gatherRouteMiddleware()` (解決後クラス列。`:param` を strip して比較) へ変更

### テスト計画

- [ ] 上記 4 テストが S1-S3 完了後に green、S1-S3 のいずれかを revert すると red になること
      (各施策の完了判定に使う)
- [ ] pre-binding 短絡の**振る舞い検査** (Feature): 各 pre-binding 短絡が発火する状態で
      「実在 project id」と「不在 project id」の応答が完全一致すること
      (未認証 → 302 / throttle 超過 → 429 / CSRF 不一致 → 419 /
       actor 失効 → 401 の 4 状態)

### リスク

- inventory の初期投入が大きい (74 route / 全解決済み middleware)。
  ただし機械的な棚卸しであり、`route:list` から生成できる。
- 分類の初期値を誤ると偽陰性になる。**`ShortCircuits` の既定は true 側に倒す**
  (疑わしきは短絡扱い) ことを docblock に明記する。

---

## S5: `trustProxies` の env allowlist 化

### 変更箇所

- `bootstrap/app.php` L52-59
- `config/trustedproxy.php` (**新規**)
- `app/Support/TrustedProxiesConfigValidator.php` (**新規**)
- `app/Support/ProductionEnvGuard.php` L14-24 (docblock), L104-113 の後に追記
- `.env.example` (security / auth ブロック)
- `tests/Feature/Support/ProductionEnvGuardTest.php` の `beforeEach` baseline
- `tests/Architecture/EnvExampleInvariantTest.php`
- `docs/trusted-proxies-runbook.md` (**新規**) / `docs/auth-security-mechanisms.md`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `ProductionEnvGuardTest` の baseline は**追加必須**
  (追加しないと全 `toHaveCount(1)` アサーションが 2 になり全滅する)

### 現行コード

```php
// bootstrap/app.php L52-59
$middleware->trustProxies(
    at: '*',
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

### 変更後コード

```php
/*
 | LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)。
 |
 | `at:` は **渡さない**。Laravel の TrustProxies は
 |   $this->proxies() ?: config('trustedproxy.proxies')
 | の順で解決し、`TrustProxies::at()` を呼ばなければ config へ落ちる
 | (vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php)。
 | withMiddleware の callback は config 読込より前に走るため `at:` に closure は渡せず
 | (trustHosts と違い array|string|null のみ)、env 由来の allowlist は config 経由が唯一の道。
 |
 | かつて `at: '*'` だった。これは全アドレスを trusted proxy 扱いにするため
 | $request->ip() が XFF 最左 = **クライアントが自由に書ける値**になり、
 | IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化されていた
 | (audit-cycle-2 High-2)。
 |
 | 設定は TRUSTED_PROXIES (config/trustedproxy.php)。production で未宣言なら
 | ProductionEnvGuard が起動時 fail-fast する。運用契約は docs/trusted-proxies-runbook.md。
 */
$middleware->trustProxies(
    headers: Request::HEADER_X_FORWARDED_FOR
        | Request::HEADER_X_FORWARDED_HOST
        | Request::HEADER_X_FORWARDED_PORT
        | Request::HEADER_X_FORWARDED_PROTO,
);
```

#### `config/trustedproxy.php` (新規)

> ファイル名は **framework が参照する固定名** (`config('trustedproxy.proxies')`)。
> 本リポジトリの命名慣行 (`trusted_hosts.php`) とは異なるが、framework の fallback 経路に
> 乗せるため変更しない。この理由を冒頭コメントに明記する。

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted Proxies (client IP / X-Forwarded-Proto の信頼境界)
|--------------------------------------------------------------------------
|
| TRUSTED_PROXIES に **すべての hop** の IP / CIDR を CSV で列挙する。
| hop を 1 つでも取りこぼすと client IP がその hop に固定され、全利用者が
| 1 つの rate limit バケットに落ちる (自己 DoS)。
| CloudFront → ALB のような多段構成では両方の range を列挙すること。
|
| 特別な値:
|   - `none`       : 「プロキシは無い」の明示宣言 (空 list に写す)
|   - `REMOTE_ADDR`: 直接の接続元を信頼 (ローカル開発の Valet TLS 用。production では禁止)
|
| `*` / `**` は **禁止** (全アドレス信頼 = XFF 偽装が通る)。
| 不正値は silent drop ではなく App\Support\TrustedProxiesConfigValidator
| (ProductionEnvGuard 経由) が production 起動時に fail-fast する。
|
*/

$rawProxies = array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')));

return [
    /*
    | framework (Illuminate\Http\Middleware\TrustProxies) が読む正本。
    | **検証を通過した値のみ**。空配列 = 何も信頼しない (= REMOTE_ADDR が client IP)。
    */
    'proxies' => array_values(array_filter(
        $rawProxies,
        fn (string $v): bool => $v === 'REMOTE_ADDR'
            || filter_var($v, FILTER_VALIDATE_IP) !== false
            || preg_match('/^[0-9a-fA-F:.]+\/\d{1,3}$/', $v) === 1,
    )),

    /*
    | 生 token (空要素・空白のみ要素も保持)。config 段で silent drop された値を
    | 起動時 fail-fast で表面化させるために TrustedProxiesConfigValidator が読む。
    | Guard 側で env() を直接読むと config:cache 後に null 化するため config 経由で expose。
    */
    'raw_proxies' => $rawProxies,
];
```

#### `app/Support/TrustedProxiesConfigValidator.php` (新規)

`TrustedHostsConfigValidator` と同形 (final / 純粋クラス / `RuntimeException`)。

```php
/**
 * @param  list<string>  $proxies      検証通過後の proxy 列 (config 通過後)
 * @param  list<string>  $rawProxies   生 token (空白 trim のみ、format validation 前)
 *
 * @throws RuntimeException
 */
public function validateForProduction(array $proxies, array $rawProxies): void
```

検査 (production):

1. `*` / `**` が raw に含まれる → reject (「全アドレス信頼は XFF 偽装が通る」)
2. `REMOTE_ADDR` が raw に含まれる → reject (「production では実 hop の CIDR を列挙する」)
3. `none` が他の token と併記 → reject (曖昧宣言)
4. raw の非空 token が `proxies` に残っていない (= silent drop された) → reject (書式不正の表面化)
5. `proxies === []` かつ raw に `none` が無い → reject (未設定。
   「プロキシが無い構成なら TRUSTED_PROXIES=none を明示せよ」)

#### `ProductionEnvGuard` への配線

```php
// client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
$proxies = $this->stringList(config('trustedproxy.proxies', []));
$rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
try {
    (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}
```

docblock (L14-24) の検査項目一覧に 1 行追加する。

#### `.env.example`

```
# client IP / X-Forwarded-Proto の信頼境界 (config/trustedproxy.php)。
# production では未宣言だと起動時に fail-fast する (ProductionEnvGuard)。
# **すべての hop** の IP / CIDR を CSV で列挙する (CloudFront → ALB なら両方)。
# hop の取りこぼしは client IP をその hop に固定し、全利用者が 1 バケットに落ちる。
# プロキシが無い構成では `none` と明示する。ローカルで Valet TLS 越しに開発する場合は
# `REMOTE_ADDR` (production では禁止)。`*` は使用不可 (XFF 偽装が通る)。
TRUSTED_PROXIES=
```

`.env.testing` / `.env.bughunt.local.example` は**追加しない**
(未設定 = 空 = 何も信頼しない = loopback 直結の実態と一致)。

### PHPStan適合チェック

- [x] `validateForProduction(array $proxies, array $rawProxies): void` に
      `@param list<string>` を明示 (validator 通過前の値を `non-empty-string` と断定しない)
- [x] `config('trustedproxy.proxies', [])` は `mixed` → 既存の `stringList()` で `list<string>` へ正規化
- [x] `filter_var` / `preg_match` の戻り値を bool 比較で扱う (`!== false` / `=== 1`)
- [x] 新規クラスは `final` + `declare(strict_types=1)` + 日本語 docblock

### テスト計画

- [ ] **先に red を確認**: 下記「XFF 偽装」テストを現行コード (`at: '*'`) で走らせて fail を確認
- [ ] Feature (`tests/Feature/Security/TrustedProxiesTest.php` 新規):
      テスト内で `Route::get('/_ip-probe', fn () => response((string) request()->ip()))` を定義し
  - `trustedproxy.proxies = []` のとき `X-Forwarded-For: 9.9.9.9` を送っても
    `ip()` が `9.9.9.9` に**ならない** (= REMOTE_ADDR)
  - `trustedproxy.proxies = ['127.0.0.1/32']` のとき `ip()` が XFF 由来になる
  - 多段 XFF (`1.2.3.4, 10.0.0.5`) で、信頼 hop を取りこぼすと client IP が hop 側になること
    (= runbook の警告が実挙動と一致することの固定)
- [ ] Architecture: `TrustProxies::at()` が呼ばれていないこと
      (`bootstrap/app.php` に `at:` が渡っていない = config fallback が生きている) を
      「config を変えると `ip()` が変わる」という**振る舞い**で固定する
      (静的検査ではなく上記 Feature テストで担保。static prop への依存を作らない)
- [ ] Feature: `config:cache` 相当 (config を配列で上書き) でも fallback が効くこと
- [ ] Unit (`tests/Unit/Support/TrustedProxiesConfigValidatorTest.php` 新規): 5 検査 × 正常系
- [ ] `ProductionEnvGuardTest`: baseline に
      `config(['trustedproxy.proxies' => ['10.0.0.0/8'], 'trustedproxy.raw_proxies' => ['10.0.0.0/8']])` を追加し、
      新規 violation ケース (未設定 / `*` / `REMOTE_ADDR` / 書式不正) を `toHaveCount(1)` で追加
- [ ] `EnvExampleInvariantTest`: `.env.example` に `TRUSTED_PROXIES=` が含まれること
- [ ] Architecture: `docs/trusted-proxies-runbook.md` に placeholder トークン
      (`<!-- OPS-FILL -->`) が残っていたら fail

### リスク

- **production 起動が fail-fast する**。`TRUSTED_PROXIES` を設定せずにデプロイすると起動不能。
  runbook に「env 設定 → デプロイ」の順序と `production:preflight` の必須実行を固定する。
  rollback は `at: '*'` へ戻すことではない (正しい CIDR を設定するまでデプロイしない)。
- ローカル Valet TLS 利用者は `$request->secure()` が false になる。
  `.env.example` に `REMOTE_ADDR` の案内を書く。
- 既存の `security_audit_events.ip_address` は遡及訂正できない (スコープ外・docs に注記)。

---

## S6: `RedirectToHttps` を `TrustProxies` の後ろへ

### 変更箇所

- `bootstrap/app.php` L49-50

### 現行コード

```php
// HTTPS リダイレクトは最外周 (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)
$middleware->prepend(RedirectToHttps::class);
```

### 変更後コード

```php
/*
 | HTTPS リダイレクト (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)。
 |
 | **prepend にしない**: Middleware::getGlobalMiddleware() は
 | array_merge($prepends, $global, $appends) を返すため、prepend すると
 | TrustProxies **より前**に走り、$request->isSecure() が X-Forwarded-Proto を
 | 見られない。LB 終端 + FORCE_HTTPS_REDIRECT=true で 308 の無限ループになる。
 | append することで TrustProxies の後・route group より前で走る。
 */
$middleware->append(RedirectToHttps::class);
```

### PHPStan適合チェック

- [x] 型変更なし

### テスト計画

- [ ] **先に red を確認**: 下記 Feature テストを現行コードで走らせて fail を確認
- [ ] Architecture: `app(Illuminate\Foundation\Http\Kernel::class)->getGlobalMiddleware()` において
      `index(TrustProxies) < index(RedirectToHttps)` かつ
      `RedirectToHttps` が web/api group より前 (= global 配列に含まれる) こと
- [ ] Feature: `security.force_https_redirect = true` + `trustedproxy.proxies = ['127.0.0.1/32']` +
      `X-Forwarded-Proto: https` で **308 が返らない** (現行は 308 ループ)
- [ ] Feature: 同条件で `X-Forwarded-Proto: http` なら 308 が返る
- [ ] 既存 `SecurityHeadersTest.php:107` (308 の既存テスト) が green のまま

### リスク

- `RedirectToHttps` が `TrimStrings` / `ConvertEmptyStringsToNull` より後になる。
  リダイレクト判定は URL と config のみで body に依存しないため影響なし。

---

## S7: passkey 増減の監査記録

### 変更箇所

- `app/Enums/SecurityEventType.php` (case 2 件 + label)
- `app/Listeners/RecordSecurityEvent.php` (購読 2 件 + handler 2 件)
- `tests/Architecture/SecurityEventCoverageTest.php` (**新規**)

### 波及変更

- TypeScript 型定義: **要確認**。`SecurityAuditEventResource` (Filament) と
  監査ログ画面が `event_type` の label を enum から引くため、追加のみで破綻しない。
  フロントに event_type の union 型があれば追記する (実装時に `rg 'password_changed'` で確認)
- API Resource/DTO: なし
- テストファイル: 下記

### 変更後コード

```php
// app/Enums/SecurityEventType.php
case PasskeyRegistered = 'passkey_registered';
case PasskeyDeleted = 'passkey_deleted';

// label()
self::PasskeyRegistered => 'パスキーの登録',
self::PasskeyDeleted => 'パスキーの削除',
```

```php
// app/Listeners/RecordSecurityEvent.php
$events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
$events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);

/**
 * パスキーは単独でログインできる強い資格のため、増減は監査上最重要事象として記録する
 * (セッション乗っ取り後の永続化を事後追跡できるようにする)。
 * credential 本体 (公開鍵 / signature counter) は metadata に載せない。
 */
public function handlePasskeyRegistered(PasskeyRegistered $event): void
{
    $this->recorder->record(SecurityEventType::PasskeyRegistered, $this->asUser($event->user), [
        'passkey_id' => $event->passkey->getKey(),
    ]);
}

public function handlePasskeyDeleted(PasskeyDeleted $event): void
{
    $this->recorder->record(SecurityEventType::PasskeyDeleted, $this->asUser($event->user), [
        'passkey_id' => $event->passkey->getKey(),
    ]);
}
```

> **実装時の確認事項**: vendor の `PasskeyRegistered` / `PasskeyDeleted` の
> public property 名 (`$user` / `$passkey`) を実物で確認し、
> `$event->passkey->getKey()` が `int|string` を返すことを PHPStan で通す
> (`metadata` は `array<string, mixed>` のため型は問題にならない)。

### 記録経路の構造化 map (新規 Architecture テスト)

```php
/**
 * SecurityEventType => 記録経路の宣言。
 * 'event' は購読するイベントクラス、'caller' は直接記録する呼び出し元クラス。
 * enum の全 case と本 map の key は完全一致でなければならない (deny-by-default)。
 */
function securityEventRecordingMap(): array
{
    return [
        SecurityEventType::Login->value => ['event' => Illuminate\Auth\Events\Login::class],
        // ... 既存 case
        SecurityEventType::AdminMfaReset->value => ['caller' => App\Console\Commands\ResetAdminMfaCommand::class,
            'covered_by' => 'tests/Feature/Console/ResetAdminMfaCommandTest.php'],
        SecurityEventType::OrgMemberTwoFactorReset->value => ['caller' => App\Http\Controllers\Organizations\OrganizationMemberController::class,
            'covered_by' => '...'],
        SecurityEventType::PasskeyRegistered->value => ['event' => Laravel\Passkeys\Events\PasskeyRegistered::class],
        SecurityEventType::PasskeyDeleted->value => ['event' => Laravel\Passkeys\Events\PasskeyDeleted::class],
    ];
}
```

検査:

1. enum 全 case と map key の**完全一致** (双方向)
2. `event` 宣言の case: `Event::getRawListeners()` に当該イベントの listener が登録されていること
3. `caller` 宣言の case: 当該クラスが実在し `SecurityEventRecorder` を参照していること
4. 各 case に Feature テストの担保があること (`covered_by` の記載必須。
   新規 case は新規 Feature テストを書く)

### PHPStan適合チェック

- [x] enum case 追加に伴い `label()` の `match` が網羅される (未網羅なら PHPStan が検出)
- [x] `metadata` は `array<string, mixed>`
- [x] `asUser()` の `mixed → ?User` は既存ヘルパを再利用

### テスト計画

- [ ] Feature (`tests/Feature/Auth/PasskeyAuditTrailTest.php` 新規):
      passkey 登録で `security_audit_events` に `passkey_registered` が 1 行増える
- [ ] Feature: passkey 削除で `passkey_deleted` が 1 行増える
- [ ] Feature: `EnsureLoginMethodRemains` に弾かれた削除 (最後の手段) では
      **行が増えない** (transaction rollback / そもそも event 未発火の確認)
- [ ] Architecture: 上記 4 検査
- [ ] 既存 `PasskeyRecentAuthInvalidationTest` が green のまま
      (同じイベントに listener が 2 本ぶら下がる形になる)

### リスク

- `PasskeyDeleted` は `EnsureLoginMethodRemains` の transaction 内で発火する。
  記録の insert も同 transaction に入るため、rollback 時は監査行も消える
  (削除自体も消えるので整合。**意図した挙動としてテストで固定する**)。
- `SecurityEventRecorder` は `Throwable` を catch して `report()` する。
  pgsql では transaction 内の失敗文が transaction 全体を abort させるため、
  「catch したのに後続 SQL が全部落ちる」経路が理論上ある。
  これは既存の全 recorder 呼び出しに共通する性質であり本施策で新設しない
  (docblock に注記のみ)。
- **メール通知は入れない** (概念設計の決定。2FA / SSO と同格 = 監査ログのみ)。

---

## S8: `passkey.destroy` の throttle

### 変更箇所

- `app/Providers/PasskeyServiceProvider.php` L58-68 (定数), L112-131 (後付け)
- `tests/Architecture/PasskeyRouteProtectionTest.php` L22-36 (inventory), 新規順序テスト

### 現行コード

```php
/** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
private const RECENT_AUTH_ROUTE_NAMES = [...];

/** ログイン手段を減らす passkey route */
private const LOGIN_METHOD_GUARD_ROUTE_NAMES = ['passkey.destroy'];
```

### 変更後コード

```php
/**
 * throttle を後付けする passkey route。
 *
 * vendor (Fortify) の $passkeyMiddleware は $throttle を含まないため、
 * passkey.destroy **だけ** throttle が付かない
 * (vendor/laravel/fortify/routes/routes.php:217-219)。
 * EnsureLoginMethodRemains が毎リクエスト DB::transaction + User 行 lockForUpdate を
 * 取るため、認証済みユーザーが自分の User 行に無制限のロック競合を起こせる
 * (audit-cycle-2 Medium-2)。他の passkey route と同じ 10/min に揃える。
 *
 * ThrottleRequests は Laravel の priority list に含まれ Authenticate より後に走るため、
 * キーは user 単位になる (未認証 IP fallback には落ちない)。
 */
private const THROTTLE_ROUTE_NAMES = [
    'passkey.destroy',
];
```

```php
foreach (self::THROTTLE_ROUTE_NAMES as $name) {
    self::appendMiddlewareIfMissing($routes, $name, 'throttle:passkeys');
}
```

**適用順序**: `attachMiddlewareToPasskeyRoutes()` の中で `recent-auth` /
`ensure-login-method` より**前**に throttle を append する
(宣言順では throttle が先に並び、priority 適用後も `ThrottleRequests` が
`RequireRecentAuth` より前になる)。

### PHPStan適合チェック

- [x] `array<int, string>` 定数 + 既存 `appendMiddlewareIfMissing()` の再利用 (型変更なし)

### テスト計画

- [ ] `PasskeyRouteProtectionTest` inventory の `passkey.destroy` に `throttle:passkeys` を追加
- [ ] 新規: `passkey.destroy` の解決後実行順で
      `ThrottleRequests` < `RequireRecentAuth` < `EnsureLoginMethodRemains`
- [ ] Feature: `passkey.destroy` を 11 回叩くと 11 回目が 429
      (10 回目までは 404/200。**429 が binding の 404 より前に来ることで
      存在オラクルを新設しないこと**も同時に確認する
      = 不在 id と他人の passkey id で 429 到達回数が同一)
- [ ] 既存 `PasskeyRouteAccessTest` / `LoginMethodRetentionTest` が green のまま

### リスク

- throttle が binding より前に走るため、10/min を超えると 404 ではなく 429 になる。
  これは全 id で同一の挙動 (pre-binding 短絡) のため存在オラクルにならない。
  S4 検査 4 の pre-binding inventory に `ThrottleRequests` が登録済みであることで担保。
- 正当な利用者が短時間に 10 個以上の passkey を削除するケースは想定しない。

---

## ドキュメント更新

| ファイル | 内容 |
|---|---|
| `docs/app-integration-guide.md` §7 不変条件 9 | 「層 2 は FormRequest より前で閉じる」を「**層 2 は binding の直後で閉じる** (SubstituteBindings とテナント guard の間に短絡 middleware を置かない)」へ拡張。`api-key.ability` の位置を明記 |
| 同 §7 新規 route チェックリスト | 「新しい middleware を足すときは `TenantBoundaryOrderingTest` の分類 inventory に登録する」を追加 |
| 同 §5 API エラー契約 | 「actor 解決 → テナント境界 404 → ability 判定」の優先順位を明記 |
| `docs/auth-security-mechanisms.md` | 「client IP の信頼境界」節を新設 (`TRUSTED_PROXIES` / rate limiter への影響 / 監査ログ IP の信頼性) |
| `docs/trusted-proxies-runbook.md` (新規) | 実 proxy hop 一覧 (運用者記入 = `<!-- OPS-FILL -->`) / CIDR 管理主体 / 変更手順 / `production:preflight` 必須 gate / fail-fast 時の切り分けと rollback 条件 |
| `docs/architecture.md` | 課金ゲート節に「テナント guard は課金ゲートより前」を 1 行追記 |
| `routes/api.php` / `routes/web.php` ヘッダコメント | 順序契約の書き換え |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** (1 TODO = 1 worktree)。内部を 3 フェーズに分けて順次実装する |
| 判断根拠 | S1-S4 は相互依存 (S4 のテストは S1-S3 完了まで red)。S5-S6 は `bootstrap/app.php` を S2 と共有するため別 worktree にすると確実に conflict する。S7-S8 は独立だが、docs 更新と Architecture テスト群を共有するため同一 PR が安全 |
| フェーズ | ① 存在オラクル (S1→S2→S3→S4) ② proxy 信頼境界 (S5→S6) ③ passkey (S7→S8) |
| 競合リスク | `bootstrap/app.php` を S2/S5/S6 が同時に触る (同一 worktree なら問題なし)。他タスクとの並走時は `bootstrap/app.php` / `routes/*.php` / `tests/Architecture/*` が競合点 |

## 完了条件 (TODO 登録を含む)

1. `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
   `pnpm typecheck` / `pnpm test` / `pnpm build` が全 green
2. S4 の 4 テストが、S1-S3 のいずれかを revert すると red になることを実際に確認
3. `docs/trusted-proxies-runbook.md` の placeholder が残っていないこと (Architecture テストで機械検出)
4. **スコープ外項目の TODO 登録**:
   - MCP の idempotency replay がリソース解決より前に走る点 (write tool 追加時に対応)
   - 認証手段変更のメール通知ポリシー (passkey / 2FA / SSO を一貫して設計)


---

## 関連する現行コード（抜粋）

### routes/api.php (L50-99)
```php
// 公開 (未認証) エンドポイント
Route::prefix('v1')
    ->middleware(['throttle:api-status'])
    ->group(function (): void {
        Route::get('/version', [VersionController::class, 'show'])
            ->name('api.v1.version');
    });

// OAuth user token 単独 (API キーにセッション概念は無いため dual guard に含めない。
// API キー Bearer は guard 段 401 でエラー契約を混線させない)
Route::prefix('v1')
    ->middleware(['auth:api-oauth', 'throttle:api-write', 'resolve.api-actor'])
    ->group(function (): void {
        Route::delete('/me/session', [RevokeSessionController::class, 'destroy'])
            ->name('api.v1.me.session.revoke');
    });

// 読み取り (read ability)
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read',
        'api.project-in-org'])
    ->group(function (): void {
        Route::get('/me', [MeController::class, 'show'])
            ->name('api.v1.me');

        Route::get('/projects', [ProjectController::class, 'index'])
            ->name('api.v1.projects.index');
        Route::get('/projects/{project}', [ProjectController::class, 'show'])
            ->name('api.v1.projects.show');

        Route::get('/projects/{project}/items', [ItemController::class, 'index'])
            ->name('api.v1.projects.items.index');
    });

// 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する
Route::prefix('v1')
    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write',
        'api.project-in-org', 'idempotent'])
    ->group(function (): void {
        Route::post('/projects/{project}/items', [ItemController::class, 'store'])
            ->name('api.v1.projects.items.store');
        // {item} ∈ {project} を **routing 層** (SubstituteBindings) で解決する。
        // FormRequest より前に 404 が確定し、web 側 (routes/web.php) と同じ構造になる。
        Route::scopeBindings()->group(function (): void {
            Route::patch('/projects/{project}/items/{item}', [ItemController::class, 'update'])
                ->name('api.v1.projects.items.update');
            Route::delete('/projects/{project}/items/{item}', [ItemController::class, 'destroy'])
                ->name('api.v1.projects.items.destroy');
        });
    });
```

### bootstrap/app.php (L41-90, L126-185)
```php
return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // HTTPS リダイレクトは最外周 (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)
        $middleware->prepend(RedirectToHttps::class);

        // LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)
        $middleware->trustProxies(
            at: '*',
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Host header injection 防御。`config/trusted_hosts.php` で env 由来の許可 host を
        // 集約し、ここで regex pattern (`^host$`) に変換して TrustHosts middleware に渡す。
        // `preg_quote` で `.` を任意文字として誤ヒットさせない。production で allowlist が
        // 空のときは AppServiceProvider 側で ProductionEnvGuard (TrustedHostsConfigValidator)
        // が起動時 fail-fast する二重防御。
        $middleware->trustHosts(at: function (): array {
            /** @var array<int, mixed> $exact */
            $exact = (array) config('trusted_hosts.exact_hosts', []);
            /** @var array<int, mixed> $wildcard */
            $wildcard = (array) config('trusted_hosts.wildcard_suffixes', []);

            $patterns = [];
            foreach ($exact as $host) {
                if (! is_string($host) || $host === '') {
                    continue;
                }
                $patterns[] = '^'.preg_quote($host, '#').'$';
            }
            foreach ($wildcard as $suffix) {
                if (! is_string($suffix) || $suffix === '') {
                    continue;
                }
                $patterns[] = '^.+'.preg_quote($suffix, '#').'$';
            }

            return $patterns;
        });

        $middleware->web(append: [
            HandleInertiaRequests::class,
...
        // REST API v1 / MCP の middleware alias (routes/api.php・routes/ai.php で使う)。
        // API キー認証は auth guard ('auth:api-key') に置換済みのため alias なし。
        // recent-auth は web の機微操作 route 用 (generic step-up 再認証)。
        // require-active-subscription は業務 route の課金ゲート (判定は BillingAccess 経由のみ)
        $middleware->alias([
            'recent-auth' => RequireRecentAuth::class,
            // profile 更新の email 変更時のみ step-up を課す条件付きゲート
            'recent-auth.on-email-change' => RequireRecentAuthOnEmailChange::class,
            // ログイン手段を減らす操作の関門 (投影後評価 + User 行ロックによる直列化)。
            // 付与対象は LoginMethodRemovalRouteTest が deny-by-default で強制する
            // (allowlist 外への付与も fail。$next を transaction 内で実行するため)
            'ensure-login-method' => EnsureLoginMethodRemains::class,
            // guest route の応答に no-store を保証する (認証済み baseline の対象外を補う)。
            // 現在の付与先は passkey.login-options (WebAuthn challenge を載せる guest route)
            'no-store' => NoStoreResponse::class,
            'require-active-subscription' => RequireActiveSubscription::class,
            // `verified` の web POST 向け代替。未認証時に back + error flash で元ページへ戻す
            // (context 別文言は EmailVerificationGateContext)。organizations.store /
            // organizations.invitations.store に withoutMiddleware('verified') とセットで付与。
            'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
            // web の {project} route の URL 整合 guard。cross-org の {project} を
            // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
            'project.in-current-org' => EnsureProjectBelongsToCurrentOrganization::class,
            // REST API v1 用の同等 guard (組織は API キー / OAuth token から確定するため
            // web セッションの current org とは解決元が違う = 別 alias)。
            // resolve.api-actor より後・idempotent より前に置くこと (順序契約は
            // routes/api.php と ProjectRouteCurrentOrgGuardTest)
            'api.project-in-org' => EnsureProjectBelongsToApiOrganization::class,
            'resolve.api-actor' => ResolveApiActor::class,
            'api-key.ability' => RequireApiKeyAbility::class,
            'idempotent' => IdempotentRequest::class,
            'mcp.origin' => VerifyMcpOrigin::class,
            'mcp.transport' => EnforceMcpTransport::class,
            'sns.signature' => VerifySnsSignature::class,
        ]);

        // McpConsentOrganizationBinder は /oauth/authorize の approve POST body の
        // organization_id を検証する際 $request->user() (web セッション由来) を読む。
        // config('passport.middleware') 経由の付与だと route の先頭 (web=StartSession /
        // Authenticate より前) に並び、実ブラウザでは user=null になるため、
        // priority list で Authenticate の後ろに置き session + auth 解決後に走らせる。
        $middleware->appendToPriorityList(
            AuthenticatesRequests::class,
            McpConsentOrganizationBinder::class,
        );

        // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
        // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
        $middleware->validateCsrfTokens(except: [
            'stripe/*',
            'ses/*',
        ]);

        // bug-hunt (LLM 探索的バグハント) 用コード到達カバレッジ観測器。
        // env(BUGHUNT_PCOV) と function_exists('\pcov\start') の二重 guard を通らない限り
        // 完全 no-op (handle は $next をそのまま返し、terminate は即 return)。pcov 未導入の
        // 本番/CI/dev には一切影響しない。有効化は scripts/bug-hunt-shard.sh provision --coverage 経由。
        $middleware->append(BughuntCoverageMiddleware::class);
    })
```

### app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Api\V1\Concerns\ResolvesApiOrganization;
use App\Models\Project;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * REST API v1 の `{project}` route の URL 整合 guard (middleware 層)。alias: api.project-in-org。
 *
 * web の {@see EnsureProjectBelongsToCurrentOrganization} と同じ順序ハザードを API 側で閉じる。
 * cross-org の {project} を「FormRequest のバリデーションを含むあらゆるアプリコードより前に
 * 404」へ落とす。controller の inline guard (resolveOrganizationProject) は認可より前の 404 を
 * 担うが、**FormRequest は controller メソッド解決時 = inline guard より前**に走るため、
 * middleware が無いと「cross-org の実在 project + 不正 payload = 422」「不在 project = 404」の
 * 差分が存在オラクルになる (不変条件 3)。
 *
 * web 版との違いは組織の解決元だけ:
 *  - web: セッションの current org (ResolvesCurrentOrganization)
 *  - API: API キー / OAuth token から確定した request attribute 'organization'
 *         (ApiKeyGuard / ResolveApiActor が注入。ResolvesApiOrganization::resolveOrganization)
 *
 * 順序契約: api グループ (SubstituteBindings) → auth → throttle → resolve.api-actor
 *           → api-key.ability → **api.project-in-org** → idempotent → controller
 * `organization` attribute が前提のため **resolve.api-actor より後**、
 * cross-org リクエストで idempotency 行を作らせないため **idempotent より前**に置く。
 * {project} を持たない route では no-op (group 一括付与を許容し、将来の route 追加時の
 * guard 漏れを防ぐ)。
 *
 * 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する。
 * controller の inline guard は二重防御として残す (middleware の付け漏れ・
 * withoutMiddleware への最終防衛線)。
 */
class EnsureProjectBelongsToApiOrganization
{
    use ResolvesApiOrganization;

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');

        if ($project instanceof Project) {
            $organization = $this->resolveOrganization($request);
            $this->resolveOrganizationProject($organization, $project);
        }

        return $next($request);
    }
}

```

### app/Http/Middleware/ResolveApiActor.php (L27-75, L113-170)
```php
/**
 * REST API v1 の actor 解決 middleware (dual guard の後段)。
 *
 * 認証成立後 (`auth:api-key,api-oauth` / `auth:api-oauth` guard を通過) に、API キー経路 /
 * OAuth user-token 経路の双方を {@see ApiActorContext} に正規化し request attribute
 * `api_actor` へ staple する。actor 解決段の失敗 (cli:use 欠落 / session 不整合 /
 * 非メンバー / issuedBy null) は **すべて本 middleware が 401/403 で弾く**。
 * controller / ability middleware は解決済み context を読むだけで再判定しない。
 *
 * user-token 経路では下流互換のため request attribute `organization` も注入する
 * (API キー経路は ApiKeyGuard が注入済み。ResolvesApiOrganization / rate limiter が参照)。
 *
 * 順序契約: auth → throttle → resolve.api-actor → api-key.ability:X → (idempotent) → controller
 * (ApiGuardAllowlistInvariantTest が dual/oauth 分類ごと固定)。
 */
class ResolveApiActor
{
    public const ATTRIBUTE_KEY = 'api_actor';

    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey instanceof ApiKey) {
            $context = $this->contextFromApiKey($apiKey);
            if ($context instanceof JsonResponse) {
                return $context;
            }
            $request->attributes->set(self::ATTRIBUTE_KEY, $context);

            return $this->forward($next, $request);
        }

        $user = $request->user('api-oauth');
        if ($user instanceof User) {
            $context = $this->contextFromUserToken($user);
            if ($context instanceof JsonResponse) {
                return $context;
            }
            $request->attributes->set(self::ATTRIBUTE_KEY, $context);
            // 下流互換 (ResolvesApiOrganization 等): API キー経路は guard が注入するのと同じ attribute
            $request->attributes->set('organization', $context->organization);

            return $this->forward($next, $request);
        }

        // defense-in-depth: guard を経た正常系では到達しない。
        return $this->unauthenticated();
    }

...
    private function contextFromUserToken(User $user): ApiActorContext|JsonResponse
    {
        $accessToken = $user->token();
        if (! $accessToken instanceof AccessToken) {
            return $this->unauthenticated();
        }

        $scopes = $this->scopesFromToken($accessToken);
        if (! in_array(CliOAuthScope::CliUse->value, $scopes, true)) {
            return $this->unauthenticated('This token is not authorized for CLI access.');
        }

        $tokenId = (string) $accessToken->oauth_access_token_id;
        if ($tokenId === '') {
            return $this->unauthenticated();
        }

        /** @var object{organization_id: mixed, session_id: mixed}|null $row */
        $row = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->first(['organization_id', 'session_id']);
        $organizationId = $this->normalizeId($row?->organization_id);
        if ($row === null || $organizationId === null) {
            return $this->unauthenticated('This token is not associated with an organization.');
        }
        if (! is_string($row->session_id) || $row->session_id === '') {
            return $this->unauthenticated('This token is not associated with a CLI session.');
        }

        $session = OauthSession::query()->find($row->session_id);
        if (
            $session === null
            || $session->isRevoked()
            || $session->client_kind !== OAuthClientKind::Cli->value
            || $session->user_id !== $user->id
            || $session->organization_id !== $organizationId
        ) {
            return $this->unauthenticated('The CLI session is no longer active.');
        }

        // membership は毎リクエスト再検証する (組織から外れた token を即時失効同等に扱う)。
        $organization = Organization::query()->find($organizationId);
        if ($organization === null || ! $user->isMemberOf($organization)) {
            return $this->unauthenticated('You are no longer a member of this organization.');
        }

        $session->touchLastUsedAt();

        return new ApiActorContext(
            kind: ApiActorKind::UserToken,
            user: $user,
            organization: $organization,
            scopes: new ApiScopeSet($scopes),
            oauthSessionId: $session->id,
            apiKey: null,
        );
    }

```

### tests/Architecture/ProjectRouteCurrentOrgGuardTest.php (L67-138)
```php
/*
 * API の middleware 順序契約 (docblock ではなく機械で固定する):
 *
 *   resolve.api-actor  <  api-key.ability:*  <  api.project-in-org  <  idempotent
 *
 * | 破られる契約 | 起きること |
 * |---|---|
 * | resolve.api-actor が api.project-in-org **より後** | 'organization' attribute 未設定で Assert が
 *   発火し **全 API {project} route が 500** |
 * | api-key.ability:* が api.project-in-org **より後** | ability 不足の判定 (403) より先に
 *   テナント境界の 404 が返り、エラー契約 (insufficient_ability) が route ごとにぶれる |
 * | idempotent が api.project-in-org **より前** | **cross-org リクエストで idempotency 行が作られる**
 *   (cross-org の副作用 = 不変条件 3 に抵触) |
 *
 * 注意: gatherMiddleware() が返すのは **宣言順** (group middleware → route middleware)。
 * Laravel の middleware priority ($middlewarePriority) を導入すると最終的な実行順が
 * 並べ替えられうるが、現行構成では本テストが検査する custom middleware
 * (resolve.api-actor / api.project-in-org / idempotent) はいずれも priority リストに
 * 含まれないため宣言順 = 実行順である。priority を導入する際は本テストの前提を見直すこと。
 */
test('API の {project} route は middleware 順序契約を守る', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();
        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);

        $guard = $indexOf('api.project-in-org');
        $actor = $indexOf('resolve.api-actor');
        $idempotent = $indexOf('idempotent');
        // ability middleware は `api-key.ability:read` のようにパラメータ付きで並ぶ
        $ability = false;
        foreach ($middleware as $index => $entry) {
            if (is_string($entry) && str_starts_with($entry, 'api-key.ability:')) {
                $ability = $index;

                break;
            }
        }

        if ($guard === false) {
            $violations[] = "{$name}: api.project-in-org が無い";

            continue;
        }
        if ($actor === false || $actor > $guard) {
            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                .'(organization attribute 未設定で 500 になります)';
        }
        if ($ability === false || $ability > $guard) {
            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より後 "
                .'(ability 不足の 403 より前にテナント境界の 404 が返り、エラー契約がぶれます)';
        }
        if ($idempotent !== false && $idempotent < $guard) {
            $violations[] = "{$name}: idempotent が api.project-in-org より前 "
                .'(cross-org リクエストで idempotency 行が作られます)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
});
```

### app/Enums/Security/NestedRouteDefenseMode.php
```php
<?php

declare(strict_types=1);

namespace App\Enums\Security;

/**
 * nested route (親子) の IDOR 防御方式。
 *
 * 子リソースを URL で受ける route が「子は必ず URL 上の親 (またはテナント) に属する」不変条件を
 * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
 * 値に持ち、2 個以上の route パラメータを取る named route を deny-by-default で分類漏れ・drift
 * から守る。
 *
 * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
 * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
 * 解決順序の都合で scopeBindings に乗らない route のみ inline guard を使う。
 * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
 */
enum NestedRouteDefenseMode: string
{
    /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
    case ScopeBindings = 'scope_bindings';

    /** route-model binding + inline 親子整合 guard (authorize より前に子∈親/テナントを検査し不整合は 404)。 */
    case UrlIntegrityGuard = 'url_integrity_guard';
}

```

### config/trusted_hosts.php
```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Trusted Hosts (Host header injection 防御)
|--------------------------------------------------------------------------
|
| Laravel の `Illuminate\Http\Middleware\TrustHosts` は配列要素を **regex pattern**
| として扱うため、bootstrap/app.php 側で `preg_quote` + 明示 anchor (`^...$`) を
| 付与する。設定段階では「文字列としての host (alphanumeric / dot / hyphen)」として
| 保持し、runtime で regex 化する責務分離。
|
| - `exact_hosts`: 完全一致する host 名 (例: app.example.com)
| - `wildcard_suffixes`: 末尾一致する suffix (例: .preview.example.com、先頭 dot 必須)
|
| 不正値は silent drop ではなく `App\Support\TrustedHostsConfigValidator`
| (ProductionEnvGuard 経由で production 起動時) で fail-fast する。
| deploy pipeline 上で env ミスを起動時点で検知する。
|
*/

return [
    /*
    | 完全一致 host (string list)。各値は host のみ (alphanumeric / dot / hyphen)。
    | scheme / port / path を含む値は TrustedHostsConfigValidator で reject する。
    |
    | 候補: PRIMARY_HOST (deploy 時の primary domain) / APP_URL の host 部 /
    |       TRUSTED_HOSTS_ADDITIONAL の CSV 列 (alias / health check 等)。
    */
    'exact_hosts' => array_values(array_filter(
        array_map('trim', [
            (string) env('PRIMARY_HOST', ''),
            parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: '',
            ...explode(',', (string) env('TRUSTED_HOSTS_ADDITIONAL', '')),
        ]),
        fn (string $v): bool => $v !== ''
    )),

    /*
    | wildcard suffix (`.preview.example.com` 形式、先頭 dot 必須)。
    | alphanumeric / dot / hyphen のみ許容。不正書式は config 段階で除外し、
    | 起動時に TrustedHostsConfigValidator から再 verify される。
    | 先頭 dot + 2 ラベル以上 (`.com` 単体は過大許可のため除外)。
    */
    'wildcard_suffixes' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('TRUSTED_HOSTS_WILDCARD_SUFFIXES', ''))),
        fn (string $v): bool => $v !== ''
            && preg_match('/^\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $v) === 1
    )),

    /*
    | 生 env 値 (空白 trim 前) を保持。TrustedHostsConfigValidator が
    | 「config 段階で silent drop された不正値」を起動時 fail-fast で検知するために使う。
    | Guard 側で `env()` を直接読むと config:cache 後に null 化するため config 経由で expose。
    */
    'raw_wildcard_suffixes' => explode(',', (string) env('TRUSTED_HOSTS_WILDCARD_SUFFIXES', '')),
];

```

### app/Support/TrustedHostsConfigValidator.php
```php
<?php

declare(strict_types=1);

namespace App\Support;

use RuntimeException;

/**
 * TrustHosts allowlist (config/trusted_hosts.php) の production 起動時検証。
 *
 * 検証ロジックを純粋クラスに切り出して unit test で直接検証可能にする
 * (Service Provider / Guard の boot 内 inline では Feature test で再現が困難なため)。
 *
 * production で以下のいずれかに該当するときに `RuntimeException` を投げ、起動時 fail-fast させる:
 *   - allowlist が完全に空 (exact host / wildcard suffix の双方が空)
 *   - `TRUSTED_HOSTS_WILDCARD_SUFFIXES` の生 env 値が `.suffix` 形式に違反
 *     (config 側で silent drop されるが、起動時には fail-fast で表面化)
 *   - `PRIMARY_HOST` / `TRUSTED_HOSTS_ADDITIONAL` が host 形式に違反
 *     (scheme / path / port を含む値を弾く)
 *
 * 限界: 本 validator は format (label 数等) のみ検査する。`.co.jp` のような
 * public suffix 単体も「2 ラベル以上」を満たすため通過する (label 数では正当な
 * `.example.com` と区別不能)。public suffix の wildcard を弾くには Public Suffix List
 * が必要だが、運用者が意図的に設定する config に対しては過剰なため scope 外とする。
 * 運用者は所有ドメイン配下の suffix (例: `.preview.example.com`) を設定すること。
 */
final class TrustedHostsConfigValidator
{
    /**
     * @param  list<string>  $exactHosts  validate 済 exact host 列 (config 通過後)
     * @param  list<string>  $wildcardSuffixes  validate 済 wildcard suffix 列 (config 通過後)
     * @param  list<string>  $rawWildcards  生 env 値 (空白 trim のみ、format validation 前)
     *
     * @throws RuntimeException
     */
    public function validateForProduction(
        array $exactHosts,
        array $wildcardSuffixes,
        array $rawWildcards,
    ): void {
        // empty check: production で allowlist が完全空だと SUSPICIOUS_OPERATION で
        // 全 request が 400 になる事故 → 起動時点で fail-fast。
        if ($exactHosts === [] && $wildcardSuffixes === []) {
            throw new RuntimeException(
                'TrustHosts allowlist is empty in production. '
                .'Set PRIMARY_HOST or TRUSTED_HOSTS_ADDITIONAL or TRUSTED_HOSTS_WILDCARD_SUFFIXES.'
            );
        }

        // wildcard 不正値 (raw 値で検証、config 側で silent drop された値も起動時 fail で表面化)。
        foreach ($rawWildcards as $raw) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                continue;
            }
            // `.com` のような単一ラベル suffix は過大許可 (全 .com host を信頼) になるため、
            // 先頭 dot の後に **2 ラベル以上** を必須化する。
            if (preg_match('/^\.[A-Za-z0-9-]+(\.[A-Za-z0-9-]+)+$/', $trimmed) !== 1) {
                throw new RuntimeException(sprintf(
                    'TRUSTED_HOSTS_WILDCARD_SUFFIXES contains invalid value "%s". '
                    .'Format must be ".sub.domain.tld" (leading dot, >=2 labels, alphanumeric/hyphen only). '
                    .'Single-label suffixes like ".com" are rejected (too broad).',
                    $trimmed,
                ));
            }
        }

        // exact host 不正値 (scheme / path / port / wildcard / カンマ等を弾く)。
        foreach ($exactHosts as $host) {
            if (preg_match('/^[A-Za-z0-9.-]+$/', $host) !== 1) {
                throw new RuntimeException(sprintf(
                    'PRIMARY_HOST or TRUSTED_HOSTS_ADDITIONAL contains invalid value "%s". '
                    .'Format must be host only (alphanumeric/dot/hyphen, no scheme/port/path).',
                    $host,
                ));
            }
        }
    }
}

```

### app/Support/ProductionEnvGuard.php (L100-155)
```php
            $errors[] = 'TESTING_FAKE_STORAGE must be false in production '
                .'(storage fake must never be enabled in production).';
        }

        // Host header injection 防御の TrustHosts allowlist を起動時検証。
        // 純粋クラス TrustedHostsConfigValidator に委譲し、throw を violation メッセージへ写像する。
        $exact = $this->stringList(config('trusted_hosts.exact_hosts', []));
        $wildcard = $this->stringList(config('trusted_hosts.wildcard_suffixes', []));
        $rawWildcards = $this->stringList(config('trusted_hosts.raw_wildcard_suffixes', []), keepEmpty: true);
        try {
            (new TrustedHostsConfigValidator)->validateForProduction($exact, $wildcard, $rawWildcards);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * production 起動時に違反があれば例外で fail-fast。
     */
    public function enforce(): void
    {
        $errors = $this->violations();
        if ($errors !== []) {
            throw new RuntimeException(
                "Production env baseline violations:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * config 値を string list へ正規化する (非 string 要素を除外)。
     *
     * @return list<string>
     */
    private function stringList(mixed $value, bool $keepEmpty = false): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }
            if (! $keepEmpty && $item === '') {
                continue;
            }
            $result[] = $item;
        }

        return $result;
    }
}
```

### vendor: Illuminate/Http/Middleware/TrustProxies.php (proxies 解決部)
```php
    protected function setTrustedProxyIpAddresses(Request $request)
    {
        $trustedIps = $this->proxies() ?: config('trustedproxy.proxies');

        if (is_null($trustedIps) &&
            (laravel_cloud() || str_ends_with($request->host(), '.on-forge.com') || str_ends_with($request->host(), '.on-vapor.com'))) {
            $trustedIps = '*';
        }
        ...
        if ($trustedIps === '*' || $trustedIps === '**') {
            return $this->setTrustedProxyIpAddressesToTheCallingIp($request);
        }
        $trustedIps = is_string($trustedIps) ? array_map(trim(...), explode(',', $trustedIps)) : $trustedIps;
        if (is_array($trustedIps)) {
            return $this->setTrustedProxyIpAddressesToSpecificIps($request, $trustedIps);
        }
    }
    protected function proxies() { return static::$alwaysTrustProxies ?: $this->proxies; }
```

### app/Providers/PasskeyServiceProvider.php (L56-140)
```php
final class PasskeyServiceProvider extends ServiceProvider
{
    /** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
    private const RECENT_AUTH_ROUTE_NAMES = [
        'passkey.registration-options',
        'passkey.store',
        'passkey.destroy',
    ];

    /** ログイン手段を減らす passkey route */
    private const LOGIN_METHOD_GUARD_ROUTE_NAMES = [
        'passkey.destroy',
    ];

    public function register(): void
    {
        Passkeys::usePasskeyModel(Passkey::class);

        $this->app->singleton(PasskeyLoginResponseContract::class, PasskeyLoginResponse::class);
        $this->app->singleton(PasskeyConfirmationResponseContract::class, PasskeyConfirmationResponse::class);
        $this->app->singleton(PasskeyRegistrationResponseContract::class, PasskeyRegistrationResponse::class);
        $this->app->singleton(PasskeyDeletedResponseContract::class, PasskeyDeletedResponse::class);
    }

    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // binder と middleware は **全 provider boot 後** に最終上書きする
        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
        $this->app->booted(static function (Application $app): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
            self::attachMiddlewareToPasskeyRoutes($app);
        });
    }

    /**
     * passkey login の最終ゲート。判定は PasskeyLoginPolicy に集約する
     * (LoginMethodInventory と条件を二重定義しないため。closure にロジックを書かない)。
     */
    private function configureLoginAuthorization(): void
    {
        Passkeys::authorizeLoginUsing(static function (Request $request, PasskeyUser $user, VendorPasskey $passkey): bool {
            if (! $user instanceof User) {
                return false;   // fail-closed
            }

            return app(PasskeyLoginPolicy::class)->allowsPasskeyLogin($user);
        });
    }

    /**
     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
     */
    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
    {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        // **順序が重要**: recent-auth を先に通し、その後で手段保持を検査する。
        // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
        // PasskeyRouteProtectionTest が gatherMiddleware() 上の index 比較で固定する。
        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
    }

    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
}
```

### app/Enums/SecurityEventType.php
```php
<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * security_audit_events に記録するイベント種別 (固定集合)。
 * 追加時は RecordSecurityEvent の購読 map も同一 PR で更新すること。
 */
enum SecurityEventType: string
{
    case Login = 'login';
    case LoginFailed = 'login_failed';
    case Logout = 'logout';
    case PasswordReset = 'password_reset';
    case PasswordChanged = 'password_changed';
    case TwoFactorEnabled = 'two_factor_enabled';
    case TwoFactorDisabled = 'two_factor_disabled';
    case EmailChanged = 'email_changed';
    case AccountDeleted = 'account_deleted';
    case SocialAccountLinked = 'social_account_linked';
    case OwnershipTransferred = 'ownership_transferred';
    case ApiKeyIssued = 'api_key_issued';
    case ApiKeyRevoked = 'api_key_revoked';
    // CLI (admin:reset-mfa) から直接記録する (RecordSecurityEvent の購読対象外)
    case AdminMfaReset = 'admin_mfa_reset';
    // 組織管理者によるメンバー 2FA リセット (OrganizationMemberController::resetTwoFactor が
    // 直接記録する。RecordSecurityEvent の購読対象外)
    case OrgMemberTwoFactorReset = 'org_member_two_factor_reset';

    public function label(): string
    {
        return match ($this) {
            self::Login => 'ログイン',
            self::LoginFailed => 'ログイン失敗',
            self::Logout => 'ログアウト',
            self::PasswordReset => 'パスワードリセット',
            self::PasswordChanged => 'パスワード変更',
            self::TwoFactorEnabled => '2要素認証の有効化',
            self::TwoFactorDisabled => '2要素認証の無効化',
            self::EmailChanged => 'メールアドレス変更',
            self::AccountDeleted => 'アカウント削除',
            self::SocialAccountLinked => 'ソーシャルアカウント連携',
            self::OwnershipTransferred => '組織オーナー移譲',
            self::ApiKeyIssued => 'API キー発行',
            self::ApiKeyRevoked => 'API キー失効',
            self::AdminMfaReset => '管理者 MFA リセット',
            self::OrgMemberTwoFactorReset => '組織管理者によるメンバー 2FA リセット',
        };
    }
}

```

### app/Listeners/RecordSecurityEvent.php (L19-45)
```php
/**
 * 認証系イベント → security_audit_events の記録 (subscriber)。
 * EventServiceProvider ではなく Event::subscribe で明示登録する。
 */
class RecordSecurityEvent
{
    public function __construct(
        private readonly SecurityEventRecorder $recorder,
    ) {}

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(Login::class, [self::class, 'handleLogin']);
        $events->listen(Failed::class, [self::class, 'handleFailed']);
        $events->listen(Logout::class, [self::class, 'handleLogout']);
        $events->listen(PasswordReset::class, [self::class, 'handlePasswordReset']);
        $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
        $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
        $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
    }

    public function handleLogin(Login $event): void
    {
        $this->recorder->record(SecurityEventType::Login, $this->asUser($event->user), [
            'guard' => $event->guard,
        ]);
    }
```

### app/Http/Controllers/Projects/ProjectMemberController.php (destroy)
```php
    /** メンバー削除 (explicit member の detach。暗黙メンバー = org owner/admin は対象外) */
    public function destroy(Request $request, Project $project, User $user): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 (cross-tenant の {user} の存在を漏らさない)
        $this->resolveOrganizationProject($organization, $project);
        abort_unless(
            $organization->users()->whereKey($user->getKey())->exists(),
            404,
        );
        Gate::authorize('update', $project);

        $project->members()->detach($user->getKey());

        return back()->with('success', 'プロジェクトメンバーを削除しました');
    }
}
```

### routes/web.php (L226-246 メンバー route)
```php
    Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
        ->scopeBindings()
        ->name('organizations.invitations.revoke');
    // {user} は URL 整合 guard で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
    Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
        ->name('organizations.members.update');
    Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
        ->name('organizations.members.destroy');
    // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須。
    // {user} は URL 整合 guard で認可より前に 404)
    Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
        ->middleware('recent-auth')
        ->name('organizations.members.two-factor.reset');
    // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
    Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
        ->middleware('recent-auth')
        ->name('organizations.two-factor-requirement.update');
    // オーナー移譲は step-up (recent-auth) 必須
    Route::post('/organizations/{organization:slug}/transfer-ownership', [OrganizationOwnershipController::class, 'store'])
        ->middleware('recent-auth')
        ->name('organizations.transfer-ownership');
```

### tests/Feature/Support/ProductionEnvGuardTest.php (L1-35)
```php
<?php

declare(strict_types=1);

use App\Support\ProductionEnvGuard;

beforeEach(function (): void {
    // production 必須項目の baseline (すべて有効値)。各テストで 1 項目ずつ崩す。
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    config(['ciphersweet.providers.string.key' => str_repeat('a', 64)]);
    config(['cashier.webhook.secret' => 'whsec_valid']);
    config(['session.secure' => true]);
    config(['app.debug' => false]);
    config(['security.hsts.enabled' => true]);
    config(['security.csp.enabled' => true]);
    config(['debug.login.user' => '']);
    config(['debug.login.password' => '']);
    config(['testing.fake_externals' => false]);
    config(['testing.fake_llm' => false]);
    config(['testing.fake_storage' => false]);
    config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
    config(['trusted_hosts.wildcard_suffixes' => []]);
    config(['trusted_hosts.raw_wildcard_suffixes' => []]);
});

test('全 production 必須項目が埋まっていれば violations は空', function (): void {
    expect((new ProductionEnvGuard)->violations())->toBe([]);
});

test('APP_KEY が空なら violation', function (): void {
    config(['app.key' => '']);
    $errors = (new ProductionEnvGuard)->violations();
    expect($errors)->toHaveCount(1);
    expect($errors[0])->toContain('APP_KEY');
});
```
