<?php

declare(strict_types=1);

namespace App\Enums\Llm;

/**
 * 窓口が untrusted 入力を拒否した理由。
 *
 * 利用者向け文言の写像 (AnalysisPipeline::userMessageFor) は**網羅 match** でこの enum を扱い、
 * 到達不能な else を作らない。理由が増えたら写像側が静的に落ちる。
 */
enum UntrustedInputRejectionReason
{
    /** サニタイズ後の長さが config('llm-defense.max_untrusted_bytes') を超えた。 */
    case TooLarge;

    /** 有効な UTF-8 ではなく、無害化そのものが成立しなかった。 */
    case InvalidEncoding;
}
