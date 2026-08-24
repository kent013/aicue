<?php

declare(strict_types=1);

namespace Tests\Support\RawEnv;

use Tests\Support\PhpTokenScan;

/**
 * 生の環境変数 3 面 (`$_SERVER` / `$_ENV` / `putenv`) への**直接の書き込み**を
 * 字句走査で列挙する純関数 (`RawEnvDirectWriteGateTest` の検出器)。
 *
 * 走査は既存の `Tests\Support\PhpTokenScan::normalize()` (空白 / コメント / DocComment を
 * 除いた添字連番のリスト) の上で行う。**同じ正規化を 2 本持たない**。
 *
 * ── 検出する形 ──────────────────────────────────────────────────────
 *
 *  | 分類 | 形 |
 *  |---|---|
 *  | `element_assign` | 面の要素への代入 (通常 / 複合 / `??=` / 前後置インクリメント / 多段添字) |
 *  | `element_unset` | 面の要素の削除 (`unset()` の引数の**根**にある面) |
 *  | `whole_assign` | 面そのものへの代入 (複合代入を含む) |
 *  | `reference_taken` | 面 / 面の要素への参照の取得 |
 *  | `destructuring_target` | 分割代入の左辺の**根**に面が現れる形 (連想の値の側を含む。鍵の側は読み出し) |
 *  | `putenv` | プロセス面への書き込み (両形 / 完全修飾 / 別名つき取り込み) |
 *  | `unresolved` | 上のどれにも分類できなかった出現 (**必ず違反**) |
 *
 * ── 関数名の解決 (AGENTS.md 走査器共通規約 (a)) ──────────────────────────
 *
 *  `putenv` は**完全修飾名で突き合わせる**。短名一致は使わない (別名つき取り込み 1 つで
 *  検査が黙るため)。取り込み対応表 (別名・group use を含む) は**名前空間の領域ごと**に持ち、
 *  呼び出しの位置に対応する領域で解決する (ファイルに 1 つにすると、同じ別名を別の完全修飾名へ
 *  向ける 2 つ目の名前空間が 1 つ目の対応表を上書きし、1 つ目の呼び出しが黙って見逃される)。
 *  そのうえで、
 *  裸の呼び出し (名前空間の中でもグローバルへ fallback する) / 完全修飾 /
 *  別名を解いた結果が `\putenv` になる呼び出しを検出する。
 *  `T_NAME_RELATIVE` (`namespace\putenv`) は**グローバル名前空間のときだけ**一致する。
 *
 *  **fail-closed**: `use function` の取り込みを完全修飾名へ解けない形 /
 *  1 ファイルに `namespace` 宣言が 2 つ以上ある / 波括弧つき `namespace { … }` を使っている /
 *  そのファイルが自分で `putenv` という名前の関数を宣言している
 *  → そのファイルの `putenv` 相当の出現をすべて `unresolved` にする。
 *
 * ── 保証しないもの (誇張しない。ここが正本) ────────────────────────────────
 *
 *  - **可変関数呼び出し** (`$fn = 'putenv'; $fn('K=V');`) と
 *    **`call_user_func` 等の間接呼び出し** (`call_user_func('putenv', …)`)
 *  - 名前を実行時に解決する書き込み (可変変数 / `extract()` / 文字列から呼び出す形)
 *  - 面を**値渡しで受けた関数**が内部で書き換える形 (`foo($_SERVER)` の呼び先)
 *  - `Dotenv` のような**ライブラリ経由の間接的な書き込み**
 *  - ヒアドキュメント / ナウドキュメントの本文 (`token_get_all()` からは 1 トークンに見える。
 *    **実測で確認済み**であり、走査器の自己検査が負例をナウドキュメントで持てる理由でもある)
 *  - 文字列リテラル・コメントの中の綴り
 *  - 走査根から外した置き場 (`devnotes/` 配下。除外の管理は gate 側の責務)
 *
 *  **したがってこの検出器を使う gate の主張は「部品の外に 3 面への直接の書き込みが 1 件も無い」
 *  ではなく、「上に列挙した字句の書き込み形が、許可した置き場以外に 1 件も無い」である。**
 *
 * > **監視条件**: 可変関数呼び出しや `call_user_func` で 3 面へ書く形が実際に現れたら、
 * > **目録へ登録するのではなく検出規則を足す**。
 * > (文字列リテラル `'putenv'` を一律に未解決とする案は採らない — 走査器自身と
 * >  `RawEnvWriteKind` がその文字列を持つため、許可を増やさない限り設計が自分自身を
 * >  違反にしてしまう。)
 *
 * ★**母集団の非空は契約しない**。空入力でも例外にせず 0 件を返す
 *   (非空を要求するのは検出器を**使う側**の gate である)。
 */
