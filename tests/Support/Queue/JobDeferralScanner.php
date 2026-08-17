<?php

declare(strict_types=1);

namespace Tests\Support\Queue;

use DateTimeInterface;
use Illuminate\Queue\Attributes\MaxExceptions;
use Illuminate\Queue\Attributes\Tries;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * 退避終端 (標準形 v1) の検出器。
 *
 * **純関数の集合**として作る (入力 = ソース文字列 / クラス名 / config 解決子)。
 * 正例・負例を fixture と合成ソースで毎回証明できるようにするためで、
 * 既存の `QueuedJobLeaseInventoryTest` D8b / D10 と同じ思想である。
 *
 * ■ 既知の限界 (docblock が正本)
 *   - `eval` / 動的 include で生成されるコードは見ない
 *   - `dontRelease()` は**生成式に直接連結された形だけ**を非退避と判定する。
 *     変数へ格納してから呼ぶ形・条件分岐は追跡せず、保守的に退避ありへ倒す (fail-closed)
 *   - 走査根は「クラス自身 + 祖先クラス + 使用 trait の推移閉包」であり、
 *     service 委譲・factory 経由・dispatch サイトの後付けは射程外
 */
final class JobDeferralScanner
{
    /**
     * 走査根 (推移閉包)。クラス自身 + 祖先クラス + 使用 trait を再帰的に辿る。
     *
     * `getTraitNames()` は直接使用 trait しか返さないので、**trait 自身も queue に積んで
     * 再帰する**ことで「trait が使う trait」を拾う (`ReflectionClass` は trait にも使える)。
     * **vendor を除外しない** — ファイルは読めるので除外する理由が無く、除外すると
     * 「vendor 由来クラスを NO_DEFERRAL と申告すれば裏取りされない」経路が開く。
     *
     * @param  class-string  $class
     * @return list<array{path: string, content: string}>
     */
    public static function scanRootsFor(string $class): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, true> $paths */
        $paths = [];
        /** @var list<string> $pending */
        $pending = [$class];

        while ($pending !== []) {
            $current = array_shift($pending);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;

            if (! class_exists($current) && ! trait_exists($current) && ! interface_exists($current)) {
                continue;
            }

            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($current);

            $parent = $reflection->getParentClass();
            if ($parent !== false) {
                $pending[] = $parent->getName();
            }
            foreach ($reflection->getTraitNames() as $trait) {
                $pending[] = $trait;
            }

            $path = $reflection->getFileName();
            if ($path === false) {
                continue;
            }
            $paths[$path] = true;
        }

        $files = [];
        $sorted = array_keys($paths);
        sort($sorted);
        foreach ($sorted as $path) {
            $content = file_get_contents($path);
            if ($content === false) {
                continue;
            }
            $files[] = ['path' => $path, 'content' => $content];
        }

