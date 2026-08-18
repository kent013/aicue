## tests/Architecture/CachePayloadPlainDataGateTest.php

[Critical] `cachePayloadUseMap()` が namespace 直下の import だけでなく、クラス本体の trait `use` も取り込み表へ登録します。後から現れた trait 名で正しい import を上書きできるため、L4d を迂回できます。

```php
namespace App\Demo;

use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;

class TraitUser
{
    use \Vendor\Package\Guarded;
}

class Bypass extends Guarded
{
}
```

PHP 上、`Bypass extends Guarded` は namespace import により `PlainDataGuardedRepository` を指します。しかし現在の走査では、クラス本体の `use \Vendor\Package\Guarded` が `$map['Guarded']` を上書きし、継承対象を `Vendor\Package\Guarded` と誤解して L4d の母集団から外します。

`cachePayloadUseMap()` は namespace スコープの class import だけを読む必要があります。少なくとも型宣言の本体に入った後の `T_USE` と closure capture を除外し、上記の負例を実際の収集関数へ通してください。複数クラスを持つファイルでも namespace import が全クラスへ有効である点に注意が必要です。

## tests/Architecture/CacheGuardWiringGateTest.php

[Warning] W4 の保証外として追加された `call_user_func('uses', …)` は、実際に保護対象の trait 適用を行えるため、保証外の追記だけでは AGENTS.md (b) を満たしません。テスト名と W4 の主張は依然として「WithCachedConfig / WithCachedRoutes を適用するテストが 0 件」です。

動的関数呼び出しまで検出しない方針なら、W4 の名前・保証の記述を「直接の trait use と字句として書かれた `uses(...)` が 0 件」のように明示的に狭める必要があります。全適用形を塞ぐ主張を維持するなら、少なくとも `call_user_func('uses', ...)` や変数関数による呼び出しを未解決として落とす必要があります。

それ以外の standalone-call 判定、波括弧なし制御構文の負例、function/const import の除外は今回の指摘へ適切に対応しています。

CHANGES_REQUESTED