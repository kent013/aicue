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
- **`captureActive === true` の間は自動スクロール・フォーカス移動を行わない**。
  録画中のカット切替は既存仕様のまま変えない (本設計で新たに禁止も許可もしない) が、
  録画中に視点とフォーカスを奪う挙動は加えない。
  **`captureActive` は「録画中」より広い**: `CameraRecorder.svelte` L39-43 の定義は
  `starting || resuming || phase !== "idle"` で、**getUserMedia の grant 待ち 2 窓
  (録画開始 = starting / preview 復帰 = resuming) を意図的に含む**。
  つまり権限ダイアログ表示中・カメラ初期化中も `captureActive === true` であり、
  新たな state を足さずに抑止条件を満たせる (この既存事実を前提として明記する)。
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

### 施策 C: プラン事前選択を、視覚と同等のテキストで伝える (F-2-01)

初版では `PricingPlanCard` (molecule) に状態 prop を足して `aria-current` を出す案だったが、
呼び出し元 3 箇所を実地で確認して**方針を変えた**。

| 呼び出し元 | `isHighlighted` の意味 | 状態は今どう伝わっているか |
|---|---|---|
| `Onboarding/Checkout.svelte` | **選択肢のうち今選ばれているもの** (`selectedPlanCode = chosenPlanCode ?? ?plan= 由来の初期値`) | **伝わっていない = F-2-01**。カード内の「選択」Button のラベルは `chosenPlanCode` でしか「選択中」に変わらず、`?plan=` の事前選択では「選択」のまま |
| `Billing/_helpers/PlanCard.svelte` | **現在契約中**のプラン | `headerBadges` に `Badge`「現在のプラン」があり、**テキストとして既に a11y ツリーに出ている**。欠落なし |
| `Guest/Pricing.svelte` | 視覚的な強調枠 | 選択状態ではないので ARIA を出すべきでない |

つまり **欠落しているのは Checkout の 1 箇所だけ**である。ではその 1 箇所に何を出すか。

**採らない案 1: `aria-pressed` (トグルボタン)。**
`choosePlan()` は `chosenPlanCode = plan.code` を代入するだけで**解除しない**。
常にいずれか 1 プランが選ばれる排他選択であり、「押すたびに ON/OFF する」トグルではない。
`aria-pressed` はトグルと解釈されるため意味論が合わない。
(このリポジトリの `aria-pressed` 先例 —— `CameraRecorder` のグリッド/字幕トグル、
`PasswordInput` の表示切替 —— はいずれも**再押下で解除できる二値**であり、こことは性質が違う。)

**採らない案 2: `role="radiogroup"` + `role="radio"` / `aria-checked`。**
意味論としては最も正確だが、radiogroup は**矢印キーによる移動と roving tabindex** を
期待される。契約画面のキーボード操作モデルを作り替えることになり、
a11y の欠落 1 件を埋めるために新しい退行リスクを買うことになる (「今必要なものだけ作る」を超える)。

**採る案: 視覚が伝えている情報を、テキストで同じだけ伝える。**
青枠が伝えているのは「このプランが選択されている」という一事である。
それを `sr-only` テキストとしてカード内に出す。

- `Onboarding/Checkout.svelte` で、`selectedPlanCode === plan.code` のカードにだけ
  **`headerBadges` snippet で `<span class="sr-only">{plan.name} プランが選択されています</span>`** を渡す。
  **文言にプラン名を含める**のが要点である。「このプランが選択されています」だけだと、
  どのプランを指すかがカードの DOM 構造・読み上げ順に依存してしまう
  (`PricingPlanCard` は semantic group ではない)。プラン名を文言に埋め込めば、
  支援技術利用者がテキスト単位で移動しても対象が自明になる。
- `PricingPlanCard` の `headerBadges` は**既存の optional snippet** なので、
  **molecule 側の変更は 0 行**。`Button` atom も変更しない。
