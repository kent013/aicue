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
  - **施策 3 / 4 の 2 テストは `Architecture` レーン (DB 不使用・ファイル走査のみ)**
  - **施策 5 の 1 テストは `Feature` レーン (`RefreshDatabase` グローバル適用・DB を使う)**
  - 個別の `DatabaseTransactions` は使わない
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
| 5 | timeout 到達時の遷移を Feature テストで固定 | `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` + `tests/Support/Queue/TriesOnceProbeJob.php` / `TriesThriceProbeJob.php` | 新規 | 必須 |
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

### テスト計画

- 施策 3 の gate に **`規則 1: database の retry_after は env で上書きできない`** を追加する
  (Codex 詳細 R1 Critical)。値の固定だけでなく
  **`env('DB_QUEUE_RETRY_AFTER', 600)` への後退**を検出するため。テストを 2 箇所に分けず
  規則 1 gate に置く (同じ config を読む検査だから)。

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

`start_shard_workers` のループ内 (`set -u` 下の未定義参照を避けるため**一度ローカルへ受ける**):

```bash
# - --tries=1 は Job 側の $tries=1 と整合。--timeout は BUGHUNT_WORKER_TIMEOUTS の
#   接続別の値 (規則 1)。listener が子を kill する天井であり、queue:listen では
#   Job 側 $timeout が効かないためこれが唯一の上限になる。
    local conn pid wtimeout
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wtimeout="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"
        [[ -n "${wtimeout}" ]] \
            || die 1 "BUGHUNT_WORKER_TIMEOUTS に ${conn} の値が無い (規則 1: listener timeout 未定義では起動しない)"
        env -i PATH="${PATH}" HOME="${HOME}" \
            ... \
            setsid php artisan queue:listen "${conn}" --env=bughunt.local \
                --sleep=1 --tries=1 --timeout="${wtimeout}" \
```

### self-test への追加 (cmd_self_test の [y] 群。(y3) の**後**に置く)

```bash
    # (y4) 接続別 listener timeout: 全 connection が鍵を持ち、値が retry_after 未満であること
    #      (規則 1 の実行配線側。静的側は QueueWorkerLeaseInvariantTest が二段目)
    local conn_rt wt
    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
        wt="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"
        [[ -n "${wt}" ]] \
            || t_fail "BUGHUNT_WORKER_TIMEOUTS に ${conn} の値が無い (規則 1 の検査対象から漏れる)"
        # ★ vendor/autoload.php を require する (config/queue.php が env() を呼ぶため。既存 [y2] と同じ)
        conn_rt="$(cd "${WORKSPACE}" && php -r '
            require "vendor/autoload.php";
            $cfg = require "config/queue.php";
            echo (int) ($cfg["connections"][$argv[1]]["retry_after"] ?? 0);
        ' "${conn}" 2>/dev/null || echo "__php_failed__")"
        # ★ 算術比較の前に形式検査する (不正値で bash の算術評価エラーにしない)
        if [[ "${conn_rt}" == "__php_failed__" ]]; then
            t_fail "規則 1 検査 実行不能: config/queue.php を PHP 評価できない (composer install 後に再実行)"
        elif [[ ! "${wt}" =~ ^[0-9]+$ ]]; then
            t_fail "BUGHUNT_WORKER_TIMEOUTS[${conn}] が正の整数でない (${wt})"
        elif [[ ! "${conn_rt}" =~ ^[0-9]+$ || "${conn_rt}" -le 0 ]]; then
            t_fail "config の ${conn}.retry_after が正の整数でない (${conn_rt})"
        elif [[ "${wt}" -le 0 || "${wt}" -ge "${conn_rt}" ]]; then
            t_fail "規則 1 違反: ${conn} の listener timeout (${wt}) が retry_after (${conn_rt}) 以上"
        fi
    done
    # 起動行が数値リテラル直書きへ戻っていないこと (配列経由の強制)
    echo "${wrk_def}" | grep -q -- 'BUGHUNT_WORKER_TIMEOUTS' \
        || t_fail "start_shard_workers が BUGHUNT_WORKER_TIMEOUTS 経由で --timeout を渡していない"
    # ★ `--timeout=1800` だけでなく `--timeout 1800` (空白区切り) も禁止する
    echo "${wrk_def}" | grep -qE -- '--timeout(=|[[:space:]]+)[0-9]+' \
        && t_fail "start_shard_workers に --timeout の数値直書きが復活している (接続別の値を潰す)"
```

