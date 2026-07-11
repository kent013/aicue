# 概念設計: marketing-landing-pricing（LP + 料金表 + チケットリチャージ）

作成: 2026-07-11 / 対象: AI-CUE (`/workspace`) / ドナー参照: `/tmp/aigenba`（同族 first-party テンプレ）
改訂: Round 1 Codex 概念レビュー反映（pending dedup 復活 / amount_subtotal 照合 / expired 状態 / 無料開始訴求一貫化 / role-aware 導線 / DTO shape 固定）

## 背景・課題

1. **LP が雛形のまま**: route `home`（`/`）は `HomeController` が SEO メタ（JSON-LD 含む）を供給するが、描画される `Welcome.svelte` は 10 行の「template is running」雛形。AI-CUE の North Star（手順書から思考ゼロ・編集ゼロで標準マニュアル動画）を一切訴求できていない。
2. **料金表が雛形のまま**: `/pricing` は closure route + `Pricing.svelte`（30 行のプレースホルダ 3 カード）。実プラン（`plans` / `plan_prices`）ともチケット価格（`ticket_volume_prices`）とも結線されていない。
3. **チケットのスポット追加購入（リチャージ）経路が無い**: チケットは月次付与（`invoice.paid`）と signup grant のみ。`analyze`（COST_ANALYSIS=1）/ `render`（COST_RENDER=3）で残高不足になると 402 / error flash で**行き止まり**になり、ユーザーが自力で残高を回復する手段がない。
4. ドナー aigenba には同一テンプレ系譜で実証済みの LP / 料金表 / スポット購入（傾斜単価 + 冪等 Checkout + webhook 冪等付与）がある。**基本構造・レイアウト・価格値は aigenba に合わせ**、文言を AI-CUE ドメイン（SOP → AI カット設計 → PWA 撮影 → 自動合成）へ差し替えて移植する（ユーザー指示）。

## 改善アイデア

### 施策群 A: LP（トップ `/`）

- `HomeController` を拡張し、`LandingPageDto`（`app/DataTransferObjects/Marketing/LandingPageDto.php`、aigenba 移植）を Inertia typed array で供給する:
  `signupGrantTickets` / `contactUrl` / `contactIsExternal` / `isAuthenticated`。
  （aigenba の `cheapestActiveAmountJpy` は**移植しない**: AI-CUE は Free プランがあるため最安額が常に ¥0 になり、チケット消費が必要な実体験と訴求がずれる。LP は「**無料で始める**（Free プラン + 初回チケット 10 枚）」を正面訴求とし、料金 CTA は金額なしの「料金プランを見る」に統一する。）
- `Welcome.svelte` を**その場で実 LP に書き換える**（ファイル名・render 先は `Welcome` のまま = HomeController / 既存 SEO・SSR テストの参照を変えない）。構成は aigenba `Guest/Landing.svelte`（435 行）のセクション構造を踏襲:
  1. **Hero**: 「動画マニュアルを、手順書から。」— SOP を渡せば AI がカット設計、スマホでナビ撮影、編集ゼロで完成。右側は撮影ナビ画面の簡易モック（aigenba のチャットモック相当を「カット一覧 + 撮影ガイド」モックに差し替え）。
  2. **課題（3 つの壁）**: 台本作成・撮影判断・編集（AGENTS.md の North Star の 3 ハードルそのまま）。
  3. **3 ステップ（How）**: SOP アップロード → AI がカット設計（動画シナリオ）→ PWA ナビ撮影 → 自動合成（字幕付き完成動画）。
  4. **素材セクション**: 手元の手順書（PDF / Excel）から作れる。
  5. **成果セクション**: 撮る人（ナビに従うだけ）/ 教える人（品質が人に依存しない）/ 管理者（標準化された教材資産）。
  6. **セキュリティ/組織運用**: 組織分離・RBAC・PII 暗号化（既存基盤の事実のみ記載）。
  7. **料金 CTA**: 「無料で始める」（登録）+ 「料金プランを見る」（`/pricing`）/ ログイン済みならダッシュボード。「無料開始 + チケット制（AI 解析 1 枚・動画レンダ 3 枚）」の粒度を Hero・料金 CTA・pricing の FAQ で一貫させる。
