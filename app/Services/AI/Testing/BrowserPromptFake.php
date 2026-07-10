<?php

declare(strict_types=1);

namespace App\Services\AI\Testing;

use Kent013\PrismPrompt\Prompt;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use RuntimeException;

/**
 * Browser テスト用の決定論的 PromptFake。
 *
 * Prompt サブクラスのクラス名から canned response を引き、
 * sequence 枯渇しない無限供給を提供する (parent::nextResponse() の
 * sequence ベース挙動をオーバーライド)。
 *
 * prompt class の解決経路 (優先順):
 *   1. nextResponse($currentPrompt) に明示渡しされた Prompt インスタンス
 *      (将来 prism-prompt が executePrism() で $this を渡すようになった場合の forward-compat)
 *   2. 直前の record() で記録された prompt class (= 現行 kent013/laravel-prism-prompt
 *      v0.17 の実契約。executePrism()/executePrismStructured() は nextResponse() 直前に
 *      record(static::class, ...) を呼ぶため、record の最新 entry が「今実行中の Prompt」を指す)
 *
 * いずれの経路でも prompt class が得られない場合のみ fail-fast で例外を投げる
 * (= fake 未インストール / record されていない真の misconfiguration)。
 */
final class BrowserPromptFake extends PromptFake
{
    public function __construct(private readonly BrowserCannedResponses $cannedResponses)
    {
        parent::__construct([]);
    }

    /**
     * @param  ?Prompt<mixed>  $currentPrompt
     */
    public function nextResponse(?Prompt $currentPrompt = null): TextResponseFake
    {
        $promptClass = $currentPrompt !== null
            ? $currentPrompt::class
            : $this->latestRecordedPromptClass();

        if ($promptClass === null) {
            throw new RuntimeException(
                'BrowserPromptFake::nextResponse() could not resolve the current Prompt class. '
                .'Neither an explicit $currentPrompt was passed nor a prior record() call exists. '
                .'This indicates a misconfiguration: ensure BrowserPromptFakeRegistrar has installed '
                .'BrowserPromptFake and that Prompt::executePrism() recorded the prompt before requesting '
                .'a fake response.'
            );
        }

        return $this->cannedResponses->forPromptClass($promptClass);
    }

    /**
     * 直前に record() された prompt class を返す (無ければ null)。
     *
     * record() は executePrism()/executePrismStructured() の fake 分岐で
     * nextResponse() の直前に必ず呼ばれるため、最新 entry が現在実行中の Prompt を指す。
     */
    private function latestRecordedPromptClass(): ?string
    {
        $last = end($this->recorded);

        return is_array($last) ? $last['prompt_class'] : null;
    }
}
