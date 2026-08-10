提供差分ベースでレビューしました。コマンド実行・ファイル読み込みはしていません。

**Findings**

`app/Console/Commands/Development/PipelineSmokeCommand.php`

- [Warning] `runLlmEvidenceStage()` の帰属照合が設計より弱いです。  
  設計は「成功行がすべて `metadata_missing=false` かつ期待 org/subject」としていますが、実装は template ごとに 1 件でも正しい帰属行があれば、同じ template の不一致成功行があっても pass します。  
  `mismatches !== []` なら失敗、または `llmRecordingIncomplete()` に「不一致行あり」を反映する必要があります。追加テストは「同一 template に正帰属 1 件 + 誤帰属 1 件があると落ちる」がよいです。

- [Warning] `--json` が fail-secure 失敗時に機械可読出力になりません。  
  `handle()` 冒頭で `failSecureBlocker()` に引っかかると plain error を出して即 return します。設計の「`--json` は DTO `toArray()` → `json_encode` の 1 経路」とズレています。少なくとも `--json` 指定時は fail-secure failure も `SmokeRunResultData` 形で返すべきです。

- [Suggestion] preflight 表示に DB 名が出ていません。  
  設計例では `db=bug_hunt` が出ており、費用防壁の監査情報として重要です。`context['db']` を追加すると、実行ログだけで fail-secure の成立状態を読みやすくなります。

- [Suggestion] DB 未 provision 対応の `QueryException` catch は `resolveOrganization()` だけに限定されています。  
  その後の `users()` / `TicketLedgerService` / `DefaultProjectResolver` でも DB 例外は起き得ます。`--json` 契約を守るための追加なら、DB preflight 全体を同じ失敗 DTO に閉じた方が一貫します。

`app/Support/Smoke/SmokeFailureClassifier.php`

- [OK] 分類境界は設計どおりです。  
  `Llm` が `Analysis` / `LlmEvidence` に閉じている点、成功段は `null`、`llm-evidence` の記録不備を `Wiring` にしている点は妥当です。`$llmRecordingIncomplete` を `gate()` 引数で渡す判断も自然です。

`app/Services/LlmCostReportService.php` / `app/Enums/LlmCostGroupBy.php` / `app/DataTransferObjects/LlmCost*Data.php`

- [OK] 集計層に aicue 固有語彙は漏れていません。  
  `llm_call_logs` の列、汎用 subject、DTO のみで閉じており、還流性は保たれています。金額 null を 0 に潰さない点、TOTAL を DB 集計で取る点も設計どおりです。

`app/DataTransferObjects/LlmCallContextData.php` / `app/Prompts/*.php` / `app/Services/Manual/AnalysisPipeline.php`

- [OK] 帰属 metadata 配線は設計どおりです。  
  factory 必須引数化、`withMetadata($context->toMetadata())`、subject を `VideoManual` にする判断、リトライで同じ context を使う点はいずれも妥当です。

`tests/Architecture/PromptUntrustedInputContractTest.php`

- [OK] テストレーンで検証できる範囲を正しく限定しています。  
  reflection で `metadata_context` まで確認し、event/listener/DB 記録は smoke の `llm-evidence` に委ねる説明は誇張がありません。

`tests/Support/Security/DirectFetchInventory.php`

- [Suggestion] justification の「対象は常に 1 組織」は少し強すぎます。  
  `--org` 省略時は eligible organization を全体から探索します。operator console として妥当ですが、理由文は「最終的に選んだ 1 組織を対象にする。探索は bug-hunt 専用 DB 内の運用者向け preflight」とした方が正確です。

`scripts/bug-hunt-shard.sh`

- [OK] orchestrator gate は `cmd_pipeline_smoke()` の最初の実効文にあり、子 wrapper に露出しない設計とも一致しています。`--shard` / `--run-id` 非転送も self-test で押さえられています。

`docs/architecture.md` / `.claude/skills/app-bug-hunt/SKILL.md` / `AGENTS.md`

- [Warning] 施策 9 は提示 diff では完全確認できません。  
  AGENTS 相当の記述は冒頭コンテキストに見えますが、`docs/architecture.md` の「保証しないもの」と skill 側の「探索エージェントは実行しない」は差分未提示です。実装レビューとしては未確認扱いです。

**Verification**

提示された実測結果では PHP / Pint / script self-test は十分に見えます。ただし AGENTS の検証コマンド一覧にある `pnpm test` / `pnpm build` / packages 系は省略されています。UI 変更なしの判断は理解できますが、「全 green でコミット」の規約に対しては未完了です。

**全体判定: CHANGES_REQUESTED**

Critical は見つかりません。ただし `llm-evidence` の帰属照合が設計の「成功行すべて」を満たしていない点と、fail-secure 失敗時の `--json` 契約漏れは直してからマージした方がよいです。