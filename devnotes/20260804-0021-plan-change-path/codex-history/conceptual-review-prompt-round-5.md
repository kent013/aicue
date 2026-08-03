Round 4 の Critical 1 件を設計へ反映しました。反論はありません。

## 対応マトリクス

| 指摘 | 判断 | 対応 |
|---|---|---|
| [Critical] 「remote price が同一なら update を送らない」不変条件を `CashierStripeGateway` を通して検証するテストが無い (禁止事項 1 未充足) | 対応 | 検証方針に**層 0 (gateway 自体のテスト)** を追加。`Cashier::stripe()` の取得を protected seam (`stripe(): StripeClient`) に切り出し、partial mock で `Mockery::mock(StripeClient::class)` を差し込む (`StripeClient::__get()` は public なので service プロパティを stub でき、subscription object は `\Stripe\Subscription::constructFrom([...])` で組み立てるためネットワークに出ない)。固定する事項: (a) remote base item price が対象と同一 → `subscriptions->update()` は **0 回** / 戻り値 `AlreadyOnTargetPrice` (b) 異なる → `update()` が **1 回**・期待 payload と idempotency key で呼ばれ戻り値 `Applied` (c) `retrieve` と `update` が同一 subscription id を使う。実装方針にも seam の追加を明記 |

## 更新後の概念設計 (全文)

# 概念設計: plan-change-path (契約済み組織のプラン変更経路)

出典: `devnotes/20260803-203721-bug-hunt/report.md` の **F-3-01 (Critical)** /
shard 詳細 `devnotes/20260803-203721-bug-hunt/shard-3/shard-report.md#F-1`。

## 背景・課題

### 観測された破綻 (bug-hunt 実走)

`owner-starter@example.com` (Starter 契約中) が `/billing/plans` で Standard の
「このプランへ変更」→ 確認ダイアログ「変更する」を押すと、`/billing/plans` に戻され
**「既に有効なサブスクリプションがあります。プラン変更をご利用ください。」** という
赤い alert が出る。**エラー文言が指す「プラン変更」機能はアプリのどこにも存在しない**
(循環案内 = 行き止まり)。

### コードで裏取りした事実

| # | 事実 | 出典 |
|---|---|---|
| 1 | `startCheckoutLocked` 段 1 が `Assert::true(! $existing->valid(), '既に有効なサブスクリプションがあります。プラン変更をご利用ください。')` で**有効サブスクを一律拒否** | `app/Services/Billing/SubscriptionService.php:348-353` |
| 2 | `canSwitchTo()` は権限 / 現在プラン / personal しか見ず、**既存契約の有無を見ない**ので CTA は常に活性 | `resources/js/pages/Billing/Plans.svelte:44-49` |
| 3 | `app/Services/Billing/` に swap / subscription update 相当のメソッドが**存在しない** | `grep -n "function "` で全 Service を確認 |
| 4 | Customer Portal は `subscription_update => ['enabled' => false]` を**明示指定**。docblock は「プラン変更は**アプリ側 (Checkout / Subscription Schedule) が所有**しており、Portal で直接変更されると plan_code / schedule 整合が壊れる」と宣言 | `app/Services/Billing/PortalConfigurationSpec.php:10-13,38` |
| 5 | 決済 parity の乖離台帳に **A-6「`SubscriptionService` の schedule lifecycle / seat / signup funding / `changePlan` / `upgradeNow` / `isMutableState` を非移植。理由: 設計スコープ外 (席・schedule 機構が無い)」** | `devnotes/20260717-0035-aigenba-billing-parity/aigenba-divergence-ledger.md:82` |
| 6 | quota 超過エラーの文言自体が **「プランのアップグレードをご検討ください。」** とプラン変更を案内している | `app/Exceptions/Billing/QuotaExceededException.php:17` |
| 7 | `/billing/plans` の見出し説明文は「**現在のプランの変更**・新規契約ができます」 | `app/Http/Controllers/Billing/BillingController.php:140-158` + `Plans.svelte:104` |

**結論**: アプリの 3 箇所 (Portal spec の docblock / quota 超過文言 / plans 画面の説明文と CTA) が
「アプリがプラン変更を所有している」前提で書かれているのに、**その実装だけが存在しない**。
台帳 A-6 の非移植理由 (「席・schedule 機構が無い」) は、`changePlan` 本体が席にも schedule にも
依存しない (むしろ aigenba の `changePlan` は schedule 管理下の契約を**拒否**する) ため、
**`changePlan` を落とす根拠としては成立していなかった**。= 意図的な仕様ではなく積み残し。