- CTA の問い合わせ導線は aigenba の `ContactUrl` VO（`app/Services/Marketing/ContactUrl.php` + `ContactDestinationKind` enum）を移植し、既存 `InquirySource`（`landing` / `billing` に **`pricing` case を追加**）と既存 `/contact` フォームに接続する。既定は内部 `/contact?source=...`、`services.marketing.contact_url` 設定時のみ mailto / 外部 URL。
- SEO: 既存 `HomeController` の `JsonLd::softwareApplication` に `lowPriceJpy = 0`（Free プラン）を供給する（現状 null のプレースホルダを解消。「無料開始」の訴求と JSON-LD を一致させる）。

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

- **ルート**（`auth+verified`。課金ゲート `require-active-subscription` は「gated group に入れた route のみ」に適用される opt-in 構造のため、新 route 2 本は既存 billing.* と同じく **group 外に個別登録**する = allowlist 集合を広げる変更ではない。未契約 / free プラン組織でも購入可能）:
  - `GET /purchase-tickets` → `Billing/TicketPurchaseController::show`（閲覧 = 組織メンバー `view`）
  - `POST /purchase-tickets/checkout` → `Billing/TicketPurchaseController::checkout`（`manageBilling` = owner / admin のみ）
- **購入画面** `Billing/PurchaseTickets.svelte`（新規）: 枚数入力（1〜1000）→ クライアント側で傾斜表から適用単価・総額を即時表示（`PurchaseTierDto` slim 配列 = minCount / unitAmount のみ。stripe_price_id は出さない）→ 購入ボタン。現在残高・傾斜表・signup grant を併記。**disabled は使わない**（不正枚数は押下時にエラー表示）。**role-aware 表示**: `canManage` prop により、`manageBilling` を持たないメンバーには「チケットの購入は組織のオーナー / 管理者が行えます」の案内を表示する（402 導線から来た一般メンバーを再度行き止まりにしない）。
- **tier 解決はサーバが権威**: 既存 `TicketVolumePrice::currentTierFor($count)`（fail-closed / floor 強制 / production 未 sync 拒否）をそのまま使う。金額・Price ID は payload から受け取らない（クライアントは count と attempt_token のみ送る）。
- **冪等マシン（二重課金防止）** — aigenba の attempt_token 方式 + **pending dedup** を移植する（R1 Critical: token replay だけでは別タブ・リロード新 token の複数 session 並立を防げない）:
  - 画面 render ごとに `attempt_token`（ULID）を props で発行。
  - 新テーブル `ticket_checkout_sessions`: `organization_id` / `initiated_by_user_id` / `ticket_count` / `unit_amount`（作成時単価 pin = webhook 金額照合の出典）/ `currency`（pin）/ `stripe_session_id` UNIQUE / `attempt_token` + `UNIQUE(organization_id, attempt_token)` / `status`（**pending / completed / expired**）/ `checkout_url` / **`expires_at`（Stripe session 作成時の expires_at を pin。「live pending」判定の決定的な基準）**。
  - **live pending の定義**: `status=pending AND expires_at > now()`。dedup / replay は必ずこの条件のみを対象にし、**期限切れ pending は dedup 前に `expired` へ遷移**させてから新規作成に進む（R2 Critical: 同 count 再購入で Stripe 側 24h expire 済みの死 URL を永続 replay しない。Stripe 照会不要 = pin 値で決定的）。
  - `checkout` は org 単位 `Cache::lock` で直列化し、次の順で収束させる:
    1. **同一 attempt_token の既存行**: 同 count の live pending（checkout_url あり）→ 既存 URL へ replay / completed → 「受付済み」着地 / count 不一致・期限切れ・非 replayable → 再読み込み誘導エラー（`back()->with('error')`）。
    2. **live pending dedup（aigenba T680 相当）**: 同 (org, user) の live pending が**同 count** なら新規作成せず既存 checkout_url へ replay（= 別タブ・新 token でも session は 1 本）。**別 count** なら gateway 経由で Stripe 側 session を expire し（expire が complete を返したら「直前の購入が処理中」エラー）、成功時のみ行を expired 化して新規作成に進む。expire 失敗時は新規作成せずエラー着地（二重 live session を作らない）。
    3. 新規作成後の INSERT で unique 違反（並行 race / Stripe idempotency replay）→ 既存行 re-read で replay / エラーに収束（500 にしない）。
  - `Cache::lock` の取得失敗（LockTimeoutException）は **fail-closed**: 「直前の購入手続きが進行中です」の `back()->with('error')` に固定し、ロックなし実行へフォールバックしない（テストで固定）。
  - 放棄 session は Stripe Checkout 自体の有効期限（既定 24h）で Stripe 側が expire し、DB 行は上記 live 判定 + 遷移で回収する（専用 cron は v1 では作らない: 局所回収で十分・過剰実装回避）。
  - **crash 復旧特性**: Stripe 作成成功後・DB 保存前に落ちても、Stripe 作成の idempotency key が `purchase:{attempt_token}` のため同一 attempt の再試行は**同一 session** を返し、その時点で DB 行が記録され追跡に収束する。DB 行が引けない completed webhook は付与しない（fail-closed、後述）ため、未追跡 session が黙って付与されることはない。
  - aigenba の resume window（showPurchase での token 安定化）/ オートリチャージ / BillingCheckoutSession の subscription intent 兼用は**移植しない**（v1 過剰。二重課金防止は pending dedup + 台帳 idempotency_key で成立し、resume window は UX 最適化にすぎない）。
