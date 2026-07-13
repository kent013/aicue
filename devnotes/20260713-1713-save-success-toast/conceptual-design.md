# 概念設計: save-success-toast

## 背景・課題

bug-hunt findings **F-M1 (Medium, ux)** + **F-L1 (Low)** への対応。

このアプリには稼働中の toast 機構がある。正本は **サーバ flash → toast 変換**:
`HandleInertiaRequests::share()` が `flash.{success,error,info,warning}` と一意な
`visitKey` を共有 → クライアントの `consumeFlash()`（`resources/js/lib/stores/flash-to-toast.ts`）が
`visitKey` で de-dup しながら `addToast()` する。`AppLayout` / `AuthLayout` の両テンプレートが
`ToastContainer` を mount し `consumeFlash` を呼ぶ。

**重要な設計事実**: `consumeFlash` は `success/error/info/warning` のみを toast 化し、
Fortify 既定の `status` キーは**意図的に gating（toast 化しない）**。この方針は
`tests/Feature/Auth/FortifyResponseTest.php` の doc と既存 3 クラス
（`EnumerationSafePasswordResetLinkResponse` / `TwoFactorDisabledResponse` /
`VerificationNotificationSentResponse` / `RecoveryCodesGeneratedResponse`）で確立済み。
これらは Fortify 既定の `back()->with('status', ...)` を **`success` キーへ寄せ替える**
カスタム Response を `FortifyServiceProvider` に bind して実現している。

### F-M1（フィードバック欠落）
以下 3 フォームは機能的に成功するが、Fortify 既定 Response が `status` キーで
flash するため toast が出ず、ユーザーが成否を判断できない（二重送信を誘発）:

| 操作 | route | 現在の Response（未 bind = Fortify 既定） | 症状 |
|------|-------|------|------|
| プロフィール更新 | `user-profile-information.update` | `ProfileInformationUpdatedResponse`（`status`） | toast なし |
| パスワード変更 | `user-password.update` | `PasswordUpdateResponse`（`status`） | toast なし |
| パスワードリセット | `password.update` | `PasswordResetResponse`（login へ redirect + `status`） | toast なし |

### F-L1（二重 toast）
リカバリコード再生成（`two-factor.regenerate-recovery-codes` = POST `/user/two-factor-recovery-codes`）
で同一操作に success toast が **2 つ**出る:
1. **サーバ flash**: `RecoveryCodesGeneratedResponse` が `back()->with('success', 'リカバリコードを再生成しました。')`
   → Inertia redirect → `AppLayout` の `consumeFlash` が toast 化。
2. **クライアント楽観 toast**: `Security.svelte` の `handleRegenerateSuccess()` が
   `addToast('success', 'リカバリコードを再生成しました。新しいコードを保管してください。')` を直接発火。

同一 POST 成功に対しサーバ flash と client の二重発火が起きている。

## 改善アイデア

**トースト機構の正本を「サーバ flash → toast」に一貫させる**。

### (1) F-M1: 3 操作に success flash を返すカスタム Fortify Response を追加・bind
既存パターン（`EnumerationSafePasswordResetLinkResponse` 等）と同型で、
`status` ではなく `success` キーへ寄せ替える 3 クラスを新設し `FortifyServiceProvider` で bind:

- `App\Http\Responses\Fortify\ProfileUpdatedResponse`
  → `ProfileInformationUpdatedResponse` contract。`back()->with('success', 'プロフィールを更新しました。')`
- `App\Http\Responses\Fortify\PasswordUpdatedResponse`
  → `PasswordUpdateResponse` contract。`back()->with('success', 'パスワードを変更しました。')`
- `App\Http\Responses\Fortify\PasswordResetResponse`
  → `PasswordResetResponse` contract（constructor に `$status` を取るため `bind`。
  login へ redirect + `->with('success', 'パスワードを変更しました。ログインしてください。')`）

