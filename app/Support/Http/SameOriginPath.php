<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\Request;

/**
 * 任意の戻り先入力（referer / intended URL）を同一オリジン相対 path のみへ正規化する
 * open-redirect 防御の単一 SoT。ConfirmRecentAuthController / EnsureEmailIsVerifiedOrBack が共用。
 */
final class SameOriginPath
{
    public static function normalize(Request $request, mixed $intended, string $fallback): string
    {
        if (! is_string($intended) || $intended === '') {
            return $fallback;
        }

        // 制御文字 / バックスラッシュ（一部ブラウザが '/' と解釈）を含む入力は拒否。
        if (preg_match('/[\x00-\x1f\\\\]/', $intended) === 1) {
            return $fallback;
        }

        $parts = parse_url($intended);
        if ($parts === false) {
            return $fallback;
        }

        // scheme があるのに host が無い不正入力は拒否。host があれば同一オリジンのみ許可。
        if (isset($parts['scheme']) && ! isset($parts['host'])) {
            return $fallback;
        }
        if (isset($parts['host']) && $parts['host'] !== $request->getHost()) {
            return $fallback;
        }

        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // 絶対 path のみ許可（network-path '//host' は拒否）。
        if (! str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $fallback;
        }

        return $path;
    }
}
