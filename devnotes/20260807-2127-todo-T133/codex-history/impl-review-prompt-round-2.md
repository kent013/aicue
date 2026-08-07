# impl-review Round 2 (T133) — Round 1 指摘への対応

Round 1 の [Critical] ×2 / [Warning] ×1 をすべて修正した。以下に対応マトリクスと、変更後の該当コード全文、追加した負のコントロール fixture、再実測結果を示す。**指摘を却下したものは無い**。

# 対応マトリクス: impl-review Round 1

## [Critical] `cachePayloadResolveName()` が namespace alias 付き qualified name を解決できない

- 判断: **対応する**
- 根拠: 指摘のとおり実装のバグ。`elseif (! str_contains($name, '\\'))` は「`\` を含まない
  名前に対して head 展開する」という**恒真に近い無意味な分岐**で、head 展開が必要な
  qualified name (`Facades\Cache`) にはそもそも到達しなかった。さらに到達したとしても
  `$name = $useMap[$head]` は残り (`\Cache`) を捨てるため `Illuminate\Support\Facades` に潰れる。
  この形は L2 (書き込み経路) だけでなく **L3 (面) からも消える**ので、
  「無申告でキャッシュ書き込みを増やせない」という gate の中核の主張が崩れる。
- 対応内容:
  - 条件を `str_contains($name, '\\')` に反転し、`strstr($name, '\\', true)` で head を取り、
    `$useMap[$head] . substr($name, strlen($head))` と**残りを連結**して解決するよう修正。
  - 負のコントロール fixture を追加 (`use Illuminate\Support\Facades as Facades;` の
    facade 形と `use Illuminate\Contracts\Cache as CacheContract;` の DI 形の 2 通り)。
  - mutation M14 (新規ファイルで `Facades\Cache::put(..., new \stdClass, ...)`) で
    検査 2 / 検査 4 が赤くなることを実測。
- 誇張の抑制: 完全な alias 解決を主張しない。head が use 表にある場合のみ展開する
  (group use は依然として非対応で、その旨は冒頭コメントの限界に明記済み)。

## [Critical] `app()->make('cache')->put(...)` が完全に見落とされる

- 判断: **対応する**
- 根拠: 指摘のとおり。`app` の 0 引数呼び出しは「第 1 引数が cache 束縛か」の判定に
  一致せず、続く `make` は `isMemberName` で捨てられていた。string 束縛の場合は
  import も型宣言も現れないため **L3 の粗い網にも掛からない**。実測 0 件だが、
  `app('cache')` と表記上ほぼ等価な書き方が素通りするのは「受け手を解決してから
  メソッド名を見る」という母集団定義の穴であり、限界として受容できる性質ではない。
- 対応内容:
  - コンテナ束縛判定を `cachePayloadIsCacheBindingArg()` に抽出して再利用可能にした。
  - `cachePayloadContainerMakeChain()` を新設し、`app()` (引数 0 個) → `->make(...)` /
    `->makeWith(...)` / `->get(...)` の第 1 引数が cache 束縛 literal (または受け手型の
    `::class`) のときだけ、その直後を受け手として連鎖を開始する。
  - 負のコントロール fixture (`app()->make('cache')` / `app()->make(Repository::class)` /
    `app()->get('cache.store')`) を追加。mutation M15 で赤化を実測。
- 適用範囲を広げすぎない判断: `$container->make($name)` のように**束縛名が変数**の形は
  従来どおり検出しない (静的に決まらない)。冒頭コメントの「保証しないもの」に既に明記済みで、
  今回この限界は変えていない。

## [Warning] `getFacadeRoot` を TERMINAL にしているため `Cache::getFacadeRoot()->put(...)` を追跡しない

- 判断: **対応する**
- 根拠: `getFacadeRoot()` は facade の**実体 (CacheManager)** を返すので、後続の `put` は
  本物の書き込みである。Warning 扱いだが、指摘のとおり **既に role=write のファイル
  (`FxRateService`) に足された場合は L3 でも捕まらず、write count も増えないため緑のまま**通る。
  これは「見落とし方向」の穴なので Warning でも修正する。
- 対応内容: `getfacaderoot` を TERMINAL から CHAIN へ移し (語彙表は検査 6b で互いに素を強制)、
  負のコントロール fixture を追加。mutation M16 (**既存 write ファイル内**への追加) で
  検査 2 が赤くなることを実測した。
- 注: Mockery 系 (`shouldReceive` / `spy` / `expects` 等) は TERMINAL のまま。こちらは
  受け手が期待値ビルダーに変わり payload を書かないので、CHAIN に寄せる理由がない。

---

## 変更後コード (該当箇所の全文)

### 1. 名前解決 (alias 展開)

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
function cachePayloadResolveName(string $raw, array $useMap): string
{
    $name = ltrim($raw, '\\');
    if (isset($useMap[$name])) {
        $name = $useMap[$name];
    } elseif (str_contains($name, '\\')) {
        $head = strstr($name, '\\', true);
        if (is_string($head) && isset($useMap[$head])) {
            $name = $useMap[$head].substr($name, strlen($head));
        }
    }

    // 名前空間を持たない `Cache` は class alias 経由の facade (`use Cache;` を含む)
    if (! str_contains($name, '\\') && strtolower($name) === 'cache') {
        return 'Illuminate\Support\Facades\Cache';
    }

    return $name;
}
```

