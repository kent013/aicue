<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/*
 * Architecture invariant: bug-hunt インベントリ ドリフト検出器の最小契約。
 *
 * SoT = scripts/bug-hunt-inventory-check.sh 本体 + AGENTS.md §bug-hunt
 * (「ドリフト検知は scripts/bug-hunt-inventory-check.sh」)。
 *
 * 固定する不変条件:
 *   - スクリプトが存在し実行可能で、fail-closed (set -euo pipefail) であること
 *   - インベントリ正本が `.claude/skills/app-bug-hunt/{screens.md,operations.md}` であること
 *   - **exit code 規約 0=一致 / 3=ドリフト** を実際に満たすこと
 *   - route:list 取得に失敗したときに exit 0 (fail-open) を返さないこと
 *
 * exit code 規約は「静的に読める宣言」ではなく **実走で** 検証する。ただし実 route:list
 * (artisan boot + DB) には依存させない: スクリプトを一時 sandbox へ複製し、`php` を
 * 固定 JSON を吐く shim に差し替えて走らせる (決定論・DB 不使用)。
 * これが本 gate の負のコントロール (drift fixture で実際に exit 3 になること) も兼ねる。
 */

function bhicScriptPath(): string
{
    return base_path('scripts/bug-hunt-inventory-check.sh');
}

/**
 * sandbox を組み立てる。scripts/ にスクリプト複製、.claude/skills/app-bug-hunt/ に
 * インベントリ fixture、bin/ に `php` shim (固定 route:list JSON を吐く) を置く。
 *
 * @param  list<array{method: string, uri: string, middleware: list<string>, name: string}>  $routes
 * @param  bool  $phpFails  true なら shim が失敗する (route:list 取得失敗の再現)
 */
