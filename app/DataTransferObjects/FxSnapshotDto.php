<?php

declare(strict_types=1);

namespace App\DataTransferObjects;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Support\Arrayable;
use Webmozart\Assert\Assert;

/**
 * 為替レートのスナップショット (llm_call_logs.fx_snapshot に JSON round-trip する)。
 * 後からレートが変わっても過去のコスト記録が変化しないよう、取得時点の値を保持する。
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FxSnapshotDto implements Arrayable
{
    public function __construct(
        public float $rate,
        public string $pair,
        public string $source,
        public CarbonImmutable $fetchedAt,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rate' => $this->rate,
            'pair' => $this->pair,
            'source' => $this->source,
            'fetched_at' => $this->fetchedAt->toIso8601String(),
        ];
    }

    /**
     * cache 等から復元する。欠損 / 不正値は Assert で例外化する
     * (呼び出し側 FxRateService が catch して cache を破棄する)。
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        Assert::keyExists($data, 'rate');
        Assert::keyExists($data, 'pair');
        Assert::keyExists($data, 'source');
        Assert::keyExists($data, 'fetched_at');
        Assert::numeric($data['rate']);
        Assert::greaterThan($data['rate'], 0);
        Assert::stringNotEmpty($data['pair']);
        Assert::stringNotEmpty($data['source']);
        Assert::stringNotEmpty($data['fetched_at']);

        return new self(
            rate: (float) $data['rate'],
            pair: (string) $data['pair'],
            source: (string) $data['source'],
            fetchedAt: CarbonImmutable::parse((string) $data['fetched_at']),
        );
    }
}
