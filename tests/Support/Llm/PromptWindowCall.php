<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/**
 * factory から窓口へ渡された引数の静的な読み取り結果。
 *
 * `template` / `untrusted` が**リテラルとして読めなかった**場合は null になり、
 * gate はそれ自体を違反として扱う (動的に組み立てて静的検査を無効化させない)。
 */
final readonly class PromptWindowCall
{
    /**
     * @param  'load'|'loadUnattributed'|'loadWithMedia'  $method
     * @param  list<string>|null  $untrustedKeys  キーがすべて文字列リテラルの配列リテラルなら鍵一覧、そうでなければ null
     */
    public function __construct(
        public string $path,
        public int $line,
        public string $method,
        public ?string $template,
        public ?array $untrustedKeys,
    ) {}
}
