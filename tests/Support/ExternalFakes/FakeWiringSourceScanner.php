<?php

declare(strict_types=1);

namespace Tests\Support\ExternalFakes;

use App\Services\AI\Testing\CannedPromptFakeRegistrar;
use App\Support\FakeStorageGate;

/**
 * BughuntFakesServiceProvider の container 呼び出し形と、本番コードのクラス参照を
 * token ベースで抽出する純粋 helper (I/O を持たない。引数は PHP ソース文字列)。
 *
 * ★設計判断
 *  - 「禁止 API を列挙する」のではなく「**許可された呼び出し形を列挙し、残りを禁止する**」。
 *    API 名の列挙は未知 API (rebinding / 将来の Container API) で必ず抜けられる。
 *  - `make()` は**引数まで**固定する。`$this->app->make(SomeRegistrar::class)->register()` という
 *    委譲で配線を別クラスへ逃がせるため (既存の CannedPromptFakeRegistrar が現に委譲パターン)。
 *  - `bind()` は「位置引数ちょうど 2 個で、どちらも宣言 entry のプロパティ参照
 *    (`$swap->abstract` / `$swap->fake`)」に固定する。**`::class` を直に書く形も禁止**で、
 *    差し替え先の決定を宣言 (`ExternalFakeDeclaration`) だけに閉じるための摩擦である
 *    (provider に 1 組でも手書きすると、宣言を読まない差し替えが無音で増える)。
 *    `bind(A::class, B::class, true)` は第 3 引数 $shared = singleton 相当なので、
 *    これも禁止しないと singleton 禁止を同じ意味の書き方で回避できる。
 *  - 誤検出は分類 1 行で解消できるが検出漏れは永久に気付けない、という非対称性から
 *    **過剰検出側 (fail-closed)** へ倒す。
 *
 * ★short class name の FQCN 正規化
 *  ソース先頭の **namespace 宣言 + use map** を先に構築し、`A::class` の `A` を FQCN へ正規化する。
 *  対応する形: `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` / `\A\B\C::class` /
 *  use に無い `C::class` (現在の namespace 配下として解決)。
 *  この正規化は bindPairs() / disallowedContainerCalls() の make 引数照合 / referencedClasses() が共有する。
 *
 * ★限界 (呼び出し側テストの docblock にも明記する)
 *  - 到達可能性を判定しない (`if (false) { … }` 中の呼び出しも候補になる)。
 *  - 変数経由の container (`$c = $this->app; $c->bind(...)`) は
 *    disallowedIndirectAccess() の `$this->app` の**非呼び出し出現**検出で捕まえる。
 *  - 非 bracketed namespace 前提 (Pint が強制)。
 *  - クラス宣言名 (`class FakeStorageGate`) は参照として数えない (自己参照は漏洩の証拠ではない)。
 *  - **敵対的回避に対する完全性は主張しない**。PHP は reflection / `eval` /
 *    `Closure::bind` 等で任意に container へ到達できるため、字句解析だけで
 *    「絶対に抜けられない」ことは示せない。本 gate が守るのは
 *    **通常の実装作業で起きるドリフト** (差し替えの追加漏れ・登録点の脱落・順序反転・
 *    inventory 未登録) であり、そのために「許可形の列挙 = 閉じた文法」を要求して
 *    未分類の書き方をすべて赤にする、という方針を採る。
 *    新しい抜け道を見つけたら **Unit テスト (5-x) にケースを足して文法を狭める** —
 *    allowlist を広げる方向へは倒さない。
 */
final class FakeWiringSourceScanner
{
    /**
     * 許可する `$this->app-><method>(…)` の呼び出し形 (これ以外はすべて禁止 = deny-by-default)。
     *
     * value は許可する**位置引数の形**:
     * - `declaredPair`: 位置引数ちょうど 2 個で、どちらも変数のプロパティ参照であり、
     *   プロパティ名が順に `abstract` / `fake` であること (= 宣言 entry を読んだ差し替えだけを許す)
     * - `allowlistedClass`: 位置引数ちょうど 1 個で MAKE_ALLOWED_ARGUMENTS のいずれか
     * - `none`: 位置引数なし
     *
     * @var array<string, string>
     */
    private const array ALLOWED_APP_CALLS = [
        'bind' => 'declaredPair',
        'make' => 'allowlistedClass',
        'environment' => 'none',
    ];

