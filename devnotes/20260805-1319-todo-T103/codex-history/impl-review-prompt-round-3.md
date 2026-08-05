## Round 2 の指摘への対応

# 対応マトリクス: impl-review Round 2

## [Critical] 実行されない認可式 (クロージャ / arrow function / 到達不能分岐) による誤合格

指摘は 2 つの異なる性質の問題を含むため、分けて判断した。

### (a) ネストしたクロージャ / arrow function 内のマーカー

- 判断: **対応する**
- 根拠: `$authorize = fn () => Gate::authorize('delete', $item);` は
  **字句的にも構文的にも「呼ばれていない」ことが静的に確定する**。
  トークン走査の範囲内で判定でき、かつ gate の主張
  (「ハンドラは必ず認可判断を 1 回通る」) を直接破るため、対応必須と判断した。
- 対応内容: `AuthorizationMarkerScanner::nestedFunctionMask()` を追加。
  断片の先頭に現れる `function` / `fn` (= ハンドラ本体そのもの) 以降に現れる
  `function` / `fn` の本体範囲をマスクし、その内側のマーカーを
  `authorizationMarkerOffset()` / `guardMarkerOffsets()` の両方で数えないようにした。
  - `function` は本体 `{ ... }` を括弧対応で、`fn` は同深度の `;` / `,` または
    深さが外へ出た位置までを範囲とする
  - 判定は**保守的** (迷ったら除外) にした。除外しすぎた場合の結果は
    「認可なし」= gate が fail して人間が気づく方向であり、誤合格 (沈黙) にはならない
- 実証: `ItemController::destroy` の認可を `fn () => ...` で包んだところ
  gate が「未分類」で fail することを実測 (修正前は合格していた)。
  恒久固定として Unit テストを 4 本追加 (arrow function 内 / クロージャ内 /
  クロージャが同居しても直下の認可は検出される / クロージャ内 guard は順序検証対象外)。

### (b) 到達不能分岐 (`if (false) { Gate::authorize(...); }`)

- 判断: **見送る (線引きを明文化して受け入れる)**
- 根拠: Codex 自身が指摘するとおり、これは**制御フロー解析 (AST/CFG) の領域**であり、
  トークン走査の限界を超える。ここに踏み込むのは
  - 思考原則 2「今必要なものだけ作る」に反する (現行コードベースに該当例 0 件)
  - gate の責務 (`NestedRouteIdorDefenseTest` と同じ「分類漏れ・drift を落とす」役割) を超える
  - CFG 解析器そのものが新しいバグ表面になり、gate の信頼性をむしろ下げる

  重要なのは、この抜けが**単独では成立しない**こと。到達不能分岐に認可を置いた実装は
  Feature テスト (`ItemAuthorizationTest` の viewer 403 ケース 5 本) で必ず落ちる。
  「入口の存在 = Architecture テスト / 実挙動 = Feature テスト」の 2 層で守る設計であり、
  片方だけで完全性を主張していない。
- 対応内容: 逃げずに**限界として明文化**した。
  `AuthorizationMarkerScanner` の docblock に「★本 helper の限界 (意図的な線引き)」節を追加し、
  到達可能性を判定しないこと・その代わり実挙動は Feature テストが担保することを明記。

## [Warning] `authorizationMarkerOffset()` が最初の認可だけを返し、複数認可を落としうる

- 判断: **契約として明文化する (挙動は変えない)**
- 根拠: Codex の但し書き
  「設計上『すべての認可より先にテナント境界を確定する』なら、この厳格さは妥当」が
  まさに本設計の契約である。「1 回目の認可 → guard → 2 回目の認可」という配置は、
  1 回目の認可が cross-org を 403 で弾いてしまい存在が漏れる = 不変条件 2 違反。
  したがってこれは false positive ではなく**意図した検出**。
- 対応内容: `ControllerAuthorizationGateTest` の順序検証テスト直前に契約 docblock を追加し、
  「すべての URL 整合 guard は、すべての認可判断より前」であること、
  および複数認可を意図的に違反扱いする理由を明記した。

## その他

- `ProjectRouteCurrentOrgGuardTest` / `AuthorizationMarkerScannerTest` /
  `ItemAuthorizationTest` は Round 2 で APPROVED を得たため変更なし。


---

## 修正後のコード

### tests/Support/AuthorizationMarkerScanner.php (全文)

```php
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

```

### tests/Unit/Architecture/AuthorizationMarkerScannerTest.php — 追加した 4 本

