<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * エンドユーザーの閲覧専用リソース (編集・削除なし)。
 *
 * PII (name / email) は CipherSweet で暗号化されているため、平文 LIKE 検索は
 * 機能しない。name / email の検索は blind index (whereBlind) の完全一致で行う
 * (name は Lowercase transformer 付きで case-insensitive 完全一致)。
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?string $navigationLabel = 'ユーザー';

    protected static ?string $modelLabel = 'ユーザー';

    protected static ?string $pluralModelLabel = 'ユーザー';

    protected static string|\UnitEnum|null $navigationGroup = 'テナント管理';

    public static function table(Table $table): Table
    {
        // name/email は CipherSweet 暗号化列。blind index (morph テーブル) を引く whereBlind で
        // 検索する。blind index は値全体ハッシュ = 完全一致のみ。name は Lowercase transformer
        // 付きで大文字小文字を吸収する (case-insensitive 完全一致)。
        return $table
            // blind index は値全体ハッシュ = 完全一致のみ。Filament 既定の空白トークン分割を
            // 無効化し、入力文字列全体を 1 つの whereBlind 値として渡す (多語氏名の完全一致を成立)。
            ->splitSearchTerms(false)
            ->searchPlaceholder('氏名・メールの完全一致')
            ->columns([
                TextColumn::make('id')->label('ID')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')->label('名前')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->whereBlind('name', 'name_index', $search)),
                TextColumn::make('email')->label('メールアドレス')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->whereBlind('email', 'email_index', $search)),
                TextColumn::make('created_at')->label('作成日時')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('id')->label('ID'),
            TextEntry::make('name')->label('名前'),
            TextEntry::make('email')->label('メールアドレス'),
            TextEntry::make('currentOrganization.name')->label('現在の組織')
                ->placeholder('-'),
            TextEntry::make('email_verified_at')->label('メール確認日時')
                ->dateTime()
                ->placeholder('-'),
            TextEntry::make('created_at')->label('作成日時')
                ->dateTime(),
        ]);
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