- **見た目は一切変わらない** (`sr-only` は視覚上不可視)。「選択」ボタンのラベルも
  `chosenPlanCode` 基準のまま動かさない (押していないものを「選択中」と表示すると別の誤認を作る)。
- これは `Billing/_helpers/PlanCard.svelte` が「現在のプラン」Badge = **テキスト**で
  同じ種類の状態を伝えているのと**同じ手口**であり、画面をまたいで手口が揃う。
- **`PricingPlanCard` / `Button` / `Billing` / `Guest/Pricing` はいずれも変更しない。**
  変更ファイルは `Onboarding/Checkout.svelte` の 1 枚だけになる。

これにより role を偽らずに状態を伝えられ、molecule にも atom にも意味論を持ち込まずに済む。

## 期待効果

- **使命への貢献 (施策 A)**: 「思考ゼロ・編集ゼロ」で現場作業者が撮影できる、という North Star の
  中核導線から、カットごとに発生していた手動スクロールを取り除く。撮影カット数に比例して効く。
- **a11y (施策 B/C)**: 支援技術利用者が「動画ができた」「このプランが選ばれている」を
  認識できるようになる。契約という不可逆操作の前段で状態が伝わらない F-2-01 は特に重要。
- **回帰の固定**: 3 件とも**同一条件をテストで固定する** (「再燃しない」とまでは言わない —
  固定できるのは下の受入条件そのものであって、周辺の類似欠落まで守るわけではない)。

## 受入条件 (固定するレーン付き)

| # | finding | 受入条件 | 固定レーン |
|---|---|---|---|
| 1 | F-1-03 | 1 カラム幅で任意のカットを選ぶと、**撮影パネル先頭の見出しが矩形全体として** viewport 内に入る (1px 交差では不可。CameraRecorder 全体の可視は要求しない —— 小さい viewport では成立しないため) | Browser (Chromium + WebKit) |
| 2 | F-1-03 | 同じ操作でフォーカスが撮影パネル先頭の見出し (選択中カット名を含む) へ移る | Browser |
| 3 | F-1-03 | 2 カラム幅 (lg 以上) では、カット選択でスクロール位置もフォーカスも動かない | Browser |
| 4 | F-1-03 | `captureActive === true` (録画中 / getUserMedia grant 待ちを含む) のカット選択では自動スクロール・フォーカス移動が起きない | Browser |
| 5 | F-1-03 | `prefers-reduced-motion: reduce` のとき smooth scroll を使わない (`behavior` が `auto`) | Browser |
| 6 | F-1-03 | 撮影パネルの「カット一覧へ戻る」で一覧が viewport に入り、**かつフォーカスが一覧側 (見出しまたは選択中カット行) へ戻る** | Browser |
| 7 | F-1-02 | `preview-video` のアクセシブルネームが「完成動画 / プレビュー」であることが分かる語を含む (非空チェックでは不足) | Browser |
| 8 | F-1-02 | `take-preview-video` のアクセシブルネームが**選択中カットの手順名**を含む | Browser |
| 9 | F-2-01 | `?plan=starter` で開いたとき、starter のカードだけが**「Starter」というプラン名を含む**選択状態テキストを a11y ツリーに持ち、他プランのカードは持たない | Browser |
| 10 | F-2-01 | 別プランを選び直すと、旧カードから選択状態テキストが消え、**新プラン名を含む**テキストが新カードに現れる (初期状態だけでなく遷移も固定) | Browser |
| 11 | F-2-01 | **Checkout に限定**して: (a) 選択カードと未選択カードで見出し・価格・選択ボタンの位置とサイズが変わらない、(b) `headerBadges` ラッパが可視領域を持たない (幅・高さ 0)、(c) sr-only 文言がスクリーンショットに描画されない | Browser |

