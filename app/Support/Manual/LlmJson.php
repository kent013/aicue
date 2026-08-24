<?php

declare(strict_types=1);

namespace App\Support\Manual;

use App\Enums\Manual\LlmOutputInvalidReason;
use App\Exceptions\Manual\LlmOutputInvalidException;
use JsonException;

/**
 * LLM 応答文字列を構造化データへ直す**唯一の復号点** (家系の正典 v1 の i1〜i6)。
 *
 * ## 受理契約 (厳しい入口が 1 つだけ。緩い入口は持たない)
 *
 *   応答 = PRE OPEN VALUE GAP CLOSE POST
 *     PRE   : 囲みの印を含まない任意の文字列 (前置きの説明文を許す)
 *     OPEN  : 逆引用符 3 個以上の並び + 任意の言語札 [A-Za-z0-9_+.-]*
 *     VALUE : 最上位が入れ物 (object / array) の JSON 値ちょうど 1 つ
 *     GAP   : 空白のみ
 *     CLOSE : 逆引用符 3 個以上の並び (直後に言語札を持たない)
 *     POST  : 囲みの印を含まない任意の文字列 (後書きを許す)
 *
 * - 採るのは**常に最初の囲みの直後の値**である (決定論。同じ応答なら常に同じブロック)。
 * - 囲みの印が**ブロックの外にもう 1 つ**あれば受け取らない (差し込みを採らない)。
 * - 囲みの印は「行」ではなく「連続 3 個以上の逆引用符の並び」である。応答データの中に
 *   現れた印を終端に数えないのは、**構造の走査で決まった値の区間の外側だけを数える**
 *   ことで担保する。
 *
 * ## 区分の決定順序 (単一パスの到達順 = 複合不正の優先順位。正本はこの表)
 *
 * | #  | 判定                                                      | 区分                        |
 * |----|-----------------------------------------------------------|-----------------------------|
 * | 1  | 囲みの印が 1 つも無い                                     | `FenceAbsent`               |
 * | 2  | 開きの印 + 言語札の先が空白のみで終端                     | `ValueIncompleteInferred`   |
 * | 3  | その先の最初の非空白が囲みの印 (空のブロック)             | `TopLevelNotContainer`      |
 * | 4  | その先の最初の非空白が `{` でも `[` でもない              | `TopLevelNotContainer`      |
 * | 5a | 構造の走査が期待と異なる閉じ括弧に遭遇                    | `SyntaxBroken`              |
 * | 5b | 構造の走査が深さ 0 に戻らずに終端                         | `ValueIncompleteInferred`   |
 * | 6  | 値の終端の後、空白を飛ばした先が終端                      | `ClosingFenceAbsent`        |
 * | 7  | 値の終端の後の印の直後に言語札がある (別ブロックの開き)   | `FenceMultiple`             |
 * | 8  | 値の終端の後が印でもなく非空白 (余剰トークン)             | `SyntaxBroken`              |
 * | 9  | 閉じの印より後にさらに囲みの印がある                      | `FenceMultiple`             |
 * | 10 | 切り出した値の `json_decode` が `JsonException`           | `SyntaxBroken`              |
 * | 11 | `json_decode` の結果が配列でない (4 で落ちるので到達不能) | `TopLevelNotContainer`      |
 *
 * ## 走査器の責務 (誇張しない)
 *
 * `scanValueEnd()` が行うのは「**最初の JSON 値の終端候補を特定する**」ことだけである。
 * 値が JSON として妥当かは判定せず、それは `json_decode(..., JSON_THROW_ON_ERROR)` に委譲する
 * (自前パーサへ膨らませて `json_decode()` と判定が食い違う状態を作らない)。
 *
 * ## 保証しないもの
 *
 * - 逆引用符の**個数の対応**は見ない (開き 4 個 / 閉じ 3 個も対応が取れているとみなす)。
 * - **scalar の厳密な識別はしない**。分類は「値の開始文字が `{` / `[` か」だけで決めるので、
 *   札の形をした scalar (三連引用符の直後の `null` / `42`) は言語札として消費され、
 *   `TopLevelNotContainer` / `ValueIncompleteInferred` へ落ちる。
 * - 走査はバイト単位である (対象文字はすべて ASCII で、UTF-8 の後続バイトと衝突しない)。
 * - 受理文法の GAP / 前後の「空白」は **JSON の空白 4 種 (SP / HT / LF / CR) だけ**である
 *   (Unicode の空白類 — 全角空白・NBSP 等 — は空白として扱わない = 余剰トークンになる)。
 * - PRE の説明文に偶然 3 連の逆引用符が現れると、そこが OPEN になり以降で拒否される。
 *   同様に、閉じの印の直後に言語札の字種が続く後書き (`\`\`\`end`) は別ブロックの開きとみなす。
 *   いずれも**誤って受理する側には倒れない** (fail-closed 方向の誤り)。
 * - 応答の**切り詰めの断定はしない** (`ValueIncompleteInferred` は推定。正本は
 *   `llm_call_logs.finish_reason`)。
 *
 * 不正は `LlmOutputInvalidException` (有界リトライのトリガー。§10.7-2)。
 */
