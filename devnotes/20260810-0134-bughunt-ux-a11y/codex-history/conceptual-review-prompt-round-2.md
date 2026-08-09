Round 1 の指摘 (Critical 0 / Warning 5) をすべて捌いた。対応マトリクスと改訂後の概念設計を渡すので再レビューしてほしい。

特に確認してほしい点:
- 施策 C は指摘を受けて**方針を変更**した。呼び出し元 3 箇所を実地確認したところ、a11y 欠落は
  `Onboarding/Checkout.svelte` の 1 箇所だけで、そこは「現在位置」ではなく「選択肢 UI」だった。
  よって `PricingPlanCard` (molecule) には一切手を入れず、`Button` (atom) に `ariaPressed` を足して
  操作要素の側に状態を載せる形にした。この判断が妥当か。
- 施策 A の「録画中は自動スクロール・フォーカス移動を行わない」という限定が、
  既存仕様 (録画中でもカット切替は可能) を変えずに新挙動だけを安全側に倒せているか。
- 受入条件 7 行が finding 3 件を過不足なく固定できているか (取りこぼし / 過剰がないか)。

# 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

Critical はゼロ。Warning 5 件はすべて対応した。

## [Warning] 概念設計時点で回帰固定方針が未確定 (禁止事項 1)
- 判断: **対応する**
- 根拠: 「テストは詳細設計で確定」は、設計が通ってから受入条件を後付けする形になり、
  受入条件が実装の都合に寄る危険がある。finding ごとの受入条件は概念設計の一部である。
- 対応内容: 「受入条件 (finding ごとに 1 行 + 固定するレーン)」節を新設。7 行の受入条件と
  固定レーン (Browser: Chromium + WebKit / vitest) を明記した。

## [Warning] scrollIntoView だけではキーボード/SR の現在位置が一覧側に残る (H13 修正が H14 欠落を生む)
- 判断: **対応する**
- 根拠: 指摘のとおり。bug-hunt は H13 と H14 を同じ表で扱っており、片方の修正で
  もう片方を作るのは本末転倒。「視点」と「フォーカス」は同時に運ぶべき。
- 対応内容: 施策 A を「視点**と**フォーカスを運ぶ」に改め、
  (a) 撮影パネル先頭に見出し + `tabindex="-1"` を置きカット選択時に `focus()`、
  (b) 見出しに選択中カット名を含める、(c) 受入条件に「フォーカスが撮影パネル先頭へ移る」
  「lg 以上ではフォーカスも動かない」を追加した。

## [Warning] 「次回以降の bug-hunt で再燃しない」は先走り
- 判断: **対応する**
- 根拠: テストが固定するのは受入条件そのものであって、周辺の類似欠落までは守らない。
  保証範囲を誇張しないのは AGENTS.md の基調でもある。
- 対応内容: 期待効果を「同一条件をテストで固定する」に書き換え、
  括弧書きで「周辺の類似欠落まで守るわけではない」と明示した。

## [Warning] `aria-current="true"` の意味論が危うい / prop 名も再検討すべき
- 判断: **対応する (方針を変更)**
- 根拠: 指摘を受けて呼び出し元 3 箇所を実地確認したところ、初版の前提が誤っていた。
  - `Onboarding/Checkout.svelte` は**選択肢 UI** (カードごとに「選択」Button があり、
    `selectedPlanCode = chosenPlanCode ?? ?plan= 由来の初期値`)。現在位置ではないので
    `aria-current` は誤り。状態は**操作要素 (Button)** に載せるのが正しい。
  - `Billing/_helpers/PlanCard.svelte` は `headerBadges` に Badge「現在のプラン」を
    既に持っており、**テキストとして a11y ツリーに出ている = 欠落なし**。
  - `Guest/Pricing.svelte` は選択状態ではない。
  つまり欠落は Checkout の 1 箇所だけで、molecule に意味論 prop を足す必要そのものが無かった。
