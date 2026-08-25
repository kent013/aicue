# 対応マトリクス: design-review Round 2

## [Warning] 施策2: 注入する `$targets` の要素型の明記
- 判断: 対応する
- 根拠: PHPStan level 10 では iterable value type の欠落がエラーになる。
- 対応内容: 設計のコード片の docblock は既に
  `@param list<array{absolute: string, relative: string}>|null $targets` を持っていたが、
  `TrackedPhpSourceFiles::all()` の戻り型と**完全一致**させることを本文へ明記した
  (同クラスの `@return list<array{absolute: string, relative: string}>` を実読して確認済み)。
  PHPStan 適合チェック欄にも同項目を追加した。

## [Warning] 施策3: `mutatedDebtPaths` が最初に赤くなる手順のずれ
- 判断: 対応する
- 根拠: 指摘どおり。gate 本体ファイルへの最初の変更 (手順 2 の配線検査追加) の時点で
  突合 gate は赤になる。
- 対応内容: 実装手順の冒頭へ「手順 2 の時点から手順 6 まで突合 gate は意図的に赤のまま」の
  注記を置き、各手順の状態表記を「対象 gate 単位の red→green + 全体 green は手順 1 と 6 のみ」
  へ書き直した。
