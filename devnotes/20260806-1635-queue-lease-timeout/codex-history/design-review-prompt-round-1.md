## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則

## 禁止事項

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

## セキュリティ不変条件(アプリ都合で緩めない)

【思考原則 — 全議論に適用】
まず仮説を立てろ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。
データに真摯に向き合え。数値を見て即座に閾値を弄るな。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。
仕組みが機能していない段階で値を弄るな。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 / Pest / DTO + JsonResource パターン / Laratrust RBAC
- テストレーン: Architecture は DB 不使用 (tests/Pest.php で TestCase のみ)。Feature/Unit は RefreshDatabase グローバル適用 + --parallel

【本件固有の前提 — 蒸し返さないこと】
- 規則 1 (ワーカー timeout < retry_after、無条件) と規則 2 (ジョブ $timeout < retry_after) は互いに独立で、両者の間に大小関係は課さない (上位 c2c 台帳の確定裁定)。
- 実行時 fail-fast の全ジョブ導入は却下済み。
- 概念設計の Codex 合議 3 ラウンドは完了済みで、値 (600/540, 1680/1620, 1680/1620, 300/240) と方針は確定した与件である。値そのものの再議論は不要。
- スコープ外の c2c feature (budget-invariant-gates 等) には触れない。

【レビュー観点】
1. コードの正確性 (ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性 (命名規約、パターン、API)
3. PHPStan level 10 適合性
4. テスト計画の網羅性 (各施策に Pest テスト)
5. 副作用・後退リスク
6. 波及変更の網羅性
7. セキュリティ (AGENTS.md のセキュリティ不変条件)
8. **実装可能性**: 記載された helper のシグネチャと実装規定で、実装者が迷わず着手できるか。トークン解析・bash/YAML パースの規定に穴はないか

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: キューのリース期間とワーカー制限時間の整合 (規則 1)

- 概念設計: [`conceptual-design.md`](./conceptual-design.md)
- c2c feature: `queue-lease-timeout-consistency` (revision `6-507568dc7e82`) / 裁定 AG-084 + AG-080

---

## 使命・制約 (絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書 (SOP) を起点に**、AI が撮るべきカットを設計した
**動画シナリオ**を生成し、そのシナリオを**スマホ (PWA) でナビゲーション撮影**することで、
専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合 (OJT を撮って形式化する tebiki) と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置 (SECI)。

本タスクの使命への貢献は**間接的**である (信頼性の下支え)。解析・レンダ・課金のジョブが
黙って二重に走らないことを保証する。

### 禁止事項 (AGENTS.md より。本タスクに効くもの)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
9. Artifact の使用

### コーディングルール

- **PHPStan level 10** 必須 (`composer phpstan`)
- **Pest**。`RefreshDatabase` は `tests/Pest.php` でグローバル適用 (`Feature` / `Unit` のみ)。
  **`Architecture` レーンは DB を使わない** (`tests/Pest.php` L65-69 で `TestCase` のみ)。
  本タスクの新規 2 テストは**すべて `Architecture` レーン = DB 不使用**で書く。
- `declare(strict_types=1)` + 日本語コメント
- テストデータは Factory (本タスクは DB を触らないので該当なし)
- PHP 8.4 + Laravel 12

---

## 施策一覧

| # | 施策名 | 変更ファイル | 種別 | 優先度 |
|---|---|---|---|---|
| 0 | `database` の `retry_after` を 600 リテラルへ | `config/queue.php` | 変更 | 必須 |
| 1 | mprocs 4 ペインの `--timeout` 是正 + 接続名明示 | `mprocs.yaml` | 変更 | 必須 (AG-084) |
| 2 | bug-hunt worker の `--timeout` を接続別に是正 | `scripts/bug-hunt-shard.sh` | 変更 | 必須 |
| 3 | **規則 1** gate 新設 | `tests/Architecture/QueueWorkerLeaseInvariantTest.php` | 新規 | 必須 |
| 4 | **規則 2 + 接続経路網羅** gate 新設 | `tests/Architecture/QueuedJobLeaseInventoryTest.php` | 新規 | 必須 |
| 5 | timeout 到達時の遷移を Feature テストで固定 | `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` | 新規 | 必須 |
| 6 | 運用契約の明文化 | `docs/architecture.md` | 変更 | 必須 |
| 7 | 既存コメントのドリフト是正 | `app/Jobs/Manual/RunManualAnalysis.php` / `RunManualRender.php` | 変更 | 必須 |

**実装順序**: 3 → 4 (テストファースト。fail を確認) → 0 → 1 → 2 → 5 → 6 → 7。

### 波及変更チェック

| 面 | 影響 |
|---|---|
| TypeScript 型定義 | **なし** (フロントに露出しない) |
| Inertia Props / API Resource / DTO | **なし** |
| `.env.example` / `.env.testing` | **なし** (`DB_QUEUE_RETRY_AFTER` はどちらにも無い。実査済み) |
| 既存テスト | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` は `database-analysis` / `database-render` のみ参照 → **無影響**。`BughuntShardCapInvariantTest` は cap のみ → 無影響 |
| `scripts/bug-hunt-shard.sh self-test` | `[y]` (`BUGHUNT_WORKER_CONNECTIONS` の drift 検査) と `[y3]` (構造検査) に**追加**が要る (後述) |
| 既存コメント | `RunManualAnalysis` / `RunManualRender` の「既定 database は 90s のため」→ 600s |

---

## 施策 0: `database` の `retry_after` を 600 リテラルへ

### 変更箇所

`config/queue.php` L38-45。

### 現行コード

```php
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
    'after_commit' => false,
],
```

### 変更後コード

```php
// 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
// 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
// env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
// 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
// Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT) = 約 400s。
// worker timeout 540 (< 600) がそれを上回る (docs/architecture.md §キューのリース期間規約)。
'database' => [
    'driver' => 'database',
    'connection' => env('DB_QUEUE_CONNECTION'),
    'table' => env('DB_QUEUE_TABLE', 'jobs'),
    'queue' => env('DB_QUEUE', 'default'),
    'retry_after' => 600,
    'after_commit' => false,
],
```

### リスク

- ワーカー異常死時の再取得が最大 510 秒遅くなる (90 → 600)。
  許容根拠は概念設計 §回収遅延の許容。
- `DB_QUEUE_RETRY_AFTER` を設定していた環境があれば無視される。
  `.env.example` / `.env.testing` / `.env.bughunt.local.example` のいずれにも無いことを実査済み。

---

## 施策 1: `mprocs.yaml` の是正

### 変更後コード (procs 部のみ)

```yaml
  # queue worker の --timeout は「その接続で有効なワーカー制限時間」であり、
  # 必ずその接続の retry_after を下回らせる (規則 1。config/queue.php と
  # tests/Architecture/QueueWorkerLeaseInvariantTest.php が対応)。
  # ★ queue:listen では **ジョブ側の $timeout が効かない** (Listener は子
  #   `queue:work --once` に --timeout を渡さず、Worker::runNextJob() は SIGALRM を
  #   張らない)。ここの --timeout が dev における唯一の上限である。
  # ★ 接続名は必ず明示する (既定接続に頼ると QUEUE_CONNECTION 次第で
  #   どの retry_after と比較すべきか静的に決まらない)。
  queue:
    shell: "php artisan queue:listen database --tries=1 --timeout=540"
  # 専用 connection (analysis/render/media) は既定 database connection の default
  # キューを見る上の worker では拾われないため、docs/architecture.md の運用契約どおり
  # connection ごとに worker を分けて常駐させる (retry_after が connection 固有のため
  # 1 本にまとめない)。
  queue-analysis:
    shell: "php artisan queue:listen database-analysis --tries=1 --timeout=1620"
  queue-render:
    shell: "php artisan queue:listen database-render --tries=1 --timeout=1620"
  queue-media:
    shell: "php artisan queue:listen database-media --tries=1 --timeout=240"
  # pail はログ追尾クライアントであってキューワーカーではない。--timeout は
  # 「追尾を何秒で打ち切るか」であり retry_after とは無関係
  # (QueueWorkerLeaseInvariantTest の MPROCS_NON_WORKER_TIMEOUT_PROCS に理由付きで登録)。
  logs:
    shell: "php artisan pail --timeout=0"
