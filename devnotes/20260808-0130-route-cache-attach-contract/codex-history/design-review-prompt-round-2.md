# 詳細設計レビュー Round 2

Round 1 の指摘への対応マトリクスと、修正後の該当箇所を示す。全体判定の再評価を求める。

## 対応マトリクス

# 対応マトリクス: design-review Round 1

Codex 全体判定: **CHANGES_REQUESTED**。施策 1 / 3 / 4 が REQUEST_CHANGES、2 / 5 / 6 / 7 は APPROVE。

## [Warning] 施策 1: `attachOnBooted()` が cached 起動でも `$specResolver()` を呼ぶ

- 判断: **対応する（ただし Codex 案より強い形にする）**
- 根拠: 指摘は正しい。「後付け入口を binder に集約して T120 を構造的に防ぐ」と主張するなら、
  cached 起動で **resolver 実行にも到達しない**のが契約でなければならない。
  ただし Codex の修正案（booted closure の中で早期 return し、`attachAll(..., false)` を
  リテラルで渡す）は、**保証を配線側 closure に置く**ため、純粋関数だけを見ても
  「resolver が呼ばれない」ことを検証できない（Application stub が要る = 施策 4 の指摘に繋がる）。
- 対応内容: `attachAll()` の第 2 引数を **`callable(): array` に変える**。
  `attachAll(Router $router, callable $specResolver, bool $routesAreCached)` が
  `$routesAreCached` のとき **resolver を呼ぶ前に** early return する。
  こうすると:
  1. `attachOnBooted()` は resolver をそのまま渡すだけになり、**構造的に前倒し評価できない**。
  2. 「cached では resolver が呼ばれない」ことが**純粋関数のテストで直接固定できる**
     （Application stub 不要。施策 4 の Warning も同時に解消する）。
  `RouteThrottleBinder::attachAll()` は array のままで揃わなくなるが、あちらは
  spec に副作用がなく（定数表）resolver を持たないため、無理に合わせない
  （思考原則 4: 似ているからで統合しない）。docblock に差分の理由を書く。

## [Warning] 施策 3: `RouteMiddlewareBinder` の import 追加が明記されていない

- 判断: **対応する**
- 根拠: 実装時に未解決クラスで落ちる。設計書の記述漏れ。
- 対応内容: 施策 3 に `use App\Support\Http\RouteMiddlewareBinder;` /
  `use Laravel\Fortify\Features;` の追加を明記した。施策 2 側も同様に明記した。

## [Warning] 施策 4: T120 恒久回帰テストが `attachAll()` 直叩きに寄っている

- 判断: **対応する（方式は変更）**
- 根拠: 指摘の本質は「実リスクは配線側にある」。上記の型変更（resolver を `attachAll` が受ける）
  により、**実リスクそのものが純粋関数の中へ移る**ため、Application stub を作らずに
  同じ保証が取れる。stub を増やさない分だけ単純になる（禁止事項 6）。
- 対応内容: 施策 4 のテスト #1 を
  「`routesAreCached: true` のとき、**呼ばれたら throw する resolver** を渡しても
  例外が出ず middleware も増えない」に差し替えた。あわせて #1b として
  「`attachOnBooted()` は resolver を**そのまま**渡す（配線点で先に呼ばない）」ことを
  型シグネチャで担保している旨を設計に明記した。

## [Suggestion] 施策 2 / 3: `static fn (): array` より first-class callable を使え

- 判断: **対応する**
- 根拠: `self::recentAuthRouteSpecs(...)` は元メソッドの `@return array<string, list<string>>`
  をそのまま持ち込むため、level 10 で iterable value type 不足を指摘される余地が消える。
  記述も短い。
- 対応内容: 施策 2 / 3 の呼び出しを first-class callable 記法へ変更した。

## [Suggestion] 施策 5: false positive が出たら `token_get_all()` でコメント除外に寄せればよい

- 判断: **見送る**
- 根拠: Codex 自身が「現時点で先回りする必要はない」と書いている。思考原則 2。
  ただし**現時点で false positive が出ないこと**は確認済み
  （`getByName(` / `refreshNameLookups(` の出現は `app/` 全体で 3 ファイル・7 箇所のみで、
  すべて実コード）。設計にその実測を残して、将来の判断材料にする。
- 対応内容: 施策 5 に実測（7 箇所・3 ファイル）を追記した。

## [Suggestion] 施策 6: 「cached 起動では named route を 1 本も解決できない」に “binder callback が走る時点では” を補え

- 判断: **対応する**
- 根拠: 正確性の指摘として妥当。compiled routes は**後で**読まれるので、
  「絶対に存在しない」と読ませると次の誤読を生む（本設計が潰したい failure mode そのもの）。
- 対応内容: §7c の文面と `RouteMiddlewareBinder` の docblock の両方を
  「**この callback が走る時点では**」を含む表現に修正した。

## [Suggestion] 施策 2 の feature flag 対応付けは正しい / 施策 5・6・7 は妥当

- 判断: **見送る（変更なし）**
- 根拠: 元設計の結論と一致。


## 修正後の該当箇所（差分の要点）

### 施策 1: `attachAll()` が resolver を受け取る形へ変更（Round 1 [Warning] の対応）

