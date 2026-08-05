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
