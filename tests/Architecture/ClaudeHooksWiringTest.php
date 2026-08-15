<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Webmozart\Assert\Assert;

/*
 * 常設 hook 配線の台帳 (deny-by-default) と、hook スクリプトの実挙動ゲート。
 *
 * 本テストは 2 層で構成する:
 *  - 静的層 (S01〜S12b): `.claude/settings.json` が下の台帳と**完全一致**することを見る。
 *    台帳に無い hook・イベント・トップレベルキーはすべて違反 = 配線の正本が 1 か所になる。
 *  - 実起動層 (B01〜B51): hook スクリプトと起動子を**別プロセスで本当に起動**して、
 *    終了コード・標準出力の空・告知の回数・排他・敵対的な検索パス・symlink の置き場での
 *    振る舞いを実証する。静的検査だけでは「書いてあるが効いていない」を検出できない。
 *
 * 本テストは DB を触らない (ファイル読み取りと別プロセス起動のみ)。
 * 関数名を `claudeHooks` 接頭辞で始めるのは、Pest が全テストを 1 プロセスへ読み込むため
 * 素の名前が他の Architecture テストと衝突するからである。
 */

/** 設定ファイルのトップレベルに置いてよいキー (全数申告制)。 */
const CLAUDE_HOOKS_TOP_LEVEL_KEYS = ['hooks'];

/**
 * 配線台帳。ここに書かれた形と `.claude/settings.json` が完全一致しなければ落ちる。
 *
 * `matcher` の `Write|Edit` は **`Write` と `Edit` のときだけ**発火する。
 * 部分一致で将来の派生ツールを自動で拾うとは書かない (書くと嘘になる)。
 *
 * @var array<string, list<array{matcher: string, script: string, timeout: int, deny_exit_code: int|null}>>
 */
const CLAUDE_HOOKS_WIRING = [
    'PreToolUse' => [
        [
            'matcher' => 'Bash',
            'script' => 'scripts/bughunt-worktree-hook.sh',
            'timeout' => 10,
            'deny_exit_code' => 97,
        ],
    ],
    'PostToolUse' => [
        [
            'matcher' => 'Write|Edit',
            'script' => 'scripts/code-review-graph-update-hook.sh',
            'timeout' => 30,
            'deny_exit_code' => null,
        ],
    ],
];

/**
 * 索引の対象外拡張子。`scripts/code-review-graph-update-hook.sh` の `SKIP_EXTENSIONS` と
 * 完全一致すること (索引ツールを更新したらここも棚卸しする)。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_SKIP_EXTENSIONS = ['md', 'txt', 'json', 'yaml', 'yml', 'lock', 'log'];

/** 検索パス安全化ブロックの開始・終了マーカー (2 本の hook で byte 一致する)。 */
const CLAUDE_HOOKS_PROLOGUE_BEGIN = '# ---8< SHARED_PATH_PROLOGUE (2 本の hook で byte 一致。台帳テストが固定する) >8---';
const CLAUDE_HOOKS_PROLOGUE_END = '# ---8< /SHARED_PATH_PROLOGUE >8---';

/**
 * S12b の走査対象 (実行面のファイルのみ)。文書は走査しない —
 * 禁止を説明する文章にコマンド名が出るのは正常であり、走査すると必ず落ちるためである。
 *
 * @var list<string>
 */
const CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS = [
    'scripts/*.sh',
    'scripts/*/*.sh',
    '.claude/settings*.json',
    'docker/Dockerfile',
    'composer.json',
    'package.json',
    '.github/workflows/*',
];

// =============================================================================
// ヘルパ (静的層)
// =============================================================================

/** ファイルを読む (読めなければ明示 fail し string へ narrow する)。 */
function claudeHooksReadFile(string $path): string
{
    Assert::fileExists($path);
    $contents = file_get_contents($path);
    Assert::string($contents, "読み込めません: {$path}");

    return $contents;
}

/**
 * `.claude/settings.json` を配列として読む。
 *
 * @return array<string, mixed>
 */
function claudeHooksSettings(): array
{
    $decoded = json_decode(claudeHooksReadFile(base_path('.claude/settings.json')), true);
    Assert::isArray($decoded, '.claude/settings.json が JSON オブジェクトではない');

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * 起動子の文字列を台帳側で組み立てる (設定を書き換えたら必ずここと食い違って落ちる)。
 *
 * 起動子が持つ 3 つの役割:
 *  1. 起動先の検証 (絶対パス / `..` を含まない / `scripts` が symlink でない実ディレクトリ /
 *     起動先が symlink でない通常ファイル)。1 つでも欠ければ内側を起動しない
 *  2. 終了コードの写像 (PreToolUse は 97 だけを 2 へ写す。それ以外はすべて 0)
 *  3. 環境からのシェル関数の遮断 (`-p` = privileged mode)
 */
function claudeHooksExpectedCommand(string $script, ?int $denyExitCode): string
{
    $conditions = '[ -n "$d" ] && [ "${d#/}" != "$d" ] && [ "${d//../}" = "$d" ]'
        .' && [ -d "$d/scripts" ] && [ ! -L "$d/scripts" ] && [ -f "$f" ] && [ ! -L "$f" ]';

    $inner = 'd=${CLAUDE_PROJECT_DIR:-}; f=$d/'.$script.'; ';
    $inner .= $denyExitCode === null
        ? 'if '.$conditions.'; then /bin/bash -p "$f"; fi; exit 0'
        : 's=0; if '.$conditions.'; then /bin/bash -p "$f"; s=$?; fi; '
            .'if [ "$s" = '.$denyExitCode.' ]; then exit 2; fi; exit 0';

    return "/bin/bash -p -c '".$inner."'";
}

/**
 * 検索パス安全化ブロックを取り出す。マーカーが 1 組でなければ違反として文字列を返す。
 *
 * shell parser は作らない。見るのは (1) マーカーが 1 組 (2) ブロックの byte 列
 * (3) 開始マーカーより前が shebang・コメント・空行だけ、の 3 点だけである。
 *
 * @return array{block: string, violations: list<string>}
 */
function claudeHooksPrologueBlock(string $contents, string $label): array
{
    $violations = [];

    $beginCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $endCount = substr_count($contents, CLAUDE_HOOKS_PROLOGUE_END);
    if ($beginCount !== 1 || $endCount !== 1) {
        return [
            'block' => '',
            'violations' => ["{$label}: 検索パス安全化ブロックのマーカーが 1 組でない (begin={$beginCount} end={$endCount})"],
        ];
    }

    $begin = strpos($contents, CLAUDE_HOOKS_PROLOGUE_BEGIN);
    $end = strpos($contents, CLAUDE_HOOKS_PROLOGUE_END);
    Assert::integer($begin);
    Assert::integer($end);
    if ($end < $begin) {
        return ['block' => '', 'violations' => ["{$label}: 終了マーカーが開始マーカーより前にある"]];
    }

    $block = substr($contents, $begin, $end - $begin + strlen(CLAUDE_HOOKS_PROLOGUE_END));

    // 開始マーカーより前は shebang・コメント・空行だけであること
    // (= 最初の外部コマンド呼び出しより前にプロローグがある、が自動的に成立する)
    foreach (preg_split('/\r\n|\r|\n/', substr($contents, 0, $begin)) ?: [] as $index => $line) {
        $trimmed = trim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            continue;
        }
        $violations[] = "{$label}: 検索パス安全化ブロックより前に実行される行がある (".($index + 1)." 行目: {$trimmed})";
    }

    return ['block' => $block, 'violations' => $violations];
}

