# テストファースト / 変異による検出力の記録 (T147)

**実際に走らせた出力だけ**を転記する。推測で「赤だったはず」とは書かない。

## 1. 実装前の赤 (テストファースト)

### 施策 1: LLM 呼び出しの帰属メタデータ配線

```
vendor/bin/pest tests/Architecture/PromptUntrustedInputContractTest.php \
                tests/Unit/DataTransferObjects/LlmCallContextDataTest.php
→ tests=7 passed=0 errors=7
   Class "App\DataTransferObjects\LlmCallContextData" not found
```

= 帰属 DTO が無く、prompt factory も context を受け取らないため inventory の
組み立て closure が構築できない状態。

### 施策 2 / 3: コスト集計と期間集計コマンド

```
vendor/bin/pest tests/Unit/Services/LlmCostReportServiceTest.php \
                tests/Feature/Console/LlmCostReportCommandTest.php
→ tests=23 passed=0 errors=23
   Target class [App\Services\LlmCostReportService] does not exist.
```

### 施策 4 / 5 / 6 (正直な記録)

これらは**テストを先に書いたが、赤の確認より先に実装を置いてしまった**
(施策 1・2/3 のような「実装前に走らせた出力」を持っていない)。
代わりに **2. の変異検査**で「テストが本当に退行を捕まえるか」を実測した。

## 2. 変異検査 (テストの検出力を実測する)

実装を意図的に壊し、対応するテストが赤くなることを確認してから元に戻した。

| # | 変異 | 結果 |
|---|---|---|
| M1 | `LlmCostReportService::AGGREGATE_SELECT` の整数列から `COALESCE(SUM(...), 0)` を外す | `対象 0 件でも TOTAL は 1 行返り、整数列は 0 / 金額列は null になる` が**赤** (`集計列 input_tokens が数値ではありません`) |
| M2 | `confirmToProceed($costWarning, true)` の第 2 引数を外す | `bughunt.local でも実行確認が出て、拒否したら何も実行しない` が**赤** (`Expected status code 2 but received 1` = 確認が出ずに素通りした) |
| M3 | `SopExtractPrompt::make()` から `->withMetadata(...)` を外す | `帰属が必要な prompt は metadata_context に organization / subject を持つ` が**赤** (`'organization_id' を渡してください`) |
| M4 | `cmd_pipeline_smoke` の `require_orchestrator` を `require_manifest` の後ろへ移動 | `scripts/bug-hunt-shard.sh self-test` の `[e3]` が**赤** (`gate より前に副作用が起きた (記録: require_manifest)`) |

復元後は 3 ファイル 37 件すべて緑 (`tests=37 passed=37`)。
