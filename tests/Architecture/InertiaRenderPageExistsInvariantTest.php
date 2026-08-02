<?php

declare(strict_types=1);

/*
 * Architecture invariant: `Inertia::render` / `inertia()` が **literal で** 参照するページ
 * コンポーネントが `resources/js/pages/` に実在すること。
 *
 * SoT = app/ + routes/ の literal 参照 と resources/js/pages/ の実体、および
 * resources/js/inertia.ts の resolver 規約 (`./pages/{name}.svelte` を glob 解決し、
 * 未解決なら throw する)。参照先が無いページは **本番で白画面** になり、しかも
 * その画面へ入るまで誰も気づかない。
 *
 * 検出方式: PhpToken::tokenize で `Inertia::render(` / `inertia(` の第 1 引数を抽出する
 * (コメント・改行・named 引数 `component:` に対して regex より頑健)。
 *
 * **検査対象は literal 引数のみ**。変数・定数・連結など非 literal の第 1 引数は
 * 静的にページ名を決定できないため **存在検査の対象外**とする。ただし黙って穴が開かないよう、
 * 非 literal 呼び出し・`Route::inertia`・非正準形の facade 参照は「出現したら fail」させ、
 * 必要になった時点で本テストの拡張 (or allowlist 登録) を強制する (deny-by-default)。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * 非 literal ページ名の明示 allowlist (現状ゼロ)。
 *
 * 行番号非依存キー「相対パス::包囲関数/メソッド::呼出形::第1引数の正規化表現」形式。
 * 例: 'app/Http/Controllers/FooController.php::index::Inertia::render::$page'
 * 新規追加は「なぜ静的に決定できないか」の理由コメントとセットでのみ許可する。
 *
 * @var list<string>
 */
const INERTIA_DYNAMIC_ALLOWLIST = [];

/**
 * ページ名 → ページコンポーネント絶対パス (純関数)。
 */
function inertiaPageComponentPath(string $pageName): string
{
    return base_path('resources/js/pages/'.str_replace('\\', '/', $pageName).'.svelte');
}

/**
 * 走査対象 (app/ + routes/) の PHP ファイル一覧。
 *
 * @return list<array{absolute: string, relative: string}>
 */
function inertiaScanTargets(): array
{
    $root = base_path();
    $files = [];
    foreach (['app', 'routes'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $absolute = $file->getRealPath();
            if (! is_string($absolute)) {
                continue;
            }
            $files[] = [
                'absolute' => $absolute,
                'relative' => ltrim(str_replace($root, '', $absolute), '/'),
            ];
        }
    }

    return $files;
}

/**
 * index 以降で最初の significant token (whitespace / comment 以外) の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function inertiaNextSignificant(array $tokens, int $index): ?int
{
    $count = count($tokens);
    for ($i = $index; $i < $count; $i++) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * index 以前で直近の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function inertiaPrevSignificant(array $tokens, int $index): ?int
{
    for ($i = $index; $i >= 0; $i--) {
        if (! $tokens[$i]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            return $i;
        }
    }

    return null;
}

/**
 * 第 1 引数の正規化表現 (値開始から depth 0 の `,` または対応する閉じ括弧まで)。
 *
 * @param  list<PhpToken>  $tokens
 */
function inertiaNormalizeFirstArg(array $tokens, int $startIndex): string
{
    $depth = 0;
    $parts = [];
    $count = count($tokens);
    for ($i = $startIndex; $i < $count; $i++) {
        $token = $tokens[$i];
        $text = $token->text;
        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
        } elseif ($text === ')' || $text === ']' || $text === '}') {
            if ($depth === 0) {
                break;
            }
            $depth--;
        } elseif ($text === ',' && $depth === 0) {
            break;
        }
        if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }
        $parts[] = $text;
    }

    return implode('', $parts);
}

/**
 * index の token を包囲する function / method 名 (allowlist キー用)。
 *
 * @param  list<PhpToken>  $tokens
 */
function inertiaEnclosingFunction(array $tokens, int $index): string
{
    for ($i = $index; $i >= 0; $i--) {
        if (! $tokens[$i]->is(T_FUNCTION)) {
            continue;
        }
        $nameIndex = inertiaNextSignificant($tokens, $i + 1);
        if ($nameIndex !== null && $tokens[$nameIndex]->is(T_STRING)) {
            return $tokens[$nameIndex]->text;
        }
        // 無名関数はさらに外側を探す
    }

    return '(top-level)';
}

