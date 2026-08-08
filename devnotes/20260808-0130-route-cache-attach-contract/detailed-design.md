# 詳細設計: route-cache-attach-contract

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  （撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- テストデータは必ず Factory で生成（本設計では DB を触らないため該当なし）
- **DTO + JsonResource** パターン（本設計では HTTP 応答を作らないため該当なし）
- `declare(strict_types=1)` + 日本語コメント / アーリーリターン推奨
- **コードフォーマット**: `composer fix`（Pint）
- PHP 8.4 + Laravel 12

## 概念設計リファレンス

- `devnotes/20260808-0130-route-cache-attach-contract/conceptual-design.md`（APPROVED / Round 1）
- 一次入力: `devnotes/20260808-0130-route-cache-attach-contract/recon-brief.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `RouteMiddlewareBinder` の新設（skip 判定を引数で受ける純粋関数 + booted 配線） | `app/Support/Http/RouteMiddlewareBinder.php`（新規） | 高 |
| 2 | `FortifyServiceProvider` の後付けを binder 経由へ + docblock 是正 | `app/Providers/FortifyServiceProvider.php` | 高 |
| 3 | `PasskeyServiceProvider` の後付けを binder 経由へ + docblock 是正（`Route::bind` の区別を明記） | `app/Providers/PasskeyServiceProvider.php` | 高 |
| 4 | binder の契約テスト（T120 恒久回帰を含む） | `tests/Feature/Security/RouteMiddlewareBinderTest.php`（新規） | 高 |
| 5 | 後付け経路の deny-by-default 目録 | `tests/Architecture/PostBootRouteMutationInventoryTest.php`（新規） | 中 |
| 6 | 運用契約の格上げ（§7c 新設 / §7b から参照） | `docs/app-integration-guide.md` | 中 |
| 7 | AGENTS.md に運用要件ブロック（デプロイ基盤未整備の明示を含む） | `AGENTS.md` | 中 |

**変更しないもの**（明示）:

- `app/Support/Http/RouteThrottleBinder.php` — 契約記述は家系で唯一正しい。統合も一般化もしない。
- `tests/Architecture/{ThrottleCoverageInventoryTest,ThrottleLaneAssignmentTest,InlineThrottleInventoryTest,RecentAuthRouteTest,TwoFactorStepUpInventoryTest,PasskeyRouteProtectionTest}.php`
  および `tests/Feature/Security/RouteThrottleBinderTest.php` — **1 行も変更しない**。
  これらが差分なしで green であることが「付与内容が変わっていない = 振る舞い不変」の直接の証拠になる。
- `app/Providers/AppServiceProvider.php` — throttle 後付けのみで、記述も既に正しい。§7c 参照の
  1 行追記は**行わない**（正しい記述を触らない。参照は `RouteThrottleBinder` の docblock が既に担う）。

---

## 施策 1: `RouteMiddlewareBinder` の新設

### 変更箇所

- ファイル: `app/Support/Http/RouteMiddlewareBinder.php`（新規）

### 波及変更

- TypeScript 型定義: なし（フロント差分ゼロ）
- API Resource/DTO: なし
- テストファイル: 施策 4（新規）/ 施策 5（新規）。既存テストの変更は**なし**

### 現行コード

該当なし（新規）。同等機能は `FortifyServiceProvider` と `PasskeyServiceProvider` に
private static `appendMiddlewareIfMissing()` として**重複実装**されている（下記 施策 2 / 3 参照）。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesRoutes;
use Illuminate\Routing\Route;
use Illuminate\Routing\RouteCollectionInterface;
use Illuminate\Routing\Router;
use RuntimeException;

/**
 * vendor が登録した named route へ **middleware alias** を後付けする binder。
 *
 * ★責務境界: throttle の後付けは {@see RouteThrottleBinder} が担当する（統合しない）。
 *   あちらは limiter の形式検証・二重付与検出という固有責務を持ち、ここは
 *   「alias を冪等に足す」だけである。両者は route:cache との契約だけを共有する。
 *
 * ★**2 つの事象を混ぜないこと**（ここが本 binder の存在理由）:
 *
 *   1. **生成時**（`php artisan route:cache` の実行中）
 *      `RouteCacheCommand::handle()` は先頭で `route:clear` してから
 *      `getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。
 *      そこでは `loadRoutesFrom()` が `require` を通すため本後付けが**完全に走り**、
 *      付与済み middleware がそのまま cache へ**焼き込まれる**。
 *      route 名が消えていれば**ここでデプロイが止まる**（fail-fast が効くのはここ）。
 *
 *   2. **起動時**（route cache がある状態でのリクエスト処理 / artisan）
 *      本後付けは **1 本も効かない**。理由は 2 つあり、片方だけでも成立する:
 *        (a) `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を
 *            飛ばす。Fortify / laravel-passkeys はこれを使うため、**この callback が走る
 *            時点では対象 named route が 1 本も登録されていない**
 *            （compiled routes は後で読まれるので「route が永久に存在しない」の意味ではない。
 *            ここを誤読すると次の担当がまた別の誤った結論に着く）。
 *        (b) 仮に触れていても、framework の `RouteServiceProvider` が本 callback より**後**の
 *            app-booted で compiled routes を読み、`Router::setCompiledRoutes()` が
 *            route collection を**新品へ丸ごと差し替える**ため捨てられる。
 *      よって cached 起動では `$routesAreCached` を見て**明示 skip** する。
 *      **ここで例外を投げてはならない**（`route:list` が必ず落ちる = T120 の事故）。
 *
 *   ⇒ **cached 起動での保護を持っているのは cache の中身である**。したがって
 *     **`php artisan route:cache` を毎デプロイ再生成することが本機構の前提条件**になる。
 *     stale な route cache は古い付与状態のまま起動し、**無音で保護が外れる**
 *     （実測: 剥がした cache では 2FA 秘密 GET が 409 でなく 200 を返す）。
 *     運用契約の正本は `docs/app-integration-guide.md` §7c。
 *
 * ★よくある誤読の否定（この記述を消さないこと）:
 *   `CompiledRouteCollection::getByName()` が Route instance を `nameCache` へ memoize し、
 *   `match()` がその `getByName()` を通るのは**事実**である。しかしそれは
 *   「**compiled collection が読まれた後に** getByName して書き換えた場合」の話であり、
 *   本 callback はその前に走って**別の collection** を見ているため前提が成立しない。
 *   「nameCache があるから cached 起動でも後付けが効く」とは書かない。
 */
final class RouteMiddlewareBinder
{
    /**
     * 起動完了後に named route 群へ middleware alias を後付けする（登録の唯一の入口）。
     *
     * ★spec を **resolver（callable）で受け、ここでは呼ばない**。理由は 2 つ:
     *   1. 呼び出し側の feature flag 判定を `boot()` へ前倒ししないため。現行 2 経路は
     *      判定タイミングが異なる（Fortify = boot 内 / Passkey = booted 内）ので、
     *      resolver を booted の中で解決することで**どちらのタイミングも変えない**。
     *   2. **cached 起動では resolver 自体を実行しない**ため。resolver をここで呼んで
     *      配列にしてから渡す形にすると、将来 resolver が route collection を覗く実装に
     *      なったとき early return の**前**に落ちる = T120 の再導入になる。
     *      ★ただし「型で不可能」なわけではない（`$specs = $specResolver();` してから
     *      `static fn () => $specs` を渡す退行は型検査を通る）。**誇張しない**。
     *      この配線が前倒し評価へ退行していないことは
     *      `RouteMiddlewareBinderTest` の配線テスト（`routes.cached` を true に束ねて
     *      「呼ばれたら throw する resolver」を渡す）が**振る舞いで**固定する。
     *
     * @param  callable(): array<string, list<string>>  $specResolver
     *         route 名 => 付与する middleware alias の列
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
     * named route 群へ middleware alias を後付けする（`$routesAreCached` なら何もしない）。
     *
     * skip 判定を**引数で受ける**ことで、判定と後付けの両方を純粋関数として検証できる
     * （{@see attachOnBooted} が実アプリの状態を渡す唯一の配線点）。
     *
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
            // 後付けは route:cache 生成時に焼き込み済み。
            // ★**resolver を呼ぶ前に**返すこと。route 解決はもちろん spec の構築にも
            //   到達させない（到達させると T120 型の事故を再導入する余地が残る）。
            return;
        }

        $routes = $router->getRoutes();
        // fluent な ->name() 付与は name index に遅延反映されるため明示 refresh
        $routes->refreshNameLookups();

        foreach ($specResolver() as $name => $aliases) {
            self::attachByName($routes, $name, $aliases);
        }
    }

    /**
     * named route へ middleware alias 群を冪等に後付けする。
     *
     * @param  list<string>  $aliases
     *
     * @throws RuntimeException route が引けない場合（= 無防備なまま公開される事故を止める）
     */
    public static function attachByName(RouteCollectionInterface $routes, string $routeName, array $aliases): void
    {
        $route = $routes->getByName($routeName);
        if (! $route instanceof Route) {
            throw new RuntimeException(
                "middleware を後付けすべき route [{$routeName}] が見つかりません。"
                .'vendor package が update で route 名を変えたか、feature flag が無効化された'
                .'可能性があります（無効化が正しいなら呼び出し側の spec から外すこと）。'
                .'無防備なまま公開される事故を防ぐため fail-fast で起動を止めます。',
            );
        }

        $changed = false;
        foreach ($aliases as $alias) {
            if (in_array($alias, $route->middleware(), true)) {
                continue; // 冪等（同一 bootstrap 内の重複呼び出し / 既に定義側で貼られている）
            }

            $route->middleware($alias);
            $changed = true;
        }

        if ($changed) {
            // ★memo の破棄（RouteThrottleBinder と同じ作法）。
            //   **本経路では現時点 no-op である**ことを実読で確認している
            //   （ここは gatherMiddleware() を呼ばないため $computedMiddleware は温まらない）。
            //   それでも置くのは、この memo を取りこぼしたときの壊れ方が
            //   「middleware() には載るのに dispatch では実行されない = 無音の無防備」であり、
            //   本 binder が潰そうとしている失敗形そのものだから。
            $route->computedMiddleware = null;
        }
    }
}
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（すべて `void`）
- [x] null 安全: `getByName()` の戻りを `instanceof Route` で絞ってから使う
      （`RouteThrottleBinder::attachByName()` と同じ形）
- [x] DTO を返している: 該当なし（値を返さない）
- [x] Generics の型パラメータ: `array<string, list<string>>` /
      `callable(): array<string, list<string>>` を phpdoc で明示。
      `Route::$computedMiddleware` は framework の public property（`RouteThrottleBinder`
      が既に同じ代入を level 10 で通している）

### テスト計画

施策 4 で全面カバー（下記）。

### リスク

- **唯一の振る舞い変化**: 非 cached 起動で spec の route が引けないとき、
  無音 no-op → `RuntimeException`。呼び出し側が feature flag を正しく反映していないと
  起動しなくなる。→ 施策 2 / 3 で feature 条件を明示的に持たせ、施策 4 のテストで固定する。
- cached 起動では skip するため、T120 の事故（`route:list` が落ちる）は起こらない。
  ただし「構造的に不可能」とは書かない（配線側で resolver を前倒し評価する退行は
  型検査では止まらない）。**純粋関数の契約（#1）と配線の振る舞い（#8 / #8b）を
  2 本で固定する**のが本設計の担保である。
- **`attachOnBooted()` に skip 判定を二重に置くことはしない**。skip の決定点を
  `attachAll()` の 1 箇所に保つ（二重化すると配線側の flag が実質デッドになり、
  「どちらが正なのか」が次の担当に読めなくなる）。退行の検出は #8 / #8b が担う。

---

## 施策 2: `FortifyServiceProvider` の後付けを binder 経由へ

### 変更箇所

- ファイル: `app/Providers/FortifyServiceProvider.php`
  （L225-266 = `attachRecentAuthToSensitiveRoutes()` と `appendMiddlewareIfMissing()`）

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 既存の `RecentAuthRouteTest` / `TwoFactorStepUpInventoryTest` /
  `PasswordConfirmMiddlewareAbsenceTest` は**変更しない**（差分なしで green であることが回帰）

### 現行コード

```php
    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。route:cache 下でも
     * CompiledRouteCollection::getByName() が nameCache に memoize した同一 instance を
     * match() が返すため、この変更は dispatch にも有効。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        $this->app->booted(static function (Application $app): void {
            $routes = $app->make(Router::class)->getRoutes();
            // fluent な ->name() 付与はコレクションの name index に遅延反映のため明示 refresh
            $routes->refreshNameLookups();

            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
            }

            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                self::appendMiddlewareIfMissing($routes, $name, $alias);
            }
        });
    }

    /**
     * named route に middleware alias を idempotent に append する (未登録時のみ)。
     *
     * booted callback (static クロージャ) から呼ぶため **static** で定義し
     * `self::appendMiddlewareIfMissing(...)` で呼ぶ。長寿命プロセス等で callback が
     * 同一 Route instance に複数回届いても重複付与しない (idempotent)。
     */
    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
