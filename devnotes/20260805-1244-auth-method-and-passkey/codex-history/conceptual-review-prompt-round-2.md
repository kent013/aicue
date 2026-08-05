# 概念設計レビュー Round 2

Round 1 の指摘への対応マトリクスと、修正後の概念設計全文を送ります。
Critical 4 件はすべて設計に反映しました。Warning は 2 件で「一部対応 (根拠付きで反論)」としています。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] passkey login が Fortify の TOTP チャレンジを迂回する

- 判断: **対応する**
- 根拠: 指摘は正しい。vendor 実測で `PasskeyLoginController::store()` は
  `$guard->login($passkey->user, ...)` を直接呼び、Fortify の two-factor challenge
  (`login.id` を session に置いて `/two-factor-challenge` へ送る経路) を通らない。
  組織 2FA 強制は post-login の global middleware なので効くが、
  **ユーザーが自分で有効化した TOTP は迂回される**。
  2026-08-04 裁定 A の再検討条件が「パスキーが 2FA 準拠判定に算入される時」である以上、
  **現時点で passkey は 2FA 相当ではない**というのが台帳の立場であり、
  passkey login で TOTP を置き換えるのは assurance の後退にあたる。
  台帳未裁定の論点なので、fail-closed 既定を置いた上で c2c へ agenda として戻す。
- 対応内容: `Passkeys::authorizeLoginUsing()` で
  「TOTP が confirmed のユーザーは passkey **login** を拒否」する既定を入れる
  (passkey の **confirm (step-up)** と **管理 (登録/削除)** は使える)。
  詰みを作らないため UI で理由を明示する (2FA 有効ユーザーには
  「パスキーは再認証に使えます。ログインには 2 要素認証が必要です」と表示。
  ボタンを disabled にはしない = 禁止事項 8)。Feature テストで固定。
  §7 スコープ外に「passkey の 2FA 準拠算入」を残しつつ、
  §8 に c2c へ戻す agenda として明記。

## [Critical] LoginMethodInventory が feature state を見ておらずキルスイッチ論と矛盾

- 判断: **対応する**
- 根拠: 完全に正しい。`Features::passkeys()` を外すと route が消えるのに
  `passkeys` 行を数え続けると「使えない手段」を残存手段として数える = guard の形骸化。
  同じ論理は social にも効く (`config('template.social_providers')` に無い provider は
  `SocialAuthController::ensureProviderEnabled()` が 404 にするため、
  連携行があってもログインには使えない)。
- 対応内容: inventory の定義を「**データがある**」から
  「**今この瞬間ログイン画面から使える**」に変更。
  - `passkey`: 行が 1 件以上 **かつ** `Features::enabled(Features::passkeys())`
  - `social:{p}`: 連携行あり **かつ** `config('template.social_providers.{p}')` が存在
  - `password`: `hasPassword()`
  ロールバック説明も「feature off で passkey は手段として数えられなくなる」に修正。

## [Critical] 既存 SSO ユーザーのランダム password が移行されない

- 判断: **一部対応する (前方修正のみ + 残存リスクの境界を明示)**
- 根拠: 指摘は正しいが、提案された「既存レコードの一括 null 化」は**危険で採れない**。
  「social_accounts 行を持つ」だけでは
  「パスワード登録 → 後から Google 連携」したユーザーと
  「SSO 登録 (ランダム password)」を区別できず、前者の**実パスワードを消してしまう**。
  `security_audit_events` にも `password_changed` の購読が無く
  (`SecurityEventType::PasswordChanged` は enum にあるが `RecordSecurityEvent` は未購読)、
  遡及的な判別子は存在しない。

  一方で **T-β の route 面では、この穴はロックアウトに繋がらない**:
  `EnsureLoginMethodRemains` が守る除去 route は T-β では `passkey.destroy` の 1 本のみで、
  「ランダム password を持つ legacy SSO ユーザー」は定義上 social 連携を必ず持つため、
  passkey を消しても手段は残る。穴が実害化するのは **SSO 連携解除 route を足したとき**であり、
  それは本設計のスコープ外かつ `LoginMethodRemovalRouteTest` が
  分類漏れとして必ず捕まえる (= 足すときに再検討が強制される)。
- 対応内容: §1-2 (A) と §2 施策 1 に以下を追記。
  (1) 修正は**前方のみ** (新規 SSO 登録は password null)。
  (2) 既存行の一括 null 化は**行わない**理由 (実パスワード消失リスク) を明記。
  (3) 運用者向けに件数確認クエリを提示し、0 件なら移行不要であることを明示。
  (4) 残存リスクの**射程** (SSO 連携解除 route 追加時に初めて実害化する) を明記し、
      その時点で必ず再検討が強制される構造 gate があることを示す。
  (5) 将来の判別子として `SecurityEventType::PasswordChanged` の購読配線を施策に追加
      (現在 enum だけあって記録されていない既存ギャップの是正でもある)。

## [Critical] passkey login と step-up の recent-auth 二重記録

- 判断: **対応する**
- 根拠: vendor 実測で `PasskeyVerified` は `VerifyPasskey::__invoke()` の中で dispatch され、
  **login 経路と confirm 経路の両方**で発火する。login 経路では
  `PasskeyVerified` (→ `confirm('passkey')`) の直後に `$guard->login()` が
  `Login` を発火し `StampRecentAuthOnLogin` が `confirm('login')` で上書きする。
  最終状態は決定的だが、設計に書かれていなければ将来壊れる。
- 対応内容: §2 施策 3-d に発火順序と最終 session state の表を追加し、
  Feature テストで `recent_auth_method` の期待値を経路別 (login / password / sso / passkey confirm)
  に固定する計画を明記。

  **追加で発見した問題も同時に対応**: `PasskeyConfirmationController::store()` は
  `$session->passwordConfirmed()` を呼び **Fortify の `auth.password_confirmed_at` に書く**。
  本アプリは `RecentAuthState` の docblock で
  「Fortify の `auth.password_confirmed_at` には書かない (意味汚染・権限漏れ回避)」と
  明示的にこれを避けている。将来 `password.confirm` を使う route が生えると
  passkey confirm がそれを満たしてしまう潜在的権限漏れになるため、
  「**`password.confirm` middleware を持つ route が 0 本である**」ことを
  Architecture テストで deny-by-default 固定する施策を追加。

## [Warning] passkey 未対応端末・共有端末・紛失時のフォールバック

- 判断: **対応する**
- 根拠: 妥当。現場 PWA が主戦場である以上、非対応端末で詰ませない条件は受入条件に要る。
- 対応内容: §2 施策 3-f に feature detection
  (`window.PublicKeyCredential` / `isUserVerifyingPlatformAuthenticatorAvailable`) と
  非対応時の表示条件、および §6 に `docs/supported-browsers.md` への追記を受入条件として追加。

## [Warning] binder 差し替えの上書き順序 / route cache 後の挙動

- 判断: **対応する**
- 根拠: 妥当。`PasskeysServiceProvider::boot()` の `Route::bind()` と
  アプリ Provider の `Route::bind()` はどちらが後勝ちかが provider 順序に依存する。
- 対応内容: §2 施策 3-b に「アプリ Provider を `PasskeysServiceProvider` より**後**に
  boot させる (`bootstrap/providers.php` への明示登録で順序を確定)」と明記し、
  `PasskeyPackageContractTest` の検査項目に
  「他人の passkey id → 404」「不在 id → 404」「binder の最終解決系がアプリ実装であること」を追加。