- 対応内容: 施策 C を全面改稿。`PricingPlanCard` は**変更しない**。
  代わりに `Button` atom へ `ariaPressed?: boolean` を追加 (既存 `ariaExpanded` /
  `ariaControls` と同じ button モード専用 aria prop の枠)、
  `Onboarding/Checkout.svelte` の「選択」Button に `aria-pressed={selectedPlanCode === plan.code}` を付与。
  表示文言 (`chosenPlanCode` 基準の「選択中」) は動かさない
  (押していないものを「選択中」と表示すると別の誤認を作るため)。
  スコープ外に「Billing への ARIA 追加」「molecule への意味論 prop (初版案の撤回)」を明記。

## [Warning] 録画中・権限ダイアログ中のカット切替/スクロール挙動を確認すべき
- 判断: **対応する**
- 根拠: `Capture/Show.svelte` を確認したところ、`captureActive` state は既にあるが
  カット切替は録画中でも抑止されていない (既存仕様)。ここで切替可否そのものを変えるのは
  スコープ拡大なので、**既存仕様は変えず、新しい挙動 (自動スクロール/フォーカス移動) だけを
  録画中は抑止する**のが最小かつ安全。
- 対応内容: 施策 A に「録画中 (`captureActive === true`) は自動スクロール・フォーカス移動を
  行わない」を追加し、受入条件にも 1 行足した。スコープ外に「録画中カット切替の可否そのものの
  見直し」を明記した。

## [Warning] Svelte 側 prop 追加は明示型 + 未指定時の差分なしをテストで固定
- 判断: **対応する**
- 根拠: 妥当。`ariaPressed` を optional にしただけでは「未指定で属性が出ない」ことは保証されない。
- 対応内容: `ariaPressed?: boolean` (optional・既定値なし・未指定なら属性を出力しない) と明記し、
  受入条件に「`ariaPressed` 未指定の既存 Button は `aria-pressed` 属性を出力しない (vitest)」を追加。
  呼び出し元 3 箇所の意味差分については、そもそも `PricingPlanCard` を変更しない方針に変えたため
  差分は発生しない (Checkout の 1 箇所のみ Browser テストで固定)。

## [Suggestion] 採用したもの
- `matchMedia('(prefers-reduced-motion: reduce)')` の SSR/ブラウザ境界に注意 → 施策 A に明記した。
- 施策 A の breakpoint 判定は `lg` の値を二重管理しないよう**レイアウトの実測**で行う点も追記した
  (Codex の指摘ではないが、同じ「二重管理を避ける」観点で自主的に足した)。


---

# 改訂後の概念設計

# 概念設計: bughunt-ux-a11y (bug-hunt run 20260809-152048 のアプリ側 finding 修正)

## 背景・課題

2026-08-09 のフルサイズ bug-hunt (run `20260809-152048`, 4 shard 並列) で、アプリ側に 3 件の finding が出た。
Critical/High は 0 件で、いずれも「機能は動くがユーザーが目的に到達しにくい」種類の欠落である。

### F-1-03 (Medium / H13 レスポンシブ) — 撮影 PWA モバイル幅でカット選択後に撮影パネルへ到達できない

`resources/js/pages/Capture/Show.svelte` L178 のレイアウトは
`grid grid-cols-1 gap-4 lg:grid-cols-2` で、**lg 未満では左ペイン (CutNavigator = シナリオ一覧) の下に
右ペイン (ナレーション + CameraRecorder + TakeStrip) が縦積みされる**。
カット行をタップしても `selectedCutId` が変わるだけでスクロール位置は動かないため
(`window.scrollY` は 0 のまま)、ユーザーは毎回 14 件以上のカット一覧を手動でスクロールして
撮影パネルまで降りる必要がある。

これは AI-CUE の North Star そのもの ——「スマホ(PWA)でナビゲーション撮影する」「思考ゼロ・編集ゼロ」——
の中核体験で、**カットを切り替えるたびに毎回発生する**。現場で片手にスマホを持ち、
カット 1 → 撮影 → カット 2 → 撮影 … と進む主要ワークフローの摩擦であり、
「AI が撮影を指示する」はずのナビが、実際にはユーザーに「撮影 UI を探させて」いる。

### F-1-02 (Low / H14 a11y) — 完成動画・プレビューの `<video>` にアクセシブルネームが無い

