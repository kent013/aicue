Round 2 の Critical 2 件 + Warning 4 件 + Suggestion 3 件をすべて対応した (反論なし)。

要点:
- **H-3 は主張そのものを下げた**。`env -i` が `.env` を防げないのは指摘のとおりなので、
  「DB に触らない」と言い切るのをやめ、(a) 既知の DB 接触タスクを構造的に外す、
  (b) ambient env は遮断する、(c) 拡張タスクの集合が増えたら**検出する** (Architecture テストで
  allowlist に pin)、の 3 点だけを主張範囲にした。実登録を調べた結果は 2 件
  (`filament:optimize-clear` / `icons:clear`) でどちらもファイルキャッシュだった。
  期待効果からも「dev DB の状態に一切依存しない」を削除した。
- **受入条件 7 を制御フローの固定へ作り替えた**。9〜12 を新設し、
  「非 zombie を返したら dropdb wrapper が一度も呼ばれない」「raw dropdb 新設なし」まで固定する。
- procfs 判定を 2 回評価 (2 回目は dropdb 分岐の直前) にし、0 件と zombie のみを区別した。
- H-4 の mode を `0600` 固定にした (`cp -p` は親が 0644 なら world-readable を作るため不採用)。
- H-2 は bash 算術ループ + テスト用 cap の実評価 (cap+1 が deny) にした。

これで概念設計として詳細設計へ進めるか判定してほしい。

# 対応マトリクス

# 対応マトリクス: conceptual-review (harness) Round 2

Critical 2 件 + Warning 4 件 + Suggestion 3 件。**全件対応**した（反論なし）。

## [Critical] H-3 の `env -i` は Laravel が `.env` を読むことまでは防げない
- 判断: **対応する（主張そのものを下げた）**
- 根拠: 指摘のとおり。`env -i` が遮断するのは**親シェル由来の env だけ**で、
  Artisan 起動後に Laravel は通常どおり `.env` を読む。そこに dev DB credential があれば、
  `ServiceProvider::$optimizeClearCommands` の拡張タスクから dev DB へ到達する余地が残る。
  「DB に触るのは cache だけ」「dev DB の状態に一切依存しない」は**証明できていなかった**。
- 対応内容: 3 段構えに直し、**主張できる範囲を明示的に狭めた**。
  1. **実登録を調査**した。本リポジトリの `$optimizeClearCommands` は 2 件だけ:
     - `filament:optimize-clear` (`vendor/filament/support/src/SupportServiceProvider.php` L442-445)
     - `icons:clear` (`vendor/blade-ui-kit/blade-icons/src/BladeIconsServiceProvider.php` L111-114)
     どちらもファイルキャッシュの破棄で DB を触らない。
     ただし**依存を足せば増える**ので「今は安全」を根拠にはしない。
  2. **Architecture テストで登録集合を allowlist に pin** する (deny-by-default)。
     新しい依存が clear コマンドを登録したら赤くなり、
     「その clear は DB を触らないか」を人が判断してから allowlist に足す運用にする。
     これは**証明ではなく検出**であると設計に明記した。
  3. H-3 が主張できるのは 3 点だけ、と設計に列挙した:
     (a) 既知の DB 接触タスク `cache:clear` を構造的に外した、
     (b) ambient env 由来の `DB_*`/`PG*` は渡らない、
     (c) 拡張タスクの集合が増えたら検出される。
  期待効果からも「dev DB の状態に一切依存しなくなる」を**削除**し、
  「今回踏んだ失敗経路 (`cache:clear` → dev DB の `cache` 表) を構造的に閉じた」に置き換えた。

## [Critical] 受入条件 7 が「worker 停止失敗時に dropdb へ到達しない」を固定していない
- 判断: **対応する**
- 根拠: 指摘のとおり。DB 名 regex と admin role が維持されていても、
  **H-1 が誤って停止成功を返せば**、対象 bughunt DB は正規条件を満たしたまま drop される。
  blast radius の中心は**制御フロー**であって guard の存在ではなかった。
- 対応内容: 受入条件を 15 → **20 件**に拡張し、H-1 の中核として 9〜12 を新設した:
  - 9: `group_live_members` が非 zombie を返したら **dropdb wrapper が一度も呼ばれない**
  - 10: 停止失敗時は pidfile 保持 + 当該 shard の teardown が失敗扱い
  - 11: dropdb 候補へ進んだ後も DB 名 guard と admin role guard を必ず通る
  - 12: **raw `dropdb` 呼び出しが新設されていない** (Architecture テスト)
  受入条件表の直後に「危険なのは guard の存在ではなく**到達制御**である」旨の注記も置いた。

## [Warning] procfs 走査は一時点のスナップショットではない (PID 消滅 race だけでは不足)
- 判断: **対応する**
- 根拠: 走査済み PID の後に同じ group へプロセスが増える / 読み取り後に状態が変わる race は残る。
  dropdb 直前の判定としては不足。
