## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)

詳細と実装手順は `docs/app-integration-guide.md` §7。すべて Architecture テストで強制されている:

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
   (`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**
   (`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: 外部 URL(特にユーザ入力由来)を取得する機能は
   必ず `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す。
   安全境界は `config/ssrf-pin.php` に pin する(`SsrfPinBoundaryTest` が pin 値を固定)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


---


# あなたの役割

Laravel 12 + Svelte 5 + Inertia のアプリに対する **実装レビュアー**。
以下の詳細設計書 (APPROVED 済み) に対する実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 詳細設計の 8 施策 (S1-S8) が意図どおり実装されているか。
   意図的な逸脱があるなら、その理由がコード/コメントから読み取れるか
2. **正確性**: ロジックの誤り、境界条件、順序依存、race。とくに
   **middleware の解決後実行順** に関する推論が実装と一致しているか
3. **PHPStan level 10 適合性** (型の widen / baseline 化は禁止)
4. **DTO / JsonResource パターン** (`response()->json()` 直書き禁止)
5. **テスト網羅性**: 各施策にテストがあるか。テストが「本当にその不変条件を
   落とせるか」(空疎な assertion / 偽グリーンになっていないか)。
   とくに **Architecture テストの deny-by-default 性**が本物か
   (逃げ道・allowlist・分類漏れの見逃しが無いか)
6. **セキュリティ**: 存在オラクル (実在 id と不在 id で応答が分岐する) が
   本当に閉じたか。新しいオラクルを作っていないか。信頼境界の設定ミス
7. **DESIGN.md 準拠 / Atomic Design 準拠**: 今回の差分に resources/js は含まれないため該当なし

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
  - Critical: セキュリティ・正確性の欠陥、設計との重大な乖離、テストの偽グリーン
  - Warning: 見落としやすい副作用、将来の drift を招く構造
  - Suggestion: 可読性・保守性
- 最後に **全体判定: APPROVED / CHANGES_REQUESTED** を明記する

## 背景 (レビュー時に踏まえるべき文脈)

- 本タスクは監査サイクル 2 の High-1 (存在オラクル) / High-2 (trustProxies 信頼境界) /
  Medium (passkey 監査ログ・throttle) の是正である
- S5 は **意図的にデプロイ時の破壊的変更**。TRUSTED_PROXIES 未宣言で production を
  起動すると fail-fast する。本番のプロキシ構成はリポジトリから確認できない
  (deploy/ / terraform / k8s / nginx が存在しない) ため、CIDR を推測で決め打ちしていない
- S4 は **例外機構を作らない** ことが要件 (allowlist を作ると存在オラクルが再発する)
- 実装中に、設計が想定していなかった Laravel の挙動を 1 件発見している:
  `SortedMiddleware::sortMiddleware()` は **priority map に載っていない middleware を
  一切動かさない**ため、テナント guard だけを priority list に足しても列の末尾に留まる。
  そこで guard と binding の間に挟まっていた web グループの 8 個の middleware も
  「guard より後」として priority list に鎖状に登録している。この判断の妥当性を
  重点的に見てほしい (全 route の解決後 middleware 列を before/after で差分検証し、
  変化したのは {project} を持つ 44 route の guard の位置だけであることは確認済み)
- 設計に無い追加を 2 件行っている:
  (a) `SecurityEventType::EmailChanged` が enum に存在しながら記録経路が無い幽霊 case
      だったため、S7 の構造化 map (deny-by-default) が検出し、記録を配線した
  (b) 担保テストの無かった 6 つの event_type に対する Feature テストを新設した


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
            → EnsureProjectBelongsToRouteOrganization      ← テナント境界 404
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
  → EnsureProjectBelongsToRouteOrganization
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
| S3 | メンバー route の実在性オラクル解消 (`{user}`) | `routes/web.php`, `app/Http/Controllers/Projects/ProjectMemberController.php`, `app/Http/Routing/RouteBindingTypes.php` | High |
| S4 | 順序不変条件の Architecture テスト新設 + 分類 inventory 拡張 | `app/Enums/Security/NestedRouteDefenseMode.php`, `tests/Architecture/NestedRouteIdorDefenseTest.php`, `tests/Architecture/TenantBoundaryOrderingTest.php`(新), `tests/Architecture/ProjectRouteCurrentOrgGuardTest.php` | High |
| S5 | `trustProxies` の env allowlist 化 | `bootstrap/app.php`, `config/trustedproxy.php`(新), `app/Support/TrustedProxyToken.php`(新), `app/Support/TrustedProxiesConfigValidator.php`(新), `app/Support/ProductionEnvGuard.php`, `.env.example`, `docs/trusted-proxies-runbook.md`(新) | High |
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
      status と `json()` が完全一致 (どちらも 404 `not_found`)。
      ヘッダ比較は **volatile ヘッダを除外する normalize helper 経由**で行う
      (`Date` / `Set-Cookie` / `X-RateLimit-*` / `Retry-After` / request id 系は
      連続リクエストで必ず差分が出るため、生の完全一致比較は不安定になる)
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
    EnsureProjectBelongsToRouteOrganization::class,
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
- [ ] `projects.update` — `EnsureProjectBelongsToRouteOrganization` が
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
- `EnsureProjectBelongsToRouteOrganization` が `verified` より前に走るため、
  `current_organization_id` が null のユーザーは `{project}` route で 404 になる。
  変更前も `RequireActiveSubscription` が素通しして同じ 404 に落ちていたため挙動不変
  (`RequireActiveSubscription.php:73-75` + `ResolvesCurrentOrganization::resolveCurrentOrganization`)。

---

## S3: メンバー route の実在性オラクル解消 (`{user}`)

scopeBindings (S3-a) と手動解決 (S3-b) の 2 方式を、route ごとの意味的な親に応じて使い分ける。

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

**なぜ controller での解決で足りるのか** (Codex R1 Critical への回答):
存在オラクルの成立条件は「不在 id と実在の他テナント id で**応答が分岐する**こと」であり、
その分岐は `SubstituteBindings` が**不在 id だけ**を 404 にすることから生まれる。
`{user}` を implicit binding から外すと `SubstituteBindings` は `{user}` を一切解決しないため、
不在 id も実在の非メンバー id も**まったく同じ経路**を辿る。
未契約組織なら両方とも課金ゲートの 302、メール未確認なら両方とも 302、
正常な利用者なら両方とも controller の 404 になる = **分岐しない**。
専用 middleware を新設するより単純で、`{notification}` の既存作法と一致する。

ただしこの保証は「**その param が binding 段で解決されない**」ことに完全依存する
(implicit binding = action 引数のモデル型、explicit binding = `Route::bind()` / `Route::model()` の
**どちらが復活してもオラクルが復活する**)。
S4 に「`ManualOwnerScopedResolution` の param は binding 段で解決されないこと」の
機械検証を置く (§S4-c 検査 3a の 3 条件)。

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
      `.../members/999999999` が **status / body ともに完全同一**であること。
      期待値は 404 固定ではない — 未契約組織では**両方とも課金ゲートの 302** になる。
      検証対象は「2 ケースが一致すること」= 分岐しないこと (オラクルの不成立)。
      302 同士でも遷移先が違えば観測可能な差になるため、**`Location` を含む非 volatile ヘッダ**も
      S1 の normalize helper (volatile ヘッダ除外) を web 応答に転用して比較する
- [ ] Feature: 契約済み・メール確認済みの正常なユーザーでは
      非メンバー user / 不在 user がともに **404** であること
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

    /** テナント guard middleware (project.in-route-org / api.project-in-org) が担う。 */
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
| `project` | `TenantGuardMiddleware` | `project.in-route-org` / `api.project-in-org` が binding 直後に走る (S2) |
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

#### モード → 検査規則の対応表

| モード | 順序に関する規則 | 追加の機械検証 |
|---|---|---|
| `ScopeBindings` | 制約なし (binding 段で 404) | — |
| `ScopedBinder` | 制約なし (binding 段で 404) | — |
| `TenantGuardMiddleware` | **検査 2**: binding と guard の間に短絡なし | guard が実際に列に載っていること |
| `ManualOwnerScopedResolution` | 制約なし (binding 段で解決しない = 分岐しない) | **検査 3a**: その param が binding 段で解決され**ない**こと (implicit / explicit の 3 条件) |
| `UrlIntegrityGuard` | **検査 3b**: binding より後に短絡なし | — |
| `NonResourceParameter` / `PublicGlobalResource` | 対象外 | `isTenantDefense() === false` |

> **正規化の仕様**: `Router::gatherRouteMiddleware()` は `Class:param` 形式を返しうるため、
> 比較前に `explode(':', $m, 2)[0]` で parameter を落とし、**alias 解決後の具象クラス名**で扱う。
> Inertia の middleware はアプリの具象 class (`App\Http\Middleware\HandleInertiaRequests`)
> として現れる (`Inertia\Middleware` ではない)。closure 要素は分類不能として fail。

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
        App\Http\Middleware\HandleInertiaRequests::class => true,   // Inertia version mismatch の 409
        App\Http\Middleware\RequireActiveSubscription::class => true,
        App\Http\Middleware\RequireTwoFactorForEnforcedOrganizations::class => true,
        App\Http\Middleware\BlockTwoFactorDisableForEnforcedOrganizations::class => true,
        App\Http\Middleware\RequireRecentAuth::class => true,
        App\Http\Middleware\RequireApiKeyAbility::class => true,
        App\Http\Middleware\ResolveApiActor::class => true,
        App\Http\Middleware\IdempotentRequest::class => true,
        App\Http\Middleware\EnsureProjectBelongsToRouteOrganization::class => true,
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

検査 3a — **手動解決 param が binding 段で解決されないこと**

`ManualOwnerScopedResolution` モードの param は、**binding 段で解決されない**ことが
オラクル不成立の唯一の根拠である。`SubstituteBindings` は
(i) implicit binding (action 引数のモデル型) と
(ii) explicit binding (`Route::bind()` / `Route::model()` で登録された binder) の
**両方**を実行するため、3 条件すべてを機械検証する (Codex R2 Warning):

1. controller action の対応引数の型が `Illuminate\Database\Eloquent\Model` 派生**でない**こと
   (Reflection で action メソッドの引数型を見る。既存 IV-9(a) 検査と同じ手法)
2. 当該 route × param が `RouteBindingTypes` の**手動解決 exclusion に登録済み**であること
3. 当該 param に **explicit binder が登録されていない**こと
   — `app('router')->getBindingCallback($param) === null` で検証する
   (`Illuminate\Routing\Router::getBindingCallback()` は public。
   `Route::bind('user', ...)` / `Route::model('user', ...)` のどちらでも `$binders` に入る)

加えて**振る舞い**でも固定する: 後段短絡が発生する状態
(未契約組織 / メール未確認) で「実在の非メンバー id」と「不在 id」の応答が
status / body ともに同一であること (§S3-b テスト計画)。
静的 3 条件が将来破られても、この Feature テストが red になる二段構え。

検査 3b — **inline guard route の制約**

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
- (d) **named limiter の振る舞い検査** (Codex R1 Warning): `ThrottleRequests` 自体が
  route param を読まなくても、`throttle:{bucket}` の limiter closure が
  `$request->route(...)` を読めば存在依存になる。静的検査は closure まで届かないため、
  テナント guard を持つ route が使う全 bucket
  (`api-read` / `api-write` / `render-trigger` / `passkeys`) について
  「**ある id で bucket を使い切ったあと、別の id でも 429 になる**」ことを Feature テストで固定する
  (= limiter キーが route param を含まないことの behavioral proof)

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
- `app/Support/TrustedProxyToken.php` (**新規**)
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

use App\Support\TrustedProxyToken;

$rawProxies = array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')));

return [
    /*
    | framework (Illuminate\Http\Middleware\TrustProxies) が読む正本。
    | **検証を通過した値のみ**。空配列 = 何も信頼しない (= REMOTE_ADDR が client IP)。
    */
    'proxies' => array_values(array_filter(
        $rawProxies,
        // 判定は App\Support\TrustedProxyToken に一本化する (config 段と validator 段で
        // 同じ関数を使い、判定のズレによる silent drop / 誤 reject を作らない)。
        // config:cache は評価結果 (plain array) を保存するため関数呼び出しでも問題ない。
        static fn (string $v): bool => TrustedProxyToken::isTrustableAddress($v),
    )),

    /*
    | 生 token (空要素・空白のみ要素も保持)。config 段で silent drop された値を
    | 起動時 fail-fast で表面化させるために TrustedProxiesConfigValidator が読む。
    | Guard 側で env() を直接読むと config:cache 後に null 化するため config 経由で expose。
    */
    'raw_proxies' => $rawProxies,
];
```

#### `app/Support/TrustedProxyToken.php` (新規)

`TRUSTED_PROXIES` の 1 token の妥当性判定を config 段と validator 段で共有する純粋クラス。
正規表現による緩い判定 (`999.999.999.999/999` を通す) を避ける (Codex R1 Critical)。

```php
final class TrustedProxyToken
{
    /** 「プロキシは無い」の明示宣言 (空 list に写す sentinel)。 */
    public const string NONE = 'none';

    /** 直接の接続元を信頼する予約値 (framework が REMOTE_ADDR に展開。production では禁止)。 */
    public const string REMOTE_ADDR = 'REMOTE_ADDR';

    /** framework に渡してよい値か (単一 IP / CIDR / REMOTE_ADDR)。 */
    public static function isTrustableAddress(string $token): bool
    {
        if ($token === self::REMOTE_ADDR) {
            return true;
        }
        if (filter_var($token, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return self::isCidr($token);
    }

    /** CIDR 書式か (IP 部は FILTER_VALIDATE_IP、prefix は IPv4 0-32 / IPv6 0-128)。 */
    public static function isCidr(string $token): bool
    {
        $parts = explode('/', $token);
        if (count($parts) !== 2) {
            return false;
        }
        [$address, $prefix] = $parts;
        if ($prefix === '' || ctype_digit($prefix) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return (int) $prefix <= 32;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return (int) $prefix <= 128;
        }

        return false;
    }
}
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

検査 (production)。**順序が重要** — `none` sentinel を先に処理してから書式検査に入る
(Codex R1 Critical: silent-drop 検査を先に回すと `none` 自身が「落ちた不正値」として reject される):

```
$tokens = raw のうち空文字を除いたもの (trim 済)

1. $tokens に '*' または '**' が含まれる
     → reject 「全アドレス信頼は XFF 偽装を通す。実 hop の CIDR を列挙すること」
2. $tokens に 'none' が含まれる
     2-1. count($tokens) !== 1 → reject 「none は単独で宣言すること (曖昧宣言)」
     2-2. $proxies !== []      → reject 「none 宣言なのに proxies が非空 = 設定の不整合」
     2-3. それ以外             → **正常終了** (プロキシ無し構成の明示宣言)
3. $tokens に 'REMOTE_ADDR' が含まれる
     → reject 「production では直接接続元の一括信頼を許さない。実 hop の CIDR を列挙すること」
4. $tokens のうち TrustedProxyToken::isTrustableAddress() を満たさないものがある
     → reject 「書式不正: {token}。IP または CIDR で指定すること」(config 段の silent drop を表面化)
5. $proxies === []
     → reject 「TRUSTED_PROXIES が未設定。プロキシが無い構成なら none を明示すること」
```

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
- [ ] Unit (`tests/Unit/Support/TrustedProxyTokenTest.php` 新規):
      `10.0.0.0/8` / `192.168.1.1` / `2001:db8::/32` / `REMOTE_ADDR` が true、
      `999.999.999.999/999` / `10.0.0.0/33` / `2001:db8::/129` / `10.0.0.0/` /
      `10.0.0.0/abc` / `*` / 空文字が false
- [ ] Unit (`tests/Unit/Support/TrustedProxiesConfigValidatorTest.php` 新規): 検査 1-5 × 正常系。
      特に **`none` 単独は正常終了**、`none` + 他 token は reject、
      `none` 宣言なのに `proxies` が非空なら reject の 3 ケースを明示的に持つ
- [ ] `ProductionEnvGuardTest`: baseline に
      `config(['trustedproxy.proxies' => ['10.0.0.0/8'], 'trustedproxy.raw_proxies' => ['10.0.0.0/8']])` を追加し、
      新規 violation ケース (未設定 / `*` / `REMOTE_ADDR` / 書式不正 / `none` 併記) を
      `toHaveCount(1)` で追加。`none` 単独 baseline でも violations が空になることも確認
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

- TypeScript 型定義: **なし** (確認済み)。
  `rg 'password_changed|two_factor_enabled|api_key_issued' resources/js app/Filament` が **0 件**で、
  event_type の文字列はどこにも直書きされていない (label は enum の `label()` 経由)。
  したがって case 追加は追記のみで破綻しない
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

**vendor event の実体 (確認済み)**:
`vendor/laravel/passkeys/src/Events/PasskeyRegistered.php` /
`PasskeyDeleted.php` はいずれも

```php
public function __construct(
    public Authenticatable $user,
    public Passkey $passkey,   // Laravel\Passkeys\Passkey (App\Models\Passkey が実体)
) {}
```

であり、`$event->user` / `$event->passkey` はともに public promoted property。
`$event->passkey->getKey()` は `mixed` を返すが `metadata: array<string, mixed>` に入れるため
PHPStan level 10 で追加のキャストは不要。`$event->user` は `Authenticatable` のため
既存 `asUser()` (`$user instanceof User ? $user : null`) をそのまま使える。

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
        // **全 case が同一形式**: ('event' | 'caller') + 'covered_by' を必須にする
        // (片方でも欠けるとテストが fail する = 空疎な登録を作らせない)
        SecurityEventType::Login->value => [
            'event' => Illuminate\Auth\Events\Login::class,
            'covered_by' => 'tests/Feature/Auth/...Test.php',
        ],
        // ... 既存 case も全件同形式に揃える (実装時に既存の担保先テストを特定して記入)
        SecurityEventType::AdminMfaReset->value => [
            'caller' => App\Console\Commands\ResetAdminMfaCommand::class,
            'covered_by' => 'tests/Feature/Console/ResetAdminMfaCommandTest.php',
        ],
        SecurityEventType::OrgMemberTwoFactorReset->value => [
            'caller' => App\Http\Controllers\Organizations\OrganizationMemberController::class,
            'covered_by' => 'tests/Feature/Organizations/...Test.php',
        ],
        SecurityEventType::PasskeyRegistered->value => [
            'event' => Laravel\Passkeys\Events\PasskeyRegistered::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
        SecurityEventType::PasskeyDeleted->value => [
            'event' => Laravel\Passkeys\Events\PasskeyDeleted::class,
            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
        ],
    ];
}
```

検査:

1. enum 全 case と map key の**完全一致** (双方向)
2. `event` 宣言の case: `Event::getRawListeners()` に当該イベントの listener が登録されていること
3. `caller` 宣言の case: 当該クラスが実在し `SecurityEventRecorder` を参照していること
4. 各 case に `covered_by` が**必ず**あり、そのファイルが実在すること。
   さらに**空疎な登録を防ぐ**ため、`covered_by` のファイル内容に
   当該 case の `value` (例: `passkey_registered`) が出現することを検査する
   (= そのテストが本当にその event_type を検証している証跡。Codex R2 Warning)
5. 各エントリは `event` か `caller` の**いずれか一方**を持つこと (両方 / 両方なしは fail)

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
- [ ] Architecture: 上記 5 検査
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

**limiter キーの実体** (`FortifyServiceProvider.php:188-194`):

```php
RateLimiter::for('passkeys', function (Request $request) {
    $identifier = $request->user()?->getAuthIdentifier();

    return Limit::perMinute(10)->by(
        is_scalar($identifier) ? 'passkey|'.$identifier : 'passkey|'.$request->ip(),
    );
});
```

`ThrottleRequests` は priority list 上 `AuthenticatesRequests` より後のため
`$request->user()` は解決済みで、`passkey.destroy` のキーは `passkey|{user id}` になる。
ただしこれは**設計上の期待**であり、キーの実体は limiter 定義次第なので
**別ユーザー同士で bucket が共有されないこと**を Feature テストで固定する (Codex R1 Warning)。

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
- [ ] Feature: **別ユーザーで bucket が共有されない** (user A が 10 回使い切っても user B は 429 にならない)
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


## 実装差分 (git diff)

```diff
diff --git a/.env.example b/.env.example
index cced2bf..bc7e433 100644
--- a/.env.example
+++ b/.env.example
@@ -156,6 +156,14 @@ PRIMARY_HOST=
 TRUSTED_HOSTS_ADDITIONAL=
 # 末尾一致で許可する suffix の CSV (先頭 dot + 2 ラベル以上。例: .preview.example.com)
 TRUSTED_HOSTS_WILDCARD_SUFFIXES=
+# client IP / X-Forwarded-Proto の信頼境界 (config/trustedproxy.php)。
+# production では未宣言だと起動時に fail-fast する (ProductionEnvGuard)。
+# **すべての hop** の IP / CIDR を CSV で列挙する (CloudFront -> ALB なら両方)。
+# hop の取りこぼしは client IP をその hop に固定し、全利用者が 1 バケットに落ちる (自己 DoS)。
+# プロキシが無い構成では `none` と明示する。ローカルで Valet TLS 越しに開発する場合は
+# `REMOTE_ADDR` (production では禁止)。`*` は使用不可 (XFF 偽装が通る)。
+# 運用手順は docs/trusted-proxies-runbook.md。
+TRUSTED_PROXIES=
 # 利用規約の同意バージョン (規約改定時に更新。config/legal.php)
 LEGAL_CONSENT_VERSION=draft-1
 # 問い合わせ (Inquiry) の保持日数 (spam=created_at / closed=closed_at 基準。inquiry:purge が日次削除)
diff --git a/app/Actions/Fortify/UpdateUserProfileInformation.php b/app/Actions/Fortify/UpdateUserProfileInformation.php
index e07da87..4bbd7aa 100644
--- a/app/Actions/Fortify/UpdateUserProfileInformation.php
+++ b/app/Actions/Fortify/UpdateUserProfileInformation.php
@@ -4,8 +4,10 @@
 
 namespace App\Actions\Fortify;
 
+use App\Enums\SecurityEventType;
 use App\Models\User;
 use App\Notifications\EmailChangedSecurityNotification;
+use App\Services\Security\SecurityEventRecorder;
 use Illuminate\Support\Facades\Notification;
 use Illuminate\Support\Facades\Validator;
 use Illuminate\Validation\ValidationException;
@@ -22,6 +24,10 @@
  */
 class UpdateUserProfileInformation implements UpdatesUserProfileInformation
 {
+    public function __construct(
+        private readonly SecurityEventRecorder $recorder,
+    ) {}
+
     /**
      * @param  array<string, mixed>  $input
      */
@@ -57,6 +63,12 @@ public function update(User $user, array $input): void
             'email_verified_at' => null,
         ])->save();
 
+        // 監査証跡。SecurityEventType::EmailChanged は enum に存在しながら記録経路が
+        // 無かった (T108 S7 の SecurityEventCoverageTest が deny-by-default で検出)。
+        // 通知 (検知導線) と監査ログ (事後追跡) は同じ事象の両輪なので同じ場所で記録する。
+        // 平文 email は metadata に載せない (PII は CipherSweet 管理の users 側に閉じる)。
+        $this->recorder->record(SecurityEventType::EmailChanged, $user);
+
         // 旧アドレスへの on-demand セキュリティ通知 (アカウントを持たない宛先にも送れる経路)
         Notification::route('mail', $oldEmail)
             ->notify(new EmailChangedSecurityNotification);
diff --git a/app/Enums/Security/NestedRouteDefenseMode.php b/app/Enums/Security/NestedRouteDefenseMode.php
index 4ed4c7b..367af84 100644
--- a/app/Enums/Security/NestedRouteDefenseMode.php
+++ b/app/Enums/Security/NestedRouteDefenseMode.php
@@ -5,23 +5,60 @@
 namespace App\Enums\Security;
 
 /**
- * nested route (親子) の IDOR 防御方式。
+ * route parameter ごとの IDOR / 存在オラクル 防御方式。
  *
- * 子リソースを URL で受ける route が「子は必ず URL 上の親 (またはテナント) に属する」不変条件を
+ * URL で受ける各 parameter が「その id は必ず URL 上の親 (またはテナント) に属する」不変条件を
  * どの機構で担保しているかを明示分類する。`NestedRouteIdorDefenseTest` の inventory が本 enum を
- * 値に持ち、2 個以上の route パラメータを取る named route を deny-by-default で分類漏れ・drift
- * から守る。
+ * 値に持ち、**1 個以上**の route parameter を取る named route を deny-by-default で分類漏れ・
+ * drift から守る。さらに `TenantBoundaryOrderingTest` が、モードごとに要求される
+ * **解決後 middleware の順序不変条件**を機械検証する。
  *
  * テンプレートは `Route::scopeBindings()` を既定 (主防御) とする (親 relation 経由で子を解決し、
  * 不整合は認可より前に 404)。model binding にならない子 (payload 由来・文字列 token 等) や
- * 解決順序の都合で scopeBindings に乗らない route のみ inline guard を使う。
+ * 解決順序の都合で scopeBindings に乗らない route のみ他方式を使う。
  * アプリ固有の防御方式が必要になったら case を追加し、docs/template-divergence.md に記録する。
+ *
+ * **例外機構は設けない**。テナント防御が要る param を「対象外」と宣言して逃がすと
+ * 存在オラクルがそのまま再発するため、非テナントモードの宣言には必ず理由の登録を要求する
+ * (`NestedRouteIdorDefenseTest` の reason 突合)。
  */
 enum NestedRouteDefenseMode: string
 {
+    // --- テナント防御モード (id が親テナントに属することを担保する) ---
+
     /** Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404)。テンプレートの主防御。 */
     case ScopeBindings = 'scope_bindings';
 
-    /** route-model binding + inline 親子整合 guard (authorize より前に子∈親/テナントを検査し不整合は 404)。 */
+    /** Route::bind() の explicit binder が actor スコープで解決する (不整合は binding 段で 404)。 */
+    case ScopedBinder = 'scoped_binder';
+
+    /** テナント guard middleware (project.in-route-org / api.project-in-org) が担う。 */
+    case TenantGuardMiddleware = 'tenant_guard_middleware';
+
+    /** implicit binding を使わず controller が owner-scoped relation から手動解決する。 */
+    case ManualOwnerScopedResolution = 'manual_owner_scoped_resolution';
+
+    /** route-model binding + inline 親子整合 guard (authorize より前に検査し不整合は 404)。 */
     case UrlIntegrityGuard = 'url_integrity_guard';
+
+    // --- 非テナントモード (テナント防御の対象ではないことを明示宣言する) ---
+
+    /**
+     * テナント親子関係の対象にならない param。
+     * 固定集合 (provider / intent)、署名付き URL の構成要素 (id / hash / token)、
+     * 非モデル文字列 (path)、local 専用 debug 経路の対象指定などが該当する。
+     */
+    case NonResourceParameter = 'non_resource_parameter';
+
+    /** テナントに属さない公開リソース。 */
+    case PublicGlobalResource = 'public_global_resource';
+
+    /** テナント防御モードか (順序不変条件の検査対象か)。 */
+    public function isTenantDefense(): bool
+    {
+        return match ($this) {
+            self::NonResourceParameter, self::PublicGlobalResource => false,
+            default => true,
+        };
+    }
 }
diff --git a/app/Enums/SecurityEventType.php b/app/Enums/SecurityEventType.php
index adbe8fa..0ccb85c 100644
--- a/app/Enums/SecurityEventType.php
+++ b/app/Enums/SecurityEventType.php
@@ -6,7 +6,11 @@
 
 /**
  * security_audit_events に記録するイベント種別 (固定集合)。
- * 追加時は RecordSecurityEvent の購読 map も同一 PR で更新すること。
+ *
+ * **case を追加したら記録経路も同一 PR で配線すること**。
+ * 全 case の記録経路 (購読イベント / 直接呼び出し元 / 担保テスト) は
+ * tests/Architecture/SecurityEventCoverageTest.php の構造化 map が
+ * deny-by-default で機械保証する (map と enum の完全一致 + 担保テストの実在)。
  */
 enum SecurityEventType: string
 {
@@ -28,6 +32,9 @@ enum SecurityEventType: string
     // 組織管理者によるメンバー 2FA リセット (OrganizationMemberController::resetTwoFactor が
     // 直接記録する。RecordSecurityEvent の購読対象外)
     case OrgMemberTwoFactorReset = 'org_member_two_factor_reset';
+    // パスキー (単独でログインできる強い資格) の増減。vendor イベントを購読して記録する
+    case PasskeyRegistered = 'passkey_registered';
+    case PasskeyDeleted = 'passkey_deleted';
 
     public function label(): string
     {
@@ -47,6 +54,8 @@ public function label(): string
             self::ApiKeyRevoked => 'API キー失効',
             self::AdminMfaReset => '管理者 MFA リセット',
             self::OrgMemberTwoFactorReset => '組織管理者によるメンバー 2FA リセット',
+            self::PasskeyRegistered => 'パスキーの登録',
+            self::PasskeyDeleted => 'パスキーの削除',
         };
     }
 }
diff --git a/app/Http/Controllers/Projects/ProjectMemberController.php b/app/Http/Controllers/Projects/ProjectMemberController.php
index 39f2fcc..ed32908 100644
--- a/app/Http/Controllers/Projects/ProjectMemberController.php
+++ b/app/Http/Controllers/Projects/ProjectMemberController.php
@@ -63,19 +63,28 @@ public function store(Request $request, Project $project): RedirectResponse
         return back()->with('success', 'プロジェクトメンバーを追加しました');
     }
 
-    /** メンバー削除 (explicit member の detach。暗黙メンバー = org owner/admin は対象外) */
-    public function destroy(Request $request, Project $project, User $user): RedirectResponse
+    /**
+     * メンバー削除 (explicit member の detach。暗黙メンバー = org owner/admin は対象外)。
+     *
+     * {user} は **implicit binding を使わない** (action 引数を string で受ける)。
+     * implicit binding だと「不在 id = binding 段の 404 / 実在の非メンバー = 後段 middleware の
+     * 302/402」という差分が users.id の存在オラクルになるため
+     * (audit-cycle-2 High-1 横断)、組織メンバーに閉じた relation から手動解決して
+     * 両者を同一の応答に落とす。binding 段で解決されない = 不在も実在も同じ経路を辿る。
+     * 型制約 (数値・18 桁上限) は RouteBindingTypes の pattern が担保する。
+     */
+    public function destroy(Request $request, Project $project, string $user): RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
         // URL 整合 guard: 認可より前に 404 (cross-tenant の {user} の存在を漏らさない)
         $this->resolveOrganizationProject($organization, $project);
-        abort_unless(
-            $organization->users()->whereKey($user->getKey())->exists(),
-            404,
-        );
+
+        /** @var User $member 組織メンバー以外・不在 id はここで等しく 404 */
+        $member = $organization->users()->whereKey($user)->firstOrFail();
+
         Gate::authorize('update', $project);
 
-        $project->members()->detach($user->getKey());
+        $project->members()->detach($member->getKey());
 
         return back()->with('success', 'プロジェクトメンバーを削除しました');
     }
diff --git a/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php b/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
index ac72fa7..d7ac735 100644
--- a/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
+++ b/app/Http/Middleware/EnsureProjectBelongsToApiOrganization.php
@@ -25,14 +25,20 @@
  *  - API: API キー / OAuth token から確定した request attribute 'organization'
  *         (ApiKeyGuard / ResolveApiActor が注入。ResolvesApiOrganization::resolveOrganization)
  *
- * 順序契約: api グループ (SubstituteBindings) → auth → throttle → resolve.api-actor
- *           → api-key.ability → **api.project-in-org** → idempotent → controller
+ * 順序契約 (**宣言順ではなく bootstrap/app.php の priority list が正本**):
+ *   auth → throttle → resolve.api-actor → SubstituteBindings
+ *     → **api.project-in-org** → api-key.ability → idempotent → controller
  * `organization` attribute が前提のため **resolve.api-actor より後**、
+ * ability 不足時に cross-org の実在を 403 で漏らさないため **api-key.ability より前**、
  * cross-org リクエストで idempotency 行を作らせないため **idempotent より前**に置く。
+ * とりわけ **SubstituteBindings の直後**であることが本質で、間に 404 以外で短絡する
+ * middleware があると「他組織に実在 = その短絡の応答 / 不在 = binding の 404」という
+ * 1 bit の存在オラクルになる (audit-cycle-2 High-1)。
  * {project} を持たない route では no-op (group 一括付与を許容し、将来の route 追加時の
  * guard 漏れを防ぐ)。
  *
- * 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が deny-by-default で固定する。
+ * 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest と
+ * tests/Architecture/TenantBoundaryOrderingTest が deny-by-default で固定する。
  * controller の inline guard は二重防御として残す (middleware の付け漏れ・
  * withoutMiddleware への最終防衛線)。
  */
diff --git a/app/Http/Middleware/ResolveApiActor.php b/app/Http/Middleware/ResolveApiActor.php
index 4c696ef..9e87b76 100644
--- a/app/Http/Middleware/ResolveApiActor.php
+++ b/app/Http/Middleware/ResolveApiActor.php
@@ -36,8 +36,22 @@
  * user-token 経路では下流互換のため request attribute `organization` も注入する
  * (API キー経路は ApiKeyGuard が注入済み。ResolvesApiOrganization / rate limiter が参照)。
  *
- * 順序契約: auth → throttle → resolve.api-actor → api-key.ability:X → (idempotent) → controller
+ * 順序契約: auth → throttle → **resolve.api-actor → SubstituteBindings** →
+ * api.project-in-org → api-key.ability:X → (idempotent) → controller
  * (ApiGuardAllowlistInvariantTest が dual/oauth 分類ごと固定)。
+ *
+ * 本 middleware は route binding に依存しない (`$request->route(...)` を読まない)。
+ * この性質が `SubstituteBindings` より前に置ける根拠であり、
+ * `TenantBoundaryOrderingTest` の pre-binding 短絡 inventory が静的検査で固定する。
+ * binding より前に置く理由は、actor 解決失敗 (401/403) を
+ * 「不在 id の 404 がまだ起きていない時点」で返し、不在 id と実在 id の応答を
+ * 一致させるため (audit-cycle-2 High-1 横断)。
+ *
+ * 副作用の注記: OAuth 経路の `$session->touchLastUsedAt()` は binding より前に走るため、
+ * **不在 id へのリクエストでも last_used_at が更新される**。これは「そのトークンで
+ * API を叩いた」という事実の記録であり、リソースの実在とは無関係のため意図した挙動。
+ * むしろ binding 成否で更新有無が変わると、CLI セッション一覧の last_used_at 自体が
+ * 存在オラクルになる (更新される = その id は実在した) ため、前倒しが正しい。
  */
 class ResolveApiActor
 {
diff --git a/app/Http/Routing/RouteBindingTypes.php b/app/Http/Routing/RouteBindingTypes.php
index 856f5c8..04878b4 100644
--- a/app/Http/Routing/RouteBindingTypes.php
+++ b/app/Http/Routing/RouteBindingTypes.php
@@ -99,6 +99,16 @@ final class RouteBindingTypes
             'routes' => ['notifications.open', 'notifications.read'],
             'reason' => 'cross-user 404 のため controller が $user->notifications() 経由で解決する',
         ],
+        // ProjectMemberController::destroy は現在組織の users() から解決する。
+        // implicit binding のままだと「不在 id = binding 404 / 実在の非メンバー = 後段短絡の
+        // 302」と分岐し users.id の存在オラクルになる (audit-cycle-2 High-1 横断)。
+        // {user} の意味的な親は {project} ではなく現在組織のため scopeBindings は採れない
+        // (Project::users() が存在しない。Project::members() は明示メンバーのみで意味が狭い)。
+        'user' => [
+            'routes' => ['projects.members.destroy'],
+            'reason' => '存在オラクル封じのため controller が $organization->users() 経由で解決する'
+                .' (binding 段で解決しないことが不在 id と実在の非メンバーを同一応答にする根拠)',
+        ],
     ];
 
     /**
diff --git a/app/Listeners/RecordSecurityEvent.php b/app/Listeners/RecordSecurityEvent.php
index 9bc1756..1f446b8 100644
--- a/app/Listeners/RecordSecurityEvent.php
+++ b/app/Listeners/RecordSecurityEvent.php
@@ -15,6 +15,8 @@
 use Laravel\Fortify\Events\RecoveryCodesGenerated;
 use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
 use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
 
 /**
  * 認証系イベント → security_audit_events の記録 (subscriber)。
@@ -35,6 +37,8 @@ public function subscribe(Dispatcher $events): void
         $events->listen(TwoFactorAuthenticationConfirmed::class, [self::class, 'handleTwoFactorConfirmed']);
         $events->listen(TwoFactorAuthenticationDisabled::class, [self::class, 'handleTwoFactorDisabled']);
         $events->listen(RecoveryCodesGenerated::class, [self::class, 'handleRecoveryCodesGenerated']);
+        $events->listen(PasskeyRegistered::class, [self::class, 'handlePasskeyRegistered']);
+        $events->listen(PasskeyDeleted::class, [self::class, 'handlePasskeyDeleted']);
     }
 
     public function handleLogin(Login $event): void
@@ -81,6 +85,34 @@ public function handleRecoveryCodesGenerated(RecoveryCodesGenerated $event): voi
         ]);
     }
 
+    /**
+     * パスキーは単独でログインできる強い資格のため、増減は監査上最重要事象として記録する
+     * (セッション乗っ取り後の永続化を事後追跡できるようにする)。
+     * credential 本体 (公開鍵 / signature counter) は metadata に載せない。
+     */
+    public function handlePasskeyRegistered(PasskeyRegistered $event): void
+    {
+        $this->recorder->record(SecurityEventType::PasskeyRegistered, $this->asUser($event->user), [
+            'passkey_id' => $event->passkey->getKey(),
+        ]);
+    }
+
+    /**
+     * 削除は EnsureLoginMethodRemains の transaction 内で発火するため、
+     * rollback 時は監査行も消える (削除自体も消えるので整合。テストで固定済み)。
+     *
+     * 注記: SecurityEventRecorder は Throwable を catch して report() するが、
+     * pgsql では transaction 内の失敗文が transaction 全体を abort させるため
+     * 「catch したのに後続 SQL が全部落ちる」経路が理論上ある。これは既存の全 recorder
+     * 呼び出しに共通する性質であり、本 handler で新設したものではない。
+     */
+    public function handlePasskeyDeleted(PasskeyDeleted $event): void
+    {
+        $this->recorder->record(SecurityEventType::PasskeyDeleted, $this->asUser($event->user), [
+            'passkey_id' => $event->passkey->getKey(),
+        ]);
+    }
+
     private function asUser(mixed $user): ?User
     {
         return $user instanceof User ? $user : null;
diff --git a/app/Providers/PasskeyServiceProvider.php b/app/Providers/PasskeyServiceProvider.php
index 59ae756..1e4a67b 100644
--- a/app/Providers/PasskeyServiceProvider.php
+++ b/app/Providers/PasskeyServiceProvider.php
@@ -55,6 +55,25 @@
  */
 final class PasskeyServiceProvider extends ServiceProvider
 {
+    /**
+     * throttle を後付けする passkey route。
+     *
+     * vendor (Fortify) の $passkeyMiddleware は $throttle を含まないため、
+     * passkey.destroy **だけ** throttle が付かない
+     * (vendor/laravel/fortify/routes/routes.php)。
+     * EnsureLoginMethodRemains が毎リクエスト DB::transaction + User 行 lockForUpdate を
+     * 取るため、認証済みユーザーが自分の User 行に無制限のロック競合を起こせる
+     * (audit-cycle-2 Medium-2)。他の passkey route と同じ 10/min に揃える。
+     *
+     * ThrottleRequests は Laravel の priority list に含まれ Authenticate より後に走るため、
+     * キーは user 単位になる (未認証 IP fallback には落ちない)。これは limiter 定義次第の
+     * **設計上の期待**なので、別ユーザー同士で bucket が共有されないことを
+     * PasskeyThrottleTest が振る舞いで固定する。
+     */
+    private const THROTTLE_ROUTE_NAMES = [
+        'passkey.destroy',
+    ];
+
     /** recent-auth を後付けする passkey route (credential 集合を触る管理経路) */
     private const RECENT_AUTH_ROUTE_NAMES = [
         'passkey.registration-options',
@@ -114,9 +133,15 @@ private static function attachMiddlewareToPasskeyRoutes(Application $app): void
         $routes = $app->make(Router::class)->getRoutes();
         $routes->refreshNameLookups();
 
-        // **順序が重要**: recent-auth を先に通し、その後で手段保持を検査する。
+        // **順序が重要**: throttle → recent-auth → 手段保持 の順に通す。
+        // throttle を先に並べることで、priority 適用後も ThrottleRequests が
+        // RequireRecentAuth より前になる (無制限のロック競合を最外周で止める)。
         // 逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
-        // PasskeyRouteProtectionTest が gatherMiddleware() 上の index 比較で固定する。
+        // PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
+        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
+            self::appendMiddlewareIfMissing($routes, $name, 'throttle:passkeys');
+        }
+
         foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
             self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
         }
diff --git a/app/Support/ProductionEnvGuard.php b/app/Support/ProductionEnvGuard.php
index b2d81d0..7a89a8e 100644
--- a/app/Support/ProductionEnvGuard.php
+++ b/app/Support/ProductionEnvGuard.php
@@ -22,6 +22,8 @@
  * - TESTING_FAKE_LLM=false (LLM fake の本番混入防止)
  * - TESTING_FAKE_STORAGE=false (storage fake の本番混入防止)
  * - TrustHosts allowlist (Host header injection 防御の allowlist 非空・書式)
+ * - TrustProxies allowlist (client IP / X-Forwarded-Proto の信頼境界。未宣言・`*`・
+ *   REMOTE_ADDR・書式不正を拒否。プロキシ無し構成は `none` の明示宣言を要求する)
  */
 class ProductionEnvGuard
 {
@@ -112,6 +114,17 @@ public function violations(): array
             $errors[] = $e->getMessage();
         }
 
+        // client IP の信頼境界 (TrustProxies allowlist) を起動時検証。
+        // 未宣言だと XFF 偽装 or hop 取りこぼしによる自己 DoS のどちらかに倒れるため、
+        // production では「hop を明示宣言する」ことを起動条件にする (audit-cycle-2 High-2)。
+        $proxies = $this->stringList(config('trustedproxy.proxies', []));
+        $rawProxies = $this->stringList(config('trustedproxy.raw_proxies', []), keepEmpty: true);
+        try {
+            (new TrustedProxiesConfigValidator)->validateForProduction($proxies, $rawProxies);
+        } catch (Throwable $e) {
+            $errors[] = $e->getMessage();
+        }
+
         return $errors;
     }
 
diff --git a/app/Support/TrustedProxiesConfigValidator.php b/app/Support/TrustedProxiesConfigValidator.php
new file mode 100644
index 0000000..2f7a2b6
--- /dev/null
+++ b/app/Support/TrustedProxiesConfigValidator.php
@@ -0,0 +1,99 @@
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
+        foreach (['*', '**'] as $wildcard) {
+            if (in_array($wildcard, $tokens, true)) {
+                throw new RuntimeException(sprintf(
+                    'TRUSTED_PROXIES contains "%s". Trusting every address lets clients forge '
+                    .'X-Forwarded-For (client IP, rate limits and audit logs become attacker-controlled). '
+                    .'Enumerate the actual proxy hops as IP/CIDR instead.',
+                    $wildcard,
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
index 0000000..8999c10
--- /dev/null
+++ b/app/Support/TrustedProxyToken.php
@@ -0,0 +1,56 @@
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
+    /** framework に渡してよい値か (単一 IP / CIDR / REMOTE_ADDR)。 */
+    public static function isTrustableAddress(string $token): bool
+    {
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
diff --git a/bootstrap/app.php b/bootstrap/app.php
index ccbc6d7..d5bc8e9 100644
--- a/bootstrap/app.php
+++ b/bootstrap/app.php
@@ -29,11 +29,13 @@
 use App\Http\Resources\Billing\QuotaExceededResource;
 use App\Support\Http\AdminPanelPath;
 use Illuminate\Auth\AuthenticationException;
+use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
 use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
 use Illuminate\Foundation\Application;
 use Illuminate\Foundation\Configuration\Exceptions;
 use Illuminate\Foundation\Configuration\Middleware;
 use Illuminate\Http\Request;
+use Illuminate\Routing\Middleware\SubstituteBindings;
 use Inertia\Inertia;
 use Inertia\Middleware\EncryptHistory;
 use Symfony\Component\HttpFoundation\Response;
@@ -46,12 +48,36 @@
         health: '/up',
     )
     ->withMiddleware(function (Middleware $middleware): void {
-        // HTTPS リダイレクトは最外周 (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)
-        $middleware->prepend(RedirectToHttps::class);
+        /*
+         | HTTPS リダイレクト (FORCE_HTTPS_REDIRECT で有効化。LB 終端構成では off)。
+         |
+         | **prepend にしない**: Middleware::getGlobalMiddleware() は
+         | array_merge($prepends, $global, $appends) を返すため、prepend すると
+         | TrustProxies **より前**に走り、$request->isSecure() が X-Forwarded-Proto を
+         | 見られない。LB 終端 + FORCE_HTTPS_REDIRECT=true で 308 の無限ループになる。
+         | append することで TrustProxies の後・route group より前で走る。
+         */
+        $middleware->append(RedirectToHttps::class);
 
-        // LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)
+        /*
+         | LB / reverse proxy 終端構成での X-Forwarded-* 信頼 (HTTPS 検出・client IP 復元)。
+         |
+         | `at:` は **渡さない**。Laravel の TrustProxies は
+         |   $this->proxies() ?: config('trustedproxy.proxies')
+         | の順で解決し、`TrustProxies::at()` を呼ばなければ config へ落ちる
+         | (vendor/laravel/framework/src/Illuminate/Http/Middleware/TrustProxies.php)。
+         | withMiddleware の callback は config 読込より前に走るため `at:` に closure は渡せず
+         | (trustHosts と違い array|string|null のみ)、env 由来の allowlist は config 経由が唯一の道。
+         |
+         | かつて `at: '*'` だった。これは全アドレスを trusted proxy 扱いにするため
+         | $request->ip() が XFF 最左 = **クライアントが自由に書ける値**になり、
+         | IP ベースの rate limit / reCAPTCHA / 監査ログがすべて無効化されていた
+         | (audit-cycle-2 High-2)。
+         |
+         | 設定は TRUSTED_PROXIES (config/trustedproxy.php)。production で未宣言なら
+         | ProductionEnvGuard が起動時 fail-fast する。運用契約は docs/trusted-proxies-runbook.md。
+         */
         $middleware->trustProxies(
-            at: '*',
             headers: Request::HEADER_X_FORWARDED_FOR
                 | Request::HEADER_X_FORWARDED_HOST
                 | Request::HEADER_X_FORWARDED_PORT
@@ -145,12 +171,14 @@
             'verified.or-back' => EnsureEmailIsVerifiedOrBack::class,
             // web の {project} route の URL 整合 guard。cross-org の {project} を
             // FormRequest の DB ルール (unique/exists) より前に 404 へ落とす
-            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
+            // (存在オラクル防止。網羅性は ProjectRouteCurrentOrgGuardTest が固定)。
+            // **実行位置は上の priority list が正本** (SubstituteBindings 直後)。
             'project.in-route-org' => EnsureProjectBelongsToRouteOrganization::class,
             // REST API v1 用の同等 guard (組織は API キー / OAuth token から確定するため
             // web セッションの current org とは解決元が違う = 別 alias)。
-            // resolve.api-actor より後・idempotent より前に置くこと (順序契約は
-            // routes/api.php と ProjectRouteCurrentOrgGuardTest)
+            // resolve.api-actor より後・api-key.ability より前・idempotent より前
+            // (順序契約は routes/api.php / ProjectRouteCurrentOrgGuardTest /
+            // TenantBoundaryOrderingTest。実行位置の正本は上の priority list)
             'api.project-in-org' => EnsureProjectBelongsToApiOrganization::class,
             'resolve.api-actor' => ResolveApiActor::class,
             'api-key.ability' => RequireApiKeyAbility::class,
@@ -170,6 +198,64 @@
             McpConsentOrganizationBinder::class,
         );
 
+        /*
+         | テナント境界 404 の位置を priority list で確定させる (audit-cycle-2 High-1)。
+         |
+         | 不在 id は SubstituteBindings が 404 にする。したがって **binding より後・テナント
+         | guard より前**に 404 以外で短絡する middleware があると、「他組織に実在 = その短絡の
+         | 応答 / 不在 = 404」という 1 bit の存在オラクルになる (課金ゲート 402/302・
+         | verified 302・2FA 強制 302・Inertia version mismatch 409・ability 403 が該当した)。
+         |
+         | 対処は 2 段:
+         |   1. ResolveApiActor を **binding より前**へ。actor 解決失敗 (401/403) を
+         |      「不在 404 がまだ起きていない時点」で返す。同 middleware は route binding に
+         |      依存しない ($request->route(...) を読まない) ため前倒し可能。
+         |   2. テナント guard を **binding の直後**へ。以降のすべての短絡より前になる。
+         |
+         | 副作用: guard が 404 で短絡すると内側 (HandleInertiaRequests / SecurityHeaders /
+         | NoStoreCacheHeaders / EncryptHistory) は走らない。これは binding 失敗 404 と同じ
+         | 扱いであり、既存契約 (SecurityHeadersTest「binding 失敗 404 には Permissions-Policy が
+         | 一切付かない」) と一致する = 不在と cross-org が応答ヘッダまで同一になる。
+         |
+         | 適用順序: ApplicationBuilder は appends → prepends の順に反映するため、
+         | 鎖状 append (SubstituteBindings → API guard → web guard → …) の anchor は解決可能。
+         |
+         | ⚠ **priority list は「載っている middleware 同士の相対順序」しか強制しない**
+         | (SortedMiddleware::sortMiddleware は priority map に無い要素を一切動かさない)。
+         | したがって「guard を binding の直後に置く」には、現に両者の間に挟まっている
+         | web グループの middleware も **guard より後**として priority list に載せる必要がある。
+         | 載せずに guard だけ登録しても、guard は列の末尾に留まったまま何も動かない
+         | (実測で確認済み。この落とし穴が audit-cycle-2 High-1 の再発経路そのもの)。
+         | 下の web 鎖はそのための宣言であり、順序は §唯一の順序契約 と一致する。
+         | 実測は TenantBoundaryOrderingTest が解決後の middleware 列で固定する。
+         */
+        $middleware->appendToPriorityList(
+            SubstituteBindings::class,
+            EnsureProjectBelongsToApiOrganization::class,
+        );
+        $middleware->appendToPriorityList(
+            EnsureProjectBelongsToApiOrganization::class,
+            EnsureProjectBelongsToRouteOrganization::class,
+        );
+        // テナント guard より後に走ることを確定させる web グループの鎖
+        // (guard を binding 直後まで引き上げるための「後続」宣言)。
+        foreach ([
+            [EnsureProjectBelongsToRouteOrganization::class, HandleInertiaRequests::class],
+            [HandleInertiaRequests::class, SecurityHeaders::class],
+            [SecurityHeaders::class, RequireTwoFactorForEnforcedOrganizations::class],
+            [RequireTwoFactorForEnforcedOrganizations::class, BlockTwoFactorDisableForEnforcedOrganizations::class],
+            [BlockTwoFactorDisableForEnforcedOrganizations::class, NoStoreCacheHeadersForAuthenticatedPages::class],
+            [NoStoreCacheHeadersForAuthenticatedPages::class, EncryptHistory::class],
+            [EncryptHistory::class, EnsureEmailIsVerified::class],
+            [EnsureEmailIsVerified::class, RequireActiveSubscription::class],
+        ] as [$after, $append]) {
+            $middleware->appendToPriorityList($after, $append);
+        }
+        $middleware->prependToPriorityList(
+            SubstituteBindings::class,
+            ResolveApiActor::class,
+        );
+
         // Stripe webhook は署名検証 (Cashier middleware)、SES/SNS webhook は
         // SNS 署名検証 (VerifySnsSignature) で保護されるため CSRF 対象外
         $middleware->validateCsrfTokens(except: [
diff --git a/config/trustedproxy.php b/config/trustedproxy.php
new file mode 100644
index 0000000..9e817bf
--- /dev/null
+++ b/config/trustedproxy.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+|--------------------------------------------------------------------------
+| Trusted Proxies (client IP / X-Forwarded-Proto の信頼境界)
+|--------------------------------------------------------------------------
+|
+| ⚠ **ファイル名はフレームワークが参照する固定名**。
+| Illuminate\Http\Middleware\TrustProxies は `$this->proxies() ?: config('trustedproxy.proxies')`
+| の順で解決するため、env 由来の allowlist を渡す唯一の道がこの config キーである。
+| 本リポジトリの命名慣行 (`trusted_hosts.php` = snake_case) とは異なるが、
+| framework の fallback 経路に乗せるため変更しない。
+|
+| TRUSTED_PROXIES に **すべての hop** の IP / CIDR を CSV で列挙する。
+| hop を 1 つでも取りこぼすと client IP がその hop に固定され、全利用者が
+| 1 つの rate limit バケットに落ちる (自己 DoS)。
+| CloudFront → ALB のような多段構成では両方の range を列挙すること。
+|
+| 特別な値:
+|   - `none`       : 「プロキシは無い」の明示宣言 (空 list に写す)
+|   - `REMOTE_ADDR`: 直接の接続元を信頼 (ローカル開発の Valet TLS 用。production では禁止)
+|
+| `*` / `**` は **禁止** (全アドレス信頼 = XFF 偽装が通る)。
+| 不正値は silent drop ではなく App\Support\TrustedProxiesConfigValidator
+| (ProductionEnvGuard 経由) が production 起動時に fail-fast する。
+| 運用契約は docs/trusted-proxies-runbook.md。
+|
+*/
+
+use App\Support\TrustedProxyToken;
+
+$rawProxies = array_map('trim', explode(',', (string) env('TRUSTED_PROXIES', '')));
+
+return [
+    /*
+    | framework (Illuminate\Http\Middleware\TrustProxies) が読む正本。
+    | **検証を通過した値のみ**。空配列 = 何も信頼しない (= REMOTE_ADDR が client IP)。
+    |
+    | 判定は App\Support\TrustedProxyToken に一本化する (config 段と validator 段で
+    | 同じ関数を使い、判定のズレによる silent drop / 誤 reject を作らない)。
+    | config:cache は評価結果 (plain array) を保存するため関数呼び出しでも問題ない。
+    */
+    'proxies' => array_values(array_filter(
+        $rawProxies,
+        static fn (string $v): bool => TrustedProxyToken::isTrustableAddress($v),
+    )),
+
+    /*
+    | 生 token (空要素・空白のみ要素も保持)。config 段で silent drop された値を
+    | 起動時 fail-fast で表面化させるために TrustedProxiesConfigValidator が読む。
+    | Guard 側で env() を直接読むと config:cache 後に null 化するため config 経由で expose。
+    */
+    'raw_proxies' => $rawProxies,
+];
diff --git a/routes/api.php b/routes/api.php
index 9625c7d..0a0267e 100644
--- a/routes/api.php
+++ b/routes/api.php
@@ -16,19 +16,28 @@
 |
 | `/api` prefix 配下 (bootstrap/app.php の withRouting)。認証は dual guard
 | `auth:api-key,api-oauth` (組織スコープの API キー + OAuth user token。guard 順序は
-| api-key 先 = 自動化トラフィックが先に解決)。middleware の順序契約:
+| api-key 先 = 自動化トラフィックが先に解決)。middleware の順序契約
+| (**宣言順ではなく bootstrap/app.php の priority list が正本**):
 |
-|   auth:api-key,api-oauth → throttle:{bucket} → resolve.api-actor
-|     → api-key.ability:{ability} → api.project-in-org → (idempotent) → controller
+|   auth:api-key,api-oauth → throttle:{bucket}
+|     → resolve.api-actor            ← SubstituteBindings より前 (401/403)
+|     → SubstituteBindings
+|     → api.project-in-org           ← テナント境界 404
+|     → api-key.ability:{ability}    ← 403
+|     → (idempotent) → controller
 |
 | api.project-in-org (EnsureProjectBelongsToApiOrganization) は URL 上の {project} が
 | actor の組織に属さなければ 404 にする層 2a。**FormRequest より前**に走ることが本質で、
 | これが無いと「cross-org の実在 project + 不正 payload = 422 / 不在 project = 404」の
-| 差分が project の存在オラクルになる。順序契約は 2 点とも不可侵:
+| 差分が project の存在オラクルになる。順序契約は 3 点とも不可侵:
 |   - resolve.api-actor **より後** ('organization' attribute が前提。前に置くと全 project
 |     route が Assert 発火で 500)
+|   - api-key.ability:* **より前** (audit-cycle-2 High-1)。逆順だと read-only キーで
+|     write route を叩いたとき「他組織に実在 = 403 / 不在 = 404」と分岐し、
+|     ability 不足のキーだけで project id の存在を列挙できる存在オラクルになる
 |   - idempotent **より前** (cross-org リクエストで idempotency 行を作らせない)
-| 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest が機械固定する。
+| 網羅性と順序は tests/Architecture/ProjectRouteCurrentOrgGuardTest と
+| tests/Architecture/TenantBoundaryOrderingTest が機械固定する。
 |
 | resolve.api-actor が両経路を ApiActorContext (request attribute 'api_actor') に
 | 正規化する (OAuth 経路の cli:use scope / session 束縛 / membership 再検証もここ)。
@@ -64,10 +73,10 @@
             ->name('api.v1.me.session.revoke');
     });
 
-// 読み取り (read ability)
+// 読み取り (read ability)。テナント境界 404 (api.project-in-org) は ability 403 より **前**
 Route::prefix('v1')
-    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor', 'api-key.ability:read',
-        'api.project-in-org'])
+    ->middleware(['auth:api-key,api-oauth', 'throttle:api-read', 'resolve.api-actor',
+        'api.project-in-org', 'api-key.ability:read'])
     ->group(function (): void {
         Route::get('/me', [MeController::class, 'show'])
             ->name('api.v1.me');
@@ -81,10 +90,11 @@
             ->name('api.v1.projects.items.index');
     });
 
-// 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する
+// 書き込み (write ability)。全 write エンドポイントに Idempotency-Key を配線する。
+// テナント境界 404 (api.project-in-org) は ability 403 より **前**
 Route::prefix('v1')
-    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor', 'api-key.ability:write',
-        'api.project-in-org', 'idempotent'])
+    ->middleware(['auth:api-key,api-oauth', 'throttle:api-write', 'resolve.api-actor',
+        'api.project-in-org', 'api-key.ability:write', 'idempotent'])
     ->group(function (): void {
         Route::post('/projects/{project}/items', [ItemController::class, 'store'])
             ->name('api.v1.projects.items.store');
diff --git a/routes/web.php b/routes/web.php
index e2e1e1e..128d8ff 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -226,16 +226,26 @@
     Route::delete('/organizations/{organization:slug}/invitations/{invitation}', [OrganizationInvitationController::class, 'destroy'])
         ->scopeBindings()
         ->name('organizations.invitations.revoke');
-    // {user} は URL 整合 guard で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
-    Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
-        ->name('organizations.members.update');
-    Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
-        ->name('organizations.members.destroy');
-    // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須。
-    // {user} は URL 整合 guard で認可より前に 404)
-    Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
-        ->middleware('recent-auth')
-        ->name('organizations.members.two-factor.reset');
+    /*
+    | {user} は scopeBindings で $organization->users() 経由に解決する。
+    | 非メンバー / 不在 id は **binding 段で等しく 404** になり、recent-auth (302) を含む
+    | binding 後のどの短絡 middleware よりも前に閉じる (audit-cycle-2 High-1 横断)。
+    | implicit binding のままだと「不在 = binding 404 / 実在の非メンバー = 後段短絡の 302」と
+    | 分岐し、users.id の存在オラクルになっていた。
+    | controller の inline guard (resolveOrganizationMember) は二重防御として残す。
+    | 親 {organization:slug} は MembershipScopedOrganizationBinder が引き続き担当する
+    | (scopeBindings は子解決のみに作用)。
+    */
+    Route::scopeBindings()->group(function (): void {
+        Route::patch('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'update'])
+            ->name('organizations.members.update');
+        Route::delete('/organizations/{organization:slug}/members/{user}', [OrganizationMemberController::class, 'destroy'])
+            ->name('organizations.members.destroy');
+        // メンバーの 2FA リセット (ロックアウト救済。Owner/Admin + step-up + 理由必須)
+        Route::delete('/organizations/{organization:slug}/members/{user}/two-factor', [OrganizationMemberController::class, 'resetTwoFactor'])
+            ->middleware('recent-auth')
+            ->name('organizations.members.two-factor.reset');
+    });
     // 組織の 2FA 必須方針トグル (Owner 専権 + step-up)
     Route::patch('/organizations/{organization:slug}/two-factor-requirement', [OrganizationController::class, 'updateTwoFactorRequirement'])
         ->middleware('recent-auth')
@@ -402,9 +412,14 @@
         | プロジェクト (current org スコープ。URL に org / team セグメントを含めない =
         | Default Team パターンのルーティング仕様)。
         | {project} の URL 整合 guard ({project} ∈ current org) は 2 層:
-        | (1) project.in-route-org middleware — FormRequest の DB ルール (unique/exists) より
-        |     前に cross-org を 404 に落とす (存在オラクル防止。{project} を持たない route では
-        |     no-op のため group 一括付与。網羅性は ProjectRouteCurrentOrgGuardTest が固定)
+        | (1) project.in-route-org middleware — cross-org を 404 に落とす (存在オラクル防止)。
+        |     **実行位置は宣言順ではなく bootstrap/app.php の priority list が正本**で、
+        |     SubstituteBindings の**直後** = 課金ゲート 302・verified 302・2FA 強制 302・
+        |     Inertia version mismatch 409・FormRequest の DB ルールより前に走る。
+        |     間に 404 以外で短絡する middleware が入ると「他組織に実在 = その短絡の応答 /
+        |     不在 = 404」の存在オラクルが復活する (audit-cycle-2 High-1)。
+        |     {project} を持たない route では no-op のため group 一括付与。
+        |     網羅性は ProjectRouteCurrentOrgGuardTest、順序は TenantBoundaryOrderingTest が固定
         | (2) controller の inline guard (resolveOrganizationProject) — 二重防御
         */
         Route::get('/projects', [ProjectController::class, 'index'])
@@ -505,8 +520,15 @@
                 ->name('projects.manuals.duplicate');
         });
 
-        // プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
-        // {user} は URL 整合 guard (org member か) で認可より前に 404 (NestedRouteIdorDefenseTest 登録済み)
+        /*
+        | プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
+        | destroy の {user} は **implicit binding を使わない** (controller が string で受け、
+        | 現在組織の users() から手動解決する)。{user} の意味的な親は {project} ではなく
+        | 現在組織であり、scopeBindings が要求する Project::users() は存在しないため。
+        | binding 段で解決されない = 不在 id も実在の非メンバーもまったく同じ経路を辿る
+        | (= 分岐しない = 存在オラクル不成立)。この性質は TenantBoundaryOrderingTest の
+        | 検査 3a が「binding 段で解決されないこと」として機械検証する。
+        */
         Route::post('/projects/{project}/members', [ProjectMemberController::class, 'store'])
             ->name('projects.members.store');
         Route::delete('/projects/{project}/members/{user}', [ProjectMemberController::class, 'destroy'])
diff --git a/tests/Architecture/EnvExampleInvariantTest.php b/tests/Architecture/EnvExampleInvariantTest.php
index 49544c1..28861c2 100644
--- a/tests/Architecture/EnvExampleInvariantTest.php
+++ b/tests/Architecture/EnvExampleInvariantTest.php
@@ -21,6 +21,18 @@
     expect($contents)->toContain('SESSION_ENCRYPT=true');
 });
 
+/*
+ * client IP の信頼境界 (T108 S5)。production で未宣言だと起動時 fail-fast するため、
+ * .env.example に必ず提示して「設定し忘れてデプロイが落ちる」事故を減らす。
+ */
+
+test('.env.example に TRUSTED_PROXIES が含まれる', function (): void {
+    $contents = file_get_contents(base_path('.env.example'));
+    expect($contents)->toBeString();
+    /** @var string $contents */
+    expect($contents)->toContain('TRUSTED_PROXIES=');
+});
+
 /*
  * テンプレート規約: 環境座標 (config/template.php) のキーは .env.example に必ず提示する。
  */
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 11198dd..7fd751a 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -4,162 +4,59 @@
 
 use App\Enums\Security\NestedRouteDefenseMode;
 use Illuminate\Support\Facades\Route;
+use Tests\Support\Routing\NestedRouteDefenseInventory;
 
 /**
- * nested route (親子) IDOR 防御の網羅性 invariant。
+ * route parameter ごとの IDOR / 存在オラクル 防御の網羅性 invariant。
  *
- * 「子リソースを URL で受ける route は、子が必ず URL 親/テナントに属することを構造的に担保し、
- * 不整合は認可より前に 404 (403 で存在を漏らさない)」という不変条件を、各 route が
+ * 「id を URL で受ける route は、その id が必ず URL 親/テナントに属することを構造的に担保し、
+ * 不整合は認可より前に 404 (403 や 302 で存在を漏らさない)」という不変条件を、各 parameter が
  * どの防御方式 (NestedRouteDefenseMode) で守っているかを deny-by-default で機械検証する。
  *
- * 本テストは「分類漏れ・drift を落とす」役割に限定する (inline guard の静的正当性は証明しない)。
- * 実挙動 (不整合→404 等) は scopeBindings の Routing 層 enforcement と各 Feature テスト
- * (UrlIntegrityGuardTest / OrganizationBoundaryNotFoundTest 等) が担保する。
- *
- * 2 個以上の route パラメータを取る named route を全て候補とし、inventory (防御方式付き) か
- * prefixExemptAllowlist (親子テナントでない理由付き) のどちらかに必ず分類させる。
+ * inventory の正本は {@see NestedRouteDefenseInventory} (TenantBoundaryOrderingTest と共有)。
+ * 本テストは「分類漏れ・stale・無記名の逃げ道」を落とす役割に限定する
+ * (inline guard の静的正当性は証明しない)。モードごとの**順序不変条件**は
+ * tests/Architecture/TenantBoundaryOrderingTest、実挙動 (不整合→404 等) は各 Feature テスト
+ * (MemberRouteExistenceOracleTest / TenantBoundaryPrecedenceTest / UrlIntegrityGuardTest /
+ * OrganizationBoundaryNotFoundTest 等) が担保する。
  */
+test('1+param 候補 route の全 parameter が inventory に明示分類されている (未知は fail)', function (): void {
+    $inventory = NestedRouteDefenseInventory::inventory();
+    $violations = [];
 
-/**
- * route 名 => 防御方式の明示 inventory (型付き)。
- *
- * @return array<string, NestedRouteDefenseMode>
- */
-function nestedRouteIdorInventory(): array
-{
-    $s = NestedRouteDefenseMode::ScopeBindings;
-    $g = NestedRouteDefenseMode::UrlIntegrityGuard;
-
-    return [
-        // --- Route::scopeBindings() (親 relation 経由で子を解決、不整合は 404) ---
-        // {apiKey} は $organization->apiKeys() 経由 ({organization} 自体は
-        // MembershipScopedOrganizationBinder が membership スコープで解決)
-        'organizations.api-keys.revoke' => $s,
-        // {oauthSession} は $organization->oauthSessions() 経由 (WP24。controller 内の
-        // organization_id 再検査は二重防御)
-        'organizations.api-keys.sessions.revoke' => $s,
-        // {invitation} は $organization->invitations() 経由 (招待取り消し。cross-org は 404)
-        'organizations.invitations.revoke' => $s,
-        // {item} は $project->items() 経由 ({project} ∈ current org は
-        // project.in-route-org middleware + controller inline guard の 2 層)
-        'projects.items.update' => $s,
-        'projects.items.destroy' => $s,
-        // {category} は $project->categories() 経由 ({project} ∈ current org は
-        // project.in-route-org middleware + controller inline guard の 2 層。
-        // FormRequest の DB ルール (unique) より前の 404 は ProjectRouteCurrentOrgGuardTest 参照)
-        'projects.categories.update' => $s,
-        'projects.categories.destroy' => $s,
-        // {manual} は $project->manuals() 経由 (relation 名は route パラメータ {manual} の
-        // scopeBindings 推論と一致させた manuals()。{project} ∈ current org は
-        // project.in-route-org middleware + inline guard の 2 層)
-        'projects.manuals.show' => $s,
-        'projects.manuals.edit' => $s,
-        'projects.manuals.update' => $s,
-        // シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
-        'projects.manuals.scenario.update' => $s,
-        'projects.manuals.destroy' => $s,
-        'projects.manuals.duplicate' => $s, // {manual} は $project->manuals() 経由 (保存済み cuts を複製)
-        // SOP アップロード / AI 解析 / job ポーリング ({manual} は $project->manuals()、
-        // {analysisJob} は $manual->analysisJobs() 経由。不整合は認可より前に 404)
-        'projects.manuals.source-documents.store' => $s,
-        'projects.manuals.analyze' => $s,
-        'projects.manuals.jobs.show' => $s,
-        // レンダ/プレビュー/ポーリング/再生/DL ({manual} は $project->manuals()、
-        // {renderJob} は $manual->renderJobs() 経由。不整合は認可より前に 404。§10.3)
-        'projects.manuals.render' => $s,
-        'projects.manuals.preview' => $s,
-        'projects.manuals.render-jobs.show' => $s,
-        'projects.manuals.render-jobs.playback' => $s,
-        'projects.manuals.download' => $s,
-        // 撮影 PWA (/app/*。doc/10 §10.8-3)。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut} は
-        // scopeBindings + 各書き込み Service の tx 内連鎖再解決 (二重防御)。
-        // {project} ∈ current org は project.in-route-org middleware + inline guard の 2 層
-        'capture.manuals.show' => $s,
-        'capture.takes.upload-url' => $s,
-        'capture.takes.store' => $s,
-        'capture.takes.update' => $s,
-        'capture.takes.destroy' => $s,
-        'capture.takes.adopt' => $s,
-        'capture.takes.downloaded' => $s,
-        'capture.takes.playback' => $s,
-        // REST API v1: {item} は $project->items() 経由 (scopeBindings)。
-        // {project} ∈ actor の組織は api.project-in-org middleware + controller inline guard の
-        // 2 層 (いずれも認可より前に 404。middleware は FormRequest より前に走る)
-        'api.v1.projects.items.update' => $s,
-        'api.v1.projects.items.destroy' => $s,
-        // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
-        // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
-        'organizations.members.update' => $g,
-        'organizations.members.destroy' => $g,
-        'organizations.members.two-factor.reset' => $g,
-        // ProjectMemberController::destroy (org 越境 {user} は 404)
-        'projects.members.destroy' => $g,
-    ];
-}
+    foreach (NestedRouteDefenseInventory::candidates() as $route) {
+        $name = $route->getName();
+        if ($name === null) {
+            $violations[] = '無名の param 付き route: '.$route->uri().' (name を付け inventory 登録してください)';
 
-/**
- * 2+param だが「親子 IDOR の対象外」と明示する route (理由付き、真の deny-by-default sentinel)。
- *
- * @return array<string, string>
- */
-function nestedRoutePrefixExemptAllowlist(): array
-{
-    return [
-        'social.redirect' => 'auth/{provider}/redirect/{intent}: いずれも config 由来の固定集合で検証・テナント親子でない',
-        'verification.verify' => 'email/verify/{id}/{hash}: 署名付き URL (MustVerifyEmail)・テナント親子でない',
-    ];
-}
-
-/** @return list<Illuminate\Routing\Route> parameterNames>=2 の候補 route (パッケージ内部 route は除外)。 */
-function nestedRouteCandidates(): array
-{
-    $candidates = [];
-    foreach (Route::getRoutes() as $route) {
-        if (count($route->parameterNames()) < 2) {
             continue;
         }
+        if (! array_key_exists($name, $inventory)) {
+            $violations[] = $name.' ('.$route->uri().') が未分類';
 
-        // パッケージ管理ルート (Filament/Livewire/Passport 内部) はパッケージ側が防御を担うため
-        // 対象外。アプリが定義するルートのみ検査する。
-        $name = $route->getName();
-        if (str_starts_with($route->uri(), 'livewire')
-            || ($name !== null && (str_starts_with($name, 'filament.')
-                || str_starts_with($name, 'livewire.')
-                || str_starts_with($name, 'passport.')))) {
             continue;
         }
 
-        $candidates[] = $route;
-    }
-
-    return $candidates;
-}
-
-test('2+param 候補 route は inventory か exemptAllowlist に明示分類されている (未知は fail)', function (): void {
-    $inventory = nestedRouteIdorInventory();
-    $allow = nestedRoutePrefixExemptAllowlist();
-    $violations = [];
-
-    foreach (nestedRouteCandidates() as $route) {
-        $name = $route->getName();
-        if ($name === null) {
-            $violations[] = '無名の 2+param route: '.$route->uri().' (name を付け inventory 登録してください)';
-
-            continue;
+        foreach ($route->parameterNames() as $param) {
+            if (! array_key_exists($param, $inventory[$name])) {
+                $violations[] = $name.' の parameter {'.$param.'} が未分類';
+            }
         }
-        if (array_key_exists($name, $inventory) || array_key_exists($name, $allow)) {
-            continue;
+        foreach (array_keys($inventory[$name]) as $declared) {
+            if (! in_array($declared, $route->parameterNames(), true)) {
+                $violations[] = $name.' の inventory に存在しない parameter {'.$declared.'} が登録されている';
+            }
         }
-        $violations[] = $name.' ('.$route->uri().') が未分類';
     }
 
     expect($violations)->toBe([],
-        '未分類の親子候補 route があります。nestedRouteIdorInventory() に防御方式を登録するか、'
-        .'親子テナントでなければ nestedRoutePrefixExemptAllowlist() に理由付きで登録してください。'
+        '未分類の route parameter があります。NestedRouteDefenseInventory::inventory() に'
+        .'parameter 単位で防御方式を登録してください (テナント親子でなければ NonResourceParameter / '
+        .'PublicGlobalResource を宣言し、nonTenantReasons() に理由を書くこと)。'
         .PHP_EOL.implode(PHP_EOL, $violations));
 });
 
-test('inventory/allowlist の key は現存 named route (逆方向整合・stale 検出)', function (): void {
+test('inventory の key は現存 named route (逆方向整合・stale 検出)', function (): void {
     $named = [];
     foreach (Route::getRoutes() as $route) {
         $n = $route->getName();
@@ -169,20 +66,45 @@ function nestedRouteCandidates(): array
     }
 
     $stale = [];
-    foreach ([
-        ...array_keys(nestedRouteIdorInventory()),
-        ...array_keys(nestedRoutePrefixExemptAllowlist()),
-    ] as $key) {
+    foreach (array_keys(NestedRouteDefenseInventory::inventory()) as $key) {
         if (! isset($named[$key])) {
             $stale[] = $key;
         }
     }
 
-    expect($stale)->toBe([], 'inventory/allowlist に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
+    expect($stale)->toBe([], 'inventory に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
+});
+
+test('非テナントモードの宣言と理由は 1 対 1 (逃げ道を無記名で作らせない)', function (): void {
+    $reasons = NestedRouteDefenseInventory::nonTenantReasons();
+    $declared = [];
+
+    foreach (NestedRouteDefenseInventory::inventory() as $routeName => $params) {
+        foreach ($params as $param => $mode) {
+            if ($mode->isTenantDefense()) {
+                continue;
+            }
+            $declared[] = $routeName.'#'.$param;
+        }
+    }
+
+    $missingReason = array_values(array_diff($declared, array_keys($reasons)));
+    $staleReason = array_values(array_diff(array_keys($reasons), $declared));
+
+    expect($missingReason)->toBe([],
+        '非テナントモードを宣言した parameter に理由がありません: '.implode(', ', $missingReason));
+    expect($staleReason)->toBe([],
+        'テナント防御モードに変わった / 消えた parameter の理由が残っています: '.implode(', ', $staleReason));
+
+    foreach ($reasons as $key => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThan(15, "{$key} の理由が空疎です");
+    }
 });
 
 test('inventory の各値は NestedRouteDefenseMode', function (): void {
-    foreach (nestedRouteIdorInventory() as $mode) {
-        expect($mode)->toBeInstanceOf(NestedRouteDefenseMode::class);
+    foreach (NestedRouteDefenseInventory::inventory() as $params) {
+        foreach ($params as $mode) {
+            expect($mode)->toBeInstanceOf(NestedRouteDefenseMode::class);
+        }
     }
 });
diff --git a/tests/Architecture/PasskeyRouteProtectionTest.php b/tests/Architecture/PasskeyRouteProtectionTest.php
index 9610876..6909e9e 100644
--- a/tests/Architecture/PasskeyRouteProtectionTest.php
+++ b/tests/Architecture/PasskeyRouteProtectionTest.php
@@ -5,6 +5,7 @@
 use App\Http\Middleware\EnsureLoginMethodRemains;
 use App\Http\Middleware\NoStoreResponse;
 use App\Http\Middleware\RequireRecentAuth;
+use Illuminate\Routing\Middleware\ThrottleRequests;
 use Illuminate\Routing\Route as RoutingRoute;
 use Illuminate\Routing\Router;
 
@@ -30,8 +31,11 @@ function passkeyRouteMiddlewareInventory(): array
         // credential 集合を増やす管理経路
         'passkey.registration-options' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
         'passkey.store' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth'],
-        // credential 集合を減らす管理経路 (手段保持 guard つき)
-        'passkey.destroy' => ['web', 'auth:web', 'recent-auth', 'ensure-login-method'],
+        // credential 集合を減らす管理経路 (手段保持 guard つき)。
+        // throttle は vendor (Fortify) が付けないため PasskeyServiceProvider が後付けする
+        // (EnsureLoginMethodRemains の User 行 lockForUpdate を無制限に叩けなくする。
+        //  audit-cycle-2 Medium-2)
+        'passkey.destroy' => ['web', 'auth:web', 'throttle:passkeys', 'recent-auth', 'ensure-login-method'],
     ];
 }
 
@@ -94,3 +98,25 @@ function passkeyRoute(string $name): RoutingRoute
 
     expect($resolved)->toContain(NoStoreResponse::class);
 });
+
+/*
+ * passkey.destroy の実行順: ThrottleRequests < RequireRecentAuth < EnsureLoginMethodRemains。
+ * throttle が最外周にあることで、stale recent-auth でも User 行ロックでもなく
+ * **リクエスト数そのもの**を先に止める。
+ */
+test('passkey.destroy は throttle が recent-auth / ensure-login-method より先に走る', function (): void {
+    /** @var Router $router */
+    $router = app('router');
+    $resolved = array_map(
+        static fn (mixed $m): string => is_string($m) ? explode(':', $m, 2)[0] : '(closure)',
+        $router->gatherRouteMiddleware(passkeyRoute('passkey.destroy')),
+    );
+
+    $throttleIndex = array_search(ThrottleRequests::class, $resolved, true);
+    $recentAuthIndex = array_search(RequireRecentAuth::class, $resolved, true);
+    $loginMethodIndex = array_search(EnsureLoginMethodRemains::class, $resolved, true);
+
+    expect($throttleIndex)->not->toBeFalse('ThrottleRequests が解決後の middleware 列に無い');
+    expect($throttleIndex)->toBeLessThan($recentAuthIndex);
+    expect($recentAuthIndex)->toBeLessThan($loginMethodIndex);
+});
diff --git a/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
index a12b7b1..87ba5c8 100644
--- a/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
+++ b/tests/Architecture/ProjectRouteCurrentOrgGuardTest.php
@@ -2,6 +2,12 @@
 
 declare(strict_types=1);
 
+use App\Http\Middleware\EnsureProjectBelongsToApiOrganization;
+use App\Http\Middleware\IdempotentRequest;
+use App\Http\Middleware\RequireApiKeyAbility;
+use App\Http\Middleware\ResolveApiActor;
+use Illuminate\Routing\Middleware\SubstituteBindings;
+use Illuminate\Routing\Router;
 use Illuminate\Support\Facades\Route;
 
 /*
@@ -67,24 +73,26 @@
 /*
  * API の middleware 順序契約 (docblock ではなく機械で固定する):
  *
- *   resolve.api-actor  <  api-key.ability:*  <  api.project-in-org  <  idempotent
+ *   resolve.api-actor  <  SubstituteBindings  <  api.project-in-org  <  api-key.ability:*  <  idempotent
  *
  * | 破られる契約 | 起きること |
  * |---|---|
  * | resolve.api-actor が api.project-in-org **より後** | 'organization' attribute 未設定で Assert が
  *   発火し **全 API {project} route が 500** |
- * | api-key.ability:* が api.project-in-org **より後** | ability 不足の判定 (403) より先に
- *   テナント境界の 404 が返り、エラー契約 (insufficient_ability) が route ごとにぶれる |
+ * | api-key.ability:* が api.project-in-org **より前** | **ability 不足時に cross-org の実在が
+ *   403 で漏れる** (他組織に実在 = 403 / 不在 = 404 の存在オラクル。audit-cycle-2 High-1) |
  * | idempotent が api.project-in-org **より前** | **cross-org リクエストで idempotency 行が作られる**
  *   (cross-org の副作用 = 不変条件 3 に抵触) |
  *
- * 注意: gatherMiddleware() が返すのは **宣言順** (group middleware → route middleware)。
- * Laravel の middleware priority ($middlewarePriority) を導入すると最終的な実行順が
- * 並べ替えられうるが、現行構成では本テストが検査する custom middleware
- * (resolve.api-actor / api.project-in-org / idempotent) はいずれも priority リストに
- * 含まれないため宣言順 = 実行順である。priority を導入する際は本テストの前提を見直すこと。
+ * 注意: **順序の正本は bootstrap/app.php の priority list** であり route の宣言順ではない。
+ * したがって本テストは gatherMiddleware() (宣言順の alias 文字列) ではなく
+ * Router::gatherRouteMiddleware() (priority 適用後の具象クラス列) を測る。
+ * 宣言順を見ていたことが audit-cycle-2 で穴が見えなかった直接の原因である。
+ * binding 直後であることまで含めた不変条件は TenantBoundaryOrderingTest が担う。
  */
-test('API の {project} route は middleware 順序契約を守る', function (): void {
+test('API の {project} route は middleware 順序契約を守る (解決後の実行順)', function (): void {
+    /** @var Router $router */
+    $router = app('router');
     $checked = 0;
     $violations = [];
 
@@ -97,21 +105,18 @@
         }
 
         $name = $route->getName() ?? $route->uri();
-        $middleware = $route->gatherMiddleware();
-        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);
-
-        $guard = $indexOf('api.project-in-org');
-        $actor = $indexOf('resolve.api-actor');
-        $idempotent = $indexOf('idempotent');
-        // ability middleware は `api-key.ability:read` のようにパラメータ付きで並ぶ
-        $ability = false;
-        foreach ($middleware as $index => $entry) {
-            if (is_string($entry) && str_starts_with($entry, 'api-key.ability:')) {
-                $ability = $index;
-
-                break;
-            }
-        }
+        // `Class:param` の parameter を落とし、解決後の具象クラス名で比較する
+        $resolved = array_map(
+            static fn (mixed $m): string => is_string($m) ? explode(':', $m, 2)[0] : '(closure)',
+            $router->gatherRouteMiddleware($route),
+        );
+        $indexOf = static fn (string $class): int|false => array_search($class, $resolved, true);
+
+        $guard = $indexOf(EnsureProjectBelongsToApiOrganization::class);
+        $actor = $indexOf(ResolveApiActor::class);
+        $binding = $indexOf(SubstituteBindings::class);
+        $ability = $indexOf(RequireApiKeyAbility::class);
+        $idempotent = $indexOf(IdempotentRequest::class);
 
         if ($guard === false) {
             $violations[] = "{$name}: api.project-in-org が無い";
@@ -122,9 +127,13 @@
             $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                 .'(organization attribute 未設定で 500 になります)';
         }
-        if ($ability === false || $ability > $guard) {
-            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より後 "
-                .'(ability 不足の 403 より前にテナント境界の 404 が返り、エラー契約がぶれます)';
+        if ($binding === false || $binding > $guard) {
+            $violations[] = "{$name}: SubstituteBindings が api.project-in-org より後 "
+                .'(guard が binding 済みの Project を読めません)';
+        }
+        if ($ability === false || $ability < $guard) {
+            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より前 "
+                .'(ability 不足時に cross-org の実在が 403 で漏れます)';
         }
         if ($idempotent !== false && $idempotent < $guard) {
             $violations[] = "{$name}: idempotent が api.project-in-org より前 "
diff --git a/tests/Architecture/SecurityEventCoverageTest.php b/tests/Architecture/SecurityEventCoverageTest.php
new file mode 100644
index 0000000..93571ed
--- /dev/null
+++ b/tests/Architecture/SecurityEventCoverageTest.php
@@ -0,0 +1,229 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Actions\Fortify\UpdateUserPassword;
+use App\Actions\Fortify\UpdateUserProfileInformation;
+use App\Console\Commands\ResetAdminMfaCommand;
+use App\Enums\SecurityEventType;
+use App\Http\Controllers\Organizations\OrganizationApiKeyController;
+use App\Http\Controllers\Organizations\OrganizationMemberController;
+use App\Services\Auth\SocialAccountService;
+use App\Services\Organization\OrganizationMembershipService;
+use Illuminate\Auth\Events\Failed;
+use Illuminate\Auth\Events\Login;
+use Illuminate\Auth\Events\Logout;
+use Illuminate\Auth\Events\PasswordReset;
+use Illuminate\Support\Facades\Event;
+use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
+use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+
+/**
+ * security_audit_events の**記録経路の網羅性** invariant (T108 S7)。
+ *
+ * `SecurityEventType` は「監査に残すと決めた事象」の固定集合だが、case を足しても
+ * 記録経路を配線し忘れると **enum に存在するのに 1 行も記録されない**幽霊 case になる。
+ * 実際 `password_changed` と `email_changed` はその状態で放置されていた。
+ *
+ * そこで全 case の記録経路を構造化 map で宣言させ、
+ *   - enum の全 case と map の key が完全一致 (双方向 = deny-by-default)
+ *   - `event` 宣言なら当該イベントに listener が実際に登録されている
+ *   - `caller` 宣言なら当該クラスが実在し SecurityEventRecorder を参照している
+ *   - すべての case に `covered_by` があり、そのファイルが実在し、
+ *     **その中で当該 case を名指ししている** (空疎な登録の禁止)
+ *   - `event` / `caller` は**いずれか一方**だけを持つ
+ * を機械検証する。
+ */
+
+/**
+ * SecurityEventType の値 => 記録経路の宣言。
+ *
+ * `event`  : 購読するイベントクラス (RecordSecurityEvent が listener を張る)
+ * `caller` : 直接 SecurityEventRecorder を呼ぶクラス
+ * `covered_by` : その event_type が記録されることを担保するテストファイル
+ *
+ * @return array<string, array{event?: class-string, caller?: class-string, covered_by: string}>
+ */
+function securityEventRecordingMap(): array
+{
+    return [
+        SecurityEventType::Login->value => [
+            'event' => Login::class,
+            'covered_by' => 'tests/Feature/Auth/AuthenticationTest.php',
+        ],
+        SecurityEventType::LoginFailed->value => [
+            'event' => Failed::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::Logout->value => [
+            'event' => Logout::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::PasswordReset->value => [
+            'event' => PasswordReset::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::PasswordChanged->value => [
+            'caller' => UpdateUserPassword::class,
+            'covered_by' => 'tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php',
+        ],
+        SecurityEventType::TwoFactorEnabled->value => [
+            'event' => TwoFactorAuthenticationConfirmed::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::TwoFactorDisabled->value => [
+            'event' => TwoFactorAuthenticationDisabled::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::EmailChanged->value => [
+            'caller' => UpdateUserProfileInformation::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::AccountDeleted->value => [
+            'caller' => OrganizationMembershipService::class,
+            'covered_by' => 'tests/Feature/Auth/AccountDeletionTest.php',
+        ],
+        SecurityEventType::SocialAccountLinked->value => [
+            'caller' => SocialAccountService::class,
+            'covered_by' => 'tests/Feature/Security/SecurityAuditTrailCoverageTest.php',
+        ],
+        SecurityEventType::OwnershipTransferred->value => [
+            'caller' => OrganizationMembershipService::class,
+            'covered_by' => 'tests/Feature/Organization/OwnershipTransferTest.php',
+        ],
+        SecurityEventType::ApiKeyIssued->value => [
+            'caller' => OrganizationApiKeyController::class,
+            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
+        ],
+        SecurityEventType::ApiKeyRevoked->value => [
+            'caller' => OrganizationApiKeyController::class,
+            'covered_by' => 'tests/Feature/Api/ApiKeyTest.php',
+        ],
+        SecurityEventType::AdminMfaReset->value => [
+            'caller' => ResetAdminMfaCommand::class,
+            'covered_by' => 'tests/Feature/Console/ResetAdminMfaCommandTest.php',
+        ],
+        SecurityEventType::OrgMemberTwoFactorReset->value => [
+            'caller' => OrganizationMemberController::class,
+            'covered_by' => 'tests/Feature/Organizations/TwoFactorEnforcementTest.php',
+        ],
+        SecurityEventType::PasskeyRegistered->value => [
+            'event' => PasskeyRegistered::class,
+            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
+        ],
+        SecurityEventType::PasskeyDeleted->value => [
+            'event' => PasskeyDeleted::class,
+            'covered_by' => 'tests/Feature/Auth/PasskeyAuditTrailTest.php',
+        ],
+    ];
+}
+
+test('検査1: enum の全 case と記録経路 map の key が完全一致する', function (): void {
+    $cases = array_map(
+        static fn (SecurityEventType $type): string => $type->value,
+        SecurityEventType::cases(),
+    );
+    $declared = array_keys(securityEventRecordingMap());
+
+    sort($cases);
+    sort($declared);
+
+    expect($declared)->toBe($cases,
+        'SecurityEventType に case を足したら securityEventRecordingMap() にも記録経路を宣言してください '
+        .'(宣言のない case は「enum にあるのに 1 行も記録されない」幽霊 case になります)');
+});
+
+test('検査2: event 宣言の case は当該イベントに listener が登録されている', function (): void {
+    $violations = [];
+
+    foreach (securityEventRecordingMap() as $value => $entry) {
+        $eventClass = $entry['event'] ?? null;
+        if ($eventClass === null) {
+            continue;
+        }
+        if (! class_exists($eventClass)) {
+            $violations[] = "{$value}: イベントクラス {$eventClass} が実在しない";
+
+            continue;
+        }
+        if (Event::getRawListeners()[$eventClass] ?? null) {
+            continue;
+        }
+        $violations[] = "{$value}: {$eventClass} に listener が登録されていない "
+            .'(RecordSecurityEvent::subscribe で listen していますか?)';
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査3: caller 宣言の case は当該クラスが SecurityEventRecorder を参照している', function (): void {
+    $violations = [];
+
+    foreach (securityEventRecordingMap() as $value => $entry) {
+        $callerClass = $entry['caller'] ?? null;
+        if ($callerClass === null) {
+            continue;
+        }
+        if (! class_exists($callerClass)) {
+            $violations[] = "{$value}: 呼び出し元クラス {$callerClass} が実在しない";
+
+            continue;
+        }
+
+        $file = (new ReflectionClass($callerClass))->getFileName();
+        $source = $file === false ? '' : (string) file_get_contents($file);
+        if (! str_contains($source, 'SecurityEventRecorder')) {
+            $violations[] = "{$value}: {$callerClass} が SecurityEventRecorder を参照していない";
+        }
+        // enum case を名指ししていること (「recorder を持っているだけ」を通さない)
+        $caseName = SecurityEventType::from($value)->name;
+        if (! str_contains($source, 'SecurityEventType::'.$caseName)) {
+            $violations[] = "{$value}: {$callerClass} が SecurityEventType::{$caseName} を記録していない";
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査4: 全 case に実在する covered_by があり、その中で当該 case を名指ししている', function (): void {
+    $violations = [];
+
+    foreach (securityEventRecordingMap() as $value => $entry) {
+        $path = $entry['covered_by'];
+        if (! file_exists(base_path($path))) {
+            $violations[] = "{$value}: covered_by のファイル {$path} が実在しない";
+
+            continue;
+        }
+
+        $source = (string) file_get_contents(base_path($path));
+        $caseName = SecurityEventType::from($value)->name;
+        // 生の値 ('passkey_registered') か enum case 参照のどちらかで名指ししていること
+        $mentionsValue = str_contains($source, "'".$value."'") || str_contains($source, '"'.$value.'"');
+        $mentionsCase = str_contains($source, 'SecurityEventType::'.$caseName);
+        if (! $mentionsValue && ! $mentionsCase) {
+            $violations[] = "{$value}: {$path} が当該 event_type を名指ししていない "
+                .'(空疎な covered_by 登録。実際にその event_type を検証するテストを書いてください)';
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
+
+test('検査5: 各エントリは event か caller のいずれか一方だけを持つ', function (): void {
+    $violations = [];
+
+    foreach (securityEventRecordingMap() as $value => $entry) {
+        $hasEvent = array_key_exists('event', $entry);
+        $hasCaller = array_key_exists('caller', $entry);
+
+        if ($hasEvent === $hasCaller) {
+            $violations[] = "{$value}: event / caller は"
+                .($hasEvent ? '両方宣言されている' : 'どちらも宣言されていない');
+        }
+    }
+
+    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
+});
diff --git a/tests/Architecture/TenantBoundaryOrderingTest.php b/tests/Architecture/TenantBoundaryOrderingTest.php
new file mode 100644
index 0000000..a366d3c
--- /dev/null
+++ b/tests/Architecture/TenantBoundaryOrderingTest.php
@@ -0,0 +1,481 @@
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
+use App\Http\Middleware\EnsureProjectBelongsToRouteOrganization;
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
+        EnsureProjectBelongsToRouteOrganization::class => true,
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
+        EnsureProjectBelongsToRouteOrganization::class,
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
+    $guard = EnsureProjectBelongsToRouteOrganization::class;
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
diff --git a/tests/Architecture/TrustedProxiesRunbookTest.php b/tests/Architecture/TrustedProxiesRunbookTest.php
new file mode 100644
index 0000000..0100b30
--- /dev/null
+++ b/tests/Architecture/TrustedProxiesRunbookTest.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+/*
+ * TRUSTED_PROXIES 運用 runbook の記入漏れを機械検出する。
+ *
+ * `TRUSTED_PROXIES` は本番のプロキシ構成を知らないと正しく書けない
+ * (本リポジトリには deploy/ / terraform / k8s manifest / nginx conf が無く、
+ * 設計時点では実 hop を確認できていない)。CIDR を推測で決め打ちすると
+ * 「hop 取りこぼしによる自己 DoS」か「過大信頼による XFF 偽装」のどちらかに倒れるため、
+ * 運用者記入欄を docs/trusted-proxies-runbook.md に置き、placeholder が残っている限り
+ * fail させる。
+ *
+ * placeholder を消すだけで通せてしまう点は承知の上で、
+ * 「デプロイ前に人間が必ず一度読む」ことを機械的に強制する装置として置く。
+ */
+
+const TRUSTED_PROXIES_RUNBOOK = 'docs/trusted-proxies-runbook.md';
+
+/** 運用者記入欄の未記入マーカー。 */
+const OPS_FILL_MARKER = '<!-- OPS-FILL -->';
+
+test('trusted-proxies runbook が存在する', function (): void {
+    expect(file_exists(base_path(TRUSTED_PROXIES_RUNBOOK)))->toBeTrue(
+        TRUSTED_PROXIES_RUNBOOK.' が存在しません (TRUSTED_PROXIES の運用契約の正本)',
+    );
+});
+
+test('trusted-proxies runbook に運用者記入欄の placeholder が残っていない', function (): void {
+    $contents = file_get_contents(base_path(TRUSTED_PROXIES_RUNBOOK));
+    expect($contents)->toBeString();
+    /** @var string $contents */
+    $lines = [];
+    foreach (explode("\n", $contents) as $index => $line) {
+        // インラインコードスパン (`...`) 内の出現は「この gate の仕組みの説明」であって
+        // 未記入欄ではないため除外する (未記入欄は必ず表セルに素で置かれる)
+        $stripped = preg_replace('/`[^`]*`/', '', $line) ?? $line;
+        if (str_contains($stripped, OPS_FILL_MARKER)) {
+            $lines[] = 'L'.($index + 1).': '.trim($line);
+        }
+    }
+
+    expect($lines)->toBe([],
+        TRUSTED_PROXIES_RUNBOOK.' に未記入の運用者記入欄が残っています。'
+        .'実 proxy hop 一覧と CIDR 管理主体を埋めてください (推測で CIDR を決め打ちしないこと)。'
+        .PHP_EOL.implode(PHP_EOL, $lines));
+});
+
+test('trusted-proxies runbook が必須節を持つ (章立ての drift 検知)', function (): void {
+    $contents = file_get_contents(base_path(TRUSTED_PROXIES_RUNBOOK));
+    expect($contents)->toBeString();
+    /** @var string $contents */
+    foreach ([
+        '実 proxy hop 一覧',
+        'CIDR の管理主体',
+        'production:preflight',
+        'rollback 条件',
+    ] as $section) {
+        expect($contents)->toContain($section);
+    }
+});
diff --git a/tests/Feature/Api/V1/ItemAuthorizationTest.php b/tests/Feature/Api/V1/ItemAuthorizationTest.php
index 894966b..2244384 100644
--- a/tests/Feature/Api/V1/ItemAuthorizationTest.php
+++ b/tests/Feature/Api/V1/ItemAuthorizationTest.php
@@ -8,6 +8,7 @@
 use App\Models\Organization;
 use App\Models\Project;
 use Tests\Support\OAuthTestHelpers;
