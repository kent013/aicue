# 概念設計: route-cache-attach-contract

> 一次入力: `devnotes/20260808-0130-route-cache-attach-contract/recon-brief.md`
> (2026-08-08 に独立 2 系統で実測した確定事実)。
> 本設計者も vendor / アプリコードを再読して裏取り済み (下記「自分で取った裏」)。

## 背景・課題

vendor (Fortify / laravel/passkeys) が登録する named route へ、アプリ側が boot 後に
middleware を後付けする経路が **3 系統**ある:

| # | 後付け元 | 対象 | 付ける middleware |
|---|---|---|---|
| 1 | `RouteThrottleBinder::attachOnBooted()` (`FortifyServiceProvider` / `AppServiceProvider` から) | Fortify 12 route + `cashier.webhook` | `throttle:{limiter}` |
| 2 | `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` | `two-factor.*` 6 本 + `user-profile-information.update` | `recent-auth` / `recent-auth.on-email-change` |
| 3 | `PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` | `passkey.*` 7 本 | `throttle:passkeys` / `recent-auth` / `ensure-login-method` / `no-store` |

この 3 系統は **同じ機序**で動いているのに、**docblock の説明が食い違っている**。

- 1 (`RouteThrottleBinder`) は「cached 起動では named route を 1 本も解決できないので skip する。
  実効になるのは `route:cache` 生成時の焼き込み。よって毎デプロイ再生成が前提条件」と
  **正しく**書いてある (T120 の事故後に是正済み)。
- 2 (`FortifyServiceProvider` L232-234) と 3 (`PasskeyServiceProvider` L129) は
  「route:cache 下でも `CompiledRouteCollection` の `nameCache` が同一 instance を返すため
  dispatch にも有効」と書いてある。**これは誤り**。

### 誤りの正体 (「結論は合っているが理由が違う」)

`nameCache` の性質の記述それ自体は正しい (`CompiledRouteCollection::getByName()` は
`nameCache` に memoize し、`match()` はその `getByName()` を通る)。誤っているのは
**前提**である — この callback は compiled route collection に**到達しない**。

1. `ServiceProvider::loadRoutesFrom()` は `routesAreCached()` のとき `require` を飛ばす
   (`vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php`)。
   Fortify (`FortifyServiceProvider::configureRoutes()`) も passkeys
   (`PasskeysServiceProvider::registerRoutes()`) もこれを使う。
   → **cached 起動では対象 named route がそもそも登録されない**。
2. framework の `RouteServiceProvider::register()` は `$this->booted(...)` の中で
   `loadCachedRoutes()` を呼び、それが**さらに** `$this->app->booted(fn () => require cached routes)`
   を積む。`withRouting()` 経由で最後に boot されるため、この app-booted callback は
   アプリ provider の `$app->booted()` **より後**に走る。
   → 後付け callback の時点で compiled collection はまだ読まれていない。
3. `Router::setCompiledRoutes()` は `new CompiledRouteCollection(...)` を作って
   `$this->routes` と container の `'routes'` instance を**丸ごと差し替える**。
   → 仮に触れていても捨てられる (二重の理由で効かない)。

結果、`appendMiddlewareIfMissing()` の `$route !== null` ガードが **無音 no-op** する。
直接証拠は boot 完了直後の `CompiledRouteCollection::$nameCache` が **0 件**であること
(後付けが compiled collection に一度も触れていない)。

### それでも保護が効いている理由

`RouteCacheCommand::handle()` は先頭で `route:clear` してから
`getFreshApplicationRoutes()` で **cache 無しのアプリを再 bootstrap** する。そこでは
`loadRoutesFrom()` が require を通すため後付けが**完全に走り**、付与済み middleware が
そのまま cache へ**焼き込まれる** (実測: `two-factor.qr-code` の attributes に `recent-auth`、
cache 全体で 33 箇所)。正規 cache での cached 起動では 2FA step-up テスト 11 本が green。

**壊れるのは stale cache のときだけ**である。剥がした cache での実 HTTP 実測:
鮮度切れセッションで 2FA 秘密 GET が **409 でなく 200 で秘密を返す**、`force=true` の
enable も 200 で通る、`passkey.destroy` の 429 が消えて 404 になる。

### したがって課題は 3 つ

1. **誤った機序の記述**が次の担当を誤らせる (「cached でも効くのだから安心」と読める)。
2. **運用要件が隠れている**。`php artisan route:cache` の毎デプロイ再生成は
   throttle だけでなく **recent-auth / ensure-login-method / no-store の前提条件**でもあるのに、
   `docs/app-integration-guide.md` では**流量制限の節 (§7b) にしか書かれていない**。
   T124 の 2FA step-up がこの要件に乗っていることが読み取れない。
