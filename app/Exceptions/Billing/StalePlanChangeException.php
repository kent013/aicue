<?php

declare(strict_types=1);

namespace App\Exceptions\Billing;

use RuntimeException;

/**
 * 画面 render 時点のプラン (`current_plan_code`) と実際の現在プランが食い違ったとき。
 *
 * **UX 用の stale 検知専用**であり認可判定ではない (認可は Gate、変更可否は subscription 状態)。
 * Controller が 422 (`errors.plan_code`) に変換し、redirect-back で props も最新化される。
 */
final class StalePlanChangeException extends RuntimeException
{
    public function __construct(
        public readonly ?string $expectedPlanCode,
        public readonly ?string $actualPlanCode,
        public readonly string $requestedPlanCode,
    ) {
        parent::__construct('プラン変更の前提が変わりました。');
    }
}
