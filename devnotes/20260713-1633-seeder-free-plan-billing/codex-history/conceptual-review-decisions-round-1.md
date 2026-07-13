# 対応マトリクス: conceptual-review Round 1

全体判定: **APPROVED** (Round 1)。Warning 3 件は詳細設計に反映する。

## [Warning] fake subscription の最小整合条件が粗い (観点3 実現可能性)
- 判断: 対応する
- 根拠: Cashier の `subscription('default')` が active/trialing を返すための最小カラム
  (type='default', stripe_id, stripe_status, quantity) を外すと seed が別の壊れ方をする。
- 対応内容: 詳細設計で、テストの `createFakeSubscription` helper と同じ生成経路
  (`$organization->subscriptions()->create([...])`) に寄せ、必要カラムを明示する。
  seeder の private メソッド `attachFakeActiveSubscription()` として切り出す。

## [Warning] 回帰テストが Free 偏重で有償側の不変条件を固定できていない (観点4)
- 判断: 対応する
- 根拠: 本設計は「Free ⇒ plan_code null」「有償 ⇒ plan_code=code かつ subscription あり」の
  両側を不変条件として扱うべき。
- 対応内容: ManualTestSeederTest を更新し、Free 組織 (plan_code null / subscription 無し) と
  有償組織 (plan_code=code / active subscription あり / hasActiveAccess true) の両方を検証する。

## [Warning] current base price 欠落の paid plan が seed 上 Free 扱いになる (観点5 リスク)
- 判断: 一部対応する (テストで前提を固定。seed の hard fail は見送り)
- 根拠: PlanSeeder が standard に base Price を bootstrap 投入する不変条件は既存テストで担保済み。
  seeder に「base Price 無しなら fail」を足すのは今回の Critical 修正の責務を超え over-engineering。
  ただし standard 側が「有償扱い (plan_code + subscription あり)」になる前提はテストで固定する。
- 対応内容: ManualTestSeederTest で standard 組織が plan_code='standard' かつ active subscription を
  持つことを明示検証し、有償判定経路 (currentPrice(Base)!==null) が期待どおり効くことを固定する。

## [Suggestion] /projects 以外の代表導線も回帰対象に (観点1)
- 判断: 対応する
- 根拠: 使命との接続を明確化。既存 RequireActiveSubscriptionMiddlewareTest は /app もカバー済み。
- 対応内容: seeder 回帰テストでは Free owner の /projects 到達 (200・billing へ redirect されない) を
  中心に据え、middleware レベルの網羅は既存テストに委ねる (二重化しない)。

## [Suggestion] code 文字列比較を行わない旨を設計に明記 (観点7)
- 判断: 対応する
- 対応内容: 詳細設計に「createOrganization は Plan の値 (currentPrice) からのみ状態を導出し、
  $plan->code の文字列比較はしない」と明記する。
