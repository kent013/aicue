<?php

declare(strict_types=1);

namespace App\Filament\Resources\LlmCallLogs\Pages;

use App\Filament\Resources\LlmCallLogResource;
use Filament\Resources\Pages\ListRecords;

class ListLlmCallLogs extends ListRecords
{
    protected static string $resource = LlmCallLogResource::class;
}
