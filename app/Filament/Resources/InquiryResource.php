<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\Inquiry\InquirySource;
use App\Enums\Inquiry\InquiryStatus;
use App\Enums\Inquiry\InquiryType;
use App\Filament\Resources\Inquiries\Pages\EditInquiry;
use App\Filament\Resources\Inquiries\Pages\ListInquiries;
use App\Models\Inquiry;
use App\Support\EmailNormalizer;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 問い合わせ (Inquiry) の管理。
 *
 * - PII 列 (name/email/company_name/message) は表示専用 (disabled)。status のみ編集可。
 * - email 検索は CipherSweet blind index (inquiry_email_index) の exact-match。保存時に
 *   EmailNormalizer で正規化しているため、検索入力も同一正規化を通す。
 * - 削除は DeleteInquiryAction 経由 (EditInquiry の DeleteAction。構造化ログを残す)。
 */
class InquiryResource extends Resource
{
    protected static ?string $model = Inquiry::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?string $navigationLabel = 'お問い合わせ';

    protected static ?string $modelLabel = 'お問い合わせ';

    protected static ?string $pluralModelLabel = 'お問い合わせ';

    protected static string|\UnitEnum|null $navigationGroup = 'システム';

    public static function canCreate(): bool
    {
        // 受付は公開フォーム (POST /contact) のみ。管理画面から作成しない。
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        // 本人削除要請の手動対応経路 (AdminUser 限定 = panel 認可に乗る、hard delete)。
        return true;
    }

    public static function form(Schema $schema): Schema
    {
        // status のみ編集可。status は $fillable に無いため EditInquiry::handleRecordUpdate で
        // 明示代入する (Filament 既定の fill()->save() では更新されない)。
        return $schema->components([
            Select::make('status')->label('ステータス')
                ->options(collect(InquiryStatus::cases())->mapWithKeys(
                    static fn (InquiryStatus $s): array => [$s->value => $s->label()]
                )->all())
                ->required(),
            TextInput::make('name')->label('お名前')->disabled(),
            TextInput::make('email')->label('メールアドレス')->disabled(),
            TextInput::make('company_name')->label('会社・組織名')->disabled(),
            Textarea::make('message')->label('お問い合わせ内容')->disabled()->rows(6),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('type')->label('種別')
                    ->formatStateUsing(static fn (InquiryType $state): string => $state->label())
                    ->badge(),
                TextColumn::make('status')->label('ステータス')
                    ->formatStateUsing(static fn (InquiryStatus $state): string => $state->label())
                    ->badge()
                    ->color(static fn (InquiryStatus $state): string => match ($state) {
                        InquiryStatus::Open => 'warning',
                        InquiryStatus::InProgress => 'info',
                        InquiryStatus::Closed => 'success',
                        InquiryStatus::Spam => 'danger',
                    }),
                TextColumn::make('source')->label('経路')
                    ->formatStateUsing(static fn (InquirySource $state): string => $state->label())
                    ->placeholder('-'),
                // email は CipherSweet 暗号化列。保存時と同一の正規化 (EmailNormalizer) を通して
                // blind index (inquiry_email_index) を exact-match させる。
                TextColumn::make('email')->label('メールアドレス')
                    ->searchable(query: static function (Builder $query, string $search): Builder {
                        return $query->whereBlind('email', 'inquiry_email_index', EmailNormalizer::normalize($search));
                    }),
                TextColumn::make('name')->label('お名前'),
                // stale 可視化: 30 日以上 open のまま放置された行を badge + danger 色で強調。
                TextColumn::make('age_days')->label('経過日数')
                    ->state(static function (Inquiry $record): int {
                        $createdAt = $record->created_at;
                        if ($createdAt === null) {
                            return 0;
                        }

                        return (int) $createdAt->diffInDays(now());
                    })
                    ->badge()
                    ->color(static function (Inquiry $record): string {
                        $createdAt = $record->created_at;
                        $isStale = $record->status === InquiryStatus::Open
                            && $createdAt !== null
                            && $createdAt->lt(now()->subDays(30));

                        return $isStale ? 'danger' : 'gray';
                    }),
                TextColumn::make('created_at')->label('受信日時')->dateTime()->sortable(),
                TextColumn::make('closed_at')->label('完了日時')->dateTime()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('status')->label('ステータス')
                    ->options(collect(InquiryStatus::cases())->mapWithKeys(
                        static fn (InquiryStatus $s): array => [$s->value => $s->label()]
                    )->all()),
                // stale 可視化 (30 日以上 open のまま放置された問い合わせ)。
                TernaryFilter::make('stale')->label('30日以上 未対応')
                    ->queries(
                        true: static fn (Builder $query): Builder => $query
                            ->where('status', InquiryStatus::Open->value)
                            ->where('created_at', '<', now()->subDays(30)),
                        false: static fn (Builder $query): Builder => $query,
                        blank: static fn (Builder $query): Builder => $query,
                    ),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListInquiries::route('/'),
            'edit' => EditInquiry::route('/{record}/edit'),
        ];
    }
}
