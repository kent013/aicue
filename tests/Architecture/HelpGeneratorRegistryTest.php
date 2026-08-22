<?php

declare(strict_types=1);

use App\Services\Help\Generators\HelpGenerator;
use App\Services\Help\HelpGeneratorRegistry;
use App\Services\Help\HelpManifestException;
use App\Services\Help\HelpRepository;
use Tests\Support\Help\HelpTestTree;

/*
 * 生成器の台帳 (I10) — 許可一覧も除外の口も持たない全数申告であり、
 * manifest の生成 entry と **完全一致** することを両方向で強制する (deny-by-default)。
 *
 * 負例は合成した一時 manifest で作る (実 `docs/help/manifest.json` は書き換えない)。
 */

afterEach(function (): void {
    HelpTestTree::cleanup();
});

/** 一時 manifest を持つ HelpRepository を組み立てる。 */
function helpRegistryRepository(array $sections): HelpRepository
{
    $root = HelpTestTree::makeDir('help-registry');
    HelpTestTree::writeManifest($root, $sections);

    return new HelpRepository($root);
}

test('実リポジトリの manifest は台帳と完全一致する', function (): void {
    $registry = app(HelpGeneratorRegistry::class);

    $registry->verifyRegistryIsFullyReferenced(app(HelpRepository::class));
})->throwsNoExceptions();

test('台帳の母集団は非空である (0 件どうしの「一致」を成立させない)', function (): void {
    expect(HelpGeneratorRegistry::GENERATORS)->not->toBeEmpty();
});

test('台帳に載せた生成器はすべて解決でき、key() が台帳のキーと一致する', function (): void {
    $generators = app(HelpGeneratorRegistry::class)->all();

    expect(array_keys($generators))->toBe(array_keys(HelpGeneratorRegistry::GENERATORS));

    foreach ($generators as $key => $generator) {
        expect($generator)->toBeInstanceOf(HelpGenerator::class)
            ->and($generator->key())->toBe($key);
    }
});

test('負例: 台帳に在る生成器が manifest に無ければ赤くなる', function (): void {
    $repository = helpRegistryRepository([
        ['slug' => 'intro', 'title' => 'はじめに', 'path' => 'pages/intro.md'],
    ]);

    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
        ->toThrow(HelpManifestException::class, '台帳に在る生成器が manifest に宣言されていません');
});

test('負例: manifest が宣言した生成器が台帳に無ければ赤くなる', function (): void {
    $sections = [];
    foreach (array_keys(HelpGeneratorRegistry::GENERATORS) as $key) {
        $sections[] = ['slug' => $key, 'title' => $key, 'path' => '_generated/'.$key.'.md', 'generator' => $key];
    }
    $sections[] = ['slug' => 'ghost', 'title' => 'ghost', 'path' => '_generated/ghost.md', 'generator' => 'ghost'];

    $repository = helpRegistryRepository($sections);

    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
        ->toThrow(HelpManifestException::class, 'manifest が宣言した生成器が台帳に在りません');
});

test('負例: 同じ生成器を 2 つの節が参照したら赤くなる (集合一致へ弱まっていない)', function (): void {
    $key = (string) array_key_first(HelpGeneratorRegistry::GENERATORS);

    $repository = helpRegistryRepository([
        ['slug' => 'a', 'title' => 'a', 'path' => '_generated/a.md', 'generator' => $key],
        ['slug' => 'b', 'title' => 'b', 'path' => '_generated/b.md', 'generator' => $key],
    ]);

    expect(fn () => app(HelpGeneratorRegistry::class)->verifyRegistryIsFullyReferenced($repository))
        ->toThrow(HelpManifestException::class, 'generator が重複しています');
});

test('免除の受け皿が生えていないこと (public 定数は GENERATORS ちょうど 1 つ / static プロパティ 0 件)', function (): void {
    $reflection = new ReflectionClass(HelpGeneratorRegistry::class);

    $publicConstants = array_map(
        static fn (ReflectionClassConstant $c): string => $c->getName(),
        array_filter(
            $reflection->getReflectionConstants(),
            static fn (ReflectionClassConstant $c): bool => $c->isPublic(),
        ),
    );
    sort($publicConstants);

    expect($publicConstants)->toBe(['GENERATORS'])
        ->and($reflection->getProperties(ReflectionProperty::IS_STATIC))->toBe([]);
});
