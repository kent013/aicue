<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Testing\TextResponseFake;
use Kent013\PrismPrompt\TextPrompt;
use RuntimeException;

/**
 * Prompt クラスごとの決定論的 canned response 定義 (Browser テスト用)。
 *
 * 新しい Prompt サブクラスを Browser テストから呼ぶ場合は、ここに必ず応答を
 * 登録すること。未登録のまま呼ばれた場合は fail-fast で例外を投げ、
 * 偽陽性 (silent green) を防ぐ。
 *
 * Feature/Unit テストは Prism::fake() / Prompt::fake() を使い個別にモックするため、
 * ここには影響しない。本クラスは Browser (pest-plugin-browser) テストプロセス内で
 * のみ使われる (tests/Pest.php の Browser lane が BrowserPromptFakeRegistrar 経由で
 * インストールする)。
 *
 * キーの決まり方 (重要):
 *   record() には Prompt インスタンスの `static::class` が入る。テンプレートの
 *   `ExampleSummaryPrompt` のような「YAML を Prompt::load で読む factory」は
 *   generic な `TextPrompt` インスタンスを返すため、記録されるクラスは
 *   `TextPrompt::class` になる (factory クラス名ではない)。
 *   専用サブクラス (`class FooPrompt extends TextPrompt` 等) を定義した場合は
 *   そのサブクラス名がキーになるので、map() に 1 行追加する。
 */
final class BrowserCannedResponses
{
    public function forPromptClass(string $promptClass): TextResponseFake
    {
        $canned = $this->lookup($promptClass);

        if ($canned === null) {
            $registered = implode(', ', $this->supportedPromptClasses());
            throw new RuntimeException(sprintf(
                "BrowserCannedResponses has no canned response for prompt class '%s'.\n"
                ."Registered classes: [%s]\n"
                .'Register one in app/Services/AI/Testing/BrowserCannedResponses.php '
                .'to avoid silent false-positives in Browser tests.',
                $promptClass,
                $registered === '' ? '(none)' : $registered,
            ));
        }

        return TextResponseFake::make()->withText($canned);
    }

    /**
     * @return list<class-string>
     */
    public function supportedPromptClasses(): array
    {
        /** @var list<class-string> $keys */
        $keys = array_keys($this->map());

        return $keys;
    }

    private function lookup(string $promptClass): ?string
    {
        return $this->map()[$promptClass] ?? null;
    }

    /**
     * @return array<class-string, string>
     */
    private function map(): array
    {
        return [
            // App\Prompts\ExampleSummaryPrompt (factory) 用の最小エントリ。
            // Prompt::load が返す generic TextPrompt を実行するため、記録される
            // prompt class は TextPrompt::class になる (クラス docblock 参照)。
            TextPrompt::class => self::exampleSummaryCanned(),
        ];
    }

    /**
     * ExampleSummaryPrompt (example-summary.yaml) 用の canned response。
     * 「テキストを日本語 1 文で要約する」プロンプトなので固定 1 文を返す。
     */
    private static function exampleSummaryCanned(): string
    {
        return 'Browser テスト用の固定要約文です。';
    }
}
