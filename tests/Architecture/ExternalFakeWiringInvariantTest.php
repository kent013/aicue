<?php

declare(strict_types=1);

use App\Http\Controllers\Testing\GetFakeStorageObjectController;
use App\Http\Controllers\Testing\PutFakeStorageObjectController;
use App\Providers\AppServiceProvider;
use App\Providers\BughuntFakesServiceProvider;
use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Services\Auth\SocialiteDriverResolver;
use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
use App\Services\Billing\Contracts\StripeGatewayInterface;
use App\Services\Billing\TicketCheckoutGateway;
use App\Services\Captcha\RecaptchaVerifier;
use App\Services\Capture\TakeObjectStorage;
use App\Services\Render\RenderObjectStorage;
use App\Support\ExternalFakes\ExternalFakeBinding;
use App\Support\ExternalFakes\ExternalFakeDeclaration;
use App\Support\FakeStorageGate;
use Illuminate\Support\Facades\Log;
use Kent013\PrismPrompt\Prompt;
use Tests\Support\ExternalFakes\FakeClassCatalog;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * 偽の外部サービスの配線の実証 gate (c2c: external-fakes-wiring-gate 柱 1)。
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
 * ★宣言の正本は App\Support\ExternalFakes\ExternalFakeDeclaration (本番側) である。
 *   かつて同じ集合をテスト側にも書き、provider のソースを走査して集合一致を確かめる検査
 *   (旧 3-8) を持っていたが、**差し替え先の決定が宣言 1 か所になったので比較する相手が
 *   無くなった**ため削除した。宣言から entry を消す変異を映すのは 3-16 だけである。
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
 * M1 / M2 (宣言 entry の削除) は 3-2 の data-driven 解決検査が自動被覆する…のではなく
 * **3-16 の件数付き pin だけ**が映す (entry を消すと provider の bind もデータセットも
 * 同時に縮むため。詳細は 3-16 のコメント)。
 *
 * 定数名は他の Architecture テストと衝突しないよう prefix する
 * (Pest のファイル直下 const / function はグローバル空間に出る)。
 */
const EXTERNAL_FAKE_WIRING_MUTATION_COVERAGE = [
    'M3' => 'bootstrap/providers.php に BughuntFakesServiceProvider が登録されている',
    'M4' => 'BughuntFakesServiceProvider は AppServiceProvider より後に登録される (後勝ち)',
    'M5' => 'provider は差し替え先のクラス名を 1 つも参照しない (決定は宣言側だけにある)',
    'M6' => 'provider の container 呼び出しは許可された形だけ',
    'M7' => '本番コードは fake クラスを参照しない (FakeClassReferenceInvariantTest が担当)',
];

const EXTERNAL_FAKE_WIRING_SOURCE_MUTATION_IDS = ['M3', 'M4', 'M5', 'M6', 'M7'];

/**
 * 配線 provider が参照してよい配線基盤クラス (偽物の実体ではないもの)。
 *
 * 「provider が参照する偽物系クラス = 本集合ちょうど」を集合一致で検査する (3-10)。
 * ここに載っていないクラスを provider が参照した時点で赤くなり、とくに
 * **差し替え先 (swaps() の fake) が 1 つでも現れたら赤くなる**
 * = 差し替え先の決定が宣言側にしか無いことの機械的な裏付けになる。
 */
const EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS = [
    // LLM の偽物を立てる窓口 (container 配線を行わない)
    CannedPromptFakeRegistrar::class,
    // 偽の保存先の有効化条件 (container 配線を行わない)
    FakeStorageGate::class,
    // 偽の保存先の署名付き経路の受け口 (route action。container 配線を行わない)
    PutFakeStorageObjectController::class,
    GetFakeStorageObjectController::class,
];

/** 配線 provider のソース (走査系テストの共通入力。読み取り失敗は例外で落ちる) */
function externalFakeWiringProviderSource(): string
{
    return FakeClassCatalog::sourceOf('app/Providers/BughuntFakesServiceProvider.php');
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
    foreach (ExternalFakeDeclaration::swaps() as $binding) {
        yield $binding->label() => [$binding];
    }
});

dataset('external fake bindings and allowed environments', function (): Generator {
    foreach (ExternalFakeDeclaration::swaps() as $binding) {
        foreach ($binding->allowedEnvironments as $environment) {
            yield $binding->label().' @ '.$environment => [$binding, $environment];
        }
    }
});