- 対応内容: 仕様に 2 項追加した ——
  「判定を**短い間隔で 2 回**行い、**2 回とも**非 zombie ゼロのときだけ成功」
  「**2 回目は dropdb 分岐の直前**に置く (判定と dropdb の間に窓を作らない)」。
  受入条件 7 に落とした。

## [Warning] 「メンバー 0 件」と「zombie のみ」の区別が無い
- 判断: **対応する**
- 対応内容: 「0 件は通常の停止成功 (**警告なし**)、zombie のみのときだけ警告する」を仕様に明記し、
  受入条件 2 (0 件 → 成功・警告なし) と 8 (zombie のみ → stderr 出力) に分けた。

## [Warning] H-4 の mode 契約が二義的 (「親と同等」と `chmod 600` は別物)
- 判断: **対応する**
- 根拠: 指摘のとおり。親が `0644` なら `cp -p` は**world-readable な秘密ファイルを新たに作る**。
  「親と同等」は契約として弱い。
- 対応内容: **コピー先 mode を `0600` 固定**に決めた。「親と同等 (`cp -p`)」を採らない理由も明記。
  受入条件 20 を「親が `0644` でも world-readable にしない」に強化した。

## [Warning] H-2 の条件はテキスト検査だけになりやすい
- 判断: **対応する**
- 対応内容: 受入条件 14 を
  「**テスト用 cap で実評価**し、`0..cap` が全て allow・`cap+1` が deny になる
  (テキスト検査で済ませない)。本番定数は env で上書き可能にしない」に強化した。

## [Suggestion] H-2 は `seq` より bash 算術ループのほうが依存が少ない
- 判断: **対応する**
- 対応内容: `for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++))` に変更した。

## [Suggestion] procfs の「最後の `) ` より後ろ」方式は妥当 / スコープは適切 / 期待効果の表現は適正
- 追加対応なし（評価を受領）。ただし期待効果は上記 Critical 対応でさらに 1 段弱めた。


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
6. **procfs 走査は一時点のスナップショットではない**ため、判定を **短い間隔で 2 回**行い、
   **2 回とも非 zombie がゼロのときだけ**停止成功とする。2 回目は **dropdb 分岐の直前**に置く
   (判定と dropdb の間に窓を作らない)。どちらかで非 zombie を観測したら fail-closed で pidfile を保持する。
7. **「メンバー 0 件」と「zombie のみ」を区別する**。0 件は通常の停止成功 (警告なし)、
   zombie のみのときだけ「zombie N 件を残して停止」を警告する。

- メンバー 0 件、または全メンバーが zombie なら pidfile を削除し、dropdb を許可する。
- **zombie のみのときは黙って通さない**: 「zombie N 件を残して停止 (PID 1 が刈らない環境)」を
  stderr に出す。次に読む人が「なぜ pidfile が消えたか」を追える必要がある。
- **zombie と非 zombie が混在する group は従来どおり失敗** (pidfile 保持・dropdb 抑止)。
  この施策は判定を緩めるのではなく、**判定対象を正しくする**ものである。
- **dropdb 側の条件は 1 mm も広げない**。`guard_shard_db_name` (DB 名 regex) と
  admin role 明示は従来のまま通る。H-1 が変えるのは「worker が止まったか」の判定だけで、
  「どの DB を落としてよいか」は一切触らない。

### 施策 H-2: teardown のループ範囲を cap から導出する

`0 1 2 3 4 5 6 7 8` のリテラルを **bash 算術ループ**
`for ((shard = 0; shard <= BUGHUNT_SHARD_CAP; shard++))` に置き換える
(`seq` への外部依存を増やさない)。
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
**このスクリプトの非交渉の原則は「shell の DB_*/PG* を遮断してから実行する」**であり、
「今は接続しないはずだから ambient のままでよい」は原則の例外を残すことになる。

**ただし `env -i` は「親シェル由来の env」しか遮断しない。** Laravel は起動時に
プロジェクトの `.env` を読むので、dev DB の credential はそこから供給されうる。
したがって「絶対に DB へ接続しない」とは**主張できない**。ここは正直に書く。

閉じられるのは「**DB を触りうるタスクの集合**」のほうである。
`ServiceProvider::$optimizeClearCommands` の実登録を調べたところ、本リポジトリでは
**2 件だけ**だった:

| 登録元 | clear コマンド | 性質 |
|---|---|---|
| `vendor/filament/support/src/SupportServiceProvider.php` L442-445 | `filament:optimize-clear` | Filament の component/blade キャッシュ (ファイル) |
| `vendor/blade-ui-kit/blade-icons/src/BladeIconsServiceProvider.php` L111-114 | `icons:clear` | アイコンキャッシュ (ファイル) |

どちらもファイルキャッシュの破棄で DB を触らない。しかし**依存を足せば集合は増える**ので、
「今は安全」を根拠にはできない。**deny-by-default の目録で固定する**:

- Architecture テストで `ServiceProvider::$optimizeClearCommands` の登録集合を
  **既知の allowlist (`filament` / `blade-icons`) に pin** する。
