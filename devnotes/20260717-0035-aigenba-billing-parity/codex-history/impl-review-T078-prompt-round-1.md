# AI-CUE 実装レビュー依頼: T078 (決済 parity P7 = 新規登録経路 `?plan=` handoff + verify ソフトゲート継続)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件 (アプリ都合で緩めない。すべて Architecture テストで強制)

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない(`ProhibitsProtectedKeys` + `MassAssignmentSafetyTest`)
2. **子は親に属する**: nested route の不整合は**認可より前に 404**(`NestedRouteIdorDefenseTest` の inventory に登録必須)
3. **cross-org 不可**: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**(strict_check=true)
6. **PII(email/name)は CipherSweet**。検索は `whereBlind()`(平文 where は hit しない)
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. **外部 URL 取得は SSRF 検査経由**: `Kent013\SsrfPin\UrlSafetyInspector` / `PinnedHttpClient` を通す

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# あなたの役割 (system 部)

あなたは AI-CUE の実装レビュアーである。以下の差分 (T078 = 決済 parity フェーズ P7) が**添付の詳細設計 P7 節どおりに実装されているか**を検証し、逸脱・欠陥・後退リスクを指摘せよ。

この詳細設計は Codex 合議 16 ラウンドで APPROVED 済みであり、**設計が正本**である。設計と差分が食い違う箇所は、原則として差分側の誤りとして指摘せよ (設計自体の欠陥を発見した場合はその旨を明示して指摘してよい)。

## レビュー観点

