# 概念設計: home-responsive-header

## 背景・課題

bug-hunt finding **F-M2 (Medium, H13)**。

- home (Welcome / LP) のヘッダーナビが mobile 375px 幅でハンバーガーメニュー化されず、
  ロゴ + 横並びナビ (`料金プラン` / `ログイン` / `無料で始める`) が水平方向に収まりきらず
  **単語途中で折返す**。LP は第一印象を決める面であり、可読性の低下は North Star
  (現場作業者が迷わず使える) の入口体験を損なう。
- 現行のヘッダーは共通レイアウト `resources/js/components/templates/GuestLayout.svelte`
  にあり、ナビ内容はページ側 (`Welcome.svelte` / `Pricing.svelte`) から `nav` snippet で
  差し込まれる。`GuestLayout` のヘッダーは `flex items-center justify-between` +
  `nav class="flex items-center gap-4"` の**単一の横並び**で、狭幅の折返し対策が無い。
- 同じ症状は `GuestLayout` を使う **Pricing.svelte** でも起きる (共通の根)。
  認証済み画面の `AppLayout.svelte` は `flex-wrap` で 2 段化する別方針を既に持つが、
  ゲスト面には未適用。

## 改善アイデア

**根 (`GuestLayout.svelte`) を 1 箇所直す**ことで、`GuestLayout` を使う全ゲストページ
(Welcome / Pricing / Contact) のヘッダーを狭幅対応にする。ページ側 (`Welcome.svelte`) は
既存の `nav` snippet をそのまま渡すだけで変更不要 (snippet の中身は据え置き)。

- **広幅 (`sm` = 640px 以上)**: 現行どおりロゴ右に横並びナビを表示。
- **狭幅 (`sm` 未満, 375px を含む)**: 横並びナビを隠し、**ハンバーガーのトグルボタン**
  (Lucide `Menu` アイコン、開いている間は `X`) を表示。押下でヘッダー直下に
  ナビ項目を**縦積みのパネル**として展開する。
- ナビ項目そのもの (`nav` snippet が描く `<a>` 群) は不変。展開方向 (横 flex / 縦 flex-col)
  は `GuestLayout` 側のコンテナが制御するため、**同一 snippet を 2 箇所で `@render`** して
  広幅=横・狭幅パネル=縦を出し分ける。

### なぜ `sm` (640px) を境界にするか
- ゲストナビは最大 3 項目の短いリンクで、640〜768px では横並びで問題なく収まる
  (`768px で崩れない` を満たす)。375px では収まらないためハンバーガー化する
  (`375px で崩れない` を満たす)。
- アプリ既存のレスポンシブは `sm:` / `lg:` の Tailwind ブレークポイントを多用しており
  (Welcome の grid: `sm:grid-cols-3` / `lg:grid-cols-2` 等)、`sm` 境界は既存方針に整合。

## 期待効果

- **使命への貢献**: LP/料金の第一印象で文字折返しが消え、初見の現場作業者・導入担当が
  迷わず CTA (`無料で始める` / `ログイン`) に到達できる。入口体験の破綻を除去する。
- **具体的な改善見込み**:
  - 375px でヘッダーが 1 行に収まり、ナビはハンバーガー内に整列 (単語途中折返しの解消)。
  - `GuestLayout` 単一修正で Welcome / Pricing / Contact のヘッダーが同時に狭幅対応。
  - 768px 以上は現行の見た目を維持 (回帰なし)。

## 実装方針（概要）

`resources/js/components/templates/GuestLayout.svelte` のヘッダーのみを変更する。

1. `GuestLayout` にローカル `$state` (`menuOpen`) と `toggle()` / `close()` を追加。
2. ヘッダー右側を 2 系統に分割:
   - **広幅ナビ**: 現行の `<nav>` に `hidden sm:flex` を付与 (`sm` 未満で非表示)。
   - **ハンバーガーボタン**: `sm:hidden` を付与し `sm` 以上で非表示。
     既存の **Button atom (`iconOnly`)** を再利用 (塗りつぶしでない `ghost` variant、
     型で必須の `ariaLabel` を付与)。アイコンは `@lucide/svelte` の `Menu` / `X` を状態で切替。
     `aria-expanded` / `aria-controls` を配線。
3. **展開パネル**: `{#if menuOpen}` のときだけヘッダー直下に縦積みの `<nav>` を描画
   (`id` をボタンの `aria-controls` に対応)。中身は同じ `{@render nav()}`。
   閉時は DOM に出さない (テストの重複要素回避・SSR/初期描画の軽量化)。
4. a11y/UX: パネル内リンク押下で `close()`、`Escape` で `close()`。DESIGN.md 準拠で
   影・グラデーション不使用、`border`/`bg-surface` と token で階層表現。

**新規 component は作らない** (レイアウト構造の関心は template 層が担う。トグルは
Button atom の再利用で足りる)。Atomic Design の階層 (atoms→...→templates→pages) と
単方向 import を逸脱しない。

### 実装前の確定事項（Codex 概念レビュー round-1 で明文化）

