**レビュー結果**

コマンド実行は禁止指定のため、追加検証はしていません。提示された green 結果を前提に、diff と設計書の一致性をレビューしました。

**ファイル別判定**

[app/Http/Controllers/Projects/ProjectController.php](/workspace/.claude/worktrees/tasks/T182/app/Http/Controllers/Projects/ProjectController.php)
- [Warning] `manualRows()` の範囲外ページ丸めが `total() > 0` のときだけです。`?page=99` かつ一覧 0 件の場合、`last_page=1` なのに `current_page=99` のような props になり得ます。M4 の「範囲外ページの丸め」と pagination UI の正本性に反します。`currentPage() > lastPage()` なら total 0 でも `lastPage()` へ丸めるべきです。
- 追加テスト: 0 件の一覧で `?page=99` / 巨大 page を渡したとき `meta.current_page=1`。

[app/DataTransferObjects/Manual/ManualListQuery.php](/workspace/.claude/worktrees/tasks/T182/app/DataTransferObjects/Manual/ManualListQuery.php), [app/Http/Controllers/Projects/VideoManualController.php](/workspace/.claude/worktrees/tasks/T182/app/Http/Controllers/Projects/VideoManualController.php)
- [Warning] `category` は `ctype_digit()` だけで、値を生文字列のまま `toQueryParams()` から Location に戻しています。巨大な数字列や `0003` がそのまま redirect URL に残り、設計の「生のユーザー入力を Location に素通ししない」に対して弱いです。ID は int なので、受理時に `(int)` 化して canonical な文字列へ戻す、または上限超過を null にする方が安全です。
- 追加テスト: `category=0003`、`category=<PHP_INT_MAX超の数字列>` が canonicalize または破棄されること。

[app/Support/Security/RenderArtifactSelectionInventory.php](/workspace/.claude/worktrees/tasks/T182/app/Support/Security/RenderArtifactSelectionInventory.php), [tests/Architecture/CurrentRenderArtifactInventoryTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Architecture/CurrentRenderArtifactInventoryTest.php), [app/Enums/Security/RenderArtifactSelectionKind.php](/workspace/.claude/worktrees/tasks/T182/app/Enums/Security/RenderArtifactSelectionKind.php), [app/Models/VideoManual.php](/workspace/.claude/worktrees/tasks/T182/app/Models/VideoManual.php)
- [Warning] `EagerLoadCandidate` の機械検査は「登録ファイルが `output_path` を参照しない」だけなので、`Models/VideoManual.php` 全体が新しい render artifact 選択式を追加できる免除領域になります。これは deny-by-default 目録としては弱まっています。少なくとも `latestSucceededRender()` というメソッド名、`hasOne(RenderJob::class)->ofMany(...)`、`kind=Render`、`status=Succeeded`、候補 relation が 1 つだけ、を pin する検査が必要です。
- behavioral parity テストは有効ですが、将来同ファイルに別の候補 relation が増えた場合を止められません。

[app/DataTransferObjects/Manual/ManualListItemData.php](/workspace/.claude/worktrees/tasks/T182/app/DataTransferObjects/Manual/ManualListItemData.php), [tests/Feature/Manual/ManualRowDownloadableParityTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Feature/Manual/ManualRowDownloadableParityTest.php)
- [Warning] `downloadable` は `published` 条件を含みますが、parity テストが `ready + succeeded render + output_pathあり` の download endpoint 側 404 を固定していません。「download endpoint が 302 を返す条件と 1 対 1」と書くなら、このケースも endpoint と一覧 props の一致をテストすべきです。

[app/DataTransferObjects/Manual/ManualListRefData.php](/workspace/.claude/worktrees/tasks/T182/app/DataTransferObjects/Manual/ManualListRefData.php)
- 指摘なし。DTO shape は明確です。

[app/Services/Manual/ManualRowAbilities.php](/workspace/.claude/worktrees/tasks/T182/app/Services/Manual/ManualRowAbilities.php)
- 指摘なし。代表行方式の前提と崩れた場合の手順が明記され、Feature test も対応しています。

[database/factories/VideoManualFactory.php](/workspace/.claude/worktrees/tasks/T182/database/factories/VideoManualFactory.php)
- 指摘なし。`published(?int $totalLengthMs = null)` は設計どおりです。

[resources/js/components/features/manual/ManualListRow.svelte](/workspace/.claude/worktrees/tasks/T182/resources/js/components/features/manual/ManualListRow.svelte), [resources/js/pages/Projects/Show.svelte](/workspace/.claude/worktrees/tasks/T182/resources/js/pages/Projects/Show.svelte), [resources/js/types/manual.ts](/workspace/.claude/worktrees/tasks/T182/resources/js/types/manual.ts), [resources/js/lib/manual/format-duration.ts](/workspace/.claude/worktrees/tasks/T182/resources/js/lib/manual/format-duration.ts)
- 指摘なし。DS token、Lucide、Atomic Design の向き、disabled を置かない方針は守られています。`URLSearchParams` 回避も妥当です。

[tests/Feature/Projects/ManualListQueryCountTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Feature/Projects/ManualListQueryCountTest.php), [tests/Feature/Projects/ManualRowAbilityPremiseTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Feature/Projects/ManualRowAbilityPremiseTest.php), [tests/Feature/Projects/ManualRowActionsTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Feature/Projects/ManualRowActionsTest.php), [tests/Feature/Projects/ProjectShowManualsTest.php](/workspace/.claude/worktrees/tasks/T182/tests/Feature/Projects/ProjectShowManualsTest.php), [tests/js/**]
- 主要契約は広く固定されています。ただし上記の 0 件ページ丸め、category canonicalize、ready+succeeded の endpoint parity が不足しています。

**全体判定: CHANGES_REQUESTED**