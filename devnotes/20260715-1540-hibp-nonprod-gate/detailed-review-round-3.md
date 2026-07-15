## 施策1: APPROVE

fail-secure と責務分離は維持されています。

## 施策2: APPROVE

env・config とも差し替え前の値へ復元され、テスト隔離の問題は解消しています。

- [Suggestion] 設計書末尾に旧記述が残っています。`withAppEnv()` を `withPasswordPolicyAppEnv()`、`false へ復帰`を「元の値へ復元」に修正してください。実装方針への影響はありません。

## 施策3: APPROVE

HIBP URLのみを fake し、成功導線と不送出を同時に検証する構成は妥当です。回帰時には fake がリクエストを記録し、実通信なしで `assertNotSent()` が失敗します。

- [Suggestion] 要件にリセットPOSTも含むなら、同様のFeatureテストを追加するか、本設計の保証範囲を「登録POST」に明記してください。

## 全体判定: APPROVED

Critical／Warning は解消済みです。残件は設計書内の旧表記修正と、リセットPOSTの保証範囲確認のみです。