/**
 * quote / b-prefix を除去して literal 文字列の中身を返す。ページ名として扱えないものは null。
 */
function inertiaLiteralValue(string $raw): ?string
{
    if (preg_match('/\A[bB]?([\'"])(.*)\1\z/s', $raw, $m) !== 1) {
        return null;
    }
    /** @var array{0: string, 1: string, 2: string} $m */
    $inner = $m[2];
    if (str_contains($inner, '\\') && $m[1] === "'") {
        $inner = str_replace(['\\\\', "\\'"], ['\\', "'"], $inner);
    } elseif (str_contains($inner, '\\')) {
        return null; // double quote の escape sequence はページ名として扱わない
    }
    if (str_contains($inner, '$')) {
        return null;
    }

    return $inner;
}

/**
 * 1 ファイル分の PHP ソースを token 走査し、Inertia ページ参照を収集する (純関数)。
 *
 * @return array{
 *     literals: list<array{page: string, location: string}>,
 *     dynamics: list<string>,
 *     routeInertia: list<string>,
 *     nonCanonical: list<string>,
 * }
 */
function inertiaCollectFromSource(string $source, string $relative): array
{
    $literals = [];
    $dynamics = [];
    $routeInertia = [];
    $nonCanonical = [];

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($source);
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // ---- 非正準形の Inertia facade 参照 (走査をすり抜けるため禁止) ----
        // 走査は正準形 `Inertia::render` を前提にする。FQCN (`\Inertia\Inertia::render`) /
        // qualified / alias import (`use Inertia\Inertia as X`) が増えると silent hole になる。
        if ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
            && str_ends_with($token->text, 'Inertia\\Inertia')) {
            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
            if ($colonIndex !== null && $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
                $nonCanonical[] = "{$relative}:{$token->line} ({$token->text}:: 形は正準形 Inertia:: に統一)";

                continue;
            }
        }
        if ($token->is(T_USE)) {
            $nameIndex = inertiaNextSignificant($tokens, $i + 1);
            if ($nameIndex !== null
                && $tokens[$nameIndex]->is([T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_STRING])
                && str_ends_with($tokens[$nameIndex]->text, 'Inertia\\Inertia')) {
                $asIndex = inertiaNextSignificant($tokens, $nameIndex + 1);
                if ($asIndex !== null && $tokens[$asIndex]->is(T_AS)) {
                    $nonCanonical[] = "{$relative}:{$token->line} (use Inertia\\Inertia as ... の alias import 禁止)";

                    continue;
                }
            }
        }

        // ---- Route::inertia 検出 (ページ名が走査対象外になるため禁止) ----
        $isRouteFacade = ($token->is(T_STRING) && $token->text === 'Route')
            || ($token->is([T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED])
                && str_ends_with($token->text, 'Facades\\Route'));
        if ($isRouteFacade) {
            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
            if ($colonIndex !== null && $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
                $methodIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
                if ($methodIndex !== null && $tokens[$methodIndex]->is(T_STRING)
                    && $tokens[$methodIndex]->text === 'inertia') {
                    $routeInertia[] = "{$relative}:{$token->line}";

                    continue;
                }
            }
        }

        $callKind = null;
        $openIndex = null;

        // ---- Inertia::render( 検出 ----
        if ($token->is(T_STRING) && $token->text === 'Inertia') {
            $colonIndex = inertiaNextSignificant($tokens, $i + 1);
            if ($colonIndex === null || ! $tokens[$colonIndex]->is(T_DOUBLE_COLON)) {
                continue;
            }
            $methodIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
            if ($methodIndex === null || ! $tokens[$methodIndex]->is(T_STRING)
                || $tokens[$methodIndex]->text !== 'render') {
                continue;
            }
            $parenIndex = inertiaNextSignificant($tokens, $methodIndex + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $callKind = 'Inertia::render';
            $openIndex = $parenIndex;
        }

        // ---- inertia( / \inertia( helper 検出 ----
        $isHelperName = ($token->is(T_STRING) && $token->text === 'inertia')
            || ($token->is(T_NAME_FULLY_QUALIFIED) && $token->text === '\\inertia');
        if ($callKind === null && $isHelperName) {
            $prevIndex = inertiaPrevSignificant($tokens, $i - 1);
            if ($prevIndex !== null) {
                $prev = $tokens[$prevIndex];
                // メソッド呼び出し・定義・static 参照は対象外
                if ($prev->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST])) {
                    continue;
                }
                // 直前が識別子付きの `\` なら namespace 参照 (Foo\inertia)
                if ($prev->is(T_NS_SEPARATOR)) {
                    $beforeNs = inertiaPrevSignificant($tokens, $prevIndex - 1);
                    if ($beforeNs !== null && $tokens[$beforeNs]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                        continue;
                    }
                }
            }
            $parenIndex = inertiaNextSignificant($tokens, $i + 1);
            if ($parenIndex === null || $tokens[$parenIndex]->text !== '(') {
                continue;
            }
            $callKind = 'inertia';
            $openIndex = $parenIndex;
        }

        if ($callKind === null || $openIndex === null) {
            continue;
        }

        // ---- 第 1 引数の判定 ----
        $argIndex = inertiaNextSignificant($tokens, $openIndex + 1);
        if ($argIndex === null) {
            continue;
        }
        $argToken = $tokens[$argIndex];

        // 引数なし `inertia()` (= ResponseFactory 取得) はページ参照ではない
        if ($argToken->text === ')') {
            continue;
        }

        // named 引数 `component: '...'`
        if ($argToken->is(T_STRING) && $argToken->text === 'component') {
            $colonIndex = inertiaNextSignificant($tokens, $argIndex + 1);
            if ($colonIndex !== null && $tokens[$colonIndex]->text === ':') {
                $valueIndex = inertiaNextSignificant($tokens, $colonIndex + 1);
                if ($valueIndex !== null) {
                    $argIndex = $valueIndex;
                    $argToken = $tokens[$valueIndex];
                }
            }
        }

        $literal = null;
        if ($argToken->is(T_CONSTANT_ENCAPSED_STRING)) {
            // literal 直後が `.` (連結) なら非 literal 扱い
            $afterIndex = inertiaNextSignificant($tokens, $argIndex + 1);
            $isConcatenated = $afterIndex !== null && $tokens[$afterIndex]->text === '.';
            if (! $isConcatenated) {
                $literal = inertiaLiteralValue($argToken->text);
            }
        }

        if ($literal !== null) {
            $literals[] = ['page' => $literal, 'location' => "{$relative}:{$token->line}"];

            continue;
        }

        $dynamics[] = implode('::', [
            $relative,
            inertiaEnclosingFunction($tokens, $i),
            $callKind,
            inertiaNormalizeFirstArg($tokens, $argIndex),
        ]);
    }

    return [
        'literals' => $literals,
        'dynamics' => $dynamics,
        'routeInertia' => $routeInertia,
        'nonCanonical' => $nonCanonical,
    ];
}

