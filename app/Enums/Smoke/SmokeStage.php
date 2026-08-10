<?php

declare(strict_types=1);

namespace App\Enums\Smoke;

/**
 * pipeline smoke の段 (実行順)。**すべて実在の業務経路**に対応する。
 */
enum SmokeStage: string
{
    case Preflight = 'preflight';       // 事前検査 (LLM を 1 回も呼ばない)
    case Fixture = 'fixture';           // SOP 投入 (manual + source_document)
    case Analysis = 'analysis';         // AI 解析 (worker 待ち)
    case LlmEvidence = 'llm-evidence';  // 実呼び出しと帰属の記録検査 (DB 読み取りのみ)
    case Capture = 'capture';           // 撮影テイクの登録と採用
    case Render = 'render';             // ffmpeg 合成 (worker 待ち)
    case Artifact = 'artifact';         // 出力 mp4 の読み出しと ffprobe
}
