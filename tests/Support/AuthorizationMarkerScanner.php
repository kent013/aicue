<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * 認可マーカー (`Gate::authorize` / `Gate::forUser(...)->authorize`) の字句解析器。
 *
 * `ControllerAuthorizationGateTest` (変更系 route の deny-by-default 認可 gate) の
 * 検出ロジックを route 走査から切り離した純粋 helper。
 * 「route 走査 = テスト、字句解析 = 本 helper」と責務を分け、解析器そのものの
 * positive/negative を `tests/Unit/Architecture/AuthorizationMarkerScannerTest.php` が
 * 恒久固定する (gate 自体がセキュリティ機構であり、手動のコメントアウト検証では
 * 後の改修に対する回帰が効かないため)。
 *
 * ★設計判断:
 *  - 正規表現は使わない。`/Gate::forUser.*?->authorize/` は
 *    `Gate::forUser($u); $other->authorize();` のような無関係な 2 文でも合格してしまう
 *    (deny-by-default では誤合格が最悪の失敗モード)。括弧の深さを数える状態機械で
 *    「同一メソッドチェーン」であることを確認する。
 *  - コメント / 文字列リテラルはトークン段階で除去する
 *    (`// Gate::authorize を通す` のような記述で誤合格させない)。
 *  - 完全修飾名 (`\Illuminate\Support\Facades\Gate::authorize`) は受理しない。
 *    同名の別クラスによる誤合格を防ぐため、合格判定したファイルには
 *    `use Illuminate\Support\Facades\Gate;` の名前空間 import を必須とする
 *    ({@see self::importsGateFacade()})。
 *  - **ネストしたクロージャ / arrow function の中のマーカーは数えない**
 *    ({@see self::nestedFunctionMask()})。`$authorize = fn () => Gate::authorize(...);` は
 *    「その場では実行されない認可式」であり、これを合格させると gate の主張
 *    (「ハンドラは必ず認可判断を 1 回通る」) が崩れる。
 *
 * ★本 helper の限界 (意図的な線引き):
 *  トークン走査は**到達可能性を判定しない**。`if (false) { Gate::authorize(...); }` のような
 *  到達不能分岐に置かれたマーカーは合格する。制御フロー解析まで踏み込むのは本 gate の
 *  役割を超える (思考原則「今必要なものだけ作る」) ため実装しない。
 *  本 gate の役割は「**認可判断の入口が存在しない route を作らせない**」ことに限定し、
 *  認可が実際に効いているか (viewer が 403 になるか) は Feature テストの責務である
 *  (REST API v1 Item なら tests/Feature/Api/V1/ItemAuthorizationTest)。
 *  この 2 層で「入口の存在 = Architecture テスト / 実挙動 = Feature テスト」を分担する。
 *
 * ★前提 (将来 bracketed namespace を導入する場合は要見直し):
 *  本リポジトリは非 bracketed namespace (`namespace App\Foo;` のセミコロン形式) で
 *  統一されている。bracketed namespace (`namespace App { ... }`) を使うと
 *  名前空間 import の波括弧深さが 0 でなくなり {@see self::importsGateFacade()} の
 *  深さ判定が崩れる。Pint も非 bracketed を強制するため現状は対応しない。
 */
final class AuthorizationMarkerScanner
{
    /** 受理する Facade の完全修飾名 (これ以外の `Gate` は同名の別クラスとして扱う)。 */
    private const GATE_FACADE = 'Illuminate\Support\Facades\Gate';

    /**
     * メソッド本体のソース断片に認可マーカーがあるか。
     *
     * @param  string  $methodSource  `ReflectionMethod` の開始行〜終了行を切り出した PHP 断片
     */
    public static function hasAuthorizationMarker(string $methodSource): bool
    {
        return self::authorizationMarkerOffset($methodSource) !== null;
    }

