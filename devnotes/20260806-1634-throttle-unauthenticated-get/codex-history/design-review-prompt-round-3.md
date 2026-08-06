# Round 3: Round 2 指摘への対応

## 対応マトリクス

# 対応マトリクス: design-review Round 2

## [Critical] 施策 6: Filament MFA exemption の前提が機械検証されていない

- 判断: **対応する** (前提を機械化する側を採った)
- 根拠: 指摘が正しい。実査で「今は生成しない」と分かっても、vendor が `mount()` 側へ
  移した瞬間に **GET が秘密生成 endpoint に変わり、inventory は無音で通り続ける**。
  これは本件の最重要条件 (機械が検出できない状態を作らない) に反する。
- 検討した代替: exemption をやめて `two-factor-secret-read` を貼る案。
  だが Filament panel の面と Fortify の 2FA 秘密読み取りレーンを 1 bucket に混ぜることになり、
  レーン設計としては劣化する (施策 4 で「レーンを混ぜない」ことの重要性を示したばかり)。
- 対応内容: **9-7 を新設**。既存の
  `tests/Feature/Filament/AdminMfaBypassPreventionTest.php` が
  `AdminUser::factory()` + `actingAs($admin, 'admin')` で panel 内 URL を叩けることを確認したため、
  premise テストは低コストで書ける。検証内容は
  (a) GET が DB 書込を 1 件も発行しない、
  (b) `app_authentication_secret` / `app_authentication_recovery_codes` が null のまま。
  実装スニペットと `SESSION_DRIVER=array` 依存の注記も設計に入れた。

## [Warning] 施策 9: 「状態が自セッションに閉じる」前提が未検証

- 判断: **対応する** (Codex 提示の 2 案のうち、より安い機械化を採用)
- 根拠: 2 セッションを跨ぐ behavioral テストは、controller 側の intent 検証で先に短絡するため
  「state 検証が効いた」ことを分離して示せず、**空振り green** になりやすい。
  一方この前提は Socialite `hasInvalidState()` (session 由来の `state` を `hash_equals`) と
  Laravel の session 分離が保証する vendor 側の性質であり、
  **アプリがこれを壊しうる現実的な経路は `->stateless()` の付与ただ一つ**。
- 対応内容: **9-6 を新設**し、`SocialAuthController` のソースに `stateless(` が現れないことを
  deny-by-default で固定した (同ファイル内の `debug.login-as` 登録条件のソース走査と同じ流儀)。
  「なぜソース走査なのか」の根拠も設計に明記した。

## [Warning] 施策 9: `recent-auth.confirm` がまだ「実装時に確認」のまま

- 判断: **対応する** (実査で確定させた)
- 根拠: 「exemption 理由と実装の一致」がレビュー重点である以上、1 件だけ未確定は不整合。
- 対応内容: `ConfirmRecentAuthController::show()` / `buildStatus()` を実査。
  `hasPassword()` / `socialAccounts()->pluck()` / `passkeys()->exists()` の **read のみ**で、
  鮮度は session から読む。**DB 書込なし**を確定し「実査で確定させた事項」の表へ移した。
  「実装時に確認すること」は施策 1 の fail 件数の確認 1 項目だけになった。

## [Warning] 施策 10・段階分けの件数が計画と不一致

- 判断: **対応する**
- 対応内容:
  - 段階分けを「named limiter 3 本 / throttle 5 本 / exemption 14 件 (新 case 2) /
    inventory 検査 3 本追加 / 前提テスト 7 本 / behavioral proof 8 本 / docs」に更新
  - 検証コマンド表の `RateLimiterKeyConventionTest` 期待結果に
    `two-factor-secret-read:user:` / `two-factor-secret-read:ip:` を追加

---

## 修正後の施策 9 (全文)

## 施策 9: 新 exemption case の前提 proof

### 変更箇所

