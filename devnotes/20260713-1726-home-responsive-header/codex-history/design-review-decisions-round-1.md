# 対応マトリクス: design-review Round 1

Round 1 全体判定: **CHANGES_REQUESTED**（施策1 APPROVE / 施策2 APPROVE / 施策3 REQUEST_CHANGES）。
Critical 2 / Warning 3 / Suggestion 多数。すべて「対応する」。

## [Critical] 施策2: svelte:window Escape が入力要素でも閉じうる
- 判断: 対応する
- 根拠: 現行 nav は純リンクで実害ゼロだが、将来の snippet 逸脱に対する防御として安価かつ妥当。
- 対応内容: `handleKeydown` に入力要素ガードを追加。
  `target instanceof HTMLElement && target.closest("input, textarea, [contenteditable='true']")`
  なら return。

## [Critical] 施策3: フォーカス復帰の検証が欠落
- 判断: 対応する
- 根拠: `element` bindable 導入価値を回帰で固定すべき。
- 対応内容: Escape ケースに `expect(toggle).toHaveFocus()` を追加。

## [Warning] 施策2: `event.target as HTMLElement` の非 Element 可能性
- 判断: 対応する
- 対応内容: `handlePanelClick` を `target instanceof Element && target.closest("a")` に変更。
  `handleKeydown` も `instanceof HTMLElement` narrowing を採用。

## [Warning] 施策3: nav なしを「構造的保証」で済ませる回帰耐性の弱さ
- 判断: 対応する
- 対応内容: `tests/js/components/templates/GuestLayout.test.ts`（新規）を追加。
  `createRawSnippet` で children を最小生成し、nav 未指定でトグル・パネルが不在であることを固定。
  施策一覧・施策3 変更箇所にも追記。

## [Warning] 施策1: href モードでの disclosure props 誤用（JS 利用時の混入）
- 判断: 対応する
- 根拠: 型で防げるが DEV 警告があると誤用検知が強くなる。既存 iconOnly DEV 警告と同パターン。
- 対応内容: Button.svelte に DEV 専用 `$effect` を追加。
  `href !== undefined && (ariaExpanded !== undefined || ariaControls !== undefined)` で console.warn。
  Button.test に任意で警告テストを追加。

## Suggestion 群
- 判断: 対応不要（設計方針の追認）。within(panel) スコープ・sm:hidden 二重制御・sm 境界などは肯定的評価。
