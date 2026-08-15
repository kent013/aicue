<?php

declare(strict_types=1);

namespace App\Enums\Recovery;

/**
 * 定期実行のうち「滞留回収ではない」ものの区分。
 *
 * 滞留回収の入口は work:recover-stuck ただ 1 本という規約を deny-by-default で保つため、
 * Schedule に載る他のコマンドはすべてこの区分と 30 文字以上の理由付きで目録へ登録する。
 * 未分類のコマンドが Schedule に現れたら目録 gate が落ちる (6 本目の独自回収を素通しで足せない)。
 */
enum NonRecoveryScheduleReasonKind: string
{
    /** 外部サービスを真実として自分の状態を収束させる (DB の状態だけでは行き先が決まらない) */
    case ExternalReconciliation = 'external_reconciliation';

    /** 生成物の後始末 (世代交代済みの出力の削除など。滞留の前進ではない) */
    case ArtifactCleanup = 'artifact_cleanup';

    /** 通知の送信 */
    case Notification = 'notification';

    /** 検知だけを行い状態を書かない */
    case DetectionOnly = 'detection_only';

    /** 保持期間の決着 (期限を過ぎた記録の削除・畳み込み) */
    case RetentionSettlement = 'retention_settlement';
}
