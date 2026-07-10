<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModelAudits\Pages;

use App\Filament\Resources\ModelAuditResource;
use Filament\Resources\Pages\ViewRecord;

class ViewModelAudit extends ViewRecord
{
    protected static string $resource = ModelAuditResource::class;
}
