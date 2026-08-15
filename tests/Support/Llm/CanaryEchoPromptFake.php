<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use RuntimeException;

/**
 * 直前に記録した system prompt から合言葉を読み取り、それを**含む応答**を返す fake。
 *
 * 合言葉は呼び出しのたびに変わるため、固定文字列の fake では漏洩を再現できない。
 * vendor は `record()` → `nextResponse()` の順に呼ぶので、記録済み messages から
 * 合言葉を取り出せる (公開 API `recorded()` だけを使い、reflection は使わない)。
 */
final class CanaryEchoPromptFake extends PromptFake
{
    private int $callCount = 0;

    /**
     * @param  string  $prefix  応答の先頭に置く文字列 (不正バイトを混ぜる検査にも使う)
     * @param  int|null  $splitEveryChars  合言葉を空白で分割する場合の 1 片の文字数
     */
    public function __construct(
        private readonly string $prefix = '',
        private readonly ?int $splitEveryChars = null,
    ) {
        parent::__construct([]);
    }

    public function nextResponse(): TextResponseFake
    {
        $this->callCount++;

        $last = end($this->recorded);
        if ($last === false) {
            throw new RuntimeException('CanaryEchoPromptFake: 記録済みの呼び出しがありません');
        }

        $systemText = '';
        foreach ($last['messages'] as $message) {
            if ($message instanceof SystemMessage) {
                $systemText .= $message->content."\n";
            }
        }

        $matches = [];
        if (preg_match('/合言葉: ([0-9a-f]{8,})/', $systemText, $matches) !== 1) {
            throw new RuntimeException('CanaryEchoPromptFake: system prompt から合言葉を読めません');
        }
        $canary = $matches[1];
        if ($this->splitEveryChars !== null) {
            $canary = implode(' ', str_split($canary, $this->splitEveryChars));
        }

        return TextResponseFake::make()->withText($this->prefix.$canary);
    }

    /** 実際に LLM 呼び出しが試行された回数 (再試行の有無を固定するために使う)。 */
    public function callCount(): int
    {
        return $this->callCount;
    }
}
