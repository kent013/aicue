# 対応マトリクス: design-review Round 3

## [Warning] 施策 B — backfill テストの既存行が手組み (「テストデータは必ず Factory」に抵触)
- 判断: **対応する**
- 根拠: 指摘のとおり。旧スキーマへ挿入するため insert を query builder で行う必要はあるが、
  属性値まで手組みする理由は無い。
- 対応内容: `IdempotencyKey::factory()->forApiKey($apiKey)->raw([...])` で属性を生成し、
  落とした `state` を `unset` し、json 列だけ明示エンコードしてから
  `DB::table()->insert()` する形に変更した。

## [Warning] 保証範囲 1 番に「コミットされる」旧記述が残存
- 判断: **対応する**
- 根拠: Round 2 で施策 H 側は直したが「保証しないもの」1 番が追随していなかった。
- 対応内容: 1 番を書き直し、並行安全性の主張を
  (テスト 1) claim 行の先行作成と同一接続での観測 /
  (テスト 3) unique による 2 本目の排除 /
  (実行環境の前提。**テストによる証明ではない**) 外側 transaction 不在 + pgsql autocommit・read committed
  の 3 つの合成として明記した。

## その他 (施策 A / C / D / E / F / G / H / I は APPROVE)
- 判断: **対応不要**
