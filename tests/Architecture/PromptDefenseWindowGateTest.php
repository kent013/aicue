<?php

declare(strict_types=1);

use App\Support\Llm\GuardedPrompt;
use App\Support\Llm\PromptCanary;
use App\Support\Llm\PromptDefense;
use App\Support\Llm\UntrustedTextSanitizer;
use Kent013\PrismPrompt\Values\UserInput;
use Tests\Support\Llm\PromptWindowCall;
use Tests\Support\Llm\PromptWindowRule;
use Tests\Support\Llm\PromptWindowScanner;
use Tests\Support\Llm\PromptWindowSite;
use Tests\Support\PhpReferenceScanner;
use Tests\Support\PromptYaml;

/*
 * 窓口通過の構造検査 gate (裁定 AG-028 の「窓口通過の構造検査 gate」)。
 *
 * 守るのは「app/Prompts/ の factory → 窓口 (PromptDefense) → 実行単位 (GuardedPrompt)」
 * という 1 本道であり、迂回経路を deny-by-default で消す。
 *
 * ★ 走査母集団は検査の性質ごとに違う (「窓口 gate は app/ だけ」と一括で言わない):
 *   - 呼び出し site の検査 = app/ + routes/ + database/ + config/ + bootstrap/ の 5 根。
 *     routes/ のクロージャや seeder からの直接呼び出しは Prism 直呼びではないため
 *     PromptGuardrailTest では捕まらない。ここを app/ だけにすると 1 本道が保証できない
 *   - 所有権の検査と reflection 系 = app/ (クラス配置の問題であるため)
 *   - tests/ は常に母集団外 (GuardedPromptInspector が内部へ触るのは正当)
 *
 * ★ YAML の変数抽出を正規表現で行える根拠は、PromptYamlContractTest が prompt YAML に
 *   書ける Blade 構文を 2 形 (単純変数展開 / 防御指示の静的呼び出し) へ絞っているからである。
 *   **構文契約が先、抽出は後**。契約側を緩めるなら本 gate の抽出も同時に見直すこと。
 */

/** 窓口ファイル (vendor prompt 読み込みと実行単位構築を許す唯一の場所)。 */
const WINDOW_FILE = 'app/Support/Llm/PromptDefense.php';

/** 実行単位ファイル (合言葉を property 型として正当に参照する)。 */
const GUARDED_PROMPT_FILE = 'app/Support/Llm/GuardedPrompt.php';

/** 帰属なし窓口を呼んでよい唯一の factory (テンプレート同梱の見本)。 */
const UNATTRIBUTED_FACTORY_FILE = 'app/Prompts/ExampleSummaryPrompt.php';

/**
 * 呼び出し site 検査の走査根 (相対 => 絶対)。
 *
 * @return array<string, string>
 */
function promptWindowCallSiteRoots(): array
{
    $repoRoot = dirname(__DIR__, 2);

    $roots = [];
    foreach (['app', 'routes', 'database', 'config', 'bootstrap'] as $relative) {
        $roots[$relative] = $repoRoot.'/'.$relative;
    }

    return $roots;
}

/**
 * 所有権検査の走査根 (app/ のみ)。
 *
 * @return array<string, string>
 */
function promptWindowOwnershipRoots(): array
{
    return ['app' => dirname(__DIR__, 2).'/app'];
}

/** @return list<PromptWindowSite> */
function promptWindowCallSites(): array
{
    return PromptWindowScanner::scanRoots(promptWindowCallSiteRoots());
}

/** @return list<ReflectionMethod> app/Prompts/ の全 public static メソッド */
function promptFactoryPublicStaticMethods(): array
{
    $base = realpath(dirname(__DIR__, 2).'/app/Prompts');
    if (! is_string($base)) {
        return [];
    }

    $methods = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($base) + 1, -4);
        $class = 'App\\Prompts\\'.str_replace('/', '\\', $relative);
        if (! class_exists($class)) {
            continue;
        }
        foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() && $method->getDeclaringClass()->getName() === $class) {
                $methods[] = $method;
            }
        }
    }

    return $methods;
}

/**
 * app/Prompts/ の窓口呼び出し (引数のリテラル読み取り込み)。
 *
 * @return list<PromptWindowCall>
 */