+use Tests\Support\ResponseSignature;
 
 /*
  * REST API v1 Item の認可境界 (web 側 Projects\ItemController と同一の ItemPolicy 境界)。
@@ -340,3 +341,89 @@ function itemAuthorizationBearer(string $plain): array
 
     expect($project->items()->count())->toBe(1);
 });
+
+/*
+ * --- ability 不足 × テナント境界 の優先順位 (audit-cycle-2 High-1 / T108 S1) ---
+ *
+ * ability (403 insufficient_ability) の判定が テナント境界 404 より **前** に走ると、
+ * 「他組織に実在する project = 403 / 不在 project id = 404」と分岐し、
+ * ability 不足のキーだけで project id の実在を 1 bit ずつ列挙できてしまう。
+ * 順序契約 (api.project-in-org < api-key.ability:*) を **応答の同一性** で固定する。
+ */
+
+test('read のみの API キーで write route を叩くと cross-org 実在 project と不在 id が完全に同一応答', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    [, $plain] = issueApiKey($organizationB, $ownerB, ['read']);
+    $headers = itemAuthorizationBearer($plain);
+    $payload = ['name' => '越境'];
+
+    $crossOrg = $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$projectA->id}/items", $payload);
+    $missing = $this->withHeaders($headers)
+        ->postJson('/api/v1/projects/999999999/items', $payload);
+
+    expect($crossOrg->getStatusCode())->toBe(404)
+        ->and($crossOrg->json('error.code'))->toBe('not_found')
+        ->and(ResponseSignature::of($crossOrg))->toBe(ResponseSignature::of($missing));
+});
+
+test('write のみの API キーで read route を叩いても cross-org 実在 project と不在 id は同一応答', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    [, $plain] = issueApiKey($organizationB, $ownerB, ['write']);
+    $headers = itemAuthorizationBearer($plain);
+
+    $crossOrg = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectA->id}/items");
+    $missing = $this->withHeaders($headers)->getJson('/api/v1/projects/999999999/items');
+
+    expect($crossOrg->getStatusCode())->toBe(404)
+        ->and($crossOrg->json('error.code'))->toBe('not_found')
+        ->and(ResponseSignature::of($crossOrg))->toBe(ResponseSignature::of($missing));
+});
+
+test('自組織 project + ability 不足は 403 insufficient_ability のまま (エラー契約の維持)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner, ['read']);
+
+    $this->withHeaders(itemAuthorizationBearer($plain))
+        ->postJson("/api/v1/projects/{$project->id}/items", ['name' => 'アイテム'])
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'insufficient_ability')
+        ->assertJsonPath('error.details.required_ability', 'write');
+});
+
+test('OAuth CLI トークン (read scope のみ) でも 3 ケースが同じ順序契約で成立する', function (): void {
+    [$organizationA] = createOrganizationWithOwner('組織A');
+    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
+    $projectA = Project::factory()->forOrganization($organizationA)->create();
+    $projectB = Project::factory()->forOrganization($organizationB)->create();
+
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $ownerB,
+        organization: $organizationB,
+        client: OAuthTestHelpers::createMcpClient(name: 'Ordering CLI'),
+        scope: 'cli:use read',
+    );
+    $headers = ['Authorization' => 'Bearer '.$issued['access_token']];
+    $payload = ['name' => '越境'];
+
+    // (1) cross-org 実在 と (2) 不在 id が完全に同一
+    $crossOrg = $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$projectA->id}/items", $payload);
+    $missing = $this->withHeaders($headers)
+        ->postJson('/api/v1/projects/999999999/items', $payload);
+
+    expect($crossOrg->getStatusCode())->toBe(404)
+        ->and(ResponseSignature::of($crossOrg))->toBe(ResponseSignature::of($missing));
+
+    // (3) 自組織 project では従来どおり 403 insufficient_ability
+    $this->withHeaders($headers)
+        ->postJson("/api/v1/projects/{$projectB->id}/items", $payload)
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'insufficient_ability');
+});
diff --git a/tests/Feature/Auth/PasskeyAuditTrailTest.php b/tests/Feature/Auth/PasskeyAuditTrailTest.php
new file mode 100644
index 0000000..de4dcf2
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyAuditTrailTest.php
@@ -0,0 +1,101 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\SecurityEventType;
+use App\Models\Passkey;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Laravel\Passkeys\Events\PasskeyDeleted;
+use Laravel\Passkeys\Events\PasskeyRegistered;
+
+/*
+ * パスキー増減の監査記録 (audit-cycle-2 Medium / T108 S7)。
+ *
+ * パスキーは **単独でログインできる強い資格**のため、増減は監査上最重要事象として
+ * security_audit_events に残す (セッション乗っ取り後の永続化を事後追跡できるようにする)。
+ * 記録経路の網羅性は tests/Architecture/SecurityEventCoverageTest が deny-by-default で固定する。
+ *
+ * WebAuthn の登録は実ブラウザの authenticator が要るため、ここでは vendor が発火する
+ * イベント (Laravel\Passkeys\Events\PasskeyRegistered / PasskeyDeleted) を境界として扱う。
+ * 「そのイベントが実際に発火するか」は vendor 側の責務で、削除経路は下の HTTP テストが実走する。
+ */
+
+/** password / social を持たず passkey だけでログインするユーザー */
+function passkeyAuditUser(int $passkeys = 1): User
+{
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count($passkeys)->for($user)->create();
+
+    return $user;
+}
+
+/** 指定 event_type の行数。 */
+function passkeyAuditCount(SecurityEventType $type): int
+{
+    return SecurityAuditEvent::query()->where('event_type', $type->value)->count();
+}
+
+test('passkey 登録で passkey_registered が 1 行増える', function (): void {
+    $user = passkeyAuditUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    expect(passkeyAuditCount(SecurityEventType::PasskeyRegistered))->toBe(0);
+
+    PasskeyRegistered::dispatch($user, $passkey);
+
+    expect(passkeyAuditCount(SecurityEventType::PasskeyRegistered))->toBe(1);
+
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::PasskeyRegistered->value)
+        ->firstOrFail();
+    expect($event->user_id)->toBe($user->id)
+        // credential 本体 (公開鍵 / signature counter) は載せない
+        ->and($event->metadata)->toBe(['passkey_id' => $passkey->getKey()]);
+});
+
+test('passkey 削除で passkey_deleted が 1 行増える (HTTP 経路の実走)', function (): void {
+    // 手段が残る状態 (passkey 2 本) にして EnsureLoginMethodRemains を通す
+    $user = passkeyAuditUser(passkeys: 2);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertRedirect();
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeFalse();
+    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);
+
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', SecurityEventType::PasskeyDeleted->value)
+        ->firstOrFail();
+    expect($event->user_id)->toBe($user->id)
+        ->and($event->metadata)->toBe(['passkey_id' => $passkey->getKey()]);
+});
+
+test('EnsureLoginMethodRemains に弾かれた削除では監査行が増えない', function (): void {
+    // 唯一の passkey = 削除するとログイン手段が 0 になるため guard が transaction ごと巻き戻す。
+    // 削除自体が消えるので監査行も消えるのが整合 (意図した挙動として固定する)。
+    $user = passkeyAuditUser(passkeys: 1);
+    $passkey = $user->passkeys()->firstOrFail();
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete("/user/passkeys/{$passkey->getKey()}")
+        ->assertRedirect(route('settings.security'));
+
+    expect(Passkey::query()->whereKey($passkey->getKey())->exists())->toBeTrue();
+    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(0);
+});
+
+test('PasskeyDeleted イベント自体からも記録される (listener の直接検証)', function (): void {
+    $user = passkeyAuditUser();
+    $passkey = $user->passkeys()->firstOrFail();
+
+    PasskeyDeleted::dispatch($user, $passkey);
+
+    expect(passkeyAuditCount(SecurityEventType::PasskeyDeleted))->toBe(1);
+});
diff --git a/tests/Feature/Auth/PasskeyThrottleTest.php b/tests/Feature/Auth/PasskeyThrottleTest.php
new file mode 100644
index 0000000..685ad95
--- /dev/null
+++ b/tests/Feature/Auth/PasskeyThrottleTest.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Passkey;
+use App\Models\User;
+
+/*
+ * passkey.destroy の throttle (audit-cycle-2 Medium-2 / T108 S8)。
+ *
+ * vendor (Fortify) の passkey middleware は destroy に throttle を付けないため、
+ * EnsureLoginMethodRemains の DB::transaction + User 行 lockForUpdate を
+ * 認証済みユーザーが無制限に叩けた。他の passkey route と同じ 10/min に揃える。
+ *
+ * throttle は binding より前 (pre-binding 短絡) なので、429 は全 id で同一に返る =
+ * **新しい存在オラクルを作らない**ことも同時に固定する。
+ */
+
+/** passkey だけを持つユーザー (削除しても手段が残るよう複数持たせる)。 */
+function passkeyThrottleUser(int $passkeys = 3): User
+{
+    $user = User::factory()->ssoOnly()->create();
+    Passkey::factory()->count($passkeys)->for($user)->create();
+
+    return $user;
+}
+
+test('passkey.destroy は 11 回目で 429 になる (10/min)', function (): void {
+    $user = passkeyThrottleUser();
+
+    for ($i = 1; $i <= 10; $i++) {
+        $response = $this->actingAs($user)
+            ->withSession(freshRecentAuthSession())
+            ->from(route('settings.security'))
+            ->delete('/user/passkeys/999999999');
+        expect($response->getStatusCode())->not->toBe(429, "{$i} 回目で早すぎる 429");
+    }
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/999999999')
+        ->assertStatus(429);
+});
+
+test('429 到達回数は 不在 id と 他人の passkey id で同一 (新しい存在オラクルを作らない)', function (): void {
+    $victim = passkeyThrottleUser();
+    $othersPasskeyId = (string) $victim->passkeys()->firstOrFail()->getKey();
+
+    $attacker = passkeyThrottleUser();
+
+    // 他人の passkey id で 10 回 → 11 回目 429
+    for ($i = 1; $i <= 10; $i++) {
+        $this->actingAs($attacker)
+            ->withSession(freshRecentAuthSession())
+            ->from(route('settings.security'))
+            ->delete("/user/passkeys/{$othersPasskeyId}");
+    }
+    $othersResult = $this->actingAs($attacker)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/user/passkeys/{$othersPasskeyId}");
+
+    // 別ユーザーで不在 id を 10 回 → 11 回目 429 (同じ回数で同じ status)
+    $other = passkeyThrottleUser();
+    for ($i = 1; $i <= 10; $i++) {
+        $this->actingAs($other)
+            ->withSession(freshRecentAuthSession())
+            ->from(route('settings.security'))
+            ->delete('/user/passkeys/999999999');
+    }
+    $missingResult = $this->actingAs($other)
+        ->withSession(freshRecentAuthSession())
+        ->delete('/user/passkeys/999999999');
+
+    expect($othersResult->getStatusCode())->toBe(429)
+        ->and($missingResult->getStatusCode())->toBe(429);
+});
+
+test('bucket は別ユーザー間で共有されない (limiter キーが user 単位であることの証明)', function (): void {
+    $userA = passkeyThrottleUser();
+    $userB = passkeyThrottleUser();
+
+    for ($i = 1; $i <= 11; $i++) {
+        $this->actingAs($userA)
+            ->withSession(freshRecentAuthSession())
+            ->from(route('settings.security'))
+            ->delete('/user/passkeys/999999999');
+    }
+
+    // userA は使い切っているが userB は影響を受けない
+    $this->actingAs($userB)
+        ->withSession(freshRecentAuthSession())
+        ->from(route('settings.security'))
+        ->delete('/user/passkeys/999999999')
+        ->assertStatus(404);
+});
diff --git a/tests/Feature/Security/MemberRouteExistenceOracleTest.php b/tests/Feature/Security/MemberRouteExistenceOracleTest.php
new file mode 100644
index 0000000..9040523
--- /dev/null
+++ b/tests/Feature/Security/MemberRouteExistenceOracleTest.php
@@ -0,0 +1,191 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\AdminConsoleRole;
+use App\Enums\OrganizationRole;
+use App\Enums\ProjectRole;
+use App\Models\Project;
+use App\Models\User;
+use Illuminate\Testing\TestResponse;
+use Tests\Support\ResponseSignature;
+
+/*
+ * メンバー route の `{user}` 実在性オラクルの解消 (audit-cycle-2 High-1 横断 / T108 S3)。
+ *
+ * `{user}` はグローバル implicit binding のため、素のままだと
+ *   - 不在 id            → SubstituteBindings が 404
+ *   - 実在するが非メンバー → binding 成功 → 後段の短絡 (recent-auth 302 / 課金 302) or controller 404
+ * と**分岐する** = users.id の実在オラクルになる。
+ *
+ * S2 の priority pin は「テナント guard を持つ route」にしか効かないため、
+ *   - organizations.members.*  → Route::scopeBindings() で binding 段に閉じる (S3-a)
+ *   - projects.members.destroy → implicit binding を外し controller で手動解決する (S3-b)
+ * の 2 方式で個別に閉じる。
+ *
+ * 本テストの主張は一貫して「**2 ケースの応答が一致すること**」であり、
+ * 特定の status を要求するものではない (未契約組織なら両方 302 が正解)。
+ */
+
+/** 不在の {user} id (18 桁 pattern 内・実在しない)。 */
+const MREO_MISSING_USER_ID = '999999999';
+
+/** 組織に属さない実在ユーザー。 */
+function mreoOutsider(): User
+{
+    return User::factory()->create();
+}
+
+/**
+ * 「実在するが非メンバーの id」と「不在 id」で応答が完全一致することを表明する。
+ *
+ * @param  callable(string): TestResponse  $request
+ */
+function mreoAssertNoOracle(callable $request, User $outsider, ?int $expectedStatus = null): void
+{
+    $existing = $request((string) $outsider->id);
+    $missing = $request(MREO_MISSING_USER_ID);
+
+    if ($expectedStatus !== null) {
+        expect($existing->getStatusCode())->toBe($expectedStatus);
+    }
+    expect(ResponseSignature::of($existing))->toBe(
+        ResponseSignature::of($missing),
+        '実在の非メンバー id と 不在 id の応答が一致しない (存在オラクル)',
+    );
+}
+
+// --- S3-a: organizations.members.* (scopeBindings) ---
+
+test('stale recent-auth でも members.two-factor.reset の非メンバーと不在 id は同一 404', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $outsider = mreoOutsider();
+
+    // recent-auth を満たさないセッション (302 で短絡する状態) で叩く
+    mreoAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->delete("/organizations/{$organization->slug}/members/{$id}/two-factor", ['reason' => 'ロックアウト救済のため']),
+        $outsider,
+        404,
+    );
+});
+
+test('organizations.members.update の非メンバーと不在 id は同一 404', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $outsider = mreoOutsider();
+
+    mreoAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->patch("/organizations/{$organization->slug}/members/{$id}", ['role' => AdminConsoleRole::Admin->value]),
+        $outsider,
+        404,
+    );
+});
+
+test('organizations.members.destroy の非メンバーと不在 id は同一 404', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $outsider = mreoOutsider();
+
+    mreoAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->delete("/organizations/{$organization->slug}/members/{$id}"),
+        $outsider,
+        404,
+    );
+});
+
+test('organizations.members.update の正常系は従来どおり成功する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($owner)
+        ->patch("/organizations/{$organization->slug}/members/{$member->id}", [
+            'role' => AdminConsoleRole::Admin->value,
+        ])
+        ->assertRedirect();
+
+    expect($member->fresh()?->organizationRole($organization))->toBe(OrganizationRole::Admin);
+});
+
+test('organizations.members.destroy の正常系は従来どおり成功する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+
+    $this->actingAs($owner)
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}")
+        ->assertRedirect();
+
+    expect($organization->users()->whereKey($member->id)->exists())->toBeFalse();
+});
+
+test('members.two-factor.reset の正常系 (メンバー + fresh recent-auth) は従来どおり通る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill([
+        'two_factor_secret' => encrypt('test-totp-secret'),
+        'two_factor_recovery_codes' => encrypt(json_encode(['code-one'], JSON_THROW_ON_ERROR)),
+        'two_factor_confirmed_at' => now(),
+    ])->save();
+
+    $this->actingAs($owner)
+        ->withSession(freshRecentAuthSession())
+        ->delete("/organizations/{$organization->slug}/members/{$member->id}/two-factor", [
+            'reason' => 'ロックアウト救済のため解除する',
+        ])
+        ->assertRedirect();
+
+    expect($member->fresh()?->two_factor_confirmed_at)->toBeNull();
+});
+
+// --- S3-b: projects.members.destroy (手動解決) ---
+
+test('未契約組織でも projects.members.destroy の非メンバーと不在 id は完全同一応答', function (): void {
+    // 未契約組織 = 課金ゲートが 302 で短絡する状態。両ケースとも 302 に落ちれば分岐しない
+    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $outsider = mreoOutsider();
+
+    mreoAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->delete("/projects/{$project->id}/members/{$id}"),
+        $outsider,
+        302,
+    );
+});
+
+test('契約済み組織では projects.members.destroy の非メンバーと不在 id はともに 404', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $outsider = mreoOutsider();
+
+    mreoAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->delete("/projects/{$project->id}/members/{$id}"),
+        $outsider,
+        404,
+    );
+});
+
+test('projects.members.destroy の正常系 (明示メンバーの削除) は従来どおり成功する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $member = attachOrganizationMember($organization);
+    attachProjectMember($project, $member, ProjectRole::Member);
+
+    $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/members/{$member->id}")
+        ->assertRedirect();
+
+    expect($project->members()->whereKey($member->id)->exists())->toBeFalse();
+});
+
+test('projects.members.destroy に暗黙メンバー (pivot 無しの org admin) を指定しても従来どおり成功応答', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $admin = attachOrganizationMember($organization, OrganizationRole::Admin);
+
+    // pivot が無い = detach は no-op。挙動非退行 (404 に変わっていない) の確認
+    $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/members/{$admin->id}")
+        ->assertRedirect();
+});
diff --git a/tests/Feature/Security/NamedRateLimiterKeyTest.php b/tests/Feature/Security/NamedRateLimiterKeyTest.php
new file mode 100644
index 0000000..f0f8276
--- /dev/null
+++ b/tests/Feature/Security/NamedRateLimiterKeyTest.php
@@ -0,0 +1,90 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use Illuminate\Testing\TestResponse;
+
+/*
+ * named rate limiter のキーが **route parameter を含まない** ことの behavioral proof
+ * (T108 S4 検査 4(d))。
+ *
+ * ThrottleRequests は SubstituteBindings より前 (pre-binding) に走る短絡であり、
+ * 「全 id で同一の応答になる」ことが存在オラクル不成立の根拠になっている。
+ * しかし ThrottleRequests 自身が route parameter を読まなくても、
+ * `throttle:{bucket}` の limiter closure が `$request->route(...)` を読めば
+ * bucket が id ごとに分かれ、「429 になるまでの回数」が id の実在を漏らす。
+ * 静的検査 (TenantBoundaryOrderingTest 検査 4(a)) は closure まで届かないため、
+ * ここで実挙動として固定する。
+ *
+ * 検証方法: **同じ actor で route parameter だけを変えた 2 連続リクエスト**の
+ * `X-RateLimit-Remaining` が連続して減ること。limiter キーに route parameter が
+ * 混ざっていれば 2 回目の remaining は 1 回目と同じ値に戻る (= bucket が分かれた証拠)。
+ * bucket を実際に使い切る方式より少ないリクエストで同じ性質を証明できる。
+ */
+
+/** 応答の X-RateLimit-Remaining を int で取り出す。 */
+function limiterRemaining(TestResponse $response): int
+{
+    $remaining = $response->headers->get('X-RateLimit-Remaining');
+    expect($remaining)->not->toBeNull('X-RateLimit-Remaining が無い (throttle が付いていない?)');
+
+    return (int) $remaining;
+}
+
+test('api-read bucket は route parameter ごとに分かれない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $projectA = Project::factory()->forOrganization($organization)->create();
+    $projectB = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner, ['read']);
+    $headers = ['Authorization' => "Bearer {$plain}"];
+
+    $first = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectA->id}/items");
+    $second = $this->withHeaders($headers)->getJson("/api/v1/projects/{$projectB->id}/items");
+
+    expect(limiterRemaining($second))->toBe(
+        limiterRemaining($first) - 1,
+        'route parameter を変えたら残数が戻った = limiter キーが route parameter を含んでいる',
+    );
+});
+
+test('api-write bucket は route parameter ごとに分かれない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $projectA = Project::factory()->forOrganization($organization)->create();
+    $projectB = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner, ['write']);
+    $headers = ['Authorization' => "Bearer {$plain}"];
+
+    $first = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectA->id}/items", ['name' => 'A']);
+    $second = $this->withHeaders($headers)->postJson("/api/v1/projects/{$projectB->id}/items", ['name' => 'B']);
+
+    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
+});
+
+test('api-read bucket は不在 project id のリクエストでも同じ bucket を消費する', function (): void {
+    // 不在 id が別 bucket に落ちると「429 になるまでの回数」で実在が漏れる
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    [, $plain] = issueApiKey($organization, $owner, ['read']);
+    $headers = ['Authorization' => "Bearer {$plain}"];
+
+    $existing = $this->withHeaders($headers)->getJson("/api/v1/projects/{$project->id}/items");
+    $missing = $this->withHeaders($headers)->getJson('/api/v1/projects/999999999/items');
+
+    expect($missing->getStatusCode())->toBe(404);
+    expect(limiterRemaining($missing))->toBe(limiterRemaining($existing) - 1);
+});
+
+test('render-trigger bucket は route parameter ごとに分かれない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $projectA = Project::factory()->forOrganization($organization)->create();
+    $projectB = Project::factory()->forOrganization($organization)->create();
+
+    // 応答が 4xx でも throttle は最外周で消費される (limiter キーの検証が目的)
+    $first = $this->actingAs($owner)
+        ->postJson("/projects/{$projectA->id}/manuals/999999999/render");
+    $second = $this->actingAs($owner)
+        ->postJson("/projects/{$projectB->id}/manuals/999999999/render");
+
+    expect(limiterRemaining($second))->toBe(limiterRemaining($first) - 1);
+});
diff --git a/tests/Feature/Security/SecurityAuditTrailCoverageTest.php b/tests/Feature/Security/SecurityAuditTrailCoverageTest.php
new file mode 100644
index 0000000..4c2e01b
--- /dev/null
+++ b/tests/Feature/Security/SecurityAuditTrailCoverageTest.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\SecurityEventType;
+use App\Models\SecurityAuditEvent;
+use App\Models\User;
+use Illuminate\Auth\Events\PasswordReset;
+use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
+use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
+use Laravel\Socialite\Contracts\Provider;
+use Laravel\Socialite\Contracts\User as SocialiteUserContract;
+use Laravel\Socialite\Facades\Socialite;
+use Mockery\MockInterface;
+
+/*
+ * security_audit_events の記録経路のうち、**担保テストが存在しなかった event_type** を
+ * 実際に記録されるところまで固定する (T108 S7)。
+ *
+ * tests/Architecture/SecurityEventCoverageTest が「全 case に担保テストがあること」を
+ * deny-by-default で機械保証するようになったため、その要求を満たす実体としてここに置く。
+ * 既に担保のある case は既存テストが引き続き担う (map の covered_by がその対応表)。
+ *
+ * 検証の境界: RecordSecurityEvent は event subscriber なので「対象イベントが発火したら
+ * 行が増える」ことが記録経路の契約。HTTP で安く駆動できるものは実走し、
+ * vendor 内部でしか発火しないもの (パスワードリセット完了 / TOTP 確定・解除) は
+ * フレームワークのイベントを直接 dispatch して listener 側を固定する。
+ */
+
+/** 指定 event_type の行数。 */
+function auditTrailCount(SecurityEventType $type): int
+{
+    return SecurityAuditEvent::query()->where('event_type', $type->value)->count();
+}
+
+test('ログイン失敗が login_failed として記録される', function (): void {
+    $user = User::factory()->create(['email' => 'failed@example.com']);
+
+    $this->from('/login')->post('/login', [
+        'email' => 'failed@example.com',
+        'password' => 'wrong-password',
+    ]);
+
+    expect(auditTrailCount(SecurityEventType::LoginFailed))->toBe(1);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', 'login_failed')
+            ->where('user_id', $user->id)
+            ->exists(),
+    )->toBeTrue();
+});
+
+test('メールアドレス変更が email_changed として記録される', function (): void {
+    $user = User::factory()->create(['email' => 'before@example.com']);
+
+    $this->actingAs($user)
+        ->withSession(freshRecentAuthSession())
+        ->put('/user/profile-information', [
+            'name' => '変更後の名前',
+            'email' => 'after@example.com',
+        ])
+        ->assertSessionHasNoErrors();
+
+    expect($user->fresh()?->email)->toBe('after@example.com');
+    expect(auditTrailCount(SecurityEventType::EmailChanged))->toBe(1);
+
+    // 平文 email を監査行に落とさない (PII は CipherSweet 管理の users 側に閉じる)
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', 'email_changed')
+        ->firstOrFail();
+    expect($event->user_id)->toBe($user->id)
+        ->and($event->metadata)->toBeNull();
+});
+
+test('ソーシャルアカウント連携が social_account_linked として記録される', function (): void {
+    $user = User::factory()->create(['email' => 'link@example.com']);
+
+    /** @var SocialiteUserContract&MockInterface $socialiteUser */
+    $socialiteUser = Mockery::mock(SocialiteUserContract::class);
+    $socialiteUser->shouldReceive('getId')->andReturn('g-link-1');
+    $socialiteUser->shouldReceive('getEmail')->andReturn('link@example.com');
+    $socialiteUser->shouldReceive('getName')->andReturn('Linked User');
+
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('user')->andReturn($socialiteUser);
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+
+    $this->actingAs($user)
+        ->withSession(['social_auth_intent' => 'link'])
+        ->get('/auth/google/callback');
+
+    expect(auditTrailCount(SecurityEventType::SocialAccountLinked))->toBe(1);
+    $event = SecurityAuditEvent::query()
+        ->where('event_type', 'social_account_linked')
+        ->firstOrFail();
+    expect($event->metadata)->toBe(['provider' => 'google']);
+});
+
+test('パスワードリセット完了が password_reset として記録される', function (): void {
+    // Illuminate\Auth\Events\PasswordReset は Fortify / Laravel のリセット完了で発火する
+    $user = User::factory()->create();
+
+    event(new PasswordReset($user));
+
+    expect(auditTrailCount(SecurityEventType::PasswordReset))->toBe(1);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', 'password_reset')
+            ->where('user_id', $user->id)
+            ->exists(),
+    )->toBeTrue();
+});
+
+test('2 段階認証の確定が two_factor_enabled として記録される', function (): void {
+    $user = User::factory()->create();
+
+    TwoFactorAuthenticationConfirmed::dispatch($user);
+
+    expect(auditTrailCount(SecurityEventType::TwoFactorEnabled))->toBe(1);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', 'two_factor_enabled')
+            ->where('user_id', $user->id)
+            ->exists(),
+    )->toBeTrue();
+});
+
+test('2 段階認証の解除が two_factor_disabled として記録される', function (): void {
+    $user = User::factory()->create();
+
+    TwoFactorAuthenticationDisabled::dispatch($user);
+
+    expect(auditTrailCount(SecurityEventType::TwoFactorDisabled))->toBe(1);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', 'two_factor_disabled')
+            ->where('user_id', $user->id)
+            ->exists(),
+    )->toBeTrue();
+});
+
+test('ログアウトが logout として記録される', function (): void {
+    $user = User::factory()->create();
+
+    $this->actingAs($user)->post('/logout');
+
+    expect(auditTrailCount(SecurityEventType::Logout))->toBe(1);
+    expect(
+        SecurityAuditEvent::query()
+            ->where('event_type', 'logout')
+            ->where('user_id', $user->id)
+            ->exists(),
+    )->toBeTrue();
+});
diff --git a/tests/Feature/Security/TenantBoundaryPrecedenceTest.php b/tests/Feature/Security/TenantBoundaryPrecedenceTest.php
new file mode 100644
index 0000000..817eb54
--- /dev/null
+++ b/tests/Feature/Security/TenantBoundaryPrecedenceTest.php
@@ -0,0 +1,257 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use App\Models\User;
+use Illuminate\Support\Facades\Auth;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Testing\TestResponse;
+use Tests\Support\OAuthTestHelpers;
+use Tests\Support\ResponseSignature;
+
+/*
+ * テナント境界 404 が「binding より後のあらゆる短絡」より前に走ることの**振る舞い**固定
+ * (audit-cycle-2 High-1 / T108 S2)。
+ *
+ * 不在 id は SubstituteBindings が 404 にする。したがって binding より後・テナント guard
+ * より前に 404 以外で短絡する middleware があると、
+ *   「他組織に実在 = その短絡の応答 (302/402/409) / 不在 = 404」
+ * という 1 bit の存在オラクルになる。監査の横断確認で、課金ゲート 302・verified 302・
+ * 2FA 強制 302・Inertia version mismatch 409 のすべてがテナント境界より先に走っていた。
+ *
+ * 本テストは「その状態のユーザーで cross-org 実在 project と不在 project id を叩き、
+ * 応答が **status / ヘッダ / body すべて同一** であること」を固定する
+ * (= 分岐しない = オラクル不成立)。同時に「自組織 project では従来どおりの着地」も
+ * 固定し、課金ゲートの『行き先のない詰みを作らない』契約を壊していないことを示す。
+ *
+ * 順序そのものの静的固定は tests/Architecture/TenantBoundaryOrderingTest。
+ */
+
+/** 不在の {project} id (18 桁 pattern 内・実在しない)。 */
+const TBP_MISSING_PROJECT_ID = '999999999';
+
+/**
+ * cross-org の実在 project と 不在 id で応答が完全一致することを表明する。
+ *
+ * @param  callable(string): TestResponse  $request
+ */
+function tbpAssertNoOracle(callable $request, Project $crossOrgProject, int $expectedStatus): void
+{
+    $crossOrg = $request((string) $crossOrgProject->id);
+    $missing = $request(TBP_MISSING_PROJECT_ID);
+
+    expect($crossOrg->getStatusCode())->toBe(
+        $expectedStatus,
+        'cross-org の実在 project が期待した status で閉じていない',
+    );
+    expect(ResponseSignature::of($crossOrg))->toBe(
+        ResponseSignature::of($missing),
+        'cross-org 実在 project と 不在 project id の応答が一致しない (存在オラクル)',
+    );
+}
+
+/** 他組織に実在する project を作る。 */
+function tbpForeignProject(): Project
+{
+    [$otherOrg] = createOrganizationWithOwner('他組織');
+
+    return Project::factory()->forOrganization($otherOrg)->create();
+}
+
+test('メール未確認ユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $owner->forceFill(['email_verified_at' => null])->save();
+    $foreign = tbpForeignProject();
+
+    tbpAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
+        $foreign,
+        404,
+    );
+});
+
+test('未契約組織のユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
+    // grandfatherFreePlan: false = 真の未契約組織 (課金ゲートが onboarding へ 302 する)
+    [, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
+    $foreign = tbpForeignProject();
+
+    tbpAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
+        $foreign,
+        404,
+    );
+});
+
+test('2FA 強制の未準拠ユーザーでも cross-org 実在 project と不在 id は同一 404', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    // owner は 2FA 未設定 = 未準拠 (RequireTwoFactorForEnforcedOrganizations が 302 する状態)
+    expect($owner->twoFactorStatus()->value)->toBe('disabled');
+    $foreign = tbpForeignProject();
+
+    tbpAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)->get("/projects/{$id}"),
+        $foreign,
+        404,
+    );
+});
+
+test('Inertia version mismatch (409 契機) でも cross-org 実在 project と不在 id は同一 404', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $foreign = tbpForeignProject();
+
+    tbpAssertNoOracle(
+        fn (string $id) => $this->actingAs($owner)
+            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => 'stale-version'])
+            ->get("/projects/{$id}"),
+        $foreign,
+        404,
+    );
+});
+
+test('未契約組織でも自組織 project は従来どおり課金ゲートの 302 で受ける (詰みを作らない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner('未契約組織', grandfatherFreePlan: false);
+    $ownProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$ownProject->id}")
+        ->assertRedirect();
+});
+
+test('メール未確認でも自組織 project は従来どおり verified の 302 で受ける', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $owner->forceFill(['email_verified_at' => null])->save();
+    $ownProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$ownProject->id}")
+        ->assertRedirect(route('verification.notice'));
+});
+
+test('2FA 強制の未準拠ユーザーでも自組織 project は従来どおり 302 で受ける', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['two_factor_required' => true])->save();
+    $ownProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)
+        ->get("/projects/{$ownProject->id}")
+        ->assertRedirect(route('settings.security'));
+});
+
+/*
+ * --- pre-binding 短絡の性質固定 (S4 検査 4 の behavioral proof) ---
+ *
+ * SubstituteBindings **より前**に走る短絡 (未認証 302 / throttle 429 / CSRF 419 /
+ * actor 解決失敗 401) は、route parameter を読まないため実在 id と不在 id で
+ * 応答が分岐しない。静的検査 (TenantBoundaryOrderingTest 検査 4) は
+ * 「呼び出し先クラス経由の間接参照」までは証明できないため、実応答でも固定する。
+ */
+
+test('未認証 (pre-binding 短絡) では実在 project と不在 id が同一応答', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $existing = Project::factory()->forOrganization($organization)->create();
+
+    tbpAssertNoOracle(
+        fn (string $id) => $this->get("/projects/{$id}"),
+        $existing,
+        302,
+    );
+});
+
+test('API の actor 解決失敗 (pre-binding 短絡) では実在 project と不在 id が同一 401', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $existing = Project::factory()->forOrganization($organization)->create();
+    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read', 'write']);
+    // 発行者削除 = actor (人間の帰属) を解決できない → 403 actor_not_resolvable
+    $apiKey->forceFill(['created_by_user_id' => null])->save();
+
+    $crossOrg = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
+        ->getJson("/api/v1/projects/{$existing->id}/items");
+    $missing = $this->withHeaders(['Authorization' => "Bearer {$plain}"])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items');
+
+    expect($crossOrg->getStatusCode())->toBe(403)
+        ->and($crossOrg->json('error.code'))->toBe('actor_not_resolvable')
+        ->and(ResponseSignature::of($crossOrg))->toBe(ResponseSignature::of($missing));
+});
+
+/*
+ * --- S2 の副作用 (ResolveApiActor を binding より前へ移した結果) ---
+ *
+ * actor 解決失敗時の応答は、不在 project id に対しても **404 ではなく 401/403** になる。
+ * これは「actor が解決できない = リソースの話に到達していない」という意味論として正しく、
+ * かつ実在 id と不在 id で同一のため存在オラクルにならない。
+ * 5 つの失効状態を個別に登録し、将来 binding より後ろへ戻されたら red になるようにする。
+ */
+
+test('API キー失効後は不在 project id でも 401 (404 にならない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read']);
+    $apiKey->forceFill(['revoked_at' => now()])->save();
+
+    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
+        ->assertUnauthorized();
+});
+
+test('API キー発行者削除後は不在 project id でも 403 actor_not_resolvable (404 にならない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    [$apiKey, $plain] = issueApiKey($organization, $owner, ['read']);
+    $apiKey->forceFill(['created_by_user_id' => null])->save();
+
+    $this->withHeaders(['Authorization' => "Bearer {$plain}"])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
+        ->assertForbidden()
+        ->assertJsonPath('error.code', 'actor_not_resolvable');
+});
+
+test('OAuth トークン失効後は不在 project id でも 401 (404 にならない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $owner,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Revoke CLI'),
+    );
+    DB::table('oauth_access_tokens')->update(['revoked' => true]);
+    Auth::forgetGuards();
+
+    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
+        ->assertUnauthorized();
+});
+
+test('CLI セッション失効後は不在 project id でも 401 (404 にならない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $owner,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Session CLI'),
+    );
+    $issued['session']->revoke();
+    Auth::forgetGuards();
+
+    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
+        ->assertUnauthorized();
+});
+
+test('membership 剥奪後は不在 project id でも 401 (404 にならない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $issued = OAuthTestHelpers::issueCliSessionTokens(
+        test: $this,
+        user: $member,
+        organization: $organization,
+        client: OAuthTestHelpers::createMcpClient(name: 'Membership CLI'),
+    );
+    $organization->users()->detach($member->id);
+    Auth::forgetGuards();
+    expect($owner)->toBeInstanceOf(User::class);
+
+    $this->withHeaders(['Authorization' => 'Bearer '.$issued['access_token']])
+        ->getJson('/api/v1/projects/'.TBP_MISSING_PROJECT_ID.'/items')
+        ->assertUnauthorized();
+});
diff --git a/tests/Feature/Security/TrustedProxiesTest.php b/tests/Feature/Security/TrustedProxiesTest.php
new file mode 100644
index 0000000..b95183c
--- /dev/null
+++ b/tests/Feature/Security/TrustedProxiesTest.php
@@ -0,0 +1,121 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Http\Middleware\RedirectToHttps;
+use Illuminate\Contracts\Http\Kernel;
+use Illuminate\Http\Middleware\TrustProxies;
+use Illuminate\Support\Facades\Route;
+use Tests\TestCase;
+
+/*
+ * client IP / X-Forwarded-Proto の信頼境界 (audit-cycle-2 High-2 / T108 S5・S6)。
+ *
+ * かつて trustProxies(at: '*') だったため $request->ip() は XFF 最左 =
+ * **クライアントが自由に書ける値**だった。allowlist 化した後の実挙動を固定する。
+ *
+ * 検証は「静的に at() が呼ばれていないこと」ではなく **振る舞い**で行う:
+ * config('trustedproxy.proxies') を変えると ip() が変わる = framework の
+ * config fallback 経路が生きている、という形で固定する
+ * (TrustProxies の static prop に依存する検査を作らない)。
+ */
+
+beforeEach(function (): void {
+    // 応答本文に解決後の client IP / secure 判定を出すだけの probe route
+    Route::middleware('web')->get('/_ip-probe', fn () => response(
+        (string) request()->ip().'|'.(request()->isSecure() ? 'https' : 'http'),
+    ));
+});
+
+/** probe を叩いて [ip, scheme] を返す。 */
+function ipProbe(TestCase $test, array $headers = []): array
+{
+    $response = $test->withHeaders($headers)->get('/_ip-probe');
+    $response->assertOk();
+
+    return explode('|', (string) $response->getContent());
+}
+
+test('proxies が空なら XFF は無視される (REMOTE_ADDR が client IP)', function (): void {
+    config(['trustedproxy.proxies' => []]);
+
+    [$ip] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);
+
+    expect($ip)->not->toBe('9.9.9.9');
+    expect($ip)->toBe('127.0.0.1');
+});
+
+test('proxies に接続元を登録すると XFF 由来の client IP になる', function (): void {
+    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);
+
+    [$ip] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);
+
+    expect($ip)->toBe('9.9.9.9');
+});
+
+test('config を配列で上書きしても fallback が効く (config:cache 相当)', function (): void {
+    // at() を呼んでいない = 常に config を読む。config:cache 後は plain array になるため
+    // 「配列で上書きした状態」で挙動が変わることを確認する
+    config(['trustedproxy.proxies' => ['127.0.0.0/8']]);
+    [$trusted] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);
+
+    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
+    [$untrusted] = ipProbe($this, ['X-Forwarded-For' => '9.9.9.9']);
+
+    expect($trusted)->toBe('9.9.9.9');
+    expect($untrusted)->toBe('127.0.0.1');
+});
+
+test('多段 XFF で hop を取りこぼすと client IP がその hop になる (runbook の警告の実挙動)', function (): void {
+    // 経路: client(1.2.3.4) → hop(10.0.0.5) → app。hop を信頼していないので
+    // client IP は hop の 10.0.0.5 に固定される = 全利用者が 1 バケットに落ちる (自己 DoS)
+    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);
+    [$missedHop] = ipProbe($this, ['X-Forwarded-For' => '1.2.3.4, 10.0.0.5']);
+    expect($missedHop)->toBe('10.0.0.5');
+
+    // hop も信頼すれば本来の client IP まで遡れる
+    config(['trustedproxy.proxies' => ['127.0.0.1/32', '10.0.0.0/8']]);
+    [$allHops] = ipProbe($this, ['X-Forwarded-For' => '1.2.3.4, 10.0.0.5']);
+    expect($allHops)->toBe('1.2.3.4');
+});
+
+/*
+ * --- S6: RedirectToHttps は TrustProxies の **後** に走る ---
+ *
+ * prepend していたため TrustProxies より前に走り、$request->isSecure() が
+ * X-Forwarded-Proto を見られなかった。LB 終端 + FORCE_HTTPS_REDIRECT=true で
+ * 308 の無限ループになる潜在バグ。
+ */
+
+test('global middleware で TrustProxies が RedirectToHttps より前に走る', function (): void {
+    /** @var Kernel $kernel */
+    $kernel = app(Kernel::class);
+    $global = $kernel->getGlobalMiddleware();
+
+    $trustProxies = array_search(TrustProxies::class, $global, true);
+    $redirect = array_search(RedirectToHttps::class, $global, true);
+
+    expect($trustProxies)->not->toBeFalse('TrustProxies が global middleware に無い');
+    expect($redirect)->not->toBeFalse('RedirectToHttps が global middleware に無い (route group へ移動した?)');
+    expect($trustProxies)->toBeLessThan(
+        $redirect,
+        'RedirectToHttps が TrustProxies より前に走ると X-Forwarded-Proto を見られず 308 ループになる',
+    );
+});
+
+test('LB 終端 (X-Forwarded-Proto: https) では 308 が返らない', function (): void {
+    config(['security.force_https_redirect' => true]);
+    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);
+
+    [, $scheme] = ipProbe($this, ['X-Forwarded-Proto' => 'https']);
+    expect($scheme)->toBe('https');
+});
+
+test('LB 終端でも X-Forwarded-Proto: http なら 308 が返る', function (): void {
+    config(['security.force_https_redirect' => true]);
+    config(['trustedproxy.proxies' => ['127.0.0.1/32']]);
+
+    $this->withHeaders(['X-Forwarded-Proto' => 'http'])
+        ->get('/_ip-probe')
+        ->assertStatus(308);
+});
diff --git a/tests/Feature/Support/ProductionEnvGuardTest.php b/tests/Feature/Support/ProductionEnvGuardTest.php
index 1b31d20..1bb4add 100644
--- a/tests/Feature/Support/ProductionEnvGuardTest.php
+++ b/tests/Feature/Support/ProductionEnvGuardTest.php
@@ -21,6 +21,8 @@
     config(['trusted_hosts.exact_hosts' => ['app.example.com']]);
     config(['trusted_hosts.wildcard_suffixes' => []]);
     config(['trusted_hosts.raw_wildcard_suffixes' => []]);
