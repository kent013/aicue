<?php

declare(strict_types=1);

namespace App\Support\Llm;

use App\Exceptions\Llm\UntrustedInputRejectedException;

/**
 * untrusted 文字列の構造的な無害化 (裁定 AG-028 の「入力の無害化」)。
 *
 * 扱うのは**構造だけ** (制御文字・不可視文字・長さ):
 *  - 保持: 改行 / タブ / 通常の空白 (SOP の本文構造そのもの)
 *  - 改行へ正規化: CR (単独 / CRLF) / U+2028 / U+2029 (行の区切りという意味は保つ)
 *  - 除去: その他の C0 / C1 / 双方向制御 / ゼロ幅 / BOM
 *          (人間には見えないのにモデルには渡る = 見えない指示の運び手になる)
 *  - 拒否: 上限超過 / 不正な UTF-8 (切り詰めると黙って内容が変わるため拒否で扱う)
 *
 * ★ **「ignore previous instructions」等の文言は除去しない**。偽陰性と回避のいたちごっこになり、
 *   正当な SOP 本文 (「前の指示は破棄する」という作業手順) を壊す。
 *   分類表の正本は devnotes の prompt-injection-defense 詳細設計 §B である。
 */
final class UntrustedTextSanitizer
{
    /** 改行へ正規化する区切り (CRLF → LF を先に畳む)。 */
    private const array LINE_BREAKS = ["\r\n", "\r", "\u{2028}", "\u{2029}"];

    /** 除去する不可視文字 (C0 の一部 / C1 / ゼロ幅 / 双方向制御 / BOM)。 */
    private const string REMOVE_PATTERN = '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}'
        .'\x{0080}-\x{009F}\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]/u';

    /**
     * @throws UntrustedInputRejectedException 長さ超過 / 不正な UTF-8 (どちらも切り詰めない)
     */
    public static function sanitize(string $value): SanitizedText
    {
        $normalized = str_replace(self::LINE_BREAKS, "\n", $value);

        // 除去**対象だけ**を数える (改行正規化は件数に含めない = ログの意味を
        // 「不可視文字を n 文字除去した」に限定する)。
        $removedCount = preg_match_all(self::REMOVE_PATTERN, $normalized);
        $sanitized = preg_replace(self::REMOVE_PATTERN, '', $normalized);
        if ($removedCount === false || ! is_string($sanitized)) {
            // 不正な UTF-8。素通しせず拒否する (fail-closed)。
            throw UntrustedInputRejectedException::invalidEncoding();
        }

        $limit = config()->integer('llm-defense.max_untrusted_bytes');
        $actual = strlen($sanitized);
        if ($actual > $limit) {
            throw UntrustedInputRejectedException::tooLarge($actual, $limit);
        }

        return new SanitizedText($sanitized, $removedCount);
    }
}
