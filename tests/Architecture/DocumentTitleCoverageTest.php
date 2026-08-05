<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * Architecture invariant: **Inertia を render する GET named route** は必ず
 * ページ固有のタイトルを持つ (deny-by-default)。
 *
 * SoT = SeoManager::resolveDocumentTitle() の優先順位:
 *   1. controller 供給メタ (route_classification.full)
 *   2. config('seo.minimal_titles')[route]
 *   3. SeoManager::setPrivateTitle() の動的上書き / config('seo.app_titles')[route]
 * どれも無い route は **サイト名のみ (`AI-CUE`)** になる。複数タブを開いたとき全部
 * 同じタイトルになる静かな UX 劣化で、既存テストでは落ちない。
 * (`<title>` = SeoComposer/SeoRenderer、SPA 遷移時の document.title =
 *  HandleInertiaRequests の共有 prop `title` で、どちらも同じ経路を読む)
 *
 * 検出方式: Route ファサードで route を列挙し、action を
 * `Class@method` / invokable / Closure に分けて解決する。Inertia の render 判定と
 * setPrivateTitle 判定は **PhpToken でメソッド本体に限定して**行う
 * (InertiaRenderPageExistsInvariantTest と同じ token 走査基盤)。
 *
 * **メソッド粒度が必須である根拠 (実測)**:
 *   - ConfirmRecentAuthController: ファイル粒度だと JsonResponse を返す status() まで
 *     Inertia 扱いになる (誤検出)
 *   - CaptureManualController: ファイル粒度だと show() の setPrivateTitle が index() を
 *     覆い隠す (取りこぼし。本 gate の本命 1 件)
 *
 * **正準形 (`Inertia::render` / `inertia()`) だけを見ることの前提**:
 * alias import (`use Inertia\Inertia as I;` → `I::render()`) や FQCN 形は本 gate の
 * 走査をすり抜けるが、それらは **`InertiaRenderPageExistsInvariantTest` の
 * 「Inertia facade の非正準形 (FQCN / alias import) は禁止」テストが app/ + routes/ 全体で
 * deny-by-default に落としている**。route の action となる controller は必ず app/ 配下なので、
 * 非正準形は本 gate へ到達する前に別 gate で fail する = 二重に守られている。
 * **この相互依存は load-bearing**: 当該テストを緩めるなら、本 gate に alias 解決を
 * 足さない限り「Inertia を描画するのにタイトル未網羅の route」が黙って通る穴が開く。
 *
 * DB 不使用の静的検査 (既存 Architecture テストと同じ作法)。
 */

/**
 * パッケージが所有する route 名の prefix (自前で head を持つ / アプリのページではない)。
 * NestedRouteIdorDefenseTest の除外規約に揃える。
 *
 * @var list<string>
 */
const DOCUMENT_TITLE_PACKAGE_ROUTE_PREFIXES = [
    'filament.', 'livewire.', 'passport.', 'mcp.', 'cashier.',
    'storage.', 'horizon.', 'telescope.', 'sanctum.', 'ignition.',
];

/**
 * action を静的解決できず、かつ config にタイトルも無い route の明示 allowlist (理由付き)。
 *
 * 新規追加は「なぜ静的に決定できないか」+「なぜタイトルが不要か」の理由とセットでのみ許可する。
 *
 * @return array<string, string>
 */
