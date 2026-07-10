## Round 5: Round 4 指摘への対応と最終再レビュー依頼

Round 4（並行制御の Warning 1 点）に対し、以下の通り改訂しました。**残 Critical/Warning がないか、APPROVED 可否を最終判定してください。**

### 対応内容

- [Warning] Category 全行 `lockForUpdate()` では新規 insert を直列化できない（行ロックは未作成行を守らない・0 件 project の同時作成で同一 max+1 採番）→ **create / delete / reorder の全処理で transaction 冒頭に共通の `Project` 行を `lockForUpdate()`** し、その後に Category 集合取得・`max(sort_order)+1` 計算・集合再検証・更新を実行。Category 全行ロックは不要。project 単位で確実に直列化。ロック中は増減しないため reorder の集合不一致は 422 のみ（409 は発生しない）。

### 改訂後の該当箇所（抜粋）

**実装方針（Category sort_order 並行制御）**:
> 行ロックは未作成行を守らないため「Category 全行ロック」では新規 insert（0 件 project での同時作成含む）を直列化できず、同時作成が同じ `max(sort_order)+1` を採番しうる。したがって **create / delete / reorder の全処理で、transaction 冒頭に共通の `Project` 行を `lockForUpdate()`** し、そのロック取得後に Category 集合の取得・`max` 計算・集合再検証・更新を行う。これで project 単位に確実に直列化され、Category 全行ロックは不要。順序は「後勝ち」ではなく「ロック取得順に直列化」。reorder は集合一致検証（distinct・過不足なし）を Project ロック取得後に行い、不一致は 422。

上記以外は Round 4 版から変更ありません。