> `wrk_def` は既存 (y3) で `declare -f start_shard_workers` として取得済み。

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
 * bash ソースから `名前() { ... }` の関数定義本体を切り出す (純関数)。
 *
 * ★ 波括弧カウントは使わない (`${...}` を関数ブロックと誤認する。Codex 詳細 R3 Warning)。
 *   **行単位**で抽出する:
 *     - 開始行: `/^{名前}\(\)[ \t]*\{$/` — 0 件または 2 件以上なら fail
 *     - 終了行: 開始行より後で最初に現れる列頭 `/^\}$/` — 見つからなければ fail
 *   対象スクリプトはこの整形規約に従っている (実査済み)。
 */
function extractBashFunction(string $bashSource, string $functionName): string;

/** 行頭 (前置空白可) の `#` で始まる行を除去する (純関数)。 */
function stripBashCommentLines(string $bash): string;

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
| 6 | `規則 1: bug-hunt の起動行は --timeout を配列経由で渡す` | **`start_shard_workers` の関数定義本体からコメント行を除去したもの**を対象に、`--timeout="${wtimeout}"` を含み、かつ `/--timeout(?:=|\s+)\d+/` にマッチ**しない**こと。★ 全文を対象にすると施策 2 で入れる説明コメント (「旧実装は `--timeout=1800`」) 自身を拾って**必ず失敗する** (Codex 詳細 R2 Critical)。self-test 側が `declare -f` を対象にしているのと範囲を揃える |
| 7 | `規則 1: database の retry_after は env で上書きできない` | `Env::getRepository()->set('DB_QUEUE_RETRY_AFTER', '1')` を仕掛けた状態で `config/queue.php` を新規 `require` しても `retry_after === 600`。`try`/`finally` で `clear()` して必ず戻す (Codex 詳細 R1 Critical) |

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

### ケース 7 (env 上書き禁止) の実装規定

```php
use Illuminate\Support\Env;

$repository = Env::getRepository();
// ★ 元の値を保存する (無条件 clear は既存の env 設定を破壊する。Codex 詳細 R2 Warning)
$hadOriginal = $repository->has('DB_QUEUE_RETRY_AFTER');
$original = $hadOriginal ? $repository->get('DB_QUEUE_RETRY_AFTER') : null;

$repository->set('DB_QUEUE_RETRY_AFTER', '1');

try {
    // config() ではなく **新規 require**。config は起動時に解決済みで再評価されないため。
    $config = require base_path('config/queue.php');
    Assert::isArray($config);
    // ... connections.database.retry_after を取り出す
    expect($retryAfter)->toBe(600, 'database.retry_after が env で上書きされた (リテラルに戻すこと)');
} finally {
    if ($hadOriginal && is_string($original)) {
        $repository->set('DB_QUEUE_RETRY_AFTER', $original);
    } else {
        $repository->clear('DB_QUEUE_RETRY_AFTER');
    }
}
```

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
 * PHP ソースをトークン解析し、接続 / timeout の決定に関わる site をすべて列挙する (純関数)。
 *
 * 検出対象:
 *   - `->onConnection(...)` / `?->onConnection(...)` / `::onConnection(...)`
 *   - `->viaConnections(...)` / `->viaConnection(...)`
 *   - **クラス直下**の `$connection` / `$timeout` プロパティ宣言 (デフォルト値の有無つき)
 *   - `$this->connection = ...` / `$this->timeout = ...` 代入
 *
 * @return list<array{
 *     class: class-string|null,   // 宣言元クラス (T_NAMESPACE + T_CLASS から解決。クラス外なら null)
 *     kind: 'onConnection'|'viaConnections'|'viaConnection'
 *           |'connectionProperty'|'connectionAssignment'
 *           |'timeoutProperty'|'timeoutAssignment',
 *     receiverIsThis: bool,       // receiver が $this か (呼び出し系のみ意味を持つ)
 *     literal: string|null,       // 引数が文字列リテラル 1 個のときのみ非 null
 *     hasDefault: bool,           // プロパティ宣言系のみ: `= <値>` を伴うか
 *     line: int,
 * }>
 */
function connectionDeclarationSites(string $phpSource): array;

