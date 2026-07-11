<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Marketing;

/**
 * LP (トップ `/`) の Inertia page prop (単一真実源の shape 定義)。
 *
 * TS 側は resources/js/types/marketing.ts の LandingPageProps と exact 対で保守する。
 *
 * @phpstan-type LandingPageShape array{
 *   signupGrantTickets: int,
 *   contactUrl: string,
 *   contactIsExternal: bool,
 *   isAuthenticated: bool
 * }
 */
final readonly class LandingPageDto
{
    public function __construct(
        public int $signupGrantTickets,
        public string $contactUrl,
        public bool $contactIsExternal,
        public bool $isAuthenticated,
    ) {}

    /**
     * @return LandingPageShape
     */
    public function toArray(): array
    {
        return [
            'signupGrantTickets' => $this->signupGrantTickets,
            'contactUrl' => $this->contactUrl,
            'contactIsExternal' => $this->contactIsExternal,
            'isAuthenticated' => $this->isAuthenticated,
        ];
    }
}