```php
test('arrow function の中の Gate::authorize は認可マーカーにならない (実行されない認可式)', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            $authorize = fn () => Gate::authorize('delete', $item);
            $item->delete();

            return JsonResource::make(['deleted' => true])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeFalse();
});

test('クロージャの中の Gate::authorize は認可マーカーにならない (実行されない認可式)', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            $callback = function () use ($item): void {
                Gate::forUser($item->owner)->authorize('delete', $item);
            };
            $item->delete();

            return JsonResource::make(['deleted' => true])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeFalse();
});

test('ハンドラ直下の認可は、同じメソッドにクロージャがあっても検出される', function (): void {
    $source = <<<'PHP'
        public function destroy(Request $request, Project $project, Item $item): JsonResponse
        {
            Gate::forUser($this->apiActor($request)->user)->authorize('delete', $item);
            $names = array_map(static fn (Item $i): string => $i->name, $project->items->all());
            $item->delete();

            return JsonResource::make(['deleted' => true, 'siblings' => $names])->response();
        }
        PHP;

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker($source))->toBeTrue();
});

test('クロージャ内の inline guard は順序検証の対象にならない', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    $source = <<<'PHP'
        public function update(Request $request, Project $project, Item $item): JsonResponse
        {
            $later = function () use ($project, $item): void {
                $this->resolveProjectItem($project, $item);
            };
            $this->resolveOrganizationProject($organization, $project);
            Gate::forUser($this->apiActor($request)->user)->authorize('update', $item);

            return ItemResource::make($item)->response();
        }
        PHP;

    // クロージャ内の guard は実行位置が確定しないため数えない (残るのは直下の 1 件)
    expect(AuthorizationMarkerScanner::guardMarkerOffsets($source, $guards))->toHaveCount(1);
});


```

### tests/Architecture/ControllerAuthorizationGateTest.php — 順序契約の明文化

```php
/*
 * 順序契約: **すべての URL 整合 guard は、すべての認可判断より前**に置く。
 *
 * 「最初の認可より後ろに guard が 1 つでもあれば違反」という厳格な形にしてある。
 * 認可を 2 回以上呼ぶハンドラで「1 回目の認可 → guard → 2 回目の認可」という配置は、
 * 1 回目の認可が cross-org を 403 で弾いてしまい存在が漏れるため、意図的に違反とする
 * (テナント境界の確定は必ず全認可に先行する = 層 2 → 層 3 の順序は不可侵)。
 */
test('URL 整合 guard は認可より前に置かれている (不変条件 2)', function (): void {
    $guards = controllerAuthorizationInlineGuards();
    $violations = [];
    $checked = 0;

    foreach (controllerAuthorizationMutatingRoutes() as $route) {
        $resolved = controllerAuthorizationHandlerSource($route);
        if ($resolved['status'] !== 'ok') {
            continue;
        }

        $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($resolved['fragment'], $guards);
        $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($resolved['fragment']);
        if ($guardOffsets === [] || $authOffset === null) {
            continue;
        }
        $checked++;

        // guard が 2 段ある route では **全件** が認可より前でなければならない
        // (片方だけ後ろに移動した壊れ方を見逃さない)
        if (max($guardOffsets) > $authOffset) {
            $violations[] = controllerAuthorizationRouteLabel($route)
                .': URL 整合 guard が認可 (Gate) より後に置かれています'
                .' (cross-org が 404 ではなく 403 を返し、リソースの存在が漏れます)';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
    // guard と認可の両方を持つ route が 1 本も無い = 順序検証が空振りしている
    expect($checked)->toBeGreaterThan(0);
});

```

---

## 検証結果 (すべてローカル実走)

- `composer test`: **2753 tests / 2751 passed / 0 failed / 2 skipped** (exit 0)
  - Round 2 時点の 2749 → +4 (追加した Unit テスト 4 本)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed

### 修正が実効であることの実測 (一時改変 → fail 確認 → 復元)

| 改変 | 結果 |
|---|---|
| `ItemController::destroy` の認可を `$authorize = fn () => Gate::forUser(...)->authorize(...);` に包む | gate が「api.v1.projects.items.destroy が未分類」で **fail した** (Round 2 の実装では合格していた) |

累積の drift 実証 (すべて実測済み、いずれも復元済み):

| # | 改変 | 検出したテスト |
|---|---|---|
| 1 | 候補下限 40 → 200 | 候補下限テスト (候補実測 61) |
| 2 | `api.project-in-org` を `resolve.api-actor` より前へ | middleware 順序契約 |
| 3 | `api.project-in-org` を `idempotent` より後へ | middleware 順序契約 |
| 4 | `api.project-in-org` を `api-key.ability:*` より前へ | middleware 順序契約 |
| 5 | read group から `api.project-in-org` 削除 | 存在テスト + 順序テスト |
| 6 | `destroy` の guard 2 段**両方**を Gate の後ろへ | 「guard は認可より前」 |
| 7 | `destroy` の guard **2 段目だけ**を Gate の後ろへ | 「guard は認可より前」(Round 1 指摘の修正) |
| 8 | `destroy` の認可を arrow function で包む | 「認可を持つか exemption 分類」(Round 2 指摘の修正) |

---

## 最終確認のお願い

1. (a) ネストしたクロージャ / arrow function の除外実装に、逆方向の問題
   (正当な直下の認可を落とす / 除外範囲の計算ミス) は無いか
2. (b) 到達不能分岐を **gate の責務外として明文化し、実挙動は Feature テストで担保する**
   という線引きを受け入れられるか。受け入れられない場合、
   「トークン走査の範囲内で」実現可能な追加対策があれば具体的に示してほしい
   (AST/CFG 解析器の新設は本 TODO のスコープ外と判断している)
3. その他 Critical / Warning が無ければ **全体判定: APPROVED** を明記してほしい
