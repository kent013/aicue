# Round 4: Round 3 の指摘への対応

Round 3 の全指摘 (Critical 1 / Warning 2 / Suggestion 3) を捌きました。

## 対応マトリクス

# 対応マトリクス: impl-review Round 3

## [Critical] 同一名前空間の短名を完全修飾名へ解決できず、guard 実装クラスの継承禁止を迂回できる

- 判断: **対応する**
- 根拠: 指摘のとおり `cachePayloadResolveName()` は現在の名前空間を受け取っておらず、
  `namespace Tests\Support\Cache; class X extends PlainDataGuardedRepository {}` は
  短名のまま比較されて母集団から外れていた。AGENTS.md 走査規約 (a) の違反であり、
  「合法な未解決形は存在しない」という Round 2 の整理への反例でもある。
- 対応内容:
  - `cachePayloadNamespace()` を新設し、`namespace A\B;` を抽出する
  - `cachePayloadResolveName()` に `$namespace` を渡し、
    **取り込みにも無い非完全修飾名は現在の名前空間からの相対**として解決する
  - `namespace\Foo` (`T_NAME_RELATIVE`) を解決する。名前判定の token 集合にも
    `T_NAME_RELATIVE` を足した (継承句 / 受け手型 / 収集ループ / `new` の対象)
  - 全呼び出し元 (受け手型の解決 / 継承句 / コンテナ束縛の引数 / 収集ループ) へ
    名前空間を通した
  - 負例に**同一名前空間の短名**と `namespace\Foo` の 2 形を追加した
  - 正例として「完全修飾名 / 別名 / 同一名前空間の短名 / 相対参照」の 4 経路が
    同じ完全修飾名になることと、名前空間の中の裸の名前が global へ落ちないことを固定した
  - `use Cache;` の facade 特例が取り込み表の分岐でも効くことを確認した
    (既存の正例が一度赤くなったので、取り込み解決の後にも特例を適用する形へ直した)
  - 冒頭 docblock の「合法な未解決形は無い」の記述に、
    **名前の解決は取り込み表 → 現在の名前空間の順で行い完全修飾名で突き合わせる**ことを併記した

## [Warning] W2/W3 が try と finally の対応関係を見ていない

- 判断: **対応する**
- 根拠: 指摘の合成例のとおり、独立に探すと「flush を持つ finally 無しの try」と
  「別の try-finally」を組み合わせた形が通ってしまう。flush が投げると reset へ到達しない。
- 対応内容: `cacheGuardTryStatement()` を新設し、try ブロックの直後の `catch` 群を読み飛ばして
  **その try 文自身に属する finally** だけを組にして返す形にした。
  finally を持たない try しか無ければ違反である。
  負例「reset が別の try 文の finally にある」を追加した。

## [Warning] W4 が動的な `uses($trait)` を保証外にしている

- 判断: **対応する (未解決として落とす側を選んだ)**
- 根拠: 指摘のとおり、保護対象の状態を作れる構文を保証外へ書くだけでは AGENTS.md (b) に
  適合しない。通常の `uses(X::class, Y::class)` はすべて名前で書かれるので誤検出は出ない。
- 対応内容: `uses()` の引数に名前として解決できない token があれば
  `UNRESOLVED_USES(...)` を返し、W4 が落ちる形にした。
  負例 (`$trait = WithCachedConfig::class; uses($trait);`) と
  正例 (`uses(TestCase::class, RefreshDatabase::class)`) を追加した。

## [Suggestion] 動的 `new` の目録はファイル単位の件数なので用途は機械検証していない

- 判断: **対応する (docblock へ明記した)**
- 対応内容: 冒頭 docblock の「保証しないもの」へ
  「`rationale` は人間の申告で、機械は件数の exact-fit しか見ない。同じファイルの中で
  許可済みの生成をキャッシュの保管先の生成へ置き換えると、件数が変わらない限り検出できない」
  と書いた (L2 の `payload` 欄と同じ扱いであることも併記)。

## [Suggestion] guide の記述が「自己テストだけを exact-fit」のままだった

- 判断: **対応する**
- 根拠: Round 2 で直したつもりだったが、置換が当たっておらず旧文が残っていた。
- 対応内容: 迂回の pin を **3 つの目録** (境界 API と直接生成 / 継承・実装 / 動的生成) として
  書き分けた。

## [Suggestion] D30 が L4h と動的生成の目録を含んでいない

- 判断: **対応する**
- 対応内容: 「揃え続ける不変条件と保証機構」を L4a-L4h へ更新し、
  観点表へ「静的に解決できない生成 (`new $class`)」の行を追加した。


## 名前解決まわりの修正後コード (全文)

