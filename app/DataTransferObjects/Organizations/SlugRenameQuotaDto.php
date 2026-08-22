<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Organizations;

use Carbon\CarbonImmutable;

/**
 * 識別名の変更に関する**画面表示のための**残り回数 (家系裁定 AG-046)。
 *
 * ★**権威ではない**。最終判定は組織行を行ロックした後の再判定であり、
 *   ここに出る数字は表示のための早期情報にすぎない。
 * ★上限に達していてもボタンは disabled にしない (AGENTS.md 禁止事項 8)。
 *   押下時にサーバが 422 を返して理由を示す。
 */
final readonly class SlugRenameQuotaDto
{
    public function __construct(
        public int $remaining,
        public ?CarbonImmutable $nextAvailableAt,
    ) {}

    /**
     * @return array{remaining: int, nextAvailableAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'remaining' => $this->remaining,
            'nextAvailableAt' => $this->nextAvailableAt?->toIso8601String(),
        ];
    }
}
