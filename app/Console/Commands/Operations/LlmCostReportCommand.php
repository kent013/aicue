<?php

declare(strict_types=1);

namespace App\Console\Commands\Operations;

use App\DataTransferObjects\LlmCostReportData;
use App\DataTransferObjects\LlmCostRowData;
use App\Enums\LlmCostGroupBy;
use App\Services\LlmCostReportService;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Console\Command;

/**
 * llm_call_logs を期間集計して LLM 利用コストを表示する (読み取り専用)。
 *
 * 集計本体は LlmCostReportService が持つ (1 実装・複数入口。もう 1 つの入口は
 * dev:pipeline-smoke の末尾に出る「この実行分」のレポート)。
 * 本コマンドは入力の検証と表示だけを担う。**スケジュール登録はしない**。
 */
class LlmCostReportCommand extends Command
{
    /** 日付のみ入力 (`Y-m-d`) の解釈で使う。 */
    private const string DATE_FORMAT = 'Y-m-d';

    /** 日時入力 (`Y-m-d H:i:s`) の解釈で使う。 */
    private const string DATE_TIME_FORMAT = 'Y-m-d H:i:s';

    /** 既定の集計期間 (日)。 */
    private const int DEFAULT_WINDOW_DAYS = 30;

    /** @var string */
    protected $signature = 'operations:llm-cost-report
        {--since= : 集計開始日時 (Y-m-d または Y-m-d H:i:s。既定 = 30 日前。UTC 解釈)}
        {--until= : 集計終了日時 (既定 = 現在。UTC 解釈)}
        {--group-by=prompt_template : 集計軸 (prompt_template|model|organization|subject)}
        {--json : 機械可読出力}';

    /** @var string */
    protected $description = 'llm_call_logs を集計して LLM 利用コストを表示する (読み取り専用)。';

    public function handle(LlmCostReportService $reports): int
    {
        $groupByOption = $this->stringOption('group-by') ?? LlmCostGroupBy::PromptTemplate->value;
        $groupBy = LlmCostGroupBy::tryFrom($groupByOption);
        if ($groupBy === null) {
            $this->error("--group-by が不正です: {$groupByOption} (指定できるのは ".LlmCostGroupBy::optionList().')');

            return self::INVALID;
        }

        $sinceOption = $this->stringOption('since');
        $since = $sinceOption === null
            ? CarbonImmutable::now()->subDays(self::DEFAULT_WINDOW_DAYS)
            : self::parseBoundary($sinceOption, exclusiveEndOfDay: false);
        if ($since === null) {
            $this->error('--since を解釈できません (Y-m-d または Y-m-d H:i:s で指定してください)');

            return self::INVALID;
        }

        $untilOption = $this->stringOption('until');
        $until = $untilOption === null
            ? CarbonImmutable::now()
            : self::parseBoundary($untilOption, exclusiveEndOfDay: true);
        if ($until === null) {
            $this->error('--until を解釈できません (Y-m-d または Y-m-d H:i:s で指定してください)');

            return self::INVALID;
        }

        if ($since->greaterThanOrEqualTo($until)) {
            $this->error('--since は --until より前でなければなりません (期間は半開区間 since <= created_at < until)');

            return self::INVALID;
        }

        $report = $reports->report($groupBy, $since, $until);

        if ($this->option('json') === true) {
            $this->line(json_encode($report->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTable($report);

        return self::SUCCESS;
    }

    /** 表 + 注記 (注記は 4 行から増やさない)。 */
    private function renderTable(LlmCostReportData $report): void
    {
        $this->line(sprintf(
            'group_by=%s since=%s until=%s (UTC)',
            $report->groupBy->value,
            $report->since?->toDateTimeString() ?? '-',
            $report->until?->toDateTimeString() ?? '-',
        ));

        $rows = array_map(self::displayRow(...), $report->rows);
        $rows[] = self::displayRow($report->total);

        $this->table(
            ['key', 'calls', 'in_tok', 'out_tok', 'usd', 'jpy', 'usd_null', 'jpy_null', 'failed', 'meta_missing'],
            $rows,
        );

        $this->line('注: 期間境界は UTC で解釈する (JST とは 9 時間ずれる)');
        $this->line('注: JPY は各行の記録時レート (fx_snapshot) の合計であり単一レート換算ではない');
        $this->line('注: usd_null / jpy_null の行は金額合計に含まれない (0 に潰していない)');
        $this->line('注: meta_missing = 組織・対象が特定できない行。0 でないなら呼び出し側の withMetadata() 配線が欠けている');
    }

    /**
     * 表示行 (列がガタつかないよう桁を揃えるだけ。DTO 側は丸めない)。
     *
     * @return list<string>
     */
    private static function displayRow(LlmCostRowData $row): array
    {
        return [
            $row->key,
            (string) $row->calls,
            (string) $row->inputTokens,
            (string) $row->outputTokens,
            $row->totalCostUsd === null ? '-' : number_format((float) $row->totalCostUsd, 6, '.', ''),
            $row->totalCostJpy === null ? '-' : number_format((float) $row->totalCostJpy, 2, '.', ''),
            (string) $row->usdUnresolvedCalls,
            (string) $row->jpyUnresolvedCalls,
            (string) $row->failedCalls,
            (string) $row->metadataMissingCalls,
        ];
    }

    /**
     * 期間境界の解釈。解釈できなければ null (呼び出し側が INVALID を返す)。
     *
     * - `Y-m-d` の `--until` は**翌日 0 時 (排他)** にする = 「その日を含む」
     * - `Y-m-d H:i:s` はそのまま使う (排他境界のまま)
     */
    private static function parseBoundary(string $raw, bool $exclusiveEndOfDay): ?CarbonImmutable
    {
        $dateOnly = self::parseWithFormat($raw, self::DATE_FORMAT);
        if ($dateOnly !== null) {
            return $exclusiveEndOfDay ? $dateOnly->addDay() : $dateOnly;
        }

        return self::parseWithFormat($raw, self::DATE_TIME_FORMAT);
    }

    /**
     * 厳格な parse。再フォーマットが入力と一致しない値 (`2026-13-45` の桁溢れ等) は null。
     */
    private static function parseWithFormat(string $raw, string $format): ?CarbonImmutable
    {
        try {
            // '!' で未指定フィールドを epoch へリセットする (時刻の混入を防ぐ)
            $parsed = CarbonImmutable::createFromFormat('!'.$format, $raw);
        } catch (InvalidFormatException) {
            return null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format($format) !== $raw) {
            return null;
        }

        return $parsed;
    }

    /** option を string|null へ narrow する (bool option と取り違えない)。 */
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
