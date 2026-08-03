Round 2 の指摘 3 件をすべて反映した。対応内容と根拠は以下のとおり。再レビューを依頼する。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Warning] fail-closed な `session_id` でも canonical へ 303 するのかが曖昧
- 判断: **対応する**
- 根拠: 指摘のとおり読み取れる余地があった。意図は「着地 query を認識した時点で必ず畳む」。
  そうしないと「バナーは出ないが URL に session_id が残り続ける」= 次のリロードでまた
  DB を引きに行く状態が残り、one-shot 契約の穴になる。
  `resolveAutoRechargeSetupLanding()` の未追跡 session 分岐 (flash なしで 303) が既存の前例。
- 対応内容: 規約 3 として明文化 (「feedback の有無に関わらず必ず canonical へ 303」)。
  テスト計画に「fail-closed 5 ケースで `Location: /billing` かつ feedback flash なし」を追加。

## [Warning] `highlight` 保持規約が feedback resolver だけか、3 resolver 共通か不明
- 判断: **対応する**
- 根拠: resolver ごとに規約が割れると、まさに今回のような「コメントと実装の乖離」を再生産する。
  現状 `resolveAutoRechargeSetupLanding()` は素の `/billing` へ倒しており `highlight` を落とす。
- 対応内容: canonical URL 構築を **3 resolver 共通のヘルパ**に寄せる方針を明記
  (保持 query の allowlist + 呼び出し側の追加 query のマージ。T1004 は `highlight` を自分で立てる)。
  テストは `?session_id` + `?highlight` と `?setup_session_id` + `?highlight` の 2 本で固定。
  なお両方が同時に来る導線は現状存在しない (Stripe に渡す success_url に `highlight` は含まない)
  ため、これは「規約の統一」であって新機能ではない。

## [Warning] 「直接再訪でも DB 現在値から再導出されるので嘘にならない」は自設計と矛盾
- 判断: **対応する (指摘が正しい)**
- 根拠: 設計内で「`pending` は『決済済み・webhook 待ち』と『Checkout 放棄』を区別できない。
  だからイベント性 (session_id 付きで戻ってきたこと) が根拠だ」と論じている以上、
  イベント性の無い直接再訪で `purchase_processing` を出すのは**嘘になりうる**。矛盾していた。
- 対応内容: 「嘘にならない」を削除し、「残余リスクが残るが、通常導線の修正を優先して受容する」
  に書き換えた。

## [Suggestion] 詳細設計で `error` 判定 → `keep()` → feedback flash の順序を固定する
- 判断: **対応する (詳細設計に持ち込む)**
- 対応内容: 詳細設計の `resolveBillingFeedbackLanding()` 実装で
  (1) 着地 query 判定 → (2) `keep()` → (3) `error` 判定による suppress → (4) kind 解決 → (5) 303
  の順序をコード + docblock で固定する。

## [Suggestion] 使命 / 禁止事項 / スコープ / 型安全性
- 判断: 指摘なし。現状維持。

---

## 修正後の概念設計 (全文)

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

### 着地 hop で「他の状態」を落とさないための規約 (Codex R1 [Critical] 対応)

追加する 303 は**内部 hop** であり、ユーザー向けの他の状態を消費してはならない。次を規約とする。

1. **flash の透過**: 着地 redirect では `HandleInertiaRequests` が共有する 4 キー
   (`success` / `error` / `info` / `warning`) を `session()->keep([...])` で次リクエストへ持ち越す。
   hop 自体が flash を 1 消費してしまう事故 (= 直前の error トーストが消える) を構造的に防ぐ。
   - ただし **`error` が積まれている着地では feedback を出さない**という現行の
     成功偽装抑止ルールは維持する (error だけが次 render に残る)。
2. **query の透過**: `/billing` の query surface は閉じており、
   `setup_session_id` / `session_id` / `portal` / `replayed` / `retry` / `highlight` の 6 つで全部
   (grep で確認。`?plan=` handoff は `onboarding.*` / `SocialAuthController` の所管で `/billing` には来ない。
   `return_to` は session 側で query ではない)。
   本設計後に残る query は **`session_id` / `portal` / `setup_session_id` (外部 Stripe からの戻り)** と
   **`highlight` (副作用なしの scroll anchor)** のみ。
   着地 hop は **着地 query (畳む対象) を allowlist で明示し、それ以外の既知 query
   (= `highlight`) は保持**して redirect する。
   この canonical URL 構築は **3 つの着地 resolver が共通のヘルパを使う**
   (`setup_session_id` 着地も現在は素の `/billing` へ倒しており `highlight` を落とす。
   実際に両方が同時に来る導線は今は無いが、規約を resolver ごとに割らない)。
   T1004 着地だけは `highlight=auto-recharge` を**自分で立てる** (保持ではなく上書き) ため、
   ヘルパは「保持する query に呼び出し側の追加分をマージする」形にする。