```

`server` / `vite` ペインは変更しない。

### リスク

- 有限 timeout に到達すると `ProcessTimedOutException` が `Listener::listen()` を抜けて
  **ペインが終了する**。選んだ値は通常の dev 実行で到達しない水準
  (概念設計 §有限 `--timeout` の副作用)。

---

## 施策 2: `scripts/bug-hunt-shard.sh` の是正

### 変更箇所

L710 付近 (`BUGHUNT_WORKER_CONNECTIONS` の直後) と L736-753 (`start_shard_workers`)。

### 現行コード

```bash
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)
...
# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
        setsid php artisan queue:listen "${conn}" --env=bughunt.local \
            --sleep=1 --tries=1 --timeout=1800 \
```

### 変更後コード

```bash
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)

# 接続ごとの listener timeout (規則 1: その接続の retry_after を必ず下回る)。
# ★ 一律値にしない: retry_after は接続ごとに違う (1680 / 1680 / 300)。
# ★ 旧実装は 3 接続すべてに --timeout=1800 を与えており、database-media
#   (retry_after=300) では 6 倍の違反だった (二重取得の窓)。
# ★ queue:listen では Job 側 $timeout は効かない (Listener は子 `queue:work --once` へ
#   --timeout を渡さず、Worker::runNextJob() は SIGALRM を張らない)。ここが唯一の上限。
# ★ BUGHUNT_WORKER_CONNECTIONS の全要素が鍵を持つこと / 各値が retry_after 未満で
#   あることは self-test [y4] と tests/Architecture/QueueWorkerLeaseInvariantTest.php が
#   二段で固定する。
declare -A BUGHUNT_WORKER_TIMEOUTS=(
    [database-analysis]=1620
    [database-render]=1620
    [database-media]=240
)
```

`start_shard_workers` のコメントと起動行:

```bash
# - --tries=1 は Job 側の $tries=1 と整合。--timeout は BUGHUNT_WORKER_TIMEOUTS の
#   接続別の値 (規則 1)。listener が子を kill する天井であり、queue:listen では
#   Job 側 $timeout が効かないためこれが唯一の上限になる。
        setsid php artisan queue:listen "${conn}" --env=bughunt.local \
            --sleep=1 --tries=1 --timeout="${BUGHUNT_WORKER_TIMEOUTS[${conn}]}" \
```

### self-test への追加 (cmd_self_test の [y] 群)

```bash
    # (y4) 接続別 listener timeout: 全 connection が鍵を持ち、値が retry_after 未満であること
    #      (規則 1 の実行配線側。静的側は QueueWorkerLeaseInvariantTest)
    local conn_rt
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        [[ -n "${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}" ]] \
            || t_fail "BUGHUNT_WORKER_TIMEOUTS に ${conn} の値が無い (規則 1 の検査対象から漏れる)"
        conn_rt="$(cd "${WORKSPACE}" && php -r '
            $cfg = require "config/queue.php";
            echo (int) ($cfg["connections"][$argv[1]]["retry_after"] ?? 0);
        ' "${conn}" 2>/dev/null || echo 0)"
        [[ "${conn_rt}" -gt 0 && "${BUGHUNT_WORKER_TIMEOUTS[${conn}]}" -lt "${conn_rt}" ]] \
            || t_fail "規則 1 違反: ${conn} の listener timeout (${BUGHUNT_WORKER_TIMEOUTS[${conn}]}) が retry_after (${conn_rt}) 以上"
    done
    # 起動行が数値リテラル直書きへ戻っていないこと (配列経由の強制)
    echo "${wrk_def}" | grep -qE -- '--timeout="\$\{BUGHUNT_WORKER_TIMEOUTS' \
        || t_fail "start_shard_workers が BUGHUNT_WORKER_TIMEOUTS 経由で --timeout を渡していない"
