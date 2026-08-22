<?php

declare(strict_types=1);

namespace Tests\Support\SurfaceRemoval;

/**
 * PHP のクラス参照を**完全修飾名へ解決する**(AGENTS.md「静的検査の共通規約」(a))。
 *
 * 短名一致は別名つき取り込み 1 つで検査が黙り、末尾の要素だけの一致は同名の別クラスを拾う。
 * 本クラスは `Tests\Support\PhpTokenScan::normalize()` が返すトークン列を 1 度走査して
 * 「その位置での namespace / 取り込み表 / 囲んでいる型」を索引し、参照位置のトークンから
 * 完全修飾名を返す。
 *
 * ★**対応する名前構文** (これ以外は解決しない = null を返す):
 *   - `namespace A\B;` (文形) と `namespace A\B { … }` (ブロック形)、1 ファイル内の複数 namespace
 *   - `use A\B\C;` / `use A\B\C as D;` / group use `use A\B\{C, D as E};`
 *   - `T_NAME_FULLY_QUALIFIED` (`\A\B\C`) / `T_NAME_QUALIFIED` (`A\B\C`) /
 *     `T_NAME_RELATIVE` (`namespace\C`) / `T_STRING` (短名)
 *   - class / enum / interface の中の `self` (現在の宣言クラス) /
 *     `static` (遅延静的束縛で別クラスになり得るが**現在の宣言クラスを候補として保守的に扱う**。
 *     拾いすぎる方向は可・見逃す方向は不可) / `parent` (`extends` を解ければそれ、解けなければ**未解決**)
 * ★**trait の中の `self` / `static` / `parent` はすべて未解決にする**。trait のメンバーは
 *   利用クラスへ組み込まれるため `self` 等の意味は**利用クラスに依存する** (PHP の意味論)。
 *   trait 自身の完全修飾名へ確定すると誤った解決済み結果になり、対象メソッドの呼び出しを
 *   trait に置いて対象クラスが `use` する形が**静かに通ってしまう** (fail-open)。
 *   v1 は trait-use graph を実装しないので fail-closed で落とす。
 * ★**保証しないもの**: 動的なクラス名 (`$cls::` / 文字列変数) は解決しない (null を返し、
 *   利用側 gate が未解決として落とす)。`use function` / `use const` は取り込み表に入れない
 *   (クラス参照ではないため対象外)。取り込み表は **namespace 区間全体へ一様に適用する**
 *   (使用位置より後ろに書かれた `use` も効く = 拾いすぎる方向)。
 *   条件分岐の中で宣言されたクラスや、`class_alias()` による別名は扱わない。
 *
 * @phpstan-type NormalizedToken array{id: int|null, text: string, line: int}
 * @phpstan-type NamespaceSegment array{start: int, namespace: string, uses: array<string, string>}
 * @phpstan-type TypeSegment array{start: int, end: int, bodyDepth: int, fqcn: string, isTrait: bool, parentRaw: string|null, parentId: int|null, usesTraits: bool}
 */
final class PhpNameResolver
{
    /**
     * @param  list<NamespaceSegment>  $namespaceSegments
     * @param  list<TypeSegment>  $typeSegments
     * @param  list<int>  $depths  トークン位置 => その位置の波括弧の深さ
     */
    private function __construct(
        private readonly array $namespaceSegments,
        private readonly array $typeSegments,
        private readonly array $depths,
    ) {}

    /**
     * トークン列を索引する。
     *
     * @param  list<NormalizedToken>  $tokens
     */
    public static function analyze(array $tokens): self
    {
        /** @var list<NamespaceSegment> $namespaceSegments */
        $namespaceSegments = [['start' => 0, 'namespace' => '', 'uses' => []]];
        /** @var list<TypeSegment> $typeSegments */
        $typeSegments = [];
        /** @var list<TypeSegment> $openTypes */
        $openTypes = [];
        /** @var TypeSegment|null $pendingType */
        $pendingType = null;
        $depth = 0;
        $count = count($tokens);
        /** @var list<int> $depths */
        $depths = [];

        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i]['id'];
            $text = $tokens[$i]['text'];
            // ★その位置に入った時点の深さを記録する (波括弧の増減を反映する前)
            $depths[$i] = $depth;