function documentTitleUnresolvableAllowlist(): array
{
    return [
        // --- Fortify の非ページ endpoint (JSON / redirect。HTML ヘッドを持たない) ---
        'verification.verify' => 'Fortify の署名付き検証リンク着地。検証後 redirect するのみでページを描画しない',
        'password.confirmation' => 'Fortify の確認済みパスワード状態プローブ (JSON)。ページを描画しない',
        'two-factor.qr-code' => 'Fortify の 2FA QR (SVG/JSON) endpoint。ページを描画しない',
        'two-factor.secret-key' => 'Fortify の 2FA secret (JSON) endpoint。ページを描画しない',
        'two-factor.recovery-codes' => 'Fortify のリカバリコード (JSON) endpoint。ページを描画しない',
        // --- passkey (WebAuthn) の options endpoint (JSON)。ceremony 用 challenge を返すのみ ---
        'passkey.login-options' => 'WebAuthn ログイン options (JSON) endpoint。ページを描画しない',
        'passkey.confirm-options' => 'WebAuthn 再認証 options (JSON) endpoint。ページを描画しない',
        'passkey.registration-options' => 'WebAuthn 登録 options (JSON) endpoint。ページを描画しない',
        // --- Route::view の Blade スタブ (Inertia ではない。title は blade 側が持つ) ---
        'legal.terms' => 'Route::view の Blade スタブ (Inertia 非経由)。NoIndex middleware 付きの文面プレースホルダ',
        'legal.privacy' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
        'legal.commerce-disclosure' => 'Route::view の Blade スタブ (Inertia 非経由)。同上',
        // --- 仕様固定の空応答 endpoint ---
        'capture.csrf-cookie' => '419 リトライ用の CSRF cookie 再発行 (204 no content の Closure)。ページを描画しない',
    ];
}

/**
 * Inertia を render するが、タイトル網羅の対象外とする route の明示 allowlist (理由付き)。
 *
 * @return array<string, string>
 */
function documentTitleExemptAllowlist(): array
{
    return [
        // routes/web.php が isLocal() || runningUnitTests() で route 登録自体を囲む =
        // staging / production には存在しない。LocalOnly middleware で二重防御済み。
        'debug.login' => 'local / テスト専用のデバッグログイン。本番に存在しないため固有タイトルを持たせる価値がない',
    ];
}

/**
 * index 以降で最初の significant token の index。
 *
 * @param  list<PhpToken>  $tokens
 */
function documentTitleNextSignificant(array $tokens, int $index): ?int
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
 * ファイル内の各メソッドの token 範囲と可視性を抽出する (純関数)。
 *
 * **キーは小文字化したメソッド名**。PHP のメソッド名解決は case-insensitive なので、
 * route の action 文字列 (`Class@Index`) と宣言 (`function index`) の case が
 * 揃っていなくても解決できるようにする。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, array{start: int, end: int, visibility: string}>
 */
function documentTitleMethodRanges(array $tokens): array
{
    $ranges = [];
    $count = count($tokens);
    $visibility = 'public';

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if ($token->is([T_PUBLIC, T_PROTECTED, T_PRIVATE])) {
            $visibility = strtolower($token->text);

            continue;
        }
        if ($token->is([T_STATIC, T_FINAL, T_ABSTRACT, T_READONLY])) {
            continue;
        }
        if (! $token->is(T_FUNCTION)) {
            continue;
        }

        $nameIndex = documentTitleNextSignificant($tokens, $i + 1);
        if ($nameIndex !== null && $tokens[$nameIndex]->text === '&') {
            $nameIndex = documentTitleNextSignificant($tokens, $nameIndex + 1);
        }
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)) {
            $visibility = 'public'; // 無名関数 / arrow fn

            continue;
        }

        // 引数括弧と return type を跨いで body の `{` を探す
        $parenDepth = 0;
        $bodyStart = null;
        for ($j = $nameIndex + 1; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '(') {
                $parenDepth++;
            } elseif ($text === ')') {
                $parenDepth--;
            } elseif ($parenDepth === 0 && $text === '{') {
                $bodyStart = $j;
                break;
            } elseif ($parenDepth === 0 && $text === ';') {
                break; // abstract / interface メソッド
            }
        }
        if ($bodyStart === null) {
            $visibility = 'public';

            continue;
        }

        $braceDepth = 0;
        for ($j = $bodyStart; $j < $count; $j++) {
            $text = $tokens[$j]->text;
            if ($text === '{') {
                $braceDepth++;
            } elseif ($text === '}') {
                $braceDepth--;
                if ($braceDepth === 0) {
                    $ranges[strtolower($tokens[$nameIndex]->text)] = [
                        'start' => $bodyStart,
                        'end' => $j,
                        'visibility' => $visibility,
                    ];
                    break;
                }
            }
        }
        $visibility = 'public';
    }

    return $ranges;
}

