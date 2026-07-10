<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * AI 解析パイプラインの段 (doc/10 §10.1 step 列)。
 * TS 側 types/manual.ts の AnalysisStep union と値集合を一致させる。
 */
enum AnalysisStep: string
{
    case Extract = 'extract';
    case Decompose = 'decompose';
    case Generate = 'generate';
}
