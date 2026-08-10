<?php

declare(strict_types=1);
use Tests\Support\Ci\TestDatabaseEnv;

/*
 * bug-hunt 並列枠数 cap の単一ソース化ゲート (c2c: bug-hunt-exec-infra / オーナー裁定 AG-048b)。
 *
 * 固定する不変条件:
 *   1. cap の正本は scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP ただ 1 つ (env 上書き不可)
 *   2. SHARD_RE / SHARD_DB_RE / manifest key regex は cap から導出され、数字を写経していない
 *   3. valid_parallel_n の受理集合と stories_for_shard の定義が cap と整合している
 *   4. 「割り当て」を説明する散文 (CAP_ALLOCATION_DOCS) に cap 超過が残っていない
 *      - Tier A (割り当て値): 行から構文で抽出した値が cap 超過 → **マーカーで免除できない**
 *      - Tier B (literal): ポート / DB 名 / 範囲表記 → `cap-defense-ok` マーカーで免除できる
 *   5. 「守り」の面 (CAP_DEFENSE_SURFACES) は **意図的に cap より広い**。値を直接固定する
 *
 * ★ 4 と 5 は逆向きの検査である。5 の面を 4 に含めてはならない
 *   (含めると防御を狭める方向へ改変が誘導される)。
 * ★ scripts/bug-hunt-shard.sh は 4 の対象に含めない。自身のコメントが 5 の説明を持つため
 *   偽陽性になる。スクリプトは 1〜3 の構造検査で固定する。
 * ★ `cap-defense-ok` は「守りが cap より広い理由」を書く行にのみ使う。
 *   Tier A (割り当て値) は**マーカーがあっても違反**なので、bypass にはならない。
 *
 * 既存テストとの役割分担: tests/Unit/Ci/TestDatabaseEnvTest.php は DEV_DB_DENYLIST の
 * **全体一致**を固定する。本テストが固定するのは「その denylist が **cap より広い**」という
 * 意図だけである。重複ではなく、「cap を下げたときに一緒に denylist も縮める」改変を止める別軸の検査。
 *
 * 実行時の挙動 (受理 / 拒否の exit code) は `scripts/bug-hunt-shard.sh self-test` が担う
 * 二段防御: Architecture = 静的構造、self-test = 実行配線。DB 不使用の静的検査。
 */

/** 守りの面を説明する行に付ける明示 opt-out マーカー (c2c 台帳の ref-ok と同じ発想)。 */
const CAP_DEFENSE_MARKER = 'cap-defense-ok';

/**
 * 割り当て (触れる対象) を説明する散文。cap 超過を deny-by-default で走査する。
 * ★ scripts/bug-hunt-shard.sh は**含めない** (自身のコメントが守りの説明を含むため。
 *   構造は bughuntCapScriptViolations() が別途固定する)。
 */
const CAP_ALLOCATION_DOCS = [
    'AGENTS.md',
    '.claude/skills/app-bug-hunt/SKILL.md',
    '.claude/skills/app-bug-hunt/stories/README.md',
    '.claude/skills/app-bug-hunt/ledger/README.md',
    '.claude/skills/app-bug-hunt/ledger/findings.schema.json',
    '.claude/skills/app-bug-hunt/ledger/validate_findings.py',
    '.claude/skills/app-bug-hunt/coverage/merge_pcov.py',
    '.env.bughunt.local.example',
    'docs/worktree-isolation-strategy.md',
    'tests/Architecture/BughuntEnvExampleContractTest.php',
];

/**
 * 守り (残留も含めて守る / 検出する) の面。**意図的に cap より広い**ので走査対象外。
 * key = パス / value = なぜ cap と同期させないか。
 */