```php
/**
 * ソース中の名前トークンを FQCN へ解決する。
 *
 * 未 import の裸 `Cache`、および `use Cache;` (root 名前空間の class alias を import した形) は
 * Laravel の class alias で facade に解決されるため、**安全側に facade とみなす**
 * (過剰検出は目録登録で解消できるが、見落としは本番でしか気付けない)。
 *
 * **名前空間 alias 経由の qualified name も展開する** (`use Illuminate\Support\Facades as F;`
 * → `F\Cache::put(...)`)。head だけを alias に差し替えて残りを捨てると `F\Cache` が
 * `Illuminate\Support\Facades` に潰れて受け手判定から落ちるため、**残りを連結する**
 * (impl-review Round 1 [Critical] 反映)。
 *
 * @param  array<string, string>  $useMap
 */
function cachePayloadResolveName(string $raw, array $useMap, string $namespace = ''): string
{
    $isFullyQualified = str_starts_with($raw, '\\');
    $name = ltrim($raw, '\\');

    // `namespace\Foo` (T_NAME_RELATIVE) は現在の名前空間からの相対指定である
    if (! $isFullyQualified && str_starts_with(strtolower($name), 'namespace\\')) {
        $rest = substr($name, strlen('namespace\\'));

        return $namespace === '' ? $rest : $namespace.'\\'.$rest;
    }

    if (isset($useMap[$name])) {
        $resolved = $useMap[$name];

        // `use Cache;` (root 名前空間の class alias の取り込み) も facade とみなす
        return strtolower($resolved) === 'cache' ? 'Illuminate\Support\Facades\Cache' : $resolved;
    }

    if (str_contains($name, '\\')) {
        $head = strstr($name, '\\', true);
        if (is_string($head) && isset($useMap[$head])) {
            return $useMap[$head].substr($name, strlen($head));
        }
    }

    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)。
    // ★これは**安全側への過剰検出**である (PHP はクラス名を global へ落とさないので、
    //   名前空間の中の裸の `Cache` は本来 `<現在の名前空間>\Cache` を指す)。
    if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }

    // ★取り込みにも無い非完全修飾名は**現在の名前空間からの相対**である。
    //   ここを飛ばすと `namespace Tests\Support\Cache; class X extends PlainDataGuardedRepository {}`
    //   のような**同一名前空間の短名**が完全修飾名へ解決できず、継承禁止をすり抜ける
    //   (AGENTS.md 走査規約 (a): クラス参照は完全修飾名で突き合わせる)。
    if (! $isFullyQualified && $namespace !== '') {
        return $namespace.'\\'.$name;
    }

    return $name;
}

/**
 * ファイル先頭の `namespace A\B;` を取り出す (無ければ空文字)。
 *
 * @param  list<PhpToken>  $tokens
 */
function cachePayloadNamespace(array $tokens): string
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_NAMESPACE)) {
            continue;
        }
        $nameIndex = cachePayloadNext($tokens, $i + 1);
        if ($nameIndex === null || ! $tokens[$nameIndex]->is([T_STRING, T_NAME_QUALIFIED])) {
            // `namespace\Foo` (相対参照) や無名前空間ブロック。名前空間の宣言ではない
            continue;
        }

        return ltrim($tokens[$nameIndex]->text, '\\');
    }

    return '';
}


```

## try / finally の対応関係と uses() の未解決判定 (全文)

