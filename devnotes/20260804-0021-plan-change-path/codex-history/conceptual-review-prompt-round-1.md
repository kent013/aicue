【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【セキュリティ不変条件 (アプリ都合で緩めない) — AGENTS.md より】

1. tenant キー不信: ownership/actor/tenant キーを payload から受け取らない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可
4. untrusted 文字列は UserInput 型経由でのみ prompt に入れる
5. 権限判定は常に `laratrust_team_id` を明示
6. PII(email/name)は CipherSweet
7. **課金の冪等性**: webhook は冪等マシン経由、チケットは reserve→commit/release の 2 フェーズ
8. 外部 URL 取得は SSRF 検査経由

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

AI-CUE 固有の思考原則: (1) フレームワークのレンジ内でやる (自前機構の前に Laravel / 同梱モジュールの公式作法を確認する) (2) 今必要なものだけ作る (オーバーエンジニアリング禁止) (3) 後方互換の並走を残さない (4) 別物の概念を「似ているから」で統合しない (5) テストファースト (6) タコツボ実装を避ける。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js + Cashier/Stripe）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の依頼】
本件は探索的バグハントで見つかった Critical (契約済み組織のプラン変更経路が in-app にも Stripe Customer Portal にも存在しない) への設計である。概念設計では 3 案を比較して 1 案を推奨している。**とくに「案 1 (in-app swap) は本当に今必要か」「案 2 (Portal 再開放) の却下理由は妥当か」「案 3 (UI 暫定対応) を却下した判断は妥当か」を厳しく検証してほしい。**採用案が過大 (オーバーエンジニアリング) だと判断する場合は、その根拠と代替の最小案を具体的に示すこと。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 参考: リポジトリ内の関連事実 (レビュー時の前提。すべてコードで確認済み)

- `app/Services/Billing/SubscriptionService.php:348-353` (startCheckoutLocked 段 1):
  ```php
  $existing = $org->subscription('default');
  Assert::true(
      ! $existing instanceof Subscription || ! $existing->valid(),
      '既に有効なサブスクリプションがあります。プラン変更をご利用ください。'
  );
  ```
- `resources/js/pages/Billing/Plans.svelte:44-49`:
  ```ts
  const canSwitchTo = (plan: PricingPlanShape): boolean => {
      if (!page.canManage) return false;
      if (page.currentPlanCode === plan.code) return false;
      if (isPersonal(plan)) return false;
      return true;
  };
  ```
- `app/Services/Billing/PortalConfigurationSpec.php:10-13,38`: `subscription_update => ['enabled' => false]`。
  docblock「プラン変更はアプリ側 (Checkout / Subscription Schedule) が所有しており、Portal で直接変更されると plan_code / schedule 整合が壊れるため」。
- webhook 同期 (`SubscriptionService::applySubscriptionSnapshot` / `StripeWebhookProcessor::syncSubscriptionState`) は
  `data.object.items.data.0.price.id` から plan を逆引きし、status が active/trialing のとき `organizations.plan_code` を同期する。
  AI-CUE の subscription は 1 item (席課金が無い)。`organizations.plan_code` の writer はこの webhook 経路だけ。
- AI-CUE のプランは `personal` (無料 / Stripe Checkout 対象外) / `starter` (¥980) / `standard` (¥4,980) の 3 つ。
  `monthly_ticket_grant` は全 tier 0 (D28 で月次付与を廃止済み)。席課金・trial・campaign・`pending_plan_code` は存在しない。
- Subscription Schedule は「reconcile コマンドによる観測・復旧」だけが存在し、**作成経路が無い**
  (`app/Console/Commands/Billing/ReconcileSubscriptionSchedules.php:27-29`: 「phases の再構築はアプリのプラン移行ドメイン知識が必要なためテンプレートでは行わない」)。
- quota は作成時チェックのみ (`QuotaService::check` / `checkAddition`)。既存データを遡って削除する機構は無い。
  `QuotaExceededException` の文言は「現在のプランの上限 (…) に達しています。プランのアップグレードをご検討ください。」
- 移植元アプリ (aigenba) には実績のある `SubscriptionService::changePlan()` + `CashierStripeGateway::swapSubscriptionPrices()`
  (proration_behavior=create_prorations / 決定的 idempotency key / 既存 item id 指定 update) がある。
  決済 parity の乖離台帳には「A-6: `changePlan` / `upgradeNow` / `isMutableState` を非移植。理由: 設計スコープ外 (席・schedule 機構が無い)」とだけ記録されている。

