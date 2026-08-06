# 詳細設計: 未認証 GET の認証面を流量制限の母集団へ (throttle-unauthenticated-get)

概念設計: [`conceptual-design.md`](./conceptual-design.md)

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**
  (撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) /
> 動画合成は自前 ffmpeg / 単一 Default Project。

**本タスクの位置づけ**: 使命に直接寄与する機能ではなく、使命を支える
**セキュリティ不変条件「流量制限 (throttle) の付与規約」の gate 是正**である。
SSO ログインと組織招待は現場導入の主動線であり、そこが未認証で無制限に踏める状態は
可用性の問題として直接ユーザーに跳ね返る。

### 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI
9. Artifact の使用

**本タスクで特に効く禁止事項**: 1 (テストなしの完了報告)。
不変条件の変更なので、**Architecture / Feature テストへの登録まで含めて「実装済み」**である。

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)。型の widen / baseline 禁止
- **Pest**。`RefreshDatabase` は `tests/Pest.php` でグローバル適用済 (個別 `DatabaseTransactions` 禁止)
- テストデータは Factory 生成 (`Model::create()` 手組み禁止)
- `declare(strict_types=1)` + 日本語コメント
- `composer fix` (Pint) / `vendor/bin/pint --test`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

### 本タスク固有の規約 (AGENTS.md §流量制限の付与規約)

- named limiter のキーは **`{レーン}:{種別}:{値}`**
- **inline throttle (`throttle:6,1`) は「認証済みかつ actor 自身に閉じる操作」限定**。
  未認証面は必ず named limiter
- **閾値は既存値を変えない**。新しい面には本番稼働中の同性質エンドポイントと同値を充てる
- `RateLimiter::for()` の第 1 引数は**必ずリテラル**でベタ書き (ループ登録しない。
  `RateLimiterRegistrationScanner` が非リテラルを deny-by-default で fail させる)
- vendor 登録 route への後付けは `RouteThrottleBinder::attachOnBooted()` 経由
- **limiter キーに route parameter を入れない** (`NamedRateLimiterKeyTest`)

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | S3 セレクタから `$isMutating` を外す + floor 更新 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | 必須 (最初に単独で当てて fail を見る) |
| 2 | named limiter 2 本の新設 | `app/Providers/AppServiceProvider.php` | 必須 |
| 3 | 自前 route 2 本への throttle 付与 | `routes/web.php` | 必須 |
| 4 | 2FA 秘密 GET 3 本への throttle 後付け (named limiter 1 本を新設) | `app/Providers/FortifyServiceProvider.php` | 必須 |
| 5 | exemption enum に case 2 つ追加 | `app/Enums/Security/ThrottleCoverageExemption.php` | 必須 |
| 6 | exemption inventory 14 件追加 + cap 更新 + 検査 3 本追加 | `tests/Architecture/ThrottleCoverageInventoryTest.php` | 必須 |
| 7 | limiter キー規約テストへの登録 | `tests/Architecture/RateLimiterKeyConventionTest.php` | 必須 |
| 8 | 新 throttle 5 本の behavioral proof | `tests/Feature/Security/AuthThrottleCoverageTest.php` | 必須 |
| 9 | 新 exemption case の前提 proof | `tests/Feature/Security/ThrottleExemptionPremiseTest.php` | 必須 |
| 10 | ドキュメント更新 | `docs/app-integration-guide.md` | 必須 |

**実装順序 (テストファースト)**: 1 → (fail を確認) → 5 → 2 → 3 → 4 → 6 → 7 → 8 → 9 → 10

---

## 施策 1: S3 セレクタから `$isMutating` を外す + floor 更新

### 変更箇所

- `tests/Architecture/ThrottleCoverageInventoryTest.php` (L45-49, L193-194)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: 本ファイル自身。施策 6 と同一ファイルだが**別コミットに分ける**
  (fail を観測するため)

### 現行コード

```php
/** 母集団件数の下限 (空振り drift ガード。実測 47 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 40;
}
```

```php
        // S3: 認証済み側も含む credential 面
        $s3 = $isMutating && $name !== '' && preg_match($pattern, $name) === 1;
```

### 変更後コード

```php
/** 母集団件数の下限 (空振り drift ガード。実測 70 に対し余裕を持たせた値)。 */
function throttleCoverageRouteFloor(): int
{
    return 60;
}
```