dataset('external fake bindings and denied environments', function (): Generator {
    // production だけでなく staging も見る = 「未知環境で誤設定されても fake しない」という
    // allowlist 方式の趣旨そのものを固定する。
    foreach (ExternalFakeDeclaration::swaps() as $binding) {
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

            (new BughuntFakesServiceProvider($this->app))->register();

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

            (new BughuntFakesServiceProvider($this->app))->register();

            expect(app($binding->abstract)::class)->toBe($binding->real);
        } finally {
            config([$binding->flag => $originalFlag]);
            $this->app['env'] = $originalEnvironment;
        }
    }
)->with('external fake bindings and denied environments');

test('3-4 provider 単体: 外部サービス fake flag on + allowlist 外 env は warning を出す', function (): void {
    $originalFlag = config(ExternalFakeDeclaration::EXTERNALS_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        Log::spy();

        $this->app['env'] = 'staging';
        config([ExternalFakeDeclaration::EXTERNALS_FLAG => true]);

        (new BughuntFakesServiceProvider($this->app))->register();

        Log::shouldHaveReceived('warning')->once();
    } finally {
        config([ExternalFakeDeclaration::EXTERNALS_FLAG => $originalFlag]);
        $this->app['env'] = $originalEnvironment;
    }
});

test('3-5 登録点: bootstrap/providers.php に BughuntFakesServiceProvider が登録されている', function (): void {
    expect(externalFakeWiringRegisteredProviders())->toContain(BughuntFakesServiceProvider::class);
});

test('3-6 登録点: BughuntFakesServiceProvider は AppServiceProvider より後 (後勝ち)', function (): void {
    $providers = externalFakeWiringRegisteredProviders();

    $fakeIndex = array_search(BughuntFakesServiceProvider::class, $providers, true);
    $appIndex = array_search(AppServiceProvider::class, $providers, true);

    expect($fakeIndex)->toBeInt()
        ->and($appIndex)->toBeInt()
        ->and($fakeIndex)->toBeGreaterThan($appIndex);
});

test('3-7 登録点: 起動済み container に provider がロードされている', function (): void {
    expect(array_key_exists(BughuntFakesServiceProvider::class, $this->app->getLoadedProviders()))->toBeTrue();
});