final class RawEnvDirectWriteScanner
{
    /** 走査対象の面 (変数として現れる 2 面)。 */
    private const array SURFACE_VARIABLES = ['$_SERVER', '$_ENV'];

    /** 代入系の演算子 (単一文字の `=` は id が null なので別に見る)。 */
    private const array ASSIGNMENT_TOKEN_IDS = [
        T_CONCAT_EQUAL, T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL,
        T_MOD_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL, T_OR_EQUAL, T_AND_EQUAL,
        T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL,
    ];

    /** 呼び出しではない (メソッド / 宣言 / 定数) ことを示す直前のトークン。 */
    private const array NON_CALL_PREFIX_TOKEN_IDS = [
        T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW, T_CONST,
    ];

    /** インスタンス化しない (純関数の置き場)。 */
    private function __construct() {}

    /**
     * PHP ソース 1 本を走査し、3 面への書き込み (と未解決) をすべて返す。
     *
     * @return list<RawEnvWriteSite>
     */
    public static function scan(string $phpSource): array
    {
        $tokens = PhpTokenScan::normalize($phpSource);

        if ($tokens === []) {
            return [];
        }

        $pairs = self::bracketPairs($tokens);
        $enclosingParen = self::enclosingParens($tokens);
        $context = self::analyseFileContext($tokens, $pairs);
        $destructuring = self::destructuringRanges($tokens, $pairs);
        $unsetRanges = self::unsetRanges($tokens, $pairs);

        /** @var array<int, RawEnvWriteSite> $sites */
        $sites = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_VARIABLE && in_array($token['text'], self::SURFACE_VARIABLES, true)) {
                $kind = self::classifySurface($tokens, $pairs, $enclosingParen, $destructuring, $unsetRanges, $index);

                if ($kind !== null) {
                    $sites[$index] = new RawEnvWriteSite($kind, $token['text'], $token['line']);
                }

                continue;
            }

            $kind = self::classifyFunctionCall($tokens, $context, $index);

