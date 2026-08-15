<?php

declare(strict_types=1);

namespace Tests\Support\TemplateDivergence;

use Webmozart\Assert\Assert;

/**
 * 逸脱の登録簿 (`docs/template-divergence.md`) の Markdown を解析する (純関数)。
 *
 * 解析器は**取り出すだけ**で、値の妥当性は `DivergenceLedgerRules` が見る。
 * 読み解けなかったこと (囲みが閉じない / 登録エントリ領域が無い) は
 * 違反として返し、空集合へ落として緑にする経路は持たない (fail-closed)。
 *
 * Markdown の囲み文法を全部は実装しない。台帳で使ってよい囲みは**行頭のバッククォート
 * 3 個ちょうど**だけで、バッククォート 4 個以上と `~~~` は**明示的に違反**にする
 * (黙って読み飛ばすと、その囲みで登録を隠せる回避口になる)。
 */
final class DivergenceLedgerParser
{
    /**
     * 登録メタ表のラベル (規定の順序)。過不足・順序違いは「台帳を解釈できない」= 不合格。
     *
     * @var list<string>
     */
    public const META_LABELS = [
        '対象パス',
        '業務要件起因の説明',
        '揃え続ける不変条件と保証機構',
        '再判定の条件',
        '決めた日',
        '決めた人',
        '根拠',
        '状態',
        '見直し期限',
    ];

    /** 登録の見出しの正準形 (行全体一致)。 */
    private const ENTRY_HEADING = '/^## D([1-9]\d*) (\S.*)$/u';

    /** 件数の明示行。 */
    private const DECLARED_COUNT = '/^登録エントリ: (\d+) 件$/u';

    public static function parse(string $markdown): ParsedLedger
    {
        $scan = self::outsideFenceLines($markdown);
        $violations = $scan['violations'];

        if ($scan['unclosed']) {
            $violations[] = 'P1: 囲みコード区画が閉じていない (解析不能)。囲みは行頭のバッククォート 3 個ちょうどで開閉する';

            return new ParsedLedger([], null, $violations, true);
        }

        $lines = $scan['lines'];

        $declared = null;
        $declaredHits = 0;
        foreach ($lines as $line) {
            if (preg_match(self::DECLARED_COUNT, $line[1], $matches) === 1) {
                $declaredHits++;
                $declared = (int) $matches[1];
            }
        }
        if ($declaredHits !== 1) {
            $violations[] = sprintf(
                'TD12: 件数の明示行「登録エントリ: N 件」は囲みの外にちょうど 1 本必要 (実測 %d 本)',
                $declaredHits,
            );
            $declared = null;
        }

        $regionStart = null;
        foreach ($lines as $index => $line) {
            if (str_starts_with($line[1], '## D')) {
                $regionStart = $index;
                break;
            }
        }

        if ($regionStart === null) {
            $violations[] = 'P2: 登録エントリ領域 (最初の `## D<n>` 見出し) が見つからない (解析不能)';

            return new ParsedLedger([], $declared, $violations, true);
        }

        /** @var list<int> $headingIndexes */
        $headingIndexes = [];
        $total = count($lines);
        for ($index = $regionStart; $index < $total; $index++) {
            if (str_starts_with($lines[$index][1], '## ')) {
                $headingIndexes[] = $index;
            }
        }

        /** @var list<ParsedEntry> $entries */
        $entries = [];
        /** @var array<int, int> $seenIds id => 初出の行番号 */
        $seenIds = [];

        foreach ($headingIndexes as $position => $headingIndex) {
            [$lineNumber, $headingText] = $lines[$headingIndex];

            if (preg_match(self::ENTRY_HEADING, $headingText, $matches) !== 1) {
                $violations[] = sprintf(
                    'TD1: %d 行目の見出しが正準形 `## D<n> <要約>` ではない: %s',
                    $lineNumber,
                    $headingText,
                );

                continue;
            }

            $id = (int) $matches[1];
            $summary = $matches[2];

            foreach (self::forbiddenSummaryReasons($summary) as $reason) {
                $violations[] = sprintf('TD1: D%d (%d 行目) の要約は%s', $id, $lineNumber, $reason);
            }

            if (isset($seenIds[$id])) {
                $violations[] = sprintf(
                    'TD1: D%d が重複している (%d 行目と %d 行目)。番号はリポジトリ内で一意',
                    $id,
                    $seenIds[$id],
                    $lineNumber,
                );
            } else {
                $seenIds[$id] = $lineNumber;
            }

            $bodyEnd = $headingIndexes[$position + 1] ?? $total;
            $body = array_slice($lines, $headingIndex + 1, $bodyEnd - $headingIndex - 1);

            $metadata = self::parseMetadata($body, sprintf('D%d (%d 行目)', $id, $lineNumber), $violations);

            $entries[] = new ParsedEntry($id, $summary, $lineNumber, $metadata);
        }

        return new ParsedLedger($entries, $declared, $violations, false);
    }

