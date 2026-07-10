# Bug-hunt Coverage (操作到達カバレッジ / コード到達カバレッジ) — App

bug-hunt の探索が「**どの操作 (route) を叩き、どのコード行に到達したか**」を run_id 突合で可視化する道具。
2 系統ある: **操作到達カバレッジ** (operation-reach / correlate.py、pcov 不要) と
**コード到達カバレッジ** (code-reach / merge_pcov.py、pcov 必要)。**主出力は未カバー worklist**（次に埋める対象）で、
**絶対 % は副**（`*_pct` に添えるだけ・目標にしない＝gaming 防止）。
「機能カバレッジ%」「品質保証%」という表現は出力にもこの README にも書かない。

> 静的棚卸しの `coverage-audit.md`（route/operation の机上対応表）とは役割が違う。
> こちらは **run 突合の動的 proxy**（実際に走った run の結果と機構分母を突き合わせる）。
> audit = 静的棚卸し / `coverage/` = run 突合の動的 proxy、と区別すること。

## 正直な前提（最重要・読み飛ばさない）

- **pcov は本環境未導入**。コード到達カバレッジ (merge_pcov.py) は pcov 非依存の純ロジック
  (入力は C3 middleware 出力形の JSON) であり、テストは fixture の shard を union して検証する。
  pcov を入れたら C3/C4/C5 の end-to-end を実機で検証してから運用する。
- **graph の TESTED_BY は TypeScript 専用**。`/workspace/.code-review-graph/graph.db` 実測
  (2026-06-20): **TESTED_BY=15787 全て TS、PHP(.php::)=0**。
  → PHP web route の TESTED_BY は **「false」ではなく `unknown_graph_gap`**（unknown）として扱う。
  「TESTED_BY なし」を PHP route で出すと全件ヒットして無意味なので、correlate は
  `tested_by_status ∈ {tested, untested, unknown_graph_gap}` の三値で表現し、PHP route は既定
  `unknown_graph_gap`。**PHP のテスト有無は graph では分からない → Pest を別途見ること。**
- route 名は graph に無い。route→graph の join は `route:list` の `action` (FQCN@method) →
  controller ファイル相対パス → graph node の `file_path` 経由。`action='Closure'` / `__invoke` 不能行は
  join できず `unknown_graph_gap`（隠さず可視化する）。

## fix-gate #3: operations.md の列マッピング（app 固有・最重要）

app の `operations.md` は markdown leading-pipe の **5 列** が基本:

```
| method | route | name | story | 区分 |
```

ヘッダを `strip("|").split("|")` した後の index は **0=method, 1=route(URL), 2=name, 3=story, 4=区分**。
graph join の **join キーは name 列 (= index 2)** であり、URL の route 列 (index 1) ではない。
URL 列を誤抽出すると `route:list` の name と一致せず graph join が全件失敗する。

S8 の API/CLI 面のみ **6 列**（`| method | route | api route name | CLI | story | 区分 |`）。
`load_operations()` はヘッダ行から name/story/区分 列の index を動的に決めるため、5 列/6 列
どちらの節も正しい列を拾う。name 列の backtick (`api.v1.projects.store`) は剥がす。

## 構成

| ファイル | 役割 |
|---|---|
| `correlate.py` | **操作到達カバレッジ correlator**。run_id で executed / findings / operations / graph を突合し未カバー worklist を作る（stdlib のみ） |
| `merge_pcov.py` | **コード到達カバレッジ pcov merge**。C3 middleware が吐く shard JSONL を union し uncovered を主出力する（stdlib のみ） |
| `test_correlate.py` | correlate のテスト（`python3 -m unittest`、graph は fixture sqlite で生成。実 operations.md / 実 graph.db があれば fix-gate #3/#4 を追加検証） |
| `test_merge_pcov.py` | merge のテスト（全 fixture、pcov 不要） |
| `test_naming_no_stale.py` | 旧 Stage 付番の後退防止 self-test |
| `fixtures/` | サンプル入力（route-list / operations(5列+6列) / findings / executed）と `fixtures/pcov/` の shard JSONL |