### 成功条件 (この設計が満たせたら成功と判断する基準)

1. Starter 契約中の組織が `/billing/plans` から Standard へ変更でき、逆方向 (Standard→Starter) も
   同じ画面で完了する (paid→paid の双方向)。
2. 「既に有効なサブスクリプションがあります。プラン変更をご利用ください。」という循環案内が
   起きない (契約中の組織の CTA は変更経路へ、未契約の組織は従来どおり Checkout へ)。
3. 変更が Stripe に受理されてから `organizations.plan_code` が webhook で追随するまでの
   数分間、利用者が「受け付けられたのか / 失敗したのか」を判断できる。

**「アプリがプラン変更 UX を完全所有する」ことは目的ではない** (結果としてそうなるだけ)。
判断基準は上記 3 点であり、これを最小コストで満たす案を採る。

### 使命 (North Star) との関係

AI-CUE の使命は「思考ゼロ・編集ゼロ」で現場が標準化動画を作れること。プラン変更は使命の
直接の担い手ではないが、**上位プランへ行けない = プロジェクト数 / 保存容量の上限を上げられない**
= 現場が作れる動画マニュアルの量が構造的に頭打ちになる。かつ quota 超過時にアプリ自身が
「アップグレードをご検討ください」と案内する以上、**その導線が存在しないことは使命の遂行を阻害する**。

## 選択肢の比較 (本設計の中心的な問い)

### 案 1: in-app swap を実装する (Stripe Subscription Update) — **推奨**

`SubscriptionService::changePlan()` を新設し、`Cashier::stripe()->subscriptions->update()` で
base price を差し替える。移植元 aigenba に**実績のある実装がある** (`/tmp/aigenba`
`app/Services/Billing/SubscriptionService.php:995-1110` / `CashierStripeGateway::swapSubscriptionPrices`
`:181-239`)。

**「本当に今必要か (思考原則 2)」の検証**:

- 必要性: BILL-02 (プラン申込・変更) は課金画面の中核ジョブで、**既存有償契約者全員**が
  upgrade / downgrade を一度も完了できない。上位プランへ行けない = 収益機会の直接損失でもある。
- 重さの実測: AI-CUE には aigenba の重い部分が**そもそも無い**ため、移植コストは aigenba より
  大幅に小さい。
  | aigenba で `changePlan` を重くしていた要素 | AI-CUE | 出典 |
  |---|---|---|
  | 席課金 (`included_seats` / seat price / overflow 計算) | **無い** | 台帳 A-1 |
  | `pending_plan_code` (予約済みプラン変更) | **列が無い** | `SubscriptionState.php:26-28` |
  | Subscription Schedule の作成経路 | **無い** (reconcile 観測のみ) | `ReconcileSubscriptionSchedules.php:27-29` |
  | 月次チケット付与のプラン差分 | **全 tier 0 枚 (D28)** = 差分ゼロ | `PlanSeeder.php:40-43` |
  | trial / campaign | **無い** | 台帳 A-2 |
  → 残るのは「1 item の price を差し替える」だけ。
