# 実装レビュー Round 2 対応マトリクス

## Critical

### PromptWindowScanner: 提示された 2 形の具体的な回避経路が未検出のまま (docblock だけで済ませていた)

**対応する。** `Image::{$method}(...)` / `ImageAnalysisMediaData::{$method}(...)` のような
中括弧による動的メソッド名の静的呼び出しを検出する `PromptWindowScanner::dynamicMethodNameCalls()`
を新設した。受け手が対象クラス (`Image`/`Document`/`ImageAnalysisMediaData`/
`PdfAnalysisMediaData`) へ解決できる場合、メソッド名が動的でも
`VendorMediaTypeConstruction`/`MediaDataNamedConstructorCall` として違反候補に拾う
(メソッド名を問わないため列挙は不要)。負例テストを追加し、実窓口ファイルにこの構文が
無いことも確認した。

**配列 callable 経由の呼び出し** (`$f = [Image::class, 'method']; $f(...)`) は、`::` トークン列を
経由しないため引き続き検出できない。これは既存の `VendorPromptLoad`/`WindowLoad` も持つ
走査器アーキテクチャ全体の限界であり (データフロー解析が要る)、`docs/architecture.md` の
既存宣言と同じ扱いとして docblock に明記し、**検出できないことを示す負例テスト**
(「既知の保証しない限界」) を追加した。誇張しない形で保証範囲を確定させた。

## Warning

### OCR 成功条件に本文量の下限が無い (日本語比率だけだと 1 文字でも比率 1.0 で通過する)

**対応する。** `AnalysisAcceptanceGate::validateOcrResult()` へ、テキスト経路の `tooShort` 相当
(`manual.analysis_min_text_bytes` 未満の実質空判定。マーカー除去後のバイト数で判定) を追加した。
`work_process: "あ"` のような 1 文字の手順が比率 1.0 でも拒否されることを固定するテストを追加し、
境界値 (ちょうど / 1 byte 不足) も固定した。既存の OCR 成功パステスト用フィクスチャ
(`AnalysisPipelineOcrTest.php` の `ocrExtractFixture()`) は、この新しい下限を安全に上回る
実際の SOP らしい分量へ拡張した。

### 「手順 0 件」が `OcrEmptyOrInvalid` に正規化されない

**反論する (設計文書どおりの意図的な仕様であり、design review Round 5 で既に確定済み)。**
`app/Enums/Manual/AnalysisFailureReason.php` の `OcrEmptyOrInvalid` の docblock、および
detailed-design.md 施策 7 の記述を引用する:

> 手順 0 件は `ExtractedSopData::fromLlmText()` が `LlmOutputInvalidException` として先に
> 検出するため、この reason には到達しない (`AnalysisAcceptanceGate` 参照)

> 検証順序: スキーマ違反 (空文字列 work_process) は日本語比率チェックまで到達せず
> schemaViolation になることを確認する (概念設計 Round 5 の Suggestion 対応)

これは概念設計 Round 5 で明示的にレビュー・確定済みの仕様であり、`ocrEmptyOrInvalid()` が
実際に到達するのは「手順は 1 件以上あるが、日本語比率不足・実質空 (今回追加) のいずれか」の
経路である。「手順 0 件」と「読み取れた手順の中身が空・判読不能」は意味が異なる失敗であり、
前者は既存の `LlmOutputInvalidException` の分類 (schemaViolation) へ、後者は
`AnalysisFailedException::ocrEmptyOrInvalid()` へ、という分離は設計判断として妥当である
(検証順序テストで固定済み)。利用者向け文言・観測カテゴリの食い違いは無い
(`llm_output_invalid_*` カテゴリで別途観測できる)。

### SopExtractMediaSchemaSyncTest: 保証しないものの記述がファイル末尾の通常コメントだった

**対応する。** `extractSchemaBlock()` の直前へ `/** ... */` docblock として移動し、
走査対象と保証しないものを関数から直接たどれる形にした。

## Round 1 対応の再確認 (Codex が妥当と判定済み)

- PDF 実バイトの MIME sniff 追加
- client 申告と実バイトを分離した容量上限テスト
- HEIC 拒否時の再保存案内
- `StoreVideoManualRequest` のフラグ true/false 統合テスト
- ログ回数の説明を「`run()` 1 回単位」へ修正
- スキーマ抽出器の正例・負例追加
- 画像の再アップロード不可 (見送り) は Codex も「差し戻し理由にしない」と判定済み
