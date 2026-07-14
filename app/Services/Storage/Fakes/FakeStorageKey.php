<?php

declare(strict_types=1);

namespace App\Services\Storage\Fakes;

/**
 * fake storage signed route の key 検証ヘルパ (多層防御。署名前提でも横断読取/書込面積を縮小する)。
 */
final class FakeStorageKey
{
    /**
     * 許可 key の segment 単位検証 (単純 str_contains('..') は誤検知するため segment 分割で判定):
     * - 先頭 segment は 'projects'
     * - segment 数 >= 2
     * - 各 segment: 空でない / `.`・`..` でない / `\`・NUL を含まない
     */
    public static function isAllowed(string $key): bool
    {
        if (! str_starts_with($key, 'projects/')) {
            return false;
        }
        $segments = explode('/', $key);
        if (count($segments) < 2) {
            return false;
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
            if (str_contains($segment, '\\') || str_contains($segment, "\0")) {
                return false;
            }
        }

        return true;
    }
}