const CAP_DEFENSE_SURFACES = [
    'tests/Support/Ci/TestDatabaseEnv.php' => '残留 bug_hunt_5..8 を保護し続ける dev DB denylist',
    'database/seeders/Concerns/DetectsBughuntDatabase.php' => '残留も bughunt DB と検出する fail-secure 判定',
    'scripts/run-browser-test.sh' => '残留 serve 検出の pre-flight guard (広い方が偽赤に倒れて安全)',
    'scripts/run-browser-test.contract.test.ts' => '同 guard のテスト側ミラー',
    'scripts/verify-global-test-lock.sh' => 'bind 可能ポートを探す fixture (cap と無関係)',
    'docs/testing-browser.md' => '上記 guard の説明',
    'scripts/README.md' => '上記 guard の説明',
];

/** `cap-defense-ok` を書いてよいファイル。ここ以外のマーカーは違反。 */
const CAP_DEFENSE_MARKER_FILES = [
    'AGENTS.md',                                     // 割り当てと守りの両方を説明する規約文書
    '.claude/skills/app-bug-hunt/ledger/README.md',  // 過去 run の shard_id 範囲に触れる
];

/** マーカー行に最低 1 つ必要な「守りの語」。 */
const CAP_MARKER_DEFENSE_WORDS = ['denylist', 'guard', '残留', '防御', '検出'];

function bughuntCapReadSource(string $relativePath): string
{
    $contents = file_get_contents(base_path($relativePath));
    expect($contents)->toBeString("{$relativePath} が読めない");
    /** @var string $contents */
    expect($contents)->not->toBe('', "{$relativePath} が空");

    return $contents;
}

/**
 * `^name()` 行から行頭 `}` までの関数窓を切り出す。
 * 対象 3 関数 (manifest_valid_shards / valid_parallel_n / stories_for_shard) はいずれも
 * 行頭 `}` を本体内に持たない (heredoc の python も字下げされている)。
 */
function bughuntCapFunctionWindow(string $source, string $name): string
{
    $m = [];
    $matched = preg_match('/^'.preg_quote($name, '/').'\(\)\s*\{[\s\S]*?^\}/m', $source, $m);
    expect($matched)->toBe(1, "関数窓が見つからない: {$name}");

    /** @var array{0: string} $m */
    return $m[0];
}

/** cap の正本を scripts/bug-hunt-shard.sh から抽出する。 */
function bughuntCapFromScript(string $script): int
{
    $m = [];
    $matched = preg_match('/^BUGHUNT_SHARD_CAP=([0-9]+)$/m', $script, $m);
    expect($matched)->toBe(1, 'BUGHUNT_SHARD_CAP=<数値> の代入が見つからない');

    /** @var array{0: string, 1: string} $m */
    return (int) $m[1];
}

/**
 * cap 代入そのものの違反 (SSOT 性 / 1 桁性 / env 上書き不可)。
 *
 * @return list<string>
 */
function bughuntCapSsotViolations(string $script): array
{
    $violations = [];

    $count = (int) preg_match_all('/^BUGHUNT_SHARD_CAP=/m', $script);
    if ($count !== 1) {
        $violations[] = "BUGHUNT_SHARD_CAP の代入が {$count} 箇所 (正本は 1 箇所であること)";
    }
    if (preg_match('/^BUGHUNT_SHARD_CAP=[2-9]$/m', $script) !== 1) {
        $violations[] = 'BUGHUNT_SHARD_CAP が 2..9 の 1 桁即値で書かれていない (文字クラス導出の前提)';
    }
    if (preg_match('/^BUGHUNT_SHARD_CAP=[^\n]*\$\{/m', $script) === 1) {
        $violations[] = 'BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれている (allowlist を外から広げられる)';
    }

    return $violations;
}

/**
 * SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出されているか。
 *
 * @return list<string>
 */
