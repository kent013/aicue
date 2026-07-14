## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
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
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか（本件はフロントのみ）

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

（以下は `devnotes/20260714-1654-stale-validation-sweep/conceptual-design.md` の全文。関連コードは `resources/js/pages/Organizations/Settings.svelte`, `resources/js/pages/Projects/Show.svelte`, 参考修正 `resources/js/pages/Billing/PurchaseTickets.svelte` を read 可能。）

# 概念設計: stale-validation-sweep

## 背景・課題

bug-hunt (real-llm 2nd run) の **F-3-01 (Medium, H12 / validation_gap)** で、
オーナー移譲フォーム (`organizations.transfer-ownership`) の「移譲先メンバー」select に
stale validation が残ることが報告された。

- 症状: select を空値のまま送信ボタンを押すと「移譲先のメンバーを選択してください。」の
  エラー文言 + `aria-invalid` が表示される。その後**有効な候補を選び直しても**エラー表示が
  消えず、視覚的に「有効な選択なのにエラー」という矛盾状態が残る (H12: 回復性の破綻)。
- 機能自体はブロックされない (submit すれば正しく進む) が、ユーザーを誤解させる UX 破綻。

これは前回の **T041 (purchase-tickets)** で修正した同種バグの**横展開漏れ**である。
T041 では「client-side バリデーションのエラー state を submit 内でのみセット/クリアし、
入力修正 (oninput / derived) に連動しない」パターンを `$effect` ガードで解消した。
同じパターンが他フォームに残存しているため、本件で横展開して掃討する。

### 現状パターンの棚卸し (grep 由来)

`resources/js/` 内で「submit ハンドラ内で client-side に field エラーをセットするが、
値修正に連動してクリアしない」フォームは以下:

| フォーム | ファイル | エラー設定 | 状態 |
|---|---|---|---|
| 購入枚数 | `Billing/PurchaseTickets.svelte` | `clientError` ($state) | **T041 で修正済 (対象外)** |
| メンバーのロール変更 | (member-role) | — | **T033 で修正済 (対象外)** |
| **オーナー移譲** | `Organizations/Settings.svelte` L107-124 | `transferForm.setError("user_id", …)` | **未修正 (本件対象)** |
| **プロジェクトメンバー追加** | `Projects/Show.svelte` L123-137 | `memberForm.setError("user_id", …)` | **未修正 (本件対象)** |

`grep -rn "setError" resources/js/` の結果は上記 3 箇所 (PurchaseTickets は独自 `clientError`
$state)。すなわち client-set field エラーを持つフォームは本件の 2 つで**掃討完了**となる。

## 改善アイデア

T041 と同一の**最小 `$effect` ガード方式**を 2 フォームに横展開する:

> 値が有効へ復帰した時点で、client-set エラーを連動クリアする。
> `if (clientError !== null && isValid) clientError = null`

「押下時にエラー表示」契約 (禁止事項 #8, DESIGN.md) は維持する — 無効のままなら
エラーは残し、有効へ復帰したときだけクリアする (過剰クリア防止)。

### serverErrors を対象外に保つための設計判断 (重要)

対象 2 フォームは Inertia `useForm()` を使い、client-set エラーを
`form.setError("user_id", …)` で **`form.errors.user_id` に載せている**。この bag は
サーバ validation エラー (POST 往復由来) と**共有**されている。

そのため「`form.errors.user_id` を有効時に `clearErrors` する」単純案は、サーバが
返した `user_id` エラー (例: 対象が非メンバー・personal org 等の深いドメイン理由) を
client 側の浅い有効判定だけで消してしまい、**serverErrors を過剰クリアする退行**を招く。

→ よって T041 と同じく **transient な専用 client-error state を別に持たせる**方式を採る:

- `let clientTargetError = $state<string | null>(null)` を導入
- submit precheck では `form.setError` の代わりにこの state をセット
- 表示は `error={clientTargetError ?? form.errors.user_id}` (client 優先、無効時のみ)
- `$effect` は `clientTargetError` のみをクリア対象にし、`form.errors` には触れない

これにより serverErrors 経路は完全に不変 (非退行) となる。

## 期待効果

- **使命への貢献**: AI-CUE は「思考ゼロ」で現場作業者が使えることが核。フォームの
  矛盾フィードバック (有効なのにエラー) は最も直接的に「迷い」を生む UX 破綻であり、
  その掃討は操作の確信度を底上げする。オーナー移譲・プロジェクトメンバー追加は
  組織運用の要の操作で、ここで詰まらせない意義は大きい。
- 具体的改善: 対象 2 フォームで「無効入力→エラー→有効値に修正→エラー即時消失 +
  `aria-invalid` 解除」が成立し、F-3-01 の視覚矛盾を解消する。
- 横展開の完了: client-set field エラーを持つ全フォームが同一契約に揃い、以後の
  bug-hunt で同種指摘が再発しない (パターンの掃討)。

## 実装方針 (概要)

### 対象 1: オーナー移譲 (`Organizations/Settings.svelte`)

- `transferForm.setError("user_id", …)` (L110, L120) を専用 `clientTargetError` state の
  代入に置き換える。
- 有効判定 derived を追加: `isValidTransferTarget = transferCandidates.some(m => String(m.id) === transferForm.user_id)`
  (これは既存 precheck L116-118 と同一条件 = 「submit が通る条件」の否定がエラー条件)。
- `$effect(() => { if (clientTargetError !== null && isValidTransferTarget) clientTargetError = null; })`。
  候補 0 人ケース (L109-115) のエラーは `isValidTransferTarget` が常に false のため残留する
  (= 選択では直せない → 過剰クリアしないのが正しい)。
- FormField の `error` を `clientTargetError ?? transferForm.errors.user_id` に変更。

### 対象 2: プロジェクトメンバー追加 (`Projects/Show.svelte`)

- `memberForm.setError("user_id", …)` (L128) を専用 `addMemberError` state の代入に置き換える。
- 有効判定 derived: `isAddMemberSelected = memberForm.user_id !== ""` (既存 precheck L127 の否定)。
- `$effect(() => { if (addMemberError !== null && isAddMemberSelected) addMemberError = null; })`。
- FormField の `error` を `addMemberError ?? memberForm.errors.user_id` に変更。
- POST 成功時 `memberForm.reset()` に合わせ、`addMemberError` も念のため `null` へ戻す
  (reset は user_id を空へ戻すため、再び無効になっても直前エラーが残らないよう整合させる)。

いずれも pages 層のロジック追加のみ。UI 構造・DS token・atomic import グラフ・
Inertia Props・PHP / DTO / Resource は変更なし。

## 制約・前提

- Svelte 5 runes (`$state` / `$derived` / `$effect`)。T041 と同一イディオム。
- 「押下時にエラー表示」契約 (禁止事項 #8) を維持。エラーは submit precheck でのみセットし、
  disabled は使わない。
- serverErrors (`form.errors.*`) は読み取り表示のみで、本件では書き換えない (非退行)。
- テストは vitest。対象 2 ページには既存テスト
  (`tests/js/pages/OrganizationsSettings.test.ts` / `ProjectsShow.test.ts`) があり、
  `useForm` は実物・`router` のみモックの既存流儀に追随する。

## スコープ外

- T041 (purchase-tickets) / T033 (member-role) の再修正 — 対象外。
- serverErrors の表示ロジック・往復挙動の変更 — 対象外 (非退行のみ担保)。
- PHP / バリデーションルール / DTO / Policy の変更 — 一切なし (フロントのみ)。
- select 以外の新規バリデーション追加や UI リデザイン — しない (最小修正)。

