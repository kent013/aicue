# レビュー結果

**全体判定: CHANGES_REQUESTED**

主要な OCR 経路と PromptDefense の一本道は概ね設計どおりです。ただし、静的 gate の回避経路と画像差し替え不能の問題は、マージ前に修正が必要です。また、`composer test` の全件 green が未確定なので、リポジトリ規約上も APPROVED にはできません。

なお、コマンド実行禁止に従いローカルコマンドは使っていません。この環境にはコマンドを介さないテキストファイル reader がないため、`detailed-design.md` の逐語的な照合ではなく、提示差分・施策 1〜11 の対応表・差分内の設計引用を基準に判定しています。

## Critical

### [tests/Support/Llm/PromptWindowScanner.php](/workspace/.claude/worktrees/tasks/T234/tests/Support/Llm/PromptWindowScanner.php)

`VendorMediaTypeConstruction` と `MediaDataNamedConstructorCall` は、動的 callable／動的メソッド名で回避できます。

例えば次は、現在の分類条件では vendor 媒体構築として拾われない可能性があります。

```php
$factory = [Image::class, 'fromRawContent'];
$media = $factory($bytes, 'image/jpeg');

$method = 'fromValidated';
$data = ImageAnalysisMediaData::{$method}(...);
```

実装は固定名の static call、`new`、`extends` を中心に検出しており、これらの unresolved な呼び出し形を違反として返す分岐がありません。「vendor 媒体型の構築を窓口一箇所へ閉じる」「named constructor を validator 一箇所へ閉じる」というセキュリティ不変条件を、検査が green のまま破れます。

AGENTS.md の走査器共通規約 (b) にある fail-closed 条件にも抵触します。少なくとも動的 callable、動的受け手、動的メソッド名の負例を追加し、未解決参照として gate を失敗させる必要があります。

## Warning

### [app/Services/Manual/SourceDocumentService.php](/workspace/.claude/worktrees/tasks/T234/app/Services/Manual/SourceDocumentService.php)

画像の存在確認が過去の `source_documents` 全件を対象にしているため、一度画像をアップロードすると、そのマニュアルでは画像を二度と差し替えられません。

```php
$manual->sourceDocuments()
    ->where('mime', 'like', 'image/%')
    ->exists()
```

これは既存の「追記型 immutable・差し替え＝新規行」および UI の「手順書を差し替える」と衝突します。最初の写真が不鮮明だった場合にも撮り直しを登録できません。途中で PDF を追加しても、古い画像行が残るため再度の画像追加は拒否され続けます。

「一回のアップロードで画像一枚」という要件なら、単一の `document` フィールドですでに満たされています。マニュアルの生涯を通じて一枚という意図なら、画像差し替え不能であることを設計・UI・テストで明示する必要があります。

### [app/Services/Manual/AnalysisMediaValidator.php](/workspace/.claude/worktrees/tasks/T234/app/Services/Manual/AnalysisMediaValidator.php) / [app/DataTransferObjects/Manual/Analysis/PdfAnalysisMediaData.php](/workspace/.claude/worktrees/tasks/T234/app/DataTransferObjects/Manual/Analysis/PdfAnalysisMediaData.php)

PDF 経路は実バイトの MIME sniff を行っていません。

`validatePdfForOcr()` は `$document->mime === 'application/pdf'` を確認して PDF parser に渡すだけです。画像側は実バイトから得た MIME と persisted MIME を比較していますが、PDF 側には同等の確認がありません。

DTO と設計文書は「MIME sniff・容量・ページ数の検証を通過した媒体」と保証しているため、実装と契約が不一致です。保存ファイルの差し替わり、DB 不整合、parse 可能な polyglot などで、実体と異なる MIME を vendor に渡せます。PDF バイトの再 sniff と persisted MIME との一致確認、および不一致の負例が必要です。

### [tests/Feature/Projects/SourceDocumentUploadOcrTest.php](/workspace/.claude/worktrees/tasks/T234/tests/Feature/Projects/SourceDocumentUploadOcrTest.php)

容量分類のテストは、client MIME を使う実装への回帰を十分に検出できません。

`UploadedFile::fake()->create(..., 'image/jpeg')` は testing fake が報告する MIME を指定しているため、実バイトの finfo 結果とクライアント申告 MIME が食い違うケースになっていません。実装を `getClientMimeType()` に戻しても、同じ値が返ってテストが通る可能性があります。

