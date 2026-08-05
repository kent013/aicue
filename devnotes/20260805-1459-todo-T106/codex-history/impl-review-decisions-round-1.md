# 対応マトリクス: impl-review Round 1

## [Critical] recent-auth/status に passkey satisfier が含まれていない (passkey-only ユーザーが stale から回復できない)

- 判断: **対応する**
- 根拠: 指摘のとおり実害がある。`canSatisfy = passwordSet || providers` のままだと、
  passkey しか持たないユーザー (SSO 未連携 + password なし) が
  - インラインモーダル: 「再認証手段が設定されていません」+ パスワードリセット導線 (踏破不能)
  - 全画面 confirm: 同上 + ログアウト誘導
  という **行き止まり**に落ちる。パスキーは実際に satisfier として成立するのに、
  UI 判定だけが古い契約に取り残されていた。
  「画面ごとに判定を持たせない (サーバの status を単一の源にする)」という指摘の原則も正しい。
- 対応内容:
  - `RecentAuthStatusDto` / `RecentAuthStatusResource` に `passkeyAvailable` を追加
  - `ConfirmRecentAuthController::buildStatus()` が
    `Features::enabled(Features::passkeys()) && $user->passkeys()->exists()` で算出し、
    **`canSatisfy` に算入**する (feature off では route ごと消えるため fail-closed で false)
  - `show()` (Inertia prop) にも `passkeyAvailable` を渡す
  - `resources/js/lib/recent-auth.ts` の `RecentAuthStatus` に `passkeyAvailable` を追加
  - `RecentAuthModal` の `passkeyAvailable` prop を **status 由来**に切り替え
    (Security page が `passkeys.length > 0` から手渡ししていたのをやめた)
  - **全画面 confirm 画面 (`Auth/ConfirmRecentAuth.svelte`) にもパスキー導線を追加**。
    こちらは 302 fallback 着地で元 URL がサーバの `url.intended` にしか無いため、
    fetch ではなく **Inertia `router.post('/passkeys/confirm')`** で送り
    `PasskeyConfirmationResponse` の `redirect()->intended()` 分岐に乗せる
    (そのために `confirmPasskeyCredential()` = ceremony のみ実行して送信しない export を追加)
  - テスト: `tests/Feature/Auth/RecentAuthTest.php` に 5 本
    (passkeyAvailable=true / passkey しか無くても canSatisfy=true / TOTP 有効でも再認証には使える /
     feature off で false かつ canSatisfy=false / confirm 画面 prop)、
    `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` 5 本、
    `tests/js/pages/SettingsSecurityPasskey.test.ts` に status 由来の on/off 2 本

## [Critical] passkey 登録の positive path テストが無い / payload shape が設計と食い違う

- 判断: **対応する** (ただし shape は実装が正しく、**設計書のほうが誤り**)
- 根拠: vendor の `Laravel\Passkeys\Http\Requests\PasskeyRegistrationRequest::rules()` は
  `credential` (array) + `credential.id` / `credential.rawId` / `credential.type` /
  `credential.response` を要求する **nested** 形。設計書の `{ name, ...credential }`
  (flat 展開) だと `credential` が欠落して validation で落ちる。
  実装は `{ name, credential }` で正しいが、**その正しさを固定するテストが無かった**のが
  指摘の本体であり、これは正当。
- 対応内容:
  - サーバ側: `tests/Feature/Auth/PasskeyRouteAccessTest.php` に 2 本
    (flat 形は `credential` の validation error / nested 形は rules を通過して
     ceremony 検証まで進む) を追加し **vendor 契約を pin**
  - client 側: `tests/js/pages/SettingsSecurityPasskey.test.ts` に登録 positive path 4 本
    (`router.post('/user/passkeys', { name, credential })` の payload 固定 /
     stale なら ceremony を開始しない / cancel は騒がない / failed はトースト + POST しない)
  - ceremony 自体はラッパをモックし、送信 payload の shape だけを固定する
    (実 ceremony は仮想認証器が Chromium 限定で iOS Safari を再現できないため自動化しない方針。
     `docs/supported-browsers.md` に明記済み)

## [Critical] `SecurityEventType::PasswordChanged` の記録にテストが無い

- 判断: **対応する**
- 根拠: 監査経路も「テストなしの実装完了」に当たる (禁止事項 1)。
- 対応内容: `tests/Feature/Auth/PasswordUpdateSessionInvalidationTest.php` に 2 本
  (PUT /user/password 成功で `security_audit_events` に `password_changed` が入る /
   current_password 不一致の失敗時は記録しない = fail-closed)

## [Warning] `createPasskeyCredential()` の payload shape 検証が呼び出し側任せ

- 判断: **対応する** (上記 Critical 2 の client 側テストで解消)

## [Warning] passkey login deny 経路が event 直 dispatch の近似でしか固定されていない

- 判断: **部分的に対応する**
- 根拠: `Passkeys::allowsLogin()` の deny を実 HTTP で踏むには、**成立する WebAuthn assertion**
  (署名検証を通る credential) が必要で、仮想認証器なしでは作れない。
  一方「login route が guest session に鮮度を残さない」という**不変条件そのもの**は
  ceremony 前段の失敗でも観測できる。
- 対応内容: `tests/Feature/Auth/PasskeyRouteAccessTest.php` に
  「`POST /passkeys/login` の失敗は guest session に `recent_auth_at` を残さない + guest のまま」
  を追加 (実 vendor controller + 実 listener 配線を通る統合境界)。
  deny 分岐そのものは `Passkeys::allowsLogin()` の直接検証
  (`PasskeyTwoFactorInteractionTest`) と、guest 文脈での `PasskeyVerified` 非 stamp
  (`RecentAuthMethodStampingTest`) の 2 本で挟む。

## [Warning] 登録経路での recent-auth 失効が実経路で保証されていない

- 判断: **見送る** (理由を明示して受容)
- 根拠: 登録の実 HTTP 経路は成立する attestation を要し自動化できない。
  失効そのものは listener の契約テストで固定済みで、削除側は実 HTTP 経路で通っている。
  `PasskeyRegistered` を dispatch するのは vendor の `StorePasskey` のみ (実装確認済み) で、
  アプリ側の責務は listener の配線に限られる。

## [Warning] docs (architecture / factories / supported-browsers / template-divergence) が diff に無い

- 判断: **反論する** (指摘は diff の切り出し漏れによる誤検出)
- 根拠: Round 1 に添付した diff は `app/ resources/ tests/ routes/ config/ database/ bootstrap/ .env.example`
  に絞っており `docs/` を含めていなかった。実際には 4 ファイルとも更新済み:
  - `docs/template-divergence.md` に **D13** (phantom password の前方修正) を追加
  - `docs/auth-security-mechanisms.md` に **§5 パスキー** / **§6 ログイン手段保持 guard** を追加
  - `docs/architecture.md` に `Passkey` モデル / `LoginMethodInventory` / `PasskeyLoginPolicy` を追加
  - `docs/factories.md` に `PasskeyFactory` を追加
  - `docs/supported-browsers.md` に「パスキーの保証範囲」節 + 実機受入確認の再確認条件を追加
- 対応内容: Round 2 で docs の diff を添付する。

## [Warning] テストの穴 (登録 positive / passkey-only stale / PasswordChanged)

- 判断: **対応する** (上記 3 Critical の対応で解消)
