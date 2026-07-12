<?php

declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Validator;

/*
 * ユーザー向けバリデーション文言の deny-by-default inventory (bug-hunt F-02 由来):
 *
 * 検査 1: app/Http/Requests 配下の全 FormRequest の rules() 全キー (dotted/wildcard 含む) が
 *         lang/ja/validation.php の attributes に登録されていること。
 * 検査 2: app/ 配下 (app/Http/Requests を除く) の inline validation 呼び出し
 *         (`->validate(` / `->validateWithBag(` / `Validator::make(`) をトークナイザで検出し、
 *         ルール配列リテラルから抽出した全キーが attributes に登録されていること。
 *         ルール配列を静的抽出できない呼び出しは UNPARSEABLE_CALL_INVENTORY に理由付きで
 *         登録されていない限り fail (fail-closed: 未解析を成功扱いしない)。
 *
 * 規約: validation の呼び出し経路を追加する場合 (`validator()` helper 等) は、本テストの
 *       検出対象パターンにも必ず追加すること。
 */

/**
 * 属性ラベル不要キーの除外 inventory (クラス => キー => 理由)。
 * 属性名を含まない個別 messages() を全 rule に定義したキーのみ登録可 (既定は空)。
 *
 * @var array<class-string, array<string, string>>
 */
const ATTRIBUTE_ALLOWLIST = [
    // 'App\Http\Requests\FooRequest' => ['some_key' => '理由'],
];

/**
 * rules() をリクエスト文脈なしで安全に呼べないクラスの除外 inventory (クラス => 理由)。
 * 既定は空 (現状の全クラスは route 未バインドでも null-safe に呼べることを確認済み)。
 *
 * @var array<class-string, string>
 */
const RULES_UNINSTANTIABLE_INVENTORY = [
    // 'App\Http\Requests\FooRequest' => '理由',
];

/**
 * ルール配列を静的抽出できない inline 呼び出しの除外 inventory。
 * キー形式は "{相対パス}@{行番号}#{validate|validateWithBag|make}" に固定する
 * (行番号ずれで stale になった entry は fail し再確認を強制する = fail-closed 側に倒れる)。
 *
 * @var array<string, string>
 */
const UNPARSEABLE_CALL_INVENTORY = [
    'app/Services/Mail/Sns/AwsSnsSignatureVerifier.php@43#validate' => 'AWS SNS MessageValidator::validate (Laravel validation ではなくルール配列を持たない)',
];

/**
 * app/Http/Requests 配下の全 FormRequest クラスを列挙する
 * (FormRequestProhibitedKeyTest と同一パターン。関数名は Pest のグローバル関数衝突を避け
 * validationCoverage* プレフィックスにする)。
 *
 * @return list<class-string<FormRequest>>
 */
function validationCoverageFormRequestClasses(): array
{
    $classes = [];
    $base = app_path('Http/Requests');
    expect(is_dir($base))->toBeTrue();

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = str_replace([$base.'/', '.php', '/'], ['', '', '\\'], $file->getPathname());
        $class = 'App\\Http\\Requests\\'.$relative;
        if (class_exists($class) && is_subclass_of($class, FormRequest::class)) {
            $classes[] = $class;
        }
    }

    sort($classes);

    return $classes;
}

/**
 * アプリロケール (ja) の validation attributes map を取得する。
 *
 * @return array<string, string>
 */
function validationCoverageAttributes(): array
{
    // .env 差異に依存させず、検証対象ロケールを明示する
    app()->setLocale('ja');
    $attributes = Lang::get('validation.attributes');
    expect($attributes)->toBeArray();
    /** @var array<string, string> $attributes */

    return $attributes;
}

/**
 * キーの数値セグメントを '*' に正規化する (Laravel の primaryAttribute 解決と同じ向き)。
 * rules() のキーは定義時点で '*' を含むため実際にはほぼ恒等 (防御的に適用)。
 */
function validationCoverageNormalizeKey(string $key): string
{
    $normalized = preg_replace('/(^|\.)\d+(?=\.|$)/', '$1*', $key);

    return $normalized ?? $key;
}