function bughuntCapDerivationViolations(string $script): array
{
    $violations = [];

    foreach (['SHARD_RE', 'SHARD_DB_RE'] as $name) {
        $m = [];
        if (preg_match('/^'.$name.'=(.*)$/m', $script, $m) !== 1) {
            $violations[] = "{$name} の代入が見つからない";

            continue;
        }
        /** @var array{0: string, 1: string} $m */
        $line = $m[1];
        if (! str_contains($line, '${BUGHUNT_SHARD_CAP}')) {
            $violations[] = "{$name} が cap から導出されていない: {$line}";
        }
        if (preg_match('/\[[0-9]-[0-9]\]/', $line) === 1) {
            $violations[] = "{$name} に数字の写経が残っている: {$line}";
        }
    }

    $window = bughuntCapFunctionWindow($script, 'manifest_valid_shards');
    if (! str_contains($window, 'CAP="${BUGHUNT_SHARD_CAP}"')) {
        $violations[] = 'manifest_valid_shards が cap を parser へ渡していない (CAP="${BUGHUNT_SHARD_CAP}")';
    }
    if (! str_contains($window, '[0-{cap}]')) {
        $violations[] = 'manifest key regex が cap から導出されていない ([0-{cap}])';
    }
    if (preg_match('/\[0-[0-9]\]/', $window) === 1) {
        $violations[] = 'manifest key regex に数字の写経が残っている';
    }

    return $violations;
}

/**
 * BUGHUNT_DB_PREFIX が SHARD_DB_RE へ埋め込まれる**前**に形式検証されているか。
 * (prefix は escape なしで dev DB 防御の核へ埋め込まれるため、順序が保証になる)
 *
 * @return list<string>
 */
function bughuntCapPrefixValidationViolations(string $script): array
{
    $violations = [];

    $m = [];
    $matched = preg_match(
        '/\[\[\s*"\$\{BUGHUNT_DB_PREFIX\}"\s*=~\s*\^\[a-z\]\[a-z0-9_\]\*\$\s*\]\]/',
        $script,
        $m,
        PREG_OFFSET_CAPTURE
    );
    if ($matched !== 1) {
        return ['BUGHUNT_DB_PREFIX の形式検証 (^[a-z][a-z0-9_]*$) が無い'];
    }
    /** @var array{0: array{0: string, 1: int}} $m */
    $validationOffset = $m[0][1];

    $assignOffset = strpos($script, 'SHARD_DB_RE="');
    if ($assignOffset === false) {
        return ['SHARD_DB_RE の代入が見つからない'];
    }
    if ($validationOffset > $assignOffset) {
        $violations[] = 'BUGHUNT_DB_PREFIX の形式検証が SHARD_DB_RE への埋め込みより後ろにある (検証 → 代入の順であること)';
    }

    return $violations;
}

/**
 * valid_parallel_n が「2 以上 cap 以下の偶数」の算術判定であること (数字の列挙に戻っていないこと)。
 *
 * @return list<string>
 */
function bughuntCapParallelAcceptanceViolations(string $script): array
{
    $violations = [];
    $window = bughuntCapFunctionWindow($script, 'valid_parallel_n');

    if (preg_match('/\bcase\b/', $window) === 1) {
        $violations[] = 'valid_parallel_n が case 列挙のまま (cap の SSOT 化が効いていない)';
    }
    if (preg_match('/[0-9]\s*\|\s*[0-9]/', $window) === 1) {
        $violations[] = 'valid_parallel_n に受理値の数字列挙が残っている';
    }
    foreach (['n >= 2', 'n <= BUGHUNT_SHARD_CAP', 'n % 2 == 0'] as $needle) {
        if (! str_contains($window, $needle)) {
            $violations[] = "valid_parallel_n に算術条件「{$needle}」が無い";
        }
    }

    return $violations;
}

/**
 * stories_for_shard の固定マップから {N => [shard,...]} を抽出する (純関数)。
 *
 * @return array<int, list<int>>
 */
function bughuntCapStoryMap(string $script): array
{
    $window = bughuntCapFunctionWindow($script, 'stories_for_shard');

    $m = [];
    preg_match_all('/^\s*([0-9]+)-([0-9]+)\)/m', $window, $m);
    /** @var array{1: list<string>, 2: list<string>} $m */
    $map = [];
    foreach ($m[1] as $i => $n) {
        $map[(int) $n][] = (int) $m[2][$i];
    }
    ksort($map);
    foreach ($map as $n => $shards) {
        sort($shards);
        $map[$n] = array_values($shards);
    }

    return $map;
}

