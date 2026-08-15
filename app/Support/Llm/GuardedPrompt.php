<?php

declare(strict_types=1);

namespace App\Support\Llm;

use App\Exceptions\Llm\PromptResponseRejectedException;
use Kent013\PrismPrompt\Prompt;
use Webmozart\Assert\Assert;

/**
 * 実行単位 (裁定 AG-028 の「実行単位」)。vendor 実行と応答検査を 1 メソッドに束ね、
 * 合言葉が漏れていたら**応答を呼び出し元へ渡さずに**例外にする (fail-closed)。
 *
 * ★ vendor の Prompt を返す public メソッドを 1 つも持たない (応答検査の迂回経路を
 *   構造的に消す)。公開面は `__construct` と `executeSync` だけで、
 *   `PromptDefenseWindowGateTest` が完全一致で pin する。
 * ★ 保持する型は vendor の**基底** `Kent013\PrismPrompt\Prompt` にする。
 *   `Prompt::load()` の宣言戻り値は `self`、`withMetadata()` は `static` であり、
 *   基底で受けるのが静的解析上いちばん素直だからである (実体は `TextPrompt`)。
 *   `executeSync(): mixed` は `TextPrompt::parseResponse()` が string を返すことから
 *   `Assert::string()` で絞る (mixed を外へ出さない)。
 */
final class GuardedPrompt
{
    /**
     * @param  Prompt<string>  $prompt
     */
    public function __construct(
        private readonly Prompt $prompt,
        private readonly PromptCanary $canary,
        private readonly string $template,
    ) {}

    /**
     * @throws PromptResponseRejectedException 合言葉の漏洩
     */
    public function executeSync(): string
    {
        $result = $this->prompt->executeSync();
        Assert::string($result, 'TextPrompt は文字列を返す');

        if ($this->canary->leakedIn($result)) {
            throw PromptResponseRejectedException::canaryLeaked($this->template);
        }

        return $result;
    }
}
