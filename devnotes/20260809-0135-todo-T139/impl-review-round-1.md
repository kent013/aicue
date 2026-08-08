**Findings**

- [Critical] [tests/Feature/Api/IdempotencyConcurrentClaimTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Feature/Api/IdempotencyConcurrentClaimTest.php:121)  
  `claim の INSERT は同一スコープで 1 本しか通らない` が `IdempotencyKey` の行を手組み配列で生成しています。詳細設計の「テストデータは必ず Factory で生成」と、施策 H の「Factory 経由のデータ生成のみ」に反します。  
  `IdempotencyKey::factory()->forApiKey($apiKey)->processing()->raw([...])` を起点にし、必要な `route_name` / `key` / `request_hash` だけ上書きして `insertOrIgnore()` に渡す形へ寄せるべきです。

- [Warning] [tests/Architecture/IdempotentRouteCoverageTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Architecture/IdempotentRouteCoverageTest.php:103)  
  `DELETE /api/v1/mcp` の免除理由は「vendor の定数 405 スタブ」ですが、その前提を behavioral に固定するテストがありません。vendor 側が将来 DELETE を意味のある処理に変えても、route label が同じなら exemption が生き続けます。  
  `DELETE /api/v1/mcp` が 405 で、本体処理へ到達しないことを `IdempotencyExemptionPremiseTest` へ追加すると gate の主張範囲が締まります。

**File別判定**

- [app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php](/workspace/.claude/worktrees/tasks/T139/app/Console/Commands/Operations/PruneIdempotencyKeysCommand.php:1): OK。state 別 delete、MCP 側 prune、processing report は設計どおり。
- [app/Enums/ApiErrorCode.php](/workspace/.claude/worktrees/tasks/T139/app/Enums/ApiErrorCode.php:30): OK。409 系追加と `fromHttpStatus()` 据え置きの説明は妥当。
- [app/Enums/Idempotency/*](/workspace/.claude/worktrees/tasks/T139/app/Enums/Idempotency/IdempotencyState.php:1): OK。状態語彙と claim status は設計どおり。
- [app/Enums/Security/IdempotencyWiringExemption.php](/workspace/.claude/worktrees/tasks/T139/app/Enums/Security/IdempotencyWiringExemption.php:1): OK。ただし MCP DELETE stub の前提テスト不足は上記 Warning。
- [app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php](/workspace/.claude/worktrees/tasks/T139/app/Exceptions/Idempotency/IdempotencyFinalizationFailure.php:1): OK。ログに key/body を載せない方針と一致。
- [app/Http/Middleware/IdempotentRequest.php](/workspace/.claude/worktrees/tasks/T139/app/Http/Middleware/IdempotentRequest.php:1): OK。claim → 分岐 → finalize、fail-closed、replay header、長過ぎる key の 422 は設計どおり。
- [app/Models/IdempotencyKey.php](/workspace/.claude/worktrees/tasks/T139/app/Models/IdempotencyKey.php:1): OK。`state` を fillable に入れない判断、`CarbonInterface` 化は妥当。
- [app/Services/Mcp/McpIdempotencyService.php](/workspace/.claude/worktrees/tasks/T139/app/Services/Mcp/McpIdempotencyService.php:1): OK。MCP 据え置き範囲を誇張していない。
- [app/Support/Idempotency/*](/workspace/.claude/worktrees/tasks/T139/app/Support/Idempotency/IdempotencyRetention.php:1): OK。SoT 化、header 定数、DTO は設計どおり。
- [config/idempotency.php](/workspace/.claude/worktrees/tasks/T139/config/idempotency.php:1): OK。env 不使用。
- [database/factories/IdempotencyKeyFactory.php](/workspace/.claude/worktrees/tasks/T139/database/factories/IdempotencyKeyFactory.php:1): OK。
- [database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php](/workspace/.claude/worktrees/tasks/T139/database/migrations/2026_08_09_000100_add_state_to_idempotency_keys_table.php:1): OK。completed backfill / no DB default / nullable response_status は設計どおり。
- [routes/console.php](/workspace/.claude/worktrees/tasks/T139/routes/console.php:156): OK。
- [tests/Architecture/IdempotencyContractParityTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Architecture/IdempotencyContractParityTest.php:1): OK。
- [tests/Architecture/IdempotentRouteCoverageTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Architecture/IdempotentRouteCoverageTest.php:1): Warning あり。
- [tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Architecture/McpWriteToolIdempotencyEnforcementTest.php:1): OK。字句検査の限界も明示済み。
- [tests/Feature/Api/IdempotencyConcurrentClaimTest.php](/workspace/.claude/worktrees/tasks/T139/tests/Feature/Api/IdempotencyConcurrentClaimTest.php:1): Critical あり。
- その他の Feature/Unit/Docs 差分: OK。契約変更、保証しない範囲、MCP 据え置きの書き方は誇張していません。

**全体判定: CHANGES_REQUESTED**