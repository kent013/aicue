# 対応マトリクス: design-review Round 1

## [Critical] 施策 4: `Route::bind('passkey')` の boot 順序が保証されていない

- 判断: **対応する**
- 根拠: 正しい。`bootstrap/providers.php` の順序は app provider 間の順序であり、
  auto-discovery された package provider (`PasskeysServiceProvider`) との最終 boot 順序を
  設計根拠にするのは危うい。
- 対応内容: binder 差し替えも `$this->app->booted(...)` の中で実行する
  (route middleware 後付けと同じ「全 provider boot 後に最終上書き」の形へ統一)。
  `bootstrap/providers.php` の配置は残すが、**正しさの根拠を `booted()` に移す**。
  Feature テスト「他人の passkey DELETE が 404」に加え、
  router の binder を直接叩く小テスト (`Route::getBindingCallback('passkey')` 経由) を追加。

## [Critical] 施策 4/6: Response contract とフロント transport の契約が未確定

- 判断: **対応する (最重要)**
- 根拠: 正しい。`back()` を返す Response と fetch wrapper が噛み合っていない。
  成功判定・Inertia props 更新・409/422 の扱いが崩れる。
- 対応内容: **operation 単位の transport 契約表**を新設し、Response 実装をそれに合わせる。
  既存アプリの作法に合わせて決定する:

  | operation | options 取得 | 送信 | 応答 |
  |-----------|-------------|------|------|
  | 登録 | `fetch GET /user/passkeys/options` (JSON) | **Inertia `router.post`** | `back()->with('success')` |
  | 削除 | — | **Inertia `router.delete`** | `back()->with('success')` |
  | step-up confirm | `fetch GET /passkeys/confirm/options` | **`fetch POST`** | **204 + no-store** (`recent-auth.password` と同契約) |
  | ログイン (guest) | `fetch GET /passkeys/login/options` | **`fetch POST`** | JSON `{redirect}` (DTO+Resource) |

  根拠: 登録/削除は passkey 一覧 (Inertia prop) を更新する必要があり、
  既存 `Settings/Security.svelte` の 2FA が `router.post` / `router.delete` +
  `back()` flash で統一されている。confirm は既存 `RecentAuthModal` が
  `fetch` + 204 契約なので合わせる。options 取得は既存
  `/user/two-factor-qr-code` の fetch パターンと同一。

- **あわせて `EnsureLoginMethodRemains::reject()` の応答も修正する**:
  Inertia mutation に 422 JSON を返すと Inertia protocol 違反になる。
  `expectsJson()` (非 Inertia XHR) のみ 422 JSON、
  それ以外 (Inertia 含む) は `back()->withErrors(['login_method' => ...])` にする。
  Inertia は 302 + errors を native に処理し、Svelte 側は `$page.props.errors` で読める。
  禁止事項 7 (操作系 POST は `back()->with(...)` で完結) とも整合する。
  判別子として `expectsJson()` が使えるのは、Inertia が
  `Accept: text/html, application/xhtml+xml` を送るため。

## [Critical] 施策 3: middleware 内 transaction の適用範囲が広がるリスク

- 判断: **対応する**
- 根拠: 正しい。`$next()` を transaction に入れると controller / 同期 listener /
  Responsable 変換 / flash まで含まれる。現状 `passkey.destroy` 1 本なら成立するが、
  将来この middleware が別 route に付くと副作用範囲が急拡大する。
- 対応内容:
  1. `LoginMethodRemovalRouteTest` に
     「**`ensure-login-method` を付けてよいのは guarded allowlist の route のみ**」を追加
     (未知 route への付与を deny-by-default で fail させる。現状は付与の**有無**しか見ていない)
  2. middleware の docblock に適用条件を明記:
     「streamed response / 外部 I/O (HTTP・S3) / `afterCommit` でない queue dispatch を
     含む route には付けない。付ける場合は本 middleware の transaction 方式を再設計すること」

## [Warning] 施策 3: middleware の実行順 (`recent-auth` → `ensure-login-method`)

- 判断: **対応する**
- 根拠: 正しい。順序が逆だと stale recent-auth のリクエストでも User 行ロックを取りに行く。
- 対応内容: `PasskeyRouteProtectionTest` で `passkey.destroy` の
  `gatherMiddleware()` 上の**インデックス比較**により
  `recent-auth` が `ensure-login-method` より前であることを pin する。

## [Warning] 施策 3: `RefreshDatabase` 下では `transactionLevel()` が 1 以上から始まる

- 判断: **対応する**
- 根拠: 正しい。`level === 1` を期待すると壊れる。
- 対応内容: 「middleware 突入前の level を基準に **+1 されていること**」および
  「`for update` の select と `delete` が**同一 level** で観測されること」を見る設計に変更。

## [Warning] 施策 4: config cache 下の保証が `config()->all()` だけでは不十分

- 判断: **対応する (より忠実な検査に置き換える)**
- 根拠: 正しい。ただし Pest から `config:cache` を実行するのは
  `bootstrap/cache/config.php` を書き換え `--parallel` 実行を壊すため採れない。
