<?php

declare(strict_types=1);

namespace App\Filament\Resources\Inquiries\Pages;

use App\Actions\Inquiry\DeleteInquiryAction;
use App\Enums\Inquiry\InquiryStatus;
use App\Filament\Resources\InquiryResource;
use App\Models\Inquiry;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Webmozart\Assert\Assert;

class EditInquiry extends EditRecord
{
    protected static string $resource = InquiryResource::class;

    /** @return array<int, Action> */
    protected function getHeaderActions(): array
    {
        return [
            // UI 削除も DeleteInquiryAction 経由にし、構造化ログ (inquiry.deleted, reason=manual)
            // を必ず残す。素の DeleteAction はログ無しで PII を消すため不可。
            DeleteAction::make()
                ->using(static function (Inquiry $record): void {
                    app(DeleteInquiryAction::class)($record, 'manual');
                }),
        ];
    }

    /**
     * status は $fillable に無いため Filament 既定の fill()->save() では更新されない。
     * narrow してから明示代入する (updating フックで closed_at を自動集約:
     * close で set、reopen で null)。
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        Assert::isInstanceOf($record, Inquiry::class);
        Assert::keyExists($data, 'status');
        Assert::string($data['status']);

        $record->status = InquiryStatus::from($data['status']);
        $record->save();

        return $record;
    }
}
