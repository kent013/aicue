# アプリの使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

# 参考: AGENTS.md の bug-hunt 節 (本テーマの対象基盤)

`.claude/skills/app-bug-hunt/` は自由探索型の UX バグハント基盤。回帰テストでは見つからない
説明なしリダイレクト・操作詰み・IDOR・UX 破綻を、隔離 bughunt 環境 (直列 `:8010` / 並列 shard
`:8011..8014` (cap=4)、DB `bug_hunt(_N)`) で実ブラウザ走行して発見する (修正はしない)。起動は `/app-bug-hunt`。

- **オプトイン・完全 no-op**: 未使用時はアプリ実行に一切影響しない。`config/bughunt.php` と
  `BughuntCoverageMiddleware` は `env(BUGHUNT_PCOV)` + `function_exists('\pcov\start')` の二重 guard で
  pcov 未導入の本番/CI/dev では常に no-op。`BughuntOAuthSeeder` は fake_externals + bughunt.local +
  `DetectsBughuntDatabase` の DB 名判定を含む三重 fail-secure ガードで、条件不成立なら no-op
  (dev DB に認証状態をばら撒かない)。判定側の regex は残留 DB も検出するため cap より広い。
- **dev DB 防御 (非交渉)**: 全 DB 操作は `scripts/bug-hunt-shard.sh` の用途別 wrapper (`env -i` で
  shell の `DB_*`/`PG*` を遮断 + DB名 regex + role guard) 経由のみ。生 artisan/psql/tinker/createdb/dropdb 禁止。
  `provision`/`teardown` は `BUGHUNT_ORCHESTRATOR=1` を持つ親のみ (worker は default-deny)。
- **worktree 既定**: bug-hunt は worktree から走る (`scripts/bughunt-worktree-hook.sh` の PreToolUse ガードが
  main 直叩きを早期に止める。配線は `.claude/settings.bughunt-hook.example.json` を `.claude/settings.json` にマージ)。
- **スケルトン**: `screens.md` / `operations.md` / `stories/` はテンプレートでは空スケルトン。初回に
  `php artisan route:list` から生成する (SKILL.md Phase 1)。ドリフト検知は `scripts/bug-hunt-inventory-check.sh`。
- **capability 語彙**: finding の `capability_tag` の正本は
  `.claude/skills/app-bug-hunt/capability-catalog.md`(SOP→シナリオ→撮影→レンダの責務境界を
  先に定義し、その上に capability_id を割り当てる。未割当は `unmapped`・tag 不能は `unknown`)。
- 検証: `scripts/bug-hunt-shard.sh self-test` (実資源に触れず guard/資源導出/env 隔離/asset 鮮度を検証)。
  Python ツール (`coverage/` `ledger/`) は `python3 -m unittest` (stdlib のみ)。

【思考原則】
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは概念設計レビュアーです。今回の対象は**アプリ本体ではなく、開発基盤 (bash スクリプト)** です。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命 (North Star) に貢献するか
   (基盤の改善なので「探索の予算を基盤復旧に食われない」という間接貢献の妥当性を見てほしい)
2. 禁止事項違反: 特に「dev DB への破壊操作」の防御を緩めていないか
3. 実現可能性: bash / procfs / Laravel artisan の挙動として正しいか
4. 期待効果の妥当性
5. リスク: ガードの緩和・秘密の露出・後退の可能性
6. スコープの適切さ
7. 受入条件の十分性 (self-test で本当に固定できるか。すり抜ける余地は無いか)

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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

- group の生存判定を `/proc/<pid>/stat` の **プロセス状態が `Z` でないメンバーが 1 つでもあるか**に変える。
- 全メンバーが zombie なら **「停止済み」として扱う** (pidfile を削除し、dropdb を許可する)。
- ただし**黙って通さない**: zombie だけが残った場合は
  「zombie N 件を残して停止 (PID 1 が刈らない環境)」を stderr に出す。
  次に読む人が「なぜ pidfile が消えたか」を追える必要がある。
- **本物の残留 (R/S/D 状態のメンバーがいる) は今までどおり失敗**にする。
  この施策は判定を緩めるのではなく、**判定対象を正しくする**ものである。

### 施策 H-2: teardown のループ範囲を cap から導出する

`0 1 2 3 4 5 6 7 8` のリテラルを `$(seq 0 "${BUGHUNT_SHARD_CAP}")` に置き換える。
これで cap を動かしたときにループも DB 名 regex も同時に追随する
(cap は既に「★ env で上書きしない (ハードコード)」と宣言された単一の定数)。

