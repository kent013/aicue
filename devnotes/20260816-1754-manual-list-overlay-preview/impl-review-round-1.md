提供 diff ベースで確認しました。コマンド実行は禁止条件のため、テストは再実行していません。提示された green 結果は前提情報として扱っています。

**指摘**
なし。Critical / Warning はありません。

**ファイル別判定**
- `app/DataTransferObjects/Manual/ManualListItemData.php`: OK。選択責務は `CurrentRenderArtifact` に移っており、DTO 側には published / ability 判定だけが残っています。
- `app/Services/Manual/CurrentRenderArtifact.php`: OK。`currentSucceeded()` と `fromLoadedRenderCandidate()` が同じ `receivable()` を通り、最新 succeeded の `output_path=NULL` で旧世代へフォールバックしない規則も維持されています。
- `app/Models/VideoManual.php`: OK。docblock が責務移管後の説明に追随しています。
- `app/Support/Security/RenderArtifactSelectionInventory.php`: OK。Canonical 消費者数と rename 後のテスト名が同期されています。
- `app/Http/Controllers/Projects/ProjectController.php`: OK。Inertia props の shape 更新のみで妥当です。
- `resources/js/types/manual.ts`: OK。旧 `downloadable` から `current_finished_render_job_id` への置換は設計通りです。
- `resources/js/components/features/manual/ManualPreviewModal.svelte`: OK。署名 URL を props/HTML に直接載せず、同一オリジン route のみを `src` にしています。DS token / atomic import / lucide 制約にも反していません。
- `resources/js/components/features/manual/ManualListRow.svelte`: OK。プレビューと DL が同じ props 分岐にあり、disabled ボタンも増えていません。
- `resources/js/pages/Projects/Show.svelte`: OK。モーダルをページに 1 つだけ置く配線で、行ごとの Dialog 増殖はありません。
- `tests/Architecture/CurrentRenderArtifactInventoryTest.php`: OK。DTO への選択式逆流を検出するテストとして妥当です。
- `tests/Feature/Manual/ManualRowFinishedVideoParityTest.php`: OK。props と download/playback endpoint の非対称、旧世代直叩き、撮影者 403 が押さえられています。
- `tests/Feature/Projects/ProjectShowManualsTest.php`: OK。旧キー不在、kind=render 限定、権限差分が固定されています。
- `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php`: OK。追加クエリ 0、未ロード例外、NULL output_path が固定されています。
- `tests/js/components/features/manual/ManualListRow.test.ts`: OK。
- `tests/js/components/features/manual/ManualPreviewModal.test.ts`: OK。
- `tests/js/pages/ProjectsShow.test.ts`: OK。

[Suggestion] `tests/Unit/Manual/CurrentRenderArtifactLoadedCandidateTest.php` の query log は、退行時の例外でも `DB::disableQueryLog()` が必ず走るよう `try/finally` にしておくとテスト間のグローバル状態漏れにより強くなります。必須修正ではありません。

**全体判定: APPROVED**