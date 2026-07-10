<?php

declare(strict_types=1);

namespace App\Filament\Resources\Organizations\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * 組織詳細のメンバー一覧 (閲覧専用。attach/detach はアプリ側の正規経路で行う)。
 */
class UsersRelationManager extends RelationManager
{
    protected static string $relationship = 'users';

    protected static ?string $title = 'ユーザー';

    public function table(Table $table): Table
    {
        // name/email は CipherSweet 暗号化列。blind index (morph テーブル) を引く whereBlind で
        // 検索する (UserResource と同一規則)。blind index は完全一致のみ。name は Lowercase
        // transformer で大文字小文字を吸収する (case-insensitive 完全一致)。
        return $table
            // blind index は値全体ハッシュ = 完全一致のみ。Filament 既定の空白トークン分割を
            // 無効化し、入力文字列全体を 1 つの whereBlind 値として渡す (多語氏名の完全一致を成立)。
            ->splitSearchTerms(false)
            ->searchPlaceholder('氏名・メールの完全一致')
            ->columns([
                TextColumn::make('id')->label('ID')
                    ->sortable(),
                TextColumn::make('name')->label('名前')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->whereBlind('name', 'name_index', $search)),
                TextColumn::make('email')->label('メールアドレス')
                    ->searchable(query: static fn (Builder $query, string $search): Builder => $query
                        ->whereBlind('email', 'email_index', $search)),
                TextColumn::make('created_at')->label('作成日時')
                    ->dateTime()
                    // users と organization_user (pivot) の双方に created_at があり、join した
                    // relation query では未修飾だと pgsql が "ambiguous" で失敗するため列を明示修飾する。
                    ->sortable(['users.created_at']),
            ])
            ->defaultSort('users.created_at', 'desc');
    }
}
