# 対応マトリクス: impl-review Round 1

## [Critical] `claim の INSERT は同一スコープで 1 本しか通らない` が行を手組み配列で生成している

- 判断: **対応する**
- 根拠: 「テストデータは必ず Factory で生成 (`Model::create()` 手組み禁止)」は
  AGENTS.md / 詳細設計のコーディングルールであり、query builder へ直接渡す場合も
  **属性値の出所は Factory** であるべき (施策 B のマイグレーションテストでは既に
  `->raw()` + 一部除去の形にしていたのに、本テストだけ手組みが残っていた = 一貫性も欠く)。
- 対応内容: `IdempotencyKey::factory()->forApiKey($apiKey)->processing()->raw([...])` を
  起点にし、`route_name` / `key` だけ上書きする形へ変更。query builder は enum cast を
  通さないため `state` のみ `->value` へ落とし、`created_at` (Factory 定義に無い列) を明示。
  `expect(...)->toBe(1)` → `toBe(0)` → 行数 1 の主張は変えていない
  (テストが証明する内容は同じで、データの出所だけを規約準拠にした)。

## [Warning] `DELETE /api/v1/mcp` の免除前提を behavioral に固定するテストが無い

- 判断: **対応する**
- 根拠: 指摘のとおり。exemption は「本体処理へ到達しないから冪等性の概念が無い」という
  **主張**であり、vendor が DELETE を意味のある処理に変えても route label が同じなら
  免除が生き残る。これは「対処済みに見える無防備」で、他の exemption
  (`SelfRevocationUnreachableReplay`) には premise テストがあるのに片方だけ無いのは非対称。
- 対応内容: `tests/Feature/Security/IdempotencyExemptionPremiseTest.php` に
  `DELETE /api/v1/mcp は定数 405 スタブのままで冪等行を 1 件も作らない` を追加。
  405 + `Allow: POST` + 空 body に加えて **`IdempotencyKey` が 0 件**であることを固定する。
  405 スタブであること自体は `ThrottleExemptionPremiseTest` も既に固定しているため、
  重複を避けてコメントで役割分担 (こちらは「冪等行が作られない」という冪等側の観測) を明記した。

## 検証

```
composer test -- --filter="IdempotencyConcurrentClaim|IdempotencyExemptionPremise"
  → 15 tests / 15 passed / 82 assertions
```
