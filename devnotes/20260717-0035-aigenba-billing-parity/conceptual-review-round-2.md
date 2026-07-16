全体判定: **CHANGES_REQUESTED**

Round 1 の3点は概ね閉じています。ただし、課金不変条件とロールバックに新たな Critical が残ります。

## 1. 使命との整合性

[Suggestion] North Star への位置づけと成功指標は適切です。決済を主目的として誇張せず、業務到達率・作業停止件数に限定できています。

## 2. 禁止事項・セキュリティ不変条件

[Critical] P6 が AGENTS.md の課金不変条件に抵触しています。

AGENTS.md は「チケットは reserve→commit/release の2フェーズ」を非交渉のセキュリティ不変条件としています。一方、本設計は「source 分割台帳 + reserve/commit を撤去して単一合計へ移行」と明記しています。aigenba の `TicketService` が単一合計でも予約を別概念として維持するのか、予約自体を廃止するのかが不明です。

修正提案: 「単一合計」は残高集計方式だけの変更とし、消費処理の reserve→commit/release は維持すると明記してください。aigenba が維持していない場合は、全面一致よりリポジトリのセキュリティ不変条件が優先され、意図的逸脱として扱えません。

## 3. 実現可能性・フェーズ順序

[Critical] P6 の rollback は現状では成立しません。

cutover 後に新 `TicketService` だけへ書き込み、旧台帳を「保持」するだけなら、旧台帳は直ちに古くなります。その状態でコードを revert すると、cutover 後の購入・消費・grant が失われた残高へ戻ります。物理削除しないことと、復帰可能であることは別です。

修正提案: 次のいずれかを設計に固定してください。

- P6 中は旧台帳にも互換イベントを書き、revert 可能性を保つ。
- revert 前に新会計から旧台帳へ差分を再同期する rollback migration/runbook を用意する。
- P6 は forward-fix のみと明記し、「revert で復帰可能」という主張を削除する。

思考原則の「後方互換の並走を残さない」との整合からは、差分再同期 runbook が最も自然です。

[Warning] P4 の「同一PR」は、backfill がゲートコードより先に本番適用されることを保証しません。

修正提案: `add columns/index → marker/grandfather backfill完了・件数検証 → gate code deploy` のデプロイ順序をDoDに固定してください。backfill失敗時にはゲートを反転しないことも必要です。

## 4. 期待効果

[Warning] grandfathered org の「自然収束」は保証されません。既に業務ルートへ到達できるため、ユーザーが再度 activate する契機がありません。

修正提案: 「自然に収束する」を削除し、収束は将来タスクまたは明示的な再申告導線による、と限定してください。濫用防止の主要効果は新規orgに限定されます。

## 5. 金銭・冪等リスク

[Warning] `signup_tickets_granted_at` を真実源にするだけでは、並行する free activate と paid webhook の競合を防げません。双方が未設定を読んで二重付与する可能性があります。

修正提案: org行の `lockForUpdate()`、または原子的な条件付き更新を起点として、marker更新とticket付与を同一transactionに置く不変条件を明記してください。free/paid競合テストもP1/P6へ追加してください。

## 6. スコープ

[Suggestion] 9フェーズへの分割は妥当です。ただし本文に「依存順の7フェーズ」と残っているため、9フェーズへ修正が必要です。

また制約欄の「既存ユーザーを締め出さない（P3移行）」は **P4移行** の誤記です。

## 7. 型安全性

[Suggestion] `PlanCode` backed enum、`EffectivePlan` / `SubscriptionSnapshot` DTOへの集約、Inertia propsのDTO化で妥当です。grandfathered状態もnullable値の組合せを各所で解釈せず、`EffectivePlan` variantとして閉じ込める方針を維持してください。

重点3点のうち、順序・grandfathering・signup grantの履歴移行は閉じています。承認を妨げる残件は、**reserve→commit/release不変条件との衝突**と、**P6 rollbackが残高を保全できないこと**です。