/**
 * ReflectionClass の default properties から $timeout を int|null へ正規化する (純関数)。
 *
 * ★ この関数が信頼できるのは、`connectionDeclarationSites()` 側で
 *   「`$timeout` は正の int デフォルト値を持つプロパティ宣言のみ」を強制した後だけである
 *   (constructor 代入や default 無しの typed property は Reflection から見えない)。
 *
 * - `array_key_exists('timeout', $defaults)` が **false のときだけ** null を返す (未宣言 = 正常)
 * - 宣言されている値が **null / 非 int / 0 以下 → Assert で fail**
 *   (「`$timeout` は正の int デフォルト値を持つ宣言に限る」。
 *    明示 `public ?int $timeout = null` を未宣言と同一視すると規則 2 を素通りする。
 *    Codex 詳細 R3 Critical)
 */
function declaredJobTimeout(ReflectionClass $class): ?int;
```

### `connectionDeclarationSites` の実装規定 (トークン解析)

```
1. token_get_all($source) → T_WHITESPACE / T_COMMENT / T_DOC_COMMENT を除去した
   リストへ正規化 (index を詰める)。以降「トークン i」はこの正規化列の添字
2. namespace: T_NAMESPACE の後続 T_NAME_QUALIFIED / T_STRING を連結
3. **深さの追跡** (Codex 詳細 R1 Critical):
   - `{` で braceDepth++ / `}` で braceDepth--
   - `(` で parenDepth++ / `)` で parenDepth--
4. **クラススコープはスタックで管理する** (Codex 詳細 R2 Warning。
   単一の `classBodyDepth` だと匿名クラス / ネスト宣言の site を外側のクラスへ誤帰属させる):
   - T_CLASS の **直前が T_DOUBLE_COLON でない** (= `Foo::class` を除外) こと。そのうえで
     - **直後が T_STRING** → 名前付きクラス宣言。`{class: "{namespace}\\{名前}", bodyDepth: …}` を push
     - **直後が T_STRING でない** (`new class(...)` / `new class extends ...`) → **匿名クラス**。
       `{class: null, bodyDepth: …}` を push (内部の site を外側クラスに帰属させない)
   - `bodyDepth` は「クラス宣言直後の開き `{` を処理して **braceDepth++ した後**の値」
   - **pop の順序を固定する** (off-by-one 防止。Codex 詳細 R3 Critical):
     ```
     「}」トークンを見たとき:
       1. スタック最上位の bodyDepth === 現在の braceDepth なら、その「}」はクラス終端 → pop
       2. その後 braceDepth--
     ```
     (先に braceDepth-- してから比較すると、メソッド終端の `}` で誤って pop する)
   - site の `class` は**スタック最上位**の値 (空なら `null` = クラス外)
5. メソッド呼び出し: T_OBJECT_OPERATOR | T_NULLSAFE_OBJECT_OPERATOR | T_DOUBLE_COLON の
   直後の T_STRING が対象名 (onConnection / viaConnections / viaConnection) のとき site を作る。
   receiverIsThis = 「演算子が T_OBJECT_OPERATOR」かつ「その直前が T_VARIABLE で値が '$this'」
6. リテラル判定: 対象名の次が '(' で、その次が T_CONSTANT_ENCAPSED_STRING、
   さらにその次が ')' のときだけ literal を採る (それ以外は literal = null)。
   literal 値は前後のクォートを剥がす
7. **プロパティ宣言** (`$connection` / `$timeout`):
   T_VARIABLE の値が '$connection' / '$timeout' で、かつ
   **`braceDepth === スタック最上位の bodyDepth` かつ `parenDepth === 0`** のときのみ
   プロパティ宣言とみなす
   (= クラス直下の property statement)。これにより
   `function foo(string $connection)` (parenDepth > 0) と
   メソッド本体のローカル変数 (braceDepth > classBodyDepth) を除外する。
   hasDefault = 直後のトークンが `=` であること
8. 代入: T_VARIABLE '$this' + T_OBJECT_OPERATOR + T_STRING ('connection' | 'timeout') + '='
   (parenDepth / braceDepth は問わない。どこで代入しても違反)
