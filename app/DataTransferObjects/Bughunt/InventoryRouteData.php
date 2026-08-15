<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Bughunt;

/**
 * bug-hunt 目録の機械事実 1 件 (= route オブジェクト 1 件)。
 *
 * **判定を持たない**。面 (web セッション面かどうか) の判定・表の分割・除外は
 * すべて生成器 (scripts/bug-hunt-inventory.py) の責務で、ここは事実を写すだけである
 * (同じ規則を PHP と Python の 2 か所に置かない)。
 */
final readonly class InventoryRouteData
{
    /**
     * @param  list<non-empty-string>  $methods  HEAD を含む宣言どおりの HTTP メソッド
     * @param  list<non-empty-string>  $middleware  宣言のままの middleware (group 名 `web` を含みうる)
     */
    public function __construct(
        public ?string $name,
        public string $uri,
        public array $methods,
        public array $middleware,
        public ?string $action,
        public ?string $title,
    ) {}

    /**
     * @return array{
     *   name: string|null,
     *   uri: string,
     *   methods: list<non-empty-string>,
     *   middleware: list<non-empty-string>,
     *   action: string|null,
     *   title: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'uri' => $this->uri,
            'methods' => $this->methods,
            'middleware' => $this->middleware,
            'action' => $this->action,
            'title' => $this->title,
        ];
    }
}
