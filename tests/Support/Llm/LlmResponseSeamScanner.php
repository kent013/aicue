<?php

declare(strict_types=1);

namespace Tests\Support\Llm;

use Tests\Support\PhpReferenceScanner;
use Tests\Support\ReferenceKind;
use Tests\Support\ReferenceSite;

/**
 * 「LLM 応答が app/ に入る点」を列挙する走査器 (純関数)。
 *
 * `tests/Architecture/LlmResponseDecodePointGateTest.php` の 8 検査のうち、
 * ソースを読む必要のある 5 つ (受け取り口の分類 / 応答の流れ / `GuardedPrompt` の参照者 /
 * 復号語彙の不在 / 受け取り関数の中の流れ) を提供する。
 *
 * ## 走査対象
 *
 * - `executeSyncSites()`: `$x->executeSync(` の**メソッド呼び出し**すべて
 *   (母集団はメソッド名で採る = 拾いすぎる方向にだけ倒れる)。
 * - `decodeVocabularyViolations()`: 関数呼び出しとして解決される `json_decode` と、
 *   逆引用符 3 連を含む**文字列リテラル**。
 * - `referencesGuardedPrompt()`: `App\Support\Llm\GuardedPrompt` の参照 (import を含む)。
 * - `receiverFlowViolations()`: 登録済みの受け取り関数の中で、生の応答文字列が
 *   復号点へ**直接 1 回だけ**渡ることの検査。
 *
 * ## 名前解決 (共通規約 (a))
 *
 * クラス名の解決は `PhpReferenceScanner` に委譲する (`use` / group use / 別名 /
 * 部分修飾を解いた完全修飾名)。**同じ解決を 2 本持たない**。
 * 関数名は本クラスが `use function` の別名表を作って解決し、
 * **解決後の完全修飾名が global の `json_decode`** のものだけを違反にする
 * (`use function Foo\{json_decode as decodeJson};` は `Foo\json_decode` なので違反ではない)。
 * PHP の関数名は**大文字小文字を区別しない**ので、比較は小文字化してから行う。
 *
 * ## 判定は区切りで割ったトークンの完全一致で行う (共通規約 (e))
 *
 * 区切りは PHP の字句 (トークン) そのものである。したがって `my_json_decode(` /
 * `json_decode_all(` / `$o->json_decode(` はいずれも別トークン・別文脈として扱われ、
 * 違反にならない (負例で裏取りする)。
 *
 * ## 保証しないもの (誇張しない)
 *
 * - **動的な関数呼び出し** (`$fn($text)` / 変数に入れた callable / `Closure::fromCallable($var)`) は
 *   見えない。名前が静的に決まらないためである
 *   (文字列リテラルの完全一致 `'json_decode'` だけは拾う)。
 * - 反射・動的に組み立てたクラス名・文字列キーだけの container 解決の経路は見えない。
 * - `vendor/` 配下と `tests/` 配下は走査しない (利用側 gate が走査根を宣言する)。
 * - 逆引用符 3 連の検出は**文字列リテラルの中身だけ**を見る
 *   (コメント / docblock は `PhpTokenScan::normalize()` が落としている)。
 * - `executeSync` の母集団は**メソッド名**で採る。同名の別メソッドがあれば母集団に入るが、
 *   受け手の解決は下の規則だけで行うので「解決できたことにする」方向へは倒れない。
 */
final class LlmResponseSeamScanner
{
    /** 囲みの印 (逆引用符 3 連)。**本ファイルは走査根の外**なのでここに書いてよい。 */
    private const string FENCE_MARK = '```';

    /**
     * `executeSync()` の呼び出し点を解決状態つきで列挙する。
     *
     * 受け手の解決は次の 5 段で、**どの段でも条件を満たさなければ `Unresolved`** である。
     * 解決の規則は `resolveSeam()` の docblock を参照 (受け手と囲みの呼び出しを同時に決める)。
     *
     * @param  list<string>  $promptFactories  目録の鍵 (依頼文 factory の完全修飾名)
     * @return list<LlmResponseSeamFinding>
     */
    public static function executeSyncSites(string $relativePath, string $phpSource, array $promptFactories): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $scan = PhpReferenceScanner::references($relativePath, $phpSource);

        /** @var array<int, ReferenceSite> $staticCalls */
        $staticCalls = [];
        foreach ($scan->sites as $site) {
            if ($site->kind === ReferenceKind::StaticCall) {
                $staticCalls[$site->tokenIndex] = $site;
            }
        }

