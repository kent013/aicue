# 使命・禁止事項・思考原則（全 Codex 呼び出しに自動適用）

## アプリの使命 (North Star) — AGENTS.md より

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 — AGENTS.md より

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: 概念設計レビュアーとしての指示

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー対象の文脈】
本件は製品機能の追加ではなく、**bug-hunt harness (LLM 探索的バグハント基盤) の環境ギャップ修正**です。bug-hunt は隔離環境 (専用 DB `bug_hunt(_N)` / 専用ポート :8010..8018) でアプリを実走して UX 破綻・詰み・IDOR を発見するオプトイン基盤で、`scripts/bug-hunt-shard.sh` が provision (createdb → migrate:fresh+seed → serve 起動) と teardown を機械的に行います。dev DB を wipe しないための非交渉要件として、全 DB 操作は `env -i` による環境遮断 + DB 名 regex + role guard 経由でのみ行われます。

今回の finding F-01: `RunManualAnalysis` / `RunManualRender` ジョブが専用 queue connection (`database-analysis` / `database-render`、driver=database 固定) を `onConnection()` でハードコードしており、bughunt の `QUEUE_CONNECTION=sync` (default connection のみの差し替え) をバイパスして jobs テーブルに積まれる。provision は queue worker を起動しないため、ジョブが `queued` のまま永久停止し、S3 中核ジャーニー後半 (AI 解析 → プレビュー → レンダ) が探索不能。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js。今回は bash スクリプト中心）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか（特に dev DB 防御の非交渉要件、teardown でのプロセス残留、pid 再利用による誤 kill）
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: 本件は bash スクリプト中心のため該当が薄いが、製品コード (PHP/TS) に触れないという主張が守られているか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

参考ファイル (読み込み可): scripts/bug-hunt-shard.sh, config/queue.php, app/Jobs/Manual/RunManualAnalysis.php, app/Jobs/Manual/RunManualRender.php, app/Jobs/Capture/DeleteTakeObjectsJob.php, .env.bughunt.local.example, devnotes/20260712-191243-bug-hunt/shard-0/shard-report.md

---

# user: 概念設計

## 概念設計

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
   - 起動直後に `kill -0` で生存確認し、即死していれば log パスを添えて fail-fast。
   - pid / log: `tmp/bug-hunt/worker-{shard}-{connection}.pid` / `.log`。manifest にも記録。
2. **worker 停止 (teardown)**: shard ごとに pidfile を読み、`/proc/{pid}/cmdline` に
   `queue:listen` + 当該 connection 名が含まれることを検証してから kill する
   (pid 再利用による誤 kill 防止。serve kill と同じ流儀)。
   順序: 子プロセス (`queue:work --once`) の pid を `pgrep -P` で先に採取 →
   マスター kill (再スポーン停止) → 採取済み子 pid を kill → pidfile 削除。
   `--drop-db` 時に worker の DB 接続が残って dropdb が失敗するのを防ぐ。
3. **keepdb-check 拡張**: `--keep-db` reuse の preflight に worker 生存確認を追加する
   (serve だけ生きていて worker が死んでいる状態で reuse すると F-01 が再発するため)。
4. **self-test 拡張**: 実資源に触れない構造検証を追加する。
   - worker pidfile/logfile の導出関数のテスト。
   - worker 対象 connection リストが `config/queue.php` の database driver 専用 connection
     (`database-analysis` / `database-render` / `database-media`) と一致するかの drift check
     (新しい専用 connection を追加したのに worker 起動リストへの追加を忘れる事故の機械検出)。
   - `cmd_provision` に worker 起動配線、`cmd_teardown` に worker kill 配線
     (マスター kill が子 pid 採取より後) が存在するかの `declare -f` 構造検査
     (既存 [w] セクションと同じ流儀)。
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
- ジョブが有限時間内に completed / failed の終端状態へ到達する (F-01 の受け入れ条件)。
  ※ LLM/ffmpeg の fake が bughunt に未配線の経路では「失敗が即座に UI に返る」までが本修正の
  保証範囲 (無限 `queued` の解消)。fake の配線自体は別 finding (F-06 系) のスコープ。
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