## [Warning] config cache / route cache を含む露出 gate

- 判断: **対応する**
- 根拠: 妥当。`Features::passkeys([...])` は `config(['fortify-options.passkeys' => ...])` という
  **副作用**で option を書くため、config cache の取り込み経路が既存 2FA と同じであることは
  設計上の前提として明示すべき。
- 対応内容: §0 と §4 に検査項目を追加。
  `PasskeyPackageContractTest` に
  「`Passkeys::shouldRegisterRoutes()` が false」
  「passkey route の登録元が Fortify 側であること (route ファイル由来の一意性)」
  「`fortify-options.passkeys.confirmPassword` が false として解決される」を含める。

## [Warning] ConfirmedEmailTrustPolicy の名前が強すぎる

- 判断: **一部対応する (改名はしない / 判定基準を明文化する)**
- 根拠: 命名の懸念は理解するが、`Confirmed` / `Unconfirmed` は
  c2c 台帳 `auth-sso-social` の boundary が canonical 資産名として明記している
  (`MicrosoftEmailTrustPolicy (Confirmed/Unconfirmed キルスイッチ)`)。
  家系 4 リポジトリで名前が揃っていることが台帳追従の価値なので、
  aicue だけ独自名にするのは追従性を損なう。
  ただし「何を根拠に confirmed とみなすか」が空欄なのは指摘のとおり。
- 対応内容: 名前は据え置き、判定基準を interface の契約として明文化する
  (「provider が email の**所有**を検証済みであり、かつ組織管理者が任意の email を
  claim できない provider か」)。Google が Confirmed である根拠と、
  Microsoft が Unconfirmed になる根拠 (nOAuth) を設計に書く。

## [Warning] WebAuthn endpoint の Inertia / JSON 境界

- 判断: **対応する**
- 対応内容: §2 施策 3-b に 7 route × (リクエスト種別 → 応答形式) の表を追加。

## [Warning] T-β が大きい

- 判断: **一部対応する (TODO は分けない / TODO 内の PR 段階化を明記)**
- 根拠: 指摘自身が「施策 1 と 3 を完全分離しない理由は成立している」と認めている。
  TODO を分けると guard が保護対象ゼロで着地する問題が戻るため分割はしない。
  段階化の提案は有益なので取り込む。
- 対応内容: §5 の実装順序を「PR 段階」として明示し、
  どの段階まではキルスイッチ off のまま main に入れられるかを書く。

## [Warning] 型安全性の記述不足

- 判断: **対応する**
- 対応内容: §4 に `User implements PasskeyUser` / `PasskeyAuthenticatable` trait /
  `passkeys()` の戻り型 / `App\Models\Passkey` + Factory / binder closure の型を追記。

## [Suggestion] 使命への貢献 / dont-discover 判断

- 判断: **見送る (現状維持)** — 肯定的評価であり変更不要。

---

## Round 1 で指摘されなかったが、対応過程で新たに発見して設計に追加した事実

1. **(F) vendor の passkey confirm が `auth.password_confirmed_at` を汚染する**
   `PasskeyConfirmationController::store()` が `$session->passwordConfirmed()` を呼ぶ。
   本アプリは RecentAuthState の docblock で「Fortify の auth.password_confirmed_at には
   書かない (意味汚染・権限漏れ回避)」と明示的に避けている。controller 内で起きるため
   Response 差し替えでは消せない。→ `password.confirm` middleware を持つ route が
   0 本であることを deny-by-default で固定する gate を追加。

2. **`VerifyPasskey::ensurePasskeyBelongsToUser()` は既に文字列正規化済み**
   `(string) $passkey->user_id !== (string) $identifier` で比較しており、
   spirux:T1108 施策 3 の修正は upstream 済み。**strict `===` が残っているのは
   `PasskeyRegistrationController::destroy()` だけ**であることを実測で確認した。

3. **`SecurityEventType::PasswordChanged` は enum にあるが購読されていない**
   `RecordSecurityEvent` が購読していないため記録されていない。既存ギャップの是正として
   施策 1 に含めた (将来の password 判別子にもなる)。

---

## 再レビュー依頼

以下を重点的に見てください。

- Critical 3 (既存 SSO ユーザーの移行) について、「遡及移行しない + 残存リスクの射程を
  T-β の route 面に限定する」という反論が成立しているか。
  すなわち「T-β の除去 route は passkey.destroy の 1 本のみで、legacy SSO ユーザーは
  定義上 social 連携を持つのでロックアウトしない」という論証に穴はないか
- Critical 1 の解 (TOTP 有効ユーザーの passkey login を拒否) が、
  かえって UX 上の詰みや別の後退を生まないか。より良い代替があるか
- `Confirmed`/`Unconfirmed` の改名を見送り、判定基準の明文化で代替した判断
- P1〜P6 の PR 段階化で、P3 が意図的に fail する設計が worktree 運用と両立するか

全体判定 (APPROVED / CHANGES_REQUESTED) を必ず出してください。

---

## 修正後の概念設計 (全文)

# 概念設計: auth-method-and-passkey

対象 c2c 台帳 (ledger_revision `f7175a1d…` 時点のスナップショット):

| id | aicue status | canonical |
|----|--------------|-----------|
| `auth-login-method-retention` | `pending` | t0 |
| `auth-sso-social` | `update_pending` (version `pre-t0`) | t0 |
| `auth-passkey` | `pending` | v1 (aigenba) |

---

## 0. 着手前の露出リスク確認 (結論: **露出なし**)

指示された事前確認を実行した。結果は「**passkey route は 1 本も生えていない**」。

### 実測

```
$ grep -n "laravel/passkeys" composer.lock   → v0.2.1 が存在 (composer.json の require には無い)
$ php artisan route:list --json | (passkey フィルタ)  → 198 routes 中 0 件
```

### なぜ生えていないか (経路を最後まで追った)

1. `laravel/passkeys` は **`laravel/fortify` v1.37.2 の推移依存** (`composer.lock` の fortify
   require に `"laravel/passkeys": "^0.2.0"`)。`composer.json` の直接 require ではない。
2. `Laravel\Passkeys\PasskeysServiceProvider` は `extra.laravel.providers` 宣言を持ち、
   `composer.json` の `extra.laravel.dont-discover` には **入っていない** ため
   **auto-discovery されている**。
3. しかし `Laravel\Fortify\FortifyServiceProvider::configurePasskeys()` が
   **無条件で `LaravelPasskeys::ignoreRoutes()` を呼ぶ** (vendor/laravel/fortify/src/FortifyServiceProvider.php:123)。
   `Passkeys::shouldRegisterRoutes()` が false になるため、パッケージ側 routes は登録されない。
4. Fortify 1.37 は **passkey route を自前の routes ファイルで提供する**
   (vendor/laravel/fortify/routes/routes.php:180)。ただし
   `if (Features::enabled(Features::passkeys()))` でゲートされており、
   本アプリの `config/fortify.php` の `features` に `Features::passkeys()` は **無い**。

⇒ 「passkeys テーブル不在のまま 500」の露出は**現時点で存在しない**。

### したがって `dont-discover` 追加は行わない (積極的に避ける)

`laravel/passkeys` を `dont-discover` に入れるのは**有害**である:

- Fortify の passkey route は `Laravel\Passkeys\Http\Controllers\*` を直接参照する
- `PasskeysServiceProvider::register()` が 4 つの Response contract の binding を張る
- `registerRouteBindings()` が `passkey` route model binding を張る

discovery を切ると Fortify ネイティブの passkey 機能ごと壊れる。
台帳 `auth-passkey` が求めているのは「passkey を導入する」ことであり、封じることではない。

### ただし「露出なし」は**脆い不変条件**なので gate は張る

現状の安全は「Fortify が boot して `ignoreRoutes()` を呼ぶ」という**ロード順序依存の副作用**に
乗っているだけである。Fortify の将来版がこの呼び出しをやめる / features 配列を触った PR で
意図せず有効化される、のどちらでも黙って露出面が変わる。
台帳 `auth-passkey` の `gates` が指名する canonical gate 2 本を新設して機械固定する:

- `tests/Architecture/PasskeyPackageContractTest.php`
  — パッケージ側 route 非登録・Response contract binding・vendor 契約の pin
- `tests/Architecture/PasskeyRouteProtectionTest.php`
  — passkey route 各本の middleware スタックを列挙固定 (deny-by-default)

指示にあった `tests/Feature/Auth/PasskeyRouteExposureTest.php` は、
**露出が無かったため「露出を止める応急 pin」としては不要**であり、
上記 canonical gate 2 本に役割を統合する (家系で名前が揃うほうが台帳追従として正しい)。

---

## 1. 背景・課題

### 1-1. 台帳が指す 3 件は「1 つの安全不変条件」の構成部品

3 件はいずれも **「ユーザーが自分のアカウントから締め出されない / 他人に成り代わられない」** という
同じ不変条件の別側面である。

- `auth-login-method-retention`: ログイン手段を**全部消して自分で締め出す**事故の防止
- `auth-sso-social`: IdP が主張する email を**無条件に信頼して他人に成り代わられる**事故の防止
- `auth-passkey`: パスワードに依存しないログイン手段の**追加**

### 1-2. 発見した実害 (設計の起点になる 4 つ)

調査で以下を確認した。いずれも「台帳追従」以前に aicue 側に既に存在する欠陥である。

**(A) `hasPassword()` が SSO 登録ユーザーに対して嘘をつく**

`app/Services/Auth/SocialAccountService.php:57` が SSO 登録時に `Str::password(32)` の
ハッシュを書き込む。一方 `database/migrations/0001_01_01_000000_create_users_table.php:22-24`
は **`password` を nullable にしており**、コメントは明示的に
「SSO-only ユーザー (`UserFactory::ssoOnly()` / password 未設定) を許容するため nullable。
password 経路の可否判定は `User::hasPassword()` が fail-closed で行う」と書いている。
`User::hasPassword()` の docblock も
「テンプレート標準では SSO 登録ユーザーにもランダム password が設定されるため常に true だが、
password 未設定 (null / 空) を許すアプリはこの判定で SSO-only ユーザーを password 経路から
fail-closed で除外できる」と、**逸脱を選ぶ余地を明示的に残している**。

つまり **スキーマ・Factory・判定ヘルパはすべて「password は無くてよい」前提で作られており、
`SocialAccountService::register()` だけがその前提を裏切っている**。

現在の実害: SSO で登録したユーザーは `/recent-auth/status` が `passwordSet: true` を返すため、
`RecentAuthModal` / `ConfirmRecentAuth.svelte` が**入力しても絶対に通らないパスワード欄**を提示する。
`RecentAuthPasswordRecoveryTest` が守っている「手段が無いユーザーの回復導線」も、
このユーザーには `canSatisfy: true` に見えるので出ない。

そして本題として、**`EnsureLoginMethodRemains` が `hasPassword()` で数えると常に真になり
guard が形骸化する** (指示書の指摘どおり)。

**既存レコードの扱い (遡及移行はしない)**

修正は**前方のみ** (新規 SSO 登録で `password` を null にする) とし、
既存行の一括 null 化は**行わない**。理由:

- 「`social_accounts` 行を持つ」だけでは
  「**パスワードで登録 → 後から Google を連携**」したユーザーと
  「**SSO で登録** (ランダム password)」を区別できず、前者の**実パスワードを消してしまう**
- `security_audit_events` にも判別材料が無い
  (`SecurityEventType::PasswordChanged` は enum に存在するが `RecordSecurityEvent` が
  **購読していない** = 記録されていない)
- したがって**遡及的な判別子は存在しない**

**残存リスクの射程 (T-β では実害化しない)**

`EnsureLoginMethodRemains` が守る除去 route は T-β では **`passkey.destroy` の 1 本のみ**である。
「ランダム password を持つ legacy SSO ユーザー」は**定義上 social 連携を必ず持つ**ため、
passkey を消しても手段は残り、ロックアウトは起こらない。
この穴が実害化するのは **SSO 連携解除 route を足したとき**であり、それは本設計のスコープ外かつ
`LoginMethodRemovalRouteTest` が分類漏れとして必ず fail させる
(= 足す PR で再検討が構造的に強制される)。

**運用者への確認手順**: 対象行の有無は次で確認できる。0 件なら移行の議論自体が不要。

```sql
SELECT count(*) FROM users u
WHERE u.password IS NOT NULL
  AND EXISTS (SELECT 1 FROM social_accounts sa WHERE sa.user_id = u.id);
```

1 件以上ある環境では、**自動一括 null 化ではなく運用者の個別判断**で対応する
(誤って実パスワードを消しても `/forgot-password` で回復できるが、無断で消してよいものではない)。

**将来のための判別子を今から積む**: `SecurityEventType::PasswordChanged` の購読を
`RecordSecurityEvent` に配線する (enum だけあって記録されていない既存ギャップの是正でもある)。
これ以降に設定されたパスワードは監査ログで判別可能になる。

**(B) vendor の passkey 削除は「他人の passkey の存在」を 403 で漏らす**

`vendor/laravel/passkeys/src/Http/Controllers/PasskeyRegistrationController.php::destroy()`:

```php
abort_unless($passkey->user_id === $user->getKey(), 403);
```

route model binding (`PasskeysServiceProvider::registerRouteBindings()`) は
**グローバルに id で解決する**ため、他人の passkey id を投げると
「存在する→403」「存在しない→404」で**識別できてしまう**。
AGENTS.md セキュリティ不変条件 2「不整合は**認可より前に 404**」(403 で存在を漏らさない) に反する。

台帳 `auth-passkey` の boundary が canonical 資産として挙げる
「PasskeyServiceProvider (vendor route 加工・**binder 差し替え**・Response contract 上書き)」の
**binder 差し替えはまさにこれの是正**である。

**(C) passkey endpoint は現状のまま有効化すると無制限になる**

Fortify の passkey route の throttle は `config('fortify.limiters.passkeys')` から取る。
本アプリの `config/fortify.php` の `limiters` は `login` / `two-factor` のみで
`passkeys` が無く、`FortifyServiceProvider::passkeyThrottleMiddleware()` は `null` を返す。
⇒ **`GET /passkeys/login/options` (guest, 未認証) が無制限**になる。
毎回 `random_bytes(32)` + session 書き込みが走る未認証 endpoint を絞りなしで開けてはいけない。

**(D) `RecentAuthState::clear()` は本番呼び出し元ゼロの死んだ API**

