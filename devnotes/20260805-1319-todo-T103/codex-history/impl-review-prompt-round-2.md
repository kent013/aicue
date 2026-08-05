## Round 1 の指摘への対応

以下の対応マトリクスのとおり、**Critical 3 件 / Warning 1 件 / Suggestion 3 件のすべてに対応**した。

# 対応マトリクス: impl-review Round 1

## [Critical] `Gate::forUser(...)->authorize` の検出で `authorize` 直後の `(` を確認していない

- 判断: **対応する**
- 根拠: 指摘のとおり `Gate::forUser($user)->authorize;` が合格する。
  deny-by-default gate における誤合格は最悪の失敗モードであり、実際に再現した。
  (d-1) の `Gate::authorize(` 側は元から `(` を要求していたが、(d-2) だけ抜けていた。
- 対応内容: `AuthorizationMarkerScanner::authorizationMarkerOffset()` の (d-2) 判定に
  `($tokens[$close + 3] ?? '') === '('` を追加。

## [Critical] `guardMarkerOffset()` が最初の guard 位置しか返さず、2 段 guard の部分的な後置を見逃す

- 判断: **対応する**
- 根拠: `ItemController::update/destroy` は `resolveOrganizationProject` +
  `resolveProjectItem` の 2 段 guard であり、2 段目だけが `Gate` の後ろに移動した壊れ方が
  合格していた。これは本設計の中心不変条件 (層 2 → 層 3 の順序) の直接的な穴。
- 対応内容: API を `guardMarkerOffsets(): list<int>` (全件返却) に変更し、
  `ControllerAuthorizationGateTest` 側で `max($guardOffsets) > $authOffset` を違反とした。
  単数版は残さない (後方互換の並走を作らない)。
  実証: `ItemController::destroy` の 2 段目 guard だけを `Gate` の後ろへ移動したところ
  修正後の gate が fail することを実測 (修正前は合格していたパターン)。

## [Critical] 上記 2 点を固定する negative test が不足

- 判断: **対応する**
- 対応内容: `AuthorizationMarkerScannerTest` に 2 本追加 (合計 21 tests)。
  - 「authorize を呼んでいない (末尾の括弧が無い) 記述は認可マーカーにならない」
    (`->authorize;` / `Gate::authorize;` / `->authorize::class`)
  - 「guard が 2 段ある場合は全件を返す (片方だけ認可より後ろでも検出できる)」

## [Warning] middleware 順序テストが `api-key.ability:* < api.project-in-org` を検証していない

- 判断: **対応する**
- 根拠: 設計書の順序契約は 4 項 (`resolve.api-actor < api-key.ability:* <
  api.project-in-org < idempotent`) だが、テストは 3 項しか固定していなかった。
  ability 判定より先にテナント境界の 404 が返ると `insufficient_ability` の
  エラー契約が route ごとにぶれる。
- 対応内容: `api-key.ability:` を prefix 一致で探して index 比較する判定を追加。
  実証: `api.project-in-org` を ability より前へ動かして fail することを実測。

## [Suggestion] 順序契約コメントの表現が逆

- 判断: **対応する**
- 対応内容: 「破られる契約 / 起きること」の見出しに整理し、
  「idempotent が api.project-in-org **より前**」と条件側を正しく書き直した。

## [Suggestion] `{item}` の 404 body 同一性テストを足す

- 判断: **対応する**
- 根拠: `scopeBindings()` 化で `{item}` の解決経路が変わったため、
  「この project には無いが item 自体は存在する」という新しい識別差分が
  生まれていないことを直接証明する価値がある。
- 対応内容: 「{item} も cross-project / cross-org / 不在 で完全に同一の 404 応答」を追加。
  cross-project item / 不在 item id / cross-org project+item の 3 応答が
  status と body の両方で一致することを assert する。

## [Suggestion] `viewerApiKey()` / `apiBearer()` の名前が汎用的

- 判断: **対応する**
- 根拠: Pest の global 関数は再宣言できず、他ファイルとの衝突は fatal error になる。
- 対応内容: `itemAuthorizationViewerApiKey()` / `itemAuthorizationBearer()` に改名。


---

