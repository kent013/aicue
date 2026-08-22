<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

/**
 * runner の結果。
 *
 * ★nonce / go token は**持たない**。同一性の検査 (`assertIdentity`) は runner の中で
 *   完結しており、内部プロトコルをテストへ漏らさない。
 * ★代わりに、行の裏取り (`idempotency_keys` のスコープと request_hash) に要る値だけを渡す。
 */
final readonly class ConcurrentProbeResult
{
    /**
     * @param  array<string, ConcurrentProbeObservation>  $observations  childId => 観測
     */
    public function __construct(
        public array $observations,
        public string $routeName,
        public string $uri,
        public string $idempotencyKey,
        /** 親が middleware と同一規則で計算した期待 hash */
        public string $expectedRequestHash,
    ) {}

    /**
     * `entered_handler` で勝者・敗者に分ける (ちょうど 1:1 でなければ例外)。
     *
     * @return array{ConcurrentProbeObservation, ConcurrentProbeObservation} [勝者, 敗者]
     *
     * @throws ConcurrencyProtocolException
     */
    public function partition(): array
    {
        $winners = [];
        $losers = [];

        foreach ($this->observations as $observation) {
            if ($observation->enteredHandler) {
                $winners[] = $observation;

                continue;
            }

            $losers[] = $observation;
        }

        if (count($winners) !== 1 || count($losers) !== 1) {
            throw ConcurrencyProtocolException::unexpectedObservation(sprintf(
                '勝者・敗者が 1:1 に分かれない (勝者 %d 件 / 敗者 %d 件)',
                count($winners),
                count($losers),
            ));
        }

        return [$winners[0], $losers[0]];
    }
}
