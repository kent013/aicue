提示された全文 diff と設計要旨を照合した限り、実装内容にロジック上の問題は見つかりません。フラグ撤去、常時有効化、props 削除、テストの畳み込みはいずれも設計どおりです。ただし、修正後のテスト完走がまだ確認できないため、現時点では承認できません。

## Critical

### 全体 — 修正後コードの検証が未完了

最後に完了した `composer test` は修正前で3件失敗しています。GIF fixture へ変更した現在のコードに対するフルスイート結果は「実行中」で、成功結果がありません。

また、フロントテストを3ファイル変更していますが、`pnpm test` の実行結果も提示されていません。`pnpm lint` と `pnpm typecheck` ではテストの期待値や描画挙動は検証されません。

最低限、以下の成功確認が必要です。

- 修正後の `composer test`
- `pnpm test`
- 完了・コミット報告をする場合は AGENTS.md に列挙された残りの検証コマンドもすべて green

## Warning

なし。

## Suggestion

### `tests/Feature/Projects/SourceDocumentUploadOcrTest.php`

公開面の一貫性テストに、create/show の両方について次を追加すると、props の「完全撤去」を回帰検出できます。

```php
->missing('imageSourceDocumentsEnabled')
```

現在のテストは新しい固定値を確認しますが、古い prop が再追加されても失敗しません。実装自体では正しく撤去されています。

同ファイルの「jpg/png アップロードが成功する」というテスト名に対し、提示された該当テスト本体で明示的に成功確認しているのは JPEG です。PNG は単体テストの受理集合や後続テストで間接的に扱われていますが、タイトルを JPEG に限定するか、別マニュアルを使って PNG の成功も直接確認すると主張が明確になります。

## ファイル別判定

| ファイル | 判定 |
|---|---|
| `app/Http/Controllers/Projects/VideoManualController.php` | 問題なし。create/show 双方から旧 prop を撤去 |
| `app/Rules/SourceDocumentSizeLimit.php` | 問題なし。sniff MIME に基づく容量分類は不変 |
| `app/Services/Manual/AnalysisPipeline.php` | 問題なし。画像の直接 OCR、PDF の対象理由だけのフォールバック、route 更新順が維持されている |
| `app/Services/Manual/SopTextExtractor.php` | 問題なし。責務を変えない docblock 更新 |
| `app/Support/Manual/AcceptedSourceDocumentTypes.php` | 問題なし。受理拡張子・sniff MIME・ラベルが画像込み固定値になり、旧メソッドも撤去 |
| `config/manual.php` | 問題なし。config/env の実行可能な参照を完全撤去 |
| `docs/architecture.md` | 問題なし。現在の常時有効状態と履歴を正確に記載 |
| `docs/rollout-checklists.md` | 問題なし。旧手順を実行不可の履歴として明確に隔離 |
| `resources/js/components/features/manual/SourceDocumentUpload.svelte` | 問題なし。prop の透過を撤去 |
| `resources/js/components/features/manual/SourceDocumentUploadNotice.svelte` | 問題なし。警告の常時表示化。既存 DS token と階層責務も維持 |
| `resources/js/pages/Manuals/Create.svelte` | 問題なし。旧 prop を型・分割代入・子への受け渡しから撤去 |
| `resources/js/pages/Manuals/Show.svelte` | 問題なし。同上 |
| `tests/Feature/Manual/Analysis/AnalysisPipelineOcrTest.php` | 問題なし。構造的に生成不能になった無効状態だけを削除し、主要な OCR 成功・失敗・ログ検証を維持 |
| `tests/Feature/Projects/SourceDocumentUploadOcrTest.php` | 実装上は問題なし。上記 Suggestion あり |
| `tests/Feature/Projects/SourceDocumentUploadTest.php` | 問題なし。PNGから未受理のGIFへの置換は妥当で、拒否・偽装・Service二層目という検査対象は不変 |
| `tests/Unit/Support/Manual/AcceptedSourceDocumentTypesTest.php` | 問題なし。固定集合・順序・ラベル・対象外形式を維持 |
| `tests/js/components/features/manual/SourceDocumentUpload.test.ts` | 問題なし。ただし実行結果が必要 |
| `tests/js/components/features/manual/SourceDocumentUploadNotice.test.ts` | 問題なし。ただし実行結果が必要 |
| `tests/js/pages/ManualsCreate.test.ts` | 問題なし。ただし実行結果が必要 |

DTO/JsonResource パターンは既存の Inertia props 方式を変更しておらず妥当です。セキュリティ境界もクライアント申告値へ移っておらず、Atomic Design・DESIGN.md 上の新たな逸脱もありません。

**CHANGES_REQUESTED**