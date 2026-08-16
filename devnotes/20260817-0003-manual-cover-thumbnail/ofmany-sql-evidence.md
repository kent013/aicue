# 検証記録: ofMany(['sort_order' => 'min', 'id' => 'min'], closure) の生成 SQL

実行: `php devnotes/20260817-0003-manual-cover-thumbnail/verify-ofmany-sql.php`
(DB へは 1 件もクエリを投げない。toSql / getBindings のみ。app/ は無変更で匿名派生モデルを使用)

Laravel 12 / vendor で生成 SQL を実測し、**設計どおりの辞書順選択の構造**になっていることを確認した。
eager load のクエリ数は施策 8 の実 DB テストで固定する。

```
=== relation SQL ===
select "cuts".* from "cuts" inner join (select min("cuts"."id") as "id_aggregate", min("cuts"."sort_order") as "sort_order_aggregate", "cuts"."video_manual_id" from "cuts" inner join (select min("cuts"."sort_order") as "sort_order_aggregate", "cuts"."video_manual_id" from "cuts" where exists (select * from "takes" where "cuts"."adopted_take_id" = "takes"."id" and "thumbnail_path" is not null) and "cuts"."video_manual_id" = ? and "cuts"."video_manual_id" is not null group by "cuts"."video_manual_id") as "coverCut" on "coverCut"."sort_order_aggregate" = "cuts"."sort_order" and "coverCut"."video_manual_id" = "cuts"."video_manual_id" where exists (select * from "takes" where "cuts"."adopted_take_id" = "takes"."id" and "thumbnail_path" is not null) group by "cuts"."video_manual_id") as "coverCut" on "coverCut"."id_aggregate" = "cuts"."id" and "coverCut"."sort_order_aggregate" = "cuts"."sort_order" and "coverCut"."video_manual_id" = "cuts"."video_manual_id" where "cuts"."video_manual_id" = ? and "cuts"."video_manual_id" is not null

=== bindings ===
array (
  0 => 42,
  1 => 42,
)

=== eager load SQL ===
select "cuts".* from "cuts" inner join (select min("cuts"."id") as "id_aggregate", min("cuts"."sort_order") as "sort_order_aggregate", "cuts"."video_manual_id" from "cuts" inner join (select min("cuts"."sort_order") as "sort_order_aggregate", "cuts"."video_manual_id" from "cuts" where exists (select * from "takes" where "cuts"."adopted_take_id" = "takes"."id" and "thumbnail_path" is not null) and "cuts"."video_manual_id" is null and "cuts"."video_manual_id" is not null and "cuts"."video_manual_id" in (1, 2, 3) group by "cuts"."video_manual_id") as "coverCut" on "coverCut"."sort_order_aggregate" = "cuts"."sort_order" and "coverCut"."video_manual_id" = "cuts"."video_manual_id" where exists (select * from "takes" where "cuts"."adopted_take_id" = "takes"."id" and "thumbnail_path" is not null) group by "cuts"."video_manual_id") as "coverCut" on "coverCut"."id_aggregate" = "cuts"."id" and "coverCut"."sort_order_aggregate" = "cuts"."sort_order" and "coverCut"."video_manual_id" = "cuts"."video_manual_id" where "cuts"."video_manual_id" is null and "cuts"."video_manual_id" is not null

=== eager bindings ===
array (
)
```

## 読み方 (3 点)

1. **辞書順が SQL の構造として実現している**。内側 = `min(sort_order) group by video_manual_id`
   (候補条件 `exists(takes … thumbnail_path is not null)` 付き) → 中間 = その `sort_order` に
   一致する行の中で `min(id)` → 外側 = `id_aggregate = cuts.id` かつ
   `sort_order_aggregate = cuts.sort_order` で join。**最後の join が主キー一致**なので 1 行に確定する。
2. **候補条件 (closure) は各集約サブクエリに入っている**。外側クエリには入らないが、
   上記 1 の主キー一致 join により結果は変わらない。
3. `addEagerConstraints([...])` を与えると `"cuts"."video_manual_id" in (1, 2, 3)` になり、
   **親を 1 件ずつ絞る形ではなく、まとめて絞る形になる**。
   ただし「**eager load が 1 クエリで済む**」ことの根拠は、この SQL 本文ではなく
   **Laravel の eager load が relation ごとに 1 回だけクエリを発行する仕組み**であり、
   実際にそうなっていることは施策 8 のクエリ数テスト (行数・候補件数・権限の 3 軸) が固定する。

## この記録が保証しないもの

- **実データでの選択結果は保証しない** (SQL 文字列の構造を見ただけである)。
  代表の選択規則とタイブレークは実 DB の Feature テスト (施策 8 #1 / #2) が固定する。
- eager load 形の出力に混ざる `"cuts"."video_manual_id" is null` は、**保存されていないモデルの上で
  relation を作った検証手順の副作用**である (`addConstraints` が親キー null で走ったため)。
  実際の eager load は `Relation::noConstraints()` の下で relation を作るため、この条件は付かない。
- 方言差は見ていない (この出力は既定接続の grammar によるもの)。