// =============================================================================
// ヘルパ (実起動層)
// =============================================================================

/** 実起動層で必要な外部コマンドの絶対パスを解決する。 */
function claudeHooksResolveExecutable(string $name): string
{
    foreach (['/usr/local/bin/', '/usr/bin/', '/bin/'] as $dir) {
        if (is_executable($dir.$name)) {
            return $dir.$name;
        }
    }

    throw new RuntimeException("実起動層に必要な外部コマンドが見つかりません: {$name}");
}

/**
 * sandbox を作る。実スクリプトを `$sandbox/scripts/` へ複製するので、
 * `BASH_SOURCE` から解決されるリポジトリルートは sandbox 側になり本物を汚さない。
 *
 * 検索パスは**システムディレクトリを一切含めない**。必要な外部コマンド
 * (`mkdir` / `flock` / `timeout` / `sleep`) だけを sandbox の bin へ symlink するので、
 * 「索引ツールが未導入」を実行環境に左右されずに作れる。
 */
function claudeHooksSandbox(): string
{
    $sandbox = sys_get_temp_dir().'/claude-hooks-'.bin2hex(random_bytes(8));
    File::makeDirectory($sandbox.'/scripts', 0700, true);

    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            File::copy(base_path($entry['script']), $sandbox.'/'.$entry['script']);
        }
    }

    // 3 種類の bin を用意する (索引ツールの有無 / timeout の有無を作り分ける)
    foreach (['bin', 'bin-notool', 'bin-notimeout'] as $binDir) {
        File::makeDirectory($sandbox.'/'.$binDir, 0700, true);
        foreach (['mkdir', 'flock', 'sleep'] as $name) {
            symlink(claudeHooksResolveExecutable($name), $sandbox.'/'.$binDir.'/'.$name);
        }
    }
    foreach (['bin', 'bin-notool'] as $binDir) {
        symlink(claudeHooksResolveExecutable('timeout'), $sandbox.'/'.$binDir.'/timeout');
    }

    return $sandbox;
}

/** 索引ツールの stub を置く (起動された事実と引数を `invoked.txt` へ追記する)。 */
function claudeHooksInstallToolStub(string $sandbox, string $tail = "exit 0\n"): void
{
    foreach (['bin', 'bin-notimeout'] as $binDir) {
        $path = $sandbox.'/'.$binDir.'/code-review-graph';
        File::put($path, "#!/bin/bash\nprintf '%s\\n' \"\$*\" >> '{$sandbox}/invoked.txt'\n".$tail);
        chmod($path, 0700);
    }
}

/** 索引ツールが解決できる検索パス。 */
function claudeHooksPathWithTool(string $sandbox): string
{
    return $sandbox.'/bin';
}

/** 索引ツールだけが解決できない検索パス (「未導入」の再現)。 */
function claudeHooksPathWithoutTool(string $sandbox): string
{
    return $sandbox.'/bin-notool';
}

/** `timeout` だけが解決できない検索パス。 */
function claudeHooksPathWithoutTimeout(string $sandbox): string
{
    return $sandbox.'/bin-notimeout';
}

/**
 * 別プロセスで走らせて結果をそろえて返す。
 *
 * @param  list<string>  $command
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRun(array $command, string $input = '', ?string $cwd = null, int $timeout = 90): array
{
    $pending = Process::timeout($timeout)->input($input);
    if ($cwd !== null) {
        $pending = $pending->path($cwd);
    }

    $startedAt = microtime(true);
    $result = $pending->run($command);
    $elapsed = microtime(true) - $startedAt;

    $exitCode = $result->exitCode();
    Assert::integer($exitCode, '子プロセスの終了コードが取れない');

    return [
        'exitCode' => $exitCode,
        'output' => $result->output(),
        'errorOutput' => $result->errorOutput(),
        'elapsed' => $elapsed,
    ];
}

/**
 * 索引更新 hook を sandbox 内で起動する (環境は `env -i` で完全に作り直す)。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunUpdateHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
{
    return claudeHooksRun([
        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
        '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
    ], $input, $cwd);
}

/**
 * bug-hunt ガードを sandbox 内で起動する。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunBughuntHook(string $sandbox, string $input, ?string $path = null, ?string $cwd = null): array
{
    return claudeHooksRun([
        '/usr/bin/env', '-i', 'PATH='.($path ?? claudeHooksPathWithTool($sandbox)),
        '/bin/bash', $sandbox.'/scripts/bughunt-worktree-hook.sh',
    ], $input, $cwd);
}

/** 索引ツールの stub が起動された回数。 */
function claudeHooksInvocations(string $sandbox): int
{
    if (! is_file($sandbox.'/invoked.txt')) {
        return 0;
    }

    return count(array_filter(explode("\n", claudeHooksReadFile($sandbox.'/invoked.txt'))));
}

