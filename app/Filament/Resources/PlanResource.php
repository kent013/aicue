<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\Plans\Pages\EditPlan;
use App\Filament\Resources\Plans\Pages\ListPlans;
use App\Models\Billing\Plan;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * プラン定義の一覧 + monthly_ticket_grant のみ編集。
 *
 * code / name / sort_order は PlanSeeder が真実源のため管理画面からは編集させない。
 * プランの追加・削除も Seeder 経由で行う (作成・削除 action は提供しない)。
 */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'プラン';

    protected static ?string $modelLabel = 'プラン';

    protected static ?string $pluralModelLabel = 'プラン';

    protected static string|\UnitEnum|null $navigationGroup = '課金';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            // 表示のみ (PlanSeeder が真実源。dehydrated(false) で保存対象から外す)
            TextInput::make('code')->label('コード')
                ->disabled()
                ->dehydrated(false),
            TextInput::make('name')->label('名前')
                ->disabled()
                ->dehydrated(false),
            // 編集できるのは月次チケット付与数のみ
            TextInput::make('monthly_ticket_grant')->label('月次チケット付与数')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->required(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->label('コード')
                    ->sortable(),
                TextColumn::make('name')->label('名前'),
                TextColumn::make('monthly_ticket_grant')->label('月次チケット付与数')
                    ->sortable(),
                TextColumn::make('sort_order')->label('表示順')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('sort_order');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListPlans::route('/'),
            'edit' => EditPlan::route('/{record}/edit'),
        ];
    }
}