/**
 * `(...)` = PHP 8.1 の first-class callable 構文 (`Inertia::render(...)`) か。
 *
 * first-class callable は **Closure を作るだけで実行しない**ため、
 * 「このメソッドが Inertia ページを描画する / タイトルを供給する」証拠にはならない。
 *
 * **引数アンパック `(...$args)` と厳密に区別する**: どちらも `(` の直後は `T_ELLIPSIS` だが、
 * first-class callable は `...` の**次が閉じ括弧**である一方、アンパックは変数等が続く
 * (= 通常の呼び出し)。`T_ELLIPSIS` だけで判定すると `Inertia::render(...$args)` を
 * 「呼び出していない」と誤認し、**Inertia を描画する route を取りこぼす**。
 *
 *   Inertia::render(...)       → '(' T_ELLIPSIS ')'            = first-class callable
 *   Inertia::render(...$args)  → '(' T_ELLIPSIS T_VARIABLE ')' = 通常呼び出し
 *
 * @param  list<PhpToken>  $tokens
 * @param  int  $openParenIndex  `(` の index
 */
function documentTitleIsFirstClassCallable(array $tokens, int $openParenIndex): bool
{
    $ellipsis = documentTitleNextSignificant($tokens, $openParenIndex + 1);
    if ($ellipsis === null || ! $tokens[$ellipsis]->is(T_ELLIPSIS)) {
        return false;
    }

    $after = documentTitleNextSignificant($tokens, $ellipsis + 1);

    return $after !== null && $tokens[$after]->text === ')';
}

/**
 * メソッド本体に `->name(` / `?->name(` 形の**メソッド呼び出し**が現れるか (case 無視)。
 *
 * 識別子の出現だけを見ると、変数名・配列キー・コメント外の同名文字列・callable 参照
 * (`[$seo, 'setPrivateTitle']`) でも通ってしまい **偽陰性** (タイトル未供給を
 * 「供給済み」と誤判定して gate が取りこぼす) になる。呼び出しトークン列に限定する。
 * 既存 `ScenarioWritePathInventoryTest::containsMethodCall()` と同じ判定形。
 *
 * first-class callable (`$seo->setPrivateTitle(...)`) も **Closure を作るだけで
 * 実行しない**ので呼び出しとは見なさない (同じく取りこぼす方向の偽陰性になる)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 */
function documentTitleBodyCallsMethod(array $tokens, array $range, string $method): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        if (! $tokens[$i]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
            continue;
        }
        $nameIndex = documentTitleNextSignificant($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is(T_STRING)
            || strcasecmp($tokens[$nameIndex]->text, $method) !== 0) {
            continue;
        }
        $paren = documentTitleNextSignificant($tokens, $nameIndex + 1);
        if ($paren !== null && $tokens[$paren]->text === '('
            && ! documentTitleIsFirstClassCallable($tokens, $paren)) {
            return true;
        }
    }

    return false;
}

/**
 * メソッド本体が Inertia ページを render するか (`Inertia::render(` / `inertia(` の literal 呼び出し)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 */
function documentTitleBodyRendersInertia(array $tokens, array $range): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        $token = $tokens[$i];
        if (! $token->is(T_STRING)) {
            continue;
        }

        // Inertia::render( — `(` まで確認する (`[Inertia::class, 'render']` の
        // callable 参照や `Inertia::render` 単独参照を誤検出しない)
        if (strcasecmp($token->text, 'Inertia') === 0) {
            $colon = documentTitleNextSignificant($tokens, $i + 1);
            if ($colon !== null && $tokens[$colon]->is(T_DOUBLE_COLON)) {
                $method = documentTitleNextSignificant($tokens, $colon + 1);
                if ($method !== null && $tokens[$method]->is(T_STRING)
                    && strcasecmp($tokens[$method]->text, 'render') === 0) {
                    $paren = documentTitleNextSignificant($tokens, $method + 1);
                    if ($paren !== null && $tokens[$paren]->text === '('
                        && ! documentTitleIsFirstClassCallable($tokens, $paren)) {
                        return true;
                    }
                }

                continue;
            }
        }

        // inertia( ヘルパ (引数なし `inertia()` = ResponseFactory 取得は除く)
        if (strcasecmp($token->text, 'inertia') === 0) {
            $paren = documentTitleNextSignificant($tokens, $i + 1);
            if ($paren !== null && $tokens[$paren]->text === '('
                && ! documentTitleIsFirstClassCallable($tokens, $paren)) {
                $arg = documentTitleNextSignificant($tokens, $paren + 1);
                if ($arg !== null && $tokens[$arg]->text !== ')') {
                    return true;
                }
            }
        }
    }

    return false;
}