// ──────────────────────────── 検査 2 用の静的解析ヘルパ ────────────────────────────

/**
 * 同ファイルの use 文 (alias 含む) を最小パースした map (alias => FQCN)。
 * class-body の trait use はインデントされるため `^use ` 行のみを対象にする。
 *
 * @return array<string, string>
 */
function validationCoverageUseMap(string $code): array
{
    $map = [];
    if (preg_match_all('/^use\s+([A-Za-z0-9_\\\\]+)(?:\s+as\s+([A-Za-z0-9_]+))?\s*;/m', $code, $matches, PREG_SET_ORDER) > 0) {
        foreach ($matches as $m) {
            $fqcn = $m[1];
            $segments = explode('\\', $fqcn);
            $alias = $m[2] ?? end($segments);
            $map[$alias] = $fqcn;
        }
    }

    return $map;
}

/**
 * クラス参照トークンを FQCN に解決する。解決できない場合 (同 namespace の単純名等) は null
 * (FQCN 直書きは解決結果が完全一致する場合のみ = 同名の独自クラスによる過剰検出を避ける)。
 *
 * @param  array<string, string>  $useMap
 */
function validationCoverageResolveClass(string $name, array $useMap): ?string
{
    if (str_starts_with($name, '\\')) {
        return substr($name, 1);
    }
    $segments = explode('\\', $name);
    $head = $segments[0];
    if (! array_key_exists($head, $useMap)) {
        return null;
    }
    $rest = array_slice($segments, 1);

    return $useMap[$head].($rest === [] ? '' : '\\'.implode('\\', $rest));
}

/**
 * @param  list<PhpToken>  $tokens
 */
function validationCoverageNextSignificant(array $tokens, int $start): ?int
{
    for ($i = $start, $count = count($tokens); $i < $count; $i++) {
        if (! $tokens[$i]->isIgnorable()) {
            return $i;
        }
    }

    return null;
}

/**
 * 呼び出しの '(' から引数リストを top-level カンマで分割する。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<array{start: int, end: int}>
 */
function validationCoverageParseArguments(array $tokens, int $openParen): array
{
    $depth = 0;
    $args = [];
    $argStart = null;
    for ($i = $openParen, $count = count($tokens); $i < $count; $i++) {
        $token = $tokens[$i];
        $text = $token->text;
        if ($text === '(' || $text === '[' || $text === '{' || $text === '#['
            || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
            if ($depth === 1) {
                $argStart = $i + 1;
            }

            continue;
        }
        if ($text === ')' || $text === ']' || $text === '}') {
            $depth--;
            if ($depth === 0) {
                if ($argStart !== null && validationCoverageNextSignificantWithin($tokens, $argStart, $i - 1) !== null) {
                    $args[] = ['start' => $argStart, 'end' => $i - 1];
                }

                return $args;
            }

            continue;
        }
        if ($text === ',' && $depth === 1 && $argStart !== null) {
            if (validationCoverageNextSignificantWithin($tokens, $argStart, $i - 1) !== null) {
                $args[] = ['start' => $argStart, 'end' => $i - 1];
            }
            $argStart = $i + 1;
        }
    }

    // 括弧が閉じない = tokenize 異常。fail-closed に倒す
    expect(false)->toBeTrue('unbalanced parenthesis while parsing arguments');

    return [];
}

/**
 * @param  list<PhpToken>  $tokens
 */
function validationCoverageNextSignificantWithin(array $tokens, int $start, int $end): ?int
{
    for ($i = $start; $i <= $end; $i++) {
        if (! $tokens[$i]->isIgnorable()) {
            return $i;
        }
    }

    return null;
}

/**
 * 配列リテラル引数から深さ 1 の文字列キーを抽出する。
 * 配列リテラルでない・非リテラルキー ("{$var}.x" 等) を含む場合は null (= 静的抽出不能)。
 *
 * @param  list<PhpToken>  $tokens
 * @return list<string>|null
 */
