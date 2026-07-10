<?php

declare(strict_types=1);

namespace App\Enums\Billing;

/**
 * Stripe Subscription Schedule の作成進捗状態。
 *
 * `from_subscription` + 別 `update` の 2 段 API call の部分完了を追跡する。
 * - None: schedule なし
 * - Created: Stripe schedule 生成済、phases 未設定 (部分完了。reconcile で復旧対象)
 * - Configured: phases 設定完了
 */
enum ScheduleSetupStatus: string
{
    case None = 'none';
    case Created = 'created';
    case Configured = 'configured';
}