```php
        // S3: credential 面 (認証済み側も含む)。
        // ★**メソッドを問わない** (GET/HEAD も母集団に入れる)。
        //   認証面は「読むだけ」の GET でも秘密の開示・外部呼び出し・状態生成を伴いうる。
        //   $isMutating を条件に残していた頃は認証面 GET が 1 本も母集団に入らず、
        //   パターン中の `social\.` は 1 件も一致しない**死んだ条件**だった
        //   (social route は 2 本とも GET)。
        // ★S1 (未認証の変更系) は $isMutating のまま残す。S1 まで GET へ広げると
        //   母集団が数百本になり、exemption 台帳に埋もれて gate が機能しなくなる。
        $s3 = $name !== '' && preg_match($pattern, $name) === 1;
```

### この時点で観測すべき fail (テストファーストの証拠)

```
composer test -- --filter=ThrottleCoverageInventoryTest
```

→ 「保護対象 route の throttle 付与が不正です」に **19 本**が列挙されて fail する。
(増分 23 本 - 既に throttle 済み 4 本 = `verification.verify` / `passkey.login-options` /
`passkey.confirm-options` / `passkey.registration-options`)

**この fail 出力をコミットメッセージか devnotes に貼ってから分類作業に入ること。**

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている (`int` / `bool`)
- [x] null 安全 (変更なし)
- [x] DTO 返却 (対象外)
- [x] Generics (対象外)

### リスク

- floor を 60 にすると、`Features::twoFactorAuthentication()` / `Features::passkeys()` を
  同時に無効化した場合に fail しうる。**それは意図した挙動** (母集団が縮んだら気づきたい)。

---

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

### 変更箇所

- `app/Enums/Security/ThrottleCoverageExemption.php` (末尾に追加)

### 波及変更

- テストファイル: `tests/Architecture/ThrottleCoverageInventoryTest.php` (施策 6) /
  `tests/Feature/Security/ThrottleExemptionPremiseTest.php` (施策 9)

### 変更後コード (追加分のみ)

```php
    /**
     * 認証面の非変更系 (GET/HEAD) で、応答が画面 / ステータスの描画にすぎない route。
     *
     * 適用条件 (すべて満たすこと):
     *  - HTTP メソッドが GET/HEAD のみ (変更系には適用しない)
     *  - 外部呼び出し・メール送信・重い計算・**DB 書込**を伴わない (DB read は可)
     *  - 推測可能な秘密を開示しない
     *    (自セッションが既に保持する情報の再表示・自分が提示した token の prefill は可)
     *  - 副作用が自セッション (CSRF token / flash / 汚染値の除去) の中に閉じる
     *
     * ★credential の検証・生成が同 URI の変更系側にある場合は、その変更系が
     *   throttle か exemption のどちらかで分類済みであることまで確認して使う。
     */
    case AuthViewRenderOnly = 'auth_view_render_only';

    /**
     * 認証フローを開始するが、その場では外向き通信を一切行わない非変更系 route。
     *
     * 適用条件 (すべて満たすこと):
     *  - HTTP メソッドが GET/HEAD のみ
     *  - **その場で外向き HTTP を発行しない** (発行するのは対になる完了経路)
     *  - 生成する状態が自セッション内に閉じ、他セッションから消費できない
     *  - **対になる完了経路が throttle 済みである** (増幅はそちらで有界化されている)
     *
     * ★4 番目の条件が本 case の要である。完了経路の throttle を外すと
     *   この exemption の前提が崩れるため、ThrottleExemptionPremiseTest が
     *   完了経路の throttle 実在と limiter 名を behavioral に固定する。
     */
    case AuthFlowInitiationWithoutOutboundCall = 'auth_flow_initiation_without_outbound_call';
```

### PHPStan 適合チェック

- [x] backed enum の値は `string` 固定

---

## 施策 6: exemption inventory 14 件追加 + cap 更新 + 検査 3 本追加

### 変更箇所

- `tests/Architecture/ThrottleCoverageInventoryTest.php`
  (L51-55 cap / L63-130 inventory / 末尾へテスト 3 本追加 + 新関数 1 本)

### 6-a. cap の更新

