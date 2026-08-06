# Round 2: Round 1 指摘への対応

## 対応マトリクス

# 対応マトリクス: design-review Round 1

## [Critical] 施策 6: Filament MFA set-up-required の exemption が「実装時に確認」のまま

- 判断: **対応する** (実査で確定させた)
- 根拠: deny-by-default gate で「未確定のまま exemption を置く」のは最悪。指摘が正しい。
- 対応内容: vendor 実装を実査し、**GET の描画では TOTP 秘密もリカバリコードも生成されない**
  ことを確定した。生成は `SetUpAppAuthenticationAction::mountUsing()`
  (`vendor/filament/filament/src/Auth/MultiFactor/App/Actions/SetUpAppAuthenticationAction.php:45-54`)
  = **Livewire POST (`default-livewire.update`)** の中で起きる。
  `SetUpRequiredMultiFactorAuthentication::mount()` は enable 済み判定と redirect のみ。
  exemption 理由を「導線リンクの描画のみ / 生成は mountUsing 側」と実装に即した文言へ書き換え、
  「実装時に確認すること」から削除した。設計末尾に**実査で確定させた事項の表**を新設。

## [Critical] 施策 9-4: PHPStan level 10 で `$callback` の null が絞り込まれない

- 判断: **対応する**
- 根拠: `expect()->not->toBeNull()` は PHPStan の narrowing にならない。指摘のとおり。
- 対応内容: `Webmozart\Assert\Assert::isInstanceOf($callback, RoutingRoute::class)` で
  narrowing する形に書き換え (リポジトリ標準は Webmozart Assert。`assert()` ではなく
  既存コード (`SocialAuthController` 等) と同じ流儀に揃えた)。必要な import も明記。

## [Warning] 施策 4: 2FA GET へ `10,1` を貼ると既存 2FA 操作と bucket を共有するのでは

- 判断: **対応する (設計を変更した。本レビューで最大の収穫)**
- 根拠: 指摘を受けて vendor を実査した結果、**共有することが確定**した。
  `ThrottleRequests::handle()` の inline 分岐はキーを
  `$prefix.resolveRequestSignature($request)` で作り、`$prefix` の既定は `''`、
  `resolveRequestSignature()` は認証済みなら `formatIdentifier($user->getAuthIdentifier())`
  **だけ**を返す (route も limiter 名も入らない)。
  一方 named limiter は `md5($limiterName.$limit->key)` でレーンが分かれる。
  → ページ描画のたびに 2 発飛ぶ GET を inline に足すと、
  **2FA 設定画面を 3 回リロードしただけで共有カウンタが 6 に達し、
  `recent-auth.password` (max 6) が 429 = 再認証できなくなる**。
  「秘密 GET を有界化するために再認証を壊す」は明確な後退。
- 対応内容:
  - 施策 4 を **named limiter `two-factor-secret-read` (10/min、user | ip の 2 分岐)** に変更。
    閾値 10/min は姉妹の 2FA 管理操作と同値のまま (値は発明していない)
  - 変更の根拠 (vendor 実装の引用付き) を施策 4 の冒頭節として明記
  - AGENTS.md の規約との関係を明記: 文言 (「認証済みかつ actor 自身」) は満たすが、
    **根拠**(「既定キーがちょうど求める数える単位になる」) が成立しないため規約の根拠に従う
  - 施策 7 に `two-factor-secret-read` の scenario を追加 (limiter は 3 本になる)
  - 施策 10 に「inline throttle は route ごとの bucket ではない」を docs 追記項目として追加
  - 後続 TODO 候補に「既存 inline throttle 群の bucket 共有の見直し」を追加
    (既存レーンの分離は閾値と数える単位の再設計になるため本タスク外)

## [Warning] 施策 8-5 が middleware entry の存在確認に寄っている / bucket 共有を示せない

- 判断: **対応する**
- 根拠: 上記の発見により、この指摘は決定的に重要になった。
- 対応内容: テストを 6 本 → 8 本に増やし、
  **8-6「2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が
  429 にならない」を新設**した (inline へ戻したらここで落ちる恒久回帰)。
  8-7 で実際の 429 発生も固定する (存在確認だけにしない)。

## [Warning] 施策 8: `Http::preventStrayRequests()` は Socialite/Guzzle を保証しない

- 判断: **対応する**
- 根拠: Socialite は Guzzle を直接使うため Laravel HTTP client の fake では捕まらない。正しい。
- 対応内容: 8-3 を新設し、**Socialite ファサードの spy で `driver()` が呼ばれないこと**を
  直接 assert する形にした。`preftStrayRequests()` は追加の網としてのみ併用し、
  単独の根拠にしないことを実装注意に明記。

## [Warning] 施策 9-2 が `social.redirect` にも DB 書込 0 件を要求している (session driver と衝突)

- 判断: **対応する**
- 根拠: `social.redirect` は session に OAuth state を書く。`SESSION_DRIVER=database` では
  DB 書込として観測され、case の適用条件 (自セッション内の副作用は許容) と検査条件が衝突する。
