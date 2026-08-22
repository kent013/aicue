<?php

declare(strict_types=1);

use App\Services\Help\HelpManifestException;
use App\Services\Help\HelpRepository;
use App\Services\Help\HelpSection;
use Tests\Support\Help\HelpTestTree;

/*
 * ヘルプの置き場 (`docs/help/`) の読み取り層。
 *
 * I1 (取り込み基盤) / I11 (直下のみ・階層不可) / I12 (閉じる側へ倒れる:
 * パスを組み立てるたびに字句の検査と実体の検査をやり直す) を負例で裏取りする。
 *
 * 書き込みを伴うので **必ず一時ディレクトリ** を root にする (実 `docs/help/` は触らない)。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 生成物 1 件を宣言した既定の manifest を持つ一時置き場。 */
function helpRepoRoot(string $prefix = 'help-repo'): string
{
    $root = HelpTestTree::makeDir($prefix);
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
    ]);

    return $root;
}

test('manifest が宣言した節を宣言順に読める (生成物と手書きの区別を含む)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-sections');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツール', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
        ['slug' => 'getting-started', 'title' => 'はじめに', 'path' => 'pages/getting-started.md'],
    ]);

    $sections = (new HelpRepository($root))->sections();

    expect($sections)->toHaveCount(2)
        ->and($sections[0]->slug)->toBe('mcp-tools')
        ->and($sections[0]->generatorKey)->toBe('mcp-tools')
        ->and($sections[0]->isGenerated())->toBeTrue()
        ->and($sections[1]->slug)->toBe('getting-started')
        ->and($sections[1]->generatorKey)->toBeNull()
        ->and($sections[1]->isGenerated())->toBeFalse();
});

test('手書きページが 0 件の manifest も正常に読める (未整備を赤字にしない)', function (): void {
    expect((new HelpRepository(helpRepoRoot()))->sections())->toHaveCount(1);
});

test('本文が無い節は例外ではなく null を返す (不在と検査不能を混同しない)', function (): void {
    $root = helpRepoRoot();
    $repository = new HelpRepository($root);

    expect($repository->read($repository->sections()[0]))->toBeNull();
});

test('本文が在れば読める', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/mcp-tools.md', "# 見本\n");

    $repository = new HelpRepository($root);

    expect($repository->read($repository->sections()[0]))->toBe("# 見本\n");
});

test('生成物ディレクトリが無ければ孤児の母集団は空である', function (): void {
    expect((new HelpRepository(helpRepoRoot()))->generatedArtifactPaths())->toBe([]);
});

test('生成物ディレクトリ直下の Markdown だけを昇順で列挙する', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/zebra.md', "z\n");
    HelpTestTree::put($root.'/_generated/alpha.md', "a\n");
    HelpTestTree::put($root.'/pages/draft.md', "下書き\n");

    expect((new HelpRepository($root))->generatedArtifactPaths())
        ->toBe(['_generated/alpha.md', '_generated/zebra.md']);
});

test('書き込みは生成物として宣言された節にしか行えない', function (): void {
    $root = HelpTestTree::makeDir('help-repo-write-page');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
    ]);

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, 'x'))
        ->toThrow(HelpManifestException::class, '手書きページを生成物として書き込めません');
});

test('書き込みは生成物ディレクトリを非再帰に作り、読み戻せる', function (): void {
    $root = helpRepoRoot();
    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    $repository->writeGenerated($section, "生成物\n");

    expect($repository->read($section))->toBe("生成物\n")
        ->and(is_dir($root.'/_generated'))->toBeTrue();
});

/*
 * -------- 字句の負例 (I12 / I11) --------
 */

dataset('規約に反する path', [
    '相対指定を含む' => ['_generated/../../etc/passwd.md'],
    '絶対パス' => ['/etc/passwd.md'],
    '許されないディレクトリ' => ['secrets/leak.md'],
    '階層化した生成物' => ['_generated/sub/x.md'],
    'Markdown でない' => ['_generated/x.txt'],
    '名前が英数字以外で始まる' => ['_generated/-x.md'],
]);

test('path が規約に反する manifest は読めない', function (string $path): void {
    $root = HelpTestTree::makeDir('help-repo-path');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'x', 'title' => 'x', 'path' => $path, 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class);
})->with('規約に反する path');