---

## 概念設計

<!-- ここから devnotes/20260804-0021-plan-change-path/conceptual-design.md 全文 -->
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
- 冪等性 (セキュリティ不変条件 #7): 決定的 idempotency key
  `change-plan:{stripe_id}:{period_end}:{plan_code}:swap` を Stripe へ渡す (aigenba 同型)。
  **チケットの増減が無いので reserve→commit の 2 フェーズは無関係**。attempt_token 冪等マシンは
  「Checkout Session を作る」ための機構であり swap には Session が無いため**流用しない**
  (別物の概念を似ているからで統合しない = 思考原則 4)。
- proration: `create_prorations` (Stripe 既定の日割り。upgrade は即時差額請求、downgrade は
  次回請求への繰越クレジット)。**自前の日割り計算は書かない** (思考原則 1)。
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
- **しかし採らない理由**:
  1. Portal の subscription_update は configuration に**変更可能な product / price を列挙**する
     必要があり、価格台帳 (`plan_prices` / `StripePriceLookupKeys` / `billing:sync-stripe-prices` /
     `billing:verify-stripe-prices`) と**別の真実源が Stripe 側に生える**。drift 検知機構を
     もう 1 系統作ることになり、案 1 より重い。
  2. 変更の可否・理由をアプリが説明できない (downgrade 時の上限低下の告知、`personal` (無料) を
     Portal から選ばせない制御など)。**押下時にエラー・理由を出す** という AI-CUE の UX 規約
     (禁止事項 #8) を Stripe hosted 画面には適用できない。
  3. `/billing/plans` の CTA を「Stripe へ飛ぶ」に変えることになり、プラン比較 → 変更が
     同一画面で完結しなくなる。
  4. 案 1 を採れば docblock の宣言 (「プラン変更はアプリが所有」) が**初めて真になる**。
     案 2 はその宣言を反転させ、`PortalConfigurationTest` / `billing:ensure-portal-configuration` /
     `docs/architecture.md:140` の記述も同時に書き換える必要がある。

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

### 判定

**案 1 を採用**。案 2 は「Stripe 側に第 2 の価格真実源を作る」コストと UX 制御の喪失で却下、
案 3 は行き先が存在せず BILL-02 を回復しないため却下。
**Portal の `subscription_update=false` は維持する** (反転ではなく、宣言どおり
「プラン変更はアプリが所有する」を実装で満たす)。

## 改善アイデア

1. **`StripeGatewayInterface::swapSubscriptionPrices()`** を追加し、Cashier 実装 (実 Stripe) と
   Fake 実装 (fake_externals / bug-hunt) の 2 実装を揃える。
2. **`SubscriptionService::changePlan()`** を新設。org 単位 Cache lock 下で
   (a) 契約再読込 → (b) stale UI 検知 → (c) 変更可能 state 判定 → (d) schedule 管理下の拒否 →
   (e) 同一プラン no-op → (f) 決定的 idempotency key で Stripe update、の順に倒す。
3. **`POST /billing/plan` (`billing.plan.change`)** を追加。`manageBilling` 必須。
   payload は `plan_code` + `current_plan_code` (stale 検知用) のみ。
4. **`Billing/Plans.svelte`** を分岐させる: 有効な契約がある組織は新 route へ、無い組織は
   従来の `POST /billing/checkout` へ。CTA は**両方とも活性のまま** (禁止事項 #8)。
   確認ダイアログの文言をプラン変更用 (日割り / 上限低下の告知) に切り替える。
5. **`organizations.plan_code` は引き続き webhook が唯一の writer** とし、in-app swap では
   書かない。UI には「反映まで数分かかる場合があります」を明示する。

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
| `app/Http/Requests/Billing/ChangePlanRequest.php` | 新設 (`plan_code` / `current_plan_code`) |
| `app/Http/Controllers/Billing/BillingController.php` | `changePlan()` action 追加 + 例外マッピング |
| `routes/web.php` | `POST /billing/plan` を billing.* の構造的 allowlist 内に追加 |
| `app/DataTransferObjects/Billing/BillingPlansPageDto.php` | `hasChangeableSubscription: bool` 追加 |
| `resources/js/types/billing.ts` | `BillingPlansPageProps` に同フィールド追加 (exact 対を維持) |
| `resources/js/pages/Billing/Plans.svelte` | 送信先分岐 + 文言 |
| `lang/ja/validation.php` | `current_plan_code` の attribute 追加 |
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
