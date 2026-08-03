# 対応マトリクス: design-review Round 3

Codex 判定: 施策 B **REQUEST_CHANGES** / 全体 **CHANGES_REQUESTED**（Warning 3 + Suggestion 1）。
施策 A は Round 2 で APPROVE 済み。

## [Warning] リセット payload に `password_confirmation` が明記されていない
- 判断: **反論する（ただし「成功 redirect + エラー不在の確認」は採用）**
- 根拠: 本リポジトリの `App\Actions\Fortify\ResetUserPassword::reset()` の rules は
  `['password' => ['required', 'string', Password::default()]]` のみで、
  docblock に「**確認入力 (confirmed) は使わない**（表示トグル + リセット導線 + SSO で代替）」と
  明記されている。画面 `resources/js/pages/Auth/ResetPassword.svelte` にも確認用フィールドが無い。
  `password_confirmation` を送るのは**存在しない仕様をテストに書き込む**ことになる。
- 対応内容: payload は `token` / `email` / `password` のままとし、
  Codex の有用な部分（結果検証の強化）を採用して
  `assertRedirect(route('login'))` + `assertSessionHasNoErrors()` を `hasPassword()` 検証の前に置く
  （`PasswordResetResponse` は login へ redirect + `success` flash）。反論の根拠も設計本文に残した。

## [Warning] ログアウト後に `Welcome` へ着地する契約がテストされていない
- 判断: **対応する**
- 根拠: 指摘のとおり。`Welcome.test.ts` は「Welcome を描画したとき」のリンクしか保証しない。
  B-2 は「ログアウトすると `/` に着地する」ことを前提にしているので、その前提自体を固定すべき。
- 対応内容: 回復テストの `post('/logout')` に `assertRedirect('/')` + `assertGuest()` を追加
  （Fortify 既定 `Fortify::redirects('logout', '/')` を裏取り済み。新規テストは増やさない）。

## [Warning] vitest 計画に旧ラベル「ログアウトしてパスワードを設定する」が残っている
- 判断: **対応する**
- 対応内容: `tests/js/pages/ConfirmRecentAuth.test.ts` の期待を「**ログアウトする**」に修正し、
  押下で `router.post("/logout")` が呼ばれることも assert する
  （`vi.mock("@inertiajs/svelte")` で router を差し替え。既存 `VerifyEmail.test.ts:5-10` と同作法）。

## [Suggestion] 「SSO 専用ユーザー」という呼称が不正確
- 判断: **対応する**
- 根拠: 実態は「password 未設定 かつ 利用可能な再認証 provider なし」という状態であり、
  provider が生きている通常の SSO ユーザー（`canSatisfy=true`）とは別物。
- 対応内容: テスト名・設計文言をその状態名に統一した。
