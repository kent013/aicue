<?php

declare(strict_types=1);

namespace App\Filament\Resources\Plans\Pages;

use App\Filament\Resources\PlanResource;
use Filament\Resources\Pages\EditRecord;

/**
 * プラン編集 (monthly_ticket_grant のみ。削除 action は提供しない)。
 */
class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;
}
