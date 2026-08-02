<?php

declare(strict_types=1);

/*
 * Architecture invariant: `composer phpstan` が `scripts/phpstan.sh` 経由で実行されること。
 *
 * 背景 (SoT = scripts/phpstan.sh のヘッダコメント + composer.json の phpstan script):
 * この worktree は virtiofs マウント (OrbStack / Docker Desktop の host share) 上にあり、
 * phpstan.phar を並列 worker が同時に open/mmap するとレースで "Cannot open phar archive" が
 * 出て PHPStan が "Result is incomplete" で落ちることがある。scripts/phpstan.sh は phar を
 * 実 fs (${TMPDIR:-/tmp}) に内容ハッシュ鍵で複製してから実行することでこれを回避する。
 *
 * composer.json の phpstan script を直 phar 呼び出し (vendor/bin/phpstan ... /
 * php vendor/.../phpstan.phar ...) に戻すと virtiofs 並列クラッシュが再発するため、
 * 「wrapper 経由」+「wrapper が実 fs 複製を exec する」の 2 点を不変条件として固定する。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * composer.json の phpstan script が wrapper 経由かを検査する (純関数 = 負のコントロール用)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function phpstanComposerScriptViolations(string $composerJson): array
{
    $violations = [];

    /** @var mixed $decoded */
    $decoded = json_decode($composerJson, true);
    if (! is_array($decoded)) {
        return ['composer.json が JSON object として読めない'];
    }

    $scripts = $decoded['scripts'] ?? null;
    if (! is_array($scripts) || ! isset($scripts['phpstan'])) {
        return ['composer.json の scripts.phpstan が存在しない'];
    }

    /** @var mixed $phpstan */
    $phpstan = $scripts['phpstan'];
    $lines = is_array($phpstan) ? $phpstan : [$phpstan];
    $joined = implode("\n", array_map(strval(...), $lines));

    if (! str_contains($joined, 'scripts/phpstan.sh')) {
        $violations[] = 'scripts.phpstan が scripts/phpstan.sh 経由でない (virtiofs 並列クラッシュが再発する)';
    }
    // 直 phar 呼び出しへの逆戻りを明示的に弾く。
    if (preg_match('#(vendor/bin/phpstan|vendor/phpstan/phpstan/phpstan\.phar)#', $joined) === 1) {
        $violations[] = 'scripts.phpstan が phar を直接呼んでいる (wrapper 経由に戻すこと)';
    }

    return $violations;
}

/**
 * wrapper 本体が「実 fs へ複製した phar を実行する」形を保っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function phpstanWrapperSourceViolations(string $shellSource): array
{
    $violations = [];

    if (preg_match('/TMPDIR:-\/tmp/', $shellSource) !== 1) {
        $violations[] = 'wrapper が ${TMPDIR:-/tmp} (実 fs) の複製先を持たない';
    }
    if (preg_match('/^\s*cp\s/m', $shellSource) !== 1) {
        $violations[] = 'wrapper が phar を複製 (cp) していない';
    }
    // 複製物ではなく元 phar ($SRC) をそのまま実行するのは回避策の無効化。
    if (preg_match('/exec\s+php\s+"\$\{?SRC\}?"/', $shellSource) === 1) {
        $violations[] = 'wrapper が複製せず元 phar ($SRC) を直接 exec している';
    }
    if (preg_match('/exec\s+php\s+"\$\{?CACHED\}?"/', $shellSource) !== 1) {
        $violations[] = 'wrapper が複製済み phar ($CACHED) を exec していない';
    }

    return $violations;
}

test('scripts/phpstan.sh が存在し実行可能であること', function (): void {
    $path = base_path('scripts/phpstan.sh');
    expect(file_exists($path))->toBeTrue('scripts/phpstan.sh が見つからない');
    expect(is_executable($path))->toBeTrue('scripts/phpstan.sh に実行権が無い');
});

test('composer.json の phpstan script が scripts/phpstan.sh 経由であること', function (): void {
    $composerJson = file_get_contents(base_path('composer.json'));
    expect($composerJson)->toBeString();
    /** @var string $composerJson */
    expect(phpstanComposerScriptViolations($composerJson))->toBe([]);
});

test('scripts/phpstan.sh が phar を実 fs へ複製してから実行すること', function (): void {
    $source = file_get_contents(base_path('scripts/phpstan.sh'));
    expect($source)->toBeString();
    /** @var string $source */
    expect(phpstanWrapperSourceViolations($source))->toBe([]);
});

/*
 * 負のコントロール: gate が「壊れた状態」を実際に検出することを fixture で確認する
 * (実ファイルは書き換えない)。空振り gate を green として扱わないため。
 */
test('負のコントロール: 直 phar 呼び出しへ戻した composer.json を検出する', function (): void {
    $broken = json_encode([
        'scripts' => ['phpstan' => ['vendor/bin/phpstan analyse --memory-limit=2G']],
    ]);
    expect($broken)->toBeString();
    /** @var string $broken */
    $violations = phpstanComposerScriptViolations($broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('scripts/phpstan.sh');

    $missing = json_encode(['scripts' => ['fix' => ['vendor/bin/pint']]]);
    expect($missing)->toBeString();
    /** @var string $missing */
    expect(phpstanComposerScriptViolations($missing))->not->toBe([]);
});

test('負のコントロール: 複製せず元 phar を exec する wrapper を検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    set -euo pipefail
    SRC="${PHPSTAN_PHAR:-vendor/phpstan/phpstan/phpstan.phar}"
    exec php "$SRC" "$@"
    SH;

    $violations = phpstanWrapperSourceViolations($broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('$SRC');
});
