# 監査: 決済・料金プラン・チケット裏チャージ・新規登録経路 の aigenba 差分

> 4 スライス並列監査 (registration-funnel / pricing-plans / billing-subscription / ticket-charge)。
> 全 findings は実ファイル参照付き。ユーザー方針「決済まわりを全部 aigenba に合わせる」に対する接地調査。

## サマリー

- 総 findings: 49 (high 22 / medium 19 / low 8)
- 分類: missing-feature 16, domain-model 16, flow 9, layout 4, component 2, copy-i18n 1, other 1
- parity に新規実装が要る (blocksParity): 39
- **人のプロダクト判断が要る (requiresProductDecision): 34**

## 結論 (T071 との決定的な違い)

T071 (レイアウト parity) は「私が足した独自実装を削って aigenba に戻す」作業だった。今回は違う。
AI-CUE の決済ドメインの差分の大半は **AI-CUE 側の意図的なプロダクト設計** であり、
`aigenba に全部合わせる` = **記録済みの意思決定の反転 + 自社 LP 文言との矛盾** を意味する。
よって機械的に寄せてはならず、下記 3 つの分岐を人が決める必要がある。

### 分岐 F1: 課金ゲート方針 (最上流)

- AI-CUE: 未契約 (plan_code null) を **free tier として通す**。遮断は「有償契約中の支払い不健全」のみ。
  → `devnotes/20260712-0927-bugfix-billing-free-access` に **意図的決定として記録済み**。
- aigenba: 未 Subscribed を **遮断**し checkout / billing-required へ (プラン選択が必須関門)。
- 影響: registration-funnel の 14 findings のほぼ全て (onboarding/checkout, IntendedPlanResolver, 
  activate-personal, BillingRequired, ?plan= handoff) がこの分岐に従属する。

### 分岐 F2: signup grant (初回無料チケット) の付与契機

- AI-CUE: **登録 tx 内**で付与 (冪等キー `signup_grant:org:{orgId}`)。LP `Welcome.svelte` が
  「新規登録でチケット N 枚が無料」と **約束している**。
- aigenba: 登録時は 0 枚。**プラン有効化時**に付与 (`signup_grant:{stripeSubId}` / `signup_grant:personal:{orgId}`)。
- 影響: aigenba に合わせると **AI-CUE の LP 文言が嘘になる**。文言・付与契機・冪等キーを一体で決める必要。

### 分岐 F3: チケット会計モデル

- AI-CUE: source 分割台帳 (`TicketLedgerService`) + reserve/commit 2 フェーズ + `ticket_purchases` 逆仕訳。
- aigenba: 単一合計 + `TicketService` 単体。
- 影響: 金銭会計の書き換えは残高欠損/二重計上のリスク直結。**寄せる実利が薄く、リスクが高い**。

### 「裏チャージ」= オートリチャージ (ユーザー指摘の核心と推定)

- aigenba: **オートリチャージ (残高低下時の自動補充)** を持つ。AI-CUE は **持たない** (通知のみ)。
- これは反転でなく **純粋な機能欠落** → 追加するかどうかの判断のみ (F1/F2 と独立に着手可)。

## A. 判断不要 = 機械的に aigenba へ寄せられる (15件)

