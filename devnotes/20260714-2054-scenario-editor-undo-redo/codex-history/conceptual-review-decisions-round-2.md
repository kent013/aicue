# 対応マトリクス: conceptual-review Round 2

## [Warning 観点2] undoStack 空 + pending 編集中に Undo ボタンが disabled で押せない
- 判断: 対応する
- 根拠: 的確。初回セル編集中(editBaseline あり・undoStack 空)は Undo が disabled になり、
  「押下で blur→flush→undo」が成立しない(disabled はクリック/フォーカスを受けない)。
- 対応内容: `canUndo = undoStack.length > 0 ||
  (editBaseline !== null && editBaseline !== serializeSteps(steps))` を `$derived` で定義。
  pending 編集がある間 Undo を活性化。テスト「初回セル編集の focus 中に Undo ボタンで戻せる」を追加。

## [Warning 観点3] focusout が必ず IME 確定後とは限らない
- 判断: 対応する
- 根拠: compositionend と focusout の順序はブラウザ/IME 依存。keydown.isComposing だけでは
  focusout commit を防げない。
- 対応内容: `compositionstart`/`compositionend` で `composing` 状態を管理。onfocusout 時に
  composing なら即 commit せず `flushDeferred=true` にし、compositionend で確定する。
  focusout→compositionend の順序差を模したテストを追加。

## [Warning 観点5] redoStack の容量管理が未定義
- 判断: 対応する
- 根拠: undo 連続で undoStack と同量が redoStack に移る。redo 側の上限・reset が未定義だった。
- 対応内容: 容量管理を純関数 `boundHistory(stack, maxEntries, maxChars)` に切り出し、
  **両スタック**の push 後に適用(各スタック個別上限)。`resetHistory()` は両スタックを空に。
  純関数として単体テスト(件数打ち切り・文字数打ち切り・単一巨大エントリは残す)。
  → 履歴スタックのユーティリティを `resources/js/lib/manual/scenario-history.ts`(純関数)に
  切り出す(brief「関連 util(履歴スタック)」に整合、単体テスト容易化)。

## [Suggestion 観点6] スコープ外の記述が改訂後設計と矛盾
- 判断: 対応する
- 対応内容: 「input 内テキストのネイティブ undo との厳密な段階連動(アプリ層 undo を優先で割り切る)」
  を「編集フィールド内は native undo に委ね、document undo との厳密な履歴統合は行わない」に修正。

## [Suggestion 観点7] validator は配列要素・points・全必須フィールドを検証
- 判断: 対応する(明文化)
- 対応内容: `parseHistory` の型ガードは配列であること、各 step/point が全必須フィールド
  (rowOf の 8 フィールド + id: number|null)を持ち points が配列であることまで検証。
  内部に素の型アサーションを残さない。詳細設計で shape を明記。
