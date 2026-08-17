<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Prompt;

/**
 * canned PromptFake の install/uninstall を単一箇所に封じ込める。
 *
 * `laravel-prism-prompt` が提供する `Prompt::installFake(PromptFake)` 公開 API を使う。
 * 将来この API が変わった場合も影響範囲はここだけ。
 *
 * Browser lane (tests/Pest.php) と bughunt 実行時 (BughuntFakesServiceProvider::boot) の
 * 両方から共有される (Browser 専用ではない)。
 */
final class CannedPromptFakeRegistrar
{
    public function __construct(private readonly CannedPromptResponses $responses) {}

    public function install(): void
    {
        Prompt::installFake(new CannedPromptFake($this->responses));
    }

    public function uninstall(): void
    {
        Prompt::stopFaking();
    }
}
