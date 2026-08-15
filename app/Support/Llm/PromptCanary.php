<?php

declare(strict_types=1);

namespace App\Support\Llm;

use LogicException;
use Random\RandomException;

/**
 * 応答カナリア (裁定 AG-028 の「応答カナリアによる乗っ取り検知」)。
 *
 * system prompt にだけ載せた合言葉が応答に現れたら、モデルが system 側の内容を
 * そのまま吐いた = 乗っ取り / 漏洩が起きたとみなして応答を捨てる。
 *
 * ★ 保証範囲 (誇張しない): これは**漏洩の検知**であって、プロンプトインジェクション一般の
 *   検出器ではない。JSON として妥当な悪性シナリオは検知できない。
 */
final readonly class PromptCanary
{
    private function __construct(public string $token) {}

    /**
     * ★ 設定値が 1 バイト未満なら**合言葉なしで prompt を組み立てず**例外にする (fail-closed)。
     *   空文字の合言葉は `str_contains()` が常に true になり、逆に全応答を拒否してしまう。
     *
     * @throws RandomException 乱数源が利用できない (fail-closed。合言葉なしの prompt を作らない)
     * @throws LogicException 合言葉の長さ設定が 1 バイト未満
     */
    public static function generate(): self
    {
        $bytes = config()->integer('llm-defense.canary_bytes');
        if ($bytes < 1) {
            throw new LogicException('llm-defense.canary_bytes は 1 以上でなければなりません');
        }

        return new self(bin2hex(random_bytes($bytes)));
    }

    /**
     * 応答に合言葉が含まれるか。大小無視 + 空白除去の 2 パスで見る。
     *
     * ★ 合言葉は ASCII の hex なので、空白除去は **Unicode モードを使わずバイト列**として行う
     *   (`/u` を付けると不正な UTF-8 の応答で preg が false を返し、
     *    「空白で分割された合言葉 + 不正バイト」の応答が**漏洩なし扱い (fail-open)** になる)。
     * ★ それでも正規化に失敗したら**漏洩ありとみなす** (fail-closed)。
     * ★ 検知の限界: 非空白文字を挟んだ分割 (`ab-cd…`) は検出しない。
     *   完全な検出器ではないことは docs/architecture.md にも書いてある。
     */
    public function leakedIn(string $response): bool
    {
        $needle = strtolower($this->token);
        $haystack = strtolower($response);
        if (str_contains($haystack, $needle)) {
            return true;
        }

        $withoutSpaces = preg_replace('/[[:space:]]+/', '', $haystack);
        if (! is_string($withoutSpaces)) {
            return true; // 正規化できない応答は安全側に倒す
        }

        return str_contains($withoutSpaces, $needle);
    }
}
