# 詳細設計レビュー Round 3

Round 2 のご指摘 2 件へ対応しました。差分のみ示します。
再レビューをお願いします。判定 (APPROVED / CHANGES_REQUESTED) を明示してください。

---

## 対応マトリクス

# 対応マトリクス: design-review Round 2

Round 2 は全 10 施策が APPROVE。全体判定のみ CHANGES_REQUESTED (検証レーンの不足) だった。

## [Warning] 実装完了条件の検証コマンドが AGENTS.md の必須集合と一致していない
- 判断: **対応する**
- 根拠: 指摘のとおり。AGENTS.md の `VERIFICATION_COMMANDS` は 10 本を「全 green でコミット」と
  規定しており、package 側を直接変更しないことは省略の理由にならない
  (`verification-commands-doc-sync.test.ts` が package.json の検証系 script との同期を
  deny-by-default で強制している = 集合そのものが契約である)。
- 対応内容: 実装順序の Step 6 を 10 本 (`pnpm typecheck:packages` / `pnpm build:packages` /
  `pnpm test:packages` を追加) に書き換えた。併せて Step 7 として
  一時検証スクリプトを devnotes に残す (`scripts/` へ昇格させない) ことを明記した。

## [Suggestion] eager load の「1 クエリ」の根拠の書き方
- 判断: **対応する**
- 根拠: 検証手順の副作用で矛盾条件 (`video_manual_id is null` と `in (1,2,3)` の同居) が
  混ざった SQL を根拠に「1 クエリ」と書くのは、保証範囲の誇張になる。
- 対応内容: `ofmany-sql-evidence.md` の読み方 3 を書き換え、
  SQL 本文から読めるのは「親をまとめて絞る形になること」までで、
  **1 クエリであることの根拠は Laravel の eager load の仕組み + 施策 8 のクエリ数テスト**である、
  と分けて書いた。


---

## 差分 1: 実装順序 (詳細設計の「実装順序」節。全文)

## 実装順序 (テストファースト)


1. 施策 8 の #1〜#5 (代表の選択規則) を**先に書いて fail を確認**する
   (`coverCut` も `cover` props もまだ無い状態で赤くなること)。
2. 施策 1 → 施策 2 → 施策 3 → 施策 4 (サーバ側)。#1〜#5 が緑になる。
3. 施策 8 の #6〜#17 (契約・境界・クエリ数) を追加。
4. 施策 5 → 施策 6 → 施策 7 (フロント)、施策 9 の Vitest。
5. 施策 10 のドキュメント追記。
6. **AGENTS.md の検証コマンド 10 本を全 green** で完了する
   (`VERIFICATION_COMMANDS` の集合。package 側を変更しなくても省略しない):
   `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
   `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` /
   `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
7. 一時検証スクリプト `devnotes/20260817-0003-manual-cover-thumbnail/verify-ofmany-sql.php` は
   設計時の記録として devnotes に残す (`scripts/` へ昇格させない)。

## 全体のリスクと後退可能性

| リスク | 影響 | 緩和 |
|---|---|---|
| `ofMany` + `whereHas` が意図した SQL を作らない | 代表が出ない / 誤った行が出る | 実 DB の Feature テストを最初に書く (施策 8 #1・#2)。vendor 実読で機序は確認済み |
| eager load の張り忘れで N+1 | 現場の通信環境で一覧が遅くなる | クエリ数テスト 3 本 (行数 / 候補混在 / 権限なし) |
| 撮れない利用者に壊れた画像 | 現場の混乱 | props 側で権限を解決 + 契約テスト (i)(iii) |
| 署名 URL 期限切れ・S3 失敗 | 壊れた画像アイコン | component の読み込み失敗フォールバック |
| 転送量の増加 | 通信環境の悪い現場で重い | `loading="lazy"` / 64px 表示 / ホバー自動再生を持ち込まない。保証しないものを docs に明記 |
| T148 目録の exact-fit 違反 | Architecture テストが赤 | 施策 2 で登録。検出 A/B の判定表を設計に明記済み |
| 3 枚セット (規約 3) への影響 | 認証済み画面の復元 | 追加は props 1 キーと `<img>` 1 つのみ。no-store / bfcache guard / history 暗号化のいずれにも触れない |


---

## 差分 2: 検証記録の「読み方」節 (ofmany-sql-evidence.md。全文)

# 検証記録: ofMany(['sort_order' => 'min', 'id' => 'min'], closure) の生成 SQL

実行: `php devnotes/20260817-0003-manual-cover-thumbnail/verify-ofmany-sql.php`
(DB へは 1 件もクエリを投げない。toSql / getBindings のみ。app/ は無変更で匿名派生モデルを使用)

Laravel 12 / vendor 実測。判定: **設計どおりの辞書順選択になり、eager load は 1 クエリで済む**。

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