3. **無音の no-op が残っている**。cached 起動で 1 本も引けないのは正常だが、
   非 cached で引けないのは「vendor が route 名を変えた = 無防備」であり、
   両者を同じ `$route !== null` で黙って畳んでいる。1 (binder) は既にこの 2 事象を
   分離しているので、家系内で作法が割れている。

## 改善アイデア

**振る舞いを変えないのが原則**。現状の保護は焼き込みで実効しており、緊急のセキュリティ
修正ではない。直すのは記述と作法である。ただし 3 (無音の no-op) だけは**意図的に振る舞いを
変える** — 非 cached で route が引けない場合に fail-fast する。以下 4 施策。

### 施策 A: 誤った docblock の是正 (振る舞い不変)

`FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()` と
`PasskeyServiceProvider::attachMiddlewareToPasskeyRoutes()` の docblock を、
`RouteThrottleBinder` と同じ **2 事象分離**で書き直す:

- **生成時** (`route:cache` 実行時) = `route:clear` 後の再 bootstrap で後付けが完全に走り、
  cache へ焼き込まれる。route 名が消えていればここでデプロイが止まる。
- **起動時** (cached 起動) = 対象 named route が未登録のため後付けは **1 本も効かない**
  (`loadRoutesFrom()` の require skip + `setCompiledRoutes()` の差し替えの二重)。
- ゆえに **`route:cache` の毎デプロイ再生成が T124 保護の前提条件**である。

あわせて Passkey 側には**区別**を明記する: 同じ booted callback 内の
`Route::bind('passkey', SelfScopedPasskeyBinder::class)` は `Router::$binders`
(route collection とは別の連想配列) への登録であり、collection 差し替えの影響を受けない。
**cached 起動でも有効**。「callback ごと無効」と一括りに誤読させない。

### 施策 B: 後付け作法の統一 (silent no-op の廃止)

2 provider に重複している private `appendMiddlewareIfMissing()` を、
`RouteThrottleBinder` と**同じ形**の共有 helper へ寄せる:

- 公開入口 `attachOnBooted(Application $app, callable $specResolver): void` が
  `$app->booted()` の中で `$app instanceof CachesRoutes && $app->routesAreCached()` を評価し、
  **skip 判定を引数で渡す**。spec を `callable(): array<string, list<string>>` で受けるのは、
  現行 2 経路の feature 判定タイミング (Fortify = `boot()` 内 / Passkey = `booted()` 内) を
  **早める方向に変えない**ため (振る舞い不変の徹底)。
- 実体 `attachAll(Router $router, array $specs, bool $routesAreCached): void` は
  `$routesAreCached` なら理由コメント付きで **early return** する純粋関数。
  early return は **route 解決より前**に置く (解決を試みてから返す形にしない)。
- 非 cached で route が引けなければ **`RuntimeException` で fail-fast**
  (vendor が route 名を変えた = 無防備なまま公開される事故を止める)。
- feature flag で route ごと消える正常系を fail-fast させないため、呼び出し側 (provider) が
  `throttledFortifyRoutes()` と**同じ形**で feature 条件を評価して spec から外す
  (`Features::twoFactorAuthentication()` / `Features::updateProfileInformation()` /
  `Features::passkeys()`)。helper 自身は vendor の Features に依存しない。
- 付与後に `$route->computedMiddleware = null` を置く (`RouteThrottleBinder` と同じ作法)。
  **現時点では no-op** — この経路は `gatherMiddleware()` を呼ばないため memo は温まらない —
  ことを確認済みで、そのことをコメントに明記する (誇張しない)。それでも置くのは、
  この memo の取りこぼしが起こす失敗形が「**middleware() には載るのに dispatch されない
  = 無音の無防備**」であり、本設計が潰そうとしている失敗形そのものだから。
- 型は新クラスを増やさず `@phpstan-type` の array shape で閉じる。
  spec を readonly DTO 化しないのは、同じ概念を `throttledFortifyRoutes()` の array shape と
  DTO の 2 表現に割らないため (禁止事項 6 / 思考原則 2)。

**T120 を踏まない根拠**: 例外を投げうるのは `$routesAreCached === false` の枝だけであり、
cached 起動 (= `route:list` / 本番起動) では判定より前に early return する。
判定を引数で受けることで「cached 相当を渡したら 1 本も触らず例外も投げない」ことを
**純粋関数のテストで直接固定できる**。

