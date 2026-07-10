<?php

declare(strict_types=1);

namespace Tests\Support\Prompts;

use Kent013\PrismPrompt\Prompt;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

/**
 * StrayLlmCallGuard の self-test 専用最小 Prompt サブクラス。
 *
 * 実 Prompt (app/Prompts/ 配下) は constructor 引数や metadata YAML を要求し、
 * variables 不足で guard 到達前に別例外になりうる。本クラスは constructor 引数なし、
 * 固定 system / user message、JSON 抽出なしの最小実装にして guard 到達経路だけを
 * 確実に通す (対応する YAML は意図的に置かない。loadMetadata は不在時 no-op)。
 *
 * @extends Prompt<string>
 */
final class MinimalLlmCallPrompt extends Prompt
{
    protected ?string $provider = 'openai';

    protected ?string $model = 'gpt-4o-mini';

    /**
     * @return array<int, Message>
     */
    #[\Override]
    protected function buildMessages(): array
    {
        return [
            new SystemMessage('You are a helper for tests.'),
            new UserMessage('Say "ok".'),
        ];
    }

    #[\Override]
    protected function parseResponse(string $responseText): string
    {
        return $responseText;
    }
}
