<?php

declare(strict_types=1);

namespace App\Enums\Recovery;

/**
 * 回収 1 件の結果。**この 5 値がすべて**で、集計側は既定の分岐を持たない match で処理する。
 *
 * Recovered                   = 業務状態を前へ進めた
 * RecoveredWithCleanupFailure = 業務状態は前へ進めたが、付随する後始末に失敗した
 *                               (撮影アップロードの S3 削除失敗。件数を Recovered に畳まない)
 * Skipped                     = 競合・条件不成立で何もしなかった (正常事象。失敗ではない)
 * Deferred                    = 前へ進まなかったが次回の掃引へ残した (webhook の再実行失敗)
 * Escalated                   = 自動回収の対象外へ移し人手へ渡した (webhook の recovery_pending)
 */
enum RecoveryOutcome: string
{
    case Recovered = 'recovered';
    case RecoveredWithCleanupFailure = 'recovered_with_cleanup_failure';
    case Skipped = 'skipped';
    case Deferred = 'deferred';
    case Escalated = 'escalated';
}