### 施策 C: 運用要件を「後付け機構全体の前提条件」へ格上げ

- `docs/app-integration-guide.md` に **§7c「vendor route への後付け機構と route:cache の契約」**
  を新設し、route:cache 要件の記述をそこへ移す (§7b は throttle 固有の話に戻し、§7c を参照する)。
  対象は throttle / recent-auth / ensure-login-method / no-store の**全部**であると明記。
- `AGENTS.md` の `TRUSTED_PROXIES` 運用要件ブロック (T108) の**隣**に、同じ形式で
  route:cache 運用要件ブロックを置く。**デプロイ基盤が未整備であることを明記**し、
  「デプロイ基盤を作る PR は本要件を実装してからでないと完了にできない」と書く。
- ドメイン固有規約 5 の既存記述 (「毎デプロイ再生成する」) は残し、**対象が throttle だけでない**
  ことが読めるよう §7c を指すようにする。

**新しい仕組みは作らない**。今存在しないデプロイ基盤のために preflight コマンドや
起動時の cache 鮮度検査を作るのは AGENTS.md 思考原則 2 に反する (詳細はスコープ外の節)。

### 施策 D: 機械で守れるところだけ守る

「docblock の主張が実際の機序と一致している」ことは機械検査できない。検査するのは
**それの代理になる 2 点だけ**にする:

1. **純粋関数の振る舞いテスト** (禁止事項 1 により必須):
   - `routesAreCached: true` に **存在しない route 名だけを渡しても** 1 本も middleware を
     足さず、**例外も投げない** (= T120 の恒久回帰。early return が route 解決より前に
     あることの直接の表明になる)。
   - `routesAreCached: false` で対象 route が存在しないと `RuntimeException`。
   - 冪等: 既に付いている alias を二重に足さない。
2. **後付け経路の deny-by-default 目録** (Architecture テスト):
   `app/` 配下で「起動後に route collection から named route を引いて middleware を足す」
   コードを持ってよいのは **`RouteThrottleBinder` と新 helper の 2 クラスだけ**とし、
   それ以外の出現を token 走査で fail させる。
   → 4 本目の後付け経路が旧作法 (無音 no-op) や生の inline 実装で足されるのを止める。
   これは T120 で実際に起きた事故 (cached 起動で `route:list` が必ず落ちる) の再発防止であり、
   新しい検査文化の発明ではない (本リポジトリの deny-by-default 目録は既に 30 本弱ある)。

**作らない検査**を明記する:
- docblock の文面と機序の一致検査 (自然言語の主張は機械で照合できない)。
- 起動時の route cache 鮮度検査。**原理的に判定できない** — 本番デプロイは全ファイルを
  新規展開するため mtime は揃い、cache が古いソースから作られたかは起動時からは見えない。
  「作れるが作らない」ではなく「正しく作れない」ものを置かない。

## 期待効果

> **誇張しない**: これは**基盤整備**であり、直接のユーザー価値を増やす改善ではない。
> 保護の実効性は今日も明日も変わらない (振る舞い不変)。増えるのは
> 「次の担当が誤らない」ことと「stale cache という唯一の穴が読み取れる」ことだけである。

- **使命への貢献**: 撮影 PWA の主戦場はスマホで、2FA 秘密の露出 / 第二要素の差し替え /
  passkey 削除は「現場作業者のアカウントが乗っ取られたときの被害」を直接左右する。
  この保護が **stale cache のときだけ無音で外れる**ことが読み取れる状態にすることは、
  「専門知識ゼロの現場作業者でも安全に使える」ための最低条件。
- 次の担当が「cached 起動でも効く」と誤読しない (家系 3 系統の記述が 1 つの機序に揃う)。
- 4 本目の後付け経路が T120 の事故を再発させない。
- デプロイ基盤を作る人が、要件を知らずに作ることを防ぐ (AGENTS.md の運用要件ブロック)。

## 実装方針 (概要)

| 対象 | 変更内容 |
|---|---|
| `app/Providers/FortifyServiceProvider.php` | docblock 是正 / private `appendMiddlewareIfMissing()` を共有 helper 呼び出しへ置換 / spec に feature 条件 |
| `app/Providers/PasskeyServiceProvider.php` | 同上 + `Route::bind` が cached でも有効である旨の区別を追記 |
| `app/Support/Http/RouteMiddlewareBinder.php` (新規) | skip 判定を引数で受ける純粋関数 + booted 配線の唯一の入口 |
| `app/Support/Http/RouteThrottleBinder.php` | **変更しない** (契約記述は既に正しい。§7c への参照だけ 1 行足すか検討) |
| `docs/app-integration-guide.md` | §7c 新設 / §7b から参照 |
| `AGENTS.md` | 運用要件ブロック追加 / ドメイン固有規約 5 から §7c を指す |
| `tests/Unit/Support/Http/RouteMiddlewareBinderTest.php` (新規) | 純粋関数の振る舞い固定 |
| `tests/Architecture/PostBootRouteMutationInventoryTest.php` (新規) | 後付け経路の deny-by-default 目録 |

