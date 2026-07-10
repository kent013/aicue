# Phase 1: 認証・アカウント(M2)実装メモ

> **ステータス: 完了(2026-06-11)**。Pest 27 / vitest 152 / PHPStan lv10 / Pint / build 全 green。
> 実装内容と計画からの変更点:
> - CipherSweet: users.email/name 暗号化 + blind index、email 一意性は blind_indexes の
>   (indexable_type,name,value) unique + 登録時 whereBlind 明示チェックの 2 層
> - EncryptedUserProvider(encrypted-eloquent driver)で Fortify ログインを blind index 解決
> - パスワード確認欄(confirmed)は廃止(ドナー aigenba T655 方式)。ポリシー 12 字+大小数字、
>   uncompromised は production のみ
> - SSO: GET anchor 開始(CSP 対応)/ login intent は自動登録しない / register intent は
>   メール一致既存ユーザーへ自動リンクしない(乗っ取り防止)/ link intent は他ユーザー連携済みを拒否
> - SecurityAuditEvent + SecurityEventRecorder + RecordSecurityEvent subscriber(監査 Layer 2)
> - アカウント削除は password.confirm(step-up)必須。SocialAccount cascade、削除イベントは
>   user_id nullOnDelete で残置
> - 旧アドレス通知(EmailChangedSecurityNotification)は on-demand Notification
> - recent-auth は Laravel 標準 password.confirm を採用(SSO-only ユーザーの再 SSO 確認は
>   将来課題として保留 — donor の RequireRecentAuth 移植は必要になった時点で)
> - Inertia v3.3 は runes ベース($form store ではなく form 直参照)
> - フィードバック反映済み: 規約同意はボタン非活性にしない / SSO は GET anchor /
>   Checkbox atom の行揃え
> - passkeys 機能は無効化(WebAuthn フロントエンドとセットで将来導入)

> ドナー: aigenba(spirux のアカウント削除・画面シンプルさを取り込み)。
> 決定参照: Q10(SocialAccount)、Q11(メール変更=Fortify+旧宛先通知)、
> 14-donor-auth-ux-feedback.md(3 件のフィードバック反映)。

## スコープ

1. **Fortify**: 登録 / ログイン / ログアウト / パスワードリセット / メール検証 / 2FA(TOTP+リカバリ)
2. **CipherSweet**: users.email / users.name 暗号化 + blind index(登録前に migration を作る)
3. **Socialite(Google)**: SSO ログイン / 登録 / アカウントリンク(SocialAccount モデル)。
   プロバイダは config 駆動で追加可能に
4. **step-up 再認証**(recent-auth middleware + 確認画面)
5. **メール変更**: Fortify レンジ + 旧アドレスへセキュリティ通知(新アドレス非開示)
6. **アカウント削除**(カスケード、spirux 型)
7. **画面**(Svelte, AuthLayout 使用): Login / Register / ForgotPassword / ResetPassword /
   VerifyEmail / TwoFactorChallenge / TwoFactor 設定(Settings 内)/ ConfirmRecentAuth
8. **AuditLog 3 層のうち SecurityAuditEvent**(認証イベント記録)を先行導入(11 §4)

## フィードバック反映(必須)

- Register: 規約同意は Checkbox atom(error 表示内蔵)。**ボタンは disabled にしない**。
  押下時にクライアント検証 + サーバ `accepted` バリデーション
- SSO 開始導線は **GET の anchor**(Button anchor モード)。form POST にしない
  (CSP form-action がリダイレクト先に適用される問題の回避)

## 移植元ファイル(aigenba)

- app/Actions/Fortify/*(CreateNewUser / UpdateUserProfileInformation 等)
- app/Notifications/User/EmailChangedSecurityNotification.php
- SSO: app/Services/Sso/ 系 + UserSocialAccount(→ SocialAccount に改名)
- RequireRecentAuth middleware + RecentAuthRouteTest
- tests/Feature/Auth/ 一式(EmailChangeOldAddressNotificationTest 含む)
- spirux: アカウント削除 + SecurityAuditEvent + RecordSecurityEvent listener

## 注意

- SESSION_SECURE_COOKIE=true が .env にあるため、http://localhost での手元検証時は
  .env で false にする(dev 手順を README/docs に明記)
- Personal Organization 自動生成は Phase 2 で配線(Registered イベントの listener フックだけ用意)
