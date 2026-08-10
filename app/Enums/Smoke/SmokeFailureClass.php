<?php

declare(strict_types=1);

namespace App\Enums\Smoke;

/**
 * pipeline smoke の失敗分類 (観測語彙)。
 *
 * ★ 分類は**観測のためであり制御フローを変えない**。
 * ★ `Unknown` は「写像表に一致が無かった」ことを意味し、写像表の値としては使わない。
 */
enum SmokeFailureClass: string
{
    /** preflight で落ちた (LLM を 1 回も呼んでいない) */
    case Preflight = 'preflight';

    /** ジョブが queued のまま上限到達 / LLM は動いているのに記録が不完全 */
    case Wiring = 'wiring';

    /** ジョブが running のまま上限到達 */
    case StageTimeout = 'stage_timeout';

    /** provider 側の疑い (LLM が原因になり得る段でのみ使う) */
    case Llm = 'llm';

    /** レンダ (error_code あり / 出力は読めたが ffprobe が非 0) */
    case Render = 'render';

    /** 出力オブジェクトが不在 / 読み出し不能 */
    case Storage = 'storage';

    /** 写像表に一致が無かった */
    case Unknown = 'unknown';
}
