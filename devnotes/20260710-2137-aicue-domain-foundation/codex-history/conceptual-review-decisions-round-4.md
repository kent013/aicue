# 対応マトリクス: conceptual-review Round 4

全体判定: CHANGES_REQUESTED（Round 4、並行制御の Warning 1 点）→ 対応の上 Round 5 へ。

## [Warning] Category 全行 lockForUpdate では新規 insert を直列化できない
- 判断: 対応する
- 根拠: 正当。PostgreSQL の行ロックは未作成行をロックしないため、reorder 中の insert や 0 件 project の同時作成を防げず、同時作成が同じ max+1 を採番しうる。
- 対応内容: create / delete / reorder の全処理で transaction 冒頭に共通の Project 行を `lockForUpdate()` し、その後に Category 集合取得・max 計算・集合再検証・更新を行う方式へ変更。Category 全行ロックは不要。ロック中は増減しないため reorder の集合不一致は 422 のみ（409 は発生しない）に整理。