/**
 * app/ + routes/ 全体の収集結果。
 *
 * @return array{
 *     literals: list<array{page: string, location: string}>,
 *     dynamics: list<string>,
 *     routeInertia: list<string>,
 *     nonCanonical: list<string>,
 * }
 */
function inertiaCollectAll(): array
{
    $result = ['literals' => [], 'dynamics' => [], 'routeInertia' => [], 'nonCanonical' => []];

    foreach (inertiaScanTargets() as $target) {
        $source = file_get_contents($target['absolute']);
        if (! is_string($source)) {
            continue;
        }
        $collected = inertiaCollectFromSource($source, $target['relative']);
        $result['literals'] = array_merge($result['literals'], $collected['literals']);
        $result['dynamics'] = array_merge($result['dynamics'], $collected['dynamics']);
        $result['routeInertia'] = array_merge($result['routeInertia'], $collected['routeInertia']);
        $result['nonCanonical'] = array_merge($result['nonCanonical'], $collected['nonCanonical']);
    }

    return $result;
}

test('Inertia render の literal 参照先ページが全て実在する', function (): void {
    $refs = inertiaCollectAll();

    // 走査自体が壊れて 0 件になる退行を検知する。
    expect(count($refs['literals']))->toBeGreaterThan(0);

    $missing = [];
    foreach ($refs['literals'] as $ref) {
        if (! is_file(inertiaPageComponentPath($ref['page']))) {
            $missing[] = "{$ref['location']} → resources/js/pages/{$ref['page']}.svelte (不存在)";
        }
    }

    expect($missing)->toBe([]);
});