1. **`nav` 不在ページの扱い (Contact/*)**: ハンバーガーボタン・広幅ナビ・狭幅パネルの
   **3 つすべてを `{#if nav}` 配下**に置く。`nav` が渡されないページ (Contact/Index /
   Contact/Thanks) ではヘッダー右側は現状どおり何も出さない (ボタンだけ出る誤実装を防ぐ)。
   `menuOpen` state と `Escape` ハンドラも `nav` 有りのときだけ有効化する。
2. **Button atom の最小拡張（現状 API 確認済み）**: トグルは Button atom (`iconOnly` +
   `ghost`) を使う。**現行 Button atom は明示 prop のみ受け取り任意属性を forward しない**
   (`Button.svelte` は `...rest` を持たず、`aria-expanded` / `aria-controls` / DOM ref を
   出す口が無い)。素の `<button>` 手書きは DESIGN.md §Do's and Don'ts で禁止のため、
   Button atom に以下を**最小拡張**する (詳細設計の独立施策):
   - `ariaExpanded?: boolean` → `<button>` 分岐で `aria-expanded` を描画
   - `ariaControls?: string` → `<button>` 分岐で `aria-controls` を描画
   - `element = $bindable<HTMLButtonElement>()` → `<button>` に `bind:this`。
     フォーカス復帰用に**具体型 `HTMLButtonElement`** を保持し widen しない。
   `Button.types.ts` (BaseProps) / `Button.svelte` (button 分岐) / DESIGN.md の Button 節 /
   Button の atom テストを**同一 PR で更新**する。anchor/inertia 分岐には出さない
   (disclosure は `<button>` 用途に限定)。
3. **二重 `@render` の契約**: `GuestLayout` の `nav` snippet は「**単純なリンク群**を想定する」
   契約をコンポーネント JSDoc に明記する。状態 full 要素・複雑構造を入れない前提を残し、
   今回の対象 (Welcome / Pricing の `<a>` 群) がこれを満たすことを確認する。
4. **キーボード UX / フォーカス復帰**: `Escape` で閉じた後、`element` bindable で保持した
   トグルボタン (`HTMLButtonElement`) に `.focus()` でフォーカスを戻す。パネル内リンク押下でも
   `close()`。**outside-click (外側クリックで閉じる) は今回スコープ外**とし実装しない
   (仕様が曖昧でリスナー解除漏れ・トグル直後再クローズの温床になるため。Escape + リンク押下で
   閉じる導線で十分)。
5. **広幅での表示保証 / リサイズ時の state**: 展開パネルには `{#if menuOpen}` に加えて
   **`sm:hidden`** を付け、広幅 (`sm` 以上) では `menuOpen` の値にかかわらず**必ず非表示**にする
   (CSS ブレークポイント切替だけでは `menuOpen` は false にならないため、状態でなく表示で保証)。
   **リサイズ監視 (resize listener) は追加しない**。「狭幅で開く → 広幅へ → 再び狭幅」で
   `menuOpen=true` が残り開いた状態が復元される挙動は**許容する** (実害が小さく、監視追加は
   オーバーエンジニアリング)。ハンバーガーボタンは `sm:hidden`、広幅ナビは `hidden sm:flex`。

## 制約・前提

- **純フロントエンド変更**。バックエンド (Controller / Service / DTO / JsonResource /
  Inertia Props / marketing 型) に一切変更なし。`response()->json` 直書き等の禁止事項に無関係。
  PHPStan (PHP 側) には影響しない。
- DESIGN.md が canonical。色は既存 token (`text-text-secondary` / `text-primary` /
  `bg-surface` / `border-border` 等) のみ使用し、hex 直書き・新規 token を増やさない。
  角丸は `sm/md/lg` ramp のみ。アイコンは Lucide のみ (`Menu` / `X`)。
- component 階層 (`atomic-import-graph.test.ts`)・Lucide scoped import
  (`lucide-scoped-import.test.ts`)・SVG 直書き禁止 (`svg-inline-allowlist.test.ts`)・
  ds-purity / shape-ramp-purity / typography-invariant の各アーキテクチャテストに適合させる。
- `nav` snippet を 2 箇所で `@render` する。閉時にパネルを描画しないことで、既存
  `tests/js/pages/Welcome.test.ts` の `getByRole("link", ...)` が**重複ヒットしない**
  ことを保証する (jsdom は `hidden sm:*` を実際には隠さないため、閉時レンダリング抑止が要)。
- ブレークポイント境界は `sm` (640px)。375px=ハンバーガー / 768px=横並び。

## テスト方針（概要）

「テストなしの実装完了」は禁止事項。純フロント変更のため **vitest + @testing-library/svelte**
のコンポーネントテスト (`pnpm test`) を追加する。詳細設計でケースを確定するが、少なくとも:

- 既定 (`menuOpen=false`) では狭幅パネルを DOM に描画せず、ナビリンクが**単一ヒット**する
  (既存 `Welcome.test.ts` の `getByRole("link", ...)` が壊れない回帰保証)。
- ハンバーガー押下でパネルが展開しナビ項目が現れる / `aria-expanded` が切り替わる。
- `Escape` 押下・パネル内リンク押下で閉じる。
- `nav` を渡さないと (Contact 相当) トグルボタンもパネルも出ない。

加えて **`pnpm typecheck` / `pnpm lint` / `pnpm build`** と、既存アーキテクチャテスト
(atomic-import-graph / lucide-scoped-import / svg-inline-allowlist / ds-purity /
shape-ramp-purity / typography-invariant) を green に保つ。

## スコープ外

- 認証済み画面 `AppLayout.svelte` のヘッダー方針変更 (別 finding。今回は触らない)。
- ナビ項目そのものの追加・文言変更・IA 変更。
- フッターリンク (`footerLinks` snippet) の狭幅対応 (現状 `料金プラン` 等の短リンクで
  折返し報告なし。今回の finding 対象外)。
- ダークモード/テーマ切替の新設、アニメーション演出の作り込み。