/** 告知の行数 (標準エラーの非空行)。 */
function claudeHooksWarningLines(string $stderr): int
{
    return count(array_filter(array_map(trim(...), explode("\n", $stderr)), fn (string $l): bool => $l !== ''));
}

/** PostToolUse の入力 payload。 */
function claudeHooksWritePayload(string $filePath, string $sessionId = 'sess-a'): string
{
    return json_encode([
        'session_id' => $sessionId,
        'tool_name' => 'Write',
        'tool_input' => ['file_path' => $filePath],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

/**
 * PreToolUse の入力 payload。
 *
 * `$escapeSlashes` を真にすると `/` を `\/` へ逃がす (JSON として正当な別表記)。
 * 許可シグナルの照合がこの表記でも取りこぼさないことを実証するために使う。
 */
function claudeHooksBashPayload(string $command, string $description = 'x', bool $escapeSlashes = false): string
{
    $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE;
    if (! $escapeSlashes) {
        $flags |= JSON_UNESCAPED_SLASHES;
    }

    return json_encode([
        'session_id' => 'sess-a',
        'tool_name' => 'Bash',
        'tool_input' => ['command' => $command, 'description' => $description],
    ], $flags);
}

/**
 * 「含むこと」を理由付きで検査する。
 *
 * Pest の `toContain()` は可変長引数なので、第 2 引数を理由として渡すと
 * **もう 1 つの検索語**として扱われて必ず落ちる。理由を残したい箇所はこちらを使う。
 */
function claudeHooksExpectContains(string $haystack, string $needle, string $reason): void
{
    expect(str_contains($haystack, $needle))->toBeTrue("{$reason} (期待する文字列: {$needle})");
}

/** 「含まないこと」を理由付きで検査する。 */
function claudeHooksExpectNotContains(string $haystack, string $needle, string $reason): void
{
    expect(str_contains($haystack, $needle))->toBeFalse("{$reason} (現れてはならない文字列: {$needle})");
}

/** 台帳から起動子の実文字列を取り出す (台帳の写しではなく本物を走らせるため)。 */
function claudeHooksLauncherCommand(string $event): string
{
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    Assert::keyExists($settings['hooks'], $event);
    $group = $settings['hooks'][$event];
    Assert::isArray($group);
    Assert::isArray($group[0]);
    Assert::isArray($group[0]['hooks']);
    Assert::isArray($group[0]['hooks'][0]);
    $command = $group[0]['hooks'][0]['command'];
    Assert::string($command);

    return $command;
}

/**
 * 起動子そのものを走らせる。`CLAUDE_PROJECT_DIR` を渡さないときは環境から消える。
 *
 * @return array{exitCode: int, output: string, errorOutput: string, elapsed: float}
 */
function claudeHooksRunLauncher(string $command, ?string $projectDir, ?string $cwd = null): array
{
    $env = ['/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin'];
    if ($projectDir !== null) {
        $env[] = 'CLAUDE_PROJECT_DIR='.$projectDir;
    }

    return claudeHooksRun([...$env, '/bin/bash', '-c', $command], '', $cwd);
}

/** 起動子の内側に置く「終了コードだけを返す」スクリプト。 */
function claudeHooksWriteExitStub(string $path, int $exitCode): void
{
    File::put($path, "#!/bin/bash\nexit {$exitCode}\n");
    chmod($path, 0700);
}

// =============================================================================
// 静的層
// =============================================================================

test('S01: .claude/settings.json が実在し有効な JSON であること', function (): void {
    expect(claudeHooksSettings())->toBeArray();
});

test('S02: .claude/settings.json が git 追跡下にあること (各自任せの見本方式へ戻さない)', function (): void {
    $result = Process::path(base_path())->timeout(30)
        ->run(['git', 'ls-files', '--error-unmatch', '.claude/settings.json']);

    expect($result->exitCode())->toBe(0, '.claude/settings.json が git 追跡下にない');
});

test('S03: トップレベルキーが台帳と完全一致すること (全数申告制)', function (): void {
    $keys = array_keys(claudeHooksSettings());
    sort($keys);
    $expected = CLAUDE_HOOKS_TOP_LEVEL_KEYS;
    sort($expected);

    expect($keys)->toBe($expected, '台帳に無いトップレベルキーは既定拒否 (台帳を同じ変更で更新すること)');
});

test('S04: hooks のイベント集合が台帳と完全一致すること', function (): void {
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);
    $events = array_keys($settings['hooks']);
    sort($events);
    $expected = array_keys(CLAUDE_HOOKS_WIRING);
    sort($expected);

    expect($events)->toBe($expected);
});

test('S05/S06: 各 hook の matcher / 起動文字列 / timeout が台帳と完全一致すること', function (): void {
    $settings = claudeHooksSettings();
    Assert::isArray($settings['hooks']);

    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        $group = $settings['hooks'][$event];
        Assert::isArray($group);
        expect($group)->toHaveCount(count($entries), "{$event} の登録数が台帳と違う");

        foreach ($entries as $index => $entry) {
            $matcherGroup = $group[$index];
            Assert::isArray($matcherGroup);
            expect(array_keys($matcherGroup))->toBe(['matcher', 'hooks']);
            expect($matcherGroup['matcher'])->toBe($entry['matcher']);

            Assert::isArray($matcherGroup['hooks']);
            expect($matcherGroup['hooks'])->toHaveCount(1);
            $hook = $matcherGroup['hooks'][0];
            Assert::isArray($hook);
            expect(array_keys($hook))->toBe(['type', 'command', 'timeout']);
            expect($hook['type'])->toBe('command');
            expect($hook['timeout'])->toBe($entry['timeout']);
            expect($hook['command'])->toBe(
                claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']),
                "{$event} の起動文字列が台帳と 1 文字でも違う",
            );
        }
    }
});

test('S06b: 起動子が privileged mode / 起動先検証 / 終了コード写像の 3 役をすべて持つこと', function (): void {
    // claudeHooksExpectedCommand() は台帳側の組み立てなので、そこが緩んでも S05 は緑のままになる。
    // 「何が書かれていなければならないか」を独立に固定する。
    foreach (CLAUDE_HOOKS_WIRING as $event => $entries) {
        foreach ($entries as $entry) {
            $command = claudeHooksExpectedCommand($entry['script'], $entry['deny_exit_code']);

            expect($command)->toStartWith("/bin/bash -p -c '", "{$event}: 起動子が絶対パス + privileged mode でない");
            claudeHooksExpectContains($command, '/bin/bash -p "$f"', "{$event}: 内側の起動が privileged mode でない");

            foreach ([
                '[ -n "$d" ]',                    // 未設定を弾く
                '[ "${d#/}" != "$d" ]',           // 絶対パスであること
                '[ "${d//../}" = "$d" ]',         // `..` を含まないこと
                '[ -d "$d/scripts" ]',            // scripts が実ディレクトリ
                '[ ! -L "$d/scripts" ]',          // scripts が symlink でない
                '[ -f "$f" ]',                    // 起動先が通常ファイル
                '[ ! -L "$f" ]',                  // 起動先が symlink でない
            ] as $condition) {
                claudeHooksExpectContains($command, $condition, "{$event}: 起動先の検証が無い");
            }

            if ($entry['deny_exit_code'] === null) {
                claudeHooksExpectNotContains($command, 'exit 2', "{$event}: ブロックしない hook が 2 を返しうる");
            } else {
                claudeHooksExpectContains(
                    $command,
                    'if [ "$s" = '.$entry['deny_exit_code'].' ]; then exit 2; fi',
                    "{$event}: 拒否コードの写像が無い",
                );
            }
            expect($command)->toEndWith("exit 0'", "{$event}: 既定で 0 に畳んでいない");
        }
    }
});

test('S07: .claude/settings.local.json は hooks キーを持てないこと (常設配線をローカルから殺さない)', function (): void {
    $path = base_path('.claude/settings.local.json');
    if (! is_file($path)) {
        expect(true)->toBeTrue('ローカル設定は無い (常設配線を上書きする経路も無い)');

        return;
    }

    $decoded = json_decode(claudeHooksReadFile($path), true);
    Assert::isArray($decoded);
    expect(array_key_exists('hooks', $decoded))
        ->toBeFalse('.claude/settings.local.json に hooks を置かないこと (常設配線をローカルから殺す経路になる)');
});

test('S08: 見本ファイル方式が復活していないこと', function (): void {
    expect(is_file(base_path('.claude/settings.bughunt-hook.example.json')))
        ->toBeFalse('オプトインの見本ファイルは常設配線と並走させない (後方互換の並走を残さない)');
});

test('S09: 台帳の 2 スクリプトが実在し bash -n を通ること', function (): void {
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $path = base_path($entry['script']);
            expect(is_file($path))->toBeTrue("{$entry['script']} が無い");

            $result = Process::timeout(30)->run(['bash', '-n', $path]);
            expect($result->exitCode())->toBe(0, "{$entry['script']} が bash -n を通らない:\n".$result->errorOutput());
        }
    }
});

