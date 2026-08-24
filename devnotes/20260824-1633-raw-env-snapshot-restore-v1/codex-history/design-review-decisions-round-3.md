# 対応マトリクス: design-review Round 3

## 施策 1

### [Critical] h-3 の検出力が主張する契約より弱い (break で抜ける形・無条件送出が通る)
- 判断: 対応する (提示された 2 案のうち **構造を最後まで固定する**案を採る)
- 根拠: 指摘のとおり。「唯一の `throw` がループの外にある」だけでは
  「ループの中で `break` して抜ける形」「失敗を蓄積せず無条件に送出する形」を止められない。
  保証表を狭めるほうは、例外の契約が丸ごと未検査になるので採らない。
- 対応内容: h-3 の判定を 5 つに分けて明記した。
  (1) 復元のループの本体に `throw` / `return` / `break` / `continue` が 1 件も無い /
  (2) `$failed[] = …` がループ本体にちょうど 1 件ある /
  (3) その追加が `$applied === false` の条件分岐の本体にある /
  (4) ループの後の `$failed !== []` の条件分岐の本体に、メソッド唯一の `throw` がある /
  (5) その `throw` 以外にメソッドを途中終了させるトークン (`return` / `throw`) が無い。
  走査 API に `variableAppends()` (`$var[] =` の位置) と
  `ifBlocks()` (各 `if` の [条件範囲, 本体範囲]) を足した。

### [Warning] 例外の `previous` 連結が検査されていない
- 判断: 対応する
- 根拠: 引数を落としても h-1〜h-3 が緑のままなら、例外の契約の半分が未検査になる。
- 対応内容: 走査 API に `callArguments()` (呼び出しの丸括弧の中を最上位のカンマで割り、
  各引数のトークン列を返す。括弧の対応が取れない / 引数の区切りが確定しない場合は**例外**) を足し、
  次を h-1 / h-2 / h-3 へ追加した —
  `with()` の `restore(` の第 1 引数が `$bodyError` / `captureAndClear()` の第 1 引数が `$e` /
  `restore()` の `new RuntimeException(` の**第 3 引数が `$previous`** であること。

### [Warning] `foreachOver()` の契約では `$this->state` を表現できない
- 判断: 対応する
- 対応内容: API を `foreachOverExpression(array $tokens, list<string> $expressionTexts): list<int>`
  に改めた (正規化済みのトークンの綴りの列を受ける。`['$changes']` / `['$keys']` /
  `['$this', '->', 'state']`)。判定は `foreach` の直後の丸括弧を開いた最初の有意トークンから
  **綴りが完全一致で連続すること**、かつ次の有意トークンが `T_AS` であることで行う。
  正例に `$this->state`、負例に `array_values($this->state)` を追加した。

### [Suggestion] 実装手順の段 4 に h-3 を含める
- 判断: 対応する
- 対応内容: 段 4 の記述を「h-1 / h-2 / h-3 を足す」へ改めた。

## 施策 2 / 3 / 4 / 5
- 判定: APPROVE。変更なし。
