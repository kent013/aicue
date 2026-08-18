<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt 目録のドリフト検査の契約 (T176 で作り替え)。
 *
 * 目録 (.claude/skills/app-bug-hunt/{screens,operations}.md) は**生成物**であり、
 * 判定は scripts/bug-hunt-inventory.py に一本化してある。シェル
 * (scripts/bug-hunt-inventory-check.sh) は起動するだけで判定を持たない。
 *
 * 固定する不変条件:
 *   - シェルが存在し実行可能で、fail-closed (set -euo pipefail) であること
 *   - シェルが判定 (route:list / grep / 対象外の語彙) を**持たない**こと
 *   - 目録の正本パスを持つのは生成器側であること
 *   - **exit code 規約 0=一致 / 2=致命 / 3=ドリフト** を実際に満たすこと
 *   - 抽出に失敗したときに exit 0 (fail-open) を返さないこと
 *
 * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 artisan
 * (boot + APP_KEY + DB) には依存させない: 一時 sandbox へ道具一式を複製し、`php` を
 * 固定の scan JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
 *
 * ★空振り検査 (母集団非空) の付与対象外である。理由:
 *   本 gate は**ディレクトリを列挙して母集団を作らない**。見るのは名指しの 2 ファイル
 *   (`scripts/bug-hunt-inventory-check.sh` / `scripts/bug-hunt-inventory.py`) と、
 *   テスト自身が組み立てた sandbox の fixture だけである。走査根の改名・移動は
 *   「母集団が 0 件になって緑」ではなく `Assert::fileExists` / `expect(file_exists(...))` の
 *   即時 fail になる (= 無言の空振りが起きる形になっていない)。
 *   シェルの実装行を読む `bhicShellCodeLines()` だけは列挙に近いが、その非空は
 *   同じケース内の必須語句検査 (`expect($code)->toContain('scripts/bug-hunt-inventory.py')`) が
 *   先に落ちることで担保されている。
 *   なお目録の生成器が母集合 0 件を検出する責務は生成器側 (段 1) が持つ。
 */

function bhicScriptPath(): string
{
    return base_path('scripts/bug-hunt-inventory-check.sh');
}

function bhicGeneratorPath(): string
{
    return base_path('scripts/bug-hunt-inventory.py');
}

/** sandbox 内の相対パス (生成器が持つ正本パスと同じ場所へ置く)。 */
const BHIC_SKILL_DIR = '.claude/skills/app-bug-hunt';

/**
 * sandbox を組み立てる。scripts/ に検査シェルと生成器、skill ディレクトリに注釈・散文ノート・
 * 機能カタログ、bin/ に `php` shim (固定の scan JSON を吐く) を置く。
 *
 * 目録 2 ファイルは置かない (テスト側が generate を走らせて作る = 実挙動で組み立てる)。
 *
 * @param  bool  $phpFails  true なら shim が失敗する (抽出失敗の再現)
 */