test('S10: 2 本の検索パス安全化ブロックが byte 一致し、どちらもファイル先頭にあること', function (): void {
    $blocks = [];
    $violations = [];

    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            $extracted = claudeHooksPrologueBlock(claudeHooksReadFile(base_path($entry['script'])), $entry['script']);
            $blocks[$entry['script']] = $extracted['block'];
            $violations = [...$violations, ...$extracted['violations']];
        }
    }

    expect($violations)->toBe([], implode("\n", $violations));
    expect(count(array_unique($blocks)))->toBe(1, '2 本の検索パス安全化ブロックが byte 一致していない');
    $block = reset($blocks);
    Assert::string($block);
    claudeHooksExpectContains($block, '_hook_sanitize_path', '安全化の実体がブロックに無い');
});

test('S11: 索引の対象外拡張子が台帳と完全一致すること', function (): void {
    $contents = claudeHooksReadFile(base_path('scripts/code-review-graph-update-hook.sh'));

    expect(preg_match("/^readonly SKIP_EXTENSIONS='([^']*)'$/m", $contents, $matches))
        ->toBe(1, 'SKIP_EXTENSIONS の宣言が見つからない');

    expect(preg_split('/\s+/', trim($matches[1])))->toBe(
        CLAUDE_HOOKS_SKIP_EXTENSIONS,
        '対象外拡張子が台帳と食い違う (索引ツールを更新したら両方を同じ変更で棚卸しすること)',
    );
});