いずれも **`wantsJson()` 分岐は Fortify 既定どおり JSON 200 を維持**（XHR/API 契約を壊さない）。
リセット後は未認証で login ページへ遷移するが、`AuthLayout` も `consumeFlash` を持つため
login 画面で success toast が出る。

### (2) F-L1: 再生成 toast の発火元を「サーバ flash」に一本化
`Security.svelte` の `handleRegenerateSuccess()` から
**クライアント側 `addToast('success', ...)` を削除**し、サーバ flash
（`RecoveryCodesGeneratedResponse` の `success`）を唯一の成功 toast 源とする。
保管を促す文言はサーバメッセージへ集約:
`RecoveryCodesGeneratedResponse::SUCCESS_MESSAGE` を
`'リカバリコードを再生成しました。新しいコードを保管してください。'` に更新。

クライアントは引き続き「旧コードの即時クリア → 新コード GET → パネルへ focus」を担い、
**GET 失敗時のみ** error toast（表示失敗＝再生成成功とは別事象）を出す。これは重複ではなく
別メッセージ（再生成は成功したが表示に失敗＝再取得導線あり）であり許容する。

## 期待効果

- **使命への貢献**: 「思考ゼロ・編集ゼロ」を掲げる本アプリで、設定変更の成否が
  即座に伝わることは現場作業者の操作不安・二重送信を減らす基礎 UX。フレームワーク
  （Fortify + 既存 flash→toast 正本）のレンジ内で自前機構を足さずに解決する。
- F-M1: profile/password/reset の 3 操作すべてで成功 toast が出る（二重送信抑止）。
- F-L1: 再生成 toast が happy path で 1 つに収束（二重解消）。
- toast 発火の正本が「サーバ flash」に一貫し、以後の操作追加も同じ型で拡張できる。

## 実装方針（概要）

| 変更 | 対象 |
|------|------|
| 新規 Response 3 クラス | `app/Http/Responses/Fortify/{ProfileUpdated,PasswordUpdated,PasswordReset}Response.php` |
| bind 追加 | `app/Providers/FortifyServiceProvider.php`（singleton 2 + bind 1） |
| メッセージ集約 | `app/Http/Responses/Fortify/RecoveryCodesGeneratedResponse.php`（`SUCCESS_MESSAGE`） |
| client toast 削除 | `resources/js/pages/Settings/Security.svelte`（`handleRegenerateSuccess` の `addToast('success')`） |
| Feature テスト | `tests/Feature/Auth/FortifyResponseTest.php`（3 操作の success flash + `wantsJson` JSON 契約） |
| vitest 更新 | `tests/js/pages/SettingsSecurity.test.ts`（再生成 happy path で client success toast を出さないこと） |

- `response()->json()` 直書きはしない（Fortify Response は `JsonResponse`／`RedirectResponse` を返す
  contract 実装で、AGENTS §禁止4 の対象外＝Fortify 既定と同じ形。DTO/JsonResource は不要）。
- flash-to-toast / toast store 自体は変更しない（正本は既に妥当）。

## 制約・前提

- Fortify Response contract の bind は `FortifyServiceProvider::register()` の既存ブロックに追加。
  `PasswordResetResponse` は constructor 引数 `$status` があるため **`bind`（非 singleton）**。
- profile/password update は `back()`（`/settings`）へ戻る → `AppLayout` の `consumeFlash` が発火。
  reset は login へ redirect → `AuthLayout` の `consumeFlash` が発火。両テンプレとも配線済み。
- `status` gating（flash-to-toast）は変更しない。既存の他 `status` 利用箇所に影響を与えない。
- DESIGN.md / Atomic Design: UI コンポーネントの新設・token 変更なし（既存 `ToastContainer` を流用）。

## スコープ外

- flash-to-toast / toast store のロジック変更（正本は妥当なため触らない）。
- 他の Fortify 操作（login/register/verify 等、既に success 化済み or 別 finding）。
- toast のデザイン・表示時間・アクセシビリティ改修。
- 2FA 有効化/無効化フローのメッセージ見直し（別スコープ）。
