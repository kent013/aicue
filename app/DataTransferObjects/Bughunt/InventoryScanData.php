<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Bughunt;

/**
 * 抽出コマンド (bughunt:inventory-scan) の出力全体。
 *
 * `schema_version` と `extraction_condition` は生成器が受け取り側で照合する
 * (どちらかが食い違ったら致命として落ちる = 母集合が黙って変わることを防ぐ)。
 */
final readonly class InventoryScanData
{
    public const SCHEMA_VERSION = 1;

    /** 抽出条件のラベル。環境名ではない (local 実行と Pest 実行で同一になる)。 */
    public const EXTRACTION_CONDITION = 'local-or-unit-tests';

    /** @param  list<InventoryRouteData>  $routes */
    public function __construct(public array $routes) {}

    /**
     * @return array{
     *   schema_version: int,
     *   extraction_condition: non-empty-string,
     *   routes: list<array{
     *     name: string|null,
     *     uri: string,
     *     methods: list<non-empty-string>,
     *     middleware: list<non-empty-string>,
     *     action: string|null,
     *     title: string|null
     *   }>
     * }
     */
    public function toArray(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'extraction_condition' => self::EXTRACTION_CONDITION,
            'routes' => array_map(
                static fn (InventoryRouteData $route): array => $route->toArray(),
                $this->routes,
            ),
        ];
    }
}
