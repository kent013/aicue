<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Http;

use App\Enums\Http\InertiaErrorScreenStatus;
use App\Support\Http\ErrorScreenDestination;
use Webmozart\Assert\Assert;

/**
 * Error ページ (resources/js/pages/Error.svelte) の props。
 *
 * **props 生成の唯一の入口**は toInertiaProps()。呼び出し側が配列を手組みしないこと
 * (TS 側 resources/js/types/error-screen.ts と 1:1 で保守する)。
 *
 * 共有 props (HandleInertiaRequests::share) には依存しない。例外はテナント guard 404 のように
 * middleware が走る前にも起きるため、Error 画面が必要とする値はすべてここに入れる。
 *
 * @phpstan-type ErrorScreenPropsShape array{
 *   status: int,
 *   title: string,
 *   message: string,
 *   retryAfterSeconds: int<0, max>|null,
 *   destinations: non-empty-list<array{label: string, href: string}>
 * }
 */
final readonly class ErrorScreenData
{
    /**
     * @param  int<0, max>|null  $retryAfterSeconds
     * @param  non-empty-list<ErrorScreenDestination>  $destinations
     */
    public function __construct(
        public InertiaErrorScreenStatus $status,
        public ?int $retryAfterSeconds,
        public array $destinations,
    ) {
        // 型 (non-empty-list) は静的な約束にすぎないため、実行時にも空を拒否する。
        // 戻り先ゼロの Error 画面は「押せる導線が無い画面」= 詰みそのもの (禁止事項 8 の精神)。
        Assert::minCount($destinations, 1, 'Error 画面の戻り先は 1 件以上必要です');
    }

    /** @return ErrorScreenPropsShape */
    public function toInertiaProps(): array
    {
        return [
            'status' => $this->status->value,
            'title' => $this->status->title(),
            'message' => $this->status->message(),
            'retryAfterSeconds' => $this->retryAfterSeconds,
            'destinations' => array_map(
                static fn (ErrorScreenDestination $destination): array => $destination->toArray(),
                $this->destinations,
            ),
        ];
    }
}
