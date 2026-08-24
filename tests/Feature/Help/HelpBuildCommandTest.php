<?php

declare(strict_types=1);

use App\Services\Help\Generators\McpToolReferenceGenerator;
use App\Services\Help\HelpArtifactObservation;
use App\Services\Help\HelpArtifactState;
use App\Services\Help\HelpBuildService;
use App\Services\Help\HelpRepository;
use App\Services\Help\McpToolScanner;
use Tests\Support\Help\HelpTestTree;

/*
 * `help:build` の振る舞い (I6 / I7 / I8 / I9 / I13)。
 *
 * ★書き込みを伴うので **必ず一時ディレクトリ** を置き場に差し替えて実行する
 *   (`composer test` は --parallel。実 `docs/help/` を触ると別レーンと競合する)。
 *   実リポジトリを読むのは `HelpBuildFreshnessTest` (読み取りのみ) の担当である。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 一時置き場を container へ差し込み、その絶対パスを返す。 */
function helpCommandRoot(): string
{
    $root = HelpTestTree::makeDir('help-build');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
    ]);

    app()->instance(HelpRepository::class, new HelpRepository($root));

    return $root;
}

test('生成 → --check が 0 で通る (唯一の入口が生成と検査の両方を持つ)', function (): void {
    $root = helpCommandRoot();

    $this->artisan('help:build')->assertExitCode(0);

    expect(is_file($root.'/_generated/mcp-tools.md'))->toBeTrue();

    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
});

test('--check は作業ツリーを 1 バイトも変えない (I6)', function (): void {
    $root = helpCommandRoot();
    $this->artisan('help:build')->assertExitCode(0);

    $before = HelpTestTree::snapshot($root);

    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);

    expect(HelpTestTree::snapshot($root))->toBe($before);
});

test('生成物が無ければ --check は Missing を報告して 1 で終わる (作業ツリーは変えない)', function (): void {
    $root = helpCommandRoot();

    $before = HelpTestTree::snapshot($root);

    $this->artisan('help:build', ['--check' => true])
        ->expectsOutputToContain('missing')
        ->assertExitCode(1);

    expect(HelpTestTree::snapshot($root))->toBe($before);
});

test('生成物が古ければ --check は Stale を報告して 1、再生成すれば 0 に戻る (対の動き)', function (): void {
    $root = helpCommandRoot();
    $this->artisan('help:build')->assertExitCode(0);

    $artifact = $root.'/_generated/mcp-tools.md';
    HelpTestTree::put($artifact, (string) file_get_contents($artifact)."手で足した 1 行\n");

    $this->artisan('help:build', ['--check' => true])
        ->expectsOutputToContain('stale')
        ->assertExitCode(1);

    $this->artisan('help:build')->assertExitCode(0);
    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
});

test('manifest に無い生成物は Orphan として報告し、削除はしない (人が消す)', function (): void {
    $root = helpCommandRoot();
    $this->artisan('help:build')->assertExitCode(0);

    HelpTestTree::put($root.'/_generated/ghost.md', "孤児\n");

    $this->artisan('help:build', ['--check' => true])
        ->expectsOutputToContain('orphan')
        ->assertExitCode(1);

    $this->artisan('help:build')->assertExitCode(1);

    expect(is_file($root.'/_generated/ghost.md'))->toBeTrue()
        ->and(file_get_contents($root.'/_generated/ghost.md'))->toBe("孤児\n");
});

test('報告の種別は up_to_date / stale / missing / orphan の 4 つである (I9)', function (): void {
    $states = array_map(
        static fn (HelpArtifactState $s): string => $s->value,
        HelpArtifactState::cases(),
    );
    sort($states);

    expect($states)->toBe(['missing', 'orphan', 'stale', 'up_to_date']);
});