function promptFactoryWindowCalls(): array
{
    $calls = [];
    $base = dirname(__DIR__, 2).'/app/Prompts';
    foreach (PhpReferenceScanner::phpFiles($base, 'app/Prompts') as $relative => $source) {
        array_push($calls, ...PromptWindowScanner::windowCalls($relative, $source));
    }

    return $calls;
}

/**
 * prompt YAML の Blade 変数名を集める (`{{ $name }}` 形のみ。構文契約が前提)。
 *
 * @return array<string, list<string>> template 名 => 変数名 (昇順)
 */
function promptYamlBladeVariables(): array
{
    $result = [];
    foreach (PromptYaml::paths() as $path) {
        $errors = [];
        $parsed = PromptYaml::parseOrFail($path, $errors);
        if ($parsed === null) {
            continue;
        }
        $template = basename($path, '.yaml');
        $source = '';
        foreach (['system_prompt', 'prompt'] as $key) {
            $value = $parsed[$key] ?? null;
            if (is_string($value)) {
                $source .= $value."\n";
            }
        }
        $matches = [];
        preg_match_all('/\{\{\s*\$([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/', $source, $matches);
        /** @var list<string> $names */
        $names = array_values(array_unique($matches[1]));
        sort($names);
        $result[$template] = $names;
    }

    return $result;
}

// ── 1. 走査根の健全性 ────────────────────────────────────────────────
test('窓口 gate の走査根 5 本すべてで PHP ファイルが検出される (空振り防止)', function (): void {
    foreach (promptWindowCallSiteRoots() as $relative => $absolute) {
        $files = PhpReferenceScanner::phpFiles($absolute, $relative);
        expect($files)->not->toBeEmpty("走査根 {$relative} で PHP ファイルが 0 件です (根の移動 / typo)");
    }
});

// ── 2. vendor prompt の読み込みは窓口 1 ファイルだけ ────────────────
test('vendor prompt の読み込み (Prompt::load 等) は窓口 1 ファイルに限る', function (): void {
    $paths = PromptWindowScanner::pathsOf(promptWindowCallSites(), PromptWindowRule::VendorPromptLoad);

    expect($paths)->toBe([WINDOW_FILE],
        'vendor の prompt 読み込みは窓口 (PromptDefense) の中でのみ行ってください。'
        .'窓口を迂回すると無害化・タグ境界化・合言葉の合流がすべて抜けます。');
});

// ── 3. 実行単位の構築は窓口 1 ファイルだけ ──────────────────────────
test('実行単位 (new GuardedPrompt) の構築は窓口 1 ファイルに限る', function (): void {
    $paths = PromptWindowScanner::pathsOf(promptWindowCallSites(), PromptWindowRule::GuardedPromptConstruction);

    expect($paths)->toBe([WINDOW_FILE],
        '実行単位を窓口の外で組み立てると、合言葉と応答検査の対応が崩れます。');
});

// ── 4. 窓口の内部部品の所有権 ────────────────────────────────────────
test('窓口の内部部品 (UserInput / 無害化 / 合言葉) を参照してよいファイルは固定されている', function (): void {
    $allowed = [
        UserInput::class => [WINDOW_FILE],
        UntrustedTextSanitizer::class => [WINDOW_FILE],
        // 実行単位は合言葉を constructor / property の型として正当に参照する (昇順で持つ)
        PromptCanary::class => [GUARDED_PROMPT_FILE, WINDOW_FILE],
    ];

    $actual = [];
    foreach (PromptWindowScanner::scanRoots(promptWindowOwnershipRoots()) as $site) {
        if ($site->rule === PromptWindowRule::InternalPartReference) {
            $actual[$site->symbol][$site->path] = true;
        }
    }

    foreach ($allowed as $symbol => $expected) {
        $paths = array_keys($actual[$symbol] ?? []);
        sort($paths);
        expect($paths)->toBe($expected,
            "{$symbol} を参照してよいのは ".implode(' / ', $expected).' だけです。'
            .'無害化・タグ境界化・合言葉の生成を factory 側で自前実装すると規律が分散します。');
    }

    // 目録に無いシンボルが検出されたら、所有権の宣言が古い (順序は問わない)
    $detected = array_keys($actual);
    $declared = array_keys($allowed);
    sort($detected);
    sort($declared);
    expect($detected)->toBe($declared, '所有権を宣言していない内部部品が検出されました');
});