docblock は「認証要素変更 (password/email/2FA/social link·unlink 等) 後に鮮度を失効させる」と
宣言しているが、**production コードからの呼び出しは 1 件も無い**。
2026-08-04 裁定 A の `ClearRecentAuthOnPasskeyChange` が**この API の最初の実利用者**になる。

**(E) vendor の passkey login は Fortify の TOTP チャレンジを迂回する**

`vendor/laravel/passkeys/src/Http/Controllers/PasskeyLoginController.php::store()` は
`$guard->login($passkey->user, $request->remember())` を**直接**呼ぶ。
Fortify の通常ログインが通る two-factor challenge
(`login.id` を session に置いて `/two-factor-challenge` へ送る経路) を通らない。

- **組織 2FA 強制**は post-login の global middleware
  (`RequireTwoFactorForEnforcedOrganizations`, `bootstrap/app.php:92`) なので**効く**
- しかし**ユーザーが自分で有効化した TOTP は迂回される**

2026-08-04 裁定 A の再検討条件が「**パスキーが 2FA 準拠判定に算入される時**」である以上、
台帳の現在の立場は「**passkey は 2FA 相当ではない**」であり、
passkey login で TOTP を置き換えるのは assurance の後退にあたる。

**(F) vendor の passkey confirm は `auth.password_confirmed_at` を汚染する**

`PasskeyConfirmationController::store()` は `$session->passwordConfirmed()` を呼ぶ。
本アプリは `RecentAuthState` の docblock で
「Fortify の `auth.password_confirmed_at` には書かない (意味汚染・権限漏れ回避。
横断標準は `recent_auth_at` を正本とする)」と**明示的にこれを避けている**。
将来 `password.confirm` middleware を使う route が生えると、
passkey confirm がそれを黙って満たしてしまう潜在的な権限漏れになる。

### 1-3. 台帳スナップショットの陳腐化 1 件 (報告事項)

台帳 `auth-passkey` の aicue note は
「composer に laravel/passkeys なし (実査)」と記録しているが、
これは **fortify 1.37 系への更新前の実査**である。現在は推移依存として
`laravel/passkeys v0.2.1` が `composer.lock` に入っている。
本設計はこの実測を前提にする (= パッケージ導入コストは既に払い済み)。

---

## 2. 改善アイデア

### 施策 1. ログイン手段の「実在」を単一の源で定義し、除去経路に関門を張る

**まず「実際にログインに使える手段」を型で定義する。**
`App\Services\Auth\LoginMethodInventory` が `User` から
「**今この瞬間、ログイン画面から本人がアカウントに入れる手段**」の集合を返す。

基準は「**データが存在する**」ではなく「**今この瞬間使える**」である
(データだけで数えると、機能を落とした後も使えない手段を残存手段として数えてしまい guard が形骸化する):

| 手段 | 数える条件 |
|------|-----------|
| `password` | `User::hasPassword()` (raw attribute が非空文字列) |
| `social:{provider}` | `social_accounts` に行がある **かつ** `config('template.social_providers.{provider}')` が存在する |
| `passkey` | `passkeys` に行がある **かつ** `Features::enabled(Features::passkeys())` |

- **social は `capability` で絞らない** (`identity_only` の provider でも「ログイン」はできる)。
  ただし `config` に無い provider は `SocialAuthController::ensureProviderEnabled()` が
  404 にするため、連携行があってもログインには使えない → 数えない
- **passkey は feature flag に連動させる**。これにより
  「`Features::passkeys()` を外す = キルスイッチ」という主張が
  inventory 側とも矛盾しなくなる (feature off なら passkey は手段として数えられない)

**既存の `canSatisfy` と統合しない。** `ConfirmRecentAuthController::buildStatus()` の
`canSatisfy = $passwordSet || $providers !== []` は
「**step-up 再認証を成立させられるか**」であり、`ProviderCapability::isStepUpSatisfier()` で
provider を絞り込む。一方ログイン手段は capability に関係なく数える
(`identity_only` の provider でもログインはできる)。
AGENTS.md 思考原則 4「別物の概念を『似ているから』で統合しない」に従い、両者は別クラスに保つ。

**(A) の是正を前提条件として同梱する。** `SocialAccountService::register()` から
`Str::password(32)` を外し、SSO 登録ユーザーの `password` を **null のまま**にする。
これによって `hasPassword()` が初めて意味を持ち、guard が形骸化しない。
これは `docs/template-divergence.md` に登録する**意図的なテンプレート逸脱**とする
(`User::hasPassword()` の docblock が明示的に許容している逸脱)。

**関門**: `app/Http/Middleware/EnsureLoginMethodRemains.php`。
「この操作が成功したら手段が 0 になる」ならブロックする。
route パラメータから除去対象を特定し、残存数を数える。

**構造 gate**: `tests/Architecture/LoginMethodRemovalRouteTest.php` を
`NestedRouteIdorDefenseTest` と同じ **inventory + exempt allowlist の deny-by-default** で新設。
候補 route を構造的に列挙し、
「guard 必須 (`ensure-login-method` middleware を持つ)」か
「免除 (理由文字列必須)」のどちらかに**必ず分類させる**。分類漏れは fail。

免除の代表例と理由を最初から登録しておく:

- `settings.account.destroy` — アカウント自体を消す操作。手段が 0 になるのは**目的**であり関門を通さない
- `two-factor.disable` — 第二要素であってログイン手段ではない
- `user-password.update` — 変更であって除去ではない (`current_password` 必須で null 化不能)

### 施策 2. SSO の email 信頼を差し替え可能な policy にする

`SocialAccountService::register()` の `email_verified_at => now()` 無条件付与
(SocialAccountService.php:62) を policy 経由に通す。

- `App\Services\Auth\EmailTrust\EmailTrustPolicy` interface (`trustsEmail(SocialiteUser): bool`)
- `ConfirmedEmailTrustPolicy` (true) / `UnconfirmedEmailTrustPolicy` (false)
- provider ごとの宣言は `config/template.php` の `social_providers.{provider}.email_trust` に置く
  (既存の `capability` と同じ場所・同じ fail-closed 作法)
- **google は `confirmed` を宣言し、挙動は完全に不変** (`email_verified_at` は今までどおり付く)

**`Confirmed` / `Unconfirmed` という名前の判定基準を契約として明文化する。**
名前は c2c 台帳 `auth-sso-social` の boundary が canonical 資産名として指定しているため
(`MicrosoftEmailTrustPolicy (Confirmed/Unconfirmed キルスイッチ)`)、家系 4 リポジトリで
揃える価値を優先して据え置く。ただし「何を根拠に confirmed とみなすか」は空欄にしない:

> **Confirmed** = provider が当該 email の**所有を検証済み**であり、かつ
> **テナント管理者が任意の email を claim できない**こと。この 2 条件を満たす provider のみ、
> IdP の主張だけで `email_verified_at` を立ててよい。

- **Google が Confirmed である根拠**: Gmail / Workspace のいずれもアカウント作成時に
  email 所有を検証しており、管理者は自組織が所有権を証明したドメインの外を claim できない
- **Microsoft が Unconfirmed になる根拠 (nOAuth)**: Entra ID のテナント管理者は
  未検証の `email` claim を任意に設定でき、他社ドメインの email を主張できる。
  この場合 `email_verified_at` を立てず、通常のメール確認フローに落とす