test('S12a: 索引ツール自身に配線を書かせない明文が AGENTS.md にマーカー付きで存在すること', function (): void {
    $agents = claudeHooksReadFile(base_path('AGENTS.md'));

    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
    expect($agents)->toContain('<!-- CLAUDE_HOOKS_WIRING:END -->');

    $begin = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:BEGIN -->');
    $end = strpos($agents, '<!-- CLAUDE_HOOKS_WIRING:END -->');
    Assert::integer($begin);
    Assert::integer($end);
    $section = substr($agents, $begin, $end - $begin);

    foreach (['code-review-graph install', 'uninstall', '.claude/settings.json'] as $needle) {
        claudeHooksExpectContains($section, $needle, '常設 hook 配線の節に必要な記述が無い');
    }
    foreach (CLAUDE_HOOKS_WIRING as $entries) {
        foreach ($entries as $entry) {
            claudeHooksExpectContains($section, $entry['script'], '常設 hook 配線の節に hook の行が無い');
        }
    }
});

test('S12b: 実行面のファイルが索引ツールに配線を書かせる呼び出しを持たないこと', function (): void {
    $violations = [];

    foreach (CLAUDE_HOOKS_TOOL_SELFWIRING_SCAN_GLOBS as $glob) {
        foreach (glob(base_path($glob)) ?: [] as $path) {
            if (! is_file($path)) {
                continue;
            }
            if (preg_match('/code-review-graph\s+(install|init|uninstall)\b/', claudeHooksReadFile($path)) === 1) {
                $violations[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    expect($violations)->toBe([], "配線の正本が二重化する呼び出しがある:\n".implode("\n", $violations));
});

// =============================================================================
// 実起動層: 索引更新 hook (B01〜B25)
// =============================================================================

test('B01: 正常な入力で索引の差分更新が 1 回だけ起動され、静かに 0 で終わること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/Models/User.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
        expect($result['errorOutput'])->toBe('', '成功時は告知しない');
        expect(claudeHooksInvocations($sandbox))->toBe(1);
        claudeHooksExpectContains(
            claudeHooksReadFile($sandbox.'/invoked.txt'),
            'update -q --repo '.$sandbox,
            '差分更新が sandbox のルートを --repo で受け取っていない',
        );
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B02〜B05: 告知は理由ごと・セッションごとに 1 回だけであること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // B02: 索引ツール未導入 → 1 行だけ告知する
        $first = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/A.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
        );
        expect($first['exitCode'])->toBe(0);
        expect($first['output'])->toBe('');
        expect(claudeHooksWarningLines($first['errorOutput']))->toBe(1);
        expect($first['errorOutput'])->toContain('未導入');

        // B03: 同じセッション・同じ理由 → 黙る
        $second = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/B.php', 'sess-1'), claudeHooksPathWithoutTool($sandbox),
        );
        expect(claudeHooksWarningLines($second['errorOutput']))->toBe(0, '同一セッション・同一理由で二重告知した');

        // B04: セッションが変われば再告知する
        $third = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/C.php', 'sess-2'), claudeHooksPathWithoutTool($sandbox),
        );
        expect(claudeHooksWarningLines($third['errorOutput']))->toBe(1);

        // B05: 同じセッションでも理由が違えば告知する (timeout 不在)
        claudeHooksInstallToolStub($sandbox);
        $fourth = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/D.php', 'sess-1'), claudeHooksPathWithoutTimeout($sandbox),
        );
        expect(claudeHooksWarningLines($fourth['errorOutput']))->toBe(1);
        expect($fourth['errorOutput'])->toContain('timeout');
        expect(claudeHooksInvocations($sandbox))->toBe(0, 'timeout が無いのに更新を起動した');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B06/B07: 敵対的な検索パスでもカレントの偽ツールを起動しないこと', function (string $path): void {
    $sandbox = claudeHooksSandbox();

    try {
        // カレントディレクトリに偽の索引ツールを置く (PATH に "." が残っていれば起動される)
        File::makeDirectory($sandbox.'/cwd', 0700, true);
        File::put($sandbox.'/cwd/code-review-graph', "#!/bin/bash\ntouch '{$sandbox}/FAKE-RAN'\n");
        chmod($sandbox.'/cwd/code-review-graph', 0700);

        $result = claudeHooksRunUpdateHook(
            $sandbox, claudeHooksWritePayload('app/A.php'), $path, $sandbox.'/cwd',
        );

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(is_file($sandbox.'/FAKE-RAN'))->toBeFalse("検索パス [{$path}] でカレントの偽ツールが起動された");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'PATH が空' => [''],
    'PATH がカレント' => ['.'],
    'PATH が相対値' => ['relative/bin'],
    'PATH が存在しない絶対パス' => ['/nonexistent-claude-hooks'],
]);

test('B08/B09: 壊れた JSON でも空入力でも 0 で終わること', function (string $input): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    '壊れた JSON' => ['{"session_id": "sess-a", "tool_input": {'],
    '空入力' => [''],
]);