    /**
     * `declaredPair` が要求するプロパティ名 (順序込み)。
     *
     * 宣言 entry (App\Support\ExternalFakes\ExternalFakeBinding) の
     * 「解決キー」と「差し替え先」のプロパティ名であり、順序が入れ替わると
     * 差し替えの向きが逆になるため位置ごとに固定する。
     *
     * @var list<string>
     */
    private const array DECLARED_PAIR_PROPERTIES = ['abstract', 'fake'];

    /**
     * `make()` に渡してよいクラス (container 配線を行わないことを分類済みの 2 件のみ)。
     *
     * @var list<class-string>
     */
    private const array MAKE_ALLOWED_ARGUMENTS = [
        FakeStorageGate::class,
        CannedPromptFakeRegistrar::class,
    ];

    /** container へ間接到達するグローバル helper (`use function app as c;` の alias も解決して照合する) */
    private const array CONTAINER_HELPERS = ['app', 'resolve'];

    /** container へ間接到達する静的アクセス起点 (use alias を FQCN 解決して照合する) */
    private const array CONTAINER_STATIC_FQCNS = [
        'Illuminate\Support\Facades\App',
        'Illuminate\Container\Container',
    ];

    /**
     * container へ間接到達する静的アクセス起点の短縮名 (fail-closed の予備線)。
     *
     * `use` が無く FQCN 解決が現在 namespace 配下になってしまう `App::` / `Container::` を
     * 取りこぼさないため、FQCN 一致とは**別に**末尾セグメントでも照合する。
     */
    private const array CONTAINER_STATIC_ROOTS = ['App', 'Container'];

    /** 名前を表すトークン */
    private const array NAME_TOKEN_IDS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /**
     * @param  list<array{id: int, text: string, line: int}>  $tokens  コメント / 空白を除去済み
     * @param  array<string, string>  $useMap  短縮名 => FQCN
     * @param  array<string, string>  $useFunctionMap  短縮名 => 完全修飾関数名 (`use function … as …`)
     * @param  list<bool>  $isImportToken  namespace / use 文に属するトークンか
     * @param  list<bool>  $isDeclarationName  クラス様宣言の名前トークンか
     */
    private function __construct(
        private readonly array $tokens,
        private readonly string $namespace,
        private readonly array $useMap,
        private readonly array $useFunctionMap,
        private readonly array $isImportToken,
        private readonly array $isDeclarationName,
    ) {}

    /**
     * 許可外の `$this->app-><method>(…)` 呼び出し。
     *
     * @return list<string> 人間可読な違反説明 (行番号付き)
     */
    public static function disallowedContainerCalls(string $source): array
    {
        $scanner = self::analyze($source);
        $violations = [];

        foreach ($scanner->appMethodCalls() as $call) {
            $method = $call['method'];
            $line = $call['line'];

            if (! array_key_exists($method, self::ALLOWED_APP_CALLS)) {
                $violations[] = "{$method}(…) は許可された container 呼び出し形ではない (line {$line})";

                continue;
            }

            // 名前付き引数 / spread unpack は引数の形を機械照合できないため fail-closed で禁止する。
            foreach ($call['args'] as $arg) {
                if (self::isNamedArgument($arg) || self::containsUnpack($arg)) {
                    $violations[] = "{$method}(…) は名前付き引数 / unpack を使っている (line {$line})";

                    continue 2;
                }
            }

            $shape = self::ALLOWED_APP_CALLS[$method];
            $arguments = $call['args'];

            if ($shape === 'none' && $arguments !== []) {
                $violations[] = "{$method}(…) は引数なしでのみ許可される (line {$line})";

                continue;
            }

            if ($shape === 'declaredPair') {
                if (count($arguments) !== 2
                    || ! self::isDeclaredProperty($arguments[0], self::DECLARED_PAIR_PROPERTIES[0])
                    || ! self::isDeclaredProperty($arguments[1], self::DECLARED_PAIR_PROPERTIES[1])) {
                    $violations[] = "{$method}(…) は位置引数ちょうど 2 個で、順に "
                        .'$<変数>->'.self::DECLARED_PAIR_PROPERTIES[0].' / $<変数>->'
                        .self::DECLARED_PAIR_PROPERTIES[1]." でなければならない (line {$line})";
                }

                continue;
            }

            if ($shape === 'allowlistedClass') {
                if (count($arguments) !== 1 || ! self::isClassConstant($arguments[0])) {
                    $violations[] = "{$method}(…) は位置引数ちょうど 1 個の ::class 定数でなければならない (line {$line})";

                    continue;
                }

                $resolved = $scanner->resolve($arguments[0][0]['text']);
                if (! in_array($resolved, self::MAKE_ALLOWED_ARGUMENTS, true)) {
                    $violations[] = "{$method}({$resolved}::class) は許可されていない (line {$line})";
                }
            }
        }

        return $violations;
    }

