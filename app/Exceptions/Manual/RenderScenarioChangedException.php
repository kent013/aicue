<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use RuntimeException;

/**
 * レンダジョブの version 固定 guard 違反 (§10.8-6)。
 * preview のトリガー後〜開始前にシナリオが編集された場合等に RenderPipeline が投げ、
 * catch → failJob(error_code=scenario_version_changed) に落ちる (HTTP 応答へは出ない)。
 */
final class RenderScenarioChangedException extends RuntimeException
{
    public static function versionMismatch(): self
    {
        return new self('編集中にシナリオが変更されたため、プレビューを作り直してください。');
    }

    public static function manualBusy(): self
    {
        return new self('シナリオの解析・書き出しが始まったため、プレビューを作り直してください。');
    }
}