/**
 * --parallel の受理値 (2 以上 cap 以下の偶数)。
 *
 * @return list<int>
 */
function bughuntCapAcceptedParallelValues(int $cap): array
{
    $values = [];
    for ($n = 2; $n <= $cap; $n += 2) {
        $values[] = $n;
    }

    return $values;
}

/**
 * stories_for_shard のマップが「受理される全 N × shard 1..N」を過不足なく持つか。
 *
 * @return list<string>
 */
function bughuntCapStoryMapViolations(string $script, int $cap): array
{
    $violations = [];
    $map = bughuntCapStoryMap($script);
    $accepted = bughuntCapAcceptedParallelValues($cap);

    foreach (array_keys($map) as $n) {
        if (! in_array($n, $accepted, true)) {
            $violations[] = "stories_for_shard に受理されない N={$n} の case が残っている (cap={$cap})";
        }
    }
    foreach ($accepted as $n) {
        $expected = range(1, $n);
        $actual = $map[$n] ?? [];
        if ($actual !== $expected) {
            $violations[] = "stories_for_shard: N={$n} の shard 集合が 1..{$n} と一致しない ("
                .implode(',', $actual).')';
        }
    }

    return $violations;
}

/**
 * scripts/bug-hunt-shard.sh の**構造**違反の一覧 (純関数)。
 * ★ 本スクリプトは散文 scan の対象にしない (自身のコメントが守りの説明を含み偽陽性になるため)。
 *
 * @return list<string>
 */
function bughuntCapScriptViolations(string $script): array
{
    $cap = bughuntCapFromScript($script);

    return array_merge(
        bughuntCapSsotViolations($script),
        bughuntCapDerivationViolations($script),
        bughuntCapPrefixValidationViolations($script),
        bughuntCapParallelAcceptanceViolations($script),
        bughuntCapStoryMapViolations($script, $cap),
    );
}

/**
 * 1 行から「割り当て値として書かれた数値」を構文で抽出する (純関数)。★ 検出ロジックの単一ソース。
 *
 * 散文検査 (Tier A) とマーカー無効化判定の**両方がこの 1 本を使う**
 * (別々の正規表現集合を持つと再び差分が生じるため)。
 * 数字の近接では判定しない: `S7` / `AG-048b` / `2025 年` はどの構文にも当たらない。
 *
 * @return list<int> 抽出できた割り当て値 (順不同・重複可)
 */
function bughuntCapAllocationValues(string $line): array
{
    // 区切り: `=` / `は` / `:` / 空白。日本語散文の「cap は 8」「--parallel 8」も割り当て値として拾う
    // (Codex impl-review R1 Suggestion)。数値が続く場合のみ一致するので「parallel 実行は…」には当たらない。
    $sep = '(?:\s*(?:=|は|:)\s*|\s+)';
    $numbers = '([0-9]+(?:\s*\/\s*[0-9]+)*)';
    $patterns = [
        // `--parallel=8` / `--parallel は 2/4/6/8` / `--parallel: 8` / `--parallel 8`
        '/--parallel'.$sep.$numbers.'/u',
        // `parallel=8` / `parallel は 8` (`--parallel` は上で拾うので lookbehind で除外)
        '/(?<![-_a-zA-Z])parallel'.$sep.$numbers.'/iu',
        // `N=8` / `N は 8` / `N=2/4/6/8` (前が英数字なら不一致。`AG-048b` の `048` には当たらない)
        '/(?<![0-9A-Za-z])N'.$sep.$numbers.'(?![0-9])/u',
        // `cap=8` / `cap = 8` / `cap は 8`
        '/cap'.$sep.'([0-9]+)(?![0-9])/iu',
        // `shard 1..8` (lookbehind により `8011..8018` の内側には当たらない)
        '/(?<![0-9])1\s*\.\.\s*([0-9]+)(?![0-9])/u',
    ];

    $values = [];
    foreach ($patterns as $pattern) {
        $m = [];
        if ((int) preg_match_all($pattern, $line, $m) < 1) {
            continue;
        }
        /** @var array{1: list<string>} $m */
        foreach ($m[1] as $group) {
            foreach (preg_split('/\s*\/\s*/', $group) ?: [] as $number) {
                $values[] = (int) $number;
            }
        }
    }

    return $values;
}

