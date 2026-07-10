# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED（Round 2）→ 対応の上 Round 3 へ。

## [Critical] Service 例に旧 relation 名 videoManuals() 残存 + stale $project 使用
- 判断: 対応する
- 対応内容: `VideoManualService::create` を `$locked->manuals()` / `$locked->categories()` に修正。全操作でロック済み `$locked` の relation のみ使用（stale `$project` 不使用）を明記。

## [Critical] CategoryService に update メソッド欠落
- 判断: 対応する
- 対応内容: `update(Project, Category, string $name)` を追加（Project 行ロック → self 除外の重複再検査 → fill save）。

## [Critical] Controller と route の不整合（create 欠落）
- 判断: 対応する
- 対応内容: `VideoManualController::create` を正式メソッドに追加（`Gate::authorize('create', [VideoManual::class, $project])` → Manuals/Create render + categories props、typed-array PHPDoc、撮影者 403 の Feature）。

## [Warning] Category 複合 unique 違反が 500 化
- 判断: 対応する
- 対応内容: Request に project-scoped `Rule::unique('categories','name')->where('project_id',$projectId)`（Update は `ignore`）。並行競合は Service の `assertNameUnique`（ロック後再検査）で 422 に先回り。

## [Warning] 空 reorder で不正 CASE 式
- 判断: 対応する
- 対応内容: 集合一致後、`$orderedIds === []` なら早期 return（no-op）。

## [Warning] Policy reorder 認可シグネチャが曖昧
- 判断: 対応する
- 対応内容: `CategoryPolicy::reorder(User, Project)` を定義（create と同じ「対象なし + Project 引数」パターン、ItemPolicy::create 見本）。Controller は `Gate::authorize('reorder', [Category::class, $project])`。update/delete は Category 引数で `$category->project` 経由委譲に明記。

## [Warning] migration down に parent_cut_id FK drop 欠落
- 判断: 対応する
- 対応内容: 後付け migration の down で parent_cut_id / adopted_take_id 両 FK を dropForeign、その後逆順 drop、とリスク節を修正。

## [Warning] テストケース追加
- 判断: 対応する
- 対応内容: Category create/update 重複 422、空 reorder 成功、Category update/destroy cross-project 404、Manual create 画面認可・props、category 送信成功、を施策12 に追加。
