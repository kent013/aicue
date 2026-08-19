# 対応マトリクス: design-review Round 4

## [Critical] PDF OCR フォールバックの media 検証失敗で route が `text` に誤記録される
- 判断: 対応する
- 根拠: 指摘のとおり、`resolveExtractInput()` の戻り値の型で route を判定する設計では、
  `validatePdfForOcr()` 自体が例外を投げて戻り値が得られないケースを route に反映できない。
- 対応内容: `$route` を参照渡しにし、`validatePdfForOcr()` を**呼ぶ直前**に `'ocr'` へ
  更新する設計へ書き直した。呼び出し自体が失敗しても route は既に更新済みのため正しく記録される。

## [Critical] OCR 専用の `dev:pipeline-smoke` シナリオが無く、帰属到達を検証できない
- 判断: 対応する
- 根拠: 指摘のとおり。既存の smoke はテキスト SOP fixture しか使わないため、
  `sop-extract-media` は一度も実行されず、「既存 3 段と同じ仕組みだから大丈夫」という
  説明は新設した 4 段目の検証にはならない。
- 対応内容: `PipelineSmokeCommand` に `--source-kind=text|image|pdf-ocr` オプションを
  追加し、OCR シナリオ選択時は専用 fixture をアップロードして
  `config(['manual.ocr_analysis_enabled' => true])` を明示設定する設計を追加した。
  `organization_id`/`subject_type`/`subject_id`/`prompt_template` の検証をシナリオに
  応じてパラメータ化する方針も明記した。実装時に他タスク (worktree T232/T233) と
  ファイルが競合しうることも明記した。

## [Critical] 施策 10 本文が Round 2 以前の内容のまま更新されていなかった
- 判断: 対応する
- 根拠: 対応マトリクスで「対応済み」としていたが、実際には詳細設計本文を書き換えて
  いなかった (見落とし)。
- 対応内容: 対象コンポーネント (`SourceDocumentUpload.svelte`)・親ページ
  (`Show.svelte`)・Controller への具体的な変更内容、TypeScript 型定義・Inertia Props
  の追加箇所、法務文言の実際の分岐に合わせた訂正まで、本文を実際に書き換えた。

## [Warning] `UploadedFile::getSize()`/`getMimeType()` の取得失敗が fail-closed でない
- 判断: 対応する
- 対応内容: `getSize()` が `int` でない場合・`getMimeType()` が `null` の場合、
  どちらも上限内として扱わず拒否する分岐を追加した。

## [Warning] 容量分類がフラグで変化する `AcceptedSourceDocumentTypes::mimes()` に依存
- 判断: 対応する
- 根拠: 指摘のとおり、「画像かどうか」はファイルの性質であり、現在の許可集合とは別概念。
- 対応内容: `str_starts_with($mime, 'image/')` という、フラグに依存しない固定判定へ
  変更した。許可判定 (mimes: ルール) と容量分類 (本 Rule) の責務分離を明記した。

## [Warning] `imageSourceDocumentsEnabled: bool` が波及変更に記載されていない
- 判断: 対応する
- 対応内容: 施策 1 の波及変更・テスト計画へ追記した。

## [Warning] generic `PrismException` の HTTP status が `unknown` に落ちる
- 判断: 対応する
- 対応内容: `observabilityCategoryFor()` に `extractHttpStatus()` を使った分岐を追加し、
  `userMessageFor()` と同じ status 定数で `timed_out`/`provider_busy` に分類した。
  `UntrustedInputRejectedException` の分類も追加した。

## [Warning] `JobOwnershipLostException` を失敗として記録するかが未定義
- 判断: 対応する
- 対応内容: `logExtractStageTerminal()` の先頭で `JobOwnershipLostException` を検出したら
  何も記録せず早期 return する設計にした (正常系のノイズを失敗率の集計対象に含めない)。

## [Warning] 静的 gate のテスト計画が「4 ルール」のまま (実際は 5 ルール)
- 判断: 対応する
- 対応内容: 「5 ルール」に訂正し、`VendorMediaTypeSubclassDeclaration` については
  母集団非空 (scanner 自己検査) と違反 0 件 (本 gate) を区別して記載した。

## [Warning] `VendorMediaTypeSubclassDeclaration` の負例がテスト計画に無い
- 判断: 対応する
- 対応内容: 別名 import 版・group use 版・無名クラス版の負例と、同じ短名の別
  namespace クラスを誤検出しない正例をテスト計画に追加した。

## [Warning] 法務文言がテキスト層を正常抽出できる PDF には当てはまらない
- 判断: 対応する
- 対応内容: 「画像や、文字を読み取れないスキャン PDF は紙面の見た目がそのまま送信される」
  という、OCR 経路とテキスト経路の性質の違いを正確に反映した文言へ訂正した。

## [Suggestion] synthetic 確認後のチケット後始末が ledger の破壊的修正に読める
- 判断: 対応する
- 対応内容: 「消費したチケットは通常の grant または検証費用として計上し、既存の課金
  ledger を削除・巻き戻さない」ことを明記した。

## [Suggestion] route:cache 運用要件との紛らわしさ
- 判断: 対応済み (Round 3 で対応)
