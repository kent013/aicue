Round 1 の Critical 2 件 + Warning 6 件 + Suggestion 4 件をすべて対応した (反論なし)。

特に判定してほしい点:
- **H-3 の方針変更**: `CACHE_STORE=array` を捨て、`optimize:clear --except=cache` + `env -i` にした。
  `OptimizeClearCommand::getOptimizeClearTasks()` は
  `['config'=>'config:clear','cache'=>'cache:clear','compiled'=>'clear-compiled','events'=>'event:clear',
    'routes'=>'route:clear','views'=>'view:clear', ...ServiceProvider::$optimizeClearCommands]`
  を返し、`--except` は `$exceptions->hasAny([$command, $key])` でキー名・コマンド名の両方に一致する。
  この事実認定と、個別 clear への分解を採らない理由 (ServiceProvider 登録分の取りこぼし) が妥当か。
- **H-1 の procfs 仕様**: 「最後の `") "` 以降をパースして state/ppid/pgrp を確定」で
  comm の空白・括弧問題を回避できているか。列挙方法 (`/proc/[0-9]*/stat` 走査) と
  race の扱い (消えた PID は残留と数えない) に穴がないか。
- **受入条件 15 件**でまだすり抜ける余地があるか。特に dropdb 条件の不変性 (受入条件 7)。

# 対応マトリクス

# 対応マトリクス: conceptual-review (harness) Round 1

Critical 2 件 + Warning 6 件 + Suggestion 4 件。**全件対応**した（反論なし）。

## [Critical] H-1 の `/proc/<pid>/stat` パースが罠だらけで仕様固定が不足
- 判断: **対応する**
- 根拠: 指摘のとおり。`comm` は括弧で囲まれ**プロセス名に空白や `)` を含みうる**ため、
  `awk '{print $3}'` 的な位置決めは state を誤読する。また `kill -0 -- -pgid` からは
  **group のメンバー一覧が取れない**ので、列挙方法そのものが未定義だった。
  ここを曖昧にしたまま実装に渡すと、dev DB 防御の直前ガードが壊れる。
- 対応内容: helper (`group_live_members`) に切り出し、**5 段階の仕様を概念設計で固定**した:
  (1) `/proc/[0-9]*/stat` を走査、(2) **最後の `") "` 以降**をパースして
  `state` / `ppid` / `pgrp` を確定 (先頭からの位置決めをしない)、
  (3) `pgrp` 一致 かつ `state != Z` が 1 つでもあれば残留、
  (4) 全て `Z` なら停止成功、(5) 走査中に消えた PID は race として無視。
  受入条件にも「`comm` に空白・`)` があっても誤読しない」「PID 消滅 race を残留と誤判定しない」を追加。

## [Critical] H-1 は dropdb 直前ガードなので誤判定の blast radius が大きい
- 判断: **対応する**
- 根拠: 指摘のとおり。procfs パースミスや group 列挙漏れがあると、
  実行中 worker が残っているのに dropdb を許す = **dev DB 防御の実質的な緩和**になりうる。
- 対応内容: 概念設計に「**dropdb 側の条件は 1 mm も広げない**」を明記し、
  H-1 が変えるのは「worker が止まったか」の判定だけで、
  「どの DB を落としてよいか」(`guard_shard_db_name` の regex 一致 + admin role 明示) には
  一切触れないことを宣言した。受入条件 7 に
  「dropdb の条件が広がっていないこと」を独立した検査項目として追加した。
  受入条件 3 に「zombie と非 zombie の**混在は失敗**」も独立して立てた。

## [Warning] H-3 の「`CACHE_STORE=array` なら DB に一切触らない」は保証が強すぎる
- 判断: **対応する (方針を変更)**
- 根拠: 指摘のとおり。`optimize:clear` は複合コマンドで、cache store の設定だけを弄るのは
  **「設定で誤魔化す」**であって構造的な解決ではない。将来 DB を触るタスクが増えれば再発する。
