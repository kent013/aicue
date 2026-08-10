# 概念設計: state-aware-messaging (状態を区別しない汎用メッセージの是正)

対象 finding: bug-hunt run `20260811-003230` の **F-2-01 (Medium)** / **F-2-02 (Low)**
一次入力: `devnotes/20260811-0146-state-aware-messaging/recon-brief.md`
証跡: `devnotes/20260811-003230-bug-hunt/shard-2/shard-report.md` (再現手順つき) /
`.../screenshots/F-2-01-dashboard-billing-callout.png` / `.../F-2-02-passkey-429.png`

---

## 背景・課題

### F-2-01: 未契約ユーザーに「支払いが確認できない」と表示される

**再現 (実ブラウザで確認済み)**: `/register` → メール認証 → `/dashboard` を開くと、
一度も課金手続きをしていない新規ユーザーに

> サブスクリプションのお支払いが確認できないため、一部機能を一時停止しています。お支払い方法をご確認ください。

が出る (`resources/js/pages/Dashboard.svelte:208-217`)。

**サーバは状態を知っている**。`BillingAccess::state()` は 5 値の
`App\Enums\Billing\OnboardingBillingState`
(`no_subscription` / `pending_checkout` / `expired_checkout` / `subscribed` / `active_free_plan`)
を返し、`RequireActiveSubscription` は **すでに 2 分岐**している
(`NO_PLAN_MESSAGE` = 「ご利用にはプランの選択が必要です。」/ `BLOCKED_MESSAGE` = 上記の支払い文言)。
にもかかわらず dashboard へ渡る props は `BillingSummaryData::$hasBillingAccess`
(= `state()->grantsAccess()`) の **真偽値 1 個に潰されている**
(`app/Services/Dashboard/DashboardService.php:234`)。潰した結果、画面は
「未契約」と「支払い不健全」を区別できない。

**影響範囲**: S1 登録ファネルを通る **全新規ユーザー**が最初に見る画面。
member 視点 (manageBilling なし) でも同一文言が出ることを shard-2 が確認している。

**阻害されたユーザージョブ**: 登録直後に「次に何をすればよいか」を知る。
正しい次アクションは「プランを選ぶ」なのに、身に覚えのない支払い失敗の修復
(「お支払い方法を確認」→ `/billing`) へ誘導される。

### F-2-02: passkey の 429 が他の失敗と区別されない

**再現 (実ブラウザで確認済み)**: `/login` で「パスキーでログイン」を 11 回連打すると
`GET /passkeys/login/options` が 429 (`X-RateLimit-Limit: 10`) を返すが、画面には

> パスキーの認証を開始できませんでした。

の汎用文言だけが出る (`resources/js/lib/passkeys.ts` の `assertPasskey`)。
原因は `fetchOptions()` が `if (!res.ok) return null;` で **HTTP status を捨てている**こと。
呼び出し側は「options が取れなかった」以上の情報を持てない。

**阻害されたユーザージョブ**: パスキーでログインする。
429 は「待てば直る」という、他の失敗 (未対応端末 / 通信断 / サーバ異常) とは
**質の違う情報**である。伝わらないと連打を続けて状況を悪化させる。

### 共通する構造

どちらも **「システムは具体的な状態を知っているのに、境界で情報を捨てているため
画面がどの状態でも同じ文言を出す」**。エラーを隠す問題ではなく、**行き先の提示**の問題である。

---

## 改善アイデア

### 施策 1 (F-2-01): dashboard props を真偽値から既存 state enum へ差し替える

`BillingSummaryData` の `hasBillingAccess: bool` を **`billingState: OnboardingBillingState`**
へ置き換える (wire では `billing_state: BillingStateValue`)。`Dashboard.svelte` は
**5 状態を網羅する copy map** で callout を出し分ける。

| state | callout | CTA |
|---|---|---|
| `subscribed` / `active_free_plan` | 出さない (現状どおり) | — |
| `no_subscription` | 「ご利用にはプランの選択が必要です。」 | 「プランを選ぶ」→ `/onboarding/checkout` |
| `pending_checkout` | 「お支払いのお手続きが完了していません。ご利用を開始するには、プラン選択からお手続きください。」 | 「プラン選択へ」→ `/onboarding/checkout` |
| `expired_checkout` | **現行文言のまま** | 「お支払い方法を確認」→ `/billing` (現行どおり) |

