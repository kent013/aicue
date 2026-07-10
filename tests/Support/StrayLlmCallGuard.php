<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Contracts\Foundation\Application;
use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Enums\Provider as ProviderEnum;
use Prism\Prism\PrismManager;
use Prism\Prism\Providers\Provider;
use RuntimeException;

/**
 * テスト中に未 fake の LLM 呼び出しを runtime で検知する PrismManager 差し替え guard。
 *
 * 仕組み:
 *  1. resolve() を override し、static accumulator に stray call を記録 → RuntimeException を throw。
 *  2. tests/Pest.php の beforeEach で install(), afterEach で flushAndFailIfStray() を呼ぶ。
 *  3. テスト本体で Prism::fake([...]) / Prompt::fake([...]) を呼ぶと PrismManager binding が
 *     上書きされ、または Prompt 層で short-circuit するため guard は無効化される。
 *  4. Service 層の try/catch fallback で例外が握り潰されても accumulator に残るため
 *     afterEach の flushAndFailIfStray() で必ず test を fail させる (= 主防御の核)。
 *
 * phpunit.xml の API キーダミー値強制 (OPENAI_API_KEY 等) は本 guard が万一
 * 無効化された場合の最終防壁 (tests/Feature/Config/PrismApiKeyDummyTest が到達を検証)。
 */
final class StrayLlmCallGuard extends PrismManager
{
    /** @var list<array{provider: string, providerConfig: array<string, mixed>}> */
    private static array $strayCalls = [];

    /**
     * Pest beforeEach から呼ぶ。前テストの残留状態を clear したうえで guard を install する。
     *
     * 順序:
     *  (1) accumulator を空にする (前テスト異常終了で残った記録を捨てる)
     *  (2) Prompt::stopFaking() で Kent013\PrismPrompt\Prompt::$fake static を reset
     *      (前テストの Prompt::fake が次テストにリークすると、本来 guard が catch すべき
     *       fake 漏れが Prompt 層で short-circuit して見逃される)
     *  (3) 既解決の PrismManager singleton を破棄
     *  (4) guard を PrismManager binding に差し込む
     */
    public static function install(Application $app): void
    {
        self::$strayCalls = [];
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        $app->instance(PrismManager::class, new self($app));
    }

    /**
     * Pest afterEach から呼ぶ。stray call が記録されていれば RuntimeException を throw して
     * test を fail させる。Service 層の try/catch fallback で例外が握り潰されても
     * このパスで必ず CI が赤くなるのが本 guard の存在意義。
     *
     * accumulator は finally で必ず clear する (process 内の後続テストへの二次被害を防ぐ)。
     */
    public static function flushAndFailIfStray(): void
    {
        try {
            if (self::$strayCalls === []) {
                return;
            }
            $summary = self::summarize(self::$strayCalls);
            throw new RuntimeException(
                'Stray LLM call detected during test execution. '
                .'Did you forget to call Prism::fake([...]) or Prompt::fake([...]) in the test body? '
                .PHP_EOL.$summary
            );
        } finally {
            self::$strayCalls = [];
        }
    }

    /**
     * accumulator を空に戻す。afterEach の finally から呼び、flushAndFailIfStray() が
     * throw した場合でも次テストへ残留状態を漏らさないことを保証する。
     */
    public static function reset(): void
    {
        self::$strayCalls = [];
    }

    /**
     * self-test 用 drain。意図的に stray call を発生させるテストで、global afterEach に
     * 到達する前に accumulator を取り出して clear する。
     *
     * @return list<array{provider: string, providerConfig: array<string, mixed>}>
     */
    public static function drainForAssertion(): array
    {
        $drained = self::$strayCalls;
        self::$strayCalls = [];

        return $drained;
    }

    /**
     * Prism 基盤を直接テストする Unit テストで guard 自体を opt-out するための helper。
     * Prism 基盤を直接テストする Unit テストでのみ使用する (通常テストでの opt-out 禁止)。
     */
    public static function uninstallForTest(Application $app): void
    {
        Prompt::stopFaking();
        $app->forgetInstance(PrismManager::class);
        self::$strayCalls = [];
    }

    /**
     * @param  array<string, mixed>  $providerConfig
     */
    #[\Override]
    public function resolve(ProviderEnum|string $name, array $providerConfig = []): Provider
    {
        $providerName = $name instanceof ProviderEnum ? $name->value : $name;
        self::$strayCalls[] = [
            'provider' => strtolower($providerName),
            'providerConfig' => $providerConfig,
        ];

        throw new RuntimeException(
            "Stray LLM call to provider [{$providerName}]. "
            .'Call Prism::fake([...]) or Prompt::fake([...]) in the test body before the LLM call.'
        );
    }

    /**
     * @param  list<array{provider: string, providerConfig: array<string, mixed>}>  $calls
     */
    private static function summarize(array $calls): string
    {
        $lines = [];
        foreach ($calls as $i => $call) {
            $lines[] = sprintf('  [%d] provider=%s', $i + 1, $call['provider']);
        }

        return implode(PHP_EOL, $lines);
    }
}
