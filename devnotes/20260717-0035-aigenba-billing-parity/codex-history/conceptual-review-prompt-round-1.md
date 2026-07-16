【アプリの使命 (North Star)】
## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。


【禁止事項】
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


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【本件固有の前提 — 再検討不要】
- 「決済まわりを参照アプリ aigenba に全面一致させる。独自実装は不要」はユーザーの明示決定であり、
  F1(課金ゲート反転) / F2(signup grant 契機) / F3(チケット会計) / スコープ4領域は決定済み。
  **方針そのものの是非を再議論する必要はない**。レビューは「その決定を安全に遂行する設計になっているか」に集中せよ。
- 参照実装 aigenba は /tmp/aigenba に実在する（読み込み可）。AI-CUE は /workspace。
- 接地監査は devnotes/20260716-2339-aigenba-billing-audit/audit-report.md（49 findings・実ファイル参照付き）。

【特に厳しく見てほしい点】
- 記録済み決定（devnotes/20260712-0927-bugfix-billing-free-access）の反転が、bug-hunt Critical finding F-07
  （free 組織が業務ルートに到達できない詰み）を**再発させない**と言えるか。設計の論拠に穴はないか。
- 既存ユーザー（plan_code IS NULL の組織）の移行設計が本当に締め出しを防げるか。
- 金銭ドメイン（チケット残高・付与の冪等）の移行リスクを過小評価していないか。
- 7 フェーズ分割が「各フェーズ単独でマージしても課金が壊れない」を満たすか。フェーズ順序に誤りはないか。

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: aigenba-billing-parity（決済ドメインを aigenba に全面一致させる）

## 背景・課題

ユーザー方針は一貫して「**UI/機能は参照アプリ aigenba に合わせる。無駄な独自実装をしない**」。
T069→T071 でログイン後レイアウトの外枠 parity を完了した後、ユーザーから決済まわりも同様との指摘:

> 「決済周りも同じじゃない？基本的に料金プランもチケット裏チャージ周りも新規登録経路とかも aigenba に全部合わせて。」

### 接地監査（実施済み）

`devnotes/20260716-2339-aigenba-billing-audit/audit-report.md`（4 スライス並列 / **49 findings** / 全件実ファイル参照付き）。
high 22 / medium 19 / low 8。blocksParity 39 / requiresProductDecision 34。

監査の結論は T071 と**性質が異なる**: レイアウト parity は「私が足した独自実装を削って aigenba に戻す」作業だったが、
決済ドメインの差分の多くは **AI-CUE 側の意図的なプロダクト設計**（記録付き）だった。よって上流の分岐を
ユーザーに提示し、**明示的な決定を得た**（下記）。本設計はその決定を前提とし、再検討しない。

### ユーザー決定（確認済み・設計の前提）

| # | 分岐 | 決定 |
|---|------|------|
| F1 | 課金ゲート（未契約を free として通す vs プラン選択必須） | **aigenba 方式へ反転**（「独自実装いらない」） |
| F2 | signup grant 付与契機（登録時 vs プラン有効化時） | **aigenba 方式へ**（「独自実装いらない」） |
| F3 | チケット会計（source 分割台帳 vs 単一合計） | **aigenba 方式へ** |
| スコープ | — | **4 つ全部**（裏チャージ / 判断不要 15 件 / プランモデル / チケット会計） |

### 反転する記録済み決定（明示的に上書きする）

`devnotes/20260712-0927-bugfix-billing-free-access`（F1 の対象）は**好みで書かれたものではない**。由来:

- bug-hunt finding **F-07 (Critical)** = free 組織が `/projects`・`/app` で理由なく `/billing` へリダイレクトされ、
  **プロジェクト作成に到達できない詰み**（H1+H2）。F-05 と重なると全アカウント恒久詰み。
- 根本原因は「AI-CUE の free 組織は構造的に Cashier subscription を持たない」（free プランは Stripe Price 無し、
  `config/quota.php: fallback_plan='free'`、`plan_code` は webhook でのみ書かれる）ため、
  テンプレート既定の `hasActiveAccess()`（subscription active/trialing のみ true）で恒久的に締め出されていた。
- 対処として `BillingAccess` に「`plan_code === null` = fallback free = 支払い不要 tier として**通す**」を実装し、
  コードコメントに「意図的な書き換え」と明記した。

**本設計はこの決定を反転する。反転記録は本ファイルを正とする**（旧 devnote は歴史として保持し、削除しない）。

### 反転が F-07 を再発させない理由（本設計の中核的発見）

「aigenba に合わせる」= **無料枠の廃止ではない**。aigenba にも無料枠は存在する:

- `App\Enums\PlanCode::Personal = 'personal'` は **free**。`requiresStripeCheckout()` が false
  （「Personal は free (サブスクなし・`PersonalPlanService::activate` で有効化)」とコード内に明記）。
