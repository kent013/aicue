<?php

declare(strict_types=1);

use App\Services\Billing\Fakes\FakeExternalUrl;
use App\Services\Billing\Fakes\FakeStripeGateway;
use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;
use App\Services\Billing\TicketCheckoutGateway;
use App\Support\FakeStorageGate;
use Tests\Support\ExternalFakes\FakeWiringSourceScanner;

/*
 * fake 配線 gate の走査器 (FakeWiringSourceScanner) の positive / negative 固定。
 *
 * gate 自体がセキュリティ機構であり、**走査器が壊れたら gate は静かに無力化する**。
 * PrimaryKeyStaticQueryScannerTest / AuthorizationMarkerScannerTest と同じ位置づけで、
 * 「何を検出し、何を検出しないか」をここで恒久固定する。
 *
 * 新しい抜け道を見つけたら、allowlist を緩めるのではなく**ここにケースを足す**。
 */

/** 走査対象の最小ソースを組み立てる (namespace + use + メソッド本体) */
function fakeWiringScannerSource(string $uses, string $body, string $namespace = 'App\\Providers'): string
{
    return "<?php\n\ndeclare(strict_types=1);\n\n"
        ."namespace {$namespace};\n\n"
        ."{$uses}\n\n"
        ."final class DemoProvider\n"
        ."{\n"
        ."    public function register(): void\n"
        ."    {\n"
        ."{$body}\n"
        ."    }\n"
        ."}\n";
}

test('5-1: bind(A::class, B::class) は 1 組として読み取れる', function (): void {
    $source = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);');

    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
        ['abstract' => 'App\Demo\A', 'concrete' => 'App\Demo\B'],
    ]);
});

test('5-2: bind の第 2 引数が closure なら concrete は null (呼び出し側で fail させる)', function (): void {
    $source = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, fn () => new \App\Demo\B);');

    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
        ['abstract' => 'App\Demo\A', 'concrete' => null],
    ]);
});

test('5-3: singleton() は許可された呼び出し形ではない', function (): void {
    $source = fakeWiringScannerSource('', '        $this->app->singleton(\App\Demo\A::class, \App\Demo\B::class);');

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
});

test('5-4: 未知の container API (rebinding) も検出する = API 名の列挙に依存しない', function (): void {
    $source = fakeWiringScannerSource('', '        $this->app->rebinding(\App\Demo\A::class, fn () => null);');

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
});

test('5-5: make(FakeStorageGate::class) は許可される', function (): void {
    $source = fakeWiringScannerSource(
        'use App\Support\FakeStorageGate;',
        '        $this->app->make(FakeStorageGate::class);'
    );

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([]);
});

test('5-6: make(未分類クラス) は委譲による逃げ道として検出する', function (): void {
    $source = fakeWiringScannerSource('', '        $this->app->make(\App\Demo\SomeRegistrar::class)->register();');

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1);
});

test('5-7: app() / resolve() / Container::getInstance() は間接到達として検出する', function (): void {
    $helper = fakeWiringScannerSource('', '        app()->bind(\App\Demo\A::class, \App\Demo\B::class);');
    $resolve = fakeWiringScannerSource('', '        resolve(\App\Demo\A::class);');
    $container = fakeWiringScannerSource(
        'use Illuminate\Container\Container;',
        '        Container::getInstance()->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($helper))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($resolve))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($container))->toHaveCount(1);
});

test('5-8: $this->app の非呼び出し出現 (変数への退避) を検出する', function (): void {
    $source = fakeWiringScannerSource('', '        $container = $this->app;');

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toHaveCount(1);
});

test('5-9: コメント / docblock 中の container 呼び出しは誤検出しない', function (): void {
    $body = "        // \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class);\n"
        ."        /** \$this->app->singleton(\\App\\Demo\\A::class, \\App\\Demo\\B::class); */\n"
        .'        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class);';
    $source = fakeWiringScannerSource('', $body);

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('5-10: FQCN と完全一致する文字列リテラルは参照として検出する', function (): void {
    $source = fakeWiringScannerSource('', '        $class = \'App\Services\Billing\Fakes\FakeStripeGateway\';');

    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))
        ->toBe([FakeStripeGateway::class]);
});

test('5-11: 説明文中の class basename は部分一致では検出しない', function (): void {
    $source = fakeWiringScannerSource('', '        $note = \'説明文に FakeStripeGateway と書いただけ\';');

    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))->toBe([]);
});

test('5-12: グループ use を FQCN へ解決する', function (): void {
    $source = fakeWiringScannerSource(
        'use App\Services\Billing\Fakes\{FakeExternalUrl as Ext, FakeStripeGateway};',
        '        $this->app->bind(Ext::class, FakeStripeGateway::class);'
    );

    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
        ['abstract' => FakeExternalUrl::class, 'concrete' => FakeStripeGateway::class],
    ]);
});

test('5-13: use された短縮名の bind 組を FQCN 正規化して返す (現行 provider の実パターン)', function (): void {
    $uses = "use App\Services\Billing\Fakes\FakeTicketCheckoutGateway;\n"
        .'use App\Services\Billing\TicketCheckoutGateway;';
    $source = fakeWiringScannerSource(
        $uses,
        '        $this->app->bind(TicketCheckoutGateway::class, FakeTicketCheckoutGateway::class);'
    );

    expect(FakeWiringSourceScanner::bindPairs($source))->toBe([
        ['abstract' => TicketCheckoutGateway::class, 'concrete' => FakeTicketCheckoutGateway::class],
    ]);
});

