<?php

declare(strict_types=1);

namespace App\Enums\Billing;

use App\Services\Billing\PersonalPlanService;

/**
 * Personal (free) プランを有効化できない理由。
 *
 * 表示文言 (label) はサーバー側で確定し、frontend に文言マッピングを散らさない。
 */
enum PersonalPlanIneligibleReason: string
{
    case HasEntitledSubscription = 'has_entitled_subscription';
    case TooManyMembers = 'too_many_members';
    case AlreadyHasFreePersonalOrg = 'already_has_free_personal_org';

    public function label(): string
    {
        return match ($this) {
            self::HasEntitledSubscription => '有効な有償契約があるためパーソナルプランは選択できません。',
            self::TooManyMembers => sprintf('メンバーが %d 名を超えているためパーソナルプランは選択できません。', PersonalPlanService::MAX_MEMBERS),
            self::AlreadyHasFreePersonalOrg => '既にパーソナルプラン（無料）の組織をお持ちのため、この組織では選択できません。',
        };
    }
}
