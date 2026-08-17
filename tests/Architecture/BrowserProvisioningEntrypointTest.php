<?php

declare(strict_types=1);

use Webmozart\Assert\Assert;

/*
 * ブラウザ導入経路の一元化 gate。
 *
 * 「Browser テスト用のブラウザを入れる」という操作を**実行する**箇所は
 * scripts/setup-browser-testing.sh ただ 1 つである。導入が 2 箇所に増えると、
 * 対象ブラウザ集合 (chromium + webkit) と OS 共有ライブラリの要否判定が二重管理になり、
 * 「片方だけ直して、もう片方で WebKit が全 fail する」という過去の失敗の再来になる。
 *
 * **母集団は「実行される場所」に限る** (deny-by-default だが、対象は狭く精密に取る):
 *   1. scripts/ 配下の shell スクリプト (**再帰走査**。scripts/tools/foo.sh も母集団に入る) の
 *      **実行行** (行頭 # のコメント行を除く)
 *   2. composer.json / package.json の scripts に書かれたコマンド
 *   3. docker/Dockerfile の **命令部** (行頭 # のコメント行を除く)
 *
 * **照合の前に 2 つの正規化を行う** (行単位の素朴な照合は簡単に迂回できる):
 *   - コメント行の除去 (説明文の言及で偽赤にしない)
 *   - **行継続 (`\` + 改行 + 先頭空白) を空白へ畳む** —
 *     `pnpm exec playwright \`+改行+`    install chromium` は行単位では素通りする。
 *     shell と Dockerfile の両方に効かせる
 *
 * **保証しないもの (誇張しない)**:
 *   - 手順書・コメント・設計文書の**言及**は対象外である (docs/testing-browser.md /
 *     docker/Dockerfile のコメント / devnotes/)。禁じたいのは実行であって説明ではない。
 *   - .github/workflows/*.yml は本テストの母集団に**入れない** —
 *     YAML の実行行の検査は tests/js/architecture/ci-workflow-inventory.test.ts の W20 が担う
 *     (同じ事実を 2 箇所で検査しない。あちらは既に YAML を parse している)。
 *   - .claude/skills/app-bug-hunt/ は対象外。bug-hunt は @playwright/cli という
 *     **別の導入経路**を意図的に持つ (AGENTS.md §bug-hunt)。
 *   - 変数へ組み立ててから実行する形 (`cmd="playwright install"; $cmd`) は検出しない。
 *
 * 本テストは DB を触らない (ファイル読み取りのみ)。
 */

/** 導入を実行してよい唯一のファイル (リポジトリルートからの相対パス)。 */
const BROWSER_PROVISIONING_SINGLE_SOURCE = 'scripts/setup-browser-testing.sh';

/**
 * 走査する「実行される場所」のうち、scripts/ 配下以外の固定分。
 *
 * @var list<string>
 */
const BROWSER_PROVISIONING_SCANNED_FILES = [
    'composer.json',
    'package.json',
    'docker/Dockerfile',
];

/**
 * 導入コマンドとみなすパターン。
 * 単純部分一致 (`'playwright install'`) にすると `playwright   install` のような
 * 空白差分を見逃すため、正規表現で見る。`install-deps` も `\binstall\b` に一致するので
 * 同じ規則で捕まる (意図どおり)。
 */
const BROWSER_PROVISIONING_PATTERN = '/\bplaywright\s+install\b/';

/**
 * 行頭 (空白を除く) が `#` の行を落とし、**行継続を畳んでから**実行行を返す (純関数)。
 *
 * `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
 * 文字途中で分断する (`PcreUnicodeModifierGateTest` が全数を固定している。本ファイルは
 * `\R` を使わず改行を明示列挙する)。
 *
 * 順序は「コメント除去 → 行継続の畳み込み」。逆にすると、継続行の途中にある `#` の扱いが
 * 変わって取りこぼす。
 *
 * @return list<string> 実行行 (1 始まりの行番号は保持しない)
 */
function browserProvisioningCodeLines(string $source): array
{
    $kept = [];
    foreach (preg_split('/\r\n|\r|\n/u', $source) ?: [] as $line) {
        if (preg_match('/^\s*#/u', $line) === 1) {
            continue;
        }
        $kept[] = $line;
    }

    $folded = preg_replace('/\\\\\n[ \t]*/u', ' ', implode("\n", $kept));
    Assert::string($folded, '行継続の畳み込みに失敗した');

    $lines = [];
    foreach (preg_split('/\n/u', $folded) ?: [] as $line) {
        if (trim($line) === '') {
            continue;
        }
        $lines[] = $line;
    }

    return $lines;
}

