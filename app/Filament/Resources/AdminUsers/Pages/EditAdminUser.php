<?php

declare(strict_types=1);

namespace App\Filament\Resources\AdminUsers\Pages;

use App\Filament\Resources\AdminUserResource;
use Filament\Resources\Pages\EditRecord;

/**
 * 管理者の編集 (削除 action は提供しない。退任時は DB 直接 or 専用手順で対応する)。
 */
class EditAdminUser extends EditRecord
{
    protected static string $resource = AdminUserResource::class;
}