test('生成物の節が pages/ を指していたら読めない (generator の有無で期待するディレクトリが決まる)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dir-mismatch');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'x', 'title' => 'x', 'path' => 'pages/x.md', 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '_generated/<name>.md');
});

/*
 * -------- manifest の負例 --------
 */

test('manifest が無ければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-no-manifest');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
});

test('manifest の JSON が壊れていたら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-broken-json');
    HelpTestTree::writeRawManifest($root, '{ broken');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'JSON が壊れています');
});

test('manifest の最上位が object でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-top-list');
    HelpTestTree::writeRawManifest($root, '[]');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '最上位が object ではありません');
});

test('sections が list でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-sections-map');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":{"a":1}}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'sections が配列 (list) ではありません');
});

test('sections が無ければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-no-sections');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'sections がありません');
});

test('節が object でなければ例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-entry-scalar');
    HelpTestTree::writeRawManifest($root, '{"schema_version":1,"sections":["x"]}');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'object ではありません');
});

test('slug が重複したら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-slug');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
        ['slug' => 'a', 'title' => 'b', 'path' => 'pages/b.md'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'slug が重複しています');
});

test('path が重複したら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-path');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => 'pages/a.md'],
        ['slug' => 'b', 'title' => 'b', 'path' => 'pages/a.md'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'path が重複しています');
});

test('同じ generator を 2 つの節が参照したら例外で止まる (完全一致を集合一致へ弱めない)', function (): void {
    $root = HelpTestTree::makeDir('help-repo-dup-generator');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => 'mcp-tools'],
        ['slug' => 'b', 'title' => 'b', 'path' => '_generated/b.md', 'generator' => 'mcp-tools'],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'generator が重複しています');
});

test('generator が空文字なら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-empty-generator');
    HelpTestTree::writeManifest($root, [
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => ''],
    ]);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '非空の文字列ではありません');
});

dataset('読めない schema_version', [
    '欠落' => ['{"sections":[]}'],
    '型違いの文字列' => ['{"schema_version":"1","sections":[]}'],
    '未知の版' => ['{"schema_version":2,"sections":[]}'],
]);

test('読める schema_version 以外は読まずに落ちる', function (string $raw): void {
    $root = HelpTestTree::makeDir('help-repo-schema');
    HelpTestTree::writeRawManifest($root, $raw);

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, 'schema_version');
})->with('読めない schema_version');

/*
 * -------- 実体の負例 (字句だけの飾りにしない) --------
 */

test('manifest が symlink なら例外で止まる', function (): void {
    $root = HelpTestTree::makeDir('help-repo-manifest-link');
    $outside = HelpTestTree::makeDir('help-repo-manifest-outside');
    HelpTestTree::put($outside.'/manifest.json', '{"schema_version":1,"sections":[]}');
    symlink($outside.'/manifest.json', $root.'/manifest.json');

    expect(fn (): array => (new HelpRepository($root))->sections())
        ->toThrow(HelpManifestException::class, '通常ファイルとして存在しません');
});

test('本文が symlink なら例外で止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-body-outside');
    HelpTestTree::put($outside.'/leak.md', "外\n");
    mkdir($root.'/_generated', 0o755);
    symlink($outside.'/leak.md', $root.'/_generated/mcp-tools.md');

    $repository = new HelpRepository($root);

    expect(fn (): ?string => $repository->read($repository->sections()[0]))
        ->toThrow(HelpManifestException::class, 'symlink は使えません');
});

test('生成物ディレクトリ自体が symlink なら例外で止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-dir-outside');
    symlink($outside, $root.'/_generated');

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'symlink は使えません');
});

test('生成物ディレクトリ直下に階層があれば例外で止まる (再帰走査を持たない)', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/sub/x.md', "x\n");

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, '階層を許しません');
});

test('生成物ディレクトリ直下の symlink は Orphan に畳まず例外で止まる', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/real.md', "r\n");
    symlink($root.'/_generated/real.md', $root.'/_generated/linked.md');

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'symlink があります');
});

test('生成物ディレクトリ直下の Markdown 以外は例外で止まる', function (): void {
    $root = helpRepoRoot();
    HelpTestTree::put($root.'/_generated/notes.txt', "t\n");

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'Markdown 以外の実体があります');
});