**未宣言は fail-closed** (= `unconfirmed` 扱い) とし、
`tests/Architecture/SocialProviderTrustPolicyTest.php` が
`config/template.php` の全 provider に宣言があることを機械検証する
(`SsrfPinBoundaryTest` / `RecentAuthRouteTest` と同型)。

**踏み込まないこと** (台帳で未裁定・指示でも明示):

- Microsoft provider の追加 (台帳 `auth-sso-social` の `agenda` は未解決)
- aigenba の id_token 署名検証 + `auth_time` 検証 (同 `agenda` 未解決)
- `capability` は `fresh_auth_prompt_only` のまま据え置き

### 施策 3. passkey を Fortify ネイティブ機能として有効化し、アプリ側の不変条件を被せる

**指示は「template t0 の `PasskeyServiceProvider` を移植」だが、
移植元 t0 のソースは本環境に存在しない** (リポジトリにも近傍にも `laravel-claude-template` は無い)。
一方で台帳 `auth-passkey` の boundary は canonical 資産の中身を
「**vendor route 加工・binder 差し替え・Response contract 上書き**」と明記している。
これは vendor の**置き換え**ではなく**アダプタ**である。

さらに AGENTS.md 思考原則 1「フレームワークのレンジ内でやる。自前機構の前に
Laravel / 同梱モジュールの公式作法を確認する」に照らすと、
**route 定義・controller・action・migration を自前で書き直すのは明確な違反**である。
Fortify 1.37 は passkey を第一級機能として同梱しており、公式作法は
`Features::passkeys()` を有効にすることである。

したがって:

**3-a. Fortify の passkey feature を有効化する**

`config/fortify.php` の `features` に `Features::passkeys(['confirmPassword' => false])` を追加。
`confirmPassword => false` は 2FA と**同じ理由で必須**である —
本アプリは Fortify 標準の `password.confirm` (3h・パスワード限定) を撤去し
generic recent-auth (15 分窓・パスワード or 再SSO) に統一済みで、
`password.confirm` を残すと SSO-only ユーザーが確認画面で詰む
(`config/fortify.php` の既存コメントがこの判断を明文化している)。

`limiters` に `'passkeys' => 'passkeys'` を追加し、
`FortifyServiceProvider` に `RateLimiter::for('passkeys', ...)` を定義する ((C) の是正)。

migration は `Passkeys::migrationPath()` を Fortify が publish するものを取り込む
(自前で書かない)。

**3-b. `App\Providers\PasskeyServiceProvider` を「アダプタ」として新設**

台帳 boundary の 3 役割をそのまま担わせる:

| 役割 | 中身 | 解決する問題 |
|------|------|-------------|
| binder 差し替え | `Route::bind('passkey', ...)` を**認証ユーザーの `passkeys()` relation 経由**に張り替える | (B) の 403 情報漏れ → 他人の passkey は **404** |
| Response contract 上書き | 4 つの `PasskeyXxxResponse` を Inertia / DTO+JsonResource を返すアプリ実装に差し替え | AGENTS.md 禁止事項 4 (`response()->json()` 直書き) の回避、Inertia 契約への適合 |
| vendor route 加工 | `recent-auth` / `ensure-login-method` の後付け配線 | 既存 `attachRecentAuthToSensitiveRoutes()` と同じ作法 |
| login 認可 closure | `Passkeys::authorizeLoginUsing()` で TOTP 有効ユーザーの passkey **login** を拒否 | (E) の 2FA 迂回 |

**boot 順序を明示的に固定する。** `Route::bind()` は後勝ちのため、
アプリ Provider が `PasskeysServiceProvider` より**後**に boot する必要がある。
`bootstrap/providers.php` に `App\Providers\PasskeyServiceProvider` を明示登録する
(auto-discovery された package provider より後に並ぶ)。
この順序依存は `PasskeyPackageContractTest` が
「binder の最終解決系がアプリ実装であること」で機械固定する。

**7 route の応答形式を表で固定する** (WebAuthn ceremony はブラウザ API が厳密な JSON を期待する。
Inertia との境界を誤るとフロントが壊れる):

| route | 認証 | リクエスト | 応答形式 |
|-------|------|-----------|---------|
| `passkey.login-options` (GET) | guest | XHR (fetch) | **JSON** (`{options}`) + `no-store` |
| `passkey.login` (POST) | guest | XHR (fetch) | **JSON** (`{redirect}`) — vendor 既定を JsonResource へ差し替え |
| `passkey.confirm-options` (GET) | auth | XHR (fetch) | **JSON** (`{options}`) + `no-store` |
| `passkey.confirm` (POST) | auth | XHR (fetch) | **204 No Content** + `no-store` (既存 `recent-auth.password` と同一契約) |
| `passkey.registration-options` (GET) | auth | XHR (fetch) | **JSON** (`{options}`) + `no-store` |
| `passkey.store` (POST) | auth | Inertia | **redirect back + flash** (禁止事項 7: 操作系 POST で `intended()` を使わない) |
| `passkey.destroy` (DELETE) | auth | Inertia | **redirect back + flash** |

`options` 系 3 本は challenge と PII (email) を載せるため `no-store` を必須にする。

**3-c. `App\Models\Passkey` (vendor モデルの app サブクラス) + Factory**

`Passkeys::usePasskeyModel(App\Models\Passkey::class)` で差し替える。理由:

- AGENTS.md 実装規約「新規モデル追加時は Factory の追加と
  `docs/architecture.md` / `docs/factories.md` への追記が必須」
  — テストは Factory 生成が必須 (`Model::create()` 手組み禁止)
- self-scoped な route binding と `HasFactory` の置き場所になる

**3-d. recent-auth との配線 (2026-08-04 裁定 A)**

- `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php` を新設し、
  `PasskeyRegistered` / `PasskeyDeleted` の両方で `RecentAuthState::clear()` を呼ぶ
  (= credential 集合の変化 = 失効)。**(D) の死んだ API の最初の実利用者**になる
- 配線は `AppServiceProvider::boot()` の `Event::listen()` で明示的に行う
  (同 `register()` で `EventServiceProvider::disableEventDiscovery()` 済みのため
  auto-discovery には乗らない)
- `PasskeyVerified` → `RecentAuthState::confirm(method: 'passkey')` を satisfier に追加する
- **登録直後 passkey の satisfier 除外は実装しない** (裁定で見送り済み)

**イベント発火順序と最終 session state を固定する。**
vendor 実測で `PasskeyVerified` は `VerifyPasskey::__invoke()` の**中**で dispatch されるため、
**login 経路と confirm 経路の両方**で発火する。経路ごとの最終状態は次のとおりで、
これを Feature テストで固定する (書かなければ将来黙って壊れる):

| 経路 | 発火順 | 最終 `recent_auth_method` |
|------|--------|--------------------------|
| passkey **login** | `PasskeyVerified` → `Login` | `'login'` (`StampRecentAuthOnLogin` が後勝ち) |
| passkey **confirm** (step-up) | `PasskeyVerified` のみ | `'passkey'` |
| password 再入力 | — | `'password'` |
| 再SSO (step-up) | — | `'sso'` (+ `recent_auth_provider`) |
| passkey **登録 / 削除** | `PasskeyRegistered` / `PasskeyDeleted` | **未設定 (clear 済み)** |

