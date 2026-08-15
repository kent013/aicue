<?php

declare(strict_types=1);

use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;
use Tests\Support\TrackedPhpSourceFiles;

/*
 * レーン側 (tests/) から本番の偽の実装クラスを container へ直接結ぶことの静的禁止
 * (正典 v1 の「差し替え処理を 1 本に集約し、レーン側からの直呼びを静的に禁じる」)。
 *
 * 差し替えの入口は「宣言 (App\Support\ExternalFakes\ExternalFakeDeclaration) +
 * 配線 provider (FakeExternalsServiceProvider)」の 1 本だけである。レーン側で同じことを
 * 書けると、宣言に載っていない差し替えがテストの中だけで成立し、
 * 「宣言と実際の差し替えが一致している」という保証が意味を失う。
 *
 * ★per-test の代役 (tests/Support/Fake*) は対象外である。あれは Laravel 公式作法の
 *   テストダブルであり、bug-hunt レーンの差し替えとは別概念である (思考原則 4)。
 *   対象は **app/ 配下の偽の実装クラス**を container へ結ぶ形だけ。
 *
 * ★例外の登録簿は持たない。本番側の偽物を使いたくなったら宣言 + provider を通す
 *   (赤くなるのは正しい摩擦である)。
 *
 * **保証範囲を誇張しない**: 読めるのは container へ到達する 4 形
 * (`$this->app->bind` / `app()->bind` / `App::bind` / `Container::getInstance()->bind`) で、
 * 第 2 引数が `::class` 定数のものだけである。変数経由の結び付け・`instance()` /
 * `swap()`・モック機構経由には**沈黙する** (走査器の自己検査 5-24 / 5-25 が境界を固定する)。
 */

/**
 * 走査対象 (git 追跡下の tests/ 配下の .php、repo ルート相対)。
 *
 * @return list<string>
 */
function laneExternalFakeScanFiles(): array
{
    $files = [];
    foreach (TrackedPhpSourceFiles::all(FakeClassCatalog::repoRoot()) as $file) {
        if (str_starts_with($file['relative'], 'tests/')) {
            $files[] = $file['relative'];
        }
    }

    return $files;
}

test('レーン側は app/ の偽の実装クラスを container へ直接結ばない', function (): void {
    $fakes = FakeClassCatalog::implementationClasses();
    $files = laneExternalFakeScanFiles();

    // 母集団が空になったら「違反なし」ではなく赤にする (走査の故障を緑で見逃さない)。
    expect($fakes)->not->toBeEmpty()
        ->and($files)->not->toBeEmpty();

    $violations = [];
    foreach ($files as $file) {
        foreach (FakeWiringSourceScanner::bindPairs(FakeClassCatalog::sourceOf($file)) as $pair) {
            if ($pair['concrete'] !== null && in_array($pair['concrete'], $fakes, true)) {
                $violations[] = $file.': '.$pair['abstract'].' => '.$pair['concrete'];
            }
        }
    }

    expect($violations)->toBe([]);
});

test('負のコントロール: レーン側の直接の結び付けを 4 形すべてで検出する', function (): void {
    $fakes = FakeClassCatalog::implementationClasses();
    expect($fakes)->not->toBeEmpty();

    // 実在する偽の実装クラスを 1 つ選び、レーン側で結んだ体の合成ソースを作る。
    $fake = $fakes[0];
    $bodies = [
        '$this->app->bind(\App\Demo\A::class, \\'.$fake.'::class);',
        'app()->bind(\App\Demo\A::class, \\'.$fake.'::class);',
        'App::bind(\App\Demo\A::class, \\'.$fake.'::class);',
        'Container::getInstance()->bind(\App\Demo\A::class, \\'.$fake.'::class);',
    ];

    foreach ($bodies as $body) {
        $source = "<?php\n\nnamespace Tests\\Demo;\n\n"
            ."use Illuminate\\Container\\Container;\n"
            ."use Illuminate\\Support\\Facades\\App;\n\n"
            ."final class DemoTest\n{\n    public function run(): void\n    {\n"
            ."        {$body}\n    }\n}\n";

        $concretes = array_map(
            static fn (array $pair): ?string => $pair['concrete'],
            FakeWiringSourceScanner::bindPairs($source)
        );

        expect($concretes)->toBe([$fake], "レーン側の結び付けを読み取れない形がある: {$body}");
    }
});