function validationCoverageExtractArrayKeys(array $tokens, int $start, int $end): ?array
{
    $first = validationCoverageNextSignificantWithin($tokens, $start, $end);
    if ($first === null || $tokens[$first]->text !== '[') {
        return null;
    }

    $keys = [];
    $depth = 0;
    $previousSignificant = null;
    for ($i = $first; $i <= $end; $i++) {
        $token = $tokens[$i];
        if ($token->isIgnorable()) {
            continue;
        }
        $text = $token->text;
        if ($text === '[' || $text === '(' || $text === '{' || $text === '#['
            || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;
        } elseif ($text === ']' || $text === ')' || $text === '}') {
            $depth--;
            if ($depth === 0) {
                break;
            }
        } elseif ($depth === 1 && $token->id === T_DOUBLE_ARROW) {
            if ($previousSignificant === null || $previousSignificant->id !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }
            $quote = $previousSignificant->text[0];
            $inner = substr($previousSignificant->text, 1, -1);
            $keys[] = str_replace(['\\'.$quote, '\\\\'], [$quote, '\\'], $inner);
        }
        $previousSignificant = $token;
    }

    return $keys;
}

/**
 * app/ 配下 (app/Http/Requests を除く) の inline validation 呼び出しを走査する。
 *
 * @return array{keys: array<string, list<string>>, unparseable: list<string>}
 */
