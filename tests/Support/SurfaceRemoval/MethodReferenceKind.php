<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/** 対象クラスのメソッドに触れる形。 */
enum MethodReferenceKind
{
    /** 対象クラスの本体に書かれたメソッド宣言。 */
    case Declaration;

    /** 対象クラスを受け手にした静的呼び出し (`Types::imagesEnabled()`)。 */
    case StaticCall;
}