- 対応内容: 9-1 / 9-2 の対象を `AuthViewRenderOnly` 代表 3 本に限定し、
  `social.redirect` は 9-4 (外向き HTTP なし / Socialite spy) + 9-5 (完了経路の throttle 実在)
  で検証する形に分離。**条件に無いものを検査しない**理由も明記した。

## [Warning] 施策 9: CTE (`with ... insert`) を先頭動詞判定では検出できない

- 判断: **対応する**
- 根拠: deny-by-default では見逃しが最悪。過検出は「exemption を諦めて throttle を貼る」方向に
  しか倒れないので安全、という指摘の論理が正しい。
- 対応内容: 判定を「先頭が insert/update/delete/truncate、**または** `with` で始まり
  これらの動詞を含む」に変更 (保守的に write 扱い)。9-3 の単体ケース表も更新。

## [Warning] 施策 6: タイトルが「検査 2 本追加」だが snippet は 3 本

- 判断: **対応する**
- 対応内容: 施策一覧・見出し・波及変更をすべて「検査 3 本追加」に統一。

## [Warning] 施策 6: case 別 cap のテストが「未使用 case」を検出しない (説明と不一致)

- 判断: **対応する** (説明を直すのではなく**検査を強くする**方を採った)
- 根拠: 説明どおり「新 case を足したら上限も同時に決めさせる」方が deny-by-default として強い。
  使用時に初めて要求する形だと、使い始めた瞬間に上限なしで通る窓が空く。
- 対応内容: `ThrottleCoverageExemption::cases()` を走査して**全 case に cap を要求**する形に変更。
  併せて cap 側に enum に無い case が残っていないか (rename/削除の stale) も検出するようにした。

## [Warning] 施策 10: 後半に `exemption 25 / cap 26` と読める記述があり exact fit と矛盾

- 判断: **対応する**
- 対応内容: 概念設計 §8-2 の検証表と §10 のリスク表を
  「全体 cap 25 (exact fit) + case 別上限」に統一。

## [Warning] 施策 2: 無効リクエストも正当ユーザーの枠を消費する点を明記すべき

- 判断: **対応する**
- 対応内容: limiter の docblock に「無効リクエストも同じ bucket を消費する = 一時 DoS が残る」
  ことと、その引き換えに得ているもの (外向き HTTP / token 照合の総量が有界になる) を明記。
  テスト名 (8-1 / 8-4) にも「無効 request でも枠を消費する」観点を入れた。

## [Warning] 施策 3: `social.redirect` 無制限との組み合わせで callback 枠を枯らす一時 DoS が残る

- 判断: **対応する** (許容リスクとして docs に残す)
- 対応内容: 施策 10 の docs 追記項目に「invalid callback 比率の監視」と
  「`social.redirect` を throttle しないため一時 DoS は残る」を明記。

## [Suggestion] 施策 1: fail 観測ログに実測母集団数も残す運用

- 判断: **対応する** (軽微)
- 対応内容: 再現用スクリプト
  `devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php` を設計成果物として
  同梱し、「実装時に確認すること」から参照するようにした。

---

## 修正後の該当節 (全文)

## 施策 2: named limiter 2 本の新設

### 変更箇所

- `app/Providers/AppServiceProvider.php` (L236-240 の呼び出し + 新 private メソッド)

### 波及変更

- テストファイル: `tests/Architecture/RateLimiterKeyConventionTest.php` (施策 7 で必須登録)
- TypeScript 型定義 / DTO: なし

### 変更後コード

`boot()` の限定登録ブロックに 1 行追加:

```php
        $this->configureApiRateLimiters();
        $this->configureAuthSurfaceRateLimiters();   // ★追加
        $this->configureInquiryRateLimiter();
        $this->configureRenderRateLimiter();
        $this->configureWebhookRateLimiters();
```

新規メソッド (`configureApiRateLimiters()` の直前あたりに置く):

