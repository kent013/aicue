<?php

declare(strict_types=1);

namespace Tests\Support\JobDedup;

use Webmozart\Assert\Assert;

/**
 * 「preflight を要する外部呼び出しを持たない」という目録上の宣言。
 *
 * ★ここでいう「外部呼び出し」は `App\Enums\Security\ExternalCallKind` の定義域、すなわち
 *   **取り消せない外部副作用を持つ呼び出し**である。ローカル CPU (ffmpeg)・冪等な読み取り
 *   (S3 GET / invoice retrieve)・状態検査で冪等化された終端 (invoice void/delete) は
 *   定義域外であり、本 case で宣言してよい。**その旨を根拠文に必ず書く**
 *   (「外部 API を 1 本も呼ばない」と「preflight を要する呼び出しが無い」は別の主張)。
 */
final readonly class NoExternalCall implements PreflightRequirement
{
    /**
     * @param  non-empty-string  $rationale  「preflight を要する外部呼び出しを持たない」根拠 (30 文字以上)
     *
     * ★根拠文は日本語のため `mb_strlen()` で**文字数**を数える (`strlen()` はバイト数になる)。
     *   mbstring は Laravel の必須拡張であり、既存 ThrottleCoverageInventoryTest も同方式。
     */
    public function __construct(public string $rationale)
    {
        Assert::greaterThanEq(mb_strlen($rationale), 30, '「外部呼び出しなし」の根拠は 30 文字以上で書くこと');
    }
}