```

> `wrk_def` は既存 (y3) で `declare -f start_shard_workers` として取得済み。
> (y4) は (y3) の**後**に置く。

### リスク

- `declare -A` は bash 4+ を要求する。本スクリプトは既に `#!/usr/bin/env bash` +
  `local -n` 相当を使わないものの、Linux 前提 (`/proc` cmdline 照合) なので実害なし。
  念のため self-test 冒頭の環境検査に依存せず、`declare -A` は**ファイル冒頭ではなく
  既存の変数定義位置**に置く (読み込み順の変更を最小化)。

---

## 施策 3: `QueueWorkerLeaseInvariantTest` (規則 1)

### ファイル

`tests/Architecture/QueueWorkerLeaseInvariantTest.php` (新規。DB 不使用)。

### 定数・シグネチャ

```php
<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * ワーカー起動定義に `--timeout` を持つが**キューワーカーではない** proc。
 * key = mprocs の proc 名 / value = なぜ規則 1 の対象外か。
 * ★ 黙って除外しない。理由を書けないものをここに足さないこと。
 */
const MPROCS_NON_WORKER_TIMEOUT_PROCS = [
    'logs' => 'php artisan pail はログ追尾クライアント。--timeout は追尾の打ち切り秒数であり、キューのリース (retry_after) とは無関係',
];

/**
 * mprocs の proc 定義を「proc 名 => shell 文字列」へ正規化する (純関数)。
 *
 * @return array<string, string>
 */
function mprocsShellCommands(string $yamlPath): array;

/**
 * shell 文字列がキューワーカー起動かを判定し、接続名と --timeout を返す (純関数)。
 *
 * `queue:work` / `queue:listen` の**両方**をワーカーとして扱う
 * (どちらでも --timeout は「その接続で有効な制限時間」= 規則 1 の対象)。
 *
 * @return array{connection: string|null, timeout: int|null}|null
 *         ワーカーでなければ null。connection/timeout が省略されていれば当該キーが null
 */
function parseQueueWorkerCommand(string $shell): ?array;

/**
 * bash ソースから `declare -A BUGHUNT_WORKER_TIMEOUTS=( [conn]=N ... )` を抽出する (純関数)。
 *
 * @return array<string, int>
 */
function bughuntWorkerTimeouts(string $bashSource): array;

/**
 * bash ソースから `BUGHUNT_WORKER_CONNECTIONS=(a b c)` を抽出する (純関数)。
 *
 * @return list<string>
 */
function bughuntWorkerConnections(string $bashSource): array;

/**
 * config/queue.php の driver=database 接続 (接続名 => retry_after)。
 *
 * @return array<string, int>
 */
function databaseQueueRetryAfters(): array;
```

### テストケース (すべて失敗メッセージ先頭に `規則 1:`)

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `規則 1: mprocs のキューワーカーは接続名を明示する` | ワーカー判定された全 proc で `connection !== null`。理由: 既定接続は `QUEUE_CONNECTION` 次第でどの `retry_after` と比較すべきか静的に決まらない |
| 2 | `規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る` | 全ワーカーで `timeout !== null` かつ `1 <= timeout < retry_after(connection)`。**`--timeout=0` は 0 なので必ず落ちる** |
| 3 | `規則 1: mprocs のワーカーは driver=database の全接続を覆う` | ワーカーの接続集合 == `databaseQueueRetryAfters()` のキー集合 (sort 比較) |
| 4 | `規則 1: --timeout を持つ非ワーカー proc は理由付きで除外登録されている` | `shell` に `--timeout` を含むがワーカー判定されない proc 名の集合 == `MPROCS_NON_WORKER_TIMEOUT_PROCS` のキー集合。理由文字列は 20 文字以上 |
| 5 | `規則 1: bug-hunt の listener timeout は接続ごとに retry_after を下回る` | `bughuntWorkerTimeouts()` の全 entry で `1 <= N < retry_after`。かつ `bughuntWorkerConnections()` の全要素が鍵を持つ |
| 6 | `規則 1: bug-hunt の起動行は --timeout を配列経由で渡す` | `start_shard_workers` の定義に `--timeout="${BUGHUNT_WORKER_TIMEOUTS[` が含まれ、`--timeout=<数字>` の直書きが**含まれない** |

### `parseQueueWorkerCommand` の実装規定

```
1. shell を空白で分割 (mprocs の shell はシェル 1 行。引用符は使っていない)
2. 'artisan' トークンが無い、または 'queue:work' / 'queue:listen' が無ければ null を返す
3. サブコマンドの**次**のトークンが '-' で始まらなければそれを connection とする。
   '-' で始まる (= オプション) なら connection = null
4. '--timeout=N' または '--timeout' + 次トークン N から timeout を取る。無ければ null
5. N が数字列でなければ Assert で fail (「--timeout の値が数値でない」)
```

> **正規表現ではなくトークン分割**で足りる根拠: mprocs の `shell` は引用符・変数展開・
> パイプを含まない単純な 1 コマンド行である (実査済み)。将来それが崩れたら
> ケース 1/2 が「接続名が取れない」で落ちるので、黙って通ることはない。

### `bughuntWorkerTimeouts` の実装規定

`declare -A BUGHUNT_WORKER_TIMEOUTS=(` から対応する `)` までを切り出し、
`/\[([a-z0-9_-]+)\]=(\d+)/` で全件抽出する。
**ブロックが見つからなければ fail** (「bug-hunt の接続別 timeout 定義が見つからない =
一律値へ戻された可能性」)。bash / YAML という限定された構文にのみ正規表現を使う
(概念設計 §トークン解析の適用範囲)。

### PHPStan 適合チェック

- [x] 全 helper に戻り値型と `@return` の shape/list 注釈
- [x] `Yaml::parseFile()` の戻りは `mixed` → `Assert::isArray()` で narrowing してからループ
      (`AnalysisBudget::clientTimeoutSecondsFromYaml()` と同じ流儀)