```

> `L232-234` の「route:cache 下でも … dispatch にも有効」が**是正対象の誤り**。
> `nameCache` の性質の記述自体は正しいが、この callback は compiled collection に到達しない。

### 変更後コード

```php
    /**
     * Fortify が登録する機微な 2FA 管理ルートへ recent-auth middleware を後付けする。
     *
     * Fortify 標準の password.confirm は generic recent-auth へ置換済み
     * (config/fortify.php features.twoFactorAuthentication.confirmPassword=false) のため、
     * そのままではリカバリコードの表示/再生成が step-up なしで到達可能になる。
     * ルート登録は Fortify package provider の boot 内で行われるため、全 provider boot 後の
     * booted callback で名前解決して append する。
     *
     * ★route:cache との契約（**cached 起動では 1 本も効かない**）と、
     *   「毎デプロイ `php artisan route:cache` を再生成する」という前提条件は
     *   {@see \App\Support\Http\RouteMiddlewareBinder} の docblock が正本。
     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
     *   返すため dispatch にも有効」と書いてあったが、**誤り**である。cached 起動では
     *   Fortify の loadRoutesFrom() が require を飛ばして対象 route を登録しないため、
     *   この callback は compiled collection に到達しない。実効しているのは
     *   route:cache **生成時**の焼き込みである。
     *
     * ★feature flag の扱い: 対象 route は Fortify の機能フラグで登録有無が決まるため、
     *   有効な機能の分だけを spec に載せる（無効な機能の route まで要求すると
     *   binder が fail-fast して起動できなくなる）。skip が穴にならない根拠は
     *   throttledFortifyRoutes() の docblock と同じ = 目録検査（RecentAuthRouteTest /
     *   TwoFactorStepUpInventoryTest）が二重の網になる。
     */
    private function attachRecentAuthToSensitiveRoutes(): void
    {
        // first-class callable で渡す（`static fn (): array => …` にすると
        // 匿名関数の戻り値に iterable value type が付かず PHPStan level 10 で詰まる。
        // メソッド参照なら recentAuthRouteSpecs() の @return がそのまま効く）
        RouteMiddlewareBinder::attachOnBooted($this->app, self::recentAuthRouteSpecs(...));
    }

    /**
     * recent-auth 系 middleware を後付けする Fortify route の spec。
     *
     * @return array<string, list<string>> route 名 => middleware alias の列
     */
    private static function recentAuthRouteSpecs(): array
    {
        $specs = [];

        if (Features::enabled(Features::twoFactorAuthentication())) {
            foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
                $specs[$name] = ['recent-auth'];
            }
        }

        if (Features::enabled(Features::updateProfileInformation())) {
            foreach (self::CONDITIONAL_RECENT_AUTH_ROUTES as $name => $alias) {
                $specs[$name] = [$alias];
            }
        }

        return $specs;
    }
