追加差分には修正が必要です。セキュリティ試験の方向性は正しいですが、文書に1点の事実誤認があります。

- `tests/Browser/InertiaHistoryRestoreAfterLogoutTest.php`
  - 判定: 概ねOK
  - 旧鍵との値比較だけでは「過去エントリが復号不能」の直接証明にはなりません。ただし、暗号化済み履歴の確認、旧鍵の変更、back時のPII瞬間露出監視を組み合わせており、挙動契約として十分強いです。
  - [Suggestion] 現在の `!== $keyBefore` は `null` でも成功します。「新しい鍵へ入れ替わる」という文書まで固定するなら、`historyKey !== null && historyKey !== oldKey` にしてください。
  - 「旧鍵が二度と手に入らない」はテストで証明できる範囲を超えます。「現在の履歴鍵が旧鍵から変更され、旧履歴が描画されない」が正確です。

- `app/Http/Responses/Fortify/LogoutResponse.php`
  - 判定: 要修正
  - [Warning] 「JSON 204 経路はBrowserテストの補助（経路Bの再現）にしか使われていない」は、追加したB1自身が経路Cの再現にもJSON 204を使うため事実と矛盾します。
  - 「Browserテストで経路B/Cのセッション失効状態を再現する補助にのみ使われる」等へ修正が必要です。
  - 3導線への更新自体は、編集対象内の事実誤認を直す限定的なdrift fixなので、本タスクに含めて問題ありません。

- `docs/supported-browsers.md`
  - 判定: OK
  - null判定を否定し、鍵の変更と最終的なPII非描画を観測する方針は妥当です。ただし「旧鍵が二度と手に入らない」という絶対表現は避けるのが安全です。

- `app/DataTransferObjects/Billing/QuotaStatusDto.php`
  - 判定: OK
  - PHPStanの `arrayValues.list` に従って `array_values()` を外す判断は正当です。コメントも将来の変更条件を十分固定しています。

Criticalはありません。上記LogoutResponseの文書矛盾は本タスクの主目的に直接関係するため、全体判定は **CHANGES_REQUESTED** です。