<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\AvatarProviders\Contracts\AvatarProvider;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;

/**
 * 外部 fetch しないローカル initials アバター provider。
 *
 * Filament 既定の `UiAvatarsProvider` は運用者氏名を `ui-avatars.com` へ送出する (閉域運用で表示不可 +
 * privacy)。本 provider は initials を `data:image/svg+xml` に inline して egress を無くす。
 * CSP は `img-src 'self' data:` (config/security.php) で許可済み。
 */
final class LocalInitialsAvatarProvider implements AvatarProvider
{
    // Filament contract は `get(Model $record): string` (union でない。厳密一致)。
    public function get(Model $record): string
    {
        $initials = $this->initials((string) Filament::getNameForDefaultAvatar($record));

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            .'<rect width="64" height="64" fill="#374151"/>'
            .'<text x="32" y="32" dy="0.35em" fill="#FFFFFF" font-family="sans-serif" font-size="28" '
            .'text-anchor="middle">'.$initials.'</text></svg>';

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * 氏名から安全な initials (Unicode 文字/数字の先頭最大 2 字、XML エスケープ) を生成する。空は '?'。
     * 日本語氏名も initials 化できるよう `\p{L}\p{N}` を許容し、記号/空白のみ除去する (カナ化はしない)。
     */
    private function initials(string $name): string
    {
        $clean = preg_replace('/[^\p{L}\p{N}]/u', '', $name) ?? '';
        $initials = mb_strtoupper(mb_substr($clean, 0, 2));

        // SVG の <text> へ inline するため必ず XML エスケープする (\p{L}\p{N} で < > & " は除外済だが契約として明示)。
        return htmlspecialchars($initials === '' ? '?' : $initials, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