- [x] `config()->integer(...)` ではなく `require config/queue.php` の生配列を読む
      (テスト env で `queue.default` が `sync` に差し替わる影響を受けないため。
      **`config()` 経由だと `.env.testing` の値が混ざる**)
- [x] 配列 offset 式のままにせずローカル変数へ移す (narrowing 保持)

### リスク

- ケース 3 (接続集合の完全一致) は強い制約。「dev で起動しない接続」を足したい人が
  詰まる。**失敗メッセージにその旨と `docs/architecture.md` の該当節を書く**。

---

## 施策 4: `QueuedJobLeaseInventoryTest` (規則 2 + 接続経路網羅)

### ファイル

`tests/Architecture/QueuedJobLeaseInventoryTest.php` (新規。DB 不使用)。

### 目録

```php
/**
 * キューに載る全クラス (ShouldQueue 実装) の接続目録。
 * value = `$this->onConnection('...')` で pin した接続名 / null = 既定接続。
 *
 * ★ deny-by-default: app/ の走査結果とこの目録の**対称差が空**であること。
 *   新しいジョブ / Mailable / Notification を足したら必ずここに登録する。
 * ★ null (既定接続) の entry は **$timeout の宣言を禁止**する
 *   (既定接続は QUEUE_CONNECTION 次第でどの接続にも化けるため、静的に
 *    retry_after と比較できない。$timeout が要るなら onConnection() で pin する)。
 *
 * @var array<class-string, string|null>
 */
const QUEUED_JOB_LEASE_INVENTORY = [
    App\Jobs\Billing\AutoRechargeTriggerJob::class => null,
    App\Jobs\Billing\ExecuteAutoRechargeAttemptJob::class => null,
    App\Jobs\Billing\HandleAutoRechargeChargeFailureJob::class => null,
    App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob::class => null,
    App\Jobs\Billing\SetDefaultPaymentMethodJob::class => null,
    App\Jobs\Billing\SyncBillingCustomerDetails::class => null,
    App\Jobs\Capture\DeleteTakeObjectsJob::class => 'database-media',
    App\Jobs\Manual\DeleteRenderOutputsJob::class => 'database-media',
    App\Jobs\Manual\RunManualAnalysis::class => 'database-analysis',
    App\Jobs\Manual\RunManualRender::class => 'database-render',
    App\Mail\InquiryAcknowledgementMail::class => null,
    App\Mail\InquiryReceivedMail::class => null,
    App\Notifications\Billing\AutoRechargeActionRequiredNotification::class => null,
    App\Notifications\Billing\AutoRechargeDisabledNotification::class => null,
    App\Notifications\Billing\AutoRechargeEnabledNotification::class => null,
    App\Notifications\Billing\AutoRechargeFailedNotification::class => null,
    App\Notifications\Billing\PaymentFailedNotification::class => null,
    App\Notifications\Billing\RenewalReminderNotification::class => null,
];
```

> 実装時に**必ず実走査の出力と突き合わせる** (上記は本設計時点の実査結果 18 件)。

### シグネチャ

```php
/**
 * app/ 配下の ShouldQueue 実装クラスを列挙する (純関数)。
 *
 * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)`
 * + `isInstantiable()`。親クラス / trait 経由の実装も拾えるため、Job だけでなく
 * Mailable / Notification も自動的に母集団へ入る。
 *
 * @return list<class-string>
 */
function shouldQueueClasses(): array;

/**
 * PHP ソースをトークン解析し、接続決定に関わる呼び出し/宣言をすべて列挙する (純関数)。
 *
 * 検出対象:
 *   - `->onConnection(...)` / `?->onConnection(...)` / `::onConnection(...)`
 *   - `->viaConnections(...)` / `->viaConnection(...)`
 *   - `connection` という名前のプロパティ宣言
 *   - `$this->connection = ...` 代入
 *
 * @return list<array{
 *     class: class-string|null,   // 宣言元クラス (T_NAMESPACE + T_CLASS から解決)
 *     kind: 'onConnection'|'viaConnections'|'viaConnection'|'property'|'assignment',
 *     receiverIsThis: bool,       // receiver が $this か
 *     literal: string|null,       // 引数が文字列リテラル 1 個のときのみ非 null
 *     line: int,
 * }>
 */
function connectionDeclarationSites(string $phpSource): array;

/**
 * ReflectionClass の default properties から $timeout を int|null へ正規化する (純関数)。
 *
 * - `array_key_exists('timeout', $defaults)` が false → null (未宣言)
 * - 値が null → null (明示 null。未宣言と同じ扱いだが区別してメッセージに出す)
 * - 値が int でない、または 0 以下 → Assert で fail
 */
function declaredJobTimeout(ReflectionClass $class): ?int;
```

### `connectionDeclarationSites` の実装規定 (トークン解析)

```
1. token_get_all($source) → T_WHITESPACE / T_COMMENT / T_DOC_COMMENT を除去した
   リストへ正規化 (index を詰める)
2. namespace: T_NAMESPACE の後続 T_NAME_QUALIFIED / T_STRING を連結
3. クラス宣言: T_CLASS の **直前が T_DOUBLE_COLON でない** (= `Foo::class` を除外) かつ
   **直後が T_STRING** (= 匿名クラス `new class(...)` / `new class extends` を除外) の場合のみ
   クラス宣言とみなし、その T_STRING をクラス名とする
4. メソッド呼び出し: T_OBJECT_OPERATOR | T_NULLSAFE_OBJECT_OPERATOR | T_DOUBLE_COLON の
   直後の T_STRING が対象名 (onConnection / viaConnections / viaConnection) のとき site を作る。
   receiverIsThis = 「直前の直前のトークンが T_VARIABLE かつ値が '$this'」かつ
   演算子が T_OBJECT_OPERATOR であること
5. リテラル判定: 対象名の次が '(' で、その次が T_CONSTANT_ENCAPSED_STRING、
   さらにその次が ')' のときだけ literal を採る (それ以外は literal = null)
