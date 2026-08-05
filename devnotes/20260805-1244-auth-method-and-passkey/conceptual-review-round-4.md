全体判定: **CHANGES_REQUESTED**

投影評価の欠陥は解消されています。ただし、同時削除によるTOCTOU競合が残っています。これはロックアウト防止不変条件そのものなので、概念設計段階で解決が必要です。

## 1. 使命との整合性

[Suggestion] 変更なし。現場での認証摩擦軽減とロックアウト防止はNorth Starへ直接貢献します。

## 2. セキュリティ不変条件

[Critical] `remainingAfter()` の確認と実際の削除が原子的ではありません。

passkeyが2件あるユーザーから、異なるpasskeyを同時に削除すると、次が起こり得ます。

1. リクエストAはBが残ると判定
2. リクエストBはAが残ると判定
3. 両方が削除
4. 残存passkeyが0件

password/socialがなければロックアウトします。正しい投影でも、middleware確認とvendor controllerの削除が別トランザクションなら防げません。

修正提案: `EnsureLoginMethodRemains` の確認から削除完了までを、共通の直列化境界に入れてください。現スコープなら以下が自然です。

- middlewareが`DB::transaction()`内で対象Userを`lockForUpdate()`する
- ロック取得後に`remainingAfter()`を再評価する
- 同じトランザクション内で`$next($request)`を実行し、vendor削除まで完了させる
- 将来のpassword削除・SSO解除にも同じUser行ロック規約を適用する
- ロック取得順序をUser → credentialに固定する

Feature/統合テストには「passkey 2件を並行削除しても、片方だけ成功し最低1件残る」を追加してください。並行テストが難しい場合でも、ロック規約のArchitecture inventoryと競合を再現する統合テストが必要です。

## 3. 実現可能性

[Warning] middlewareでトランザクションを張る場合、Response生成までトランザクション内に入るかを詳細設計で固定してください。

修正提案: `$next()`が返すResponsableの変換時点、`PasskeyDeleted`イベント、recent-auth clear、session更新の順序を確認し、DBロールバック時に成功flashだけが残らないことをテストしてください。

## 4. 期待効果

[Suggestion] 投影後評価により、単一passkey削除のロックアウト防止という主張は妥当になりました。並行操作も直列化すれば期待効果が成立します。

## 5. リスク

[Warning] `LoginMethodRemoval` の列挙には`allPasskeys()`が示されていませんが、TOTP不変条件テストで使用されています。

修正提案: `none/passkey/allPasskeys/social/password`を正式な閉じたvariantとして定義するか、TOTP不変条件専用に「passkeyを除外した集合」を表現できる型を定義してください。文字列種別やnullable IDへの縮退は避けるべきです。

## 6. スコープ

[Suggestion] 競合対策はスコープ膨張ではありません。`EnsureLoginMethodRemains`が保証を果たすために必要な最小要件です。

## 7. 型安全性

[Suggestion] `LoginMethodRemoval`と`LoginMethodSet`による表現、policyとcredential集合の責務分離は妥当です。詳細設計では、除去対象passkeyが対象Userに属することをDTO生成前にbinderで404確定してください。

## 8. TODO分割

[Warning] 直列化境界はS1ではなく、実際の削除routeが生えるS3で完成させる必要があります。

修正提案: S3へ以下を明記してください。

- User行ロックを伴う原子的な投影評価
- vendor削除まで同一トランザクション
- 並行削除テスト
- 将来の除去routeが同じロック規約へ登録されるArchitecture gate

これを除けば、S3を一つのgreen commitにまとめる判断は妥当です。承認に必要なのは、投影評価と削除を直列化するトランザクション・ロック境界の追加です。