**AGENTS.md**
指摘なし。S5 の追記は設計どおりで、番号の既存参照も壊していません。

**docs/app-integration-guide.md**
指摘なし。ただし下記の gate 側の見落としを直すまでは、「目録で強制」の実効性が不足しています。

**tests/Architecture/CachePayloadPlainDataGateTest.php**
[Critical] `cachePayloadResolveName()` が namespace alias 付き qualified name を解決できません。  
例: `use Illuminate\Support\Facades as Facades; Facades\Cache::put(...)` や `use Illuminate\Contracts\Cache as CacheContract; CacheContract\Repository $cache` が、L2 だけでなく L3 surface からも落ち得ます。`$head` alias を qualified name でも展開し、fixture を追加してください。

[Critical] `app()->make('cache')->put(...)` が完全に見落とされます。  
`app()` が 0 引数の場合に container chain として扱われず、後続の member `make` は `isMemberName` で無視されます。string binding だと import も型 token も無いため L3 にも出ません。`app()->make('cache'|'cache.store'|Repository::class)->...` の負コントロールが必要です。

[Warning] `getFacadeRoot` を TERMINAL にしているため、`Cache::getFacadeRoot()->put(...)` を追跡しません。  
新規ファイルなら L3 で止まりますが、既に `role=write` の `FxRateService` 内に追加された場合、write count が増えず緑のまま通る可能性があります。`getFacadeRoot` は実 root repository を返し得るので CHAIN か unclassified 扱いに寄せるべきです。

**tests/Feature/Config/ConfigHardeningTest.php**
指摘なし。`false` とキー欠落を分けて pin しており、S3 の目的に合っています。

**tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php**
指摘なし。DTO の配列往復、素データ性、壊れた payload の拒否は S2 と一致しています。

CHANGES_REQUESTED