    /**
     * container へ `bind(A::class, B::class)` する組 (**FQCN 正規化済み**)。
     *
     * 読める呼び出し形は **container へ到達する 4 つ**である —
     * `$this->app->bind` / `app()->bind` (`resolve()` と `use function … as …` の別名を含む) /
     * `App::bind` (facade。別名解決あり) / `Container::getInstance()->bind`。
     *
     * 第 2 引数が `::class` 定数でない (closure 等) 場合は concrete を `null` として返し、
     * 呼び出し側テストで「差し替えは ::class 対 ::class の形に限る」を fail させる。
     * 第 1 引数が `::class` 定数でない形 (変数 abstract など) は組として読み取れないため返さない。
     *
     * **保証範囲を誇張しない**: 読めるのは上の 4 形だけである。変数経由の結び付け
     * (`$container->bind(…)`)・`instance()` / `swap()`・モック機構経由には**沈黙する**。
     *
     * @return list<array{abstract: class-string, concrete: class-string|null}>
     */
    public static function bindPairs(string $source): array
    {
        $scanner = self::analyze($source);
        $pairs = [];

        foreach ($scanner->containerBindCalls() as $args) {
            if (count($args) < 2 || ! self::isClassConstant($args[0])) {
                continue;
            }

            /** @var class-string $abstract */
            $abstract = $scanner->resolve($args[0][0]['text']);

            $concrete = null;
            if (self::isClassConstant($args[1])) {
                /** @var class-string $concrete */
                $concrete = $scanner->resolve($args[1][0]['text']);
            }

            $pairs[] = ['abstract' => $abstract, 'concrete' => $concrete];
        }

        return $pairs;
    }