### 2. コンテナ束縛判定の抽出と app()->make(...) 追跡

```php
/**
 * コンテナ解決の第 1 引数がキャッシュ束縛かどうか。
 *
 * `'cache'` / `'cache.store'` の literal、または受け手型の `::class` のときだけ true。
 * bind 名が変数の形は静的に決まらないので false (冒頭コメントの「保証しないもの」)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 */
function cachePayloadIsCacheBindingArg(array $tokens, ?int $firstArg, array $useMap): bool
{
    if ($firstArg === null) {
        return false;
    }
    if ($tokens[$firstArg]->is(T_CONSTANT_ENCAPSED_STRING)) {
        return in_array(trim($tokens[$firstArg]->text, "'\""), ['cache', 'cache.store'], true);
    }
    if (! $tokens[$firstArg]->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
        return false;
    }

    // app(Repository::class) 形。`::class` であることまで確認する
    $colon = cachePayloadNext($tokens, $firstArg + 1);
    $classToken = $colon === null ? null : cachePayloadNext($tokens, $colon + 1);
    $isClassConst = $colon !== null && $tokens[$colon]->is(T_DOUBLE_COLON)
        && $classToken !== null && strtolower($tokens[$classToken]->text) === 'class';

    return $isClassConst && in_array(
        cachePayloadResolveName($tokens[$firstArg]->text, $useMap),
        CACHE_PAYLOAD_RECEIVER_TYPES,
        true
    );
}

/**
 * `app()` (引数 0 個 = コンテナそのもの) から `->make('cache')` / `->get('cache')` を辿り、
 * キャッシュ受け手になった直後の `->` の index を返す。該当しなければ null。
 *
 * ★`app('cache')->put(...)` と違い `app()->make('cache')->put(...)` は
 *   受け手が member 名 (`make`) 側に現れるため、literal 束縛の判定を別経路で持たないと
 *   **L2 でも L3 でも丸ごと素通り**する (impl-review Round 1 [Critical] 反映)。
 *
 * @param  list<PhpToken>  $tokens
 * @param  array<string, string>  $useMap
 * @param  int  $closeIndex  `app()` の閉じ括弧 index
 */
function cachePayloadContainerMakeChain(array $tokens, int $closeIndex, array $useMap): ?int
{
    $arrow = cachePayloadNext($tokens, $closeIndex + 1);
    if ($arrow === null || ! $tokens[$arrow]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return null;
    }
    $method = cachePayloadNext($tokens, $arrow + 1);
    if ($method === null || ! $tokens[$method]->is(T_STRING)
        || ! in_array(strtolower($tokens[$method]->text), ['make', 'makewith', 'get'], true)) {
        return null;
    }
    $open = cachePayloadNext($tokens, $method + 1);
    if ($open === null || $tokens[$open]->text !== '(') {
        return null;
    }
    if (! cachePayloadIsCacheBindingArg($tokens, cachePayloadNext($tokens, $open + 1), $useMap)) {
        return null;
    }
    $close = cachePayloadMatchingParen($tokens, $open);
    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
    if ($next === null || ! $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
        return null;
    }

    return $next;
}
```