// ── 5. 窓口の呼び出し site ───────────────────────────────────────────
test('窓口 (PromptDefense::load) を呼べるのは app/Prompts/ の factory だけ', function (): void {
    $paths = PromptWindowScanner::pathsOf(promptWindowCallSites(), PromptWindowRule::WindowLoad);

    $outside = array_values(array_filter(
        $paths,
        static fn (string $path): bool => ! str_starts_with($path, 'app/Prompts/'),
    ));

    expect($paths)->not->toBeEmpty();
    expect($outside)->toBe([],
        'Service や route から直接 prompt を組むと、分類目録 (PromptUntrustedInputContractTest) と'
        .'帰属の検査を迂回できてしまいます。app/Prompts/ の factory を通してください。');
});

test('帰属なしの窓口 (loadUnattributed) を呼べるのは見本 factory 1 件だけ', function (): void {
    $paths = PromptWindowScanner::pathsOf(promptWindowCallSites(), PromptWindowRule::WindowLoadUnattributed);

    expect($paths)->toBe([UNATTRIBUTED_FACTORY_FILE],
        '帰属なしの窓口は帰属の対象を構造的に持たない見本 1 本のためだけにあります。'
        .'新しい factory は PromptDefense::load (LlmCallContextData 必須) を使ってください。');
});