    /**
     * 認可マーカーが最初に現れるトークン位置 (無ければ null)。
     *
     * 「URL 整合 guard → 認可」の順序検証 (不変条件 2) に使う。
     */
    public static function authorizationMarkerOffset(string $methodSource): ?int
    {
        $tokens = self::significantTokens($methodSource);
        $nested = self::nestedFunctionMask($tokens);
        $count = count($tokens);
        $offsets = [];

        for ($i = 0; $i < $count; $i++) {
            // ネストしたクロージャ / arrow function の中のマーカーは
            // 「ハンドラが必ず 1 回通る認可」ではないため数えない
            if ($nested[$i]) {
                continue;
            }
            if ($tokens[$i] !== 'Gate' || ($tokens[$i + 1] ?? '') !== '::') {
                continue;
            }

            // (d-1) Gate :: authorize (
            if (($tokens[$i + 2] ?? '') === 'authorize' && ($tokens[$i + 3] ?? '') === '(') {
                $offsets[] = $i;

                continue;
            }

            // (d-2) Gate :: forUser ( ... ) -> authorize
            if (($tokens[$i + 2] ?? '') !== 'forUser' || ($tokens[$i + 3] ?? '') !== '(') {
                continue;
            }

            $close = self::matchingParenthesis($tokens, $i + 3);
            if ($close === null) {
                continue;
            }
            // forUser() の戻り値に対して**直接** authorize() を呼んでいる形だけを合格とする
            // (間に `;` や別の式が挟まればチェーンは切れており不合格)。
            // 末尾の `(` は必須: `->authorize;` のような「呼んでいない」記述で合格させない
            if (($tokens[$close + 1] ?? '') === '->'
                && ($tokens[$close + 2] ?? '') === 'authorize'
                && ($tokens[$close + 3] ?? '') === '(') {
                $offsets[] = $i;
            }
        }

        return $offsets === [] ? null : min($offsets);
    }

    /**
     * inline URL 整合 guard (`$this->resolveOrganizationProject(...)` 等) の**全**トークン位置。
     *
     * ★最初の 1 件だけを返してはならない: guard が 2 段ある route
     * (`resolveOrganizationProject` + `resolveProjectItem`) で、片方だけが `Gate` より
     * 後ろに移動した壊れ方を見逃す (誤合格)。呼び出し側は全件が認可より前であることを検証する。
     *
     * @param  list<string>  $guardMethods  guard とみなすメソッド名
     * @return list<int>
     */
    public static function guardMarkerOffsets(string $methodSource, array $guardMethods): array
    {
        $tokens = self::significantTokens($methodSource);
        $nested = self::nestedFunctionMask($tokens);
        $count = count($tokens);
        $offsets = [];

        for ($i = 1; $i < $count; $i++) {
            if ($nested[$i]) {
                continue;
            }
            if ($tokens[$i - 1] === '->'
                && in_array($tokens[$i], $guardMethods, true)
                && ($tokens[$i + 1] ?? '') === '(') {
                $offsets[] = $i;
            }
        }

        return $offsets;
    }

