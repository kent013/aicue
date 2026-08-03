Round 3 の対応内容は妥当です。残る問題は依存順と例外契約の表現です。

**施策別判定**

- A: **REQUEST_CHANGES**
- B: **REQUEST_CHANGES**
- C: **APPROVE**
- D: **APPROVE**
- E: **APPROVE**

**指摘**

- [Warning] 実装順ではAが先ですが、AのGatewayがBで新設する `PlanChangeFailedException` に依存します。記載順ではA単体を実装・テストできません。  
  修正案: `PlanChangeFailedException` の作成をAへ移すか、実装順を「例外 → Gateway → Service」に変更してください。

- [Warning] 「Gatewayがthrowするのは `PlanChangeFailedException` だけ」は、subscription欠落などの `Assert` が `InvalidArgumentException` をthrowする実装と矛盾します。  
  修正案: 契約を「想定される外部障害・remote shape異常は `PlanChangeFailedException` に統一」と限定し、前提違反・実装不備はfail-fastで外へ出る旨を明記してください。

- [Suggestion] `ChangePlanRequest` のコメントに「表示用currentPlanCode」と残っています。現在の送信元は `planChangeExpectedPlanCode` なので記述を更新してください。

- [Suggestion] 実装順の「例外3種」は現在2種です。また、null一致テストの「段3を通過」は「段5を通過」へ更新が必要です。

**全体判定**

- **CHANGES_REQUESTED**