**(F) の是正も同時に行う。** `PasskeyConfirmationController::store()` が呼ぶ
`$session->passwordConfirmed()` は controller 内で起きるため Response 差し替えでは消せない。
汚染そのものを害にしないため、
「**`password.confirm` middleware を持つ route が 0 本である**」ことを
Architecture テスト (`tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php`) で
deny-by-default 固定する。本アプリは既に generic recent-auth へ統一済みなので現状 0 本であり、
将来 `password.confirm` を使う route が生えた瞬間に「passkey confirm がそれを満たしてよいか」を
必ず考えさせる構造になる。

**3-e. 2FA を後退させない (fail-closed 既定 + 台帳へ差し戻し)**

(E) のとおり passkey login は Fortify の TOTP チャレンジを迂回する。
組織 2FA 強制は post-login の global middleware なので効くが、
**ユーザーが自分で有効化した TOTP は迂回される**。

裁定 A の再検討条件が「**パスキーが 2FA 準拠判定に算入される時**」である以上、
台帳の現在の立場は「passkey は 2FA 相当ではない」であり、
passkey login で TOTP を置き換えるのは **assurance の後退**にあたる。
これは c2c 未裁定の論点なので、**fail-closed の既定を置いた上で agenda として差し戻す**。

**既定**: `Passkeys::authorizeLoginUsing()` で
「TOTP が confirmed のユーザーの passkey **login** を拒否」する。

- passkey の **confirm (step-up)** と **管理 (登録 / 削除)** は 2FA ユーザーでも使える
  (= 登録した passkey が完全に無駄にはならない)
- **詰みを作らない**: TOTP を有効化したユーザーは定義上 password か SSO を持っている
  (それでログインして 2FA を有効化した) ため、ログイン手段は必ず残る
- **UI で理由を明示する**: 2FA 有効ユーザーの Settings/Security には
  「パスキーはこの画面での再認証に使えます。ログインには 2 要素認証が必要です」と表示する。
  **ボタンを disabled にしない** (AGENTS.md 禁止事項 8)
- 実装は closure 1 本なので、裁定が出たら即座に反転できる

**あわせて固定するもの** (Feature テスト):

- TOTP 有効ユーザーの `POST /passkeys/login` が拒否される
- TOTP 無効ユーザーの passkey login は成功する
- passkey ログインでも **2FA 強制組織のゲートは通過できない**
  (= passkey は組織 2FA 準拠に算入されない)

**3-f. フロント**

- `resources/js/lib/passkeys.ts` (TypeScript 必須 / AGENTS.md 禁止事項 7)
  — WebAuthn ceremony ラッパ (base64url ⇄ `ArrayBuffer` 変換 + `navigator.credentials` 呼び出し)
- **非対応環境で詰ませない (feature detection)**。現場 PWA が主戦場である以上、
  端末が passkey を使えないことは常態として扱う:
  - `window.PublicKeyCredential` が無い → passkey セクション自体を出さず、
    password / SSO 導線のみを見せる
  - `PublicKeyCredential.isUserVerifyingPlatformAuthenticatorAvailable()` が false →
    「この端末ではパスキーを作成できません」と**理由を明示**して案内する
    (ボタンを disabled にはしない = 禁止事項 8)
  - ceremony 中の `NotAllowedError` (ユーザーキャンセル / タイムアウト) を
    エラーとして騒がず、再試行導線に落とす
  - ログイン画面の passkey ボタンは feature detection 成功時のみ出す
    (= 共有端末 / 生体未設定端末では password / SSO が既定導線のまま)
- `Settings/Security.svelte` に passkey カードを追加。
  既存の `guardWithRecentAuth` / `RecentAuthModal` 契約にそのまま乗せる
  (登録・削除は recent-auth 必須のため)
- T102 の `noInlineConfig: true` (eslint.config:58) により
  **inline `eslint-disable` が使えない**。WebAuthn の `ArrayBuffer`/`base64url` 変換で
  lint 違反を出さない書き方にするか、必要なら `eslint.config` 側で
  ファイル単位の override を宣言する (inline に逃げない)
- DESIGN.md の token / Atomic Design に従い、既存 atom (`Card`/`Button`/`Badge`/`Alert`/
  `FormField`/`Input`/`ConfirmDialog`) の組合せで構成する。新規 SVG は作らない
  (`@lucide/svelte` のみ。`svg-inline-allowlist.test.ts` が強制)
- 台帳 `auth-passkey` の gate `tests/js/architecture/passkeys-import-isolation.test.ts` に倣い、
  `passkeys.ts` が passkey 以外から import されないことを固定する

---

## 3. 期待効果

### 使命 (North Star) への貢献

AI-CUE の使命は「専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする」。
**v1 スコープで撮影は PWA (同一オリジン・セッション認証)** と決まっている。

- **現場でパスワードを打たせない**: 手袋・保護具・明るい工場現場でのスマホ入力は
  マニュアル動画作成のワークフロー上、最も摩擦の大きい所作の一つ。
  passkey (生体/端末ロック解除) は「思考ゼロ」の思想と直結する
- **同一オリジン PWA なので RP ID が素直**: WebAuthn の relying party は
  アプリのホストそのままでよく、cross-origin の複雑さを持ち込まない
- **締め出しは現場を止める**: 現場作業者が自分で手段を消して入れなくなると、
  復旧には管理者の介在が要る。`EnsureLoginMethodRemains` はその停止時間を構造的に消す

### 具体的な改善見込み

- SSO 登録ユーザーに**入力しても通らないパスワード欄を出さなくなる** ((A) の是正)
- 他人の passkey の存在が **403 で漏れなくなる** ((B) の是正、404 に統一)
- 未認証の passkey challenge endpoint が**絞られる** ((C) の是正)
- 「ログイン手段を減らす route」が今後追加されたとき、
  guard 無しなら **CI が構造的に fail する** (分類漏れの deny-by-default)
- `RecentAuthState::clear()` が**死んだ API でなくなる** ((D) の是正)

---

## 4. 実装方針 (概要)

### 変更コンポーネント

| 層 | ファイル | 施策 |
|----|---------|------|
| config | `config/fortify.php` (features / limiters) | 3 |
| config | `config/template.php` (`social_providers.*.email_trust`) | 2 |
| config | `.env.example` (`PASSKEYS_*`) | 3 |
| Service | `app/Services/Auth/LoginMethodInventory.php` (新) | 1 |
| Service | `app/Services/Auth/SocialAccountService.php` (改) | 1, 2 |
| Policy | `app/Services/Auth/EmailTrust/{EmailTrustPolicy,Confirmed…,Unconfirmed…}.php` (新) | 2 |
| Middleware | `app/Http/Middleware/EnsureLoginMethodRemains.php` (新) | 1 |
| Provider | `app/Providers/PasskeyServiceProvider.php` (新) | 3 |
| Provider | `app/Providers/FortifyServiceProvider.php` (改: limiter + route 配線) | 1, 3 |
| Provider | `app/Providers/AppServiceProvider.php` (改: `Event::listen`) | 3 |
| Provider | `bootstrap/providers.php` (改: boot 順序を確定) | 3 |
| Model | `app/Models/User.php` (改: `PasskeyUser` 実装 + trait) | 3 |
| Model | `app/Models/Passkey.php` + `database/factories/PasskeyFactory.php` (新) | 3 |
| Listener | `app/Listeners/Auth/ClearRecentAuthOnPasskeyChange.php` (新) | 3 |
| Listener | `app/Listeners/Auth/StampRecentAuthOnPasskeyVerified.php` (新) | 3 |
| Listener | `app/Listeners/RecordSecurityEvent.php` (改: `PasswordChanged` 購読) | 1 |
| Migration | passkeys テーブル (vendor publish) | 3 |
| Front | `resources/js/lib/passkeys.ts` (新) | 3 |
| Front | `resources/js/pages/Settings/Security.svelte` (改) | 3 |
| Front | `resources/js/pages/Auth/Login.svelte` (改: passkey ログインボタン) | 3 |
| Docs | `docs/template-divergence.md` / `docs/architecture.md` / `docs/factories.md` / `docs/supported-browsers.md` | 1, 3 |

