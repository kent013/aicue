<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Billing;

/**
 * 滞留回収 1 実行分の結果。
 *
 * **任意メタデータ領域は持たせない** (`BillingRetentionPurgeResultDto` と同じ方針。
 * 型で分からない領域を作ると organization id 等が運用ログへ漏れる)。
 *
 * 件数の意味:
 *   replayed               = 再実行して processed まで終局した件数
 *   retryScheduled         = 再実行が失敗し received のまま次回の回収へ回した件数
 *   movedToRecoveryPending = 自動再実行の対象外として回収待ちへ置いた件数
 *   skipped                = 何もしなかった件数 (受理条件を満たさない / 行が無い /
 *                            書き込みが別の世代に追い越された)
 */
final readonly class WebhookRecoveryResultDto
{
    public function __construct(
        public int $replayed,
        public int $retryScheduled,
        public int $movedToRecoveryPending,
        public int $skipped,
    ) {}
}