`RenderPanel.svelte` L370 (`data-testid="preview-video"`) と
`TakePreviewDialog.svelte` L78 (`data-testid="take-preview-video"`) の `<video>` は
`aria-label` を持たず、アクセシビリティツリーに名前付きで現れない
(実測: `document.querySelector('video').getAttribute('aria-label')` → `null`)。
視覚的には見出し「完成動画」の直下にあるので文脈から分かるが、支援技術利用者には
「動画が生成された / 再生できる」ことが伝わらない。

### F-2-01 (Low / H14 a11y) — オンボーディングのプラン事前選択が視覚のみで ARIA に出ない

`/onboarding/checkout?plan=starter` で該当プランカードが青枠 (`border-primary`) で
ハイライトされるが、DOM には `aria-current` / `aria-selected` / `aria-pressed` のいずれも無い。
`PricingPlanCard.svelte` の `isHighlighted` prop は `borderClass` (視覚) にしか効かない。
`/pricing` の「このプランで始める」から `?plan=` 付きで誘導されたスクリーンリーダー利用者は、
**意図したプランが事前選択されているか確認できないまま契約に進む**ことになる。

## 改善アイデア

### 施策 A: カット選択時に撮影パネルへ「視点」と「フォーカス」を運ぶ (F-1-03)

「ナビが撮影を指示する」という機能の名前に立ち返る。カットを選んだ瞬間に
**ユーザーが次にやること (撮る) が画面に入っている**のが正しい状態である。

ここで **視覚的スクロールだけを直すと、キーボード/スクリーンリーダー利用者の現在位置は
一覧側に残り、H13 の修正が新しい H14 欠落を生む**。視点とフォーカスは同時に運ぶ。

- **1 カラム表示のときだけ** (`lg` 未満)、カット選択時に右ペイン (撮影パネル) へ
  `scrollIntoView` する。2 カラム (lg 以上) では左右が同時に見えているのでスクロールしない
  (デスクトップで勝手に画面が動くのは退行になる)。判定は viewport 幅ではなく
  **レイアウトの実測** (右ペインが左ペインの下に来ているか) で行い、`lg` breakpoint 値の
  二重管理を避ける。
- **撮影パネル先頭に見出し + `tabindex="-1"`** を置き、カット選択時にそこへ `focus()` する。
  これによりスクリーンリーダーの読み上げ位置と Tab 順の起点が撮影パネルへ移る。
  見出しには選択中のカット名 (手順 n) を含め、「どのカットの撮影か」を名前で伝える。
- **`prefers-reduced-motion` を尊重**し `behavior` を切り替える
  (`matchMedia` は SSR 境界に注意し、ブラウザ側でのみ評価する)。
- **録画中 (`captureActive === true`) は自動スクロール・フォーカス移動を行わない**。
  録画中のカット切替は既存仕様のまま変えない (本設計で新たに禁止も許可もしない) が、
  録画中に視点とフォーカスを奪う挙動は加えない。
- **戻る導線を必ず用意する**: 撮影パネル先頭に「カット一覧へ戻る」を置く。
  スクロールで運んだ以上、帰り道が無ければ別の詰みを作る (H2 を自分で作らない)。

**採らなかった案**: モバイルをタブ/ドロワー切替 (一覧⇔撮影) にする。
表示モデルを 2 つに分岐させると CameraRecorder のライフサイクル (getUserMedia / 録画中の
アンマウント) に条件分岐が波及し、「今必要なものだけ作る」を超える。
まず摩擦の主因である「視点が運ばれない」を解消し、効果を見てから検討する。

### 施策 B: 動画要素にアクセシブルネームを与える (F-1-02)

`<video>` に `aria-label` を付ける。文言は「何の動画か」が分かるものにする
(完成動画/プレビュー、テイクは手順名を含める)。既存の
`<!-- svelte-ignore a11y_media_has_caption -->` (字幕焼き込み済みのため) は維持する
—— これは caption trackの話で、アクセシブルネームとは別軸である。

### 施策 C: プラン事前選択の状態を「操作要素の側」に出す (F-2-01)

