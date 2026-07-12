# 概念設計: bugfix-bughunt-queue-worker (F-01: bug-hunt 環境のジョブ滞留解消)

## 背景・課題

bug-hunt 走行 (devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md F-01, Critical) で、
AI 解析 (analyze)・プレビュー生成・レンダの queue job が bughunt 環境で永久に `queued` のまま
進まず、S3 中核ジャーニー後半 (シナリオ自動生成 → プレビュー → 完成動画レンダ → DL → 再生) が
2 走行連続で検証不能になっている。

根本原因 (コードリーディングで確定済み、製品バグではなく bug-hunt harness の環境ギャップ):

1. `app/Jobs/Manual/RunManualAnalysis.php` / `RunManualRender.php` はコンストラクタで
   `onConnection('database-analysis')` / `onConnection('database-render')` を明示指定する
   (retry_after を job timeout に合わせた専用 connection。`config/queue.php` 参照)。
   `app/Jobs/Capture/DeleteTakeObjectsJob.php` / `app/Jobs/Manual/DeleteRenderOutputsJob.php` も
   同様に `onConnection('database-media')` を指定する。
2. これらの専用 connection は driver=`database` 固定のため、`.env.bughunt.local` の
   `QUEUE_CONNECTION=sync` (default connection の差し替え) を **バイパス** して jobs テーブルに積まれる。
3. `scripts/bug-hunt-shard.sh` の provision は `php artisan serve` しか起動せず、
   `queue:work` 系 worker を一切起動しない (`grep -n "queue:work" scripts/bug-hunt-shard.sh` 該当なし)。
   → ジョブは誰にも処理されず `queued` のまま無期限停止。

`config/queue.php` / 両 Job クラスのコメントに「運用契約: worker は
`php artisan queue:work database-analysis` (render/media も同様) を必須登録」と明記されており、
bughunt provision がこの運用契約を満たしていないことが欠陥の本質。

## 改善アイデア

### 検討した 2 案

**案 A (採用): provision で専用 connection の queue worker を shard ごとに background 起動し、teardown で確実に停止する**

- `scripts/bug-hunt-shard.sh` の `cmd_provision` に、serve 起動と同じ env 隔離パターン
  (`env -i` で shell の `DB_*`/`PG*` を遮断し bughunt 値のみ明示注入) で
  `php artisan queue:listen {connection} --env=bughunt.local` を connection ごと
  (`database-analysis` / `database-render` / `database-media`) に nohup background 起動する。
- pid を `tmp/bug-hunt/worker-{shard}-{connection}.pid` に記録し、`cmd_teardown` で
  serve と同様の cmdline 検証付き kill で確実に停止する。
- 変更は `scripts/bug-hunt-shard.sh` (+ `.env.bughunt.local(.example)` のコメント修正) に閉じる。
  **製品コード (app/, config/) は一切変更しない。**

**案 B (不採用): bughunt env でのみジョブ接続を sync に上書きする仕組み (env 駆動) を用意する**

不採用理由:

1. **製品コードへの介入が必要**: Job コンストラクタの `onConnection()` を config/env 経由にする、
   または `config/queue.php` の専用 connection の driver を env 化する必要があり、
   dev/prod のジョブ配線 (retry_after 契約、AnalysisTimeBudgetInvariantTest /
   RenderTimeBudgetInvariantTest が固定する連鎖) に触れる。フィーチャブリーフの
   「製品 (dev/prod) のジョブ接続・挙動は変えない」に反するリスクが案 A より高い。
2. **探索面が減る**: S3 の polling UI (`jobs.show` / `render-jobs.show` の XHR ポーリング、
   進捗表示、`analyzing` 中のシナリオ編集ロック = F-02 の発見面) は非同期でしか観測できない。
   sync 化すると `queued`/`analyzing` の中間状態が消え、本番と異なる UX を探索することになる
   (bug-hunt の目的である「本番相当 UX の実走」に反する)。
3. **serve のブロッキング**: `php artisan serve` は既定でシングルワーカー
   (`PHP_CLI_SERVER_WORKERS` 未設定)。sync 実行中 (解析は LLM 3 段、レンダは job timeout
   1,380/1,500 秒が上限) はその shard の**全 HTTP リクエストが停止**し、探索ブラウザが固まる。
   sync driver では job の `$timeout` (pcntl alarm) も効かない。
