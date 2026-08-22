<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/** middleware 位置に現れた参照の種別。 */
enum MiddlewareReferenceKind
{
    /** 文字列リテラル (alias 名。`password.confirm` / `password.confirm:web`)。 */
    case AliasString;

    /** `X::class` 形のクラス参照 (完全修飾名へ解決済み)。 */
    case ClassReference;
}