```php
/** exemption 件数の上限 (形骸化ガード)。**現在値ちょうど** (exact fit)。 */
function throttleCoverageExemptionCap(): int
{
    // ★余裕を 1 でも持たせると、その 1 本は「個別の根拠も再レビューも無しに
    //   免除できる枠」になる。exact fit なら次の 1 本が必ず「この数値を変える差分」
    //   として現れ、個別理由・前提テスト追加要否・そもそも貼るべきでないかの
    //   再検討を強制できる。上げる前に必ず再検討すること。
    return 25;
}

/**
 * exemption の case 別上限 (分類の偏り検出)。全体 cap とは役割が違う
 * (全体 = セレクタの広さ / case 別 = どのカテゴリが膨らんだか)。
 * ★array_sum() で全体 cap を導出しない (両方を独立に検査する)。
 *
 * @return array<string, int> ThrottleCoverageExemption::value => 上限
 */
function throttleCoverageExemptionCapByCase(): array
{
    return [
        ThrottleCoverageExemption::StaticMetadataResponse->value => 4,
        ThrottleCoverageExemption::VendorMethodNotAllowedStub->value => 2,
        ThrottleCoverageExemption::SessionTeardownOnly->value => 2,
        ThrottleCoverageExemption::LocalOnlyDebugRoute->value => 1,
        ThrottleCoverageExemption::ComponentLevelLimiter->value => 1,
        ThrottleCoverageExemption::SignatureRequiredBeforeEffect->value => 1,
        // ★ここが膨らむ = 「貼るべき route を描画系として逃がした」疑い。
        ThrottleCoverageExemption::AuthViewRenderOnly->value => 13,
        ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall->value => 1,
    ];
}
```

### 6-b. inventory への 14 件追加

`throttleCoverageExemptions()` の先頭 alias 群に 2 行追加:

```php
    $render = ThrottleCoverageExemption::AuthViewRenderOnly;
    $flowInit = ThrottleCoverageExemption::AuthFlowInitiationWithoutOutboundCall;
```

既存 11 件の**後ろ**に、以下 14 件を追加する (**既存 11 件は 1 文字も触らない**)。
理由はすべて 30 文字以上 (`throttleCoverageReasonMinLength()`)。

