<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * PHP ソースの静的走査で共有する `token_get_all()` の正規化 (純関数)。
 *
 * ★同じ正規化を 2 本持たない。`QueuedJobLeaseInventoryTest` (既存) と
 *   `ExternalClientBoundaryScanner` (T126) の両方がここを使う。
 * ★Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
 *   `Tests\Support\QueueLeaseConfig` と同じくクラスの static メソッドへ集約する。
 */
final class PhpTokenScan
{
    /**
     * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する。
     *
     * 単一文字トークン (`{` / `}` / `;` など) は `id => null` で表現し、
     * 行番号は直前トークンの行を引き継ぐ (単一文字トークンは行情報を持たないため)。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function normalize(string $phpSource): array
    {
        $normalized = [];
        foreach (token_get_all($phpSource) as $token) {
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
}
