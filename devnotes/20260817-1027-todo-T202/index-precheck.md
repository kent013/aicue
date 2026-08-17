# index-precheck: `cuts` の索引 (migration 追加**前**の実測)

詳細設計 施策 5 の完了条件「`Schema::getIndexes('cuts')` の migration **前**の出力を貼る」の記録。
**前提の実測記録であって重複回避機構ではない** — 提示した migration は結果にかかわらず索引を作る。
目的は「`cuts.video_manual_id` に索引は存在しない」という設計時の断定が実測と合っていたかを
後から検証できるようにすることだけである。

## 計測できなかった場所と、その理由

dev DB (`app`) は本 worktree の環境では **1 表も存在しない** (`Schema::hasTable('cuts') === false`)。

```
{"hasCuts": false, "db": "app", "driver": "pgsql", "idxCuts": [], "idxVm": []}
```

よって dev DB からは「索引が無い」ことを読み取れない (表そのものが無いため、
空配列は「索引が無い」ではなく「表が無い」の意味になる)。
dev DB へ `migrate` を掛けるのはエージェント判断で行わない (AGENTS.md 禁止事項 3 の趣旨)。

## 実測した場所

migration 適用済みの**テストレーンの pgsql DB** (`RefreshDatabase` が `migrate` を通した後) で
一時テストから `Schema::getIndexes('cuts')` を出力した (計測後にその一時テストは削除した)。

```
PRECHECK-CUTS-INDEXES: [{"name":"cuts_pkey","columns":["id"],"type":"btree","unique":true,"primary":true}]
```

## 読み取り

- `cuts` の索引は**主キー 1 本 (`cuts_pkey` / `id`) だけ**である。
- `video_manual_id` を先頭列に持つ索引は **1 本も無い**。
- したがって詳細設計 施策 5 の前提 (「PostgreSQL は FK 列に索引を自動生成しない」ため
  `cuts.video_manual_id` に索引は存在しない) は**実測と一致していた**。

## 保証範囲を誇張しない

これはテストレーンの DB (migration の結果そのもの) の観測であり、
**本番・dev の実環境に手動索引が無いことの証拠にはならない**。
管理外の手動索引が実環境で見つかった場合の扱いは詳細設計 施策 5 のとおりで、
migration は変更せず環境側のスキーマドリフトとして是正する。