```

**use 文の増減（明示）**:

- **追加**: `use App\Support\Http\RouteMiddlewareBinder;`
- **削除候補**（他で使われていなければ削除。`composer fix` / PHPStan で確認）:
  `Illuminate\Routing\RouteCollectionInterface` / `Illuminate\Routing\Router` /
  `Illuminate\Contracts\Foundation\Application`
- `Laravel\Fortify\Features` は既に import 済み（`throttledFortifyRoutes()` が使用）
- `appendMiddlewareIfMissing()` は**削除**する（思考原則 3: 後方互換の並走を残さない）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`void` / `array<string, list<string>>`）
- [x] null 安全: binder 側で `instanceof Route` 判定。provider 側に null は現れない
- [x] DTO: 該当なし
- [x] Generics: spec の array shape を phpdoc で明示

### テスト計画

- [x] 既存 `tests/Architecture/RecentAuthRouteTest.php` が**変更なしで** green
      （`two-factor.*` 6 本の `recent-auth` 付与が保たれる）
- [x] 既存 `tests/Architecture/TwoFactorStepUpInventoryTest.php` が**変更なしで** green
- [x] 既存 `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` が**変更なしで** green
- [x] 個別の `DatabaseTransactions` を使っていない（新規テストは施策 4 / 5 のみ）

### リスク

- feature flag の対応付けを誤ると起動しなくなる。
  `RECENT_AUTH_ROUTE_NAMES` = `Features::twoFactorAuthentication()`、
  `CONDITIONAL_RECENT_AUTH_ROUTES`（`user-profile-information.update`）=
  `Features::updateProfileInformation()` であることを
  `vendor/laravel/fortify/routes/routes.php` の分岐（L104 / L133）で確認済み。
- `attachRecentAuthToSensitiveRoutes()` は `boot()` 内で
  `attachThrottleToFortifyRoutes()` **より先**に呼ばれる = booted callback の発火順も先。
  両者が同じ route に触れても互いに独立（alias 追加と throttle 追加）で、順序依存はない。

---

## 施策 3: `PasskeyServiceProvider` の後付けを binder 経由へ

### 変更箇所

- ファイル: `app/Providers/PasskeyServiceProvider.php`（L99-164）

### 波及変更

- TypeScript 型定義 / API Resource/DTO: なし
- テストファイル: `PasskeyRouteProtectionTest` / `PasskeyPackageContractTest` /
  `LoginMethodRemovalRouteTest` は**変更しない**

### 現行コード

```php
    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // binder と middleware は **全 provider boot 後** に最終上書きする
        // (PasskeysServiceProvider::boot() の Route::bind に確実に後勝ちするため)。
        $this->app->booted(static function (Application $app): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
            self::attachMiddlewareToPasskeyRoutes($app);
        });
    }

    /**
     * Fortify が登録した passkey route へアプリ側 middleware を後付けする。
     * FortifyServiceProvider::attachRecentAuthToSensitiveRoutes() と同じ作法
     * (route:cache 下でも CompiledRouteCollection の nameCache が同一 instance を返すため有効)。
     */
    private static function attachMiddlewareToPasskeyRoutes(Application $app): void
    {
        $routes = $app->make(Router::class)->getRoutes();
        $routes->refreshNameLookups();

        // **順序が重要**: throttle → recent-auth → 手段保持 の順に通す。…（略）
        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'throttle:passkeys');
        }

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'recent-auth');
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            self::appendMiddlewareIfMissing($routes, $name, 'ensure-login-method');
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        self::appendMiddlewareIfMissing($routes, 'passkey.login-options', 'no-store');
    }

    private static function appendMiddlewareIfMissing(RouteCollectionInterface $routes, string $name, string $alias): void
    {
        $route = $routes->getByName($name);
        if ($route !== null && ! in_array($alias, $route->middleware(), true)) {
            $route->middleware($alias);
        }
    }
