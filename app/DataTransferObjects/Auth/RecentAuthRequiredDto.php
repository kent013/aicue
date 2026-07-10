<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Auth;

/**
 * recent-auth 鮮度切れ時、XHR (expectsJson) / Inertia mutation に返す 409 ボディ。
 * クライアントはこの `redirect` を使って再認証 (モーダル or 画面遷移) を開始する。
 */
final readonly class RecentAuthRequiredDto
{
    /**
     * 409 契約の判別子。クライアント側は status だけでなく code 厳格一致で
     * 自分宛ての応答のみ処理する (他の 409 契約との誤食防止)。
     */
    public const string CODE = 'recent_auth_required';

    public function __construct(
        public string $message,
        public string $redirect,
    ) {}
}
