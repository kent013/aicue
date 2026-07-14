# 概念設計レビュー Round 3（残 Warning 2 件の対応確認）

Round 2 の Warning 2 件に対応しました。確認のうえ、残指摘が無ければ APPROVED を明記してください。

## 対応サマリ

1. **[W] `bind:this` が Button の component インスタンスを返し .focus() 不可**
   現行 `Button.svelte` を確認したところ、明示 prop のみ受け取り `...rest` を持たず、
   `aria-expanded` / `aria-controls` / DOM ref を出す口がありません。素の `<button>` 手書きは
   DESIGN.md §Do's and Don'ts で禁止です。よって Button atom を**最小拡張**する独立施策に格上げ:
   - `ariaExpanded?: boolean` → `<button>` 分岐で `aria-expanded` を描画
   - `ariaControls?: string` → `<button>` 分岐で `aria-controls` を描画
   - `element = $bindable<HTMLButtonElement>()` → `<button>` に `bind:this`（具体型のまま widen しない）
   `Button.types.ts` / `Button.svelte` / DESIGN.md Button 節 / Button の atom テストを同一 PR で更新。
   anchor / inertia 分岐には出さず disclosure は `<button>` 用途に限定。

2. **[W] outside-click ハンドラが未定義仕様**
   ご提案どおり**今回スコープ外として削除**しました。閉じる導線は Escape + パネル内リンク押下 +
   広幅への復帰で十分とし、`menuOpen` state と Escape ハンドラは `nav` 有り時のみ有効化します。
   フォーカス復帰は `element` bindable で保持した `HTMLButtonElement` に `.focus()`。

## 反映後「実装前の確定事項」全文

（下に添付）

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
   (仕様が曖昧でリスナー解除漏れ・トグル直後再クローズの温床になるため。Escape + リンク押下 +
   広幅への復帰で閉じる導線は十分)。

## 制約・前提