### 型安全性 (PHPStan level 10) の要点

```php
// app/Models/User.php
class User extends Authenticatable implements
    CipherSweetEncrypted, LaratrustUser, MustVerifyEmail, OAuthenticatable,
    \Laravel\Passkeys\Contracts\PasskeyUser        // ← 追加
{
    use \Laravel\Passkeys\PasskeyAuthenticatable;  // ← 追加 (@phpstan-require-implements PasskeyUser)
    // trait が passkeys(): HasMany / hasPasskeysEnabled(): bool /
    // getPasskeyUserHandle(): string / getPasskeyDisplayName(): string /
    // getPasskeyUsername(): string を供給する
}

// app/Models/Passkey.php — vendor モデルの app サブクラス
/** @extends \Illuminate\Database\Eloquent\Factories\HasFactory<\Database\Factories\PasskeyFactory> */
final class Passkey extends \Laravel\Passkeys\Passkey { use HasFactory; }
// Passkeys::usePasskeyModel(Passkey::class) で差し替える
// (Passkeys::$passkeyModel は class-string<\Laravel\Passkeys\Passkey> なので共変で通る)

// binder closure は narrowing 必須 (user() は User|AdminUser の union になりうる)
Route::bind('passkey', function (string $value): Passkey { /* 認証ユーザー scope + firstOrFail */ });
```

`getPasskeyDisplayName()` / `getPasskeyUsername()` は CipherSweet 暗号化属性
(`name` / `email`) を返すが、モデル経由の読み出しは透過的に復号されるため型・動作とも問題ない。

### 新設する gate

| gate | 型 | 施策 |
|------|----|----|
| `tests/Architecture/LoginMethodRemovalRouteTest.php` | inventory + exempt (deny-by-default) | 1 |
| `tests/Architecture/SocialProviderTrustPolicyTest.php` | config 宣言網羅 | 2 |
| `tests/Architecture/PasskeyPackageContractTest.php` | vendor 契約 pin | 3 |
| `tests/Architecture/PasskeyRouteProtectionTest.php` | route middleware 列挙固定 | 3 |
| `tests/Architecture/PasswordConfirmMiddlewareAbsenceTest.php` | `password.confirm` 0 本の deny-by-default | 3 |
| `tests/Architecture/RecentAuthRouteTest.php` (改) | allowlist 追加 + **satisfier 集合 inventory を新設** | 3 |
| `tests/js/architecture/passkeys-import-isolation.test.ts` | `passkeys.ts` の import 隔離 | 3 |

`RecentAuthRouteTest` は現在 allowlist しか見ていない。指示にある
「satisfier 集合 (`RecentAuthState` / `SocialAuthController::completeStepUp` /
`ConfirmRecentAuthController`) を更新」を機械化するため、
**`RecentAuthState::confirm()` の呼び出し元集合を inventory 固定**するテストを同ファイルに追加する
(未登録の satisfier が生えたら fail)。

`PasskeyPackageContractTest` の検査項目 (露出不変条件の機械固定):

- `Passkeys::shouldRegisterRoutes()` が **false** (= パッケージ側 routes は登録されない)
- passkey named route の登録元が **Fortify 側の routes ファイル**であること
- `fortify-options.passkeys.confirmPassword` が **false** として解決されること
  (config cache 経由でも同じ値になる = `Features::passkeys([...])` の副作用が取り込まれる)
- `Passkeys::passkeyModel()` が `App\Models\Passkey`、`Passkeys::userModel()` が `App\Models\User`
- **binder の最終解決系がアプリ実装であること** (boot 順序の後勝ちを固定)
- 他人の passkey id / 不在 id はともに **404** (403 で存在を漏らさない)

---

## 5. TODO 分割の判断

### 結論: **2 TODO** (依存順序あり)

| TODO | 台帳 | 内容 | 独立性 |
|------|------|------|--------|
| **T-α** | `auth-sso-social` | 施策 2 (EmailTrustPolicy seam) | **完全独立**。先行でも後行でもよい |
| **T-β** | `auth-login-method-retention` + `auth-passkey` | 施策 1 + 施策 3 | T-α と独立。内部で 1↔3 が相互依存 |

### なぜ施策 1 と 3 を分けないか

**`EnsureLoginMethodRemains` は、単独で出すと保護対象 route が 1 本も無い死んだコードになる。**

現在の aicue に「ログイン手段を減らす route」は**存在しない**:

- SSO 連携解除 route は無い (`Settings/Security.svelte` は「連携済み」バッジを出すだけで解除導線が無い)
- passkey 削除 route は無い (施策 3 で初めて生える)
- `settings.account.destroy` はアカウント除去であり関門の対象外 (免除)
- `user-password.update` は `current_password` 必須の変更であり除去ではない

AGENTS.md 思考原則 2「今必要なものだけ作る (オーバーエンジニアリング禁止)」および
禁止事項 1「テストなしの実装完了報告」に照らすと、
**関門とその最初の被保護 route は同じ TODO でしか green にできない**。
指示書自身も「passkey 削除経路が `EnsureLoginMethodRemains` を通ることを Feature テストで固定」と
要求しており、これは 1 TODO 内でのみ満たせる。

台帳 `auth-login-method-retention` の boundary も
「EnsureLoginMethodRemains middleware **とその適用** (パスワード削除・SSO 連携解除・passkey 削除経路)」と
**適用まで含めて 1 単位**と定義している。

### 却下した分割案

**案 X: 台帳 3 件 = 3 TODO (指示のデフォルト)**
→ 施策 1 が保護対象ゼロで着地する。`LoginMethodRemovalRouteTest` の inventory も空になり
guard としての意味を持たない。却下。

**案 Y: passkey を server / UI で 2 分割**
→ server だけ先行させると、UI から到達できない認証 endpoint が main に一定期間残る。
未認証 route (`/passkeys/login/*`) を含むため、到達導線が無いまま開けるのは筋が悪い。
機能としても UI が本体 (現場作業者が使えて初めて使命に効く)。却下。

**案 Z: 施策 1 で SSO 連携解除 route も新設して独立させる**
→ 連携解除は**プロダクト判断を伴う新機能**であり、
台帳 `auth-sso-social` の boundary にも `auth-login-method-retention` の
「今回実装せよ」にも入っていない。スコープ膨張。却下
(ただし `LoginMethodRemovalRouteTest` の候補判定には**将来 route が生えたら捕まる**形で組み込む)。

### ロールバック単位

- **T-α**: 単独 revert 可。config 追加 + 新規クラス + テストのみで、
  google の挙動は不変 (`email_verified_at` は従来どおり付く) なのでデータ影響ゼロ
