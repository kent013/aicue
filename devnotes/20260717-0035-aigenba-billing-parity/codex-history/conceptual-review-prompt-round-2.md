Round 1 の指摘（Critical 3 / Warning 6）を全て反映して概念設計を改訂した。対応の要旨:

【Critical 3-1（P3 順序 = F-07 が新規ユーザーに再発）】完全に受け入れ。提案どおり順序を組み替え、
Onboarding 最小導線（activate-personal / billing-required / checkout）を **ゲート反転より前の独立フェーズ P3** に前倒しし、
「導線 → ゲート反転 → 会計移行 → 会計 cutover の順序を崩さない」を交渉不可の不変条件として明文化。9 フェーズへ再構成。
F-07 論拠は「条件付き主張」に書き換え、条件 A（新規: 反転前に導線が実在）/ 条件 B（既存: backfill で救う）を明示した。

【Critical 3-2（declarer unique と backfill の衝突）】受け入れ。実データ構造で裏付けも取った
（AI-CUE は Route::post('/organizations') + Organizations/Create.svelte で 1 ユーザーが複数 org を持てるため、
指摘どおり実際に衝突する）。解決は aigenba の index 定義に内在していた:
`WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL` により **declarer NULL 行は制約対象外**。
よって grandfathering を「free_plan_code='personal' + declarer NULL + personal_declared_at NULL（自己申告を経ていない移行組）」
と定義し、締め出しゼロ・制約違反ゼロ・index の独自改変ゼロを同時に達成。3 類型を一様に declarer-less で救うため
survivor 選定は不要（移行分岐を作らない）。収束は「後から明示 activate で declarer が付く」で自然進行。

【Critical 5-1（signup grant 冪等移行）】受け入れ。これも aigenba に既に解があった:
`signup_tickets_granted_at`（org 単位で生涯 1 回のマーカー。free 有効化・paid 成立の両経路で共用する真実源）と
分離された backfill data migration（2026_07_08_113550_backfill_signup_tickets_granted_at）。aigenba のコメントは
「従来は subscription 行単位の記録だったため解約→再契約で再付与される穴があり、backfill で既存付与済 org を塞ぐ」
と経緯まで残している。さらに AI-CUE の現行冪等キー `signup_grant:org:{orgId}`（org スコープ + ticket_ledger.idempotency_key
UNIQUE）は意味論が marker と 1:1 対応するため、移行は履歴からの直マッピングで足りる（推測不要）。
規約を 4 点明文化: (a) marker を P1 で先行導入し付与の唯一の真実源とする、(b) 既存履歴から backfill、
(c) grandfathering backfill と free 移行は grant を発火しない、(d) marker 未設定 org の新規 activate / paid 成立のみが発火。

【Warning 対応】3-3 会計を P5（移行 + 残高一致 invariant 検証。旧台帳が正のまま additive）と P6（cutover。P5 green が前提）
に分割 / 4-1 濫用防止の効果表現を「新規 org から先に成立、既存 grandfathered は収束時に全面成立」へ緩和 /
5-2 backfill 条件を raw column でなく effective entitlement snapshot 分類で判定（分類表は詳細設計）/
5-3 オートリチャージ（P8）と UI parity（P9）を分離 / 6-1 9 フェーズへ再構成し LP 文言修正は F2 と同一 PR に維持 /
7-1 PlanCode backed enum を唯一のコード表現とし判定を EffectivePlan / SubscriptionSnapshot DTO に集約、Inertia props は DTO 経由（P2 の DoD）。

【Suggestion 対応】成功指標を検証可能なものに限定（業務ルート到達率 / activate-personal 完了率 / billing への説明なし遷移率 /
残高切れ停止件数）/ 共通 DoD に「回帰テスト先行」「既存課金テストは削除せず期待更新」「DTO 経由」「Factory 生成」を明記 /
**feature flag は導入しない**（aigenba に無い独自機構を足さない方針のため）。代わりに rollback 手順を確定:
P4 はデータ変更が additive のみでゲートは純粋なコード → revert で即時復帰、P6 は旧台帳を保持したまま切替 → revert で復帰。
旧台帳の物理削除はスコープ外（安定確認後の別タスク）。

改訂後の概念設計を全文添付する。特に「順序の不変条件」「grandfathering 定義」「signup grant 冪等移行」の 3 点が
閉じているかを重点的に見てほしい。残る穴があれば指摘し、無ければ APPROVED を出してほしい。

---

## 改訂後の概念設計（全文）

# 概念設計: aigenba-billing-parity（決済ドメインを aigenba に全面一致させる）