- 新しい依存が clear コマンドを登録したらテストが赤くなり、
  **「その clear は DB を触らないか」を人が判断してから allowlist に足す**運用にする。
- これは「証明」ではなく「**検出**」である。誇張しない。

まとめると H-3 が主張できるのは次の 3 点に限られる:

1. **既知の DB 接触タスク (`cache:clear`) を構造的に外した** (`--except=cache`)
2. **ambient env 由来の DB_*/PG* は渡らない** (`env -i`。スクリプトの原則に合流)
3. **拡張タスクの集合が増えたら検出される** (Architecture テストの deny-by-default)

「provision が dev DB の状態に一切依存しなくなる」とは書かない。
**今回踏んだ失敗経路 (`cache:clear` → dev DB の `cache` 表) は構造的に閉じる**、が正確な主張である。

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
  **コピー先の mode を `0600` に固定する**。
  「親と同等の mode を維持 (`cp -p`)」は採らない —— 親が `0644` なら
  **world-readable な秘密ファイルを新たに作ってしまう**ため、契約として弱い。
  `0600` 固定なら親の状態によらず結果が一意に決まる。
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
  ただし **「provision が dev DB の状態に一切依存しなくなる」とは主張しない** ——
  Laravel は `.env` を読むため、拡張 clear コマンドが DB を触る可能性は
  「検出できる」だけで「起こり得ない」ではない (施策 H-3 参照)。
  主張できるのは **今回踏んだ失敗経路 (`cache:clear` → dev DB の `cache` 表) を構造的に閉じた**ことである。

## 受入条件

| # | 施策 | 受入条件 | 固定レーン |
|---|---|---|---|
| 1 | H-1 | group のメンバーが**全て zombie (`Z`)** のとき、停止成功と判定し pidfile を削除する | `self-test` |
| 2 | H-1 | group のメンバーが**0 件**のとき、停止成功と判定する (zombie 警告は**出さない**) | `self-test` |
| 3 | H-1 | **非 zombie (`R`/`S`/`D` 等) が 1 つでもあれば**失敗し pidfile を保持する | `self-test` |
| 4 | H-1 | **zombie と非 zombie が混在**する group は失敗扱い | `self-test` |
| 5 | H-1 | `comm` に**空白や `)` を含む**プロセス名でも `state` / `pgrp` を誤読しない | `self-test` |
| 6 | H-1 | 走査中に PID が消えた場合も安全に処理する (残留と誤判定しない) | `self-test` |
| 7 | H-1 | 判定を**短い間隔で 2 回**行い、**2 回とも**非 zombie ゼロのときだけ成功。2 回目は **dropdb 分岐の直前** | `self-test` |
| 8 | H-1 | zombie のみで成功した場合、その旨が **stderr に出る** (無言で通さない) | `self-test` |
| **9** | **H-1** | **`group_live_members` が非 zombie を返したら dropdb wrapper が一度も呼ばれない** (制御フローそのものの固定) | `self-test` |
| 10 | H-1 | worker 停止失敗時は pidfile が保持され、当該 shard の teardown が**失敗扱い**になる | `self-test` |
| 11 | H-1 | dropdb 候補へ進んだ後も、**DB 名 guard (`SHARD_DB_RE`) と admin role guard を必ず通る** | `self-test` |
| 12 | H-1 | **raw `dropdb` 呼び出しが新設されていない** (wrapper 経由のみ) | Architecture テスト |
| 13 | H-2 | teardown のループ範囲が `BUGHUNT_SHARD_CAP` から導出され、**リテラルの上限を持たない** | `self-test` |
| 14 | H-2 | **テスト用 cap で実評価**し、`0..cap` が全て allow・`cap+1` が deny になる (テキスト検査で済ませない)。本番定数は env で上書き可能にしない | `self-test` |
| 15 | H-3 | `optimize:clear` の呼び出しが **`--except=cache` を伴う** | `self-test` |
| 16 | H-3 | `optimize:clear` の呼び出しへ **ambient の `DB_*` / `PG*` が渡らない** (`env -i` 経由) | `self-test` |
| 17 | H-3 | `ServiceProvider::$optimizeClearCommands` の登録集合が **既知 allowlist に pin** され、増えたら赤くなる | Architecture テスト |
| 18 | H-4 | 親に `.env.bughunt.local` があれば worktree にコピーされる | `setup-worktree` 契約テスト |
| 19 | H-4 | 親に無ければ何もしない (bug-hunt 非利用リポジトリで no-op) | 同上 |
| 20 | H-4 | **コピー先の mode が `0600`** (親が `0644` でも world-readable にしない) | 同上 |

> **受入条件 9〜12 が H-1 の中核**である。Round 1/2 の指摘どおり、危険なのは
> 「DB 名 guard が生きているか」ではなく「**worker 停止失敗時に dropdb へ到達しないか**」という
> 制御フローそのものなので、そこを直接固定する。

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