/**
 * 1 hop (同一クラスの private/protected helper) 経由で setPrivateTitle に到達するか。
 *
 * 追跡条件 (すべて満たす場合のみ):
 *   1. `$this->name(` または `self::name(` の直接呼び出し
 *   2. name が同一ファイル・同一クラスに宣言されている
 *   3. その可視性が private / protected
 *   4. 1 段のみ
 *
 * @param  list<PhpToken>  $tokens
 * @param  array{start: int, end: int, visibility: string}  $range
 * @param  array<string, array{start: int, end: int, visibility: string}>  $ranges
 */
function documentTitleOneHopHasSetPrivateTitle(array $tokens, array $range, array $ranges): bool
{
    for ($i = $range['start']; $i <= $range['end']; $i++) {
        $token = $tokens[$i];
        $callee = null;

        if ($token->is(T_VARIABLE) && $token->text === '$this') {
            $arrow = documentTitleNextSignificant($tokens, $i + 1);
            if ($arrow === null || ! $tokens[$arrow]->is(T_OBJECT_OPERATOR)) {
                continue; // ?-> は helper 呼び出しとして扱わない (nullable な自己参照はしない)
            }
            $name = documentTitleNextSignificant($tokens, $arrow + 1);
            if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                continue; // $this->$method() は静的に決まらない
            }
            $paren = documentTitleNextSignificant($tokens, $name + 1);
            if ($paren === null || $tokens[$paren]->text !== '('
                || documentTitleIsFirstClassCallable($tokens, $paren)) {
                continue; // プロパティアクセス / first-class callable (実行しない)
            }
            $callee = $tokens[$name]->text;
        } elseif ($token->is(T_STRING) && strcasecmp($token->text, 'self') === 0) {
            $colon = documentTitleNextSignificant($tokens, $i + 1);
            if ($colon === null || ! $tokens[$colon]->is(T_DOUBLE_COLON)) {
                continue;
            }
            $name = documentTitleNextSignificant($tokens, $colon + 1);
            if ($name === null || ! $tokens[$name]->is(T_STRING)) {
                continue;
            }
            $paren = documentTitleNextSignificant($tokens, $name + 1);
            if ($paren === null || $tokens[$paren]->text !== '('
                || documentTitleIsFirstClassCallable($tokens, $paren)) {
                continue;
            }
            $callee = $tokens[$name]->text;
        }

        if ($callee === null) {
            continue; // 追跡条件を満たさない token (この分岐が無いと strtolower(null) で TypeError)
        }

        $key = strtolower($callee); // PHP のメソッド名解決は case-insensitive
        if (! isset($ranges[$key])) {
            continue; // 同一ファイル・同一クラスに宣言が無い (継承 / trait は辿らない)
        }
        if (! in_array($ranges[$key]['visibility'], ['private', 'protected'], true)) {
            continue; // public は外部 API = 専用 helper と見なさない
        }
        if (documentTitleBodyCallsMethod($tokens, $ranges[$key], 'setPrivateTitle')) {
            return true;
        }
    }

    return false;
}

/** route 名がパッケージ所有か。 */
function documentTitleIsPackageRoute(string $name): bool
{
    foreach (DOCUMENT_TITLE_PACKAGE_ROUTE_PREFIXES as $prefix) {
        if (str_starts_with($name, $prefix)) {
            return true;
        }
    }

    return false;
}