    /**
     * 囲みコード区画の外にある行だけを行番号付きで返す。
     *
     * @return array{lines: list<array{0: int, 1: string}>, violations: list<string>, unclosed: bool}
     */
    private static function outsideFenceLines(string $markdown): array
    {
        $split = preg_split("/\r\n|\n|\r/", $markdown);
        Assert::isArray($split, '登録簿を行へ分割できない');

        /** @var list<array{0: int, 1: string}> $lines */
        $lines = [];
        /** @var list<string> $violations */
        $violations = [];
        $inFence = false;

        foreach ($split as $index => $text) {
            Assert::string($text);
            $number = $index + 1;

            if ($inFence) {
                if (preg_match('/^`{3}\s*$/', $text) === 1) {
                    $inFence = false;
                }

                continue;
            }

            if (preg_match('/^`{4,}/', $text) === 1) {
                $violations[] = sprintf('P3: %d 行目: バッククォート 4 個以上の囲みは台帳では使えない', $number);

                continue;
            }

            if (str_starts_with($text, '~~~')) {
                $violations[] = sprintf('P3: %d 行目: `~~~` の囲みは台帳では使えない', $number);

                continue;
            }

            if (preg_match('/^`{3}\s*$/', $text) === 1) {
                $inFence = true;

                continue;
            }

            if (str_starts_with($text, '```')) {
                // 言語名などを添えた囲みは扱わない。黙って読み飛ばすと、その書き方で登録を
                // 隠せる回避口になるため、書式そのものを 1 種類に絞って明示的に拒否する。
                $violations[] = sprintf('P3: %d 行目: 囲みは行頭のバッククォート 3 個だけで書く (言語名などを添えない)', $number);

                continue;
            }

            $lines[] = [$number, $text];
        }

        return ['lines' => $lines, 'violations' => $violations, 'unclosed' => $inFence];
    }

    /**
     * 要約に印・解消を表す語が含まれていないかを見る。
     *
     * `\p{S}` 全体は禁じない (見出しに `+` を使っている登録が実在し、正当な書き方であるため)。
     *
     * @return list<string> 違反理由 (空 = 合格)
     */
    private static function forbiddenSummaryReasons(string $summary): array
    {
        /** @var list<string> $reasons */
        $reasons = [];

        if (preg_match('/\p{So}/u', $summary) === 1) {
            $reasons[] = '印 (その他の記号) を含む。見出しは印を持たない正準形で書く';
        }
        if (str_contains($summary, '→')) {
            $reasons[] = '矢印 `→` を含む。状態の遷移を見出しで表さない';
        }
        foreach (['解消', '済み'] as $word) {
            if (str_contains($summary, $word)) {
                $reasons[] = sprintf('解消を表す語「%s」を含む。解消した逸脱は登録ごと消す', $word);
            }
        }

        return $reasons;
    }

