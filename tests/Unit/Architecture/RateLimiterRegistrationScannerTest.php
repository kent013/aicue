<?php

declare(strict_types=1);

use Tests\Support\RateLimiterRegistrationScanner;

/*
 * RateLimiterRegistrationScanner の positive/negative 固定。
 *
 * 走査器そのものが deny-by-default 検査 (RateLimiterKeyConventionTest) の土台であり、
 * 「検出漏れ = 偽グリーン」になるため、解析器の挙動をここで恒久固定する
 * (AuthorizationMarkerScannerTest と同じ責務設計)。
 */

/** @return array{names: list<string>, unresolved: list<string>} */
function scanRateLimiterSource(string $body, string $prelude = "use Illuminate\\Support\\Facades\\RateLimiter;\n"): array
{
    return RateLimiterRegistrationScanner::scan("<?php\n\n".$prelude."\n".$body, 'fake.php');
}

test('use 済み短縮名の単純な呼び出しを検出する', function (): void {
    $result = scanRateLimiterSource("RateLimiter::for('login', fn () => 1);");

    expect($result['names'])->toBe(['login']);
    expect($result['unresolved'])->toBe([]);
});

test('改行・空白を挟んだ呼び出しを検出する', function (): void {
    $result = scanRateLimiterSource("RateLimiter::for(\n    'login',\n    fn () => 1,\n);");

    expect($result['names'])->toBe(['login']);
    expect($result['unresolved'])->toBe([]);
});

test('完全修飾名の呼び出しを検出する', function (): void {
    $result = scanRateLimiterSource("\\Illuminate\\Support\\Facades\\RateLimiter::for('x', fn () => 1);", '');

    expect($result['names'])->toBe(['x']);
    expect($result['unresolved'])->toBe([]);
});

test('名前空間内の非完全修飾名は unresolved に入る (PHP の解決規則では別クラス)', function (): void {
    $result = scanRateLimiterSource("Illuminate\\Support\\Facades\\RateLimiter::for('x', fn () => 1);", '');

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toHaveCount(1);
    expect($result['unresolved'][0])->toContain('非完全修飾');
});

test('alias 経由の呼び出しは unresolved に入る', function (): void {
    $result = scanRateLimiterSource(
        "Limiter::for('x', fn () => 1);",
        "use Illuminate\\Support\\Facades\\RateLimiter as Limiter;\n",
    );

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toHaveCount(1);
    expect($result['unresolved'][0])->toContain('alias');
});

test('alias を import しただけで未使用なら fail させない', function (): void {
    $result = scanRateLimiterSource(
        "RateLimiter::for('x', fn () => 1);",
        "use Illuminate\\Support\\Facades\\RateLimiter;\nuse Some\\Other\\RateLimiter as Limiter;\n",
    );

    expect($result['names'])->toBe(['x']);
    expect($result['unresolved'])->toBe([]);
});

test('非リテラルな第 1 引数は unresolved に入る', function (): void {
    $result = scanRateLimiterSource(
        "RateLimiter::for(\$name, fn () => 1);\nRateLimiter::for(self::NAME, fn () => 1);",
    );

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toHaveCount(2);
});

test('コメント / 文字列リテラル中の記述は検出しない', function (): void {
    $result = scanRateLimiterSource(
        "// RateLimiter::for('fake')\n/* RateLimiter::for('fake2') */\n\$s = \"RateLimiter::for('fake3')\";",
    );

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toBe([]);
});

test('別クラスの ::for は検出しない', function (): void {
    $result = scanRateLimiterSource("OtherClass::for('x', fn () => 1);");

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toBe([]);
});

test('import の無い裸の RateLimiter::for は unresolved に入る', function (): void {
    $result = scanRateLimiterSource("RateLimiter::for('x', fn () => 1);", '');

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toHaveCount(1);
    expect($result['unresolved'][0])->toContain('import');
});

test('group use は受理しない (RateLimiter::for が unresolved になる)', function (): void {
    $result = scanRateLimiterSource(
        "RateLimiter::for('x', fn () => 1);",
        "use Illuminate\\Support\\Facades\\{RateLimiter, Auth};\n",
    );

    expect($result['names'])->toBe([]);
    expect($result['unresolved'])->toHaveCount(1);
});

test('クロージャの lexical use / trait use を名前空間 import と誤認しない', function (): void {
    $source = <<<'PHP'
<?php

use Illuminate\Support\Facades\RateLimiter;

class Foo
{
    use SomeTrait;

    public function bar(string $lane): void
    {
        $fn = function () use ($lane) {
            return $lane;
        };

        RateLimiter::for('login', $fn);
    }
}
PHP;

    $result = RateLimiterRegistrationScanner::scan($source, 'fake.php');

    expect($result['names'])->toBe(['login']);
    expect($result['unresolved'])->toBe([]);
});

test('unresolved の位置情報にはファイルパスと行番号が入る', function (): void {
    $result = scanRateLimiterSource("\n\nRateLimiter::for(\$name, fn () => 1);");

    expect($result['unresolved'][0])->toStartWith('fake.php:');
});