        $findings = [];
        foreach ($scan->sites as $site) {
            if ($site->kind !== ReferenceKind::MethodCall || $site->name !== 'executeSync') {
                continue;
            }

            [$factory, $enclosing] = self::resolveSeam($tokens, $staticCalls, $site->tokenIndex);
            $resolution = match (true) {
                $factory === null => LlmResponseSeamResolution::Unresolved,
                in_array($factory, $promptFactories, true) => LlmResponseSeamResolution::ResolvedPromptFactory,
                default => LlmResponseSeamResolution::ResolvedOther,
            };

            $findings[] = new LlmResponseSeamFinding(
                path: $relativePath,
                line: $site->line,
                resolution: $resolution,
                factory: $factory,
                enclosingCall: $enclosing,
            );
        }

        return $findings;
    }

    /** `App\Support\Llm\GuardedPrompt` を参照している (import だけの場合も含む)。 */
    public static function referencesGuardedPrompt(string $relativePath, string $phpSource, string $fqcn): bool
    {
        $scan = PhpReferenceScanner::references($relativePath, $phpSource);

        if (in_array($fqcn, array_values($scan->imports), true)) {
            return true;
        }
        foreach ($scan->sites as $site) {
            if ($site->kind === ReferenceKind::NameReference || $site->kind === ReferenceKind::Construction) {
                if ($site->name === $fqcn) {
                    return true;
                }
            }
            if ($site->receiver->is($fqcn)) {
                return true;
            }
        }

        return false;
    }

    /**
     * 復号語彙 (関数としての `json_decode` / 逆引用符 3 連の文字列リテラル) の出現。
     *
     * @return list<string> 違反の説明 (空なら違反なし)
     */
    public static function decodeVocabularyViolations(string $relativePath, string $phpSource): array
    {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
        $functionImports = self::functionImports($tokens);
        $namespace = self::namespaceOf($tokens);

        $violations = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            $id = $token['id'];

            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
                $content = $id === T_CONSTANT_ENCAPSED_STRING
                    ? substr($token['text'], 1, -1)
                    : $token['text'];
                if (str_contains($content, self::FENCE_MARK)) {
                    $violations[] = "{$relativePath}:{$token['line']} 囲みの印を含む文字列リテラル";
                }
                if ($id === T_CONSTANT_ENCAPSED_STRING && self::isJsonDecode($content)) {
                    $violations[] = "{$relativePath}:{$token['line']} 文字列リテラルの json_decode";
                }

                continue;
            }

            if ($id !== T_STRING && $id !== T_NAME_FULLY_QUALIFIED && $id !== T_NAME_QUALIFIED) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next === null || $next['id'] !== null || $next['text'] !== '(') {
                continue;
            }
            $previousId = $tokens[$i - 1]['id'] ?? null;
            if (in_array($previousId, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue; // メソッド呼び出し / メソッド宣言 / 構築であって関数呼び出しではない
            }
            if (self::isJsonDecode(self::resolveFunctionName($token, $functionImports, $scan->imports, $namespace))) {
                $violations[] = "{$relativePath}:{$token['line']} 関数呼び出しの json_decode";
            }
        }

        return $violations;
    }

    /**
     * 登録済みの受け取り関数の中で、生の応答文字列が復号点へ**直接 1 回だけ**渡ること。
     *
     * @return list<string> 違反の説明 (空なら違反なし)
     */
    public static function receiverFlowViolations(
        string $relativePath,
        string $phpSource,
        string $class,
        string $method,
        string $decodeClass,
        string $decodeMethod,
    ): array {
        $tokens = PhpReferenceScanner::tokens($phpSource);
        $scan = PhpReferenceScanner::references($relativePath, $phpSource);
        $label = "{$class}::{$method}";

        $decodeIndexes = [];
        foreach ($scan->sites as $site) {
            if ($site->kind === ReferenceKind::StaticCall
                && $site->class === $class
                && $site->callable === $method
                && $site->name === $decodeMethod
                && $site->receiver->is($decodeClass)) {
                $decodeIndexes[] = $site->tokenIndex;
            }
        }
        if (count($decodeIndexes) !== 1) {
            return ["{$label}: {$decodeClass}::{$decodeMethod} の静的呼び出しが ".count($decodeIndexes).' 件 (1 件であること)'];
        }

        $declaration = self::methodDeclarationIndex($tokens, $method);
        if ($declaration === null) {
            return ["{$label}: メソッド宣言を一意に特定できません (未解決)"];
        }

        $parametersOpen = $declaration + 2;
        if (($tokens[$parametersOpen]['text'] ?? null) !== '(') {
            return ["{$label}: 引数リストの開き括弧を特定できません (未解決)"];
        }
        $parametersClose = self::matchForward($tokens, $parametersOpen);
        if ($parametersClose === null) {
            return ["{$label}: 引数リストの対応が取れません (未解決)"];
        }

        $parameterName = null;
        for ($i = $parametersOpen + 1; $i < $parametersClose; $i++) {
            if ($tokens[$i]['id'] === T_VARIABLE) {
                $parameterName = $tokens[$i]['text'];
                break;
            }
        }
        if ($parameterName === null) {
            return ["{$label}: 第 1 引数の変数を特定できません (未解決)"];
        }

        $body = self::bodyRange($tokens, $parametersClose);
        if ($body === null) {
            return ["{$label}: メソッド本体を特定できません (未解決)"];
        }

        $occurrences = [];
        for ($i = $body[0]; $i <= $body[1]; $i++) {
            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $parameterName) {
                $occurrences[] = $i;
            }
        }
        if (count($occurrences) !== 1) {
            return ["{$label}: 生の応答 {$parameterName} の出現が ".count($occurrences).' 件 (1 件であること)'];
        }

        $occurrence = $occurrences[0];
        $expected = $decodeIndexes[0] + 2; // `LlmJson` `::` `decode` `(` `$text`
        $following = $tokens[$occurrence + 1]['text'] ?? null;
        if ($occurrence !== $expected || ($following !== ')' && $following !== ',')) {
            return ["{$label}: 生の応答 {$parameterName} が {$decodeMethod}() の直接の引数になっていません"];
        }

        return [];
    }

    /**
     * 呼び出し点 `i` の**受け手**と**囲みの呼び出し**を同時に解決する。
     *
     * 受け手 (`X::make(...)`) の解決は次の 4 段で、どの段でも条件を満たさなければ `null` である。
     *  1. 呼び出しの手前が `)`
     *  2. そこから後方へ括弧の対応を数えて `make(` の開き括弧を決める
     *     (対応が取れないまま列の先頭に達したら `null`)
     *  3. 開き括弧の直前が `名前トークン :: make` の形である
     *  4. `名前トークン` が完全修飾名まで解決できる
     *
     * 囲みの呼び出しは「**応答が丸ごと 1 つの引数になっている**」ときだけ返す。
     * 加工して渡す形 (`->executeSync().'x'` / 三項 / null 合体 / キャスト / 配列に入れる)
     * では引数の開始または終端が一致しないので `null` になり、利用側 gate が赤くなる。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  array<int, ReferenceSite>  $staticCalls
     * @return array{0: string|null, 1: string|null} [受け手の完全修飾名, 囲みの `{FQCN}::{method}`]
     */
    private static function resolveSeam(array $tokens, array $staticCalls, int $index): array
    {
        $previous = $tokens[$index - 2] ?? null;
        if ($previous === null || $previous['id'] !== null || $previous['text'] !== ')') {
            return [null, null];
        }
        $makeOpen = self::matchBackward($tokens, $index - 2);
        if ($makeOpen === null || $makeOpen < 3) {
            return [null, null];
        }
        $makeSite = $staticCalls[$makeOpen - 1] ?? null;
        if ($makeSite === null || $makeSite->name !== 'make' || ! $makeSite->receiver->isResolved()) {
            return [null, null];
        }
        $factory = $makeSite->receiver->fqcn();
        $receiverNameIndex = $makeOpen - 3;

        // `executeSync(` … `)` の範囲。閉じ括弧の直後が引数の区切りでなければ「加工して渡した」形である
        $callOpen = $tokens[$index + 1] ?? null;
        if ($callOpen === null || $callOpen['id'] !== null || $callOpen['text'] !== '(') {
            return [$factory, null];
        }
        $callClose = self::matchForward($tokens, $index + 1);
        if ($callClose === null) {
            return [$factory, null];
        }
        $following = $tokens[$callClose + 1] ?? null;
        if ($following === null || $following['id'] !== null || ($following['text'] !== ',' && $following['text'] !== ')')) {
            return [$factory, null];
        }

        $enclosingOpen = self::innermostUnclosedParen($tokens, $index - 1);
        if ($enclosingOpen === null) {
            return [$factory, null];
        }
        $argumentStart = self::argumentStart($tokens, $enclosingOpen, $index);
        $label = $tokens[$argumentStart] ?? null;
        $colon = $tokens[$argumentStart + 1] ?? null;
        $named = $label !== null && $label['id'] === T_STRING
            && $colon !== null && $colon['id'] === null && $colon['text'] === ':';
        $expected = $named ? $argumentStart + 2 : $argumentStart;
        if ($expected !== $receiverNameIndex) {
            return [$factory, null];
        }

        $site = $staticCalls[$enclosingOpen - 1] ?? null;
        if ($site === null || ! $site->receiver->isResolved()) {
            return [$factory, null];
        }

        return [$factory, $site->receiver->fqcn().'::'.$site->name];
    }

    /**
     * `index` を囲む**最内の未閉じ `(`** の位置 (無ければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function innermostUnclosedParen(array $tokens, int $index): ?int
    {
        $depth = 0;
        for ($k = $index; $k >= 0; $k--) {
            if ($tokens[$k]['id'] !== null) {
                continue;
            }
            if ($tokens[$k]['text'] === ')') {
                $depth++;

                continue;
            }
            if ($tokens[$k]['text'] !== '(') {
                continue;
            }
            if ($depth === 0) {
                return $k;
            }
            $depth--;
        }

        return null;
    }

    /**
     * `open` で始まる引数リストのうち、`before` を含む引数の**開始添字**。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function argumentStart(array $tokens, int $open, int $before): int
    {
        $start = $open + 1;
        $depth = 0;
        for ($k = $open + 1; $k < $before; $k++) {
            if ($tokens[$k]['id'] !== null) {
                continue;
            }
            $text = $tokens[$k]['text'];
            if ($text === '(' || $text === '[' || $text === '{') {
                $depth++;

                continue;
            }
            if ($text === ')' || $text === ']' || $text === '}') {
                $depth--;

                continue;
            }
            if ($depth === 0 && $text === ',') {
                $start = $k + 1;
            }
        }

        return $start;
    }

    /**
     * `)` の位置から対応する `(` の位置を後方に探す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchBackward(array $tokens, int $closeIndex): ?int
    {
        $depth = 0;
        for ($k = $closeIndex; $k >= 0; $k--) {
            if ($tokens[$k]['id'] !== null) {
                continue;
            }
            if ($tokens[$k]['text'] === ')') {
                $depth++;

                continue;
            }
            if ($tokens[$k]['text'] === '(') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    /**
     * `(` の位置から対応する `)` の位置を前方に探す。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function matchForward(array $tokens, int $openIndex): ?int
    {
        $depth = 0;
        $count = count($tokens);
        for ($k = $openIndex; $k < $count; $k++) {
            if ($tokens[$k]['id'] !== null) {
                continue;
            }
            if ($tokens[$k]['text'] === '(') {
                $depth++;

                continue;
            }
            if ($tokens[$k]['text'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $k;
                }
            }
        }

        return null;
    }

    /**
     * 指定名のメソッド宣言 `function {name}` の `function` トークン位置 (一意でなければ null)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function methodDeclarationIndex(array $tokens, string $method): ?int
    {
        $found = [];
        $count = count($tokens);
        for ($k = 0; $k < $count; $k++) {
            if ($tokens[$k]['id'] !== T_FUNCTION) {
                continue;
            }
            $next = $tokens[$k + 1] ?? null;
            if ($next !== null && $next['id'] === T_STRING && $next['text'] === $method) {
                $found[] = $k;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * 引数リストの `)` の後にある本体 `{` … `}` の内側の範囲 (開始添字, 終了添字)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array{int, int}|null
     */
    private static function bodyRange(array $tokens, int $parametersClose): ?array
    {
        $count = count($tokens);
        $open = null;
        for ($k = $parametersClose + 1; $k < $count; $k++) {
            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === '{') {
                $open = $k;
                break;
            }
            if ($tokens[$k]['id'] === null && $tokens[$k]['text'] === ';') {
                return null; // 本体を持たない宣言
            }
        }
        if ($open === null) {
            return null;
        }

        $depth = 0;
        for ($k = $open; $k < $count; $k++) {
            $id = $tokens[$k]['id'];
            if ($id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
                $depth++;

                continue;
            }
            if ($id !== null) {
                continue;
            }
            if ($tokens[$k]['text'] === '{') {
                $depth++;

                continue;
            }
            if ($tokens[$k]['text'] === '}') {
                $depth--;
                if ($depth === 0) {
                    return [$open + 1, $k - 1];
                }
            }
        }

        return null;
    }

    /**
     * 解決後の名前が global の `json_decode` か。
     *
     * ★PHP の**関数名は大文字小文字を区別しない**ので、比較の前に小文字化する
     *   (`JSON_DECODE(` / `\Json_Decode(` / 大文字の `use function` はいずれも実行できる)。
     *   先頭の `\` も落とす (文字列 callable の `'\json_decode'` は global を指す)。
     */
    private static function isJsonDecode(string $resolvedName): bool
    {
        return mb_strtolower(ltrim(trim($resolvedName), '\\')) === 'json_decode';
    }

    /**
     * 名前トークンを関数の完全修飾名へ解決する。
     *
     * - `T_NAME_FULLY_QUALIFIED` (`\json_decode`): 先頭の `\` を落とす
     * - `T_STRING`: `use function` の別名表を引き、無ければ**global へ落ちる** (PHP の規則)
     * - `T_NAME_QUALIFIED` (`Foo\json_decode`): 先頭要素をクラス / 名前空間の import 表で置き換え、
     *   無ければ現在の名前空間の下に置く
     *
     * @param  array{id: int|null, text: string, line: int}  $token
     * @param  array<string, string>  $functionImports
     * @param  array<string, string>  $classImports
     */
    private static function resolveFunctionName(array $token, array $functionImports, array $classImports, string $namespace): string
    {
        $text = $token['text'];

        if ($token['id'] === T_NAME_FULLY_QUALIFIED) {
            return ltrim($text, '\\');
        }

        if ($token['id'] === T_STRING) {
            return $functionImports[mb_strtolower($text)] ?? $text;
        }

        $separator = strpos($text, '\\');
        $head = $separator === false ? $text : substr($text, 0, $separator);
        $resolvedHead = $classImports[mb_strtolower($head)] ?? null;
        if ($resolvedHead !== null) {
            return $separator === false ? $resolvedHead : $resolvedHead.substr($text, $separator);
        }

        return $namespace === '' ? $text : $namespace.'\\'.$text;
    }

    /**
     * `use function` の別名表 (小文字の短縮名 => 完全修飾の関数名)。
     *
     * group use (`use function Foo\{json_decode as decodeJson};`) にも対応する。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return array<string, string>
     */
    private static function functionImports(array $tokens): array
    {
        $imports = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_USE || ($tokens[$i + 1]['id'] ?? null) !== T_FUNCTION) {
                continue;
            }

            $prefix = '';
            $current = '';
            $alias = null;
            $expectAlias = false;

            for ($k = $i + 2; $k < $count; $k++) {
                $id = $tokens[$k]['id'];
                $text = $tokens[$k]['text'];

                if ($id === T_AS) {
                    $expectAlias = true;

                    continue;
                }
                if ($id === T_STRING || $id === T_NAME_QUALIFIED || $id === T_NAME_FULLY_QUALIFIED || $id === T_NS_SEPARATOR) {
                    if ($expectAlias) {
                        $alias = $text;

                        continue;
                    }
                    $current .= $text;

                    continue;
                }
                if ($id !== null) {
                    continue;
                }

                if ($text === '{') {
                    $prefix = $current;
                    $current = '';
                    $alias = null;
                    $expectAlias = false;

                    continue;
                }
                if ($text === ',' || $text === '}' || $text === ';') {
                    if ($current !== '') {
                        $fqn = ltrim($prefix.$current, '\\');
                        $short = $alias ?? self::shortName($fqn);
                        $imports[mb_strtolower($short)] = $fqn;
                    }
                    $current = '';
                    $alias = null;
                    $expectAlias = false;

                    if ($text === ';') {
                        $i = $k;
                        break;
                    }
                }
            }
        }

        return $imports;
    }

    /**
     * ファイル先頭の `namespace` 宣言 (無ければ空文字列)。
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function namespaceOf(array $tokens): string
    {
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i]['id'] !== T_NAMESPACE) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
                return $next['text'];
            }
        }

        return '';
    }

    private static function shortName(string $fqn): string
    {
        $position = strrpos($fqn, '\\');

        return $position === false ? $fqn : substr($fqn, $position + 1);
    }
}
