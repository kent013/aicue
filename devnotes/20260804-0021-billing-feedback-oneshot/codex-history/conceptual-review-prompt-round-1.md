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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 前提情報 (レビュー対象の背景)

これは LLM 探索的バグハントで確定した finding F-3-04 (High) への設計である。
リポジトリは /workspace。関連する現行実装は以下 (必要なら read してよい):
- app/Http/Controllers/Billing/BillingController.php (index / checkout / portal / resolveBillingFeedback / resolveAutoRechargeSetupLanding / resolveAutoRechargeLanding)
- app/DataTransferObjects/Billing/BillingFeedbackDto.php
- app/Enums/Billing/BillingFeedbackKind.php
- resources/js/pages/Billing/Index.svelte
- resources/js/lib/stores/flash-to-toast.ts
- app/Http/Middleware/HandleInertiaRequests.php (flash 共有)
- tests/Feature/Billing/BillingFeedbackTest.php
- docs/architecture.md (§サブスク契約 Checkout とオンボーディング着地)

## 概念設計

# 概念設計: billing-feedback-oneshot (F-3-04)

## 背景・課題

bug-hunt run `20260803-203721` の finding **F-3-04 (High)**:
**P9 着地 feedback バナーが「one-shot」契約を満たしていない。**

- 再現 (shard-3): `/billing?session_id=cs_...` に着地 → バナー表示 → **ブラウザリロード**
  → 同じバナーが復活する。何度でも復活する。
- 原因は「`?session_id=` を毎 render 解釈している」こと:
  - `BillingController::resolveBillingFeedback()` (L449-511) は
    `session_id` / `portal` / `replayed` / `retry` の **query を都度読んで** DTO を組み立てる。
  - `resources/js/pages/Billing/Index.svelte:44` のコメントは
    「一度表示したら消える (リロードで query が落ちれば feedback は null で届く)」と書くが、
    **ブラウザの reload は query を保持したまま再送する**ので前提が誤り。
  - クライアント側の `history.replaceState` / `router.replace` による scrub は存在しない (grep 済み)。
- 実害 (H10 / 直前操作と矛盾する状態表示):
  Stripe からの戻り URL が履歴・ブックマークに残るため、後日再訪すると
  「お支払いを確認しています」等の古い文脈のバナーが何度でも再提示される。

### 正本 (docs/architecture.md) との関係 — どちらを直すか

`docs/architecture.md` §サブスク契約 Checkout とオンボーディング着地 (L286-291) は

> **着地 feedback (P9)**: … `/billing` 着地で one-shot バナーを出す …
> `PurchaseFormState::Completed` 撤去後、**購入完了を伝える唯一の経路**。

と書いている。**正本の意図 (one-shot) が正しく、実装が正本に追いついていない**と判断する。
→ **実装を直す**。あわせて正本には「one-shot を何で担保しているか (query は canonical へ 303 で畳み、
feedback は 1 リクエスト限りの flash で運ぶ)」を 1 文追記して、今回のような
「コメント上の願望」と実装の乖離が再発しないようにする。

### 壊してはならない既存の良い点 (bug-hunt が確認済み)

- **cross-org fail-closed**: 他 org の実 session_id を付けてもバナーは出ない
  (org スコープ relation `$organization->checkoutSessions()` 経由 + `intent` 検証)。
  この 2 段の fail-closed は**一切弱めない**。
- **failed / expired は無言** (状態を主張しない)。
- **`?portal` 着地で error flash があるときは feedback を出さない** (成功偽装の抑止)。
- **UI は raw query を見ない** (`feedback` DTO のみ描画)。

## 改善アイデア

**「着地 query を canonical URL へ 303 で畳み、feedback は session flash で 1 render だけ運ぶ」**。
= このコントローラに既にある `resolveAutoRechargeSetupLanding()` (`?setup_session_id`) と
`resolveAutoRechargeLanding()` (T1004) と**同じ着地パターン**に揃える。

```
GET /billing?session_id=cs_xxx
  → (現行と同一の org スコープ + intent 検証で kind を確定)
  → 303 Location: /billing        （flash: billing_feedback_kind=purchase_received）
GET /billing
  → flash を 1 回だけ読み、feedback DTO を props に載せて描画
GET /billing (リロード)
  → flash は消費済み → feedback = null → **バナーは復活しない**
```

- 303 でブラウザの履歴エントリは `/billing` (query なし) になるため、
  リロードもブックマークも「戻る」も query 付き URL を再送しない
  = 報告された再現手順が構造的に成立しなくなる。