```

> **`Queue::connection(...)` は検出対象に入れない**。`connection` は汎用名で、
> Eloquent の `->connection()` 等と衝突して偽陽性が大量に出る。
> ジョブの接続を実際に差し替えられる経路は `onConnection` / `viaConnections` /
> `$connection` プロパティの 3 つで、`Queue::connection()->push()` は
> 「どのキューへ push するか」であってジョブクラスの契約ではない
> (かつ本アプリに 1 件も無い)。この判断を**テストの冒頭コメントに理由付きで残す**。

### テストケース

| # | 接頭辞 | テスト名 | 検証内容 |
|---|---|---|---|
| 1 | `接続経路:` | `キューに載る全クラスが目録に登録されている` | `shouldQueueClasses()` と目録キーの**対称差が空**。差分はクラス名を列挙して表示 |
| 2 | `接続経路:` | `Job / Mailable / Notification の 3 系統が母集団に入っている` | 代表 3 クラス (`RunManualAnalysis` / `InquiryReceivedMail` / `PaymentFailedNotification`) が `shouldQueueClasses()` に含まれる (母集団判定が Job だけに縮んでいないことの behavioral 固定) |
| 3 | `接続経路:` | `接続の指定は $this->onConnection('リテラル') に限る` | **`app/` 配下の全 PHP ファイル**を走査 (目録クラスのファイルだけではない)。**母集団は接続関連 kind のみ** = `['onConnection', 'viaConnections', 'viaConnection', 'connectionProperty', 'connectionAssignment']` (timeout 関連 kind はケース 5 の担当。混ぜると正当な `$timeout` 宣言まで落ちる。Codex 詳細 R2 Critical)。許可されるのは「**宣言元クラスが目録に登録済み** かつ `kind === 'onConnection'` かつ `receiverIsThis === true` かつ `literal !== null`」の site **のみ**。それ以外はすべて違反 —— クラス外 (`class === null`)、Controller / Service など非 queued クラス内の `Foo::dispatch()->onConnection(...)`、`$job->onConnection(...)`、非リテラル引数、`viaConnections` / `viaConnection`、`$connection` プロパティ宣言、`$this->connection =` 代入。違反メッセージに「動的に決まる接続は静的検査できない。ジョブ側で `$this->onConnection('リテラル')` に寄せるか、実行時 fail-fast の対象として個別に扱うこと」を含める |
| 4 | `接続経路:` | `目録の接続宣言がソースと一致する` | 各目録クラスについて、検出した `onConnection` site が **0 件または 1 件**であること (複数は fail = 「接続を 2 回指定していてどちらが効くか読めない」)。1 件ならリテラルが目録値と一致、0 件なら目録値が `null` |
| 5 | `規則 2:` | `キューに載るクラスの $timeout は正の int デフォルト値を持つプロパティ宣言に限る` | **母集団は「site の `class` が目録キーに含まれる timeout 関連 kind」のみ** (キューと無関係なクラスの `$timeout` は本不変条件の対象外。Codex 詳細 R2 Warning)。`kind === 'timeoutAssignment'` が 1 件でもあれば fail。`kind === 'timeoutProperty'` で `hasDefault === false` も fail。メッセージ: 「実行時に決まる `$timeout` は静的検査できない」(Codex 詳細 R1 Critical) |
| 6 | `規則 2:` | `接続を pin したジョブの $timeout は retry_after を下回る` | 目録値が非 null の entry で `declaredJobTimeout()` が非 null なら `timeout < retry_after(connection)` |
| 7 | `規則 2:` | `既定接続のジョブは $timeout を宣言しない` | 目録値が null の entry で `declaredJobTimeout()` が非 null なら fail。メッセージ: 「既定接続は QUEUE_CONNECTION 次第で接続が変わるため静的に retry_after と比較できない。`$this->onConnection()` で接続を pin すること」 |
| 8 | `規則 2:` | `目録の接続名が config/queue.php に実在する` | 目録の非 null 値がすべて `QueueLeaseConfig::databaseConnections()` のキーに含まれる |

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

### 新規テスト用ジョブ (`app/` ではなく `tests/` に置く)

`tests/Support/Queue/` に 2 本。**`app/` 配下ではないので施策 4 の目録走査を汚さない**。

```php
namespace Tests\Support\Queue;

/** worker timeout の遷移検証用 (tries=1)。handle は何もしない */
final class TriesOnceProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Bus\Queueable;
    use \Illuminate\Foundation\Bus\Dispatchable;
    use \Illuminate\Queue\InteractsWithQueue;

    public int $tries = 1;

    public function handle(): void {}
}