function bhicMakeSandbox(array $routes, string $screensMd, string $operationsMd, bool $phpFails = false): string
{
    $sandbox = sys_get_temp_dir().'/bhic-'.bin2hex(random_bytes(6));
    mkdir($sandbox.'/scripts', 0o755, true);
    mkdir($sandbox.'/.claude/skills/app-bug-hunt', 0o755, true);
    mkdir($sandbox.'/bin', 0o755, true);

    copy(bhicScriptPath(), $sandbox.'/scripts/bug-hunt-inventory-check.sh');
    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/screens.md', $screensMd);
    file_put_contents($sandbox.'/.claude/skills/app-bug-hunt/operations.md', $operationsMd);

    $json = json_encode($routes, JSON_UNESCAPED_SLASHES);
    file_put_contents($sandbox.'/routes.json', is_string($json) ? $json : '[]');

    $shim = $phpFails
        ? "#!/usr/bin/env bash\necho 'route:list failed' >&2\nexit 1\n"
        : "#!/usr/bin/env bash\ncat \"\$(dirname \"\$0\")/../routes.json\"\n";
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
 * sandbox 内でスクリプトを走らせ [exitCode, output] を返す。
 *
 * @return array{0: int|null, 1: string}
 */
function bhicRunSandbox(string $sandbox): array
{
    $process = new Process(
        ['bash', $sandbox.'/scripts/bug-hunt-inventory-check.sh'],
        $sandbox,
        ['PATH' => $sandbox.'/bin:'.(getenv('PATH') ?: '/usr/bin:/bin')]
    );
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

test('scripts/bug-hunt-inventory-check.sh が存在し実行可能で fail-closed であること', function (): void {
    $path = bhicScriptPath();
    expect(file_exists($path))->toBeTrue('bug-hunt-inventory-check.sh が見つからない');
    expect(is_executable($path))->toBeTrue('bug-hunt-inventory-check.sh に実行権が無い');

    $src = file_get_contents($path);
    expect($src)->toBeString();
    /** @var string $src */
    expect($src)->toContain('set -euo pipefail');
});

test('インベントリ正本が .claude/skills/app-bug-hunt/{screens,operations}.md であること', function (): void {
    $src = file_get_contents(bhicScriptPath());
    expect($src)->toBeString();
    /** @var string $src */
    expect($src)->toContain('.claude/skills/app-bug-hunt');
    expect($src)->toMatch('/SCREENS="\$\{SKILL_DIR\}\/screens\.md"/');
    expect($src)->toMatch('/OPS="\$\{SKILL_DIR\}\/operations\.md"/');
    // exit 規約の宣言がスクリプト冒頭に残ること (運用者向けの契約表明)。
    expect($src)->toMatch('/exit 0=.*3=/u');
});

test('exit code 規約 0=一致 / 3=ドリフト を実走で満たすこと (sandbox / DB 不使用)', function (): void {
    if ((new Process(['which', 'python3']))->run() !== 0) {
        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
    }

    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";

    // (1) 一致: route:list の全ルートがインベントリに載っている → exit 0
    $match = bhicMakeSandbox([
        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
    ], $screens, $operations);
    try {
        [$code, $out] = bhicRunSandbox($match);
        expect($code)->toBe(0, "一致 fixture は exit 0 であるべき:\n".$out);
        expect($out)->toContain('drift なし');
    } finally {
        bhicRemoveSandbox($match);
    }
});

/*
 * 負のコントロール: 未追記ルート (screens / operations 双方) で実際に exit 3 になること。
 * gate が空振り (常に 0) でないことをここで担保する。
 */
test('負のコントロール: 未追記ルートがあれば exit 3 (ドリフト) になること', function (): void {
    if ((new Process(['which', 'python3']))->run() !== 0) {
        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
    }

    $screens = "# screens\n\n| ルート名 | URI |\n|---|---|\n| dashboard | /dashboard |\n";
    $operations = "# operations\n\n| ルート名 | 操作 |\n|---|---|\n| projects.store | 作成 |\n";

    // screens 側ドリフト: 未追記の GET×web ルート
    $screenDrift = bhicMakeSandbox([
        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
        ['method' => 'GET|HEAD', 'uri' => 'reports', 'middleware' => ['web'], 'name' => 'reports.index'],
        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
    ], $screens, $operations);
    try {
        [$code, $out] = bhicRunSandbox($screenDrift);
        expect($code)->toBe(3, "screens 未追記は exit 3 であるべき:\n".$out);
        expect($out)->toContain('reports.index');
    } finally {
        bhicRemoveSandbox($screenDrift);
    }

    // operations 側ドリフト: 未追記の 非GET×web ルート
    $opDrift = bhicMakeSandbox([
        ['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard'],
        ['method' => 'POST', 'uri' => 'projects', 'middleware' => ['web'], 'name' => 'projects.store'],
        ['method' => 'DELETE', 'uri' => 'projects/{project}', 'middleware' => ['web'], 'name' => 'projects.destroy'],
    ], $screens, $operations);
    try {
        [$code, $out] = bhicRunSandbox($opDrift);
        expect($code)->toBe(3, "operations 未追記は exit 3 であるべき:\n".$out);
        expect($out)->toContain('projects.destroy');
    } finally {
        bhicRemoveSandbox($opDrift);
    }
});

test('負のコントロール: route:list 取得に失敗したとき exit 0 (fail-open) を返さないこと', function (): void {
    if ((new Process(['which', 'python3']))->run() !== 0) {
        $this->markTestSkipped('python3 が PATH に無いため exit code 実走検証を skip');
    }

    $sandbox = bhicMakeSandbox(
        [['method' => 'GET|HEAD', 'uri' => 'dashboard', 'middleware' => ['web'], 'name' => 'dashboard']],
        "| dashboard | /dashboard |\n",
        "| projects.store | 作成 |\n",
        phpFails: true
    );
    try {
        [$code, $out] = bhicRunSandbox($sandbox);
        expect($code)->not->toBe(0, "route:list 失敗時に exit 0 を返してはならない (fail-open):\n".$out);
    } finally {
        bhicRemoveSandbox($sandbox);
    }
});
