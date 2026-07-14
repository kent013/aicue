# 対応マトリクス: conceptual-review Round 3

## [Warning 観点3] flushPendingEdit() 直接呼び出し全経路の IME 状態保証
- 判断: 対応する
- 根拠: 的確。`focusout → 構造 click → compositionend` の順序では、click handler 内の
  flushPendingEdit() が変換途中を commit しうる。flushPendingEdit 自体を IME-aware に必要。
- 対応内容: コミットを誘発する全経路を IME gate 化。
  - `flushPendingEdit()` を IME-aware に(composing 中は commit せず flushDeferred=true で return)。
  - 構造操作 / undo / redo は `runSettled(action)` を通し、composing なら `pendingAction` に
    退避、`compositionend` で flush→pendingAction を順に実行。
  - `pendingAction` は 1 スロット(ユーザ操作は逐次)。
  - テスト: `compositionstart → focusout → 構造 click → compositionend` で
    「テキスト編集 1 + 構造操作 1」の 2 エントリ、中間文字列を積まない。

## [Suggestion 観点5] MAX_HISTORY_CHARS はソフト上限
- 判断: 対応する(明記)
- 対応内容: 「単一エントリは残す」ためのソフト上限である旨を設計に明記。

## その他 Suggestion(観点1,2,4,6,7)
- いずれも「問題なし/解消済み」判定。追加対応なし。
