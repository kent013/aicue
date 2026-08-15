<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * bfcache 秘匿・再検証 guard (resources/js/lib/bfcache-guard.ts) の軽量プローブ応答 DTO。
 *
 * セッションが「今も有効か」だけを伝える最小 DTO。recent-auth (step-up 鮮度) とは
 * 意味が異なるため RecentAuthStatusDto を流用しない。PII / 権限 / 組織情報は載せない
 * (bfcache 復元直後の未検証状態で叩かれる endpoint であり、露出面を最小に保つ)。
 */
final readonly class SessionStatusDto
{
    public function __construct(
        public bool $authenticated,
        /** 要求が運んだ描画世代が、現在のセッションの世代と一致するか。 */
        public bool $sessionEpochMatches,
    ) {}
}
