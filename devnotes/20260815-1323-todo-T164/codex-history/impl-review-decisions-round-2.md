# 対応マトリクス: impl-review Round 2

Round 2 の全体判定は **APPROVED**。blocking な指摘は無し。

## [Suggestion] `capture_empty` の説明を「名前付き route の観測行が 0」に揃える

- 判断: **対応する**
- 根拠: 実装 (`build_executed.load_shard`) は「有効行 0」ではなく「名前付き route の行が 0」で
  終了コード 3 にしている (照合器の `executed_no_rows` と定義を揃えるため)。
  文書側が「観測行 0」と書いていると、`route_name: null` の行しか無い shard が落ちる理由が読めない。
- 対応内容: `docs/template-divergence.md` D14 の不変条件と「保証しないもの」の表現を
  「名前付き route の観測行が 0」に直し、`route_name: null` だけの shard も落ちることを明記した。
  README (`coverage/README.md`) は理由コード表で `capture_empty` を挙げるに留めており、
  誤った説明は書いていないため変更しない。