- 冪等性 (セキュリティ不変条件 #7): Stripe へ渡す idempotency key は
  **`change-plan:{plan_change_token}:{plan_code}`**。`plan_change_token` は
  `/billing/plans` の **1 render につき 1 個**サーバが発行する ULID
  (既存の `subscriptionAttemptToken` / `ticketAttemptToken` / `autoRechargeSetupToken` と
  同じ「操作ごとに別 key 空間」の作法。DB 行は作らない = Checkout Session の冪等マシンとは
  別物なので流用しない)。
  - **同一 render からの二重送信 (ダブルクリック / 再送)** は同一 key に収束 = Stripe が
    replay して二重の proration を作らない。
  - **A→B→A→B の往復 (ABA)** は render が変わるたび token が変わるため別 key になり、
    3 回目の変更が「1 回目の replay」として握り潰されない。
  - 同一 token で別プランを押した場合 (戻るボタン等) は key に `plan_code` が入るので
    Stripe の「同一 key・別パラメータ」エラーにならない。
  - **チケットの増減が無いので reserve→commit の 2 フェーズは無関係**。
  - **token が守れない範囲を明示する**: 成功後 `/billing` へ redirect し、webhook 未到達のまま
    `/billing/plans` を再表示すると**新しい token が発行され、画面は旧プランのまま**なので、
    利用者は同じ変更をもう一度押せる。このとき key は別物になるため冪等 key では守れない。
    → **remote 現在 price との照合で構造的に潰す** (下記)。
- **二重 proration の構造的防止 (remote 状態との照合)**: swap の実行前に Stripe から
  subscription を `expand=items.data` で取得する (既存 item id の解決に**どのみち必要**な
  1 回の read)。取得した base item の price が**既に対象 price と同一なら update を送らない**
  (`AlreadyOnTargetPrice` として正常終了)。
  - ローカルの `subscriptions.stripe_price` / `organizations.plan_code` は webhook 同期のため
    ラグがあり、この判定には使えない (remote が唯一の真実源)。
  - これにより「反映待ち中の再操作」「idempotency key の保持期間 (24h) 超過後の再操作」も
    二重 proration を作らない。冪等 key は同一 render の二重送信を、remote 照合は
    それ以外のすべてを担当する (二層防御)。
- proration: **`proration_behavior=create_prorations`** (Stripe 既定)。日割り調整額は
  **次回請求に反映される** (upgrade は差額加算、downgrade は繰越クレジット)。
  **`always_invoice` は採らない**: 即時請求は与信失敗 → `incomplete` / `past_due` /
  `pending_update` という決済状態遷移と、それを受ける UI・webhook 設計を丸ごと呼び込む。
  今必要なのは「プランを変えられること」であり、即時徴収ではない (思考原則 2)。
  自前の日割り計算も書かない (思考原則 1)。
  - **前提の限定**: 「その場では請求されない」が成立するのは
    **同一請求間隔 (月次) / 同一通貨 (jpy) の base price 1 item の差し替え**という
    AI-CUE の条件下である。即時請求を誘発しうるパラメータ
    (`billing_cycle_anchor` / `trial_end` / `payment_behavior` の明示指定等) は**送らない**
    ことを gateway の payload 単体テストで pin する。
- **成功の定義**: 本設計での「成功」は **accepted** (Stripe が subscription の更新を受理して
  200 を返した = Stripe 側では適用済み) までを指す。`organizations.plan_code` の追随は
  **projection_synced** (webhook 到達時) と呼び分ける。上記の前提下では
  `payment_action_required` は発生しない。UI 文言はこの区別をそのまま表す
  (「変更を受け付けました。反映まで数分かかる場合があります。」)。
- quota 縮小時の既存データ: AI-CUE の quota は**作成時チェックのみ** (`QuotaService::check` /
  `checkAddition`) で既存データを消さない。かつ **解約経路 (Portal の subscription_cancel は
  有効) では既に「上限が下がるが既存データは残る」状態が発生しうる**。したがって downgrade で
  同じ状態を作ることは新しい破綻ではない。→ **ブロックしない**。確認ダイアログで事実
  (既存データは消えない / 上限内に戻るまで新規作成・アップロードができない) を伝える。

### 案 2: Portal の `subscription_update` を再開放する — 却下

**封じた理由の追跡結果**: 明文の根拠は `PortalConfigurationSpec.php:10-13` の docblock のみ
(「plan_code / schedule 整合が壊れる」)。devnotes の parity 設計・台帳には Portal 側の
プラン変更に関する議論は無く、docblock は aigenba からほぼ verbatim に持ち込まれている
(aigenba 側は `:11` で「プラン変更はアプリが `changePlan` / `upgradeNow` / schedule で所有」と
書いており、**アプリ側実装があることが前提の宣言**)。

**理由が今も有効か**:

- `plan_code` 整合: **AI-CUE では壊れない**。webhook `customer.subscription.updated` →
  `applySubscriptionSnapshot` が `items.data.0.price.id` から plan を逆引きして
  `organizations.plan_code` を同期する (`SubscriptionService.php:163-213`,
  `StripeWebhookProcessor.php:225-260`)。AI-CUE の subscription は 1 item (席が無い) なので
  index 0 固定でも取りこぼさない。→ **docblock の懸念の前半は AI-CUE では成立しない**。
- schedule 整合: AI-CUE に schedule の作成経路が無い以上、衝突する相手がいない。→ 同上。
- **却下理由 (「運用コスト感」ではなく、Portal では満たせない要件)**:

  **(a) Portal は「1 行の boolean 反転」では開かない。開くと課金金額の真実源が二重化する。**
  Stripe の `POST /v1/billing_portal/configurations` は
  `features.subscription_update` を有効にする場合 **`default_allowed_updates` と
  `products: [{product, prices: [...]}]` の指定が必須** (price だけでなく **product id** が要る)。
  AI-CUE は **product id をどこにも保持していない** — `plan_prices` の列は
  `stripe_price_id` / `lookup_key` / `amount` / `currency` / `is_current` のみ
  (`database/migrations/2026_06_11_091100_create_plans_tables.php:46-47,71-72`)。
  つまり案 2 は「product id を取得・保持する列と同期経路の新設」から始まる。
  さらに致命的なのは列挙の**鮮度**で:
  - 価格改定は `PlanPriceService::replaceCurrent()` が旧 current 行を
    `is_current=false` にして新行を差し込む (旧 Price 行は履歴として**残る**)。
    Portal configuration の `prices` 列挙を同時に張り替えなければ、**旧価格の Price へ
    Portal から移行できてしまう** (`resolvePlanCodeFromPriceId` は kind=base の行を
    price_id で引くので plan_code は解決でき、**誤りに気づけないまま旧価格で課金され続ける**)。
  - `plans.is_active=false` (販売停止) はアプリ側のフィルタ
    (`PricingService::listPublicPlans` / checkout の `where('is_active', true)`) でしか効かない。
    Portal 列挙には効かず、**販売停止プランへ移行できてしまう**。
  - 既存の drift 検知 `billing:ensure-portal-configuration --verify` は
    **`subscription_update.enabled === false` かどうかしか見ない**
    (`EnsurePortalConfiguration.php:52-58`)。products 列挙の drift を検証する仕組みは無く、
    新規に作る必要がある。
  → これは「価格と販売可否の正しさ」に関わる**機能要件の不足**であり、運用感覚の話ではない。
  対して案 1 は `$plan->currentPrice(PlanPriceKind::Base)` を**リクエスト時に解決**するため、
  現行価格・`is_active` フィルタが構造的に効く (新しい真実源を作らない)。

  **(b) 変更の可否・理由をアプリが説明できない。**
  AI-CUE は「必須条件未充足でも押させて、押下時に理由を出す」を規約にしている (禁止事項 #8)。
  `past_due` / `paused` / schedule 管理下の契約に対する拒否理由、downgrade 時の上限低下の告知、
  `personal` (無料) は解約経路であるという説明は、いずれも Stripe hosted 画面には載せられない。
  さらに Portal は **`payment_method_update` / `subscription_cancel` と同一画面**であり、
  「プランを上げに来た利用者に解約ボタンを併置する」導線になる。

  **(c) `/billing/plans` の存在意義と衝突する。**
  プラン比較画面が既にあり、そこに CTA も文言もある。案 2 はその CTA を「Stripe へ飛ぶ」に
  変え、比較 → 変更を別ドメインの画面に分断する。

  **(d) 宣言との整合。**
  案 1 は `PortalConfigurationSpec` の宣言 (「プラン変更はアプリが所有」) を**初めて真にする**。
  案 2 はその宣言・`PortalConfigurationTest`・`docs/architecture.md:140` を同時に反転させる。

- **「Phase 0 = Portal 再開放 → Phase 1 = in-app swap」という段階案も採らない**:
  (a) より Phase 0 自体が最小ではない (product id 保持 + 列挙同期 + drift 検証の新設)。
  そのうえ Phase 1 で閉じ直すなら、**作ってすぐ消す機構**を 1 系統ぶん増やすことになり、
  その間は「Portal でも変えられる / アプリでも変えられる」二重経路が並走する
  (思考原則 3「後方互換の並走を残さない」に真正面から反する)。
  一度 Portal で変更できた顧客の期待を後で取り上げることにもなる。

### 案 3: UI を現仕様に合わせる (暫定) — 却下 (単独では成立しない)

CTA を非活性化する案は **禁止事項 #8 に真正面から抵触する** (必須条件未充足を理由に
disabled にしない)。押下時に理由を出す形にしても、**「行き先」が存在しない**:

- Portal は `subscription_update` 無効 = プラン変更に使えない。
- 「解約 → 再契約」は Portal の解約が `mode=at_period_end` (`PortalConfigurationSpec.php:39`)
  のため、**次の請求期日まで数週間サービスが宙に浮く**うえ、`customer.subscription.deleted` で
  `plan_code` が null に落ちて課金ゲートに遮断される。upgrade したいだけの利用者に
  この経路を案内するのは新しい詰みを作る。
- 「問い合わせ導線」は BILL-02 をセルフサービスで達成させない = bug-hunt が指摘した
  ジョブ阻害そのものが残る。

→ 案 3 は**案 1 が長期化する場合の一時策**としてのみ意味を持つが、案 1 の実装量が小さい
(下表) 以上、暫定策を挟むと後方互換の並走 (思考原則 3) を自分で作ることになる。**採らない**。

### 検討した縮小案: 「upgrade だけ先に通す」 — 採らない

Critical を閉じる最小形として「upgrade のみ許可し downgrade は後回し」も検討したが、
**コードは減らず、新しい行き止まりを作る**:

- swap の実装は方向に依存しない (同一の `subscriptions->update`)。upgrade 限定にするには
  **「目標プランの金額 > 現在プランの金額」を判定するフィルタを追加する** = 実装は**増える**。
- 「間違って上位プランにしてしまった組織が戻れない」という、F-3-01 と**同じ種類の行き止まり**を
  新たに作る。bug-hunt が指摘したのは「片方向が塞がっている」ことではなく「経路が無い」こと。
- downgrade は既存データを消さない (quota は作成時チェックのみ) ため、データ損失リスクは無い。

→ 双方向を 1 つの経路で提供するのが最小。ただし downgrade の確認ダイアログには
**上限が下がる旨の明示的な告知**を必須にする (下記 UI 方針)。

### 判定

**案 1 を採用**。案 2 は「Stripe 側に第 2 の価格真実源を作る」コストと UX 制御の喪失で却下、
案 3 は行き先が存在せず BILL-02 を回復しないため却下。
**Portal の `subscription_update=false` は維持する** (反転ではなく、宣言どおり
「プラン変更はアプリが所有する」を実装で満たす)。

## 改善アイデア

1. **`StripeGatewayInterface::swapSubscriptionPrices()`** を追加し、Cashier 実装 (実 Stripe) と
   Fake 実装 (fake_externals / bug-hunt) の 2 実装を揃える。
   - 戻り値は **`SubscriptionSwapOutcome` enum (`Applied` / `AlreadyOnTargetPrice`)**。
     Stripe SDK の object をこの境界より外へ出さない。
   - **例外変換は「想定された外部障害」だけ**: `\Stripe\Exception\ApiErrorException`
     (API / 通信 / 認証系はすべてこの派生) のみを `PlanChangeFailedException` に変換する。
     `TypeError` や実装バグは握り潰さず 500 に落として調査対象にする。
   - `Cashier::stripe()` の取得は **protected seam** (`stripe(): StripeClient`) に切り出し、
     gateway 単体テストから Stripe client を差し替え可能にする (実ネットワークに出ない)。
   - 送信 payload の組み立ては**純関数メソッド** (`buildSwapPayload()`) に分け、
     既存 `buildSubscriptionSessionPayload()` の invariant テストと同型の gateway 単体テストで
     `items[0].id/price` / `proration_behavior=create_prorations` / idempotency key /
     **`billing_cycle_anchor` 等の即時請求誘発パラメータを送らないこと**を固定する。
2. **`SubscriptionService::changePlan()`** を新設。org 単位 Cache lock 下で
   (a) 契約再読込 → (b) stale UI 検知 → (c) 変更可能 state 判定 → (d) schedule 管理下の拒否 →
   (e) 同一プラン no-op → (f) 決定的 idempotency key で Stripe update、の順に倒す。
3. **`POST /billing/plan` (`billing.plan.change`)** を追加。`manageBilling` 必須。
   payload は `plan_code` + `current_plan_code` (stale 検知用) + `plan_change_token`
   (1 render 1 個の ULID = idempotency key の素) の 3 つ。
4. **`Billing/Plans.svelte`** を分岐させる: 有効な契約がある組織は新 route へ、無い組織は
   従来の `POST /billing/checkout` へ。CTA は**両方とも活性のまま** (禁止事項 #8)。
   確認ダイアログの文言は 3 通りに出し分ける:
   - 新規契約 (契約なし): 現行文言 (Stripe の支払い画面へ移動する旨)。
   - upgrade (目標プランの基本料金 ≥ 現在プラン): 即時にプランが切り替わり、**日割り差額は
     次回請求に反映される**旨 + 反映待ちの告知。
   - **downgrade (目標プランの基本料金 < 現在プラン): 上記に加え「新プランの上限
     (プロジェクト / メンバー / 保存容量) を超えている場合、既存データは削除されませんが、
     上限内に収まるまで新規作成・アップロードができません」を必須表示**。
   金額比較は**文言選択のためだけ**に使う (可否判定はサーバのみ)。
5. **`organizations.plan_code` は引き続き webhook が唯一の writer** とし、in-app swap では
   書かない。成功時は `/billing` へ redirect + flash で
   「プラン変更を受け付けました。反映まで数分かかる場合があります。」を出す
   (`AlreadyOnTargetPrice` のときは「このプランへの変更は受付済みです。反映まで数分…」)。
   **二重 proration が起きないこと**を設計の不変条件として固定する
   (同一 render の再送 = 冪等 key / 反映待ち中の再操作 = remote price 照合の二層)。

## 期待効果

- **BILL-02 の回復**: 既存有償契約者が upgrade / downgrade を `/billing/plans` で完了できる
  (bug-hunt F-3-01 の Critical 解消)。
- **循環案内の解消**: 「プラン変更をご利用ください」「プランのアップグレードをご検討ください」
  が指す先が実在するようになる。
- **使命への貢献**: quota (プロジェクト数 / 保存容量) を上げる手段が生まれ、現場が作れる
  マニュアル動画の量的上限を利用者自身で外せる。
- **宣言と実装の一致**: `PortalConfigurationSpec` の「プラン変更はアプリが所有する」が真になる。

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `app/Services/Billing/Contracts/StripeGatewayInterface.php` | `swapSubscriptionPrices()` 追加 |
| `app/Services/Billing/CashierStripeGateway.php` | 既存 item id 解決 + `subscriptions->update` (`proration_behavior=create_prorations`, idempotency key) |
| `app/Services/Billing/Fakes/FakeStripeGateway.php` | no-op 実装 (中立帰還契約を維持) |
| `app/Services/Billing/SubscriptionService.php` | `changePlan()` 追加 (段 0〜5 の guard 列) |
| `app/Exceptions/Billing/StalePlanChangeException.php` | 新設 (422 へ倒すための識別) |
| `app/Exceptions/Billing/PlanChangeFailedException.php` | 新設 (`ApiErrorException` のみを変換して Controller へ渡す) |
| `app/Enums/Billing/SubscriptionSwapOutcome.php` | 新設 (`Applied` / `AlreadyOnTargetPrice`) |
| `app/Http/Requests/Billing/ChangePlanRequest.php` | 新設 (`plan_code` / `current_plan_code` / `plan_change_token`) |
| `app/Http/Controllers/Billing/BillingController.php` | `changePlan()` action 追加 + 例外マッピング |
| `routes/web.php` | `POST /billing/plan` を billing.* の構造的 allowlist 内に追加 |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php` | `hasChangeableSubscription: bool` / `planChangeToken: string` 追加 |
| `resources/js/types/billing.ts` | `BillingPlansPageProps` に同フィールド追加 (exact 対を維持) |
| `resources/js/pages/Billing/Plans.svelte` | 送信先分岐 + 文言 |
| `lang/ja/validation.php` | `current_plan_code` / `plan_change_token` の attribute 追加 |
| `.claude/skills/app-bug-hunt/operations.md` | 新 POST route をインベントリに追記 (drift 検知対応) |
| `docs/architecture.md` | 「サブスク契約 Checkout とオンボーディング着地」節にプラン変更経路を追記 |

## 制約・前提

- **`organizations.plan_code` の writer は webhook 同期 1 本**
  (`SubscriptionService::applySubscriptionSnapshot`)。swap 経路では書かない
  (`BillingController` docblock `:58-59` / `Organization.php:39-41` の既存契約)。
- **Stripe への外向き呼び出しは Gateway interface 越し**。fake_externals 環境
  (bug-hunt / Browser テスト) では `FakeStripeGateway` が bind される。
- **禁止事項 #8**: CTA を disabled にしない。変更不可の理由は押下時 Alert + caption。
- **禁止事項 #4**: 応答は Inertia / redirect + flash。`response()->json()` を使わない。
- **セキュリティ不変条件 #1**: `plan_code` は「購入意図」であり tenant / 状態キーではない
  (既存 `BillingCheckoutRequest` と同じ扱い)。`current_plan_code` も**認可には使わない**
  (stale UI 検知専用) ことを docblock で明示する。
- **セキュリティ不変条件 #3**: current-org スコープ (route parameter 無し) を維持する。
- **セキュリティ不変条件 #7**: 外向き mutation は決定的 idempotency key を必ず伴う。
- **stale UI 検知 (`current_plan_code`) は UX 専用**。変更の可否・対象の決定は
  **サーバが読んだ subscription 状態と plan 台帳だけ**で閉じる (client hint に依存させない)。
- **fake 環境での検証方針** (責務を 3 層に分ける):
  0. **remote price 照合の制御フロー** = `CashierStripeGateway` 自体のテスト。
     `Cashier::stripe()` の取得を **protected seam** (`stripe(): StripeClient`) に切り出し、
     partial mock で `Mockery::mock(StripeClient::class)` を差し込む
     (`StripeClient::__get()` は public なので service プロパティを stub できる。
     subscription object は `\Stripe\Subscription::constructFrom([...])` で組み立て、
     ネットワークに出ない)。固定する事項:
     - remote の base item price が対象と**同一** → `subscriptions->update()` は **0 回** /
       戻り値 `AlreadyOnTargetPrice`
     - **異なる** → `update()` が **1 回**・期待 payload と idempotency key で呼ばれ、
       戻り値 `Applied`
     - `retrieve` と `update` が**同一 subscription id** を使う
  1. **何を Stripe に送るか** = `CashierStripeGateway::buildSwapPayload()` の純関数単体テスト
     (既存 `buildSubscriptionSessionPayload` の invariant テストと同型)。
  2. **Service が gateway を正しい引数で 1 回だけ呼ぶか** = `StripeGatewayInterface` を
     mock した Feature テスト (既存 `ReconcileSubscriptionSchedulesTest` の
     `$this->mock(StripeScheduleGateway::class)` と同型)。ABA 往復で idempotency key が
     変わること、**「成功 → 再表示 (webhook 未到達) → 同じ変更を再送」で
     `AlreadyOnTargetPrice` に落ちて update を送らないこと**もここで固定する。
  3. **変更が反映されるか** = swap 実行 → `customer.subscription.updated` payload 注入 →
     `organizations.plan_code` 追随 の webhook 注入テスト
     (既存 `tests/Feature/Billing/SubscriptionSnapshotSyncTest.php` と同型)。
  `FakeStripeGateway` は「実 Stripe を呼ばない」表明に留め状態を変えない (既存の中立帰還契約)。
  bug-hunt 環境では webhook が発火しないため、観測できるのは「反映待ち」表示までであることを
  設計上明記する。
- PHPStan level 10 / Pest / `RefreshDatabase` グローバル適用。

## スコープ外

- **予約型プラン変更 (期末反映 / Subscription Schedule)**: 列 (`pending_plan_code`) も
  作成経路も無い。即時 swap のみを提供する。
- **proration のプレビュー表示** (Stripe の upcoming invoice 照会): 「あったら便利」であり
  今必要ではない (思考原則 2)。
- **downgrade の quota 超過ブロック**: 解約経路と非対称な新ルールになるため作らない
  (確認ダイアログでの告知に留める)。事業判断が要る点として open question に残す。
- **`personal` (無料) への変更**: 解約 (Portal) 経由のまま。有償 → 無料は subscription の
  終了であって swap ではない (別物の概念を統合しない)。
- **Enterprise**: `requiresStripeCheckout()=false` = 問い合わせ営業のまま。
- **Portal configuration の変更**: `subscription_update=false` を維持する。
- bug-hunt の他 finding (F-3-02 以降、着地バナー等) は本設計の対象外。