- **Stripe Checkout 作成**: 解決 tier の `stripePriceId` × `quantity=count` の one-time Checkout（`mode=payment`）。**promotion code / automatic tax は使わず、`payment_method_types=['card']`（即時決済のみ）に固定**（金額照合と「completed = 決済済み」の前提を壊さない。非同期決済手段の許可は将来スコープ = `checkout.session.async_payment_succeeded` を `HandledStripeWebhookEvent` へ追加する拡張点）。Stripe 呼び出しは新設の薄い gateway（`Billing/TicketCheckoutGateway` interface: `createTicketCheckout()` / `expireCheckoutSession()` + Cashier `$organization->checkout()` 実装）に閉じ、テストは fake 実装を bind する（aigenba の `StripeGatewayInterface` パターン踏襲）。metadata に `purpose=ticket_purchase` / `organization_id` / `count`（**照合専用**）。Stripe idempotency key は `purchase:{attempt_token}`。
- **応答規約（Inertia 統一）**: 成功 = `Inertia::location($checkoutUrl)`（外部 full-page 遷移）/ バリデーション失敗 = Laravel 標準の back redirect + session errors（Inertia が props で受ける。XHR 向け独自 422 JSON は作らない）/ 業務エラー（進行中・stale token・expire 失敗・lock timeout）= `back()->with('error', ...)`。`response()->json()` 直書きゼロ・`redirect()->intended()` 不使用。
- **webhook 冪等付与**: 既存 `StripeWebhookProcessor` の `CheckoutSessionCompleted` 拡張点（現在 no-op）を実装:
  1. metadata `purpose=ticket_purchase` 以外（サブスク checkout / 他 purpose / mode≠payment）は no-op（従来どおり `invoice.paid` 経路。無関係 event を failed にしない）。
  2. **fail-closed は「retryable failure（例外 throw）」で実現する**（R3 Critical: silent skip で processed 化すると「決済済み・付与なし」が恒久化する）。既存冪等マシンの failed → Stripe 再送で received 復帰 → 再処理（attempts 上限 8 / 再送窓 ~3 日 / 上限到達で terminal-ack + failure_reason）に乗せる。
  3. **真実源は自 DB 行**: `stripe_session_id` で `ticket_checkout_sessions` を引き、count / unit_amount / currency / organization_id を DB 行から取る。**行不在は例外 throw**（= failed。crash 先着 webhook は、同一 attempt 再試行が Stripe idempotency key で同一 session に収束して DB 行が記録された後、event 再送で grantPurchased へ収束する）。payload の customer / metadata は**照合のみ**（不一致は例外 throw。tenant キー不信）。
  4. **`payment_status === 'paid'` を必須照合**（card 固定下では常に paid の想定だが、未決済 completed への防御線。paid 以外は例外 throw = 付与せず completed 化もしない）。
  5. `amount_subtotal === ticket_count × unit_amount（pin 値）` かつ `currency === pin 値` を照合（amount_total は税・割引の運用設定ドリフトで壊れるため使わない。作成側でも promo / automatic tax を使わない構成に固定 = 二重防御）。不一致は例外 throw（再送で直らない恒久不整合は attempts 上限の terminal-ack + failure_reason で運用調査に回る = 既存機構の設計どおり）。payload の amount_subtotal / currency / payment_status は nullable/untrusted として既存 `stringAt()` 流儀の型ガードで絞り込む（欠落も例外 throw、PHPStan lv10 で mixed を漏らさない）。
  6. 既存 `TicketLedgerService::grantPurchased()`（idempotency_key `purchase:{sessionId}` の冪等 insert、payment_intent / purchase_amount 記録済み設計）で付与 → 行を completed 化。event 再送・event_id 違い再送でも二重付与しない（claim + 台帳 UNIQUE の 2 重防御）。
  7. 返金逆仕訳は既存 `charge.refunded` → `clawbackPurchasedByPaymentIntent` がそのまま効く（変更不要）。
  - **Feature テストで固定するシナリオ**: 「session 作成成功 → DB 保存前障害（行なし）→ webhook 先着 = failed（付与なし）→ DB 行記録後の event 再送で一度だけ付与」（failed → received 復帰の既存冪等マシン許容も併せて検証）。