    /**
     * 見出しの直後にある登録メタ表を解析する。
     *
     * @param  list<array{0: int, 1: string}>  $body  見出しの次の行から次の見出しの手前まで
     * @param  list<string>  $violations
     */
    private static function parseMetadata(array $body, string $label, array &$violations): ?EntryMetadata
    {
        $position = 0;
        $count = count($body);
        while ($position < $count && trim($body[$position][1]) === '') {
            $position++;
        }

        if ($position >= $count) {
            $violations[] = sprintf('TD2: %s に登録メタ表が無い', $label);

            return null;
        }

        $header = self::splitRow($body[$position][1]);
        if ($header === null || $header[0] !== '行' || $header[1] !== '内容') {
            $violations[] = sprintf('TD2: %s の登録メタ表は `| 行 | 内容 |` のヘッダで始める', $label);

            return null;
        }
        $position++;

        $separator = $position < $count ? self::splitRow($body[$position][1]) : null;
        if ($separator === null
            || preg_match('/^:?-{3,}:?$/', $separator[0]) !== 1
            || preg_match('/^:?-{3,}:?$/', $separator[1]) !== 1) {
            $violations[] = sprintf('TD2: %s の登録メタ表にヘッダ区切り行 `|---|---|` が無い', $label);

            return null;
        }
        $position++;

        /** @var list<string> $values */
        $values = [];
        foreach (self::META_LABELS as $expected) {
            $row = $position < $count ? self::splitRow($body[$position][1]) : null;
            if ($row === null) {
                $violations[] = sprintf(
                    'TD2: %s の登録メタ表が 9 行に足りない (「%s」の行が読めない。セルに `|` を書いていないか)',
                    $label,
                    $expected,
                );

                return null;
            }
            if ($row[0] !== $expected) {
                $violations[] = sprintf(
                    'TD2: %s の登録メタ表 %d 行目のラベルが「%s」ではなく「%s」。9 行の順序は規定である',
                    $label,
                    count($values) + 1,
                    $expected,
                    $row[0],
                );

                return null;
            }
            $values[] = $row[1];
            $position++;
        }

        // 9 行の直後は空行にする。表の行が続いていたら 10 行目とみなす
        // (列数で見分けようとすると、列を増やした 10 行目が比較表に紛れて通ってしまう)。
        if ($position < $count && str_starts_with(trim($body[$position][1]), '|')) {
            $violations[] = sprintf('TD2: %s の登録メタ表が 9 行を超えている (メタ表の直後には空行を置く)', $label);

            return null;
        }

        return new EntryMetadata(
            targetPaths: self::extractTargetPaths($values[0]),
            rawTargetPathCell: $values[0],
            domainReason: $values[1],
            invariantAndGuard: $values[2],
            reevaluationCondition: $values[3],
            decidedOn: $values[4],
            decidedBy: $values[5],
            rationale: $values[6],
            state: $values[7],
            reviewDeadline: $values[8],
        );
    }

    /**
     * 表の 1 行を 2 セルへ分割する。
     *
     * セルの中に `|` を書くこと (エスケープした `\|` を含む) は許さないので、
     * 分割後の要素数がちょうど 4 個 (先頭の空・ラベル・値・末尾の空) でなければ null を返す。
     *
     * @return array{0: string, 1: string}|null
     */
    private static function splitRow(string $line): ?array
    {
        $trimmed = trim($line);
        if (! str_starts_with($trimmed, '|')) {
            return null;
        }

        $parts = explode('|', $trimmed);
        if (count($parts) !== 4) {
            return null;
        }
        if (trim($parts[0]) !== '' || trim($parts[3]) !== '') {
            return null;
        }

        return [trim($parts[1]), trim($parts[2])];
    }

    /**
     * 対象パス欄からパスを取り出す。
     *
     * 許すのはバッククォート囲みのパスを ` / ` でつないだ形だけで、
     * バッククォートの外に空白以外の文字があれば 1 件も取り出さない
     * (書式違反は `DivergenceLedgerRules` が生セルを見て報告する)。
     *
     * @return list<string>
     */
    private static function extractTargetPaths(string $cell): array
    {
        if (preg_match('/^`[^`]+`(?: \/ `[^`]+`)*$/u', $cell) !== 1) {
            return [];
        }

        $found = preg_match_all('/`([^`]+)`/u', $cell, $matches);
        if ($found === false || $found === 0) {
            return [];
        }

        /** @var list<string> $paths */
        $paths = [];
        foreach ($matches[1] as $path) {
            $paths[] = $path;
        }

        return $paths;
    }
}