```php
        // ─────────────────────────────────────────────────────────────
        // 認証面の非変更系 GET (T120 事後監査の是正で母集団に加わった 23 本のうち、
        // throttle を貼らないことが正しいと裁定した 14 本)。
        // 判断基準は「1 リクエストで外向き通信・重い計算・状態生成が起きるか」。
        // ─────────────────────────────────────────────────────────────

        'login' => [$render,
            'Fortify::loginView() が config(template.social_providers) のキー一覧だけを props にした '
            .'Inertia ページ (Auth/Login) を描画する。credential 検証は POST /login '
            .'(throttle:login) 側にあり、GET は DB 書込・外部呼び出し・メール送信を伴わない。'],

        'register' => [$render,
            'Fortify::registerView() の Inertia 描画。session に**自分で置いた** invitation_token が '
            .'ある場合のみ OrganizationMembershipService::resolveRegisterPrefillEmail() が招待を '
            .'1 件 read するが、token を持たない要求は DB へ到達しない。DB 書込・外部呼び出しは無い。'],

        'password.request' => [$render,
            'Fortify::requestPasswordResetLinkView() が props 無しの Inertia ページ '
            .'(Auth/ForgotPassword) を描画するだけ。メール送信は POST /forgot-password '
            .'(throttle:password-reset-request) 側で、GET は DB にも外部にも触れない。'],

        'password.reset' => [$render,
            'Fortify::resetPasswordView() が route parameter の token と query の email を props へ '
            .'写すだけの Inertia 描画。token の DB 照合は POST /reset-password '
            .'(throttle:password-reset-submit) 側で行われ、GET は token の有効性を判定しない '
            .'(応答が token に依存しないためオラクルにならない)。'],

        'two-factor.login' => [$render,
            'Fortify の TwoFactorAuthenticatedSessionController::create() が session の login.id に '
            .'対応する user の存在を read し、無ければ login へ 302 する。コード検証は '
            .'POST /two-factor-challenge (throttle:two-factor) 側。DB 書込・外部呼び出しは無い。'],

        'password.confirm' => [$render,
            'FortifyServiceProvider::configureViews() が confirmPasswordView を '
            .'recent-auth.confirm への 302 に差し替えており、応答は redirect 1 本のみ。'
            .'DB アクセス・外部呼び出し・秘密の開示を一切伴わない。'],

        'password.confirmation' => [$render,
            'Fortify の ConfirmedPasswordStatusController::show() が session の '
            .'auth.password_confirmed_at と設定値を比較した bool を返すだけ。auth 必須で '
            .'actor 自身の session 状態しか見ず、DB にも外部にも触れない。'],

        'recent-auth.confirm' => [$render,
            'auth 必須。ConfirmRecentAuthController::show() が actor 自身の recent-auth 鮮度と '
            .'利用可能な satisfier を props にした Inertia 描画を返す。password 検証は '
            .'POST /recent-auth/password (throttle:6,1) 側にあり、GET は DB 書込を伴わない。'],

        'recent-auth.status' => [$render,
            'auth 必須の軽量プローブ。ConfirmRecentAuthController::status() が actor 自身の鮮度を '
            .'JsonResource で返し no-store を付けるだけで、DB 書込・外部呼び出し・'
            .'秘密の開示を伴わない (bfcache 再検証のため頻繁に叩かれる前提の endpoint)。'],

        'verification.notice' => [$render,
            'auth 必須。Fortify::verifyEmailView() が EmailVerificationContinuation::hasContinuation() '
            .'の bool だけを props にした Inertia 描画を返す。検証メールの再送は '
            .'POST /email/verification-notification (throttle:6,1) 側で有界化されている。'],

        'filament.admin.auth.login' => [$render,
            'Filament panel のログインページ描画。credential 検証は Livewire の POST '
            .'(default-livewire.update) 側にあり、そこは ComponentLevelLimiter として登録済みで '
            .'Auth/Pages/Login の rateLimit(5) が実在する (ThrottleExemptionPremiseTest が固定)。'],

        'filament.admin.auth.profile' => [$render,
            'auth 必須の Filament プロフィールページ描画。パスワード変更等の実処理は Livewire POST '
            .'(default-livewire.update) 側にあり ComponentLevelLimiter で分類済み。'
            .'GET は actor 自身のフォーム描画のみで、秘密の生成も外部呼び出しも伴わない。'],

        'filament.admin.auth.multi-factor-authentication.set-up-required' => [$render,
            'auth 必須の Filament MFA 設定要求ページ描画 (SetUpRequiredMultiFactorAuthentication)。'
            .'TOTP 秘密とリカバリコードの生成は SetUpAppAuthenticationAction の mountUsing '
            .'(= Livewire POST / default-livewire.update) で起き、GET の描画では起きない '
            .'(ComponentLevelLimiter で分類済みの経路)。GET は導線リンクの描画のみ。'],

        'social.redirect' => [$flowInit,
            'SocialAuthController::redirect() は provider allowlist (config) と intent を検証し、'
            .'session へ intent と OAuth state を書いて IdP へ 302 するだけで、**その場では '
            .'外向き HTTP を発行しない**。外向き HTTP は対になる social.callback で起き、'
            .'そちらは throttle:social-callback で有界化されている (前提は Premise テストが固定)。'],
```

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
| 9-6 | `別セッションで発行した state では callback が外向き HTTP へ進まない (OAuth state が自セッションに閉じる)` | `AuthFlowInitiation…` | 適用条件 3 番目の **behavioral proof**。intent は満たした状態で state だけ他セッション由来にし、外向き HTTP が 0 件であることを確認する |
| 9-6b | (補助) `SocialAuthController は stateless() を使わない` | `AuthFlowInitiation…` | 9-6 を補強するソース走査。**単独の根拠にはしない** |
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

### 9-6 の実装メモ (state が自セッションに閉じることの behavioral proof)

**設計方針**: ソース走査 (`stateless(` の不在) だけでは
「表記ゆれ / helper 経由 / provider 生成側での stateless 化」を検出できず、
deny-by-default の根拠として不足する。**実挙動で証明する**。

**成立条件 (空振りにしないための要点)**: セッション B 側に
**正しい `social_auth_intent` を持たせる**こと。intent があれば controller は短絡せず、
`Socialite::driver()->user()` まで進む。そこで止まるのは
`AbstractProvider::hasInvalidState()` (session の `state` を `pull` して query の `state` と
`hash_equals`) **だけ**になるので、state 照合とセッション分離を分離して証明できる。