4. 案 A は本番運用契約 (queue:work worker 必須登録) と**同型の構成**であり、
   「worker がいる状態の実挙動」をそのまま探索できる。

### 案 A の設計要点

1. **worker 起動 (provision)**: `cmd_provision` の serve ヘルスチェック成功後に、
   3 つの専用 connection それぞれについて `queue:listen` を background 起動する。
   - `queue:work` (daemon) でなく **`queue:listen`** を使う: shard worker は走行中に
     wrapper 経由で `reseed` (migrate:fresh) を実行でき、jobs/cache テーブルが一時的に消える。
     `queue:work` daemon はループ内の restart シグナル確認 (cache 読み) で QueryException を
     受けるとプロセスごと死に、**worker が静かに死んで F-01 が再発**する。
     `queue:listen` は各イテレーションで子プロセス (`queue:work --once`) を起動する
     Laravel 公式のスーパーバイザ構成で、子の異常終了でもマスターが継続する
     (自前 bash supervisor ループを書かずフレームワークのレンジ内で解決。思考原則 1)。
   - オプション: `--sleep=1` (ポーリング応答性)、`--tries=1` (両 Job の `$tries=1` と整合)、
     `--timeout=1800` (listener が子プロセスを kill するまでの上限。job 側の
     `$timeout` 1,380/1,500 が pcntl alarm で先に効くため、予約 TTL と同じ 1,800 を天井にする)。
   - env 注入は serve 起動ブロックと同一パターン (`env -i` + `.env.bughunt.local` 値の明示注入 +
     `DB_DATABASE={shard db}`)。`guard_bughunt_runtime` を通してから起動する。
   - **`setsid` で専用 process group (pid == pgid) として起動する**: teardown で listener の
     子プロセス (`queue:work --once`) を race なく一括停止するため (Codex R1 Warning 反映)。
   - 起動直後に `kill -0` で生存確認し、即死していれば log パスを添えて fail-fast。
   - pid (= pgid) / log: `tmp/bug-hunt/worker-{shard}-{connection}.pid` / `.log`。manifest にも記録。
2. **worker 停止 (teardown)**: shard ごとに pidfile を読み、`/proc/{pid}/cmdline` に
   `queue:listen` + 当該 connection 名が含まれることを検証してから
   **`kill -TERM -- -{pid}` (process group kill) で master + 子を一括停止**する
   (pid 再利用による誤 kill 防止は serve kill と同じ流儀。子 pid の個別採取は
   採取〜kill 間の再スポーン race があるため採らない。Codex R1 Warning 反映)。
   `--drop-db` 時に worker の DB 接続が残って dropdb が失敗するのを防ぐ。
3. **keepdb-check 拡張**: `--keep-db` reuse の preflight に worker 生存確認を追加する
   (serve だけ生きていて worker が死んでいる状態で reuse すると F-01 が再発するため)。
   判定は `kill -0` でなく teardown と同じ cmdline 検証
   (pidfile 存在 ∧ `/proc/{pid}/cmdline` に `queue:listen` + 当該 connection 名) とする
   (stale pidfile / pid 再利用の誤判定防止。Codex R1 Warning 反映)。
4. **self-test 拡張**: 実資源に触れない構造検証を追加する。
   - worker pidfile/logfile の導出関数のテスト。
   - worker 対象 connection リストが `config/queue.php` の database driver 専用 connection
     (`database-analysis` / `database-render` / `database-media`) と一致するかの drift check
     (新しい専用 connection を追加したのに worker 起動リストへの追加を忘れる事故の機械検出)。
     判定は bash の文字列 grep でなく **`php -r` で config/queue.php を実評価**して
     driver=database の専用 connection 一覧を抽出し比較する (記法変更への耐性。Codex R1 Warning 反映)。
   - `cmd_provision` に worker 起動配線 (setsid 含む)、`cmd_teardown` に worker の
     process group kill 配線が存在するかの `declare -f` 構造検査 (既存 [w] セクションと同じ流儀)。
   - dryrun provision が worker を起動しないこと。
