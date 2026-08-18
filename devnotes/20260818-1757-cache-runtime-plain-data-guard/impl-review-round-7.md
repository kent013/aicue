## tests/Architecture/CachePayloadPlainDataGateTest.php

[Critical] `cachePayloadUseMap()` は「名前空間スコープの `use` だけを読む」代わりに「最初の型宣言で走査を終了」しています。この2つは同義ではありません。PHPでは名前空間スコープの import を型宣言より後にも置けるため、次の合法な形で L4d を迂回できます。

```php
namespace App\Demo;

class Marker
{
}

use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;

class Bypass extends Guarded
{
}
```

取り込み表は最初の `class Marker` で空のまま確定し、`Bypass extends Guarded` は `App\Demo\Guarded` と誤解されて母集団から外れます。

型宣言で打ち切るのではなく、波括弧のスコープを追跡し、namespace スコープにある `T_USE` だけを収集してください。これならクラス本体の trait use と closure capture を除外しつつ、複数クラスの間にある正当な import も解決できます。上記入力を収集関数へ通す負例も必要です。

同じ「最初の型宣言で打ち切る」方式を採った `cacheGuardUseMap()` にも同種の問題があります。後置 import の alias で `WithCachedConfig` / `WithCachedRoutes` を使う後続クラスを見逃すため、こちらも同じスコープ判定へ直す必要があります。

W4 の主張を字句上の2形へ限定した文言自体は、今回選んだ保証範囲と整合しています。

CHANGES_REQUESTED