- `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (末尾に追加)

### テスト計画

| # | テスト名 | 対象 case | 検証内容 |
|---|---------|-----------|---------|
| 9-1 | `AuthViewRenderOnly の代表 GET は外向き HTTP もメール送信も起こさない` | `AuthViewRenderOnly` | `Http::preventStrayRequests()` + `Mail::fake()` の下で `/login` `/register` `/forgot-password` を GET し `Mail::assertNothingSent()` |
| 9-2 | `AuthViewRenderOnly の代表 GET は DB 書込を 1 件も発行しない (read は許す)` | `AuthViewRenderOnly` | `DB::listen` で収集した SQL に書込文が 0 件 |
| 9-3 | `SQL 書込判定の検出器そのものが機能する` | (検出器) | 判定関数の単体ケース |
| 9-4 | `social.redirect は外向き HTTP を発行しない (Socialite の redirect は URL 組み立てのみ)` | `AuthFlowInitiation…` | `Http::preventStrayRequests()` + Socialite spy で `->user()` が呼ばれないことを確認。**DB 書込 0 件は要求しない** (下記参照) |
| 9-5 | `social.redirect の exemption 前提: 対になる social.callback が throttle:social-callback を**ちょうど 1 本**持つ` | `AuthFlowInitiation…` | 適用条件 4 番目。callback の throttle を外す / 別 limiter に差し替えるとここで fail する |
| 9-6 | `SocialAuthController は stateless() を使わない (OAuth state が自セッションに閉じる根拠)` | `AuthFlowInitiation…` | 適用条件 3 番目。`stateless()` を入れると state が session に紐づかなくなり「他セッションから消費できない」が崩れる。**ソース走査**で固定する (`debug.login-as` の登録条件走査と同じ流儀) |
| 9-7 | `filament.admin.auth.multi-factor-authentication.set-up-required の GET は MFA 秘密を生成・永続化しない` | `AuthViewRenderOnly` | **Round 2 Critical の解消**。vendor が将来 `mount()` で生成を始めたら落ちる |

> **9-4 で DB 書込 0 件を要求しない理由** (レビュー指摘の反映):
> `social.redirect` は **session に OAuth state と intent を書く**。
> `SESSION_DRIVER=database` の環境ではこれが DB 書込として観測され、
> 「自セッション内の副作用は許容する」という case の適用条件と検査条件が衝突する。
> `AuthFlowInitiationWithoutOutboundCall` の適用条件は
> 「**外向き HTTP を発行しない**」「状態が自セッションに閉じる」「完了経路が throttle 済み」
> であり、DB 書込の有無は条件に入っていない。**条件に無いものを検査しない**
> (検査と条件がずれると、将来 driver を変えただけで green/red が動く不安定な網になる)。

### 判定関数のシグネチャ

```php
/**
 * SQL が書込文か (deny-by-default = 迷ったら write 扱いにする)。
 *
 * ★SQL パーサは導入しない。対象 route が発行するのは Eloquent / query builder 生成の
 *   SQL のみで先頭コメントが付かないため、先頭動詞の判定で足りる。
 * ★ただし CTE (`with ... as (...) insert ...`) は先頭動詞が `with` になり、
 *   単純な前方一致では**書込を見逃す**。deny-by-default では見逃しが最悪の失敗なので、
 *   `with` で始まる文に insert/update/delete が現れたら**保守的に write 扱い**にする
 *   (過検出は「exemption を諦めて throttle を貼る」方向にしか倒れないので安全)。
 * ★検出器が黙って壊ると「DB 書込があるのに exemption は通り続ける」= 最悪失敗になるため、
 *   判定関数自身の単体ケースを同ファイルに置く (9-3)。
 *
 * @return bool insert / update / delete / truncate で始まる、または
 *              with で始まりこれらの動詞を含むなら true
 */