呼び出し側 (collectFromSource 内の container 分岐):

```php
            if (! $isMemberName && in_array($lower, ['app', 'resolve', 'make'], true)) {
                $open = cachePayloadNext($tokens, $i + 1);
                $hasParen = $open !== null && $tokens[$open]->text === '(';
                $firstArg = $hasParen ? cachePayloadNext($tokens, $open + 1) : null;
                $close = $hasParen && $open !== null ? cachePayloadMatchingParen($tokens, $open) : null;

                if ($hasParen && cachePayloadIsCacheBindingArg($tokens, $firstArg, $useMap)) {
                    // app('cache')->put(...) / app(Repository::class)->put(...)
                    $surface = true;
                    $next = $close === null ? null : cachePayloadNext($tokens, $close + 1);
                    if ($next !== null && $tokens[$next]->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR])) {
                        $operatorIndex = $next;
                    }
                } elseif ($hasParen && $close !== null && $firstArg === $close && $lower === 'app') {
                    // app()->make('cache')->put(...) 形。コンテナ経由の 1 段追加
                    $chained = cachePayloadContainerMakeChain($tokens, $close, $useMap);
                    if ($chained !== null) {
                        $surface = true;
                        $operatorIndex = $chained;
                    }
                }
            }
```

### 3. 語彙表 (getFacadeRoot を TERMINAL → CHAIN)

```php
const CACHE_PAYLOAD_CHAIN_METHODS = ['store', 'driver', 'tags', 'resolve', 'getstore', 'getfacaderoot'];

/**
 * 受け手がキャッシュでなくなる terminal (以降の連鎖を辿らない)。
 *
 * `getFacadeRoot` はここに置かない — facade の**実体 (CacheManager)** を返すので
 * `Cache::getFacadeRoot()->put(...)` は本物の書き込みになる。CHAIN 側に置く
 * (impl-review Round 1 [Warning] 反映)。
 *
 * @var list<string>
 */
const CACHE_PAYLOAD_TERMINAL_METHODS = [
    'lock', 'restorelock', 'shouldreceive', 'spy', 'partialmock', 'swap',
    'expects', 'shouldhavereceived', 'shouldnothavereceived',
];
```

### 4. 追加した負のコントロール fixture (3 本)