実 JPEG バイトを持つ一時ファイルから `UploadedFile` を構築し、ファイル名・client MIME を PDF、実バイトを JPEGにする負例で固定すべきです。

同ファイルの「HEIC は JPEG / PNG で保存し直すと表示する」というテスト目的も、現状は `assertJsonValidationErrors(['document'])` だけで、文言を検査していません。

### [app/Http/Requests/Projects/StoreSourceDocumentRequest.php](/workspace/.claude/worktrees/tasks/T234/app/Http/Requests/Projects/StoreSourceDocumentRequest.php) / [app/Http/Requests/Projects/StoreVideoManualRequest.php](/workspace/.claude/worktrees/tasks/T234/app/Http/Requests/Projects/StoreVideoManualRequest.php)

HEIC 拒否時の専用案内は、この差分には実装されていません。Laravel の汎用 `mimes` メッセージに委ねられており、「JPEG / PNG で保存し直す」という利用者の次の行動を保証できません。

また、後付けアップロード経路には Feature テストがありますが、同時に変更された新規マニュアル作成時の `StoreVideoManualRequest` について、フラグ true/false と画像容量上限を通した統合テストがありません。

### [app/Services/Manual/AnalysisPipeline.php](/workspace/.claude/worktrees/tasks/T234/app/Services/Manual/AnalysisPipeline.php) / [docs/rollout-checklists.md](/workspace/.claude/worktrees/tasks/T234/docs/rollout-checklists.md)

`logExtractStageTerminal()` が保証しているのは、`runExtractStage()` の一回の呼び出しにつき一回です。「ジョブにつきちょうど一回」ではありません。

ログ出力後の worker 終了やプロセス再実行では、同じ `analysis_job_id` のログが再度出る可能性があります。永続的な冪等キーや記録済み状態はありません。また、成功ケースで一回だけ出ることを検証するテストもありません。

rollout 集計側で `analysis_job_id` ごとに重複排除する仕様にするか、文書を「実行試行につき一回」へ狭める必要があります。厳密な一回性が必要なら永続化した終端イベントが必要です。

## Suggestion

### [tests/Architecture/SopExtractMediaSchemaSyncTest.php](/workspace/.claude/worktrees/tasks/T234/tests/Architecture/SopExtractMediaSchemaSyncTest.php)

新設した抽出ロジックの負例は「見出しが複数」の一分岐だけです。以下も固定すると、AGENTS.md の走査器共通規約により適合します。

- 見出しなし
- 開始 `{` なし
- 対応する `}` なし
- 正常なネスト
- JSON 文字列中に `{` / `}` がある場合の扱い

現在の単純な波括弧カウントは、引用符内の括弧を構文上の括弧と区別しません。保証外とするなら docblock に明記が必要です。

### `dev:pipeline-smoke` の見送り

今回の見送りは妥当です。実課金・外部送信を伴う手動 smoke は、OCR の本番経路を完成させるための必須実装ではなく、概念設計のスコープ外記述と「今必要なものだけ作る」に整合します。

ただし detailed design の施策 4 と概念設計で記述が割れている状態は残るため、どちらを正本とするか文書上で解消してください。

## 問題なしと判断した主な箇所

- [app/Support/Llm/PromptDefense.php](/workspace/.claude/worktrees/tasks/T234/app/Support/Llm/PromptDefense.php) から `GuardedPrompt` へ至る実行経路自体は、既存の窓口方式を維持しています。
- `SopExtractFromMediaPrompt` は `LlmCallContextData` を必須とし、帰属を落としていません。
- YAML への prompt 分離、防御指示、canary、応答検査の共有は適切です。
- `response()->json()` の追加はありません。
- Svelte 変更は DS token のみで、hex 直書き、disabled 化、Atomic Design の逆流は見当たりません。
- `pages → features/manual` の依存方向は許可範囲です。
- `AnalysisFailureReason` による分岐は message 依存を除去できています。
- OCR 結果に対するスキーマ検証 → 日本語比率検査の順序は妥当です。

最後に、`composer test` のフル実行がまだ完了していません。上記修正に加え、全検証コマンドの green が確認されるまでは実装完了条件を満たしません。