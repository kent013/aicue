## Round 4: Round 3 指摘への対応と最終再レビュー依頼

Round 3（CHANGES_REQUESTED、親ロック後の子再解決）に対し、以下の通り改訂しました。**残 Critical/Warning がないか、APPROVED 可否を最終判定してください。**

### 対応サマリー

- [Critical] CategoryService::update/delete の子再解決 → 両メソッドで `$lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail()` を実行し、以後 `$lockedCategory` のみ使用（cross-project は 404）。
- [Critical] VideoManualService::updateMeta/delete の再解決契約 → `$locked->manuals()->whereKey($manual->id)->firstOrFail()` を明記。category も `$locked->categories()` から再解決 associate / null で dissociate。
- [Warning] Service 一覧 → `CategoryService（create / update / reorder / delete）` に修正。
- [Warning] Service 境界テスト → 施策12 に「別 project の Category/VideoManual を Service update/delete に渡すと 404・DB 無変更」を追加。施策7 冒頭に Service 境界の不変条件（全メソッドはロック後に子を親 relation から再解決）を明記。

### 改訂後の該当箇所（抜粋）

**施策7 Service 境界の不変条件**:
> 全メソッドはロック取得後に対象の子（Category/VideoManual）を親 relation から再解決してから操作する。route binding 以外の経路から cross-project の子を渡されても firstOrFail（→404）で拒否し、DB を変更しない。

**CategoryService::update**:
```php
$locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
$lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail(); // 子再解決
$this->assertNameUnique($locked, $name, $lockedCategory->id);
$lockedCategory->fill(['name' => $name])->save();
```
**CategoryService::delete**: 同様に `$lockedCategory` を再解決してから delete。
**VideoManualService::updateMeta/delete**:
```php
$locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
$lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail(); // 再解決
// updateMeta: fill title + category 再解決 associate / null で dissociate; delete: delete
```

**施策12 追加テスト**:
> Service 境界防御: 別 project の Category/VideoManual を Service update/delete に直接渡すと 404、対象が変更・削除されない。

上記以外は Round 3 版から変更ありません。
