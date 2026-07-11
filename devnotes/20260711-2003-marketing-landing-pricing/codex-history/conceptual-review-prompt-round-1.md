# 使命・禁止事項・思考原則（AGENTS.md より挿入）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件（アプリ都合で緩めない）

1. **tenant キー不信**: ownership/actor/tenant キーを payload から受け取らない
2. **子は親に属する**: nested route の不整合は認可より前に 404
3. **cross-org 不可**: 組織を跨ぐ read/write をしない
4. **untrusted 文字列は UserInput 型経由でのみ prompt に入れる**
5. **権限判定は常に `laratrust_team_id` を明示**
6. **PII は CipherSweet。検索は `whereBlind()`**
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

# system: 役割・タスク

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【補足コンテキスト】
- 本設計は「ドナー参照実装 `/tmp/aigenba`（同族 first-party テンプレ、ユーザーが clone 済）」の LP / 料金表 / スポットチケット購入を AI-CUE へ移植するもの。基本構造・レイアウト・価格値を aigenba に合わせることはユーザーの明示指示。
- AI-CUE は aigenba 由来テンプレの課金基盤（TicketLedgerService の reserve→commit/release + 冪等付与 grantMonthly/grantSignupGrant/grantPurchased + clawback、StripeWebhookProcessor 冪等マシン、TicketVolumePrice + Seeder、config/billing.php signup_grant=10枚/30日・floor=¥50、config/quota.php plans free/standard、Plan/PlanPrice DB snapshot、Cashier Billable=Organization）を既に持つ。grantPurchased は idempotency_key `purchase:{sessionId}` UNIQUE の冪等 insert 済み。StripeWebhookProcessor の checkout.session.completed は現在 no-op の拡張点。
- チケット消費は AI 解析 1 枚 / 動画レンダ 3 枚（doc/10 §10.5）。残高不足は InsufficientTicketsException → XHR 402 (`insufficient_tickets`) / web back+flash。
- 必要に応じて /workspace および /tmp/aigenba のファイルを読んで確認してよい（読み込みのみ）。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

# user: 概念設計

（以下、devnotes/20260711-2003-marketing-landing-pricing/conceptual-design.md の全文）

# 概念設計: marketing-landing-pricing（LP + 料金表 + チケットリチャージ）

作成: 2026-07-11 / 対象: AI-CUE (`/workspace`) / ドナー参照: `/tmp/aigenba`（同族 first-party テンプレ）

## 背景・課題

1. **LP が雛形のまま**: route `home`（`/`）は `HomeController` が SEO メタ（JSON-LD 含む）を供給するが、描画される `Welcome.svelte` は 10 行の「template is running」雛形。AI-CUE の North Star（手順書から思考ゼロ・編集ゼロで標準マニュアル動画）を一切訴求できていない。
2. **料金表が雛形のまま**: `/pricing` は closure route + `Pricing.svelte`（30 行のプレースホルダ 3 カード）。実プラン（`plans` / `plan_prices`）ともチケット価格（`ticket_volume_prices`）とも結線されていない。
3. **チケットのスポット追加購入（リチャージ）経路が無い**: チケットは月次付与（`invoice.paid`）と signup grant のみ。`analyze`（COST_ANALYSIS=1）/ `render`（COST_RENDER=3）で残高不足になると 402 / error flash で**行き止まり**になり、ユーザーが自力で残高を回復する手段がない。
4. ドナー aigenba には同一テンプレ系譜で実証済みの LP / 料金表 / スポット購入（傾斜単価 + 冪等 Checkout + webhook 冪等付与）がある。**基本構造・レイアウト・価格値は aigenba に合わせ**、文言を AI-CUE ドメイン（SOP → AI カット設計 → PWA 撮影 → 自動合成）へ差し替えて移植する（ユーザー指示）。

## 改善アイデア

### 施策群 A: LP（トップ `/`）

- `HomeController` を拡張し、`LandingPageDto`（`app/DataTransferObjects/Marketing/LandingPageDto.php`、aigenba 移植）を Inertia typed array で供給する:
  `cheapestPlanAmountJpy`（有効プラン最安月額）/ `contactUrl` / `contactIsExternal` / `isAuthenticated` / `signupGrantTickets`。
