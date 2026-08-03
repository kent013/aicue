<?php

declare(strict_types=1);

namespace Tests\Support;

use Closure;
use Kent013\PrismPrompt\Testing\PromptFake;
use Kent013\PrismPrompt\Testing\TextResponseFake;
use RuntimeException;
use Throwable;

/**
 * 例外を投げられる PromptFake。AnalysisPipeline の transient 例外リトライと
 * deadline 打ち切りを決定論的に検証するために使う。
 *
 * `Prompt::installFake()` (パッケージの公開注入点。CannedPromptFake と同じ経路) で差し込む。
 * script に Throwable を混ぜると、その順番の呼び出しで throw する。
 *
 * 時計操作は **このクラスでは行わない**。$onAttempt (呼び出しごとに実行される closure) を
 * テスト側から渡し、テストクラスの `$this->travel(...)` を呼ばせる
 * (Carbon のグローバル状態を Support クラスへ漏らさない / 実時間に依存させない)。
 */
final class ThrowingPromptFake extends PromptFake
{
    private int $index = 0;

    /**
     * @param  list<TextResponseFake|Throwable>  $script
     * @param  ?Closure(int):void  $onAttempt  試行ごとに呼ばれる (引数 = 1 始まりの試行番号)
     */
    public function __construct(
        private readonly array $script,
        private readonly ?Closure $onAttempt = null,
    ) {
        parent::__construct([]);
    }

    public function nextResponse(): TextResponseFake
    {
        $item = $this->script[$this->index] ?? throw new RuntimeException(
            'ThrowingPromptFake: script を使い切りました (想定より多く LLM が呼ばれています)'
        );
        $this->index++;

        if ($this->onAttempt !== null) {
            ($this->onAttempt)($this->index);
        }

        if ($item instanceof Throwable) {
            throw $item;
        }

        return $item;
    }

    /** 実際に LLM 呼び出しが試行された回数 */
    public function attemptCount(): int
    {
        return $this->index;
    }
}
