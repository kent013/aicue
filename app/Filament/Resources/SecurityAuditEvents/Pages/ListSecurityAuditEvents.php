<?php

declare(strict_types=1);

namespace App\Filament\Resources\SecurityAuditEvents\Pages;

use App\Filament\Resources\SecurityAuditEventResource;
use Filament\Resources\Pages\ListRecords;

class ListSecurityAuditEvents extends ListRecords
{
    protected static string $resource = SecurityAuditEventResource::class;
}