> Round 1 レビュー反映済み（フェーズ順序を「導線 → ゲート反転」へ組み替え / grandfathering を declarer-less で定義 /
> signup grant marker を付与の唯一の真実源として先行導入 / 会計を移行と cutover に分割 / オートリチャージと UI を分離 /
> EffectivePlan DTO へ判定集約 / 成功指標を限定 / feature flag は導入せず rollback 手順を確定）。

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

1. **F-07 は「導線が存在する限りにおいて」再発しない** — 遮断先が「説明なき /billing」ではなく「プラン選択 =
   checkout / activate-personal」であり、free を選ぶ導線がその場にある（H1「説明なしリダイレクト」も H2「詰み」も解消）。
   **これは条件付きの主張であり、2 つの必須条件を満たさない限り成立しない**（Round 1 レビューで穴を指摘され修正）:
   - **条件 A（新規ユーザー）**: **ゲート反転より前に** activate-personal / billing-required / checkout の導線が
     実在すること。導線が無い状態でゲートだけ反転すると、新規登録者は遮断されるだけで free を選べず **F-07 が再発する**。
     → フェーズ順序で「導線（P3）→ ゲート反転（P4）」を**不変条件として固定**する。
   - **条件 B（既存ユーザー）**: 既存 `plan_code IS NULL` 組織を移行で救うこと。救わなければ F-07 が実データ上再発する。
     → P4 と同一 PR の grandfathering 移行（後述）で担保する。
2. **LP の「無料で始められます」は維持できる** — Personal が free だから。ただし F2 により付与契機が
   「登録時」→「プラン有効化時」に変わるため、**`Welcome.svelte:349`「新規登録でチケット {N} 枚が無料」の文言は
   事実と乖離する。文言修正は本設計の必須随伴変更**（付与契機・冪等キー・文言を一体で、同一 PR で変更する）。
3. **濫用防止が増える（ただし段階的に成立）** — aigenba は「1 user が declarer である active free personal org は 1 つ」を
   **DB partial unique index**（`WHERE free_plan_code='personal' AND personal_declared_by_user_id IS NOT NULL`）で保証する。
   AI-CUE の現行（org 単位 signup grant + 暗黙 free）は組織を量産して無料チケットを収穫できる構造で、この不変条件を持たない。
   **効果は「新規 org から先に成立し、既存 grandfathered org は declarer 付き activate へ収束した時点で全面成立」**
   （移行直後に全面成立するとは主張しない）。

### grandfathering の定義（既存ユーザーを締め出さず、unique 制約とも衝突しない）

AI-CUE は `Route::post('/organizations')` + `Organizations/Create.svelte` を持ち **1 ユーザーが複数 org を保有できる**。
よって「全 `plan_code IS NULL` org を declarer 付き personal へ backfill」は unique index に衝突する（migration failure
か締め出し）。**解は aigenba の index 定義に内在している**: 当該 index は `personal_declared_by_user_id IS NOT NULL` を
条件に含むため、**declarer が NULL の行は制約対象外**。

→ grandfathering を **`free_plan_code='personal'` + `personal_declared_by_user_id = NULL` + `personal_declared_at = NULL`
（= 自己申告を経ていない移行組）** と定義する。これにより:

- **締め出しゼロ**（free entitlement を持つので業務ルートに到達できる）、**制約違反ゼロ**（index 対象外）。
- **index を独自改変しない**（aigenba verbatim のまま。独自実装を足さない方針に一致）。
- 3 類型（単独 org / 複数 org / declarer 不在・曖昧）を**一様に declarer-less で救う**ため survivor 選定が不要
  = 移行分岐を作らない（移行バグの余地を消す）。
- 収束は「ユーザーが後から明示 activate すると declarer が付き、以後 unique 制約下に入る」で自然に進む。

なお backfill の判定条件は **raw column（`plan_code IS NULL`）ではなく effective entitlement snapshot**
（active sub の有無 / cancel・grace / 既存付与履歴 / owner 状態）で分類する（null は「fallback free」以外の壊れた
中間状態を含みうるため）。分類表は詳細設計で確定する。

### signup grant の冪等移行（二重付与・未付与の双方を閉塞する）

F2（付与契機の変更）は、**既存ユーザーが登録時に既に grant 済み**であるため、素直に切り替えると二重付与か未付与を生む。
**これも aigenba に既に解がある**: `signup_tickets_granted_at`（「初回無償チケット付与の **org 単位で生涯 1 回**マーカー。
free 有効化・paid サブスク成立の**両経路で共用する真実源**」）+ **分離された backfill data migration**
（`2026_07_08_113550_backfill_signup_tickets_granted_at`）。aigenba のコメントは、従来 subscription 行単位の記録だったため
**解約→再契約で再付与される穴**があり backfill で既存付与済 org を塞いだ、と経緯まで残している。

