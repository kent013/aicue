# 対応マトリクス: impl-review Round 2

Codex 全体判定: **APPROVED** (Critical 0 / Warning 0 / Suggestion 0)

Round 1 の 3 指摘 (Warning 2 / Suggestion 1) はいずれも解消と判定された。
追加の対応は無い。

## Codex が明示した「残る限界」(実装は変えず記録する)

1. **件数一致検査は「1 行が抜けて別の 1 行が入る」置換までは検出できない**。
   台帳が append-only であり、既知の唯一の UPDATE 経路
   (`TicketLedgerService::backfillPaymentIntentId`) が
   `delta` / `organization_id` / `source` / `expires_at` / `created_at` を触らないため、
   その経路は存在しない。PR-C2 の並行対象 (insert / reserve / commit / grant) に対しては十分。
   → **この前提が崩れる変更 (台帳への新しい UPDATE 経路) を入れるときは、
   本 guard の前提も同時に見直すこと**。
2. **MU11 のテストは別接続トランザクションの再現ではない** (同一トランザクション内に
   割り込みを差し込む形)。rollback 分岐の機械固定としては成立している。
3. **検査 7 は任意の命名による TS 型まで完全には検出しない** (走査語は
   `TicketLedgerKind` / `reserve_commit` / `carry_forward` の 3 つ)。
   保証範囲は gate の docblock に明記済み。現在存在しない TS union を先回りして
   新設する必要は無い (思考原則 2)。

これら 3 点は Codex も「現状で十分」と判定しており、本 PR での追加実装は行わない。
