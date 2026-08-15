<?php

declare(strict_types=1);

namespace App\Enums\Recovery;

/**
 * 滞留回収の対象系列。
 *
 * **キーはコマンド引数 (--stream) と Schedule と目録の同一性の基準**である
 * (定期実行はすべて同じ work:recover-stuck なので、コマンド名では stream の欠落も重複も見えない)。
 */
enum RecoveryStream: string
{
    case AnalysisJob = 'analysis_job';
    case RenderJob = 'render_job';
    case TicketReservation = 'ticket_reservation';
    case WebhookEvent = 'webhook_event';
    case UploadReservation = 'upload_reservation';

    /**
     * 定期実行の間隔 (分)。現行の cron の間隔をそのまま保存する。
     *
     * **60 の約数であること** (cron の刻み表記が毎時同じ間隔で回る前提。Unit テストで固定)。
     */
    public function cadenceMinutes(): int
    {
        return match ($this) {
            self::AnalysisJob, self::RenderJob,
            self::TicketReservation, self::WebhookEvent => 5,
            self::UploadReservation => 10,
        };
    }

    /**
     * 多重起動を抑止するロックの有効期限 (分) = 実行間隔の 2 倍。
     *
     * **Laravel 既定 (24 時間) に任せない**。異常終了でロックが残ると、既定では丸 1 日
     * 回収が止まったまま無音になる (回収基盤が回収を止める)。2 倍にしてあるのは、
     * 前回の実行が長引いている間の重複起動は抑えつつ、取り残しが最大 2 周期で解けるようにするため。
     *
     * **保証範囲を誇張しない**: 有効期限を過ぎるとロックは期限切れとして解けるので、
     * 正常な実行がこの時間を超えて走っている間は同一系列が並行実行されうる。
     * 多重起動しても状態が壊れないことは各 stream の行ロック下の再評価が担保する。
     */
    public function overlapExpiryMinutes(): int
    {
        return $this->cadenceMinutes() * 2;
    }
}