6. プロパティ宣言: T_VARIABLE '$connection' の直前が可視性修飾子 / 型 / T_VAR のとき
7. 代入: T_VARIABLE '$this' + T_OBJECT_OPERATOR + T_STRING 'connection' + '='
```

### テストケース

| # | 接頭辞 | テスト名 | 検証内容 |
|---|---|---|---|
| 1 | `接続経路:` | `キューに載る全クラスが目録に登録されている` | `shouldQueueClasses()` と目録キーの**対称差が空**。差分はクラス名を列挙して表示 |
| 2 | `接続経路:` | `Job / Mailable / Notification の 3 系統が母集団に入っている` | 代表 3 クラス (`RunManualAnalysis` / `InquiryReceivedMail` / `PaymentFailedNotification`) が `shouldQueueClasses()` に含まれる (母集団判定が Job だけに縮んでいないことの behavioral 固定) |
| 3 | `接続経路:` | `接続の指定は $this->onConnection('リテラル') に限る` | `app/` 全 PHP を走査。**目録登録済みクラス内**の `kind === 'onConnection'` かつ `receiverIsThis === true` かつ `literal !== null` **以外の site をすべて違反**として列挙。違反メッセージに「動的に決まる接続は静的検査できない。実行時 fail-fast の対象として個別に扱うこと」を含める |
| 4 | `接続経路:` | `目録の接続宣言がソースと一致する` | 各クラスについて、検出した `onConnection` リテラルが目録値と一致 (検出ゼロなら目録値が `null`) |
| 5 | `規則 2:` | `接続を pin したジョブの $timeout は retry_after を下回る` | 目録値が非 null の entry で `declaredJobTimeout()` が非 null なら `timeout < retry_after(connection)` |
| 6 | `規則 2:` | `既定接続のジョブは $timeout を宣言しない` | 目録値が null の entry で `declaredJobTimeout()` が非 null なら fail。メッセージ: 「既定接続は QUEUE_CONNECTION 次第で接続が変わるため静的に retry_after と比較できない。`$this->onConnection()` で接続を pin すること」 |
| 7 | `規則 2:` | `目録の接続名が config/queue.php に実在する` | 目録の非 null 値がすべて `databaseQueueRetryAfters()` のキーに含まれる |

> `databaseQueueRetryAfters()` は施策 3 と同名の helper になる。
> **Pest のファイルスコープ関数はテストファイル間で衝突する**ため、
> `Tests\Support\QueueLeaseConfig` クラスの static メソッドとして
> `tests/Support/QueueLeaseConfig.php` に置き、両テストから使う
> (`Tests\Support\AnalysisBudget` と同じ流儀)。同様に `MPROCS_NON_WORKER_TIMEOUT_PROCS` と
> `QUEUED_JOB_LEASE_INVENTORY` は**別ファイルの const なので衝突しない**が、
> 名前を十分に固有にしておく。

### 新規 Support クラス

`tests/Support/QueueLeaseConfig.php`

```php
namespace Tests\Support;

final class QueueLeaseConfig
{
    /**
     * config/queue.php を **直接 require** して driver=database の接続を返す。
     *
     * config() 経由にしないのは、テスト env (QUEUE_CONNECTION=sync) や
     * env 上書きの影響を受けずに「リポジトリに書かれている値」を検査するため。
     *
     * @return array<string, int> 接続名 => retry_after
     */
    public static function databaseConnections(): array;
}
```

> `config/queue.php` は `env()` を呼ぶが、`database` の `retry_after` は施策 0 で
> リテラルになるので影響しない。他接続 (`beanstalkd` / `redis`) は driver が
> `database` でないので除外される。

### PHPStan 適合チェック

- [x] `@return list<class-string>` / `array<class-string, string|null>` を明示
- [x] `token_get_all()` の戻りは `array<int, array{0:int,1:string,2:int}|string>` → 正規化関数で
      `list<array{id: int|null, text: string, line: int}>` へ畳んでから走査
- [x] `ReflectionClass::getDefaultProperties()` は `array<string, mixed>` → `declaredJobTimeout()` で
      `Assert::integer()` / `Assert::greaterThan()` を通して `int` へ narrowing
- [x] `class_exists()` ではなく composer autoload 済みのクラスを
      `RecursiveDirectoryIterator` + PSR-4 変換で解決し、`ReflectionClass` 生成失敗は fail

### リスク

- クラス列挙が `app/` の全 PHP を `ReflectionClass` 化するため、副作用のある
  トップレベルコードがあると走る。`app/` は全て宣言のみ (実査済み) なので問題ない。
- ケース 3 が deny-by-default なので、将来 `dispatch()->onConnection()` を書きたい人は
  必ずここで止まる。**それが意図**であり、メッセージに代替 (ジョブ側で pin する) を書く。

---

## 施策 5: timeout 到達時の遷移を固定する Feature テスト

### ファイル

`tests/Feature/Queue/WorkerTimeoutTransitionTest.php` (新規。`Feature` レーン = `RefreshDatabase`)。

### 目的

概念設計 §ワーカー timeout に達したときに何が起きるか の **経路 A (`queue:work`)** を
behavioral に固定する。「規則 1 が守る窓」が実在することをコードで示す。

### テストケース

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `tries=1 のジョブは worker timeout で即座に failed 記録される` | `Illuminate\Queue\Worker` を実インスタンス化し、`markJobAsFailedIfWillExceedMaxAttempts` 相当の経路を `maxTries=1` で通す。`failed_jobs` に 1 行入ること |
| 2 | `tries=3 のジョブは worker timeout では failed にならず予約が残る` | 同経路を `maxTries=3` で通す。`failed_jobs` が空で `jobs.reserved_at` が残ること |

### 実装方針

**実プロセスや実 SIGALRM を使わない**。`Worker::registerTimeoutHandler()` が呼ぶのは
`markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, $maxTries, $e)` (protected) なので、
`Worker` を継承した匿名クラスで `public` に開いた薄い proxy を作り、DB queue へ実際に
push → pop した `DatabaseJob` を渡して呼ぶ。これで
「SIGALRM が発火したときに何が起きるか」を実 DB 状態で観測できる。

```php
$worker = new class(...) extends \Illuminate\Queue\Worker {
    /** timeout ハンドラが呼ぶ protected メソッドをテストから叩くための seam */
    public function failIfWillExceedMaxAttemptsForTest(
        string $connectionName,
        \Illuminate\Contracts\Queue\Job $job,
        int $maxTries,
        \Throwable $e,
    ): void {
        $this->markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, $maxTries, $e);
    }
};
```

### 検証しないと決めたこと (理由付き)

**経路 B (`queue:listen`) の終了挙動は自動テストにしない**。
実プロセス起動 + 実時間の経過 (最短でも `--timeout` 秒) が必要で、グローバルテストロック配下の
テストレーンを数分間占有する。代わりに vendor 実読の結果をテストファイル冒頭のコメントに固定する:

```
Listener::createCommand() は子へ --timeout を渡さない
→ WorkCommand の --once は Worker::runNextJob() を呼び、runNextJob() は SIGALRM を張らない
→ queue:listen 配下では Job 側 $timeout が効かず、親 Symfony Process の timeout が唯一の上限
→ 到達時は markJobAsFailedIfWillExceedMaxAttempts を通らず、予約が残ったまま子が kill され、
  ProcessTimedOutException が Listener::listen() を抜けて listener 本体も終了する