- free entitlement は `subscriptions` テーブル（= Stripe 実体のみを保持する invariant）ではなく
  **`organizations.free_plan_code`** で表現される。
- 未契約者は遮断されるが、**`onboarding/activate-personal` で Personal(free) を明示的に有効化する導線がある**。

つまり aigenba 方式は「無料を無くす」のではなく「**無料を暗黙の fallback から、明示的な申告 + 有効化へ置き換える**」。
したがって:

1. **F-07 は再発しない** — 遮断先が「説明なき /billing」ではなく「プラン選択 = checkout / activate-personal」であり、
   free を選ぶ導線がその場にある（H1「説明なしリダイレクト」も H2「詰み」も解消される形）。
   ※ ただし **既存の plan_code null 組織を移行で救わないと F-07 が実データ上再発する**（後述の移行設計が必須条件）。
2. **LP の「無料で始められます」は維持できる** — Personal が free だから。ただし F2 により付与契機が
   「登録時」→「プラン有効化時」に変わるため、**`Welcome.svelte:349`「新規登録でチケット {N} 枚が無料」の文言は
   事実と乖離する。文言修正は本設計の必須随伴変更**（付与契機・冪等キー・文言を一体で変更する）。
3. **濫用防止が増える** — aigenba は「1 user が declarer である active free personal org は 1 つ」を
   **DB partial unique index** で保証する。AI-CUE の現行（org 単位 signup grant + 暗黙 free）は
   組織を量産して無料チケットを収穫できる構造で、この不変条件を持たない。parity は防御を**足す**側に働く。

### 規模（過小評価を避けるため明記）

| | aigenba | AI-CUE 現行 |
|---|---|---|
| `app/Services/Billing` | **25 files / 7,735 行** | 14 files / 2,024 行 |
| 中核サービス | `TicketService` 1,494 行 / `SubscriptionService` 1,622 行 | `TicketLedgerService` 471 行 |

加えて **課金関連テスト約 60 ファイル**（うち free 前提 13）が gate 反転で影響を受ける。
**金銭を扱うドメインの全面置換**であり、単一 PR では実装・レビュー・ロールバックのいずれも成立しない。

## 改善アイデア

決済ドメイン（プラン / サブスク / チケット会計 / 裏チャージ / 新規登録経路 / 課金 UI）を aigenba の設計へ
全面的に寄せ、AI-CUE 独自実装（暗黙 free fallback・source 分割台帳・登録時 grant）を**撤去**する。
規模ゆえに**依存順の 7 フェーズに分割**し、各フェーズを独立 TODO として実装・検証・マージする。

### 中核となる置換

| 領域 | 撤去する AI-CUE 独自 | 採用する aigenba 方式 |
|---|---|---|
| 無料枠 | `plan_code === null` = 暗黙 fallback free | `PlanCode::Personal` (free) + `free_plan_code` + 明示 `activate` + declarer 単位 unique |
| ゲート | 未契約を通す `BillingAccess` | 未 Subscribed を遮断 → checkout / billing-required |
| grant | 登録 tx 内 `signup_grant:org:{orgId}` | プラン有効化時 `signup_grant:{stripeSubId}` / `signup_grant:personal:{orgId}` |
| チケット会計 | source 分割台帳 + reserve/commit + `ticket_purchases` 逆仕訳 | 単一合計 + `TicketService` |
| プラン | PlanCode enum 無し・Plan seeder のみ | `PlanCode` enum + `PlanPriceService` + Personal/Starter |
| 登録経路 | plan 概念なし（`/register` 直行） | `?plan=` handoff + `IntendedPlanResolver` + onboarding checkout |
| 裏チャージ | **無し**（通知のみ） | オートリチャージ（同意・設定・無効化理由・リコンサイル） |

## 期待効果

- **ユーザー方針の充足（主目的）**: 決済まわりの独自実装を撤去し aigenba と同一設計に統一。以後 aigenba 側の
  更新を取り込みやすくなり、二重メンテと設計乖離が消える。
- **濫用防止の獲得**: declarer 単位の free org unique 制約により、組織量産による無料チケット収穫を DB 制約で遮断
  （現行 AI-CUE には無い防御）。
- **無料導線の明示化**: 「暗黙に通す」から「ユーザーが Personal を選んで有効化する」へ変わり、
  ユーザーが自分の契約状態を理解できる（現行は「現在のプラン: Free」と出るのに billing へ戻される混乱があった）。
- **裏チャージの獲得**: 残高切れで作業が止まる体験を、自動補充で解消できる（使命「思考ゼロ」に資する）。
- 使命への接続: 決済は AI-CUE の North Star（SOP からマニュアル動画を作る）そのものではなく**支持基盤**。
  本施策の価値は「現場作業者が残高・契約で作業を止められない」ことと「保守コストを下げる」ことに限定される。
  **機能価値の主張を誇張しない**。

## 実装方針（依存順 7 フェーズ = 7 TODO）

> 各フェーズは独立してテスト green + マージ可能な粒度。**混在期間に課金が壊れない**ことを各フェーズの DoD に含める。