/** config 由来のタイトルを持つか (full / minimal_titles / app_titles)。 */
function documentTitleHasConfiguredTitle(string $name): bool
{
    /** @var list<string> $full */
    $full = config('seo.route_classification.full', []);
    /** @var array<string, string> $minimal */
    $minimal = config('seo.minimal_titles', []);
    /** @var array<string, string> $app */
    $app = config('seo.app_titles', []);

    return in_array($name, $full, true)
        || array_key_exists($name, $minimal)
        || array_key_exists($name, $app);
}

/**
 * GET named route を走査し、タイトル網羅の判定結果を返す。
 *
 * @return array{
 *     missing: list<string>,
 *     unresolvable: list<string>,
 *     inertiaRoutes: int,
 * }
 */
function documentTitleCollectAll(): array
{
    $missing = [];
    $unresolvable = [];
    $inertiaRoutes = 0;
    $exempt = documentTitleExemptAllowlist();
    $allowUnresolvable = documentTitleUnresolvableAllowlist();

    /** @var array<string, array{tokens: list<PhpToken>, ranges: array<string, array{start: int, end: int, visibility: string}>}> $cache */
    $cache = [];

    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true)) {
            continue;
        }
        $name = $route->getName();
        if ($name === null || documentTitleIsPackageRoute($name)) {
            continue;
        }

        $controller = $route->getAction('controller');

        // ---- action の静的解決 ----
        $file = null;
        $method = null;
        if (is_string($controller)) {
            if (str_contains($controller, '@')) {
                [$class, $method] = explode('@', $controller, 2);
            } else {
                $class = $controller;
                $method = '__invoke';
            }
            if (class_exists($class)) {
                $reflected = (new ReflectionClass($class))->getFileName();
                if (is_string($reflected) && ! str_starts_with($reflected, base_path('vendor'))) {
                    $file = $reflected;
                }
            }
        }

        if ($file === null || $method === null) {
            // 静的解決できない: config にタイトルがあれば OK、無ければ allowlist 必須
            if (documentTitleHasConfiguredTitle($name) || array_key_exists($name, $allowUnresolvable)) {
                continue;
            }
            $unresolvable[] = "{$name} ({$route->uri()}) は action を静的解決できず、config にもタイトルが無い";

            continue;
        }

        if (! isset($cache[$file])) {
            $source = file_get_contents($file);
            if (! is_string($source)) {
                continue;
            }
            /** @var list<PhpToken> $tokens */
            $tokens = PhpToken::tokenize($source);
            $cache[$file] = ['tokens' => $tokens, 'ranges' => documentTitleMethodRanges($tokens)];
        }
        $tokens = $cache[$file]['tokens'];
        $ranges = $cache[$file]['ranges'];
        $methodKey = strtolower($method); // ranges のキーは小文字化されている

        if (! isset($ranges[$methodKey])) {
            if (documentTitleHasConfiguredTitle($name) || array_key_exists($name, $allowUnresolvable)) {
                continue;
            }
            $unresolvable[] = "{$name} ({$route->uri()}) のメソッド {$method} を解決できず、config にもタイトルが無い";

            continue;
        }

        if (! documentTitleBodyRendersInertia($tokens, $ranges[$methodKey])) {
            continue; // Inertia ページを描画しない route は本 gate の対象外
        }
        $inertiaRoutes++;

        if (array_key_exists($name, $exempt)) {
            continue;
        }
        if (documentTitleHasConfiguredTitle($name)) {
            continue;
        }
        if (documentTitleBodyCallsMethod($tokens, $ranges[$methodKey], 'setPrivateTitle')) {
            continue;
        }
        if (documentTitleOneHopHasSetPrivateTitle($tokens, $ranges[$methodKey], $ranges)) {
            continue;
        }

        $missing[] = "{$name} ({$route->uri()}) → {$controller}";
    }

    return ['missing' => $missing, 'unresolvable' => $unresolvable, 'inertiaRoutes' => $inertiaRoutes];
}

test('Inertia を render する GET named route は全てページ固有タイトルを持つ', function (): void {
    $result = documentTitleCollectAll();

    expect($result['missing'])->toBe([],
        'ページ固有タイトルが無い route があります。config/seo.php の app_titles / minimal_titles に'
        .'登録するか、controller で SeoManager::setPrivateTitle() を呼んでください'
        .'(タイトル不要なら documentTitleExemptAllowlist() に理由付きで登録)。'
        .PHP_EOL.implode(PHP_EOL, $result['missing']));
});