+    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
+    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8']]);
 });
 
 test('全 production 必須項目が埋まっていれば violations は空', function (): void {
@@ -184,3 +186,63 @@
     config(['app.url' => 'https://app.example.com']);
     $this->artisan('production:preflight')->assertFailed();
 });
+
+/*
+ * TrustProxies allowlist (client IP の信頼境界。audit-cycle-2 High-2 / T108 S5)。
+ * 未宣言のまま production を起動すると fail-fast するのは **意図した破壊的変更**。
+ */
+
+test('TRUSTED_PROXIES が未設定なら violation', function (): void {
+    config(['trustedproxy.proxies' => []]);
+    config(['trustedproxy.raw_proxies' => ['']]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('TRUSTED_PROXIES is not set');
+});
+
+test('TRUSTED_PROXIES に * が含まれるなら violation (XFF 偽装が通る)', function (): void {
+    config(['trustedproxy.proxies' => []]);
+    config(['trustedproxy.raw_proxies' => ['*']]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('Trusting every address');
+});
+
+test('TRUSTED_PROXIES に REMOTE_ADDR が含まれるなら violation', function (): void {
+    config(['trustedproxy.proxies' => ['REMOTE_ADDR']]);
+    config(['trustedproxy.raw_proxies' => ['REMOTE_ADDR']]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('REMOTE_ADDR');
+});
+
+test('TRUSTED_PROXIES に書式不正が含まれるなら violation (config 段の silent drop を表面化)', function (): void {
+    // config 段では落ちるので proxies には現れない = raw を見ないと検知できない
+    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
+    config(['trustedproxy.raw_proxies' => ['10.0.0.0/8', '999.999.999.999/99']]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('invalid value');
+});
+
+test('TRUSTED_PROXIES に none と他の値を併記したら violation (曖昧宣言)', function (): void {
+    config(['trustedproxy.proxies' => ['10.0.0.0/8']]);
+    config(['trustedproxy.raw_proxies' => ['none', '10.0.0.0/8']]);
+    $errors = (new ProductionEnvGuard)->violations();
+    expect($errors)->toHaveCount(1);
+    expect($errors[0])->toContain('must be declared alone');
+});
+
+test('TRUSTED_PROXIES=none 単独なら violations は空 (プロキシ無し構成の明示宣言)', function (): void {
+    config(['trustedproxy.proxies' => []]);
+    config(['trustedproxy.raw_proxies' => ['none']]);
+    expect((new ProductionEnvGuard)->violations())->toBe([]);
+});
+
+test('TRUSTED_PROXIES 未設定なら enforce() が起動を止める (production の fail-fast)', function (): void {
+    config(['trustedproxy.proxies' => []]);
+    config(['trustedproxy.raw_proxies' => ['']]);
+
+    expect(fn () => (new ProductionEnvGuard)->enforce())
+        ->toThrow(RuntimeException::class, 'TRUSTED_PROXIES is not set');
+});
diff --git a/tests/Support/ResponseSignature.php b/tests/Support/ResponseSignature.php
new file mode 100644
index 0000000..4480b99
--- /dev/null
+++ b/tests/Support/ResponseSignature.php
@@ -0,0 +1,96 @@
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
+        'expires',
+        'age',
+        'etag',
+        'last-modified',
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
diff --git a/tests/Support/Routing/NestedRouteDefenseInventory.php b/tests/Support/Routing/NestedRouteDefenseInventory.php
new file mode 100644
index 0000000..1095be6
--- /dev/null
+++ b/tests/Support/Routing/NestedRouteDefenseInventory.php
@@ -0,0 +1,268 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Routing;
+
+use App\Enums\Security\NestedRouteDefenseMode;
+use Illuminate\Routing\Route as RoutingRoute;
+use Illuminate\Routing\Router;
+use Illuminate\Support\Facades\Route;
+
+/**
+ * route parameter ごとの IDOR / 存在オラクル 防御方式の inventory (単一 source of truth)。
+ *
+ * 2 つの Architecture テストが同じ inventory を読むため、Pest のファイル読み込み順に
+ * 依存する global 関数ではなく静的クラスに置く:
+ *   - NestedRouteIdorDefenseTest    … 分類漏れ・stale・理由の突合 (deny-by-default)
+ *   - TenantBoundaryOrderingTest    … モードごとの解決後 middleware 順序不変条件
+ *
+ * **母集団は 1 個以上の parameter を持つ named route** (旧実装は 2 個以上だった)。
+ * 2+param に絞ると単独 param の route (`projects/{project}` / `user/passkeys/{passkey}` 等) が
+ * 母集団から丸ごと外れ、テナント越境が分類対象にならない。audit-cycle-2 High-1 で
+ * 実際に穴が残ったのはこの層である。
+ *
+ * **分類は route 単位ではなく parameter 単位**。同じ param 名でも route ごとに防御方式が
+ * 違いうる (例: {user} は organizations.members.* が scopeBindings、
+ * projects.members.destroy が手動解決)。route 単位の allowlist は param 1 つの分類漏れを
+ * 丸ごと免除してしまうため使わない。
+ */
+final class NestedRouteDefenseInventory
+{
+    /**
+     * route 名 => (parameter 名 => 防御方式)。
+     *
+     * @return array<string, array<string, NestedRouteDefenseMode>>
+     */
+    public static function inventory(): array
+    {
+        $scoped = NestedRouteDefenseMode::ScopeBindings;
+        $binder = NestedRouteDefenseMode::ScopedBinder;
+        $tenant = NestedRouteDefenseMode::TenantGuardMiddleware;
+        $manual = NestedRouteDefenseMode::ManualOwnerScopedResolution;
+        $nonRes = NestedRouteDefenseMode::NonResourceParameter;
+
+        // {project} は web/API とも テナント guard middleware が binding 直後に走る (T108 S2)
+        $project = ['project' => $tenant];
+
+        return [
+            // --- REST API v1 ---
+            'api.v1.projects.show' => $project,
+            'api.v1.projects.items.index' => $project,
+            'api.v1.projects.items.store' => $project,
+            // {item} は $project->items() 経由 (scopeBindings)
+            'api.v1.projects.items.update' => [...$project, 'item' => $scoped],
+            'api.v1.projects.items.destroy' => [...$project, 'item' => $scoped],
+
+            // --- 撮影 PWA (/app/*。{manual}∈{project}, {cut}∈{manual}, {take}∈{cut}) ---
+            'capture.manuals.index' => $project,
+            'capture.manuals.show' => [...$project, 'manual' => $scoped],
+            'capture.takes.upload-url' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
+            'capture.takes.store' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
+            'capture.takes.update' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+            'capture.takes.destroy' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+            'capture.takes.adopt' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+            'capture.takes.downloaded' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+            'capture.takes.playback' => [...$project, 'manual' => $scoped, 'cut' => $scoped, 'take' => $scoped],
+
+            // --- 業務 route (web) ---
+            'projects.show' => $project,
+            'projects.edit' => $project,
+            'projects.update' => $project,
+            'projects.destroy' => $project,
+            'projects.items.store' => $project,
+            'projects.items.update' => [...$project, 'item' => $scoped],
+            'projects.items.destroy' => [...$project, 'item' => $scoped],
+            'projects.categories.index' => $project,
+            'projects.categories.store' => $project,
+            'projects.categories.reorder' => $project,
+            'projects.categories.update' => [...$project, 'category' => $scoped],
+            'projects.categories.destroy' => [...$project, 'category' => $scoped],
+            'projects.manuals.create' => $project,
+            'projects.manuals.store' => $project,
+            'projects.manuals.show' => [...$project, 'manual' => $scoped],
+            'projects.manuals.edit' => [...$project, 'manual' => $scoped],
+            'projects.manuals.update' => [...$project, 'manual' => $scoped],
+            'projects.manuals.destroy' => [...$project, 'manual' => $scoped],
+            'projects.manuals.duplicate' => [...$project, 'manual' => $scoped],
+            'projects.manuals.scenario.update' => [...$project, 'manual' => $scoped],
+            'projects.manuals.source-documents.store' => [...$project, 'manual' => $scoped],
+            'projects.manuals.analyze' => [...$project, 'manual' => $scoped],
+            // {analysisJob} は $manual->analysisJobs() 経由
+            'projects.manuals.jobs.show' => [...$project, 'manual' => $scoped, 'analysisJob' => $scoped],
+            'projects.manuals.render' => [...$project, 'manual' => $scoped],
+            'projects.manuals.preview' => [...$project, 'manual' => $scoped],
+            'projects.manuals.download' => [...$project, 'manual' => $scoped],
+            // {renderJob} は $manual->renderJobs() 経由
+            'projects.manuals.render-jobs.show' => [...$project, 'manual' => $scoped, 'renderJob' => $scoped],
+            'projects.manuals.render-jobs.playback' => [...$project, 'manual' => $scoped, 'renderJob' => $scoped],
+            'projects.members.store' => $project,
+            // {user} は ProjectMemberController::destroy が $organization->users() から手動解決する
+            // (implicit binding を外して不在 id と実在の非メンバーを同一経路に落とす。T108 S3-b)
+            'projects.members.destroy' => [...$project, 'user' => $manual],
+
+            // --- 組織 (親 {organization} は MembershipScopedOrganizationBinder が membership スコープで解決) ---
+            'organizations.settings' => ['organization' => $binder],
+            'organizations.update' => ['organization' => $binder],
+            'organizations.switch' => ['organization' => $binder],
+            'organizations.onboarding.cli' => ['organization' => $binder],
+            'organizations.onboarding.mcp' => ['organization' => $binder],
+            'organizations.transfer-ownership' => ['organization' => $binder],
+            'organizations.two-factor-requirement.update' => ['organization' => $binder],
+            'organizations.invitations.store' => ['organization' => $binder],
+            // {invitation} は $organization->invitations() 経由
+            'organizations.invitations.revoke' => ['organization' => $binder, 'invitation' => $scoped],
+            'organizations.api-keys.index' => ['organization' => $binder],
+            'organizations.api-keys.store' => ['organization' => $binder],
+            // {apiKey} は $organization->apiKeys() 経由
+            'organizations.api-keys.revoke' => ['organization' => $binder, 'apiKey' => $scoped],
+            'organizations.api-keys.sessions.index' => ['organization' => $binder],
+            // {oauthSession} は $organization->oauthSessions() 経由 (controller 内の再検査は二重防御)
+            'organizations.api-keys.sessions.revoke' => ['organization' => $binder, 'oauthSession' => $scoped],
+            // {user} は $organization->users() 経由 (scopeBindings。T108 S3-a)
+            'organizations.members.update' => ['organization' => $binder, 'user' => $scoped],
+            'organizations.members.destroy' => ['organization' => $binder, 'user' => $scoped],
+            'organizations.members.two-factor.reset' => ['organization' => $binder, 'user' => $scoped],
+
+            // --- 個人スコープ ---
+            // {notification} は NotificationController が $user->notifications() から手動解決する
+            'notifications.open' => ['notification' => $manual],
+            'notifications.read' => ['notification' => $manual],
+            // {passkey} は SelfScopedPasskeyBinder が認証ユーザーの passkeys() スコープで解決する
+            'passkey.destroy' => ['passkey' => $binder],
+
+            // --- テナント親子でない param (理由は nonTenantReasons に必須登録) ---
+            'social.callback' => ['provider' => $nonRes],
+            'social.redirect' => ['provider' => $nonRes, 'intent' => $nonRes],
+            'verification.verify' => ['id' => $nonRes, 'hash' => $nonRes],
+            'password.reset' => ['token' => $nonRes],
+            'debug.login-as' => ['userId' => $nonRes],
+            'mcp.oauth.authorization-server.nested' => ['path' => $nonRes],
+            'mcp.oauth.protected-resource.nested' => ['path' => $nonRes],
+            'storage.local' => ['path' => $nonRes],
+            'storage.local.upload' => ['path' => $nonRes],
+        ];
+    }
+
+    /**
+     * 非テナントモード (NonResourceParameter / PublicGlobalResource) を宣言した parameter の理由。
+     *
+     * key は `{route名}#{parameter名}`。**非テナント宣言は必ずここに理由を持つ**
+     * (逆に、ここに理由があるのに宣言がテナント防御モードなら stale として fail)。
+     * これは例外機構ではなく「テナント防御が要らないと判断した根拠を残す」ための
+     * deny-by-default sentinel であり、判断の誤りを人間のレビューに晒す唯一の場所である。
+     *
+     * @return array<string, string>
+     */
+    public static function nonTenantReasons(): array
+    {
+        return [
+            'social.callback#provider' => 'config 由来の固定集合 (有効な OAuth provider 名) で検証される。テナント親子でない',
+            'social.redirect#provider' => 'config 由来の固定集合で検証される。テナント親子でない',
+            'social.redirect#intent' => 'ログイン/連携の 2 値 intent。リソース id ではない',
+            'verification.verify#id' => 'Fortify の署名付き URL (MustVerifyEmail) の構成要素。署名検証が改ざんを閉じる',
+            'verification.verify#hash' => 'email hash。署名付き URL の構成要素でリソース id ではない',
+            'password.reset#token' => 'Fortify のパスワードリセットトークン。リソース id ではない',
+            'debug.login-as#userId' => 'local 専用のデバッグログイン (route 登録自体が isLocal/runningUnitTests 限定 + LocalOnly middleware)。テナント親子でない',
+            'mcp.oauth.authorization-server.nested#path' => 'vendor (laravel/mcp) の OAuth discovery。任意の後続セグメントでリソース id ではない',
+            'mcp.oauth.protected-resource.nested#path' => 'vendor (laravel/mcp) の OAuth discovery。任意の後続セグメントでリソース id ではない',
+            'storage.local#path' => 'Laravel の local disk 配信 route。署名付き URL でファイルパスを受ける (リソース id ではない)',
+            'storage.local.upload#path' => 'Laravel の local disk アップロード route。署名付き URL でファイルパスを受ける',
+        ];
+    }
+
+    /**
+     * parameterNames >= 1 の候補 route (パッケージ内部 route は除外)。
+     *
+     * パッケージ管理ルート (Filament/Livewire/Passport/Cashier 内部) はパッケージ側が防御を
+     * 担うため対象外。アプリが定義するルートのみ検査する。
+     *
+     * @return list<RoutingRoute>
+     */
+    public static function candidates(): array
+    {
+        $candidates = [];
+        foreach (Route::getRoutes() as $route) {
+            if ($route->parameterNames() === []) {
+                continue;
+            }
+            if (str_starts_with($route->uri(), 'livewire')) {
+                continue;
+            }
+            $name = $route->getName();
+            if ($name !== null && (str_starts_with($name, 'filament.')
+                || str_starts_with($name, 'livewire.')
+                || str_starts_with($name, 'passport.')
+                || str_starts_with($name, 'cashier.'))) {
+                continue;
+            }
+
+            $candidates[] = $route;
+        }
+
+        return $candidates;
+    }
+
+    /**
+     * inventory に登録された名前を持つ現存 route (名前 => route)。
+     *
+     * @return array<string, RoutingRoute>
+     */
+    public static function registeredRoutes(): array
+    {
+        $inventory = self::inventory();
+        $routes = [];
+        foreach (Route::getRoutes() as $route) {
+            $name = $route->getName();
+            if ($name !== null && array_key_exists($name, $inventory)) {
+                $routes[$name] = $route;
+            }
+        }
+
+        return $routes;
+    }
+
+    /**
+     * テナント防御モードを 1 つ以上宣言した route (名前 => route)。
+     *
+     * @return array<string, RoutingRoute>
+     */
+    public static function tenantDefenseRoutes(): array
+    {
+        $inventory = self::inventory();
+
+        return array_filter(
+            self::registeredRoutes(),
+            static function (RoutingRoute $route) use ($inventory): bool {
+                foreach ($inventory[(string) $route->getName()] as $mode) {
+                    if ($mode->isTenantDefense()) {
+                        return true;
+                    }
+                }
+
+                return false;
+            },
+        );
+    }
+
+    /**
+     * 解決後 (priority 適用後) の middleware 列。
+     *
+     * `Class:param` 形式の parameter を落とし、alias 解決後の具象クラス名で返す。
+     * closure 要素は分類不能を示す `(closure)` に写す。
+     *
+     * @return list<string>
+     */
+    public static function resolvedMiddleware(RoutingRoute $route): array
+    {
+        /** @var Router $router */
+        $router = app('router');
+
+        return array_values(array_map(
+            static fn (mixed $middleware): string => is_string($middleware)
+                ? explode(':', $middleware, 2)[0]
+                : '(closure)',
+            $router->gatherRouteMiddleware($route),
+        ));
+    }
+}
diff --git a/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php b/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php
new file mode 100644
index 0000000..850ce83
--- /dev/null
+++ b/tests/Unit/Support/TrustedProxiesConfigValidatorTest.php
@@ -0,0 +1,78 @@
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
+test('検査1: * / ** は全アドレス信頼として reject', function (string $wildcard): void {
+    assertProxyValidationFails([], [$wildcard], 'Trusting every address');
+})->with(['*', '**']);
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
index 0000000..05fabdc
--- /dev/null
+++ b/tests/Unit/Support/TrustedProxyTokenTest.php
@@ -0,0 +1,54 @@
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
+    '0.0.0.0/0',
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

```

## テスト結果

- composer test: **2976 passed / 0 failed / 2 skipped** (2978 tests, 11745 assertions)
  - main 時点は 2865 passed / 0 failed / 2 skipped (= 113 テスト増)
- composer phpstan: **No errors** (level 10)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck / test (1130) / build / typecheck:packages / test:packages: 全 green

## 追加で確認済みの事実 (レビューの参考)

- **S4 の red 検証**: S1 を revert すると ProjectRouteCurrentOrgGuardTest +
  TenantBoundaryOrderingTest が 3 件 fail、S2 を revert すると TenantBoundaryOrderingTest が
  2 件 fail、S3-b を revert すると検査 3a が fail、S6 を revert すると TrustedProxiesTest が
  fail することを実際に確認済み
- **priority list 変更の影響範囲**: 全 205 route の解決後 middleware 列を before/after で
  比較し、変化したのは {project} を持つ 44 route のみ、変化内容は
  EnsureProjectBelongsToRouteOrganization が 8 個後ろから SubstituteBindings 直後へ
  移動しただけ (middleware の集合自体は不変) であることを検証済み

