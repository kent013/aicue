<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Str;

/**
 * email 値の canonical 正規化。
 *
 * 用途 (適用範囲を明示限定):
 *   - rate limiter key 生成 (login / register / forgot 等での大文字小文字による throttle bypass 防止)
 *   - email 同値判定 (例: 現在の email と入力された email の比較)
 *
 * 用途外 (= 「raw 値で blind index 生成 / 保存」の既存規約と一致させるため正規化しない):
 *   - `whereBlind('email', 'email_index', $value)` の入力 → raw を渡す
 *   - `users.email` 列への保存 → raw を保存する
 *
 * 設計判断: `Str::transliterate()` は legitimate な Unicode email を別 user に collapse
 * させるリスクがあるため使わない。`trim + 小文字化` の最小正規化に留める。
 */
final class EmailNormalizer
{
    public static function normalize(string $email): string
    {
        $trimmed = trim($email);
        if ($trimmed === '') {
            return '';
        }

        return Str::lower($trimmed);
    }
}