function validationCoverageScanInlineCalls(): array
{
    $keysByCall = [];
    $unparseable = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS),
    );
    $files = [];
    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        if (str_starts_with($path, app_path('Http/Requests').'/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    foreach ($files as $path) {
        $code = file_get_contents($path);
        expect($code)->toBeString();
        /** @var string $code */
        $relative = ltrim(str_replace(base_path(), '', $path), '/');
        $tokens = PhpToken::tokenize($code);
        $useMap = validationCoverageUseMap($code);

        foreach ($tokens as $i => $token) {
            $call = null; // array{method: string, line: int, paren: int, argOffset: int}

            // (a) ->validate( / ->validateWithBag( (nullsafe 含む)
            if ($token->id === T_OBJECT_OPERATOR || $token->id === T_NULLSAFE_OBJECT_OPERATOR) {
                $nameIndex = validationCoverageNextSignificant($tokens, $i + 1);
                if ($nameIndex !== null && $tokens[$nameIndex]->id === T_STRING
                    && in_array($tokens[$nameIndex]->text, ['validate', 'validateWithBag'], true)) {
                    $parenIndex = validationCoverageNextSignificant($tokens, $nameIndex + 1);
                    if ($parenIndex !== null && $tokens[$parenIndex]->text === '(') {
                        $call = [
                            'method' => $tokens[$nameIndex]->text,
                            'line' => $tokens[$nameIndex]->line,
                            'paren' => $parenIndex,
                        ];
                    }
                }
            }

            // (b) Validator::make( — use 文 (alias 含む) / FQCN 直書きの解決結果が
            //     Illuminate\Support\Facades\Validator と完全一致する場合のみ
            if ($call === null
                && in_array($token->id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                $colonIndex = validationCoverageNextSignificant($tokens, $i + 1);
                if ($colonIndex !== null && $tokens[$colonIndex]->id === T_DOUBLE_COLON) {
                    $makeIndex = validationCoverageNextSignificant($tokens, $colonIndex + 1);
                    if ($makeIndex !== null && $tokens[$makeIndex]->id === T_STRING
                        && $tokens[$makeIndex]->text === 'make') {
                        $parenIndex = validationCoverageNextSignificant($tokens, $makeIndex + 1);
                        if ($parenIndex !== null && $tokens[$parenIndex]->text === '('
                            && validationCoverageResolveClass($token->text, $useMap) === Validator::class) {
                            $call = [
                                'method' => 'make',
                                'line' => $tokens[$makeIndex]->line,
                                'paren' => $parenIndex,
                            ];
                        }
                    }
                }
            }

            if ($call === null) {
                continue;
            }

            $args = validationCoverageParseArguments($tokens, $call['paren']);
            $callId = sprintf('%s@%d#%s', $relative, $call['line'], $call['method']);

            // ルール配列引数の位置: validate は第 1、validateWithBag (Request 版) / make は第 2。
            // Validator インスタンス連鎖の ->validate() (引数 0) / ->validateWithBag('bag')
            // (引数 1 = bag 名のみ) はルールを運ばない (ルールは Validator::make 側で検査済み) ため skip。
            if ($call['method'] === 'validate' && $args === []) {
                continue;
            }
            if ($call['method'] === 'validateWithBag' && count($args) <= 1) {
                continue;
            }
            $rulesArg = $call['method'] === 'validate' ? ($args[0] ?? null) : ($args[1] ?? null);

            $extracted = $rulesArg === null
                ? null
                : validationCoverageExtractArrayKeys($tokens, $rulesArg['start'], $rulesArg['end']);
            if ($extracted === null) {
                $unparseable[] = $callId;

                continue;
            }
            $keysByCall[$callId] = array_merge($keysByCall[$callId] ?? [], $extracted);
        }
    }

    return ['keys' => $keysByCall, 'unparseable' => $unparseable];
}

// ──────────────────────────── 検査 1 (FormRequest) ────────────────────────────

test('全 FormRequest の rules() キーが validation attributes に登録されている', function (): void {
    $attributes = validationCoverageAttributes();
    $violations = [];

    foreach (validationCoverageFormRequestClasses() as $class) {
        if (array_key_exists($class, RULES_UNINSTANTIABLE_INVENTORY)) {
            continue;
        }
        $request = $class::create('/', 'POST');
        $request->setContainer(app());
        // rules() は FormRequest の規約メソッド (基底クラス未宣言) のため callable 経由で呼ぶ
        $rulesMethod = [$request, 'rules'];
        expect(is_callable($rulesMethod))->toBeTrue($class.' に rules() がありません');
        assert(is_callable($rulesMethod));
        $rules = $rulesMethod();
        expect($rules)->toBeArray();
        /** @var array<array-key, mixed> $rules */
        foreach ($rules as $key => $rule) {
            // 値が ['missing'] のみのキーは保護キー deny-guard (ProhibitsProtectedKeys) であり
            // ユーザー入力フィールドではないため対象外
            if ($rule === ['missing']) {
                continue;
            }
            $key = (string) $key;
            if (isset(ATTRIBUTE_ALLOWLIST[$class][$key])) {
                continue;
            }
            $normalized = validationCoverageNormalizeKey($key);
            if (! array_key_exists($normalized, $attributes)) {
                $violations[] = $class.'::'.$normalized;
            }
        }
    }

    $violations = array_values(array_unique($violations));
    expect($violations)->toBe([], 'attributes 未登録キー: '.implode(', ', $violations));
});

// ──────────────────────────── 検査 2 (inline validation, fail-closed) ────────────────────────────

test('inline validation のルールキーが validation attributes に登録されている (fail-closed)', function (): void {
    $attributes = validationCoverageAttributes();
    $scan = validationCoverageScanInlineCalls();

    // 静的抽出できない呼び出しは inventory 登録が必須 (fail-closed)
    $unregistered = array_values(array_diff($scan['unparseable'], array_keys(UNPARSEABLE_CALL_INVENTORY)));
    expect($unregistered)->toBe([], '未登録の解析不能呼び出し: '.implode(', ', $unregistered));

    // stale entry (行番号ずれ・削除済み) の残置も fail (再確認を強制する)
    $stale = array_values(array_diff(array_keys(UNPARSEABLE_CALL_INVENTORY), $scan['unparseable']));
    expect($stale)->toBe([], 'stale な inventory entry: '.implode(', ', $stale));

    $violations = [];
    foreach ($scan['keys'] as $callId => $keys) {
        foreach ($keys as $key) {
            $normalized = validationCoverageNormalizeKey($key);
            if (! array_key_exists($normalized, $attributes)) {
                $violations[] = $callId.'::'.$normalized;
            }
        }
    }

    $violations = array_values(array_unique($violations));
    expect($violations)->toBe([], 'attributes 未登録キー: '.implode(', ', $violations));
});
