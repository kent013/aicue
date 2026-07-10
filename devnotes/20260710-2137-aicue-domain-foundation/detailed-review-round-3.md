## 施策別判定

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **APPROVE**
- 施策4: **APPROVE**
- 施策5: **APPROVE**
- 施策6: **APPROVE**
- 施策7: **REQUEST_CHANGES**
- 施策8: **APPROVE**
- 施策9: **APPROVE**
- 施策10: **APPROVE**
- 施策11: **APPROVE**
- 施策12: **REQUEST_CHANGES**

## 残存指摘

- [Critical] `CategoryService::update/delete` がロック済みProject relationから子を再解決していません。  
  `update()` は受け取った `$category` を直接更新し、`delete()` も直接削除しています。「全操作で `$locked` の relation のみ使用」および「子は親に属する」というService境界の不変条件と矛盾します。route binding以外からServiceが呼ばれた場合、cross-project更新が可能です。  
  **修正案:** ロック後に必ず次のように再解決してください。
  ```php
  $lockedCategory = $locked->categories()
      ->whereKey($category->id)
      ->firstOrFail();
  ```
  重複検査の除外・更新・削除には `$lockedCategory` のみを使用します。

- [Critical] `VideoManualService::updateMeta/delete` にも同じ再解決契約を明記する必要があります。  
  現状は「`$locked->manuals()`を使う」とだけあり、対象manualを親relationから再解決するか不明です。  
  **修正案:** 両操作で `$locked->manuals()->whereKey($manual->id)->firstOrFail()` を実行してから更新・削除すると設計に明記してください。

- [Warning] Service一覧が改訂内容と不一致です。  
  `CategoryService（create / reorder / delete）` に `update` がありません。  
  **修正案:** `create / update / reorder / delete` に修正してください。

- [Warning] 上記Service直接呼び出し時の防御テストが不足しています。  
  **修正案:** 別ProjectのCategory/VideoManualをServiceへ渡した場合に404となり、DBが変更されないテストを追加してください。routeのcross-project 404とは別のService境界テストです。

## Round 2対応状況

relation名称、Category更新、create画面、重複制御、空reorder、専用Policy、migration down、既存Featureテスト追加は適切に解消されています。

## 全体判定

**CHANGES_REQUESTED**

残件は親ロック後の子再解決に限定されます。これはcross-project不変条件に直接関係するため、最終承認前に設計への明記が必要です。