<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Resources\InquiryResource;
use Filament\Resources\Pages\ListRecords;

class ListInquiries extends ListRecords
{
    protected static string $resource = InquiryResource::class;
}