function bhicMakeSandbox(bool $phpFails = false): string
{
    $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/scripts', 0o755, true);
    mkdir($sandbox.'/'.BHIC_SKILL_DIR.'/inventory', 0o755, true);
    mkdir($sandbox.'/bin', 0o755, true);

    copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
    copy(bhicGeneratorPath(), $sandbox.'/scripts/bug-hunt-inventory.py');

    $scan = [
        'schema_version' => 1,
        'extraction_condition' => 'local-or-unit-tests',
        'routes' => [
            [
                'name' => 'dashboard', 'uri' => 'dashboard', 'methods' => ['GET', 'HEAD'],
                'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@index', 'title' => 'ダッシュボード',
            ],
            [
                'name' => 'projects.store', 'uri' => 'projects', 'methods' => ['POST'],
                'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@store', 'title' => null,
            ],
        ],
    ];
    $json = json_encode($scan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    file_put_contents($sandbox.'/scan.json', $json);

    file_put_contents(
        $sandbox.'/'.BHIC_SKILL_DIR.'/inventory/annotations.toml',
        "schema_version = 1\n\n[routes.\"dashboard\"]\nkind = \"画面\"\nstory = \"S1\"\nkubun = \"通常\"\n\n"
        ."[routes.\"projects.store\"]\nstory = \"S4\"\nkubun = \"通常\"\n"
    );
    file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-screens.md', "## 画面の散文\n\n人が書く。\n");
    file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/inventory/notes-operations.md', "## 操作の散文\n\n人が書く。\n");
    file_put_contents(
        $sandbox.'/'.BHIC_SKILL_DIR.'/capability-catalog.md',
        "# Capability Catalog\n\n| id | 機能 (actor→outcome) | 代表機構 (route name) |\n|---|---|---|\n"
        ."| PROJ-01 | owner→プロジェクト作成 | `projects.store` |\n"
    );

    $shim = $phpFails
        ? "#!/usr/bin/env bash\necho 'inventory-scan failed' >&2\nexit 1\n"
        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../scan.json\"\n";
    file_put_contents($sandbox.'/bin/php', $shim);
    chmod($sandbox.'/bin/php', 0o755);

    return $sandbox;
}

function bhicRemoveSandbox(string $sandbox): void
{
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        if (! $entry instanceof SplFileInfo) {
            continue;
        }
        $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($sandbox);
}

/**
 * python3 の存在を **前提条件として固定**する (skip しない)。
 *
 * 判定は python3 の生成器が持つ。python3 が無い環境では検査そのものが動かないため、
 * skip して green にすると「exit code 規約が未検証のまま合格」になる。
 * 環境不備は skip ではなく fail として顕在化させる。
 */
function bhicRequirePython3(): void
{
    expect((new Process(['which', 'python3']))->run())->toBe(
        0,
        'python3 が PATH に無い。bug-hunt 目録の検査は python3 必須 (環境不備を skip で隠さない)'
    );
}

/**
 * sandbox 内でコマンドを走らせ [exitCode, output] を返す。
 *
 * @param  list<string>  $command
 * @return array{0: int|null, 1: string}
 */
function bhicRunSandbox(string $sandbox, array $command): array
{
    $process = new Process(
        $command,
        $sandbox,
        ['PATH' => $sandbox.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')]
    );
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

/**
 * sandbox で目録を生成してから検査シェルを走らせる。
 *
 * @return array{0: int|null, 1: string}
 */
function bhicGenerate(string $sandbox): array
{
    return bhicRunSandbox($sandbox, ['python3', $sandbox.'/scripts/bug-hunt-inventory.py', 'generate']);
}

/** @return array{0: int|null, 1: string} */
function bhicCheck(string $sandbox): array
{
    return bhicRunSandbox($sandbox, ['bash', $sandbox.'/scripts/bug-hunt-inventory-check.sh']);
}

/** シェルの実装行 (コメント行と空行を除く)。説明コメントで誤検知しないため。 */
function bhicShellCodeLines(): string
{
    $src = file_get_contents(bhicScriptPath());
    expect($src)->toBeString();
    /** @var string $src */
    $lines = array_filter(
        explode("\n", $src),
        static fn (string $line): bool => trim($line) !== '' && ! str_starts_with(trim($line), '#'),
    );

    return implode("\n", $lines);
}

test('scripts/bug-hunt-inventory-check.sh が存在し実行可能で fail-closed であること', function (): void {
    $path = bhicScriptPath();
    expect(file_exists($path))->toBeTrue('bug-hunt-inventory-check.sh が見つからない');
    expect(is_executable($path))->toBeTrue('bug-hunt-inventory-check.sh に実行権が無い');

    $src = file_get_contents($path);
    expect($src)->toBeString();
    /** @var string $src */
    expect($src)->toContain('set -euo pipefail');
    // exit 規約の宣言がスクリプト冒頭に残ること (運用者向けの契約表明)。
    expect($src)->toMatch('/exit 0=.*2=.*3=/u');
});

test('シェルが判定を持たず、生成器を起動するだけであること', function (): void {
    $code = bhicShellCodeLines();

    expect($code)->toContain('scripts/bug-hunt-inventory.py');
    // 判定の語彙がシェルへ戻っていないこと (同じ規則を 2 か所に置かない)。
    foreach (['route:list', 'grep', 'OUT_OF_SCOPE', 'python3 -c'] as $forbidden) {
        expect(str_contains($code, $forbidden))->toBeFalse("判定がシェルへ戻っている: {$forbidden}");
    }
});

test('目録の正本パスを持つのは生成器側であること', function (): void {
    $src = file_get_contents(bhicGeneratorPath());
    expect($src)->toBeString();
    /** @var string $src */
    expect($src)->toContain('.claude/skills/app-bug-hunt');
    expect($src)->toContain('"screens.md"');
    expect($src)->toContain('"operations.md"');
    expect($src)->toContain('"annotations.toml"');
});

test('exit code 規約 0=一致 / 3=ドリフト を実走で満たすこと (sandbox / DB 不使用)', function (): void {
    bhicRequirePython3();

    $sandbox = bhicMakeSandbox();
    try {
        [$genCode, $genOut] = bhicGenerate($sandbox);
        expect($genCode)->toBe(0, "生成に失敗した:\n".$genOut);

        // (1) 一致: 生成直後は差分なし → exit 0
        [$code, $out] = bhicCheck($sandbox);
        expect($code)->toBe(0, "一致 fixture は exit 0 であるべき:\n".$out);
        expect($out)->toContain('一致');

        // (2) 生成物の手編集 → exit 3
        $screens = $sandbox.'/'.BHIC_SKILL_DIR.'/screens.md';
        file_put_contents($screens, file_get_contents($screens)."手で足した行\n");
        [$code, $out] = bhicCheck($sandbox);
        expect($code)->toBe(3, "生成物の手編集は exit 3 であるべき:\n".$out);
        expect($out)->toContain('screens.md');
    } finally {
        bhicRemoveSandbox($sandbox);
    }
});

test('負のコントロール: 未注釈の route があれば exit 3 (ドリフト) になること', function (): void {
    bhicRequirePython3();

    $sandbox = bhicMakeSandbox();
    try {
        expect(bhicGenerate($sandbox)[0])->toBe(0);

        // 実装に route を足して注釈を足さない = 意味の欠落
        $scan = json_decode((string) file_get_contents($sandbox.'/scan.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($scan)->toBeArray();
        /** @var array{routes: list<array<string, mixed>>} $scan */
        $scan['routes'][] = [
            'name' => 'reports.index', 'uri' => 'reports', 'methods' => ['GET', 'HEAD'],
            'middleware' => ['web'], 'action' => 'App\\Http\\Controllers\\X@index', 'title' => null,
        ];
        file_put_contents($sandbox.'/scan.json', json_encode($scan, JSON_THROW_ON_ERROR));

        [$code, $out] = bhicCheck($sandbox);
        expect($code)->toBe(3, "未注釈 route は exit 3 であるべき:\n".$out);
        expect($out)->toContain('reports.index');
    } finally {
        bhicRemoveSandbox($sandbox);
    }
});

test('負のコントロール: 抽出に失敗したとき exit 2 になること (fail-open を返さない)', function (): void {
    bhicRequirePython3();

    $ok = bhicMakeSandbox();
    try {
        expect(bhicGenerate($ok)[0])->toBe(0);
        $screens = file_get_contents($ok.'/'.BHIC_SKILL_DIR.'/screens.md');
        $operations = file_get_contents($ok.'/'.BHIC_SKILL_DIR.'/operations.md');
    } finally {
        bhicRemoveSandbox($ok);
    }

    $sandbox = bhicMakeSandbox(phpFails: true);
    try {
        // 生成物は揃っている (差分が原因で落ちたのではないことを明確にする)。
        file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/screens.md', $screens);
        file_put_contents($sandbox.'/'.BHIC_SKILL_DIR.'/operations.md', $operations);

        [$code, $out] = bhicCheck($sandbox);
        expect($code)->toBe(2, "抽出失敗は exit 2 であるべき (fail-open にしない):\n".$out);
    } finally {
        bhicRemoveSandbox($sandbox);
    }
});

test('生成物 2 本が「生成物である」と宣言していること (手編集の抑止を消さない)', function (): void {
    foreach (['screens.md', 'operations.md'] as $name) {
        $content = file_get_contents(base_path(BHIC_SKILL_DIR.'/'.$name));
        expect($content)->toBeString();
        /** @var string $content */
        // 生成物宣言 (手編集の抑止) が消えたら赤くする。
        expect($content)->toContain('このファイルは生成物である');
    }
});
