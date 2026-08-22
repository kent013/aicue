<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 撤去語 (語そのものと一致様式を 1 つにまとめる)。
 *
 * ★語だけを渡す API にしない。様式を語と別に持ち回ると、呼び出し側ごとに
 *   違う様式で同じ語を判定する事故が起きる。
 */
final readonly class RemovedTerm
{
    public function __construct(
        public string $term,
        public TermMatchMode $mode,
    ) {}
}
