<?php

declare(strict_types=1);

namespace App\Exceptions\Manual;

use RuntimeException;

/**
 * ffmpeg / ffprobe 実行失敗 (FfmpegVideoComposer が投げる)。
 * メッセージは内部詳細 (コマンド・stderr) を含む report() 用で、ユーザー表示には使わない
 * (RenderPipeline::userMessageFor が汎用文言へ変換する)。
 */
final class RenderCompositionException extends RuntimeException {}