```

> この事実が変わると規則 1 の重要度そのものが変わるため、**Laravel のメジャー更新時は
> ここを再確認する**ことを `docs/architecture.md` に運用項目として書く。

---

## 施策 6: `docs/architecture.md` の運用契約明文化

### 追加する節 (新規。「AI 解析ジョブの運用契約」の直前)

```markdown
### キューのリース期間とワーカー制限時間の規約

DB driver のキューには**実行中にリース (`retry_after`) を延長する API が無い**ため、
「まだ走っている処理を落ちたと誤認させない」手段は設定の大小関係を保つことだけである。
2 本の規則を**互いに独立に**満たす (両者のあいだに大小関係は課さない)。

- **規則 1 (無条件)**: その接続で有効なワーカー / supervisor の `timeout` が、
  その接続の `retry_after` を**下回る**。1 つのワーカーは同じ接続の複数種類のジョブを
  処理するため、`$timeout` を持つジョブが 1 本あっても免除されない。
- **規則 2**: その接続で動くジョブの明示的な `$timeout` が、その接続の `retry_after` を下回る。

| 接続 | `retry_after` | ワーカー `--timeout` | 備考 |
|---|---|---|---|
| `database` | 600 | **540** | 既知の有限上限は Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s |
| `database-analysis` | 1680 | **1620** | ジョブ側 `$timeout` 1560 を上回る帯 |
| `database-render` | 1680 | **1620** | ジョブ側 `$timeout` 1500 を上回る帯 |
| `database-media` | 300 | **240** | 削除は冪等 + `$tries=3` なので kill されても再配布で完了する |

**本番/ステージングの supervisor 定義にもこの `--timeout` を必ず設定する**
(リポジトリ外にあるため CI は検知しない。上表が正本)。

- `driver=database` の接続は **dev ワーカーペイン (`mprocs.yaml`) を必ず持つ**。
  接続だけ増やしてワーカーを足し忘れるとジョブが黙って滞留する。
- 静的検査: `tests/Architecture/QueueWorkerLeaseInvariantTest.php` (規則 1。
  `mprocs.yaml` と `scripts/bug-hunt-shard.sh` の両方) /
  `tests/Architecture/QueuedJobLeaseInventoryTest.php` (規則 2 + キューに載る全クラスの
  接続目録を deny-by-default で固定)。
- **`queue:listen` ではジョブ側 `$timeout` が効かない**
  (`Listener` が子 `queue:work --once` へ `--timeout` を渡さず、`Worker::runNextJob()` は
  SIGALRM を張らない)。dev / bug-hunt では `--timeout` が唯一の上限であり、
  到達すると listener 本体も終了する。**Laravel のメジャー更新時はこの前提を再確認する**。