- flash は Laravel の標準機構 (次の 1 リクエストだけ生存) であり、
  **one-shot を自前で実装しない** (思考原則 1: フレームワークのレンジ内)。

### 併せて行う整理 (後方互換の並走を残さない)

- `?replayed=1` / `?retry=1` は **アプリ自身が発行している query** (`checkout()` 内の
  `redirect()->route('billing.index', ['replayed' => 1])` など)。着地で畳み直すのは無駄なので、
  **発行側で最初から flash に載せる** (query を発明しない)。
  → `resolveBillingFeedback()` の `replayed` / `retry` 分岐は**削除**する。
  → 外部 (Stripe) から戻ってくる `session_id` / `portal` だけが query 由来として残る。
- 文言は `BillingFeedbackKind` に集約する (`kind` だけを flash に載せれば済み、
  flash payload が検証可能なスカラ 1 個で閉じる = PHPStan level 10 で shape assertion 不要)。

## 代案と却下理由

| 案 | 却下/採用 | 理由 |
|---|---|---|
| **A. サーバ 303 + flash (採用)** | 採用 | 同一 controller に前例が 2 つ (`setup_session_id` / T1004) あり、機構を発明しない。GET に副作用なし。URL が履歴・ブックマークに残らない。 |
| B. クライアントで `history.replaceState` / `router.replace` して query を scrub | 却下 | (1) サーバが依然 query を正としたままで、JS 無効/初回描画前リロードで再発する。(2) Inertia の history 管理と二重に URL を書き換える。(3) 同一 controller の他 2 着地とパターンが割れる (タコツボ実装)。(4) 「バナーを描いた後に URL を書き換える」= 表示と URL の一貫性が描画順に依存する。 |
| C. サーバに消費マーカー (`billing_checkout_sessions.feedback_shown_at` 等) を立てる | 却下 | **GET に DB 書き込みの副作用を持たせる**ことになる。同 controller の `resolveAutoRechargeSetupLanding()` は「状態の書き込みは webhook の管轄。ここは表示のみ = GET で副作用を起こさない」と明記しており、その不変条件を壊す。さらに冪等マシン (`BillingCheckoutSession` の状態機械 / AGENTS.md セキュリティ不変条件 #7) に**表示都合の列**を持ち込む (別物の概念の統合。思考原則 4)。マイグレーション + 状態機械の面積増に見合う便益がない (思考原則 2)。得られる差分は「query 付き URL を手で再入力したときも 2 度目は出ない」だけで、A では履歴・ブックマークからその URL に到達できない。 |
| D. feedback を汎用 flash (`success`/`info`) に載せ替えて `BillingFeedbackDto` ごと廃止 | 却下 | 汎用 flash は `flash-to-toast` で**自動消滅する toast** になる (`resources/js/lib/stores/flash-to-toast.ts`)。`PurchaseFormState::Completed` 撤去後、このバナーは**購入完了を伝える唯一の経路**であり、数秒で消える toast に格下げするのは後退。ページ内常在の `Alert` (atom のコメント: 「ページ内に常在するインライン通知ボックス (一時通知は Toast を使う)」) を維持する。 |

## 「唯一の経路」を痩せさせないための担保

one-shot 化は「気づかないうちに消える」リスクと表裏なので、次を設計制約とする:

1. **描画先は今までどおりページ内常在の `Alert`** (toast にしない)。
   着地 render の間はユーザーが離脱するまで消えない。
2. **着地 render は必ず 1 回発生する**。303 の直後の GET が flash を読むため、
   「フィードバックが誰にも読まれずに消える」経路は存在しない
   (flash を消費しうる中間リクエストが挟まらない)。
3. `purchase_processing` (webhook 未達の窓) を**恒久表示に格上げしない**。
   DB 上の `pending` は「決済済み・webhook 待ち」と「ユーザーが Checkout を放棄」を
   区別できない (両方 `pending` で最大 1 日 live)。恒久バナー化すると
   放棄したユーザーに「お支払いを確認しています」と嘘をつくことになり、H10 を別の形で再生産する。
   **「Stripe から session_id 付きで戻ってきた」というイベント性こそが根拠**なので、
   イベント (= one-shot flash) のまま扱うのが正しい。
4. 一方で `purchase_received` の**恒久的な裏付け**は既に画面上にある
   (webhook 反映後は「現在のプラン」カードと次回請求日・チケット残高が変わる)。

## 期待効果

- **使命への貢献**: 「思考ゼロ」を掲げる以上、決済まわりで「直前の操作と矛盾する古い状態」を
  何度も突きつけないことは体験の前提条件。現場管理者が課金画面で迷わない。
- F-3-04 (High) のクローズ。リロード・履歴・ブックマークのいずれからも古いバナーが復活しない。
- 着地処理のパターンが `/billing` の 3 経路 (`setup_session_id` / T1004 / feedback) で統一され、
  次の着地を足す人が迷わない。

## 実装方針（概要）

1. `app/Enums/Billing/BillingFeedbackKind.php` — `message(): string` を追加 (文言の単一出典)。
2. `app/DataTransferObjects/Billing/BillingFeedbackDto.php` — `simple()` を `fromKind()` へ置換
   (旧 API は残さない)。`toArray()` の shape は不変 = **TS 型・props 契約は無変更**。
3. `app/Http/Controllers/Billing/BillingController.php`
   - `resolveBillingFeedback()` → `resolveBillingFeedbackLanding(): ?RedirectResponse` へ作り替え。
     `session_id` / `portal` を検証 (**org スコープ + intent の fail-closed は現行式をそのまま移送**)
     し、303 + flash(kind) で canonical `/billing` へ畳む。query が無ければ素通し (null)。
   - `index()` は flash から kind を読んで DTO を組む。**着地判定は DTO 構築より前**
     (`resolveOnboardingContinue()` が return_to を消費する前に redirect する必要があるため)。
   - `checkout()` の `['replayed' => 1]` / `['retry' => 1]` を flash 発行へ置換。
   - `portal()` の return_url は `?portal=1` のまま (Stripe に渡す URL なので query が必要)。
4. `resources/js/pages/Billing/Index.svelte` — **誤ったコメントを実装に合わせて訂正**
   (ロジック変更なし)。
5. `docs/architecture.md` — one-shot の担保方式を明記。
6. テスト (詳細設計で層まで確定):
   - Feature: 着地 → 303 (canonical へ・query なし) → 次 render にバナー → **さらに次の GET で消える**。
   - Feature: fail-closed (他 org / 未知 / setup intent / failed / expired) では
     **flash を積まない**ことを直接 assert (props が null なだけでは one-shot 前提が変わったとき緩む)。
   - Feature: `?portal` + error flash では feedback を出さず、**error flash を取りこぼさない**。
   - Feature (回帰): `checkout()` の replay / stale 経路が query ではなく flash を出す。
   - JS: 既存 `tests/js/pages/Billing/Index.test.ts` の feedback 群は props 契約が不変なので維持。

## 制約・前提

- AGENTS.md セキュリティ不変条件 #3 (cross-org 不可): 検証は `$organization->checkoutSessions()`
  relation 経由のまま。**query から org を導かない**。
- AGENTS.md セキュリティ不変条件 #7 (課金の冪等性): `BillingCheckoutSession` の状態機械・
  webhook 冪等マシンには**一切触れない** (今回は表示経路だけの変更)。
- 禁止事項 #4: props は `BillingDashboardDto` 1 本のまま (`response()->json()` を増やさない)。
- 禁止事項 #7 (`redirect()->intended()` 禁止): 使わない。着地は常に named route への redirect。
- 思考原則 3: `?replayed` / `?retry` の query 解釈は削除し、新旧を並走させない。
- ドメイン規約 #3 (bfcache): `/billing` は認証済みページで no-store baseline + bfcache guard の
  対象。bfcache 復元でバナーが一時的に再表示されても、それは「同一 render の DOM 復元」であり
  サーバ状態の再主張ではない (guard の再検証で最新化される)。設計はこの層に手を入れない。

## スコープ外

- `/purchase-tickets?purchased=1&session_id=...` の同種の query 依存
  (同じ「着地 query が残る」species だが、`purchased` フラグは resume 導線の抑止
  (`TicketPurchaseController::show()` の `$resumable` 条件) と結合しており、
  畳むと「支払い済み Checkout への『決済を続ける』直リンク」が復活しうる。
  別 finding として切り出すべきで、本設計では触らない)。
- `?highlight=auto-recharge` (T1004 着地) の query は残す。副作用のない scroll anchor であり、
  状態を主張しないためリロードで再スクロールしても嘘にならない。
- `BillingFeedbackKind` の PHP enum ⇔ TS union 同期テスト (`TsUnionValues`) の新設。
  値集合を変えないため今回の不変条件ではない。
- feedback 文言そのもののコピー改善、`purchase_processing` 中の恒久的な進捗表示の新設。