final class LlmJson
{
    /** 囲みの印の最小形 (逆引用符 3 個)。 */
    private const string FENCE = '```';

    /** 開きの印の直後に許す言語札の字種 (これ以外が来たら札は空と解釈する)。 */
    private const string TAG_CHARS = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_+.-';

    /**
     * 囲みちょうど 1 つの応答から最上位が入れ物の JSON 値を取り出す。
     *
     * @return array<array-key, mixed>
     *
     * @throws LlmOutputInvalidException 受理契約に合わない (区分は docblock の表のとおり)
     */
    public static function decode(string $text): array
    {
        $length = strlen($text);

        // (1) 最初の囲みの印 = OPEN。無ければ素の JSON も含めて拒否する
        $open = self::findFence($text, 0);
        if ($open === null) {
            throw self::reject(LlmOutputInvalidReason::FenceAbsent);
        }

        // (2) 言語札を貪欲に読み飛ばし、値の開始位置を決める
        $start = self::skipWhitespace($text, self::skipTag($text, $open));
        if ($start >= $length) {
            throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
        }
        if (self::isFenceAt($text, $start)) {
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer); // 空のブロック
        }
        if ($text[$start] !== '{' && $text[$start] !== '[') {
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
        }

        // (3) 構造の走査で値の終端を決める (括弧の対応 + 文字列と打ち消しの解釈)
        $valueEnd = self::scanValueEnd($text, $start);

        // (4) 値の後は 空白 → 閉じの印 → (印を含まない後書き) だけを許す
        $after = self::skipWhitespace($text, $valueEnd);
        if ($after >= $length) {
            throw self::reject(LlmOutputInvalidReason::ClosingFenceAbsent);
        }
        if (! self::isFenceAt($text, $after)) {
            throw self::reject(LlmOutputInvalidReason::SyntaxBroken); // ブロック内の余剰トークン
        }
        $closeEnd = self::skipBackticks($text, $after);
        if ($closeEnd < $length && str_contains(self::TAG_CHARS, $text[$closeEnd])) {
            // 閉じの印ではなく別ブロックの開きだった (i3: 開始の印を閉じと読み違えない)
            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
        }
        if (self::findFence($text, $closeEnd) !== null) {
            throw self::reject(LlmOutputInvalidReason::FenceMultiple);
        }

        // (5) 妥当性は json_decode に委譲する
        try {
            /** @var mixed $decoded */
            $decoded = json_decode(substr($text, $start, $valueEnd - $start), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
        }
        if (! is_array($decoded)) {
            // (2) が `{` / `[` を確認済みなので到達しない。多重防御として残す
            throw self::reject(LlmOutputInvalidReason::TopLevelNotContainer);
        }

        return $decoded;
    }