test('生成物ディレクトリ直下の通常ファイルでない実体は例外で止まる', function (): void {
    $root = helpRepoRoot();
    mkdir($root.'/_generated', 0o755);
    posix_mkfifo($root.'/_generated/pipe.md', 0o644);

    expect(fn (): array => (new HelpRepository($root))->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, '通常ファイルでない実体があります');
});

/*
 * -------- 書き込み経路が置き場の外へ出ないこと --------
 */

test('生成物ディレクトリが外部への symlink なら書き込みは止まり、外部ファイルは 1 バイトも変わらない', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-write-outside');
    HelpTestTree::put($outside.'/mcp-tools.md', "外部の中身\n");
    symlink($outside, $root.'/_generated');

    $before = HelpTestTree::snapshot($outside);

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, 'symlink は使えません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($outside.'/mcp-tools.md'))->toBe("外部の中身\n");
});

test('生成物の実体が symlink なら書き込みは止まる', function (): void {
    $root = helpRepoRoot();
    $outside = HelpTestTree::makeDir('help-repo-file-outside');
    HelpTestTree::put($outside.'/target.md', "外部\n");
    mkdir($root.'/_generated', 0o755);
    symlink($outside.'/target.md', $root.'/_generated/mcp-tools.md');

    $repository = new HelpRepository($root);
    $section = $repository->sections()[0];

    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, '生成物に symlink は使えません');

    expect(file_get_contents($outside.'/target.md'))->toBe("外部\n");
});

test('置き場そのものが外部への symlink なら読み書きのすべてが止まり、外部ファイルは変わらない', function (): void {
    $outside = helpRepoRoot('help-repo-root-outside');
    HelpTestTree::put($outside.'/_generated/mcp-tools.md', "外部の中身\n");

    $linkRoot = HelpTestTree::makeDir('help-repo-root-link-holder').'/help';
    symlink($outside, $linkRoot);

    $before = HelpTestTree::snapshot($outside);
    $repository = new HelpRepository($linkRoot);

    expect(fn (): array => $repository->sections())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): array => $repository->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');

    // 節そのものは実置き場から読める形で作り、書き込み経路も止まることを見る
    $section = (new HelpRepository($outside))->sections()[0];
    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): ?string => $repository->read($section))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($outside.'/_generated/mcp-tools.md'))->toBe("外部の中身\n");
});

test('置き場の**親要素**が symlink でも読み書きのすべてが止まり、外部ファイルは変わらない', function (): void {
    // outside/help が実ディレクトリ (置き場)。holder/docs -> outside という**親要素**の
    // symlink を張り、holder/docs/help を置き場にする。最終要素は通常ディレクトリなので
    // `is_link()` だけでは素通りするが、canonical path 検査は弾く。
    $outside = HelpTestTree::makeDir('help-repo-ancestor-outside');
    $store = $outside.'/help';
    mkdir($store, 0o755);
    HelpTestTree::writeManifest($store, [
        ['slug' => 'mcp-tools', 'title' => 'MCP ツールリファレンス', 'path' => '_generated/mcp-tools.md', 'generator' => 'mcp-tools'],
    ]);
    HelpTestTree::put($store.'/_generated/mcp-tools.md', "外部の中身\n");

    $holder = HelpTestTree::makeDir('help-repo-ancestor-holder');
    symlink($outside, $holder.'/docs');
    $root = $holder.'/docs/help';

    expect(is_link($root))->toBeFalse()
        ->and(is_dir($root))->toBeTrue();

    $before = HelpTestTree::snapshot($outside);
    $repository = new HelpRepository($root);
    $section = (new HelpRepository($store))->sections()[0];

    expect(fn (): array => $repository->sections())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): array => $repository->generatedArtifactPaths())
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn () => $repository->writeGenerated($section, '侵入'))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');
    expect(fn (): ?string => $repository->read($section))
        ->toThrow(HelpManifestException::class, 'canonical path ではありません');

    expect(HelpTestTree::snapshot($outside))->toBe($before)
        ->and(file_get_contents($store.'/_generated/mcp-tools.md'))->toBe("外部の中身\n");
});

test('HelpSection は generatorKey の有無だけで生成物かどうかを決める', function (): void {
    expect((new HelpSection('a', 'A', '_generated/a.md', 'k'))->isGenerated())->toBeTrue()
        ->and((new HelpSection('a', 'A', 'pages/a.md', null))->isGenerated())->toBeFalse();
});
