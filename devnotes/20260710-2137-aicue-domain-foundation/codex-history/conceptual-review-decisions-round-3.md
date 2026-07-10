# 対応マトリクス: conceptual-review Round 3

全体判定: CHANGES_REQUESTED（Round 3、残り reorder 契約の Warning 2 点）→ 対応の上 Round 4 へ。

## [Warning] Category Store/Update が sort_order を受けると reorder 契約を迂回（重複・欠番）
- 判断: 対応する
- 根拠: 正当。sort_order を通常更新から任意設定できると専用 reorder の全件再採番契約が破れる。
- 対応内容: Category FormRequest 入力を name のみに限定（sort_order 除外）。作成時は Store Service が `max(sort_order)+1` を末尾採番、以後の変更は reorder Service のみ、と明記。

## [Warning] transaction だけでは並行 reorder / reorder と作成・削除の競合を直列化できない
- 判断: 対応する
- 根拠: 正当。複数行更新の並行で混在順・一部未更新、集合検証と更新の間の増減競合が残る。
- 対応内容: reorder Service は当該 project の Category 全行を id 昇順で `lockForUpdate()` → ロック後に集合一致を再検証（増減時は 409/再取得）→ 配列順に再採番。作成・削除も同じ project スコープのロック規約で直列化。表現を「後勝ち」→「ロック取得順に直列化」に修正。

## [Suggestion] parent_cut_id も same-manual を将来条件に
- 判断: 対応する（採用）
- 対応内容: Tier B 将来必須条件に parent_cut_id（親手順は同一 video_manual 所属を relation 経由解決・cross-manual 404）を追加。
