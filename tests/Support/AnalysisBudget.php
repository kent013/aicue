<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * AI 解析の時間 budget 不変条件で使う「仕様値」と、prompt YAML からの実測読み出し。
 *
 * Pest のファイルスコープ const / 関数はテスト間で衝突しうるため、
 * Tests\Support\PromptYaml と同じく autoload されるクラスに集約する。
 *
 * **CLIENT_TIMEOUT_SECONDS は仕様値であり、YAML から導出しない**。
 * これは意図的な重複である: YAML と仕様値を突き合わせることで初めて
 * 「YAML を勝手に変えた」ことを検出できる (YAML から導出すると同時変更で素通りする)。
 */
final class AnalysisBudget
{
    /** C: 1 呼び出しの client timeout (仕様値。prompt YAML と一致すること) */
    public const CLIENT_TIMEOUT_SECONDS = 360;

    /** extract / decompose / generate */
    public const STAGE_COUNT = 3;

    /** M₁: deadline 通過後の terminal tx + commit/release + 通知 */
    public const FINALIZE_BUDGET_SECONDS = 30;

    /** S: P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 + ログ */
    public const SAFETY_MARGIN_SECONDS = 90;

    /** D: パイプライン deadline の仕様値 */
    public const DEADLINE_SECONDS = self::STAGE_COUNT * self::CLIENT_TIMEOUT_SECONDS;

    /** 解析パイプラインの 3 プロンプト */
    public const PROMPT_NAMES = ['sop-extract', 'work-decomposition', 'scenario-generation'];

    /**
     * prompt YAML から読んだ client_options.timeout (プロンプト名 => 値)。
     *
     * ★読み取り規則の正本は `Tests\Support\PromptWaitBudget` 1 箇所である
     *   (未宣言 / 非配列 / キー無し / 非 int / 非正 をすべて例外にする)。
     *   ここに `Assert::integer()` 相当を書き戻さないこと — 以前の実装は
     *   `timeout: 0` を通していた。
     *
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $timeouts[$name] = PromptWaitBudget::requirePositive(
                resource_path("prompts/{$name}.yaml"),
                "{$name}.yaml",
            );
        }

        return $timeouts;
    }
}