        return $files;
    }

    /**
     * 退避マーカーの検出 (token_get_all のトークン列走査)。
     *
     * kind:
     *   self-release          … `$this->release(` / `$this?->release(`
     *   job-release           … `$this->job->release(` / `$this->job?->release(`
     *   middleware-new        … `new <退避 middleware>(`
     *   middleware-container  … `app(<退避 middleware>::class` 等
     *   namespace-brace       … 波括弧つき namespace (fail-closed)
     *   namespace-multiple    … 1 ファイルに namespace 宣言 2 つ以上 (fail-closed)
     *
     * @param  list<array{path: string, content: string}>  $files
     * @return list<array{path: string, line: int, kind: string, name: string|null}>
     */
    public static function deferralMarkersIn(array $files): array
    {
        $markers = [];

        foreach ($files as $file) {
            foreach (self::markersInSource($file['content']) as $marker) {
                $markers[] = [
                    'path' => $file['path'],
                    'line' => $marker['line'],
                    'kind' => $marker['kind'],
                    'name' => $marker['name'],
                ];
            }
        }

        return $markers;
    }

    /**
     * ファイルのクラス import から alias 表 (短縮名 => FQCN) を作る。
     *
     * マーカー走査 (段 1) と C4 の `Foo::NAME` 解決の**両方**がこれを使う。
     * `use function` / `use const` / closure の `use (...)` / trait 取り込みの `use` は
     * クラス import ではないので表に入れない。
     *
     * @return array<string, string>
     */
    public static function classAliasesIn(string $source): array
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);
        $aliases = [];
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            // 文字列補間の `{$x}` は開き側が T_CURLY_OPEN、閉じ側が素の `}` になるため、
            // 開き側も数えないと depth が負に振れて class 本体の `use` (trait 取り込み) を
            // import と誤読する。
            if (in_array($token['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                $depth++;

                continue;
            }
            if ($token['id'] === null && $token['text'] === '{') {
                $depth++;

                continue;
            }
            if ($token['id'] === null && $token['text'] === '}') {
                $depth--;

                continue;
            }
            if ($token['id'] !== T_USE || $depth !== 0) {
                continue;
            }
            // closure の `use (...)` / `use function` / `use const` はクラス import ではない
            $next = $tokens[$i + 1] ?? null;
            if ($next === null) {
                continue;
            }
            if ($next['id'] === null && $next['text'] === '(') {
                continue;
            }
            if ($next['id'] === T_FUNCTION || $next['id'] === T_CONST) {
                continue;
            }

            $statement = [];
            $j = $i + 1;
            while ($j < $count && ! ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';')) {
                $statement[] = $tokens[$j];
                $j++;
            }
            $i = $j;

            foreach (self::parseUseStatement($statement) as $alias => $fqcn) {
                $aliases[$alias] = $fqcn;
            }
        }

        return $aliases;
    }

    /**
     * `use` 文 (`use` と `;` の間) を alias 表へ展開する。
     *
     * 単一形 / カンマ区切り / group use (`use A\{B, C as D};`) の 3 形を扱う。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $statement
     * @return array<string, string>
     */
    private static function parseUseStatement(array $statement): array
    {
        $texts = array_map(static fn (array $token): string => $token['text'], $statement);
        $joined = implode(' ', $texts);

        // group use: `A\B\{C, D as E}`
        $bracePosition = null;
        foreach ($statement as $index => $token) {
            if ($token['id'] === null && $token['text'] === '{') {
                $bracePosition = $index;

                break;
            }
        }

        if ($bracePosition !== null) {
            $prefix = trim(implode('', array_slice($texts, 0, $bracePosition)));
            $prefix = rtrim($prefix, '\\');
            $body = implode('', array_slice($texts, $bracePosition + 1));
            $body = rtrim(trim($body), '}');

            return self::parseUseItems($body, $prefix);
        }

        return self::parseUseItems($joined, '');
    }

    /**
     * カンマ区切りの import 項目群を alias 表へ展開する。
     *
     * @return array<string, string>
     */
    private static function parseUseItems(string $body, string $prefix): array
    {
        $aliases = [];

        foreach (explode(',', $body) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }

            $alias = null;
            if (preg_match('/^(.*?)\s+as\s+([A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)$/i', $item, $matches) === 1) {
                $item = trim($matches[1]);
                $alias = $matches[2];
            }

            $name = str_replace(' ', '', $item);
            $name = ltrim($name, '\\');
            if ($name === '') {
                continue;
            }
            $fqcn = $prefix === '' ? $name : $prefix.'\\'.$name;

            $segments = explode('\\', $fqcn);
            $aliases[$alias ?? end($segments)] = $fqcn;
        }

        return $aliases;
    }

    /**
     * 1 ファイル分のマーカー検出。
     *
     * @return list<array{line: int, kind: string, name: string|null}>
     */
    private static function markersInSource(string $source): array
    {
        $namespaceViolations = self::namespaceViolations($source);
        if ($namespaceViolations !== []) {
            // alias 表が空洞化するので、そのファイルは fail-closed で違反そのものを返す。
            return $namespaceViolations;
        }

        $tokens = self::significantTokens($source);
        $aliases = self::classAliasesIn($source);
        $excluded = self::releaseDeclarationRanges($tokens);
        $count = count($tokens);
        $markers = [];

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token['id'] === T_VARIABLE && $token['text'] === '$this') {
                $marker = self::selfReleaseMarkerAt($tokens, $i);
                if ($marker !== null && ! self::isExcluded($i, $excluded)) {
                    $markers[] = $marker;
                }

                continue;
            }

            if ($token['id'] === T_NEW) {
                $marker = self::middlewareNewMarkerAt($tokens, $i, $aliases);
                if ($marker !== null) {
                    $markers[] = $marker;
                }

                continue;
            }

            if ($token['id'] === T_STRING && in_array($token['text'], JobDeferralContract::CONTAINER_RESOLVERS, true)) {
                foreach (self::middlewareContainerMarkersAt($tokens, $i, $aliases) as $marker) {
                    $markers[] = $marker;
                }
            }
        }

        return $markers;
    }

    /**
     * namespace 宣言の前提 (1 ファイル 1 つ・`namespace Foo;` 形) が壊れていないか。
     *
     * 壊れていると import の alias 表が空洞化し、検出が無言で素通りする。
     * セキュリティ不変条件 15 が同じ前提を pin しているのと同型の fail-closed。
     *
     * @return list<array{line: int, kind: string, name: string|null}>
     */
    private static function namespaceViolations(string $source): array
    {
        $tokens = self::significantTokens($source);
        $count = count($tokens);
        $violations = [];
        $declarations = 0;

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_NAMESPACE) {
                continue;
            }
            // `namespace\foo()` (T_NAME_RELATIVE) は宣言ではないので T_NAMESPACE にならない
            $declarations++;

            for ($j = $i + 1; $j < $count; $j++) {
                if ($tokens[$j]['id'] !== null) {
                    continue;
                }
                if ($tokens[$j]['text'] === '{') {
                    $violations[] = ['line' => $tokens[$i]['line'], 'kind' => 'namespace-brace', 'name' => null];

                    break;
                }
                if ($tokens[$j]['text'] === ';') {
                    break;
                }
            }

            if ($declarations >= 2) {
                $violations[] = ['line' => $tokens[$i]['line'], 'kind' => 'namespace-multiple', 'name' => null];
            }
        }

        return $violations;
    }

    /**
     * `function release(...) { ... }` の宣言本体のトークン範囲。
     *
     * `Illuminate\Queue\InteractsWithQueue::release()` は宣言本体に
     * `return $this->job->release($delay);` を持つため、これを使用サイトに数えると
     * **この trait を使うだけで全ジョブが退避ありと判定される**修正不能な偽レッドになる。
     * 「退避できる能力の提供」と「処理からその能力を呼ぶこと」は別なので、
     * 宣言本体の**自己退避マーカーだけ**を数えない (middleware の生成は残す)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<array{0: int, 1: int}>
     */
    private static function releaseDeclarationRanges(array $tokens): array
    {
        $count = count($tokens);
        $ranges = [];

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if ($name === null || $name['id'] !== T_STRING || strtolower($name['text']) !== 'release') {
                continue;
            }

            // 引数リストを読み飛ばしてから本体の `{` を探す (戻り型・既定値を跨ぐ)
            $j = $i + 2;
            if (($tokens[$j]['id'] ?? null) === null && ($tokens[$j]['text'] ?? '') === '(') {
                $j = self::matchingIndex($tokens, $j, '(', ')');
                if ($j === null) {
                    continue;
                }
                $j++;
            }

            $open = null;
            for (; $j < $count; $j++) {
                if ($tokens[$j]['id'] !== null) {
                    continue;
                }
                if ($tokens[$j]['text'] === '{') {
                    $open = $j;

                    break;
                }
                if ($tokens[$j]['text'] === ';') {
                    break;
                }
            }
            if ($open === null) {
                continue;
            }

            $close = self::matchingIndex($tokens, $open, '{', '}');
            if ($close === null) {
                continue;
            }
            $ranges[] = [$open, $close];
        }

        return $ranges;
    }

    /**
     * @param  list<array{0: int, 1: int}>  $ranges
     */
    private static function isExcluded(int $index, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if ($index >= $range[0] && $index <= $range[1]) {
                return true;
            }
        }

        return false;
    }

    /**
     * `$this->release(` / `$this->job->release(` の検出 (トークン列一致)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{line: int, kind: string, name: string|null}|null
     */
    private static function selfReleaseMarkerAt(array $tokens, int $index): ?array
    {
        $arrow = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

        $next = $tokens[$index + 1] ?? null;
        if ($next === null || ! in_array($next['id'], $arrow, true)) {
            return null;
        }

        $member = $tokens[$index + 2] ?? null;
        if ($member === null || $member['id'] !== T_STRING) {
            return null;
        }

        // $this->release(
        if ($member['text'] === 'release') {
            $paren = $tokens[$index + 3] ?? null;
            if ($paren !== null && $paren['id'] === null && $paren['text'] === '(') {
                return ['line' => $member['line'], 'kind' => 'self-release', 'name' => null];
            }

            return null;
        }

        // $this->job->release(
        if ($member['text'] !== 'job') {
            return null;
        }
        $secondArrow = $tokens[$index + 3] ?? null;
        if ($secondArrow === null || ! in_array($secondArrow['id'], $arrow, true)) {
            return null;
        }
        $releaseName = $tokens[$index + 4] ?? null;
        if ($releaseName === null || $releaseName['id'] !== T_STRING || $releaseName['text'] !== 'release') {
            return null;
        }
        $paren = $tokens[$index + 5] ?? null;
        if ($paren === null || $paren['id'] !== null || $paren['text'] !== '(') {
            return null;
        }

        return ['line' => $releaseName['line'], 'kind' => 'job-release', 'name' => null];
    }

    /**
     * `new <退避 middleware>(` の検出。生成式に直結した `->dontRelease()` は非退避とする。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $aliases
     * @return array{line: int, kind: string, name: string|null}|null
     */
    private static function middlewareNewMarkerAt(array $tokens, int $index, array $aliases): ?array
    {
        $name = $tokens[$index + 1] ?? null;
        if ($name === null || ! in_array($name['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $short = self::resolveShortName($name['text'], $aliases);
        if (! in_array($short, JobDeferralContract::RELEASING_MIDDLEWARE, true)) {
            return null;
        }

        // 生成式の終端 (引数リストの閉じ括弧、または括弧を書かない `new Foo`)
        $end = $index + 1;
        $paren = $tokens[$index + 2] ?? null;
        if ($paren !== null && $paren['id'] === null && $paren['text'] === '(') {
            $closing = self::matchingIndex($tokens, $index + 2, '(', ')');
            if ($closing === null) {
                return null;
            }
            $end = $closing;
        }

        if (self::hasDirectDontRelease($tokens, $index, $end)) {
            return null;
        }

        return ['line' => $name['line'], 'kind' => 'middleware-new', 'name' => $short];
    }

    /**
     * 生成式の直後に `->dontRelease()` が**直結**しているか。
     *
     * 非退避と判定するのは次の 2 形だけである:
     *   - `new RateLimited('mail')->dontRelease()`   (PHP 8.4 の括弧なし形)
     *   - `(new RateLimited('mail'))->dontRelease()` (外側が **grouping 括弧**のとき)
     *
     * **外側の `)` を無条件に剥がしてはならない** (Codex 実装レビュー Round 1 [Warning])。
     * `wrap(new RateLimited('mail'))->dontRelease()` では `dontRelease()` が wrapper の
     * 戻り値に掛かっており middleware の退避は無効化されていないのに、無条件に剥がすと
     * 非退避と誤判定して **NO_DEFERRAL を偽グリーンにできる**。
     * そこで `new` の直前の `(` が grouping 括弧であること (= その直前が呼び出し名・変数・
     * `)`・`]` のいずれでもないこと) を条件にし、**判定できない形はマーカーを残す (fail-closed)**。
     *
     * 変数へ格納してから呼ぶ形・条件分岐も同じ理由で追跡しない (退避ありへ倒す)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function hasDirectDontRelease(array $tokens, int $newIndex, int $end): bool
    {
        // (a) 括弧なし形
        if (self::isDontReleaseChainAt($tokens, $end + 1)) {
            return true;
        }

        // (b) 外側が grouping 括弧の形
        $opening = $tokens[$newIndex - 1] ?? null;
        if ($opening === null || $opening['id'] !== null || $opening['text'] !== '(') {
            return false;
        }
        if (! self::isGroupingParenPrefix($tokens[$newIndex - 2] ?? null)) {
            // `wrap(new Foo(...))->dontRelease()` / `'wrap'(...)` / `$wrap(...)` のような呼び出し形。
            // dontRelease() の受け手が middleware ではないので、退避ありのまま残す (fail-closed)。
            return false;
        }
        $closing = $tokens[$end + 1] ?? null;
        if ($closing === null || $closing['id'] !== null || $closing['text'] !== ')') {
            return false;
        }

        return self::isDontReleaseChainAt($tokens, $end + 2);
    }

    /**
     * 指定 index から `-> dontRelease` が始まっているか。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isDontReleaseChainAt(array $tokens, int $index): bool
    {
        $chain = $tokens[$index] ?? null;
        if ($chain === null || ! in_array($chain['id'], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)) {
            return false;
        }
        $method = $tokens[$index + 1] ?? null;

        return $method !== null && $method['id'] === T_STRING && $method['text'] === 'dontRelease';
    }

    /**
     * その `(` が **grouping 括弧**と断定できるか (直前トークンの **allowlist**)。
     *
     * **blacklist にしてはならない** (Codex 実装レビュー Round 2 [Warning]):
     * 「呼び出しに見えるトークン」を列挙して除外する形にすると、列挙外の callable 構文
     * (callable string `'wrap'(...)`、即時実行 closure の `}`、`$fn(...)` の亜種、
     *  first-class callable 等) が grouping と誤認され、`dontRelease()` の除外が
     * **NO_DEFERRAL の偽グリーン経路**として復活する。
     *
     * したがって「式が来る位置だと断定できる直前トークン」だけを allowlist にし、
     * **未知の構文はすべて fail-closed** (= grouping ではない = マーカーを残す) に倒す。
     *
     * @param  array{id: int|null, text: string, line: int}|null  $token
     */
    private static function isGroupingParenPrefix(?array $token): bool
    {
        // ファイル先頭 (直前トークンが無い) は式の開始位置とみなす。
        if ($token === null) {
            return true;
        }

        if ($token['id'] === null) {
            return in_array($token['text'], ['(', '[', '{', ',', ';', '=', '.', '?', ':'], true);
        }

        return in_array(
            $token['id'],
            [T_RETURN, T_DOUBLE_ARROW, T_COALESCE, T_BOOLEAN_AND, T_BOOLEAN_OR, T_CASE, T_YIELD],
            true,
        );
    }

    /**
     * container 解決形 (`app(X::class)` 等) の引数に退避 middleware があるか。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<string, string>  $aliases
     * @return list<array{line: int, kind: string, name: string|null}>
     */
    private static function middlewareContainerMarkersAt(array $tokens, int $index, array $aliases): array
    {
        $paren = $tokens[$index + 1] ?? null;
        if ($paren === null || $paren['id'] !== null || $paren['text'] !== '(') {
            return [];
        }
        $closing = self::matchingIndex($tokens, $index + 1, '(', ')');
        if ($closing === null) {
            return [];
        }

        $markers = [];
        for ($i = $index + 2; $i < $closing; $i++) {
            $name = $tokens[$i];
            if (! in_array($name['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $operator = $tokens[$i + 1] ?? null;
            $classKeyword = $tokens[$i + 2] ?? null;
            if ($operator === null || $operator['id'] !== T_DOUBLE_COLON) {
                continue;
            }
            if ($classKeyword === null || $classKeyword['id'] !== T_CLASS) {
                continue;
            }

            $short = self::resolveShortName($name['text'], $aliases);
            if (in_array($short, JobDeferralContract::RELEASING_MIDDLEWARE, true)) {
                $markers[] = ['line' => $name['line'], 'kind' => 'middleware-container', 'name' => $short];
            }
        }

        return $markers;
    }

    /**
     * 名前を alias 表で解決して**短縮名 (末尾セグメント)** にする。
     *
     * @param  array<string, string>  $aliases
     */
    private static function resolveShortName(string $name, array $aliases): string
    {
        $normalized = ltrim($name, '\\');
        $segments = explode('\\', $normalized);
        $head = $segments[0];

        if (isset($aliases[$head])) {
            $segments = array_merge(explode('\\', $aliases[$head]), array_slice($segments, 1));
        }

        // explode() は必ず 1 要素以上を返すので end() が false になることはない。
        return end($segments);
    }

    /**
     * 対応する閉じ記号の index。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchingIndex(array $tokens, int $openIndex, string $open, string $close): ?int
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $openIndex; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== null) {
                // `{` は T_CURLY_OPEN / T_DOLLAR_OPEN_CURLY_BRACES としても現れる
                if ($open === '{' && in_array($tokens[$i]['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
                    $depth++;
                }

                continue;
            }
            if ($tokens[$i]['text'] === $open) {
                $depth++;

                continue;
            }
            if ($tokens[$i]['text'] === $close) {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /**
     * 有意トークン (空白・コメントを除く) の正規化列。
     *
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private static function significantTokens(string $source): array
    {
        $tokens = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                $line = $token[2];
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $tokens[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];

                continue;
            }
            $tokens[] = ['id' => null, 'text' => $token, 'line' => $line];
        }

        return $tokens;
    }

    /**
     * C1: retryUntil() の宣言形。違反理由の list を返す (空 = 合格)。
     *
     * **プロパティ形 `public $retryUntil` を認めない**: framework の `getJobExpiration()` は
     * `$job->retryUntil ?? $job->retryUntil()` を読むのでプロパティ形でも動くが、
     * 標準形は 1 つに固定する (2 形が並走すると雛形の読み手が選択を迫られる)。
     * 戻り型を非 nullable の named type に固定するのは、`getJobExpiration()` が
     * `DateTimeInterface` でない値 (int timestamp 等) をそのまま payload へ通してしまい、
     * 「絶対時刻」の裁定が空洞化するためである。
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function horizonDeclarationViolations(string $class): array
    {
        $violations = [];
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);

        if (array_key_exists('retryUntil', $reflection->getDefaultProperties())) {
            $violations[] = 'retryUntil をプロパティ形で宣言している (標準形はメソッド形 1 つに固定する)';
        }

        if (! $reflection->hasMethod('retryUntil')) {
            $violations[] = 'retryUntil() を宣言していない';

            return $violations;
        }

        $type = $reflection->getMethod('retryUntil')->getReturnType();

        if (! $type instanceof ReflectionNamedType) {
            $violations[] = 'retryUntil() の戻り型が named type ではない (union / intersection / 無指定)';

            return $violations;
        }
        if ($type->allowsNull()) {
            $violations[] = 'retryUntil() の戻り型が nullable である (期限なしへ倒れる口を残さない)';
        }
        if (! is_a($type->getName(), DateTimeInterface::class, true)) {
            $violations[] = 'retryUntil() の戻り型が DateTimeInterface (またはその子型) ではない: '.$type->getName();
        }

        return $violations;
    }

    /**
     * C2: $tries / #[Tries] / tries() のいずれも宣言していないこと (継承・trait 由来を含む)。
     *
     * 根拠 (逐語): `Worker::markJobAsFailedIfAlreadyExceedsMaxAttempts()` は
     * `if ($retryUntil && Carbon::now()->getTimestamp() <= $retryUntil) { return; }` で
     * **先に return する**ため、`retryUntil` があると `maxTries` は一切参照されない。
     * `tries()` メソッド形まで見るのは `Queue::getJobTries()` が
     * `if (method_exists($job, 'tries')) { $tries = $job->tries(); }` を持つためである。
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function triesDeclarationViolations(string $class): array
    {
        $violations = [];
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);

        // **`getDefaultProperties()` ではなく `hasProperty()` で見る** (Codex 実装レビュー Round 1 [Warning]):
        // C2 の契約は「$tries を**宣言しない**」であって既定値が何かではない。値取得 API で
        // 宣言の有無を判定すると `public int $tries;` (既定値なし) のような形で迂回されうる。
        if ($reflection->hasProperty('tries')) {
            $violations[] = '$tries を宣言している (retryUntil があると maxTries は参照されない)';
        }
        if (method_exists($class, 'tries')) {
            $violations[] = 'tries() メソッドを宣言している (Queue::getJobTries が読む)';
        }
        // **禁止側なので framework より厳しく trait を再帰的に辿る** (fail-closed)。
        // framework は direct trait しか見ないので trait-in-trait の #[Tries] は実際には効かないが、
        // 「回数でも止まる」という誤読を生む宣言は書かせない。
        if (self::queueAttributeInstance($class, Tries::class, recursiveTraits: true) !== null) {
            $violations[] = '#[Tries] 属性を持っている (クラス / trait (入れ子を含む) / 親クラスのいずれか)';
        }

        return $violations;
    }

    /**
     * C3: $maxExceptions / #[MaxExceptions] が 1 以上の int であること。
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function maxExceptionsViolations(string $class): array
    {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);

        $value = $reflection->getDefaultProperties()['maxExceptions'] ?? null;

        if ($value === null) {
            // **C3 は framework と同一範囲 (direct trait + 親)** にする。ここは実効値の解決なので、
            // framework より広く見ると「実際には効かない値」を合格判定に使うことになる。
            $attribute = self::queueAttributeInstance($class, MaxExceptions::class, recursiveTraits: false);
            if ($attribute instanceof MaxExceptions) {
                $value = $attribute->maxExceptions;
            }
        }

        if ($value === null) {
            return ['$maxExceptions / #[MaxExceptions] を宣言していない (未処理例外を別に数えられない)'];
        }
        if (! is_int($value)) {
            return ['$maxExceptions が int ではない'];
        }
        if ($value < 1) {
            return ['$maxExceptions が 1 未満である (0 は「数えない」と同義)'];
        }

        return [];
    }

    /**
     * `#[Tries]` / `#[MaxExceptions]` を**クラス → trait → 親クラス**の順で解決する。
     *
     * `$recursiveTraits = false` のとき、framework の
     * `ReadsClassAttributes::getAttributeInstance()` と**完全に同じ探索範囲**になる
     * (direct trait のみ + 親クラス連鎖)。**実効値を解決する検査 (C3) はこちらを使う** —
     * framework より広く見ると「実際には効かない値」を合格判定に使うことになるため。
     *
     * `$recursiveTraits = true` のとき、trait が使う trait まで再帰的に辿る。
     * **宣言そのものを禁止する検査 (C2) はこちらを使う** — framework が読まない位置の
     * `#[Tries]` は実際には効かないが、「回数でも止まる」という誤読を生むので書かせない
     * (fail-closed。Codex 実装レビュー Round 1 [Suggestion])。
     *
     * @param  class-string  $class
     * @param  class-string  $attributeClass
     */
    private static function queueAttributeInstance(
        string $class,
        string $attributeClass,
        bool $recursiveTraits,
    ): ?object {
        /** @var ReflectionClass<object> $reflection */
        $reflection = new ReflectionClass($class);

        while (true) {
            $attributes = $reflection->getAttributes($attributeClass);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }

            $instance = self::traitAttributeInstance($reflection, $attributeClass, $recursiveTraits);
            if ($instance !== null) {
                return $instance;
            }

            $parent = $reflection->getParentClass();
            if ($parent === false) {
                return null;
            }
            $reflection = $parent;
        }
    }

    /**
     * trait 側の属性を解決する。`$recursive` が true のとき trait が使う trait まで辿る。
     *
     * @param  ReflectionClass<object>  $reflection
     * @param  class-string  $attributeClass
     */
    private static function traitAttributeInstance(
        ReflectionClass $reflection,
        string $attributeClass,
        bool $recursive,
    ): ?object {
        foreach ($reflection->getTraits() as $trait) {
            $attributes = $trait->getAttributes($attributeClass);
            if ($attributes !== []) {
                return $attributes[0]->newInstance();
            }
            if (! $recursive) {
                continue;
            }
            $nested = self::traitAttributeInstance($trait, $attributeClass, true);
            if ($nested !== null) {
                return $nested;
            }
        }

        return null;
    }

    /**
     * C4 (合成ソース版): `retryUntil()` の**自スコープ**の return 式が閉じた式文法に合うこと。
     *
     * ```
     * return-expr := clock ( '->' add系 '(' arg ')' )+     ← 加算 1 回以上 (0 回は不可)
     * clock       := now() | Carbon::now() | CarbonImmutable::now() | Date::now()
     * add系        := JobDeferralContract::ADD_METHODS のいずれか (引数 1 個)
     * arg         := 整数リテラル | クラス定数 | config('<文字列リテラル>')   ← 解決値が int かつ 1 以上
     * ```
     *
     * **許可形の閉集合 (deny-by-default)** にしてあるので、シリアライズ済みプロパティ起点・
     * コンストラクタ引数起点・絶対日時リテラル・三項演算子・別メソッド戻り値・変数は
     * すべて自動的に落ちる。「禁止形を列挙して落とす」形は allow-by-default への退行なので採らない。
     *
     * `$selfClass` (= `self::` の解決先 = **宣言クラス**) と `$staticClass`
     * (= `static::` の解決先 = **検査対象クラス**) を別々に受けるのは PHP の意味が違うためである。
     *
     * @param  class-string|null  $selfClass
     * @param  class-string|null  $staticClass
     * @param  array<string, string>  $aliases
     * @param  callable(string): mixed  $configResolver
     * @return list<string>
     */
    public static function horizonExpressionViolationsIn(
        string $source,
        ?string $selfClass,
        ?string $staticClass,
        array $aliases,
        callable $configResolver,
    ): array {
        $tokens = self::significantTokens($source);
        $body = self::methodBodyRange($tokens, 'retryUntil');

        if ($body === null) {
            return ['retryUntil() の宣言本体を切り出せなかった'];
        }

        $returns = self::ownScopeReturns($tokens, $body[0], $body[1]);

        if ($returns === []) {
            return ['retryUntil() の自スコープに return が 1 件も無い (空虚に成功させない)'];
        }

        $violations = [];
        foreach ($returns as $expression) {
            $violation = self::horizonReturnViolation($expression, $selfClass, $staticClass, $aliases, $configResolver);
            if ($violation !== null) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /**
     * C4 (実クラス版)。**retryUntil() の宣言クラスのファイル**からソースを取り、
     * `self::` / `static::` を正しい基準で解決して合成ソース版へ委譲する。
     *
     * - **宣言クラスのファイルから取る**: 継承した `retryUntil()` の場合、エントリ自身の
     *   ファイルには `function retryUntil` が 1 つも無く、素朴な実装は return 0 件で偽レッドになる。
     * - **行範囲で切り出す**: 1 ファイルに複数クラスがあり、それぞれが `retryUntil()` を
     *   宣言していると「最初の `function retryUntil`」を見る実装は別クラスを検査してしまう。
     * - **alias 表はファイル全体から作る**: 行範囲の断片には `use` 宣言が含まれないため、
     *   `use ... as Policy;` を使った正当な契約が偽レッドになる。
     *
     * @param  class-string  $class
     * @return list<string>
     */
    public static function horizonExpressionViolationsFor(string $class): array
    {
        if (! method_exists($class, 'retryUntil')) {
            return ['retryUntil() を宣言していない'];
        }

        $method = new ReflectionMethod($class, 'retryUntil');
        $declaring = $method->getDeclaringClass();
        $path = $method->getFileName();

        if ($path === false) {
            return ['retryUntil() の宣言ファイルを解決できなかった'];
        }

        $lines = file($path);
        if ($lines === false) {
            return ['retryUntil() の宣言ファイルを読めなかった: '.$path];
        }

        $start = $method->getStartLine();
        $end = $method->getEndLine();
        if ($start === false || $end === false) {
            return ['retryUntil() の行範囲を解決できなかった'];
        }

        $fragment = implode('', array_slice($lines, $start - 1, $end - $start + 1));

        return self::horizonExpressionViolationsIn(
            "<?php\n".$fragment,
            $declaring->getName(),
            $class,
            self::classAliasesIn(implode('', $lines)),
            static fn (string $key): mixed => config($key),
        );
    }

    /**
     * `function <name>` の宣言本体 (開き `{` と閉じ `}`) のトークン index。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{0: int, 1: int}|null
     */
    private static function methodBodyRange(array $tokens, string $name): ?array
    {
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }
            $declared = $tokens[$i + 1] ?? null;
            if ($declared === null || $declared['id'] !== T_STRING || $declared['text'] !== $name) {
                continue;
            }

            $j = $i + 2;
            if (($tokens[$j]['id'] ?? null) === null && ($tokens[$j]['text'] ?? '') === '(') {
                $closing = self::matchingIndex($tokens, $j, '(', ')');
                if ($closing === null) {
                    return null;
                }
                $j = $closing + 1;
            }

            for (; $j < $count; $j++) {
                if ($tokens[$j]['id'] !== null) {
                    continue;
                }
                if ($tokens[$j]['text'] === '{') {
                    $close = self::matchingIndex($tokens, $j, '{', '}');

                    return $close === null ? null : [$j, $close];
                }
                if ($tokens[$j]['text'] === ';') {
                    return null;
                }
            }
        }

        return null;
    }

    /**
     * 本体の**自スコープ**にある return 式のトークン列。
     *
     * closure / アロー関数 / 匿名クラス**の内側の return は数えない** (件数にも構造検査にも
     * 含めない)。波括弧の深さだけでなく**関数スコープの開始・終了を追跡する**。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return list<list<array{id: int|null, text: string, line: int}>>
     */
    private static function ownScopeReturns(array $tokens, int $open, int $close): array
    {
        $returns = [];
        $i = $open + 1;

        while ($i < $close) {
            $token = $tokens[$i];

            if ($token['id'] === T_FUNCTION) {
                $i = self::skipBracedScope($tokens, $i, $close);

                continue;
            }
            if ($token['id'] === T_FN) {
                $i = self::skipArrowFunction($tokens, $i, $close);

                continue;
            }
            // 匿名クラスは `new` の**直後が T_CLASS** である形だけ (通常の `new Foo()` と衝突させない)
            if ($token['id'] === T_NEW && ($tokens[$i + 1]['id'] ?? null) === T_CLASS) {
                $i = self::skipBracedScope($tokens, $i + 1, $close);

                continue;
            }
            if ($token['id'] !== T_RETURN) {
                $i++;

                continue;
            }

            $expression = [];
            $depth = 0;
            $j = $i + 1;
            while ($j < $close) {
                $current = $tokens[$j];
                if ($current['id'] === null) {
                    if (in_array($current['text'], ['(', '[', '{'], true)) {
                        $depth++;
                    } elseif (in_array($current['text'], [')', ']', '}'], true)) {
                        $depth--;
                    } elseif ($current['text'] === ';' && $depth === 0) {
                        break;
                    }
                }
                $expression[] = $current;
                $j++;
            }
            $returns[] = $expression;
            $i = $j + 1;
        }

        return $returns;
    }

    /**
     * `{ ... }` を持つ入れ子スコープを読み飛ばして、その直後の index を返す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function skipBracedScope(array $tokens, int $from, int $limit): int
    {
        for ($i = $from; $i < $limit; $i++) {
            if ($tokens[$i]['id'] !== null || $tokens[$i]['text'] !== '{') {
                continue;
            }
            $close = self::matchingIndex($tokens, $i, '{', '}');

            return $close === null ? $limit : $close + 1;
        }

        return $limit;
    }

    /**
     * アロー関数 (`fn (...) => 式`) を読み飛ばす。本体は `;` / `,` / 外側の閉じ括弧で終わる。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function skipArrowFunction(array $tokens, int $from, int $limit): int
    {
        $i = $from + 1;
        if (($tokens[$i]['id'] ?? null) === null && ($tokens[$i]['text'] ?? '') === '(') {
            $closing = self::matchingIndex($tokens, $i, '(', ')');
            if ($closing === null) {
                return $limit;
            }
            $i = $closing + 1;
        }

        $depth = 0;
        for (; $i < $limit; $i++) {
            $token = $tokens[$i];
            if ($token['id'] !== null) {
                continue;
            }
            if (in_array($token['text'], ['(', '[', '{'], true)) {
                $depth++;

                continue;
            }
            if (in_array($token['text'], [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return $i;
                }
                $depth--;

                continue;
            }
            if ($depth === 0 && ($token['text'] === ';' || $token['text'] === ',')) {
                return $i;
            }
        }

        return $limit;
    }

    /**
     * return 式 1 本を式文法で検査する (合格なら null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $expression
     * @param  class-string|null  $selfClass
     * @param  class-string|null  $staticClass
     * @param  array<string, string>  $aliases
     * @param  callable(string): mixed  $configResolver
     */
    private static function horizonReturnViolation(
        array $expression,
        ?string $selfClass,
        ?string $staticClass,
        array $aliases,
        callable $configResolver,
    ): ?string {
        $rendered = self::render($expression);
        $count = count($expression);
        $cursor = self::consumeClock($expression, $aliases);

        if ($cursor === null) {
            return '許可された時計起点 ('.implode(' / ', JobDeferralContract::CLOCK_SOURCES).') で始まっていない: '.$rendered;
        }

        $additions = 0;
        while ($cursor < $count) {
            if ($expression[$cursor]['id'] !== T_OBJECT_OPERATOR) {
                return '加算チェーン以外のトークンが混ざっている: '.$rendered;
            }
            $method = $expression[$cursor + 1] ?? null;
            if ($method === null || $method['id'] !== T_STRING
                || ! in_array($method['text'], JobDeferralContract::ADD_METHODS, true)) {
                return '許可されていないメソッドを呼んでいる (単位固定の加算だけが許可される): '.$rendered;
            }
            $paren = $expression[$cursor + 2] ?? null;
            if ($paren === null || $paren['id'] !== null || $paren['text'] !== '(') {
                return '加算メソッドの呼び出し形になっていない: '.$rendered;
            }
            $closing = self::matchingIndex($expression, $cursor + 2, '(', ')');
            if ($closing === null) {
                return '加算メソッドの引数リストが閉じていない: '.$rendered;
            }

            $argument = array_slice($expression, $cursor + 3, $closing - $cursor - 3);
            $value = self::resolveHorizonArgument($argument, $selfClass, $staticClass, $aliases, $configResolver);

            if (! is_int($value)) {
                return '加算の引数を int として解決できない (整数リテラル / クラス定数 / config リテラルのみ): '.$rendered;
            }
            if ($value < 1) {
                return '加算の引数が 1 未満である (push 時点で期限切れの payload を作れてしまう): '.$rendered;
            }

            $additions++;
            $cursor = $closing + 1;
        }

        if ($additions < 1) {
            return '加算が 1 回も無い (現在時刻そのものは期限にならない): '.$rendered;
        }

        return null;
    }

    /**
     * 時計起点を消費して次の index を返す (合わなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $expression
     * @param  array<string, string>  $aliases
     */
    private static function consumeClock(array $expression, array $aliases): ?int
    {
        $first = $expression[0] ?? null;
        if ($first === null) {
            return null;
        }

        $isParen = static fn (?array $token, string $text): bool => $token !== null
            && $token['id'] === null
            && $token['text'] === $text;

        // now()
        if ($first['id'] === T_STRING && $first['text'] === 'now'
            && $isParen($expression[1] ?? null, '(') && $isParen($expression[2] ?? null, ')')) {
            return 3;
        }

        // Carbon::now() / CarbonImmutable::now() / Date::now()
        if (! in_array($first['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }
        if (($expression[1]['id'] ?? null) !== T_DOUBLE_COLON) {
            return null;
        }
        $method = $expression[2] ?? null;
        if ($method === null || $method['id'] !== T_STRING || $method['text'] !== 'now') {
            return null;
        }
        if (! $isParen($expression[3] ?? null, '(') || ! $isParen($expression[4] ?? null, ')')) {
            return null;
        }
        if (! in_array(self::resolveShortName($first['text'], $aliases).'::now', JobDeferralContract::CLOCK_SOURCES, true)) {
            return null;
        }

        return 5;
    }

    /**
     * 加算の引数を解決する (解決できなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $argument
     * @param  class-string|null  $selfClass
     * @param  class-string|null  $staticClass
     * @param  array<string, string>  $aliases
     * @param  callable(string): mixed  $configResolver
     */
    private static function resolveHorizonArgument(
        array $argument,
        ?string $selfClass,
        ?string $staticClass,
        array $aliases,
        callable $configResolver,
    ): mixed {
        $count = count($argument);

        // 整数リテラル
        if ($count === 1 && $argument[0]['id'] === T_LNUMBER) {
            return (int) $argument[0]['text'];
        }

        // クラス定数 (self:: / static:: / <alias または修飾名>::)
        if ($count === 3 && $argument[1]['id'] === T_DOUBLE_COLON && $argument[2]['id'] === T_STRING) {
            $owner = self::resolveConstantOwner($argument[0], $selfClass, $staticClass, $aliases);
            if ($owner === null || ! class_exists($owner)) {
                return null;
            }
            /** @var ReflectionClass<object> $reflection */
            $reflection = new ReflectionClass($owner);
            if (! $reflection->hasConstant($argument[2]['text'])) {
                return null;
            }

            return $reflection->getConstant($argument[2]['text']);
        }

        // config('<文字列リテラル>')
        if ($count === 4
            && $argument[0]['id'] === T_STRING && $argument[0]['text'] === 'config'
            && $argument[1]['id'] === null && $argument[1]['text'] === '('
            && $argument[2]['id'] === T_CONSTANT_ENCAPSED_STRING
            && $argument[3]['id'] === null && $argument[3]['text'] === ')') {
            $key = trim($argument[2]['text'], "'\"");

            return $configResolver($key);
        }

        return null;
    }

    /**
     * クラス定数の所有クラスを解決する。
     *
     * `self::` は**宣言クラス**、`static::` は**検査対象クラス**を指す (PHP の意味が違う)。
     * 親が private 定数を持つ場合、子の reflection では取れないため、この区別を誤ると
     * 正当な契約が偽レッドになる。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     * @param  class-string|null  $selfClass
     * @param  class-string|null  $staticClass
     * @param  array<string, string>  $aliases
     */
    private static function resolveConstantOwner(
        array $token,
        ?string $selfClass,
        ?string $staticClass,
        array $aliases,
    ): ?string {
        if ($token['id'] === T_STATIC) {
            return $staticClass;
        }
        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        $name = $token['text'];
        $lower = strtolower($name);
        if ($lower === 'self') {
            return $selfClass;
        }
        if ($lower === 'static') {
            return $staticClass;
        }
        if ($lower === 'parent') {
            // 親を辿る形は「宣言クラスが自明でない」ため受理しない (fail-closed)。
            return null;
        }

        $normalized = ltrim($name, '\\');
        $segments = explode('\\', $normalized);
        $head = $segments[0];

        if (isset($aliases[$head])) {
            return implode('\\', array_merge(explode('\\', $aliases[$head]), array_slice($segments, 1)));
        }
        if (count($segments) > 1 || str_starts_with($name, '\\')) {
            return $normalized;
        }

        // 未修飾で alias 表にも無い名前は解決できない (fail-closed)。
        return null;
    }

    /**
     * 失敗メッセージ用にトークン列を復元する。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $expression
     */
    private static function render(array $expression): string
    {
        return implode('', array_map(static fn (array $token): string => $token['text'], $expression));
    }
}
