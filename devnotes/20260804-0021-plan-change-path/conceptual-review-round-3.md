全体判定: **CHANGES_REQUESTED**

Round 2 の主要指摘は適切に反映されています。案1の採用、案2・案3の却下、`create_prorations` の選択、ABA 対策の方向性は妥当です。ただし、冪等性について設計上の保証範囲と記述が一致していません。

## 1. 使命との整合性

[Suggestion] paid→paid の双方向変更に限定されており、North Star を阻害する quota の行き止まりを解消する改善として妥当です。

## 2. 禁止事項違反

[Suggestion] 明確な違反はありません。テスト層、Inertia 応答、CTA 活性、Webhook writer 一本化も規約に沿っています。

## 3. 実現可能性

[Warning] 「`create_prorations` なので `payment_action_required` は発生しない」「支払い処理が伴わない」は断定が強すぎます。Stripe は `create_prorations` の日割り項目が特定条件では即時請求されると定義しています。

修正提案: AI-CUE の同一請求間隔・同一通貨の price swap では次回請求になる、という前提に限定してください。Gateway テストでも `billing_cycle_anchor` など即時請求を誘発するパラメータを送らないことを固定します。

## 4. 期待効果の妥当性

[Suggestion] `accepted` とローカル projection の追随は区別できています。ただし Stripe 上では200時点で subscription update は適用済みなので、`applied` より `projection_synced` などの名称の方が正確です。

## 5. リスク

[Critical] 「反映前に同じプランをもう一度押しても同一 key に収束する」という不変条件は成立しません。

成功後は `/billing` へ redirect されます。その後 `/billing/plans` を再表示すると新しい `plan_change_token` が発行されます。Webhook 未到達なら画面には旧プランが表示されるため、同じ変更を再度押せますが、今度は別の idempotency key になります。

修正提案: 次のいずれかに設計を修正してください。

- 保証を「同一 render からの二重送信だけ」に限定し、反映待ち中の再操作について二重 proration が起きないことを別途保証する。
- Stripe の現在 price を mutation 前に確認し、既に対象 price なら no-op とする。
- 操作 token を反映完了まで保持し、再表示後も同じ論理操作に同じ token を使う。

少なくとも「成功→再表示→Webhook 未到達→同じ変更を再送」の Feature/Gateway テストが必要です。

[Warning] `Throwable` の一括変換は `TypeError` や実装バグまで利用者向け決済失敗に隠します。

修正提案: Stripe SDK/API/通信例外など、想定された外部障害だけを `PlanChangeFailedException` に変換してください。

## 6. スコープの適切さ

[Suggestion] Portal 用の商品同期機構や暫定経路を作らず、双方向 swap を直接実装する判断は最小です。現時点で過大とは判断しません。

## 7. 型安全性

[Suggestion] Gateway の `void` 契約と型付き例外への変換は PHPStan level 10 に適合できます。`plan_change_token` は FormRequest で ULID として検証し、raw string の組み立て箇所を Gateway 境界に限定する方針で問題ありません。