- 対応内容: `OptimizeClearCommand` の実装を読み、**`--except` オプションの存在**を確認した
  (`$exceptions->hasAny([$command, $key])` でキー名 `cache` とコマンド名 `cache:clear` の
  両方に一致する)。方針を **`optimize:clear --except=cache` + `env -i` 隔離**へ変更した。
  検討した 3 案 (a: CACHE_STORE=array / b: 個別 clear へ分解 / c: --except + env -i) を
  表で比較し、(b) を採らない理由 (`ServiceProvider::$optimizeClearCommands` の
  取りこぼしが起きる) も明記した。
  受入条件も「cache store を無効化」から
  「`--except=cache` を伴う」「ambient `DB_*`/`PG*` が渡らない」
  「親が `CACHE_STORE=database` でも database store を使わない」の 3 条件へ分割した。

## [Warning] H-3 は「そもそも optimize:clear が必要か」を一段確認すべき
- 判断: **対応する**
- 根拠: 「機能の名前に立ち返れ」の原則どおり。必要なのは bootstrap cache の破棄であって
  アプリケーションキャッシュの削除ではない。
- 対応内容: 施策 H-3 の冒頭に「機能の名前に立ち返る」段落を置き、
  `optimize:clear` の description ("Remove the cached bootstrap files") と
  `getOptimizeClearTasks()` の中身 (config / cache / compiled / events / routes / views +
  ServiceProvider 登録分) を示して、**DB に触るのは `cache` だけ**であることを根拠にした。
  そのうえで「分解 (案 b) は取りこぼしを作るので採らない」と結論を書いた。

## [Warning] 「zombie は DB 接続を保持しない」は procfs 実装が正しい前提でのみ成立
- 判断: **対応する**
- 対応内容: 受入条件 2・3 で「非 zombie が 1 つでもあれば失敗」「混在は失敗」を
  独立に固定し、self-test が `Z` と `S/R/D` の混在ケースを持つことを要求した。

## [Warning] 「bug-hunt が最後まで通るようになる」は表現が強い
- 判断: **対応する**
- 根拠: playwright-cli の既定ブラウザ解決や pcov 未導入といったスコープ外要因が残る。
  保証範囲を誇張しないのは AGENTS.md の基調でもある。
- 対応内容: 期待効果を
  「**既知の harness 起因の停止 4 件を除去し、次回 run が同じ 4 件では止まらなくなる**」
  に書き換え、括弧でスコープ外要因が残ることを明示した。

## [Warning] H-4 は秘密ファイルの複製範囲を広げる。permission の扱いを決めるべき
- 判断: **対応する**
- 対応内容: 「コピー後に親と同等の mode を維持する (`cp -p` 相当、または明示 `chmod 600`)」を
  施策に追記し、受入条件 15 に「world-readable を新たに作らない」を追加した。

## [Warning] self-test だけでは H-1 のすり抜けを固定しきれない
- 判断: **対応する**
- 対応内容: 受入条件を 8 → **15 件**に拡張した。H-1 だけで 7 件 (全 zombie / 非 zombie 残留 /
  混在 / comm パース / PID 消滅 race / stderr 出力 / dropdb 条件不変) を立てた。

## [Suggestion] H-4 の契約テストは setup-worktree 全体を走らせると副作用が大きい
- 判断: **対応する**
- 対応内容: 「実行時ファイルのコピー部分を関数へ切り出し、
  composer install / pnpm install / DB 作成を走らせずにコピーの契約だけ検証できるようにする」を
  施策 H-4 に明記した。

## [Suggestion] `.env.bughunt.local` に本番相当 credential が混入しない運用前提の確認
- 判断: **詳細設計で扱う**
- 根拠: `.env.bughunt.local.example` は「専用 role `bughunt` (CREATEDB なし・dev DB へ
  CONNECT 不可)」を前提として書かれており、構造上は本番 credential が入る設計ではない。
  ただし「入っていないこと」を実ファイルで確認するのは詳細設計フェーズの作業。

## [Suggestion] 使命との整合性 / スコープの適切さ
- 追加対応なし (APPROVE 評価を受領)。


---

# 改訂後の概念設計

# 概念設計: bughunt-harness-hardening (bug-hunt 基盤の不具合 4 件)

## 背景・課題

2026-08-09 のフルサイズ bug-hunt (run `20260809-152048`) では、**アプリのバグより先に
走行基盤の不具合に 3 回ぶつかり**、さらに teardown で 2 件の不具合が出た
(`devnotes/20260809-152048-bug-hunt/report.md` §8)。

