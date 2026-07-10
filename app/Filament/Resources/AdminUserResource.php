<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AdminUsers\Pages\CreateAdminUser;
use App\Filament\Resources\AdminUsers\Pages\EditAdminUser;
use App\Filament\Resources\AdminUsers\Pages\ListAdminUsers;
use App\Models\AdminUser;
use Closure;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * 管理者 (AdminUser) 自身の管理 (一覧 / 作成 / 編集。削除は提供しない)。
 *
 * email は CipherSweet 暗号化カラムのため標準 unique rule では検証できず、
 * create 時のみ編集可 + whereBlind によるカスタム重複検証とする (編集での email
 * 変更は提供しない。変更が必要なら新規作成 + 旧アカウント削除で対応する)。
 * password はフォーム入力時のみ保存 (編集で空欄なら据え置き)。
 * MFA (app_authentication 系)・remember_token はサーバ管理のためフォームに出さない。
 */
class AdminUserResource extends Resource
{
    protected static ?string $model = AdminUser::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?string $navigationLabel = '管理者';

    protected static ?string $modelLabel = '管理者';

    protected static ?string $pluralModelLabel = '管理者';

    protected static string|\UnitEnum|null $navigationGroup = 'システム';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('名前')
                ->required()
                ->maxLength(255),
            TextInput::make('email')->label('メールアドレス')
                ->email()
                // 暗号化カラムのため標準 unique rule (ignoreRecord) が使えず、
                // email は create 時のみ編集可 + whereBlind カスタム重複検証とする
                ->required(static fn (string $operation): bool => $operation === 'create')
                ->disabled(static fn (string $operation): bool => $operation === 'edit')
                ->dehydrated(static fn (string $operation): bool => $operation === 'create')
                ->rule(static function (string $operation): Closure {
                    return static function (string $attribute, mixed $value, Closure $fail) use ($operation): void {
                        if ($operation !== 'create') {
                            return;
                        }
                        if (! is_string($value)) {
                            return;
                        }
                        if (AdminUser::whereBlind('email', 'email_index', $value)->exists()) {
                            $fail('このメールアドレスは既に使用されています。');
                        }
                    };
                })
                ->maxLength(255),
            TextInput::make('password')->label('パスワード')
                ->password()
                ->revealable()
                ->required(static fn (string $operation): bool => $operation === 'create')
                ->minLength(12)
                ->maxLength(255)
                ->confirmed()
                // 編集で空欄のままなら password を変更しない
                ->dehydrated(static fn (?string $state): bool => $state !== null && $state !== ''),
            TextInput::make('password_confirmation')->label('パスワード (確認)')
                ->password()
                ->revealable()
                ->required(static fn (string $operation): bool => $operation === 'create')
                ->dehydrated(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                // name / email は暗号化カラムのため DB 側の検索・ソートができない
                // (searchable / sortable を付けない。運用者は少数のため一覧で足りる)
                TextColumn::make('name')->label('名前'),
                TextColumn::make('email')->label('メールアドレス'),
                TextColumn::make('created_at')->label('作成日時')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->defaultSort('id');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListAdminUsers::route('/'),
            'create' => CreateAdminUser::route('/create'),
            'edit' => EditAdminUser::route('/{record}/edit'),
        ];
    }
}