test('action を静的解決できない route は config タイトルか理由付き allowlist が必須', function (): void {
    $result = documentTitleCollectAll();

    expect($result['unresolvable'])->toBe([],
        'action を静的解決できず、タイトルも無い route があります。'
        .'documentTitleUnresolvableAllowlist() に「なぜ静的に決定できないか」+'
        .'「なぜタイトルが不要か」の理由付きで登録してください。'
        .PHP_EOL.implode(PHP_EOL, $result['unresolvable']));
});

test('走査が空振りしていない (Inertia route を実際に検出できている)', function (): void {
    // route 定義の変更や token 走査の破損で「0 件検査して green」になる退行を落とす。
    expect(documentTitleCollectAll()['inertiaRoutes'])->toBeGreaterThan(0);
});

test('allowlist の key は現存 named route (逆方向整合・stale 検出)', function (): void {
    $named = [];
    foreach (Route::getRoutes() as $route) {
        $routeName = $route->getName();
        if ($routeName !== null) {
            $named[$routeName] = true;
        }
    }

    $stale = [];
    foreach ([
        ...array_keys(documentTitleUnresolvableAllowlist()),
        ...array_keys(documentTitleExemptAllowlist()),
    ] as $key) {
        if (! isset($named[$key])) {
            $stale[] = $key;
        }
    }

    expect($stale)->toBe([], 'allowlist に現存しない route 名 (削除/rename 済): '.implode(', ', $stale));
});

test('allowlist の各エントリは理由コメント (非空文字列) を持つ', function (): void {
    foreach ([...documentTitleUnresolvableAllowlist(), ...documentTitleExemptAllowlist()] as $key => $reason) {
        expect(trim($reason))->not->toBe('', "allowlist エントリ {$key} に理由がありません");
    }
});

/*
 * 負のコントロール: 実ファイルを書き換えず fixture ソースに対して検出器が点灯することを確認する。
 * 「Inertia を render するがタイトルを供給しないメソッド」を検出できること。
 */
test('負のコントロール: Inertia を render するがタイトルを供給しないメソッドを識別する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Fixture/Index', []);
        }
        public function status() {
            return response()->json(['ok' => true]);
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // index は Inertia を render し、setPrivateTitle を持たない = 網羅対象かつ未網羅
    expect(documentTitleBodyRendersInertia($tokens, $ranges['index']))->toBeTrue();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['index'], 'setPrivateTitle'))->toBeFalse();
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['index'], $ranges))->toBeFalse();

    // status は Inertia を render しない = 対象外 (ファイル粒度なら誤検出するケース)
    expect(documentTitleBodyRendersInertia($tokens, $ranges['status']))->toBeFalse();
});

/*
 * 正のコントロール: 「呼び出しではない参照」を render / setPrivateTitle と誤認しない。
 * - first-class callable `Inertia::render(...)` は Closure を作るだけで実行しない
 * - callable 配列 `[$seo, 'setPrivateTitle']` は識別子が現れるだけで呼び出していない
 * どちらも誤認すると gate が **取りこぼす方向** に倒れる (最悪の失敗)。
 */
test('正のコントロール: first-class callable / callable 参照を呼び出しと誤認しない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function makesClosure($seo) {
            $renderer = Inertia::render(...);
            $setter = $seo->setPrivateTitle(...);
            $hop = $this->applyTitle(...);
            $staticHop = self::applyTitle(...);
            $callable = [$seo, 'setPrivateTitle'];
            $named = 'setPrivateTitle';
            $viaClass = [Inertia::class, 'render'];
            return $renderer;
        }
        private function applyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    expect(documentTitleBodyRendersInertia($tokens, $ranges['makesclosure']))->toBeFalse();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['makesclosure'], 'setPrivateTitle'))->toBeFalse();
    // 1 hop 側も first-class callable を「実行済み」と誤認しない
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['makesclosure'], $ranges))->toBeFalse();
});