```php
    /**
     * 未認証で到達する認証面 GET の RateLimiter (T120 事後監査の是正)。
     *
     * ★どちらも**未認証**面のため named limiter で数える単位を明示する。
     *   inline throttle (`10,1`) はフレームワーク既定キーに依存するため、
     *   AGENTS.md の規約どおり「認証済みかつ actor 自身に閉じる操作」以外では使わない。
     *
     * ★閾値は発明しない (AG-096 = 閾値はプロダクト依存):
     *   - social-callback  = 10/min。未認証で到達する認証面の IP レーンとして
     *     本番稼働中の `passkeys` limiter の guest 分岐 (10/min) と同値。
     *   - invitation-accept = 10/min。姉妹操作 invitations.accept.store の
     *     `throttle:10,1` と同値 (同じ token 照合を行う 2 本の非対称を解消する)。
     *
     * ★キーに route parameter / query token を混ぜない (NamedRateLimiterKeyTest)。
     *   social.callback の {provider} や invitations.accept の ?token= を key に入れると
     *   bucket が分かれ、「429 になるまでの回数」が実在オラクルになる。
     *
     * ★**無効リクエストも同じ bucket を消費する** (throttle は controller より前に走る)。
     *   intent 不在の callback / 無効 token の招待 open も枠を減らすため、
     *   同一 IP からの無効連打は正当利用者の枠を奪える (一時 DoS)。
     *   これは「未認証面を IP で数える」ことの必然であり、
     *   引き換えに得ているのは「外向き HTTP と token 照合の総量が有界になること」である。
     *
     * ★巻き添えの扱い: IP レーンである以上、同一 NAT 配下の一斉ログイン / 一斉招待受諾は
     *   巻き添え 429 になりうる。limiter は恒久ロックを作らないが到達は保証しない。
     *   運用は 429 発生率と invalid callback 比率を監視し、
     *   **初動は閾値変更ではなく TRUSTED_PROXIES / 実 client IP の解決の確認**とする
     *   (docs/trusted-proxies-runbook.md)。
     */
    private function configureAuthSurfaceRateLimiters(): void
    {
        // SSO callback。1 リクエストで IdP へ token エンドポイント POST が飛びうる
        // (state + intent が揃った場合)。未認証で外部へ HTTP を発射できる唯一の経路。
        RateLimiter::for('social-callback', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('social-callback:ip:'.($request->ip() ?? 'unknown')));

        // 招待受諾の確認画面 (GET)。未認証入力の token を sha256 照合して DB を 1 件引き、
        // 有効/無効で応答が分岐する。姉妹の POST は既に throttle:10,1 で有界化されている。
        RateLimiter::for('invitation-accept', fn (Request $request): Limit => Limit::perMinute(10)
            ->by('invitation-accept:ip:'.($request->ip() ?? 'unknown')));
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示 (`fn (Request $request): Limit` / メソッドは `void`)
- [x] null 安全 — `$request->ip()` は `?string` なので `?? 'unknown'` で潰す
      (既存 `webhook-ses` / `oauth-register` と同じ書き方。型を緩めない)
- [x] DTO 返却 (対象外)
- [x] Generics (対象外)

### リスク

- `RateLimiter::for()` の第 1 引数をリテラルで書かないと
  `RateLimiterRegistrationScanner` が `unresolved` として fail する。**必ずベタ書き**。

---

## 施策 3: 自前 route 2 本への throttle 付与 (第 1 段)

### 変更箇所

- `routes/web.php` L164-165 (`social.callback`) / L600-601 (`invitations.accept`)

### 波及変更

- テストファイル: `tests/Feature/Security/AuthThrottleCoverageTest.php` (施策 8)
- frontend: なし (429 の UI 表現は別 feature `error-response-contract`)

### 現行コード

```php
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->name('social.callback');
```

```php
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->name('invitations.accept');
```

### 変更後コード

```php
Route::get('/auth/{provider}/redirect/{intent}', [SocialAuthController::class, 'redirect'])
    ->name('social.redirect');
// callback は SocialAuthController::callback() 内の Socialite::driver()->user() で
// **IdP への外向き HTTP** が起きる (未認証で外部へ HTTP を発射できる唯一の経路)。
// 未認証面のため named limiter で IP レーンを明示する (閾値は passkeys guest と同値)。
// redirect 側は外向き通信をしないため throttle を貼らず、exemption
// (AuthFlowInitiationWithoutOutboundCall) として理由を inventory に残す。
Route::get('/auth/{provider}/callback', [SocialAuthController::class, 'callback'])
    ->middleware('throttle:social-callback')
    ->name('social.callback');
```

```php
// GET も token を sha256 照合して DB を 1 件引き、有効/無効で応答が分岐する
// (未認証で観測できる分、姉妹の POST より攻撃面として広い)。
// POST 側の `10,1` と同値にする。未認証面のため named limiter でキーを明示する。
Route::get('/invitations/accept', [InvitationAcceptanceController::class, 'show'])
    ->middleware('throttle:invitation-accept')
    ->name('invitations.accept');
```

### PHPStan 適合チェック

- [x] 型 (route 定義のため対象外)

### リスク

- `social.callback` は `{provider}` を持つが、throttle は `SubstituteBindings` より前に走り
  limiter が provider を読まないため、存在オラクルにはならない。
- 巻き添え: §概念設計 §10-1。

---

## 施策 4: Fortify の 2FA 秘密 GET 3 本への throttle 後付け (第 3 段)

### ⚠ 設計変更の根拠: inline throttle は **actor ごとの単一 bucket** を全 route で共有する

レビューで「inline bucket を既存の 2FA 操作と共有するのでは」と問われ、
vendor 実装を実査した結果、**共有する**ことが確定した。当初案の inline `10,1` は**採らない**。

`vendor/laravel/framework/src/Illuminate/Routing/Middleware/ThrottleRequests.php`:

```php
// handle() — inline (`{max},{decay}`) の場合
'key' => $prefix.$this->resolveRequestSignature($request),   // $prefix は既定 ''

// resolveRequestSignature() (L224-233)
if ($user = $request->user()) {
    return $this->formatIdentifier($user->getAuthIdentifier());   // ← route を含まない
}