bug-hunt は「回帰テストでは見つからない UX 破綻を見つける」ための仕組みであり、
**基盤が転ぶたびに探索の予算が基盤の復旧に食われる**。しかも 3 件のうち 2 件は
**環境によっては毎回踏む**。ここを直さないと、次の run も同じ場所で止まる。

### H-1: teardown が zombie を「生存」と誤判定し DB を破棄しない

`stop_shard_workers()` は成功条件を「**process group 全体の消滅**」に置き、
`kill -0 -- "-${pgid}"` で判定する (`scripts/bug-hunt-shard.sh` L830-841)。
この判定は **zombie (`<defunct>`) をも「生存」と数える**。

本環境の PID 1 は `sleep infinity` で、**orphan の zombie を刈らない**。
worker (`queue:listen`) を KILL すると、その子だった `queue:work --once` の終了済みプロセスが
zombie のまま group に残り、`kill -0 -- -pgid` が成功し続ける。結果:

```
error: shard-1 worker group (database-media, pgid=2532290) が KILL 後も残留
warning: shard-1 の worker 停止に失敗 — この shard の dropdb をスキップ (pidfile 保持)
```

実測では **4 shard すべてで dropdb がスキップ**され、`bug_hunt_1..4` が残置された。
zombie は DB 接続を保持しないので、この抑止は**守るべきものを守っていない**。
一方で次回 run の `createdb` は既存 DB とぶつかる。

### H-2: teardown のループが cap を超えて回り、自分の guard で abort する

`cmd_teardown()` は `for shard in 0 1 2 3 4 5 6 7 8` とハードコードしている (L1151)。
`BUGHUNT_SHARD_CAP=4` に対し、`SHARD_DB_RE` は `^bug_hunt(_[1-4])?$` (L105) なので、
shard 5 に来た時点で:

```
error: guard_shard_db_name: DB 名 'bug_hunt_5' は ^bug_hunt(_[1-4])?$ に一致しない (dev DB 防御で abort)
```

**guard は正しく動いている**。壊れているのは呼び出し側で、cap を 8 から 4 へ下げたときに
このループが同期されなかった (cap と DB 名 regex は同期されている)。
実害は「teardown が最後まで走らず、後片付けの残りが実行されない」こと。

### H-3: provision の `optimize:clear` が ambient env のまま dev DB を触る

`cmd_provision_all()` は `php artisan optimize:clear` を **`env -i` 隔離なし**で実行する (L1085)。
このスクリプトの設計原則は「**全 DB 操作は `env -i` で shell の `DB_*`/`PG*` を遮断してから
bughunt 値を注入する**」(AGENTS.md §bug-hunt の「dev DB 防御 (非交渉)」) であり、
**この 1 行だけがその原則の外にいる**。

`CACHE_STORE=database` の環境では `optimize:clear` の `cache:clear` が
**dev DB の `cache` テーブルを DELETE しにいく**。dev DB が未 migrate な環境では
`SQLSTATE[42P01] relation "cache" does not exist` で落ち、`set -euo pipefail` により
**provision 全体が死ぬ**。今回はこれで 1 回止まった。

問題は 2 つある。(a) **dev DB を触っている** (設計原則違反)、
(b) **dev DB の状態に provision の成否が依存する** (隔離されていない)。

### H-4: `setup-worktree.sh` が `.env.bughunt.local` をコピーしない (SKILL.md と実装の乖離)

`app-bug-hunt` の SKILL.md Phase 0a は
「setup-worktree.sh が `.env.bughunt.local` (`.gitignore` 対象) と Passport 鍵を親からコピーする」
と書いている。しかし `scripts/setup-worktree.sh` L200-215 が実際にコピーするのは
`.env` / `storage/oauth-*.key` / `public/build` の 3 種で、**`.env.bughunt.local` は含まれない**。

結果、worktree で provision すると
`.env.bughunt.local が無い` で止まる。今回は手動 `cp` で回避した。
**文書と実装のどちらかが誤っている**状態が放置されている。

## 改善アイデア

### 施策 H-1: 生存判定を「zombie を除く実プロセス」に変える

