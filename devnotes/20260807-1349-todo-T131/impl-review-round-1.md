**Findings**

`app/Services/Manual/AnalysisPipeline.php`

- [Critical] `writeProgress()` now writes `result_json` through `AnalysisJob::query()->update($attributes)`. Eloquent mass updates do not run the model’s JSON cast/mutator for arbitrary attributes, so `result_json => $decomposition->toArray()` can be sent to the DB as a raw PHP array. This can fail at runtime or persist an invalid value on the normal decompose path, independent of concurrency. Keep the conditional `where status=running`, but encode/cast the JSON value before the mass update, or derive the casted attribute value via a model instance and then use that scalar in the conditional update.

`tests/Architecture/JobExclusionOrderingInvariantTest.php`

- [Warning] The test says it checks the “default connection” retry window, but it hard-codes `queue.connections.database.retry_after`. The added premise only asserts both jobs have `connection === null`; it does not assert that `queue.default` is `database`. If the default queue connection changes, this gate can stay green while comparing against the wrong lane. Use `config('queue.default')` to select the retry_after, or explicitly assert the default is `database`.

`app/Services/Billing/AutoRechargeService.php`

- [Warning] `terminateInvoiceBestEffort()` logs `$exception->getMessage()` into structured context. Because this exception can originate from the Stripe gateway, the message may include provider payload fragments or sensitive operational details. Prefer a sanitized error category/class/code, and keep raw exception details in `report()`/secure logs only if needed.

`app/Enums/Security/ExternalCallKind.php`, `JobDedupGuarantee.php`, `JobDedupExemption.php`, `app/Exceptions/Manual/JobOwnershipLostException.php`

- 指摘なし。

`app/Services/Manual/RenderPipeline.php`

- 指摘なし。S3 PUT 直前の preflight と terminal 行への進捗書き戻し抑止は設計意図に合っています。

`app/Support/JobExecution/AttemptOwnershipPreflight.php`

- 指摘なし。fake が判定を差し替えず `parent::stillPending()` に委譲する構成も妥当です。

`tests/Architecture/JobExecutionDedupInventoryTest.php`, `tests/Support/QueuedJobPopulation.php`, `tests/Support/JobDedup/*`

- 指摘なし。Architecture gate の限界を明記し、配置は Feature テスト側に寄せている点も設計と一致しています。

`tests/Feature/*`, `tests/Support/FakeAttemptOwnershipPreflight.php`, `tests/Support/FakeAutoRechargeGateway.php`

- 指摘なし。特に Billing の preflight fake が「競合窓だけ作る」形になっているのは、テストが本実装から乖離しにくく妥当です。

`docs/architecture.md` / `AGENTS.md`

- [Warning] 提示された `git diff` には S7 の文書差分が見当たりません。AGENTS 側は会話冒頭の内容では追記済みに見えますが、`docs/architecture.md` の運用契約が実装差分に含まれていないなら S7 未完了です。

全体判定: CHANGES_REQUESTED