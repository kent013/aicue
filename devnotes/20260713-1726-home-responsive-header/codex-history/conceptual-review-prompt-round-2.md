# 概念設計レビュー Round 2（Warning 対応の確認）

Round 1 は全体判定 APPROVED でした。ご指摘の Warning 5 件すべてを概念設計に反映しました。
反映内容を確認し、残 Critical/Warning があれば指摘してください。無ければ APPROVED を明記してください。

## 対応サマリ

1. **[W] テスト方針未明示** → 概念設計に「テスト方針（概要）」節を追加。
   - menuOpen=false 時に狭幅パネルを DOM 描画せずナビリンク単一ヒット（既存 Welcome.test.ts 回帰保証）
   - ハンバーガー押下で展開 / `aria-expanded` 切替
   - Escape・パネル内リンク押下で閉じる
   - nav 未指定時はトグルもパネルも出ない
   - `pnpm typecheck/lint/build` + 既存アーキテクチャテスト（atomic-import-graph 等）green

2. **[W] Button atom の属性 forward 前提** → 「実装前の確定事項 2」を追加。
   実装着手時に Button.types.ts / Button.svelte で `onclick`/`aria-expanded`/`aria-controls`/`type="button"`
   の forward を確認し、不足なら Button atom を最小拡張（DESIGN.md の Button 表と型を同一 PR で更新）。
   素の `<button>` 手書きはしない。

3. **[W] Contact 系 (nav 無し) の空トグル防止 / [W] nav?: Snippet 不在分岐の徹底** →
   「実装前の確定事項 1」を追加。トグルボタン・広幅ナビ・狭幅パネルの 3 つすべてを
   `{#if nav}` 配下に置き、`menuOpen` state と Escape/outside ハンドラも nav 有り時のみ有効化。

4. **[W] nav snippet 二重 @render の将来リスク** → 「実装前の確定事項 3」を追加。
   `nav` snippet は「単純なリンク群を想定」する契約をコンポーネント JSDoc に明記。
   対象（Welcome / Pricing の `<a>` 群）が前提を満たすことを確認。

5. **[Suggestion] Escape 後フォーカス復帰** → 「実装前の確定事項 4」を追加。
   `bind:this` でトグルボタン参照を保持し、Escape close 後にフォーカスを戻す。

## 反映後の該当セクション全文

（以下、conceptual-design.md の更新差分を含む主要セクション）

### 実装方針 抜粋
### 実装前の確定事項（Codex 概念レビュー round-1 で明文化）

1. **`nav` 不在ページの扱い (Contact/*)**: ハンバーガーボタン・広幅ナビ・狭幅パネルの
   **3 つすべてを `{#if nav}` 配下**に置く。`nav` が渡されないページ (Contact/Index /
   Contact/Thanks) ではヘッダー右側は現状どおり何も出さない (ボタンだけ出る誤実装を防ぐ)。
   `menuOpen` state と `Escape`/outside ハンドラも `nav` 有りのときだけ有効化する。
2. **Button atom の属性 forward 前提**: トグルは Button atom (`iconOnly` + `ghost`) を使う。
   実装着手時に Button atom が `onclick` / `aria-expanded` / `aria-controls` / `type="button"`
   を素通し (forward) できることを `Button.types.ts` / `Button.svelte` で確認する。
   forward できない属性があれば **Button atom を最小拡張**する (DESIGN.md の Button 表と
   `Button.types.ts` を同一 PR で更新)。素の `<button>` 手書きはしない (§Do's and Don'ts)。
3. **二重 `@render` の契約**: `GuestLayout` の `nav` snippet は「**単純なリンク群**を想定する」
   契約をコンポーネント JSDoc に明記する。状態 full 要素・複雑構造を入れない前提を残し、
   今回の対象 (Welcome / Pricing の `<a>` 群) がこれを満たすことを確認する。
4. **キーボード UX**: `Escape` で閉じた後、フォーカスをトグルボタンへ戻す
   (`bind:this` でボタン要素を保持)。パネル内リンク押下でも `close()`。

## 制約・前提

### テスト方針 抜粋
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
