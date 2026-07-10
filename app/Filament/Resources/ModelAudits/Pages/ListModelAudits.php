<?php

declare(strict_types=1);

namespace App\Filament\Resources\ModelAudits\Pages;

use App\Filament\Resources\ModelAuditResource;
use Filament\Resources\Pages\ListRecords;

class ListModelAudits extends ListRecords
{
    protected static string $resource = ModelAuditResource::class;
}
