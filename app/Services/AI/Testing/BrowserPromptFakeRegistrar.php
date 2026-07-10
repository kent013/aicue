<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Prompt;

/**
 * `Prompt::$fake` への BrowserPromptFake 差替を封じ込める単一箇所。
 *
 * `laravel-prism-prompt` が提供する `Prompt::installFake(PromptFake)` 公開 API
 * を使う。将来この API が変わった場合も影響範囲はここだけ。
 */
final class BrowserPromptFakeRegistrar
{
    public function __construct(private readonly BrowserCannedResponses $responses) {}

    public function install(): void
    {
        Prompt::installFake(new BrowserPromptFake($this->responses));
    }

    public function uninstall(): void
    {
        Prompt::stopFaking();
    }
}
