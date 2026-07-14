# 対応マトリクス: conceptual-review Round 2

Round 2 全体判定: **CHANGES_REQUESTED**（Critical なし / Warning 2）。両方「対応する」。

## [Warning] 観点3: `bind:this` で Button atom を参照しても component インスタンスで .focus() 不可
- 判断: 対応する
- 根拠: 現行 Button.svelte を確認 → 明示 prop のみ受け取り `...rest` 無し。DOM ref も
  aria-expanded/aria-controls も出す口が無い。素の `<button>` 手書きは DESIGN.md 禁止。
- 対応内容: 「確定事項 2」を書き換え、Button atom を最小拡張する独立施策に格上げ:
  `ariaExpanded?: boolean` / `ariaControls?: string` / `element = $bindable<HTMLButtonElement>()`
  を button 分岐に追加。型・実装・DESIGN.md・atom テストを同一 PR 更新。widen しない具体型で保持。

## [Warning] 観点5: outside ハンドラが未定義仕様
- 判断: 対応する（削除）
- 根拠: reviewer 推奨どおり今回の課題に必須でなく、曖昧なまま入れるとリスナー解除漏れ・
  トグル直後再クローズの温床。Escape + パネル内リンク押下 + 広幅復帰で閉じる導線で十分。
- 対応内容: 「確定事項 1・4」から outside 記述を削除。「確定事項 4」に「outside-click は
  今回スコープ外」と明記。

## Suggestion 群
- 判断: 対応不要（方針追認）。DOM 参照追加時に具体型を保つ点は確定事項 2 に反映済み。
