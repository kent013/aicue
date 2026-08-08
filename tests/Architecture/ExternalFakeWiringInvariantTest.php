<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FakeExternalsServiceProvider;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;
use Tests\Support\ExternalFakes\ExternalFakeBinding;
use Tests\Support\ExternalFakes\ExternalFakeWiringInventory;
use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 外部 fake 配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
 *
 * Laravel は abstract が具象クラスなら設定が無くても自動組み立てするため、
 * **差し替えの登録漏れは例外にならず、本物が静かに動く**。
 * したがって「宣言と実装の字面が一致するか」ではなく
 * 「**実際に解決して中身を確かめる**」層を持つ。
 *
 * 判定は必ず `$resolved::class === $expected` の**厳密一致**で行う
 * (FakeTakeObjectStorage は TakeObjectStorage を継承しているため、instanceof では
 *  fake でも real 判定が通ってしまう = 対照実行が無意味になる)。
 *
 * 責務境界: 本番混入防止の正本は ProductionEnvGuard (+ ProductionEnvGuardTest)。
 * 本 gate は非本番側の配線だけを見る。
 *
 * 状態リーク対策 (Architecture lane は RefreshDatabase も StrayLlmCallGuard も無い):
 *  - container の復元は Pest の test case ごとの app 再構築に任せる
 *    (対照と実証を**独立 test case** に分け、テスト順序に依存させない)。
 *    「flag を戻して provider を再実走すれば real に戻る」は成立しない
 *    (provider は early return するだけで binding を巻き戻さない) ため、その検査は書かない。
 *  - config / env を書き換える test case は try/finally で原値復元する。
 *  - Prompt::$fake は static なので、test 本体の finally で stopFaking() し、
 *    **同一 test case 内で** isFaking() === false を assert する。
 *    afterEach はフェイルセーフとして併置する (検査表現ではない)。
 *
 * 走査器 (FakeWiringSourceScanner) の限界は tests/Unit/Architecture/FakeWiringSourceScannerTest.php
 * が positive/negative で固定している。到達可能性は判定しない (`if (false) { … }` 中も候補)。
 */

/*
 * ソース走査系 mutation (M3〜M7) の被覆表。
 * M1 / M2 (inventory entry の bind 削除) は 3-2 の data-driven 解決検査が自動被覆するため
 * 本 map の対象外 (entry を足せば検査も自動で増える構造になっている)。
 *
 * 定数名は他の Architecture テストと衝突しないよう prefix する
 * (Pest のファイル直下 const / function はグローバル空間に出る)。
 */
const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
    'M3' => 'bootstrap/providers.php に FakeExternalsServiceProvider が登録されている',
    'M4' => 'FakeExternalsServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
    'M5' => 'provider の bind 組は inventory と集合一致する',
    'M6' => 'provider の container 呼び出しは許可された形だけ',
    'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
];

const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];

/** fake 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
function externalFakeWiringProviderSource(): string
{
    return FakeClassCatalog::sourceOf('app/Providers/FakeExternalsServiceProvider.php');
}

/**
 * bootstrap/providers.php が宣言する provider 一覧。
 *
 * @return list<class-string>
 */
function externalFakeWiringRegisteredProviders(): array
{
    /** @var list<class-string> $providers */
    $providers = require base_path('bootstrap/providers.php');

    return $providers;
}

afterEach(function (): void {
    // フェイルセーフ: LLM fake の static がテスト境界を越えないようにする (検査表現ではない)。
    if (Prompt::isFaking()) {
        Prompt::stopFaking();
    }
});

dataset('external fake bindings', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        yield $binding->label() => [$binding];
    }
});