    /**
     * スキーマ違反の例外を生成する (DTO 検証用の短縮形)。
     * $path は観測用の違反位置 (例: validation.works.2)。省略時は null。
     */
    public static function schemaViolation(string $detail, ?string $path = null): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException(LlmOutputInvalidReason::SchemaViolation, $detail, $path);
    }

    /** 区分ごとの固定文だけを載せた失効の例外 (応答本文を載せない = i9)。 */
    private static function reject(LlmOutputInvalidReason $reason): LlmOutputInvalidException
    {
        return new LlmOutputInvalidException($reason, $reason->detail());
    }

    /**
     * 最初の JSON 値の**終端候補**を返す (終端の次の位置)。
     *
     * ★括弧の対応は**期待する閉じ括弧のスタック**で追う (深さの数だけでは `{"a":[}` を
     *   終端候補まで通してしまう)。最初の不整合で確定し、走査は継続しない。
     * ★走査は最初の値が完結した時点で終わる。したがって `{"a":1}}` の 2 つ目の `}` は
     *   走査中の不整合ではなく「値の後の余剰トークン」として (4) が `SyntaxBroken` にする。
     *
     * @throws LlmOutputInvalidException `SyntaxBroken` (括弧の不整合) /
     *                                   `ValueIncompleteInferred` (完結しないまま終端)
     */
    private static function scanValueEnd(string $text, int $start): int
    {
        $length = strlen($text);
        /** @var list<string> $expected 期待する閉じ括弧 */
        $expected = [];
        $inString = false;
        $escaped = false;

        for ($i = $start; $i < $length; $i++) {
            $char = $text[$i];

            if ($inString) {
                if ($escaped) {
                    $escaped = false;

                    continue;
                }
                if ($char === '\\') {
                    $escaped = true;

                    continue;
                }
                if ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;

                continue;
            }
            if ($char === '{') {
                $expected[] = '}';

                continue;
            }
            if ($char === '[') {
                $expected[] = ']';

                continue;
            }
            if ($char !== '}' && $char !== ']') {
                continue;
            }

            if (array_pop($expected) !== $char) {
                throw self::reject(LlmOutputInvalidReason::SyntaxBroken);
            }
            if ($expected === []) {
                return $i + 1;
            }
        }

        throw self::reject(LlmOutputInvalidReason::ValueIncompleteInferred);
    }

    /** $from 以降の最初の囲みの印の開始位置 (無ければ null)。 */
    private static function findFence(string $text, int $from): ?int
    {
        $position = strpos($text, self::FENCE, $from);

        return $position === false ? null : $position;
    }

    private static function isFenceAt(string $text, int $position): bool
    {
        return substr($text, $position, strlen(self::FENCE)) === self::FENCE;
    }

    /** 印の逆引用符の並びを読み飛ばした位置 (3 個以上を 1 つの印として扱う)。 */
    private static function skipBackticks(string $text, int $position): int
    {
        $length = strlen($text);
        $cursor = $position;
        while ($cursor < $length && $text[$cursor] === '`') {
            $cursor++;
        }

        return $cursor;
    }

    /** 開きの印 + 言語札を読み飛ばした位置。 */
    private static function skipTag(string $text, int $fencePosition): int
    {
        $length = strlen($text);
        $cursor = self::skipBackticks($text, $fencePosition);
        while ($cursor < $length && str_contains(self::TAG_CHARS, $text[$cursor])) {
            $cursor++;
        }

        return $cursor;
    }

    /** JSON の空白 4 種 (SP / HT / LF / CR) だけを読み飛ばした位置。 */
    private static function skipWhitespace(string $text, int $position): int
    {
        $length = strlen($text);
        $cursor = $position;
        while ($cursor < $length && ($text[$cursor] === ' ' || $text[$cursor] === "\t"
            || $text[$cursor] === "\n" || $text[$cursor] === "\r")) {
            $cursor++;
        }

        return $cursor;
    }
}
