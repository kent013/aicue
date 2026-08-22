<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

/**
 * 作った検体の主キー (cleanup の対象を推測させないために持ち回る)。
 *
 * ★route 名は**持たない**。route 名を決めるのは {@see ConcurrencyProbeRunner} であり、
 *   検体の生成時にはまだ存在しない。掃除は `api_key_id` で足りる
 *   (`idempotency_keys` は cascade 対象)。
 */
final readonly class ConcurrencyFixtureKeys
{
    public function __construct(
        public int $organizationId,
        public int $laratrustTeamId,
        public int $userId,
        public int $apiKeyId,
    ) {}
}