さらに **AI-CUE の現行冪等キー `signup_grant:org:{orgId}`（org スコープ + `ticket_ledger.idempotency_key` UNIQUE）は
`signup_tickets_granted_at` と意味論が 1:1 対応する**（どちらも「org 生涯 1 回」）。よって移行は履歴からの直マッピングで足り、
推測が要らない。規約:

1. `signup_tickets_granted_at` を **付与の唯一の真実源**として P1 で先に導入する。
2. 既存 `ticket_ledger` の `signup_grant:org:{orgId}` 履歴から marker を backfill する（付与済み org を塞ぐ）。
3. **grandfathering backfill と free 移行は grant を発火しない**。
4. **marker が立っていない org の新規 activate / paid 成立のみが grant を発火する**。

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
- **濫用防止の獲得（段階的）**: declarer 単位の free org unique 制約により、組織量産による無料チケット収穫を DB 制約で遮断
  （現行 AI-CUE には無い防御）。ただし **新規 org から先に成立し、既存 grandfathered org（declarer-less）は
  ユーザーが明示 activate して declarer が付いた時点で収束**する。移行直後の全面成立は主張しない。
- **無料導線の明示化**: 「暗黙に通す」から「ユーザーが Personal を選んで有効化する」へ変わり、
  ユーザーが自分の契約状態を理解できる（現行は「現在のプラン: Free」と出るのに billing へ戻される混乱があった）。
- **裏チャージの獲得**: 残高切れで作業が止まる体験を、自動補充で解消できる（使命「思考ゼロ」に資する）。
- 使命への接続: 決済は AI-CUE の North Star（SOP からマニュアル動画を作る）そのものではなく**支持基盤**。
  本施策の価値は「現場作業者が残高・契約で作業を止められない」ことと「保守コストを下げる」ことに限定される。
  **機能価値の主張を誇張しない**。

### 成功指標（検証可能なものに限定）

- 業務ルート到達率（登録 → プロジェクト作成に到達できた割合）
- `activate-personal` 完了率 / billing 起因の離脱率
- billing への**説明なし遷移**率（F-07 型の症状。0 が期待値）
- 残高切れによる作業停止件数（P8 オートリチャージの効果測定）

## 実装方針（依存順 9 フェーズ = 9 TODO）

> 各フェーズは独立してテスト green + マージ可能な粒度。**混在期間に課金が壊れない**ことを各フェーズの DoD に含める。
> **不変条件（交渉不可）: 導線（P3）→ ゲート反転（P4）→ 会計移行（P5）→ 会計 cutover（P6）の順序を崩さない。**

| # | フェーズ | 単独マージ時の安全性 |
|---|---|---|
| **P1** | **プラン基盤**: `PlanCode` enum / `PlanPriceService` / `organizations` へ `free_plan_code`・`free_plan_activated_at`・`personal_declared_at`・`personal_declared_by_user_id`・**`signup_tickets_granted_at`** 追加 + partial unique index（aigenba verbatim）/ `PersonalPlanService` / Plan seeder（Personal・Starter）/ **marker の backfill（既存 `signup_grant:org:{orgId}` 履歴から）** | 挙動不変（列追加は additive、ゲートは未反転） |
| **P2** | **サブスク層**: `SubscriptionService` / `SubscriptionSnapshot` / `BillingCustomerSynchronizer` / `BillingPermissionService` を移植し Gateway 系を置換。**判定を `EffectivePlan` DTO へ集約**（raw column 分岐を作らない） | 挙動不変（判定の集約のみ。ゲートの結論は現行と同値） |
| **P3** | **Onboarding 最小導線**: `onboarding.{checkout,activate-personal,billing-required}` の routes / Controller / DTO / `Onboarding/{Checkout,BillingRequired}.svelte`。T071 primitive 準拠（page-shell-structure arch テスト） | 安全（導線が増えるだけ。まだ強制されない） |
| **P4** | **ゲート反転 + grandfathering 移行**: `BillingAccess` の暗黙 free 許可を撤去し `RequireActiveSubscription` を aigenba 方式へ。**同一 PR で declarer-less grandfathering backfill**（既存 org を救う） | **反転の山場**。条件 A（P3 導線）と条件 B（backfill）を両方満たして初めて安全 |
| **P5** | **チケット会計 移行 + 検証**: `TicketService` を導入し残高を単一合計へ **additive に構築**。旧台帳は正のまま保持し、**残高一致 invariant 検証**を green にする（書き込みは切替えない） | 安全（旧台帳が正のまま。読み書きの挙動不変） |
| **P6** | **会計 cutover + F2**: 書き込み/読み取りを `TicketService` へ切替。**signup grant 契機を有効化時へ変更**（marker が真実源）。**LP 文言（`Welcome.svelte:349`）を同一 PR で修正** | cutover。P5 の invariant green が前提条件 |
| **P7** | **新規登録経路**: `IntendedPlanResolver` / `OnboardingReturnResolver` / `EmailVerificationContinuation` / `RegisterResponse` / `registerView(?plan)` / `verifyEmailView(continueUrl)` / 料金表の `?plan=` handoff | 安全（導線の質向上。ゲートは P4 で確定済み） |
| **P8** | **裏チャージ（オートリチャージ）**: `AutoRechargeConsentDto` / `AutoRechargeSettingsDto` / `AutoRechargeDisabledReason` / `ReconcileAutoRechargeAttempts` | 安全（opt-in 機能の追加。リコンサイルを含めて 1 フェーズ） |
| **P9** | **課金 UI parity**: `Guest/Pricing` / `Billing/Plans` / `PlanCard` / `Billing/Index` / `PurchaseTickets` / `ticketCount.ts` + 監査の判断不要 15 件 | 安全（UI のみ） |