- **P1 プラン基盤**: `PlanCode` enum / `PlanPriceService` / `organizations.free_plan_code` 追加 /
  `PersonalPlanService`（activate + declarer 単位 partial unique index）/ Plan seeder 整備（Personal・Starter）。
  この時点ではゲートは**まだ反転しない**（基盤のみ。既存挙動不変）。
- **P2 サブスク層**: `SubscriptionService` / `SubscriptionSnapshot` / `BillingCustomerSynchronizer` /
  `BillingPermissionService` を移植し、AI-CUE の Gateway 系（`SubscriptionCheckoutGateway` 等）を置換。
- **P3 ゲート反転 + 移行**: `BillingAccess` の暗黙 free 許可を撤去し `RequireActiveSubscription` を aigenba 方式へ。
  **同一 PR で移行 migration**（既存 `plan_code IS NULL` 組織へ `free_plan_code='personal'` を backfill = 既存
  ユーザーを締め出さない）を行う。**この移行が無いと F-07 が実データ上再発する = 本フェーズの最重要要件**。
- **P4 チケット会計**: `TicketService` へ統合し台帳を単一合計へ移行（既存残高の保全・冪等・二重計上防止を
  移行設計で明示）。signup grant 契機を有効化時へ変更し、**LP 文言（`Welcome.svelte:349`）を同一 PR で修正**。
- **P5 新規登録経路**: `IntendedPlanResolver` / `OnboardingReturnResolver` / `EmailVerificationContinuation` /
  `RegisterResponse` / `registerView(?plan)` / `verifyEmailView(continueUrl)` / 料金表の `?plan=` handoff。
- **P6 Onboarding 画面**: `onboarding.{checkout,activate-personal,billing-required}` の routes / Controller /
  DTO / `Onboarding/{Checkout,BillingRequired}.svelte`。**T071 の primitive（PageContainer/PageHeader/PageContent）に
  従い page-shell-structure arch テストを満たす**。
- **P7 裏チャージ + 課金 UI**: オートリチャージ（`AutoRechargeConsentDto` / `AutoRechargeSettingsDto` /
  `AutoRechargeDisabledReason` / `ReconcileAutoRechargeAttempts`）+ 料金/請求 UI（`Guest/Pricing` / `Billing/Plans` /
  `PlanCard` / `Billing/Index` / `PurchaseTickets` / `ticketCount.ts`）+ 監査の判断不要 15 件。

> 詳細設計で確定する事項: (1) P3 の移行 backfill 条件と rollback 手順、(2) P4 の台帳→単一合計の移行アルゴリズムと
> 残高一致検証、(3) 各フェーズの feature flag 要否、(4) 60 テストファイルのフェーズ別割り当て。

## テスト計画（テストファースト）

- **P3 の要**: 「既存 plan_code null 組織が移行後も業務ルートに到達できる」= **F-07 回帰テストを先に書く**
  （現行 `SeededFreePlanBillingAccessTest` / `RequireActiveSubscriptionMiddlewareTest` を反転後の期待へ作り直す。
  **削除ではなく期待の更新**）。
- **P4 の要**: 移行前後で全組織のチケット残高が一致する invariant テスト + 二重付与/二重計上が起きない冪等テスト。
- **P1 の要**: declarer 単位 free org unique 制約が並行 activate で破れないこと（後着は 500 にしない）。
- 各フェーズで既存 60 課金テストのうち該当分を更新（**禁止事項: 既存テストの削除**）。全て Factory 生成。
- P6/P7 の新規ページは page-shell-structure / ds-purity / atomic-import-graph の arch テストを満たす。

## 制約・前提

- PHP 8.4 + Laravel 12 + Cashier(Stripe) + Svelte 5 runes + Inertia。PHPStan level 10 / Pest（RefreshDatabase
  グローバル・`--parallel`）/ DTO + JsonResource / DS token のみ（hex 直書き禁止）。
- **金銭ドメイン**: 移行は可逆性を確保し、残高・付与の冪等キーを設計時に固定する。二重計上・残高欠損は
  ユーザー被害に直結するため、各フェーズで invariant テストを必須にする。
- **既存ユーザーを締め出さない**（P3 移行）。これは反転の受け入れ条件であり、交渉不可。
- LP・料金表の文言は**事実と一致させる**（F2 の随伴。マーケ文言と実挙動の乖離は F-07 の根本原因そのものだった）。

## スコープ外

- Stripe 本番の Price/Product 再設計（既存 Price 体系は流用。プラン集合の変更に伴う Stripe 側整備は運用作業）。
- 席課金（seat billing）の全面移植は P2 で**判定のみ**（AI-CUE に席概念が無いため、UI/購入導線は本設計に含めない）。
- Enterprise の営業導線（お問い合わせ）は現行維持。
- bug-hunt の aigenba 視覚比較レーン（T071 から継続する別タスク）。
