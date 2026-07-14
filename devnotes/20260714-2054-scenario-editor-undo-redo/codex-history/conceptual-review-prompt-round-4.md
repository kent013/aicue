# 概念設計レビュー Round 4 — 最終対応

Round 3 の唯一の残 Warning(flushPendingEdit の IME 状態保証)に対応しました。

## [Warning 観点3] コミット誘発の全経路を IME gate 化
`flushPendingEdit()` 自体・構造操作・undo/redo を全て IME-aware にしました:

- `oncompositionstart`: composing=true
- `flushPendingEdit()`: composing なら commit せず `flushDeferred=true` で return。
  そうでなければ editBaseline を(変化があれば)push し editBaseline=null。
- `onfocusout`: flushPendingEdit() を呼ぶ(composing なら遅延される)
- 構造操作 / undo / redo は `runSettled(action)` を通す。composing なら `pendingAction=action` に
  退避、そうでなければ即実行。
- `oncompositionend`: composing=false → flushDeferred が立っていれば flushPendingEdit()
  (テキスト編集 1 エントリ確定)→ pendingAction があれば取り出して実行(構造操作等を続行)。
- keydown は event.isComposing で無視。pendingAction は 1 スロット(操作は逐次)。

テストで `compositionstart → focusout → 構造 click → compositionend` を固定:
「テキスト編集 1 + 構造操作 1」の 2 エントリ、中間文字列を積まない。

## [Suggestion 観点5] MAX_HISTORY_CHARS はソフト上限である旨を明記
単一エントリは残す(length>1 保持)ため、1 エントリが上限超でも保持するソフト上限であることを設計に追記しました。

以上で残 Warning は解消と考えます。APPROVED 可否をお願いします。