/**
 * Tier B (literal) の検出パターン。cap から動的に生成する。
 *
 * @return array<string, string> label => PCRE
 */
function bughuntCapExcessLiteralPatterns(int $cap): array
{
    $patterns = [];

    if ($cap < 9) {
        $excess = '['.($cap + 1).'-9]';
        $patterns['cap 超過ポート'] = '/801'.$excess.'/';
        $patterns['ポート範囲の写経'] = '/8011\s*\.\.\s*801'.$excess.'/';
        $patterns['DB regex の写経'] = '/_\[1-'.$excess.'\]/';
        $patterns['cap 超過 DB 名'] = '/bug_hunt_'.$excess.'/';
    }
    if ($cap < 8) {
        // 「0-8」形の範囲表記。9 は正規表現の文字クラス `[0-9]` と衝突するため意図的に除外する。
        $patterns['shard 範囲表記'] = '/(?<![0-9])0-['.($cap + 1).'-8](?![0-9])/';
    }

    // `--parallel` 受理値の写経 (cap を超える偶数を含む列挙。cap=4 なら `2/4/6`)。
    $evens = [];
    for ($n = 2; $n <= $cap + 2; $n += 2) {
        $evens[] = (string) $n;
    }
    $patterns['受理値列挙の写経'] = '/'.implode('\s*\/\s*', $evens).'/';

    return $patterns;
}

/**
 * 割り当て散文に残った cap 超過の一覧 (純関数)。
 *
 * - **Tier A**: bughuntCapAllocationValues() の抽出値が cap 超過なら違反。マーカーで免除しない。
 * - **Tier B**: ポート / DB 名 / 範囲表記の literal。有効なマーカー行は免除する。
 *
 * マーカーが有効になる条件は (a) CAP_DEFENSE_MARKER_FILES に含まれるファイル
 * (b) 行に CAP_MARKER_DEFENSE_WORDS のいずれかを含む、の両方。欠ければマーカー自体が違反。
 *
 * @return list<string> 違反メッセージ (行番号 + tier 付き)。空なら合格
 */
function bughuntCapProseViolations(string $relativePath, string $content, int $cap): array
{
    $violations = [];
    $tierB = bughuntCapExcessLiteralPatterns($cap);
    $lines = preg_split('/\R/u', $content) ?: [];

    foreach ($lines as $index => $line) {
        $no = $index + 1;
        $excerpt = trim($line);

        foreach (bughuntCapAllocationValues($line) as $value) {
            if ($value > $cap) {
                $violations[] = "{$relativePath}:{$no} [Tier A] 割り当て値 {$value} が cap({$cap}) を超える: {$excerpt}";
            }
        }

        $markerEffective = false;
        if (str_contains($line, CAP_DEFENSE_MARKER)) {
            $inAllowedFile = in_array($relativePath, CAP_DEFENSE_MARKER_FILES, true);
            $hasDefenseWord = false;
            foreach (CAP_MARKER_DEFENSE_WORDS as $word) {
                if (str_contains($line, $word)) {
                    $hasDefenseWord = true;
                    break;
                }
            }
            if (! $inAllowedFile) {
                $violations[] = "{$relativePath}:{$no} [marker] ".CAP_DEFENSE_MARKER
                    .' は CAP_DEFENSE_MARKER_FILES 以外のファイルでは使えない: '.$excerpt;
            } elseif (! $hasDefenseWord) {
                $violations[] = "{$relativePath}:{$no} [marker] ".CAP_DEFENSE_MARKER
                    .' 行に守りの語 ('.implode('/', CAP_MARKER_DEFENSE_WORDS).') が無い: '.$excerpt;
            } else {
                $markerEffective = true;
            }
        }

        if ($markerEffective) {
            continue;
        }
        foreach ($tierB as $label => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $violations[] = "{$relativePath}:{$no} [Tier B] {$label}: {$excerpt}";
            }
        }
    }

    return $violations;
}