初版では `PricingPlanCard` (molecule) に状態 prop を足して `aria-current` を出す案だったが、
呼び出し元 3 箇所を実地で確認して**方針を変えた**。

| 呼び出し元 | `isHighlighted` の意味 | 状態は今どう伝わっているか |
|---|---|---|
| `Onboarding/Checkout.svelte` | **選択肢のうち今選ばれているもの** (`selectedPlanCode = chosenPlanCode ?? ?plan= 由来の初期値`) | **伝わっていない = F-2-01**。カード内の「選択」Button のラベルは `chosenPlanCode` でしか「選択中」に変わらず、`?plan=` の事前選択では「選択」のまま |
| `Billing/_helpers/PlanCard.svelte` | **現在契約中**のプラン | `headerBadges` に `Badge`「現在のプラン」があり、**テキストとして既に a11y ツリーに出ている**。欠落なし |
| `Guest/Pricing.svelte` | 視覚的な強調枠 | 選択状態ではないので ARIA を出すべきでない |

つまり **欠落しているのは Checkout の 1 箇所だけ**で、しかもそこは
「カードが現在位置を示す」のではなく「**選択肢を選ぶ操作**」である。
`aria-current` (現在位置) ではなく、**状態を実際の操作要素 (Button) に載せる**のが正しい:

- `Onboarding/Checkout.svelte` の「選択」Button に **`aria-pressed={selectedPlanCode === plan.code}`** を付ける。
  これで事前選択されたプランは「選択 ボタン、押されている状態」と読み上げられる。
  **見た目は一切変わらない** (ラベルは `chosenPlanCode` 基準のまま。ユーザーが押していないものを
  「選択中」と表示すると別の誤認を作るため、表示文言は動かさない)。
- `Button` atom (`ButtonProps`) に **`ariaPressed?: boolean`** を追加する。
  既存の `ariaExpanded` / `ariaControls` と同じ「button モード専用 aria prop」の枠に載せる
  (未指定時は属性を出さない = 既存呼び出し元の出力は完全に不変)。
- **`PricingPlanCard` は変更しない**。`Billing` / `Guest/Pricing` も変更しない。

これにより「別物の概念を似ているから統合しない」を守りつつ、molecule に意味論を持ち込まずに済む。

## 期待効果

- **使命への貢献 (施策 A)**: 「思考ゼロ・編集ゼロ」で現場作業者が撮影できる、という North Star の
  中核導線から、カットごとに発生していた手動スクロールを取り除く。撮影カット数に比例して効く。
- **a11y (施策 B/C)**: 支援技術利用者が「動画ができた」「このプランが選ばれている」を
  認識できるようになる。契約という不可逆操作の前段で状態が伝わらない F-2-01 は特に重要。
- **回帰の固定**: 3 件とも**同一条件をテストで固定する** (「再燃しない」とまでは言わない —
  固定できるのは下の受入条件そのものであって、周辺の類似欠落まで守るわけではない)。

## 受入条件 (finding ごとに 1 行 + 固定するレーン)

| finding | 受入条件 | 固定レーン |
|---|---|---|
| F-1-03 | 1 カラム幅で任意のカットを選ぶと、撮影パネルが viewport 内に入り、かつフォーカスが撮影パネル先頭の見出しへ移る | Browser (Chromium + WebKit) |
| F-1-03 | 2 カラム幅 (lg 以上) では、カット選択でスクロール位置もフォーカスも動かない | Browser |
| F-1-03 | 録画中 (`captureActive`) のカット選択では自動スクロール・フォーカス移動が起きない | Browser |
| F-1-03 | 撮影パネルから「カット一覧へ戻る」で一覧先頭へ戻れる | Browser |
| F-1-02 | `preview-video` / `take-preview-video` が空でないアクセシブルネームを持つ | Browser (DOM 属性検査) |
| F-2-01 | `?plan=starter` で開いたとき `select-plan-starter` が `aria-pressed="true"`、他プランは `"false"` | Browser |
| F-2-01 | `ariaPressed` 未指定の既存 Button は `aria-pressed` 属性を出力しない | vitest (component) |

