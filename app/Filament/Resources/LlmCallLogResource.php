<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\LlmCallLogs\Pages\ListLlmCallLogs;
use App\Models\LlmCallLog;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * LLM 呼び出しログ (append-only) の閲覧専用一覧。
 *
 * 観測ログのため管理画面からの作成・編集・削除は一切提供しない
 * (記録は RecordLlmCallCost / RecordLlmCallFailure listener → LlmCallLogWriter 経由のみ)。
 */
class LlmCallLogResource extends Resource
{
    protected static ?string $model = LlmCallLog::class;

    protected static string|\BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static ?string $navigationLabel = 'LLM 呼び出しログ';

    protected static ?string $modelLabel = 'LLM 呼び出しログ';

    protected static ?string $pluralModelLabel = 'LLM 呼び出しログ';

    protected static string|\UnitEnum|null $navigationGroup = '監査';

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')
                    ->sortable(),
                TextColumn::make('provider')->label('プロバイダ'),
                TextColumn::make('model')->label('モデル'),
                TextColumn::make('finish_reason')->label('終了理由')
                    ->badge()
                    ->color(static fn (string $state): string => $state === 'failed' ? 'danger' : 'success'),
                TextColumn::make('input_tokens')->label('入力トークン')
                    ->placeholder('-'),
                TextColumn::make('output_tokens')->label('出力トークン')
                    ->placeholder('-'),
                // 一覧フッターにページ内 / フィルタ結果全体の合計を表示する
                TextColumn::make('total_cost_usd')->label('コスト (USD)')
                    ->placeholder('-')
                    ->summarize(Sum::make()->label('合計')),
                TextColumn::make('total_cost_jpy')->label('コスト (JPY)')
                    ->placeholder('-')
                    ->summarize(Sum::make()->label('合計')),
                TextColumn::make('duration_ms')->label('所要時間 (ms)')
                    ->placeholder('-'),
                TextColumn::make('failure_reason')->label('失敗理由')
                    ->placeholder('-')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('呼び出し日時')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('provider')->label('プロバイダ')
                    ->options(static fn (): array => self::distinctOptions('provider')),
                SelectFilter::make('model')->label('モデル')
                    ->options(static fn (): array => self::distinctOptions('model')),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => ListLlmCallLogs::route('/'),
        ];
    }

    /**
     * 指定カラムの実在値を SelectFilter の options 形式 (値 => 値) で返す。
     *
     * @return array<string, string>
     */
    private static function distinctOptions(string $column): array
    {
        /** @var list<string> $values */
        $values = LlmCallLog::query()
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->all();

        return array_combine($values, $values);
    }
}