関連（このディレクトリ外）:
- `../ledger/findings.schema.json` … Finding 台帳のスキーマ。findings.jsonl の正本。
- `../operations.md` … 機構分母（name 列 / 区分）。
- C3 middleware = `app/Http/Middleware/BughuntCoverageMiddleware.php`、C5 = `scripts/bug-hunt-shard.sh --coverage`（pcov 導入時）。

---

## 操作到達カバレッジ（operation-reach / correlate.py）

pcov 無しで使える「軸 A 粗 proxy」。**Phase 4 レポートの後**に 1 コマンドで回す（毎回実行）。

### 入力

1. `route:list --json` — `php artisan route:list --json`（`--route-list` 省略時は correlate が自動実行）。
2. `operations.md` — 機構分母（5 列、name 列が join キー）。区分 `外`(対象外)=分母外、`逸`(逸脱のみ)=未実行でも警告しない材料。
3. `findings.jsonl` — Finding Ledger（複数 shard を `cat` 連結 or glob 可、`-` で stdin）。`--run-id` で絞る。
   route 直結しない finding は `capability_tag` 経由で story 一致の機構群へブロードキャスト（`via_capability`）。
4. `executed.json` — bug-hunt 子が「UI 経由で実際に叩いた route」を run_id・shard 単位で記録したもの。
   複数 shard を union（どれか 1 shard で executed なら executed=true）。スキーマ:
   ```json
   {
     "run_id": "20260618-082101",
     "shards": ["0","1","2","3","4"],
     "executed_routes": [
       {"route_name": "register.store", "shard": "1", "story": "S1", "status": "ok"}
     ]
   }
   ```
   `status` は `ok|blocked|skipped`。`ok` のみ executed 扱い。`--executed` 省略時は全 in_scope 機構を未実行 candidate 扱い。
5. `graph.db` — TESTED_BY を controller ファイル単位で引く（`/workspace/.code-review-graph/graph.db`）。

### 使い方

```bash
cd /workspace/.claude/worktrees/<worktree>   # CWD を明示
python3 .claude/skills/app-bug-hunt/coverage/correlate.py \
  --operations .claude/skills/app-bug-hunt/operations.md \
  --findings 'devnotes/20260618-082101-bug-hunt/shard-*/findings.jsonl' \
  --executed devnotes/20260618-082101-bug-hunt/executed.json \
  --graph-db /workspace/.code-review-graph/graph.db \
  --run-id 20260618-082101 \
  > devnotes/20260618-082101-bug-hunt/coverage-operation-reach.md

# 機械集計 (trend 用):
python3 .../correlate.py ... --run-id 20260618-082101 --json
```

複数 shard の findings は `cat ... | correlate.py --findings -` でも渡せる。
`--hotspot-threshold N`（既定 2）で hotspot の閾値を変えられる。

### 出力の読み方（主＝未カバー worklist、% は副）

markdown 本文（`coverage-operation-reach.md` 想定）の節構成:

1. **未実行機構**（`in_scope ∧ ¬executed`）… 主。区分 `逸`/`外` は除外。次に走るべき route。
2. **★cross: 未実行 ∧ finding 多**（最優先で埋める）… capability 経由 finding を含む。
3. **finding hotspot**（finding_count ≥ 閾値）… 欠陥が集中している機構。
4. **TESTED_BY untested**（**TS 面のみ**）… TS で実テストの無い機構。**PHP は件数サマリのみ別掲**
   （`unknown_graph_gap`、本文を汚さない。PHP=0 ゆえ app では全 PHP route がここに落ちる）。
5. **summary**（trend 用）。

`--json` の主フィールド（KPI）:

| key | 意味 | 主/副 |
|---|---|---|
| `unexecuted_count` | 未実行機構数（**最重要・主出力の規模**） | 主 |
| `cross_count` | 未実行 ∧ finding 多の積集合（**埋める優先度=最高**） | 主 |
| `hotspot_count` | finding hotspot 数 | 主 |
| `untested_real_count` | TS 面で実テスト無し | 主 |
| `unknown_graph_gap_count` | PHP 等で TESTED_BY 判定不能（= Pest を別途見よ） | 注記 |
| `in_scope_count` | 分母（gaming 防止のため明示） | 注記 |
| `dropped_other_run` | run_id 不一致で捨てた行数（trend 汚染検知） | 注記 |
| `executed_pct` | 実行率 | **副・目標にしない** |

> KPI の使い方は **worklist の逓減**（run を重ねて `unexecuted_count` / `cross_count` が減る）を見る。
> `executed_pct` を上げること自体を目標にしない（分母固定 + % 副記で gaming を防ぐ）。
> 分母（`in_scope` 機構集合）を変えたら worklist ヘッダに注記する。

---

## コード到達カバレッジ（code-reach / pcov / merge_pcov.py、`--coverage` 時限定）

実装到達カバレッジを行レベルで採る。**既定 OFF**。`--coverage` フラグ到達時のみ使う。
**pcov 未導入のため本環境では実 coverage が出ない** → merge は fixture で検証する純ロジック。

### 収集 → merge の流れ（pcov 導入時）

1. `scripts/bug-hunt-shard.sh provision-all --coverage`（C5）で serve を pcov 付き起動。
2. C3 middleware（`BughuntCoverageMiddleware`）が per-request に `app/` 限定で covered/all 行を
   `storage/bughunt-coverage/{run}-{shard}.json` に **JSONL 追記**する。1 行の形:
   ```json
   {"file":"app/Http/Controllers/Auth/RegisteredUserController.php","covered":[12,13,18],"all":[12,13,14,15,18,20]}
   ```
3. `merge_pcov.py` で全 shard（shard 0-4）を **union**（covered = ∪、all = ∪）して uncovered を主出力。

### 使い方

```bash
python3 .claude/skills/app-bug-hunt/coverage/merge_pcov.py \
  --shard 'storage/bughunt-coverage/20260618-082101-*.json' \
  --run-id 20260618-082101 \
  > devnotes/20260618-082101-bug-hunt/coverage-code-reach.md

python3 .../merge_pcov.py --shard '...-*.json' --run-id ... --json   # 機械集計
```

`--shard` は繰り返し可・glob 可・`-` で stdin。`--only app/`（既定）で app 配下のみ残す。
`--verbose` で uncovered 行を展開（既定はファイル単位に畳む）。

### 出力の読み方（uncovered 主、% は副）

1. **一度も到達しないファイル**（covered 空、app/ 限定）… 主。
2. **到達したが uncovered 行が残るファイル**… 主。validation/権限/例外分岐の未到達こそ pcov 固有の価値。

`--json` の主フィールド:

| key | 意味 | 主/副 |
|---|---|---|
| `fully_uncovered_count` | 一度も到達しないファイル数 | 主 |
| `uncovered_line_count` | 未到達の実行可能行数 | 主 |
| `parse_errors` | skip した不正行数（exit は警告に留める） | 注記 |
| `line_pct` | 行カバレッジ率 | **副・目標にしない** |

> `all`（実行可能行）が shard 間でコード差分により食い違うと union が歪むため、
> **merge は同一 run_id（= 同一 commit）のみ**を前提にする。

---

## テスト

```bash
cd /workspace/.claude/worktrees/<worktree>/.claude/skills/app-bug-hunt/coverage
python3 -m unittest test_correlate test_merge_pcov test_naming_no_stale
```

いずれも **stdlib のみ・pcov 非依存**（graph は fixture sqlite を生成、pcov 入力は fixture JSONL）。
実 `operations.md` / 実 `graph.db`（`/workspace/.code-review-graph/graph.db`）がある環境では、
fix-gate #3（name 列 join）/ #4（PHP TESTED_BY=0 → unknown_graph_gap）の追加テストも自動で走る。
