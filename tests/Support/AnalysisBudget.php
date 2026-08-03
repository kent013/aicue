<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

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
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
    {
        $timeouts = [];
        foreach (self::PROMPT_NAMES as $name) {
            $yaml = Yaml::parseFile(resource_path("prompts/{$name}.yaml"));
            Assert::isArray($yaml, "{$name}.yaml が map ではありません");
            Assert::keyExists($yaml, 'client_options', "{$name}.yaml に client_options がありません");

            // 配列 offset 式のままだと PHPStan の narrowing が保たれないためローカル変数へ移す
            $clientOptions = $yaml['client_options'];
            Assert::isArray($clientOptions, "{$name}.yaml の client_options が map ではありません");
            Assert::keyExists($clientOptions, 'timeout', "{$name}.yaml に client_options.timeout がありません");

            $timeout = $clientOptions['timeout'];
            Assert::integer($timeout, "{$name}.yaml の client_options.timeout が int ではありません");

            $timeouts[$name] = $timeout;
        }

        return $timeouts;
    }
}
