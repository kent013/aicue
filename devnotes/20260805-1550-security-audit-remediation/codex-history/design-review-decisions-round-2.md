# 対応マトリクス: design-review Round 2

## [Warning] S4: `ManualOwnerScopedResolution` の検査が implicit binding だけに限定されている

- 判断: 対応する (指摘どおり。explicit binder の穴を見落としていた)
- 根拠: `SubstituteBindings` は implicit binding (action 引数のモデル型) と
  explicit binding (`Route::bind()` / `Route::model()`) の**両方**を実行する。
  action 引数を `string` にしても、将来 `Route::bind('user', ...)` が登録されれば
  binding 段で解決され 404 の非対称が復活する。
- 対応内容: 検査 3a を 3 条件に拡張した。
  1. action 引数の型が Model 派生でないこと (Reflection)
  2. `RouteBindingTypes` の手動解決 exclusion に登録済みであること
  3. **当該 param に explicit binder が登録されていないこと**
     — `app('router')->getBindingCallback($param) === null` で検証
     (`Illuminate\Routing\Router::getBindingCallback()` は public。
     `Route::bind()` / `Route::model()` のどちらでも `$binders` に入るため両方を捕捉できる)
  加えて、静的 3 条件が破られても落ちるように、
  後段短絡が発生する状態 (未契約組織 / メール未確認) での
  「実在の非メンバー id」と「不在 id」の応答同一性を Feature テストで固定する二段構えを明記した。

## [Warning] S7: `securityEventRecordingMap()` の仕様とサンプルが矛盾している

- 判断: 対応する (指摘どおり。サンプルが検査 4 の要件を満たしていなかった)
- 対応内容: サンプルを**全 case 同一形式** (`'event'|'caller'` + `'covered_by'` 必須) に修正。
  検査項目も以下に強化した:
  - 4: `covered_by` が必ずあり、ファイルが実在し、**その内容に当該 case の `value` が出現する**こと
       (空疎な登録の防止)
  - 5: `event` と `caller` は**いずれか一方**のみ (両方 / 両方なしは fail)

## [Suggestion] S3 の施策名が S3-b と一致しない

- 判断: 対応する
- 対応内容: 施策名を「**メンバー route の実在性オラクル解消 (`{user}`)**」に変更し、
  scopeBindings (S3-a) と手動解決 (S3-b) の 2 方式を route ごとの意味的な親に応じて
  使い分ける旨を冒頭に追記した。
