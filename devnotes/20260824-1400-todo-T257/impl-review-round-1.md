仮説「復号本体は概ね正しいが、新設 gate の検出力に抜けがあり得る」を基準に確認しました。実際、復号処理そのものより静的検査側に修正必須の問題があります。

### `tests/Support/Llm/LlmResponseSeamScanner.php`

- [Warning] `json_decode` の判定が大文字小文字を正規化していません。PHP の関数名は case-insensitive なので、`JSON_DECODE(...)`、`\Json_Decode(...)`、`use function JSON_DECODE as decodeJson` は実行可能ですが検査 5 を通過します。`resolveFunctionName()` の結果を小文字へ正規化する負例が必要です。
- [Warning] callable 文字列は厳密に `'json_decode'` とだけ比較しているため、`call_user_func('\json_decode', ...)` を検出できません。先頭の `\` と大小文字を正規化してください。
- [Warning] `resolveEnclosingCall()` は「直前側にある未閉じの呼び出し」を見ているだけで、`executeSync()` が受け取り関数の直接の引数であることを確認していません。例えば次は登録済み receiver と解決され、検査 3 を通過します。

```php
ExtractedSopData::fromLlmText(
    SopExtractPrompt::make(...)->executeSync().'suffix',
);
```

  三項演算、null 合体、配列、キャストなども同様です。`executeSync()` の閉じ括弧から外側呼び出しの区切りまでを走査し、余計な演算がないことを確認する必要があります。
- [Suggestion] クラス docblock は「ソースを読む必要のある4つ」としていますが、`referencesGuardedPrompt()` を含めると検査 2・3・4・5・8 の5つです。

### `tests/Architecture/LlmResponseDecodePointGateTest.php`

- [Warning] `llmResponseOtherReceivers()` の照合が FQCN の完全一致ではなく、位置文字列に対する `str_ends_with()` です。たとえば登録値 `Foo` が実際の `BarFoo` に一致し得るため、共通規約 (a) を満たしません。
- [Warning] `llmResponseOtherReceivers()` は未使用登録の拒否と30文字以上の理由検査がありません。観測された側から登録簿を見るだけで、deny-by-default の双方向・exact-fit になっていません。
- [Warning] `llmExecuteSyncPopulationExemptions()` も理由とファイルの存在しか確認せず、免除対象に実際の `executeSync()` が存在するかを検証していません。「exact-fit」という記述と実装が一致せず、古い免除が残っても緑になります。
- [Warning] 検査 3 の「直接の引数」という主張は、前述の `resolveEnclosingCall()` の抜けにより成立していません。

### `tests/Unit/Architecture/LlmResponseSeamScannerTest.php`

- [Warning] 次の必須負例が不足しているため、上記の偽陰性を検出できません。

  - `JSON_DECODE()`、`\Json_Decode()`、大小文字を変えた `use function`
  - callable 文字列 `'\json_decode'`
  - `executeSync().'suffix'`、三項演算など、receiver 内で応答を加工する形
  - 詳細設計に明記された「対応の取れない括弧 → Unresolved」

- [Warning] `llmResponseOtherReceivers()` と免除目録の stale/余剰登録を拒否する自己検査もありません。

### `tests/Feature/Projects/AnalysisPipelineTest.php`

- [Warning] 非漏洩テストは `$observed` が非空であることしか確認していないため、再試行ログまたは終端ログの片方が消えてももう片方だけで緑になります。「2種類を検証した」という主張を満たしません。ログ種別ごとの件数を個別に固定してください。
- [Suggestion] context を `json_encode()` した結果だけで検査すると、将来 `Throwable` などのオブジェクトが context に入った場合、その内部メッセージを観測できません。ログ context を素のデータに限定するか、再帰的に文字列値を確認すると非漏洩保証が明確になります。

### `AGENTS.md`

- [Warning] 詳細設計の施策 8 で要求されたドメイン固有規約 21 の変更が、提示された差分に存在しません。新規 prompt 追加時の目録登録、単一復号点、再試行可否と失敗区分の分離が規約化されていません。

### `docs/architecture.md`

- [Warning] 詳細設計の施策 8 が未実装です。少なくとも以下が欠落しています。

  - 受理文法と区分決定順
  - `ValueIncompleteInferred` と `finish_reason` の関係
  - ログ集計の分母に関する制約
  - 出荷後の観測・一式 revert 手順
  - 過去の `invalid_json` とのデプロイ境界

### `app/Support/Manual/LlmJson.php`

判定: 指摘なし。最初の囲みを決定論的に選び、値の内部にある印を終端扱いせず、余剰トークン・括弧不整合・閉じ囲み欠落を fail-closed に分類しています。固定文による拒否も応答本文を含みません。

### `app/Enums/Manual/LlmOutputInvalidReason.php`

判定: 指摘なし。6区分と直交する `SchemaViolation`、固定の非漏洩 detail、全 case を列挙した `match` は設計どおりです。

### `app/Services/AI/Testing/CannedPromptResponses.php`

判定: 指摘なし。構造化応答4本だけを囲み、自由文を囲まない分離は適切です。

### 依頼文 YAML

以下はいずれも判定: 指摘なし。新しい受理契約と同期しています。

- `resources/prompts/sop-extract.yaml`
- `resources/prompts/sop-extract-media.yaml`
- `resources/prompts/work-decomposition.yaml`
- `resources/prompts/scenario-generation.yaml`

### 復号点テスト・共有ヘルパ

以下はいずれも判定: 指摘なし。

- `tests/Unit/Manual/LlmJsonTest.php`
- `tests/Support/Manual/FencedLlmResponse.php`
- `tests/Support/Manual/LlmJsonRejection.php`

受理・6区分の拒否・深さ・不正 UTF-8・非 JSON 空白・例外メッセージ非漏洩を両方向で確認しています。

### Gate の補助型

以下はいずれも判定: 指摘なし。

- `tests/Support/Llm/DecodePointPublicSurface.php`
- `tests/Support/Llm/Fixtures/LenientDecodePointProbe.php`
- `tests/Support/Llm/LlmResponseHandling.php`
- `tests/Support/Llm/LlmResponseSeamFinding.php`
- `tests/Support/Llm/LlmResponseSeamResolution.php`
- `tests/Support/Prompts/PromptFactoryPopulation.php`

### `tests/Architecture/fixtures/llm-seam/*`

提示された各 fixture 自体には指摘ありません。

- `receiver-flow-clean.php.txt`
- `receiver-flow-missing-decode.php.txt`
- `receiver-flow-rebound.php.txt`
- `receiver-flow-reused.php.txt`
- `seam-resolved-other.php.txt`
- `seam-resolved-receiver.php.txt`
- `seam-unresolved-static.php.txt`
- `seam-unresolved-variable.php.txt`
- `seam-wrong-enclosing.php.txt`
- `vocabulary-clean.php.txt`
- `vocabulary-violations.php.txt`

ただし、前述した大小文字、先頭 `\` の callable、後置加工の fixture を追加する必要があります。

### 既存契約を書き換えたテスト

以下はいずれも判定: 指摘なし。旧「囲みなし受理」を残さず、新契約へ置換しています。

- `tests/Unit/Manual/AnalysisDtoTest.php`
- `tests/Unit/Manual/WorkDecompositionResponseDataTest.php`
- `tests/Unit/Support/Manual/AnalysisAcceptanceGateTest.php`
- `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php`
- `tests/Feature/Llm/CannedPromptResponsesTest.php`
- `tests/Feature/Projects/ScenarioBookendMaterializeTest.php`
- `tests/Feature/Notifications/ManualAnalysisNotificationTest.php`

### 検証状態

- [Warning] 報告されているのは対象テストの個別実行、PHPStan、限定した Pint のみです。完了条件にある `composer test`、フロント・packages の全検証、`pipeline-smoke --check` は未確認です。
- 課金を伴う互換性確認 A/B を実行しなかった判断自体は設計どおりですが、外部確認待ちのため出荷完了とは扱えません。

全体判定: CHANGES_REQUESTED