    /**
     * `$this->app` のメソッド呼び出し以外で container へ到達する形 (未知 API 経由の抜け道封じ)。
     *
     * 検出対象: `app(` / `resolve(` / `App::` facade / `Container::getInstance()` /
     * `$this->app` の**メソッド呼び出し以外の出現** (変数への代入・引数渡し・ArrayAccess)。
     *
     * @return list<string>
     */
    public static function disallowedIndirectAccess(string $source): array
    {
        $scanner = self::analyze($source);
        $violations = [];
        $count = count($scanner->tokens);

        foreach ($scanner->appAccessIndexes() as $index) {
            if ($scanner->isAppMethodCallAt($index)) {
                continue;
            }
            $line = $scanner->tokens[$index]['line'];
            $violations[] = "\$this->app がメソッド呼び出し以外で使われている (line {$line})";
        }

        // 「container 到達 API を名前で列挙する」方式は、未分類の式を経由されると必ず抜けられる
        // (`$this->{'app'}->bind(…)` / `get_object_vars($this)['app']->bind(…)` /
        //  `('app')()->bind(…)` / `$factory()->bind(…)` はいずれも名前照合に掛からない)。
        // 本 gate が要求しているのは「provider の container 差し替えは `$this->app-><許可形>(…)` だけ」
        // という**閉じた文法**なので、その文法から外れる形を fail-closed で拒否する。
        for ($i = 0; $i < $count; $i++) {
            $token = $scanner->tokens[$i];
            $next = $scanner->tokens[$i + 1] ?? null;

            // (1) $this は `$this-><静的なメンバ名>` の形でのみ許す
            //     ($this を式へ渡して container を取り出す経路・動的メンバアクセスの両方を閉じる)
            if ($token['id'] === T_VARIABLE && $token['text'] === '$this') {
                $isStaticMemberAccess = $next !== null
                    && in_array($next['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
                    && ($scanner->tokens[$i + 2]['id'] ?? -1) === T_STRING;

                if (! $isStaticMemberAccess) {
                    $violations[] = "\$this が \$this-><メンバ名> 以外の形で使われている (line {$token['line']})";
                }

                continue;
            }

            // (2) 変数 callable の呼び出し (`$factory()`) — 呼び先を静的に判定できない
            if ($token['id'] === T_VARIABLE && $next !== null && $next['text'] === '(') {
                $violations[] = "変数 callable の呼び出しは呼び先を静的に判定できない (line {$token['line']})";

                continue;
            }

            // (3) 式の即時呼び出し (`('app')()` / `(fn () => …)()`) — 同上
            if ($token['text'] === ')' && $next !== null && $next['text'] === '(') {
                $violations[] = "式の即時呼び出しは呼び先を静的に判定できない (line {$token['line']})";
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $scanner->tokens[$i];
            if ($scanner->isImportToken[$i] || ! in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
                continue;
            }

            $segments = explode('\\', ltrim($token['text'], '\\'));
            $last = $segments[count($segments) - 1];
            $next = $scanner->tokens[$i + 1] ?? null;
            $previous = $scanner->tokens[$i - 1] ?? null;

            // `app(` / `resolve(` のグローバル helper 経由 (メソッド呼び出しは除く)。
            // `use function app as container;` の alias も解決してから照合する。
            if ($next !== null && $next['text'] === '('
                && ! self::isMemberAccessBoundary($previous)
                && in_array($scanner->resolveFunctionName($token['text']), self::CONTAINER_HELPERS, true)) {
                $violations[] = "{$last}() 経由で container へ到達している (line {$token['line']})";

                continue;
            }

            // `App::` facade / `Container::getInstance()`。
            // `use Illuminate\Container\Container as C;` の alias も解決してから照合し、
            // use が無い短縮名は CONTAINER_STATIC_ROOTS で取りこぼさない (fail-closed の二重線)。
            if ($next !== null && $next['id'] === T_DOUBLE_COLON
                && (in_array($scanner->resolve($token['text']), self::CONTAINER_STATIC_FQCNS, true)
                    || in_array($last, self::CONTAINER_STATIC_ROOTS, true))) {
                $violations[] = "{$last}:: 経由で container へ到達している (line {$token['line']})";
            }
        }

        return $violations;
    }

    /**
     * ソースが参照するクラス FQCN の集合 ($candidates との積集合)。
     *
     * 収集元 4 系統:
     *  1. `use A\B\C;` (グループ use / alias 付きも解決)
     *  2. 完全修飾 / 修飾名トークン (T_NAME_FULLY_QUALIFIED / T_NAME_QUALIFIED)
     *  3. **文字列リテラルの内容が FQCN と完全一致**するもの (文字列経由の抜け道封じ。部分一致はしない)
     *  4. **candidate の class basename に一致する T_STRING** を namespace / use map で解決したもの
     *     (`use` も完全修飾も無い**同一 namespace 内の short name 参照**を拾う)
     *
     * クラス宣言名 (`class FakeStorageGate`) とメンバアクセス (`$x->FakeStorageGate`) は除外する。
     *
     * @param  list<class-string>  $candidates  照合する FQCN 母集団
     * @return list<class-string>
     */
    public static function referencedClasses(string $source, array $candidates): array
    {
        $scanner = self::analyze($source);
        $candidateSet = array_fill_keys($candidates, true);

        /** @var array<string, true> $candidateBasenames */
        $candidateBasenames = [];
        foreach ($candidates as $candidate) {
            $position = strrpos($candidate, '\\');
            $candidateBasenames[$position === false ? $candidate : substr($candidate, $position + 1)] = true;
        }

        /** @var array<string, true> $found */
        $found = [];

        // 収集元 1: use 文
        foreach ($scanner->useMap as $fqcn) {
            if (isset($candidateSet[$fqcn])) {
                $found[$fqcn] = true;
            }
        }

        $count = count($scanner->tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($scanner->isImportToken[$i] || $scanner->isDeclarationName[$i]) {
                continue;
            }

            $token = $scanner->tokens[$i];

            // 収集元 3: 文字列リテラルの FQCN 完全一致
            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
                $literal = self::normalizeStringLiteral($token['text']);
                if ($literal !== null && isset($candidateSet[$literal])) {
                    $found[$literal] = true;
                }

                continue;
            }

            // 収集元 2: 完全修飾 / 修飾名
            if ($token['id'] === T_NAME_FULLY_QUALIFIED || $token['id'] === T_NAME_QUALIFIED) {
                $fqcn = $scanner->resolve($token['text']);
                if (isset($candidateSet[$fqcn])) {
                    $found[$fqcn] = true;
                }

                continue;
            }

            // 収集元 4: 同一 namespace / use 経由の short name
            if ($token['id'] === T_STRING
                && isset($candidateBasenames[$token['text']])
                && ! self::isMemberAccessBoundary($scanner->tokens[$i - 1] ?? null)) {
                $fqcn = $scanner->resolve($token['text']);
                if (isset($candidateSet[$fqcn])) {
                    $found[$fqcn] = true;
                }
            }
        }

        /** @var list<class-string> $result */
        $result = array_keys($found);
        sort($result);

        return $result;
    }

    /**
     * ソースをトークン化し namespace / use map / 走査除外範囲を確定する。
     */
    private static function analyze(string $source): self
    {
        $tokens = self::tokenize($source);
        $count = count($tokens);
        $namespace = '';
        $useMap = [];
        $useFunctionMap = [];
        $isImportToken = array_fill(0, max($count, 1), false);
        $isDeclarationName = array_fill(0, max($count, 1), false);

        // クラス本体の `use SomeTrait;` を import と誤認しないための波括弧深さ
        // (非 bracketed namespace 前提。namespace / グループ use の波括弧は下の分岐で読み飛ばす)。
        $braceDepth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token['text'] === '{') {
                $braceDepth++;

                continue;
            }
            if ($token['text'] === '}') {
                $braceDepth--;

                continue;
            }

            if ($token['id'] === T_NAMESPACE) {
                for ($j = $i; $j < $count; $j++) {
                    $isImportToken[$j] = true;
                    if (in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
                        $namespace = $tokens[$j]['text'];
                    }
                    if ($tokens[$j]['text'] === ';' || $tokens[$j]['text'] === '{') {
                        break;
                    }
                }
                $i = $j;

                continue;
            }

            if ($token['id'] === T_USE) {
                // クロージャの `use (...)` とクラス本体の trait use は import ではない
                // (trait use を import 扱いすると use map が汚れ、短縮名の解決が壊れる)
                if (($tokens[$i + 1]['text'] ?? '') === '(' || $braceDepth > 0) {
                    continue;
                }

                $statement = [];
                for ($j = $i; $j < $count; $j++) {
                    $isImportToken[$j] = true;
                    $statement[] = $tokens[$j];
                    if ($tokens[$j]['text'] === ';') {
                        break;
                    }
                }
                self::parseUseStatement($statement, $useMap, $useFunctionMap);
                $i = $j;

                continue;
            }

            // クラス様宣言の名前 (自己参照は漏洩の証拠ではないので参照として数えない)
            if (in_array($token['id'], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ($tokens[$i - 1]['id'] ?? -1) !== T_DOUBLE_COLON
                && ($tokens[$i + 1]['id'] ?? -1) === T_STRING) {
                $isDeclarationName[$i + 1] = true;
            }
        }

        return new self($tokens, $namespace, $useMap, $useFunctionMap, $isImportToken, $isDeclarationName);
    }

    /**
     * `use` 文 1 本を use map へ展開する (単純 / alias / グループ / カンマ区切りに対応)。
     *
     * `use function …` は**関数 map** へ入れる (`use function app as container;` で
     * container helper 経由の到達を alias に隠せてしまうため。`use const …` は
     * container 到達にもクラス参照にも関与しないので捨てる)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $statement
     * @param  array<string, string>  $useMap
     * @param  array<string, string>  $useFunctionMap
     */
    private static function parseUseStatement(array $statement, array &$useMap, array &$useFunctionMap): void
    {
        $count = count($statement);
        $i = 1; // $statement[0] === T_USE

        $kind = $statement[$i]['id'] ?? -1;
        if ($kind === T_CONST) {
            return;
        }

        $isFunctionImport = $kind === T_FUNCTION;
        if ($isFunctionImport) {
            $i++;
        }

        /** @var array<string, string> $parsed */
        $parsed = [];
        $prefix = '';
        $name = '';
        $alias = null;
        $expectingAlias = false;

        for (; $i < $count; $i++) {
            $token = $statement[$i];

            if (in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
                if ($expectingAlias) {
                    $alias = $token['text'];
                } else {
                    $name .= $token['text'];
                }

                continue;
            }

            if ($token['id'] === T_NS_SEPARATOR) {
                $name .= '\\';

                continue;
            }

            if ($token['text'] === '{') {
                $prefix = $name;
                $name = '';

                continue;
            }

            if ($token['id'] === T_AS) {
                $expectingAlias = true;

                continue;
            }

            if ($token['text'] === ',' || $token['text'] === '}' || $token['text'] === ';') {
                self::registerUse($prefix, $name, $alias, $parsed);
                $name = '';
                $alias = null;
                $expectingAlias = false;

                if ($token['text'] === '}') {
                    $prefix = '';
                }
            }
        }

        if ($isFunctionImport) {
            $useFunctionMap = array_merge($useFunctionMap, $parsed);

            return;
        }

        $useMap = array_merge($useMap, $parsed);
    }

    /**
     * @param  array<string, string>  $useMap
     */
    private static function registerUse(string $prefix, string $name, ?string $alias, array &$useMap): void
    {
        if ($name === '') {
            return;
        }

        $fqcn = ltrim($prefix.$name, '\\');
        $segments = explode('\\', $fqcn);
        $short = $alias ?? $segments[count($segments) - 1];
        $useMap[$short] = $fqcn;
    }

    /**
     * 短縮名 / 修飾名を FQCN へ正規化する。
     */
    private function resolve(string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $segments = explode('\\', $name);
        if (isset($this->useMap[$segments[0]])) {
            $segments[0] = $this->useMap[$segments[0]];

            return implode('\\', $segments);
        }

        return $this->namespace === '' ? $name : $this->namespace.'\\'.$name;
    }

    /**
     * 関数呼び出し名を「照合用の末尾セグメント」へ正規化する。
     *
     * `use function app as container;` の alias を解いたうえで末尾セグメントを返す
     * (namespace 付きの `Foo\resolve()` も末尾で照合する = fail-closed)。
     */
    private function resolveFunctionName(string $name): string
    {
        $resolved = $this->useFunctionMap[$name] ?? $name;
        $segments = explode('\\', ltrim($resolved, '\\'));

        return $segments[count($segments) - 1];
    }

    /**
     * `$this->app` が出現するトークン位置 (`$this` の index)。
     *
     * @return list<int>
     */
    private function appAccessIndexes(): array
    {
        $indexes = [];
        $count = count($this->tokens);

        for ($i = 0; $i + 2 < $count; $i++) {
            if ($this->tokens[$i]['id'] === T_VARIABLE
                && $this->tokens[$i]['text'] === '$this'
                && $this->tokens[$i + 1]['id'] === T_OBJECT_OPERATOR
                && $this->tokens[$i + 2]['id'] === T_STRING
                && $this->tokens[$i + 2]['text'] === 'app') {
                $indexes[] = $i;
            }
        }

        return $indexes;
    }

    /** `$this->app-><method>(` の形か */
    private function isAppMethodCallAt(int $index): bool
    {
        return ($this->tokens[$index + 3]['id'] ?? -1) === T_OBJECT_OPERATOR
            && ($this->tokens[$index + 4]['id'] ?? -1) === T_STRING
            && ($this->tokens[$index + 5]['text'] ?? '') === '(';
    }

    /**
     * `$this->app-><method>(…)` の呼び出し一覧。
     *
     * @return list<array{method: string, line: int, args: list<list<array{id: int, text: string, line: int}>>}>
     */
    private function appMethodCalls(): array
    {
        $calls = [];

        foreach ($this->appAccessIndexes() as $index) {
            if (! $this->isAppMethodCallAt($index)) {
                continue;
            }

            $calls[] = [
                'method' => $this->tokens[$index + 4]['text'],
                'line' => $this->tokens[$index + 4]['line'],
                'args' => $this->parseArguments($index + 5),
            ];
        }

        return $calls;
    }

    /**
     * container へ到達する `bind(` 呼び出しの位置引数一覧。
     *
     * 対象は container へ到達する 4 形 — `$this->app->bind` / `app()->bind` /
     * `App::bind` / `Container::getInstance()->bind` (別名は解決してから照合する)。
     *
     * @return list<list<list<array{id: int, text: string, line: int}>>>
     */
    private function containerBindCalls(): array
    {
        $calls = [];
        $count = count($this->tokens);

        for ($i = 0; $i < $count; $i++) {
            $token = $this->tokens[$i];

            if ($this->isImportToken[$i] || $token['id'] !== T_STRING || $token['text'] !== 'bind') {
                continue;
            }
            if (($this->tokens[$i + 1]['text'] ?? '') !== '(') {
                continue;
            }
            if (! $this->isContainerReceiverBefore($i)) {
                continue;
            }

            $calls[] = $this->parseArguments($i + 1);
        }

        return $calls;
    }

    /**
     * `bind` トークンの直前が container を指す受け手か。
     */
    private function isContainerReceiverBefore(int $index): bool
    {
        $operator = $this->tokens[$index - 1] ?? null;
        if ($operator === null) {
            return false;
        }

        // App::bind(…) (facade。use alias も解決する)
        if ($operator['id'] === T_DOUBLE_COLON) {
            return $this->isContainerStaticName($this->tokens[$index - 2] ?? null);
        }

        if (! in_array($operator['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return false;
        }

        $receiver = $this->tokens[$index - 2] ?? null;
        if ($receiver === null) {
            return false;
        }

        // $this->app->bind(…)
        if ($receiver['id'] === T_STRING
            && $receiver['text'] === 'app'
            && in_array($this->tokens[$index - 3]['id'] ?? -1, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && ($this->tokens[$index - 4]['id'] ?? -1) === T_VARIABLE
            && ($this->tokens[$index - 4]['text'] ?? '') === '$this') {
            return true;
        }

        // ここから先は `…()->bind(…)` の形だけが対象
        if ($receiver['text'] !== ')') {
            return false;
        }

        $open = $this->matchingOpenParen($index - 2);
        if ($open === null) {
            return false;
        }

        $callee = $this->tokens[$open - 1] ?? null;
        if ($callee === null) {
            return false;
        }

        // app()->bind(…) / resolve()->bind(…) (`use function app as …` の別名も解決する)
        if (in_array($callee['id'], self::NAME_TOKEN_IDS, true)
            && ! self::isMemberAccessBoundary($this->tokens[$open - 2] ?? null)
            && in_array($this->resolveFunctionName($callee['text']), self::CONTAINER_HELPERS, true)) {
            return true;
        }

        // Container::getInstance()->bind(…)
        return $callee['id'] === T_STRING
            && $callee['text'] === 'getInstance'
            && ($this->tokens[$open - 2]['id'] ?? -1) === T_DOUBLE_COLON
            && $this->isContainerStaticName($this->tokens[$open - 3] ?? null);
    }

    /**
     * 静的アクセスの起点が container を指す名前か (FQCN 解決 + 末尾セグメントの二重線)。
     *
     * @param  array{id: int, text: string, line: int}|null  $token
     */
    private function isContainerStaticName(?array $token): bool
    {
        if ($token === null || ! in_array($token['id'], self::NAME_TOKEN_IDS, true)) {
            return false;
        }

        $segments = explode('\\', ltrim($token['text'], '\\'));
        $last = $segments[count($segments) - 1];

        return in_array($this->resolve($token['text']), self::CONTAINER_STATIC_FQCNS, true)
            || in_array($last, self::CONTAINER_STATIC_ROOTS, true);
    }

    /**
     * `)` の位置に対応する `(` の位置 (見つからなければ null)。
     */
    private function matchingOpenParen(int $closeIndex): ?int
    {
        $depth = 0;

        for ($i = $closeIndex; $i >= 0; $i--) {
            $text = $this->tokens[$i]['text'];

            if ($text === ')') {
                $depth++;

                continue;
            }
            if ($text === '(') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * `(` の位置から位置引数を切り出す (トップレベルの `,` で分割)。
     *
     * @return list<list<array{id: int, text: string, line: int}>>
     */
    private function parseArguments(int $open): array
    {
        $depth = 0;
        $args = [];
        $current = [];
        $count = count($this->tokens);

        for ($i = $open; $i < $count; $i++) {
            $text = $this->tokens[$i]['text'];

            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;
                if ($depth === 1) {
                    continue;
                }
            } elseif ($text === ')' || $text === ']' || $text === '}') {
                $depth--;
                if ($depth === 0) {
                    if ($current !== []) {
                        $args[] = $current;
                    }

                    return $args;
                }
            } elseif ($text === ',' && $depth === 1) {
                $args[] = $current;
                $current = [];

                continue;
            }

            $current[] = $this->tokens[$i];
        }

        if ($current !== []) {
            $args[] = $current;
        }

        return $args;
    }

    /**
     * `A::class` 形の引数か (name token + `::` + `class` のちょうど 3 トークン)。
     *
     * @param  list<array{id: int, text: string, line: int}>  $arg
     */
    private static function isClassConstant(array $arg): bool
    {
        return count($arg) === 3
            && in_array($arg[0]['id'], self::NAME_TOKEN_IDS, true)
            && $arg[1]['id'] === T_DOUBLE_COLON
            && $arg[2]['id'] === T_CLASS;
    }

    /**
     * 宣言 entry のプロパティ参照 (`$swap->abstract`) か。
     *
     * @param  list<array{id: int, text: string, line: int}>  $arg
     */
    private static function isDeclaredProperty(array $arg, string $property): bool
    {
        return count($arg) === 3
            && $arg[0]['id'] === T_VARIABLE
            && in_array($arg[1]['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $arg[2]['id'] === T_STRING
            && $arg[2]['text'] === $property;
    }

    /**
     * 名前付き引数 (`abstract: A::class`) か。
     *
     * @param  list<array{id: int, text: string, line: int}>  $arg
     */
    private static function isNamedArgument(array $arg): bool
    {
        return count($arg) >= 2 && $arg[0]['id'] === T_STRING && $arg[1]['text'] === ':';
    }

    /**
     * spread unpack (`...$args`) を含むか。
     *
     * @param  list<array{id: int, text: string, line: int}>  $arg
     */
    private static function containsUnpack(array $arg): bool
    {
        foreach ($arg as $token) {
            if ($token['id'] === T_ELLIPSIS) {
                return true;
            }
        }

        return false;
    }

    /**
     * 直前のトークンが「名前がクラス参照ではない」ことを示す境界か
     * (`->` / `?->` / `::` / `function` / `const` / `new` 以外の宣言文脈)。
     *
     * @param  array{id: int, text: string, line: int}|null  $previous
     */
    private static function isMemberAccessBoundary(?array $previous): bool
    {
        if ($previous === null) {
            return false;
        }

        return in_array(
            $previous['id'],
            [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_CONST],
            true
        );
    }

    /**
     * 文字列リテラルの中身 (FQCN 表記の `\\` を 1 個の `\` へ畳む)。
     */
    private static function normalizeStringLiteral(string $text): ?string
    {
        if (strlen($text) < 2) {
            return null;
        }

        $quote = $text[0];
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        $inner = substr($text, 1, -1);
        if ($quote === "'") {
            $inner = str_replace("\\'", "'", $inner);
        }
        $inner = str_replace('\\\\', '\\', $inner);

        return ltrim($inner, '\\');
    }

    /**
     * コメント / 空白 / タグを除去したトークン列。
     *
     * 文字列リテラルは FQCN 完全一致の照合に要るのでトークンとしては残すが、
     * その中身をコードとして解釈しない。
     *
     * @return list<array{id: int, text: string, line: int}>
     */
    private static function tokenize(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML], true)) {
                    continue;
                }
                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }

            $tokens[] = ['id' => -1, 'text' => $token, 'line' => $line];
        }

        return $tokens;
    }
}