/**
 * `scripts/` 配下の shell スクリプトを **再帰的に** 列挙する (純関数)。
 *
 * `glob('scripts/*.sh')` では `scripts/tools/install-browser.sh` を取りこぼす
 * (2 階層だけを見る実装は将来のサブディレクトリを黙って漏らすので、再帰列挙を使う)。
 *
 * @return list<string> 引数ディレクトリからの相対パス (昇順)
 */
function browserProvisioningShellScripts(string $scriptsDir): array
{
    $found = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scriptsDir, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile()) {
            continue;
        }
        if ($file->getExtension() !== 'sh') {
            continue;
        }
        $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($scriptsDir) + 1));
    }

    sort($found);

    return $found;
}

/**
 * 走査対象のリポジトリルート相対パスを返す。
 *
 * @return list<string>
 */
function browserProvisioningScanTargets(): array
{
    $targets = BROWSER_PROVISIONING_SCANNED_FILES;
    foreach (browserProvisioningShellScripts(base_path('scripts')) as $relative) {
        $targets[] = 'scripts/'.$relative;
    }

    return $targets;
}

/**
 * composer.json / package.json の `scripts` から実行コマンド文字列を取り出す (純関数)。
 *
 * `composer.json` の値は **文字列と配列の両方**を取るので両方を受ける。
 * **想定外の型は違反として列挙する** (静かに素通りさせない)。
 *
 * ここで `Assert::isArray()` / `Assert::string()` を使わないのは、Assert が投げる例外で
 * テストが即座に止まり、**同じ実行で見つかるはずだった他ファイルの違反が失われる**ためである
 * (想定外の型はここでは「報告すべき違反」であって「前提の破れ」ではない)。
 * 段階的な narrow という意図は `is_array()` → `is_string()` の順で同じく満たしている。
 *
 * @return array{commands: list<string>, errors: list<string>}
 */
function browserProvisioningJsonScriptCommands(string $relative, string $contents): array
{
    $decoded = json_decode($contents, true);
    if (! is_array($decoded)) {
        return ['commands' => [], 'errors' => ["{$relative}: JSON として読めない"]];
    }

    $scripts = $decoded['scripts'] ?? [];
    if (! is_array($scripts)) {
        return ['commands' => [], 'errors' => ["{$relative}: scripts が想定外の型 (配列でない)"]];
    }

    $commands = [];
    $errors = [];
    foreach ($scripts as $name => $value) {
        $key = (string) $name;
        if (is_string($value)) {
            $commands[] = $value;

            continue;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item)) {
                    $commands[] = $item;

                    continue;
                }
                $errors[] = "{$relative}: scripts.{$key} の要素が想定外の型 (文字列でない)";
            }

            continue;
        }
        $errors[] = "{$relative}: scripts.{$key} が想定外の型 (文字列でも配列でもない)";
    }

    return ['commands' => $commands, 'errors' => $errors];
}

/**
 * 「相対パス => 中身」から違反を列挙する (純関数。負のコントロールを fixture で駆動するため)。
 *
 * @param  array<string, string>  $files
 * @return list<string>
 */
function browserProvisioningViolations(array $files): array
{
    $violations = [];

    foreach ($files as $relative => $contents) {
        if ($relative === BROWSER_PROVISIONING_SINGLE_SOURCE) {
            continue;
        }

        if (str_ends_with($relative, '.json')) {
            $parsed = browserProvisioningJsonScriptCommands($relative, $contents);
            foreach ($parsed['errors'] as $error) {
                $violations[] = $error;
            }
            foreach ($parsed['commands'] as $command) {
                if (preg_match(BROWSER_PROVISIONING_PATTERN, $command) === 1) {
                    $violations[] = "{$relative}: scripts が導入コマンドを実行している ({$command})";
                }
            }

            continue;
        }

        foreach (browserProvisioningCodeLines($contents) as $line) {
            if (preg_match(BROWSER_PROVISIONING_PATTERN, $line) === 1) {
                $violations[] = "{$relative}: 導入コマンドを実行している (".trim($line).')';
            }
        }
    }

    sort($violations);

    return $violations;
}

test('導入コマンドを実行するのは scripts/setup-browser-testing.sh だけであること', function (): void {
    $files = [];
    foreach (browserProvisioningScanTargets() as $relative) {
        $contents = file_get_contents(base_path($relative));
        Assert::string($contents, "{$relative} を読み込めません");
        $files[$relative] = $contents;
    }

    // 空振り防止: 走査対象が 1 件も無い状態で緑にならないこと
    expect(count($files))->toBeGreaterThan(count(BROWSER_PROVISIONING_SCANNED_FILES));

    $violations = browserProvisioningViolations($files);
    expect($violations)->toBe([], "ブラウザ導入経路の増殖:\n".implode("\n", $violations));
});