**Socialite の実 driver に mock HTTP client を差せる根拠**:
`SocialiteManager` は Laravel の `Manager` を継承し、解決した driver を `$this->drivers` に
**キャッシュ**する。テストから `Socialite::driver('google')->setHttpClient($mock)` しておけば、
その後 controller 内で解決される `Socialite::driver('google')` は**同一インスタンス**であり、
mock client がそのまま使われる。
→ 既存 `tests/Feature/Auth/SocialAuthTest.php` のようにファサードごと mock すると
**state 照合の実装まで消えてしまう**ので、本テストではファサードを mock しない。

```php
test('別セッションで発行した state では callback が外向き HTTP へ進まない (state が自セッションに閉じる)', function (): void {
    // AuthFlowInitiationWithoutOutboundCall の適用条件 3 番目の behavioral proof。
    // ★Socialite ファサードは mock しない (state 照合の実装ごと消えるため)。
    //   実 driver に mock HTTP client を差し、外向き要求が 1 件も出ないことを見る。
    $requests = [];
    $history = Middleware::history($requests);
    $stack = HandlerStack::create(new MockHandler([new GuzzleResponse(200, [], '{}')]));
    $stack->push($history);
    Socialite::driver('google')->setHttpClient(new Client(['handler' => $stack]));

    // --- セッション A: state を 1 つ発行して控える ---
    $a = $this->get('/auth/google/redirect/login');
    $stateA = stateFromRedirect($a);            // Location の query から state を取り出す helper

    // --- セッション B: 別セッションを作り、自分の state と intent を持たせる ---
    $this->flushSession();
    $this->get('/auth/google/redirect/login');  // B 自身の state / intent が session に入る

    // --- B のセッションで A の state を使って callback ---
    $response = $this->get('/auth/google/callback?code=dummy&state='.$stateA);

    // ★核心: 外向き HTTP が 1 件も出ていない (state 照合が token 交換より前で止めた)
    expect($requests)->toBe([], '別セッションの state で外向き HTTP が発生しました');

    // ログイン成立経路へ進んでいない (成功時は dashboard へ redirect + 認証済みになる)
    expect($response->headers->get('Location'))->not->toContain('/dashboard');
    expect(auth()->check())->toBeFalse();
});

test('SocialAuthController は stateless() を使わない (9-6 の補助)', function (): void {
    // ソース走査は補助。stateless() 化は state 照合を丸ごと無効化する最短経路なので、
    // 実挙動テスト (9-6) と二重に塞ぐ。
    $source = file_get_contents(app_path('Http/Controllers/Auth/SocialAuthController.php'));
    expect($source)->toBeString();
    expect($source)->not->toContain('stateless(');
});
```

必要な import: `use GuzzleHttp\Client;` / `use GuzzleHttp\Handler\MockHandler;` /
`use GuzzleHttp\HandlerStack;` / `use GuzzleHttp\Middleware;` /
`use GuzzleHttp\Psr7\Response as GuzzleResponse;` / `use Laravel\Socialite\Facades\Socialite;`

**実装上の注意**:
- `stateFromRedirect()` は `Location` ヘッダを `parse_url` → `parse_str` して `state` を返す
  小さな helper (同ファイル内に置く)。
- `social.callback` には throttle が付くため、この 1 テストで消費するのは 1 枠のみ (上限 10)。
- `Socialite::driver('google')` を先に解決しておくのが前提 (driver キャッシュに載せるため)。
  provider が `config('template.social_providers')` の allowlist に無いと
  `ensureProviderEnabled()` が 404 にするので、テスト環境で google が有効であることを確認する
  (既存 `SocialAuthTest` が `'google'` で通っているので有効)。

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
| 2 | `composer test -- --filter=RateLimiterKeyConventionTest` | green (limiter 名集合が一致し、キーが `social-callback:ip:` / `invitation-accept:ip:` / `two-factor-secret-read:user:` / `two-factor-secret-read:ip:`) |
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

- 施策 1〜10 のすべて
  (S3 拡張 / named limiter 3 本 / throttle 5 本 / exemption 14 件 (新 case 2) /
   inventory 検査 3 本追加 / 前提テスト 8 本 (9-1〜9-7 + 9-6b) / behavioral proof 8 本 / docs)

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