/** 同上 (tries=3) */
final class TriesThriceProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue { /* $tries = 3 */ }
```

### 実装方針

**実プロセスや実 SIGALRM を使わない**。`Worker::registerTimeoutHandler()` の SIGALRM
ハンドラが呼ぶのは `markJobAsFailedIfWillExceedMaxAttempts($connectionName, $job, $maxTries, $e)`
(protected) なので、**`ReflectionMethod::invoke()`** でその 1 メソッドだけを叩く
(PHP 8.1 以降は非 public でも `setAccessible()` 不要。`Closure::bind()` は
`Closure|null` を返し、クロージャ内 `$this` の型付けが PHPStan level 10 で扱いにくいので使わない。
Codex 詳細 R2 Warning)。`app('queue.worker')` を使うので constructor 依存も書かずに済む。

```php
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Queue\Worker;
use Illuminate\Support\Facades\Queue;
use Webmozart\Assert\Assert;

// テスト env は QUEUE_CONNECTION=sync なので **接続名を必ず明示**する
Queue::connection('database')->push(new TriesOnceProbeJob);
$job = Queue::connection('database')->pop();   // DatabaseJob (reserved_at が入る)
Assert::isInstanceOf($job, QueueJobContract::class);

$worker = app('queue.worker');                 // QueueServiceProvider が bind 済み
Assert::isInstanceOf($worker, Worker::class);

// SIGALRM ハンドラ (Worker::registerTimeoutHandler) が呼ぶのと同じ経路
$method = new ReflectionMethod(Worker::class, 'markJobAsFailedIfWillExceedMaxAttempts');
$method->invoke($worker, 'database', $job, 1, new RuntimeException('timeout'));
```

### テストケース

| # | テスト名 | 検証内容 |
|---|---|---|
| 1 | `tries=1 のジョブは worker timeout で即座に failed 記録される` | 上記経路を `maxTries = 1` で実行 → `failed_jobs` が 1 行 |
| 2 | `tries=3 のジョブは worker timeout では failed にならず予約が残る` | 同経路を `maxTries = 3` で実行 → `failed_jobs` が 0 行、`jobs` テーブルに `reserved_at` が入った行が残る (= `retry_after` 経過まで再配布されない = 規則 1 が守る窓) |

> `maxTries` は「CLI `--tries` とジョブ `$tries` の合成」なので、テストは
> **合成後の値を直接渡す**形にする (合成ロジック自体は Laravel の責務でテストしない)。

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

## Codex 合議の結果 (打ち切り記録)

オーケストレータ指示により**最大 3 ラウンド**。全記録は
`detailed-review-round-{1,2,3}.md` と `codex-history/design-review-decisions-round-{1,2,3}.md`。

| Round | 全体判定 | Critical | Warning | 処理 |
|---|---|---|---|---|
| 1 | CHANGES_REQUESTED | 4 | 5 | 全件対応 (dispatch 側の見逃し / プロパティ誤検出 / constructor 代入の `$timeout` / self-test の autoload 漏れ ほか) |
| 2 | CHANGES_REQUESTED | 2 | 5 | 全件対応 (**bash 全文検索が自コメントを拾って必ず失敗する**穴 / ケース 3 が正当な `$timeout` を落とす穴 ほか) |
| 3 | CHANGES_REQUESTED | 2 | 1 | 全件対応 (クラススコープ pop の off-by-one / 明示 `?int $timeout = null` の素通り / `extractBashFunction` の規定不足) |

Round 3 の指摘は**いずれも「実装前に規定を直せば局所的に解決できる」もの**で、全件を設計へ反映済み。
未解決の Critical / Warning は残っていない (Round 4 を回していれば APPROVED になる見込みだが、
ラウンド上限に従って打ち切った)。

**Round 1〜3 で潰した「実装したら即赤くなる」穴**:

1. self-test の `php -r` に `vendor/autoload.php` の require が無い (施策 2)
2. Architecture テストが bash 全文を検索し、施策 2 で入れる説明コメント中の
   `--timeout=1800` を自分で拾って**必ず失敗**する (施策 3 ケース 6)
3. 接続経路の deny-by-default が timeout site まで巻き込み、
   正当な `RunManualAnalysis::$timeout` / `RunManualRender::$timeout` を落とす (施策 4 ケース 3)
4. クラススコープ pop の off-by-one でメソッド終端の `}` によりスコープが壊れる (施策 4)