            if (self::isOpeningBrace($id, $text)) {
                $depth++;
                if ($pendingType !== null) {
                    $pendingType['start'] = $i;
                    $pendingType['bodyDepth'] = $depth;
                    $openTypes[] = $pendingType;
                    $pendingType = null;
                }

                continue;
            }

            if ($id === null && $text === '}') {
                $last = count($openTypes) - 1;
                if ($last >= 0 && $openTypes[$last]['bodyDepth'] === $depth) {
                    $closed = $openTypes[$last];
                    array_pop($openTypes);
                    $closed['end'] = $i;
                    $typeSegments[] = $closed;
                }
                $depth--;

                continue;
            }

            if ($id === T_NAMESPACE) {
                $name = '';
                $j = $i + 1;
                while ($j < $count && in_array($tokens[$j]['id'], [T_STRING, T_NAME_QUALIFIED], true)) {
                    $name .= $tokens[$j]['text'];
                    $j++;
                }
                $namespaceSegments[] = ['start' => $i, 'namespace' => trim($name, '\\'), 'uses' => []];
                for ($k = $i + 1; $k < $j; $k++) {
                    $depths[$k] = $depth;
                }
                $i = $j - 1;

                continue;
            }

            if ($id === T_USE) {
                $isClosureUse = isset($tokens[$i + 1]) && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(';
                if ($isClosureUse) {
                    continue;
                }
                if ($openTypes !== []) {
                    // 型の本体に書かれた `use` = trait の取り込み (v1 では追跡しない)
                    $openTypes[count($openTypes) - 1]['usesTraits'] = true;

                    continue;
                }
                $i = self::parseImport($tokens, $i, $namespaceSegments);

                continue;
            }

            if (in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                if ($i > 0 && $tokens[$i - 1]['id'] === T_DOUBLE_COLON) {
                    continue; // `Foo::class`
                }
                if (! isset($tokens[$i + 1]) || $tokens[$i + 1]['id'] !== T_STRING) {
                    continue; // 無名クラス
                }
                $name = $tokens[$i + 1]['text'];
                $namespace = $namespaceSegments[count($namespaceSegments) - 1]['namespace'];
                $parent = self::readExtends($tokens, $i + 2);
                $pendingType = [
                    'start' => $i,
                    'end' => 0,
                    'bodyDepth' => 0,
                    'fqcn' => $namespace === '' ? $name : $namespace.'\\'.$name,
                    'isTrait' => $id === T_TRAIT,
                    'parentRaw' => $parent['raw'],
                    'parentId' => $parent['id'],
                    'usesTraits' => false,
                ];
            }
        }

        // 閉じ括弧が足りない (構文検証済みなら起きない) 場合も型区間を捨てない
        foreach (array_reverse($openTypes) as $open) {
            $open['end'] = $count - 1;
            $typeSegments[] = $open;
        }

        // `use` 文などで読み飛ばした位置にも深さを埋める (未記録の位置を残さない)
        $current = 0;
        for ($i = 0; $i < $count; $i++) {
            if (isset($depths[$i])) {
                $current = $depths[$i];

                continue;
            }
            $depths[$i] = $current;
        }
        ksort($depths);