**順序の根拠**: P3 を P4 より前に置くのは F-07 再発防止の**条件 A**そのもの（Round 1 の Critical 指摘）。
P5/P6 を分けるのは、金銭の移行（additive・検証可能）と cutover（不可逆に近い切替）の成立条件が異なるため。

### rollback 手順（feature flag は導入しない）

aigenba に無い独自機構（flag）を足さない方針のため、**各フェーズを revert 可能な形に保つ**ことで代替する:

- **P4（ゲート反転）**: データ変更は additive（列追加 + backfill）のみで、ゲート自体は純粋なコード。
  **コード revert で即時復帰**（backfill 済みの `free_plan_code` は旧コードから無視されるだけで無害）。
- **P6（会計 cutover）**: 旧台帳を削除せず保持したまま読み書きを切替える。**revert で旧台帳へ復帰可能**。
  旧台帳の物理削除は本設計のスコープ外（安定確認後の別タスク）。

> 詳細設計で確定する事項: (1) P4 の backfill 分類表（effective entitlement snapshot ベース）、(2) P5 の台帳→単一合計の
> 移行アルゴリズムと残高一致検証の具体、(3) `EffectivePlan` DTO の variant 分離（activated personal / paid subscription /
> grandfathered legacy free）、(4) 課金関連 約60 テストファイルのフェーズ別割り当て。

## テスト計画（テストファースト）

**共通 DoD（全フェーズ）**: 回帰テストを**先に書いて red を確認**してから実装 / **既存課金テストは削除せず期待を更新**
（禁止事項 1・3）/ Inertia props は **DTO 経由**（禁止事項 4）/ テストデータは **Factory 生成** /
個別 `DatabaseTransactions` を使わない（`RefreshDatabase` グローバル・`--parallel`）。

- **P1 の要**: declarer 単位 free org unique 制約が**並行 activate で破れない**こと（後着は 500 にせず正常応答）。
  marker backfill が既存 `signup_grant:org:{orgId}` 履歴と一致すること。
- **P4 の要（最重要）**: **F-07 回帰テストを先に書く** — (a) 既存 `plan_code IS NULL` 組織が移行後も業務ルートに到達できる、
  (b) 新規登録者が遮断されても activate-personal / checkout に到達でき詰まない、(c) 遮断時に**理由が画面に出る**
  （H1「説明なしリダイレクト」の再発検知）。現行 `SeededFreePlanBillingAccessTest` /
  `RequireActiveSubscriptionMiddlewareTest` は**削除せず反転後の期待へ更新**する。
- **P5 の要**: 移行前後で**全組織のチケット残高が一致する invariant テスト**（これが green にならない限り P6 に進まない）。
- **P6 の要**: 二重付与/二重計上が起きない冪等テスト（marker 済み org を再 activate しても付与されない /
  解約→再契約で再付与されない = aigenba が backfill で塞いだ穴の回帰）。LP 文言と実挙動の一致。
- 各フェーズで既存 約60 課金テストのうち該当分を更新（フェーズ別割り当ては詳細設計で確定）。
- P3/P9 の新規・改修ページは page-shell-structure / ds-purity / atomic-import-graph の arch テストを満たす。

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
