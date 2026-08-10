**指摘**

[Critical] `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php:83` / `:269`  
検出 B の名指し免除が、設計の「ready 判定を持てるのは Canonical だけ」を十分に守れていません。現在の前提検査は「`->adoptedTake` / `?->adoptedTake` のプロパティフェッチを持たない」だけなので、免除ファイル内に `whereHas('adoptedTake', fn (...) => ... TakeStatus::Ready ...)` のような DB クエリ形の同じ判定を追加しても gate が通ります。これは動的アクセスではなく、かなり普通に書ける静的コードなので、設計の deny-by-default より実質的に弱いです。

直し方はどちらかです。  
1. `PipelineSmokeCommand.php` の無関係な `TakeStatus::Ready` 参照か `adoptedTake` 集計を別ファイルへ分離し、検出 B を設計どおり Canonical 1 件に戻す。  
2. 免除を残すなら、免除ファイル内で `'adoptedTake'` を引数に取る `whereHas` / `whereDoesntHave` / `doesntHave` 近傍に `TakeStatus::Ready` や `status` 条件が同居しないことまで token scan で機械検査する。

[Warning] `app/Support/Security/AdoptedTakeReferenceInventory.php:68`  
`PipelineSmokeCommand.php` の rationale が「ready 状態は見ず」と読めますが、提示された mutation 実測記録では同ファイルに `TakeStatus::Ready` の確認が既存で存在します。正確には「`adoptedTake` 参照側の集計は ready を見ない。ready 確認は別対象」という説明に直すべきです。今の文面は gate 免除の判断材料として誤解を招きます。

**ファイル別判定**

| ファイル | 判定 |
|---|---|
| `AGENTS.md` | OK |
| `app/DataTransferObjects/Manual/Render/RenderManifest.php` | OK |
| `app/DataTransferObjects/Manual/Render/RenderResult.php` | OK |
| `app/DataTransferObjects/Manual/RenderJobData.php` | OK |
| `app/DataTransferObjects/Manual/TakeCoverageData.php` | OK |
| `app/Enums/Security/AdoptedTakeReferenceKind.php` | OK |
| `app/Http/Controllers/Projects/VideoManualController.php` | OK |
| `app/Http/Resources/Manual/RenderJobResource.php` | OK |
| `app/Models/RenderJob.php` | OK |
| `app/Services/Manual/AdoptedReadyTakeCoverage.php` | OK |
| `app/Services/Manual/RenderJobService.php` | OK |
| `app/Services/Manual/RenderPipeline.php` | OK |
| `app/Support/Security/AdoptedTakeReferenceInventory.php` | 要修正 |
| `database/factories/RenderJobFactory.php` | OK |
| `database/migrations/2026_08_11_021500_add_placeholder_cut_count_to_render_jobs_table.php` | OK |
| `docs/architecture.md` | OK |
| `resources/js/components/features/manual/RenderPanel.svelte` | OK |
| `resources/js/pages/Manuals/Show.svelte` | OK |
| `resources/js/types/manual.ts` | OK |
| `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` | 要修正 |
| その他の提示テスト差分 | OK |

`playbackJobId` → `playbackJob` の追随、`placeholder_cut_count` の manifest 由来記録、preview を 422 にしない非対称、ボタン非 disabled 方針、`null` と `0` の扱いは、提示 diff 上は設計と一致しています。`coverage` を project_member に返す点も、同じ詳細画面を閲覧できる主体への表示用 props としては過剰な権限拡張には見えません。

コマンド実行は禁止指示があったため、提示された green 結果を前提にレビューしています。

**全体判定: CHANGES_REQUESTED**