// ── 6. factory の戻り値型 ────────────────────────────────────────────
test('app/Prompts/ の public static メソッドは GuardedPrompt を返す', function (): void {
    $methods = promptFactoryPublicStaticMethods();
    expect($methods)->not->toBeEmpty();

    $violations = [];
    foreach ($methods as $method) {
        $type = $method->getReturnType();
        $name = $type instanceof ReflectionNamedType ? $type->getName() : null;
        if ($name !== GuardedPrompt::class) {
            $violations[] = $method->getDeclaringClass()->getShortName().'::'.$method->getName()
                .' => '.($name ?? '(型宣言なし)');
        }
    }

    expect($violations)->toBe([],
        'factory が vendor の prompt 型を外へ出すと、応答検査を経ない executeSync が呼べてしまいます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

// ── 7. 窓口へ渡す引数はリテラルで書く ────────────────────────────────
test('factory が窓口へ渡す untrusted はキーが文字列リテラルの配列リテラルである', function (): void {
    $calls = promptFactoryWindowCalls();
    expect($calls)->not->toBeEmpty();

    $violations = [];
    foreach ($calls as $call) {
        if ($call->untrustedKeys === null) {
            $violations[] = "{$call->path}:{$call->line}";
        }
    }

    expect($violations)->toBe([],
        'untrusted: には名前付き引数で、キーがすべて文字列リテラルの配列リテラルを渡してください。'
        .'キーを動的に組み立てると YAML との 1 対 1 検査が無効化されます。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('factory が窓口へ渡す template は文字列リテラルで、YAML のファイル名と name に一致する', function (): void {
    $calls = promptFactoryWindowCalls();
    expect($calls)->not->toBeEmpty();

    /** @var array<string, string> $yamlNames ファイル名 (拡張子なし) => name キー */
    $yamlNames = [];
    foreach (PromptYaml::paths() as $path) {
        $errors = [];
        $parsed = PromptYaml::parseOrFail($path, $errors);
        $name = $parsed['name'] ?? null;
        if (is_string($name)) {
            $yamlNames[basename($path, '.yaml')] = trim($name);
        }
    }

    $violations = [];
    foreach ($calls as $call) {
        if ($call->template === null) {
            $violations[] = "{$call->path}:{$call->line}: template が文字列リテラルではありません";

            continue;
        }
        if (! array_key_exists($call->template, $yamlNames)) {
            $violations[] = "{$call->path}:{$call->line}: resources/prompts/{$call->template}.yaml がありません";

            continue;
        }
        if ($yamlNames[$call->template] !== $call->template) {
            $violations[] = "{$call->path}:{$call->line}: YAML の name ({$yamlNames[$call->template]}) が"
                ." ファイル名 ({$call->template}) と一致しません";
        }
    }

    expect($violations)->toBe([], implode(PHP_EOL, $violations));
});

// ── 8. factory の untrusted キー集合 == YAML の変数集合 − 合言葉 ────
test('factory の untrusted キー集合と YAML の Blade 変数集合が 1 対 1 で対応する', function (): void {
    $calls = promptFactoryWindowCalls();
    $yamlVariables = promptYamlBladeVariables();
    expect($calls)->not->toBeEmpty();
    expect($yamlVariables)->not->toBeEmpty();

    $guidance = 'YAML の変数はすべて untrusted か合言葉である。固定値・enum・locale などの'
        .' trusted 変数を足すときは、窓口の入口・値をリテラル / クラス定数 / enum case に限る'
        .'字句 gate・目録の 3 つを同じ PR で足すこと (docs/template-divergence.md)。';

    $violations = [];
    $covered = [];
    foreach ($calls as $call) {
        if ($call->template === null || $call->untrustedKeys === null) {
            continue; // 別テストが違反として報告済み
        }
        $covered[$call->template] = true;
        $expected = $yamlVariables[$call->template] ?? [];
        $expected = array_values(array_filter(
            $expected,
            static fn (string $name): bool => $name !== PromptDefense::CANARY_VARIABLE,
        ));
        $actual = $call->untrustedKeys;
        sort($actual);

        if ($actual !== $expected) {
            $violations[] = "{$call->path}: untrusted [".implode(', ', $actual)
                .'] / YAML 変数 ['.implode(', ', $expected).']';
        }
    }

    // 対応する factory を持たない YAML が無いこと (書きっぱなしの雛形を残さない)
    foreach (array_keys($yamlVariables) as $template) {
        if (! isset($covered[$template])) {
            $violations[] = "resources/prompts/{$template}.yaml に対応する factory がありません";
        }
    }

    expect($violations)->toBe([], $guidance.PHP_EOL.implode(PHP_EOL, $violations));
});

// ── 9. 実行単位の公開面 ──────────────────────────────────────────────
test('GuardedPrompt の public メソッドは __construct / executeSync の 2 つだけ', function (): void {
    $methods = [];
    foreach ((new ReflectionClass(GuardedPrompt::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        $methods[] = $method->getName();
    }
    sort($methods);

    expect($methods)->toBe(['__construct', 'executeSync'],
        '公開面を増やすと応答検査を迂回する脱出口 (inner() 等) が生まれます。'
        .'テストから内部を覗く必要があるなら tests/Support/Llm/GuardedPromptInspector.php を使ってください。');
});

// ── 10. 判定関数の自己検証 (合成負例 / 正例) ─────────────────────────
test('合成負例で判定が発火し、正例では発火しない (gate 自身の生存確認)', function (): void {
    // (a) routes/ 相当のファイルスコープから窓口を直接呼ぶ形 (5 根走査でしか捕まらない)
    $routeSource = <<<'PHP'
<?php
use App\Support\Llm\PromptDefense;
Route::get('/x', function () {
    return PromptDefense::load(template: 'sop-extract', untrusted: ['text' => 'a'], context: $c);
});
PHP;
    $routeSites = PromptWindowScanner::scan('routes/web.php', $routeSource);
    expect(PromptWindowScanner::pathsOf($routeSites, PromptWindowRule::WindowLoad))->toBe(['routes/web.php']);

    // (b) 窓口の外での vendor prompt 読み込み
    $vendorLoad = <<<'PHP'
<?php
namespace App\Services;
use Kent013\PrismPrompt\Prompt;
class Sneaky { public function go(): mixed { return Prompt::load('sop-extract', [])->executeSync(); } }
PHP;
    expect(PromptWindowScanner::pathsOf(
        PromptWindowScanner::scan('app/Services/Sneaky.php', $vendorLoad),
        PromptWindowRule::VendorPromptLoad,
    ))->toBe(['app/Services/Sneaky.php']);

    // (c) 窓口の外での実行単位構築
    $construction = <<<'PHP'
<?php
namespace App\Services;
use App\Support\Llm\GuardedPrompt;
class Sneaky { public function go(): GuardedPrompt { return new GuardedPrompt($p, $c, 't'); } }
PHP;
    expect(PromptWindowScanner::pathsOf(
        PromptWindowScanner::scan('app/Services/Sneaky.php', $construction),
        PromptWindowRule::GuardedPromptConstruction,
    ))->toBe(['app/Services/Sneaky.php']);

    // (d) 内部部品を factory が自前で参照する形
    $internal = <<<'PHP'
<?php
namespace App\Prompts;
use Kent013\PrismPrompt\Values\UserInput;
class Sneaky { public static function make(string $t): mixed { return UserInput::from($t); } }
PHP;
    expect(PromptWindowScanner::pathsOf(
        PromptWindowScanner::scan('app/Prompts/Sneaky.php', $internal),
        PromptWindowRule::InternalPartReference,
    ))->toBe(['app/Prompts/Sneaky.php']);

    // (e) 動的に組み立てた引数 (リテラル読み取りが null になる)
    $dynamic = <<<'PHP'
<?php
namespace App\Prompts;
use App\Support\Llm\PromptDefense;
class Sneaky
{
    public static function make(string $key, string $value, string $name): mixed
    {
        return PromptDefense::load(template: $name, untrusted: [$key => $value], context: $c);
    }
}
PHP;
    $dynamicCalls = PromptWindowScanner::windowCalls('app/Prompts/Sneaky.php', $dynamic);
    expect($dynamicCalls)->toHaveCount(1);
    expect($dynamicCalls[0]->template)->toBeNull();
    expect($dynamicCalls[0]->untrustedKeys)->toBeNull();

    // (f) 先頭要素を import した部分修飾名で vendor prompt を読む形
    //     (部分修飾名を解決しなかった頃は `PrismPrompt\Prompt` のまま照合され見逃していた)
    $partiallyQualified = <<<'PHP'
<?php
namespace App\Services;
use Kent013\PrismPrompt;
class Sneaky { public function go(): mixed { return PrismPrompt\Prompt::load('sop-extract', []); } }
PHP;
    expect(PromptWindowScanner::pathsOf(
        PromptWindowScanner::scan('app/Services/Sneaky.php', $partiallyQualified),
        PromptWindowRule::VendorPromptLoad,
    ))->toBe(['app/Services/Sneaky.php']);

    // (g) 受け手を変数にして読み込み元を隠す形 = 未解決。**fail-closed で拾う** (規約 (b))。
    //     `load` は vendor 直読みか窓口呼び出しか判別できないので、
    //     窓口 1 ファイルにしか許されない側 (vendor 読み込み) として数える。
    $unresolvedReceiver = <<<'PHP'
<?php
namespace App\Services;
class Sneaky
{
    public function go(string $prompt): mixed { return $prompt::load('sop-extract', []); }

    public function goUnattributed(string $prompt): mixed { return $prompt::loadUnattributed('sop-extract', []); }
}
PHP;
    $unresolvedSites = PromptWindowScanner::scan('app/Services/Sneaky.php', $unresolvedReceiver);
    expect(PromptWindowScanner::pathsOf($unresolvedSites, PromptWindowRule::VendorPromptLoad))
        ->toBe(['app/Services/Sneaky.php']);
    expect(PromptWindowScanner::pathsOf($unresolvedSites, PromptWindowRule::WindowLoadUnattributed))
        ->toBe(['app/Services/Sneaky.php']);

    // 正例: 名前空間相対の同名クラス (`App\Services\PrismPrompt\Prompt`) は vendor ではない
    $sameNamespace = <<<'PHP'
<?php
namespace App\Services;
class Innocent { public function go(): mixed { return PrismPrompt\Prompt::load('note', []); } }
PHP;
    expect(PromptWindowScanner::scan('app/Services/Innocent.php', $sameNamespace))->toBe([]);

    // 正例: コメント / 文字列リテラル中の記述には反応しない (gate 自身の説明文を数えない)
    $benign = <<<'PHP'
<?php
namespace App\Services;
class Doc
{
    // Prompt::load() や new GuardedPrompt() は窓口の中だけで書く
    public function note(): string
    {
        return 'PromptDefense::loadUnattributed() を直接呼ばないこと';
    }
}
PHP;
    expect(PromptWindowScanner::scan('app/Services/Doc.php', $benign))->toBe([]);

    // 正例: 実際の窓口ファイルは untrusted / template をリテラルで受け取っている
    $realCalls = promptFactoryWindowCalls();
    foreach ($realCalls as $call) {
        expect($call->template)->not->toBeNull();
        expect($call->untrustedKeys)->not->toBeNull();
    }
});
