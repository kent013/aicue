# 対応マトリクス: design-review Round 12（v2 初回 / CHANGES REQUESTED・Critical 4・Warning 2）

全指摘に対応。Critical 2 件（P4 / P9）は**実ロジックの矛盾**であり、指摘どおり。

## [Critical] P7 に v1 が大量残存（PlanCode 3 case / Enterprise 未知値扱い / normalizeRaw の Enterprise 分岐削除）
- 判断: 対応する（**v2 の部分再生成で P7 を対象から漏らしていた私のミス**）
- 対応: **P7 を v2 で全面再生成**。`PlanCode` は **verbatim 5 case**、`normalizeRaw` の **Enterprise 除外分岐も verbatim 移植**
  （5 case あるので `identical.alwaysFalse` は起きない）。TS を
  `export type PlanCode = 'personal' | 'starter' | 'standard' | 'business' | 'enterprise';` へ。契約・テスト・PHPStan 節も更新。

## [Critical] P4 backfill 集合が entitlement と不一致（`past_due + PM 有り` を誤って grandfather）
- 判断: 対応する（**実バグ**。SQL が active/trialing だけを除外するため、P2 の `state()` で `Subscribed` になる
  `past_due + PM 有り` を grandfather してしまい、分類表も P2 契約と矛盾し D22 の集合同値も成立しなかった）
- 対応: **backfill を「entitlement を PHP で評価して対象 ID を確定 → その ID 集合で UPDATE」の形へ**。
  判定は **P2 の `deriveEntitlement()` と同一定義**（PM 有無 / trial 終了 / paused / past_due の合成）を使い、
  分類表もその定義に揃えた。これにより **D22 の双方向 ID 集合一致が機械的に成立**する。

## [Critical] P9 の stale pending が永久に再利用される
- 判断: 対応する（**実バグ**。`state()` は 1 日超を `ExpiredCheckout` とするのに、`startCheckout()` の replay/dedup は
  Pending + URL だけで live 判定していたため、「2 日後に新 token で新規 Checkout」が成立しなかった）
- 対応: **`state()` と `startCheckout()` が同一の live 判定（閾値）を共有**する契約に統一。共有方法も明記。

## [Critical] P9 の webhook 状態遷移が自己矛盾（`Failed/Expired → Completed` vs「Pending 以外は触らない」）
- 判断: 対応する / 対応: **遅延成功を受理する遷移条件へ一意に定義**し、状態図・実装契約・テストを揃えた。

## [Warning] D28 の DTO 契約が不統一（P1 は `monthlyTicketGrant` を削除 / P8b は「DTO からは外さない」）
- 判断: 対応する / 対応: **P1 の削除方針へ統一**。P8b の「DTO からは外さない」を「**削除は D28 により P1 が実施済み。
  P8b では触らない（二重定義しない）**」へ。shape からも `monthlyTicketGrant` を除去。

## [Warning] フェーズ記述の残骸（P4 非スコープの「D28 = P5」/ P8a の解決済み未決事項）
- 判断: 対応する / 対応: P4 の非スコープを「**月次付与の廃止（D28 = P1 で確定済み）**」へ。
  P8a の「【製品判断・必須】既定値と同意文言」→ **v2 で確定（aigenba 既定値をそのまま。値は憶測でいじらない）**、
  「【体験判断】低残高通知との併存」→ **v2 で確定（aigenba のまま。独自の抑制ロジックを発明しない）** へ。
