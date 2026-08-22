<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 走査対象 1 ファイル (内容込みの値オブジェクト)。
 *
 * ★`$isPhp` は**拡張子から推定させない**。実母集団は
 *   `RemovedSurfaceScanTargets::population()` が決め、自己検証の見本 (`*.php.txt`) は
 *   gate 側が**引数で明示**して組み立てる (見本を `.php` で置くと
 *   `StrictTypesDeclarationGateTest` など無関係な gate が赤くなるため)。
 */
final readonly class ScannedFile
{
    public function __construct(
        /** 走査根 (`.github` / `app` / … / 見本は `fixtures`)。 */
        public string $root,
        /** リポジトリルート相対パス (見本は見本ファイルの相対パス)。 */
        public string $relative,
        /** NUL を含まず UTF-8 検証済みの内容。 */
        public string $contents,
        /** PHP ソースとして扱うか (`.blade.php` は PHP ソースではない)。 */
        public bool $isPhp,
        /** 拡張子 (小文字。拡張子なしは null)。 */
        public ?string $extension,
    ) {}
}