```php
/**
 * `$from` 以降で最初に現れる try 文のうち、**その try 文自身に属する finally** を持つものを返す。
 *
 * `try { … } catch (…) { … } finally { … }` の catch 群を読み飛ばし、
 * 直後が `finally {` である場合だけ組にして返す。finally を持たない try は null を返す
 * (「別の try-finally の finally」を借りてこないため)。
 *
 * @param  list<string>  $tokens
 * @return array{try: array{int, int}, finally: array{int, int}}|null
 */
function cacheGuardTryStatement(array $tokens, int $from): ?array
{
    $count = count($tokens);
    for ($i = $from; $i < $count; $i++) {
        if ($tokens[$i] !== 'try') {
            continue;
        }
        $tryOpen = $i + 1;
        if (($tokens[$tryOpen] ?? '') !== '{') {
            continue;
        }
        $tryClose = cacheGuardMatchingBrace($tokens, $tryOpen);
        if ($tryClose === null) {
            return null;
        }

        $cursor = $tryClose + 1;
        while (($tokens[$cursor] ?? '') === 'catch') {
            $parenOpen = $cursor + 1;
            if (($tokens[$parenOpen] ?? '') !== '(') {
                return null;
            }
            $depth = 0;
            $parenClose = null;
            for ($j = $parenOpen; $j < $count; $j++) {
                if ($tokens[$j] === '(') {
                    $depth++;
                } elseif ($tokens[$j] === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $parenClose = $j;
                        break;
                    }
                }
            }
            if ($parenClose === null || ($tokens[$parenClose + 1] ?? '') !== '{') {
                return null;
            }
            $catchClose = cacheGuardMatchingBrace($tokens, $parenClose + 1);
            if ($catchClose === null) {
                return null;
            }
            $cursor = $catchClose + 1;
        }

        if (($tokens[$cursor] ?? '') !== 'finally' || ($tokens[$cursor + 1] ?? '') !== '{') {
            return null; // この try 文は finally を持たない
        }
        $finallyClose = cacheGuardMatchingBrace($tokens, $cursor + 1);
        if ($finallyClose === null) {
            return null;
        }

        return ['try' => [$tryOpen, $tryClose], 'finally' => [$cursor + 1, $finallyClose]];
    }

    return null;
}

/** 位置が範囲の**内側**にあるか。 */
function cacheGuardIsInside(?int $position, ?array $range): bool
{
    return $position !== null && $range !== null && $position > $range[0] && $position < $range[1];
}

/**
 * 「結線が bootstrap より**前**にある」ことの違反理由 (純関数。合成入力にも当てられる)。
 *
 * ★引数は**メソッド本体の token 列**である。ファイル全体を渡すと「別のメソッドで結線し、
 *   別のメソッドで bootstrap する」形を正常扱いしてしまう。
 *
 * @param  list<string>  $tokens
 * @return list<string>
 */
function cacheGuardBootstrapOrderViolations(array $tokens, string $label): array
{
    $wiring = cacheGuardSequencePosition($tokens, ['PlainDataCacheGuard', '::', 'registerBeforeBootstrap', '(']);
    $bootstrap = cacheGuardSequencePosition($tokens, ['->', 'bootstrap', '(', ')']);

    $violations = [];
    if ($wiring === null) {
        $violations[] = "{$label}: PlainDataCacheGuard::registerBeforeBootstrap() の呼び出しがありません";
    }
    if ($bootstrap === null) {
        $violations[] = "{$label}: ->bootstrap() の呼び出しがありません (走査対象を取り違えている)";
    }
    if ($wiring !== null && $bootstrap !== null && $wiring > $bootstrap) {
        $violations[] = "{$label}: 結線が bootstrap() より後にあります (起動中の書き込みを見逃す)";
    }

    return $violations;
}

/**
 * tests/Pest.php を `pest()->extend(TestCase::class)` 単位のレーンブロックへ割る。
 *
 * @return list<array{lanes: list<string>, tokens: list<string>}>
 */
function cacheGuardLaneBlocks(string $source): array
{
    $tokens = cacheGuardNormalizedTokens($source);
    $starts = [];
    $from = 0;
    while (($position = cacheGuardSequencePosition($tokens, ['pest', '(', ')', '->', 'extend'], $from)) !== null) {
        $starts[] = $position;
        $from = $position + 1;
    }

    $blocks = [];
    foreach ($starts as $index => $start) {
        $end = $starts[$index + 1] ?? count($tokens);
        $block = array_slice($tokens, $start, $end - $start);

        $lanes = [];
        $inPosition = cacheGuardSequencePosition($block, ['->', 'in', '(']);
        if ($inPosition !== null) {
            for ($i = $inPosition + 3; $i < count($block); $i++) {
                if ($block[$i] === ')') {
                    break;
                }
                if ($block[$i] === ',') {
                    continue;
                }
                $lanes[] = trim($block[$i], "'\"");
            }
        }
        sort($lanes);

        $blocks[] = ['lanes' => $lanes, 'tokens' => $block];
    }

    return $blocks;
}

/**
 * 1 レーンブロックの結線と後始末の違反理由 (純関数。合成入力にも当てられる)。
 *
 * ★**対応する波括弧を解決した範囲**で判定する。位置の前後比較だけだと、
 *   クロージャや try / finally の外へ出した形を素通しする。
 *
 * @param  list<string>  $block
 * @return list<string>
 */
function cacheGuardLaneWiringViolations(array $block, string $label): array
{
    $violations = [];

    $beforeEach = cacheGuardBlockRange($block, ['->', 'beforeEach', '(']);
    $afterEach = cacheGuardBlockRange($block, ['->', 'afterEach', '(']);
    if ($beforeEach === null) {
        $violations[] = "{$label}: beforeEach のクロージャを解決できません";
    }
    if ($afterEach === null) {
        $violations[] = "{$label}: afterEach のクロージャを解決できません";

        return $violations;
    }

    $assertInstalled = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'assertInstalled', '(']);
    if (! cacheGuardIsInside($assertInstalled, $beforeEach)) {
        $violations[] = "{$label}: beforeEach のクロージャの中で PlainDataCacheGuard::assertInstalled() を呼んでいません";
    }

    // ★try と finally が**同じ try 文に属する**ことまで確認する。独立に探すと、
    //   「flush を持つ try (finally 無し)」と「reset を持つ別の try-finally」が
    //   別々にある形を通してしまい、flush が投げたときに reset へ到達しない。
    $statement = cacheGuardTryStatement($block, $afterEach[0]);
    if ($statement === null || ! cacheGuardIsInside($statement['try'][0] + 1, $afterEach)) {
        $violations[] = "{$label}: afterEach が try … finally の形になっていません (同じ try 文の finally が要る)";

        return $violations;
    }
    $try = $statement['try'];
    $finally = $statement['finally'];

    $flush = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'flushAndFailIfStray', '(']);
    if (! cacheGuardIsInside($flush, $try)) {
        $violations[] = "{$label}: afterEach の try ブロックの中で PlainDataCacheGuard::flushAndFailIfStray() を呼んでいません";
    }

    // ★flush が throw しても次テストへ accumulator を漏らさないために、reset は
    //   **finally ブロックの中**でなければならない。
    $reset = cacheGuardSequencePosition($block, ['PlainDataCacheGuard', '::', 'reset', '(']);
    if (! cacheGuardIsInside($reset, $finally)) {
        $violations[] = "{$label}: afterEach の finally ブロックの中で PlainDataCacheGuard::reset() を呼んでいません";
    }

    return $violations;
}

/**
 * 期待 token 列との完全一致を判定する (負例をこの関数に通すため純関数にしてある)。
 *
 * @param  list<string>  $actual
 * @param  list<string>  $expected
 * @return list<string>
 */
function cacheGuardTokenListViolations(array $actual, array $expected, string $label): array
{
    if ($actual === $expected) {
        return [];
    }

    return ["{$label}: token 列が期待値と一致しません (実測 "
        .count($actual).' token / 期待 '.count($expected).' token)'];
}

/**
 * ローカルの写しが「vendor 期待列 + 許可差分」であることの違反理由 (純関数)。
 *
 * @param  list<string>  $local
 * @return list<string>
 */
function cacheGuardLocalCopyViolations(array $local): array
{
    $violations = cacheGuardTokenListViolations(
        $local,
        CACHE_GUARD_LOCAL_CREATE_APPLICATION_TOKENS,
        'ローカルの写し',
    );

    $stripped = $local;
    foreach (array_reverse(CACHE_GUARD_LOCAL_ALLOWED_INSERTIONS) as $insertion) {
        if (array_slice($local, $insertion['offset'], count($insertion['tokens'])) !== $insertion['tokens']) {
            $violations[] = "許可差分「{$insertion['reason']}」が期待位置にありません";

            continue;
        }
        array_splice($stripped, $insertion['offset'], count($insertion['tokens']));
    }

    return array_merge($violations, cacheGuardTokenListViolations(
        $stripped,
        CACHE_GUARD_VENDOR_CREATE_APPLICATION_TOKENS,
        '許可差分を除いたローカルの写し',
    ));
}

/**
 * `use A\B\C;` / `use A\B\C as D;` / `use A\B\{C, D as E};` から alias => FQCN の表を作る。
 *
 * ★型宣言より後の `use` は trait の取り込みなので取り込み表に混ぜない
 *   (混ぜると `use WithCachedConfig;` が自分自身へ解決して短名の負例が黙る)。
 *
 * @param  list<PhpToken>  $tokens
 * @return array<string, string>
 */
function cacheGuardUseMap(array $tokens): array
{
    $map = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
            break;
        }
        if (! $tokens[$i]->is(T_USE)) {
            continue;
        }

        $prefix = '';
        $pending = null;
        $isGroup = false;

        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ';') {
                break;
            }
            if ($token->text === '{') {
                $isGroup = true;
                $prefix = $pending === null ? '' : rtrim($pending, '\\').'\\';
                $pending = null;

                continue;
            }
            if ($token->text === '}' || $token->text === ',') {
                if ($pending !== null) {
                    $map[cacheGuardShortName($pending)] = $prefix.$pending;
                    $pending = null;
                }
                if ($token->text === '}') {
                    break;
                }

                continue;
            }
            if ($token->is(T_AS)) {
                for ($k = $j + 1; $k < $count; $k++) {
                    if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                        continue;
                    }
                    if ($tokens[$k]->is(T_STRING) && $pending !== null) {
                        $map[$tokens[$k]->text] = $prefix.$pending;
                        $pending = null;
                    }
                    $j = $k;
                    break;
                }

                continue;
            }
            if ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $pending = ltrim($token->text, '\\');

                continue;
            }
            if ($token->is(T_NS_SEPARATOR)) {
                continue; // グループ use の `Foo\{` の区切り
            }
        }

        if ($pending !== null && ! $isGroup) {
            $map[cacheGuardShortName($pending)] = $pending;
        }
    }

    return $map;
}

/** 完全修飾名の末尾要素。 */
function cacheGuardShortName(string $fqcn): string
{
    return str_contains($fqcn, '\\') ? substr((string) strrchr($fqcn, '\\'), 1) : $fqcn;
}

/**
 * 1 ファイルが cached config / cached routes の trait を**適用している**か。
 *
 * 見るのは 2 形である。
 *  (1) 型宣言より後の `use ...;` (trait の取り込み)
 *  (2) Pest の `uses(...::class)` (生成される TestCase が trait を使う)。
 *      **静的に解決できない引数 (`uses($trait)`) は `UNRESOLVED_USES(...)` として返す**
 *      = 呼び出し側の gate が落ちる (見逃さない)
 *
 * ★namespace 直下の取り込み (`use Illuminate\Foundation\Testing\WithCachedConfig;`) は
 *   対象にしない — tests/TestCase.php は override のために取り込む必要があるためである。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap  alias => FQCN
 * @return list<string> 見つかった trait の完全修飾名
 */
function cacheGuardCachedStateTraitUses(array $tokens, array $useMap): array
{
    $found = [];
    $count = count($tokens);

    $resolve = static function (string $raw) use ($useMap): string {
        $name = ltrim($raw, '\\');

        return $useMap[$name] ?? $name;
    };

    // (2) Pest の uses(...::class)
    for ($i = 0; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_STRING) || strtolower($tokens[$i]->text) !== 'uses') {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text !== '(') {
                break;
            }
            for ($k = $j + 1; $k < $count; $k++) {
                if ($tokens[$k]->text === ')') {
                    break;
                }
                if ($tokens[$k]->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                    continue;
                }
                if ($tokens[$k]->text === ',' || $tokens[$k]->is([T_DOUBLE_COLON, T_CLASS, T_NS_SEPARATOR])) {
                    continue;
                }
                if ($tokens[$k]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                    if (in_array($resolve($tokens[$k]->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
                        $found[] = $resolve($tokens[$k]->text);
                    }

                    continue;
                }

                // ★`uses($trait)` のように静的に決まらない引数は**未解決として落とす**
                //   (AGENTS.md 走査規約 (b))。通常の `uses(X::class, Y::class)` は
                //   すべて名前として書かれるので誤検出にならない。
                $found[] = 'UNRESOLVED_USES('.$tokens[$k]->text.')';
            }
            break;
        }
    }

    // (1) 型宣言より後の use (trait の取り込み)
    $typeStart = null;
    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i]->is([T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM])) {
            $typeStart = $i;
            break;
        }
    }
    if ($typeStart === null) {
        return $found;
    }

    for ($i = $typeStart; $i < $count; $i++) {
        if (! $tokens[$i]->is(T_USE)) {
            continue;
        }
        for ($j = $i + 1; $j < $count; $j++) {
            $token = $tokens[$j];
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }
            if ($token->text === ';' || $token->text === '{' || $token->text === '(') {
                break; // `use (...)` の closure 形もここで抜ける
            }
            if ($token->text === ',') {
                continue;
            }
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                continue;
            }
            if (in_array($resolve($token->text), CACHE_GUARD_CACHED_STATE_TRAITS, true)) {
                $found[] = $resolve($token->text);
            }
        }
    }

    return $found;
}


```

## その他の差分 (Round 3 時点から)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 8c368e23..f27b44e9 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -86,15 +86,32 @@ ## セキュリティ不変条件(アプリ都合で緩めない)
     `bootstrap/app.php` の **priority list**(route の宣言順ではない)
     (`ProjectRouteCurrentOrgGuardTest` / `NestedRouteIdorDefenseTest` /
     `TenantBoundaryOrderingTest`)
-11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は配列 / 文字列 / 数値 / 真偽値に限る
+11. **キャッシュに入れるのは素のデータだけ**: cache へ渡す値は
+    配列 / 文字列 / 数値 / 真偽値 / `null` に限る
     (オブジェクトを直接入れない)。読み戻しは `fromArray()` 等で**明示的に組み立て直して検査**し、
     失敗したら `forget` する(準拠実装 `FxRateService` + `FxSnapshotDto`)。
     `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧を作らず、
     **キーごと消さない**(宣言が無いと制限なしの `unserialize()` に戻る = fail-open)。
-    **テストは array store で緑になり本番 database store でだけ壊れる**ため、
-    書き込み経路とキャッシュに触れるファイルは deny-by-default の目録で強制する
-    (`CachePayloadPlainDataGateTest` / 宣言 pin は `ConfigHardeningTest`。
-    guide §7 不変条件 6 と対応)
+    強制は **2 層**である(家系の裁定 AG-151 = 正典 v2)。
+    **静的層** (`CachePayloadPlainDataGateTest`) は書き込み経路とキャッシュに触れるファイルを
+    deny-by-default の目録で強制し、受け皿の境界を迂回する書き方(`Cache::extend` /
+    `getStore` / `setStore` / `tags` / 受け手型・保管先型の直接生成 / macro 登録)を
+    **通常経路は 0 件、実行時層の自己テストだけを名指しの目録へ exact-fit** で pin する。
+    受け手型・保管先型の**継承・実装の宣言**は別の名指し目録で扱い、
+    実行時層の実装 2 本 (guard 付き受け皿と guard 付き manager) だけを許す。
+    **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) はテスト中のキャッシュ書き込みを
+    受け皿の側で捕まえ、保管先へ渡す前の値を再帰検査する。結線はアプリ起動の前
+    (`Tests\TestCase::createApplication()`)で、後始末は `tests/Pest.php` の全レーンが行う
+    (`CacheGuardWiringGateTest` が deny-by-default で固定)。
+    **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+    **値**を見るので、直列化しない保管方式でも同じように発火する。
+    ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+    ため、そこは静的層だけが塞ぐ。したがって
+    **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
+    設定の宣言 pin は `ConfigHardeningTest`、実効値は静的 gate の検査 6。
+    **主要な境界の例外として `getStore()` だけをここにも記す**。
+    網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と guide には写さない
+    (2 か所に書くと必ず食い違う)。guide §7 不変条件 6 と対応
 
 > **採番の注意**: 本節の番号と `docs/app-integration-guide.md` §7 の番号は **1:1 対応しない**
 > (本節 6 = PII CipherSweet / guide 6 = 逆シリアライズ、本節 8 = SSRF / guide 8 = 認可 gate)。
diff --git a/docs/app-integration-guide.md b/docs/app-integration-guide.md
index 3e088a48..58664c0c 100644
--- a/docs/app-integration-guide.md
+++ b/docs/app-integration-guide.md
@@ -229,14 +229,32 @@ ## 7. 守るべき不変条件(チェックリスト)
 6. **任意 class の逆シリアライズを許さない / キャッシュに入れるのは素のデータだけ**:
    `config/cache.php` の `serializable_classes` は **`false` 固定**でクラス許可一覧は作らない
    (例外を作らない)。**キーごと消すのも不可** — Laravel は宣言が無いと制限なしの
-   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは配列 / 文字列 / 数値 / 真偽値だけで、
+   `unserialize()` に戻る(fail-open)。cache へ渡してよいのは
+   配列 / 文字列 / 数値 / 真偽値 / `null` だけで、
    オブジェクトは `toArray()` で素の配列にしてから入れ、読み戻しは `fromArray()` 等で
    **明示的に組み立て直して検査し、失敗したら `forget`** する
    (準拠実装: `App\Services\FxRateService` + `App\DataTransferObjects\FxSnapshotDto`)。
-   **テストレーンは array store(`serialize => false`)なのでオブジェクトを入れても緑になる** —
-   本番の database store でだけ壊れるため、静的検査で塞ぐ:
-   キャッシュ書き込み経路とキャッシュに触れるファイルは
-   `tests/Architecture/CachePayloadPlainDataGateTest.php` の目録へ登録必須(deny-by-default)。
+   強制は **2 層**である(家系の裁定 AG-151 = 正典 v2):
+   - **静的層** (`tests/Architecture/CachePayloadPlainDataGateTest.php`) —
+     キャッシュ書き込み経路とキャッシュに触れるファイルは目録へ登録必須(deny-by-default)。
+     受け皿の境界を迂回する書き方は**3 つの目録**で pin する:
+     (a) `Cache::extend` / `getStore` / `setStore` / `tags` / macro 登録 /
+     受け手型・保管先型の直接生成 は**通常経路 0 件 + 実行時層の自己テストだけ**を
+     名指しの目録へ exact-fit、
+     (b) 受け手型・保管先型・実行時層の実装クラスの**継承・実装の宣言**は
+     別の名指し目録で**実行時層の実装 2 本だけ**、
+     (c) `new $class` のように生成対象が静的に決まらない形は deny-by-default で、
+     キャッシュの保管先ではない既知の用途を理由付きの目録へ登録する
+   - **実行時層** (`Tests\Support\Cache\PlainDataCacheGuard`) —
+     テスト中のキャッシュ書き込みを受け皿の側で捕まえ、保管先へ渡す**前の値**を再帰検査する。
+     結線はアプリ起動の前(`Tests\TestCase::createApplication()`)、後始末は
+     `tests/Pest.php` の全レーン(`tests/Architecture/CacheGuardWiringGateTest.php` が固定)
+   **「テストは array store なので実行時には捕まらない」は誤り** — 実行時層は直列化ではなく
+   **値**を見るので、直列化しない保管方式でも同じように発火する。
+   ただし **`getStore()` は実行時には落とせない**(vendor 自身が流量制限・排他の正常系で呼ぶ)
+   ため、そこは静的層だけが塞ぐ。したがって
+   **vendor が `getStore()` 経由で書く値は 2 層とも見えない**。
+   網羅的な保証外一覧の正本は**実行時層の docblock**であり、本書と AGENTS.md には写さない。
    配列往復は `tests/Unit/DataTransferObjects/FxSnapshotDtoTest.php` が固定する
 7. **課金系の冪等性**: webhook は冪等マシン経由、消費は 2 フェーズ、通知は dedup_key。
    課金による利用可否の判定は `BillingAccess` 経由のみ(subscription 直参照の gate 分岐禁止。
diff --git a/docs/architecture.md b/docs/architecture.md
index 99d803ba..f85c70b6 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -2853,3 +2853,71 @@ ### 保証しないもの (誇張しない)
 - **レーンの非対称**: 値集合の同期は `pnpm test` (CI の frontend job) でだけ走る。
   PHP としての妥当性は backend job (`composer test` / PHPStan)。
   **`composer test` だけでは値集合の同期は検証されない**。
+
+## キャッシュ素データ規約の 2 層 (T228 / 家系の裁定 AG-151 = 正典 v2)
+
+「キャッシュに入れるのは素のデータだけ」(AGENTS.md セキュリティ不変条件 11) は
+**静的層と実行時層の 2 層**で強制する。どちらも他方を包含しない。
+
+| 層 | 実体 | 保証すること |
+|---|---|---|
+| 静的層 | `tests/Architecture/CachePayloadPlainDataGateTest.php` | **申告なしに書き込み経路を増やせない**。境界を迂回する書き方が通常経路で 0 件である |
+| 実行時層 | `tests/Support/Cache/PlainDataCacheGuard.php` ほか 4 本 | **テストが実行した書き込みの値が実際に素データである** |
+
+- **静的層だけが見えるもの**: `tests/` `app/` にありながらテストが 1 度も踏まない書き込み。
+  実行時層は実行されないものを永久に見ない
+- **実行時層だけが見えるもの**: `vendor/` 配下からの書き込み。静的走査の母集団
+  (`app` / `routes` / `database` / `tests`) に入らないので、テストがその経路を踏んだときに
+  値を見られるのは実行時層だけである
+
+### 実行時層の仕組み
+
+受け皿 (`Illuminate\Cache\Repository`) を継承した `PlainDataGuardedRepository` が
+値の末端 4 メソッド (`put` / `add` / `forever` / `putMany`) を override し、
+保管先へ渡す**前の値**を `PlainDataInspector` で再帰検査する。
+糖衣 API (`set` / `setMultiple` / `remember` / `rememberForever` / `sear` / `flexible` /
+`rememberWithWarmth` / `$cache[$k] = $v`) は vendor 実装がこの 4 つへ合流するので、
+合流が将来変わったら `tests/Feature/Cache/CachePayloadPlainDataGuardTest.php` が落ちる。
+
+**イベント購読 (`KeyWritten`) にはしない** — `Event::fake()` や store 設定の
+`'events' => false` で無効化できる差し替え可能な境界だからである。
+
+**結線はアプリ起動の前**である。`Tests\TestCase::createApplication()` が
+`bootstrap/app.php` を require した直後・`bootstrap()` の直前に
+`PlainDataCacheGuard::registerBeforeBootstrap()` を呼ぶ。Pest の beforeEach では遅く、
+起動中の書き込み (vendor 由来だと静的層の走査根にも入らない) が
+**2 層とも沈黙する穴**になる。
+
+**違反は「その場で例外」と「accumulator への記録」の両方**にする。アプリ側の
+`catch (Throwable)` で例外が消えても、afterEach の `flushAndFailIfStray()` で必ず赤くなる
+(既存の `StrayHttpRequestGuard` / `StrayLlmCallGuard` と同じ設計)。
+
+### 露出したときの直し方
+
+**免除目録は作らない**。出所ごとに次のとおり処理する。
+
+1. `app/` → 必ず直す。素の配列にして入れ、読み戻しで組み立て直す
+   (準拠実装 `FxRateService` + `FxSnapshotDto`)。あわせて静的層の L2 目録へ登録する
+2. `tests/` → 必ず直す (本番で壊れる書き方をテストが先取りしている状態である)
+3. vendor 由来 → (a) 本リポジトリが所有する設定でその機能を閉じる /
+   (b) その機能を使わない形へアプリを直す / (c) どちらもできなければ実装を完了にせず
+   家系の台帳の議題として起こす。**guard 側に許可一覧を足す選択肢は取らない**
+
+### 保管先への素通しの分類 (`__call`)
+
+`Illuminate\Cache\Repository` は `lock()` / `restoreLock()` を宣言しておらず、
+`Cache::lock(...)` は `Repository::__call()` の素通しで保管先へ届く。排他は payload を
+運ばないので、実行時層はこの 2 語彙**だけ**を名指しで通し、それ以外の素通しと
+macro 経由の呼び出しは境界迂回として落とす。許可を 2 か所で別々に育てないよう、
+この 2 語彙が静的層の TERMINAL 語彙 (payload を運ばないと分類した語彙) の**部分集合**である
+ことを同じ gate (検査 L4g) が固定する。
+
+### 設定で閉じたもの
+
+`config/prism-prompt.php` の `cache.enabled` は **`false` 固定** (env を介さない)。
+同梱パッケージの `PromptTemplate::fromYaml()` が `PromptTemplate` オブジェクトそのものを
+キャッシュへ入れるためで、有効・無効を決める設定を本リポジトリが所有している以上、
+既定で閉じるのが規約の帰結である。宣言と実効値の二段 pin は `ConfigHardeningTest`。
+
+> **保証しないものは本節に書かない**。正本は実行時層 (`PlainDataCacheGuard`) の docblock である
+> (2 か所に書くと必ず食い違う)。
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 591e48d1..6b2fcd93 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -8,7 +8,7 @@ # テンプレート差分レジストリ
 `template-divergence-ledger` が 2026-08-15 に確定した形) に従う。形式は
 `tests/Architecture/TemplateDivergenceLedgerFormatTest.php` が機械で強制する。
 
-登録エントリ: 28 件
+登録エントリ: 29 件
 
 ## 記録の原則
 
@@ -1698,3 +1698,63 @@ ### 関連
   `tests/js/architecture/enum-ts-sync-extractor.test.ts` /
   `tests/js/support/enum-ts-sync/`
 - 設計: `devnotes/20260817-1748-enum-ts-generic-sync-gate/`
+
+---
+
+## D30 キャッシュ素データ規約の実行時層を、アプリ起動の前に結線し境界迂回を正典より広く塞ぐ
+
+| 行 | 内容 |
+|---|---|
+| 対象パス | `tests/Support/Cache/PlainDataCacheGuard.php` / `tests/Support/Cache/GuardedBoundaryProbe.php` / `tests/Architecture/CacheGuardWiringGateTest.php` |
+| 業務要件起因の説明 | 本アプリは起動時に名前付き流量制限を多数登録し、その時点で受け皿を握るため、Pest の beforeEach で結線すると起動中の書き込みが 2 層とも見えない穴になる。また同梱パッケージがオブジェクトをキャッシュへ入れる実装を持つため、受け皿を跨ぐ書き方を正典の 3 形より広く塞ぐ必要がある |
+| 揃え続ける不変条件と保証機構 | 結線がアプリ起動の前にあり全レーンが後始末すること (`CacheGuardWiringGateTest`)。受け皿を跨ぐ書き方と静的に解決できない生成が目録と exact-fit であること (`CachePayloadPlainDataGateTest` の検査 L4a-L4h) |
+| 再判定の条件 | 家系の正典が結線点と境界迂回の語彙を改めたとき / Laravel が `createApplication()` の本体を変えて写しが維持できなくなったとき |
+| 決めた日 | 2026-08-18 |
+| 決めた人 | 開発者 |
+| 根拠 | devnotes/20260818-1757-cache-runtime-plain-data-guard/ |
+| 状態 | 監視中 |
+| 見直し期限 | 2027-02-14 |
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 結線点 | Pest の beforeEach 相当 | アプリ起動の前 (`Tests\TestCase::createApplication()` の bootstrap 直前) |
+| 境界迂回の語彙 | 保管先の直接取得・受け皿の直接生成・拡張登録の 3 形 | 上記に加えて `setStore` / `tags` / macro 系 / 具体 store の生成 / 静的に解決できない生成 |
+| 迂回の判定 | 0 件 | 通常経路 0 件 + 実行時層の自己テストだけを名指しの目録へ exact-fit |
+| 継承・実装の宣言 | 対象外 | 受け手型・保管先型・実行時層の実装クラスの継承を**別の名指し目録**で扱い、実行時層の実装 2 本だけを許す |
+| 静的に解決できない生成 (`new $class`) | 対象外 | 走査根の全体で deny-by-default にし、キャッシュの保管先ではない既知の用途を**理由付きの目録**へ exact-fit で登録する |
+| 目録の構造 | 書き込みサイトの全数申告目録 | 既存の L1-L3 に L4 (迂回) を足す形 |
+| ArrayAccess 書き込み | 検出しない | `$cache[$k] = $v` を静的にも検出する |
+
+### なぜ正当な差分か (logic-driven)
+
+`AppServiceProvider::boot()` が名前付き流量制限を多数登録するため、`Illuminate\Cache\RateLimiter` は
+**起動中に** cache を解決して受け皿を握る。beforeEach で結線すると RateLimiter が握るのは
+guard の付いていない受け皿になり、起動中の書き込みは実行時層に見えない。
+vendor 由来の書き込みは静的層の走査根 (`app` / `routes` / `database` / `tests`) にも入らないので、
+**2 層とも沈黙する**。`Illuminate\Foundation\Testing\TestCase::createApplication()` は
+`bootstrap/app.php` を require したあと `bootstrap()` を呼ぶ間に**まだ起動していない `$app`** に
+触れる唯一の点なので、そこを override して結線する。
+
+境界迂回を広げたのは、`Repository::tags()` が `new TaggedCache($this->store, ...)` を素で生成して
+継承を素通りすること、`Repository` が `Macroable` を use しており macro の closure から
+`$this->store` へ直接到達できることを vendor 実読で確認したためである。
+どちらも実行時層の被覆から抜ける口であり、正典の 3 形には含まれていない。
+
+### 揃えている不変条件 (これは保証し続ける)
+
+> 「テストが実行したキャッシュ書き込みの値は、保管先へ渡る前に素データであることを検査されている」
+
+- 結線がアプリ起動の前にあることと、全レーンが後始末することは `CacheGuardWiringGateTest` が固定する
+- vendor の `createApplication()` の写しは token 列の完全一致で pin するので、静かに古くならない
+- 受け皿を跨ぐ書き方は自己テスト目録と exact-fit で、1 件増えたら必ず赤くなる
+
+### 保証しないもの
+
+- 保証しないものの正本は `tests/Support/Cache/PlainDataCacheGuard.php` の docblock である
+  (本書と `docs/architecture.md` には写さない)
+
+### 関連
+
+- 実装: `tests/Support/Cache/` / `tests/TestCase.php` / `tests/Pest.php` /
+  `tests/Architecture/CachePayloadPlainDataGateTest.php`
+- 設計: `devnotes/20260818-1757-cache-runtime-plain-data-guard/`

```

## 追加した正負コントロール

- 静的 gate
  - 負例「guard 実装クラスの継承を 4 形とも検出する」— 取り込み / 完全修飾名 /
    **同一名前空間の短名** / `namespace\Foo` の相対参照
  - 正例「完全修飾名 / 別名 / 同一名前空間の短名 / 相対参照が同じ完全修飾名へ解決する」
    + 「名前空間の中の裸の名前は global へ落とさない」+ 名前空間抽出の正負
- 結線 gate
  - 負例「reset が別の try 文の finally にある」(flush を持つ try は catch で return する形)
  - 負例「`$trait = WithCachedConfig::class; uses($trait);`」→ `UNRESOLVED_USES` で落ちる
  - 正例「`uses(TestCase::class, RefreshDatabase::class)`」→ 未解決にしない

## テスト結果 (Round 4 時点。全検証コマンドを再実行済み)

```
composer test            : 5901 tests / 5899 passed / 0 failed / 2 skipped
composer phpstan         : No errors
vendor/bin/pint --test   : passed
pnpm lint                : passed
pnpm typecheck           : passed
pnpm test                : 165 files / 2224 tests passed
pnpm build               : built
pnpm typecheck:packages  : passed
pnpm build:packages      : passed
pnpm test:packages       : 10 files / 106 tests passed
composer test:browser    : chromium 32 passed 3 skipped / webkit 31 passed 4 skipped
```
