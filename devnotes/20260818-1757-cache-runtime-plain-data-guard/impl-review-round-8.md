## [Critical] 複数 namespace の取り込み表が混ざり、後続 namespace から上書きできる

対象:

- `tests/Architecture/CachePayloadPlainDataGateTest.php`
- `tests/Architecture/CacheGuardWiringGateTest.php`

深さ判定によって「型宣言後の取り込み」と「クラス本体の trait use」の区別は直りました。一方、両 `UseMap()` はファイル全体で単一の map を作り、`cachePayloadNamespace()` も最初の namespace だけを返します。

PHP は1ファイルに複数 namespace を置けるため、別 namespace の同名 alias で取り込み表を上書きできます。

```php
<?php

namespace First;

use Tests\Support\Cache\PlainDataGuardedRepository as Guarded;

class Bypass extends Guarded {}

namespace Second;

use Vendor\Package\Unrelated as Guarded;
```

現在の処理では map の `Guarded` が後者で上書きされ、`First\Bypass` の継承先まで `Vendor\Package\Unrelated` と解釈されます。その結果、L4d の母集団から外れます。波括弧形の複数 namespace でも同様です。

`cacheGuardUseMap()` でも、別 namespace の alias が W4 の trait 解決へ混入します。

対応は次のどちらかが必要です。

- namespace 区間ごとに namespace 名と import map を管理し、各参照位置に対応する map で解決する。
- 複数 namespace を明示的に未対応として fail-closed で落とし、保証範囲と負例を追加する。

今回追加された単一 namespace のセミコロン形・波括弧形・後置 import の負例は妥当です。ただし複数 namespace の負例も、実際の収集関数へ通して固定してください。

CHANGES_REQUESTED