/*
 * 負のコントロール: 引数アンパック `(...$args)` は **通常の呼び出し**であり、
 * first-class callable `(...)` と厳密に区別しなければならない。
 * 混同すると Inertia を描画する route / タイトルを供給するメソッドを取りこぼす。
 */
test('負のコントロール: 引数アンパック (...$args) は通常呼び出しとして扱う', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function unpacks($seo, array $args, array $titleArgs) {
            $seo->setPrivateTitle(...$titleArgs);
            return Inertia::render(...$args);
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    expect(documentTitleBodyRendersInertia($tokens, $ranges['unpacks']))->toBeTrue();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['unpacks'], 'setPrivateTitle'))->toBeTrue();
});

/*
 * 正のコントロール: メソッド粒度で setPrivateTitle を判定できること。
 * 同一ファイルの別メソッドが setPrivateTitle を持っていても、それに引きずられない
 * (CaptureManualController の index / show がまさにこの形で、本 gate の本命 1 件)。
 */
test('正のコントロール: 同一ファイルの別メソッドの setPrivateTitle に引きずられない', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function index() {
            return Inertia::render('Fixture/Index', []);
        }
        public function show($seo, $manual) {
            $seo->setPrivateTitle($manual->title);
            return Inertia::render('Fixture/Show', []);
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    expect(documentTitleBodyCallsMethod($tokens, $ranges['show'], 'setPrivateTitle'))->toBeTrue();
    expect(documentTitleBodyCallsMethod($tokens, $ranges['index'], 'setPrivateTitle'))->toBeFalse();
});

/*
 * 正のコントロール: 1 hop 追跡が仕様どおり動くこと。
 * 本バッチ時点で 1 hop を必要とする route は存在しないため、fixture でのみ機能を保証する。
 */
test('正のコントロール: $this-> / self:: 経由の private helper (1 hop) を追跡する', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function viaThis($seo) {
            $this->applyTitle($seo);
            return Inertia::render('Fixture/A', []);
        }
        public function viaSelf($seo) {
            self::applyTitle($seo);
            return Inertia::render('Fixture/B', []);
        }
        private function applyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // ranges のキーは小文字化されている
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['viathis'], $ranges))->toBeTrue();
    expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges['viaself'], $ranges))->toBeTrue();
});

/*
 * 負のコントロール: 1 hop の追跡条件を満たさないものは辿らない。
 * 別オブジェクトへの同名呼び出しを「タイトルを供給している」と誤認すると
 * gate が取りこぼす方向に倒れる (最悪の失敗)。
 */
test('負のコントロール: 別オブジェクト / public / 2 hop は 1 hop 追跡の対象外', function (): void {
    $fixture = <<<'PHP'
    <?php
    namespace App\Http\Controllers;
    use Inertia\Inertia;
    class FixtureController {
        public function otherObject($helper, $seo) {
            $helper->applyTitle($seo);          // 別オブジェクト = 辿らない
            return Inertia::render('Fixture/A', []);
        }
        public function viaPublic($seo) {
            $this->publicApplyTitle($seo);      // public = 専用 helper と見なさない
            return Inertia::render('Fixture/B', []);
        }
        public function twoHop($seo) {
            $this->firstHop($seo);              // 2 hop 先は辿らない
            return Inertia::render('Fixture/C', []);
        }
        public function dynamicName($seo, $m) {
            $this->$m($seo);                    // 変数メソッド名 = 静的に決まらない
            return Inertia::render('Fixture/D', []);
        }
        public function publicApplyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
        private function firstHop($seo): void {
            $this->applyTitle($seo);
        }
        private function applyTitle($seo): void {
            $seo->setPrivateTitle('固有名');
        }
    }
    PHP;

    /** @var list<PhpToken> $tokens */
    $tokens = PhpToken::tokenize($fixture);
    $ranges = documentTitleMethodRanges($tokens);

    // ranges のキーは小文字化されている
    foreach (['otherobject', 'viapublic', 'twohop', 'dynamicname'] as $method) {
        expect(documentTitleOneHopHasSetPrivateTitle($tokens, $ranges[$method], $ranges))
            ->toBeFalse("{$method} は 1 hop 追跡の対象外であるべき");
    }
});