`kill -0` は「シグナルを送れるか」であって「動いているか」ではない。
**見たいのは「DB 接続を保持しうるプロセスが残っているか」**である。
zombie は既に終了しており、接続も資源も保持しない (残るのは PID slot だけ)。

判定を専用 helper (仮称 `group_live_members`) に切り出し、**仕様を先に固定する**。
`kill -0 -- -pgid` からは group のメンバー一覧が取れないので、procfs を走査する。

1. `/proc/[0-9]*/stat` を走査する。
2. **各行は「最後の `") "` 以降」をパースする**。`comm` フィールドは括弧で囲まれ、
   **プロセス名に空白や `)` を含みうる**ため、先頭からの位置決め (`awk '{print $3}'` 等) は誤読する。
   最後の `") "` より後ろを分割すれば、`state` が 1 番目・`ppid` が 2 番目・`pgrp` が 3 番目に確定する。
3. `pgrp == 対象 pgid` かつ `state != Z` のメンバーが **1 つでもあれば残留**。
4. `pgrp == 対象 pgid` のメンバーが **すべて `Z`** なら **停止成功**とみなす。
5. 走査中に PID が消えた (`/proc/<pid>/stat` が読めない) 場合は **race として無視**する
   (消えたのだから残留ではない)。

- 全メンバーが zombie なら pidfile を削除し、dropdb を許可する。
- ただし**黙って通さない**: 「zombie N 件を残して停止 (PID 1 が刈らない環境)」を stderr に出す。
  次に読む人が「なぜ pidfile が消えたか」を追える必要がある。
- **zombie と非 zombie が混在する group は従来どおり失敗** (pidfile 保持・dropdb 抑止)。
  この施策は判定を緩めるのではなく、**判定対象を正しくする**ものである。
- **dropdb 側の条件は 1 mm も広げない**。`guard_shard_db_name` (DB 名 regex) と
  admin role 明示は従来のまま通る。H-1 が変えるのは「worker が止まったか」の判定だけで、
  「どの DB を落としてよいか」は一切触らない。

### 施策 H-2: teardown のループ範囲を cap から導出する

`0 1 2 3 4 5 6 7 8` のリテラルを `$(seq 0 "${BUGHUNT_SHARD_CAP}")` に置き換える。
これで cap を動かしたときにループも DB 名 regex も同時に追随する
(cap は既に「★ env で上書きしない (ハードコード)」と宣言された単一の定数)。

- **残留 DB の掃除**という元の意図 (cap=8 期の残り) は `SHARD_DB_RE` 側が既に担えない
  (regex が `_[1-4]` なので 5..8 は触れない)。**ループだけ広くても意味が無い**ので、
  意図としても cap に揃えるのが正しい。
- cap とループ範囲のずれは `self-test` で機械検出できる (既存の self-test にケースを 1 つ足す)。

### 施策 H-3: `optimize:clear` から DB を触るタスクを構造的に外し、ambient env も遮断する

まず**機能の名前に立ち返る**。`optimize:clear` の説明は
"Remove the cached bootstrap files" であり、目的は **config / route / view / event / compiled の
bootstrap cache 破棄**である。`cache:clear` (アプリケーションキャッシュ = DB) が同梱されているのは
複合コマンドだからにすぎず、**provision が必要としているものではない**
(bughunt DB は直後に `migrate:fresh` するので、そこの cache を消す意味も無い)。

検討した 3 案:

| 案 | 内容 | 評価 |
|---|---|---|
| (a) `CACHE_STORE=array` を注入して実行 | cache store を無効化して DB 接続を起こさせない | **採らない**。DB を触らないことを「設定で誤魔化している」だけで、構造的に外れていない。`optimize:clear` に将来 DB を触るタスクが増えたら再発する |
| (b) `config:clear` / `route:clear` / `view:clear` に分解 | 必要なものだけ個別に呼ぶ | **採らない**。`getOptimizeClearTasks()` は `ServiceProvider::$optimizeClearCommands` を展開しており、**パッケージが登録した clear コマンドを取りこぼす**。分解は将来の欠落を作る |
| (c) **`optimize:clear --except=cache`** + `env -i` 隔離 | DB を触る唯一のタスクを**コマンドの機能で**除外し、加えて ambient env を遮断する | **採る** |