test('非 literal のページ名は存在検査できないため allowlist 必須 (1 エントリ 1 呼び出し)', function (): void {
    $refs = inertiaCollectAll();

    $unlisted = array_values(array_diff($refs['dynamics'], INERTIA_DYNAMIC_ALLOWLIST));
    expect($unlisted)->toBe([]);

    // 同一キーへの複数マッチ (= 巻き込み許可) を禁止。
    $counts = array_count_values($refs['dynamics']);
    foreach (INERTIA_DYNAMIC_ALLOWLIST as $key) {
        expect($counts[$key] ?? 0)->toBeLessThanOrEqual(1);
    }
});

test('Route::inertia は本 gate の対象外になるため使用禁止', function (): void {
    expect(inertiaCollectAll()['routeInertia'])->toBe([]);
});

test('Inertia facade の非正準形 (FQCN / alias import) は走査をすり抜けるため禁止', function (): void {
    expect(inertiaCollectAll()['nonCanonical'])->toBe([]);
});

/*
 * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して gate が点灯することを確認する。
 * 現時点で dangling は 0 件 (= 予防 gate) のため、ここが空振りでないことの唯一の担保になる。
 */
test('負のコントロール: 実在しないページ名の literal を検出する', function (): void {
    $fixture = <<<'PHP'
    <?php
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Totally/Missing/Page', []);
        }
        public function named() {
            return Inertia::render(component: 'Another/Missing/Page');
        }
    }
    PHP;

    $refs = inertiaCollectFromSource($fixture, 'fixture.php');
    expect(array_column($refs['literals'], 'page'))->toBe(['Totally/Missing/Page', 'Another/Missing/Page']);

    $missing = [];
    foreach ($refs['literals'] as $ref) {
        if (! is_file(inertiaPageComponentPath($ref['page']))) {
            $missing[] = $ref['page'];
        }
    }
    expect($missing)->toHaveCount(2);
});

test('正のコントロール: 実在するページ名の literal は検出しない', function (): void {
    // 実在ページ (resources/js/pages/Dashboard.svelte) を参照する fixture。
    $fixture = <<<'PHP'
    <?php
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Dashboard', []);
        }
    }
    PHP;

    $refs = inertiaCollectFromSource($fixture, 'fixture.php');
    expect(array_column($refs['literals'], 'page'))->toBe(['Dashboard']);
    expect(is_file(inertiaPageComponentPath('Dashboard')))->toBeTrue();
});

test('負のコントロール: 非 literal / Route::inertia / 非正準形を検出する', function (): void {
    $dynamic = <<<'PHP'
    <?php
    use Inertia\Inertia;
    class FixtureController {
        public function show(string $page) {
            return Inertia::render($page, []);
        }
        public function concat(string $suffix) {
            return inertia('Prefix/'.$suffix);
        }
    }
    PHP;
    $refs = inertiaCollectFromSource($dynamic, 'fixture.php');
    expect($refs['literals'])->toBe([]);
    expect($refs['dynamics'])->toBe([
        'fixture.php::show::Inertia::render::$page',
        "fixture.php::concat::inertia::'Prefix/'.\$suffix",
    ]);
    // allowlist 未登録なので gate が点灯する。
    expect(array_values(array_diff($refs['dynamics'], INERTIA_DYNAMIC_ALLOWLIST)))->toHaveCount(2);

    $routeInertia = <<<'PHP'
    <?php
    use Illuminate\Support\Facades\Route;
    Route::inertia('/static', 'Static/Page');
    PHP;
    expect(inertiaCollectFromSource($routeInertia, 'fixture.php')['routeInertia'])->toHaveCount(1);

    $fqcn = <<<'PHP'
    <?php
    class FixtureController {
        public function index() {
            return \Inertia\Inertia::render('Dashboard', []);
        }
    }
    PHP;
    expect(inertiaCollectFromSource($fqcn, 'fixture.php')['nonCanonical'])->toHaveCount(1);

    $alias = <<<'PHP'
    <?php
    use Inertia\Inertia as Ia;
    class FixtureController {
        public function index() {
            return Ia::render('Dashboard', []);
        }
    }
    PHP;
    expect(inertiaCollectFromSource($alias, 'fixture.php')['nonCanonical'])->toHaveCount(1);
});