## 修正後のコード (該当箇所)

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
        $count = count($tokens);
        $offsets = [];

        for ($i = 0; $i < $count; $i++) {
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
        $count = count($tokens);
        $offsets = [];

        for ($i = 1; $i < $count; $i++) {
            if ($tokens[$i - 1] === '->'
                && in_array($tokens[$i], $guardMethods, true)
                && ($tokens[$i + 1] ?? '') === '(') {
                $offsets[] = $i;
            }
        }

        return $offsets;
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

### tests/Architecture/ControllerAuthorizationGateTest.php — 順序検証テスト

```php
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

### tests/Architecture/ProjectRouteCurrentOrgGuardTest.php — middleware 順序契約テスト

```php
/*
 * API の middleware 順序契約 (docblock ではなく機械で固定する):
 *
 *   resolve.api-actor  <  api-key.ability:*  <  api.project-in-org  <  idempotent
 *
 * | 破られる契約 | 起きること |
 * |---|---|
 * | resolve.api-actor が api.project-in-org **より後** | 'organization' attribute 未設定で Assert が
 *   発火し **全 API {project} route が 500** |
 * | api-key.ability:* が api.project-in-org **より後** | ability 不足の判定 (403) より先に
 *   テナント境界の 404 が返り、エラー契約 (insufficient_ability) が route ごとにぶれる |
 * | idempotent が api.project-in-org **より前** | **cross-org リクエストで idempotency 行が作られる**
 *   (cross-org の副作用 = 不変条件 3 に抵触) |
 *
 * 注意: gatherMiddleware() が返すのは **宣言順** (group middleware → route middleware)。
 * Laravel の middleware priority ($middlewarePriority) を導入すると最終的な実行順が
 * 並べ替えられうるが、現行構成では本テストが検査する custom middleware
 * (resolve.api-actor / api.project-in-org / idempotent) はいずれも priority リストに
 * 含まれないため宣言順 = 実行順である。priority を導入する際は本テストの前提を見直すこと。
 */
test('API の {project} route は middleware 順序契約を守る', function (): void {
    $checked = 0;
    $violations = [];

    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        if (! in_array('project', $route->parameterNames(), true)) {
            continue;
        }

        $name = $route->getName() ?? $route->uri();
        $middleware = $route->gatherMiddleware();
        $indexOf = static fn (string $needle): int|false => array_search($needle, $middleware, true);

        $guard = $indexOf('api.project-in-org');
        $actor = $indexOf('resolve.api-actor');
        $idempotent = $indexOf('idempotent');
        // ability middleware は `api-key.ability:read` のようにパラメータ付きで並ぶ
        $ability = false;
        foreach ($middleware as $index => $entry) {
            if (is_string($entry) && str_starts_with($entry, 'api-key.ability:')) {
                $ability = $index;

                break;
            }
        }

        if ($guard === false) {
            $violations[] = "{$name}: api.project-in-org が無い";

            continue;
        }
        if ($actor === false || $actor > $guard) {
            $violations[] = "{$name}: resolve.api-actor が api.project-in-org より後 "
                .'(organization attribute 未設定で 500 になります)';
        }
        if ($ability === false || $ability > $guard) {
            $violations[] = "{$name}: api-key.ability:* が api.project-in-org より後 "
                .'(ability 不足の 403 より前にテナント境界の 404 が返り、エラー契約がぶれます)';
        }
        if ($idempotent !== false && $idempotent < $guard) {
            $violations[] = "{$name}: idempotent が api.project-in-org より前 "
                .'(cross-org リクエストで idempotency 行が作られます)';
        }
        $checked++;
    }

    expect($violations)->toBe([]);
    expect($checked)->toBeGreaterThan(0); // 空振り drift ガード
});

```

### tests/Unit/Architecture/AuthorizationMarkerScannerTest.php — 追加した negative test

```php
test('authorize を呼んでいない (末尾の括弧が無い) 記述は認可マーカーにならない', function (): void {
    // 呼び出しでない `->authorize;` / `::authorize;` を合格させると gate が誤合格する
    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        'Gate::forUser($user)->authorize;'
    ))->toBeFalse();

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        'Gate::authorize;'
    ))->toBeFalse();

    expect(AuthorizationMarkerScanner::hasAuthorizationMarker(
        '$x = Gate::forUser($user)->authorize::class;'
    ))->toBeFalse();
});