```php
    /**
     * 起動完了後に named route 群へ middleware alias を後付けする（登録の唯一の入口）。
     *
     * ★spec を **resolver（callable）で受け、ここでは呼ばない**。理由は 2 つ:
     *   1. 呼び出し側の feature flag 判定を `boot()` へ前倒ししないため。
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      resolver をそのまま {@see attachAll} へ渡すことで、この前倒しが
     *      **型として不可能**になる。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     */
    public static function attachOnBooted(Application $app, callable $specResolver): void
    {
        $app->booted(static function (Application $app) use ($specResolver): void {
            self::attachAll(
                $app->make(Router::class),
                $specResolver,
                $app instanceof CachesRoutes && $app->routesAreCached(),
            );
        });
    }

    /**
     * ★`RouteThrottleBinder::attachAll()` は spec を **array** で受けるが、こちらは
     *   **resolver** で受ける。揃っていないのは意図である — あちらの spec は副作用の無い
     *   定数表で cached 起動でも評価してよいが、こちらは feature flag 判定を含み、
     *   「cached では resolver すら呼ばない」ことを保証したいため。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     */
    public static function attachAll(Router $router, callable $specResolver, bool $routesAreCached): void
    {
        if ($routesAreCached) {
            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
            //   到達させない（到達させると T120 型の事故を再導入する余地が残る）。
            return;
        }

        $routes = $router->getRoutes();
        $routes->refreshNameLookups();

        foreach ($specResolver() as $name => $aliases) {
            self::attachByName($routes, $name, $aliases);
        }
    }
```

**Codex 案（booted closure 内で早期 return し `attachAll(..., false)` をリテラルで渡す）を
採らなかった理由**: 保証が配線側 closure に残り、純粋関数だけでは検証できない
（Application stub が要る）。resolver を `attachAll` が受ける形にすると、保証が
**純粋関数の内側**へ移り、施策 4 の Warning も stub 無しで同時に解消する。

### 施策 1: docblock の正確化（Round 1 [Suggestion] の対応）

```
 *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
 *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
 *            時点では対象 named route が 1 本も登録されていない**
 *            （compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
 *            ここを誤読すると次の担当がまた別の誤った結論に着く）。
```

§7c 側も同じ表現に修正済み。

### 施策 2 / 3: first-class callable + use 文の明示（Round 1 [Suggestion] / [Warning] の対応）

```php
// FortifyServiceProvider
        // first-class callable で渡す（`static fn (): array => …` にすると
        // 匿名関数の戻り値に iterable value type が付かず PHPStan level 10 で詰まる。
        // メソッド参照なら recentAuthRouteSpecs() の @return がそのまま効く）
        RouteMiddlewareBinder::attachOnBooted($this->app, self::recentAuthRouteSpecs(...));

// PasskeyServiceProvider
        RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
```

use 文の増減を両施策に明示した:

- FortifyServiceProvider: 追加 = `App\Support\Http\RouteMiddlewareBinder`。
  削除候補 = `Illuminate\Routing\RouteCollectionInterface` / `Illuminate\Routing\Router` /
  `Illuminate\Contracts\Foundation\Application`。`Laravel\Fortify\Features` は import 済み。
- PasskeyServiceProvider: 追加 = `App\Support\Http\RouteMiddlewareBinder` /
  `Laravel\Fortify\Features`。削除候補 = `Illuminate\Routing\RouteCollectionInterface` /
  `Illuminate\Routing\Router`。`Illuminate\Support\Facades\Route` は `Route::bind` で継続使用。

### 施策 4: T120 恒久回帰テストの差し替え（Round 1 [Warning] の対応）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `routesAreCached: true では resolver すら呼ばれない` | **呼ばれたら `RuntimeException` を投げる resolver** を渡して `attachAll($router, $resolver, routesAreCached: true)` が例外なく完了する。**= T120 の恒久回帰**。resolver に到達しない ⇒ route 解決にも到達しない、を 1 本で表明できる |
| 1b | `routesAreCached: true では middleware が 1 本も増えない` | probe route を含む spec を返す resolver を渡しても `middleware()` が不変 |

#2〜#7 は Round 1 のまま（fail-fast / 1 本増える / 冪等 / 列順 / computedMiddleware 破棄 / 不変）。

### 施策 5: 実測の追記（Round 1 [Suggestion] は見送り）

`token_get_all()` によるコメント除外は入れない。理由は Codex 自身の「現時点で先回りする
必要はない」と思考原則 2。あわせて実測を設計へ残した — `getByName(` /
`refreshNameLookups(` の出現は `app/` 全体で **3 ファイル・7 箇所**
（`FortifyServiceProvider` 3 / `PasskeyServiceProvider` 2 / `RouteThrottleBinder` 2）で
すべて実コード。false positive はゼロ。

## 再評価してほしい点

1. 施策 1 の新しい形（resolver を `attachAll` が受ける）で T120 再発の余地が閉じたか。
   まだ抜けがあれば具体的に指摘すること。
2. `RouteThrottleBinder::attachAll()`（array 受け）と `RouteMiddlewareBinder::attachAll()`
   （resolver 受け）でシグネチャが揃わないことを許容した判断は妥当か。
   （揃えるために `RouteThrottleBinder` を触るのは「正しい記述を触らない」方針と
   T120 / T121 で固めた目録検査との整合を壊すリスクがあるため採らない）
3. 他に Critical / Warning が残っているか。無ければ全体判定 APPROVED を出すこと。
