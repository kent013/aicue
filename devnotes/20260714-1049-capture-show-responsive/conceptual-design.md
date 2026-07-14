# 概念設計: capture-show-responsive

## 背景・課題

bug-hunt (real-llm run 20260714-093524) F-1-3 (High, H11+H13)。

撮影画面 `capture.manuals.show`(ルート `app/projects/{project}/manuals/{manual}`、GET × Inertia、
ページ `resources/js/pages/Capture/Show.svelte`)が **mobile 375px / tablet 768px** で横 overflow し、
シナリオリスト(`CutNavigator`)のカットの **シーン説明 (`scene`) / 撮影ポイント説明 (`shooting_point`)**
が画面外に切れ、ページ全体に横スクロールが発生する。

### 証跡

- `devnotes/20260714-093524-bug-hunt/shard-1/screenshots/H13-mobile-capture-show.png`
  — scene テキスト「コーヒーメーカー全体を映し、作業者が電源ボ…」が右端で切れ、ellipsis なしで欠落。
- `.../H13-mobile-capture-hscroll.png` — 横スクロールすると隠れていた続き
  「ンに手を伸ばして押し、ランプが消灯するまでの一連」が現れる = truncate が全く効いていない。

### 根本原因(コード精査で brief の仮説を更新)

brief の当初仮説は「該当 flex 親コンテナに `min-w-0` が無い」だったが、コードを精査した結果、
`CutNavigator.svelte` の該当 flex 親(`<div class="min-w-0 flex-1">`, L49)には **既に `min-w-0` があり**、
scene 行(L54)も `truncate` を持っている。それでも truncate が効かない真因は **1 階層上のグリッド**にある:

1. **主因(ページ全体の横スクロール)**: `Show.svelte` L153 のレイアウトが
   `<div class="mt-4 grid gap-4 lg:grid-cols-2">` となっており、mobile/tablet(< `lg`)では
   **明示的な `grid-cols-1` が無い**。列テンプレート未指定の CSS Grid は暗黙の `auto` 列を作り、
   `auto` 列は **max-content**(= 折り返さない最長テキスト幅)までトラックが伸びる。
   結果、グリッドトラックが viewport 幅を超え、子の `min-w-0`/`truncate` は
   「トラックが広い」ため発火せず、ページに横スクロールが出る。
   Tailwind の `grid-cols-1` は `grid-template-columns: repeat(1, minmax(0,1fr))` を出力し、
   **列の最小幅を 0 にクランプ**して 1fr で viewport 内に収める。これが正しい封じ手。

2. **副因(撮影ポイント行の ellipsis 欠落)**: `CutNavigator.svelte` L56 の
   `<p class="flex items-center gap-1 truncate …">` は **flex コンテナ自身に `truncate` を付与**しており、
   直下の匿名テキストノード(flex アイテム、`min-width:auto`)が縮まず、ellipsis が正しく描画されない。
   アイコン(`MapPin`)とテキストを分離し、**テキストを `<span class="min-w-0 truncate">` で包む**のが定石。

## 改善アイデア

撮影画面のレイアウト境界を「狭幅でトラック/フレックスアイテムが max-content に膨らまない」ように是正する。
値のチューニングではなく、**overflow を封じる構造(min-width クランプ)を正す**。

1. `Show.svelte` の 2 カラムグリッドに **mobile 既定 `grid-cols-1`** を明示し、暗黙 `auto` 列を廃する。
2. 保険として 2 つの `<section>`(グリッドアイテム)に **`min-w-0`** を付与し、
   `lg:grid-cols-2` 時も列が子の max-content で膨らまないようにする。
3. `CutNavigator.svelte` の `shooting_point` 行を **アイコン + `<span class="min-w-0 truncate">` テキスト**に
   組み替え、ellipsis を機能させる(scene 行 L54 は grid 是正で truncate が復活するため構造変更不要)。

いずれも Tailwind のレイアウトユーティリティ(`grid-cols-1` / `min-w-0` / `truncate`)のみで、
hex 直書き・新規 design token・新規 SVG は増やさない(DESIGN.md / Atomic Design 準拠)。

## 期待効果

- **使命への貢献**: 撮影 PWA(スマホでのナビ撮影)は使命の中核。狭幅端末で手順テキストが
  読めない/横スクロールで詰まる状態を解消し、「思考ゼロ」で撮るべきカットを把握できる体験を守る。
- mobile 375px / tablet 768px でページ横スクロールが解消(overflow なし)。
- scene / shooting_point が枠内で truncate/ellipsis 表示され、行タップで詳細(narration 全文)を確認できる
  既存フローが機能する。
- 回帰防止: コンポーネントテスト(vitest)で該当要素が `min-w-0`/`truncate`/`grid-cols-1` を持つことを固定。

## 実装方針(概要)

| 対象 | 変更概要 |
|------|---------|
| `resources/js/pages/Capture/Show.svelte` | グリッド `div` に `grid-cols-1` 明示、2 つの `section` に `min-w-0` 付与 |
| `resources/js/components/features/capture/CutNavigator.svelte` | `shooting_point` 行をアイコン+`<span class="min-w-0 truncate">` へ組み替え |
| `tests/js/components/features/capture/CutNavigator.test.ts`(新規) | scene が truncate、shooting_point が min-w-0/truncate span を持つことを検証 |
| `tests/js/pages/CaptureShow.test.ts`(既存) | グリッドが `grid-cols-1` を持つ(mobile 単一列)ことのアサートを追加 |

## 制約・前提

- Svelte 5 runes + DS token/ramp のみ(DESIGN.md canonical、ds-purity テスト)。今回は色/タイポの変更なし。
- component 階層(atoms→…→features→templates→pages)の単方向 import を維持。層構成は変えない。
- アイコンは `@lucide/svelte`(`MapPin` 既存利用)。SVG 直書きの新設なし。
- jsdom(vitest)は実レイアウト計算をしないため、テストは **クラス付与の構造アサート**で回帰を固定する
  (「実際に overflow しないこと」のピクセル検証は bug-hunt の Playwright 実走に委ねる)。
- バックエンド(Controller / DTO / ルート)変更なし。TypeScript 型・Inertia Props も変更なし。

## スコープ外

- `Manuals/`(管理側)`projects.manuals.show` 等、撮影画面以外の画面の overflow。
- narration 詳細パネル(`Show.svelte` L171-179)の折り返し挙動(block 折返しで overflow なし、変更不要)。
- カメラ録画/アップロードキュー等の機能ロジック。
- design token / 配色 / タイポグラフィの変更。