```php
test('負のコントロール: 名前空間 alias 経由の qualified name も受け手として解決する', function (): void {
    // ★`use A\B as X;` + `X\Cache::put(...)` は head を alias 展開しないと受け手から落ちる。
    //   L2 だけでなく L3 (面) からも消えるため、無申告でキャッシュ書き込みを増やせてしまう
    //   (impl-review Round 1 [Critical] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades as Facades;
    use Illuminate\Contracts\Cache as CacheContract;
    class Fixture {
        public function __construct(private readonly CacheContract\Repository $cache) {}
        public function run(): void {
            Facades\Cache::put('a', [1], 60);
            $this->cache->forever('b', [1]);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(2);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: app()->make(...) 経由のコンテナ解決も検出する', function (): void {
    // ★`app('cache')->put()` と違い受け手が member 名 (`make`) の側に現れる形。
    //   専用経路を持たないと L2 でも L3 でも丸ごと素通りする (impl-review Round 1 [Critical] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Contracts\Cache\Repository;
    class Fixture {
        public function run(): void {
            app()->make('cache')->put('a', [1], 60);
            app()->make(Repository::class)->forever('b', [1]);
            app()->get('cache.store')->add('c', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(3);
    expect($result['unclassified'])->toBe([]);
    expect($result['surface'])->toBeTrue();
});

test('負のコントロール: Cache::getFacadeRoot() の後続書き込みを検出する', function (): void {
    // ★getFacadeRoot() は facade の**実体 (CacheManager)** を返すので put が本物の書き込みになる。
    //   TERMINAL に置くと同一ファイル内で write count を増やさずに書き込みを足せてしまう
    //   (impl-review Round 1 [Warning] 反映)。
    $fixture = <<<'PHP'
    <?php
    namespace App\Demo;
    use Illuminate\Support\Facades\Cache;
    class Fixture {
        public function run(): void {
            Cache::getFacadeRoot()->put('a', [1], 60);
        }
    }
    PHP;

    $result = cachePayloadCollectFromSource($fixture, 'fixture.php');
    expect($result['writes'])->toHaveCount(1);
    expect($result['unclassified'])->toBe([]);
});
```

---

## 再実測

- gate 単体: `tests/Architecture/CachePayloadPlainDataGateTest.php` → **25 tests passed / 0 failed**
  (fixture 3 本追加で 22 → 25)
- `composer test` (全件・グローバルロック配下・`--parallel`):
  `tests=3729 passed=3727 skipped=2 failed=0 assertions=15034`
- `composer phpstan` (level 10, 809 files): `No errors`
- `vendor/bin/pint --test`: `passed`

### 追加 mutation (修正が効いていることの実測。注入 → 赤を確認 → revert)

| # | 注入 | 期待 | 実測 |
|---|------|------|------|
| M14 | 新規ファイルで `use Illuminate\Support\Facades as Facades;` + `Facades\Cache::put('k', new \stdClass, 60);` | 検査 2 + 検査 4 | 25 tests / 2 failed: 検査 2, 検査 4 |
| M15 | 新規ファイルで `app()->make('cache')->put('k', new \stdClass, 60);` | 検査 2 + 検査 4 | 2 failed: 検査 2, 検査 4 |
| M16 | **既に role=write の** `FxRateService` 内に `Cache::getFacadeRoot()->put('k', new \stdClass, 60);` を追加 | 検査 2 (面は既存なので検査 4 は緑) | 1 failed: 検査 2 |

M16 は「L3 (面) では捕まらない = 既存 write ファイル内での追加」という最も見落としやすい形で、
`getFacadeRoot` を TERMINAL のまま残していたら**緑のまま通っていた**ケースである。
全 mutation は revert 済みで、revert 後 25 tests passed を確認している。

## 残している限界 (誇張しないための明示)

冒頭コメントの「保証しないもの」は以下のまま変えていない。今回の修正で縮んだのは
「alias 展開」と「`app()->make(literal)`」の 2 つだけである。

- payload の**式が本当に素データか**は静的に判定しない (目録の `payload` 欄は人間の申告)
- **束縛名が変数**のコンテナ解決 (`$container->make($name)->put(...)`) は受け手を解決できない
- **docblock だけで型付けされた受け手**は型宣言を見ていないので追わない
- group use 構文 (`use A\{B, C};`) は扱わない (実測 0 件 / `NoNonCompoundGlobalUseTest` が別途縛る)
- Mockery 系 (`shouldReceive` / `spy` / `expects`) は TERMINAL のまま。受け手が期待値ビルダーに
  変わり payload を書かないため。ただし当該ファイルは L3 (面) に必ず現れるので無申告では増やせない

この 3 点の対応で **APPROVED / CHANGES_REQUESTED** の最終判定を出してほしい。
新たな見落とし方向の穴があれば具体的な入力例 (書けば素通りする PHP コード片) つきで指摘してほしい。
