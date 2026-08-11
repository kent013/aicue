# 対応マトリクス: design-review Round 2 (全体判定 APPROVED)

Round 2 で全施策 APPROVE / 全体 APPROVED。残った [Suggestion] は 4 件で、いずれも
安価かつ設計の精度を上げるため**すべて取り込んだ**(設計の方向は変えていない)。

## [Suggestion] 施策 2: policy 差し替えのテスト間残留
- 判断: **対応する**
- 対応内容: `afterEach` で ① 静的スイッチを既定へ戻し ② `Gate::policy(VideoManual::class,
  VideoManualPolicy::class)` で本来の policy を明示再登録する、と設計に明記。
  「Laravel がテストごとに Application を作り直すから大丈夫」には**依存しない**
  (実行順非依存であることを読み手にも見せる)。

## [Suggestion] 施策 4: 応答本文全体への `https://` 非出現検査は脆い
- 判断: **対応する**(検査を限定)
- 根拠: 指摘が正しい。ziggy / asset / 将来の無関係な props を拾って偽陽性になる。
- 対応内容: 検査対象を `render.finishedJob` の**キー集合の exact 一致**(9 キー丁度)に変更。
  本文検査を足す場合も対象を `output_path` と署名先ホスト (`signed.example`) に限定すると明記。

## [Suggestion] 施策 6: mutation 表の参照が M1-M10 のまま
- 判断: **対応する**
- 対応内容: 実装順序の記述を「M1-M11(M1' / M7' を含む)」へ修正。

## [Suggestion] 施策 6: 「id の大小比較を持つ」の検出条件を具体化せよ
- 判断: **対応する**(新機構は作らない)
- 対応内容: 前提の token 条件を「正規化 token 列に `where` `(` `'id'` `,` `'>'`(または `'<'`)の
  **連続**が現れる」+「`latest(` / `orderByDesc(` が 1 度も現れない」と具体化。
  既存 `PhpTokenScan` の token 列に対する部分列照合だけで書き、構文解析機構は新設しない。
