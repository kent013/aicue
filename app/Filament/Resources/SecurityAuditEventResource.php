<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SecurityEventType;
use App\Filament\Resources\SecurityAuditEvents\Pages\ListSecurityAuditEvents;
use App\Models\SecurityAuditEvent;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * 認証・セキュリティイベント (監査 3 層の Layer 2) の閲覧専用一覧。
 *
 * 監査証跡のため管理画面からの作成・編集・削除は一切提供しない
 * (記録は App\Services\Security\SecurityEventRecorder 経由のみ)。
 */
class SecurityAuditEventResource extends Resource
{
    protected static ?string $model = SecurityAuditEvent::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'セキュリティ監査';

    protected static ?string $modelLabel = 'セキュリティ監査イベント';

    protected static ?string $pluralModelLabel = 'セキュリティ監査イベント';

    protected static string|\UnitEnum|null $navigationGroup = '監査';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')
                    ->sortable(),
                TextColumn::make('user_id')->label('ユーザーID')
                    ->sortable(),
                TextColumn::make('event_type')->label('イベント種別')
                    ->badge()
                    ->formatStateUsing(static fn (SecurityEventType $state): string => $state->label()),
                TextColumn::make('ip_address')->label('IPアドレス')
                    ->placeholder('-'),
                TextColumn::make('occurred_at')->label('発生日時')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('event_type')->label('イベント種別')
                    ->options(collect(SecurityEventType::cases())->mapWithKeys(
                        static fn (SecurityEventType $type): array => [$type->value => $type->label()],
                    )->all()),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListSecurityAuditEvents::route('/'),
        ];
    }
}