| slice | cat | sev | 差分 | action |
|---|---|---|---|---|
| registration-funnel | missing-feature | medium | メール認証 → checkout 復帰 (EmailVerificationContinuation) | #1 の checkout が存在して初めて意味を持つ従属差分。checkout 導入が決まれば機械的に追随実装可能。 |
| registration-funnel | missing-feature | medium | ゲートで失われた destination の復帰 (OnboardingReturnResolver) | #2 で aigenba 型ゲートを採用する場合の従属部品。free tier 維持なら AI-CUE の遮断は「支払い不健全」のみで頻度が低く、費用対効果は低い。 |
| registration-funnel | layout | low | オンボーディング画面の外枠 (T071 primitive との整合) | #1 で checkout を新設する場合、aigenba の直書き外枠をそのまま移植せず T071 で導入済みの primitive (PageContainer/PageHeader) に沿わせること。Pricing |
| pricing-plans | component | medium | PlanCard コンポーネント (Billing 側) | Billing/Plans ページを新設する場合に合わせて PlanCard adapter を移植。ただし isPending/disabledReason/enterprise CTA は上記のプラン変更フロー・en |
| pricing-plans | component | medium | PricingPlanCard 分子の機能差 (headerBadges / contactLabel) | Billing 側でバッジ付きプランカード(現在/予約中)を出すなら headerBadges snippet を分子へ追加。contactLabel(null=お問い合わせ)は enterprise プランを Plan |
| billing-subscription | domain-model | high | サブスク層のサービス設計 (SubscriptionService / SubscriptionSnapshot) | プラン変更/予約/早期アップグレードを AI-CUE に載せるなら、Gateway(外部 I/O 抽象)の下に SubscriptionService(ドメイン)を新設する層構成が要る。現状の Gateway は fak |
| billing-subscription | missing-feature | high | 料金プラン画面 (Billing/Plans) | 料金プラン比較を Billing/Plans として分離し、Index を請求ダッシュボードへ寄せる。T071 の PageContainer/PageHeader/PageContent primitive は AI- |
| billing-subscription | domain-model | high | サブスク Checkout の冪等・着地フィードバック | 既に AI-CUE 内にチケット購入用の TicketCheckoutSession + attempt_token の実装型があるので、それをサブスク checkout へ横展開するのが最短。success_url に |
| billing-subscription | missing-feature | medium | Stripe customer 同期 / 請求先情報 (BillingCustomerSynchronizer) | billing_contact_email/name の migration + 更新 action + 同期 job + Index への請求先フォーム追加を起票。dispatch は必ず transaction 内  |
| billing-subscription | flow | medium | Customer Portal の事前ガード | aigenba と同型の事前ガード (サブスク/stripe_id なし → error flash で back) を portal() に入れ、Index 側もボタン表示条件に契約状態を足す。free tier が既 |
| billing-subscription | layout | medium | Billing/Index のページ構造と情報密度 | 外枠 (T071 primitive) は既に準拠済みで独自 PlanCard も無いため、レイアウト自体の是正は不要。差はダッシュボードに載る情報量で、上記の各機能 (auto-recharge / 請求先 / fee |
| billing-subscription | missing-feature | low | 未契約メンバー向け説明画面 (billing-required) | 単独では価値が薄い。新規登録経路 (Onboarding/Checkout) 移植の従属タスクとして同時に起票する。 |
| ticket-charge | flow | medium | 購入フォームの状態機械 (resume/completed) と attempt_token 安定化 | AI-CUE 側は冪等基盤(TicketCheckoutService の attempt_token replay / live pending dedup)が既にあるので、controller で attempt_t |
| ticket-charge | domain-model | low | spot 単価の出典 (ticket_prices vs TicketVolumePrice min_count=1) | AI-CUE の単一テーブル集約の方が二重管理が少なく、価格解決の振る舞い(floor 強制/fail-closed)も等価なのでこの差は合わせなくてよい。ただし production での livemode/synce |
| ticket-charge | other | low | サービス分割構造 (単一 TicketService vs 4 分割) と冪等 Checkout マシン | AI-CUE の分割構造(特に Gateway 抽象 + Fake)は aigenba より見通しが良く、冪等の実質も同等以上なので単一 Service への逆行はしない。以降の parity 作業(#1/#2/#5)は |

## B. 要プロダクト判断 (34件)

| slice | cat | sev | 差分 | 判断が要る理由 |
|---|---|---|---|---|
| registration-funnel | missing-feature | high | 登録後オンボーディング (onboarding/checkout) の有無 | 本 slice の親差分。AI-CUE を aigenba に合わせるなら Onboarding/Checkout 相当のページ + ルート + Controller + DTO を新規実装する必要がある。ただし後述の「 |
| registration-funnel | domain-model | high | 未契約組織に対する課金ゲート方針 (RequireActiveSubscription) | 課金モデルの根幹 (free 既定 tier を廃してプラン選択必須にするか) の分岐点。AI-CUE 側の free 許可は devnotes に残る意図的決定のため、独断で aigenba 方針へ倒さない。「free |
| registration-funnel | domain-model | high | signup grant (初回無料チケット) の付与タイミング | チケット会計の付与契機と冪等キー名前空間 (org スコープ vs subscription スコープ) の設計差。AI-CUE は webhook 側の付与経路を既に持つため新規実装は不要だが、登録時付与を外すと LP |
| registration-funnel | missing-feature | high | intended plan (料金表で選んだプラン意図) の引き継ぎ | #1 の checkout を実装する場合の前提部品。IntendedPlanResolver 相当 (session 2 キー + 正規化規約) と PlanCode enum の新設が要る。登録フォームに plan  |
| registration-funnel | flow | medium | 料金表 → 登録 のプラン handoff | #4 (IntendedPlanResolver) とセット。単体で ?plan= を付けても受け手が無いため無意味。#1/#4 の決定後に一括で扱うこと。 |
| registration-funnel | flow | medium | 登録直後の RegisterResponse の責務 | #1 実装時の接続点。両者とも verification.notice に着地する点は一致しているため、差分は「継続コンテキストを積むか」に限定される。 |
| registration-funnel | missing-feature | high | 登録直後の funding 選択 (オートリチャージ同意 / あとで決める) | 裏チャージ (オートリチャージ) ドメインそのものの不在。他 slice (チケット購入/裏チャージ) と重複する親差分のため、本 slice では「登録直後に funding 同意を取る関門があるか」の観点のみ。オート |
| registration-funnel | domain-model | high | Personal (無料) プランの明示的有効化 (activate-personal) | #2 の課金ゲート方針の裏面。「暗黙 free tier」を「明示的に有効化する Personal プラン」に変えるのは課金モデルの変更そのもの。ユーザー判断必須。 |
| registration-funnel | missing-feature | medium | member 向け「課金手続き待ち」画面 (BillingRequired) | #2 で aigenba 型ゲートを採用する場合にのみ必要になる従属差分。free tier 維持なら実装不要。 |
| registration-funnel | domain-model | medium | Starter プラン自動移行の同意取得 | プラン体系 (Starter → Standard 自動移行) が AI-CUE に無い前提の差分。プラン体系を aigenba に合わせるか否かの上位判断に従属するため、単独では扱わない。 |
| registration-funnel | flow | low | 招待経由登録の受諾機構 | 課金主体 (personal org) を作らない結論は同じで、AI-CUE 側の tx 内受諾は grant 増幅対策として合理的に文書化されている。aigenba に合わせる積極的理由は薄い。現状維持を推奨し、変更す |
| pricing-plans | domain-model | high | プランのデータモデル / 価格導出 (Plan モデル・PlanPrice kind) | 席課金(included_seats / Seat kind PlanPrice / 追加シート単価)へ寄せるかは課金モデルそのものの変更。DB migration + Seeder + Stripe Price 体系( |
| pricing-plans | domain-model | high | プラン集合 (PlanCode) | 提供プランのラインナップ自体が異なる。どのプランを提供するか(personal 無料実体 / starter 自動移行 / business / enterprise)はプロダクト判断。合わせるなら Seeder・Pla |
| pricing-plans | missing-feature | high | Personal(無料)プランの実体と有効化フロー | 『無料の実プラン(Personal)を持ち非 Stripe で有効化する』か『AI-CUE の未契約=free tier を維持する』かは課金モデル判断。合わせる場合 PersonalPlanService・有効化 ro |
| pricing-plans | missing-feature | high | Starter オンボ専用プラン + 自動移行 | Starter 自動移行 + 早期アップグレードは課金ライフサイクル設計。導入可否は人判断。合わせるなら schedule/migration job・upgrade-now route・pending 状態管理を新規実 |
| pricing-plans | flow | high | プラン提示の構造 (専用ページ vs Index 内) | 専用プラン比較ページ + 確認 modal + in-app plan-change 経路への分離は導線とプラン変更 UX の変更。採否は人判断。合わせるなら Billing/Plans ページ・plans() cont |
| pricing-plans | layout | medium | guest pricing のレイアウト構成 | 三層構成は Personal/Enterprise の実プラン存在が前提。プラン集合の方針(Personal 無料実体・複数法人プラン)確定後にレイアウトを寄せる。/register?plan={code} の登録経路引 |
| pricing-plans | flow | high | プラン変更の実行機構 | in-app plan-change / upgrade-now への移行は Stripe subscription 変更ロジックの内製化。Customer Portal 委譲を続けるか人判断。合わせるなら change |
| billing-subscription | missing-feature | high | 新規登録経路 / Onboarding Checkout (signup funding) | ユーザー方針の中核(新規登録経路)。Onboarding/Checkout + BillingRequired + IntendedPlanResolver + startSignupCheckout の移植を設計単位で |
| billing-subscription | missing-feature | high | 裏チャージ (オートリチャージ) | ユーザー方針が名指しする「裏チャージ」。AutoRechargeService + 設定カード + setup checkout + 同意 version + reconcile scheduler を移植対象として起票 |
| billing-subscription | domain-model | high | BillingAccess のゲート設計 | aigenba 相当の state 機械へ寄せるかを先に決める。AI-CUE 側の「plan_code null = 支払い不要 free tier」は devnotes/20260712-0927-bugfix-bil |
| billing-subscription | missing-feature | high | 既存契約のプラン変更 (changePlan / upgradeNow) | 契約中 org が「このプランにする」を押した時の Stripe 挙動 (二重サブスク or 変更) を確認し、aigenba 型の plan-change endpoint 追加を起票。プラン変更を portal 任せ |
| billing-subscription | domain-model | high | free プランのデータモデル (plan_code null vs free_plan_code / PersonalPlanService) | free の表現を寄せるかは課金モデルそのものの決定。寄せる場合 organizations への free_plan_code / personal_declared_* カラム追加 + partial unique  |
| billing-subscription | missing-feature | medium | 課金権限の委譲 (BillingPermissionService) | AI-CUE 側に ApiKeyPermissionService という同型の先例があるため実装コストは低く、それに倣った BillingPermissionService を起票できる。ただし「誰が課金を触れるか」は |
| billing-subscription | domain-model | medium | 席課金 / 席キャパシティ | 席課金を採用するかは料金体系そのものの決定 (AI-CUE の現行はチケット付与量で差別化する設計で、規約としてプラン名分岐を禁じている)。採用する場合 plan_prices の kind 拡張・included_se |
| billing-subscription | flow | medium | 課金画面の組織スコープ (route 設計) | billing だけ slug スコープへ寄せるとアプリ全体の org スコープ規約 (ResolvesCurrentOrganization) と二重化する。aigenba 由来の機能 (signup checkout |
| ticket-charge | missing-feature | high | オートリチャージ (裏チャージ・自動補充) | 自動補充を製品として採用するかをまず人判断する(同意上限・カード保管・自動課金は規約/特商法影響あり)。採用なら TicketAutoRecharge モデル + setup Checkout(mode=setup) + |
| ticket-charge | domain-model | high | 残高会計モデル (source 分割 vs 単一合計) | 月次付与(期限あり)/購入済(無期限)のバケツ分離と消費優先順位を採用するかを人判断する(失効分の収益認識・ユーザの有利不利に直結)。採用なら TicketLedgerService.balance を TicketBa |
| ticket-charge | domain-model | medium | 予約・消費プリミティブ (encounter 単位 commit-wins vs 数量指定) | 消費粒度(1 encounter=1 枚 vs ジョブ別可変コスト)は製品定義そのものなので機械的に aigenba に合わせない。ただし aigenba の commit-wins / 失効 hold の no-cha |
| ticket-charge | domain-model | medium | 返金逆仕訳の正本 (ticket_purchases テーブル) | 単体では AI-CUE のインライン方式で checkout 返金は成立しているため急ぐ必要はない。finding #1(オートリチャージ)を採用する場合のみ、invoice アンカーの返金逆引きが必須になるので tic |
| ticket-charge | flow | low | 残高不足→購入→復帰導線 (return_to / 訓練に戻る) | 製品形態(非同期ジョブ vs 同期訓練)が異なるためそのまま写さない。ただし「残高不足でジョブが始まらない→購入ページへの導線が無い」は詰みになり得るので、InsufficientTicketsResource に購入ペ |
| ticket-charge | layout | low | ルーティング/スコープ (org-slug vs current-org) とパンくず | current-org 方式は AI-CUE 全体の方針なので billing だけ slug 化しない。breadcrumbs の有無はサイト共通のナビゲーション方針として別 slice(レイアウト/ナビ)で一括判断す |
| ticket-charge | domain-model | low | 残高低下時のハンドリング (通知 vs 自動補充) | AI-CUE 固有の低残高通知は aigenba に合わせるために削除しない(parity の名での機能後退になる)。finding #1 の自動補充を採用する場合に、通知と自動補充を併存させるか(補充成功時は通知不要等 |
| ticket-charge | copy-i18n | medium | 文言・単位 (枚 vs 回) と失効メッセージ | 単位の「枚」→「回」は AI-CUE の製品語彙(チケット=解析/レンダの可変コスト)と矛盾するので機械的に合わせない(人判断)。一方で「購入したチケットに有効期限はありません」は月次/signup grant 分には期 |

---

# 全 findings 詳細


## slice: registration-funnel (14 findings)

### registration-funnel-1: 登録後オンボーディング (onboarding/checkout) の有無

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は登録完了後に「プラン選択 → 資金選択 → 契約/有効化」の専用オンボーディング画面群 (checkout / activate-personal / billing-required) を持つ。AI-CUE には onboarding-billing 導線が一切存在しない。AI-CUE の app/Services/Onboarding 配下は SnippetBuilder (MCP/CLI 手順) のみで、resources/js/pages/Organizations/Onboarding も Mcp.svelte / Cli.svelte だけ。routes/web.php の onboarding.* も MCP/CLI 導入ガイドのみ。登録直後にプランを選ばせる画面・ルート・Controller が無い。
- aigenba: /tmp/aigenba/routes/web.php:442-449 (organizations.onboarding.checkout / activate-personal / billing-required), /tmp/aigenba/app/Http/Controllers/Onboarding/OnboardingController.php, /tmp/aigenba/app/Http/Controllers/Onboarding/ActivatePersonalController.php, /tmp/aigenba/app/Http/Controllers/Onboarding/BillingRequiredController.php, /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte, /tmp/aigenba/resources/js/pages/Onboarding/BillingRequired.svelte
- AI-CUE: 無し。/workspace/routes/web.php:293-296 は organizations.onboarding.{mcp,cli} のみ。/workspace/app/Services/Onboarding/SnippetBuilder.php が唯一の Onboarding service。/workspace/resources/js/pages/Organizations/Onboarding/{Mcp,Cli}.svelte のみ
- action: 本 slice の親差分。AI-CUE を aigenba に合わせるなら Onboarding/Checkout 相当のページ + ルート + Controller + DTO を新規実装する必要がある。ただし後述の「free tier 既定 vs プラン選択必須」の課金モデル決定が先行条件のため、単独で着手せず #2 (課金ゲート方針) の人判断とセットで扱うこと。

### registration-funnel-2: 未契約組織に対する課金ゲート方針 (RequireActiveSubscription)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: 同名 middleware だが遮断方針が正反対。AI-CUE は plan_code null (未契約) を「fallback free プラン = 支払い不要 tier」として**通過させ**、遮断するのは「有償プラン契約中の支払い不健全」のみ。遮断時は billing.index へ理由 flash 付き redirect。aigenba は未 Subscribed を**遮断**し、manage-billing 保持者は onboarding/checkout へ、非保持者は billing-required へ redirect (= プラン選択が必須関門)。つまり AI-CUE は「登録したらすぐ使える free 既定」、aigenba は「プランを選ぶまで業務ルートに入れない」。AI-CUE 側の free 許可は devnotes/20260712-0927-bugfix-billing-free-access で意図的決定として明記されている。
- aigenba: /tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:60-91 (state()->grantsAccess() 不成立 → checkout / billing-required へ分岐), /tmp/aigenba/routes/web.php:316-317 (require.subscription group)
- AI-CUE: /workspace/app/Http/Middleware/RequireActiveSubscription.php:39-84 (BLOCKED_MESSAGE / billing.index へ redirect), /workspace/app/Services/Billing/BillingAccess.php:17-41 (plan_code null = free tier として許可、意図的書き換えと明記)
- action: 課金モデルの根幹 (free 既定 tier を廃してプラン選択必須にするか) の分岐点。AI-CUE 側の free 許可は devnotes に残る意図的決定のため、独断で aigenba 方針へ倒さない。「free tier を維持したまま onboarding だけ足す」か「aigenba と同じ gate に寄せる」かをユーザーに確認すること。

### registration-funnel-3: signup grant (初回無料チケット) の付与タイミング

- category: domain-model / severity: high / blocksParity: False / requiresProductDecision: True
- 差分: 付与の契機が異なる。AI-CUE は**登録 transaction 内**で個人組織生成直後に付与 (冪等キー signup_grant:org:{orgId}、1 組織 1 回を部分 UNIQUE index で保証)。LP が約束する「新規登録で 10 枚」を登録時点で実現する設計。aigenba は登録時には付与せず、**プラン有効化時**にのみ付与する (subscription.created webhook で signup_grant:{stripeSubId}、Personal 無料プラン有効化で signup_grant:personal:{orgId})。結果として aigenba の新規登録直後ユーザーはチケット 0 枚で、checkout 完走が付与条件になる。
- aigenba: /tmp/aigenba/app/Services/Billing/SubscriptionService.php:423-443 (signup_grant:{stripeSubId}), /tmp/aigenba/app/Services/Billing/PersonalPlanService.php:125 (signup_grant:personal:{id}), /tmp/aigenba/app/Services/Billing/TicketService.php:261-289。/tmp/aigenba/app/Actions/Fortify/CreateNewUser.php は grant を一切呼ばない
- AI-CUE: /workspace/app/Actions/Fortify/CreateNewUser.php:101-106 (登録 tx 内で grantSignupGrant), /workspace/app/Services/Billing/TicketLedgerService.php:84-108 (signup_grant:org:{orgId})。/workspace/app/Services/Billing/StripeWebhookProcessor.php:266-270 でも subscription.created 時に呼ぶが org スコープ冪等キーのため実質 no-op
- action: チケット会計の付与契機と冪等キー名前空間 (org スコープ vs subscription スコープ) の設計差。AI-CUE は webhook 側の付与経路を既に持つため新規実装は不要だが、登録時付与を外すと LP 文言 (Welcome.svelte:349「新規登録でチケット N 枚が無料」) と矛盾する。文言・付与契機・冪等キーを一体で人判断すること。

### registration-funnel-4: intended plan (料金表で選んだプラン意図) の引き継ぎ

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は料金表 → 登録 → checkout までプラン意図を一貫保持する専用 service を持つ (pending / org-scoped の 2 キー設計、Enterprise 除外正規化、登録完了時に pending → org-scoped へ promote)。AI-CUE には plan 概念が登録経路に一切無い。registerView が渡す props は socialProviders / invitationEmail のみで、intended_plan の validation・session 保持・checkout への preselect も存在しない。AI-CUE には PlanCode enum 自体が無い (Enums/Billing 配下は PlanPriceKind のみ)。
- aigenba: /tmp/aigenba/app/Services/Onboarding/IntendedPlanResolver.php (PENDING_KEY / normalizeRaw / rememberPendingFromForm / promotePendingToOrganization), /tmp/aigenba/app/Providers/FortifyServiceProvider.php:141-157 (registerView が normalizeRaw(?plan) を intendedPlan として渡す), /tmp/aigenba/app/Actions/Fortify/CreateNewUser.php:90, /tmp/aigenba/app/Enums/PlanCode.php
- AI-CUE: 無し。/workspace/app/Providers/FortifyServiceProvider.php:182-203 (registerView は socialProviders / invitationEmail のみ), /workspace/app/Actions/Fortify/CreateNewUser.php:52-69 (validation に plan 系ルール無し)
- action: #1 の checkout を実装する場合の前提部品。IntendedPlanResolver 相当 (session 2 キー + 正規化規約) と PlanCode enum の新設が要る。登録フォームに plan を載せる = 登録フローの変更のため人判断が必要。

### registration-funnel-5: 料金表 → 登録 のプラン handoff

- category: flow / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba の料金表は各プランの CTA が /register?plan={code} でプラン意図を URL で引き渡し、Register 画面が hero 文言・Starter 同意・SSO ボタンに伝搬する。AI-CUE の料金表は全プランの CTA が素の /register で、どのプランカードから来ても登録画面は同一 (プラン情報が失われる)。LP も同様に /register 直行。
- aigenba: /tmp/aigenba/resources/js/pages/Guest/Pricing.svelte:164,189 (`/register?plan=${encodeURIComponent(plan.code)}`)
- AI-CUE: /workspace/resources/js/pages/Pricing.svelte:124 (`<Button href="/register" fullWidth>このプランで始める</Button>` — plan param 無し), /workspace/resources/js/pages/Welcome.svelte:137,160,358 (/register 直行)
- action: #4 (IntendedPlanResolver) とセット。単体で ?plan= を付けても受け手が無いため無意味。#1/#4 の決定後に一括で扱うこと。

### registration-funnel-6: 登録直後の RegisterResponse の責務

- category: flow / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: AI-CUE の RegisterResponse は verification.notice へ redirect するだけ (組織/プラン/継続の関与なし。個人組織生成は CreateNewUser 側の tx 内)。aigenba の RegisterResponse は (a) 招待 continuation があれば signedUrl へ直行、(b) 無ければ personal org を provisioning tx で確保、(c) pending intended plan を org-scoped へ promote、(d) EmailVerificationContinuation に org id を保持して verification.notice へ (ソフトゲート) — と課金オンボーディングへの継続点を構築する。
- aigenba: /tmp/aigenba/app/Responses/Fortify/RegisterResponse.php:37-72
- AI-CUE: /workspace/app/Http/Responses/Fortify/RegisterResponse.php:26-33 (redirect()->route('verification.notice') のみ)
- action: #1 実装時の接続点。両者とも verification.notice に着地する点は一致しているため、差分は「継続コンテキストを積むか」に限定される。

### registration-funnel-7: メール認証 → checkout 復帰 (EmailVerificationContinuation)

- category: missing-feature / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は verifyEmailView に continueUrl を渡し、認証待ち画面から checkout へ戻る二次 CTA を提供する (session 保持のため refresh で消えない。verify 完了時に forget)。AI-CUE の verifyEmailView は props 無しで Auth/VerifyEmail を render するだけで、continueUrl / 継続の概念が無い (grep で EmailVerificationContinuation / continueUrl の実装は 0 件)。
- aigenba: /tmp/aigenba/app/Providers/FortifyServiceProvider.php:173-184 (continueUrl を EmailVerificationContinuation::resolveUrl で解決), /tmp/aigenba/app/Support/Auth/EmailVerificationContinuation.php
- AI-CUE: /workspace/app/Providers/FortifyServiceProvider.php:219 (`Fortify::verifyEmailView(static fn (): InertiaResponse => Inertia::render('Auth/VerifyEmail'))` — props 無し)
- action: #1 の checkout が存在して初めて意味を持つ従属差分。checkout 導入が決まれば機械的に追随実装可能。

### registration-funnel-8: 登録直後の funding 選択 (オートリチャージ同意 / あとで決める)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba の Checkout はプラン確定後に「資金」2 択 (auto_recharge 既定 / later) を必ず通し、auto_recharge 選択時は同意条件 (閾値枚数・補充枚数・1 回上限額) をサーバー確定値で提示し consent_version を submit に同梱する (実行ボタン押下 = 同意アクション)。AI-CUE にはオートリチャージ機能自体が存在しない (app / resources/js を通じて auto_recharge / autoRecharge / AutoRecharge の実装 0 件)。したがって登録直後の funding 選択関門も無い。
- aigenba: /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:424-534 (personal funding), :536-636 (有償 funding), autoRechargeTerms / consentVersion 同梱
- AI-CUE: 無し (auto_recharge 系実装なし)。/workspace/routes/web.php:308-322 は billing.checkout / billing.tickets.checkout のみで signup 時の funding 選択は無い
- action: 裏チャージ (オートリチャージ) ドメインそのものの不在。他 slice (チケット購入/裏チャージ) と重複する親差分のため、本 slice では「登録直後に funding 同意を取る関門があるか」の観点のみ。オートリチャージ導入可否が決まるまで着手しないこと。

### registration-funnel-9: Personal (無料) プランの明示的有効化 (activate-personal)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は無料 Personal プランも「checkout 画面で選択 → 自己申告チェック (法人利用でない旨) → activate-personal に POST → 有効化 + signup grant 付与」という明示的な有効化手続きを踏む。サーバー側 eligibility (在籍数 / entitled サブスク / 別 free org) で選択可否も制御する。AI-CUE は plan_code null が暗黙の free tier で、有効化手続き・自己申告・eligibility 判定のいずれも存在しない (登録した時点で free 利用中)。
- aigenba: /tmp/aigenba/app/Http/Controllers/Onboarding/ActivatePersonalController.php, /tmp/aigenba/app/Services/Billing/PersonalPlanService.php:125, /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:401-535 (personal-free-step / declaration / personalEligibility), /tmp/aigenba/app/Enums/PlanCode.php:9 (case Personal)
- AI-CUE: 無し。/workspace/app/Services/Billing/BillingAccess.php:19,40-41 (plan_code null = fallback free プランとして暗黙許可)
- action: #2 の課金ゲート方針の裏面。「暗黙 free tier」を「明示的に有効化する Personal プラン」に変えるのは課金モデルの変更そのもの。ユーザー判断必須。

### registration-funnel-10: member 向け「課金手続き待ち」画面 (BillingRequired)

- category: missing-feature / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は未契約かつ manage-billing 権限なしの member 向けに専用 Inertia ページを持ち、Owner 名/連絡先と問い合わせ導線を出して「403 でも無限ループでもない」着地を作る。離脱ガード (契約済なら組織ダッシュボード、manage-billing 保持者なら checkout) も備える。AI-CUE は free tier 既定のため member が課金理由で止まる状態が発生せず、対応画面も無い。
- aigenba: /tmp/aigenba/app/Http/Controllers/Onboarding/BillingRequiredController.php:31-61, /tmp/aigenba/resources/js/pages/Onboarding/BillingRequired.svelte
- AI-CUE: 無し (RequireActiveSubscription は支払い不健全時に全員 billing.index へ流すのみ: /workspace/app/Http/Middleware/RequireActiveSubscription.php:83)
- action: #2 で aigenba 型ゲートを採用する場合にのみ必要になる従属差分。free tier 維持なら実装不要。

### registration-funnel-11: ゲートで失われた destination の復帰 (OnboardingReturnResolver)

- category: missing-feature / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は課金ゲートで弾いた際、safe method の意図遷移に限り「行きたかった内部 path」を org-scoped session に保存し (open-redirect 防御込みの normalizePath)、checkout 完了着地で復帰 CTA に使う。AI-CUE は gate redirect 時に理由 flash を積むのみで return_to の保存・復帰が無い (session reflash による直前 flash 延命は両者にある)。
- aigenba: /tmp/aigenba/app/Services/Onboarding/OnboardingReturnResolver.php, /tmp/aigenba/app/Http/Middleware/RequireActiveSubscription.php:78-81
- AI-CUE: 無し。/workspace/app/Http/Middleware/RequireActiveSubscription.php:81-83 (reflash + billing.index へ redirect、return_to 保存なし)
- action: #2 で aigenba 型ゲートを採用する場合の従属部品。free tier 維持なら AI-CUE の遮断は「支払い不健全」のみで頻度が低く、費用対効果は低い。

### registration-funnel-12: Starter プラン自動移行の同意取得

- category: domain-model / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は intended_plan=starter のときのみ starter_migration_acknowledged を server 側で必須化し (client guard + FormRequest の二重防御)、「契約から 30 日後に Standard へ自動移行し以降 Standard 料金が請求される」ことへの同意を登録フォームで取る。AI-CUE には Starter プラン概念も自動移行も登録時同意も無い (PlanCode enum 自体が無い)。
- aigenba: /tmp/aigenba/app/Actions/Fortify/CreateNewUser.php:76-83, /tmp/aigenba/resources/js/pages/Auth/Register.svelte:89-96,261-272 (starter ack checkbox), /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:170-176 (starterAutoMigrationDays)
- AI-CUE: 無し。/workspace/app/Actions/Fortify/CreateNewUser.php:52-69 (validation は name/email/password/terms_accepted のみ)
- action: プラン体系 (Starter → Standard 自動移行) が AI-CUE に無い前提の差分。プラン体系を aigenba に合わせるか否かの上位判断に従属するため、単独では扱わない。

### registration-funnel-13: 招待経由登録の受諾機構

- category: flow / severity: low / blocksParity: False / requiresProductDecision: True
- 差分: 招待経由で「個人組織を作らない」点は一致するが機構が異なる。AI-CUE は session の invitation_token を登録 tx 内で acceptInvitationIfValid し、その場で招待組織へ参加させ (受諾不能なら個人組織生成へ fallback)、招待経由では signup grant を付与しない (招待 N 人 = N×10 の増幅回避と明記)。aigenba は InvitationContinuation を pull して署名付き受諾 URL へ redirect する (受諾は別画面)、かつ pending plan を forget する。AI-CUE 側は MatchesInvitationEmail rule による server 強制、aigenba 側は session 由来 prefill + readonly。
- aigenba: /tmp/aigenba/app/Actions/Fortify/CreateNewUser.php:126-128,174-182 (hasInvitationContinuation → personal org スキップ), /tmp/aigenba/app/Responses/Fortify/RegisterResponse.php:46-54 (signedUrl へ redirect + forgetPending)
- AI-CUE: /workspace/app/Actions/Fortify/CreateNewUser.php:90-107 (acceptInvitationIfValid → 成功時 grant なし / 失敗時 個人組織 + grant), /workspace/app/Providers/FortifyServiceProvider.php:182-203 (invitationEmail prefill + no-store)
- action: 課金主体 (personal org) を作らない結論は同じで、AI-CUE 側の tx 内受諾は grant 増幅対策として合理的に文書化されている。aigenba に合わせる積極的理由は薄い。現状維持を推奨し、変更するなら招待×grant の会計影響とセットで人判断。

### registration-funnel-14: オンボーディング画面の外枠 (T071 primitive との整合)

- category: layout / severity: low / blocksParity: False / requiresProductDecision: False
- 差分: aigenba の Onboarding 系ページは GuestLayout + 独自 section (mx-auto max-w-6xl / max-w-2xl px-4 py-12) を直書きし、PageContainer/PageHeader 相当の primitive を使わない。プランカードは両者とも molecules/PricingPlanCard として同名コンポーネントが既に存在する (component parity あり)。AI-CUE には対応ページが無いため外枠差は現時点では潜在的。
- aigenba: /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte:283-284 (GuestLayout + max-w-6xl 直書き), /tmp/aigenba/resources/js/pages/Onboarding/BillingRequired.svelte:29-30 (GuestLayout + max-w-2xl 直書き), /tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte
- AI-CUE: 対応ページ無し。/workspace/resources/js/components/molecules/PricingPlanCard.svelte は存在し /workspace/resources/js/pages/Pricing.svelte:117-129 で使用中
- action: #1 で checkout を新設する場合、aigenba の直書き外枠をそのまま移植せず T071 で導入済みの primitive (PageContainer/PageHeader) に沿わせること。PricingPlanCard は既存 molecule を再利用でき新規実装不要。


## slice: pricing-plans (9 findings)

### pricing-plans-1: プランのデータモデル / 価格導出 (Plan モデル・PlanPrice kind)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba の Plan は included_seats / included_monthly_tickets / scenario_limit / course_limit / is_active を持ち、価格は PlanPrice の Base + Seat の2 kind (currentBaseAmount / currentSeatAmount) で導出する席課金モデル。AI-CUE の Plan は monthly_ticket_grant / sort_order のみで、能力(maxProjects/maxMembers/maxStorageGb)は config/quota.php + limits 側にあり、PlanPrice は Base 1 kind のみ(席課金なし)。DTO も aigenba=PlanDto(seats/limits/seatAmount)、AI-CUE=Marketing/PricingPlanDto(baseAmountJpy/maxProjects/maxMembers/maxStorageGb)で構造が別物。
- aigenba: /tmp/aigenba/app/Models/Billing/Plan.php, /tmp/aigenba/app/DataTransferObjects/Billing/PlanDto.php, /tmp/aigenba/app/Services/Billing/PlanPriceService.php (Base+Seat)
- AI-CUE: /workspace/app/Models/Billing/Plan.php, /workspace/app/DataTransferObjects/Marketing/PricingPlanDto.php (Base のみ、席課金列なし)
- action: 席課金(included_seats / Seat kind PlanPrice / 追加シート単価)へ寄せるかは課金モデルそのものの変更。DB migration + Seeder + Stripe Price 体系(Base/Seat)+ webhook 同期の再設計を要し、独断で合わせず人判断。まず『AI-CUE を席課金へ移行するか、チケット付与モデルを維持するか』の方針決定が先。

### pricing-plans-2: プラン集合 (PlanCode)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は personal / starter / standard / business / enterprise の5プラン(PlanCode enum で isSeatFixed / requiresStripeCheckout を分岐)。AI-CUE は free / standard の2プランのみで PlanCode enum 相当が無く、free は plan_prices を持たない『null=未契約=無料 tier』という別意味論。personal(無料実プラン)/ starter(オンボ専用)/ business / enterprise が AI-CUE に存在しない。
- aigenba: /tmp/aigenba/app/Enums/PlanCode.php (5 case), Seeder
- AI-CUE: /workspace/database/seeders/PlanSeeder.php (free/standard の2件、PlanCode enum 無し)
- action: 提供プランのラインナップ自体が異なる。どのプランを提供するか(personal 無料実体 / starter 自動移行 / business / enterprise)はプロダクト判断。合わせるなら Seeder・PlanCode enum・各プランの Stripe 配線を新規実装。

### pricing-plans-3: Personal(無料)プランの実体と有効化フロー

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は Personal を『base Price なし=currentBaseAmount null → 0 を渡して無料表示』の実プランとして持ち、Stripe を通さず PersonalPlanService::activate で有効化する(requiresStripeCheckout=false)。guest pricing でも『個人でご利用の方』専用バナー + /register?plan=personal 導線を持つ。AI-CUE には無料の実プランが無く、free=plan_code null の未契約状態で、有効化サービスも専用登録導線も存在しない。
- aigenba: /tmp/aigenba/app/Services/Billing/PersonalPlanService.php, /tmp/aigenba/resources/js/pages/Guest/Pricing.svelte (personal-banner)
- AI-CUE: PersonalPlanService 相当なし。/workspace/resources/js/pages/Pricing.svelte は無料=free コード分岐のみ
- action: 『無料の実プラン(Personal)を持ち非 Stripe で有効化する』か『AI-CUE の未契約=free tier を維持する』かは課金モデル判断。合わせる場合 PersonalPlanService・有効化 route・登録経路の plan 引き回しを新規実装。

### pricing-plans-4: Starter オンボ専用プラン + 自動移行

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は Starter を『契約から N 日後に Standard(実額)へ自動移行』するオンボ専用プランとして持ち、Pricing/PlanCard の warning バレット・starterAutoMigrationDays・自動移行バナー・早期アップグレード(upgrade-now)導線で全面的に UI 化。AI-CUE には Starter プラン・自動移行・早期アップグレードの概念が一切無い。
- aigenba: /tmp/aigenba/resources/js/pages/Billing/Plans.svelte (useEarlyUpgrade/upgrade-now), _helpers/PlanCard.svelte (starterMigrationText)
- AI-CUE: 該当なし
- action: Starter 自動移行 + 早期アップグレードは課金ライフサイクル設計。導入可否は人判断。合わせるなら schedule/migration job・upgrade-now route・pending 状態管理を新規実装。

### pricing-plans-5: プラン提示の構造 (専用ページ vs Index 内)

- category: flow / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は現在プラン/残高の Billing/Index とは別に、プラン比較専用ページ Billing/Plans.svelte(route: /organizations/{slug}/billing/plans)を持ち、確認 modal・plan-change/checkout/upgrade-now の経路分岐・attempt_token 冪等・seat guard エラー表示を備える。AI-CUE はプラン一覧を Billing/Index.svelte 内にインライン展開し、変更は Stripe Checkout POST のみ(専用比較ページ・確認 modal・pending 概念なし)。route も /billing(current org 暗黙)で org スラッグ配下でない。
- aigenba: /tmp/aigenba/resources/js/pages/Billing/Plans.svelte, /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php (plans() → BillingPlansDto)
- AI-CUE: /workspace/resources/js/pages/Billing/Index.svelte (プラン一覧インライン), /workspace/app/Http/Controllers/Billing/BillingController.php (index() のみ)
- action: 専用プラン比較ページ + 確認 modal + in-app plan-change 経路への分離は導線とプラン変更 UX の変更。採否は人判断。合わせるなら Billing/Plans ページ・plans() controller・BillingPlansDto・plan-change route を新規実装(現状は Customer Portal 委譲)。

### pricing-plans-6: PlanCard コンポーネント (Billing 側)

- category: component / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は Billing/_helpers/PlanCard.svelte(page-local adapter)を持ち、PricingPlanCard 分子に isCurrent/isPending バッジ・canSwitch・disabledReason(押せない理由の title/aria-label)・enterprise お問い合わせ CTA を束ねる。AI-CUE は PlanCard が無く、Billing/Index が素の Card + Badge + Button でプラン行を直接組んでおり、PricingPlanCard 分子を billing 側で再利用していない。
- aigenba: /tmp/aigenba/resources/js/pages/Billing/_helpers/PlanCard.svelte
- AI-CUE: PlanCard 無し。/workspace/resources/js/pages/Billing/Index.svelte L142-169 で inline Card 実装
- action: Billing/Plans ページを新設する場合に合わせて PlanCard adapter を移植。ただし isPending/disabledReason/enterprise CTA は上記のプラン変更フロー・enterprise プランに依存するため、それらの方針確定後に実装。

### pricing-plans-7: PricingPlanCard 分子の機能差 (headerBadges / contactLabel)

- category: component / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba の PricingPlanCard は headerBadges snippet(現在のプラン/変更予約中バッジ用)と contactLabel prop(priceAmount=null で『お問い合わせ』表示)を持ち、null=お問い合わせ / 0=無料 の3分岐。AI-CUE の PricingPlanCard は headerBadges と contactLabel を持たず(null と 0 を両方『無料』表示)、コメントで『contactLabel 分岐は移植しない/大規模利用はカード外バナーの責務』と明示的に削っている。
- aigenba: /tmp/aigenba/resources/js/components/molecules/PricingPlanCard.svelte (headerBadges snippet, contactLabel, null=お問い合わせ)
- AI-CUE: /workspace/resources/js/components/molecules/PricingPlanCard.svelte (footerCta のみ、null/0 とも『無料』)
- action: Billing 側でバッジ付きプランカード(現在/予約中)を出すなら headerBadges snippet を分子へ追加。contactLabel(null=お問い合わせ)は enterprise プランを Plan 行として持つ場合のみ必要 → enterprise プラン採否に連動。

### pricing-plans-8: guest pricing のレイアウト構成

- category: layout / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba の Guest/Pricing は『個人でご利用の方(Personal 無料バナー)』+『法人でご利用の方(corporate プランの動的カラム数グリッド)』+『Enterprise 全幅バナー』の三層構成で、CTA は /register?plan={code}。AI-CUE の Pricing は 2 プランの単一グリッド(sm:grid-cols-2 固定)+ 大規模利用バナーの二層で、CTA は /register(plan パラメータなし)。ファイル配置も aigenba=Guest/Pricing.svelte、AI-CUE=top-level Pricing.svelte。
- aigenba: /tmp/aigenba/resources/js/pages/Guest/Pricing.svelte (personal-banner + corporate grid + enterprise-banner, xlGridClass 動的)
- AI-CUE: /workspace/resources/js/pages/Pricing.svelte (単一 grid + enterprise banner, /register 固定)
- action: 三層構成は Personal/Enterprise の実プラン存在が前提。プラン集合の方針(Personal 無料実体・複数法人プラン)確定後にレイアウトを寄せる。/register?plan={code} の登録経路引き回しは登録フロー領域と要調整。

### pricing-plans-9: プラン変更の実行機構

- category: flow / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: AI-CUE のプラン変更は Billing/Index から Stripe Checkout(POST /billing/checkout)を開始する経路のみで、既存契約の変更・解約は Customer Portal に委譲(POST /billing/portal)。aigenba は checkout(新規) / plan-change(既存の Stripe mutation) / upgrade-now(予約解除+即時 swap) を billingState と earlyUpgradePlanCodes で in-app に分岐し、確認 modal + attempt_token 冪等 + seat guard エラー(errors.plan_code)を持つ。
- aigenba: /tmp/aigenba/resources/js/pages/Billing/Plans.svelte (submitPlanChange 経路分岐), BillingController の upgradeNow/changePlan/startCheckout
- AI-CUE: /workspace/app/Http/Controllers/Billing/BillingController.php (checkout + portal のみ)
- action: in-app plan-change / upgrade-now への移行は Stripe subscription 変更ロジックの内製化。Customer Portal 委譲を続けるか人判断。合わせるなら changePlan/upgradeNow controller・seat guard・attempt_token・確認 modal を新規実装。


## slice: billing-subscription (15 findings)

### billing-subscription-1: 新規登録経路 / Onboarding Checkout (signup funding)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は登録直後にプラン選択+資金選択(funding)を必達させる専用 onboarding checkout フローを持つ。未契約 org は Onboarding/Checkout で plan preselect (IntendedPlanResolver が /pricing?plan= を org-scoped session に積む) → funding_choice (tickets / later / auto_recharge) を選ばせ、startSignupCheckout が初回サブスク決済に top-up・事前同意を合成する。manage-billing を持たない member は billing-required 画面へ分岐。AI-CUE には onboarding checkout 経路が一切なく、登録は Fortify CreateNewUser のみ。プラン契約は Billing/Index のプラン一覧から直接 checkout する後追い導線しかなく、funding 選択・signup grant・intended plan・billing-required 相当が存在しない。
- aigenba: /tmp/aigenba/app/Http/Controllers/Onboarding/OnboardingController.php (show: hasActiveAccess→billing.index / !manage-billing→billing-required / ?plan= を 303 canonical 化 / preselectFunding), /tmp/aigenba/resources/js/pages/Onboarding/Checkout.svelte (31894B), /tmp/aigenba/resources/js/pages/Onboarding/BillingRequired.svelte, /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:620 startSignupCheckout, /tmp/aigenba/app/Services/Onboarding/IntendedPlanResolver.php, /tmp/aigenba/routes/web.php:439-451
- AI-CUE: 無し。/workspace/app/Actions/Fortify/CreateNewUser.php のみ (招待組織参加、/workspace/routes/web.php:513)。/workspace/app/Services/Onboarding/ は SnippetBuilder.php (MCP/CLI ガイド) のみで課金 onboarding 不在。/workspace/routes/web.php:306-311 は /billing の index/checkout/portal 3 本のみ
- action: ユーザー方針の中核(新規登録経路)。Onboarding/Checkout + BillingRequired + IntendedPlanResolver + startSignupCheckout の移植を設計単位で起票する。ただし「登録直後に課金選択を必達させるか」「funding 2/3択を持つか」は UX/課金方針そのものなので、移植前に人の決定を取る。

### billing-subscription-2: 裏チャージ (オートリチャージ)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は残高が閾値を切ったら自動でチケットを買う裏チャージ一式を持つ: 設定 upsert (閾値/Max/enabled)、fail-closed な有効化 (PM 必須+同意必須)、consent_version の現行版一致検証、カード登録用 Checkout(mode=setup)、setup 完了着地の 303+flash による自動同意、signup funding での事前同意記録と PM 流用 Job、15 分毎の reconcile scheduler。AI-CUE には auto-recharge の実装・語彙が app/resources 配下に 1 件も存在せず、チケット補充は手動購入のみ。
- aigenba: /tmp/aigenba/app/Services/Billing/AutoRechargeService.php, /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:737 updateAutoRecharge / :778 startAutoRechargeSetup / :216 resolveAutoRechargeLanding, /tmp/aigenba/app/Http/Requests/Billing/{UpdateAutoRechargeRequest,StartAutoRechargeSetupRequest}.php, /tmp/aigenba/routes/web.php:488-493, /tmp/aigenba/routes/console.php:22 billing:reconcile-auto-recharge
- AI-CUE: 無し (grep 'auto.recharge|autoRecharge|AutoRecharge|オートリチャージ|裏チャージ' が /workspace/app /workspace/resources で 0 hit)。/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php は手動購入 show/checkout のみ
- action: ユーザー方針が名指しする「裏チャージ」。AutoRechargeService + 設定カード + setup checkout + 同意 version + reconcile scheduler を移植対象として起票。自動課金は同意・返金・失敗時挙動を伴う課金モデル変更のため、閾値/上限の既定値と同意文言は人の決定必須。

### billing-subscription-3: BillingAccess のゲート設計

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: 同名クラスだが設計が別物。aigenba は OnboardingBillingState (Subscribed / ActiveFreePlan / PendingCheckout / ExpiredCheckout / NoSubscription) を返す状態機械で、SubscriptionService::deriveEntitlement (PM 有無/trial 終了/paused/past_due を合成) と BillingCheckoutSession の pending/expired を読み、流入制御 (どの onboarding 画面へ送るか) まで決める。AI-CUE は hasActiveAccess(): bool のみで、plan_code===null を free として無条件許可、非 null なら stripe_status ∈ {active,trialing} を見るだけ。checkout 進行中/失効の区別も entitlement 合成も無い。
- aigenba: /tmp/aigenba/app/Services/Billing/BillingAccess.php:31 state(): OnboardingBillingState (state() が hasActiveAccess の実体、grantsAccess() で判定)
- AI-CUE: /workspace/app/Services/Billing/BillingAccess.php:38 hasActiveAccess() のみ。GRANTING_STATUSES=['active','trialing'] の定数比較
- action: aigenba 相当の state 機械へ寄せるかを先に決める。AI-CUE 側の「plan_code null = 支払い不要 free tier」は devnotes/20260712-0927-bugfix-billing-free-access で意図的に選ばれた方針であり、RequireActiveSubscriptionMiddlewareTest が固定している。無断で ActiveFreePlan モデルへ置換すると free 組織の利用可否が変わるため、free 方針の再決定とセットで扱う。

### billing-subscription-4: サブスク層のサービス設計 (SubscriptionService / SubscriptionSnapshot)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は SubscriptionService (public method 16 本: getStatus / deriveEntitlement / startCheckout / startSignupCheckout / changePlan / upgradeNow / createPortalSession / createScheduleForStarter / completeStarterAutoMigration / applySubscriptionSnapshot / recordFundingSnapshot / grantSignupInitialTickets / computeOverflowSeats / buildSchedulePhases 等) がサブスクのドメイン中枢で、SubscriptionSnapshot が Stripe 実体の写像を担う。AI-CUE には SubscriptionService も SubscriptionSnapshot も無く、SubscriptionCheckoutGateway interface が createSubscriptionCheckout / portalRedirect の 2 メソッドだけを抽象する薄い層。既存契約のプラン変更・予約(schedule)・早期アップグレード・entitlement 導出の置き場が存在しない。
- aigenba: /tmp/aigenba/app/Services/Billing/SubscriptionService.php (16 public methods, ~1600 行), /tmp/aigenba/app/Services/Billing/SubscriptionSnapshot.php
- AI-CUE: /workspace/app/Services/Billing/SubscriptionCheckoutGateway.php:21-32 (2 メソッド interface), /workspace/app/Services/Billing/CashierSubscriptionCheckoutGateway.php (newSubscription()->checkout() と billingPortalUrl() のみ)。SubscriptionService.php / SubscriptionSnapshot.php は不在
- action: プラン変更/予約/早期アップグレードを AI-CUE に載せるなら、Gateway(外部 I/O 抽象)の下に SubscriptionService(ドメイン)を新設する層構成が要る。現状の Gateway は fake 差し替え点として有用なので廃止せず、SubscriptionService から Gateway を呼ぶ形に寄せる。

### billing-subscription-5: 既存契約のプラン変更 (changePlan / upgradeNow)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は契約後のプラン変更を Stripe subscription の mutation (changePlan) と早期アップグレード専用 endpoint (upgradeNow, EARLY_UPGRADE_TARGETS) で行い、Starter→Standard の予約 migration (schedule phases) と pendingPlanCode 表示まで持つ。AI-CUE は既存契約者でも Billing/Index の「このプランにする」から newSubscription('default')->checkout() を呼ぶ = 契約中の org に対して常に新規サブスク Checkout を張る導線しかない。プラン変更・ダウングレード・予約の概念が無く、変更は Customer Portal 任せ(かつ PortalConfigurationSpec は subscription_update 無効の spec)。
- aigenba: /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:875 changePlan / :832 upgradeNow, /tmp/aigenba/routes/web.php:471-482 (plan-change, upgrade-now), SubscriptionService::EARLY_UPGRADE_TARGETS / createScheduleForStarter / completeStarterAutoMigration
- AI-CUE: /workspace/app/Http/Controllers/Billing/BillingController.php:72 checkout のみ (常に createSubscriptionCheckout)。plan-change / upgrade-now route 無し (/workspace/routes/web.php:306-311)。/workspace/app/Services/Billing/CashierSubscriptionCheckoutGateway.php:24 newSubscription('default', ...)->checkout()
- action: 契約中 org が「このプランにする」を押した時の Stripe 挙動 (二重サブスク or 変更) を確認し、aigenba 型の plan-change endpoint 追加を起票。プラン変更を portal 任せにするか自前 mutation にするかは課金モデルの方針決定。

### billing-subscription-6: 料金プラン画面 (Billing/Plans)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: False
- 差分: aigenba はプラン比較専用画面 Billing/Plans.svelte (12251B) を持ち、Billing/Index は請求ダッシュボード (status / quotas / 残高 / auto-recharge カード / 席キャパ / 請求先フォーム / feedback / 移行バナー) に専念する 2 画面構成。AI-CUE は Billing/Index.svelte 1 枚に「現在のプラン + チケット残高 + プラン一覧 + 直接 checkout ボタン」を同居させており、プラン比較画面が存在しない。aigenba の Plans は seat 数・contactUrl(Enterprise 問い合わせ)・attemptToken・earlyUpgradePlanCodes・billingState を DTO で受ける。
- aigenba: /tmp/aigenba/resources/js/pages/Billing/Plans.svelte, /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:399 plans() → BillingPlansDto, /tmp/aigenba/routes/web.php:456 organizations.billing.plans。/tmp/aigenba/resources/js/pages/Billing/Index.svelte は 18001B のダッシュボード
- AI-CUE: /workspace/resources/js/pages/Billing/Index.svelte (7208B, 139-171 行でプラン一覧 <ul data-testid="plan-list"> を Index 内にインライン)。Plans.svelte 無し
- action: 料金プラン比較を Billing/Plans として分離し、Index を請求ダッシュボードへ寄せる。T071 の PageContainer/PageHeader/PageContent primitive は AI-CUE 側 Index が既に使用済みなので、新設 Plans も同じ外枠に合わせる。

### billing-subscription-7: サブスク Checkout の冪等・着地フィードバック

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: False
- 差分: aigenba はサブスク checkout を BillingCheckoutSession テーブルで追跡し、attempt_token (画面 render の ULID) による冪等再生、StaleCheckoutAttemptException→?retry 着地、Completed 再送→?replayed 着地、in-progress→warning、着地 query (session_id/portal/replayed/retry) を org スコープ relation で検証してから BillingFeedbackDto に落とす (他 org の session_id で偽 success を出さない IDOR 防御) を持つ。AI-CUE はチケット購入側にのみ TicketCheckoutSession + attempt_token があり、サブスク checkout は plan_code のみ・session 追跡なし・二重 submit 防止なし・着地フィードバックなし (success_url も cancel_url も同じ billing.index で成否を区別できない)。
- aigenba: /tmp/aigenba/app/Models/Billing/BillingCheckoutSession.php, /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:318 resolveBillingFeedback / :819 isAttemptCompleted / :537 startCheckout (attempt_token 'ulid' required), success_url に ?session_id={CHECKOUT_SESSION_ID}
- AI-CUE: /workspace/app/Http/Requests/Billing/BillingCheckoutRequest.php:31 は plan_code のみ (attempt_token 無し)。/workspace/app/Http/Controllers/Billing/BillingController.php:86-91 success/cancel とも route('billing.index') で区別不能。/workspace/app/Models/Billing/ に BillingCheckoutSession は無く TicketCheckoutSession のみ
- action: 既に AI-CUE 内にチケット購入用の TicketCheckoutSession + attempt_token の実装型があるので、それをサブスク checkout へ横展開するのが最短。success_url に ?session_id={CHECKOUT_SESSION_ID} を付け、org スコープ照合してから feedback を出す (TicketCheckoutService::confirmsPurchaseReturn と同型)。

### billing-subscription-8: free プランのデータモデル (plan_code null vs free_plan_code / PersonalPlanService)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: free の表現が根本的に別。AI-CUE は organizations.plan_code === null を「未契約 = fallback free tier」とし、free プランは Stripe Price を持たないため plan_code に載る経路が構造的に無い、という invariant で運用。aigenba は free を organizations.free_plan_code ('personal') で明示表現し、subscriptions テーブルは Stripe 実体のみを保持する invariant を守る。さらに Personal free は自己申告 activate (Stripe checkout を通さない)、declarer 単位 partial unique index による farming 防止、org 生涯 1 回の signup grant、paid 成立時の retire までを PersonalPlanService が持つ。paid→free で canceled サブスク行が残るケースを billingState で解決する規則 (T998) も AI-CUE には無い。
- aigenba: /tmp/aigenba/app/Services/Billing/PersonalPlanService.php (FREE_PLAN_CODE='personal', MAX_MEMBERS=3, activate/retireForPaidSubscription/eligibility), /tmp/aigenba/app/Services/Billing/BillingAccess.php:48 free_plan_code 判定, /tmp/aigenba/app/Http/Controllers/Onboarding/ActivatePersonalController.php, /tmp/aigenba/routes/web.php:445 activate-personal
- AI-CUE: /workspace/app/Services/Billing/BillingAccess.php:41 plan_code===null→true。/workspace/database/seeders/PlanSeeder.php:21-45 「free プランは Stripe Price を持たない / plan_prices を作らない」。free_plan_code / PersonalPlanService / activate-personal は不在 (grep free_plan_code は /workspace/app で 0 hit)
- action: free の表現を寄せるかは課金モデルそのものの決定。寄せる場合 organizations への free_plan_code / personal_declared_* カラム追加 + partial unique index + signup grant マーカーの migration が要り、BillingAccess・PlanSeeder・RequireActiveSubscriptionMiddlewareTest の前提を同時に書き換えることになる。単独では着手しない。

### billing-subscription-9: 課金権限の委譲 (BillingPermissionService)

- category: missing-feature / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は role とは別に manage_billing permission を個別ユーザーへ付与/剥奪でき (Laratrust team scope)、編集可否はロール階位マトリクス (自分自身不可 / OrgAdmin 以上 / 同格以上は編集不可) で判定、退会後の残存 permission を membership 確認で防ぐ。一覧表示用の一括取得 API も持ち、メンバー管理画面から PUT で編集できる。AI-CUE は OrganizationPolicy::manageBilling が owner/admin のロール判定のみで、課金権限の個別委譲が存在しない (同じアプリ内で manageApiKeys は ApiKeyPermissionService による直接付与を既にサポートしており、課金だけ委譲不可という非対称がある)。
- aigenba: /tmp/aigenba/app/Services/Billing/BillingPermissionService.php (grant/revoke/hasDirectPermission/canEdit/canEditWithKnownRoles/getDirectManageBillingMap), /tmp/aigenba/routes/web.php:365 organizations.members.update-billing-permission
- AI-CUE: /workspace/app/Policies/OrganizationPolicy.php:37 manageBilling() は organizationRole()?->canManage() のみ。BillingPermissionService は不在。対照的に同ファイル :47 manageApiKeys() は ApiKeyPermissionService::hasDirectPermission による委譲を実装済み
- action: AI-CUE 側に ApiKeyPermissionService という同型の先例があるため実装コストは低く、それに倣った BillingPermissionService を起票できる。ただし「誰が課金を触れるか」は認可方針の決定事項なので、委譲を許すか自体は人が決める。

### billing-subscription-10: Stripe customer 同期 / 請求先情報 (BillingCustomerSynchronizer)

- category: missing-feature / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は organizations に billing_contact_email / billing_contact_name を持ち、Billing/Index 上のフォームから更新でき、更新と組織リネーム時のみ BillingCustomerSynchronizer が SyncBillingCustomerDetails job を afterCommit で dispatch して Stripe customer へ反映する (webhook 経路は通さないため Stripe→app→Stripe のループが構造的に発生しない、stripe_id null は no-op)。AI-CUE には請求先の概念・更新 UI・customer 同期の窓口が一切なく、Stripe customer は Cashier 既定のまま。
- aigenba: /tmp/aigenba/app/Services/Billing/BillingCustomerSynchronizer.php:27 dispatchFor(), /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:1010 updateContact / :187 billingContact prop, /tmp/aigenba/resources/js/components/features/billing/BillingContactForm.svelte, /tmp/aigenba/routes/web.php:461 organizations.billing.contact.update
- AI-CUE: 無し。grep 'billing_contact' は /workspace/app で 0 hit。/workspace/app/Http/Controllers/Billing/BillingController.php に updateContact 相当なし。/workspace/app/Services/Billing/ に Synchronizer 不在
- action: billing_contact_email/name の migration + 更新 action + 同期 job + Index への請求先フォーム追加を起票。dispatch は必ず transaction 内 afterCommit で、webhook 経路からは呼ばない (同期ループ防止) という aigenba の契約をそのまま持ち込む。

### billing-subscription-11: Customer Portal の事前ガード

- category: flow / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は portal を「Stripe customer + サブスク前提」と明示し、free personal / 未契約 org では Stripe 例外(500) に到達させず error flash で back し、UI 側もボタンを出さない。AI-CUE は Billing/Index が canManageBilling だけを条件に portal ボタンを表示し、Controller も subscription/stripe_id を確認せず billingPortalUrl() を呼ぶ。Cashier の billingPortalUrl は先頭で assertCustomerExists() するため、Stripe customer 未作成の未契約 org (= AI-CUE では plan_code null の free tier = 既定状態) の owner/admin がボタンを押すと例外に到達する。
- aigenba: /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:978-989 redirectToPortal (billingState===ActiveFreePlan || サブスク行なし → back()->with('error', 'お支払い管理画面は有償プラン契約後にご利用いただけます。'))
- AI-CUE: /workspace/app/Http/Controllers/Billing/BillingController.php:98-104 portal() は Gate::authorize('manageBilling') 後そのまま portalRedirect。/workspace/resources/js/pages/Billing/Index.svelte:121 {#if canManageBilling} のみでボタン表示。/workspace/vendor/laravel/cashier/src/Concerns/ManagesCustomer.php:607 assertCustomerExists()
- action: aigenba と同型の事前ガード (サブスク/stripe_id なし → error flash で back) を portal() に入れ、Index 側もボタン表示条件に契約状態を足す。free tier が既定の AI-CUE では踏まれやすい経路なので優先度は高め。

### billing-subscription-12: 席課金 / 席キャパシティ

- category: domain-model / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba のプラン価格は base price + seat price + included_seats の席課金モデルで、plan ごとに席固定か否か (PlanCode::isSeatFixed: Personal/Starter は固定、Standard/Business/Enterprise は可変) を持ち、checkout 時に desiredSeatCount を確定、席の追加操作 (OrganizationSeatController + manage-seat-capacity gate)・空き算出 (SeatAvailabilityService)・超過席算出・Billing/Index の席キャパ表示まである。AI-CUE の Plan は monthly_ticket_grant と base price のみで席の概念が無く (「コードにプラン名で分岐を書かない。能力は monthly_ticket_grant で表現」という規約)、PlanCode enum 自体が存在しない (PlanPriceKind のみ)。
- aigenba: /tmp/aigenba/app/Enums/PlanCode.php:9-13 (Personal/Starter/Standard/Business/Enterprise) :20 isSeatFixed(), /tmp/aigenba/app/Services/Billing/{SeatAvailabilityService,SeatCapacityService,SeatConvergenceService}.php, /tmp/aigenba/app/Http/Controllers/Billing/OrganizationSeatController.php, /tmp/aigenba/routes/web.php:485 organizations.billing.seats.update, BillingController.php:147-156 SeatCapacityDto
- AI-CUE: /workspace/app/Models/Billing/Plan.php:26-29 fillable は code/name/monthly_ticket_grant 等、included_seats なし。/workspace/app/Enums/Billing/ は PlanPriceKind.php のみ (PlanCode 不在)。/workspace/database/seeders/PlanSeeder.php:38 'standard' => ['base' => 4980] の base 単価のみ。seat 系サービス・route は全て不在
- action: 席課金を採用するかは料金体系そのものの決定 (AI-CUE の現行はチケット付与量で差別化する設計で、規約としてプラン名分岐を禁じている)。採用する場合 plan_prices の kind 拡張・included_seats カラム・PlanCode enum 導入が要り、既存規約と正面衝突するため必ず人の決定を経る。

### billing-subscription-13: 課金画面の組織スコープ (route 設計)

- category: flow / severity: medium / blocksParity: False / requiresProductDecision: True
- 差分: aigenba は課金 route を organizations/{organization:slug}/billing/... で明示スコープし、Controller は route model binding された $organization を受けて Gate::authorize('view-billing'/'manage-billing', $organization) する。AI-CUE は /billing の単一 path で、ResolvesCurrentOrganization trait が session の current org を解決する暗黙スコープ。結果として AI-CUE の billing URL は組織を表現せず、org 切替中の着地・組織別ブックマーク・checkout 帰還先の org 特定が構造的にできない (チケット購入側は session_id を current org と照合する防御を自前で持っている)。
- aigenba: /tmp/aigenba/routes/web.php:453 Route::prefix('organizations/{organization:slug}/billing'), /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:87 index(Request $request, Organization $organization)
- AI-CUE: /workspace/routes/web.php:306-311 Route::get('/billing', ...), /workspace/app/Http/Controllers/Billing/BillingController.php:32 use ResolvesCurrentOrganization; :37 $this->resolveCurrentOrganization($request)。/workspace/app/Http/Controllers/Billing/TicketPurchaseController.php:55-59 で session_id を current org 行と照合する自前防御
- action: billing だけ slug スコープへ寄せるとアプリ全体の org スコープ規約 (ResolvesCurrentOrganization) と二重化する。aigenba 由来の機能 (signup checkout の帰還先 org 特定など) を移植する際にどちらのスコープ規約に載せるかを先に決める。billing 単独では動かさない。

### billing-subscription-14: Billing/Index のページ構造と情報密度

- category: layout / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba の Billing/Index は請求ダッシュボードで、BillingDashboardDto に status / currentPlan / pendingPlan / quotas / creditBalance / Starter 移行バナー / purchaseUnitAmountJpy / requiresAttention(UpgradeRecovery) / canUpgradeNow / seatCapacity / billingState を載せ、さらに autoRecharge 設定カード・billingContact フォーム・feedback・continueUrl を props で受ける。AI-CUE の Index が受けるのは plans / currentPlanCode / ticketBalance / canManageBilling の 4 props のみで、quota 表示・支払い要注意状態・pending plan・feedback が無い。外枠は AppLayout+PageContainer+PageHeader+PageContent の T071 primitive に沿っており、独自 PlanCard は無く汎用 Card/Badge/Button で組まれている。
- aigenba: /tmp/aigenba/app/Http/Controllers/Billing/BillingController.php:158-201 (BillingDashboardDto 14 引数 + autoRecharge/billingContact/feedback/continueUrl props), /tmp/aigenba/resources/js/pages/Billing/Index.svelte (18001B)
- AI-CUE: /workspace/resources/js/pages/Billing/Index.svelte:32-39 interface Props { plans, currentPlanCode, ticketBalance, canManageBilling }、:90-97 PageContainer/PageHeader で T071 外枠準拠、:100 Card padding="lg" testId="billing-summary"。/workspace/app/Http/Controllers/Billing/BillingController.php:60-65 Inertia::render で 4 props
- action: 外枠 (T071 primitive) は既に準拠済みで独自 PlanCard も無いため、レイアウト自体の是正は不要。差はダッシュボードに載る情報量で、上記の各機能 (auto-recharge / 請求先 / feedback / quota) を移植した結果として Index の props が増える形で追随させる。Index 単体を先に作り込まない。

### billing-subscription-15: 未契約メンバー向け説明画面 (billing-required)

- category: missing-feature / severity: low / blocksParity: True / requiresProductDecision: False
- 差分: aigenba は「未契約 かつ manage-billing 権限なし」の member が業務画面に来た時に、契約できない旨を説明する専用画面 billing-required へ分岐させる (OnboardingController::show の第 2 分岐)。AI-CUE には該当画面が無く、権限なしメンバーへの案内は Billing/Index 内の 1 行テキスト「プランの変更には組織の管理者権限が必要です。」のみ。未契約 free tier が既定のため分岐自体が現状は成立しないが、契約必須化・signup gate を移植する場合に必要になる。
- aigenba: /tmp/aigenba/app/Http/Controllers/Onboarding/BillingRequiredController.php, /tmp/aigenba/resources/js/pages/Onboarding/BillingRequired.svelte, /tmp/aigenba/app/Http/Controllers/Onboarding/OnboardingController.php:62-66, /tmp/aigenba/routes/web.php:448
- AI-CUE: 無し。/workspace/resources/js/pages/Billing/Index.svelte:132-136 {:else} の <p>プランの変更には組織の管理者権限が必要です。</p> のみ
- action: 単独では価値が薄い。新規登録経路 (Onboarding/Checkout) 移植の従属タスクとして同時に起票する。


## slice: ticket-charge (11 findings)

### ticket-charge-1: オートリチャージ (裏チャージ・自動補充)

- category: missing-feature / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は残高が閾値を割ると Stripe invoice で自動再課金する完全なオートリチャージ機構を持つ: TicketAutoRecharge / TicketAutoRechargeAttempt モデル、AutoRechargeService、commit 時に balance 閾値クロスで dispatch される AutoRechargeTriggerJob、grantAutoRecharge(invoice アンカーで recharge:{invoiceId} 冪等付与 + ticket_purchases 記録)、routes /auto-recharge と /auto-recharge/setup(mode=setup カード登録)、PurchaseTickets の転換バナー+設定リンク。AI-CUE には auto-recharge が app 全体で一切存在しない(app/ ・resources/ で grep 0 件)。裏チャージ経路は checkout.session.completed(手動購入)と invoice.paid(月次付与)のみ。
- aigenba: app/Services/Billing/TicketService.php:771 grantAutoRecharge, app/Models/Billing/TicketAutoRecharge.php, app/Services/Billing/AutoRechargeService.php, app/Jobs/Billing/AutoRechargeTriggerJob.php, routes/web.php:488-491, resources/js/pages/Billing/PurchaseTickets.svelte:187-203
- AI-CUE: 無し (app/Services/Billing 配下・resources/js 配下ともに autoRecharge/auto_recharge 参照 0 件)
- action: 自動補充を製品として採用するかをまず人判断する(同意上限・カード保管・自動課金は規約/特商法影響あり)。採用なら TicketAutoRecharge モデル + setup Checkout(mode=setup) + 閾値 trigger job + invoice アンカー付与(recharge:{invoiceId}) を T チケット化し、先に finding #4(ticket_purchases 正本化)を前提として入れる。見送るなら AI-CUE 既存の低残高通知(finding #9)を代替として明文化する。

### ticket-charge-2: 残高会計モデル (source 分割 vs 単一合計)

- category: domain-model / severity: high / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は残高を月次(PlanMonthly=有効期限あり)と購入済(Purchased=無期限)に分割し、消費優先順位 plan_monthly→purchased、reserve 時に consume_source と consume_expires_at を予約行へ固定、balance() は TicketBalanceDto{monthlyRemaining, purchasedRemaining, totalAvailable, activeReservations, nextExpireAt} を返す。AI-CUE の balance() は単一 int = SUM(未失効 delta) − SUM(reserved amount) で source 優先も per-bucket 失効会計も無く、コード docblock 自身が「全額失効として保守的に働く」と認めている。購入画面の残高表示も aigenba は 3 分割+次回失効日、AI-CUE は単一枚数のみ。
- aigenba: app/Services/Billing/TicketService.php:312-342 balance(), :405-408 消費優先, app/DataTransferObjects/Billing/TicketBalanceDto.php
- AI-CUE: app/Services/Billing/TicketLedgerService.php:226-242 balance() は単一 int (:217-225 docblock に失効会計の近似を明記), app/DataTransferObjects/Billing/PurchaseTicketsPageDto.php:36 balance:int
- action: 月次付与(期限あり)/購入済(無期限)のバケツ分離と消費優先順位を採用するかを人判断する(失効分の収益認識・ユーザの有利不利に直結)。採用なら TicketLedgerService.balance を TicketBalanceDto 相当へ差し替え、source 別集計 + consume_source/consume_expires_at を reserve に導入する大きな設計 T を立てる。finding #10(失効文言)とセットで扱うこと。

### ticket-charge-3: 予約・消費プリミティブ (encounter 単位 commit-wins vs 数量指定)

- category: domain-model / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba の reserve は encounter_id(unique)キーで 1 encounter=1 枚、consume_source/consume_expires_at を持ち commit-wins セマンティクス(reserve TTL 超過でも課金、失効 monthly hold は no-charge=ReleasedExpired)を実装、commit は TicketCommitResult enum(Committed/AlreadyCommitted/ReleasedExpired)を返す。AI-CUE の reserve は amount(任意コスト)指定で TicketReservation.amount を持ち、単純 3 状態(Reserved/Committed/Released)、Release は 0-delta 監査行、commit は -amount。commit-wins も失効 hold 処理も TicketCommitResult も無い。消費対象も aigenba=訓練 encounter、AI-CUE=manual 解析/レンダジョブ($cost 可変)で製品ドメインが異なる。
- aigenba: app/Services/Billing/TicketService.php:349-453 reserve(encounterId), :465-588 commit, app/Enums/Billing/TicketCommitResult.php
- AI-CUE: app/Services/Billing/TicketLedgerService.php:248-284 reserve(amount), :287-308 commit, :311-332 release(0-delta), 消費側 app/Services/Manual/AnalysisPipeline.php:121 / RenderPipeline.php:177 reserve(org,$cost)
- action: 消費粒度(1 encounter=1 枚 vs ジョブ別可変コスト)は製品定義そのものなので機械的に aigenba に合わせない。ただし aigenba の commit-wins / 失効 hold の no-charge 判定は会計健全性の知見なので、AI-CUE の reserve TTL 超過時の振る舞い(現状 releaseStale で解放→長時間ジョブでオーバセルの余地)を別途検証する T を立てる。

### ticket-charge-4: 返金逆仕訳の正本 (ticket_purchases テーブル)

- category: domain-model / severity: medium / blocksParity: True / requiresProductDecision: True
- 差分: aigenba は返金逆引きの正本として独立 ticket_purchases テーブル(count/amount/session_id/invoice_id/payment_intent_id/source)を持ち、grantPurchased・grantAutoRecharge が ledger と purchase を同一 TX で両建てし片肺(1,0)/(0,1)を RuntimeException で検知、PI backfill(null→値の単調更新)も持つ。clawback は purchase を lockForUpdate して按分。AI-CUE は ticket_purchases が無く、payment_intent_id と purchase_amount を ledger エントリにインライン保持、clawback は ledger を直接引く。checkout 返金は機能的にカバーするが invoice(auto-recharge)アンカーは持てない。両者とも 1 PI 複数一致は fail-closed(report+no-op)で同方針。
- aigenba: app/Models/Billing/TicketPurchase.php, app/Services/Billing/TicketService.php:680-761 grantPurchased(両建て+片肺検証), :863-925 clawbackPurchasedByPaymentIntent
- AI-CUE: app/Services/Billing/TicketLedgerService.php:119-140 grantPurchased(ledger インライン payment_intent_id/purchase_amount), :152-215 clawback(ledger 直引き) — app/Models/Billing/ に TicketPurchase なし
- action: 単体では AI-CUE のインライン方式で checkout 返金は成立しているため急ぐ必要はない。finding #1(オートリチャージ)を採用する場合のみ、invoice アンカーの返金逆引きが必須になるので ticket_purchases 相当の正本化を前提タスクとして先行させる。単独での先行導入はしない。

### ticket-charge-5: 購入フォームの状態機械 (resume/completed) と attempt_token 安定化

- category: flow / severity: medium / blocksParity: True / requiresProductDecision: False
- 差分: aigenba の showPurchase は resolveResumablePurchase で進行中/完了済 session を解決し formState(normal/resume/completed)を返す。resume は「決済を続ける」(進行中 Checkout URL)、completed は「もう一度購入する」(?fresh=1)を出し、count を boundCount に固定(編集不可)。attempt_token をサーバ側で安定化し back/bfcache で同一 token 再送→replay 収束で二重課金を防ぐ。AI-CUE は show ごとに Str::ulid() で attempt_token を新規発行し、resume/completed UI は無く、success_url 帰還時の purchased 成功バナーのみ。TicketCheckoutService は attempt_token で replay/completed を扱える実装を持つが、token が毎回 fresh なためブラウザバック後の replay には到達できない(冪等機構が実質未活用)。
- aigenba: app/Http/Controllers/Billing/BillingController.php:461-532 showPurchase(formState/boundCount/resumeUrl/newPurchaseUrl), app/Services/Billing/TicketService.php:1393-1417 resolveResumablePurchase, resources/js/pages/Billing/PurchaseTickets.svelte:205-215,302-347
- AI-CUE: app/Http/Controllers/Billing/TicketPurchaseController.php:68 attemptToken: (string) Str::ulid() を毎 render 発行, :57-59 purchased バナーのみ, app/Services/Billing/TicketCheckoutService.php:109-122 sameAttempt の replay 実装はある, resources/js/pages/Billing/PurchaseTickets.svelte:127-131
- action: AI-CUE 側は冪等基盤(TicketCheckoutService の attempt_token replay / live pending dedup)が既にあるので、controller で attempt_token をサーバ安定化(進行中/完了済 TicketCheckoutSession 由来 token の再利用 + ?fresh=1)し、formState 相当を PurchaseTicketsPageDto へ追加する T を立てる。課金モデル変更を伴わず二重課金防止に直接効くため、本 slice で最も先行させやすい項目。非管理者に resumeUrl を渋さない(aigenba の Codex R1 Critical と同じ罠)こと。

### ticket-charge-6: 残高不足→購入→復帰導線 (return_to / 訓練に戻る)

- category: flow / severity: low / blocksParity: False / requiresProductDecision: True
- 差分: aigenba は InsufficientTicketsException が organizationSlug を持ち購入ページへ 303+return_to で着地、PurchaseTickets が SafeReturnTo 検証済み path で「訓練に戻る」リンク(上部+購入完了後)を表示する。AI-CUE は InsufficientTicketsException はあるが return_to の購入ページ配線が無く、代わりに InsufficientTicketsResource(JSON) で非同期ジョブ(解析/レンダ)側にエラー返却する UX。同期的な「不足→買って戻る」導線が無い(製品が非同期ジョブ主体のため)。
- aigenba: app/Services/Billing/TicketService.php:1008-1017 ensureSufficient(organizationSlug 付与), app/Http/Controllers/Billing/BillingController.php:525 returnTo=SafeReturnTo::fromQuery, resources/js/pages/Billing/PurchaseTickets.svelte:173-184,313-324
- AI-CUE: app/Exceptions/Billing/InsufficientTicketsException.php(slug なし), app/Http/Resources/Billing/InsufficientTicketsResource.php, app/Services/Manual/AnalysisPipeline.php:289 で JSON エラー化
- action: 製品形態(非同期ジョブ vs 同期訓練)が異なるためそのまま写さない。ただし「残高不足でジョブが始まらない→購入ページへの導線が無い」は詰みになり得るので、InsufficientTicketsResource に購入ページへのリンクを載せるかを UX 判断として人に問う。

### ticket-charge-7: ルーティング/スコープ (org-slug vs current-org) とパンくず

- category: layout / severity: low / blocksParity: False / requiresProductDecision: True
- 差分: aigenba は /organizations/{slug}/billing/purchase-tickets の org-slug スコープで、PageHeaderSection に「請求 > 追加購入」breadcrumbs を持つ。AI-CUE は /purchase-tickets の current-org(セッション解決)スコープで breadcrumbs 無し、PageHeader のみ。外枠(AppLayout/PageContainer/PageContent)は両者とも T071 相当の primitive に沿っており、PlanCard 的な独自 UI は billing 購入画面には両者とも無い。差は breadcrumbs の有無と org 解決方式に集約される(billing 固有ではなく app 全体のナビゲーションモデル差)。
- aigenba: routes/web.php:458,473 /organizations/{organization}/billing/purchase-tickets, resources/js/pages/Billing/PurchaseTickets.svelte:32-35 breadcrumbs + :165-170 PageHeaderSection
- AI-CUE: routes/web.php:319-322 /purchase-tickets, app/Http/Controllers/Billing/TicketPurchaseController.php:37 ResolvesCurrentOrganization, resources/js/pages/Billing/PurchaseTickets.svelte:119-124 PageHeader(breadcrumbs 無し)
- action: current-org 方式は AI-CUE 全体の方針なので billing だけ slug 化しない。breadcrumbs の有無はサイト共通のナビゲーション方針として別 slice(レイアウト/ナビ)で一括判断するべき。本 slice では取らない。

### ticket-charge-8: spot 単価の出典 (ticket_prices vs TicketVolumePrice min_count=1)

- category: domain-model / severity: low / blocksParity: False / requiresProductDecision: False
- 差分: aigenba は spot(単発 ¥100)を独立 ticket_prices テーブル(lookup_key=config services.stripe.lookup_keys.ticket_unit, livemode/synced_at を production で必須化)で管理し、VOLUME_TIER_MIN_COUNT=20 未満は spot、20 以上を ticket_volume_prices で解決(該当 0 件は fail-closed 例外)。AI-CUE は単一 TicketVolumePrice に集約し min_count=1 行が spot を兼ね、currentTierFor が全段を権威解決する。floor(config billing.ticket_unit_price_floor)の Assert と signup grant config は両者共有で、機能的にはほぼ等価。
- aigenba: app/Models/Billing/TicketPrice.php, app/Services/Billing/TicketService.php:66 VOLUME_TIER_MIN_COUNT=20, :81-102 currentUnitPrice, :121-160 resolveVolumeTier
- AI-CUE: app/Models/Billing/TicketVolumePrice.php currentTierFor(min_count=1 が spot を兼ねる), app/Services/Billing/TicketPricingService.php:27-46 volumeTiersForDisplay, :52-55 spotUnitAmount
- action: AI-CUE の単一テーブル集約の方が二重管理が少なく、価格解決の振る舞い(floor 強制/fail-closed)も等価なのでこの差は合わせなくてよい。ただし production での livemode/synced_at 必須チェックが AI-CUE 側にあるかは TicketVolumePrice::currentTierFor を読んで別途確認する価値あり(未 sync Price での課金事故防止)。

### ticket-charge-9: 残高低下時のハンドリング (通知 vs 自動補充)

- category: domain-model / severity: low / blocksParity: False / requiresProductDecision: True
- 差分: AI-CUE は reserve 時に閾値クロス(config billing.ticket_low_balance_threshold)を検知し notifyTicketBalanceLow を afterCommit で発火する残高低下通知を持つ(aigenba には無い)。aigenba の TicketService.reserve にはこの通知が無く、代わりに commit 時(実際に -1 が書かれた経路のみ)の AutoRechargeTriggerJob で自動補充する。同じ「残高が減った」イベントへの応答方針が通知 vs 自動課金で正反対。
- aigenba: app/Services/Billing/TicketService.php:558-566 commit で AutoRechargeTriggerJob::dispatch (低残高通知は存在しない)
- AI-CUE: app/Services/Billing/TicketLedgerService.php:270-280 reserve で閾値クロス→notifyTicketBalanceLow (aigenba に無い AI-CUE 固有機能)
- action: AI-CUE 固有の低残高通知は aigenba に合わせるために削除しない(parity の名での機能後退になる)。finding #1 の自動補充を採用する場合に、通知と自動補充を併存させるか(補充成功時は通知不要等)をセットで人判断する。

### ticket-charge-10: 文言・単位 (枚 vs 回) と失効メッセージ

- category: copy-i18n / severity: medium / blocksParity: False / requiresProductDecision: True
- 差分: aigenba は単位を「回」(購入回数 / ¥○○ / 回 / ○回)で表記し、見出し「チケット追加購入」「月次上限を超えた分のチケットを追加で購入できます」、残高内訳に「プラン付与残(有効期限あり)」「購入済み残」と次回失効日を表示する。AI-CUE は単位が「枚」(購入枚数 / ○枚)、見出し「チケットを購入」、かつ「購入したチケットに有効期限はありません」を明示する(単一残高モデルのため月次付与の失効概念を購入画面から隠蔽)。AI-CUE の当該文言は purchased に限れば正しいが、grantMonthly/signup grant には expires_at があるため残高全体への誤読を誘い得る。
- aigenba: resources/js/pages/Billing/PurchaseTickets.svelte:167-169,218-232,264-289
- AI-CUE: resources/js/pages/Billing/PurchaseTickets.svelte:120-122,146-176,209-212 (「購入したチケットに有効期限はありません」), app/Services/Billing/TicketLedgerService.php:104-110 signup grant は期限付き
- action: 単位の「枚」→「回」は AI-CUE の製品語彙(チケット=解析/レンダの可変コスト)と矛盾するので機械的に合わせない(人判断)。一方で「購入したチケットに有効期限はありません」は月次/signup grant 分には期限がある事実と並べると誤誤を招くため、finding #2 の残高分割判断とセットで文言を見直す。

### ticket-charge-11: サービス分割構造 (単一 TicketService vs 4 分割) と冪等 Checkout マシン

- category: other / severity: low / blocksParity: False / requiresProductDecision: False
- 差分: aigenba は 1495 行の単一 TicketService に会計(reserve/commit/release/clawback)・価格解決(resolveVolumeTier/volumeTiers)・Checkout 生成(createPurchaseCheckout/resolveResumablePurchase)・付与(signup/monthly/purchased/autoRecharge)を集約。AI-CUE は TicketLedgerService(会計)/TicketPricingService(表示価格)/TicketCheckoutService(冪等 Checkout)/CashierTicketCheckoutGateway+FakeTicketCheckoutGateway(Stripe 境界)に責務分割し、tier 権威解決を TicketVolumePrice::currentTierFor へ一元化。冪等 Checkout マシン自体は両者ほぼ同型(attempt_token 冪等 + live pending dedup + 別 count pending の Stripe expire + unique 違反 re-read 収束で 500 にしない + webhook 冪等)で、AI-CUE 側はさらに expires_at pin による期限切れ pending の回収も持つ。
- aigenba: app/Services/Billing/TicketService.php (単一 1495 行), :1163-1382 createPurchaseCheckout
- AI-CUE: app/Services/Billing/{TicketLedgerService,TicketPricingService,TicketCheckoutService,TicketCheckoutGateway,CashierTicketCheckoutGateway,Fakes/FakeTicketCheckoutGateway}.php, TicketCheckoutService.php:91-206 startCheckoutLocked
- action: AI-CUE の分割構造(特に Gateway 抽象 + Fake)は aigenba より見通しが良く、冪等の実質も同等以上なので単一 Service への逆行はしない。以降の parity 作業(#1/#2/#5)はこの 4 分割の境界のまま実装すること(会計=TicketLedgerService、導線/状態=Controller+DTO、Stripe=Gateway)。
