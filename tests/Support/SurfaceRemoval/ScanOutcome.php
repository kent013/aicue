<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * 走査結果。**出現**と**未解決**を型上区別する (未解決を空配列へ混ぜない)。
 *
 * ★利用側 gate は `mergeUnresolved()` で**呼んだすべての結果**の未解決を 1 つに集め、
 *   空であることを必ず要求する (AGENTS.md (b) / (d))。
 *
 * @template-covariant TOccurrence of Occurrence|MiddlewareReference|MethodReference
 */
final readonly class ScanOutcome
{
    /**
     * @param  list<TOccurrence>  $occurrences
     * @param  array<string, string>  $unresolved  相対パス => 理由
     */
    public function __construct(
        public array $occurrences,
        public array $unresolved,
    ) {}

    /**
     * 出現の説明行 (gate の失敗メッセージ用)。
     *
     * @return list<string>
     */
    public function descriptions(): array
    {
        return array_values(array_map(
            static fn (Occurrence|MiddlewareReference|MethodReference $o): string => $o->describe(),
            $this->occurrences,
        ));
    }

    /**
     * 複数の走査結果の未解決を 1 つへまとめる。
     *
     * ★集めるだけで判定に使わない出力を作らないため、gate は必ずこの戻り値を
     *   「空であること」の assertion に渡す。
     *
     * @param  list<self<Occurrence|MiddlewareReference|MethodReference>>  $outcomes
     * @return list<string> `相対パス: 理由` の説明行 (昇順)
     */
    public static function mergeUnresolved(array $outcomes): array
    {
        $merged = [];
        foreach ($outcomes as $outcome) {
            foreach ($outcome->unresolved as $relative => $reason) {
                $merged[$relative.': '.$reason] = true;
            }
        }

        $lines = array_keys($merged);
        sort($lines);

        return $lines;
    }
}