// --- 構造 (スクリプト本体。散文 scan とは別レーン) -------------------------------

test('cap の正本が scripts/bug-hunt-shard.sh の BUGHUNT_SHARD_CAP=4 ただ 1 つであること', function (): void {
    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');

    expect(bughuntCapFromScript($script))->toBe(4, 'c2c オーナー裁定 AG-048b の統一値は 4');
    expect((int) preg_match_all('/^BUGHUNT_SHARD_CAP=/m', $script))->toBe(1);
});

test('BUGHUNT_SHARD_CAP が env 上書き可能な形 (${...:-N}) で書かれていないこと', function (): void {
    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');

    expect(bughuntCapSsotViolations($script))->toBe([]);
});

test('SHARD_RE / SHARD_DB_RE / manifest key regex が cap から導出され、数字の写経が無いこと', function (): void {
    expect(bughuntCapDerivationViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
});

test('BUGHUNT_DB_PREFIX が SHARD_DB_RE 埋め込み前に形式検証されていること', function (): void {
    expect(bughuntCapPrefixValidationViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
});

test('valid_parallel_n の受理集合が {2..cap の偶数} であること', function (): void {
    expect(bughuntCapParallelAcceptanceViolations(bughuntCapReadSource('scripts/bug-hunt-shard.sh')))->toBe([]);
});

test('stories_for_shard の固定マップが受理される全 N × shard 1..N を過不足なく持ち、cap 超過 case が無いこと', function (): void {
    $script = bughuntCapReadSource('scripts/bug-hunt-shard.sh');
    $cap = bughuntCapFromScript($script);

    expect(bughuntCapStoryMapViolations($script, $cap))->toBe([]);
    expect(array_keys(bughuntCapStoryMap($script)))->toBe(bughuntCapAcceptedParallelValues($cap));
});

// --- 散文 (割り当てを説明する文書) ------------------------------------------------

test('割り当て散文に cap 超過 literal が残っていないこと', function (string $relativePath): void {
    $cap = bughuntCapFromScript(bughuntCapReadSource('scripts/bug-hunt-shard.sh'));

    expect(bughuntCapProseViolations($relativePath, bughuntCapReadSource($relativePath), $cap))->toBe([]);
})->with(CAP_ALLOCATION_DOCS);

// --- 守り (意図的に cap より広い面。値を直接固定する) -------------------------------

test('defense surface の除外集合が空でなく、各 entry がパス実在 + 理由文字列を持つこと', function (): void {
    expect(CAP_DEFENSE_SURFACES)->not->toBe([]);

    foreach (CAP_DEFENSE_SURFACES as $path => $reason) {
        expect(file_exists(base_path($path)))->toBeTrue("defense surface が実在しない: {$path}");
        expect($reason)->not->toBe('', "defense surface の理由が空: {$path}");
        expect(in_array($path, CAP_ALLOCATION_DOCS, true))
            ->toBeFalse("守りの面を割り当て散文 scan に含めてはならない: {$path}");
    }
});

test('DEV_DB_DENYLIST が cap を超える bug_hunt_5..bug_hunt_8 を保持していること', function (): void {
    $cap = bughuntCapFromScript(bughuntCapReadSource('scripts/bug-hunt-shard.sh'));

    // 守る側は cap と同期させない (過去 cap=8 期の残留 DB を保護し続ける)。
    // 消えていれば残留 DB 保護の後退なので、値を直接固定する。
    for ($i = $cap + 1; $i <= 8; $i++) {
        expect(TestDatabaseEnv::DEV_DB_DENYLIST)->toContain("bug_hunt_{$i}");
    }
});

test('BughuntDatabaseGuard の regex が cap を超える _[1-8] を保持していること', function (): void {
    // 判定の SSOT は app 側 (seeder trait は委譲するだけの薄い殻)。
    // dev DB 防御は smoke コマンドの fail-secure 条件もここを読むため、正本を 1 つに保つ。
    $source = bughuntCapReadSource('app/Support/BughuntDatabaseGuard.php');

    expect($source)->toContain('/^bug_hunt(_[1-8])?$/');
});

test('DetectsBughuntDatabase は regex を二重に持たず SSOT へ委譲していること', function (): void {
    $source = bughuntCapReadSource('database/seeders/Concerns/DetectsBughuntDatabase.php');

    expect($source)->toContain('BughuntDatabaseGuard')
        ->and($source)->not->toContain('bug_hunt(');
});

test('run-browser-test.sh の pre-flight guard が cap を超える 8018 まで見ていること', function (): void {
    $source = bughuntCapReadSource('scripts/run-browser-test.sh');

    expect($source)->toContain('{8010..8018}');
});

// --- 負のコントロール (正の検出) ---------------------------------------------------

test('負のコントロール: Tier B literal (bug_hunt_5 / :8018 / 2/4/6) を検出すること', function (): void {
    $fixture = implode("\n", [
        '並列 shard は bug_hunt_5 まで作られる',
        'ポートは :8011..8018 を使う',
        '受理値は 2/4/6 である',
        'shard_id には 0-8 を記録する',
        'DB 名は ^bug_hunt(_[1-8])?$ のみ',
    ]);

    $violations = bughuntCapProseViolations('AGENTS.md', $fixture, 4);
    expect($violations)->not->toBe([]);
    $joined = implode("\n", $violations);
    foreach (['cap 超過 DB 名', 'cap 超過ポート', '受理値列挙の写経', 'shard 範囲表記', 'DB regex の写経'] as $label) {
        expect($joined)->toContain($label);
    }
});

test('負のコントロール: Tier A 割り当て値 (--parallel=8 / parallel は 2/4/6/8 / N=8 / cap=8 / shard 1..8) を検出すること', function (): void {
    $lines = [
        '--parallel=8', '--parallel は 2/4/6/8', '--parallel 8 で走らせる',
        'N=8 で走らせる', 'N は 8 とする', 'cap=8 とする', 'cap は 8 である', 'shard 1..8 に配る',
    ];
    foreach ($lines as $line) {
        $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
        expect(implode("\n", $violations))->toContain('[Tier A]');
    }

    expect(bughuntCapAllocationValues('--parallel は 2/4/6/8'))->toBe([2, 4, 6, 8]);
    expect(bughuntCapAllocationValues('cap=8'))->toBe([8]);
});

test('負のコントロール: Tier A はマーカー付きでも違反になること', function (): void {
    $line = 'parallel=8 は残留 guard 用。'.CAP_DEFENSE_MARKER;

    $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
    expect(implode("\n", $violations))->toContain('[Tier A]');
});

test('負のコントロール: allowlist 外ファイルの cap-defense-ok を違反として検出すること', function (): void {
    $line = '残留ポート :8018 までは guard が検出する。'.CAP_DEFENSE_MARKER;

    $violations = bughuntCapProseViolations('docs/worktree-isolation-strategy.md', $line, 4);
    $joined = implode("\n", $violations);
    expect($joined)->toContain('[marker]');
    // マーカーが無効なので Tier B も素通りしない。
    expect($joined)->toContain('[Tier B]');
});

test('負のコントロール: 守りの語を含まない cap-defense-ok を違反として検出すること', function (): void {
    $line = 'ポートは :8018 まで使う。'.CAP_DEFENSE_MARKER;

    $violations = bughuntCapProseViolations('AGENTS.md', $line, 4);
    $joined = implode("\n", $violations);
    expect($joined)->toContain('[marker]');
    expect($joined)->toContain('[Tier B]');
});

test('負のコントロール: 6-* / 8-* case を戻した script fixture を構造検査が検出すること', function (): void {
    $storyFixture = <<<'SH'
    stories_for_shard() {
        local shard=$1 n=$2
        case "${n}-${shard}" in
            4-1) echo "S3 S7" ;;
            4-2) echo "S1 S2" ;;
            4-3) echo "S4 S5" ;;
            4-4) echo "S6" ;;
            2-1) echo "S3 S7 S6" ;;
            2-2) echo "S1 S2 S4 S5" ;;
            6-1) echo "S3 S7" ;;
            8-1) echo "S3 S7" ;;
            *) die 1 "undefined" ;;
        esac
    }
    SH;
    $violations = bughuntCapStoryMapViolations($storyFixture, 4);
    expect(implode("\n", $violations))->toContain('N=6')
        ->and(implode("\n", $violations))->toContain('N=8');

    // 数字の写経に戻した導出も検出する。
    $reFixture = "SHARD_RE='^[0-8]$'\nSHARD_DB_RE=\"^\${BUGHUNT_DB_PREFIX}(_[1-8])?\$\"\n";
    $derivation = array_filter(
        bughuntCapDerivationViolations($reFixture.bughuntCapReadSource('scripts/bug-hunt-shard.sh')),
        static fn (string $v): bool => str_contains($v, '写経') || str_contains($v, '導出されていない'),
    );
    expect($derivation)->not->toBe([]);

    // env 上書き可能な形も検出する。
    expect(bughuntCapSsotViolations("BUGHUNT_SHARD_CAP=\${BUGHUNT_SHARD_CAP:-8}\n"))->not->toBe([]);
});