test('B10: 標準入力を閉じない相手に待ち続けないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        // 名前付きパイプの書き手を開いたまま何も書かない = 「閉じない producer」
        $script = <<<BASH
        set -u
        mkfifo '{$sandbox}/pipe'
        sleep 60 > '{$sandbox}/pipe' &
        writer=\$!
        '/bin/bash' '{$sandbox}/scripts/code-review-graph-update-hook.sh' < '{$sandbox}/pipe'
        code=\$?
        kill "\${writer}" 2>/dev/null
        exit "\${code}"
        BASH;

        $result = claudeHooksRun(['/bin/bash', '-c', $script]);

        expect($result['exitCode'])->toBe(0);
        expect($result['elapsed'])->toBeLessThan(30.0, '標準入力の待ちが時間切れで打ち切られていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B11: 1 MiB より後ろにしか手掛かりが無い入力でも待ち続けず 0 で終わること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $input = str_repeat('x', 1_200_000).claudeHooksWritePayload('docs/x.md');
        $result = claudeHooksRunUpdateHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B12〜B14: 置き場・ロックが symlink なら何も書かずに終えること', function (string $case): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        File::makeDirectory($sandbox.'/decoy', 0700, true);

        match ($case) {
            '.claude が symlink' => symlink($sandbox.'/decoy', $sandbox.'/.claude'),
            '置き場が symlink' => (function () use ($sandbox): void {
                File::makeDirectory($sandbox.'/.claude', 0700, true);
                symlink($sandbox.'/decoy', $sandbox.'/.claude/code-review-graph-update-hook');
            })(),
            'ロックが symlink' => (function () use ($sandbox): void {
                File::makeDirectory($sandbox.'/.claude/code-review-graph-update-hook', 0700, true);
                symlink($sandbox.'/decoy/update.lock', $sandbox.'/.claude/code-review-graph-update-hook/update.lock');
            })(),
        };

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$case}: 更新が起動された");
        expect(File::files($sandbox.'/decoy'))->toBe([], "{$case}: symlink の先に書き込まれた");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with(['.claude が symlink', '置き場が symlink', 'ロックが symlink']);

test('B15: 置き場の親が書けなければ黙って 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        File::makeDirectory($sandbox.'/.claude', 0500, true);

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0);
    } finally {
        if (is_dir($sandbox.'/.claude')) {
            chmod($sandbox.'/.claude', 0700);
        }
        File::deleteDirectory($sandbox);
    }
});

test('B16: 他が更新中なら待たずに黙って終えること', function (): void {
    $sandbox = claudeHooksSandbox();
    $holder = null;

    try {
        claudeHooksInstallToolStub($sandbox);
        $stateDir = $sandbox.'/.claude/code-review-graph-update-hook';
        File::makeDirectory($stateDir, 0700, true);

        $holder = Process::timeout(60)->start(['/bin/bash', '-c', <<<BASH
            exec 9> '{$stateDir}/update.lock'
            flock -n 9 || exit 1
            : > '{$sandbox}/HELD'
            sleep 20
            BASH]);

        $waitedUntil = microtime(true) + 15.0;
        while (! is_file($sandbox.'/HELD') && microtime(true) < $waitedUntil) {
            usleep(20_000);
        }
        expect(is_file($sandbox.'/HELD'))->toBeTrue('ロック保持プロセスを起こせなかった');

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, 'ロック競合中に更新が起動された');
        expect($result['elapsed'])->toBeLessThan(10.0, 'ロックを待ってしまっている (非ブロッキングでない)');
    } finally {
        $holder?->stop();
        File::deleteDirectory($sandbox);
    }
});

test('B17: 並行起動しても更新は 1 回だけであること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // 3 秒かかる更新にして、後続が確実にロック競合へ落ちるようにする
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 3\n");

        $startedAt = microtime(true);
        $processes = [];
        for ($i = 0; $i < 5; $i++) {
            $processes[] = Process::timeout(60)
                ->input(claudeHooksWritePayload("app/File{$i}.php", "sess-{$i}"))
                ->start([
                    '/usr/bin/env', '-i', 'PATH='.claudeHooksPathWithTool($sandbox),
                    '/bin/bash', $sandbox.'/scripts/code-review-graph-update-hook.sh',
                ]);
        }
        foreach ($processes as $process) {
            expect($process->wait()->exitCode())->toBe(0);
        }
        $elapsed = microtime(true) - $startedAt;

        expect(claudeHooksInvocations($sandbox))->toBe(1, '排他が効いておらず更新が重複起動された');
        expect($elapsed)->toBeLessThan(30.0, '呼び出し側 timeout (30 秒) を超えた');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B18: 終わらない更新を内側の時間切れで打ち切り、その旨を 1 行告知すること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox, "exec '".claudeHooksResolveExecutable('sleep')."' 120\n");

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
        expect($result['errorOutput'])->toContain('20 秒');
        expect($result['elapsed'])->toBeLessThan(45.0, '内側の時間切れ (20 秒) が効いていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B19: 更新が失敗したらその旨を 1 行告知して 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox, "exit 3\n");

        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect(claudeHooksWarningLines($result['errorOutput']))->toBe(1);
        expect($result['errorOutput'])->toContain('終了コード 3');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B20: 細工されたセッション識別子で置き場の外にファイルを作らないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = '{"session_id":"../../'.basename($sandbox).'-escape","tool_input":{"file_path":"app/A.php"}}';
        $result = claudeHooksRunUpdateHook($sandbox, $payload, claudeHooksPathWithoutTool($sandbox));

        expect($result['exitCode'])->toBe(0);
        // 置き場に出来てよいのはロックと告知の目印だけで、いずれも識別子が正規化されたもの
        foreach (File::files($sandbox.'/.claude/code-review-graph-update-hook') as $file) {
            expect(in_array($file->getFilename(), ['update.lock', 'warned-tool-missing-unknown'], true))
                ->toBeTrue('置き場に想定外のファイルが出来た: '.$file->getFilename());
        }
        expect(glob(dirname($sandbox).'/*-escape') ?: [])->toBe([], '置き場の外にファイルが作られた');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B21/B22: 索引の対象外拡張子では副作用ゼロで終えること', function (string $filePath): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));

        expect($result['exitCode'])->toBe(0);
        expect($result['output'])->toBe('');
        expect($result['errorOutput'])->toBe('');
        expect(claudeHooksInvocations($sandbox))->toBe(0, "{$filePath} で更新が起動された");
        expect(is_dir($sandbox.'/.claude'))->toBeFalse("{$filePath} で置き場が作られた (副作用ゼロでない)");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'docs/x.md' => ['docs/x.md'],
    '大文字の拡張子' => ['docs/x.MD'],
    'package.json' => ['package.json'],
    'pnpm-lock.yaml' => ['pnpm-lock.yaml'],
]);