1. **設計適合**: P7 節の「変更箇所」表・「主要な契約」・「テスト計画」の各項目が実装されているか。移植を指示された分岐 (Enterprise 除外・pending 規約 2 種・org-scoped no-op 規約・招待経由の排他契約) が verbatim で入っているか。**非移植と明示された要素 (`?choose` / `preselectFunding` / signup grant 復活 / `starter_migration_acknowledged` / `PLAN_LABELS`) を先取りしていないか**。
2. **禁止事項への抵触**: 特に **#7 (`redirect()->intended()` を操作系 POST の応答で使っていないか)**、#2 (PHPStan の widen/baseline/`@phpstan-ignore`)、#4 (`response()->json()` 直書き)、#1 (テストなし)。
3. **セキュリティ**: `?plan=` はユーザー入力である。allowlist (`PlanCode`) 照合を通らない値が session / prop / redirect に到達する経路が無いか。`OnboardingReturnResolver::normalizePath` の open-redirect 防御に**すり抜けるペイロードが無いか** (具体例を挙げて反証せよ)。`EmailVerificationContinuation` の membership 確認が cross-org (不変条件 #3) と IDOR を実際に塞いでいるか。session fixation / regenerate との関係。
4. **PHPStan level 10 適合**: mixed の narrowing、`parse_url` の戻り値、`?->` での握り潰し、具象戻り型。
5. **テストが不変条件を実際に固定しているか (空振りしていないか)**: 「assert が常に真になる」「前提が偶然成立している」「negative case が実は positive case になっている」ようなテストを名指しで指摘せよ。特に `createOrganizationWithOwner(grandfatherFreePlan: false)` の使い分け、session assert の対象キー、Inertia assert の prop 名。
6. **副作用・後退リスク**: `VerifyEmailResponseContract` の singleton bind 追加による既存 verify フローの後退、`RegisterResponse` への `Assert::isInstanceOf` 追加による 500 リスク、singleton が `Session` を間接保持する寿命問題、SSO の各分岐 (linked user / login / register 拒否 / step-up / link) での pending 残留、`RequireActiveSubscription` が return_to を積む条件、`BillingController::index` の `continueUrl` 誤発火。

## 出力形式

指摘は重大度別に分類し、**必ず「該当ファイル:行 (または関数名)」「なぜ問題か」「具体的な失敗シナリオ (入力 → 結果)」「推奨修正」**を書け。

- `[Critical]` — マージ前に必ず直すべき (設計違反 / セキュリティ / データ破壊 / 明確なバグ)
- `[Warning]` — 直すべきだがブロッカーではない
- `[Suggestion]` — 改善提案

指摘が無い観点は「問題なし」と明示せよ。**憶測で「たぶん壊れている」と書かず、差分中の根拠行を必ず引用せよ**。

## 補足コンテキスト (レビュー時の前提)

- 前提フェーズ P1-P6 はマージ済み。`BillingAccess::state()` は 5 状態を返し、`hasActiveAccess()` は `state()->grantsAccess()` 一本。未契約組織は onboarding へ遮断される。
- テスト helper `createOrganizationWithOwner(name, grandfatherFreePlan: true)` の既定は「無料枠 backfill 相当 (= アクセス可)」。**未契約を検証したいテストは `grandfatherFreePlan: false` を明示する必要がある**。
- 検証コマンド実測: `composer phpstan` = level 10 で [OK] no errors / `vendor/bin/pint --test` = pass / `pnpm lint` `pnpm typecheck` `pnpm test` `pnpm build` = pass。`composer test` は 2242 tests / 2237 passed / 3 errors。**3 errors は `tests/Feature/Billing/SeededFreePlanBillingAccessTest.php` の既存赤** (T078 の base コミットでも同一に再現。原因は P4/T075 が `PlanSeeder` から `plans.code='free'` 行を撤去したのに当該テストの helper が `where('code','free')->firstOrFail()` を残していること。T078 の差分は seeder / Plan / 当該テストのいずれも触っていない)。この既存赤が T078 起因かどうかも、差分から判断できる範囲で意見を述べよ。

---

# user 部: レビュー対象

## (A) 詳細設計 P7 節 (正本)

### P7 新規登録経路（`?plan=` handoff + verify ソフトゲート継続）

料金表 → `/register?plan={code}` → 登録 → `verification.notice` → `onboarding.checkout` の「プラン意図」を aigenba と同一構造（2 キー session + 書き込み規約 2 種）で一貫保持する。前提: P1（`App\Enums\PlanCode` **5 case**）/ P3（`onboarding.{checkout,activate-personal,billing-required}` = **route parameter なし**の current-org スコープ）/ P4（ゲート反転・`RequireActiveSubscription` verbatim 化）/ P6（`CreateNewUser` からの signup grant 撤去）がマージ済み。

#### 変更箇所

**新規（aigenba verbatim 移植）**

| AI-CUE（新規） | 移植元 aigenba | 内容 |
|---|---|---|
| `app/Services/Onboarding/IntendedPlanResolver.php` | `app/Services/Onboarding/IntendedPlanResolver.php` | `PENDING_KEY='onboarding.intended_plan.pending'` / `orgKey()="onboarding.intended_plan.org.{$organization->id}"` / `normalizeRaw()`（`is_string` → `strtolower(trim())` → `PlanCode::tryFrom` → **`$code === PlanCode::Enterprise` なら null**。**Enterprise 除外分岐も verbatim 移植する**）/ pending 系 = **常に書き換え**（key 不在・null・空文字・改ざん → `forgetPending`）/ org-scoped 系 = **不在は no-op**（リロード耐性）/ `promotePendingToOrganization()`（pending は必ず forget で消費）。**docblock 込みで verbatim** |
| `app/Services/Onboarding/OnboardingReturnResolver.php` | 同名 | `orgKey()="onboarding.return_to.org.{$organization->id}"` / `normalizePath()` の多段 open-redirect 防御（制御文字・raw + `rawurldecode` の二重判定・scheme / protocol-relative・バックスラッシュ・`parse_url` の scheme/host/user/pass/port・先頭 `/` 必須・query 保持 / fragment drop）/ put は不正値 no-op / peek は再正規化。**verbatim** |
| `app/Support/Auth/EmailVerificationContinuation.php` | 同名 | session キー `verify_continue_organization_id` に **組織 ID のみ**保持。`resolveUrl()` は `is_int()` → `$user->organizations()->whereKey($organizationId)->first()` の membership 確認を通してから route を再構築（URL 直保持しない = ルート変更・値汚染・IDOR 耐性）。**AI-CUE では引数なしの `route('onboarding.checkout')` を生成**（D21: route parameter なし）。寿命 = `remember`（登録時）→ `forget`（verify 完了時）。※ AI-CUE に `app/Support/Auth/` は無いため新設 |
| `app/Http/Responses/Fortify/VerifyEmailResponse.php` | `app/Responses/Fortify/VerifyEmailResponse.php` | continuation の **forget 側ライフサイクル**。`resolveUrl` → `forget` → `continueUrl !== null` なら `redirect()->to($continueUrl)`、null なら **Fortify 既定と同値**（`redirect()->intended(config('fortify.home').'?verified=1')`。`fortify.home = '/dashboard'`）。aigenba の flash 再設計（`VerifyEmailController::ATTR_ALREADY_VERIFIED` / `auth.verify_*`）は **AI-CUE に `VerifyEmailController` が存在しない**ため移植しない（原則 4） |
| `resources/js/types/Auth.ts` | `resources/js/types/Auth.ts:1,9-15` | `export type PlanCode = 'personal' \| 'starter' \| 'standard' \| 'business' \| 'enterprise';`（**PHP の `PlanCode` 5 case と exact 対**）+ `RegisterPageProps { intendedPlan: PlanCode \| null; socialProviders: string[]; invitationEmail: string \| null }`。aigenba の `consentVersion` は AI-CUE の SSO 同意が query `terms_accepted=1` 方式のため含めない。**`PLAN_LABELS` は移植しない**（プラン表示名の真実源は `PlanSeeder.name` = サーバ確定。フロントに二重台帳を作らない = P3 で確立済みの規約） |

**改修**

- `app/Actions/Fortify/CreateNewUser.php`: `IntendedPlanResolver` を DI し、`->validate()` 通過後・`DB::transaction` 前に `rememberPendingFromForm($input)` を 1 行呼ぶ（移植元 `CreateNewUser.php:85-90`）。**`intended_plan` は validation rules に足さない**（aigenba の明示規約: 無効値でも登録は通す / 422 で止めない）。既存の招待 token 解決・`MatchesInvitationEmail`・`UniqueEncryptedEmail` には触らない。**signup grant は P6 で撤去済み。P7 で復活させない**（付与契機は P1 `PersonalPlanService::activate()` / P6 paid webhook の管轄）。aigenba の `starter_migration_acknowledged`（`CreateNewUser.php:76`）は **AI-CUE の Starter に「30 日後 Standard 自動移行」が存在しない**ため移植しない（原則 4）。
- `app/Http/Responses/Fortify/RegisterResponse.php`: 移植元 `app/Responses/Fortify/RegisterResponse.php:37-72`。ただし **AI-CUE は個人組織生成が `CreateNewUser` の tx 内で完結済み**のため `provisionPersonalOrganization` 呼び出しは持ち込まず、**分岐だけ**を移植する。
  - **招待経由分岐**（aigenba `InvitationContinuation::pull` 相当）: AI-CUE は招待受諾を `CreateNewUser::create()` の tx 内（`membership->acceptInvitationIfValid()`）で行い成立時は個人組織を作らないため、判定は `$user->organizations()->where('is_personal', true)->first()`。**null（= 招待組織へ参加）なら `forgetPending()` して現行どおり `verification.notice` へ**（continuation を張らない）。
  - 通常分岐: `promotePendingToOrganization($personalOrg)` → `EmailVerificationContinuation::remember($request->session(), $personalOrg->id)` → `verification.notice`。既存の `wantsJson() → 201` 後方互換は**維持**し、session 副作用を先に実行してから返す。
- `app/Providers/FortifyServiceProvider.php`
  - `configureViews()` の `registerView`（:182-203）: 既存 `socialProviders` / `invitationEmail` / `Cache-Control: no-store` を保ったまま `'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value` を追加（移植元 :141-157）。正規化は resolver 一本化（Provider 側で分岐を書かない）。
  - `verifyEmailView`（:219）: `Inertia::render('Auth/VerifyEmail', ['continueUrl' => EmailVerificationContinuation::resolveUrl($user instanceof User ? $user : null, $request->session())])`（移植元 :173-184）。`status` は AI-CUE の `VerifyEmail.svelte` が持たないため追加しない。
  - `register()`: `$this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class)` を追加（既存 :83 の `RegisterResponse` と同型）。
- `resources/js/pages/Auth/Register.svelte`: `intendedPlan?: PlanCode | null` prop を受け、`useForm` に `intended_plan: intendedPlan` を含めて**常に送信**（null も送る = resolver の `array_key_exists` 規約で stale pending を消す。移植元 :110-111）。`ssoHref`（:61-64）に `&plan={intendedPlan}` を伝播（intendedPlan が null なら付けない）。
- `resources/js/pages/Auth/VerifyEmail.svelte`: `continueUrl?: string | null` を受け、**非 null のときのみ**二次 CTA「あとで認証する（プラン選択へ進む）」= `router.visit(continueUrl)` を出す（移植元 :45-49、`testId="verify-email-continue"`）。既存の再送信・ログアウトは不変。
- `resources/js/pages/Pricing.svelte:124`: `<Button href="/register" fullWidth>このプランで始める</Button>` → `` href={`/register?plan=${encodeURIComponent(plan.code)}`} ``（移植元 `Guest/Pricing.svelte:164,189`）。`page.isAuthenticated` 分岐（`/billing`）はそのまま。nav の `/register`（:82）は plan なし = pending forget（fresh state）で aigenba 規約どおりのため変更しない。
- **D16: `resources/js/pages/Welcome.svelte` の `/register` 直リンク 3 箇所を `/pricing` へ**（P7 所管。P8b ではない）
  - `:137` guest nav「無料で始める」/ `:160` hero `testId="hero-register"`「無料で始める」/ `:358` `landing-pricing-cta` 内「無料で始める」の `href` を **`/pricing`** にし、`inertia` 属性を付ける（既存 :360 の `/pricing` Button と同じ SPA 遷移作法）。**文言（「無料で始める」）は変更しない**（Personal(free) が実在するため事実。P6 の文言変更と非衝突）。
- **P3 / P4 導線への結線**（クラスを置くだけでは handoff が閉じないため P7 で結線する。移植元の呼び出し位置に対応）
  - `app/Http/Controllers/Onboarding/OnboardingController::show`: `$request->has('plan')` なら `rememberForOrganizationFromQuery($request, $organization)` → canonical URL（`route('onboarding.checkout')`）へ **303**。不在なら `peekForOrganization($organization)` を `pageData.intendedPlanCode` に載せて preselect（移植元 `Onboarding/OnboardingController.php:68-81`）。`?choose` / `preselectFunding` は funding 2 択が AI-CUE に無い（P8a 非移植）ため持ち込まない。
  - `app/Http/Controllers/Onboarding/ActivatePersonalController::__invoke`: 成功後に `$continue = $returnResolver->peekForOrganization($organization); $returnResolver->forgetForOrganization($organization);` → `redirect()->to($continue ?? route('dashboard'))`（移植元 `Onboarding/ActivatePersonalController.php:63-65`。P3 の `dashboard` 固定を差し替え）。
  - `app/Http/Middleware/RequireActiveSubscription.php`（P4 で verbatim 化済み）: 遮断時、**`$canManage && GET/HEAD && ! $request->expectsJson()`** のときだけ `returnResolver->rememberForOrganization($org, '/'.ltrim($request->path(), '/'))`（移植元 `Http/Middleware/RequireActiveSubscription.php:74-81`。既存の `reflash()` は維持）。
  - `app/Http/Controllers/Billing/BillingController::checkout`（:85-95）: `$gateway->createSubscriptionCheckout()` が URL を返した直後・`Inertia::location()` の**前**に `intendedPlanResolver->forgetForOrganization($organization)`（= 契約開始で意図を消費。移植元 `Billing/BillingController.php:605-608`）。`back()->with('error', …)`（price 不在）経路では **forget しない**（意図を維持して再試行できる = aigenba の in-progress 分岐と同方針）。
  - `app/Http/Controllers/Billing/BillingController::index`: `state($organization)->grantsAccess()` **かつ** `returnResolver->peekForOrganization($organization) !== null` のときだけ `forgetForOrganization()` して `'continueUrl'` prop を載せる（1 回限り = リロードで CTA が残らない）。`Billing/Index.svelte` は非 null のとき「元の画面に戻る」CTA を出す。aigenba `resolveOnboardingContinue`（`Billing/BillingController.php:285-297`）の `?session_id` + `CheckoutSessionStatus::Completed` 判定を、**P2 で移植済みの `BillingAccess::state()->grantsAccess()`（`?session_id` 非依存・同一意味論「契約成立着地でのみ提示」）**に写したもの。`?session_id` 依存の feedback は P9 所管で、P7 は触らない。
- **SSO 経路**（移植元 `Auth/SsoController.php:113,149,363`。AI-CUE は POST ではなく GET のため `rememberPendingFromQuery` を使う = aigenba に実在する同族 API で、新規メソッドは発明しない）
  - `app/Http/Controllers/Auth/SocialAuthController::redirect`（:43 の register 分岐直後）: `$intent === 'register'` のとき `rememberPendingFromQuery($request)` / `$intent === 'login'` のとき `forgetPending()`。`link` / `step-up` は触らない。
  - 同 `callback`（:117 `$service->register()` 直後・`redirect()->route('dashboard')` の前）: 個人組織（`$user->organizations()->where('is_personal', true)->first()`）を解決し `promotePendingToOrganization($personalOrg)`。**redirect 先（`dashboard`）は P7 では変えない**。register 拒否分岐（:103,:112 の `withErrors`）では `forgetPending()`（stale を残さない）。

#### 波及変更

- **TypeScript 型定義**
  - `resources/js/types/Auth.ts`（**新規**）: `PlanCode`（**5 case**）/ `RegisterPageProps`。
  - `resources/js/types/onboarding.ts`（P3 産出）: `OnboardingCheckoutShape` に **additive** で `intendedPlanCode: string | null`。
  - `resources/js/types/billing.ts`: `Billing/Index` の props に **additive** で `continueUrl: string | null`。`PurchaseTicketsPageDto` の `ticketAttemptToken` には**一切触らない**（subscription 用 `subscriptionAttemptToken` は P9 の別型）。
  - `VerifyEmail.svelte` の Props に `continueUrl?: string | null`（ページ内 interface。既存が inline 定義のため d.ts 追加不要）。
  - `resources/js/types/marketing.ts` は **変更なし**（`PricingPlanShape.code` を既に持つ）。
- **DTO / JsonResource**: 新規なし。`OnboardingCheckoutDto`（P3）に `intendedPlanCode: ?string` を additive 追加（`@phpstan-type OnboardingCheckoutShape` も同時更新）。`PricingPageDto` / `LandingPageDto` は無改変。`response()->json()` 直書きなし。
- **Inertia props（追加分のみ）**: `Auth/Register` = `+ intendedPlan: string|null` / `Auth/VerifyEmail` = `+ continueUrl: string|null` / `Onboarding/Checkout` = `+ pageData.intendedPlanCode` / `Billing/Index` = `+ continueUrl: string|null`。
- **DI / bind**: `FortifyServiceProvider::register()` に `VerifyEmailResponseContract` singleton。`CreateNewUser` / `RegisterResponse` / `SocialAuthController` / `OnboardingController` / `ActivatePersonalController` / `BillingController` / `RequireActiveSubscription` へ resolver を constructor 注入（自動解決。binding 追加なし）。
- **DB / migration / route**: **なし**（session キーと prop のみ。route は P3 が定義済み）。
- **テストファイル（新規）**: `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php` / `tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php` / `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php` / `tests/Feature/Auth/RegisterPlanHandoffTest.php` / `tests/Feature/Auth/RegisterVerifyFlowTest.php` / `tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php`（移植元: aigenba `tests/Unit/Services/Onboarding/{IntendedPlanResolverTest,OnboardingReturnResolverTest}.php` / `tests/Feature/Auth/{RegisterPlanHandoffTest,RegisterVerifyFlowTest}.php` / `tests/Feature/Onboarding/{OnboardingCheckoutPlanHandoffTest,RegisterRedirectsToCheckoutTest}.php`）。
- **テストファイル（更新・削除しない）**: `tests/Feature/Auth/RegistrationTest.php` / `RegistrationInvitationPrefillTest.php` / `FortifyResponseTest.php` / `EmailVerificationGateTest.php` / `SocialAuthTest.php` / `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/{Welcome,Pricing}.test.ts` / `tests/js/pages/OnboardingCheckout.test.ts`。

#### 主要な契約

```php
final class IntendedPlanResolver {
    public const PENDING_KEY = 'onboarding.intended_plan.pending';
    public function __construct(private readonly Session $session) {}
    public static function orgKey(Organization $organization): string;   // onboarding.intended_plan.org.{id}
    public static function normalizeRaw(mixed $value): ?PlanCode;        // 非string/無効/Enterprise → null（verbatim）
    public function rememberPendingFromQuery(Request $request): void;    // 'plan' 不在 → forget
    public function rememberPendingFromForm(array $input): void;         // 'intended_plan' key 不在 → forget
    public function peekPending(): ?PlanCode;  public function forgetPending(): void;
    public function rememberForOrganizationFromQuery(Request $r, Organization $o): void; // 不在 → no-op
    public function peekForOrganization(Organization $o): ?PlanCode;
    public function forgetForOrganization(Organization $o): void;
    public function promotePendingToOrganization(Organization $o): void; // pending は必ず forget で消費
}
final class OnboardingReturnResolver {
    public function __construct(private readonly Session $session) {}
    public static function orgKey(Organization $o): string;              // onboarding.return_to.org.{id}
    public static function normalizePath(mixed $value): ?string;         // same-origin 内部 path のみ（query 保持 / fragment drop）
    public function rememberForOrganization(Organization $o, string $path): void; // 不正値 no-op
    public function peekForOrganization(Organization $o): ?string;
    public function forgetForOrganization(Organization $o): void;
}
final class EmailVerificationContinuation {
    private const string SESSION_KEY = 'verify_continue_organization_id';
    public static function remember(Session $s, int $organizationId): void;
    public static function resolveUrl(?User $u, Session $s): ?string;    // membership 確認 → route('onboarding.checkout')（引数なし）
    public static function forget(Session $s): void;
}
```

- `RegisterResponse::toResponse($request): JsonResponse|RedirectResponse`（既存シグネチャ維持）。DI に `IntendedPlanResolver` を追加。
- `VerifyEmailResponse::toResponse($request): RedirectResponse` / `VerifyEmailResponseContract` に singleton bind。
- **`PlanCode` は verbatim 5 case**（`Personal` / `Starter` / `Standard` / `Business` / `Enterprise`。P1 産出）。`normalizeRaw` は `tryFrom` に加えて **`$code === PlanCode::Enterprise` → null** を持つ（Enterprise はセルフサーブ契約フローに乗らない = お問い合わせ営業導線）。TS 側は `export type PlanCode = 'personal' | 'starter' | 'standard' | 'business' | 'enterprise';`。
- URL 契約: **`/register?plan={PlanCode::value}`**。未知値・改ざん・配列は `normalizeRaw` が null 化 → `intendedPlan` prop は null。`?plan=enterprise` は **有効な enum 値だが normalizeRaw が明示的に除外**して null（未知値扱いではない）。
- session キー（真実源。DB 変更・route 追加なし）: `onboarding.intended_plan.pending` / `onboarding.intended_plan.org.{id}` / `onboarding.return_to.org.{id}` / `verify_continue_organization_id`。
- 依存 route（P3 が定義。**引数なし・current-org スコープ**）: `onboarding.checkout` / `onboarding.activate-personal` / `onboarding.billing-required`。continuation は **組織 ID を session 保持**し、参照時に membership を確認してから引数なしの `route('onboarding.checkout')` を生成する（URL を session に直保持しない）。
- **招待経由との排他契約**: 招待受諾成立（= `is_personal` の個人組織が存在しない）→ `forgetPending()` / continuation を張らない / `verification.notice` へ（現行どおり）。招待成立時は個人組織が無いため `promotePendingToOrganization` の対象自体が存在しない。

#### PHPStan 適合チェック（level 10）

- `normalizeRaw(mixed): ?PlanCode` — `is_string()` で mixed を絞ってから `PlanCode::tryFrom(strtolower(trim($value)))`。**`$code === PlanCode::Enterprise` は 5 case のうちの 1 case との比較であり `identical.alwaysFalse` は発生しない**（v1 の 3 case 縮小は撤回済み）。`baseline` / 型 widen での回避は行わない（禁止事項 #2）。
- `session->get()` の戻り値 mixed は `is_string($raw) ? self::normalizeRaw($raw) : null`（aigenba と同形）で narrowing。`normalizePath(mixed)` も同じく `is_string()` ガード先頭。
- `EmailVerificationContinuation::resolveUrl` — `is_int($organizationId)` で mixed session 値を絞り、`?User` の null 分岐を明示。`$user->organizations()` は `BelongsToMany<Organization, User>` generics が `User` モデル側に既存（`OrganizationProvisioningService` が同型で level 10 通過済み）。
- `RegisterResponse` — `$request->user()` は mixed のため `Assert::isInstanceOf($user, User::class)`（Webmozart は `CreateNewUser` で既に使用）で narrow。`->where('is_personal', true)->first()` は `?Organization` として解決し、null 分岐（招待経由）を明示する（`?->` で握り潰さない）。
- `verifyEmailView` クロージャ — `$request->user()` を `$user instanceof User ? $user : null` で絞ってから渡す（移植元 :180 と同形）。`registerView` は既存の `SymfonyResponse` 戻り型（`Cache-Control` 操作のため `->toResponse($request)` 済み）を維持する。
- `OnboardingReturnResolver::normalizePath` — `parse_url()` は `array<string,int|string>|false` のため `=== false` を先に弾き、`$parsed['path'] ?? '/'` を `string` として扱う（`preg_match(...) === 1` で int 戻り値を明示比較）。
- `Session` を constructor 注入する resolver を singleton の `RegisterResponse` が保持する点: `session.store` の Store は per-request に `setId/start` で再初期化される同一インスタンスのため安全（aigenba も singleton bind）。
- 戻り値は全て具象型（`RedirectResponse` / `JsonResponse|RedirectResponse` / `?string` / `?PlanCode`）。`response()->json()` 直書きなし。

#### テスト計画

**先に red を作る（新規）**

1. `tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php` — pending 規約（key 不在 → forget / 有効（personal・starter・standard・business）→ put / **`enterprise` → forget**（verbatim の除外）/ 無効文字列・配列・null・空文字・前後空白 + 大文字（`' Starter '` → `starter`）→ 規約どおり）、org-scoped 規約（不在 → **no-op = 既存値が残る** / 無効 → forget）、`promotePendingToOrganization`（pending は必ず消費 / pending 無しなら org key を触らない）、`orgKey` 形状。
2. `tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php` — open-redirect データセット（`https://evil`, `//evil`, `/\evil`, `%2F%2Fevil`, `javascript:...`, `user:pass@host`, `:8080` 付き, `%0d%0a` 混入, 相対 `foo`）の reject と `/path?a=1#frag` → `/path?a=1`（query 保持 / fragment drop）。put の不正値 no-op（既存 return_to を壊さない）、peek の再正規化（session 改ざん値 → null）。
3. `tests/Unit/Support/Auth/EmailVerificationContinuationTest.php` — remember → `resolveUrl` が `route('onboarding.checkout')`（**引数なし**）、**他組織 id を session に注入しても null**（membership 確認 = IDOR 防御。不変条件 #2）、非 int / null user → null、forget 後は null。
4. `tests/Feature/Auth/RegisterPlanHandoffTest.php` — `POST /register`（`intended_plan=starter`）で pending が forget され org key に `starter` が promote される / **`enterprise`**・`foo`・key 欠落は promote されない（org key 不在）。Factory + `whereBlind('email','email_index',…)` で移植。
5. `tests/Feature/Auth/RegisterVerifyFlowTest.php` — 登録 → `verification.notice` の `continueUrl` prop が非 null（Inertia assert）→ verify 完了で continuation が forget され `onboarding.checkout` へ着地 / **continuation 無しは `'/dashboard?verified=1'` 着地**（Fortify 既定と同値 = 非退行）。
6. `tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php` — 登録 → `GET /onboarding/checkout?plan=standard` が canonical URL へ **303** → 再 GET で `pageData.intendedPlanCode === 'standard'` / **plan なしリロードで preselect が消えない**（org-scoped no-op 規約）/ `?plan=enterprise` は preselect されない。
7. `GET /register?plan=personal|starter|standard|business|enterprise|<不正>` の `intendedPlan` prop（Inertia assert。enterprise・不正値は null）。招待経由（`invitationEmail` あり）の `Cache-Control: no-store` 非退行を同ファイルで維持。
8. **招待競合**（最重要）: 招待 token 保持 + `?plan=starter` で登録 → 招待組織へ参加 / **個人組織を作らない** / **pending は forget**（org key が一切作られない）/ continuation を張らない（`verification.notice` に `continueUrl === null`）/ 既存の招待受諾着地が不変。
9. **return_to の往復**: gate（`RequireActiveSubscription`）が manage-billing 保持者の GET を遮断 → return_to に元 path が積まれる / **POST・XHR（`expectsJson`）・非 manage-billing では積まれない** / `POST /onboarding/activate-personal` 成功で元 path へ復帰し return_to は消費される（2 回目は `dashboard`）/ 有料経路は `billing.index` の `continueUrl` prop（`grantsAccess()` 成立時のみ・1 回限り）。

**既存の更新（削除しない）**

- `tests/Feature/Auth/RegistrationTest.php` — **signup grant の期待は「登録時は未付与」のまま維持**（P6 で `CreateNewUser` から撤去済み。**登録時付与の期待を復活させない**）。`verification.notice` / `current_organization_id` の期待は維持し、**session キー（`onboarding.intended_plan.org.{id}` / `verify_continue_organization_id`）の期待を追加**する。
- `tests/Feature/Auth/RegistrationInvitationPrefillTest.php` — 招待経由で pending が消費されない / continuation が張られないことを追加（既存の prefill・非付与の期待は維持）。
- `tests/Feature/Auth/FortifyResponseTest.php` — `VerifyEmailResponseContract` bind 追加後の verify 着地。
- `tests/Feature/Auth/EmailVerificationGateTest.php` — continuation 無し時に既定着地が変わらない非退行（`assertRedirect` 群は不変）。
- `tests/Feature/Auth/SocialAuthTest.php` — SSO register の `?plan=` → pending → 個人組織へ promote / `intent=login` は forget / register 拒否分岐は forget。
- `tests/Feature/Marketing/PricingPageTest.php` / `tests/js/pages/Pricing.test.ts:101` — CTA href 期待を `/register?plan={code}` へ更新。
- `tests/js/pages/Welcome.test.ts`（**D16**） — `hero-register` / nav「無料で始める」/ `landing-pricing-cta` 内「無料で始める」の **`href` が `/pricing`** であること（3 箇所とも href を明示 assert し、`/register` 直リンクが LP に 1 本も無いことを固定）。既存の文言 assert（L43 の signup grant 文言 = P6 で更新済み）・モバイルパネル・法的リンク順序の期待は不変。
- `tests/js/pages/OnboardingCheckout.test.ts` — `intendedPlanCode` があれば preselect / null なら `defaultPlanCode`（無ければ先頭）という P3 の決定的挙動を維持。
- UI: `Register.svelte` / `VerifyEmail.svelte` は既存 `AuthLayout` 配下で primitive 構成を変えず、**新規 hex・新規 lucide import を入れない**ため page-shell-structure / ds-purity / atomic-import-graph / lucide-scoped-import は allowlist 追加なしで green（**disabled でブロックしない**規約も不変。禁止事項 #8）。

#### リスク

| リスク | 緩和 |
|---|---|
| **stale pending の誤 promote**（中断した OAuth 等で残った pending が次の plan 無し登録に promote される） | pending 規約「常に書き換え（key 不在は forget）」を `CreateNewUser` / `SocialAuthController::redirect` の**両入口**で守る。`Register.svelte` が `intended_plan: null` を**必ず送る**ことが前提のため、送信漏れをテスト 4 で検知 |
| **招待経由との競合**（最重要）。招待受諾が `CreateNewUser` tx 内で完結する AI-CUE では `RegisterResponse` が「個人組織の有無」で分岐するため、招待受諾が将来 personal org も作るよう変わると誤って continuation を張る | テスト 8 を回帰網に固定（pending forget / continuation 非設置 / 個人組織非生成の 3 点を同時に assert） |
| **open-redirect** | `OnboardingReturnResolver` を verbatim 移植し独自簡略化しない（peek 側の再正規化を落とすと session 汚染で外部遷移し得る）。テスト 2 のデータセットで固定 |
| **Enterprise の扱いを取り違える**（v1 で `PlanCode` を 3 case に縮小し `normalizeRaw` の除外分岐を削除した = **バグ発生源**） | **5 case + Enterprise 除外分岐を verbatim**。TS も 5 case。テスト 1・4・6・7 で「enterprise は enum として有効だが intent としては採用されない」を明示的に固定 |
| **P3 / P4 未マージでの前倒し** | `EmailVerificationContinuation::resolveUrl` が未定義 route を引くと `RouteNotFoundException` で verify 画面が 500。P3・P4 マージ済みを DoD にし、route 名（`onboarding.checkout`・引数なし）を P3 の実装と一致させる |
| **verify 着地の後退** | `VerifyEmailResponseContract` bind 追加は既存 verify 完了フローを置換する。continuation 無し時に Fortify 既定（`fortify.home` + `?verified=1`）と**同値**であることをテスト 5 と `EmailVerificationGateTest` で固定 |
| **`billing.index` の `continueUrl` が誤発火**（契約前・非該当 org で復帰 CTA が出る） | 条件を `state()->grantsAccess()`（P2 verbatim）に限定し、peek 成功時に必ず forget（1 回限り）。`?session_id` 依存の feedback は P9 所管で二重化しない |
| **PII キャッシュ** | `registerView` に prop を足しても `Cache-Control: no-store` の条件（`invitationEmail !== null && !== ''`）を変えない。`?plan=` は PII でないためキャッシュ抑止対象にしない |
| **rollback** | 本フェーズは additive（session キー + prop + CTA href）。コード revert のみで復帰可（DB 変更・migration・route 追加なし）。残留 session キーは旧コードが無視する |


---

## (B) 差分 (`git diff main...HEAD`, T078 ブランチ)

```diff
diff --git a/app/Actions/Fortify/CreateNewUser.php b/app/Actions/Fortify/CreateNewUser.php
index f82c4a5..f36a30b 100644
--- a/app/Actions/Fortify/CreateNewUser.php
+++ b/app/Actions/Fortify/CreateNewUser.php
@@ -9,6 +9,7 @@
 use App\Rules\UniqueEncryptedEmail;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
 use App\Services\Organization\OrganizationProvisioningService;
 use Illuminate\Database\UniqueConstraintViolationException;
@@ -33,6 +34,10 @@
  *   解決し、招待 email との一致を MatchesInvitationEmail rule で検証する。受諾可能なら本
  *   transaction 内で招待組織へ参加し、個人組織の自動生成はスキップする (招待組織を主所属に
  *   する)。受諾不能 (失効/取消/受諾済/不一致/既メンバー) なら個人組織生成へ fallback する。
+ * - 料金表由来のプラン意図 (`intended_plan`) は validation rules に足さない (無効値でも登録は
+ *   通す = 422 で止めない)。値は IntendedPlanResolver が PlanCode allowlist に照合し、
+ *   不在 / 無効 / 改ざんはすべて pending forget に倒す (stale pending の誤 promote 防止)。
+ *   pending → org-scoped への移送は RegisterResponse が行う。
  */
 class CreateNewUser implements CreatesNewUsers
 {
@@ -41,6 +46,7 @@ public function __construct(
         private readonly OrganizationMembershipService $membership,
         private readonly TicketLedgerService $tickets,
         private readonly PersonalPlanService $personalPlan,
+        private readonly IntendedPlanResolver $intendedPlanResolver,
     ) {}
 
     /**
@@ -70,6 +76,10 @@ public function create(array $input): User
             'terms_accepted.accepted' => '利用規約への同意が必要です。',
         ])->validate();
 
+        // 料金表 → /register?plan= のプラン意図を pending に書き込む (常に書き換える規約)。
+        // validate 通過後・tx 前に 1 回だけ呼ぶ (422 で止めた入力の意図は保持しない)。
+        $this->intendedPlanResolver->rememberPendingFromForm($input);
+
         $name = $validated['name'];
         $email = $validated['email'];
         $password = $validated['password'];
diff --git a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
index ce103db..2303104 100644
--- a/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
+++ b/app/DataTransferObjects/Onboarding/OnboardingCheckoutDto.php
@@ -23,7 +23,8 @@
  *   defaultPlanCode: string,
  *   contactUrl: string,
  *   personalEligibility: PersonalPlanEligibilityShape|null,
- *   signupGrantTickets: int
+ *   signupGrantTickets: int,
+ *   intendedPlanCode: string|null
  * }
  */
 final readonly class OnboardingCheckoutDto
@@ -32,6 +33,9 @@
      * @param  list<PlanDto>  $plans  is_active=true ∧ Checkout 対象 code のみ。sort_order 昇順
      * @param  PersonalPlanEligibilityDto|null  $personalEligibility  Personal (free) の選択可否 + 不可理由
      * @param  int  $signupGrantTickets  無料開始 callout 用 (初回無償チケット枚数)
+     * @param  string|null  $intendedPlanCode  料金表 `?plan=` 由来の選択意図 (allowlist 照合済。
+     *                                         `plans` への包含は保証しない = フロントは該当 code が
+     *                                         あるときだけ preselect する)
      */
     public function __construct(
         public array $plans,
@@ -40,6 +44,7 @@ public function __construct(
         public string $contactUrl,
         public ?PersonalPlanEligibilityDto $personalEligibility = null,
         public int $signupGrantTickets = 10,
+        public ?string $intendedPlanCode = null,
     ) {}
 
     /**
@@ -57,6 +62,7 @@ public function toArray(): array
             'contactUrl' => $this->contactUrl,
             'personalEligibility' => $this->personalEligibility?->toArray(),
             'signupGrantTickets' => $this->signupGrantTickets,
+            'intendedPlanCode' => $this->intendedPlanCode,
         ];
     }
 }
diff --git a/app/Http/Controllers/Auth/SocialAuthController.php b/app/Http/Controllers/Auth/SocialAuthController.php
index 602c794..e6970c8 100644
--- a/app/Http/Controllers/Auth/SocialAuthController.php
+++ b/app/Http/Controllers/Auth/SocialAuthController.php
@@ -5,9 +5,11 @@
 namespace App\Http\Controllers\Auth;
 
 use App\Http\Controllers\Controller;
+use App\Models\Organization;
 use App\Models\User;
 use App\Security\RecentAuthState;
 use App\Services\Auth\SocialAccountService;
+use App\Services\Onboarding\IntendedPlanResolver;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Auth;
@@ -30,6 +32,10 @@ class SocialAuthController extends Controller
 {
     private const INTENTS = ['login', 'register', 'link', 'step-up'];
 
+    public function __construct(
+        private readonly IntendedPlanResolver $intendedPlanResolver,
+    ) {}
+
     public function redirect(Request $request, string $provider, string $intent): RedirectResponse|SymfonyRedirectResponse
     {
         $this->ensureProviderEnabled($provider);
@@ -45,6 +51,15 @@ public function redirect(Request $request, string $provider, string $intent): Re
                 ->withErrors(['terms_accepted' => '利用規約への同意が必要です。']);
         }
 
+        // 料金表由来のプラン意図。register 開始では ?plan= を pending に書き換え (不在は forget)、
+        // login 開始では常に forget する (前回中断の stale pending を次の登録へ持ち越さない)。
+        // link / step-up は登録経路ではないため触らない。
+        if ($intent === 'register') {
+            $this->intendedPlanResolver->rememberPendingFromQuery($request);
+        } elseif ($intent === 'login') {
+            $this->intendedPlanResolver->forgetPending();
+        }
+
         $request->session()->put('social_auth_intent', $intent);
 
         $driver = Socialite::driver($provider);
@@ -100,6 +115,8 @@ public function callback(Request $request, string $provider, SocialAccountServic
 
         if ($intent === 'login') {
             // 未連携: 自動登録はしない (明示的な register 経由を要求)
+            $this->intendedPlanResolver->forgetPending();
+
             return redirect()->route('login')->withErrors([
                 'email' => 'このアカウントは登録されていません。新規登録からやり直してください。',
             ]);
@@ -109,6 +126,9 @@ public function callback(Request $request, string $provider, SocialAccountServic
         // 同一 email の既存ユーザーがいる場合は中立メッセージで弾く。
         $email = $socialiteUser->getEmail();
         if (is_string($email) && User::whereBlind('email', 'email_index', $email)->exists()) {
+            // 登録拒否分岐: stale pending を残さない。
+            $this->intendedPlanResolver->forgetPending();
+
             return redirect()->route('register')->withErrors([
                 'email' => 'このメールアドレスではアカウントを作成できません。',
             ]);
@@ -118,6 +138,15 @@ public function callback(Request $request, string $provider, SocialAccountServic
         Auth::login($user, remember: true);
         $request->session()->regenerate();
 
+        // pending → 個人組織へ移送 (pending は必ず forget で消費される)。
+        // 個人組織が無い (= 招待経由等) 場合は promote 対象が存在しないため pending だけ落とす。
+        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
+        if ($personalOrganization instanceof Organization) {
+            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
+        } else {
+            $this->intendedPlanResolver->forgetPending();
+        }
+
         return redirect()->route('dashboard');
     }
 
diff --git a/app/Http/Controllers/Billing/BillingController.php b/app/Http/Controllers/Billing/BillingController.php
index d50008e..077fabe 100644
--- a/app/Http/Controllers/Billing/BillingController.php
+++ b/app/Http/Controllers/Billing/BillingController.php
@@ -10,9 +10,13 @@
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Billing\BillingCheckoutRequest;
 use App\Models\Billing\Plan;
+use App\Models\Organization;
 use App\Models\User;
+use App\Services\Billing\BillingAccess;
 use App\Services\Billing\SubscriptionService;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Onboarding\IntendedPlanResolver;
+use App\Services\Onboarding\OnboardingReturnResolver;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
@@ -33,6 +37,12 @@ class BillingController extends Controller
 {
     use ResolvesCurrentOrganization;
 
+    public function __construct(
+        private readonly BillingAccess $access,
+        private readonly IntendedPlanResolver $intendedPlanResolver,
+        private readonly OnboardingReturnResolver $returnResolver,
+    ) {}
+
     /** 課金ページ (現在プラン / チケット残高 / プラン一覧) */
     public function index(Request $request, TicketLedgerService $tickets): Response
     {
@@ -63,6 +73,7 @@ public function index(Request $request, TicketLedgerService $tickets): Response
             'currentPlanCode' => $organization->plan_code,
             'ticketBalance' => $tickets->balance($organization),
             'canManageBilling' => $user->can('manageBilling', $organization),
+            'continueUrl' => $this->resolveOnboardingContinue($organization),
         ]);
     }
 
@@ -99,10 +110,37 @@ public function checkout(BillingCheckoutRequest $request, SubscriptionService $s
             return back()->with('error', $e->getMessage());
         }
 
+        // 契約開始が成立したのでプラン意図を消費する (checkout URL 取得後・遷移前)。
+        // price 不在 / 開始不可の back() 経路では forget しない = 意図を維持して再試行できる。
+        $this->intendedPlanResolver->forgetForOrganization($organization);
+
         // 外部 URL への遷移は Inertia::location (full page redirect)
         return Inertia::location($redirect->url);
     }
 
+    /**
+     * 契約成立着地でのみ「元の画面に戻る」導線を出す (1 回限り = リロードで CTA が残らない)。
+     *
+     * 判定は BillingAccess::state()->grantsAccess() 一本 (subscription 直参照も
+     * `?session_id` 依存もしない)。未契約 org では peek すらせず return_to を維持する
+     * (契約前に消費すると本来の復帰先が失われる)。
+     */
+    private function resolveOnboardingContinue(Organization $organization): ?string
+    {
+        if (! $this->access->state($organization)->grantsAccess()) {
+            return null;
+        }
+
+        $continue = $this->returnResolver->peekForOrganization($organization);
+        if ($continue === null) {
+            return null;
+        }
+
+        $this->returnResolver->forgetForOrganization($organization);
+
+        return $continue;
+    }
+
     /** Stripe Customer Portal へリダイレクトする (支払い方法・解約の自己管理) */
     public function portal(Request $request, SubscriptionService $subscriptions): SymfonyResponse
     {
diff --git a/app/Http/Controllers/Onboarding/ActivatePersonalController.php b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
index 8741ba6..8b9f6b1 100644
--- a/app/Http/Controllers/Onboarding/ActivatePersonalController.php
+++ b/app/Http/Controllers/Onboarding/ActivatePersonalController.php
@@ -11,6 +11,7 @@
 use App\Models\User;
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketPricingService;
+use App\Services\Onboarding\OnboardingReturnResolver;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Support\Facades\Gate;
 use Illuminate\Validation\ValidationException;
@@ -30,6 +31,7 @@ final class ActivatePersonalController extends Controller
     public function __construct(
         private readonly PersonalPlanService $personalPlan,
         private readonly TicketPricingService $ticketPricing,
+        private readonly OnboardingReturnResolver $returnResolver,
     ) {}
 
     public function __invoke(ActivatePersonalRequest $request): RedirectResponse
@@ -54,6 +56,12 @@ public function __invoke(ActivatePersonalRequest $request): RedirectResponse
             )
             : 'パーソナルプラン（無料）を開始しました。';
 
-        return redirect()->route('dashboard')->with('success', $message);
+        // 課金ゲートで保存された「やりたかった destination」があればそこへ復帰する。
+        // 値は org-scoped session に保持した same-origin 内部 path のみ (peek で再正規化)。
+        // `redirect()->intended()` は使わない (禁止事項 #7。ログイン直後フロー専用)。
+        $continue = $this->returnResolver->peekForOrganization($organization);
+        $this->returnResolver->forgetForOrganization($organization);
+
+        return redirect()->to($continue ?? route('dashboard'))->with('success', $message);
     }
 }
diff --git a/app/Http/Controllers/Onboarding/OnboardingController.php b/app/Http/Controllers/Onboarding/OnboardingController.php
index 67b7fa1..44c7d72 100644
--- a/app/Http/Controllers/Onboarding/OnboardingController.php
+++ b/app/Http/Controllers/Onboarding/OnboardingController.php
@@ -17,6 +17,7 @@
 use App\Services\Billing\PersonalPlanService;
 use App\Services\Billing\TicketPricingService;
 use App\Services\Marketing\ContactUrl;
+use App\Services\Onboarding\IntendedPlanResolver;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
@@ -39,6 +40,7 @@ public function __construct(
         private readonly PersonalPlanService $personalPlan,
         private readonly TicketPricingService $ticketPricing,
         private readonly ContactUrl $contactUrl,
+        private readonly IntendedPlanResolver $intendedPlanResolver,
     ) {}
 
     public function show(Request $request): Response|RedirectResponse
@@ -61,6 +63,15 @@ public function show(Request $request): Response|RedirectResponse
             return new RedirectResponse(route('onboarding.billing-required'));
         }
 
+        // ?plan= が来ていたら org-scoped に積み (Resolver 規約: 有効→put / 無効→forget)、
+        // canonical URL へ 303 する (再読込・共有時に query が残らない)。
+        // 不在なら session を破壊しない (= リロード耐性のため後段で peek する)。
+        if ($request->has('plan')) {
+            $this->intendedPlanResolver->rememberForOrganizationFromQuery($request, $organization);
+
+            return new RedirectResponse(route('onboarding.checkout'), 303);
+        }
+
         $dto = new OnboardingCheckoutDto(
             plans: $this->selectablePlans(),
             recommendedPlanCode: PlanCode::Standard->value,
@@ -68,6 +79,8 @@ public function show(Request $request): Response|RedirectResponse
             contactUrl: $this->contactUrl->resolveForSource(InquirySource::Onboarding),
             personalEligibility: $this->personalPlan->eligibility($organization, $user),
             signupGrantTickets: $this->ticketPricing->signupGrantTickets(),
+            // peek = 残す (リロード耐性)。Enterprise / 未知値は正規化で null に倒れる。
+            intendedPlanCode: $this->intendedPlanResolver->peekForOrganization($organization)?->value,
         );
 
         return Inertia::render('Onboarding/Checkout', [
diff --git a/app/Http/Middleware/RequireActiveSubscription.php b/app/Http/Middleware/RequireActiveSubscription.php
index 2c3c6d0..a1dac03 100644
--- a/app/Http/Middleware/RequireActiveSubscription.php
+++ b/app/Http/Middleware/RequireActiveSubscription.php
@@ -8,6 +8,7 @@
 use App\Models\Organization;
 use App\Models\User;
 use App\Services\Billing\BillingAccess;
+use App\Services\Onboarding\OnboardingReturnResolver;
 use Closure;
 use Illuminate\Http\Request;
 use Illuminate\Support\Facades\Gate;
@@ -54,6 +55,7 @@ final class RequireActiveSubscription
 
     public function __construct(
         private readonly BillingAccess $access,
+        private readonly OnboardingReturnResolver $returnResolver,
     ) {}
 
     /**
@@ -85,13 +87,25 @@ public function handle(Request $request, Closure $next): Response
             );
         }
 
+        $canManage = Gate::forUser($user)->allows('manageBilling', $organization);
+
+        // manageBilling 保持 (= 自分で契約できる) かつ safe method (GET/HEAD) の意図遷移の
+        // ときだけ「やりたかった destination」を org-scoped session に保存する。契約完了着地で
+        // 復帰導線に使う。POST は意図遷移ではない (元 path 復元に意味がない) ため保存しない。
+        // XHR/JSON はここに到達しない (直前の 402 abort で除外済み = 判定を二重化しない)。
+        // open-redirect は normalizePath が same-origin 内部 path のみ許可して防ぐ。
+        $isSafe = in_array($request->getMethod(), ['GET', 'HEAD'], true);
+        if ($canManage && $isSafe) {
+            $this->returnResolver->rememberForOrganization($organization, '/'.ltrim($request->path(), '/'));
+        }
+
         // 直前 hop で積まれた flash (例: 招待受諾の success) が、この gate-redirect の
         // 1 hop で消費され失われないよう延命する。
         $request->session()->reflash();
 
         // 遮断理由は着地ページが持つ (middleware は error flash を積まない)。
         return redirect()->route(
-            Gate::forUser($user)->allows('manageBilling', $organization)
+            $canManage
                 ? 'onboarding.checkout'          // 自分で契約できる = プラン選択へ
                 : 'onboarding.billing-required', // 契約権限なし = 説明画面へ
         );
diff --git a/app/Http/Responses/Fortify/RegisterResponse.php b/app/Http/Responses/Fortify/RegisterResponse.php
index a9f0b67..37e8ab7 100644
--- a/app/Http/Responses/Fortify/RegisterResponse.php
+++ b/app/Http/Responses/Fortify/RegisterResponse.php
@@ -4,10 +4,15 @@
 
 namespace App\Http\Responses\Fortify;
 
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Onboarding\IntendedPlanResolver;
+use App\Support\Auth\EmailVerificationContinuation;
 use Illuminate\Http\JsonResponse;
 use Illuminate\Http\RedirectResponse;
 use Illuminate\Http\Request;
 use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
+use Webmozart\Assert\Assert;
 
 /**
  * 登録直後のレスポンス (Fortify contract bind)。
@@ -17,14 +22,42 @@
  * 登録直後にメール認証を促す導線を明確にするため、未認証ユーザーが必ず到達できる
  * verification.notice (「認証してください」画面) へ直接誘導する。
  * XHR(201) は Fortify 標準と同じ後方互換を維持する。
+ *
+ * P7 の追加責務 (session 副作用のみ。個人組織生成は CreateNewUser の tx 内で完結済み):
+ *   - 通常登録: pending のプラン意図を個人組織へ promote し、verify ソフトゲートの
+ *     継続導線 (EmailVerificationContinuation) に個人組織 id を保持する。
+ *   - 招待受諾成立 (= 個人組織が存在しない): 料金表由来の pending を forget し、
+ *     継続導線も張らない (招待組織へ参加するだけのユーザーに契約導線を出さない)。
+ * session 副作用は XHR (201) 経路でも同じく先に実行してから応答を返す。
  */
 final class RegisterResponse implements RegisterResponseContract
 {
+    public function __construct(
+        private readonly IntendedPlanResolver $intendedPlanResolver,
+    ) {}
+
     /**
      * @param  Request  $request
      */
     public function toResponse($request): JsonResponse|RedirectResponse
     {
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        // 招待受諾は CreateNewUser の tx 内で完了しており、成立時は個人組織を作らない。
+        // 「個人組織の有無」が招待経由かどうかの唯一の判定軸 (?-> で握り潰さず分岐を明示する)。
+        $personalOrganization = $user->organizations()->where('is_personal', true)->first();
+
+        if ($personalOrganization instanceof Organization) {
+            // pending → org-scoped へ移送 (pending は必ず forget で消費される)。
+            $this->intendedPlanResolver->promotePendingToOrganization($personalOrganization);
+            // 生 URL ではなく組織 id のみ保持する (参照時に membership 確認 + route 再構築)。
+            EmailVerificationContinuation::remember($request->session(), $personalOrganization->id);
+        } else {
+            // 招待経由: 料金表由来の pending が残っていても消費しない (stale 防止)。
+            $this->intendedPlanResolver->forgetPending();
+        }
+
         if ($request->wantsJson()) {
             return new JsonResponse('', 201);
         }
diff --git a/app/Http/Responses/Fortify/VerifyEmailResponse.php b/app/Http/Responses/Fortify/VerifyEmailResponse.php
new file mode 100644
index 0000000..fcf295f
--- /dev/null
+++ b/app/Http/Responses/Fortify/VerifyEmailResponse.php
@@ -0,0 +1,44 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Responses\Fortify;
+
+use App\Models\User;
+use App\Support\Auth\EmailVerificationContinuation;
+use Illuminate\Http\RedirectResponse;
+use Illuminate\Http\Request;
+use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
+
+/**
+ * メール認証完了後の着地 (Fortify contract bind)。
+ *
+ * 登録由来の continuation (個人組織 id) があれば onboarding.checkout へ復帰し、
+ * continuation は verify 完了時に必ず forget する (寿命の terminal)。
+ * continuation が無い場合の着地は **Fortify 既定と同値** (`fortify.home` + `?verified=1`)
+ * に保つ = 既存の verify 完了フローを後退させない。
+ *
+ * `redirect()->intended()` の使用はログイン直後フロー (GET の signed URL 踏破) に限られ、
+ * 操作系 POST の応答ではない (AGENTS.md 禁止事項 #7 に抵触しない)。
+ */
+final class VerifyEmailResponse implements VerifyEmailResponseContract
+{
+    /**
+     * @param  Request  $request
+     */
+    public function toResponse($request): RedirectResponse
+    {
+        $user = $request->user();
+        $continueUrl = EmailVerificationContinuation::resolveUrl(
+            $user instanceof User ? $user : null,
+            $request->session(),
+        );
+        EmailVerificationContinuation::forget($request->session());
+
+        if ($continueUrl !== null) {
+            return redirect()->to($continueUrl);
+        }
+
+        return redirect()->intended(config()->string('fortify.home').'?verified=1');
+    }
+}
diff --git a/app/Providers/FortifyServiceProvider.php b/app/Providers/FortifyServiceProvider.php
index 6424b96..3544515 100644
--- a/app/Providers/FortifyServiceProvider.php
+++ b/app/Providers/FortifyServiceProvider.php
@@ -17,7 +17,11 @@
 use App\Http\Responses\Fortify\RegisterResponse;
 use App\Http\Responses\Fortify\TwoFactorDisabledResponse;
 use App\Http\Responses\Fortify\VerificationNotificationSentResponse;
+use App\Http\Responses\Fortify\VerifyEmailResponse;
+use App\Models\User;
+use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
+use App\Support\Auth\EmailVerificationContinuation;
 use Illuminate\Cache\RateLimiting\Limit;
 use Illuminate\Contracts\Foundation\Application;
 use Illuminate\Http\RedirectResponse;
@@ -40,6 +44,7 @@
 use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
 use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse as SuccessfulPasswordResetLinkRequestResponseContract;
 use Laravel\Fortify\Contracts\TwoFactorDisabledResponse as TwoFactorDisabledResponseContract;
+use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;
 use Laravel\Fortify\Fortify;
 use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
 
@@ -81,6 +86,8 @@ public function register(): void
         // 挙動の意図は各 Response クラスの docblock を参照。
         $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
         $this->app->singleton(RegisterResponseContract::class, RegisterResponse::class);
+        // verify 完了着地: continuation があれば onboarding.checkout、無ければ Fortify 既定と同値。
+        $this->app->singleton(VerifyEmailResponseContract::class, VerifyEmailResponse::class);
         $this->app->singleton(TwoFactorDisabledResponseContract::class, TwoFactorDisabledResponse::class);
         $this->app->singleton(RecoveryCodesGeneratedResponseContract::class, RecoveryCodesGeneratedResponse::class);
         $this->app->singleton(EmailVerificationNotificationSentResponseContract::class, VerificationNotificationSentResponse::class);
@@ -188,6 +195,10 @@ private function configureViews(): void
             $response = Inertia::render('Auth/Register', [
                 'socialProviders' => array_keys(config()->array('template.social_providers')),
                 'invitationEmail' => $invitationEmail,
+                // 料金表 → /register?plan={code} のプラン意図。ユーザー入力のため
+                // resolver の allowlist 照合に一本化する (Provider 側で分岐を書かない)。
+                // 未知値 / 配列 / Enterprise はすべて null (= 意図なし) に倒れる。
+                'intendedPlan' => IntendedPlanResolver::normalizeRaw($request->query('plan'))?->value,
             ])->toResponse($request);
 
             // PII (招待先 email) を含む応答を HTTP キャッシュ (共有/中間プロキシ/ブラウザの
@@ -216,7 +227,18 @@ private function configureViews(): void
             ]);
         });
 
-        Fortify::verifyEmailView(static fn (): InertiaResponse => Inertia::render('Auth/VerifyEmail'));
+        Fortify::verifyEmailView(static function (Request $request): InertiaResponse {
+            $user = $request->user();
+
+            // 登録由来の継続導線 (「あとで認証する」)。session には組織 id のみ保持し、
+            // membership 確認を通ったときだけ URL 化する (IDOR 防御)。
+            return Inertia::render('Auth/VerifyEmail', [
+                'continueUrl' => EmailVerificationContinuation::resolveUrl(
+                    $user instanceof User ? $user : null,
+                    $request->session(),
+                ),
+            ]);
+        });
 
         // password.confirm (Fortify 生 step-up) は generic recent-auth に置換済み。
         // ただし fortify.views=true の間は GET /user/confirm-password が Fortify により
diff --git a/app/Services/Onboarding/IntendedPlanResolver.php b/app/Services/Onboarding/IntendedPlanResolver.php
new file mode 100644
index 0000000..ded1a5c
--- /dev/null
+++ b/app/Services/Onboarding/IntendedPlanResolver.php
@@ -0,0 +1,164 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Onboarding;
+
+use App\Enums\PlanCode;
+use App\Models\Organization;
+use Illuminate\Contracts\Session\Session;
+use Illuminate\Http\Request;
+
+/**
+ * 料金表 → 登録 → Onboarding Checkout の経路で「料金表で選んだプラン意図」を
+ * 一貫保持する責務に集約する。
+ *
+ * 2 キー設計:
+ *   - pending (組織未確定): `onboarding.intended_plan.pending`
+ *   - org-scoped:           `onboarding.intended_plan.org.{id}`
+ *
+ * 書き込み規約 (2 種類):
+ *
+ * 1. pending 系 (`rememberPendingFromXxx`): **常に書き換える** (新規 register/SSO 開始
+ *    ごとに必ず最新値で置換)。 前回 OAuth 中断などで残った stale pending が次の plan
+ *    なし新規登録に promote される事故を防ぐため、 不在 / null / 空文字 / 改ざんは
+ *    すべて forget する。
+ *      - 不在 / null / 空文字 → forget
+ *      - 有効 (Personal/Starter/Standard/Business) → put
+ *      - 無効 / Enterprise / 改ざん (配列等) → forget
+ *
+ * 2. org-scoped 系 (`rememberForOrganizationFromXxx`): **不在は no-op** (Onboarding
+ *    Checkout のリロード耐性を保つため、 plan なしの単なるリロードで session を破壊
+ *    しない)。
+ *      - 不在 → no-op
+ *      - 有効 → put
+ *      - 無効 / Enterprise / 改ざん → forget
+ *
+ * `?plan=` はユーザー入力である。tenant/actor キーは一切受け取らず、値は必ず
+ * `PlanCode` allowlist に照合してから session に載せる (セキュリティ不変条件 #1)。
+ */
+final class IntendedPlanResolver
+{
+    public const PENDING_KEY = 'onboarding.intended_plan.pending';
+
+    public function __construct(private readonly Session $session) {}
+
+    public static function orgKey(Organization $organization): string
+    {
+        return "onboarding.intended_plan.org.{$organization->id}";
+    }
+
+    /**
+     * 文字列 → `PlanCode` 正規化の single source。
+     * Enterprise はセルフサーブ契約フローに乗らない（お問い合わせ営業導線）ため null 化する。
+     * Personal は普通の契約フローに露出する選択肢のため受理する
+     * (料金表 `?plan=personal` 経由の意図もそのまま引き継ぐ)。
+     */
+    public static function normalizeRaw(mixed $value): ?PlanCode
+    {
+        if (! is_string($value)) {
+            return null;
+        }
+        $code = PlanCode::tryFrom(strtolower(trim($value)));
+        if ($code === null || $code === PlanCode::Enterprise) {
+            return null;
+        }
+
+        return $code;
+    }
+
+    // --- pending (組織未確定): 常に書き換える ---
+
+    public function rememberPendingFromQuery(Request $request): void
+    {
+        if (! $request->has('plan')) {
+            $this->forgetPending();
+
+            return;
+        }
+        $this->putPendingFromValue($request->query('plan'));
+    }
+
+    /**
+     * @param  array<string, mixed>  $input
+     */
+    public function rememberPendingFromForm(array $input): void
+    {
+        // `array_key_exists` で「key 不在」と「明示 null」を区別する。
+        // key 不在ならフォーム送信者が plan を意図していない (= forget で fresh state)。
+        if (! array_key_exists('intended_plan', $input)) {
+            $this->forgetPending();
+
+            return;
+        }
+        $this->putPendingFromValue($input['intended_plan']);
+    }
+
+    public function peekPending(): ?PlanCode
+    {
+        $raw = $this->session->get(self::PENDING_KEY);
+
+        return is_string($raw) ? self::normalizeRaw($raw) : null;
+    }
+
+    public function forgetPending(): void
+    {
+        $this->session->forget(self::PENDING_KEY);
+    }
+
+    // --- org-scoped: 不在は no-op (リロード耐性) ---
+
+    public function rememberForOrganizationFromQuery(Request $request, Organization $organization): void
+    {
+        $key = self::orgKey($organization);
+        if (! $request->has('plan')) {
+            return;
+        }
+        $normalized = self::normalizeRaw($request->query('plan'));
+        if ($normalized === null) {
+            $this->session->forget($key);
+
+            return;
+        }
+        $this->session->put($key, $normalized->value);
+    }
+
+    public function peekForOrganization(Organization $organization): ?PlanCode
+    {
+        $raw = $this->session->get(self::orgKey($organization));
+
+        return is_string($raw) ? self::normalizeRaw($raw) : null;
+    }
+
+    public function forgetForOrganization(Organization $organization): void
+    {
+        $this->session->forget(self::orgKey($organization));
+    }
+
+    /**
+     * pending → org-scoped に移送（登録完了直後）。
+     * pending は常に forget で消費する。pending に値がなければ org key は触らない。
+     */
+    public function promotePendingToOrganization(Organization $organization): void
+    {
+        $code = $this->peekPending();
+        $this->forgetPending();
+        if ($code === null) {
+            return;
+        }
+        $this->session->put(self::orgKey($organization), $code->value);
+    }
+
+    // --- 内部 ---
+
+    private function putPendingFromValue(mixed $value): void
+    {
+        $normalized = self::normalizeRaw($value);
+        if ($normalized === null) {
+            $this->forgetPending();
+
+            return;
+        }
+        $this->session->put(self::PENDING_KEY, $normalized->value);
+    }
+}
diff --git a/app/Services/Onboarding/OnboardingReturnResolver.php b/app/Services/Onboarding/OnboardingReturnResolver.php
new file mode 100644
index 0000000..91f368c
--- /dev/null
+++ b/app/Services/Onboarding/OnboardingReturnResolver.php
@@ -0,0 +1,106 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Onboarding;
+
+use App\Models\Organization;
+use Illuminate\Contracts\Session\Session;
+
+/**
+ * onboarding subscription gate で失われる「意図先 destination」を org-scoped session に保持し、
+ * checkout / activate 完了着地で復帰導線 (continueUrl) を提供する。IntendedPlanResolver と同型の
+ * 3 規約 (put: 妥当な same-origin 内部 path のみ / peek: 再正規化 / forget: 消費)。
+ *
+ * open-redirect 防御: 保存値は **same-origin の内部相対 path (先頭 '/')** のみ許可する。
+ * 絶対 URL / '//host' (protocol-relative) / 別ホストは破棄して保存しない。
+ */
+final class OnboardingReturnResolver
+{
+    public function __construct(private readonly Session $session) {}
+
+    public static function orgKey(Organization $organization): string
+    {
+        return "onboarding.return_to.org.{$organization->id}";
+    }
+
+    /**
+     * 内部 path への正規化 (open-redirect 防御を多段で実施)。
+     * same-origin 内部相対 path のみ許可し、それ以外 (絶対URL/protocol-relative/scheme/host/
+     * user:pass@/port/バックスラッシュ/制御文字/エンコード回避) は null。
+     */
+    public static function normalizePath(mixed $value): ?string
+    {
+        if (! is_string($value)) {
+            return null;
+        }
+        $value = trim($value);
+        if ($value === '') {
+            return null;
+        }
+        // 1) 制御文字 (改行/タブ/NUL 等) を拒否 (ヘッダ/解釈差注入対策)。
+        if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
+            return null;
+        }
+        // 2) raw / エンコード両方で判定する (%2F%2F や %5C のブラウザ解釈差回避)。
+        $decoded = rawurldecode($value);
+        foreach ([$value, $decoded] as $candidate) {
+            // decoded 後に制御文字 (%0a/%0d/%09/%00 等) が現れるケースを拒否
+            // (header/解釈差注入対策。raw 側は step 1 で既に判定済だが decoded 側もここで弾く)。
+            if (preg_match('/[\x00-\x1F\x7F]/', $candidate) === 1) {
+                return null;
+            }
+            // scheme 付き (javascript: / http: 等) と protocol-relative (//host) を拒否。
+            if (preg_match('#^(?:[a-z][a-z0-9+.\-]*:)?//#i', $candidate) === 1) {
+                return null;
+            }
+            // バックスラッシュ混入 (\\host / /\host のブラウザ解釈差) を拒否。
+            if (str_contains($candidate, '\\')) {
+                return null;
+            }
+            // 先頭 '/' 必須 (相対 path や 'foo' は拒否)。
+            if (! str_starts_with($candidate, '/')) {
+                return null;
+            }
+        }
+        // 3) parse_url で scheme/host/user/pass/port が 1 つでもあれば絶対 URL とみなし拒否。
+        $parsed = parse_url($value);
+        if ($parsed === false) {
+            return null;
+        }
+        foreach (['scheme', 'host', 'user', 'pass', 'port'] as $key) {
+            if (isset($parsed[$key])) {
+                return null;
+            }
+        }
+        // 4) path のみ採用 (query は保持、fragment は drop)。返却値は raw 由来の parse_url 結果で一貫させる。
+        $path = $parsed['path'] ?? '/';
+        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
+            return null;
+        }
+        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';
+
+        return $path.$query;
+    }
+
+    public function rememberForOrganization(Organization $organization, string $path): void
+    {
+        $normalized = self::normalizePath($path);
+        if ($normalized === null) {
+            return; // 不正値は no-op (既存 return_to を壊さない)
+        }
+        $this->session->put(self::orgKey($organization), $normalized);
+    }
+
+    public function peekForOrganization(Organization $organization): ?string
+    {
+        $raw = $this->session->get(self::orgKey($organization));
+
+        return is_string($raw) ? self::normalizePath($raw) : null; // 再検証 (改ざん耐性)
+    }
+
+    public function forgetForOrganization(Organization $organization): void
+    {
+        $this->session->forget(self::orgKey($organization));
+    }
+}
diff --git a/app/Support/Auth/EmailVerificationContinuation.php b/app/Support/Auth/EmailVerificationContinuation.php
new file mode 100644
index 0000000..ddff982
--- /dev/null
+++ b/app/Support/Auth/EmailVerificationContinuation.php
@@ -0,0 +1,58 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support\Auth;
+
+use App\Models\User;
+use Illuminate\Contracts\Session\Session;
+
+/**
+ * 登録 → verify notice ソフトゲートの「あとで認証する」継続導線。
+ *
+ * 生 URL を session に持たず organization_id のみ保持し、参照時に route を再構築 +
+ * membership 確認する (URL 直保持はルート変更・値汚染に脆い)。所属確認 (relation 経由
+ * fetch、IDOR 防御規約) を通らない値は null = 導線を出さない。
+ * 寿命: remember (登録時) → forget (verify 完了時)。
+ *
+ * AI-CUE の onboarding route は current-org スコープ (route parameter なし) のため、
+ * 再構築するのは引数なしの `route('onboarding.checkout')`。session に保持した
+ * organization_id は「その組織のメンバーであること」の確認にのみ使う。
+ */
+final class EmailVerificationContinuation
+{
+    private const string SESSION_KEY = 'verify_continue_organization_id';
+
+    public static function remember(Session $session, int $organizationId): void
+    {
+        $session->put(self::SESSION_KEY, $organizationId);
+    }
+
+    /**
+     * session の organization_id から checkout URL を再構築する。
+     * 所属確認を通らない値・非 int・null user は null (= 導線を出さない)。
+     */
+    public static function resolveUrl(?User $user, Session $session): ?string
+    {
+        if ($user === null) {
+            return null;
+        }
+
+        $organizationId = $session->get(self::SESSION_KEY);
+        if (! is_int($organizationId)) {
+            return null;
+        }
+
+        $organization = $user->organizations()->whereKey($organizationId)->first();
+        if ($organization === null) {
+            return null;
+        }
+
+        return route('onboarding.checkout');
+    }
+
+    public static function forget(Session $session): void
+    {
+        $session->forget(self::SESSION_KEY);
+    }
+}
diff --git a/devnotes/20260717-0037-analysis-generate-timeout/coverage-check.php b/devnotes/20260717-0037-analysis-generate-timeout/coverage-check.php
index 1f7edea..f237802 100644
--- a/devnotes/20260717-0037-analysis-generate-timeout/coverage-check.php
+++ b/devnotes/20260717-0037-analysis-generate-timeout/coverage-check.php
@@ -14,11 +14,12 @@
  */
 
 use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
+use Illuminate\Contracts\Console\Kernel;
 use Illuminate\Support\Facades\DB;
 
 require __DIR__.'/../../vendor/autoload.php';
 $app = require_once __DIR__.'/../../bootstrap/app.php';
-$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+$app->make(Kernel::class)->bootstrap();
 
 $truth = json_decode((string) DB::table('analysis_jobs')->where('id', 1)->value('result_json'), true);
 $truthSteps = $truth['steps'] ?? [];
diff --git a/devnotes/20260717-0037-analysis-generate-timeout/provider-quality-probe.php b/devnotes/20260717-0037-analysis-generate-timeout/provider-quality-probe.php
index baf4214..0a5bcb9 100644
--- a/devnotes/20260717-0037-analysis-generate-timeout/provider-quality-probe.php
+++ b/devnotes/20260717-0037-analysis-generate-timeout/provider-quality-probe.php
@@ -21,11 +21,12 @@
 
 use App\DataTransferObjects\Manual\Analysis\GeneratedScenarioData;
 use App\Prompts\ScenarioGenerationPrompt;
+use Illuminate\Contracts\Console\Kernel;
 use Illuminate\Support\Facades\DB;
 
 require __DIR__.'/../../vendor/autoload.php';
 $app = require_once __DIR__.'/../../bootstrap/app.php';
-$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
+$app->make(Kernel::class)->bootstrap();
 
 // 公式ドキュメントから取得した単価 (USD / 1M tokens)。取得日 2026-07-17。
 // 出典: platform.claude.com/docs/en/about-claude/pricing.md,
@@ -68,7 +69,7 @@
 $results = [];
 foreach ($targets as [$provider, $model]) {
     $label = "$provider:$model";
-    printf("--- %s ... ", $label);
+    printf('--- %s ... ', $label);
 
     $logIdBefore = (int) (DB::table('llm_call_logs')->max('id') ?? 0);
     $prompt = ScenarioGenerationPrompt::make($decomposition);
diff --git a/resources/js/pages/Auth/Register.svelte b/resources/js/pages/Auth/Register.svelte
index 8810238..7ba3e84 100644
--- a/resources/js/pages/Auth/Register.svelte
+++ b/resources/js/pages/Auth/Register.svelte
@@ -9,14 +9,22 @@
     import PasswordInput from "@/components/molecules/PasswordInput.svelte";
     import AuthLayout from "@/components/templates/AuthLayout.svelte";
     import { providerLabel } from "@/lib/social";
+    import type { PlanCode } from "@/types/Auth";
 
     interface Props {
         appName?: string;
         socialProviders?: string[];
         invitationEmail?: string | null;
+        /** 料金表 `/register?plan=` 由来の選択意図 (サーバで allowlist 照合済み) */
+        intendedPlan?: PlanCode | null;
     }
 
-    let { appName, socialProviders = [], invitationEmail = null }: Props = $props();
+    let {
+        appName,
+        socialProviders = [],
+        invitationEmail = null,
+        intendedPlan = null,
+    }: Props = $props();
 
     // 招待リンク経由 (invitationEmail あり) は招待先 email を初期値にし、以降 readonly で固定する。
     // readonly は UX 上の "誘導" に過ぎない: devtools で外して別 email を POST しても、サーバの
@@ -24,11 +32,14 @@
     // prefill + readonly は「正しい値を先に入れて手入力ミスを防ぐ」ためのものでセキュリティ境界ではない。
     const isInvited = $derived(invitationEmail != null && invitationEmail !== "");
 
+    // intended_plan は null でも **常に送信**する: サーバの resolver は「キー不在 = 意図なし」
+    // として stale pending を forget するため、送らないと前回の意図が残り続ける。
     const form = useForm({
         name: "",
         email: invitationEmail ?? "",
         password: "",
         terms_accepted: false,
+        intended_plan: intendedPlan,
     });
 
     /**
@@ -58,9 +69,14 @@
         }
     }
 
+    // SSO 登録にもプラン意図を伝播する (意図なしなら付けない = pending は forget される)。
+    const planParam = $derived(
+        intendedPlan === null ? "" : `&plan=${encodeURIComponent(intendedPlan)}`,
+    );
+
     const ssoHref = $derived((provider: string) =>
         form.terms_accepted
-            ? `/auth/${provider}/redirect/register?terms_accepted=1`
+            ? `/auth/${provider}/redirect/register?terms_accepted=1${planParam}`
             : `/auth/${provider}/redirect/register`,
     );
 </script>
diff --git a/resources/js/pages/Auth/VerifyEmail.svelte b/resources/js/pages/Auth/VerifyEmail.svelte
index 42caa12..b3361b8 100644
--- a/resources/js/pages/Auth/VerifyEmail.svelte
+++ b/resources/js/pages/Auth/VerifyEmail.svelte
@@ -5,9 +5,14 @@
 
     interface Props {
         appName?: string;
+        /**
+         * 登録由来の継続導線 (プラン選択へ進む)。サーバが membership 確認を通ったときだけ
+         * 非 null で届く。null のときは二次 CTA を出さない。
+         */
+        continueUrl?: string | null;
     }
 
-    let { appName }: Props = $props();
+    let { appName, continueUrl = null }: Props = $props();
 
     const form = useForm({});
 
@@ -43,6 +48,16 @@
 
     <form onsubmit={resend} class="flex flex-col gap-3">
         <Button type="submit" loading={form.processing} fullWidth>認証メールを再送信</Button>
+        {#if continueUrl !== null}
+            <Button
+                variant="ghost"
+                onclick={() => router.visit(continueUrl)}
+                fullWidth
+                testId="verify-email-continue"
+            >
+                あとで認証する（プラン選択へ進む）
+            </Button>
+        {/if}
         <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
             ログアウト
         </Button>
diff --git a/resources/js/pages/Billing/Index.svelte b/resources/js/pages/Billing/Index.svelte
index cafc081..034dd65 100644
--- a/resources/js/pages/Billing/Index.svelte
+++ b/resources/js/pages/Billing/Index.svelte
@@ -10,6 +10,7 @@
     import PageHeader from "@/components/molecules/PageHeader.svelte";
     import { CreditCard } from "@lucide/svelte";
     import type { SharedProps } from "@/lib/shared-props";
+    import type { BillingIndexPlan, BillingIndexPlanPrice } from "@/types/billing";
 
     /**
      * 課金ページ (現在プラン / チケット残高 / プラン一覧)。
@@ -17,25 +18,28 @@
      * (POST → Inertia::location で Stripe へ full page redirect)。
      * manageBilling 権限 (owner / admin) が無いメンバーは閲覧のみ。
      */
-    interface PlanPrice {
-        unitAmount: number;
-        currency: string;
-    }
-
-    interface Plan {
-        code: string;
-        name: string;
-        price: PlanPrice | null;
-    }
+    type PlanPrice = BillingIndexPlanPrice;
+    type Plan = BillingIndexPlan;
 
     interface Props {
         plans: Plan[];
         currentPlanCode: string | null;
         ticketBalance: number;
         canManageBilling: boolean;
+        /**
+         * 課金ゲートで中断された「元の画面」への復帰先。契約成立着地でのみ 1 回だけ
+         * 非 null で届く (サーバが same-origin 内部 path に正規化済み)。
+         */
+        continueUrl?: string | null;
     }
 
-    let { plans, currentPlanCode, ticketBalance, canManageBilling }: Props = $props();
+    let {
+        plans,
+        currentPlanCode,
+        ticketBalance,
+        canManageBilling,
+        continueUrl = null,
+    }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -96,6 +100,17 @@
         />
         <PageContent>
             <div class="flex flex-col gap-10">
+            {#if continueUrl !== null}
+                <Card padding="lg" testId="billing-continue">
+                    <p class="text-body">お手続きが完了しました。中断していた画面に戻れます。</p>
+                    <div class="mt-4">
+                        <Button href={continueUrl} inertia testId="billing-continue-link">
+                            元の画面に戻る
+                        </Button>
+                    </div>
+                </Card>
+            {/if}
+
             <Card padding="lg" testId="billing-summary">
                 <div class="flex flex-wrap items-start justify-between gap-4">
                     <div>
diff --git a/resources/js/pages/Onboarding/Checkout.svelte b/resources/js/pages/Onboarding/Checkout.svelte
index a2fbc94..ca9eebe 100644
--- a/resources/js/pages/Onboarding/Checkout.svelte
+++ b/resources/js/pages/Onboarding/Checkout.svelte
@@ -40,12 +40,18 @@
     const appName = $derived(shared.appName ?? "");
     const serverErrors = $derived((inertiaPage.props.errors ?? {}) as Record<string, string>);
 
-    // defaultPlanCode は plans への包含を保証しない (コード値) ため、plans にある場合のみ
-    // preselect し、無ければ先頭 plan を強調する (決定的挙動)。
-    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null =>
-        data.plans.some((p) => p.code === data.defaultPlanCode)
+    // 料金表由来の intendedPlanCode → defaultPlanCode → 先頭 plan の順で preselect する。
+    // どちらも plans への包含を保証しない (コード値) ため、plans にある場合のみ採用する
+    // (決定的挙動)。
+    const computeInitialPlan = (data: OnboardingCheckoutShape): string | null => {
+        const intended = data.intendedPlanCode;
+        if (intended !== null && data.plans.some((p) => p.code === intended)) {
+            return intended;
+        }
+        return data.plans.some((p) => p.code === data.defaultPlanCode)
             ? data.defaultPlanCode
             : (data.plans[0]?.code ?? null);
+    };
 
     let chosenPlanCode = $state<string | null>(null);
     // 強調するカード = ユーザーが選んだもの。未選択なら props から導出した既定。
diff --git a/resources/js/pages/Pricing.svelte b/resources/js/pages/Pricing.svelte
index c02e822..d3ccc98 100644
--- a/resources/js/pages/Pricing.svelte
+++ b/resources/js/pages/Pricing.svelte
@@ -120,7 +120,9 @@
                         {#if page.isAuthenticated}
                             <Button href="/billing" fullWidth inertia>プランを変更</Button>
                         {:else}
-                            <Button href="/register" fullWidth>このプランで始める</Button>
+                            <Button href={`/register?plan=${encodeURIComponent(plan.code)}`} fullWidth>
+                                このプランで始める
+                            </Button>
                         {/if}
                     {/snippet}
                 </PricingPlanCard>
diff --git a/resources/js/pages/Welcome.svelte b/resources/js/pages/Welcome.svelte
index 2ee10af..d8c3ddf 100644
--- a/resources/js/pages/Welcome.svelte
+++ b/resources/js/pages/Welcome.svelte
@@ -134,7 +134,7 @@
             <a href="/dashboard" class="text-text-secondary hover:text-primary">ダッシュボード</a>
         {:else}
             <a href="/login" class="text-text-secondary hover:text-primary">ログイン</a>
-            <a href="/register" class="text-primary hover:text-primary-hover">無料で始める</a>
+            <a href="/pricing" class="text-primary hover:text-primary-hover">無料で始める</a>
         {/if}
     {/snippet}
 
@@ -157,7 +157,7 @@
                         <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
                     </Button>
                 {:else}
-                    <Button href="/register" size="lg" testId="hero-register">無料で始める</Button>
+                    <Button href="/pricing" size="lg" inertia testId="hero-register">無料で始める</Button>
                 {/if}
                 <Button href="#how" size="lg" variant="ghost">
                     仕組みを見る <ArrowRight class="size-4" aria-hidden="true" />
@@ -355,7 +355,7 @@
                     <LayoutDashboard class="size-5" aria-hidden="true" /> ダッシュボードへ
                 </Button>
             {:else}
-                <Button href="/register" size="lg">無料で始める</Button>
+                <Button href="/pricing" size="lg" inertia>無料で始める</Button>
             {/if}
             <Button href="/pricing" size="lg" variant="ghost" inertia>
                 料金プランを見る <ArrowRight class="size-4" aria-hidden="true" />
diff --git a/resources/js/types/Auth.ts b/resources/js/types/Auth.ts
new file mode 100644
index 0000000..a766a49
--- /dev/null
+++ b/resources/js/types/Auth.ts
@@ -0,0 +1,23 @@
+/**
+ * 認証系ページの Inertia props。
+ * PHP 側 (App\Enums\PlanCode / FortifyServiceProvider の registerView) と exact 対。
+ */
+
+/**
+ * PHP: App\Enums\PlanCode の 5 case と exact 対。
+ *
+ * 表示名 (プラン名) はここに置かない — 真実源は `plans.name` (サーバ確定値) であり、
+ * フロントに二重台帳を作らない。
+ */
+export type PlanCode = "personal" | "starter" | "standard" | "business" | "enterprise";
+
+/** Auth/Register ページの props */
+export interface RegisterPageProps {
+    /**
+     * 料金表 `/register?plan={code}` 由来の選択意図 (サーバで allowlist 照合済み)。
+     * `enterprise` はセルフサーブ契約フローに乗らないため常に null で届く。
+     */
+    readonly intendedPlan: PlanCode | null;
+    readonly socialProviders: string[];
+    readonly invitationEmail: string | null;
+}
diff --git a/resources/js/types/billing.ts b/resources/js/types/billing.ts
index fd7c6b7..b790a31 100644
--- a/resources/js/types/billing.ts
+++ b/resources/js/types/billing.ts
@@ -16,3 +16,27 @@ export interface PurchaseTicketsPageProps {
     readonly attemptToken: string;
     readonly purchased: boolean;
 }
+
+/** Billing/Index (課金ページ) の Inertia props */
+export interface BillingIndexPlanPrice {
+    readonly unitAmount: number;
+    readonly currency: string;
+}
+
+export interface BillingIndexPlan {
+    readonly code: string;
+    readonly name: string;
+    readonly price: BillingIndexPlanPrice | null;
+}
+
+export interface BillingIndexProps {
+    readonly plans: readonly BillingIndexPlan[];
+    readonly currentPlanCode: string | null;
+    readonly ticketBalance: number;
+    readonly canManageBilling: boolean;
+    /**
+     * 課金ゲートで中断された「元の画面」への復帰先 (same-origin 内部 path)。
+     * 契約成立着地でのみ 1 回だけ非 null で届く (リロードでは null に戻る)。
+     */
+    readonly continueUrl: string | null;
+}
diff --git a/resources/js/types/onboarding.ts b/resources/js/types/onboarding.ts
index fc4c3b6..c02cee1 100644
--- a/resources/js/types/onboarding.ts
+++ b/resources/js/types/onboarding.ts
@@ -35,6 +35,11 @@ export interface OnboardingCheckoutShape {
     readonly personalEligibility: PersonalPlanEligibilityShape | null;
     /** 新規登録特典の無償チケット枚数 (無料開始 callout 用) */
     readonly signupGrantTickets: number;
+    /**
+     * 料金表 `?plan=` 由来の選択意図 (サーバで allowlist 照合済み)。
+     * `plans` への包含は保証しない = 該当 code があるときだけ preselect する。
+     */
+    readonly intendedPlanCode: string | null;
 }
 
 /** PHP: BillingRequiredDto (BillingRequiredShape) と対 */
diff --git a/tests/Feature/Auth/RegisterPlanHandoffTest.php b/tests/Feature/Auth/RegisterPlanHandoffTest.php
new file mode 100644
index 0000000..f19ea74
--- /dev/null
+++ b/tests/Feature/Auth/RegisterPlanHandoffTest.php
@@ -0,0 +1,197 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\OrganizationInvitation;
+use App\Models\User;
+use App\Services\Onboarding\IntendedPlanResolver;
+use Illuminate\Support\Facades\Http;
+use Inertia\Testing\AssertableInertia;
+
+/**
+ * P7: 料金表 → `/register?plan=` の plan 引き継ぎ HTTP テスト。
+ *
+ * HTTP は CreateNewUser → RegisterResponse まで一気通貫で走るため、テスト完了時点では:
+ *   - pending key (`onboarding.intended_plan.pending`) は **forget** されている
+ *   - org-scoped key (`onboarding.intended_plan.org.{personal_org_id}`) に promote されている
+ * pending 単独の振る舞いは IntendedPlanResolver Unit テストで網羅済。
+ */
+beforeEach(function (): void {
+    // Password::defaults() の uncompromised HIBP 通信を抑止する。
+    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
+
+    $this->validPayload = fn (array $overrides = []): array => array_merge([
+        'name' => 'Plan Tester',
+        'email' => 'plan-'.uniqid().'@example.com',
+        'password' => 'CorrectHorse9Battery',
+        'terms_accepted' => '1',
+    ], $overrides);
+});
+
+// --- GET /register の intendedPlan prop (?plan= の allowlist 照合) ---
+
+test('GET /register?plan={code} は allowlist 照合済みの intendedPlan prop を返す', function (string $raw, ?string $expected): void {
+    $this->get('/register?plan='.$raw)
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/Register')
+            ->where('intendedPlan', $expected));
+})->with([
+    'personal' => ['personal', 'personal'],
+    'starter' => ['starter', 'starter'],
+    'standard' => ['standard', 'standard'],
+    'business' => ['business', 'business'],
+    // enterprise は enum として有効だが intent としては採用しない (お問い合わせ営業導線)
+    'enterprise は null' => ['enterprise', null],
+    '未知値は null' => ['foo', null],
+    '空文字は null' => ['', null],
+    '大文字・空白は正規化' => ['%20Starter%20', 'starter'],
+]);
+
+test('GET /register (plan なし) の intendedPlan prop は null', function (): void {
+    $this->get('/register')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/Register')
+            ->where('intendedPlan', null));
+});
+
+test('GET /register?plan[]=standard (配列の改ざん) でも 500 にならず intendedPlan は null', function (): void {
+    $this->get('/register?plan[]=standard')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('intendedPlan', null));
+});
+
+test('招待経由 GET /register の Cache-Control: no-store は ?plan= を足しても非退行', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => 'invited-plan@example.com']);
+
+    $response = $this->withSession(['invitation_token' => $token])->get('/register?plan=standard');
+
+    $response->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('invitationEmail', 'invited-plan@example.com')
+            ->where('intendedPlan', 'standard'));
+    expect($response->headers->get('Cache-Control'))->toContain('no-store');
+});
+
+// --- POST /register の pending → org-scoped promote ---
+
+test('POST /register intended_plan=starter は pending を消費し org-scoped に promote する', function (): void {
+    $payload = ($this->validPayload)(['intended_plan' => 'starter']);
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBe('starter');
+});
+
+test('POST /register intended_plan=enterprise は promote しない (org key 不在)', function (): void {
+    $payload = ($this->validPayload)(['intended_plan' => 'enterprise']);
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+test('POST /register intended_plan=foo (無効値) は 422 にならず promote もしない', function (): void {
+    $payload = ($this->validPayload)(['intended_plan' => 'foo']);
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertSessionHasNoErrors();
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+test('POST /register で intended_plan キー不在なら stale pending は forget され promote されない', function (): void {
+    session([IntendedPlanResolver::PENDING_KEY => 'business']);
+
+    $payload = ($this->validPayload)(); // intended_plan キー不在
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+test('POST /register intended_plan=null は stale pending を消し promote しない', function (): void {
+    session([IntendedPlanResolver::PENDING_KEY => 'business']);
+
+    $payload = ($this->validPayload)(['intended_plan' => null]);
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+test('POST /register intended_plan が配列 (改ざん) でも 422 にならず pending は forget される', function (): void {
+    session([IntendedPlanResolver::PENDING_KEY => 'business']);
+    $payload = ($this->validPayload)(['intended_plan' => ['standard']]);
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertSessionHasNoErrors();
+    $response->assertRedirect(route('verification.notice'));
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+// --- 招待経由との排他契約 (最重要) ---
+
+test('招待受諾成立の登録は個人組織を作らず pending も continuation も残さない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $email = 'invited-conflict@example.com';
+    [, $token] = OrganizationInvitation::factory()
+        ->forOrganization($organization)
+        ->createWithPlainToken(['email' => $email]);
+
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => '招待 太郎',
+        'email' => $email,
+        'password' => 'CorrectHorse9Battery',
+        'terms_accepted' => '1',
+        'intended_plan' => 'starter',
+    ]);
+
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::query()->whereBlind('email', 'email_index', $email)->firstOrFail();
+
+    // 招待組織へ参加し、個人組織は作られない
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
+    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
+
+    // pending は forget され org key は一切作られない
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
+
+    // continuation を張らない
+    $response->assertSessionMissing('verify_continue_organization_id');
+});
diff --git a/tests/Feature/Auth/RegisterVerifyFlowTest.php b/tests/Feature/Auth/RegisterVerifyFlowTest.php
new file mode 100644
index 0000000..c2a7303
--- /dev/null
+++ b/tests/Feature/Auth/RegisterVerifyFlowTest.php
@@ -0,0 +1,115 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Notification;
+use Illuminate\Support\Facades\URL;
+use Inertia\Testing\AssertableInertia;
+
+/**
+ * P7: メール/パスワード登録 → verification.notice ソフトゲート。
+ *
+ * EmailVerificationContinuation が session に personal org id を保持し、
+ * /email/verify の二次 CTA (continueUrl) と verify 完了後の checkout 復帰を支える。
+ * session 値は membership 確認 (relation 経由 fetch) を通らない限り URL 化されない。
+ */
+beforeEach(function (): void {
+    Http::fake(['https://api.pwnedpasswords.com/range/*' => Http::response('', 200)]);
+
+    $this->validPayload = fn (array $overrides = []): array => array_merge([
+        'name' => 'Verify Flow Tester',
+        'email' => 'verify-flow-'.uniqid().'@example.com',
+        'password' => 'CorrectHorse9Battery',
+        'terms_accepted' => '1',
+    ], $overrides);
+
+    // Fortify 標準 verify controller が踏む signed URL を再現する。
+    $this->verificationUrlFor = fn (User $user): string => URL::temporarySignedRoute(
+        'verification.verify',
+        now()->addMinutes(60),
+        ['id' => $user->getKey(), 'hash' => sha1($user->getEmailForVerification())],
+    );
+});
+
+test('登録 POST は verification.notice へ redirect し personal org id を session に保持する', function (): void {
+    Notification::fake();
+    $payload = ($this->validPayload)();
+
+    $response = $this->post('/register', $payload);
+
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
+});
+
+test('登録後の /email/verify GET は continueUrl に onboarding.checkout を返す', function (): void {
+    Notification::fake();
+    $payload = ($this->validPayload)();
+    $this->post('/register', $payload);
+
+    $this->get('/email/verify')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/VerifyEmail')
+            ->where('continueUrl', route('onboarding.checkout')));
+});
+
+test('continuation なしで /email/verify を直接開くと continueUrl は null', function (): void {
+    $user = User::factory()->unverified()->create();
+
+    $this->actingAs($user)
+        ->get('/email/verify')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->component('Auth/VerifyEmail')
+            ->where('continueUrl', null));
+});
+
+test('session に他人の organization id が混入しても continueUrl は null (membership 確認)', function (): void {
+    $otherOrg = Organization::factory()->create();
+    $user = User::factory()->unverified()->create();
+
+    $this->actingAs($user)
+        ->withSession(['verify_continue_organization_id' => $otherOrg->id])
+        ->get('/email/verify')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
+});
+
+test('session 値が int でない場合は continueUrl は null (値汚染防御)', function (): void {
+    $user = User::factory()->unverified()->create();
+
+    $this->actingAs($user)
+        ->withSession(['verify_continue_organization_id' => 'not-an-int'])
+        ->get('/email/verify')
+        ->assertOk()
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('continueUrl', null));
+});
+
+test('verify 完了で onboarding.checkout へ redirect し continuation が消える', function (): void {
+    Notification::fake();
+    $payload = ($this->validPayload)();
+    $this->post('/register', $payload);
+
+    $user = User::query()->whereBlind('email', 'email_index', $payload['email'])->firstOrFail();
+
+    $response = $this->get(($this->verificationUrlFor)($user));
+
+    $response->assertRedirect(route('onboarding.checkout'));
+    $response->assertSessionMissing('verify_continue_organization_id');
+    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
+});
+
+test('continuation なしの verify 完了は Fortify 既定と同値 (/dashboard?verified=1)', function (): void {
+    $user = User::factory()->unverified()->create();
+
+    $response = $this->actingAs($user)->get(($this->verificationUrlFor)($user));
+
+    $response->assertRedirect(config()->string('fortify.home').'?verified=1');
+    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
+});
diff --git a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
index d6e48b4..4cd8526 100644
--- a/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
+++ b/tests/Feature/Auth/RegistrationInvitationPrefillTest.php
@@ -6,6 +6,7 @@
 use App\Models\OrganizationInvitation;
 use App\Models\User;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Onboarding\IntendedPlanResolver;
 use App\Services\Organization\OrganizationMembershipService;
 use Illuminate\Session\ArraySessionHandler;
 use Illuminate\Session\Store as SessionStore;
@@ -183,4 +184,33 @@ function makeInvitationWithToken(string $email = 'invitee@example.com'): array
 
     // session の invitation_token は登録確定で forget されている
     $response->assertSessionMissing('invitation_token');
+
+    // P7: 個人組織 fallback 分岐では継続導線を張り、plan 意図なしなら org key は作らない
+    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+});
+
+test('P7: 招待受諾成立の登録では pending を消費せず継続導線も張らない', function (): void {
+    [, $token, $email, $organization] = makeInvitationWithToken('accepted-invitee@example.com');
+
+    session([IntendedPlanResolver::PENDING_KEY => 'standard']);
+
+    $response = $this->withSession(['invitation_token' => $token])->post('/register', [
+        'name' => '招待 花子',
+        'email' => $email,
+        'password' => 'SecurePass1234',
+        'terms_accepted' => '1',
+        'intended_plan' => 'starter',
+    ]);
+
+    $response->assertRedirect(route('verification.notice'));
+
+    $user = User::whereBlind('email', 'email_index', $email)->firstOrFail();
+    expect($organization->users()->whereKey($user->getKey())->exists())->toBeTrue();
+    expect($user->organizations()->where('is_personal', true)->exists())->toBeFalse();
+
+    // pending は forget され、招待組織の org key は作られない (promote 対象が存在しない)
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
+    $response->assertSessionMissing('verify_continue_organization_id');
 });
diff --git a/tests/Feature/Auth/RegistrationTest.php b/tests/Feature/Auth/RegistrationTest.php
index 2ad8013..a3335d3 100644
--- a/tests/Feature/Auth/RegistrationTest.php
+++ b/tests/Feature/Auth/RegistrationTest.php
@@ -4,6 +4,7 @@
 
 use App\Models\User;
 use App\Services\Billing\TicketLedgerService;
+use App\Services\Onboarding\IntendedPlanResolver;
 use Illuminate\Http\Client\Request;
 use Illuminate\Support\Facades\Http;
 
@@ -31,6 +32,11 @@
 
     // [分岐 B 固定] 通常登録では現在組織が個人組織に確定する (招待成立分岐と排他)
     expect($user->current_organization_id)->toBe($personalOrg->id);
+
+    // P7: plan 意図なしの登録では org-scoped key を作らない。verify 継続導線 (組織 id) は張る。
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBeNull();
+    expect(session('verify_continue_organization_id'))->toBe($personalOrg->id);
 });
 
 test('登録 POST は非本番で api.pwnedpasswords.com を呼ばない (F-4-01 非退行)', function (): void {
diff --git a/tests/Feature/Auth/SocialAuthTest.php b/tests/Feature/Auth/SocialAuthTest.php
index 9453dd2..f4accc1 100644
--- a/tests/Feature/Auth/SocialAuthTest.php
+++ b/tests/Feature/Auth/SocialAuthTest.php
@@ -4,6 +4,7 @@
 
 use App\Models\SocialAccount;
 use App\Models\User;
+use App\Services\Onboarding\IntendedPlanResolver;
 use Laravel\Socialite\Contracts\Provider;
 use Laravel\Socialite\Contracts\User as SocialiteUserContract;
 use Laravel\Socialite\Facades\Socialite;
@@ -97,6 +98,86 @@ function fakeSocialiteCallback(SocialiteUserContract $user): void
     $this->assertAuthenticatedAs($user);
 });
 
+test('P7: SSO register 開始は ?plan= を pending に積む (allowlist 照合済み)', function (): void {
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+
+    $this->get('/auth/google/redirect/register?terms_accepted=1&plan=standard')
+        ->assertRedirect('https://accounts.google.com/oauth');
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
+});
+
+test('P7: SSO register 開始で plan 不在・Enterprise・未知値は stale pending を消す', function (string $query): void {
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+
+    session([IntendedPlanResolver::PENDING_KEY => 'business']);
+
+    $this->get('/auth/google/redirect/register?terms_accepted=1'.$query);
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+})->with([
+    'plan 不在' => [''],
+    'enterprise' => ['&plan=enterprise'],
+    '未知値' => ['&plan=foo'],
+]);
+
+test('P7: SSO login 開始は pending を forget する', function (): void {
+    $driver = Mockery::mock(Provider::class);
+    $driver->shouldReceive('redirect')->andReturn(redirect('https://accounts.google.com/oauth'));
+    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);
+
+    session([IntendedPlanResolver::PENDING_KEY => 'standard']);
+
+    $this->get('/auth/google/redirect/login');
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+});
+
+test('P7: SSO register 成立で pending が個人組織へ promote される', function (): void {
+    $this->withSession([
+        'social_auth_intent' => 'register',
+        IntendedPlanResolver::PENDING_KEY => 'standard',
+    ]);
+    fakeSocialiteCallback(fakeSocialiteUser('g-p7', 'sso-plan@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('dashboard'));
+
+    $user = User::whereBlind('email', 'email_index', 'sso-plan@example.com')->firstOrFail();
+    $personalOrg = $user->organizations()->where('is_personal', true)->firstOrFail();
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    expect(session(IntendedPlanResolver::orgKey($personalOrg)))->toBe('standard');
+});
+
+test('P7: SSO register 拒否分岐 (email 衝突) は pending を残さない', function (): void {
+    User::factory()->create(['email' => 'victim-p7@example.com']);
+    $this->withSession([
+        'social_auth_intent' => 'register',
+        IntendedPlanResolver::PENDING_KEY => 'standard',
+    ]);
+    fakeSocialiteCallback(fakeSocialiteUser('g-p7-reject', 'victim-p7@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('register'));
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+});
+
+test('P7: SSO login の未連携拒否分岐も pending を残さない', function (): void {
+    $this->withSession([
+        'social_auth_intent' => 'login',
+        IntendedPlanResolver::PENDING_KEY => 'standard',
+    ]);
+    fakeSocialiteCallback(fakeSocialiteUser('g-p7-login', 'unknown-p7@example.com'));
+
+    $this->get('/auth/google/callback')->assertRedirect(route('login'));
+
+    expect(session(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+});
+
 test('無効なプロバイダは 404', function (): void {
     $this->get('/auth/unknown/redirect/login')->assertNotFound();
 });
diff --git a/tests/Feature/Billing/GateInversionF07RegressionTest.php b/tests/Feature/Billing/GateInversionF07RegressionTest.php
index f6848c9..6552a00 100644
--- a/tests/Feature/Billing/GateInversionF07RegressionTest.php
+++ b/tests/Feature/Billing/GateInversionF07RegressionTest.php
@@ -84,8 +84,10 @@ function gateMemberOf(Organization $organization): User
         ->assertOk()
         ->assertInertia(fn ($page) => $page->component('Onboarding/Checkout'));
 
+    // P7: 遮断時に元 path (/projects) が org-scoped session に積まれているため、
+    // 有効化の成功着地は dashboard ではなく「やりたかった画面」へ復帰する。
     $this->actingAs($owner)->post(route('onboarding.activate-personal'), ['declaration' => true])
-        ->assertRedirect(route('dashboard'));
+        ->assertRedirect('/projects');
 
     // 閉路が閉じている。
     //
diff --git a/tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php b/tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php
new file mode 100644
index 0000000..0b61d73
--- /dev/null
+++ b/tests/Feature/Onboarding/OnboardingCheckoutPlanHandoffTest.php
@@ -0,0 +1,103 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Onboarding\IntendedPlanResolver;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * P7: Onboarding/Checkout の ?plan= canonical 303 + session 反映 + リロード耐性。
+ *
+ * `?plan=` はユーザー入力のため PlanCode allowlist に照合し、未知値・Enterprise は
+ * 安全側 (= 意図なし) に倒す。org-scoped key は「不在は no-op」= リロードで消えない。
+ */
+
+/** 未契約 (free_plan_code NULL) 組織 + manageBilling 保持 owner。 */
+function unsubscribedOrgWithBillingOwner(): array
+{
+    return createOrganizationWithOwner(grandfatherFreePlan: false);
+}
+
+test('?plan=standard は org-scoped session に積んで canonical URL へ 303 する', function (): void {
+    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
+
+    $response = $this->actingAs($owner)->get('/onboarding/checkout?plan=standard');
+
+    $response->assertStatus(303);
+    $response->assertRedirect(route('onboarding.checkout'));
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBe('standard');
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Onboarding/Checkout')
+            ->where('pageData.intendedPlanCode', 'standard'));
+});
+
+test('plan なしのリロードでは preselect が消えない (org-scoped no-op 規約)', function (): void {
+    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
+    session([IntendedPlanResolver::orgKey($organization) => 'starter']);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'starter'));
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'starter'));
+
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBe('starter');
+});
+
+test('?plan=enterprise は preselect されず org-scoped session も消える', function (): void {
+    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
+    session([IntendedPlanResolver::orgKey($organization) => 'standard']);
+
+    $this->actingAs($owner)->get('/onboarding/checkout?plan=enterprise')
+        ->assertStatus(303);
+
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', null));
+});
+
+test('?plan=foo (未知値) も 303 のうえ session を消す', function (): void {
+    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
+    session([IntendedPlanResolver::orgKey($organization) => 'standard']);
+
+    $this->actingAs($owner)->get('/onboarding/checkout?plan=foo')
+        ->assertStatus(303);
+
+    expect(session(IntendedPlanResolver::orgKey($organization)))->toBeNull();
+});
+
+test('org-scoped session は組織ごとに独立している (A の意図が B に漏れない)', function (): void {
+    [$orgA, $owner] = unsubscribedOrgWithBillingOwner();
+    $orgB = Organization::factory()->create();
+    session([IntendedPlanResolver::orgKey($orgA) => 'standard']);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', 'standard'));
+
+    expect(session(IntendedPlanResolver::orgKey($orgB)))->toBeNull();
+});
+
+test('session が改ざんされ enterprise が入っていても peek が null 化する (防御)', function (): void {
+    [$organization, $owner] = unsubscribedOrgWithBillingOwner();
+    session([IntendedPlanResolver::orgKey($organization) => 'enterprise']);
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertInertia(fn (Assert $page) => $page->where('pageData.intendedPlanCode', null));
+});
+
+test('intended plan なしの通常描画では intendedPlanCode が null', function (): void {
+    [, $owner] = unsubscribedOrgWithBillingOwner();
+
+    $this->actingAs($owner)->get('/onboarding/checkout')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Onboarding/Checkout')
+            ->where('pageData.intendedPlanCode', null));
+});
diff --git a/tests/Feature/Onboarding/OnboardingReturnFlowTest.php b/tests/Feature/Onboarding/OnboardingReturnFlowTest.php
new file mode 100644
index 0000000..8165a8e
--- /dev/null
+++ b/tests/Feature/Onboarding/OnboardingReturnFlowTest.php
@@ -0,0 +1,116 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Services\Onboarding\OnboardingReturnResolver;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * P7: 課金ゲートで失われる「意図先 destination」の往復。
+ *
+ * - 遮断時に return_to を積むのは manageBilling 保持者の安全メソッド (GET/HEAD) かつ
+ *   非 XHR のときだけ (POST / JSON は元 path 復元に意味がない)。
+ * - 復帰は Personal 有効化 (activate-personal) の成功着地 / 有料経路は billing.index の
+ *   continueUrl prop。どちらも 1 回限りで消費する (リロードで CTA が残らない)。
+ * - 保存値は OnboardingReturnResolver::normalizePath を通った same-origin 内部 path のみ。
+ */
+
+test('gate 遮断 (manageBilling 保持 + GET) で元 path が return_to に積まれる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)->get('/projects')
+        ->assertRedirect(route('onboarding.checkout'));
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
+});
+
+test('gate 遮断が XHR (expectsJson) の場合は 402 で return_to を積まない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->getJson('/projects')
+        ->assertStatus(402);
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
+});
+
+test('gate 遮断が POST の場合は return_to を積まない (意図遷移ではない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->post('/projects', ['name' => 'ダミー'])
+        ->assertRedirect(route('onboarding.checkout'));
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
+});
+
+test('manageBilling を持たない member の遮断では return_to を積まない', function (): void {
+    [$organization] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+
+    $this->actingAs($member)->get('/projects')
+        ->assertRedirect(route('onboarding.billing-required'));
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
+});
+
+test('activate-personal 成功で元 path へ復帰し return_to は消費される (2 回目は dashboard)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    // 遮断 → return_to が積まれる
+    $this->actingAs($owner)->get('/projects');
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => true])
+        ->assertRedirect('/projects');
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBeNull();
+});
+
+test('return_to なしの activate-personal 成功は dashboard へ (既定の非退行)', function (): void {
+    [, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+
+    $this->actingAs($owner)
+        ->post('/onboarding/activate-personal', ['declaration' => true])
+        ->assertRedirect(route('dashboard'));
+});
+
+test('billing.index は契約成立時に continueUrl を 1 回だけ出す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
+    session([OnboardingReturnResolver::orgKey($organization) => '/projects']);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Billing/Index')
+            ->where('continueUrl', '/projects'));
+
+    // 1 回限り: リロードでは CTA が残らない
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+});
+
+test('billing.index は未契約 (grantsAccess 不成立) では continueUrl を出さず return_to も消さない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    session([OnboardingReturnResolver::orgKey($organization) => '/projects']);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+
+    expect(session(OnboardingReturnResolver::orgKey($organization)))->toBe('/projects');
+});
+
+test('改ざんされた return_to (外部 URL) は continueUrl に出ない (open-redirect 防御)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner(grandfatherFreePlan: false);
+    contractPaidPlan($organization);
+    session([OnboardingReturnResolver::orgKey($organization) => 'https://evil.example/x']);
+
+    $this->actingAs($owner)->get('/billing')
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page->where('continueUrl', null));
+});
diff --git a/tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php b/tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php
new file mode 100644
index 0000000..fac1f5e
--- /dev/null
+++ b/tests/Unit/Services/Onboarding/IntendedPlanResolverTest.php
@@ -0,0 +1,299 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\PlanCode;
+use App\Models\Organization;
+use App\Services\Onboarding\IntendedPlanResolver;
+use Illuminate\Contracts\Session\Session;
+use Illuminate\Http\Request;
+
+/**
+ * P7: 料金表 → 登録 → Onboarding/Checkout の plan 引き継ぎ用 Resolver の Unit テスト。
+ *
+ * - pending 系の「常に書き換える」規約 (= stale 防止)
+ * - org-scoped 系の「不在は no-op」規約 (= リロード耐性)
+ * - promote (pending → org-scoped)
+ * - normalize の Enterprise 除外 (Personal は普通の契約フローに露出する選択肢のため受理) + trim/strtolower
+ */
+function intendedPlanResolverWithRequest(Request $request): array
+{
+    $session = $request->session();
+    /** @var Session $session */
+    $resolver = new IntendedPlanResolver($session);
+
+    return ['resolver' => $resolver, 'session' => $session, 'request' => $request];
+}
+
+function intendedPlanRequestWithQuery(array $query): Request
+{
+    $request = Request::create('/test', 'GET', $query);
+    $request->setLaravelSession(app('session.store'));
+
+    return $request;
+}
+
+beforeEach(function (): void {
+    app('session.store')->flush();
+});
+
+describe('normalizeRaw', function (): void {
+    it('normalizes starter/standard/business to PlanCode', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw('starter'))->toBe(PlanCode::Starter);
+        expect(IntendedPlanResolver::normalizeRaw('standard'))->toBe(PlanCode::Standard);
+        expect(IntendedPlanResolver::normalizeRaw('business'))->toBe(PlanCode::Business);
+    });
+
+    it('returns null for enterprise (excluded from contract flow)', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw('enterprise'))->toBeNull();
+    });
+
+    it('normalizes personal (普通の契約フローに露出する選択肢のため受理)', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw('personal'))->toBe(PlanCode::Personal);
+    });
+
+    it('returns null for invalid string', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw('foo'))->toBeNull();
+        expect(IntendedPlanResolver::normalizeRaw(''))->toBeNull();
+    });
+
+    it('handles uppercase and surrounding whitespace', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw('STANDARD'))->toBe(PlanCode::Standard);
+        expect(IntendedPlanResolver::normalizeRaw(' Starter '))->toBe(PlanCode::Starter);
+        expect(IntendedPlanResolver::normalizeRaw('  Business'))->toBe(PlanCode::Business);
+    });
+
+    it('returns null for non-string values', function (): void {
+        expect(IntendedPlanResolver::normalizeRaw(null))->toBeNull();
+        expect(IntendedPlanResolver::normalizeRaw(123))->toBeNull();
+        expect(IntendedPlanResolver::normalizeRaw(['standard']))->toBeNull();
+        expect(IntendedPlanResolver::normalizeRaw(false))->toBeNull();
+    });
+});
+
+describe('rememberPendingFromQuery (always-overwrite contract)', function (): void {
+    it('puts pending when ?plan=standard', function (): void {
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
+        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);
+
+        expect($ctx['session']->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
+    });
+
+    it('forgets pending when ?plan=enterprise', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'enterprise']));
+        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets pending when ?plan=foo (invalid)', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'foo']));
+        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets pending when ?plan= empty string', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => '']));
+        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets stale pending when ?plan key is absent (fresh-state start)', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery([]));
+        $ctx['resolver']->rememberPendingFromQuery($ctx['request']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+});
+
+describe('rememberPendingFromForm (always-overwrite contract)', function (): void {
+    it('puts pending when intended_plan=standard', function (): void {
+        $session = app('session.store');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->rememberPendingFromForm(['intended_plan' => 'standard']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
+    });
+
+    it('forgets stale pending when intended_plan key is absent', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->rememberPendingFromForm([]);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets pending on explicit null', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->rememberPendingFromForm(['intended_plan' => null]);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets pending on tampered array value', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->rememberPendingFromForm(['intended_plan' => ['standard']]);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('forgets pending on enterprise', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'business');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->rememberPendingFromForm(['intended_plan' => 'enterprise']);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+});
+
+describe('peekPending / forgetPending', function (): void {
+    it('peekPending reads without consuming', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
+        $resolver = new IntendedPlanResolver($session);
+
+        expect($resolver->peekPending())->toBe(PlanCode::Standard);
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBe('standard');
+    });
+
+    it('peekPending returns null when key absent', function (): void {
+        $resolver = new IntendedPlanResolver(app('session.store'));
+
+        expect($resolver->peekPending())->toBeNull();
+    });
+
+    it('forgetPending clears the key', function (): void {
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->forgetPending();
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+});
+
+describe('rememberForOrganizationFromQuery (no-op on absence)', function (): void {
+    it('puts org-scoped on ?plan=standard', function (): void {
+        $org = Organization::factory()->create();
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
+        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);
+
+        expect($ctx['session']->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
+    });
+
+    it('preserves session when ?plan absent (reload resilience)', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::orgKey($org), 'standard');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery([]));
+        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);
+
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
+    });
+
+    it('forgets org-scoped on enterprise', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::orgKey($org), 'standard');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'enterprise']));
+        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);
+
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
+    });
+
+    it('forgets org-scoped on invalid plan', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::orgKey($org), 'standard');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'foo']));
+        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $org);
+
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
+    });
+
+    it('isolates org keys (write to A does not affect B)', function (): void {
+        $orgA = Organization::factory()->create();
+        $orgB = Organization::factory()->create();
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::orgKey($orgB), 'business');
+
+        $ctx = intendedPlanResolverWithRequest(intendedPlanRequestWithQuery(['plan' => 'standard']));
+        $ctx['resolver']->rememberForOrganizationFromQuery($ctx['request'], $orgA);
+
+        expect($session->get(IntendedPlanResolver::orgKey($orgA)))->toBe('standard');
+        expect($session->get(IntendedPlanResolver::orgKey($orgB)))->toBe('business');
+    });
+
+    it('orgKey has the documented shape', function (): void {
+        $org = Organization::factory()->create();
+
+        expect(IntendedPlanResolver::orgKey($org))->toBe("onboarding.intended_plan.org.{$org->id}");
+    });
+});
+
+describe('promotePendingToOrganization', function (): void {
+    it('moves pending value to org-scoped and clears pending', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'standard');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->promotePendingToOrganization($org);
+
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBe('standard');
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('no-ops when pending is absent (org key untouched)', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->promotePendingToOrganization($org);
+
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+    });
+
+    it('clears pending even when value is enterprise (no promote to org)', function (): void {
+        $org = Organization::factory()->create();
+        $session = app('session.store');
+        // 直接 enterprise を session に入れた (= 通常 Resolver 経由では起こらないが防御確認)
+        $session->put(IntendedPlanResolver::PENDING_KEY, 'enterprise');
+        $resolver = new IntendedPlanResolver($session);
+
+        $resolver->promotePendingToOrganization($org);
+
+        expect($session->get(IntendedPlanResolver::PENDING_KEY))->toBeNull();
+        expect($session->get(IntendedPlanResolver::orgKey($org)))->toBeNull();
+    });
+});
diff --git a/tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php b/tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php
new file mode 100644
index 0000000..74d31f5
--- /dev/null
+++ b/tests/Unit/Services/Onboarding/OnboardingReturnResolverTest.php
@@ -0,0 +1,144 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Services\Onboarding\OnboardingReturnResolver;
+use Illuminate\Contracts\Session\Session;
+
+/**
+ * P7: onboarding gate の意図先 path 保持 Resolver の Unit テスト。
+ * IntendedPlanResolverTest と同型。open-redirect 防御 (normalizePath) を網羅する。
+ */
+function onboardingReturnResolverContext(): array
+{
+    /** @var Session $session */
+    $session = app('session.store');
+    $resolver = new OnboardingReturnResolver($session);
+    $org = Organization::factory()->make(['id' => 42]);
+
+    return ['resolver' => $resolver, 'session' => $session, 'org' => $org];
+}
+
+beforeEach(function (): void {
+    app('session.store')->flush();
+});
+
+describe('normalizePath (open-redirect 防御)', function (): void {
+    it('allows same-origin internal paths', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('/projects/foo/manuals'))
+            ->toBe('/projects/foo/manuals');
+        expect(OnboardingReturnResolver::normalizePath('/ok'))->toBe('/ok');
+    });
+
+    it('keeps query but drops fragment', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('/ok?a=1'))->toBe('/ok?a=1');
+        expect(OnboardingReturnResolver::normalizePath('/path#frag'))->toBe('/path');
+        expect(OnboardingReturnResolver::normalizePath('/path?a=1#frag'))->toBe('/path?a=1');
+    });
+
+    it('rejects absolute URLs', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('http://x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('https://x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('https://evil.com/projects/foo'))->toBeNull();
+    });
+
+    it('rejects protocol-relative URLs', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('//x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('//evil.com/path'))->toBeNull();
+    });
+
+    it('rejects backslash variants', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('\\x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('/\\x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('\\\\host'))->toBeNull();
+    });
+
+    it('rejects encoded protocol-relative / backslash evasion', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('/%2F%2Fx'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('%2f%2fx'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('/%5Cx'))->toBeNull();
+    });
+
+    it('rejects scheme like javascript:', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('javascript:alert(1)'))->toBeNull();
+    });
+
+    it('rejects user:pass@host and port-bearing values', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('https://user:pass@host/p'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('http://host:8080/p'))->toBeNull();
+    });
+
+    it('rejects relative (no leading slash) paths', function (): void {
+        expect(OnboardingReturnResolver::normalizePath('foo'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('foo/bar'))->toBeNull();
+    });
+
+    it('rejects embedded control characters (header injection defense)', function (): void {
+        expect(OnboardingReturnResolver::normalizePath("/ok\nSet-Cookie: x"))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath("/ok\ttab"))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath("/ok\x00null"))->toBeNull();
+    });
+
+    it('rejects percent-encoded control characters (decoded check)', function (): void {
+        // %0a (LF) / %0d (CR) / %09 (TAB) / %00 (NUL) は rawurldecode 後に制御文字となるため
+        // header/解釈差注入を防ぐべく拒否する。
+        expect(OnboardingReturnResolver::normalizePath('/ok%0aSet-Cookie:%20x'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('/ok%0d%0a'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('/ok%09'))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('/ok%00'))->toBeNull();
+    });
+
+    it('rejects empty and non-string', function (): void {
+        expect(OnboardingReturnResolver::normalizePath(''))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath('   '))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath(null))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath(['/x']))->toBeNull();
+        expect(OnboardingReturnResolver::normalizePath(123))->toBeNull();
+    });
+});
+
+describe('remember / peek / forget', function (): void {
+    it('remembers and peeks a valid path', function (): void {
+        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();
+
+        $resolver->rememberForOrganization($org, '/projects/foo/manuals');
+
+        expect($resolver->peekForOrganization($org))->toBe('/projects/foo/manuals');
+    });
+
+    it('is a no-op for invalid paths (does not clobber existing value)', function (): void {
+        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();
+
+        $resolver->rememberForOrganization($org, '/ok');
+        $resolver->rememberForOrganization($org, 'https://evil.com'); // 不正 → no-op
+
+        expect($resolver->peekForOrganization($org))->toBe('/ok');
+    });
+
+    it('forget consumes the value', function (): void {
+        ['resolver' => $resolver, 'org' => $org] = onboardingReturnResolverContext();
+
+        $resolver->rememberForOrganization($org, '/ok');
+        $resolver->forgetForOrganization($org);
+
+        expect($resolver->peekForOrganization($org))->toBeNull();
+    });
+
+    it('peek re-normalizes a tampered session value (defense in depth)', function (): void {
+        ['resolver' => $resolver, 'session' => $session, 'org' => $org] = onboardingReturnResolverContext();
+
+        // session に直接改ざん値が入っていても peek が normalizePath で再検証して弾く。
+        $session->put(OnboardingReturnResolver::orgKey($org), 'https://evil.com/x');
+
+        expect($resolver->peekForOrganization($org))->toBeNull();
+    });
+
+    it('orgKey is scoped per organization id', function (): void {
+        $orgA = Organization::factory()->make(['id' => 1]);
+        $orgB = Organization::factory()->make(['id' => 2]);
+
+        expect(OnboardingReturnResolver::orgKey($orgA))->toBe('onboarding.return_to.org.1');
+        expect(OnboardingReturnResolver::orgKey($orgA))->not->toBe(OnboardingReturnResolver::orgKey($orgB));
+    });
+});
diff --git a/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php b/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php
new file mode 100644
index 0000000..de3195d
--- /dev/null
+++ b/tests/Unit/Support/Auth/EmailVerificationContinuationTest.php
@@ -0,0 +1,89 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Organization;
+use App\Models\User;
+use App\Support\Auth\EmailVerificationContinuation;
+use Illuminate\Contracts\Session\Session;
+
+/**
+ * P7: 登録 → verify notice ソフトゲートの継続導線 (session に org id のみ保持) の Unit テスト。
+ *
+ * URL を直保持せず、参照時に membership 確認 → 引数なし route('onboarding.checkout') を再構築する
+ * (IDOR 防御 = セキュリティ不変条件 #2 / #3)。
+ */
+beforeEach(function (): void {
+    app('session.store')->flush();
+});
+
+it('remember → resolveUrl が引数なしの onboarding.checkout URL を返す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    EmailVerificationContinuation::remember($session, $organization->id);
+
+    expect(EmailVerificationContinuation::resolveUrl($owner, $session))
+        ->toBe(route('onboarding.checkout'));
+});
+
+it('他組織の id を session に注入しても null (membership 確認)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    $otherOrg = Organization::factory()->create();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    EmailVerificationContinuation::remember($session, $otherOrg->id);
+
+    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
+});
+
+it('session 値が int でなければ null (値汚染防御)', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    /** @var Session $session */
+    $session = app('session.store');
+    $session->put('verify_continue_organization_id', 'not-an-int');
+
+    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
+});
+
+it('user が null なら null', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    EmailVerificationContinuation::remember($session, $organization->id);
+
+    expect(EmailVerificationContinuation::resolveUrl(null, $session))->toBeNull();
+});
+
+it('session key 不在なら null', function (): void {
+    [, $owner] = createOrganizationWithOwner();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
+});
+
+it('forget 後は null (寿命 = remember → forget)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    EmailVerificationContinuation::remember($session, $organization->id);
+    EmailVerificationContinuation::forget($session);
+
+    expect(EmailVerificationContinuation::resolveUrl($owner, $session))->toBeNull();
+});
+
+it('非メンバーの user では null (cross-org read 不可)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $stranger = User::factory()->create();
+    /** @var Session $session */
+    $session = app('session.store');
+
+    EmailVerificationContinuation::remember($session, $organization->id);
+
+    expect(EmailVerificationContinuation::resolveUrl($stranger, $session))->toBeNull();
+});
diff --git a/tests/js/pages/OnboardingCheckout.test.ts b/tests/js/pages/OnboardingCheckout.test.ts
index 2a5b0d4..fb72df0 100644
--- a/tests/js/pages/OnboardingCheckout.test.ts
+++ b/tests/js/pages/OnboardingCheckout.test.ts
@@ -39,6 +39,7 @@ const basePageData: OnboardingCheckoutShape = {
     contactUrl: "/contact?source=onboarding",
     personalEligibility: { eligible: true, reason: null, reasonLabel: null },
     signupGrantTickets: 10,
+    intendedPlanCode: null,
 };
 
 afterEach(() => {
@@ -87,6 +88,19 @@ describe("Onboarding/Checkout", () => {
         expect(screen.getByTestId("plan-card-starter")).not.toHaveClass("border-primary");
     });
 
+    it("intendedPlanCode が plans にあるときは defaultPlanCode より優先して強調する (P7)", () => {
+        renderPage({ intendedPlanCode: "standard" });
+
+        expect(screen.getByTestId("plan-card-standard")).toHaveClass("border-primary");
+        expect(screen.getByTestId("plan-card-starter")).not.toHaveClass("border-primary");
+    });
+
+    it("intendedPlanCode が plans に無いときは defaultPlanCode に戻る (P7)", () => {
+        renderPage({ intendedPlanCode: "business" });
+
+        expect(screen.getByTestId("plan-card-starter")).toHaveClass("border-primary");
+    });
+
     it("月次付与は廃止済のため「月 N 枚」表記を出さない (D28)", async () => {
         renderPage();
         await choosePersonal();
diff --git a/tests/js/pages/Pricing.test.ts b/tests/js/pages/Pricing.test.ts
index 4be7613..e61419c 100644
--- a/tests/js/pages/Pricing.test.ts
+++ b/tests/js/pages/Pricing.test.ts
@@ -95,7 +95,16 @@ describe("Pricing", () => {
 
     it("未認証は登録 CTA、認証済みはプラン変更 CTA を出す", () => {
         const { unmount } = render(Pricing, { props: { page: basePage } });
-        expect(screen.getAllByRole("link", { name: "このプランで始める" })).toHaveLength(2);
+        const ctas = screen.getAllByRole("link", { name: "このプランで始める" });
+        expect(ctas).toHaveLength(2);
+        // P7: 料金表 → /register?plan={code} で選択意図を handoff する
+        expect(ctas.map((cta) => new URL((cta as HTMLAnchorElement).href).search)).toEqual([
+            "?plan=free",
+            "?plan=standard",
+        ]);
+        for (const cta of ctas) {
+            expect(new URL((cta as HTMLAnchorElement).href).pathname).toBe("/register");
+        }
         unmount();
 
         render(Pricing, {
diff --git a/tests/js/pages/Register.test.ts b/tests/js/pages/Register.test.ts
index bbbbf21..caae936 100644
--- a/tests/js/pages/Register.test.ts
+++ b/tests/js/pages/Register.test.ts
@@ -50,6 +50,32 @@ describe("Auth/Register", () => {
         expect(screen.queryByText("利用規約への同意が必要です。")).toBeNull();
     });
 
+    it("P7: intendedPlan があると SSO リンクに plan を伝播する (同意チェック後のみ)", async () => {
+        render(Register, {
+            props: { appName: "My App", socialProviders: ["google"], intendedPlan: "standard" },
+        });
+
+        const ssoLink = screen.getByTestId("sso-register-google");
+        // 同意前は同意フローを優先 (plan も付けない = 既存挙動を変えない)
+        expect(ssoLink.getAttribute("href")).toBe("/auth/google/redirect/register");
+
+        await fireEvent.click(screen.getByTestId("terms-checkbox"));
+
+        expect(ssoLink.getAttribute("href")).toBe(
+            "/auth/google/redirect/register?terms_accepted=1&plan=standard",
+        );
+    });
+
+    it("P7: intendedPlan が null なら SSO リンクに plan を付けない", async () => {
+        render(Register, { props: { appName: "My App", socialProviders: ["google"] } });
+
+        await fireEvent.click(screen.getByTestId("terms-checkbox"));
+
+        expect(screen.getByTestId("sso-register-google").getAttribute("href")).toBe(
+            "/auth/google/redirect/register?terms_accepted=1",
+        );
+    });
+
     it("invitationEmail props あり → email 欄が readonly で招待 email を prefill し補足文言を表示する", () => {
         render(Register, {
             props: {
diff --git a/tests/js/pages/VerifyEmail.test.ts b/tests/js/pages/VerifyEmail.test.ts
new file mode 100644
index 0000000..0931fd1
--- /dev/null
+++ b/tests/js/pages/VerifyEmail.test.ts
@@ -0,0 +1,40 @@
+import { describe, expect, it, vi } from "vitest";
+import { render, screen } from "@testing-library/svelte";
+import VerifyEmail from "@/pages/Auth/VerifyEmail.svelte";
+
+const { routerVisitMock } = vi.hoisted(() => ({ routerVisitMock: vi.fn() }));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { visit: routerVisitMock, post: vi.fn() },
+}));
+
+/*
+ * メール認証待ち画面 (ソフトゲート)。
+ * continueUrl は「登録由来の継続導線が実在するとき」だけサーバが非 null で渡す。
+ * null のときに二次 CTA を出さない (= 継続先の無いボタンを出さない) ことを固定する。
+ */
+describe("Auth/VerifyEmail", () => {
+    it("continueUrl が null なら二次 CTA を出さない", () => {
+        render(VerifyEmail, { props: { appName: "My App" } });
+
+        expect(screen.queryByTestId("verify-email-continue")).toBeNull();
+        expect(screen.getByRole("button", { name: "認証メールを再送信" })).toBeInTheDocument();
+    });
+
+    it("continueUrl があるとき「あとで認証する」CTA を出す", () => {
+        render(VerifyEmail, {
+            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
+        });
+
+        expect(screen.getByTestId("verify-email-continue")).toBeInTheDocument();
+    });
+
+    it("CTA は disabled にせず押下可能 (DESIGN.md)", () => {
+        const { container } = render(VerifyEmail, {
+            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
+        });
+
+        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
+    });
+});
diff --git a/tests/js/pages/Welcome.test.ts b/tests/js/pages/Welcome.test.ts
index 798309c..2a451ca 100644
--- a/tests/js/pages/Welcome.test.ts
+++ b/tests/js/pages/Welcome.test.ts
@@ -59,6 +59,31 @@ describe("Welcome (LP)", () => {
         );
     });
 
+    it("D16: 「無料で始める」CTA 3 箇所はすべて /pricing を指し LP に /register 直リンクが無い", () => {
+        const { container } = render(Welcome, { props: baseProps });
+
+        const ctas = screen.getAllByRole("link", { name: "無料で始める" });
+        expect(ctas).toHaveLength(3);
+        for (const cta of ctas) {
+            expect(new URL((cta as HTMLAnchorElement).href).pathname).toBe("/pricing");
+        }
+
+        // hero / pricing-cta の個別固定 (testId と section 単位)
+        expect(new URL((screen.getByTestId("hero-register") as HTMLAnchorElement).href).pathname).toBe(
+            "/pricing",
+        );
+        expect(
+            new URL(
+                (within(screen.getByTestId("landing-pricing-cta")).getByRole("link", {
+                    name: "無料で始める",
+                }) as HTMLAnchorElement).href,
+            ).pathname,
+        ).toBe("/pricing");
+
+        // LP から /register への直リンクは 1 本も無い (料金表を必ず経由する)
+        expect(container.querySelectorAll('a[href^="/register"]')).toHaveLength(0);
+    });
+
     it("問い合わせリンクは内部宛先では同タブ、外部宛先では新規タブで開く", () => {
         const { unmount } = render(Welcome, { props: baseProps });
         const internal = screen.getByTestId("landing-contact-link");
```

---

## (C) 参考: 変更されたファイル一覧

```
 app/Actions/Fortify/CreateNewUser.php              |  10 +
 .../Onboarding/OnboardingCheckoutDto.php           |   8 +-
 app/Http/Controllers/Auth/SocialAuthController.php |  29 ++
 app/Http/Controllers/Billing/BillingController.php |  38 +++
 .../Onboarding/ActivatePersonalController.php      |  10 +-
 .../Onboarding/OnboardingController.php            |  13 +
 app/Http/Middleware/RequireActiveSubscription.php  |  16 +-
 app/Http/Responses/Fortify/RegisterResponse.php    |  33 +++
 app/Http/Responses/Fortify/VerifyEmailResponse.php |  44 +++
 app/Providers/FortifyServiceProvider.php           |  24 +-
 app/Services/Onboarding/IntendedPlanResolver.php   | 164 +++++++++++
 .../Onboarding/OnboardingReturnResolver.php        | 106 ++++++++
 app/Support/Auth/EmailVerificationContinuation.php |  58 ++++
 .../coverage-check.php                             |   3 +-
 .../provider-quality-probe.php                     |   5 +-
 resources/js/pages/Auth/Register.svelte            |  20 +-
 resources/js/pages/Auth/VerifyEmail.svelte         |  17 +-
 resources/js/pages/Billing/Index.svelte            |  37 ++-
 resources/js/pages/Onboarding/Checkout.svelte      |  14 +-
 resources/js/pages/Pricing.svelte                  |   4 +-
 resources/js/pages/Welcome.svelte                  |   6 +-
 resources/js/types/Auth.ts                         |  23 ++
 resources/js/types/billing.ts                      |  24 ++
 resources/js/types/onboarding.ts                   |   5 +
 tests/Feature/Auth/RegisterPlanHandoffTest.php     | 197 ++++++++++++++
 tests/Feature/Auth/RegisterVerifyFlowTest.php      | 115 ++++++++
 .../Auth/RegistrationInvitationPrefillTest.php     |  30 +++
 tests/Feature/Auth/RegistrationTest.php            |   6 +
 tests/Feature/Auth/SocialAuthTest.php              |  81 ++++++
 .../Billing/GateInversionF07RegressionTest.php     |   4 +-
 .../OnboardingCheckoutPlanHandoffTest.php          | 103 +++++++
 .../Onboarding/OnboardingReturnFlowTest.php        | 116 ++++++++
 .../Onboarding/IntendedPlanResolverTest.php        | 299 +++++++++++++++++++++
 .../Onboarding/OnboardingReturnResolverTest.php    | 144 ++++++++++
 .../Auth/EmailVerificationContinuationTest.php     |  89 ++++++
 tests/js/pages/OnboardingCheckout.test.ts          |  14 +
 tests/js/pages/Pricing.test.ts                     |  11 +-
 tests/js/pages/Register.test.ts                    |  26 ++
 tests/js/pages/VerifyEmail.test.ts                 |  40 +++
 tests/js/pages/Welcome.test.ts                     |  25 ++
 40 files changed, 1980 insertions(+), 31 deletions(-)
```