- `Welcome.svelte` を**その場で実 LP に書き換える**（ファイル名・render 先は `Welcome` のまま = HomeController / 既存 SEO・SSR テストの参照を変えない）。構成は aigenba `Guest/Landing.svelte`（435 行）のセクション構造を踏襲:
  1. **Hero**: 「動画マニュアルを、手順書から。」— SOP を渡せば AI がカット設計、スマホでナビ撮影、編集ゼロで完成。右側は撮影ナビ画面の簡易モック（aigenba のチャットモック相当を「カット一覧 + 撮影ガイド」モックに差し替え）。
  2. **課題（3 つの壁）**: 台本作成・撮影判断・編集（AGENTS.md の North Star の 3 ハードルそのまま）。
  3. **3 ステップ（How）**: SOP アップロード → AI がカット設計（動画シナリオ）→ PWA ナビ撮影 → 自動合成（字幕付き完成動画）。
  4. **素材セクション**: 手元の手順書（PDF / Excel）から作れる。
  5. **成果セクション**: 撮る人（ナビに従うだけ）/ 教える人（品質が人に依存しない）/ 管理者（標準化された教材資産）。
  6. **セキュリティ/組織運用**: 組織分離・RBAC・PII 暗号化（既存基盤の事実のみ記載）。
  7. **料金 CTA**: `/pricing` へ（最安額があれば「¥N〜」表示）+ 登録 / ログイン済みならダッシュボード。
- CTA の問い合わせ導線は aigenba の `ContactUrl` VO（`app/Services/Marketing/ContactUrl.php` + `ContactDestinationKind` enum）を移植し、既存 `InquirySource`（`landing` / `billing` に **`pricing` case を追加**）と既存 `/contact` フォームに接続する。既定は内部 `/contact?source=...`、`services.marketing.contact_url` 設定時のみ mailto / 外部 URL。
- SEO: 既存 `HomeController` の `JsonLd::softwareApplication` に `lowPriceJpy`（最安プラン額）を供給する（現状 null のプレースホルダを解消）。

### 施策群 B: 料金表（`/pricing`）

- closure route を `Marketing/PricingController`（新規）に置換。`PricingPageDto`（aigenba 移植）を供給:
  - `plans`: `Marketing/PricingService::listPublicPlans()`（新規）が構築。**プラン台帳は既存 `Plan` + `PlanPrice`（DB snapshot）が真実源**、表示能力値（max_projects / max_members / max_storage_bytes）は `config/quota.php` の limits、チケット付与は `plans.monthly_ticket_grant`。aigenba の `PricingService`（listPublicPlans / cheapestActiveAmountJpy + メモ化）を AI-CUE のモデル構造（`currentPrice(PlanPriceKind::Base)`）に合わせて移植する。
  - `ticketTiers`: `ticket_volume_prices` の current 全段（AI-CUE では min_count=1 の行が spot を兼ねる。aigenba の `volumeTiersForDisplay()` 相当を `Billing/TicketPricingService`（新規、後述）に実装）。
  - `purchaseUnitAmountJpy`（spot = min_count 1 の単価）/ `signupGrantTickets` / `signupGrantExpiryDays`（`config/billing.php`）/ `isAuthenticated` / `contactUrl`。
- `Pricing.svelte` を aigenba `Guest/Pricing.svelte`（294 行）構造で書き換え: 料金構造の注記ボックス（基本料金 + チケット別売り）→ プランカードグリッド（`PricingPlanCard` molecule 移植）→ 大規模利用のお問い合わせバナー → **チケット料金表**（「X〜Y 枚 / ¥N／枚」帯表示 + signup grant 注記）→ FAQ（アコーディオン、AI-CUE 文言: チケットは AI 解析 1 枚 / 動画レンダ 3 枚 等）。
- **価格値は aigenba を「そのまんま」移植**:
  - チケット傾斜: 1〜¥100 / 20〜¥80 / 50〜¥70 / 100〜¥65 / 200〜¥60 / 300〜¥55 / 500〜¥50(floor) — **既存 `TicketVolumePriceSeeder` が既に同値**（テンプレが aigenba 由来）。`DatabaseSeeder` への登録（オプトイン解除）のみ行う。
  - signup grant 10 枚 / 30 日、floor ¥50 — **既存 `config/billing.php` が既に同値**。変更なし。
  - プラン基本料: AI-CUE のプラン構成は **free / standard の 2 本を維持**する（aigenba の personal / starter / business / enterprise は席課金・自動移行を前提とした aigenba 固有のプラン概念であり、AI-CUE の quota / plan_code 体系に存在しない。「別物の概念を似ているからで統合しない」）。Standard の基本料は aigenba Standard の **¥4,980** を移植する（`PlanSeeder::PRICE_AMOUNTS` + `stripe/fixtures/plan_standard.json` の unit_amount を同時更新。`StripePriceCatalogFixtureInvariantTest` / `billing:verify-stripe-prices` の整合を維持）。
- SEO: `softwareApplication` JSON-LD に lowPriceJpy を供給（SeoManager 経由。既存 SeoComposer の minimal 分類から full 供給へ）。

### 施策群 C: チケットリチャージ（スポット購入）