受入条件 7/8/9/10 は「非空」ではなく**意味内容**を検査する。ただし文言の完全一致では i18n 変更に脆いため、
**必要語の包含** (例: 「完成動画」/ 当該カットの手順名 / 当該プラン名) で固定する。

**`Billing` / `Guest/Pricing` には新規 Browser 回帰テストを課さない。** 施策 C はこの 2 画面の
コードを 1 行も変更しないため、回帰テストを足すのは分母の水増しになる (受入条件 11 を
Checkout 限定にしたのはこの理由)。

**Browser レーンは Chromium + WebKit の 2 レーン契約** (`docs/testing-browser.md`) に従う。
施策 A は viewport 依存かつ実スクロール/フォーカス挙動の検証なので Browser レーンが必須。
施策 B/C の a11y 検査も、対象画面が Inertia 描画のため Browser レーンで一緒に取る
(施策 C は atom/molecule を変更しないため、component 単体の vitest は不要になった)。

## 実装方針（概要）

| # | 施策 | 変更コンポーネント |
|---|---|---|
| A | カット選択時の撮影パネルへのスクロール + 戻る導線 | `resources/js/pages/Capture/Show.svelte` |
| B | video のアクセシブルネーム | `resources/js/components/features/manual/RenderPanel.svelte`, `resources/js/components/features/capture/TakePreviewDialog.svelte` |
| C | プラン事前選択の状態を sr-only テキストで伝える | `resources/js/pages/Onboarding/Checkout.svelte` のみ |

すべて **frontend (Svelte 5 runes + TypeScript) のみ**。PHP 側 (Controller / DTO / route /
migration) の変更は無く、Inertia Props の形も変えない。

**固定レーンは上の受入条件表で確定済み**である。詳細設計で決めるのは
テストファイルの配置と fixture 構成 (どの manual / cut / plan を用意するか) だけで、
レーンの選択そのものは詳細設計で蒸し返さない。

## 制約・前提

- **DESIGN.md 準拠**: 新しい色・角丸・タイポグラフィは足さない。施策 A の「戻る」は既存の
  `TextLink` / `Button` atom を使う。hex 直書きを増やさない。
- **Atomic Design の単方向 import**: 施策 C で触るのは pages 1 枚だけ。
  atom / molecule には一切手を入れないため、階層の逆流も新規 component も発生しない。
- **`sr-only` は既存パターン**を踏襲する (`atoms/Spinner.svelte` L43 /
  `CameraRecorder.svelte` L521 / `AppLayout.svelte` L231 / `Contact/Index.svelte` L168)。
  新しいユーティリティクラスも新しい CSS も足さない。
- **禁止事項 8 (必須条件未充足を理由に disabled)**: 本設計は disabled を増やさない。
- **`svelte-ignore a11y_media_has_caption` は維持**する (字幕は焼き込み済みという既存の判断を覆さない)。
- 施策 C は `PricingPlanCard` の**既存 3 呼び出し元すべて**を実地確認した結果、
  a11y 欠落は `Onboarding/Checkout.svelte` の 1 箇所だけと確定した
  (`Billing` は「現在のプラン」Badge のテキストで既に伝わっている / `Guest/Pricing` は選択状態ではない)。
  施策 C が視覚に影響しうるのは **Checkout だけ** (`Billing` / `Guest/Pricing` はコード無変更)。
  `headerBadges` を渡すことで現れる `ml-auto` ラッパが Checkout のカード見出し行の
  レイアウトを動かさないことを、詳細設計と受入条件 11 で確認する。

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
- `Button` atom への `ariaPressed` 追加 (Round 2 案。施策 C を sr-only テキストに変えたため不要になった)。
- プラン選択の `radiogroup` 化 (矢印キー + roving tabindex)。意味論としては最も正確だが、
  契約画面のキーボード操作モデルを作り替える規模になるため今回は採らない。
- 撮影 PWA の録画中カット切替の可否そのものの見直し (既存仕様を変えない)。