/*
 * 実行ビットは git が追跡する。付け忘れると CI (bash 経由なので動く) は緑のまま、
 * ローカルの直接実行だけが落ちるという分かりにくい差になるので機械で固定する。
 */
test('単一情報源のファイルが実在し、実行可能で、対象ブラウザ 2 つを持つこと', function (): void {
    $path = base_path(BROWSER_PROVISIONING_SINGLE_SOURCE);
    Assert::fileExists($path);
    expect(is_executable($path))->toBeTrue();

    $source = file_get_contents($path);
    Assert::string($source);
    expect($source)->toContain('BROWSER_TARGETS=(chromium webkit)');
});

test('負のコントロール: scripts/ の別スクリプトが導入を実行したら検出すること', function (): void {
    $violations = browserProvisioningViolations([
        BROWSER_PROVISIONING_SINGLE_SOURCE => "pnpm exec playwright install chromium webkit\n",
        'scripts/prepare-browser-ci.sh' => "#!/usr/bin/env bash\npnpm exec playwright install chromium\n",
    ]);

    expect($violations)->toBe([
        'scripts/prepare-browser-ci.sh: 導入コマンドを実行している (pnpm exec playwright install chromium)',
    ]);
});

test('負のコントロール: 入れ子の scripts/tools/foo.sh も母集団に入ること', function (): void {
    $dir = sys_get_temp_dir().'/browser-provisioning-'.bin2hex(random_bytes(6));
    mkdir($dir.'/tools', 0o777, true);
    file_put_contents($dir.'/a.sh', "echo a\n");
    file_put_contents($dir.'/tools/foo.sh', "echo foo\n");
    file_put_contents($dir.'/tools/notes.md', "playwright install\n");

    try {
        expect(browserProvisioningShellScripts($dir))->toBe(['a.sh', 'tools/foo.sh']);
    } finally {
        array_map(unlink(...), [$dir.'/a.sh', $dir.'/tools/foo.sh', $dir.'/tools/notes.md']);
        rmdir($dir.'/tools');
        rmdir($dir);
    }
});

test('負のコントロール: composer.json の script (文字列 / 配列の両形式) を検出すること', function (): void {
    $violations = browserProvisioningViolations([
        'composer.json' => json_encode(['scripts' => ['setup' => 'pnpm exec playwright install chromium']], JSON_THROW_ON_ERROR),
        'package.json' => json_encode(['scripts' => ['prep' => ['@php artisan x', 'playwright install webkit']]], JSON_THROW_ON_ERROR),
    ]);

    expect($violations)->toBe([
        'composer.json: scripts が導入コマンドを実行している (pnpm exec playwright install chromium)',
        'package.json: scripts が導入コマンドを実行している (playwright install webkit)',
    ]);
});

test('負のコントロール: composer.json の scripts が想定外の型なら違反として列挙すること', function (): void {
    $violations = browserProvisioningViolations([
        'composer.json' => json_encode(['scripts' => 'not-an-object'], JSON_THROW_ON_ERROR),
        'package.json' => json_encode(['scripts' => ['prep' => 42]], JSON_THROW_ON_ERROR),
    ]);

    expect($violations)->toContain('composer.json: scripts が想定外の型 (配列でない)');
    expect($violations)->toContain('package.json: scripts.prep が想定外の型 (文字列でも配列でもない)');
});

test('負のコントロール: 空白差分 `playwright   install` を検出すること', function (): void {
    $violations = browserProvisioningViolations([
        'scripts/x.sh' => "pnpm exec playwright   install chromium\n",
    ]);

    expect($violations)->toHaveCount(1);
});

test('負のコントロール: 行継続で 2 行に割った導入を shell / Dockerfile の両方で検出すること', function (): void {
    $violations = browserProvisioningViolations([
        'scripts/x.sh' => "pnpm exec playwright \\\n    install chromium webkit\n",
        'docker/Dockerfile' => "RUN pnpm exec playwright \\\n    install chromium\n",
    ]);

    expect($violations)->toHaveCount(2);
});

test('負のコントロール: コメント行の言及は検出しないこと (偽陽性を作らない)', function (): void {
    $violations = browserProvisioningViolations([
        'scripts/x.sh' => "# chromium 本体は `pnpm exec playwright install chromium` で入る\necho ok\n",
        'docker/Dockerfile' => "# playwright install chromium は Dockerfile では行わない\nRUN echo ok\n",
    ]);

    expect($violations)->toBe([]);
});