5. **ドキュメント/コメント整合**: `.env.bughunt.local` / `.env.bughunt.local.example` の
   `QUEUE_CONNECTION=sync` のコメント「非同期ジョブを同期実行 (探索の決定論性)」を
   「default connection のジョブのみ同期実行。専用 connection (analysis/render/media) は
   provision が起動する queue:listen worker が処理する」に更新する
   (現状のコメントは F-01 の誤診を誘発した)。スクリプトのヘッダコメント (provision の説明) も更新。

## 期待効果

- **使命への貢献**: North Star フロー後半 (AI 解析 → シナリオ生成 → プレビュー → 完成動画) が
  bug-hunt で初めて実走可能になる。2 走行連続で未達だった S3 手順 5/8/9 の探索が解禁され、
  「思考ゼロ・編集ゼロ」の中核体験の UX 破綻・詰みを発見できるようになる。
- **受け入れ条件 (Codex R1 反映で絞り込み)**:
  (1) 専用 connection のジョブが無限 `queued` に滞留しない (worker が消費する)。
  (2) 失敗時も終端状態 (failed → failJob 経由の UI 表示) がユーザーに返る。
  (3) S3 手順 5/8/9 が bug-hunt で再走査可能になる。
  ※ completed 到達は LLM/ffmpeg fake の配線状況 (スコープ外) に依存するため必須条件にしない。
  fake 未配線の経路では「失敗が即座に UI に返る」までが本修正の保証範囲。
- 本番の運用契約 (専用 connection worker の必須登録) と同型の構成で探索するため、
  worker 前提の実挙動 (進捗遷移、失敗時 UI、recoverStale との関係) がそのまま検証面になる。

## 実装方針（概要）

変更ファイル (すべて harness 側。製品コードは不変):

| ファイル | 変更内容 |
|---------|---------|
| `scripts/bug-hunt-shard.sh` | worker 起動/停止/keepdb-check/self-test の追加、ヘッダコメント更新 |
| `.env.bughunt.local` | `QUEUE_CONNECTION=sync` コメントの正確化 |
| `.env.bughunt.local.example` | 同上 |

テスト: `scripts/bug-hunt-shard.sh self-test` が pass を維持 (新規セクション含む)。
既存の PHP/JS テストには触れない (harness スクリプトのみの変更で、composer test / pnpm test に
影響する変更を含まない)。可能なら provision → analyze 相当の簡易確認
(ジョブが jobs テーブルから消費され終端状態に到達すること) を実装フェーズの検証手順に含める。

## 制約・前提

- **dev DB 防御 (非交渉)**: worker 起動も既存の env 隔離規約 (`env -i` + bughunt 値明示注入 +
  `guard_bughunt_runtime`) に完全準拠する。生 artisan の直実行は追加しない。
- **orchestrator gate**: worker の起動/停止は provision/teardown の内部処理であり、
  既存の `BUGHUNT_ORCHESTRATOR=1` gate の内側で実行される (worker 子セッションからは触れない)。
- provision の実効 env 検証 (queue=sync 期待) は**変更しない**: default connection は
  引き続き sync (メール等の同期実行) であり、専用 connection のみ worker が処理する。
- 製品 (dev/prod) のジョブ接続・挙動・`config/queue.php`・Job クラスは一切変えない。
- `TESTING_FAKE_EXTERNALS=true` 下でも LLM (Prism) / ffmpeg の fake は現状未配線
  (`FakeExternalsServiceProvider` は Stripe のみ rebind)。worker 起動後、解析ジョブは
  LLM 呼び出し失敗で failJob → UI に失敗が返る (無限 queued は解消)。これは許容し、
  fake 配線は別設計とする。

## スコープ外

- LLM (Prism) / ffmpeg の bughunt 向け fake 配線 (F-06 系の「harness fake 基盤の拡張候補」)。
- 製品側のジョブ配線変更 (onConnection / config/queue.php / retry_after 契約)。
- F-02 (409 フィードバック欠落)、F-03 (カメラフォールバック)、F-04 (ManualTestSeeder の
  plan_code) など他 finding への対応。
- dev / 本番環境の worker 運用 (docs/architecture.md の運用契約) の変更。