test('B23/B24: 判定できない入力は更新する側へ倒すこと', function (string $filePath): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload($filePath));

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksInvocations($sandbox))->toBe(1, "{$filePath} で更新が起動されなかった");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'blade の複合拡張子' => ['resources/views/x.blade.php'],
    '拡張子なし' => ['Makefile'],
    'svelte' => ['resources/js/x.svelte'],
]);

test('B25: 作業ディレクトリと環境変数に依存せずリポジトリルートを解決すること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksInstallToolStub($sandbox);
        // cwd を / にし、CLAUDE_PROJECT_DIR も渡さない (env -i なので元から無い)
        $result = claudeHooksRunUpdateHook($sandbox, claudeHooksWritePayload('app/A.php'), null, '/');

        expect($result['exitCode'])->toBe(0);
        expect(claudeHooksReadFile($sandbox.'/invoked.txt'))->toContain('--repo '.$sandbox);
    } finally {
        File::deleteDirectory($sandbox);
    }
});

// =============================================================================
// 実起動層: bug-hunt ガード (B26〜B40b)
// =============================================================================

test('B26/B28/B30〜B33/B40/B40b: provision の直叩きだけを拒否すること', function (string $command, int $expected): void {
    $sandbox = claudeHooksSandbox();

    try {
        $result = claudeHooksRunBughuntHook($sandbox, claudeHooksBashPayload($command));

        expect($result['exitCode'])->toBe($expected, "コマンド [{$command}] の判定が違う");
        expect($result['output'])->toBe('', '標準出力は常に空でなければならない');
        if ($expected === 97) {
            expect($result['errorOutput'])->toContain('bug-hunt provision');
        } else {
            expect($result['errorOutput'])->toBe('');
        }
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B26 無関係なコマンド' => ['ls -la', 0],
    'B28 main からの直叩き' => ['scripts/bug-hunt-shard.sh provision --shard 1', 97],
    'B30 worktree から' => ['cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision', 0],
    'B31 明示解除' => ['BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B32 self-test dryrun' => ['BUGHUNT_SELFTEST_DRYRUN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B40 間に別語が入る言及' => ['scripts/bug-hunt-shard.sh scaffold x provision', 0],
    'B40b provision-all' => ['scripts/bug-hunt-shard.sh provision-all', 97],
]);

test('B37: JSON が / を \\/ へ逃がしていても worktree の指紋を取りこぼさないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = claudeHooksBashPayload(
            'cd .claude/worktrees/tasks/x && scripts/bug-hunt-shard.sh provision',
            'x',
            escapeSlashes: true,
        );
        claudeHooksExpectContains($payload, '.claude\\/worktrees\\/', 'テスト入力が逃がし表記になっていない');

        expect(claudeHooksRunBughuntHook($sandbox, $payload)['exitCode'])
            ->toBe(0, '逃がし表記の worktree パスを許可シグナルとして拾えていない');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B33: 説明文だけに provision があっても誤発火しないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $payload = claudeHooksBashPayload('echo hello', 'scripts/bug-hunt-shard.sh provision の説明');
        $result = claudeHooksRunBughuntHook($sandbox, $payload);

        expect($result['exitCode'])->toBe(0, '抽出値ではなく生入力で判定している');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B27/B29: 検索パスが壊れていても判定が変わらず、外部コマンドを 1 つも起こさないこと', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        // カレントに偽の判定用コマンドを置く (以前の実装はこれらに依存していた)
        File::makeDirectory($sandbox.'/cwd', 0700, true);
        foreach (['cat', 'grep', 'python3', 'printf'] as $name) {
            File::put($sandbox.'/cwd/'.$name, "#!/bin/bash\ntouch '{$sandbox}/FAKE-{$name}'\n");
            chmod($sandbox.'/cwd/'.$name, 0700);
        }

        // B27: 無関係なコマンド + 敵対的な検索パス → 0 のまま
        $passing = claudeHooksRunBughuntHook(
            $sandbox, claudeHooksBashPayload('ls -la'), '/nonexistent-claude-hooks', $sandbox.'/cwd',
        );
        expect($passing['exitCode'])->toBe(0);

        // B29: 拒否対象 + 空の検索パス → 無音の素通りをしない
        $denied = claudeHooksRunBughuntHook(
            $sandbox, claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision'), '', $sandbox.'/cwd',
        );
        expect($denied['exitCode'])->toBe(97, '検索パスが壊れると拒否対象が黙って通っている');
        expect($denied['errorOutput'])->toContain('bug-hunt provision');

        expect(glob($sandbox.'/FAKE-*') ?: [])->toBe([], '判定経路が外部コマンドに依存している');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B34〜B36: JSON を解釈できないときは明示解除だけを見ること', function (string $input, int $expected): void {
    $sandbox = claudeHooksSandbox();

    try {
        $result = claudeHooksRunBughuntHook($sandbox, $input);

        expect($result['exitCode'])->toBe($expected);
        expect($result['output'])->toBe('');
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B34 解除なし' => ['{"tool_input": {"comm scripts/bug-hunt-shard.sh provision', 97],
    'B35 明示解除あり' => ['{"tool_input": {"comm BUGHUNT_ALLOW_MAIN=1 scripts/bug-hunt-shard.sh provision', 0],
    'B36 痕跡だけ' => ['{"tool_input": {"comm .claude/worktrees/ scripts/bug-hunt-shard.sh provision', 97],
]);

test('B38: 標準入力が空でも閉じない相手でも 0 で終えること', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        expect(claudeHooksRunBughuntHook($sandbox, '')['exitCode'])->toBe(0);

        $script = <<<BASH
        set -u
        mkfifo '{$sandbox}/pipe'
        sleep 60 > '{$sandbox}/pipe' &
        writer=\$!
        '/bin/bash' '{$sandbox}/scripts/bughunt-worktree-hook.sh' < '{$sandbox}/pipe'
        code=\$?
        kill "\${writer}" 2>/dev/null
        exit "\${code}"
        BASH;

        $result = claudeHooksRun(['/bin/bash', '-c', $script]);
        expect($result['exitCode'])->toBe(0);
        expect($result['elapsed'])->toBeLessThan(30.0);
    } finally {
        File::deleteDirectory($sandbox);
    }
});

test('B39: 1 MiB より後ろにしか対象語が無い入力では通す (受容済みの限界)', function (): void {
    $sandbox = claudeHooksSandbox();

    try {
        $input = str_repeat('x', 1_200_000).claudeHooksBashPayload('scripts/bug-hunt-shard.sh provision');
        $result = claudeHooksRunBughuntHook($sandbox, $input);

        expect($result['exitCode'])->toBe(0, '読み取り上限を超えた入力は通す (待ち続けないことを優先する)');
    } finally {
        File::deleteDirectory($sandbox);
    }
});

// =============================================================================
// 実起動層: 起動子 (B41〜B51)
// =============================================================================

test('B41〜B49: PreToolUse の起動子が 97 だけを 2 へ写し、それ以外を 0 に畳むこと', function (string $case, int $expected): void {
    $sandbox = claudeHooksSandbox();
    $command = claudeHooksLauncherCommand('PreToolUse');
    $script = $sandbox.'/scripts/bughunt-worktree-hook.sh';
    $projectDir = $sandbox;
    $cwd = null;

    try {
        match ($case) {
            'B41 拒否 (97)' => claudeHooksWriteExitStub($script, 97),
            'B42 通過 (0)' => claudeHooksWriteExitStub($script, 0),
            'B43 構文エラー (2)' => claudeHooksWriteExitStub($script, 2),
            'B44 起動先が無い' => File::delete($script),
            'B45 CLAUDE_PROJECT_DIR が無い' => (function () use ($script, &$projectDir): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = null;
            })(),
            'B46 相対値' => (function () use ($script, $sandbox, &$projectDir, &$cwd): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = basename($sandbox);
                $cwd = dirname($sandbox);
            })(),
            'B47 .. を含む' => (function () use ($script, $sandbox, &$projectDir): void {
                claudeHooksWriteExitStub($script, 97);
                $projectDir = dirname($sandbox).'/../'.basename(dirname($sandbox)).'/'.basename($sandbox);
            })(),
            'B48 scripts が symlink' => (function () use ($script, $sandbox): void {
                claudeHooksWriteExitStub($script, 97);
                rename($sandbox.'/scripts', $sandbox.'/real-scripts');
                symlink($sandbox.'/real-scripts', $sandbox.'/scripts');
            })(),
            'B49 起動先が symlink' => (function () use ($script, $sandbox): void {
                claudeHooksWriteExitStub($sandbox.'/scripts/real-hook.sh', 97);
                File::delete($script);
                symlink($sandbox.'/scripts/real-hook.sh', $script);
            })(),
        };

        $result = claudeHooksRunLauncher($command, $projectDir, $cwd);

        expect($result['exitCode'])->toBe($expected, "{$case}: 起動子の写像が違う");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([
    'B41 拒否 (97)' => ['B41 拒否 (97)', 2],
    'B42 通過 (0)' => ['B42 通過 (0)', 0],
    'B43 構文エラー (2)' => ['B43 構文エラー (2)', 0],
    'B44 起動先が無い' => ['B44 起動先が無い', 0],
    'B45 CLAUDE_PROJECT_DIR が無い' => ['B45 CLAUDE_PROJECT_DIR が無い', 0],
    'B46 相対値' => ['B46 相対値', 0],
    'B47 .. を含む' => ['B47 .. を含む', 0],
    'B48 scripts が symlink' => ['B48 scripts が symlink', 0],
    'B49 起動先が symlink' => ['B49 起動先が symlink', 0],
]);

test('B50: PostToolUse の起動子は内側の終了コードにかかわらず常に 0 を返すこと', function (int $inner): void {
    $sandbox = claudeHooksSandbox();

    try {
        claudeHooksWriteExitStub($sandbox.'/scripts/code-review-graph-update-hook.sh', $inner);
        $result = claudeHooksRunLauncher(claudeHooksLauncherCommand('PostToolUse'), $sandbox);

        expect($result['exitCode'])->toBe(0, "内側が {$inner} のとき起動子が 0 を返していない");
    } finally {
        File::deleteDirectory($sandbox);
    }
})->with([[0], [1], [2], [97], [127]]);

test('B51: 起動子が環境からのシェル関数を内側へ継承させないこと (privileged mode)', function (): void {
    $sandbox = claudeHooksSandbox();
    $command = claudeHooksLauncherCommand('PreToolUse');

    try {
        // 内側で「注入した関数が見えるか」を自分で記録するスクリプト
        File::put($sandbox.'/scripts/bughunt-worktree-hook.sh', <<<BASH
        #!/bin/bash
        if [ "\$(type -t claude_hooks_probe)" = "function" ]; then
            touch '{$sandbox}/FUNC-LEAKED'
        fi
        exit 0
        BASH);
        chmod($sandbox.'/scripts/bughunt-worktree-hook.sh', 0700);

        $wrapper = "claude_hooks_probe() { :; }\nexport -f claude_hooks_probe\nexec ".$command;
        $result = claudeHooksRun([
            '/usr/bin/env', '-i', 'PATH=/usr/local/bin:/usr/bin:/bin', 'CLAUDE_PROJECT_DIR='.$sandbox,
            '/bin/bash', '-c', $wrapper,
        ]);

        expect($result['exitCode'])->toBe(0);
        expect(is_file($sandbox.'/FUNC-LEAKED'))
            ->toBeFalse('環境から注入したシェル関数が hook へ継承された (privileged mode が効いていない)');
    } finally {
        File::deleteDirectory($sandbox);
    }
});