`OptimizeClearCommand::getOptimizeClearTasks()` が返すのは
`config / cache / compiled / events / routes / views` + ServiceProvider 登録分で、
**このうち DB に触るのは `cache` (cache store が database のとき) だけ**である。
`--except` は `$exceptions->hasAny([$command, $key])` で**キー名 (`cache`) とコマンド名
(`cache:clear`) の両方**に一致するため、`--except=cache` で確実に外れる。
ServiceProvider 登録分は展開されたまま残るので、(b) の取りこぼしも起きない。

さらに **`env -i` で ambient の `DB_*` / `PG*` を遮断**して呼ぶ。
`--except=cache` だけでも DB 接続は起きない見込みだが、
**このスクリプトの非交渉の原則は「shell の DB_*/PG* を遮断してから実行する」**であり、
「今は接続しないはずだから ambient のままでよい」は原則の例外を残すことになる。
2 段にすることで「この 1 行だけが env 隔離の外」という状態そのものを消す。

- 結果、provision は **dev DB の状態に一切依存しなくなる** (未 migrate でも通る)。
- 「なぜ `cache` だけ外すのか」はコメントに残す (将来 `--except` を消されないため)。

### 施策 H-4: `.env.bughunt.local` を「あればコピー」対象に加える

`setup-worktree.sh` の実行時ファイルコピーに `.env.bughunt.local` を加える
(`storage/oauth-*.key` と同じ **存在すればコピー**の扱い。bug-hunt を使わないリポジトリでは
ファイルが無いので no-op)。

- **文書ではなく実装を直す**。SKILL.md の記述のほうが「あるべき姿」であり、
  worktree 走行が既定である以上、毎回手動 `cp` を要求するのは設計が間違っている。
- `.env.bughunt.local` は `.gitignore` 対象なので worktree には決して現れない = コピーが唯一の供給路。
- **秘密の取り扱い**: 中身は隔離環境の DB credential と APP_KEY / CIPHERSWEET_KEY。
  `.env` を既にコピーしている以上、複製範囲の性質は変わらない (コピー先は同一ホストの worktree)。
  ただし**複製する秘密ファイルが 1 つ増える**のは事実なので、
  **コピー後に親と同等の mode を維持する** (`cp -p` 相当、または明示的に `chmod 600`)。
  world-readable な状態を新たに作らないことを受入条件に含める。
- **テスト容易性**: 実行時ファイルのコピー部分を関数へ切り出し、
  `setup-worktree.sh` 全体 (composer install / pnpm install / DB 作成) を走らせずに
  コピーの契約だけを検証できるようにする。

## 期待効果

- **既知の harness 起因の停止 4 件を除去し、次回 run が同じ 4 件では止まらなくなる**
  (「bug-hunt が最後まで通る」とまでは言わない。playwright-cli の既定ブラウザ解決や
  pcov 未導入といったスコープ外の要因は残る)。H-3 / H-4 は環境によっては毎回踏むものなので、
  再発性の高い 2 件が消える効果が大きい。
- **teardown が後片付けを完遂する** (H-1 / H-2)。DB 残置が消え、次回 run の `createdb` が衝突しない。
- **設計原則の例外が 1 つ減る** (H-3)。「全 DB 操作は env -i 経由」が本当に全数になる。

## 受入条件