    /**
     * 各トークンが「ネストしたクロージャ / arrow function の内部」かのマスク。
     *
     * 断片の先頭に現れる `function` / `fn` はハンドラ本体そのもの (メソッド宣言、または
     * Closure route) なのでネスト扱いしない。それ以降に現れる `function` / `fn` の
     * 本体は「その場では実行されないコード」であり、
     * `$authorize = fn () => Gate::authorize(...);` のような**呼ばれない認可式**で
     * gate を誤合格させないために除外する。
     *
     * 判定は保守的 (迷ったら除外) にしてある。除外しすぎた場合の結果は
     * 「認可なし」= gate が fail して人間が気づく方向であり、誤合格 (沈黙) にはならない。
     *
     * @param  list<string>  $tokens
     * @return list<bool>
     */
    private static function nestedFunctionMask(array $tokens): array
    {
        $count = count($tokens);
        /** @var list<bool> $mask */
        $mask = array_fill(0, $count, false);

        $outer = null;
        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i] === 'function' || $tokens[$i] === 'fn') {
                $outer = $i;

                break;
            }
        }

        for ($i = ($outer ?? -1) + 1; $i < $count; $i++) {
            if ($mask[$i]) {
                continue;
            }
            if ($tokens[$i] === 'function') {
                $end = self::closureBodyEnd($tokens, $i);
            } elseif ($tokens[$i] === 'fn') {
                $end = self::arrowFunctionEnd($tokens, $i);
            } else {
                continue;
            }

            for ($j = $i; $j <= $end; $j++) {
                $mask[$j] = true;
            }
        }

        return $mask;
    }

    /**
     * `function` トークンから、その本体 `{ ... }` の終端位置を返す。
     *
     * 本体が見つからなければ断片末尾まで (保守的に全て除外)。
     *
     * @param  list<string>  $tokens
     */
    private static function closureBodyEnd(array $tokens, int $start): int
    {
        $count = count($tokens);
        for ($i = $start; $i < $count; $i++) {
            if ($tokens[$i] !== '{') {
                continue;
            }
            $depth = 0;
            for ($j = $i; $j < $count; $j++) {
                if ($tokens[$j] === '{') {
                    $depth++;
                } elseif ($tokens[$j] === '}') {
                    $depth--;
                    if ($depth === 0) {
                        return $j;
                    }
                }
            }

            break;
        }

        return $count - 1;
    }

    /**
     * `fn` トークンから、その arrow function 式の終端位置を返す。
     *
     * arrow function の本体は式 1 つなので、同じ深さの `;` / `,` か、
     * 深さが 1 つ外へ出た位置で終わる。
     *
     * @param  list<string>  $tokens
     */
    private static function arrowFunctionEnd(array $tokens, int $start): int
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $start; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === '(' || $token === '[' || $token === '{') {
                $depth++;

                continue;
            }
            if ($token === ')' || $token === ']' || $token === '}') {
                $depth--;
                if ($depth < 0) {
                    return $i - 1; // 囲みの外へ出た = 式はここで終わっている
                }

                continue;
            }
            if ($depth === 0 && ($token === ';' || $token === ',')) {
                return $i;
            }
        }

        return $count - 1;
    }

    /**
     * ファイル全文に `use Illuminate\Support\Facades\Gate;` の名前空間 import があるか。
     *
     * `T_USE` は 3 用途 (名前空間 import / クロージャの lexical use / trait use) あるため、
     * **波括弧の深さ 0** かつ **直後が `(` でない** ものだけを名前空間 import とみなす。
     * alias 付き (`... Gate as G;`) と group use (`...Facades\{Gate, Auth};`) は
     * `Gate::` が本 Facade を指す保証が無いため受理しない (deny-by-default)。
     */
    public static function importsGateFacade(string $fileSource): bool
    {
        $tokens = token_get_all(self::withOpenTag($fileSource));
        $count = count($tokens);
        $depth = 0;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (is_array($token)) {
                // 文字列内の `{$var}` / `${var}` も対応する `}` は生トークンのため深さに数える
                if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
                    $depth++;
                }
                if ($token[0] !== T_USE || $depth !== 0) {
                    continue;
                }
                if (self::matchesGateImport($tokens, $i)) {
                    return true;
                }

                continue;
            }

            if ($token === '{') {
                $depth++;
            } elseif ($token === '}') {
                $depth--;
            }
        }

        return false;
    }

    /**
     * `use` トークン位置から Gate Facade の名前空間 import かを判定する。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function matchesGateImport(array $tokens, int $useIndex): bool
    {
        $count = count($tokens);
        $i = self::skipInsignificant($tokens, $useIndex + 1);

        // クロージャの lexical use (`function ($x) use ($y) {}`)
        if ($i >= $count || $tokens[$i] === '(') {
            return false;
        }

        $name = '';
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE || $token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if (in_array($token[0], [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name .= $token[1];

                    continue;
                }

                // `use function ...` / `use const ...` / `... as Alias` 等
                return false;
            }

            if ($token === '\\') {
                $name .= '\\';

                continue;
            }

            // alias (`as`) も group use (`{`) も無い、素の import だけを受理する
            return $token === ';' && ltrim($name, '\\') === self::GATE_FACADE;
        }

        return false;
    }

    /**
     * 空白・コメントを読み飛ばした次のトークン位置。
     *
     * @param  array<int, string|array{0: int, 1: string, 2: int}>  $tokens
     */
    private static function skipInsignificant(array $tokens, int $from): int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)
                && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            return $i;
        }

        return $count;
    }

    /**
     * 意味のあるトークンだけをテキスト列に正規化する。
     *
     * コメント / 文字列リテラル / 可変長文字列の中身 / 空白を除去することで
     * 「コメントに書かれた Gate::authorize」を誤検出しない。
     *
     * @return list<string>
     */
    private static function significantTokens(string $source): array
    {
        $ignored = [
            T_COMMENT,
            T_DOC_COMMENT,
            T_CONSTANT_ENCAPSED_STRING,
            T_ENCAPSED_AND_WHITESPACE,
            T_WHITESPACE,
        ];

        $result = [];
        // ★開始タグを付けないと断片全体が T_INLINE_HTML になり検出が全滅する
        foreach (token_get_all(self::withOpenTag($source)) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], $ignored, true)) {
                    continue;
                }
                $result[] = $token[1];

                continue;
            }

            $result[] = $token;
        }

        return $result;
    }

    /**
     * `(` の位置から対応する `)` の位置を返す (引数内のネスト括弧を正しくスキップする)。
     *
     * @param  list<string>  $tokens
     */
    private static function matchingParenthesis(array $tokens, int $open): ?int
    {
        $count = count($tokens);
        $depth = 0;

        for ($i = $open; $i < $count; $i++) {
            if ($tokens[$i] === '(') {
                $depth++;
            } elseif ($tokens[$i] === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }

    /** 断片を token_get_all にかけられる形 (開始タグ付き) にする。 */
    private static function withOpenTag(string $source): string
    {
        return str_starts_with(ltrim($source), '<?php') ? $source : '<?php '.$source;
    }
}
