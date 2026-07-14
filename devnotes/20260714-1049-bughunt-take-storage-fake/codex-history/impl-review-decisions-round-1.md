# 対応マトリクス: impl-review Round 1

## [Warning] `head()` の `contentLength` が `size()` の `false` を考慮していない
- 判断: 反論する（変更なし）
- 根拠:
  1. `s3_fake` disk は `config/filesystems.php` で `'throw' => true` を明示している。`FilesystemAdapter::size()` は Flysystem の `fileSize()` を呼び、取得失敗時は throw=true のため `UnableToRetrieveMetadata` を **throw**（fail-loud）する。false を返す経路は throw=false のときだけで、本 disk では発生しない。
  2. Laravel の `Filesystem::size(): int`（契約の `@return int`）のため、`if (! is_int($size))` は PHPStan level 10 で「常に false = 到達不能」と判定され **新たな PHPStan エラー**になる（禁止事項2: widen/ignore 不可のため導入できない）。
  3. `head()` は LOCK_SH 保持下で `exists($key)` を確認済みの直後に `size()` を呼ぶため、存在するオブジェクトの size 取得に限定される。
  - 結論: 「size 取得失敗 → 例外で fail-loud」は disk の `throw=true` で既に担保されており、追加ガードは PHPStan と衝突する dead code。現状維持が正しい。

## [Warning] provider `boot()` 再実行による route 二重登録リスク
- 判断: 対応する
- 根拠: 指摘どおり、将来 `enableFakeStorage()` を多用したり route:cache と boot() が併存すると同名 route の二重登録があり得る。本番 bootstrap では boot() は 1 回だが、冪等化は production-safe で害がない。
- 対応内容: `bootStorageRoutes()` の gate 判定の後に `if (Route::has('bughunt.storage.put')) return;` を追加。未登録時のみ登録する冪等ガード。通常 bootstrap では未登録 = そのまま登録される。PHPStan/pint/該当テスト green を確認。

## [Suggestion] concurrency テストを pcntl_fork で実メソッド競合まで観測
- 判断: 見送る
- 根拠:
  1. paratest/Pest worker 内での `pcntl_fork` は、稼働中の PostgreSQL 接続・PHPUnit/Pest のプロセス状態ごと fork するため危険で、既知の flaky 要因（詳細設計 Round 5 も「時間依存/flaky な並行判定に頼らない」と明記）。
  2. 不変条件「reader は null / 同一世代 metadata のみを観測し objectB+metaA を出さない」は、決定的な単体テストで既に固定済み: (a) `FakeObjectStoreTest` の「上書き PUT で head が新 meta を返す」「object あり sidecar なし → head null（未完了）」、(b) `FakeObjectStoreConcurrencyTest` の「store が使う実 `.locks/` パス上で LOCK_EX が writer/reader を排他し、解放で reader が進む」+「store が当該 lock パスを実際に使う」。= ロック機構（promote/head/delete が直列化される土台）と混在防止の両面を非 flaky に固定している。
- 対応内容: 追加せず。上記の決定的テスト群でカバー済みである旨を Round 2 で説明。
