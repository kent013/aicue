## 施策 B-1: REQUEST_CHANGES

- [Warning] toast の3秒待機後に着地をさらに2秒待つため、「3秒以内に着地済み」という制御条件を判定できません。4秒目に着地しても H-a 支持へ誤分類されます。  
  修正案: 同一3秒ループ内で toast と着地を同時観測し、`landedWithinDeadline` を記録してください。toast失敗後は追加待機せず、その記録で分類します。

## 施策 C: REQUEST_CHANGES

- [Critical] in-flight ガードは並行実行しか防げず、画面状態を破棄した後に古い Promise が完了する競合を防げません。confirm/disable 成功後に旧 secret が state へ再格納され、直後の再有効化では `loadingEnrollmentAssets` により新規取得も拒否され得ます。  
  修正案: 世代番号を導入し、`resetEnrollmentAssets()` で世代を更新。取得開始時の世代と一致する場合だけ結果と loading 状態を反映してください。`AbortController` でも可ですが、世代番号の方が小規模です。
- [Warning] 上記 lifecycle 競合のテストがありません。  
  修正案: fetch 保留中に confirm 成功または disable 成功を発火し、その後 fetch を解決しても setup key が再格納されないケースを追加してください。

## 施策 A-1 / A-2: REQUEST_CHANGES

- [Warning] component 単体テストでは、旧 layout の `ToastContainer.onDestroy(clearToasts)` が新 layout の flash toast を後から消す遷移順序を検証できません。B-1 は AppLayout→AppLayout のみで、AppLayout→GuestLayout/AuthLayout 境界を覆いません。B-1 が pass して B-2 を見送った場合、GuestLayout追加の目的が未保証になります。  
  修正案: 認証済み画面から GuestLayout または AuthLayout へ flash 付きで遷移する Browser テストを追加し、その結果も B-2 の適用条件に含めてください。

## 施策 B-2: APPROVE

設計自体は妥当です。ただし適用条件を「B-1 fail」だけでなく、上記の cross-layout Browser テストが制御条件付きで fail した場合にも拡張してください。

## 全体判定

**CHANGES_REQUESTED**

残件は、いずれも非同期処理・layout破棄順序という同じ境界問題です。閾値調整ではなく、同一時間窓での観測、非同期結果の世代管理、cross-layout E2E 固定で解消できます。