```

> `L129` が是正対象。あわせて、同じ callback 内の `Route::bind()` が **cached 起動でも有効**
> であることが読み取れず、「callback ごと無効」と誤読されうる。

### 変更後コード

```php
    public function boot(): void
    {
        $this->configureLoginAuthorization();

        // ★この booted callback には **cached 起動での有効/無効が異なる 2 種類**が同居する。
        //   一括りに「cached では無効」と読まないこと:
        //
        //   - Route::bind() は Router::$binders（route collection とは**別の**連想配列）への
        //     登録であり、Router::setCompiledRoutes() の collection 差し替えの影響を受けない。
        //     **cached 起動でも有効**。ここに置いてあるのは boot 順序の問題
        //     （PasskeysServiceProvider::boot() の Route::bind に後勝ちする）だけが理由。
        //   - middleware の後付けは route collection への書き込みであり、
        //     **cached 起動では 1 本も効かない**（RouteMiddlewareBinder の docblock が正本）。
        $this->app->booted(static function (): void {
            Route::bind('passkey', SelfScopedPasskeyBinder::class);
        });

        // first-class callable（理由は FortifyServiceProvider 側と同じ）
        RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
    }

    /**
     * Fortify が登録した passkey route へ後付けするアプリ側 middleware の spec。
     *
     * ★**順序が重要**: throttle → recent-auth → 手段保持 の順に並べる。
     *   throttle を先に並べることで、priority 適用後も ThrottleRequests が
     *   RequireRecentAuth より前になる（無制限のロック競合を最外周で止める）。
     *   逆順だと stale recent-auth のリクエストでも User 行ロックを取りに行ってしまう。
     *   PasskeyRouteProtectionTest が解決後のクラス列上の index 比較で固定する。
     *   → 1 route あたりの alias 列も**この順**で並べる（binder は列の順に append する）。
     *
     * ★route:cache との契約（cached 起動では 1 本も効かない / 実効は生成時の焼き込み /
     *   毎デプロイ再生成が前提条件）は {@see \App\Support\Http\RouteMiddlewareBinder} が正本。
     *   **旧記述の訂正**: かつてここには「route:cache 下でも nameCache が同一 instance を
     *   返すため有効」と書いてあったが誤りである。
     *
     * ★feature flag: passkey route は `Features::passkeys()` が有効なときだけ登録される
     *   （config/fortify.php の「この 1 行が実質的なキルスイッチ」）。無効時は spec を空にする。
     *
     * @return array<string, list<string>> route 名 => middleware alias の列
     */
    private static function passkeyRouteSpecs(): array
    {
        if (! Features::enabled(Features::passkeys())) {
            return [];
        }

        $specs = [];

        foreach (self::THROTTLE_ROUTE_NAMES as $name) {
            $specs[$name] = ['throttle:passkeys'];
        }

        foreach (self::RECENT_AUTH_ROUTE_NAMES as $name) {
            $specs[$name] = [...($specs[$name] ?? []), 'recent-auth'];
        }

        foreach (self::LOGIN_METHOD_GUARD_ROUTE_NAMES as $name) {
            $specs[$name] = [...($specs[$name] ?? []), 'ensure-login-method'];
        }

        // guest route のため NoStoreCacheHeadersForAuthenticatedPages の対象外。
        // WebAuthn challenge を載せる応答をキャッシュさせない。
        $specs['passkey.login-options'] = [...($specs['passkey.login-options'] ?? []), 'no-store'];

        return $specs;
    }
