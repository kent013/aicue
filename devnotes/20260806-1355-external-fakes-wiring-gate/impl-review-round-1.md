**Findings**

[Critical] [tests/Support/ExternalFakes/FakeWiringSourceScanner.php:233]  
`disallowedIndirectAccess()` が `App::` / `Container::` を short name の末尾だけで判定しており、`use` alias を解決していません。これにより provider 内で次のように書くと、未登録 fake bind を追加しても 3-8/3-9/3-10 をすり抜けます。

```php
use Illuminate\Container\Container as C;

C::getInstance()->bind(VideoComposer::class, FakeRenderObjectStorage::class);
```

`FakeRenderObjectStorage` は既存 expected 参照なので 3-10 の集合も変わらず、`$this->app->bind()` ではないので `bindPairs()` / `disallowedContainerCalls()` にも出ません。`use function app as container; container()->bind(...)` も同系統の抜け道です。`disallowedIndirectAccess()` で use alias を FQCN 解決し、`Illuminate\Container\Container` / `Illuminate\Support\Facades\App` / `app` / `resolve` の alias を検出する Unit ケースを追加してください。

**File Judgement**

`docs/architecture.md`: APPROVED  
設計の不変条件、責務境界、ProductionEnvGuard との分担は明確です。

`tests/Architecture/ExternalFakeWiringInvariantTest.php`: APPROVED  
実証検査、provider 登録順、bind 集合一致、LLM static 復元はいずれも設計に沿っています。

`tests/Architecture/FakeClassReferenceInvariantTest.php`: APPROVED  
母集団空化ガードと allowlist 固定は妥当です。

`tests/Support/ExternalFakes/ExternalFakeBinding.php`: APPROVED  
値オブジェクトとして問題ありません。

`tests/Support/ExternalFakes/ExternalFakeWiringInventory.php`: APPROVED  
現行 5 binding と例外集合は設計と一致しています。

`tests/Support/ExternalFakes/FakeClassCatalog.php`: APPROVED  
repo 相対パス統一、母集団導出、読み取り失敗の fail-closed は妥当です。

`tests/Support/ExternalFakes/FakeWiringSourceScanner.php`: CHANGES_REQUESTED  
上記 Critical の alias 経由 container 到達が残っています。

`tests/Unit/Architecture/FakeWiringSourceScannerTest.php`: CHANGES_REQUESTED  
5-7 に alias なしの `Container::getInstance()` はありますが、`Container as C` / `App as LaravelApp` / `use function app as container` の negative がありません。

**Overall**

CHANGES_REQUESTED