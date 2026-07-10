<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * 予告 (reminder) dispatch の結果。コマンドが種別毎に集計・出力する。
 * 実 mail 送信の成否ではなく「dispatch でどう扱われたか」を表す。
 */
enum BillingReminderDispatchResult: string
{
    case Queued = 'queued';   // 新規 delivery record を作り通知を queue した
    case Skipped = 'skipped'; // 既送 ((type, dedup_key) 既存) でスキップ
    case Failed = 'failed';   // 宛先 null / enqueue 失敗で failed 記録
}