**Browser レーンは Chromium + WebKit の 2 レーン契約** (`docs/testing-browser.md`) に従う。
施策 A は viewport 依存かつ実スクロール/フォーカス挙動の検証なので Browser レーンが必須。
施策 B/C の属性検査も、対象画面が Inertia 描画のため Browser レーンで一緒に取る
(`ariaPressed` の未指定時の出力不変だけは vitest の component テストで足りる)。

## 実装方針（概要）

| # | 施策 | 変更コンポーネント |
|---|---|---|
| A | カット選択時の撮影パネルへのスクロール + 戻る導線 | `resources/js/pages/Capture/Show.svelte` |
| B | video のアクセシブルネーム | `resources/js/components/features/manual/RenderPanel.svelte`, `resources/js/components/features/capture/TakePreviewDialog.svelte` |
| C | プラン事前選択の状態を操作要素に載せる | `resources/js/components/atoms/Button.svelte` + `Button.types.ts` (`ariaPressed` 追加) + `resources/js/pages/Onboarding/Checkout.svelte` (付与) |

すべて **frontend (Svelte 5 runes + TypeScript) のみ**。PHP 側 (Controller / DTO / route /
migration) の変更は無く、Inertia Props の形も変えない。

テストは Browser レーン (pest-plugin-browser、Chromium + WebKit の 2 レーン契約) と
vitest のどちらで固定するかを詳細設計で確定する。**a11y 属性の存在は DOM 検査で足りるので
軽い方を選ぶ**が、施策 A は viewport 依存 (1 カラム/2 カラム) なので Browser レーンが要る。

## 制約・前提

- **DESIGN.md 準拠**: 新しい色・角丸・タイポグラフィは足さない。施策 A の「戻る」は既存の
  `TextLink` / `Button` atom を使う。hex 直書きを増やさない。
- **Atomic Design の単方向 import**: 施策 C で触るのは `Button` (atom) と pages のみで、
  pages → atom の方向は現状どおり。新規 component は作らない。
  `ariaPressed` は既存の `ariaExpanded` / `ariaControls` と同じ「button モード専用 aria prop」の
  枠に載せ、anchor モードへの混入は既存と同じ DEV 警告 + 型で防ぐ。
- **禁止事項 8 (必須条件未充足を理由に disabled)**: 本設計は disabled を増やさない。
- **`svelte-ignore a11y_media_has_caption` は維持**する (字幕は焼き込み済みという既存の判断を覆さない)。
- 施策 C は `PricingPlanCard` の**既存 3 呼び出し元すべて**を実地確認した結果、
  a11y 欠落は `Onboarding/Checkout.svelte` の 1 箇所だけと確定した
  (`Billing` は「現在のプラン」Badge のテキストで既に伝わっている / `Guest/Pricing` は選択状態ではない)。
  `ariaPressed` 未指定時の Button の出力は現状と完全に同一でなければならない。

## スコープ外

- **bug-hunt の要確認 Q1〜Q4** (プレビューの採用テイク 0 件許容 / オーナー移譲の
  「パスワード再確認」文言 / `config/fortify.php` の doc drift / T042 の検証条件不足)。
  いずれも仕様確定が先で、本設計では触らない。
- **bug-hunt 基盤の不具合 4 件** (teardown の zombie 誤判定 / `bug_hunt_5` ループ /
  `optimize:clear` が dev DB を触る / `setup-worktree` の env コピー欠落)。
  別テーマとして独立に設計する (対象レイヤも検証手段も違うため混ぜない)。
- モバイルの表示モデルそのものの再設計 (タブ/ドロワー化)。施策 A の効果を見てから。
- `/pricing` (`Guest/Pricing.svelte`) の強調枠に ARIA を足すこと。
  そこは「選択状態」ではないので `aria-current` / `aria-pressed` は誤りになる。
- `Billing/_helpers/PlanCard.svelte` への ARIA 追加。「現在のプラン」Badge が既にテキストで
  状態を伝えており、属性を重ねると二重に読み上げられる。
- `PricingPlanCard` (molecule) への意味論 prop の追加 (初版案。施策 C で撤回した)。
- 撮影 PWA の録画中カット切替の可否そのものの見直し (既存仕様を変えない)。

