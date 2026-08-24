<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/**
 * `LlmResponseDecodePointGateTest` の**目録の判定**を純関数として切り出したもの。
 *
 * ★切り出す理由は (c) の裏取りである。目録が現在 0 件 / 免除が 1 件だけだと、
 *   本番 gate は「余剰登録」「未登録の観測値」「30 文字未満の理由」「前提の失われた免除」の
 *   分岐を一度も通らない。合成入力を同じ関数へ流して**両方向**を固定する
 *   (自己検査: `tests/Unit/Architecture/LlmResponseSeamScannerTest.php`)。
 *
 * ## 保証しないもの
 *
 * - ここが見るのは**目録どうしの整合**だけである。走査そのもの (どの site を観測したか) は
 *   `LlmResponseSeamScanner` の担当で、本クラスはその結果を受け取るだけである。
 */
final class LlmSeamInventoryRules
{
    /** 目録の根拠に要求する最小文字数。 */
    public const int MINIMUM_REASON_LENGTH = 30;

    /**
     * 目録外の型に解決された受け手の登録が実態と一致するか (deny-by-default・双方向)。
     *
     * @param  list<string>  $observedFactories  走査で観測した完全修飾名 (重複可)
     * @param  array<string, string>  $registered  完全修飾名 => 根拠
     * @return list<string> 違反の説明 (空なら整合)
     */
    public static function otherReceiverViolations(array $observedFactories, array $registered): array
    {
        $violations = [];
        foreach ($registered as $fqcn => $reason) {
            if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
                $violations[] = "{$fqcn}: 目録外の型の登録には ".self::MINIMUM_REASON_LENGTH.' 文字以上の根拠が必要';
            }
        }

        $observed = array_values(array_unique($observedFactories));
        sort($observed);
        $keys = array_keys($registered);
        sort($keys);

        foreach (array_diff($observed, $keys) as $missing) {
            $violations[] = "{$missing}: 目録外の型が executeSync() の受け手だが登録が無い";
        }
        foreach (array_diff($keys, $observed) as $stale) {
            $violations[] = "{$stale}: 登録されているが観測されない (stale)";
        }

        return $violations;
    }

    /**
     * `executeSync()` の母集団から外す免除が exact-fit か。
     *
     * 「実在する」「根拠が十分」だけでなく、**免除の前提** (そのファイルが実際に
     * `executeSync()` の site を持つ) が生きていることまで見る。前提が消えた古い免除は赤にする。
     *
     * @param  array<string, string>  $exemptions  相対パス => 根拠
     * @param  list<string>  $scannedPaths  走査対象に実在するパス
     * @param  array<string, int>  $siteCounts  相対パス => その file の executeSync() site 数
     * @return list<string> 違反の説明 (空なら整合)
     */
    public static function exemptionViolations(array $exemptions, array $scannedPaths, array $siteCounts): array
    {
        $violations = [];
        foreach ($exemptions as $path => $reason) {
            if (! in_array($path, $scannedPaths, true)) {
                $violations[] = "{$path}: 免除に実在しないパス";

                continue;
            }
            if (mb_strlen($reason) < self::MINIMUM_REASON_LENGTH) {
                $violations[] = "{$path}: 免除には ".self::MINIMUM_REASON_LENGTH.' 文字以上の根拠が必要';
            }
            if (($siteCounts[$path] ?? 0) < 1) {
                $violations[] = "{$path}: 免除の前提 (executeSync() を持つ) が失われている";
            }
        }

        return $violations;
    }
}
