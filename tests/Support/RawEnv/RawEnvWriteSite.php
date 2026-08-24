<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

/**
 * 3 面への書き込み (と分類できなかった出現) 1 件の位置と分類 (不変の値オブジェクト)。
 *
 * ★`subject` は書き込まれた面の綴り (`$_SERVER` / `$_ENV` / `putenv` の呼び出しの綴り) である。
 *   違反を報告するときに「どの面か」を人が読める形で出すためだけに持つ。
 */
final class RawEnvWriteSite
{
    public function __construct(
        public readonly RawEnvWriteKind $kind,
        public readonly string $subject,
        public readonly int $line,
    ) {}
}