function throttlePremiseIsWriteStatement(string $sql): bool
```

9-3 の単体ケース (最低限):

| 入力 | 期待 |
|------|------|
| `'  insert into "users" ...'` (先頭空白) | true |
| `'UPDATE "users" SET ...'` (大文字) | true |
| `'select * from "users"'` | false |
| `'with recent as (select ...) insert into "logs" ...'` | true (保守的 write 扱い) |
| `'with recent as (select ...) select * from recent'` | false |

### 9-5 の実装メモ

```php
test('social.redirect の exemption 前提: social.callback が throttle:social-callback を持つ', function (): void {
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $routes = $router->getRoutes();
    $routes->refreshNameLookups();

    $callback = $routes->getByName('social.callback');
    // ★expect()->not->toBeNull() は PHPStan の型を絞らない。
    //   level 10 で throttleEntries(Router, Route) に渡すため Assert で narrowing する
    //   (リポジトリ標準は Webmozart\Assert)。
    Assert::isInstanceOf($callback, RoutingRoute::class);

    // ★throttleEntries() は Router::gatherRouteMiddleware() の**解決後**の実効 middleware 列を
    //   filter する (RouteThrottleBinder.php:171-174)。第 3 段の付与台帳ではないため、
    //   routes/web.php に直書きした第 1 段の throttle もここに現れる。
    $entries = RouteThrottleBinder::throttleEntries($router, $callback);

    expect($entries)->toHaveCount(1);
    // limiter 名まで固定する (throttle は付いているが別 limiter に差し替わっていた、を検出)
    expect(Str::after($entries[0], ':'))->toBe('social-callback');
});
```

必要な import: `use Illuminate\Routing\Route as RoutingRoute;` / `use Illuminate\Routing\Router;` /
`use Illuminate\Support\Str;` / `use Webmozart\Assert\Assert;` /
`use App\Support\Http\RouteThrottleBinder;`

### 9-6 の実装メモ (state が自セッションに閉じる根拠)

**なぜソース走査なのか**: 「session A の state を session B の callback で消費できない」は
Socialite `AbstractProvider::hasInvalidState()` (`$this->request->session()->pull('state')` と
query の `state` を `hash_equals`) と Laravel の session 分離が保証する **vendor 側の性質**であり、
アプリ側が組み立てているものではない。
アプリがこれを壊しうる**唯一の現実的な経路が `->stateless()` の付与**なので、
そこを deny-by-default で塞ぐのが費用対効果の合う網である
(2 セッションを跨ぐ behavioral テストは、controller 側の intent 検証で先に短絡するため
「state 検証が効いた」ことを分離して示せず、空振り green になりやすい)。

```php
test('SocialAuthController は stateless() を使わない (OAuth state が自セッションに閉じる根拠)', function (): void {
    $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));
    expect($source)->toBeString();

    // stateless() を入れると state が session に紐づかず、
    // AuthFlowInitiationWithoutOutboundCall の適用条件 3 番目 (状態が自セッションに閉じる) が崩れる。
    expect($source)->not->toContain('stateless(');
});
```

### 9-7 の実装メモ (Filament MFA GET の behavioral proof)

`tests/Feature/Filament/AdminMfaBypassPreventionTest.php` と同じ流儀
(`AdminUser::factory()` + `actingAs($admin, 'admin')`) で書ける。
MFA 未設定 admin はこの set-up URL に到達できることが既存テストで確認済み。

```php
test('filament.admin.auth.multi-factor-authentication.set-up-required の GET は MFA 秘密を生成・永続化しない', function (): void {
    // AuthViewRenderOnly の適用条件「秘密を開示・生成しない」の behavioral proof。
    // vendor (Filament) は現状 SetUpAppAuthenticationAction::mountUsing() = Livewire POST 側で
    // generateSecret() / generateRecoveryCodes() を呼ぶが、将来 mount() 側へ移ると
    // **GET が秘密生成 endpoint に変わる**。そのとき inventory は無音で通り続けるため固定する。
    $admin = AdminUser::factory()->create();   // MFA 未設定
    $this->actingAs($admin, 'admin');

    $queries = [];
    DB::listen(static function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $response = $this->get('/admin/multi-factor-authentication/set-up');
    expect($response->getStatusCode())->toBe(200);

    $writes = array_values(array_filter($queries, throttlePremiseIsWriteStatement(...)));
    expect($writes)->toBe([], 'GET が DB 書込を発行しました: '.implode(' / ', $writes));

    $fresh = $admin->fresh();
    Assert::isInstanceOf($fresh, AdminUser::class);
    expect($fresh->app_authentication_secret)->toBeNull();
    expect($fresh->app_authentication_recovery_codes)->toBeNull();
});
```

> **前提**: `phpunit.xml` が `SESSION_DRIVER=array` を `force="true"` で固定しているため
> (`phpunit.xml:56`)、session 書き込みが DB クエリとして観測されない。
> 9-2 / 9-7 の「DB 書込 0 件」はこの固定に依存する。**driver を変えるときは両テストを見直すこと**
> (テストのコメントにこの依存を明記する)。

### リスク

- 9-1 / 9-2 の対象は 3 route (13 本すべてではない)。
  **13 本すべてに広げない理由**: `filament.admin.auth.*` は panel 権限を持つ user の用意が要り、
  `password.reset/{token}` / `two-factor.login` は分岐条件を満たさないと
  「描画されなかっただけ」の**空振り green** になる。空振りする 13 本の網より、
  実効する 3 本の網 + `auth_view_render_only` の exact-fit cap (13) の方が
  deny-by-default として強い (14 本目が必ず再レビューを強制する)。

---

## 施策 10: ドキュメント更新

## 修正後の「実装時に確認すること / 実査で確定させた事項」(全文)

## 実装時に確認すること

1. 施策 1 の fail 出力が **19 本ちょうど**であること。
   本設計の実測 (23 本 - throttle 済み 4 本) と食い違ったら、
   その差分の原因 (機能フラグ / vendor 更新) を突き止めてから分類に入る。
   再現用スクリプトは
   `devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php`。

### 設計時に実査で確定させた事項 (再調査不要)

| 事項 | 確定内容 | 根拠 |
|------|---------|------|
| Filament MFA set-up-required の GET が TOTP 秘密を生成するか | **しない**。生成は `SetUpAppAuthenticationAction::mountUsing()` (Livewire POST = `default-livewire.update`) の中 | `vendor/filament/filament/src/Auth/MultiFactor/App/Actions/SetUpAppAuthenticationAction.php:45-54` / `.../Pages/SetUpRequiredMultiFactorAuthentication.php:21-25` |
| inline throttle の bucket 単位 | **actor ごとに 1 つ。route も limiter 名もキーに入らない** (= 全 inline route で共有) | `vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php` `handle()` (`$prefix` 既定 `''`) + `resolveRequestSignature()` L224-233 |
| named limiter の bucket 単位 | limiter 名がキーに入る → **レーンが独立する** | 同 `handleRequestUsingNamedLimiter()` L131-141 |
| `social.callback` の増幅条件 | intent (controller) と state (Socialite) の両方を満たさないと外向き HTTP は起きない | `SocialAuthController.php:81-88` / `vendor/laravel/socialite/src/Two/AbstractProvider.php:236-244` |
| 母集団の実測 | 現行 47 → 拡張後 70 (増分 23、うち 4 本は既に throttle 済み) | `measure-population.php` を `APP_ENV=testing` で実行 |
| `recent-auth.confirm` の `show()` が DB 書込をするか | **しない**。`buildStatus()` は `hasPassword()` / `socialAccounts()->pluck()` / `passkeys()->exists()` の **read のみ**で、鮮度は session から読む | `app/Http/Controllers/Auth/ConfirmRecentAuthController.php` `show()` / `buildStatus()` |
| テスト時の `SESSION_DRIVER` | `array` (`force="true"` で固定) — DB 書込 0 件検査の前提 | `phpunit.xml:56` |
| Filament admin のテスト流儀 | `AdminUser::factory()` (+ `withMfa()`) + `actingAs($admin, 'admin')` で panel 内 URL を叩ける | `tests/Feature/Filament/AdminMfaBypassPreventionTest.php` |

## 段階分け・検証コマンドの更新点

- 検証コマンド表 #2 の期待結果: `green (limiter 名集合が一致し、キーが social-callback:ip: / invitation-accept:ip: / two-factor-secret-read:user: / two-factor-secret-read:ip:)`
- 段階分け「このタスクでやる」: `施策 1〜10 のすべて (S3 拡張 / named limiter 3 本 / throttle 5 本 / exemption 14 件 (新 case 2) / inventory 検査 3 本追加 / 前提テスト 7 本 / behavioral proof 8 本 / docs)`

残 Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。