test('inline URL 整合 guard の位置は認可マーカーより前であることを比較できる', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    $correct = <<<'PHP'
        $this->resolveOrganizationProject($organization, $project);
        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
        PHP;

    $inverted = <<<'PHP'
        Gate::forUser($actor->user)->authorize('create', [Item::class, $project]);
        $this->resolveOrganizationProject($organization, $project);
        PHP;

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($correct, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($correct);
    expect($guardOffsets)->toHaveCount(1)
        ->and($authOffset)->not->toBeNull()
        ->and(max($guardOffsets))->toBeLessThan($authOffset);

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($inverted, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($inverted);
    expect(max($guardOffsets))->toBeGreaterThan($authOffset);
});

test('guard が 2 段ある場合は全件を返す (片方だけ認可より後ろでも検出できる)', function (): void {
    $guards = ['resolveOrganizationProject', 'resolveProjectItem'];

    // 1 段目は Gate の前、2 段目は Gate の後 = 壊れた順序。
    // 最初の 1 件だけを返す実装だとこれが合格してしまう (誤合格)
    $partiallyInverted = <<<'PHP'
        $this->resolveOrganizationProject($organization, $project);
        Gate::forUser($actor->user)->authorize('update', $item);
        $this->resolveProjectItem($project, $item);
        PHP;

    $guardOffsets = AuthorizationMarkerScanner::guardMarkerOffsets($partiallyInverted, $guards);
    $authOffset = AuthorizationMarkerScanner::authorizationMarkerOffset($partiallyInverted);

    expect($guardOffsets)->toHaveCount(2)
        ->and(min($guardOffsets))->toBeLessThan($authOffset)
        // ★全件検査でなければ検出できない壊れ方
        ->and(max($guardOffsets))->toBeGreaterThan($authOffset);
});

```

### tests/Feature/Api/V1/ItemAuthorizationTest.php — 追加した {item} の 404 body 同一性テスト

```php
test('{item} も cross-project / cross-org / 不在 で完全に同一の 404 応答', function (): void {
    // {item} は scopeBindings ($project->items()) で routing 層に解決される。
    // その結果生まれうる新しい識別差分 (「この project には無いが item 自体は存在する」等) が
    // 漏れていないことを body 一致で証明する
    [$organizationA] = createOrganizationWithOwner('組織A');
    [$organizationB, $ownerB] = createOrganizationWithOwner('組織B');
    $projectB = Project::factory()->forOrganization($organizationB)->create();
    $otherProjectB = Project::factory()->forOrganization($organizationB)->create();
    $itemOfOtherProject = Item::factory()->forProject($otherProjectB)->create();
    $projectA = Project::factory()->forOrganization($organizationA)->create();
    $itemA = Item::factory()->forProject($projectA)->create();

    [, $plain] = issueApiKey($organizationB, $ownerB, ['read', 'write']);
    $headers = itemAuthorizationBearer($plain);
    $payload = ['name' => '更新'];

    // 同じ組織の別 project の item (実在するが {project} には属さない)
    $crossProject = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectB->id}/items/{$itemOfOtherProject->id}", $payload);
    // 存在しない item id
    $missingItem = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectB->id}/items/999999999", $payload);
    // 他組織の project + その item (project 段で落ちる)
    $crossOrg = $this->withHeaders($headers)
        ->patchJson("/api/v1/projects/{$projectA->id}/items/{$itemA->id}", $payload);

    expect($crossProject->getStatusCode())->toBe(404)
        ->and($missingItem->getStatusCode())->toBe(404)
        ->and($crossOrg->getStatusCode())->toBe(404)
        ->and($crossProject->json())->toBe($missingItem->json())
        ->and($crossProject->json())->toBe($crossOrg->json());

    expect($itemOfOtherProject->fresh()?->name)->not->toBe('更新');
});


```

---

## 検証結果 (すべてローカル実走)

- `composer test`: **2749 tests / 2747 passed / 0 failed / 2 skipped** (exit 0)
  - Round 1 時点の 2746 → +3 (negative test 2 本 + {item} body 同一性 1 本)
- `composer phpstan` (level 10): No errors
- `vendor/bin/pint --test`: passed

### 修正が実効であることの実測 (一時改変 → fail 確認 → 復元)

| 改変 | 期待 | 結果 |
|---|---|---|
| `ItemController::destroy` の **2 段目 guard だけ**を `Gate` の後ろへ | 順序テストが fail | **fail した** (`api.v1.projects.items.destroy: URL 整合 guard が認可 (Gate) より後に置かれています`)。Round 1 の実装では**合格していた**パターン |
| `api.project-in-org` を `api-key.ability:write` より前へ | middleware 順序契約テストが fail | **fail した** (3 route 分) |

`->authorize;` 系の誤合格は `AuthorizationMarkerScannerTest` の
「authorize を呼んでいない (末尾の括弧が無い) 記述は認可マーカーにならない」で恒久固定
(修正前の実装ではこのテストが落ちることを確認済み)。

---

## 再レビュー依頼

1. 残っている誤合格 (認可が無いのに合格する) バイパスはあるか
2. `guardMarkerOffsets` の全件検査に、逆に**正当な実装を落としすぎる** (false positive) 懸念はあるか
3. その他 Critical / Warning が無ければ **全体判定: APPROVED** を明記してほしい