// --- 負のコントロール (偽陽性を出さないこと) ---------------------------------------

test('偽陽性防止: S7 / AG-048b / 2025 年 / parallel=4 の結果を S7 で検証する が違反にならないこと', function (): void {
    $fixture = implode("\n", [
        'S7 のストーリーは shard-1 に閉じ込める',
        'c2c オーナー裁定 AG-048b に従う',
        'parallel 実行は 2025 年に導入した',
        'parallel=4 の結果を S7 で検証する',
        '--parallel は 2/4',
        'shard 1..N の DB を用意する',
    ]);

    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 4))->toBe([]);
});

test('偽陽性防止: :8011..8018 の "1..8" が Tier A に当たらないこと (Tier B では当たる)', function (): void {
    expect(bughuntCapAllocationValues('serve は :8011..8018 で待ち受ける'))->toBe([]);

    $violations = bughuntCapProseViolations('AGENTS.md', 'serve は :8011..8018 で待ち受ける', 4);
    expect(implode("\n", $violations))->toContain('[Tier B]')
        ->and(implode("\n", $violations))->not->toContain('[Tier A]');
});

test('偽陽性防止: cap-defense-ok 付きの正当な守り説明行が通ること', function (): void {
    $line = '残留ポート :8018 までは guard が検出する ('.CAP_DEFENSE_MARKER.')';

    expect(bughuntCapProseViolations('AGENTS.md', $line, 4))->toBe([]);
});

test('偽陽性防止: cap を上げた場合に検出が追従すること (cap=6 なら bug_hunt_5 も parallel=6 も違反でない)', function (): void {
    $fixture = implode("\n", [
        'DB は bug_hunt_5 まで作る',
        '--parallel は 2/4/6',
        'shard 1..6 に配る',
    ]);

    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 6))->toBe([]);
    // 同じ行が cap=4 では違反になる (追従していることの対照)。
    expect(bughuntCapProseViolations('AGENTS.md', $fixture, 4))->not->toBe([]);
});