            if ($kind !== null) {
                $sites[$index] = new RawEnvWriteSite($kind, $token['text'], $token['line']);
            }
        }

        ksort($sites);

        return array_values($sites);
    }

    /**
     * 名前解決の文脈を**名前空間の領域ごとに**組み立てる。
     *
     * ★取り込み対応表はファイルに 1 つではなく**領域ごと**に持つ。ファイル全体で 1 つにすると、
     *   同じ別名を別の完全修飾名へ向ける 2 つ目の名前空間が 1 つ目の対応表を上書きし、
     *   1 つ目の `putenv` 別名呼び出しが候補から外れて**黙って見逃される** (fail-open)。
     * ★`putenvAliasKeys` は**どこかの領域で `\putenv` を指した**別名の集合である
     *   (上書きされて最終的に別の関数を指すものも残す)。解決不能なファイルで
     *   「`putenv` 相当の出現」を数える母集団に使う — 最終的な対応表だけを見ると上と同じ穴が開く。
     *   **`putenv` を 1 度も指さなかった別名は入れない** (入れると、無関係な別名関数の呼び出しまで
     *   未解決として違反になる)。
     * ★ただし `use function` の取り込み自体が解けなかったときは、どの別名が `putenv` を指したかが
     *   分からない。そのときだけ `aliasKeys` (全別名) を母集団に使う (fail-closed)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @return array{
     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
     *     putenvAliasKeys: array<string, true>,
     *     aliasKeys: array<string, true>,
     *     importParseFailed: bool,
     *     unresolved: bool,
     * }
     */
    private static function analyseFileContext(array $tokens, array $pairs): array
    {
        $count = count($tokens);
        $declarations = [];
        $braced = false;

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_NAMESPACE) {
                continue;
            }

            $cursor = $index + 1;
            $name = '';

            while ($cursor < $count && self::isNamePart($tokens[$cursor])) {
                $name .= $tokens[$cursor]['text'];
                $cursor++;
            }

            $end = $count - 1;

            if ($cursor < $count && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '{') {
                $braced = true;
            }

            $declarations[] = ['start' => $index, 'end' => $end, 'namespace' => trim($name, '\\')];
        }

        // 領域の終端は次の宣言の直前 (波括弧つきでも、走査の目的では同じ区切りで足りる)。
        foreach ($declarations as $position => $declaration) {
            if (isset($declarations[$position + 1])) {
                $declarations[$position]['end'] = $declarations[$position + 1]['start'] - 1;
            }
        }

        if ($declarations === []) {
            $declarations[] = ['start' => 0, 'end' => $count - 1, 'namespace' => ''];
        }

        $unresolved = count($declarations) >= 2 || $braced;
        $importParseFailed = false;
        $regions = [];
        $aliasKeys = [];
        $putenvAliasKeys = [];

        foreach ($declarations as $declaration) {
            $aliases = [];

            for ($i = $declaration['start']; $i + 1 <= $declaration['end']; $i++) {
                if ($tokens[$i]['id'] !== T_USE || $tokens[$i + 1]['id'] !== T_FUNCTION) {
                    continue;
                }

                $statement = [];

                for ($j = $i + 2; $j <= $declaration['end']; $j++) {
                    if ($tokens[$j]['id'] === null && $tokens[$j]['text'] === ';') {
                        break;
                    }

                    $statement[] = $tokens[$j];
                }

                if (! self::collectFunctionImports($statement, $aliases)) {
                    $unresolved = true;
                    $importParseFailed = true;
                }

                // 上書きされる前に「`\putenv` を指した別名」を控える。
                foreach ($aliases as $alias => $fullyQualified) {
                    $aliasKeys[$alias] = true;

                    if (strtolower($fullyQualified) === 'putenv') {
                        $putenvAliasKeys[$alias] = true;
                    }
                }
            }

            foreach (array_keys($aliases) as $alias) {
                $aliasKeys[$alias] = true;
            }

            $regions[] = [
                'start' => $declaration['start'],
                'end' => $declaration['end'],
                'namespace' => $declaration['namespace'],
                'aliases' => $aliases,
            ];
        }

        // そのファイル自身が `putenv` という名前の関数を宣言していたら、非修飾の呼び出しは
        // ローカル関数を指しうるので解決できない (fail-closed)。
        for ($i = 0; $i + 1 < $count; $i++) {
            if ($tokens[$i]['id'] !== T_FUNCTION) {
                continue;
            }

            if ($i > 0 && $tokens[$i - 1]['id'] === T_USE) {
                continue;
            }

            if ($tokens[$i + 1]['id'] === T_STRING && strtolower($tokens[$i + 1]['text']) === 'putenv') {
                $unresolved = true;
            }
        }

        return [
            'regions' => $regions,
            'putenvAliasKeys' => $putenvAliasKeys,
            'aliasKeys' => $aliasKeys,
            'importParseFailed' => $importParseFailed,
            'unresolved' => $unresolved,
        ];
    }

    /**
     * `use function …;` 1 文を対応表へ展開する (解けなければ false)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $statement
     * @param  array<string, string>  $aliases
     */
    private static function collectFunctionImports(array $statement, array &$aliases): bool
    {
        $prefix = '';
        $body = $statement;

        foreach ($statement as $position => $token) {
            if ($token['id'] === null && $token['text'] === '{') {
                $prefix = '';

                foreach (array_slice($statement, 0, $position) as $prefixToken) {
                    $prefix .= $prefixToken['text'];
                }

                $body = [];

                foreach (array_slice($statement, $position + 1) as $bodyToken) {
                    if ($bodyToken['id'] === null && $bodyToken['text'] === '}') {
                        break;
                    }

                    $body[] = $bodyToken;
                }

                break;
            }
        }

        $entries = [[]];

        foreach ($body as $token) {
            if ($token['id'] === null && $token['text'] === ',') {
                $entries[] = [];

                continue;
            }

            $entries[count($entries) - 1][] = $token;
        }

        $resolved = true;

        foreach ($entries as $entry) {
            if ($entry === []) {
                continue;
            }

            $alias = null;
            $nameTokens = $entry;
            $entryCount = count($entry);

            if ($entryCount >= 3 && $entry[$entryCount - 2]['id'] === T_AS) {
                $alias = $entry[$entryCount - 1]['text'];
                $nameTokens = array_slice($entry, 0, $entryCount - 2);
            }

            $name = '';

            foreach ($nameTokens as $nameToken) {
                if (! self::isNamePart($nameToken)) {
                    $resolved = false;

                    break 2;
                }

                $name .= $nameToken['text'];
            }

            $fullyQualified = trim($prefix.$name, '\\');

            if ($fullyQualified === '') {
                $resolved = false;

                break;
            }

            $segments = explode('\\', $fullyQualified);
            $alias ??= $segments[count($segments) - 1];
            $aliases[strtolower($alias)] = $fullyQualified;
        }

        return $resolved;
    }

    /**
     * 関数呼び出しの位置が `\putenv` を指すか (指せないなら未解決)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array{
     *     regions: list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>,
     *     putenvAliasKeys: array<string, true>,
     *     aliasKeys: array<string, true>,
     *     importParseFailed: bool,
     *     unresolved: bool,
     * }  $context
     */
    private static function classifyFunctionCall(array $tokens, array $context, int $index): ?RawEnvWriteKind
    {
        $token = $tokens[$index];

        if (! in_array($token['id'], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE], true)) {
            return null;
        }

        $next = $tokens[$index + 1] ?? null;

        if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
            return null;
        }

        $previous = $index > 0 ? $tokens[$index - 1] : null;

        if ($previous !== null && in_array($previous['id'], self::NON_CALL_PREFIX_TOKEN_IDS, true)) {
            return null;
        }

        $lowered = strtolower($token['text']);
        $segments = explode('\\', trim($lowered, '\\'));
        $lastSegment = $segments[count($segments) - 1];

        // `putenv` 相当の綴りを持つ呼び出しかどうか (未解決の判定にも使う母集団)。
        // 別名は「**どこかの領域で `\putenv` を指した**もの」で数える (最終的な対応表だけを見ると、
        // 2 つ目の名前空間の取り込みが 1 つ目を隠して見逃しになる)。
        // 取り込みそのものが解けなかったときだけ、全別名へ広げる (fail-closed)。
        $aliasPopulation = $context['importParseFailed'] ? $context['aliasKeys'] : $context['putenvAliasKeys'];

        $isCandidate = $lastSegment === 'putenv'
            || ($token['id'] === T_STRING && isset($aliasPopulation[$lowered]));

        if (! $isCandidate) {
            return null;
        }

        if ($context['unresolved']) {
            return RawEnvWriteKind::Unresolved;
        }

        $region = self::regionAt($context['regions'], $index);

        if ($region === null) {
            return RawEnvWriteKind::Unresolved;
        }

        return match ($token['id']) {
            T_NAME_FULLY_QUALIFIED => trim($lowered, '\\') === 'putenv' ? RawEnvWriteKind::Putenv : null,
            T_NAME_RELATIVE => $region['namespace'] === '' ? RawEnvWriteKind::Putenv : null,
            T_NAME_QUALIFIED => null,
            default => self::classifyUnqualifiedCall($region['aliases'], $lowered),
        };
    }

    /**
     * その添字を含む名前空間の領域。
     *
     * @param  list<array{start: int, end: int, namespace: string, aliases: array<string, string>}>  $regions
     * @return array{start: int, end: int, namespace: string, aliases: array<string, string>}|null
     */
    private static function regionAt(array $regions, int $index): ?array
    {
        foreach ($regions as $region) {
            if ($index >= $region['start'] && $index <= $region['end']) {
                return $region;
            }
        }

        return null;
    }

    /**
     * 非修飾の呼び出しを、**その領域の**取り込み対応表とグローバル fallback で解決する。
     *
     * @param  array<string, string>  $aliases
     */
    private static function classifyUnqualifiedCall(array $aliases, string $lowered): ?RawEnvWriteKind
    {
        if (isset($aliases[$lowered])) {
            return strtolower($aliases[$lowered]) === 'putenv' ? RawEnvWriteKind::Putenv : null;
        }

        // 名前空間の中でも、非修飾の関数呼び出しはグローバルへ fallback する。
        return $lowered === 'putenv' ? RawEnvWriteKind::Putenv : null;
    }

    /**
     * 面の変数 1 件を分類する (読み出しなら null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @param  array<int, int>  $enclosingParen
     * @param  list<array{int, int}>  $destructuring
     * @param  list<array{int, int}>  $unsetRanges
     */
    private static function classifySurface(
        array $tokens,
        array $pairs,
        array $enclosingParen,
        array $destructuring,
        array $unsetRanges,
        int $index,
    ): ?RawEnvWriteKind {
        $previous = $index > 0 ? $tokens[$index - 1] : null;
        $next = $tokens[$index + 1] ?? null;
        $atArgumentHead = $previous !== null
            && $previous['id'] === null
            && in_array($previous['text'], ['(', ','], true);
        $byReference = $previous !== null && self::isReferenceSign($previous);

        foreach ($destructuring as $range) {
            if ($index <= $range[0] || $index >= $range[1]) {
                continue;
            }

            // 範囲に入っただけでは書き込みにしない。lvalue の根にあるときだけ対象にする。
            if (! self::isDestructuringTargetRoot($tokens, $pairs, $range, $index)) {
                return null;
            }

            return $byReference ? RawEnvWriteKind::ReferenceTaken : RawEnvWriteKind::DestructuringTarget;
        }

        if ($byReference) {
            return RawEnvWriteKind::ReferenceTaken;
        }

        foreach ($unsetRanges as $range) {
            if ($index > $range[0] && $index < $range[1]
                && $atArgumentHead
                && ($enclosingParen[$index] ?? null) === $range[0]
            ) {
                return RawEnvWriteKind::ElementUnset;
            }
        }

        if ($next !== null && $next['id'] === null && $next['text'] === '[') {
            $cursor = $index + 1;

            while (isset($tokens[$cursor]) && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '[') {
                if (! isset($pairs[$cursor])) {
                    return RawEnvWriteKind::Unresolved;
                }

                $cursor = $pairs[$cursor] + 1;
            }

            $after = $tokens[$cursor] ?? null;

            if ($after !== null && (self::isAssignmentOperator($after) || in_array($after['id'], [T_INC, T_DEC], true))) {
                return RawEnvWriteKind::ElementAssign;
            }

            if ($previous !== null && in_array($previous['id'], [T_INC, T_DEC], true)) {
                return RawEnvWriteKind::ElementAssign;
            }

            return null;
        }

        if ($next !== null && self::isAssignmentOperator($next)) {
            return RawEnvWriteKind::WholeAssign;
        }

        if ($next !== null && $next['id'] === T_AS) {
            return null;
        }

        if ($atArgumentHead
            && $next !== null
            && $next['id'] === null
            && in_array($next['text'], [')', ','], true)
        ) {
            return null;
        }

        return RawEnvWriteKind::Unresolved;
    }

    /**
     * 分割代入の範囲の中で、その面が**代入先の根**にあるか。
     *
     * 満たすべきは 3 つである:
     *
     *  1. 要素の先頭位置にあること。先頭とは `[` / `(` / `,` / `=>` の直後、または
     *     **参照記号を挟んだその直後** (`[&$_ENV['K']] = $v;`) である
     *  2. 範囲の根との間に**添字の括弧が 1 つも無い**こと
     *     (`[$other[$_SERVER['K']]] = $v;` の `$_SERVER` は添字を求める読み出しである)
     *  3. 添字の連鎖の**直後が `=>` でない**こと
     *     (`[$_SERVER['K'] => $v] = $x;` の `$_SERVER` は連想の**鍵**であって代入先ではない)
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @param  array{int, int}  $range
     */
    private static function isDestructuringTargetRoot(array $tokens, array $pairs, array $range, int $index): bool
    {
        $head = $index - 1;

        if ($head >= 0 && self::isReferenceSign($tokens[$head])) {
            $head--;
        }

        if ($head < 0 || $tokens[$head]['id'] !== null || ! in_array($tokens[$head]['text'], ['[', '(', ','], true)) {
            if ($head < 0 || $tokens[$head]['id'] !== T_DOUBLE_ARROW) {
                return false;
            }
        }

        if (self::isInsideIndexBracket($tokens, $pairs, $range, $index)) {
            return false;
        }

        $cursor = $index + 1;

        while (isset($tokens[$cursor]) && $tokens[$cursor]['id'] === null && $tokens[$cursor]['text'] === '[') {
            if (! isset($pairs[$cursor])) {
                return false;
            }

            $cursor = $pairs[$cursor] + 1;
        }

        return ! isset($tokens[$cursor]) || $tokens[$cursor]['id'] !== T_DOUBLE_ARROW;
    }

    /**
     * 参照記号か (PHP 8.1 以降は変数の前の `&` が専用トークンになる)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function isReferenceSign(array $token): bool
    {
        if ($token['id'] === T_AMPERSAND_FOLLOWED_BY_VAR_OR_VARARG) {
            return true;
        }

        return $token['id'] === null && $token['text'] === '&';
    }

    /**
     * 面が分割代入の範囲の中で「添字の括弧」に囲まれているか (囲まれていれば読み出し)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @param  array{int, int}  $range
     */
    private static function isInsideIndexBracket(array $tokens, array $pairs, array $range, int $index): bool
    {
        for ($i = $range[0] + 1; $i < $index; $i++) {
            if (! self::isIndexBracket($tokens, $i)) {
                continue;
            }

            $close = $pairs[$i] ?? null;

            if ($close !== null && $close > $index) {
                return true;
            }
        }

        return false;
    }

    /**
     * 分割代入の対象範囲 (パターンの括弧 … その直後が代入記号)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @return list<array{int, int}>
     */
    private static function destructuringRanges(array $tokens, array $pairs): array
    {
        $ranges = [];

        foreach ($tokens as $index => $token) {
            $open = null;

            if ($token['id'] === null && $token['text'] === '[' && ! self::isIndexBracket($tokens, $index)) {
                $open = $index;
            } elseif ($token['id'] === T_LIST) {
                $candidate = $index + 1;

                if (isset($tokens[$candidate]) && $tokens[$candidate]['id'] === null && $tokens[$candidate]['text'] === '(') {
                    $open = $candidate;
                }
            }

            if ($open === null || ! isset($pairs[$open])) {
                continue;
            }

            $after = $tokens[$pairs[$open] + 1] ?? null;

            if ($after !== null && $after['id'] === null && $after['text'] === '=') {
                $ranges[] = [$open, $pairs[$open]];
            }
        }

        return $ranges;
    }

    /**
     * `unset(` の引数リストの範囲。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, int>  $pairs
     * @return list<array{int, int}>
     */
    private static function unsetRanges(array $tokens, array $pairs): array
    {
        $ranges = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] !== T_UNSET) {
                continue;
            }

            $open = $index + 1;

            if (isset($tokens[$open], $pairs[$open]) && $tokens[$open]['id'] === null && $tokens[$open]['text'] === '(') {
                $ranges[] = [$open, $pairs[$open]];
            }
        }

        return $ranges;
    }

    /**
     * 丸括弧・角括弧の対応表 (開きの添字 => 閉じの添字)。対応の取れない出現は載せない。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array<int, int>
     */
    private static function bracketPairs(array $tokens): array
    {
        /** @var list<array{char: string, index: int}> $stack */
        $stack = [];
        $pairs = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] === T_ATTRIBUTE) {
                $stack[] = ['char' => ']', 'index' => $index];

                continue;
            }

            if ($token['id'] !== null) {
                continue;
            }

            if ($token['text'] === '(') {
                $stack[] = ['char' => ')', 'index' => $index];
            } elseif ($token['text'] === '[') {
                $stack[] = ['char' => ']', 'index' => $index];
            } elseif (in_array($token['text'], [')', ']'], true)) {
                $top = array_pop($stack);

                if ($top !== null && $top['char'] === $token['text']) {
                    $pairs[$top['index']] = $index;
                }
            }
        }

        return $pairs;
    }

    /**
     * 各トークンを直接囲んでいる開き丸括弧の添字。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array<int, int>
     */
    private static function enclosingParens(array $tokens): array
    {
        /** @var list<int> $stack */
        $stack = [];
        $enclosing = [];

        foreach ($tokens as $index => $token) {
            if ($token['id'] === null && $token['text'] === ')') {
                array_pop($stack);
            }

            if ($stack !== []) {
                $enclosing[$index] = $stack[count($stack) - 1];
            }

            if ($token['id'] === null && $token['text'] === '(') {
                $stack[] = $index;
            }
        }

        return $enclosing;
    }

    /**
     * その `[` が「添字の括弧」か (直前が変数 / `]` / `)` なら添字、それ以外はパターン)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isIndexBracket(array $tokens, int $index): bool
    {
        $token = $tokens[$index];

        if ($token['id'] !== null || $token['text'] !== '[') {
            return false;
        }

        $previous = $index > 0 ? $tokens[$index - 1] : null;

        if ($previous === null) {
            return false;
        }

        if ($previous['id'] === T_VARIABLE || $previous['id'] === T_STRING) {
            return true;
        }

        return $previous['id'] === null && in_array($previous['text'], [']', ')'], true);
    }

    /**
     * 代入系の演算子か (単一文字の `=` は id が null なので別に見る)。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function isAssignmentOperator(array $token): bool
    {
        if ($token['id'] === null) {
            return $token['text'] === '=';
        }

        return in_array($token['id'], self::ASSIGNMENT_TOKEN_IDS, true);
    }

    /**
     * 名前の一部として扱うトークンか。
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     */
    private static function isNamePart(array $token): bool
    {
        return in_array(
            $token['id'],
            [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR],
            true,
        );
    }
}
