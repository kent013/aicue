<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations\Pages;

use App\Filament\Resources\OrganizationResource;
use Filament\Resources\Pages\EditRecord;

/**
 * 組織の編集 (name のみ。削除 action は提供しない)。
 */
class EditOrganization extends EditRecord
{
    protected static string $resource = OrganizationResource::class;
}
