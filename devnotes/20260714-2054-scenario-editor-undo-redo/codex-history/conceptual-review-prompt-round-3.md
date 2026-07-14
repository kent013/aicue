# 概念設計レビュー Round 3 — 対応報告

Round 2 の残 3 Warning + Suggestion に対応しました。承認可否を判定してください。

## [Warning 観点2] undoStack 空 + pending 編集中に Undo ボタンが disabled
→ `canUndo = undoStack.length > 0 || (editBaseline !== null && editBaseline !== serializeSteps(steps))`
を `$derived` で定義。pending 編集がある間 Undo を活性化。クリック→blur(focusout)→flush→undo が
成立する。`canRedo = redoStack.length > 0`。テスト「初回セル編集の focus 中に Undo ボタンで戻せる」追加。

## [Warning 観点3] focusout が必ず IME 確定後とは限らない
→ `compositionstart`/`compositionend` で `composing` 状態を管理:
- `oncompositionstart`: composing=true
- `onfocusout`: composing なら即 commit せず `flushDeferred=true`、そうでなければ flushPendingEdit()
- `oncompositionend`: composing=false; flushDeferred が立っていれば flushPendingEdit() して false に戻す
- keydown は `event.isComposing` で無視
テスト: `compositionstart → focusout(composing) → compositionend` の順序差で 1 エントリに確定。

## [Warning 観点5] redoStack の容量管理未定義
→ 純関数 `boundHistory(stack, maxEntries, maxChars)` に切り出し、**両スタック**の push 後に適用
(各スタック個別上限)。先頭から件数 or 総文字数が上限内になるまで捨てるが、単一巨大エントリでは
空にしない(length>1 保持)。`resetHistory()` は undoStack/redoStack を空・editBaseline=null・
flushDeferred=false。純関数として単体テスト。util は
`resources/js/lib/manual/scenario-history.ts` に配置(brief「関連 util(履歴スタック)」)。

## [Suggestion 観点6] スコープ外の記述矛盾
→ 「編集フィールド内は native undo に委ね、document undo との厳密な履歴統合は行わない」に修正。

## [Suggestion 観点7] validator の網羅
→ `parseHistory` の型ガードは「配列」「各 step/point が rowOf の 8 フィールド + id: number|null」
「points が配列」まで検証。素の型アサーションを内部に残さない。

以上で残課題は解消したと考えます。APPROVED 可否をお願いします。
