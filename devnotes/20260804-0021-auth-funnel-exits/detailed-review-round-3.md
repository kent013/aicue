施策Bは **REQUEST_CHANGES**、全体は **CHANGES_REQUESTED** です。回復経路の方向性は成立しますが、テスト仕様に小さくない不整合が残っています。

## 指摘

- [Warning] パスワードリセットpayloadに`password_confirmation`が明記されていません。
  - 修正案: `token`、`email`、`password`、`password_confirmation`を明示し、レスポンスの成功redirectとsession error不在を確認してから`hasPassword()`を検証してください。
  - CipherSweetは`EncryptedUserProvider`、PasswordPolicyは`Http::fake()`で対応できており、その他の構成は妥当です。

- [Warning] ログアウト後に`Welcome`へ着地する契約がテストされていません。
  - `Welcome.test.ts`が保証するのは、Welcomeを描画した場合のログインリンクだけです。
  - 修正案: 回復テストの`post('/logout')`に`assertRedirect('/')`と認証解除のassertを追加してください。新規テストを増やす必要はありません。

- [Warning] Vitest計画に旧ラベル「ログアウトしてパスワードを設定する」が残っています。
  - 修正案: `tests/js/pages/ConfirmRecentAuth.test.ts`の期待を「ログアウトする」に修正し、クリック時の`router.post('/logout')`も検証してください。

- [Suggestion] `ssoOnly()`かつsocial accountなしのユーザーを「SSO専用ユーザー」と呼ぶのは不正確です。実態は「password未設定かつ利用可能な再認証providerなし」です。テスト名と設計文言をこの状態名に寄せると、正常なSSO再認証経路との混同を防げます。

## 判断

CTAを「ログアウトする」に変更し、後続手順を説明文へ移した判断は妥当です。実際の1クリックの作用とラベルが一致しています。

上記3つのWarningを反映すれば、回復経路は「ログアウト → Welcome → Login → reset → password取得 → `canSatisfy=true`」まで十分固定でき、**APPROVED**にできます。