// handleRequestUsingNamedLimiter() — named limiter の場合
'key' => self::$shouldHashKeys ? md5($limiterName.$limit->key) : $limiterName.':'.$limit->key,
```

- **named limiter**: キーに limiter 名が入る → **レーンが分かれる**
- **inline throttle**: キーは `sha1(user id)` のみ → **同一ユーザーの全 inline route が 1 bucket を共有する**
  (route ごとに違うのは `maxAttempts` の比較値だけ)

現状 inline を使っている認証済み route: `password.confirm.store` (6,1) /
`user-password.update` (6,1) / `two-factor.enable` `.confirm` `.disable`
`.regenerate-recovery-codes` (10,1) / `recent-auth.password` (6,1) /
`settings.password.store` (6,1) / `invitations.accept.store` (10,1) 他。

ここへ **ページ描画のたびに 2 発飛ぶ GET** (`two-factor.qr-code` + `.secret-key`) を
同じ bucket で足すと、**2FA 設定画面を 3 回リロードしただけで共有カウンタが 6 に達し、
`recent-auth.password` (max 6) が 429 になる** = 再認証できなくなる。
「秘密 GET を有界化するために再認証を壊す」は後退であり、採ってはならない。

> **AGENTS.md との関係**: 規約の文言 (「inline は認証済みかつ actor 自身に閉じる操作限定」) は
> 満たすが、その**根拠**である「フレームワーク既定のキーがちょうど求める数える単位になる」が
> 成立しない (既定キーの単位は "このユーザーの全 inline route 合算" であって
> "この読み取りレーン" ではない)。**規約の文言ではなく根拠に従う**。

### 変更箇所

- `app/Providers/FortifyServiceProvider.php`
  - `configureRateLimiters()` — named limiter `two-factor-secret-read` を新設
  - `throttledFortifyRoutes()` (L155-168) — 3 本を追加

### 波及変更

- テストファイル: `tests/Feature/Security/AuthThrottleCoverageTest.php` (施策 8) /
  `tests/Architecture/RateLimiterKeyConventionTest.php` (施策 7。**limiter が 3 本になる**)

### 変更後コード (1): limiter の新設

`FortifyServiceProvider::configureRateLimiters()` の `passkeys` の直後に置く:

```php
        /*
         * 2FA の秘密を返す GET (qr-code / secret-key / recovery-codes) の読み取りレーン。
         *
         * ★inline (`10,1`) にしない: inline のキーは sha1(user id) だけで
         *   **同一ユーザーの全 inline route が 1 bucket を共有する**
         *   (ThrottleRequests::resolveRequestSignature)。ページ描画で 2 発飛ぶ GET を
         *   そこへ足すと、リロード数回で recent-auth.password (max 6) まで 429 にしてしまう。
         *   named limiter はキーに limiter 名が入るためレーンが独立する。
         *
         * ★閾値 10/min は姉妹の 2FA 管理操作 (two-factor.enable / .confirm / .disable /
         *   .regenerate-recovery-codes の `10,1`) と同値 (新しい値を発明しない)。
         *
         * ★throttle は auth middleware より先に走る (priority list) ため未認証でも
         *   closure が評価される。passkeys limiter と同じく IP へ倒す。
         *
         * ★これは**連続取得の回数上限**であって、秘密の漏えい防止でも step-up の代替でもない。
         *   認証強度 (recent-auth 化) は aicue:T120 の後続 TODO B2 の担当。
         */
        RateLimiter::for('two-factor-secret-read', function (Request $request): Limit {
            $identifier = $request->user()?->getAuthIdentifier();

            return is_scalar($identifier)
                ? Limit::perMinute(10)->by('two-factor-secret-read:user:'.$identifier)
                : Limit::perMinute(10)->by('two-factor-secret-read:ip:'.($request->ip() ?? 'unknown'));
        });
```

### 変更後コード (2): 後付け表への追加

```php
            'two-factor.enable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.confirm' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.disable' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.regenerate-recovery-codes' => ['throttle' => '10,1', 'feature' => Features::twoFactorAuthentication()],
            // ★秘密を返す GET 3 本 (T120 事後監査の是正)。
            //   named limiter を使う理由は configureRateLimiters() の
            //   two-factor-secret-read の docblock を参照 (inline は bucket を
            //   全 inline route で共有するため、描画 GET を足すと再認証を壊す)。
            'two-factor.qr-code' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.secret-key' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
            'two-factor.recovery-codes' => ['throttle' => 'two-factor-secret-read', 'feature' => Features::twoFactorAuthentication()],
        ];