- **新しい enum は作らない**。語彙は `OnboardingBillingState` が既に持っており、
  TS union `BillingStateValue` も `resources/js/types/billing.ts:12` に**既にある**
  (`/billing` の `BillingDashboardDto` が同じ enum を wire に載せている前例)。
  ここで別の enum を新設するのは同じ概念の二重定義 (思考原則 4)。
- **真偽値との並走を残さない** (思考原則 3)。`has_billing_access` は
  `billing_state` から一意に導けるため、同じ PR で消す。
- **「再開」と書かない** (Codex conceptual Round 1 の指摘で実装を再確認した)。
  `/onboarding/checkout` は既存 Stripe Checkout session を再開しない。同じ plan で再 POST すると
  `SubscriptionService::startCheckout()` の live pending dedup に当たり
  `back()->with('warning', '既に進行中の Checkout があります。数分お待ちください。')` になる
  (詰みではないが再開でもない)。文言は実装が実際にできることだけを言う。
- **CTA は権限で分岐させない**。`/onboarding/checkout` は controller 側で
  「契約済み → `/billing`」「manageBilling なし → `/billing-required`」へ自分で捌く
  (`OnboardingController::show`)。フロントで `manageBilling` を再判定すると
  認可判断が 2 箇所になり、禁止事項 8 (押せないボタン) の思想にも反する。
  **リンクは常に出し、行き先はサーバが決める**。

### 施策 2 (F-2-02): passkey の options 取得で 429 だけを分類する

`resources/js/lib/passkeys.ts` の `fetchOptions()` が status を捨てないようにし、
**429 のときだけ**専用文言を返す。POST 側 (`/passkeys/login` / `/passkeys/confirm`) の
`readErrorMessage()` も 429 を先に判定する (Laravel の 429 本文 `message` は
英語 "Too Many Requests" のため、そのまま出すと悪化する)。

- **分類するのは 429 だけ**。それ以外 (401/403/419/5xx/通信断) は現行の汎用文言のまま。
  429 は「待てば直る」という行動指針を含む唯一の status であり、他は
  ユーザー側の次の一手が変わらない (思考原則 2 = 今必要なものだけ)。
- `PasskeyOutcome` の型は変えない (`{status:"failed", message}` のまま)。
  呼び出し側 4 ファイル (Login / RecentAuthModal / PasskeySection / ConfirmRecentAuth) は
  すでに `outcome.message` を描画しているので**変更不要**。
- 文言はアプリ既存の 429 語彙に揃える
  (`InertiaErrorScreenStatus::TooManyRequests->message()` =
  「リクエストが続けて行われました。少し時間をおいてからお試しください。」)。
- **`Retry-After` の秒数は出さない**。秒表示のためだけにクライアント側の解釈点を新設すると
  `RetryAfterSeconds` (PHP 側の唯一の解釈点) と二重管理になる。
  上限は 10 req/分なので待ち時間は 1 分未満であり、「少し時間をおいて」で行動は決まる。

### 施策 3: 再発防止 (機械で守れる範囲だけ)

1. **`OnboardingBillingState` ⇔ TS union の値集合同期 gate** (Architecture)。
   既存 helper `Tests\Support\TsUnionValues` を使う
   (`AccountDeletionBlockerActionTsSyncInvariantTest` と同型。degenerate PASS 自己検証つき)。
   → enum に case が増えたら TS union の更新なしでは赤くなる。
2. **画面側の網羅性は TypeScript の exhaustiveness で守る**。callout の copy を
   `satisfies Record<BillingStateValue, …>` の map で持つ → 状態が増えたら
   `pnpm typecheck` が落ちる (描画漏れの silent 化を防ぐ)。
3. **文言の正しさは機械で守れない** — 「その状態にその日本語が対応しているか」は
   自然言語の意味の問題であり、テストは文字列一致しか見られない。
   「保証しないもの」に明記する。

---

## 2 件を 1 つの仕組みにまとめない (判断と根拠)

**まとめない。** 症状の語り口が同じでも、層と入力が違う。

| | F-2-01 | F-2-02 |
|---|---|---|
| 層 | Inertia props (サーバが持つドメイン状態の受け渡し) | ブラウザ fetch の HTTP status 分類 |
| 情報源 | `BillingAccess::state()` (5 値の業務状態) | HTTP 429 (transport の 1 値) |
| 失敗の形 | 誤った文言を**確信を持って**出す | 情報を持たないまま汎用文言に畳む |
| 直し方 | 捨てていた state を props に載せる | 捨てていた status を分類する |

