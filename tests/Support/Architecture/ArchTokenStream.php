<?php

declare(strict_types=1);

namespace Tests\Support\Architecture;

use ParseError;
use RuntimeException;

/**
 * Pest arch ベースラインの 3 走査器が共有する**トークン列の正規化** (純関数)。
 *
 * ★**同じ正規化を 3 本持たない**ための型である (AGENTS.md §本リポジトリでの置き方の
 *   「同じ列挙を 2 本持たない」と同じ理由)。`GlobalFunctionCallScanner` /
 *   `ArchSurfaceScanner` / `VendorArchPresetReader` はすべてここを通る。
 *
 * ★**既存の `Tests\Support\PhpTokenScan::normalize()` を使わない理由**:
 *   あちらは `token_get_all($source)` (フラグなし) で、**不正な PHP を黙って
 *   トークン列として返す**。本ベースラインの走査器は「解決できない形は落とす」
 *   (AGENTS.md 共通規約 (b)) を満たす必要があり、`TOKEN_PARSE` + `ParseError` の
 *   例外変換が**契約の一部**である。既存の利用側 (2 gate) の挙動を変えずに
 *   fail-closed を得るため、正規化をこちら側に 1 本置く。
 *
 * ★**保証しないもの**: `.blade.php` や PHP 開始タグの外側 (inline HTML) は
 *   `T_INLINE_HTML` として素通しする。走査器はそこを判定に使わない。
 */
final class ArchTokenStream
{
    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * `token_get_all($source, TOKEN_PARSE)` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`(` / `{` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * ★**トークン化できない入力は無言で空を返さず例外**にする (fail-closed)。
     *
     * @param  string  $context  例外メッセージに載せる呼び出し元の文脈
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function significantTokens(string $source, string $context): array
    {
        try {
            $rawTokens = token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException(
                "{$context}: PHP ソースをトークン化できない (TOKEN_PARSE): ".$error->getMessage(),
                previous: $error,
            );
        }

        $normalized = [];
        foreach ($rawTokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
            $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $normalized;
    }

    /**
     * 指定位置のトークンが「単一文字トークンで綴りが `$text`」か。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function isPunctuation(array $tokens, int $index, string $text): bool
    {
        $token = $tokens[$index] ?? null;

        return $token !== null && $token['id'] === null && $token['text'] === $text;
    }
}