```

`RouteThrottleBinder::assertValidLimiter()` の named 形式 (`/^[a-z][a-z0-9-]*$/`) に
`two-factor-secret-read` は適合する。

### 注意 (実装者向け)

- `two-factor.recovery-codes` は **GET** の route 名で、POST は
  `two-factor.regenerate-recovery-codes`。別 route なので二重付与にはならない
  (`vendor/laravel/fortify/routes/routes.php:170,174`)。
- `two-factor.recovery-codes` には `FortifyServiceProvider::attachRecentAuthToSensitiveRoutes()`
  が `recent-auth` を後付けしている。**throttle は `recent-auth` より先に走る**
  (`bootstrap/app.php` の priority list)。既存テスト
  「2FA 管理 route は throttle が recent-auth より先に走る」の対象に GET も加える (施策 8)。
- `RouteThrottleBinder::attachByName()` は既存 throttle があれば期待値と比較して
  一致すれば no-op、不一致なら **起動時に RuntimeException** で止まる。
  Fortify が将来この 3 本に limiter を付けたら起動が止まり、そこで気づける (fail-fast)。

### PHPStan 適合チェック

- [x] 戻り値の型 (`array<string, array{throttle: string, feature: string|null}>` のまま /
      limiter closure は `function (Request $request): Limit`)
- [x] null 安全 — `$request->user()?->getAuthIdentifier()` は `mixed`。
      `is_scalar()` で絞ってから文字列連結する (既存 `passkeys` limiter と同一の書き方。
      型を緩めない)

### リスク

- 2FA 設定画面が `qr-code` + `secret-key` を毎描画で叩くなら 1 表示 = 2 消費。
  10/min なのでリロード 5 回で 429 になる。**actor 自身の読み取りレーンに閉じており、
  他ユーザーにも他操作にも波及しない**ことを施策 8 の 8-6 で固定する。

---

## 施策 5: exemption enum に case 2 つ追加

### 6-c. 検査 3 本の追加 (既存テストの構造的な穴を塞ぐ)

```php
test('exemption inventory の key は throttle を 1 本も持たない (死んだ exemption の検出)', function (): void {
    // ★既存の「ちょうど 1 本 or exemption」検査は count($entries) === 1 で先に continue するため、
    //   *throttle 済みなのに exemption にも登録されている* 状態を検出できない。
    //   stale 検出も「母集団に存在するか」しか見ないため素通りする。
    //   放置すると「もう不要な免除理由」が台帳に溜まり、次に読む人を誤らせる。
    /** @var Router $router */
    $router = Route::getFacadeRoot();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }

        $entries = RouteThrottleBinder::throttleEntries($router, $route);
        if ($entries !== []) {
            $violations[] = "{$label}: throttle ({$entries[0]}) が付いているのに exemption にも登録されています";
        }
    }

    expect($violations)->toBe([],
        'throttle を貼ったら exemption inventory から削除してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});

test('認証面 GET 用の exemption case は非変更系 route にしか使われない', function (): void {
    // AuthViewRenderOnly / AuthFlowInitiationWithoutOutboundCall の適用条件 1 番目
    // (GET/HEAD のみ) を機械化する。変更系がこの箱に落ちると、
    // 「描画だから」という理由で副作用のある route が免除される。
    $getOnlyCases = [
        ThrottleCoverageExemption::AuthViewRenderOnly,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall,
    ];
    $mutating = throttleCoverageMutatingMethods();
    $inventory = throttleCoverageExemptions();
    $violations = [];

    foreach (throttleCoverageProtectedRoutes() as $route) {
        $label = throttleCoverageRouteLabel($route);
        if (! array_key_exists($label, $inventory)) {
            continue;
        }
        if (! in_array($inventory[$label][0], $getOnlyCases, true)) {
            continue;
        }
        if (array_intersect($mutating, $route->methods()) !== []) {
            $violations[] = "{$label}: 変更系 (".implode('|', $route->methods()).') に GET 専用 case が使われています';
        }
    }

    expect($violations)->toBe([], PHP_EOL.implode(PHP_EOL, $violations));
});

test('exemption の case 別件数が上限を超えない (分類の偏り検出)', function (): void {
    // ★走査対象は **enum の全 case**。使用中の case だけを見ると、
    //   「新しい case を足したが cap を決めていない」状態を検出できない
    //   (使い始めた瞬間に上限なしで通ってしまう)。
    $caps = throttleCoverageExemptionCapByCase();

    $counts = [];
    foreach (ThrottleCoverageExemption::cases() as $case) {
        $counts[$case->value] = 0;
    }
    foreach (throttleCoverageExemptions() as [$exemption, $reason]) {
        $counts[$exemption->value]++;
    }

    $violations = [];
    foreach ($counts as $case => $count) {
        if (! array_key_exists($case, $caps)) {
            $violations[] = "{$case}: throttleCoverageExemptionCapByCase() に上限が登録されていません";

            continue;
        }
        if ($count > $caps[$case]) {
            $violations[] = "{$case}: {$count} 件 (上限 {$caps[$case]})";
        }
    }

    // cap 側に enum に無い case が残っていないか (rename / 削除の stale 検出)
    foreach (array_keys($caps) as $case) {
        if (! array_key_exists($case, $counts)) {
            $violations[] = "{$case}: enum に存在しない case の上限が残っています";
        }
    }

    expect($violations)->toBe([],
        'exemption の case 別件数が上限を超えました。上限を上げる前に、'
        .'その case へ落とした route が本当に throttle 不要かを 1 本ずつ再検討してください。'
        .PHP_EOL.implode(PHP_EOL, $violations));
});
```

> **注意**: 本テストは enum 全 case を走査するため、
> **新しい case を足したら上限も同時に決めさせる** (deny-by-default)。
> 逆に cap 側だけ残った stale entry も検出する。

### PHPStan 適合チェック

- [x] 戻り値の型が明示 (`array<string, int>` / `void`)
- [x] `$inventory[$label][0]` は `array{ThrottleCoverageExemption, string}` の 0 番目で型が確定
- [x] `$caps[$case] ?? null` の null 分岐を明示 (widen しない)
- [x] DTO 返却 (テストのため対象外)

### リスク

- 新規 3 テストは既存 5 テストと同一ファイルに増える (合計 8 本)。
  ファイルが長くなるが、判定関数を共有しており分割すると母集団定義が二重化するため
  同一ファイルに置く。

---

## 施策 7: limiter キー規約テストへの登録

### 変更箇所

- `tests/Architecture/RateLimiterKeyConventionTest.php` (`rateLimiterKeyInventory()`)

### 波及変更

- **必須**。登録しないと「scan で検出した limiter 名の集合が inventory と完全一致する」が fail する。

### 変更後コード (追加分)

```php
        'social-callback' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['social-callback:ip'],
            'emailScenarios' => [],
        ],
        'invitation-accept' => [
            'scenarios' => ['guest' => $noEmail],
            'expectedKeyPrefixes' => ['invitation-accept:ip'],
            'emailScenarios' => [],
        ],
        // 認証済み / 未認証の 2 分岐 (passkeys と同じ形)。
        // throttle は auth middleware より先に走るため guest 分岐も実在する。
        'two-factor-secret-read' => [
            'scenarios' => [
                'authenticated' => static fn (): Request => rateLimiterAuthenticatedRequest(rateLimiterProbeUser()),
                'guest' => $noEmail,
            ],
            'expectedKeyPrefixes' => ['two-factor-secret-read:user', 'two-factor-secret-read:ip'],
            'emailScenarios' => [],
        ],
