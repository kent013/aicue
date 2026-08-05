全体判定: **CHANGES_REQUESTED**

Round 2 の Critical は解消されています。ただし、`passkey.destroy` の削除後状態を判定する設計に新たな Critical があります。これは詳細設計ではなく、`EnsureLoginMethodRemains` の成立性に関わるため概念設計で解決が必要です。

## 1. 使命との整合性

[Suggestion] passkey、端末非対応時の代替導線、TOTPユーザーへの事前説明はいずれもNorth Starと整合しています。効果の主張もlegacyユーザーを除外する形に修正され、妥当です。

## 2. 禁止事項・セキュリティ不変条件

[Critical] `passkey.destroy` の判定が、削除前のpasskeyを残存手段として数える可能性があります。

現在の設計では、`PasskeyLoginPolicy::allowsLogin()` が次を含みます。

```text
$user->passkeys()->exists()
```

`EnsureLoginMethodRemains` がDELETE実行前に通常のinventoryを取得すると、削除対象自身が存在するためpasskeyを1手段として数えます。唯一のpasskeyしかなく、passwordもsocialもないユーザーでも削除を許可しかねません。

修正提案: inventoryは必ず「操作成功後の投影状態」を評価してください。例えば以下のいずれかです。

- `LoginMethodInventory::afterRemovingPasskey(User $user, Passkey $target)`
- 除外対象を受け取る型付きquery/DTO
- passkey件数を `whereKeyNot($target->getKey())->exists()` で判定

あわせて、`PasskeyLoginPolicy` からcredential存在確認を外し、「ユーザー属性・feature状態上、passkey loginを許容するか」だけを担当させる方が責務が明確です。

```text
Inventory:
  残存credentialあり AND policyが許可

authorizeLoginUsing:
  検証済みcredentialあり（vendor保証）AND policyが許可
```

Featureテストには最低限、次を含める必要があります。

- password/socialなし、passkey 1件: 削除拒否
- password/socialなし、passkey 2件: 1件削除可能
- TOTP confirmedでpasskey 2件: passkeyはログイン手段として数えず削除判定
- 削除対象が他人のpasskey: inventory評価より前に404

## 3. 実現可能性

[Suggestion] `PasskeyConfirmationResponse::toResponse()` での実除去は成立します。通常のLaravel session lifecycleでは、`toResponse()` はcontroller実行後かつsession保存前に評価されるため、`forget()` の結果が保存されます。

詳細設計・テストでは、正常系だけでなくResponse生成後のsession assertionを行えば十分です。`toResponse()` 自身が例外を投げる異常系まで概念設計で扱う必要はありません。

## 4. 期待効果の妥当性

[Suggestion] legacy SSOについて、ロックアウトと既存の誤UI・誤判定を分離した記述は妥当です。確認SQLを「要調査候補数」とした点も正確です。

## 5. リスク

[Warning] 「TOTPを有効化できる経路がpassword/SSOログイン済み」というテストだけでは、その手段が現在も残っていることまでは保証しません。

修正提案: 本設計では削除経路が限定されているため、次の不変条件として直接固定する方が明確です。

> TOTP confirmedユーザーは、passkeyを除外しても最低1つのログイン可能手段を持つ。

将来password削除やSSO解除が追加された場合も、このFeatureテストが回帰を捕捉できます。

## 6. スコープの適切さ

[Suggestion] TOTPとpasskeyの最終裁定をc2cへ戻し、今回はfail-closedに留める判断は適切です。暫定対応をFortifyの独自2FAフロー実装まで拡大しない点も妥当です。

## 7. 型安全性

[Warning] `PasskeyLoginPolicy` にcredential存在確認まで持たせると、削除後投影との相性が悪く、責務も曖昧になります。

修正提案: policyを純粋な可否判定にし、credential集合の評価はinventoryに残してください。Architectureテストによる呼び出し元固定に加え、同じユーザー状態でinventoryとlogin authorizationが一致するFeatureテストも必要です。ソース構造だけでは意味上の一致までは保証できません。

## 8. TODO分割

[Suggestion] S3を分割せず、feature有効化・binder・middleware・Response・login policy・gateを同一green commitに束ねる判断は妥当です。「危険な中間commitを作らない」という目的に合っています。

S3内でredを確認し、greenになってからコミットする運用もworktree規約と両立します。

承認までに必要なのは、`EnsureLoginMethodRemains`を削除後の投影状態で判定する設計への修正です。Responseでのsession汚染除去、policy集約、S3の一括化については成立しています。