- **残留 DB の掃除**という元の意図 (cap=8 期の残り) は `SHARD_DB_RE` 側が既に担えない
  (regex が `_[1-4]` なので 5..8 は触れない)。**ループだけ広くても意味が無い**ので、
  意図としても cap に揃えるのが正しい。
- cap とループ範囲のずれは `self-test` で機械検出できる (既存の self-test にケースを 1 つ足す)。

### 施策 H-3: `optimize:clear` を隔離するか、そもそも dev DB を触らせない

方針は 2 つあり得る:

| 案 | 内容 | 評価 |
|---|---|---|
| (a) `env -i` + bughunt 値で `optimize:clear` する | 他の DB 操作と同じ隔離に載せる | **不要な副作用が残る** — bughunt DB は provision 直後に `migrate:fresh` するので cache を消す意味がない |
| (b) **ambient env の cache store だけ無効化して実行** | `CACHE_STORE=array` を注入して `optimize:clear` を走らせる | **採る**。目的 (bootstrap cache = config/route/view の破棄) は達成し、DB には一切触らない |

`optimize:clear` の本来の目的は **config/route/view の bootstrap cache 破棄**であって、
アプリケーションキャッシュの削除ではない。`cache:clear` が付いてくるのは
`optimize:clear` が複合コマンドだからにすぎない。
**`CACHE_STORE=array` を注入すれば、目的を保ったまま DB 依存だけが消える。**

- これにより provision は **dev DB の状態に一切依存しなくなる** (未 migrate でも通る)。
- 「この 1 行だけが env 隔離の外」という設計上の例外そのものが消える。
- self-test で「`optimize:clear` の呼び出しが cache store を無効化した状態で行われる」ことを固定する。

### 施策 H-4: `.env.bughunt.local` を「あればコピー」対象に加える

`setup-worktree.sh` の実行時ファイルコピーに `.env.bughunt.local` を加える
(`storage/oauth-*.key` と同じ **存在すればコピー**の扱い。bug-hunt を使わないリポジトリでは
ファイルが無いので no-op)。

- **文書ではなく実装を直す**。SKILL.md の記述のほうが「あるべき姿」であり、
  worktree 走行が既定である以上、毎回手動 `cp` を要求するのは設計が間違っている。
- `.env.bughunt.local` は `.gitignore` 対象なので worktree には決して現れない = コピーが唯一の供給路。
- **秘密の取り扱い**: 中身は隔離環境の DB credential と APP_KEY / CIPHERSWEET_KEY。
  `.env` を既にコピーしている以上、同じ扱いで問題は増えない (コピー先は同一ホストの worktree)。

## 期待効果

- **bug-hunt が最後まで通るようになる**。今回 3 回止まった箇所のうち 2 件 (H-3 / H-4) は
  環境によっては毎回踏むもので、これを消せば次の run は基盤で止まらない。
- **teardown が後片付けを完遂する** (H-1 / H-2)。DB 残置が消え、次回 run の `createdb` が衝突しない。
- **設計原則の例外が 1 つ減る** (H-3)。「全 DB 操作は env -i 経由」が本当に全数になる。

## 受入条件

| # | 施策 | 受入条件 | 固定レーン |
|---|---|---|---|
| 1 | H-1 | group のメンバーが全て zombie のとき、停止成功と判定し pidfile を削除する | `self-test` |
| 2 | H-1 | 実行中 (非 zombie) のメンバーが 1 つでもあれば従来どおり失敗し pidfile を保持する | `self-test` |
| 3 | H-1 | zombie だけを残して成功した場合、その旨が stderr に出る (無言で通さない) | `self-test` |
| 4 | H-2 | teardown のループ範囲が `BUGHUNT_SHARD_CAP` から導出され、リテラルの上限を持たない | `self-test` |
| 5 | H-2 | cap を変えてもループ範囲と `SHARD_DB_RE` がずれない | `self-test` |
| 6 | H-3 | `optimize:clear` は cache store を無効化した状態で呼ばれる (dev DB の `cache` 表を触らない) | `self-test` |
| 7 | H-4 | 親に `.env.bughunt.local` があれば worktree にコピーされる | `setup-worktree` の契約テスト |
| 8 | H-4 | 親に無ければ何もしない (bug-hunt 非利用リポジトリで no-op) | 同上 |

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