共通化するとしたら「状態→文言の写像を持つ汎用機構」になるが、
それは **2 箇所しか使わない抽象**であり、思考原則 2 (今必要なものだけ) と
思考原則 4 (別物の概念を「似ているから」で統合しない) の両方に反する。
共有するのは**方針**(状態を知っているなら捨てずに伝える) だけでよい。

---

## 期待効果

- **使命への貢献**: AI-CUE は「専門知識ゼロの現場作業者」が使う。最初の着地画面で
  身に覚えのない支払いエラーを告げるのは、思考ゼロで使える体験の入口を壊している。
  F-2-01 の修正は**全新規ユーザー**の初回体験に効く。
- **行き先のない詰みを作らない**: 未契約ユーザーに「プランを選ぶ」という
  実際に到達可能な次の一手を提示する (CTA の着地は権限に応じてサーバが決める)。
- F-2-02: 「待てば直る」と分かれば連打をやめられる。パスワードログインは同じ画面上にあるため
  代替手段の提示は不要 (画面が既に提示している)。

## 実装方針 (概要)

| # | 変更対象 | 内容 |
|---|---|---|
| 1-a | `app/DataTransferObjects/Dashboard/BillingSummaryData.php` | `hasBillingAccess: bool` → `billingState: OnboardingBillingState`。`toArray()` は `billing_state` を出す |
| 1-b | `app/DataTransferObjects/Dashboard/DashboardPageData.php` | `@return` shape の `has_billing_access` → `billing_state` |
| 1-c | `app/Services/Dashboard/DashboardService.php` | `hasActiveAccess()` → `state()` を渡す |
| 1-d | `resources/js/types/dashboard.ts` | `has_billing_access: boolean` → `billing_state: BillingStateValue` (`@/types/billing` から import) |
| 1-e | `resources/js/pages/Dashboard.svelte` | copy map による 4 分岐 (うち 2 状態は非表示) |
| 2-a | `resources/js/lib/passkeys.ts` | `fetchOptions()` の status 保持 + 429 文言。`readErrorMessage()` の 429 先行判定 |
| 3-a | `tests/Architecture/OnboardingBillingStateTsSyncInvariantTest.php` (新規) | enum ⇔ TS union 同期 gate |

テスト (詳細設計で確定): Pest Feature (`DashboardTest.php` の既存 3 本の更新 + 未契約 cohort の新規)、
vitest (`tests/js/pages/Dashboard.test.ts` / `tests/js/lib/passkeys.test.ts`)、
Browser lane (Chromium + WebKit) で未契約 callout → CTA 着地まで。

## 制約・前提

- **課金ゲートの判定ロジックは変えない**。`BillingAccess` / `RequireActiveSubscription` /
  `OnboardingBillingState::grantsAccess()` に手を入れない。誰が何にアクセスできるかは不変で、
  **表示だけ**が変わる。
- **流量制限の閾値は変えない** (`RateLimiter::for('passkeys', 10/min)` は不変)。
- `OnboardingBillingState` に **case を足さない** (足すと gate 判定の母集団が変わる)。
- DESIGN.md: 既存 atom (`Card` / `Button` / `TextLink`) の組み替えのみ。hex 直書きを増やさない。
  色トークンの新設なし。Atomic Design の層も跨がない (page 内の分岐のみ)。

## スコープ外

- `expired_checkout` が抱える**二重の意味** (「有償契約後の支払い不健全」と
  「checkout が期限切れ / 失敗した未契約」) の分離。これを直すには
  `BillingAccess::state()` の分類そのもの = 課金ゲートの判定ロジックに触れる必要があり、
  今回の禁止事項に当たる。**現行文言を維持する** (後退させない) にとどめ、
  「保証しないもの」に残す。
- `past_due` かつ支払い方法あり (= `Subscribed` 扱いで利用継続中) の組織への
  dunning 警告表示。今日も出ていないため今回の finding ではない (**追加機能**になる)。
- `/billing` 画面・`/billing-required` 画面の文言 (どちらも状態別の説明を既に持つ)。
- passkey 以外の fetch 経路の 429 分類 (Inertia 経路は T129 の Error 画面が既に担当)。
- F-2-03 (招待 token の email 非照合) / F-3-01 (clipboard) — 別 topic。
