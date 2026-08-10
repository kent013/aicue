<?php

declare(strict_types=1);

namespace App\Services;

use App\DataTransferObjects\LlmCostReportData;
use App\DataTransferObjects\LlmCostRowData;
use App\Enums\LlmCostGroupBy;
use App\Models\LlmCallLog;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use stdClass;
use Webmozart\Assert\Assert;

/**
 * llm_call_logs の集計 (読み取り専用)。**再計算も再換算もしない**。
 *
 * - USD が主: total_cost_usd は pricing_snapshot から決定的に決まる
 * - JPY は副: total_cost_jpy は行ごとの fx_snapshot (記録時レート) 由来。期間合計の JPY は
 *   「各行の記録時レートでの合計」であり、単一レートで USD を換算した値ではない
 * - 未解決 (null) は 0 に潰さず件数で返す
 *
 * ★ この層は llm_call_logs の列しか知らない。アプリのドメイン語彙を持ち込まない
 *   (他リポジトリへそのまま移植できる状態を保つ)。
 */
final readonly class LlmCostReportService
{
    /** TOTAL 行のキー (行のキーと衝突しうるが、TOTAL は rows と別フィールドで返すため問題にならない)。 */
    private const string TOTAL_KEY = 'TOTAL';

    /** 集計キーの null 成分の表記。 */
    private const string NONE_KEY = '(none)';

    /**
     * 集計値の SELECT 句 (行 / TOTAL で**同じもの**を使う = 定義の二重管理をしない)。
     *
     * ★ 整数列は `COALESCE(SUM(...), 0)`。`SUM()` は対象 0 件で NULL を返すため、
     *   そのままだと int 引数の DTO が TypeError になる。
     * ★ 金額列 (`total_cost_usd` / `total_cost_jpy`) には COALESCE を**掛けない**。
     *   null は「未解決」を表す情報であり、0 に潰すと「タダだった」という嘘になる
     *   (`usd_unresolved_calls` / `jpy_unresolved_calls` と対になる仕様)。
     */
    private const string AGGREGATE_SELECT = 'COUNT(*) AS calls'
        .', COALESCE(SUM(input_tokens), 0) AS input_tokens'
        .', COALESCE(SUM(output_tokens), 0) AS output_tokens'
        .', SUM(total_cost_usd) AS total_cost_usd'
        .', SUM(total_cost_jpy) AS total_cost_jpy'
        .', COALESCE(SUM(CASE WHEN total_cost_usd IS NULL THEN 1 ELSE 0 END), 0) AS usd_unresolved_calls'
        .', COALESCE(SUM(CASE WHEN total_cost_jpy IS NULL THEN 1 ELSE 0 END), 0) AS jpy_unresolved_calls'
        .', COALESCE(SUM(CASE WHEN failure_reason IS NOT NULL THEN 1 ELSE 0 END), 0) AS failed_calls'
        .', COALESCE(SUM(CASE WHEN metadata_missing THEN 1 ELSE 0 END), 0) AS metadata_missing_calls';

    /**
     * 集計本体。クエリは 2 本だけ (行 / TOTAL)。
     *
     * TOTAL を行の PHP 加算で作らないのは、DECIMAL を PHP で足すと float 化するか
     * bcmath 依存を新たに持ち込むことになり、移植先の PHP 拡張前提を増やすためである。
     * GROUP BY 無しの集計は**対象 0 件でも 1 行返る**ので、0 件時の TOTAL の形もここが正本。
     *
     * @param  ?CarbonImmutable  $since  半開区間の開始 (含む)
     * @param  ?CarbonImmutable  $until  半開区間の終了 (含まない)
     * @param  ?int  $afterId  id がこれより**大きい**行だけを対象にする (smoke の「この実行分」)
     */
    public function report(
        LlmCostGroupBy $groupBy,
        ?CarbonImmutable $since = null,
        ?CarbonImmutable $until = null,
        ?int $afterId = null,
    ): LlmCostReportData {
        $columns = $groupBy->columns();

        // 集計キー列は select() (列名の配列) で、集計値は selectRaw() で積む
        // = SQL 文字列へ列名を連結しない (literal-string 境界を崩さない)
        $query = $this->baseQuery($since, $until, $afterId)
            ->select($columns)
            ->selectRaw(self::AGGREGATE_SELECT)
            ->groupBy($columns);
        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        $rows = [];
        foreach ($query->get() as $record) {
            $data = self::recordToArray($record);
            $rows[] = self::toRow(self::keyOf($data, $columns), $data);
        }

        $totalRecord = $this->baseQuery($since, $until, $afterId)
            ->selectRaw(self::AGGREGATE_SELECT)
            ->first();
        Assert::notNull($totalRecord, 'GROUP BY 無しの集計は対象 0 件でも 1 行返る');

        return new LlmCostReportData(
            groupBy: $groupBy,
            since: $since,
            until: $until,
            afterId: $afterId,
            rows: $rows,
            total: self::toRow(self::TOTAL_KEY, self::recordToArray($totalRecord)),
        );
    }

    /** where 条件だけを積んだ素のクエリ (行用 / TOTAL 用で同じ母集団を使う)。 */
    private function baseQuery(?CarbonImmutable $since, ?CarbonImmutable $until, ?int $afterId): Builder
    {
        $query = LlmCallLog::query()->toBase();

        if ($since !== null) {
            $query->where('created_at', '>=', $since);
        }
        if ($until !== null) {
            $query->where('created_at', '<', $until);   // 半開区間 (until ちょうどは含まない)
        }
        if ($afterId !== null) {
            $query->where('id', '>', $afterId);         // 順序比較 = 主キー同一性クエリではない
        }

        return $query;
    }

    /**
     * SELECT 結果 1 行を配列へ落とす (driver ごとの stdClass / array の差を 1 箇所に閉じる)。
     *
     * @return array<string, mixed>
     */
    private static function recordToArray(mixed $record): array
    {
        Assert::isInstanceOf($record, stdClass::class, '集計クエリの戻りが想定の形ではありません');

        /** @var array<string, mixed> $data */
        $data = (array) $record;

        return $data;
    }

    /**
     * 集計キーの生成。null 成分は '(none)'、複合キーは '#' 連結。
     *
     * @param  array<string, mixed>  $data
     * @param  non-empty-list<string>  $columns
     */
    private static function keyOf(array $data, array $columns): string
    {
        $parts = [];
        foreach ($columns as $column) {
            $value = $data[$column] ?? null;
            if ($value === null) {
                $parts[] = self::NONE_KEY;

                continue;
            }
            Assert::scalar($value, "集計キー列 {$column} が scalar ではありません");
            $parts[] = (string) $value;
        }

        return implode('#', $parts);
    }

    /**
     * 集計結果 1 行 → DTO。**型の境界はここ 1 箇所**
     * (`SUM()` の戻りは driver 依存で string|int|float|null になりうるため fail-loud に検査する)。
     *
     * @param  array<string, mixed>  $data
     */
    private static function toRow(string $key, array $data): LlmCostRowData
    {
        return new LlmCostRowData(
            key: $key,
            calls: self::countOf($data, 'calls'),
            inputTokens: self::countOf($data, 'input_tokens'),
            outputTokens: self::countOf($data, 'output_tokens'),
            totalCostUsd: self::moneyOf($data, 'total_cost_usd'),
            totalCostJpy: self::moneyOf($data, 'total_cost_jpy'),
            usdUnresolvedCalls: self::countOf($data, 'usd_unresolved_calls'),
            jpyUnresolvedCalls: self::countOf($data, 'jpy_unresolved_calls'),
            failedCalls: self::countOf($data, 'failed_calls'),
            metadataMissingCalls: self::countOf($data, 'metadata_missing_calls'),
        );
    }

    /**
     * 件数系の narrow。COALESCE 済みなので null は来ない (来たら SELECT 句の退行)。
     *
     * @param  array<string, mixed>  $data
     * @return int<0, max>
     */
    private static function countOf(array $data, string $column): int
    {
        $value = $data[$column] ?? null;
        Assert::numeric($value, "集計列 {$column} が数値ではありません (COALESCE が外れていませんか)");
        $count = (int) $value;
        Assert::natural($count, "集計列 {$column} が負の値です");

        return $count;
    }

    /**
     * 金額系の narrow。**null は維持する** (未解決を 0 に潰さない)。
     *
     * @param  array<string, mixed>  $data
     * @return numeric-string|null
     */
    private static function moneyOf(array $data, string $column): ?string
    {
        $value = $data[$column] ?? null;
        if ($value === null) {
            return null;
        }
        Assert::scalar($value, "集計列 {$column} が scalar ではありません");
        $amount = (string) $value;
        Assert::numeric($amount, "集計列 {$column} が数値文字列ではありません");

        return $amount;
    }
}
