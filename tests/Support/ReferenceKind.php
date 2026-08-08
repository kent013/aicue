<?php

declare(strict_types=1);

namespace Tests\Support;

/** 参照 site の種別 (何として現れたか)。 */
enum ReferenceKind
{
    /** 型・クラス名としての参照 (型宣言 / `::class` / `instanceof` / 引数型 等)。 */
    case NameReference;

    /** `new X(...)` の構築点。 */
    case Construction;

    /** `X::method(` の静的呼び出し。 */
    case StaticCall;

    /** `$x->method(` / `$x?->method(` のメソッド呼び出し。 */
    case MethodCall;
}
