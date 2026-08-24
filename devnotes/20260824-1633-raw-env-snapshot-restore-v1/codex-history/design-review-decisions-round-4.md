# 対応マトリクス: design-review Round 4

## 施策 1

### [Warning] 制御フローのトークンを見る API の宣言と利用要件が一致していない
- 判断: 対応する
- 根拠: `throwTokens()` では `return` / `break` / `continue` を指定できず、
  `restore()` の 5 条 (1)(5) を実装できない。
- 対応内容: `controlFlowTokens(array $tokens, int $tokenId): list<int>` へ改め、
  受け付ける token id を `T_THROW` / `T_RETURN` / `T_BREAK` / `T_CONTINUE` の 4 つに限定し、
  それ以外は**例外**にした (綴り間違いで「0 件だから合格」になるのを防ぐ fail-closed)。
  自己検査へ fail-closed 4 (4 つ以外の token id を渡す → 例外) を追加した。

### [Warning] `with()` の例外連結が `restore($bodyError)` の引数だけでは固定できない
- 判断: 対応する
- 根拠: 指摘のとおり。`catch` の中で `$bodyError = null;` にする / 代入を消す /
  別の例外を送出する、のいずれでも現在の検査を通ってしまう。
- 対応内容: `with()` の `catch` 本体について次を構造で固定した —
  `$bodyError = $e` の代入が**ちょうど 1 件**で右辺が `$e` であること /
  `catch` 本体の唯一の `throw` が `$e` を再送出すること
  (`statementTokens()` が返す綴りの列が `['$e']`)。
  走査 API に `variableAssignments()` と `statementTokens()` を足し
  (`;` が見つからない場合は例外)、負例 10 (3 形) と fail-closed 5 を追加した。

### [Warning] 構造走査器の正例 1 が新しい h-1 の契約を満たしていない
- 判断: 対応する
- 根拠: 正例が本番判定より弱い形だと、正例が本番と同じ判定を通らないか、
  通したときに正例自身が失敗する。
- 対応内容: 正例 1 を**本番と同形**の合成入力へ差し替えた
  (`try` / `catch` / `finally` の 3 ブロック + 適用ループ + `self::apply(` +
  `$bodyError = $e` + `throw $e` + `restore($bodyError)`)。
  同じ合成入力で件数・代入・再送出・引数まで検査する。

## 施策 2 / 3 / 4 / 5
- 判定: APPROVE 据え置き。変更なし。