test('観測は 4 種別を実際に区別する (up_to_date / stale / missing / orphan)', function (): void {
    $root = helpCommandRoot();
    $service = app(HelpBuildService::class);

    // 生成前は Missing
    expect(array_map(
        static fn (HelpArtifactObservation $o): string => $o->state->value,
        $service->check()->observations,
    ))->toBe(['missing']);

    // 生成直後は UpToDate
    expect($service->build()->isClean())->toBeTrue();
    expect($service->check()->observations[0]->state)
        ->toBe(HelpArtifactState::UpToDate);

    // 書き換えると Stale
    $artifact = $root.'/_generated/mcp-tools.md';
    HelpTestTree::put($artifact, "手で書いた\n");
    expect($service->check()->observations[0]->state)
        ->toBe(HelpArtifactState::Stale);

    // manifest に無い生成物は Orphan (別の観測として現れる)
    HelpTestTree::put($root.'/_generated/ghost.md', "孤児\n");
    $observations = $service->check()->observations;

    expect($observations)->toHaveCount(2)
        ->and($observations[1]->relativePath)->toBe('_generated/ghost.md')
        ->and($observations[1]->state)->toBe(HelpArtifactState::Orphan)
        ->and($service->check()->isClean())->toBeFalse()
        ->and($service->check()->problems())->toHaveCount(2);
});

test('手書きページが 0 件でも --check は 0 で通る (未整備を赤字扱いしない / I13)', function (): void {
    $root = helpCommandRoot();
    $this->artisan('help:build')->assertExitCode(0);

    expect(is_dir($root.'/pages'))->toBeFalse();

    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
});

test('手書きページを宣言しても本文が無いまま --check は 0 で通る', function (): void {
    $root = HelpTestTree::makeDir('help-build-pages');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
    ]);
    app()->instance(HelpRepository::class, new HelpRepository($root));

    $this->artisan('help:build')->assertExitCode(0);
    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);
});

test('manifest と台帳が食い違うと --check も生成も 1 で止まる (I10 の fail-closed)', function (): void {
    $root = HelpTestTree::makeDir('help-build-mismatch');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
    ]);
    app()->instance(HelpRepository::class, new HelpRepository($root));

    $this->artisan('help:build', ['--check' => true])->assertExitCode(1);
    $this->artisan('help:build')->assertExitCode(1);
});

test('HelpManifestException 以外の Throwable でも終了コードは 1 である (I8)', function (): void {
    helpCommandRoot();

    // 生成器の解決結果を誤った型へ差し替えると Webmozart\Assert が
    // InvalidArgumentException を投げる (RuntimeException ではない)。
    app()->instance(McpToolReferenceGenerator::class, new stdClass);

    $this->artisan('help:build', ['--check' => true])->assertExitCode(1);
    $this->artisan('help:build')->assertExitCode(1);
});

test('走査根が壊れていても終了コードは 1 である (0/1 の 2 値だけ)', function (): void {
    helpCommandRoot();
    $empty = HelpTestTree::makeDir('help-build-empty-scan');
    app()->instance(McpToolScanner::class, new McpToolScanner($empty));

    $this->artisan('help:build')->assertExitCode(1);
});

test('生成物ディレクトリが置き場の外への symlink なら 1 で止まり、外部ファイルは変わらない', function (): void {
    $root = helpCommandRoot();
    $outside = HelpTestTree::makeDir('help-build-outside');
    HelpTestTree::put($outside.'/mcp-tools.md', "外部の中身\n");
    symlink($outside, $root.'/_generated');

    $before = HelpTestTree::snapshot($outside);

    $this->artisan('help:build')->assertExitCode(1);

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($outside.'/mcp-tools.md'))->toBe("外部の中身\n");
});

test('MCP ツールが 1 本増えると --check が Stale になる (生成物が実装へ追従する)', function (): void {
    helpCommandRoot();

    $scanRoot = HelpTestTree::makeDir('help-build-scan');
    HelpTestTree::writeToolFixture($scanRoot, 'BuildFixtureFirstTool', 'Whoami');
    app()->instance(McpToolScanner::class, new McpToolScanner($scanRoot));

    $this->artisan('help:build')->assertExitCode(0);
    $this->artisan('help:build', ['--check' => true])->assertExitCode(0);

    HelpTestTree::writeToolFixture($scanRoot, 'BuildFixtureSecondTool', 'ListProjects');

    $this->artisan('help:build', ['--check' => true])
        ->expectsOutputToContain('stale')
        ->assertExitCode(1);

    $this->artisan('help:build')->assertExitCode(0);
});
