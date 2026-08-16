<?php

declare(strict_types=1);

namespace App\Exceptions\Capture;

use RuntimeException;

/**
 * サムネイル抽出の失敗 (ffmpeg 非 0 終了 / フレーム未生成 / 出力先の掃除失敗)。
 *
 * 失敗しても take は `ready` のままである (サムネイルは採用・レンダの必須条件ではない)。
 * 本例外はジョブへ伝播し、再試行を使い切ると failed_jobs に残るだけになる。
 */
final class TakeThumbnailExtractionException extends RuntimeException {}
