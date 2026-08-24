<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

/**
 * `executeSync()` の呼び出し点 1 件の走査結果 (解決状態つき)。
 *
 * ★`factory` / `enclosingCall` は**解決できたときだけ**値を持つ。解決できない形は
 *   `resolution === Unresolved` で表し、利用側 gate が失敗させる (共通規約 (b))。
 */
final readonly class LlmResponseSeamFinding
{
    public function __construct(
        public string $path,
        public int $line,
        public LlmResponseSeamResolution $resolution,
        /** 直前の `X::make(...)` の `X` の完全修飾名 (解決できたときだけ)。 */
        public ?string $factory,
        /**
         * この呼び出しを直接の引数として囲む静的呼び出し `{FQCN}::{method}`
         * (囲みが `名前トークン :: メソッド名 (` の形でないときは null)。
         */
        public ?string $enclosingCall,
    ) {}

    /** 失敗メッセージ用の位置表現。 */
    public function location(): string
    {
        return $this->path.':'.$this->line;
    }
}
