- [Critical] **`nearestMonthlyExpiry` 固定 + `amount>1` で、月次複数期限時に過少課金/過剰付与が起きます（設計レベルの破綻）**  
  該当ファイル: `app/Services/Billing/TicketLedgerService.php:333`, `app/Services/Billing/TicketLedgerService.php:635`, `app/Services/Billing/TicketLedgerService.php:382`, `app/Services/Billing/TicketLedgerService.php:551`  
  何が壊れるか (具体例):  
  入力: `monthly +1 (2026-08-10失効)` と `monthly +100 (2026-09-10失効)` がある状態で `reserve($org, 3)`。  
  結果: `consume_expires_at` は最短の `2026-08-10` に固定され、`commit` で `-3` も同期限になる。  
  - 失効後残高: 本来 `98`（100-2）であるべきところ、`100` になり **+2 の over-grant**。  
  - 長時間ジョブで失効跨ぎ commit: `ReleasedExpired` になり **3 枚まるごと no-charge**（本来は少なくとも一部は課金されるべき）。  
  根拠: 実装は月次 hold/残高を「source 単位」でしか持たず（expiry 粒度がない）、`consume_expires_at` を 1 値に潰しているため、P5契約の「消費した grant と同じ expires_at」を `amount>1` で満たせません。`commit-wins`/`ReleasedExpired` 判定がこの1値に依存しているため金銭事故に直結します。

- [Warning] **上記 Critical を固定するテストが不足しており、現テストは空振りし得ます**  
  該当ファイル: `tests/Feature/Billing/TicketBalanceAccountingTest.php:22`, `tests/Feature/Billing/TicketCommitWinsTest.php:55`, `tests/Feature/Billing/TicketConsumeOrderTest.php:29`  
  何が壊れるか (具体例):  
  上の「月次複数期限 + `amount=3` + 失効跨ぎ」ケースを実装で壊しても、現テストは単一期限ケース中心のため green のまま通る可能性があります。  
  根拠: 追加テストは「単一 monthly grant」「単一 source 消費」「TTL/失効の個別枝」は押さえていますが、**複数 expiry をまたぐ monthly 消費の整合**（最短期限が実際供給元でないケース）を直接検証していません。

## Verdict: CHANGES_REQUESTED