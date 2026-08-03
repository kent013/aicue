**ファイル別判定**

- `app/Exceptions/Manual/AnalysisFailedException.php:32`  
  - 判定: 設計施策4と一致。`timedOut()`/`providerBusy()` 追加は妥当。  
  - 指摘: なし。

- `app/Exceptions/Manual/LlmOutputInvalidException.php:11`  
  - 判定: コメント更新のみ。施策3の retryable 範囲拡張と整合。  
  - 指摘: なし。

- `app/Jobs/Manual/RunManualAnalysis.php:31`  
  - 判定: 施策2どおり `timeout=1560`。時間 budget 連鎖コメントも新モデルに一致。  
  - 指摘: なし。

- `app/Services/Manual/AnalysisPipeline.php:42`  
  - 判定: 施策3/4を正しく実装。`run()` 第1文で deadline 確定、各段伝播、`withBoundedRetry()` の試行上限+deadline 打ち切り、deny-by-default 判定、文言分岐まで設計一致。  
  - セキュリティ不変条件 #7: reserve/commit/release の2フェーズを壊す変更は見当たらず、リトライは `startJob` 後〜`finalize` 前に閉じているため二重課金経路は増えていません。  
  - [Suggestion] `userMessageFor()` の `[500,502,503,504]` は `isTransient()` 側の status 定義と将来 drift しやすいので、共通定数化すると保守性がさらに上がります。

- `config/manual.php:15`  
  - 判定: `analysis_deadline_seconds=1080` 追加は施策2と一致。typed accessor前提とも整合。  
  - 指摘: なし。

- `config/queue.php:53`  
  - 判定: `database-analysis.retry_after=1680` へ更新され、`timeout < retry_after < TTL` を満たす。  
  - 指摘: なし。

- `resources/prompts/sop-extract.yaml:1`  
- `resources/prompts/work-decomposition.yaml:1`  
- `resources/prompts/scenario-generation.yaml:1`  
  - 判定: 施策1どおり `client_options.timeout=360`。`max_tokens=16000` 維持。  
  - 指摘: なし（`example-summary.yaml` 非変更方針とも整合）。

- `tests/Support/AnalysisBudget.php:1`  
  - 判定: 施策5どおり。仕様値とYAML実測の突合せ窓口として妥当。PHPStan narrowing も適切。  
  - 指摘: なし。

- `tests/Architecture/AnalysisTimeBudgetInvariantTest.php:15`  
  - 判定: 新算術（D/C/M₁/S/T）へ更新され、同時変更素通りを避ける構造になっている。  
  - 指摘: なし。

- `tests/Architecture/AnalysisTokenBudgetInvariantTest.php:28`  
  - 判定: 施策5と一致。`PROMPT_NAMES` の単一化、timeout pin を `AnalysisBudget` 経由化。  
  - 指摘: なし。

- `tests/Support/PrismHttpExceptionFactory.php:1`  
  - 判定: 施策6どおり。generic PrismException + previous(RequestException) を再現できる。  
  - 指摘: なし。

- `tests/Support/ThrowingPromptFake.php:1`  
  - 判定: 施策6どおり。例外混在 script と試行回数観測により deadline/retry を決定論的に検証可能。  
  - 指摘: なし。

- `tests/Feature/Projects/AnalysisPipelineTest.php:119`  
  - 判定: 施策6(A)〜(D)を網羅し、retry可否・deadline・会計不変条件（二重課金なし/課金済みfailedなし）を固定できている。  
  - 指摘: なし。

- `docs/architecture.md:188`  
  - 判定: 施策7どおり更新。運用契約の記述が実装と一致。  
  - 指摘: なし。

**横断チェック**

- DTO/JsonResource 観点: `response()->json()` 直書き追加なし。  
- 禁止事項5/6: Prism facade直呼び追加なし、prompt文字列のコード直書き追加なし（YAML更新のみ）。  
- DESIGN.md / Atomic Design: フロント変更なしのため **該当なし**。  
- PHPStan level 10: 提示結果どおり問題なし（`mixed` 抑制・narrowing 方針は妥当）。  
- [Warning] `pnpm test` / `pnpm build` は実行中とのことなので、マージ前に完了結果だけ最終確認してください（差分自体はバックエンド中心で設計整合）。

**全体判定: APPROVED**