- **T-β**: `config/fortify.php` の `Features::passkeys()` **1 行が実質的なキルスイッチ**。
  外せば passkey route が消え、`LoginMethodInventory` も
  (feature flag 連動により) passkey を手段として数えなくなり、
  UI も `canManagePasskeys` 相当の Inertia prop で隠れる。
  `passkeys` テーブルは残るが未参照になるだけで害はない
  (AGENTS.md 禁止事項 3 により migration の巻き戻しはエージェント判断で行わない)。
  施策 1 部分 (SSO password null 化) は passkey とは独立に revert 可能だが、
  revert すると `hasPassword()` の嘘が戻る点に注意

### 実装順序 (T-β 内部の PR 段階)

キルスイッチ off のまま main に入れられる段階と、有効化を伴う段階を分ける。

| 段階 | 内容 | feature flag | main 投入可否 |
|------|------|-------------|--------------|
| **P1** | (A) の前方修正 + `PasswordChanged` 購読 + `LoginMethodInventory` + `EnsureLoginMethodRemains` + `LoginMethodRemovalRouteTest` (候補は全て exempt 分類) + `PasswordConfirmMiddlewareAbsenceTest` | **off** | 可 (単独で green・単独で revert 可) |
| **P2** | migration 取り込み + `App\Models\Passkey` + Factory + `User implements PasskeyUser` | **off** | 可 (route は生えない。テーブルとモデルだけ) |
| **P3** | `Features::passkeys()` 有効化 + limiter | **on** | この時点で `LoginMethodRemovalRouteTest` が `passkey.destroy` 未分類で **fail する** (期待どおり) |
| **P4** | `PasskeyServiceProvider` (binder / Response / route 加工 / login 認可) + gate 群を green に戻す | on | 可 |
| **P5** | `ClearRecentAuthOnPasskeyChange` + `PasskeyVerified` satisfier + `RecentAuthRouteTest` 更新 | on | 可 |
| **P6** | フロント (`passkeys.ts` + `Settings/Security.svelte` + `Login.svelte` + js gate) | on | 可 |

AGENTS.md 思考原則 5「テストファースト。fail を確認してから実装に入る」に沿った順序である
(P3 で意図的に fail させ、P4 で埋める)。
main へのマージは T-β 全体が green になってから 1 度に行う (worktree 運用ルール)。

---

## 6. 制約・前提

- **DB**: PostgreSQL 18.4 (192.168.117.3) 利用可。`composer test` 実走可能
  (直近実測 2704 passed / 0 failed / 2 skipped)。テスト DB は worktree ごとに一意
  (`TestDatabaseEnv::pgsqlBaseDatabase`)
- **dev DB への破壊操作 (`migrate:fresh` 等) をエージェント判断で実行しない** (AGENTS.md 禁止事項 3)
- **PHPStan level 10** / **Pest + `RefreshDatabase` グローバル + `--parallel`** /
  個別 `DatabaseTransactions` 禁止 / テストデータは Factory 経由
- `resources/js` は **TypeScript 必須** (AGENTS.md 禁止事項 7)
- eslint `noInlineConfig: true` (T102) — inline `eslint-disable` 不可
- **PII**: `User` の `email` / `name` は CipherSweet 暗号化。
  vendor の `getPasskeyUsername()` / `getPasskeyDisplayName()` はこれらを平文で
  WebAuthn options に載せる (認証器 UI に表示されるため仕様上不可避)。
  challenge は session に入るが `SESSION_ENCRYPT=true` が
  `EnvExampleInvariantTest` で固定されているため保護される
- `EnvExampleInvariantTest` があるため `PASSKEYS_*` 系 env は `.env.example` への追記が必要
- **同一オリジン PWA** 前提なので RP ID は `APP_URL` のホストで足りる (cross-origin 考慮不要)
- **サポート対象ブラウザ**: `docs/supported-browsers.md` が「どのブラウザで何をどこまで保証しているか」の
  正本。passkey は端末・OS 依存が大きいため、保証範囲と非対応時のフォールバックを同文書へ追記する
  (AGENTS.md ドメイン固有規約 3)

---

## 7. スコープ外

- **Microsoft provider の追加** — 台帳 `auth-sso-social` の `agenda` 未解決 (プロダクト判断)
- **aigenba 形の id_token 署名検証 / `auth_time` 検証** — 同 `agenda` 未解決。
  `capability` は `fresh_auth_prompt_only` 据え置き
- **登録直後 passkey の satisfier 除外 (強化オプション)** — 2026-08-04 裁定で**明示的に見送り済み**
- **passkey を組織 2FA 準拠に算入すること** — 裁定 A の「再検討条件」であり、現時点では算入しない
- **SSO 連携解除 route の新設** — 台帳の boundary 外。スコープ膨張を避ける
- **`auth-passkey-hardening` (aigenba:T1108 の 4 施策)** — 別 feature として台帳が分離済み
- **admin (Filament) 面への passkey 適用** — `admin` guard は本設計の対象外
- **既存 SSO ユーザーのランダム password の遡及移行** — 安全な判別子が存在しないため行わない
  (§1-2 (A) に理由と残存リスクの射程を明記)

---

## 8. c2c 台帳へ差し戻す論点 (本設計では fail-closed 既定を置くに留める)

実装過程で、台帳に記録の無い未裁定論点を 1 件発見した。
aicue 側は fail-closed 既定で進めるが、家系横断の裁定として c2c へ戻すべきである。

### AG-新: passkey login と「ユーザー自身が有効化した TOTP」の関係

- **事実**: `laravel/passkeys` の `PasskeyLoginController::store()` は
  `$guard->login()` を直接呼び、Fortify の two-factor challenge を通らない
  (vendor v0.2.1 実測)
- **論点**: TOTP を有効化したユーザーが passkey 単独でログインできてよいか。
  「WebAuthn は user verification 必須なので単体で多要素」という業界標準の立場と、
  「passkey は 2FA 準拠に算入しない」という 2026-08-04 裁定 A の立場が衝突する
- **aicue の既定 (暫定)**: `Passkeys::authorizeLoginUsing()` で
  TOTP 有効ユーザーの passkey login を拒否 (assurance を下げない側 = fail-closed)。
  confirm / 管理は使える。closure 1 本なので裁定が出れば即反転できる
- **他リポジトリへの影響**: `auth-passkey` を implemented としている
  laravel-claude-template / aigenba / spirux も同じ vendor 挙動に晒されているはずで、
  各リポジトリでこの点がどう扱われているかの実査が要る
- **関連**: 裁定 A の再検討条件「パスキーが 2FA 準拠判定に算入される時」と地続きの論点

### 併せて台帳へ反映すべき事実訂正

`auth-passkey` の aicue note
「composer に laravel/passkeys なし (実査)」は **fortify 1.37 移行前の実査**であり、
現在は `laravel/fortify v1.37.2` の推移依存として `laravel/passkeys v0.2.1` が
`composer.lock` に入っている (実測)。
また **Fortify 1.37 自身が passkey route / controller / migration を第一級機能として同梱**しており、
「`PasskeyServiceProvider` を自前で持つ」形は 1.37 以降では
「vendor アダプタ」に縮退するのが自然である。
canonical v1 (aigenba) が Fortify 1.36 以前を前提にしているなら、
**t0 の再定義が家系全体で必要になる可能性がある**。
