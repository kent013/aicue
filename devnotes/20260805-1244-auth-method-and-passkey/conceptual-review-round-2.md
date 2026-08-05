全体判定: **CHANGES_REQUESTED**

Round 1 の主要論点はかなり整理されていますが、ログイン手段 inventory と passkey confirm の2点に、不変条件との新たな矛盾があります。

## 1. 使命との整合性

[Suggestion] passkey 導入、非対応端末でのフォールバック、理由を伴うエラー表示は North Star と整合しています。

一方、TOTP ユーザーにはログイン画面で passkey を提示した後、ceremony 完了後に拒否するため、操作負荷は残ります。暫定措置としては許容できますが、「パスキーでログインできる」と誤認させない文言が必要です。

## 2. 禁止事項・セキュリティ不変条件

[Critical] `LoginMethodInventory` の passkey 判定が、追加された TOTP 制約を反映していません。

現在の定義では次を満たすと passkey を「ログイン可能」と数えます。

```text
passkey 行あり AND Features::passkeys() 有効
```

しかし TOTP confirmed ユーザーは `authorizeLoginUsing()` によりログインを拒否されます。したがって、設計自身の「今この瞬間使える手段」という定義と矛盾します。

修正提案: passkey の可用条件を実際の login authorization と共通化してください。

```text
passkey 行あり
AND feature 有効
AND passkey login policy が当該ユーザーを許可
```

closure にロジックを直接書かず、`PasskeyLoginPolicy` のような共通判定を inventory と `authorizeLoginUsing()` から利用する方が安全です。

[Critical] `(F)` の対策は「汚染を害にしない」だけで、既存不変条件の「書かない」を満たしていません。

`password.confirm` route が0本でも、`auth.password_confirmed_at` は実際には書かれます。また、route middleware inventory は、将来このセッション値を直接参照するコードやvendor内部の利用を検出できません。

修正提案: passkey confirm 応答処理など、controller実行後の確実な地点で `auth.password_confirmed_at` を消去し、次をFeatureテストで固定してください。

- passkey confirm後も `auth.password_confirmed_at` が存在しない
- `recent_auth_method === 'passkey'`
- password confirm経路だけが `auth.password_confirmed_at` を設定できる、または本アプリでは常に未設定

`PasswordConfirmMiddlewareAbsenceTest` は追加防御として残せますが、単独では是正になりません。

## 3. 実現可能性

[Warning] `Passkeys::authorizeLoginUsing()` のclosureが、TOTP状態を判定できるユーザーまたはpasskeyを引数として受け取れることを実装前にvendor contract gateで固定する必要があります。

修正提案: closureの引数型、呼び出し時点、拒否時の例外・HTTP応答を `PasskeyPackageContractTest` の対象にしてください。拒否応答がWebAuthn credentialの存在やTOTP状態を第三者へ区別可能にしないことも確認が必要です。

[Warning] config cache対応の記述が、通常boot時の設定値確認に留まっています。

修正提案: 「config cache経由でも同じ」と主張するなら、実際にキャッシュ済み設定から起動する隔離テストを用意してください。route cacheについても、binder、middleware後付け、Fortify由来routeの一意性をキャッシュ済み状態で検証する必要があります。

## 4. 期待効果の妥当性

[Warning] 「SSO登録ユーザーに通らないパスワード欄を出さなくなる」は、新規ユーザーにしか成立しません。legacy SSOユーザーでは現象が残ります。

修正提案: 期待効果を「変更後に新規登録されたSSOユーザー」に限定してください。既存ユーザーについては、件数確認だけでなく「誤表示が残る既知制約」として運用文書に明記すべきです。

## 5. リスク

[Warning] 遡及移行をしない判断自体は妥当です。一括NULL化は実パスワードを消す可能性があり、採用できません。

ただし「残存リスクはSSO解除route追加時に初めて実害化する」という主張は狭すぎます。既に以下の実害が残ります。

- recent-authで利用不能なpassword入力を提示する
- `canSatisfy`が誤ってtrueになる
- LoginMethodInventoryが実在しないpasswordを数える

ロックアウトが今回の`passkey.destroy`では起きない、という限定的な論証は成立します。legacy問題全体が実害化しない、とはいえません。

修正提案: 次の2つを分けて記述してください。

- `passkey.destroy`によるロックアウトリスク: 現スコープでは発生しない
- legacy passwordによる既存の誤判定・誤UI: 未解消の既知制約として残る

件数確認SQLも「SSO登録者数」ではなく「要調査候補数」に過ぎないと明記してください。

## 6. スコープの適切さ

[Suggestion] TOTPユーザーのpasskey login拒否は、暫定的なfail-closed方針として合理的です。FortifyのTOTP challengeへpasskey認証結果を安全に接続する実装は、セッション状態機械の変更を伴うため、今回無理に導入する必要はありません。

ただし「TOTPを有効化したユーザーは必ずpasswordまたはSSOを持つ」は現在コードに依存した事実です。Architecture/Featureテストで保証するか、断定を弱めるべきです。

## 7. 型安全性

[Warning] `LoginMethodInventory` と `authorizeLoginUsing()` で別々に可用性条件を書くと、再び仕様が乖離します。

修正提案: 型付きの共通policyを置き、少なくとも以下を一箇所に集約してください。

- feature有効性
- TOTP confirmed状態
- passkey件数
- 将来の台帳裁定による反転条件

`App\Models\Passkey`、binder closure、`PasskeyUser`実装の方針はPHPStan level 10に向けて概ね妥当です。

## 8. TODO・PR分割

[Warning] P1〜P6は「PR段階」ではなく、同一worktree内の実装・コミット段階です。

P3が意図的にredになること自体はテストファーストと両立します。しかしP3単独をPRとして提出・mainへ投入することは、全green規約と両立しません。

修正提案:

- 表題を「T-β worktree内の実装段階」へ変更
- P3は観測用の一時red commit、またはコミット前のred確認とする
- レビュー・main投入単位はP1〜P6完了後のgreenなT-β全体と明記する
- P4/P5で安全不変条件が未完成の間はfeatureをoffに保ち、最後に有効化する方が履歴上も安全

`ConfirmedEmailTrustPolicy` の名称維持は、台帳との追従性を優先する判断として許容できます。ただし、`ConfirmedEmailTrustPolicy` が無条件にtrueを返すなら、Googleについて「provider単位で常に2条件を満たす」という前提を契約テストまたは根拠文書で固定する必要があります。

承認に必要な修正は主に、TOTP制約を含めたpasskey可用性判定の共通化、`auth.password_confirmed_at`汚染の実除去、legacy SSOの残存実害の正確な記述、P1〜P6の呼称・feature有効化順序の修正です。