```

**use 文の増減（明示）**:

- **追加**: `use App\Support\Http\RouteMiddlewareBinder;` / `use Laravel\Fortify\Features;`
- **削除候補**: `Illuminate\Routing\RouteCollectionInterface` / `Illuminate\Routing\Router`
  （`Illuminate\Contracts\Foundation\Application` は `configureLoginAuthorization()` 等で
  使われていなければ削除。`Illuminate\Support\Facades\Route` は `Route::bind` で引き続き必要）
- `attachMiddlewareToPasskeyRoutes()` と `appendMiddlewareIfMissing()` は**削除**する。
- 付与内容・付与順序は現行と**完全に同一**（`passkey.destroy` は
  `throttle:passkeys` → `recent-auth` → `ensure-login-method` の順で並ぶ）。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている
- [x] null 安全: `$specs[$name] ?? []` で未定義キーを避ける
- [x] Generics: `array<string, list<string>>` を phpdoc で明示。
      spread `[...$a, $b]` は list を保つ

### テスト計画

- [x] 既存 `tests/Architecture/PasskeyRouteProtectionTest.php` が**変更なしで** green
      （middleware 構成の inventory 一致 + **解決後クラス列の index 比較による順序固定**）
- [x] 既存 `tests/Architecture/PasskeyPackageContractTest.php` が**変更なしで** green
      （`Route::bind` の差し替えが生きていること = binder 分離の回帰）
- [x] 既存 `tests/Architecture/LoginMethodRemovalRouteTest.php` が**変更なしで** green

### リスク

- `Route::bind` を別の `booted()` に切り出したことで**発火順**が変わる。
  現行は「bind → middleware 後付け」の順で 1 つの callback、変更後は
  「bind の callback」→「binder の callback」の 2 つ。**登録順は変わらないため発火順も同じ**
  で、かつ両者は独立（binders 連想配列 vs route collection）なので影響はない。
  `PasskeyPackageContractTest` が bind の最終解決系を固定しているため、崩れれば検出される。
- alias 列の順序が変わると `PasskeyRouteProtectionTest` の index 比較が落ちる
  = 順序は機械固定されている（設計どおりであることの確認になる）。

---

## 施策 4: binder の契約テスト

### 変更箇所

- ファイル: `tests/Feature/Security/RouteMiddlewareBinderTest.php`（新規）

配置は既存の `tests/Feature/Security/RouteThrottleBinderTest.php` に**揃える**
（Router facade を使うため Feature レーン。DB は触らない）。

### テスト計画（実装する項目）

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `routesAreCached: true では resolver すら呼ばれない` | **呼ばれたら `RuntimeException` を投げる resolver** を渡して `attachAll($router, $resolver, routesAreCached: true)` が例外なく完了する。**= T120 の恒久回帰**。resolver に到達しない ⇒ route 解決にも到達しない、を 1 本で表明できる（Application の stub を作らずに配線側のリスクを純粋関数へ閉じ込めた形。理由は `attachOnBooted` の docblock） |
| 1b | `routesAreCached: true では middleware が 1 本も増えない` | 既存 probe route を用意し、その route を含む spec を返す resolver を渡しても `middleware()` が不変 |
| 2 | `routesAreCached: false で route が引けないと RuntimeException（メッセージに route 名を含む）` | fail-fast |
| 3 | `alias が実効列に 1 本増える` | probe route を作り `attachAll(..., false)` 後に `gatherMiddleware()` に含まれる |
| 4 | `2 回呼んでも 1 本のまま（冪等）` | 重複付与しない |
| 5 | `列の順に append される` | `['throttle:passkeys', 'recent-auth', 'ensure-login-method']` が `middleware()` 上でその順に並ぶ（Passkey の順序契約の土台） |
| 6 | `付与後に computedMiddleware が破棄されている` | 事前に `gatherMiddleware()` を呼んで memo を温めてから付与し、再度 `gatherMiddleware()` に新 alias が現れる（**無音の無防備**の回帰） |
| 7 | `変更が無いときは既存 route を壊さない` | 既に alias を持つ route へ同じ alias を渡しても `middleware()` が不変 |
| 8 | **`attachOnBooted()` は cached 起動で resolver を呼ばない（配線の固定）** | `app()->instance('routes.cached', true)` としてから `attachOnBooted(app(), 呼ばれたら throw する resolver)` を実行し、**例外が出ない**ことを表明する |
| 8b | **negative control: 非 cached なら resolver は実際に呼ばれる** | `app()->instance('routes.cached', false)` で同じ resolver を渡すと**例外が出る**。#8 が「配線が死んでいるから green」になっていないことの担保 |

#### #8 / #8b の実装メモ（stub を作らない）

`Illuminate\Foundation\Application::routesAreCached()` は
**まず container binding `routes.cached` を見る**（`if ($this->bound('routes.cached')) { return (bool) $this->make('routes.cached'); }`）。
したがって `app()->instance('routes.cached', true)` で cached 起動を再現でき、
Application の stub / mock も route cache ファイルの生成も要らない。
また `Application::booted()` は `isBooted()` なら **その場で callback を発火する**ため、
テスト内で `attachOnBooted()` を呼べば同期的に配線が走る。
（`routes.cached` の束ね直しはテストごとに作り直されるアプリに閉じるので、
他テストへ漏れない）

- [x] バグ修正ではないため再現テストは不要（振る舞い是正ではなく作法の統一）
- [x] 個別の `DatabaseTransactions` を使わない（`tests/Pest.php` のグローバル適用に従う）
- [x] 既存テストの削除・上書きなし

### リスク

- テスト内で `Route::post(...)` して probe route を作るため、name index の refresh 忘れで
  偽 fail しうる。`attachAll()` 側が `refreshNameLookups()` を呼ぶ設計なので、
  テスト側でも既存 `RouteThrottleBinderTest` と同じ helper 形にして揃える。

---

## 施策 5: 後付け経路の deny-by-default 目録

### 変更箇所

- ファイル: `tests/Architecture/PostBootRouteMutationInventoryTest.php`（新規）

### 何を検査するか（明確化）

**検査するのは「起動後に route collection から named route を引くコードが、
skip 判定を引数で受ける 2 つの binder に限られていること」だけ**である。

- 対象トークン: `app/` 配下の PHP ファイルに現れる `getByName(` と `refreshNameLookups(`
  （**実測**: 現在の出現は 3 ファイル・7 箇所のみ = `FortifyServiceProvider` 3 /
  `PasskeyServiceProvider` 2 / `RouteThrottleBinder` 2。施策 2 / 3 の後は allowlist の
  2 ファイルだけになる。素のトークン走査で false positive が出ないことを確認済みなので、
  `token_get_all()` によるコメント除外は**今は入れない** — 必要になってから入れる）
- allowlist: `app/Support/Http/RouteThrottleBinder.php` /
  `app/Support/Http/RouteMiddlewareBinder.php` の **2 ファイルのみ**
- allowlist 外に出現したら fail し、
  「後付けは RouteThrottleBinder / RouteMiddlewareBinder 経由にすること。
  cached 起動で例外を投げると `route:list` が必ず落ちる（T120）」と案内する
- **negative control**: allowlist の 2 ファイルが実際にそのトークンを**含む**ことも表明する
  （実装が消えたり改名されたときに gate が空振り green にならないようにする）

### 何を検査しないか（誇張しない）

- **docblock の主張が機序と一致していること**は検査しない。自然言語の主張は機械で
  照合できない。ここで守れるのは「後付けの**入口**が 1 本に絞られていること」までである。
- **起動時の route cache 鮮度**は検査しない。本番デプロイは全ファイルを新規展開するため
  mtime は揃い、cache が古いソースから作られたかは起動時から判定できない
  （「作れるが作らない」ではなく **正しく作れない**）。
- トークン走査であるため、`$router->getRoutes()->getByName(...)` を変数越しに
  組み立てるような書き方は**すり抜ける**。この gate は「うっかり」を止めるものであって、
  意図的な迂回を止めるものではない。

### なぜ作るか（検査を作ること自体が目的にならないよう）

止めたい具体的失敗は 1 つで、**過去に実際に起きている**:
新しい後付け経路を追加した人が skip 判定を書かず、cached 起動で例外を投げて
`php artisan route:list` が必ず落ちる（T120）。後付け経路はこの 1 年で 3 本増えており
（T120 / T121 / T124）、4 本目が足される確率は低くない。
入口を 2 クラスに絞れば、その 2 クラスが持つ「skip 判定を引数で受ける」形が自動的に効く。

### PHPStan適合チェック

- [x] Pest のテストファイル（`declare(strict_types=1)`、クロージャの戻り型 `void` 明示）
- [x] `file_get_contents()` の戻りは `string|false` なので `expect(...)->toBeString()` で絞る
      （既存 `TrustedProxiesRunbookTest` と同じ作法）

### テスト計画

自身がテストのため該当なし。ただし**実装前に fail を確認する**
（思考原則 5 テストファースト）: allowlist を空にした状態で 3 ファイルが列挙されて落ちること、
allowlist を入れて green になることを実測してから確定する。

### リスク

- 将来 vendor route への後付けが本当に別クラスで必要になったとき、allowlist を足す判断が要る。
  そのときこそ「skip 判定を引数で受けているか」を review する契機になるので、
  摩擦は**意図した摩擦**である。

---

## 施策 6: 運用契約の格上げ（§7c 新設）

### 変更箇所

- ファイル: `docs/app-integration-guide.md`
  - §7b「流量制限の付与規約」の第 3 段のうち **`php artisan route:cache` 要件の箇条書き**を
    §7c へ移し、§7b にはポインタだけを残す
  - §7b の直後に **§7c「vendor route への後付け機構と route:cache の契約」**を新設

### 変更後コード（§7c の骨子）

```markdown
### §7c vendor route への後付け機構と route:cache の契約

vendor (Fortify / laravel-passkeys / Cashier) が登録した route へ、アプリ側が
boot 後に middleware を後付けする経路は **2 つの binder に限られる**:

| binder | 付けるもの | 呼び出し元 |
|---|---|---|
| `RouteThrottleBinder::attachOnBooted()` | `throttle:{limiter}` | FortifyServiceProvider / AppServiceProvider |
| `RouteMiddlewareBinder::attachOnBooted()` | `recent-auth` / `recent-auth.on-email-change` / `ensure-login-method` / `no-store` / `throttle:passkeys` | FortifyServiceProvider / PasskeyServiceProvider |

**2 つの事象を混ぜないこと**:

1. **生成時**（`php artisan route:cache` 実行中）= 後付けが完全に走り、cache へ焼き込まれる。
   route 名が消えていればここでデプロイが止まる（fail-fast が効くのはここだけ）。
2. **起動時**（cached 起動）= 後付けは **1 本も効かない**。
   `loadRoutesFrom()` が require を飛ばすため、**binder の callback が走る時点では**
   対象 named route が 1 本も登録されていない（compiled routes はこの callback より
   **後**に読まれる。「route が永久に存在しない」の意味ではない）。
   ゆえに binder は明示 skip する
   （ここで例外を投げると `route:list` が必ず落ちる = T120）。

⇒ **運用要件: `php artisan route:cache` を毎デプロイ再生成すること。**
   これは throttle だけの要件ではない。**2FA 秘密の露出防止 (recent-auth) /
   passkey 削除の手段保持 (ensure-login-method) / WebAuthn challenge の no-store も
   同じ前提条件に乗っている**。stale な route cache は古い付与状態のまま起動し、
   **無音で保護が外れる**（実測: 剥がした cache では鮮度切れセッションの
   2FA 秘密 GET が 409 でなく **200 で秘密を返す**、`force=true` の enable も 200、
   `passkey.destroy` の 429 が消える）。

**現状**: 本リポジトリに**デプロイ定義は存在しない**（deploy/ / terraform / k8s manifest /
CI のデプロイ job のいずれも無い）。したがって上記は**今日は人手で守られている要件**であり、
デプロイ基盤を作る PR が**必ず実装しなければならない要件**である（AGENTS.md の運用要件ブロック）。
今その基盤を先回りして作らない（思考原則 2）。

**新しい後付け経路を足すとき**: 必ず上記 2 binder のどちらかを通す。
`PostBootRouteMutationInventoryTest` が deny-by-default で強制する。
```

### 波及変更

- テストファイル: **なし**（§7c の存在を検査するテストは作らない。
  ドキュメント節の存在検査は `TrustedProxiesRunbookTest` のような
  「運用者が埋めないと fail する欄」がある場合にだけ意味があり、ここには埋める欄がない）

### リスク

- §7b の既存記述を移動するため、`docs/app-integration-guide.md §7b` を参照している
  既存記述（AGENTS.md ドメイン固有規約 5 / `RouteThrottleBinder` の docblock /
  `AppServiceProvider` の docblock）が指す先がずれる。
  → §7b は**残したまま**ポインタを置く（節番号を消さない）。施策 7 で
  AGENTS.md 側の参照を §7c にも向ける。

---

## 施策 7: AGENTS.md に運用要件ブロック

### 変更箇所

- ファイル: `AGENTS.md`
  - 「セキュリティ不変条件」節末尾の **`> **運用要件 (T108)**` ブロックの直後**に、
    同じ書式で route:cache の運用要件ブロックを追加
  - ドメイン固有規約 5 の「vendor 登録 route への後付けは …」の箇条書きから
    §7c を指すよう 1 行修正（§7b への既存参照は残す）

### 変更後コード（追加ブロック）

```markdown
> **運用要件 (route:cache)**: production は `php artisan route:cache` を**毎デプロイ再生成する**。
> vendor route への middleware 後付け(`RouteThrottleBinder` / `RouteMiddlewareBinder`)は
> **cache 生成時に焼き込まれ、cached 起動では 1 本も効かない**ため、stale cache は
> **無音で保護を外す**(実測: 2FA 秘密 GET が 409 でなく 200 を返し、passkey 削除の
> 手段保持 guard も消える)。対象は throttle だけではない(recent-auth /
> ensure-login-method / no-store も同じ前提条件)。機序と実測は
> `docs/app-integration-guide.md` §7c が正本。
> **本リポジトリにデプロイ定義は無い**(deploy/ / terraform / k8s / CI デプロイ job のいずれも無い)。
> よって現在この要件は**人手でのみ守られている**。**デプロイ基盤を作る PR は、
> 本要件と TRUSTED_PROXIES 運用要件 (T108) の 2 つを実装するまで完了にできない**。
> 存在しない基盤のための preflight 機構を先回りして作らないこと(思考原則 2)。
```

### 波及変更

- テストファイル: **なし**。AGENTS.md の記述を機械検査するテストは作らない
  （文言検査は形骸化しやすく、守りたい不変条件の代理になっていない）

### リスク

- AGENTS.md が長くなる。ただし T108 の先例と同じ書式・同じ位置なので、
  「運用要件はここに並ぶ」という読み筋は保たれる。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `app/Providers/` の 2 ファイルと `app/Support/Http/` の新規 1 ファイルに閉じており、他ドメインと重ならない。(2) 施策 5 の目録テストは実装前に fail を観測する必要があり（テストファースト）、他タスクの差分が混ざると観測が濁る。(3) `AGENTS.md` / `docs/app-integration-guide.md` を触るため、同時進行の設計タスクと衝突しやすい |
| 競合リスク | `AGENTS.md`（ドメイン固有規約 5 / セキュリティ不変条件節）と `docs/app-integration-guide.md` §7b は他タスクも触りやすい。マージ順を先にするか、rebase 時に節番号の重複を確認する |

## 実装順序（テストファースト）

1. 施策 5 の目録テストを allowlist 空で作り、**3 ファイルが列挙されて fail** することを観測する。
2. 施策 1 の binder を新設し、施策 4 のテストを書いて **fail → green** を観測する
   （特に #1 = 純粋関数の lazy resolver 契約、#8 / #8b = 実配線の振る舞いと negative control、
   #6 = computedMiddleware 破棄）。
3. 施策 2 / 3 で provider を binder 経由へ移し、旧 private helper を**同じコミットで削除**する
   （思考原則 3）。ここで施策 5 の allowlist を確定し green にする。
4. 既存の route 保護系テスト群が **1 行も変更されないまま** green であることを確認する。
5. 施策 6 / 7 のドキュメントを更新する。
6. `php artisan route:cache` → `php artisan route:list` → `php artisan route:clear` の
   往復が例外なく完了することを手で確認する（T120 と同じ確認手順）。

## 検証コマンド

`composer test` / `composer phpstan` / `vendor/bin/pint --test`
（フロント差分ゼロのため `pnpm` 系は影響なしだが、CI 契約どおり全 green を確認する）

---

## 最終確認: 使命・禁止事項チェック（Phase 2-5）

| 項目 | 判定 | 根拠 |
|---|---|---|
| 全施策が使命に寄与するか | ○（間接） | 撮影 PWA の認証面（2FA 秘密の露出防止 / passkey 削除の手段保持）を守る機構の**運用要件を隠さない**ための是正。直接のユーザー価値は増やさない（誇張しない） |
| 禁止事項 1（テストなしの実装完了） | ○ | 施策 4（binder 契約テスト 9 本）と施策 5（Architecture 目録）を施策に含む。既存の route 保護系テストが**差分なしで green** であることも完了条件 |
| 禁止事項 2（PHPStan の widen / baseline） | ○ | `@phpstan-type` 相当の array shape と first-class callable で level 10 を通す。ignore / baseline を使わない |
| 禁止事項 3（dev DB 破壊操作） | ○ | DB に触れない |
| 禁止事項 4（`response()->json()` 直書き） | ○ | HTTP 応答を作らない |
| 禁止事項 5 / 6（Prism 直呼び / prompt 直書き） | ○ | 非該当 |
| 禁止事項 9（Artifact 使用） | ○ | 成果物はすべて `devnotes/` 配下のファイル |
| 思考原則 1（フレームワークのレンジ内） | ○ | 後付け方式は現行のまま。framework の `booted()` / `routesAreCached()` の公式作法だけを使う |
| 思考原則 2（今必要なものだけ） | ○ | デプロイ基盤の先回り実装なし / 起動時 cache 鮮度検査なし / `attachOnBooted` の二重 skip なし / tokenizer なし |
| 思考原則 3（後方互換の並走を残さない） | ○ | 旧 `appendMiddlewareIfMissing()` 2 本を**同じコミットで削除**する |
| 思考原則 4（似ているからで統合しない） | ○ | `RouteThrottleBinder` と統合しない（責務もシグネチャも意図的に揃えない。理由を docblock に残す） |
| 思考原則 5（テストファースト） | ○ | 「実装順序」に fail 観測の手順を明記 |
| セキュリティ不変条件 | ○ | 保護の付与内容を 1 つも変えない。既存の目録検査群が差分なしで green であることが証拠 |

## Codex 合議の結果

| フェーズ | ラウンド | 判定 |
|---|---|---|
| 概念設計レビュー（gpt-5.5 / medium） | 1 | **APPROVED**（Critical 0 / Warning 4 / Suggestion 4。Warning は 3 件対応・1 件は一部反論） |
| 詳細設計レビュー（gpt-5.5 / high） | 1 | CHANGES_REQUESTED（施策 1 / 3 / 4 が REQUEST_CHANGES） |
| 詳細設計レビュー | 2 | CHANGES_REQUESTED（施策 1 / 4 が REQUEST_CHANGES。「型として不可能」の誇張を指摘され是正） |
| 詳細設計レビュー | 3 | **APPROVED**（Critical / Warning ともに 0。二重防御を採らない反論も受理された） |

議論履歴: `devnotes/20260808-0130-route-cache-attach-contract/codex-history/`
（プロンプト 4 本 / 対応マトリクス 3 本）、Codex 返答は同ディレクトリ直上の
`conceptual-review-round-1.md` / `detailed-review-round-{1,2,3}.md`。