| # | 施策 | 受入条件 | 固定レーン |
|---|---|---|---|
| 1 | H-1 | group のメンバーが**全て zombie (`Z`)** のとき、停止成功と判定し pidfile を削除する | `self-test` |
| 2 | H-1 | **非 zombie (`R`/`S`/`D` 等) が 1 つでもあれば**従来どおり失敗し pidfile を保持する | `self-test` |
| 3 | H-1 | **zombie と非 zombie が混在**する group は失敗扱い (2 の一形態だが明示的に固定する) | `self-test` |
| 4 | H-1 | `comm` に**空白や `)` を含む**プロセス名でも `state` / `pgrp` を誤読しない | `self-test` |
| 5 | H-1 | 走査中に PID が消えた (stat が読めない) 場合も安全に処理する (残留と誤判定しない) | `self-test` |
| 6 | H-1 | zombie だけを残して成功した場合、その旨が **stderr に出る** (無言で通さない) | `self-test` |
| 7 | H-1 | **dropdb の条件が広がっていない** — `guard_shard_db_name` (`SHARD_DB_RE` 一致) と admin role 明示は従来どおり必須で、H-1 はそこに一切触れない | `self-test` |
| 8 | H-2 | teardown のループ範囲が `BUGHUNT_SHARD_CAP` から導出され、**リテラルの上限を持たない** | `self-test` |
| 9 | H-2 | cap を変えてもループ範囲と `SHARD_DB_RE` がずれない | `self-test` |
| 10 | H-3 | `optimize:clear` の呼び出しが **`--except=cache` を伴う** (DB を触るタスクを構造的に外す) | `self-test` |
| 11 | H-3 | `optimize:clear` の呼び出しへ **ambient の `DB_*` / `PG*` が渡らない** (`env -i` 経由) | `self-test` |
| 12 | H-3 | 親環境が `CACHE_STORE=database` でも database cache store を使わない (= dev DB の `cache` 表に触らない) | `self-test` |
| 13 | H-4 | 親に `.env.bughunt.local` があれば worktree にコピーされる | `setup-worktree` 契約テスト |
| 14 | H-4 | 親に無ければ何もしない (bug-hunt 非利用リポジトリで no-op) | 同上 |
| 15 | H-4 | コピー後の mode が親と同等で、**world-readable を新たに作らない** | 同上 |

`scripts/bug-hunt-shard.sh self-test` は**実資源に触れずに** guard / 資源導出 / env 隔離を
検証する既存の仕組みで、本テーマの検証はここに載せるのが自然
(AGENTS.md §bug-hunt「検証: `scripts/bug-hunt-shard.sh self-test`」)。
`self-test` 自体は `BughuntShardCapInvariantTest` 等の Architecture テストから呼ばれている前提を
詳細設計で確認する。

## 実装方針（概要）

| # | 施策 | 変更ファイル |
|---|---|---|
| H-1 | zombie を除いた group 生存判定 | `scripts/bug-hunt-shard.sh` (`stop_shard_workers`) |
| H-2 | teardown ループを cap から導出 | `scripts/bug-hunt-shard.sh` (`cmd_teardown`) |
| H-3 | `optimize:clear` の cache store 無効化 | `scripts/bug-hunt-shard.sh` (`cmd_provision_all`) |
| H-4 | `.env.bughunt.local` のコピー | `scripts/setup-worktree.sh` |

**アプリコード (app/ / resources/) は 1 行も変更しない。** PHP / Svelte / DB スキーマへの影響なし。

## 制約・前提

- **dev DB 防御は緩めない**。H-1 は判定対象を正す変更であり、
  「実行中の worker が残っていても dropdb する」ようにはしない。
- **`BUGHUNT_SHARD_CAP` は env で上書きしない**という既存の宣言を維持する
  (H-2 はこの定数への依存を増やすだけで、外から変えられる余地は作らない)。
- **`SHARD_DB_RE` は広げない**。allowlist を緩めるのはガードの緩和にあたる。
- `self-test` は実資源に触れない (sandbox 内で完結する) という既存の性質を壊さない。
- H-4 のコピーは `.gitignore` 対象ファイルの取り回しであり、**worktree の外へは出さない**。

## スコープ外

- **pcov の導入** (コード到達カバレッジが取れない件)。基盤の不具合ではなく環境の未整備であり、
  導入するなら C3/C4/C5 の end-to-end 検証が要る (`coverage/README.md` が明記)。別テーマ。
- **playwright-cli の既定ブラウザ解決**が本機 (aarch64) で壊れており毎 shard が
  `.playwright/cli.config.json` を自作している件。skill 側で 1 回用意するほうが安いが、
  これは `bug-hunt-shard.sh` ではなく skill / agent 定義の話なので分けて扱う。
- **PID 1 が zombie を刈らない**というコンテナ構成そのものの是正
  (`--init` 相当の導入)。devcontainer の構成変更はこのリポジトリの管轄外の可能性があり、
  H-1 は「刈られない環境でも正しく判定する」ことで解決する。
- bug-hunt の要確認 Q1〜Q4 (アプリ仕様の確認事項)。

