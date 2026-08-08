**レビュー仮説**
T120 再発防止の成功条件は「cached 起動では named route 解決に到達しない」だけでなく、「cached 起動では route 解決を含み得る resolver も呼ばない」ことです。feature flag 対応は vendor route 登録条件と一致していれば妥当、deny-by-default 目録は“後付け入口を絞る”範囲に留めれば過剰ではありません。

**全体判定: CHANGES_REQUESTED**

主な理由は 1 点です。施策 1 の `attachOnBooted()` が `$routesAreCached` を `attachAll()` に渡す前に `$specResolver()` を評価しており、binder の将来利用で T120 型の事故を再導入できる余地があります。

**施策 1: REQUEST_CHANGES**

[Warning] `attachOnBooted()` が cached 起動でも `$specResolver()` を呼びます。  
現行の resolver は feature flag を読むだけなので直ちに壊れませんが、「後付け入口を binder に集約して T120 を構造的に防ぐ」という契約としては不十分です。将来 resolver 側で route collection を見る実装が入ると、`attachAll()` の early return 前に落ちます。

修正案:

```php
$app->booted(static function (Application $app) use ($specResolver): void {
    $routesAreCached = $app instanceof CachesRoutes && $app->routesAreCached();

    if ($routesAreCached) {
        return;
    }

    self::attachAll(
        $app->make(Router::class),
        $specResolver(),
        false,
    );
});
```

`attachAll(..., routesAreCached: true)` の純粋関数テストは残してよいですが、実配線側も resolver 非実行にしてください。

**施策 2: APPROVE**

feature flag 対応付けは正しいです。

`RECENT_AUTH_ROUTE_NAMES` を `Features::twoFactorAuthentication()` に、`CONDITIONAL_RECENT_AUTH_ROUTES` を `Features::updateProfileInformation()` に対応させる設計は、提示された Fortify route 分岐と整合しています。

[Suggestion] PHPStan level 10 対策として、`static fn (): array => ...` は避けた方が安全です。匿名関数の `array` 戻り値に iterable value type 不足を指摘される可能性があります。  
修正案は first-class callable が簡潔です。

```php
RouteMiddlewareBinder::attachOnBooted($this->app, self::recentAuthRouteSpecs(...));
```

**施策 3: REQUEST_CHANGES**

[Warning] `PasskeyServiceProvider` の変更後コードで `RouteMiddlewareBinder` の import 追加が明記されていません。  
このままだと未解決 class で実装時に落ちます。

修正案:

```php
use App\Support\Http\RouteMiddlewareBinder;
use Laravel\Fortify\Features;
```

feature flag 対応付け自体は正しいです。passkey route 全体を `Features::passkeys()` に対応させる線引きで妥当です。

`Route::bind()` を別 callback に切り出す点も、登録順が `bind callback` → `middleware binder callback` のままなので問題ありません。`Route::bind()` は route collection ではなく router binders への登録であり、cached 起動でも有効という説明も正確です。

[Suggestion] 施策 2 と同じく、PHPStan 回避のため `static fn (): array` より first-class callable を推奨します。

```php
RouteMiddlewareBinder::attachOnBooted($this->app, self::passkeyRouteSpecs(...));
```

**施策 4: REQUEST_CHANGES**

[Warning] T120 恒久回帰テストが `attachAll()` 直叩きに寄っています。  
今回の実リスクは `attachOnBooted()` の配線にあるため、cached 起動時に resolver が呼ばれないことをテストに入れてください。

修正案: `routesAreCached() === true` の Application stub/mock を使い、resolver に「呼ばれたら fail/throw」する closure を渡すテストを追加します。

```php
RouteMiddlewareBinder::attachOnBooted($app, static function (): array {
    throw new RuntimeException('resolver must not be called when routes are cached');
});
```

そのうえで app booted callback を発火させ、例外が出ないことを確認するのが、T120 再発防止の直接証明になります。

既存の #1〜#7 は妥当です。特に `computedMiddleware` 破棄テストは価値があります。

**施策 5: APPROVE**

deny-by-default 目録の線引きは妥当です。

検査対象を `getByName(` / `refreshNameLookups(` に絞り、allowlist を `RouteThrottleBinder` / `RouteMiddlewareBinder` の 2 ファイルに限定する設計は、止めたい失敗シナリオ T120 に対して過不足が少ないです。docblock の自然言語や stale route cache を検査しない、と明記している点もよいです。

[Suggestion] false positive が出た場合だけ、`token_get_all()` でコメントを除外する実装に寄せれば十分です。現時点で先回りする必要はありません。

**施策 6: APPROVE**

§7c への格上げは妥当です。route:cache 要件が throttle だけでなく `recent-auth` / `ensure-login-method` / `no-store` にも及ぶ、という運用契約を明文化できています。

[Suggestion] 「cached 起動では named route を 1 本も解決できない」は、“binder callback が走る時点では” と補うとより正確です。compiled routes は後で読み込まれるため、絶対的に存在しないように読ませない方がよいです。

**施策 7: APPROVE**

AGENTS.md への運用要件ブロック追加は妥当です。T108 と同じ形式・位置に置く判断も整合しています。デプロイ基盤が存在しないことを明記し、先回り実装を禁止するのもスコープ制御として正しいです。

**判断を求められた 3 点への回答**

1. 施策 1 は `attachAll()` 単体では T120 を防げていますが、`attachOnBooted()` が cached 起動でも `$specResolver()` を呼ぶため、契約としては未完成です。ここは修正必須です。
2. feature flag 対応付けは正しいです。`twoFactorAuthentication` / `updateProfileInformation` / `passkeys` の対応に誤りは見当たりません。
3. 施策 5 の目録は妥当です。検査対象と非対象の線引きが明確で、検査を増やすこと自体が目的化していません。