# 対応マトリクス: impl-review Round 2

## [Critical] passkey-only ユーザーが WebAuthn 非対応ブラウザで行き止まりになる

- 判断: **対応する**
- 根拠: 指摘のとおり。`canSatisfy` は **サーバ判定 = アカウントに手段があるか**であり、
  WebAuthn の feature detection は**クライアントにしか無い**。両者を突き合わせていなかったため、
  「password 欄も SSO ボタンも passkey ボタンも出ないのに `canSatisfy=true` なので
  回復案内も出ない」という**無言の行き止まり**が残っていた。
  これは今回 `canSatisfy` に `passkeyAvailable` を算入したことで**新たに作り込んだ**穴であり、
  Critical 相当という判断に同意する。
- 対応内容:
  - 両 UI に `executableHere = passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported)`
    を導出 (`$derived`) し、`canSatisfy=true && !executableHere` の分岐を新設
  - `RecentAuthModal.svelte`: `recent-auth-unsupported-here` に理由
    (「このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
      パスキーを登録した端末・ブラウザで開き直すと再認証できます」) を表示
  - `Auth/ConfirmRecentAuth.svelte`: `confirm-unsupported-here` に同じ理由 +
    **ログアウト導線** (guest としてパスワード再設定する既存の回復手順) を提示
    (全画面のほうは離脱できないと本当に詰むため、行動可能な CTA を必ず置く)
  - `canSatisfy` の意味は**アカウント側能力のまま**にした (指摘どおり)。
    サーバに端末能力を持ち込むと、リクエストごとに変わる値を session 判定に混ぜることになる
  - テスト:
    - `tests/js/pages/ConfirmRecentAuthPasskey.test.ts` に 3 本
      (非対応 → 理由 + ログアウト導線 / 対応 → 理由を出さずパスキー導線 /
       password があれば非対応でも理由を出さない)
    - `tests/js/pages/SettingsSecurityPasskey.test.ts` に 2 本 (モーダル側の同等ケース)
  - `docs/auth-security-mechanisms.md` §1 に「`canSatisfy` は端末能力ではない」旨と
    両 UI の導出式を明記

## [Warning] nested payload テストが空振りしうる (`not->toBe(...)` はキー不在でも通る)

- 判断: **対応する**
- 根拠: 指摘のとおり。`errors.credential.0` が存在しない場合も通ってしまい、
  「rules 段を通過した」証明にならない (本レポジトリが多用している空振り防止の作法にも反する)。
- 対応内容:
  - `assertJsonPath('errors.credential.0', 'Invalid credential format.')` で
    **ceremony デシリアライズ段の既知エラーを完全一致で固定** (実測で確認)
  - あわせて `assertJsonMissingValidationErrors(['name', 'credential.id', 'credential.rawId',
    'credential.type', 'credential.response'])` を追加し、rules 段を通過したことを直接表明

## [受容] 登録の実 HTTP 経路 / allowsLogin deny の実 HTTP 経路

- Round 2 で「受容可能」の判定を得たため方針を維持する。