test('5-14: alias 付き use も FQCN へ正規化する', function (): void {
    $source = fakeWiringScannerSource(
        'use App\Services\Billing\Fakes\FakeStripeGateway as Aliased;',
        '        $class = Aliased::class;'
    );

    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStripeGateway::class]))
        ->toBe([FakeStripeGateway::class]);
});

test('5-15: make(許可クラス) の chain 呼び出しでも引数照合が効く', function (): void {
    $uses = "use App\Services\AI\Testing\CannedPromptFakeRegistrar;\n"
        .'use App\Support\FakeStorageGate;';
    $body = "        \$this->app->make(FakeStorageGate::class)->enabled();\n"
        .'        $this->app->make(CannedPromptFakeRegistrar::class)->install();';
    $source = fakeWiringScannerSource($uses, $body);

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toBe([])
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toBe([]);
});

test('5-16: bind の第 1 引数が変数の形は禁止する (bindPairs が読めない = 偽グリーン封じ)', function (): void {
    $source = fakeWiringScannerSource(
        'use App\Services\Render\Fakes\FakeRenderObjectStorage;',
        '        $this->app->bind($abstract, FakeRenderObjectStorage::class);'
    );

    expect(FakeWiringSourceScanner::disallowedContainerCalls($source))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::bindPairs($source))->toBe([]);
});

test('5-17: 同一 namespace の短縮名参照 (use なし) も検出する', function (): void {
    $body = "        \$class = FakeStorageGate::class;\n"
        .'        $gate = new FakeStorageGate($this->app);';
    $source = fakeWiringScannerSource('', $body, 'App\Support');

    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStorageGate::class]))
        ->toBe([FakeStorageGate::class]);
});

test('5-18: bind(…, true) / 引数付き make / 名前付き引数は許可形から外れる', function (): void {
    $shared = fakeWiringScannerSource('', '        $this->app->bind(\App\Demo\A::class, \App\Demo\B::class, true);');
    $makeWithArgs = fakeWiringScannerSource(
        'use App\Support\FakeStorageGate;',
        '        $this->app->make(FakeStorageGate::class, []);'
    );
    $named = fakeWiringScannerSource(
        '',
        '        $this->app->bind(abstract: \App\Demo\A::class, concrete: \App\Demo\B::class);'
    );

    expect(FakeWiringSourceScanner::disallowedContainerCalls($shared))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedContainerCalls($makeWithArgs))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedContainerCalls($named))->toHaveCount(1);
});

test('5-19: クラス宣言名は自己参照として数えない', function (): void {
    $source = "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Support;\n\n"
        ."final class FakeStorageGate\n{\n}\n";

    expect(FakeWiringSourceScanner::referencedClasses($source, [FakeStorageGate::class]))->toBe([]);
});

test('5-20: alias 付き use でも Container / App facade 経由の到達を検出する', function (): void {
    // use alias で短縮名を変えれば末尾セグメント照合をすり抜けられる、という抜け道を封じる
    // (Codex 実装レビュー Round 1 の Critical)。
    $container = fakeWiringScannerSource(
        'use Illuminate\Container\Container as C;',
        '        C::getInstance()->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );
    $facade = fakeWiringScannerSource(
        'use Illuminate\Support\Facades\App as LaravelApp;',
        '        LaravelApp::make(\App\Demo\A::class);'
    );

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($container))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($facade))->toHaveCount(1);
});

test('5-21: use function の alias 経由でも container helper を検出する', function (): void {
    $source = fakeWiringScannerSource(
        'use function app as container;',
        '        container()->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($source))->toHaveCount(1);
});

test('5-22: $this-> の動的メンバアクセスは fail-closed で禁止する', function (): void {
    // `$this->{'app'}->bind(…)` は $this->app のトークン列に現れないため、
    // 禁止しないと全 gate をすり抜ける (Codex 実装レビュー Round 2 の Critical)。
    $literal = fakeWiringScannerSource('', '        $this->{\'app\'}->bind(\App\Demo\A::class, \App\Demo\B::class);');
    $variable = fakeWiringScannerSource('', '        $this->{$property}->bind(\App\Demo\A::class, \App\Demo\B::class);');
    $dynamicVariable = fakeWiringScannerSource('', '        $this->$property->bind(\App\Demo\A::class, \App\Demo\B::class);');

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($literal))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($variable))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($dynamicVariable))->toHaveCount(1);
});

test('5-23: 未分類の式経由で container を取り出す形も禁止する', function (): void {
    // container 到達 API を名前で列挙する方式は、未分類の式を挟まれると必ず抜けられる
    // (Codex 実装レビュー Round 3 の Critical)。閉じた文法から外れる形を fail-closed で拒否する。
    $objectVars = fakeWiringScannerSource(
        '',
        '        get_object_vars($this)[\'app\']->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );
    $immediatelyInvoked = fakeWiringScannerSource(
        '',
        '        (\'app\')()->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );
    $variableCallable = fakeWiringScannerSource(
        '',
        '        $factory()->bind(\App\Demo\A::class, \App\Demo\B::class);'
    );

    expect(FakeWiringSourceScanner::disallowedIndirectAccess($objectVars))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($immediatelyInvoked))->toHaveCount(1)
        ->and(FakeWiringSourceScanner::disallowedIndirectAccess($variableCallable))->toHaveCount(1);
});
