<?php

declare(strict_types=1);

namespace App\Exceptions\Organization;

use DomainException;

/**
 * 組織識別名の **構文** 違反 (家系裁定 AG-039b)。
 *
 * ★本例外は HTTP を知らない。422 への変換は FormRequest のカスタムルール
 *   (入力の妥当性) と Controller (ロック後に検出した競合) の 2 点だけで行う。
 * ★`unchanged()` は「同じ識別名への改名」であり構文違反ではないが、
 *   利用者へ返す意味が同じ (入力値が受け付けられない) ため同じ型に畳んでいる。
 */
final class InvalidOrganizationSlugException extends DomainException
{
    public static function malformed(string $input): self
    {
        return new self(
            '識別名は小文字英数字とハイフンのみ使えます (先頭末尾のハイフン・連続ハイフンは使えません): '.$input,
        );
    }

    public static function tooLong(string $input): self
    {
        return new self('識別名が長すぎます: '.mb_substr($input, 0, 50));
    }

    public static function unchanged(): self
    {
        return new self('現在の識別名と同じ値には変更できません。');
    }
}
