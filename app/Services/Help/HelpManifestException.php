<?php

declare(strict_types=1);

namespace App\Services\Help;

use RuntimeException;

/**
 * manifest / 置き場の規約に反する形を表す。
 *
 * ★**沈黙して空を返さないための型**である。規約違反を「節が 0 件」に畳むと、
 *   鮮度検査が母集団 0 件のまま緑になってしまう。
 */
final class HelpManifestException extends RuntimeException {}
