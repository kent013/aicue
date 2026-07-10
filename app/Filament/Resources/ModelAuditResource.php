<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ModelAudits\Pages\ListModelAudits;
use App\Filament\Resources\ModelAudits\Pages\ViewModelAudit;
use App\Models\ModelAudit;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * モデル監査ログ (model_audits) の閲覧専用 Resource。
 *
 * 監査証跡のため管理画面からの作成・編集・削除は一切提供しない
 * (記録は owen-it/laravel-auditing + CriticalActionContext gating 経由のみ)。
 */
class ModelAuditResource extends Resource
{
    protected static ?string $model = ModelAudit::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?string $navigationLabel = 'モデル監査ログ';

    protected static ?string $modelLabel = 'モデル監査ログ';

    protected static ?string $pluralModelLabel = 'モデル監査ログ';

    protected static string|\UnitEnum|null $navigationGroup = '監査';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->label('作成日時')->dateTime(),
            TextEntry::make('event')->label('イベント')->badge(),
            TextEntry::make('auditable_type')->label('対象タイプ'),
            TextEntry::make('auditable_id')->label('対象ID'),
            TextEntry::make('user_type')->label('実行者タイプ'),
            TextEntry::make('user_id')->label('実行者ID'),
            TextEntry::make('displaySource')
                ->label('発生源')
                ->state(static fn (ModelAudit $record): string => $record->displaySource()),
            TextEntry::make('displayAction')
                ->label('アクション')
                ->state(static fn (ModelAudit $record): ?string => $record->displayAction()),
            TextEntry::make('displayReason')
                ->label('変更理由')
                ->state(static fn (ModelAudit $record): ?string => $record->displayReason()),
            TextEntry::make('tags')->label('タグ'),
            TextEntry::make('old_values')->label('変更前')
                ->columnSpanFull()
                ->state(static fn (ModelAudit $record): string => (string) json_encode($record->old_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
            TextEntry::make('new_values')->label('変更後')
                ->columnSpanFull()
                ->state(static fn (ModelAudit $record): string => (string) json_encode($record->new_values, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('作成日時')->dateTime()->sortable(),
                TextColumn::make('event')->label('イベント')->badge()->sortable(),
                TextColumn::make('displaySource')
                    ->label('発生源')
                    ->state(static fn (ModelAudit $record): string => $record->displaySource())
                    ->badge(),
                TextColumn::make('displayAction')
                    ->label('アクション')
                    ->state(static fn (ModelAudit $record): ?string => $record->displayAction()),
                TextColumn::make('auditable_type')->label('対象'),
                TextColumn::make('user_id')->label('実行者ID'),
            ])
            ->filters([
                SelectFilter::make('event')
                    ->label('イベント')
                    ->options([
                        'created' => '作成',
                        'updated' => '更新',
                        'deleted' => '削除',
                        'restored' => '復元',
                    ]),
                // 対象タイプは実データから動的に列挙する (テンプレートは Auditable モデルを
                // 固定しないため、選択肢のハードコードはしない)
                SelectFilter::make('auditable_type')
                    ->label('対象タイプ')
                    ->options(static fn (): array => ModelAudit::query()
                        ->distinct()
                        ->orderBy('auditable_type')
                        ->pluck('auditable_type', 'auditable_type')
                        ->all()),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListModelAudits::route('/'),
            'view' => ViewModelAudit::route('/{record}'),
        ];
    }
}