- **残高不足導線**: `AnalysisPanel` / `RenderPanel` の 402（`insufficient_tickets` code 厳格一致）エラー表示に「チケットを購入する」リンク（`/purchase-tickets`）を追加。既存 402 メッセージは「必要 N / 残高 M」を含む（`InsufficientTicketsException::forReserve`）ため必要枚数の提示は既存挙動で満たす。`Billing/Index.svelte` の残高表示にも購入導線を追加。購入画面側は role-aware 案内（前述）で非管理者の再行き止まりを防ぐ。

## 期待効果

- **獲得（使命への入口）**: LP が North Star（SOP 起点・思考ゼロ・編集ゼロ）を訴求し、tebiki 系（OJT 撮影の形式化）との差別化を初見で伝える。
- **転換**: 料金の透明性（基本料金 + チケット別売り + まとめ買い逓減 + 初回 10 枚無料）で登録障壁を下げる。
- **継続（使命のフローを止めない）**: チケットは analyze / render の燃料。残高不足 402 が「購入 → 再実行」の 2 クリックで解消され、マニュアル作成フローが課金で行き止まりにならない。
- **追跡済み Checkout の二重作成・二重付与の防止**（attempt_token 冪等 + live pending dedup + webhook 冪等 + 台帳 UNIQUE の 4 層。Stripe 作成の idempotency key により crash 後の再試行も同一 session に収束）・原価割れ販売ゼロ（floor fail-closed）を機械強制のまま拡張する。

## 実装方針（概要）

| レイヤ | 変更 |
|---|---|
| Controller | `HomeController`（DTO 供給追加）/ `Marketing/PricingController`（新規）/ `Billing/TicketPurchaseController`（新規） |
| Service | `Marketing/PricingService`（新規: listPublicPlans / cheapestActiveAmountJpy）/ `Billing/TicketPricingService`（新規: volumeTiersForDisplay / spotUnitPriceAmount / signupGrant 表示値）/ `Billing/TicketCheckoutService`（新規: 冪等 checkout 作成）/ `Billing/TicketCheckoutGateway`（新規 interface + Cashier 実装） |
| DTO | `Marketing/LandingPageDto` / `Marketing/PricingPageDto` / `Marketing/PricingPlanDto`（pricing 表示専用 = Billing 内部 DTO と責務分離）/ `Billing/PurchaseTierDto` / `Billing/PurchaseTicketsPageDto`。**全 DTO に `@phpstan-type XxxShape array{...}` + shape を返す `toArray()` を定義**し、TS 側は同名 interface を exact に対で保守（Feature テストで props key/型を固定） |
| Model / DB | `Billing/TicketCheckoutSession`（新規 model + migration + Factory）。`MassAssignmentProtectedKeys` へ FK 追記 |
| Enum | `Billing/TicketCheckoutSessionStatus`（pending / completed / expired）/ `Inquiry/InquirySource` に `Pricing` case 追加（normalize は cases 由来で自動追随。Filament 等の label 定義は波及変更として詳細設計で列挙） |
| Webhook | `StripeWebhookProcessor::CheckoutSessionCompleted` arm 実装 |
| Seeder / 価格 | `TicketVolumePriceSeeder` を `DatabaseSeeder` に登録 / **独立施策（別コミット・テスト観点分離）**: `PlanSeeder` Standard base ¥1,980→¥4,980 + `stripe/fixtures/plan_standard.json` 同時更新（価格改定はマーケ導線実装と別のプロダクト判断のため分離。値自体はユーザー指示 = aigenba そのまんま移植） |
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