3. **着地 query を認識したら feedback の有無に関わらず必ず canonical へ 303 する**。
   未知 session / 他 org / `intent` 不一致 / `failed` / `expired` の fail-closed 経路でも
   **flash を積まずに 303 する** (query を残さない)。
   = 「バナーは出ないが URL に session_id が残り続ける」状態を作らない
   (`resolveAutoRechargeSetupLanding()` の未追跡 session 分岐と同じ扱い)。
4. **着地の優先順位を 1 箇所に明記する** (`index()` の docblock + docs/architecture.md):
   `setup_session_id` (P8a カード登録) → `session_id` かつ funding=auto_recharge (T1004) →
   `session_id` / `portal` (本 feedback) → 通常 render。
   先着の着地が redirect を返したら後段は評価しない (相互排他)。テストで固定する。
5. **DTO 構築より前に着地判定を行う**。`resolveOnboardingContinue()` は
   `return_to` を **peek + forget (消費)** するため、hop する request で DTO を組むと
   復帰先を無音で失う。着地判定 3 つはすべて DTO 構築の前に置く (現行の並びを維持)。

### 併せて行う整理 (後方互換の並走を残さない)

- `?replayed=1` / `?retry=1` は **アプリ自身が発行している query** (`checkout()` 内の
  `redirect()->route('billing.index', ['replayed' => 1])` など)。着地で畳み直すのは無駄なので、
  **発行側で最初から flash に載せる** (query を発明しない)。
  → `resolveBillingFeedback()` の `replayed` / `retry` 分岐は**削除**する。
  → 外部 (Stripe) から戻ってくる `session_id` / `portal` だけが query 由来として残る。
- 文言は `BillingFeedbackKind` に集約する (`kind` だけを flash に載せれば済み、
  flash payload が検証可能なスカラ 1 個で閉じる = PHPStan level 10 で shape assertion 不要)。
  **復元経路も型で閉じる**: session から取り出した `mixed` は
  `is_string($raw) ? BillingFeedbackKind::tryFrom($raw) : null` で enum に落とし、
  未知値・欠落は「feedback なし」に倒す (fail-closed)。
  `BillingFeedbackDto` は **enum しか受け取らない** (`fromKind(BillingFeedbackKind $kind)`) ため、
  生文字列が DTO 境界を越えることがない。

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
- F-3-04 (High) のクローズ。**通常のリロード・戻る・ブックマーク起点での再発を構造的に防ぐ**
  (303 後の履歴エントリが query なしの `/billing` になるため)。
  なお「query 付き URL を手で入力し直す / メール等に控えた URL を開く」ケースまでは防がない
  = **非スコープ**。この経路では「Stripe から今戻った」というイベント性が無いにもかかわらず
  古いイベント文脈 (放棄された `pending` 行に対する `purchase_processing` 等) を
  再提示しうる**残余リスクが残る**が、通常導線 (リロード・戻る・ブックマーク) の修正を優先し、
  これを受容する。完全な単回消費には GET での DB 書き込みが必要で、代案 C として却下している。
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
5. `docs/architecture.md` — one-shot の担保方式を明記する。書く内容は 3 点:
   (a) **one-shot = 「サーバが同じ状態を再主張しない」**という意味であること
   (ブラウザの bfcache 復元など DOM 側の復元まで禁じる契約ではない)、
   (b) 担保方式 (着地 query は canonical へ 303 で畳み、feedback は 1 リクエスト限りの flash で運ぶ)、
   (c) 副作用境界 (着地 hop は他の flash を落とさない / 畳む query は allowlist / 着地の優先順位)。
6. テスト (詳細設計で層まで確定):
   - Feature: 着地 → 303 (canonical へ・query なし) → 次 render にバナー → **さらに次の GET で消える**。
   - Feature: fail-closed (他 org / 未知 / setup intent / failed / expired) では
     **flash を積まない**ことを直接 assert (props が null なだけでは one-shot 前提が変わったとき緩む)。
   - Feature: `?portal` + error flash では feedback を出さず、**error flash を取りこぼさない**。
   - Feature: **着地 hop が他の flash を落とさない** (`?portal` + error flash で error が次 render に生存)。
   - Feature: **着地の優先順位と相互排他** (`?setup_session_id` と `?session_id` の同時指定で
     setup 着地が勝つ)。
   - Feature: **canonical query 構築の共通規約** (`?session_id` + `?highlight=auto-recharge` /
     `?setup_session_id` + `?highlight=auto-recharge` の双方で `highlight` が保持される)。
   - Feature: **fail-closed でも canonical へ 303 する** (未知 / 他 org / setup intent /
     failed / expired の各ケースで `Location: /billing` かつ feedback flash なし)。
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