dataset('external fake bindings and allowed environments', function (): Generator {
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        foreach ($binding->allowedEnvironments as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});

dataset('external fake bindings and denied environments', function (): Generator {
    // production だけでなく staging も見る = 「未知環境で誤設定されても fake しない」という
    // allowlist 方式の趣旨そのものを固定する。
    foreach (ExternalFakeWiringInventory::bindings() as $binding) {
        foreach (['production', 'staging'] as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});

test('3-1 対照: flag off では real 実装が厳密一致で解決される', function (ExternalFakeBinding $binding): void {
    expect(config($binding->flag))->toBeFalse();

    expect(app($binding->abstract)::class)->toBe($binding->real);
})->with('external fake bindings');

test('3-2 実証: flag on + allowlist 環境で fake が厳密一致で解決される',
    function (ExternalFakeBinding $binding, string $environment): void {
        $originalFlag = config($binding->flag);
        $originalEnvironment = $this->app['env'];

        try {
            // 環境ごとに実証する (testing だけだと local / bughunt.local の allowlist が固定されない)。
            // storage は FakeStorageGate が testing ∧ runningUnitTests を要求するが、
            // Architecture lane では runningUnitTests() が true なので成立する。
            $this->app['env'] = $environment;
            config([$binding->flag => true]);

            (new FakeExternalsServiceProvider($this->app))->register();

            // ★厳密一致 (instanceof は使わない。storage fake は real のサブクラス)
            expect(app($binding->abstract)::class)->toBe($binding->fake);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnvironment;
        }
    }
)->with('external fake bindings and allowed environments');

test('3-3 provider 単体: flag on でも allowlist 外 env では real のまま',
    function (ExternalFakeBinding $binding, string $environment): void {
        $originalFlag = config($binding->flag);
        $originalEnvironment = $this->app['env'];

        try {
            $this->app['env'] = $environment;
            config([$binding->flag => true]);

            (new FakeExternalsServiceProvider($this->app))->register();

            expect(app($binding->abstract)::class)->toBe($binding->real);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnvironment;
        }
    }
)->with('external fake bindings and denied environments');

test('3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す', function (): void {
    $originalFlag = config(ExternalFakeWiringInventory::EXTERNALS_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        Log::spy();

        $this->app['env'] = 'staging';
        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => true]);

        (new FakeExternalsServiceProvider($this->app))->register();

        Log::shouldHaveReceived('warning')->once();
    } finally {
        config([ExternalFakeWiringInventory::EXTERNALS_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-5 登録点: bootstrap/providers.php に FakeExternalsServiceProvider が登録されている', function (): void {
    expect(externalFakeWiringRegisteredProviders())->toContain(FakeExternalsServiceProvider::class);
});

test('3-6 登録点: FakeExternalsServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
    $providers = externalFakeWiringRegisteredProviders();

    $fakeIndex = array_search(FakeExternalsServiceProvider::class, $providers, true);
    $appIndex = array_search(AppServiceProvider::class, $providers, true);

    expect($fakeIndex)->toBeInt()
        ->and($appIndex)->toBeInt()
        ->and($fakeIndex)->toBeGreaterThan($appIndex);
});

test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
    expect(array_key_exists(FakeExternalsServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
});

test('3-8 網羅性: provider の bind 組が inventory と集合一致する', function (): void {
    $pairs = FakeWiringSourceScanner::bindPairs(externalFakeWiringProviderSource());

    // closure 差し替え (concrete === null) は「厳密クラス一致で実証できない形」なので許さない
    expect(array_filter($pairs, static fn (array $pair): bool => $pair['concrete'] === null))->toBe([]);

    $actual = array_map(
        static fn (array $pair): string => $pair['abstract'].' => '.$pair['concrete'],
        $pairs
    );
    $expected = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->abstract.' => '.$binding->fake,
        ExternalFakeWiringInventory::bindings()
    );

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
    $source = externalFakeWiringProviderSource();

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('3-10 網羅性: provider が参照する fake 系クラスは inventory + 明示例外に一致する', function (): void {
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
    )));

    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);

    $expected = array_merge(
        array_map(
            static fn (ExternalFakeBinding $binding): string => $binding->fake,
            ExternalFakeWiringInventory::bindings()
        ),
        ExternalFakeWiringInventory::providerReferenceExceptions(),
    );

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);
});

test('3-11 LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る', function (): void {
    $originalFlag = config(ExternalFakeWiringInventory::LLM_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        expect(Prompt::isFaking())->toBeFalse();

        // (1) bughunt.local ∧ on → 立つ
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeWiringInventory::LLM_FLAG => true]);
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeTrue();

        Prompt::stopFaking();

        // (2) testing ∧ on → 立たない (static をテストプロセスで占有させない)
        $this->app['env'] = 'testing';
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (3) local ∧ on → 立たない (実 API 検証を潰さない)
        $this->app['env'] = 'local';
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeWiringInventory::LLM_FLAG => false]);
        (new FakeExternalsServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        // static の往復を**同一 test case 内で** assert する (afterEach はフェイルセーフ)
        if (Prompt::isFaking()) {
            Prompt::stopFaking();
        }
        expect(Prompt::isFaking())->toBeFalse();

        config([ExternalFakeWiringInventory::LLM_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-12 mutation coverage: 被覆表のキー集合が想定 mutation ID と一致する', function (): void {
    $keys = array_keys(EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE);
    $ids = EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS;

    sort($keys);
    sort($ids);

    expect($keys)->toBe($ids);
});
