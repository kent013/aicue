Round 1 の主要な実装上の穴は修正されています。ただし、保証範囲の記述と新しい目録判定の負例に残件があります。

### `AGENTS.md`

- [Warning] 「例外へ載せるのは区分ごとの固定文だけ」という記述は、`SchemaViolation` には成立しません。`LlmJson::schemaViolation($detail, ...)` は呼び出し側の `$detail` をそのまま例外へ渡します。`LlmOutputInvalidReason` の docblock にも「SchemaViolation は呼び出し側が具体的な違反内容を渡す」とあります。

  「復号に失敗した6区分では固定文だけ」と保証範囲を狭めるか、`SchemaViolation` も固定文だけにする必要があります。

- [Warning] 「応答は登録済み受け取り関数の直接の引数に渡す形だけ」という記述も、実際に検査 3 が強制するのは `Decoded` 分類だけです。`FreeText` と `ProviderShape` は対象外なので、「`Decoded` 分類の応答は」と限定してください。

### `docs/architecture.md`

- [Warning] 「`getMessage()` に入るのは区分ごとの固定文だけ」という記述が、`schemaViolation()` の実装と矛盾します。単体・統合非漏洩テストも6つの復号失敗区分だけを対象としており、`SchemaViolation` の detail 非漏洩は保証していません。保証を6区分へ限定するのが実装に即しています。

文書追加そのものは施策 8 を満たしており、受理契約、決定順序、観測、巻き戻し、旧語彙との境界は適切に記載されています。

### `tests/Architecture/LlmResponseDecodePointGateTest.php`

- [Warning] `llmResponseOtherReceivers()` の双方向照合と理由長検査は実装されましたが、現在の目録が空なので、その判定分岐を本番テストが一度も通りません。余剰登録、未登録の観測値、30文字未満の理由をそれぞれ落とす負例が必要です。
- [Warning] exemption の前提検査も現在値による正例しかありません。「対象ファイルに `executeSync()` がなくなったら失敗する」負例がないため、今回変更した gate 判定条件の検出力が共通規約 (c) に沿って裏取りされていません。純粋な判定関数へ切り出して合成入力で検証する方法が考えられます。

完全修飾名の完全一致、双方向照合、理由長、現在の exemption 前提確認という実装内容自体は Round 1 の問題を解消しています。

### `tests/Support/Llm/LlmResponseSeamScanner.php`

判定: 指摘なし。

大小文字と先頭 `\` の正規化、後置加工の拒否、引数開始位置の確認、対応不能な括弧の `Unresolved` 化はいずれも適切です。`ResolvedPromptFactory` と「登録済み receiver の直接引数」の判定も分離されており、検査 3 が後者を確実に拒否できます。

### `tests/Unit/Architecture/LlmResponseSeamScannerTest.php`

- [Suggestion] `resolveSeam()` には外側 receiver の名前付き引数を許可する専用分岐がありますが、その正例がありません。新設した分岐の誤検出側も固定するなら、次の形を追加すると明確です。

```php
ExtractedSopData::fromLlmText(
    text: SopExtractPrompt::make(...)->executeSync(),
);
```

大小文字、先頭 `\`、後置加工、配列化、対応不能な括弧の負例は追加されており、Round 1 の指摘には対応しています。

### `tests/Feature/Projects/AnalysisPipelineTest.php`

判定: 指摘なし。

再試行ログ2件と終端ログ1件を個別に固定したため、片方が消えても緑になる問題は解消されています。`analysis_jobs.error` の完全一致と6区分の sentinel 非漏洩も維持されています。

### `resources/prompts/sop-extract-media.yaml`

判定: 指摘なし。

既存の媒体向け防御指示を残し、囲み指示を独立した行として追加した修正は適切です。

### その他の変更ファイル

以下は判定: 指摘なし。

- `app/Enums/Manual/LlmOutputInvalidReason.php`
- `app/Support/Manual/LlmJson.php`
- `app/Services/AI/Testing/CannedPromptResponses.php`
- `resources/prompts/sop-extract.yaml`
- `resources/prompts/work-decomposition.yaml`
- `resources/prompts/scenario-generation.yaml`
- `tests/Support/Llm/DecodePointPublicSurface.php`
- `tests/Support/Llm/Fixtures/LenientDecodePointProbe.php`
- `tests/Support/Llm/LlmResponseHandling.php`
- `tests/Support/Llm/LlmResponseSeamFinding.php`
- `tests/Support/Llm/LlmResponseSeamResolution.php`
- `tests/Support/Prompts/PromptFactoryPopulation.php`
- `tests/Support/Manual/FencedLlmResponse.php`
- `tests/Support/Manual/LlmJsonRejection.php`
- `tests/Unit/Manual/LlmJsonTest.php`
- `tests/Unit/Manual/AnalysisDtoTest.php`
- `tests/Unit/Manual/WorkDecompositionResponseDataTest.php`
- `tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php`
- `tests/Feature/Llm/CannedPromptResponsesTest.php`
- `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php`
- `tests/Feature/Notifications/ManualAnalysisNotificationTest.php`
- `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`
- 提示された `tests/Architecture/fixtures/llm-seam/*`

### 検証状態

- [Warning] `composer test` 全数の再実行は「現在進行中」であり、Round 2 時点では全 green がまだ確定していません。個別に再実行した3件が通ったことは原因分析を支持しますが、完了条件の全数 green とは別です。
- `pipeline-smoke --check` と実 provider の互換性確認 A/B は、報告どおり外部確認待ちです。未実施を明示している点は適切ですが、詳細設計上の完了条件はまだ満たしていません。

全体判定: CHANGES_REQUESTED