```

### 既存記述の更新

- 「AI 解析ジョブの運用契約」の `queue:work database-analysis` の行に
  `--timeout=1620` を追記。
- 「レンダジョブの運用契約」の `queue:work database-render` に `--timeout=1620` を追記。
- 「media queue」の `queue:work database-media` に `--timeout=240` を追記。

---

## 施策 7: 既存コメントのドリフト是正

| ファイル | 現行 | 変更後 |
|---|---|---|
| `app/Jobs/Manual/RunManualAnalysis.php` L54 | `既定 database は 90s のため` | `既定 database は 600s のため` |
| `app/Jobs/Manual/RunManualRender.php` L44 | `既定 database は 90s のため` | `既定 database は 600s のため` |
| `scripts/bug-hunt-shard.sh` L736 | `Job 側の $timeout (1,380/1,500)` | 施策 2 のコメントで置換 (実値ドリフトの解消) |

---

## 検証コマンドと期待結果

| 手順 | コマンド | 期待 |
|---|---|---|
| 1 (テストファースト) | 施策 3 のテストのみ追加 → `composer test -- --filter=QueueWorkerLease` | **fail**。mprocs 4 ペイン (接続名未指定 1 + timeout=0 が 4) と bug-hunt (`BUGHUNT_WORKER_TIMEOUTS` 未定義) が落ちる |
| 2 (テストファースト) | 施策 4 のテストのみ追加 (目録を空にして) → `composer test -- --filter=QueuedJobLease` | **fail**。18 クラス未登録 |
| 3 | 施策 0〜2 を実装 → `composer test -- --filter='QueueWorkerLease|QueuedJobLease'` | **green** |
| 4 | `composer test -- --filter='TimeBudget'` | green (analysis/render 連鎖に無影響) |
| 5 | `composer test -- --filter='AutoRecharge'` | green |
| 6 | `bash scripts/bug-hunt-shard.sh self-test` | 全ケース pass ([y4] 追加分を含む) |
| 7 | `composer phpstan` | level 10 green |
| 8 | `vendor/bin/pint --test` | green |
| 9 | `composer test` | 全 green |
| 10 (手動) | `composer dev` → mprocs の 4 queue ペインが起動し続けること | 4 ペインとも稼働 (`--timeout` 到達なし) |

> `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` は**フロント変更が無いので
> 必須ではない**が、コミット前の全 green 規約に従い一通り流す。

---

## 段階分け

### このタスクでやる

施策 0〜7 のすべて。

### 後続 TODO 候補 (このタスクではやらない)

| 候補 | 理由 |
|---|---|
| **Stripe / AWS SDK (SES・S3) の client timeout 上限固定** | 課金・送信経路の挙動変更。`PromptClientTimeoutInvariantTest` と同型の pin が要る。これを入れると `database` の 540/600 をもっと短くでき、回収遅延を縮められる |
| **既定接続の分割 (`database-billing` 新設)** | 短いジョブ (Mail/Notification) と長いジョブ (Stripe 課金) に同じ `retry_after` を被せている構造の解消。回収遅延 510 秒が実害になった時点でやる |
| **本番 supervisor 定義のリポジトリ化と gate 化** | 現在インフラは別管理でリポジトリに実体が無い。実体ができたら規則 1 gate の対象に足す |
| **実行時 fail-fast (spirux 形) の限定導入** | 標準形 v1 で「限定適用の補助」と決着済み。対象は長時間ジョブ・環境変数で接続が決まるジョブ・失敗前に外部副作用があるジョブ。今回は静的側を固める |

---

## 実装モード

| 項目 | 内容 |
|---|---|
| 推奨モード | **standalone** |
| 判断根拠 | `config/queue.php` / `mprocs.yaml` / `scripts/bug-hunt-shard.sh` / `docs/architecture.md` という**共有度の高いファイル**を同時に触る。テストファースト (gate を先に足して fail 確認) の順序も 1 本の worktree で通したい |
| 競合リスク | `scripts/bug-hunt-shard.sh` は bug-hunt 系タスクと衝突しやすい。`docs/architecture.md` は多くのタスクが触る。並行タスクがある場合は先にマージする |

---

## 関連する現行コード (抜粋)

### mprocs.yaml (全文)
procs:
  # dev server は --env=local で .env.local を読ませる (composer dev が .env を
  # .env.local にコピーする)。
  # Browser テスト (composer test:browser, pest-plugin-browser) は in-process サーバを
  # テストプロセス内に立てるため、E2E 用の常駐 serve は不要 (旧 Dusk エントリは撤去済み)。
  server:
    shell: "php artisan serve --env=local --port=8001"
  # Stripe webhook をローカル dev server に転送する (課金フェーズ導入後に有効化)。
  # stripe:
  #   shell: "stripe listen --forward-to localhost:8001/stripe/webhook"
  queue:
    shell: "php artisan queue:listen --tries=1 --timeout=0"
  # 専用 connection (analysis/render/media) は既定 database connection の default
  # キューを見る上の worker では拾われないため、docs/architecture.md の運用契約どおり
  # connection ごとに worker を分けて常駐させる (retry_after が connection 固有のため
  # 1 本にまとめない)。
  queue-analysis:
    shell: "php artisan queue:listen database-analysis --tries=1 --timeout=0"
  queue-render:
    shell: "php artisan queue:listen database-render --tries=1 --timeout=0"
  queue-media:
    shell: "php artisan queue:listen database-media --tries=1 --timeout=0"
  logs:
    shell: "php artisan pail --timeout=0"
  vite:
    shell: "pnpm run dev"

### config/queue.php (connections 部)
    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
        // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-analysis` を必須登録
        // (docs/architecture.md。滞留は analysis:recover-stale-jobs cron が回収)
        'database-analysis' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'analysis',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // レンダ専用 (RunManualRender)。retry_after は job timeout (1,500s) より長く
        // 予約 TTL (1,800s) より短い (RenderTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-render` を必須登録
        // (docs/architecture.md。滞留は render:recover-stale-jobs cron が回収)
        'database-render' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'render',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // メディア掃除専用 (DeleteTakeObjectsJob)。運用契約: worker は
        // `php artisan queue:work database-media` を必須登録 (docs/architecture.md)
        'database-media' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'media',
            'retry_after' => 300,
            'after_commit' => false,
        ],


### scripts/bug-hunt-shard.sh (worker 起動部 L703-760)
# --- 専用 queue connection worker (F-01 対策) ----------------------------------
# RunManualAnalysis / RunManualRender / DeleteTakeObjectsJob / DeleteRenderOutputsJob は
# onConnection() で専用 connection (driver=database 固定) を指定するため、
# .env.bughunt.local の QUEUE_CONNECTION=sync (default connection の差し替え) をバイパスする。
# provision が本リストの connection ごとに queue:listen worker を起動し、teardown が停止する。
# ★ リストは config/queue.php の「driver=database の専用 connection (既定 'database' を除く)」と
#   一致させること (self-test [y] が PHP 実評価で drift を機械検出する。順序は不問 = sort 比較)。
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)

# worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
# stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
# 照合は artisan / queue:listen / connection 名 / --env=bughunt.local を独立に確認する
# (単一パターンだと将来の引数順序変化で偽陰性化するため。Codex 詳細 R1 反映)。
worker_alive() {
    local shard=$1 conn=$2 pid cmdline
    pid="$(cat "$(worker_pidfile "${shard}" "${conn}")" 2>/dev/null || echo)"
    [[ -n "${pid}" && "${pid}" != 0 && -r "/proc/${pid}/cmdline" ]] || return 1
    # 存在確認と読み出しの間にプロセスが終了する race に備え、読めなければ静かに false
    cmdline="$(tr '\0' ' ' < "/proc/${pid}/cmdline" 2>/dev/null || true)"
    [[ -n "${cmdline}" ]] || return 1
    echo "${cmdline}" | grep -q "artisan" \
        && echo "${cmdline}" | grep -q "queue:listen" \
        && echo "${cmdline}" | grep -q -- " ${conn} " \
        && echo "${cmdline}" | grep -q -- "--env=bughunt.local"
}

