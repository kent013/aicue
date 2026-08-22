# 対応マトリクス: design-review Round 4

Warning 3 件・Suggestion 2 件。**すべて対応する**（反論・見送りは 0 件）。Critical は 0 件。

## [Warning] S3 に「同一語への到達路は実行時層が捕まえる」の断定が残っており Round 3 の保証範囲と矛盾
- 判断: **対応する**
- 対応内容: 次へ書き換えた。
  > `config.password.confirm` のような形は**静的層の保証外**である。実行時層は、それが**テスト起動時に route middleware として実体化した場合のみ**補完する（実体化しない経路までは保証しない）。

## [Warning] S5 の自己検証の表に旧表現「見本が検索語を実際に含むことを先に assert」が残っている
- 判断: **対応する**
- 対応内容: 「**各正例について S1 で定義した検出経路別の前提検査を先に行う**（一律の `str_contains()` は使わない）」へ置き換えた。

## [Warning] S6 の自己検証にも同じ旧表現が残っている
- 判断: **対応する**
- 対応内容: S5 と同じ表現へ置き換えた。

## [Warning] 実装モードの「テスト層 6 ファイル（新設 5 + 変更 1）」が施策一覧と一致しない
- 判断: **対応する**
- 対応内容: 件数表記をやめ、分類での表現へ改めた。
  > 変更は**新設の見本群・`tests/Support/SurfaceRemoval/`・Architecture gate 2 本、既存 Architecture test 1 本、Svelte のコメント 2 箇所**に限定される。
  競合リスクが低いという結論は変わらない。

## [Suggestion] S2 / S3 本文の「変更箇所」にも enum ファイルを記載すべき
- 判断: **対応する**
- 対応内容: S2 の変更箇所へ `ContentClassification.php` を、S3 の変更箇所へ `MiddlewareReferenceKind.php` / `MethodReferenceKind.php` を追加した（施策一覧と一致させた）。