既存の目録検査 (`ThrottleCoverageInventoryTest` / `RecentAuthRouteTest` /
`TwoFactorStepUpInventoryTest` / `PasskeyRouteProtectionTest` / `InlineThrottleInventoryTest`) は
**1 行も変更しない**。これらは非 cached レーンで走るため、施策 B のあとも同じ結果になる
(付与内容が変わらないことの回帰になる)。

**完了条件** (禁止事項 1 の「実装済み」の定義):
1. 新規 2 テストが green。
2. 上記の既存 route 保護系テストが **1 行も変更されないまま** green
   (= 付与内容が変わっていない = 振る舞い不変の直接の証拠)。
3. `composer phpstan` (level 10) / `vendor/bin/pint --test` が green。
4. `php artisan route:cache` → `php artisan route:list` が例外なく完了し、
   `route:clear` まで往復できることを手で確認 (T120 と同じ確認手順)。

## 制約・前提

- **後付け方式そのものは変えない**。焼き込み方式のままにする (recon-brief「やらなくてよいこと」)。
  無理に変えると T120 / T121 で固めた目録検査との整合を壊す。
- 施策 B は**唯一振る舞いを変える施策**である。変わるのは「非 cached 起動で対象 route が
  引けないとき、無音で素通りしていたのが起動時例外になる」ことだけ。
  feature flag off の正常系を巻き込まないよう spec の `feature` 条件で防ぐ。
- PHPStan level 10 / Pest / `declare(strict_types=1)` / 日本語コメントは既存どおり。
- フロント差分はゼロ (TypeScript / Inertia Props / DS token に波及なし)。

## スコープ外

- **後付け実装の方式変更** (compiled collection への後付け、cache 生成時 hook など)。
- **デプロイ基盤の新設**・`deploy:preflight` 相当のコマンド・CI での `route:cache` 検証。
  今存在しない基盤のために仕組みを作るのは思考原則 2 に反する。**記述として残す**のが本設計の答え。
- **起動時の route cache 鮮度検査** (上記のとおり原理的に判定できない)。
- 閾値・limiter・middleware 構成の変更 (振る舞い不変)。
- `RouteThrottleBinder` の一般化 / 新 helper との統合。throttle 側は形式検証・二重付与検出・
  `computedMiddleware` 破棄という固有責務を持ち、統合すると禁止事項 6 (やたらに複雑な案) に触れる。

## 自分で取った裏 (recon-brief の再検証)

| 主張 | 確認した実体 |
|---|---|
| cached で require を飛ばす | `Illuminate\Support\ServiceProvider::loadRoutesFrom()` の `if (! ($this->app instanceof CachesRoutes && $this->app->routesAreCached())) { require $path; }` |
| Fortify / passkeys がそれを使う | `FortifyServiceProvider::configureRoutes()` / `PasskeysServiceProvider::registerRoutes()` |
| compiled 読み込みが後 | `RouteServiceProvider::register()` の `$this->booted(...)` → `loadCachedRoutes()` → `$this->app->booted(fn () => require ...)` の二段 |
| collection 丸ごと差し替え | `Router::setCompiledRoutes()` が `new CompiledRouteCollection` を作り `container->instance('routes', ...)` |
| nameCache の性質自体は正しい | `CompiledRouteCollection::getByName()` L200-211 の memoize / `match()` L116-130 が `getByName()` を通る |
| 焼き込みが実効の理由 | `RouteCacheCommand::handle()` が `route:clear` → `getFreshApplicationRoutes()` |
| `Route::bind` は別経路 | `Router::bind()` が `$this->binders[...]` に入れる (route collection ではない) |
| feature flag で route が消える | `vendor/laravel/fortify/routes/routes.php` の `Features::enabled(Features::passkeys())` / `twoFactorAuthentication()` 分岐、`config/fortify.php` の「この 1 行が実質的なキルスイッチ」 |
| T120 の事故 | `docs/TODO-closed.md` T120 行「binder の callback 時点で named route が 1 本も解決できず `route:list` が必ず RuntimeException で落ちた」 |