        return new self($namespaceSegments, $typeSegments, array_values($depths));
    }

    /**
     * 位置 `$index` の波括弧の深さ (その位置に入った時点の値)。
     *
     * ★型の本体の直下 (メソッド宣言の位置) は `TypeSegment['bodyDepth']` と一致する。
     *   メソッドの中で宣言された名前付き関数や、型の中に置いた無名クラスのメソッドは
     *   これより深くなるので、宣言の判定に使うと誤検出を落とせる。
     */
    public function depthAt(int $index): int
    {
        return $this->depths[$index] ?? 0;
    }

    /**
     * 位置 `$index` を囲む型 (最も内側)。
     *
     * @return TypeSegment|null
     */
    public function typeAt(int $index): ?array
    {
        $innermost = null;
        foreach ($this->typeSegments as $segment) {
            if ($segment['start'] <= $index && $index <= $segment['end']) {
                if ($innermost === null || $segment['start'] > $innermost['start']) {
                    $innermost = $segment;
                }
            }
        }

        return $innermost;
    }

    /**
     * 対象の完全修飾名を持つ型の宣言 (大小無視)。
     *
     * @return list<TypeSegment>
     */
    public function typeDeclarationsOf(string $fqcn): array
    {
        $needle = strtolower(ltrim($fqcn, '\\'));

        return array_values(array_filter(
            $this->typeSegments,
            static fn (array $segment): bool => strtolower($segment['fqcn']) === $needle,
        ));
    }

    /**
     * 参照位置のトークンから完全修飾名を解決する。**解決できない形は null**。
     *
     * @param  list<NormalizedToken>  $tokens
     */
    public function resolveClassReference(array $tokens, int $index): ?string
    {
        if (! isset($tokens[$index])) {
            return null;
        }
        $id = $tokens[$index]['id'];
        $text = $tokens[$index]['text'];
        $lower = strtolower($text);

        if ($id === T_STATIC || ($id === T_STRING && ($lower === 'static' || $lower === 'self'))) {
            $type = $this->typeAt($index);
            if ($type === null || $type['isTrait']) {
                return null;
            }

            return $type['fqcn'];
        }

        if ($id === T_STRING && $lower === 'parent') {
            $type = $this->typeAt($index);
            if ($type === null || $type['isTrait'] || $type['parentRaw'] === null) {
                return null;
            }

            return $this->resolveRawName($type['parentRaw'], $type['parentId'], $index);
        }

        if (in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return $this->resolveRawName($text, $id, $index);
        }

        return null;
    }

    /** 名前の原文を、位置 `$index` の namespace と取り込み表で解決する。 */
    private function resolveRawName(string $raw, ?int $id, int $index): ?string
    {
        $namespace = $this->namespaceAt($index);
        $uses = $this->usesAt($index);

        if ($id === T_NAME_FULLY_QUALIFIED) {
            return ltrim($raw, '\\');
        }

        if ($id === T_NAME_RELATIVE) {
            $rest = ltrim(substr($raw, strlen('namespace')), '\\');

            return $namespace === '' ? $rest : $namespace.'\\'.$rest;
        }

        if ($id === T_NAME_QUALIFIED) {
            $parts = explode('\\', $raw);
            $first = strtolower($parts[0]);
            if (isset($uses[$first])) {
                array_shift($parts);

                return $parts === [] ? $uses[$first] : $uses[$first].'\\'.implode('\\', $parts);
            }

            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
        }

        if ($id === T_STRING) {
            $lower = strtolower($raw);
            if (isset($uses[$lower])) {
                return $uses[$lower];
            }

            return $namespace === '' ? $raw : $namespace.'\\'.$raw;
        }

        return null;
    }

    /** 位置 `$index` の namespace。 */
    private function namespaceAt(int $index): string
    {
        return $this->segmentAt($index)['namespace'];
    }

    /**
     * 位置 `$index` の取り込み表 (別名を小文字化したキー => 完全修飾名)。
     *
     * @return array<string, string>
     */
    private function usesAt(int $index): array
    {
        return $this->segmentAt($index)['uses'];
    }

    /** @return NamespaceSegment */
    private function segmentAt(int $index): array
    {
        $current = $this->namespaceSegments[0];
        foreach ($this->namespaceSegments as $segment) {
            if ($segment['start'] <= $index) {
                $current = $segment;
            }
        }

        return $current;
    }

    /**
     * `use` 文 (group use を含む) を取り込み表へ登録し、文末のトークン位置を返す。
     *
     * @param  list<NormalizedToken>  $tokens
     * @param  list<NamespaceSegment>  $segments
     */
    private static function parseImport(array $tokens, int $useIndex, array &$segments): int
    {
        $count = count($tokens);
        $j = $useIndex + 1;

        if (isset($tokens[$j]) && in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
            // `use function` / `use const` はクラス参照ではないので取り込み表に入れない
            return self::skipToStatementEnd($tokens, $j);
        }

        $segmentIndex = count($segments) - 1;

        while ($j < $count) {
            $name = self::readName($tokens, $j);
            if ($name === null) {
                break;
            }
            $j = $name['next'];

            // group use は `T_NAME_QUALIFIED` + `T_NS_SEPARATOR` + `{` の 3 トークンで始まる
            $isGroupUse = isset($tokens[$j], $tokens[$j + 1])
                && $tokens[$j]['id'] === T_NS_SEPARATOR
                && $tokens[$j + 1]['id'] === null
                && $tokens[$j + 1]['text'] === '{';

            if ($isGroupUse) {
                // group use: `use A\B\{C, D as E};` と混合形 `use A\B\{function f, const C, D};`
                $prefix = rtrim($name['text'], '\\');
                $j += 2;
                while ($j < $count) {
                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === '}') {
                        $j++;
                        break;
                    }
                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
                        $j++;

                        continue;
                    }
                    // ★要素ごとの種別を保持する。PHP は関数・定数とクラスの取り込み空間が別なので、
                    //   `function` / `const` の要素は**その要素ごと**クラスの取り込み表へ入れない
                    //   (印だけ読み飛ばすと後続の名前をクラスとして誤登録し、同名の対象クラス参照を
                    //   別 namespace へ誤解決して見逃す)。
                    $isClassImport = true;
                    if (in_array($tokens[$j]['id'], [T_FUNCTION, T_CONST], true)) {
                        $isClassImport = false;
                        $j++;
                    }
                    $item = self::readName($tokens, $j);
                    if ($item === null) {
                        $j++;

                        continue;
                    }
                    $j = $item['next'];
                    $alias = self::readAlias($tokens, $j);
                    $j = $alias['next'];
                    if (! $isClassImport) {
                        continue;
                    }
                    $fqcn = $prefix.'\\'.ltrim($item['text'], '\\');
                    $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;
                }

                return self::skipToStatementEnd($tokens, $j);
            }

            $alias = self::readAlias($tokens, $j);
            $j = $alias['next'];
            $fqcn = ltrim($name['text'], '\\');
            $segments[$segmentIndex]['uses'][strtolower($alias['name'] ?? self::shortName($fqcn))] = $fqcn;

            if (isset($tokens[$j]) && $tokens[$j]['id'] === null && $tokens[$j]['text'] === ',') {
                $j++;

                continue;
            }
            break;
        }

        return self::skipToStatementEnd($tokens, $j);
    }

    /**
     * `extends` の名前を読む (`{` の手前まで)。
     *
     * @param  list<NormalizedToken>  $tokens
     * @return array{raw: string|null, id: int|null}
     */
    private static function readExtends(array $tokens, int $from): array
    {
        $count = count($tokens);
        for ($k = $from; $k < $count; $k++) {
            if (self::isOpeningBrace($tokens[$k]['id'], $tokens[$k]['text'])) {
                break;
            }
            if ($tokens[$k]['id'] === T_EXTENDS) {
                $name = self::readName($tokens, $k + 1);
                if ($name === null) {
                    break;
                }

                return ['raw' => $name['text'], 'id' => $name['id']];
            }
        }

        return ['raw' => null, 'id' => null];
    }

    /**
     * 名前トークンを 1 つ読む。
     *
     * @param  list<NormalizedToken>  $tokens
     * @return array{text: string, id: int, next: int}|null
     */
    private static function readName(array $tokens, int $index): ?array
    {
        if (! isset($tokens[$index])) {
            return null;
        }
        $id = $tokens[$index]['id'];
        if (! in_array($id, [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return null;
        }

        /** @var int $id */
        return ['text' => $tokens[$index]['text'], 'id' => $id, 'next' => $index + 1];
    }

    /**
     * `as X` を読む (無ければ name = null)。
     *
     * @param  list<NormalizedToken>  $tokens
     * @return array{name: string|null, next: int}
     */
    private static function readAlias(array $tokens, int $index): array
    {
        if (isset($tokens[$index], $tokens[$index + 1])
            && $tokens[$index]['id'] === T_AS
            && $tokens[$index + 1]['id'] === T_STRING) {
            return ['name' => $tokens[$index + 1]['text'], 'next' => $index + 2];
        }

        return ['name' => null, 'next' => $index];
    }

    /** 完全修飾名の短名。 */
    private static function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    /**
     * `;` までスキップする (その位置を返す)。
     *
     * @param  list<NormalizedToken>  $tokens
     */
    private static function skipToStatementEnd(array $tokens, int $from): int
    {
        $count = count($tokens);
        for ($k = $from; $k < $count; $k++) {
            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === ';') {
                return $k;
            }
        }

        return $count - 1;
    }

    /**
     * 開き波括弧か (文字列補間が開く `{` を含める。閉じは素の `}` なので数が合う)。
     */
    private static function isOpeningBrace(?int $id, string $text): bool
    {
        if ($id === null) {
            return $text === '{';
        }

        return in_array($id, [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true);
    }
}
