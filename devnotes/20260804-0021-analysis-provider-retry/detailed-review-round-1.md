**全体判定**  
- **CHANGES_REQUESTED**

**施策別判定**
- 施策1 `resources/prompts/*.yaml`: **APPROVE**
- 施策2 `config/manual.php` / `config/queue.php` / `RunManualAnalysis`: **APPROVE**
- 施策3 `AnalysisPipeline`（deadline + retry）: **REQUEST_CHANGES**
- 施策4 `AnalysisFailedException` / `userMessageFor`: **REQUEST_CHANGES**
- 施策5 Architectureテスト: **REQUEST_CHANGES**
- 施策6 Featureテスト + double: **REQUEST_CHANGES**
- 施策7 `docs/architecture.md`: **APPROVE**

**主要指摘（Critical/Warning/Suggestion）**
- [Warning] `isTransient()` の順序は将来の継承変更に脆いです（`PrismProviderOverloadedException` が `PrismRateLimitedException` 派生化された場合、`app/Services/Manual/AnalysisPipeline.php:235` 付近で 529 が非retry化）。  
  **修正案**: `PrismProviderOverloadedException` 判定を先に置く、または 429 側を `get_class($e) === PrismRateLimitedException::class` で厳密化。
- [Warning] `userMessageFor()` が generic `PrismException(previous=RequestException)` の 408/500/502/503/504 を個別文言化しておらず、H4「理由別行動」の一貫性が欠けます（`app/Services/Manual/AnalysisPipeline.php:286` 付近）。  
  **修正案**: `extractHttpStatus(Throwable): ?int` を追加し、408→`timedOut()`、500/502/503/504→`providerBusy()` へ分岐。
- [Warning] Architectureテストの `Yaml::parseFile()` 後の型絞り込みが Pest `expect()` 依存で、PHPStan level 10 で不安定化し得ます（`tests/Architecture/AnalysisTimeBudgetInvariantTest.php` / `AnalysisTokenBudgetInvariantTest.php`）。  
  **修正案**: `Assert::isArray($yaml)`・`Assert::integer($timeout)` を明示して mixed を静的に潰す。
- [Warning] Pest のファイルスコープ `const` / 関数は衝突しやすいです（設計で言及済みだが回避策が弱い）。  
  **修正案**: グローバル `const` を減らし、`final class AnalysisBudgetInvariant` の `public const` に集約。
- [Warning] deadline 系テスト（`analysis_deadline_seconds=1`）は時計進行に依存すると CI で揺れます（`tests/Feature/Projects/AnalysisPipelineTest.php`）。  
  **修正案**: `travelTo()` で時刻固定し、fake 内で明示的に `travel()` して「直前開始」「超過打ち切り」を決定論化。
- [Suggestion] `withBoundedRetry` の設計（ループ先頭deadline guard、deny-by-default、off-by-one）は概ね妥当です。加えて 503 連続失敗時の「最終文言」ケースを1本追加すると回帰耐性が上がります。
- [Suggestion] UI変更なしのため DESIGN.md / Atomic Design は **該当なし** 判定で妥当です。

**厳格観点への回答**
- `catch Throwable` 拡張: **設計意図は妥当**（握り潰しではなく非transient即再throw）。  
- deadline guard 位置: **先頭で正しい**（`D + C` モデル維持、無限ループ/off-by-oneなし）。  
- 2フェーズ会計不変条件: **テスト計画は良い**。ただし上記の決定論化・追加ケースでさらに固定化可能。  
- オーバーエンジニアリング: **過剰ではない**（変更範囲は解析レーンに閉じている）。