# 専用 connection worker の起動。serve と同一の env 隔離 (env -i + bughunt 値明示注入)。
# - queue:listen を使う: 各イテレーションで子 (queue:work --once) を起動する Laravel 公式の
#   スーパーバイザ構成。reseed (migrate:fresh) で jobs/cache テーブルが一時消滅して子が
#   異常終了しても master が継続する (queue:work daemon は cache 読みの QueryException で
#   静かに死に F-01 が再発しうる)。
# - setsid で専用 process group (pid==pgid) 化: teardown が process group 一括 kill で
#   master と子を race なく停止するため。
# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
start_shard_workers() {
    local shard=$1 db=$2 url=$3
    guard_bughunt_runtime "${db}" bughunt
    local conn pid
    # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
    # worker は serve と同一の env 隔離 + モードフラグ + 実キー (real-llm 時のみ) を注入する。
    secret_xtrace_off
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        env -i PATH="${PATH}" HOME="${HOME}" \
            DB_CONNECTION=pgsql \
            DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
            DB_DATABASE="${db}" DB_USERNAME=bughunt DB_PASSWORD="$(env_file_get DB_PASSWORD)" \
            APP_URL="${url}" \
            ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
            setsid php artisan queue:listen "${conn}" --env=bughunt.local \
                --sleep=1 --tries=1 --timeout=1800 \
            > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
        pid=$!
        echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
    done
    secret_xtrace_restore
    # fail-fast: 起動 1 秒後の即死検知 (artisan 起動失敗・接続不能などを provision 段階で顕在化)。
    # 併せて pid==pgid (setsid が新 session/process group を確立したこと) を検証する

### scripts/bug-hunt-shard.sh (self-test [y] 部 L1685-1715)
    [[ "$(worker_logfile 0 database-render)" == "${TMP_BASE}/worker-0-database-render.log" ]] \
        || t_fail "worker_logfile 導出"

    # (y2) config/queue.php との drift check (PHP 実評価。grep でなく実 config を読む)
    local expected_conns actual_conns
    expected_conns="$(cd "${WORKSPACE}" && php -r '
        require "vendor/autoload.php";
        $cfg = require "config/queue.php";
        $names = [];
        foreach ($cfg["connections"] as $name => $conn) {
            if (($conn["driver"] ?? "") === "database" && $name !== "database") { $names[] = $name; }
        }
        sort($names);
        echo implode(" ", $names);
    ' 2>/dev/null || echo "__php_failed__")"
    actual_conns="$(printf '%s\n' "${BUGHUNT_WORKER_CONNECTIONS[@]}" | sort | tr '\n' ' ' | sed 's/ $//')"
    if [[ "${expected_conns}" == "__php_failed__" ]]; then
        t_fail "drift check 実行不能: vendor/autoload.php または config/queue.php を PHP 評価できない (依存未導入なら composer install 後に再実行)"
    elif [[ "${expected_conns}" != "${actual_conns}" ]]; then
        t_fail "drift: config/queue.php の専用 connection (${expected_conns}) と BUGHUNT_WORKER_CONNECTIONS (${actual_conns}) が不一致"
    fi

    # (y3) 構造検査 (既存 [w] と同じ流儀): provision → start_shard_workers → setsid/queue:listen、
    #      teardown → stop_shard_workers が serve kill より前、旧 `|| continue` の復活防止
    local prov_def wrk_def stopw_def td2_def
    prov_def="$(declare -f cmd_provision)"
    echo "${prov_def}" | grep -q 'start_shard_workers' || t_fail "cmd_provision に worker 起動配線が無い"
    wrk_def="$(declare -f start_shard_workers)"
    echo "${wrk_def}" | grep -q 'setsid php artisan queue:listen' || t_fail "start_shard_workers が setsid + queue:listen でない"
    echo "${wrk_def}" | grep -q 'guard_bughunt_runtime' || t_fail "start_shard_workers が guard を通していない"
    echo "${wrk_def}" | grep -q 'env -i' || t_fail "start_shard_workers が env -i 隔離でない"

### tests/Support/AnalysisBudget.php (Support クラスの先例)
<?php

declare(strict_types=1);

namespace Tests\Support;

use Symfony\Component\Yaml\Yaml;
use Webmozart\Assert\Assert;

/**
 * AI 解析の時間 budget 不変条件で使う「仕様値」と、prompt YAML からの実測読み出し。
 *
 * Pest のファイルスコープ const / 関数はテスト間で衝突しうるため、
 * Tests\Support\PromptYaml と同じく autoload されるクラスに集約する。
 *
 * **CLIENT_TIMEOUT_SECONDS は仕様値であり、YAML から導出しない**。
 * これは意図的な重複である: YAML と仕様値を突き合わせることで初めて
 * 「YAML を勝手に変えた」ことを検出できる (YAML から導出すると同時変更で素通りする)。
 */
final class AnalysisBudget
{
    /** C: 1 呼び出しの client timeout (仕様値。prompt YAML と一致すること) */
    public const CLIENT_TIMEOUT_SECONDS = 360;

    /** extract / decompose / generate */
    public const STAGE_COUNT = 3;

    /** M₁: deadline 通過後の terminal tx + commit/release + 通知 */
    public const FINALIZE_BUDGET_SECONDS = 30;

    /** S: P (worker alarm → run() 入口) + タイマー精度 + シグナル配送 + ログ */
    public const SAFETY_MARGIN_SECONDS = 90;

    /** D: パイプライン deadline の仕様値 */
    public const DEADLINE_SECONDS = self::STAGE_COUNT * self::CLIENT_TIMEOUT_SECONDS;

    /** 解析パイプラインの 3 プロンプト */
    public const PROMPT_NAMES = ['sop-extract', 'work-decomposition', 'scenario-generation'];

    /**
     * prompt YAML から読んだ client_options.timeout (プロンプト名 => 値)。
     *
     * @return array<string, int>
     */
    public static function clientTimeoutSecondsFromYaml(): array
