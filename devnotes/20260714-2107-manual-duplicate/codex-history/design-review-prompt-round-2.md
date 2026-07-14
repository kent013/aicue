## Round 2: 詳細設計の改訂（Round 1 指摘への対応）

使命・禁止事項は再掲不要。残 Critical/Warning があるか判定してください。

### 対応サマリー

- **[Critical] 施策4 category 再解決 firstOrFail が 404（契約後退）**: **反論（404 維持）**。根拠 = **既存 `VideoManualService::create()` が同じ再解決を `$locked->categories()->whereKey($categoryId)->firstOrFail()` で 404 にしている**（現行コード）。よって 404 化は store/create の**既存契約であり後退ではない**。通常の不正・他 project category は FormRequest の `Rule::exists('categories','id')->where('project_id',$projectId)` で **422**（検証時）に落ち、404 になるのは「検証通過後に category が削除/移動された」ごく稀な競合のみ（create と完全一致）。概念レビュー（gpt-5.4）も race=404 で合意済み。設計に「firstOrFail(404) は create と同一挙動。通常不正は 422、404 は検証後競合のみ」と明記。

  → この事実（既存 create が firstOrFail=404）を踏まえても 422 に変えるべきという主張がまだあれば、既存 create との契約不整合を許容する理由を示してほしい。

- **[Warning] 施策4 point 順序が全体順序依存**: copyCuts は `orderBy('sort_order')->orderBy('id')->get()` 後に Collection::where で filter。Eloquent Collection の filter は順序保持のため親内 point は sort_order 準拠（CutSequencer と同順）。その旨をコメント固定。
- **[Warning] 施策4 孤児 point skip がデータ破損黙殺**: skip 時に `Log::warning('マニュアル複製: 親不明の急所カットを複製対象から除外しました', ['source_manual_id'=>, 'cut_id'=>, 'parent_cut_id'=>])` を出す。`use Illuminate\Support\Facades\Log;` 追加。施策9 に孤児 point テスト（複製されない + warning ログ）を追加。
- **[Warning] 施策9 退行検知強化**: reset 確認を step **および** point 両層で adopted_take_id=null・cut_length_ms=null を明示 assertion 化。scenario_version=0/status=draft も維持。
- **[Suggestion]** categoryId() の numeric string 許容コメント、dialog transform の Number 変換安全コメントを追記。

### 改訂後の該当コード（施策4 copyCuts / category 再解決）
```php
if ($categoryId !== null) {
    // 既存 create() と同一の firstOrFail。通常不正/他 project は FormRequest で 422、
    // 404 は検証通過後の削除/移動競合のみ (create と完全一致・後退なし)。
    $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
    $new->category()->associate($category)->save();
}
// ...
private function copyCuts(VideoManual $source, VideoManual $target): void
{
    // initial orderBy(sort_order,id) を維持したまま filter (Collection の filter は順序保持)
    $cuts = $source->cuts()->orderBy('sort_order')->orderBy('id')->get();
    $newStepByOldId = []; // 旧 step id → 新 step Cut
    foreach ($cuts->where('type', CutType::Step) as $step) {
        $newStepByOldId[$step->id] = $this->replicateCut($target, $step, null);
    }
    foreach ($cuts->where('type', CutType::Point) as $point) {
        $parentOldId = $point->parent_cut_id;
        if ($parentOldId === null || ! isset($newStepByOldId[$parentOldId])) {
            Log::warning('マニュアル複製: 親不明の急所カットを複製対象から除外しました', [
                'source_manual_id' => $source->id, 'cut_id' => $point->id, 'parent_cut_id' => $parentOldId,
            ]);
            continue;
        }
        $this->replicateCut($target, $point, $newStepByOldId[$parentOldId]->id);
    }
}
```

質問: 上記で全 Critical/Warning が解消しているか。特に category 404 維持の反論が妥当か判定してほしい。