- 対応内容: `config:cache` の**実装そのもの**を再現する検査に変える。
  `ConfigCacheCommand` は `'<?php return '.var_export($config, true).';'` を書き出すため、
  `var_export(config()->all(), true)` を `eval` で往復させ、
  往復後も `fortify-options.passkeys.confirmPassword === false` が残ることを検査する。
  これは「serialize 可能であること」と「キーが `all()` に含まれること」の両方を
  1 つのアサーションで忠実に証明する。

## [Warning] 施策 4/6: `User::passkeys()` / `Passkey::$authenticator` の PHPStan 型

- 判断: **一部対応する**
- `passkeys()`: **対応する**。`User` 側で明示 override し
  `@return HasMany<\App\Models\Passkey, $this>` を付ける
  (trait 由来だと vendor base model 型で見えるため、DTO 生成 closure が level 10 で落ちる)
- `$authenticator`: **実測により対応不要と判断**。
  vendor の `Laravel\Passkeys\Passkey` は class docblock に
  `@property-read string|null $authenticator` を持ち、`App\Models\Passkey` は
  これを継承する。PHPStan は `string|null` として解決できる。
  ただし DTO 側の型を `?string` にして narrowing 不要にすることを明記する。

## [Warning] 施策 5: policy deny 経路でも `PasskeyVerified` が発火し guest session に鮮度が残る

- 判断: **対応する (重要な見落とし)**
- 根拠: 正しい。`VerifyPasskey` は `allowsLogin()` 判定の**前**に `PasskeyVerified` を dispatch する。
  TOTP 有効ユーザーの passkey login が deny されても、その前に
  `StampRecentAuthOnPasskeyVerified` が **guest session** に `recent_auth_at` を打ってしまう。
- 対応内容: listener に**本人性バインド**を入れる
  (`SocialAuthController::completeStepUp` と同じ作法)。
  「検証された passkey が**現在ログイン中ユーザー**のものである場合のみ stamp する」。
  guest (login 経路) では認証ユーザーが居ないため stamp されず、deny 時の残留も消える。
  login 成功時の鮮度は `StampRecentAuthOnLogin` が担うため機能欠落も起きない。
  Feature テストで「deny 時に guest session へ鮮度が残らない」を固定する。

## [Warning] 施策 5: satisfier inventory の静的走査が文字列一致で false negative

- 判断: **対応する**
- 根拠: 正しい。alias import / container 解決 / 変数名経由 / メソッド転送を取り逃がす。
- 対応内容: `token_get_all()` ベースの走査に変更する
  (namespace / use / class 名を token から解決し、`RecentAuthState` 型の変数・
  `app(RecentAuthState::class)` の両方を拾う)。
  それでも動的呼び出しは完全には拾えないため、**限界をテスト docblock に明記**し、
  「新しい satisfier を足すときに必ず考えさせる」という目的に役割を限定する。

## [Warning] 施策 5: `ClearRecentAuthOnPasskeyChange` が HTTP session 前提

- 判断: **対応する**
- 対応内容: `session()` 操作の前に session の利用可否を確認する
  (`app()->bound('session') && session()->isStarted()`)。
  CLI / queue / admin cleanup からの発火で例外にならないようにする。
  既存 `UpdateUserPassword::deleteOtherSessionRecords()` が
  `session()->isStarted()` で同じガードをしており作法が揃う。

## [Warning] 施策 2: `PasswordUpdated` イベント名の未確定

- 判断: **対応する (実測して確定させた)**
- 根拠: 実測の結果、Fortify 1.37 の Events は
  `PasswordUpdatedViaController` / `RecoveryCode*` / `TwoFactor*` のみ。
  `PasswordUpdatedViaController` は Fortify の Controller 経由に限定された意味であり、
  アプリが所有する `App\Actions\Fortify\UpdateUserPassword` から記録するほうが
  vendor のイベント意味論に依存せず確実。
- 対応内容: **Action 直記録に確定**する
  (`OrganizationMemberController::resetTwoFactor` と同じ「Action / Controller から直接記録」の作法)。
  `ResetUserPassword` 経路は既存の `Illuminate\Auth\Events\PasswordReset` 購読で
  カバー済みのため触らない。

## [Warning] 施策 2: 将来の password/social 除去 route も同じ lock 規約が必須

- 判断: **対応する (明記を強化)**
- 対応内容: `LoginMethodInventory` の docblock と施策 3 の直列化規約に
  「除去経路は例外なく `EnsureLoginMethodRemains` を通す = 単一の直列化点」を明記し、
  `LoginMethodRemovalRouteTest` の allowlist 検査 (上記 Critical 対応) がそれを強制する。

## [Warning] 施策 6: Inertia prop の Resource collection が不安定

- 判断: **対応する**
- 根拠: 正しい。`Resource::collection($collection->map(dto))` は
  PHPStan と Inertia resolve の両面で不安定。
- 対応内容: `App\Http\Controllers\Settings\SecurityController` を新設して
  route closure から抽出し (元々「Controller は薄く」の観点で推奨していた)、
  DTO collection を `->resolve($request)` した plain array として Inertia に渡す。

## [Suggestion] 施策 1: `EmailTrustPolicyResolver` の container 解決

- 判断: **見送る**
- 根拠: レビュー自身が「現時点では YAGNI の範囲」と評価。
  AGENTS.md 思考原則 2 (今必要なものだけ作る)。
