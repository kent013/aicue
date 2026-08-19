## 判定: CHANGES_REQUESTED

Round 1 の指摘の多くは妥当に修正されていますが、Critical が解消しておらず、OCR 成功判定にもブロッキングな不足があります。

### Critical

1. PromptWindowScanner の既知の迂回経路が残ったまま、gate が絶対的な保証を主張しています

[PromptWindowScanner.php](</workspace/.claude/worktrees/tasks/T234/tests/Support/Llm/PromptWindowScanner.php>) では配列 callable と `Image::{$method}()` を保証外にしましたが、以下は引き続き絶対的な表現です。

- `VendorMediaTypeConstruction`: 「構築は窓口1ファイルに限る」
- `MediaDataNamedConstructorCall`: 「呼べるのは AnalysisMediaValidator だけ」
- `docs/architecture.md`: 「窓口の外から new/static 呼び出し不可」
- DTO の説明: deny-by-default で生成箇所を pin

これは AGENTS.md の共通規約にある「走査器の限界を書き足すだけでは既存の見逃しを規約適合へ変えない」に該当します。

しかも、提示された二形は完全なデータフロー解析がなくても直接検出できます。

- 解決済みの `Image::` / `Document::` の直後が `{` なら未解決呼び出しとして違反にする
- `[Image::class, 'fromRawContent']` のような配列 callable を検出する。少なくとも対象クラスの `::class` を callable 文脈で使う形を fail-closed にする

一般的な動的 callable すべてを保証外にする余地はありますが、Round 1 で示された具体的な直接迂回は検出し、その負例を追加する必要があります。

### Warning

2. OCR の成功条件に本文量の下限がありません

[AnalysisAcceptanceGate.php](</workspace/.claude/worktrees/tasks/T234/app/Support/Manual/AnalysisAcceptanceGate.php>) は「テキスト経路の既存ゲートと同じ基準」と説明していますが、実際に確認するのは日本語比率だけです。

そのため、例えば `work_process = "あ"` の1手順だけでも比率1.0で成功します。特に `TooShort` を PDF OCR フォールバック対象にしているので、既存の `analysis_min_text_bytes` を OCR 経路で実質的に回避できます。

OCR結果の本文について、マーカー除去後の最低情報量を検証する必要があります。既存閾値をそのまま使わない設計なら、異なる基準にする理由と対応テストが必要です。

3. 「手順0件」が `OcrEmptyOrInvalid` に正規化されません

[AnalysisPipeline.php](</workspace/.claude/worktrees/tasks/T234/app/Services/Manual/AnalysisPipeline.php>) では次の順序です。

```php
AnalysisAcceptanceGate::validateOcrResult(
    ExtractedSopData::fromLlmText(...)
)
```

そのため手順0件は acceptance gate より前に `LlmOutputInvalidException` となり、OCR用の `ocrEmptyOrInvalid()` には到達しません。

これは以下と食い違います。

- `AnalysisFailedException::ocrEmptyOrInvalid()` の「手順を1つも読み取れなかった」
- OCR失敗時の利用者向け文言
- `failure_category = ocr_empty_or_invalid` を用意した設計意図

OCR応答の「空・判読不能」と、一般的なスキーマ破損を分けて正規化し、利用者向け文言と観測カテゴリを一致させるべきです。

4. SopExtractMediaSchemaSyncTest の保証外説明が規約上の docblock になっていません

[SopExtractMediaSchemaSyncTest.php](</workspace/.claude/worktrees/tasks/T234/tests/Architecture/SopExtractMediaSchemaSyncTest.php>) の引用符内波括弧に関する保証外説明は、ファイル末尾の通常コメントです。

走査ロジックを変更した場合の規約は、走査対象と保証外を走査器の docblock に置くよう求めています。`extractSchemaBlock()` の直前へ `/** ... */` として移し、関数から直接たどれる形にしてください。

### Round 1 対応の確認

以下は妥当です。

- PDF実バイトの MIME sniff 追加
- client申告と実バイトを分離した容量上限テスト
- HEIC拒否時の再保存案内
- `StoreVideoManualRequest` のフラグ true/false 統合テスト
- ログ回数の説明を「`run()` 1回単位」へ修正
- スキーマ抽出器の正例・負例追加

画像を再アップロードできない点の見送りも、提示された確定済み設計を前提とする限り、今回の差分を拒否する理由にはしません。