```

- `social-callback` / `invitation-accept` は `webhook-ses` / `oauth-register` と同じ形
  (guest シナリオのみ、IP レーン 1 本)
- `two-factor-secret-read` は `passkeys` と同じ形 (authenticated / guest の 2 分岐)

**限定登録の網**: `RateLimiterRegistrationScanner` は `app/` 配下の `RateLimiter::for()` を
走査して inventory と**完全一致**を要求する。今回 limiter が 3 本増えるので、
3 本とも登録しないとこのテストが fail する (登録漏れは検出される)。

### PHPStan 適合チェック

- [x] 既存 inventory と同一の shape (`array{scenarios: ..., expectedKeyPrefixes: list<string>, emailScenarios: list<string>}`)

---

## 施策 8: 新 throttle 5 本の behavioral proof

## 施策 8: 新 throttle 5 本の behavioral proof

### 変更箇所

- `tests/Feature/Security/AuthThrottleCoverageTest.php` (末尾に追加)

### テスト計画

| # | テスト名 (日本語) | 検証内容 |
|---|------------------|---------|
| 8-1 | `GET /auth/{provider}/callback は 10 回目まで通り 11 回目で 429 (IP レーン 10/min)` | `social-callback` の閾値と数える単位。**無効リクエスト (intent 不在) でも枠を消費する**ことを同時に示す (throttle は controller より前で数える) |
| 8-2 | `social.callback は provider を変えても同じ bucket を消費する (存在オラクル不成立)` | limiter キーに route parameter が混ざっていないこと。`X-RateLimit-Remaining` が連続して減ることで示す (`NamedRateLimiterKeyTest` と同じ方式) |
| 8-3 | `social.callback の throttle は Socialite を一切呼ばずに枠を消費する (外向き HTTP の増幅が有界)` | intent 不在で controller が短絡することを **Socialite の spy で直接 assert** する (`Socialite::shouldReceive('driver')->never()` 相当)。「throttle が外向き HTTP より前にある」ことの本体 |
| 8-4 | `GET /invitations/accept は 10 回目まで通り 11 回目で 429 (無効 token でも枠を消費する)` | `invitation-accept` の閾値。無効 token でも消費することを明示 |
| 8-5 | `invitations.accept は token を変えても同じ bucket を消費する (token 総当りが有界)` | query token がキーに混ざっていないこと。**混ざっていたら総当りが有界にならない** |
| 8-6 | `2FA 秘密 GET のレーンは独立している — 10 回踏んでも recent-auth / 2FA 管理 POST が 429 にならない` | **本設計で最も重要な回帰テスト**。`two-factor.qr-code` を 10 回叩いて 429 にした直後に `POST /recent-auth/password` と `POST /user/confirmed-two-factor-authentication` が 429 にならないこと (= named limiter でレーンが分かれている証明。inline に戻したらここで落ちる) |
| 8-7 | `2FA 秘密 GET は 11 回目で 429 — これは回数上限であって認証強度ではない (認証強度は後続 TODO B2)` | `two-factor.qr-code` の 429 発生。**テスト名に誤読防止の一文を入れる** |
| 8-8 | 既存テスト `2FA 管理 route は throttle が recent-auth より先に走る` に `two-factor.recovery-codes` (GET) を追加 | 実効順の固定 (GET 側も同じ順序であること) |

### 実装上の注意

- 8-1 / 8-4 / 8-7 は**バケットを実際に使い切る**方式 (11 回叩く)。既存の
  「`POST /forgot-password` は 5 回目まで通り 6 回目で 429」と同じ書き方に揃える。
- 8-2 / 8-5 は `X-RateLimit-Remaining` の連続減少で示す (11 回叩かない)。
- 8-1 / 8-3 で `social.callback` を叩くとき、**session に `social_auth_intent` を置かない**。
  intent 無しなら `SocialAuthController::callback()` が Socialite に触れる前に 302 する。
- **`Http::preventStrayRequests()` に頼りきらない**: Socialite は Guzzle を直接使うため
  Laravel HTTP client の fake では捕まらない。8-3 では
  `Socialite` ファサードの spy で `driver()` が呼ばれないことを直接 assert する
  (`Http::preventStrayRequests()` は追加の網として併用してよいが、単独の根拠にしない)。
- 8-4 / 8-5 は存在しない token で叩く (`Invitations/Invalid` が返る)。**DB を汚さない**。
- 8-6 / 8-7 は認証済み user を `actingAs` する。2FA feature が有効であることが前提
  (`Features::twoFactorAuthentication()`)。
  8-6 の `recent-auth.password` は**認証情報が誤っていてもよい** (429 でないことだけ見る。
  422/302 のいずれでも「throttle で止まっていない」ことは示せる)。

### リスク

- 11 回リクエストするテストが 3 本増える。既存の 6 回方式と同オーダーなので許容。
- 8-6 は「レーン分離」を守る恒久回帰であり、**削らないこと**
  (削ると inline へ戻す変更が無音で通り、再認証が壊れる)。

---

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

### リスク

- 9-1 / 9-2 の対象は 3 route (13 本すべてではない)。
  **13 本すべてに広げない理由**: `filament.admin.auth.*` は panel 権限を持つ user の用意が要り、
  `password.reset/{token}` / `two-factor.login` は分岐条件を満たさないと
  「描画されなかっただけ」の**空振り green** になる。空振りする 13 本の網より、
  実効する 3 本の網 + `auth_view_render_only` の exact-fit cap (13) の方が
  deny-by-default として強い (14 本目が必ず再レビューを強制する)。

---

## 施策 10: ドキュメント更新

## 施策 10: ドキュメント更新

### 変更箇所

- `docs/app-integration-guide.md` §7b (L298-349 のブロック)

### 追記内容 (要点)

1. **保護対象群セレクタの非対称**:
   S1 (未認証の変更系) は変更系のみ / S3 (認証面) は**メソッドを問わない**。
   理由 = 認証面は GET でも秘密の開示・外部呼び出し・状態生成を伴いうるが、
   S1 まで GET へ広げると母集団が数百本になり gate が機能しなくなる。
2. **認証面 GET の分類方針**:
   「1 リクエストで外向き通信・重い計算・状態生成が起きるか」で貼る / 免除を決める。
   描画にすぎないものは `AuthViewRenderOnly`、フロー開始で外向き通信をしないものは
   `AuthFlowInitiationWithoutOutboundCall` (完了経路が throttle 済みであることが条件)。
3. **exemption cap は exact fit** で運用する (余裕枠を作らない)。case 別上限も併設。
4. **429 発生率の監視対象に `social-callback` / `invitation-accept` を追加**。
   併せて **invalid callback 比率** (intent 不在で `login` へ差し戻された割合) も監視項目に入れる。
   `social.redirect` は throttle しないため、同一 IP から callback 枠を意図的に枯らす
   一時 DoS は残る (許容リスクとして明記する)。巻き添え時の初動は閾値変更ではなく
   `TRUSTED_PROXIES` / 実 client IP 解決の確認。
5. **inline throttle は route ごとの bucket ではない (既存記述の補正)**:
   §7b の「フレームワーク既定のキー(認証済み = user id)がちょうど求める数える単位になる」に、
   **そのキーには route も limiter 名も入らないため、同一ユーザーの全 inline throttle route が
   1 つの bucket を共有する** (`ThrottleRequests::resolveRequestSignature()` /
   `handle()` の `$prefix` 既定 `''`) ことを追記する。
   したがって inline を使ってよいのは「その actor の**全 inline 操作を合算して数えてよい**」
   場合に限る。ページ描画のたびに飛ぶ GET のような高頻度レーンは、
   合算すると他の操作 (再認証等) を巻き添えにするため **named limiter でレーンを分ける**。
6. **監視・運用の追記先**: 上記 4 は §7b の「未認証 webhook の注意」に続く運用段落へ。

### 波及変更

- `AGENTS.md` §流量制限 (throttle) の付与規約: **変更しない**
  (規約そのものは変わらず、適用範囲の明確化のみ。詳細は §7b が正本という既存の構造を維持)。
- `docs/architecture.md`: 変更なし (新モデル・新リソースは無い)

---

## 検証コマンドと期待結果

| # | コマンド | 期待結果 |
|---|---------|---------|
| 1 | `composer test -- --filter=ThrottleCoverageInventoryTest` | 施策 1 直後は **19 本列挙で fail** / 施策 6 完了後は green |
| 2 | `composer test -- --filter=RateLimiterKeyConventionTest` | green (limiter 名集合が一致、キーが `social-callback:ip:` / `invitation-accept:ip:`) |
| 3 | `composer test -- --filter=ThrottleExemptionPremiseTest` | green |
| 4 | `composer test -- --filter=AuthThrottleCoverageTest` | green |
| 5 | `composer test -- --filter=NamedRateLimiterKeyTest` | green (既存回帰) |
| 6 | `composer test -- --filter=RouteThrottleBinderTest` | green (既存回帰) |
| 7 | `composer test -- --filter=RecentAuthRouteTest` | green (2FA route への middleware 後付けの回帰) |
| 8 | `php artisan route:cache && php artisan route:list && php artisan route:clear` | 例外なく往復。`route:list` で `social.callback` / `invitations.accept` / `two-factor.qr-code` 等に throttle が出る |
| 9 | `composer phpstan` | level 10 green (ignore / baseline 追加なし) |
| 10 | `vendor/bin/pint --test` | green |
| 11 | `composer test` | 全 green (最後に 1 回。グローバルロック配下で待たされるのは正常) |

**フロントは無変更**のため `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` は
差分が無いことの確認として最後に 1 回で足りる。

### 母集団件数の実測手順 (実装者向け)

```bash
# devnotes 配下の一時スクリプトで再現する (恒久 scripts/ には昇格させない)
APP_ENV=testing php devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php
```

期待: `現行母集団: 47` → `拡張後母集団: 70` (`throttleCoverageRouteFloor()` = 60 の根拠)。

---

## 段階分け

### このタスクでやる

- 施策 1〜10 のすべて (S3 拡張 / throttle 5 本 / exemption 14 件 / 検査 3 本 / 前提 4 本 / docs)

### 後続 TODO 候補 (このタスクではやらない)

| 候補 | 理由 |
|------|------|
| **秘密を返す GET の recent-auth 化** (`two-factor.qr-code` / `.secret-key` / `.recovery-codes`) | `aicue:T120` の後続 TODO **B2** として既に切り出し済み。本タスクは throttle の付与漏れ検査であり認証強度の話ではない。混ぜると「throttle を貼った = 秘密の保護が済んだ」という誤った完了感を生む |
| **429 応答の経路別契約** (Inertia / XHR / API での見せ方) | 別 feature `error-response-contract` の担当 |
| **`social-callback` / `invitation-accept` の 429 発生率メトリクス実装** | 本タスクでは docs の監視項目に載せるところまで。メトリクス基盤は別件 |
| **`AuthViewRenderOnly` 13 本すべてへの premise テスト拡張** | 施策 9 のリスク欄参照。空振り green になる route があるため、必要になったら Filament panel テスト基盤とセットで |
| **S1 (未認証の変更系) の GET 拡張** | 母集団が数百本になり gate が機能しなくなる (概念設計 §5 案 3) |
| **既存 inline throttle 群の bucket 共有の見直し** (本タスクで発見) | 現在 `password.confirm.store` / `user-password.update` / `two-factor.enable` 等 / `recent-auth.password` / `settings.password.store` / `invitations.accept.store` が **同一 actor の 1 bucket を共有**している (施策 4 の根拠節)。max が最小の `6,1` 側 (`recent-auth.password` = 再認証) が最初に 429 になるため、無関係な操作の連打で再認証が止まりうる。本タスクは「新たに悪化させない」ところまでで、既存レーンの分離は**閾値と数える単位の再設計**になるため別タスク。起票時は「どの操作を合算して数えるのが正しいか」から議論すること |

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存機構 (`RouteThrottleBinder` / exemption inventory / limiter キー規約テスト) の**上に載せる**変更であり、新機構を作らない。施策 1 の fail 観測 → 分類 → green という段階が明確で、途中状態でも意味が通る |
| 競合リスク | `tests/Architecture/ThrottleCoverageInventoryTest.php` を施策 1 と 6 の両方が触る (同一ブランチ内の順次変更なので競合なし)。`routes/web.php` は他の実装中タスクと衝突しうるため、**worktree 作成時に main の最新を取り込む** |

---

## 実装時に確認すること

1. **`recent-auth.confirm` の `show()` が DB 書込をしないこと**。
   satisfier 一覧の組み立てで `socialAccounts` を read する想定 (read は許容)。
   書込があれば exemption 理由の文言を実態に合わせる (理由は実装に合わせる。
   実装を理由に合わせない)。
2. 施策 1 の fail 出力が **19 本ちょうど**であること。
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

残 Critical / Warning があれば指摘してください。無ければ全体判定 APPROVED を出してください。
