<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\Contracts\Message;
use RuntimeException;

/**
 * 決定論的 canned PromptFake (SystemMessage signature 解決)。
 *
 * 全実プロンプトは Prompt::load 経由で generic TextPrompt を実行するため、
 * record() のキー (static::class) は TextPrompt::class に潰れる。よってクラス名では
 * S3 各段 (sop-extract / work-decomposition / scenario-generation) を返し分けられない。
 * 代わりに record() が保持する messages の SystemMessage 役割文 (signature) で解決する
 * (解決ロジックは CannedPromptResponses に集約)。
 *
 * record() は executePrism()/executePrismStructured() の fake 分岐で nextResponse() の
 * 直前に必ず呼ばれるため、$this->recorded の最新 entry が「今実行中の Prompt」を指す。
 *
 * Browser lane (tests/Pest.php) と bughunt 実行時 (FakeExternalsServiceProvider::boot) の
 * 両方で共有される (Browser 専用ではない)。
 */
final class CannedPromptFake extends PromptFake
{
    public function __construct(private readonly CannedPromptResponses $cannedResponses)
    {
        parent::__construct([]);
    }

    /**
     * @param  ?Prompt<mixed>  $currentPrompt
     */
    public function nextResponse(?Prompt $currentPrompt = null): TextResponseFake
    {
        $messages = $this->latestRecordedMessages();
        if ($messages === null) {
            throw new RuntimeException(
                'CannedPromptFake::nextResponse() could not resolve recorded messages. '
                .'Ensure the fake is installed and Prompt::executePrism() recorded the prompt.'
            );
        }

        return $this->cannedResponses->forMessages($messages);
    }

    /**
     * 直前に record() された messages を返す (無ければ null)。
     *
     * @return array<int, Message>|null
     */
    private function latestRecordedMessages(): ?array
    {
        $last = end($this->recorded);

        return is_array($last) ? $last['messages'] : null;
    }
}
