# 実装レビュー Round 1 対応マトリクス

## Critical

### PromptWindowScanner: 動的 callable / 中括弧動的メソッド名で `VendorMediaTypeConstruction` /
`MediaDataNamedConstructorCall` を回避できる

**対応する (docblock で保証範囲を明示)。** `[Image::class, 'fromRawContent']` 配列 callable、
`Image::{$method}(...)` 中括弧動的メソッド名のいずれも、字句 (トークン) レベルの走査である
`PhpReferenceScanner` の既存アーキテクチャ全体の限界であり (`::` トークン列を経由しない、
または `::` の直後が `T_STRING` ではない)、既存の `VendorPromptLoad`/`WindowLoad` ルールも
同じ形で回避できる (今回新設したルールに固有の欠陥ではない)。データフロー解析が要る
検出をこの走査器へ追加するのは本施策のスコープを超えるため、`classify()` の docblock へ
「保証しないもの」として明記した (`docs/architecture.md` の既存宣言「動的に組み立てた
クラス名…には沈黙する」と同じ扱い)。

## Warning

### SourceDocumentService: 画像を一度アップロードすると二度と差し替えられない

**見送る (設計文書どおりの実装であり、design review Round 1/3 で既に確定済みの意図的トレードオフ)。**
detailed-design.md 施策 1 の「リスク」節が「対象 VideoManual に画像 mime の SourceDocument が
既に 1 件以上存在する場合、拒否は『新しい画像の追加を拒否する』形にする(既存の画像を削除しない)」
と明記しており、これは design review で複数ラウンドの議論を経て確定した仕様である。
UI の「差し替える」という一般文言は非画像の差し替え (PDF→PDF 等) には正しく機能する。
画像の再アップロード (撮り直し) を許可する設計変更は本施策のスコープ外の別 TODO とする。

### AnalysisMediaValidator: PDF 側に実バイトの MIME sniff が無い (画像側との非対称)

**対応する。** `finfo` による実バイト sniff を追加し、persisted mime との不一致を
`MediaUnreadable` として拒否するようにした (`AnalysisMediaValidator::validatePdfForOcr()`)。
負例テストを追加した (`persisted mime は application/pdf だが実バイトが PDF でない場合は
MediaUnreadable`)。

### SourceDocumentUploadOcrTest: sniff MIME 判定のテストが `UploadedFile::fake()->create()` の
内部実装 (宣言 mime) に依存しており、client mime への回帰を検出できない可能性

**対応する。** 実バイト (JPEG) を一時ファイルへ書き込み、`UploadedFile` を直接構築して
client 申告 (拡張子・mime) だけを偽装したテストを追加した (`容量上限の判定材料は sniff MIME
である (実バイトと client 申告が食い違う場合)`)。`getMimeType()` (実 sniff) と
`getClientMimeType()` (偽装値) が異なることを構築直後にアサートしてから検証している。
既存のテスト (Laravel 内部実装依存版) も保持し、両方でカバーする。

### HEIC 拒否文言が汎用メッセージのままで「JPEG / PNG で保存し直す」という次アクションが無い

**対応する。** `StoreSourceDocumentRequest` / `StoreVideoManualRequest` の両方に
`messages()` を追加し、`mimes` ルールの汎用文言を「対応していないファイル形式です。
PDF・Excel・テキスト形式、または JPEG・PNG の画像でアップロードし直してください。」
(フラグ無効時は画像の案内を除く) へ差し替えた。HEIC 拒否テストで文言を検証するよう更新した。

### StoreVideoManualRequest (新規マニュアル作成時) のフラグ true/false 統合テストが無い

**対応する。** `新規マニュアル作成時 (StoreVideoManualRequest) もフラグに応じて jpg 受理可否が
変わる` テストを追加した。

### logExtractStageTerminal が「ジョブにつきちょうど 1 回」と過大に主張している
(実際は `run()` の 1 回の実行につき 1 回であり、stale 回復等での再実行では複数行になりうる)

**対応する。** `AnalysisPipeline::logExtractStageTerminal()` の docblock、
`docs/architecture.md`、`docs/rollout-checklists.md` の記述を「`run()` の 1 回の実行につき
ちょうど 1 回」へ訂正し、永続化された冪等キーを持たないこと・集計は解析ジョブ単位に丸める
方針であることを明記した。

## Suggestion

### SopExtractMediaSchemaSyncTest: 負例が「見出し複数」の 1 分岐だけ

**対応する。** 「見出しなし」「開始 `{` なし」「対応する `}` なし」「正常なネスト」の負例・正例を
追加し、引用符内の括弧を区別しない (JSON 文字列リテラル中の `{`/`}`) という保証範囲外を
docblock に明記した。

### `dev:pipeline-smoke` の見送り

**見送る (Codex も「妥当」と判定)。** 概念設計のスコープ外記述をそのまま踏襲する。
detailed design 施策 4 との記述の食い違いは、概念設計を正 (最終ラウンドまでスコープ外の
まま確定) として扱う。矛盾の解消は別途 devnotes の記録更新に譲る (本 PR の実装差分には
影響しない)。