aigenba の `/purchase-tickets`（showPurchase）+ `/checkout`（spot funding）を、AI-CUE の current-org スコープ（URL に org slug を含めない既存 billing.* 規約）へ移植する。

- **ルート**（`auth+verified`、課金ゲート `require-active-subscription` の **allowlist = billing 系 group**。未契約 / free プラン組織でも購入可能にする）:
  - `GET /purchase-tickets` → `Billing/TicketPurchaseController::show`（閲覧 = 組織メンバー `view`）
  - `POST /purchase-tickets/checkout` → `Billing/TicketPurchaseController::checkout`（`manageBilling` = owner / admin のみ）
- **購入画面** `Billing/PurchaseTickets.svelte`（新規）: 枚数入力（1〜1000）→ クライアント側で傾斜表から適用単価・総額を即時表示（`PurchaseTierDto` slim 配列 = minCount / unitAmount のみ。stripe_price_id は出さない）→ 購入ボタン。現在残高・傾斜表・signup grant を併記。**disabled は使わない**（不正枚数は押下時にエラー表示）。
- **tier 解決はサーバが権威**: 既存 `TicketVolumePrice::currentTierFor($count)`（fail-closed / floor 強制 / production 未 sync 拒否）をそのまま使う。金額・Price ID は payload から受け取らない（クライアントは count のみ送る）。
- **冪等マシン（二重課金防止）** — aigenba の attempt_token 方式を**核だけ**移植する:
  - 画面 render ごとに `attempt_token`（ULID）を props で発行。
  - 新テーブル `ticket_checkout_sessions`: `organization_id` / `initiated_by_user_id` / `ticket_count` / `unit_amount`（作成時単価 pin = webhook 金額照合の出典）/ `stripe_session_id` UNIQUE / `attempt_token` + `UNIQUE(organization_id, attempt_token)` / `status`（pending / completed）/ `checkout_url`。
  - `checkout` は org 単位 `Cache::lock` で直列化し、同一 attempt_token 再送は: 同 count の pending → 既存 checkout_url へ replay（新規 session を作らない）/ completed → 「受付済み」着地 / count 不一致・非 replayable → 再読み込み誘導エラー。並行 race の unique 違反も同じ収束（500 にしない）。
  - aigenba の resume window / 他 count pending の expire / オートリチャージ / BillingCheckoutSession の subscription intent 兼用は**移植しない**（v1 過剰。二重課金防止の必要十分条件は attempt_token replay + 台帳 idempotency_key）。
- **Stripe Checkout 作成**: 解決 tier の `stripePriceId` × `quantity=count` の one-time Checkout（`mode=payment`）。Stripe 呼び出しは新設の薄い gateway（`Billing/TicketCheckoutGateway` interface + Cashier `$organization->checkout()` 実装）に閉じ、テストは fake 実装を bind する（aigenba の `StripeGatewayInterface` パターン踏襲）。metadata に `purpose=ticket_purchase` / `organization_id` / `count`（**照合専用**）。Stripe idempotency key は `purchase:{attempt_token}`。
- **webhook 冪等付与**: 既存 `StripeWebhookProcessor` の `CheckoutSessionCompleted` 拡張点（現在 no-op）を実装:
  1. `mode=payment` かつ metadata `purpose=ticket_purchase` 以外は no-op（サブスク checkout は従来どおり `invoice.paid` 経路）。
  2. **真実源は自 DB 行**: `stripe_session_id` で `ticket_checkout_sessions` を引き、count / unit_amount / organization_id を DB 行から取る。payload の customer / metadata は**照合のみ**（不一致は report + 付与しない = fail-closed。tenant キー不信）。
  3. `amount_total === ticket_count × unit_amount（pin 値）` を照合。不一致は report + 付与しない（fail-closed）。
  4. 既存 `TicketLedgerService::grantPurchased()`（idempotency_key `purchase:{sessionId}` の冪等 insert、payment_intent / purchase_amount 記録済み設計）で付与 → 行を completed 化。event 再送・event_id 違い再送でも二重付与しない（claim + 台帳 UNIQUE の 2 重防御）。
  5. 返金逆仕訳は既存 `charge.refunded` → `clawbackPurchasedByPaymentIntent` がそのまま効く（変更不要）。
- **残高不足導線**: `AnalysisPanel` / `RenderPanel` の 402（`insufficient_tickets` code 厳格一致）エラー表示に「チケットを購入する」リンク（`/purchase-tickets`）を追加。`Billing/Index.svelte` の残高表示にも購入導線を追加。

## 期待効果

