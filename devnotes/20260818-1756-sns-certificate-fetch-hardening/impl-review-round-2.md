### `tests/Architecture/SnsCertificateFetchContractTest.php`

判定: Round 1 の指摘は解消済み。

C3 は完全修飾名・別名・完全修飾形・未解決受け手を扱い、別クラスの同名短名を除外できています。C11 と C1/C13b の正例・負例も補完されています。

### `tests/Feature/Mail/SnsCertificateFetcherTest.php`

[Warning] F11 が「一切例外を投げない」ことを固定できていません。

```php
expect(fn () => snsCertFetcher()->rememberVerified(...))
    ->not->toThrow(QueryException::class);
```

これは `QueryException` 以外の例外を禁止する契約になっていません。F11 の要件は、キャッシュ書き込み障害を握った結果として呼び出し元へ何も投げないことです。直接呼び出して未処理例外でテストを落とすか、従来どおり `throwsNoExceptions()` を使用してください。

SQLite in-memory で値テーブルとロックテーブルを独立して壊す代替自体は妥当です。`Cache::swap()` も除去され、実行時キャッシュガードの被覆が維持されています。

### `tests/Architecture/CachePayloadPlainDataGateTest.php`

判定: Round 1 の指摘は解消済み。`put` の7件への更新も提示されたコードと一致します。

### 検証結果

[Warning] 修正後の `composer test` 全体 green がまだ報告されていません。対象テスト、PHPStan、Pint は green ですが、リポジトリ規約上、全体テスト未確認の状態では APPROVED にできません。

CHANGES_REQUESTED