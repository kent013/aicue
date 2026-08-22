<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\Organizations\Pages\EditOrganization;
use App\Filament\Resources\Organizations\Pages\ListOrganizations;
use App\Filament\Resources\Organizations\Pages\ViewOrganization;
use App\Filament\Resources\Organizations\RelationManagers\UsersRelationManager;
use App\Models\Organization;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 組織 (テナント) の閲覧 + name のみ編集。
 *
 * slug / plan_code はサーバ側 (provisioning / Stripe webhook) が
 * 管理する状態キーのため管理画面からは編集させない。削除も提供しない
 * (退会フローはアプリ側の正規経路で行う)。
 */
class OrganizationResource extends Resource
{
    protected static ?string $model = Organization::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice;

    protected static ?string $navigationLabel = '組織';

    protected static ?string $modelLabel = '組織';

    protected static ?string $pluralModelLabel = '組織';

    protected static string|\UnitEnum|null $navigationGroup = 'テナント管理';

    public static function form(Schema $schema): Schema
    {
        // 編集できるのは name のみ (保護キーをフォームに出さない)
        return $schema->components([
            TextInput::make('name')->label('名前')
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('名前')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('slug')->label('スラッグ')
                    ->searchable(),
                TextColumn::make('plan_code')->label('プラン')
                    ->placeholder('未契約')
                    ->sortable(),
                TextColumn::make('created_at')->label('作成日時')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('name')->label('名前'),
            TextEntry::make('slug')->label('スラッグ'),
            TextEntry::make('plan_code')->label('プラン')
                ->placeholder('未契約'),
            TextEntry::make('members_count')->label('メンバー数')
                ->state(static fn (Organization $record): int => $record->users()->count()),
            TextEntry::make('projects_count')->label('プロジェクト数')
                ->state(static fn (Organization $record): int => $record->projects()->count()),
            TextEntry::make('created_at')->label('作成日時')
                ->dateTime(),
        ]);
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [
            UsersRelationManager::class,
        ];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListOrganizations::route('/'),
            'view' => ViewOrganization::route('/{record}'),
            'edit' => EditOrganization::route('/{record}/edit'),
        ];
    }
}
