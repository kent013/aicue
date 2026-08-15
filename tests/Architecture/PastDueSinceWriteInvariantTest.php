<?php

declare(strict_types=1);

use Tests\Support\PhpReferenceScanner;

/*
|--------------------------------------------------------------------------
| past_due_since 書き込み経路の invariant
|--------------------------------------------------------------------------
|
| `subscriptions.past_due_since` は猶予の起点 = 遮断の期日を決める状態キーのため、
| 書き込み (array key 代入 / プロパティ代入) は SubscriptionService に閉じる。
| 読み取り (`->past_due_since` の比較・null 検査) は対象外。
|
| model の `casts()` にある `'past_due_since' => 'datetime',` は**型宣言であって書き込みではない**
| ため免除するが、免除は「casts() メソッドの本体の行範囲に入っている cast 宣言」に限る
| (文字列一致だけで免除すると model 内の forceFill(['past_due_since' => …]) を見逃す)。
|
| **保証範囲を誇張しない**: 走査根は app/ のみで、database/migrations/ の backfill と
| 生 SQL は母集団に入らない (移行は 1 本きりで、手動 SQL の禁止は runbook が担う)。
| ファイル粒度の検査であり、許可ファイル内でのメソッド追加は検出しない
| (メソッド単位の fail-first は SubscriptionSnapshotSyncTest が担う)。
*/

/**
 * 1 ファイル分の `past_due_since` 書き込み違反行を返す。
 *
 * 違反ではないのは次の 2 つだけ:
 *   - docblock 行 (行頭が `*`)
 *   - `casts()` メソッド本体の行範囲にある cast 宣言 (`'past_due_since' => 'datetime',`)
 *
 * @return list<int> 違反した行番号
 */
function pastDueSinceWriteViolations(string $phpSource): array
{
    $castLines = pastDueSinceCastsBodyLines($phpSource);

    $violations = [];
    foreach (explode("\n", $phpSource) as $index => $line) {
        $lineNumber = $index + 1;
        if (! str_contains($line, 'past_due_since')) {
            continue;
        }
        // 書き込みの形 (array key 代入 / プロパティ代入)。比較 (=== / !==) は対象外。
        if (preg_match('/([\'"])past_due_since\1\s*=>|->past_due_since\s*=[^=]/', $line) !== 1) {
            continue;
        }
        if (str_starts_with(ltrim($line), '*')) {
            continue;
        }
        if (in_array($lineNumber, $castLines, true)
            && preg_match('/^([\'"])past_due_since\1\s*=>\s*([\'"])datetime\2,?$/', trim($line)) === 1) {
            continue;
        }

        $violations[] = $lineNumber;
    }

    return $violations;
}

/**
 * `casts()` メソッド本体の行範囲 (波括弧の深さで確定する。文字列一致で判定しない)。
 *
 * @return list<int>
 */
function pastDueSinceCastsBodyLines(string $phpSource): array
{
    $tokens = PhpReferenceScanner::tokens($phpSource);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]['id'] !== T_FUNCTION) {
            continue;
        }
        $name = $tokens[$i + 1] ?? null;
        if ($name === null || $name['text'] !== 'casts') {
            continue;
        }

        // 宣言の後、本体の開き `{` を探す (戻り値型・引数リストの括弧は素通しする)。
        $depth = 0;
        for ($j = $i + 2; $j < $count; $j++) {
            $text = $tokens[$j]['text'];
            if ($text === '{') {
                $depth++;

                continue;
            }
            if ($text !== '}') {
                continue;
            }
            $depth--;
            if ($depth === 0) {
                return range($tokens[$i]['line'], $tokens[$j]['line']);
            }
        }
    }

    return [];
}

test('app/ 内の past_due_since 書き込みは SubscriptionService に閉じる', function (): void {
    $allowlist = [
        'app/Services/Billing/SubscriptionService.php',
    ];

    $violations = [];
    foreach (PhpReferenceScanner::phpFiles(base_path('app'), 'app') as $relative => $source) {
        if (in_array($relative, $allowlist, true)) {
            continue;
        }
        foreach (pastDueSinceWriteViolations($source) as $line) {
            $violations[] = $relative.':'.$line;
        }
    }

    expect($violations)->toBe([], 'past_due_since の書き込みは SubscriptionService 経由に限定してください: '.implode(', ', $violations));
});

test('負のコントロール: 単一 writer 自身は書き込みとして検出される', function (): void {
    $source = (string) file_get_contents(base_path('app/Services/Billing/SubscriptionService.php'));

    expect(pastDueSinceWriteViolations($source))->not->toBe([]);
});

test('負のコントロール: cast 以外の array key 代入は違反として拾われる', function (): void {
    $source = <<<'PHP'
    <?php
    class Example
    {
        public function write(): void
        {
            $this->sub->forceFill(['past_due_since' => CarbonImmutable::now()])->save();
        }
    }
    PHP;

    expect(pastDueSinceWriteViolations($source))->toHaveCount(1);
});

test('負のコントロール: casts() の外にある cast と同じ文字列は免除されない', function (): void {
    $source = <<<'PHP'
    <?php
    class Example
    {
        public function write(): void
        {
            $this->sub->forceFill(['past_due_since' => 'datetime']);
        }

        protected function casts(): array
        {
            return ['current_period_end' => 'datetime'];
        }
    }
    PHP;

    expect(pastDueSinceWriteViolations($source))->toHaveCount(1);
});

test('負のコントロール: casts() の中の cast 宣言は免除される', function (): void {
    $source = <<<'PHP'
    <?php
    class Example
    {
        protected function casts(): array
        {
            return [
                'past_due_since' => 'datetime',
            ];
        }
    }
    PHP;

    expect(pastDueSinceWriteViolations($source))->toBe([]);
});

test('読み取り (比較・null 検査) は違反にならない', function (): void {
    $source = <<<'PHP'
    <?php
    class Example
    {
        public function read(): bool
        {
            return $this->sub->past_due_since !== null && $this->sub->past_due_since === $other;
        }
    }
    PHP;

    expect(pastDueSinceWriteViolations($source))->toBe([]);
});