- **獲得（使命への入口）**: LP が North Star（SOP 起点・思考ゼロ・編集ゼロ）を訴求し、tebiki 系（OJT 撮影の形式化）との差別化を初見で伝える。
- **転換**: 料金の透明性（基本料金 + チケット別売り + まとめ買い逓減 + 初回 10 枚無料）で登録障壁を下げる。
- **継続（使命のフローを止めない）**: チケットは analyze / render の燃料。残高不足 402 が「購入 → 再実行」の 2 クリックで解消され、マニュアル作成フローが課金で行き止まりにならない。
- 二重課金ゼロ（attempt_token 冪等 + webhook 冪等 + 台帳 UNIQUE の 3 層)・原価割れ販売ゼロ（floor fail-closed）を機械強制のまま拡張する。

## 実装方針（概要）

| レイヤ | 変更 |
|---|---|
| Controller | `HomeController`（DTO 供給追加）/ `Marketing/PricingController`（新規）/ `Billing/TicketPurchaseController`（新規） |
| Service | `Marketing/PricingService`（新規: listPublicPlans / cheapestActiveAmountJpy）/ `Billing/TicketPricingService`（新規: volumeTiersForDisplay / spotUnitPriceAmount / signupGrant 表示値）/ `Billing/TicketCheckoutService`（新規: 冪等 checkout 作成）/ `Billing/TicketCheckoutGateway`（新規 interface + Cashier 実装） |
| DTO | `Marketing/LandingPageDto` / `Marketing/PricingPageDto` / `Billing/PlanDto` / `Billing/PurchaseTierDto` / `Billing/PurchaseTicketsPageDto`（いずれも aigenba 移植・AI-CUE 形状） |
| Model / DB | `Billing/TicketCheckoutSession`（新規 model + migration + Factory）。`MassAssignmentProtectedKeys` へ FK 追記 |
| Enum | `Billing/TicketCheckoutSessionStatus`（新規）/ `Inquiry/InquirySource` に `Pricing` case 追加 |
| Webhook | `StripeWebhookProcessor::CheckoutSessionCompleted` arm 実装 |
| Seeder / 価格 | `TicketVolumePriceSeeder` を `DatabaseSeeder` に登録 / `PlanSeeder` Standard base ¥1,980→¥4,980 + `stripe/fixtures/plan_standard.json` 同時更新 |
| Route | `/pricing` controller 化 / `GET /purchase-tickets` / `POST /purchase-tickets/checkout`（billing allowlist 群） |
| Front | `Welcome.svelte` 実 LP 化 / `Pricing.svelte` 実料金表化 / `Billing/PurchaseTickets.svelte`（新規）/ `molecules/PricingPlanCard.svelte`（移植）/ `types/marketing.ts`・`types/billing.ts`（新規 TS interface）/ 402 導線（AnalysisPanel / RenderPanel / Billing/Index） |
| テスト | Feature（guest 200 / props 形状 / 認可 / 冪等 / webhook 付与・照合 fail-closed）+ 既存課金テスト green 維持 + Vitest（LP / Pricing / PurchaseTickets 描画・disabled 不使用）+ Architecture（route / 保護キー） |

## 制約・前提

- **AGENTS.md セキュリティ不変条件**: tenant キー不信（count 以外を payload から受けない・metadata は照合専用）/ webhook 冪等マシン経由 / `response()->json()` 直書き禁止（Inertia + JsonResource）/ `redirect()->intended()` 不使用（Checkout 遷移は `Inertia::location`、エラーは `back()->with`）。
- 消費系（reserve→commit/release）は**変更しない**。付与系の冪等 insert（既存 `grantPurchased`）を再利用し二重実装しない。
- フェーズ1規約踏襲: Inertia typed array + TS interface / DS token のみ / disabled 禁止 / Lucide のみ / Atomic 単方向 import / Controller 薄く Service に transaction。
- 新 route は nested param を持たない（current-org 解決 = `ResolvesCurrentOrganization`）ため `NestedRouteIdorDefenseTest` inventory 対象外。ただし cross-org 防御は「current org のみ購入・残高参照」で担保し Feature テストで固定。
- PHPStan level 10 / Pest（RefreshDatabase グローバル）/ 既存 `TicketVolumeTierTest` 等の課金テストを壊さない。
- Stripe 実呼び出しはテストで gateway fake（webhook は `WebhookReceived` イベント直発火の既存流儀）。

## スコープ外（後続）

- 決済プロバイダ追加・多通貨・外国語 LP。
- サブスク自動移行（aigenba Starter→Standard）・席課金（seat price）・オートリチャージ（T1000 相当）・購入 resume window。
- aigenba 固有ドメイン文言（対話訓練 / シナリオ / コース等）は移植しない（AI-CUE 文言へ置換）。
- プランラインナップ拡張（personal / starter / business / enterprise の導入）。
- `/terms` 等 legal ページの正式文面化。
