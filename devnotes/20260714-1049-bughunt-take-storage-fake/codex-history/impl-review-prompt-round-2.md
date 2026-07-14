## Round 2: 指摘への対応

前ラウンドの [Warning] 2 点・[Suggestion] 1 点について、以下のとおり対応しました。再判定をお願いします。

### [Warning 2] provider boot() 再実行による route 二重登録リスク → **対応（修正済み）**

`bootStorageRoutes()` に冪等ガードを追加しました（gate 判定の後）:

```php
private function bootStorageRoutes(): void
{
    if (! $this->app->make(FakeStorageGate::class)->enabled()) {
        return;
    }

    // 冪等化: boot() が複数回走っても (route:cache 併用・テストの provider 再実走等)
    // 同名 route を二重登録しない。通常の bootstrap では未登録 = そのまま登録される。
    if (Route::has('bughunt.storage.put')) {
        return;
    }

    Route::middleware('signed')->group(function (): void {
        Route::put('/_fake-storage/object', PutFakeStorageObjectController::class)
            ->name('bughunt.storage.put');
        Route::get('/_fake-storage/object', GetFakeStorageObjectController::class)
            ->name('bughunt.storage.get');
    });
}
```

PHPStan (No errors) / pint / `tests/Feature/Storage/`(12 passed) green を確認済み。

### [Warning 1] head() の size() false 未考慮 → **反論（現状維持）**

`s3_fake` disk は `'throw' => true` を明示しています。`FilesystemAdapter::size()` は Flysystem `fileSize()` を呼び、取得失敗時は throw=true のため **例外を throw**（fail-loud）します。`false` を返すのは throw=false の disk のみで、本 disk では発生しません。

加えて `Filesystem::size(): int`（契約 `@return int`）のため、`if (! is_int($size))` は PHPStan level 10 で「常に false = 到達不能」と判定され **新たな PHPStan エラー**になります（禁止事項2: ignore/widen 不可のため導入不可）。また `head()` は LOCK_SH 保持下で `exists($key)` 確認済みの直後に `size()` を呼ぶため、存在する object の size 取得に限定されます。

→ 「size 取得失敗 → 例外で fail-loud」は disk の `throw=true` で既に担保されており、追加ガードは PHPStan と衝突する dead code になるため現状維持とします。

### [Suggestion] concurrency テストを pcntl_fork で実メソッド競合まで → **見送り**

paratest/Pest worker 内での `pcntl_fork` は稼働中の PostgreSQL 接続・PHPUnit/Pest のプロセス状態ごと fork するため危険で、既知の flaky 要因です（詳細設計 Round 5 も「時間依存/flaky な並行判定に頼らない」と明記）。

不変条件「reader は null / 同一世代 metadata のみ観測し objectB+metaA を出さない」は決定的な単体テストで固定済みです:
- `FakeObjectStoreTest`: 「上書き PUT で head が新 meta を返す（旧 meta 混同なし）」「object あり sidecar なし → head null（未完了）」
- `FakeObjectStoreConcurrencyTest`: 「store が使う実 `.locks/` パス上で LOCK_EX が writer/reader(LOCK_SH) を排他し、解放で reader が進む」「複数 reader は同時可・writer は待つ」「store が当該 lock パスを実際に使い object listing を汚さない」

= ロック機構（promote/head/delete の直列化の土台）と混在防止の両面を、プロセス fork なしで決定的に固定しています。

---

再レビューの上、全体判定（APPROVED / CHANGES_REQUESTED）をお願いします。修正差分は `bootStorageRoutes()` の冪等ガード 5 行のみです。
