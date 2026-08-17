# explain-notes: 検索クエリの実行計画 (T202 施策 5 の完了条件)

「索引を足したから速い」で終えないための実測記録。
**索引は `%語%` の LIKE 自体には効かない**ので、何が効いて何が効いていないかを分けて書く。

## 計測条件

- 計測場所は**テストレーンの pgsql DB**。dev DB (`app`) はこの環境では 1 表も存在せず
  (`Schema::hasTable('cuts') === false`)、計測できない。dev DB へ `migrate` を掛けるのは
  エージェント判断で行わない (AGENTS.md 禁止事項 3 の趣旨)。
- 実行したのは `EXPLAIN (ANALYZE, BUFFERS)` の**読み取りのみ** (SELECT の実行計画取得)。
- 規模: 1 project に manual **200 本** × cut **20 本** = `cuts` **4,000 行**
  (詳細設計 施策 4 の「想定上限」に合わせた)。一致するのは 10 manual。
- 計測前に `ANALYZE video_manuals` / `ANALYZE cuts` で統計を更新した
  (統計が無いと planner が既定値で誤った計画を選び、計測が計画の性質を語らなくなる)。
- 計測用の一時テストは採取後に削除した (恒久テストにしていない = この数値は回帰検査ではない)。

## (1) PC 一覧の検索クエリ (`ProjectController::manualRows` の paginate 本体相当)

```
Limit  (cost=1774.16..1774.19 rows=10 width=74) (actual time=0.884..0.885 rows=10.00 loops=1)
  ->  Sort  (cost=1774.16..1774.41 rows=100 width=74) (actual time=0.883..0.884 rows=10.00 loops=1)
        Sort Key: video_manuals.created_at DESC, video_manuals.id DESC
        ->  Seq Scan on video_manuals  (cost=0.00..1772.00 rows=100 width=74) (actual time=0.860..0.876 rows=10.00 loops=1)
              Filter: ((project_id = '1'::bigint) AND (((title)::text ~~ '%トルクレンチ%'::text) OR (ANY (id = (hashed SubPlan 2).col1))))
              Rows Removed by Filter: 190
              SubPlan 2
                ->  Seq Scan on cuts  (cost=0.00..168.00 rows=11 width=8) (actual time=0.005..0.844 rows=10.00 loops=1)
                      Filter: ((scene ~~ '%トルクレンチ%'::text) OR (narration ~~ '%トルクレンチ%'::text) OR ((subtitle_primary)::text ~~ '%トルクレンチ%'::text) OR (subtitle_secondary ~~ '%トルクレンチ%'::text))
                      Rows Removed by Filter: 3990
                      Buffers: shared hit=88
Planning Time: 0.337 ms
Execution Time: 0.906 ms
```

記録する 3 点:

| 項目 | 実測 |
|---|---|
| 選ばれた計画 | **hash semi-join** (`hashed SubPlan` = 副問い合わせを 1 度だけ実行してハッシュ化し、外側と突き合わせる) |
| `cuts` へのアクセス方法 | **`Seq Scan`** (4,000 行を 1 度だけ走査。`Rows Removed by Filter: 3990`) |
| 実測時間 | **Execution Time 0.906 ms** (Planning 0.337 ms) |

## (2) 撮影 PWA 一覧の検索クエリ (`CaptureManualController::index` の withCount 3 本 + get)

```
Sort  (cost=6511.95..6512.20 rows=100 width=98) (actual time=1.754..1.755 rows=10.00 loops=1)
  Sort Key: video_manuals.updated_at DESC
  ->  Seq Scan on video_manuals  (cost=0.00..6508.62 rows=100 width=98) (actual time=1.667..1.746 rows=10.00 loops=1)
        Filter: (((status)::text = ANY ('{ready,published}'::text[])) AND (project_id = '1'::bigint) AND (((title)::text ~~ '%トルクレンチ%'::text) OR (ANY (id = (hashed SubPlan 5).col1))))
        Rows Removed by Filter: 190
        SubPlan 1
          ->  Aggregate  (actual time=0.007..0.007 rows=1.00 loops=10)
                ->  Index Only Scan using cuts_video_manual_id_index on cuts  (actual time=0.004..0.005 rows=20.00 loops=10)
                      Index Cond: (video_manual_id = video_manuals.id)
                      Index Searches: 10
        SubPlan 2 / SubPlan 3
                ->  Index Scan using cuts_video_manual_id_index on cuts cuts_1 / cuts_2
                      Index Cond: (video_manual_id = video_manuals.id)
        SubPlan 5
          ->  Seq Scan on cuts cuts_3  (actual time=0.004..0.890 rows=10.00 loops=1)
                Filter: ((scene ~~ …) OR (narration ~~ …) OR (subtitle_primary ~~ …) OR (subtitle_secondary ~~ …))
                Rows Removed by Filter: 3990
Planning Time: 0.436 ms
Execution Time: 1.067 ms
```

| 項目 | 実測 |
|---|---|
| 選ばれた計画 | 検索条件は **hash semi-join** (`hashed SubPlan 5`)、`withCount` 3 本は**行ごとの相関副問い合わせ** |
| `cuts` へのアクセス方法 | 検索条件側は **`Seq Scan`** (1 度だけ)、**`withCount` 側は `Index Only Scan` / `Index Scan` on `cuts_video_manual_id_index`** |
| 実測時間 | **Execution Time 1.067 ms** (Planning 0.436 ms) |

## 読み取り (何が効いて何が効いていないか)

- **本改善の検索 (`%語%` の LIKE) に索引は効いていない**。両クエリとも `cuts` を
  `Seq Scan` で 1 度走査している。これは詳細設計が事前に書いたとおりで、
  B-tree 索引は前方一致でない LIKE には使えないため**正しい計画**である。
- **索引が実際に効いたのは撮影 PWA 一覧の `withCount(['cuts', ...])`** である。
  3 本の相関副問い合わせがすべて `cuts_video_manual_id_index` を使い
  (`Index Only Scan` / `Index Scan`、`Index Cond: video_manual_id = video_manuals.id`)、
  **表示行数ぶん (loops=10) 繰り返される**。索引が無ければここが
  `cuts` 全走査 × 表示行数になっていた。施策 5 の主な利得はこちらである。
- `Seq Scan` が選ばれたことを**異常としない**。想定規模 (`cuts` 4,000 行) では
  一致率が低い LIKE を 1 度だけ走査する方が安く、実測も **PC 0.9 ms / PWA 1.1 ms** で
  一覧描画の中では無視できる大きさである。この規模では許容する。
- 許容できない実測が出たときだけ、概念設計の Conditional (pg_trgm + GIN) を起こす。
  **引き金は変えない**: `cuts` が 10^6 行を超える or 一覧描画の p95 が 1 秒を超える。

## 保証範囲を誇張しない

- これはテストレーン DB での 1 回の計測であり、**本番の計画・実測を予測するものではない**。
  行数分布・統計・共有バッファの温まり方が違えば planner は別の計画を選ぶ。
- `loops=10` は「一致した 10 行」に対する繰り返しである。無検索時の撮影 PWA 一覧は
  ページングを持たず全件返すため、`withCount` の繰り返し回数は**表示行数と同じだけ**増える
  (この非対称は本改善の原因ではなく既存仕様。ページングは Conditional のまま)。
- `Buffers: shared hit` しか出ておらず `read` が 0 なのは、直前の投入でページが
  共有バッファに載っているためである。**ディスク I/O を含む実測ではない**。