test('3-9 網羅性: provider の container 呼び出しは許可された形だけ', function (): void {
    $source = externalFakeWiringProviderSource();

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('3-10 網羅性: provider が参照する fake 系クラスは配線基盤 4 件ちょうど (差し替え先を含まない)', function (): void {
    // ★配置例外のキーも候補に足す。配線 provider は家系名への改名で名前の規則 (定義 2) から
    //   外れたため、namedClasses() だけでは候補から静かに脱落する。いまの結果は変わらない
    //   (走査器はクラス宣言名を参照として数えない) が、候補集合が黙って狭まること自体を防ぐ。
    $candidates = array_values(array_unique(array_merge(
        FakeClassCatalog::implementationClasses(),
        FakeClassCatalog::namedClasses(),
        array_keys(FakeClassCatalog::placementExceptions()),
    )));

    // 走査器 / 母集団導出が壊れて「空走査で緑」になるのを防ぐ (fail-closed)
    expect($candidates)->not->toBeEmpty();

    $actual = FakeWiringSourceScanner::referencedClasses(externalFakeWiringProviderSource(), $candidates);
    $expected = EXTERNAL_FAKE_WIRING_PROVIDER_REFERENCE_EXCEPTIONS;

    sort($actual);
    sort($expected);

    expect($actual)->toBe($expected);

    // 差し替え先が 1 つでも provider に現れたら赤くする (決定は宣言側にしか無い)。
    $fakes = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->fake,
        ExternalFakeDeclaration::swaps()
    );
    expect(array_values(array_intersect($actual, $fakes)))->toBe([]);
});

test('3-11 LLM: bughunt.local ∧ fake_llm=true でのみ Prompt fake が立ち、stopFaking で戻る', function (): void {
    $originalFlag = config(ExternalFakeDeclaration::LLM_FLAG);
    $originalEnvironment = $this->app['env'];

    try {
        expect(Prompt::isFaking())->toBeFalse();

        // (1) bughunt.local ∧ on → 立つ
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeDeclaration::LLM_FLAG => true]);
        (new BughuntFakesServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeTrue();

        Prompt::stopFaking();

        // (2) testing ∧ on → 立たない (static をテストプロセスで占有させない)
        $this->app['env'] = 'testing';
        (new BughuntFakesServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (3) local ∧ on → 立たない (実 API 検証を潰さない)
        $this->app['env'] = 'local';
        (new BughuntFakesServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();

        // (4) bughunt.local ∧ off → 立たない (既定 real LLM)
        $this->app['env'] = 'bughunt.local';
        config([ExternalFakeDeclaration::LLM_FLAG => false]);
        (new BughuntFakesServiceProvider($this->app))->boot();
        expect(Prompt::isFaking())->toBeFalse();
    } finally {
        // static の往復を**同一 test case 内で** assert する (afterEach はフェイルセーフ)
        if (Prompt::isFaking()) {
            Prompt::stopFaking();
        }
        expect(Prompt::isFaking())->toBeFalse();

        config([ExternalFakeDeclaration::LLM_FLAG => $originalFlag]);
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

test('3-13 宣言の健全性: abstract に重複が無く、許可環境は capability の部分集合である', function (): void {
    $swaps = ExternalFakeDeclaration::swaps();
    expect($swaps)->not->toBeEmpty();

    $abstracts = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
        $swaps
    );
    expect(array_values(array_unique($abstracts)))->toBe($abstracts);

    foreach ($swaps as $binding) {
        // 未宣言の flag は capabilityEnvironments() が例外にする (黙って空集合へ倒さない)。
        $capability = ExternalFakeDeclaration::capabilityEnvironments($binding->flag);

        expect($binding->allowedEnvironments)->not->toBeEmpty()
            ->and(array_values(array_diff($binding->allowedEnvironments, $capability)))
            ->toBe([], "{$binding->abstract} の許可環境が capability の許可環境を超えている");
    }
});

test('3-14 差し替えない対象: neverSwapped() は swaps() の abstract と 1 件も交わらない', function (): void {
    $neverSwapped = array_keys(ExternalFakeDeclaration::neverSwapped());

    // 空集合で緑にしない (宣言そのものが消えたら赤くする)
    expect($neverSwapped)->not->toBeEmpty();

    foreach (ExternalFakeDeclaration::neverSwapped() as $class => $reason) {
        expect(class_exists($class) || interface_exists($class))->toBeTrue("実在しないクラス: {$class}")
            ->and(mb_strlen($reason))->toBeGreaterThanOrEqual(30);
    }

    $abstracts = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
        ExternalFakeDeclaration::swaps()
    );

    expect(array_values(array_intersect($neverSwapped, $abstracts)))->toBe([]);
});

test('3-15 設定との一致: 宣言の flag が config に実在し、config 側に宣言外の偽物 flag が無い', function (): void {
    $variables = ExternalFakeDeclaration::FLAG_ENVIRONMENT_VARIABLES;
    expect($variables)->not->toBeEmpty();

    // (a) 宣言した config キーが実在すること (キー名の typo を黙って通さない)。
    foreach ($variables as $flag => $variable) {
        expect(str_starts_with($flag, 'testing.'))->toBeTrue("capability flag は testing.* であること: {$flag}")
            ->and(config()->has($flag))->toBeTrue("config に存在しない capability flag: {$flag}");
    }

    // (b) config/testing.php に現れる TESTING_FAKE_* の集合が宣言と一致すること
    //     (宣言の外に偽物のフラグが増えたらその場で落とす)。
    //     ★config('testing') 全体との完全一致は要求しない — 偽物と無関係な testing 設定を
    //       将来足せなくなるため。
    $matches = [];
    preg_match_all(
        '/TESTING_FAKE_[A-Z_]+/',
        FakeClassCatalog::sourceOf('config/testing.php'),
        $matches
    );

    $found = array_values(array_unique($matches[0]));
    $declared = array_values($variables);
    sort($found);
    sort($declared);

    expect($found)->toBe($declared);
});

test('3-16 宣言集合の固定 (意図的な摩擦): abstract 一覧が件数付きで一致する', function (): void {
    // ★この検査を消すと「宣言から entry を消す」変異が**どこにも映らなくなる**。
    //   宣言が唯一の正本なので、entry を消すと provider の bind もデータセットも同時に縮み、
    //   3-1〜3-3 は縮んだ母集団のまま緑になる。映すには「宣言とは独立にもう一度書いた一覧」が要る
    //   (同じ作法の先例: FakeClassReferenceInvariantTest の 4-2 / 4-4)。
    //   増減させるときは宣言と本 test の 2 か所を同時に触ること。
    $abstracts = array_map(
        static fn (ExternalFakeBinding $binding): string => $binding->abstract,
        ExternalFakeDeclaration::swaps()
    );

    expect($abstracts)->toHaveCount(7)
        ->and($abstracts)->toBe([
            TicketCheckoutGateway::class,
            StripeGatewayInterface::class,
            AutoRechargeGatewayInterface::class,
            TakeObjectStorage::class,
            RenderObjectStorage::class,
            RecaptchaVerifier::class,
            SocialiteDriverResolver::class,
        ]);
});
