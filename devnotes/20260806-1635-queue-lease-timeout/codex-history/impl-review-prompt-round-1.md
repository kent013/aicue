# 使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

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

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

Laravel 12/13 + Svelte 5 (Inertia) アプリ AI-CUE の**実装レビュアー**。
TODO T122「キューのリース期間とワーカー制限時間の整合 (規則 1)」の実装差分をレビューする。

## レビュー観点

1. **設計との一致性**: 詳細設計の施策 0〜7 が漏れなく・設計どおりに実装されているか。設計から逸脱した箇所は、逸脱が正当か (実コードの事実に基づくか) を判定する
2. **正確性**: 静的検査 (トークン解析 / bash 抽出 / YAML 解析) に**偽陰性 (すり抜け) と偽陽性 (正当なコードを落とす)** が無いか。とくに「gate が実際には何も検査していない (空洞化)」パターンを疑え
3. **PHPStan 適合性 (level 10)**: 型の widen / ignore で黙らせていないか (テストは PHPStan の解析対象外だが、可読性・型注釈の正しさは見る)
4. **テスト網羅性**: 不変条件が Architecture/Feature テストへの登録まで含めて実装されているか。テストを緑にするためのアサーション緩和が無いか
5. **セキュリティ・運用安全性**: dev/本番の worker 停止・二重実行のリスク、bash の quoting / set -u 安全性
6. **本タスクにフロント差分は無い** (DESIGN.md / Atomic Design 観点は該当なし)

## 出力形式

- ファイルごとに判定と指摘を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** に分類する
- 最後に全体判定を **APPROVED** または **CHANGES_REQUESTED** の 1 行で書く

---

## 前提 (与件。蒸し返さない)

- 規則 1: その接続で有効なワーカーの `--timeout` は、その接続の `retry_after` を**下回る**。無条件。
- 規則 2: その接続で動くジョブの `$timeout` は、その接続の `retry_after` を下回る。
- 採用値: database 600/540・database-analysis 1680/1620・database-render 1680/1620・database-media 300/240。
- スコープ外 (後続 TODO): Stripe/AWS SDK の client timeout 固定、既定接続の分割、本番 supervisor 定義の gate 化、実行時 fail-fast、queue:listen 経路の実プロセステスト。

## 実装で設計から意図的に逸脱した点 (レビュー対象)

1. 施策 5 (`tests/Feature/Queue/WorkerTimeoutTransitionTest.php`): 設計は「`failed_jobs` が 1 行」を検証する計画だったが、vendor 実読の結果 **`failed_jobs` への記録は Worker ではなく `queue:work` コマンド側の `JobFailed` リスナ (`WorkCommand::logFailedJob()`) が行う**ため、Worker 層だけを叩くテストでは観測できない (実際に 0 行で fail した)。そこで失敗確定の分岐点である `JobFailed` イベントの発火有無 + `jobs` テーブルの残存/予約状態を検証する形に変更した。
2. 施策 2 の self-test 追加ケースの番号: 設計は `(y4)` としていたが、既存の `(y4)`〜`(y6)` を renumber しないため `(y3b)` として `(y3)` の直後に挿入した。
3. 施策 4 のトークン解析: クラススコープの push を `class` だけでなく `trait` / `interface` / `enum` にも広げた (いずれも `class = null` で push)。理由: trait 直下の `$connection` プロパティ宣言を検出できるようにするため (検出漏れを塞ぐ方向であり、緩める方向ではない)。
4. Pest の `toHaveKey()` / `toContain()` は第 2 引数がメッセージではない (それぞれ期待値・追加 needle) ため、`expect(array_key_exists(...))->toBeTrue('メッセージ')` / `expect(str_contains(...))->toBeTrue('メッセージ')` に置き換えた。

## テスト結果 (worktree 内)

- `composer test` (全量): **3409 tests / 3407 passed / 0 failed / 2 skipped / 12964 assertions**
- テストファースト時の fail 確認: 施策 3 のみ先行追加 → 7 tests 中 5 failed + 1 error (mprocs 接続名未指定・timeout=0・接続網羅・BUGHUNT_WORKER_TIMEOUTS 不在・env 上書き)。施策 4 を目録空で追加 → 18 クラス未登録で fail。
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: 本差分のファイルはすべて green (main から持ち越しの `devnotes/20260806-1634-throttle-unauthenticated-get/measure-population.php` のみ既存 fail。本タスクの管轄外)
- `bash scripts/bug-hunt-shard.sh self-test`: all passed。加えて `[database-media]=240` を 999 に改竄すると新ケースが `規則 1 違反: database-media の listener timeout (999) が retry_after (300) 以上` で落ちることを確認済み
- `pnpm lint` / `pnpm typecheck`: green (フロント差分なし)


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


## 実装差分 (git diff HEAD)

```diff
diff --git a/app/Jobs/Manual/RunManualAnalysis.php b/app/Jobs/Manual/RunManualAnalysis.php
index bc35c06..d45e4b1 100644
--- a/app/Jobs/Manual/RunManualAnalysis.php
+++ b/app/Jobs/Manual/RunManualAnalysis.php
@@ -51,7 +51,7 @@ class RunManualAnalysis implements ShouldQueue
 
     public function __construct(public readonly int $analysisJobId)
     {
-        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 90s のため。
+        // retry_after を解析専用値にした connection (config/queue.php)。既定 database は 600s のため。
         // Queueable trait が $connection プロパティを既に定義しているため、プロパティ再宣言でなく
         // onConnection() で指定する (typed 再宣言は trait composition エラーになる)
         $this->onConnection('database-analysis');
diff --git a/app/Jobs/Manual/RunManualRender.php b/app/Jobs/Manual/RunManualRender.php
index 53d5f18..785be60 100644
--- a/app/Jobs/Manual/RunManualRender.php
+++ b/app/Jobs/Manual/RunManualRender.php
@@ -41,7 +41,7 @@ class RunManualRender implements ShouldQueue
 
     public function __construct(public readonly int $renderJobId)
     {
-        // retry_after をレンダ専用値にした connection (config/queue.php)。既定 database は 90s のため。
+        // retry_after をレンダ専用値にした connection (config/queue.php)。既定 database は 600s のため。
         // Queueable trait が $connection プロパティを既に定義しているため onConnection() で指定する
         $this->onConnection('database-render');
     }
diff --git a/config/queue.php b/config/queue.php
index 09cbb4e..8ed6d7f 100644
--- a/config/queue.php
+++ b/config/queue.php
@@ -35,12 +35,19 @@
             'driver' => 'sync',
         ],
 
+        // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
+        // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
+        // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
+        // 600s の根拠: この接続の既知の有限上限は ExecuteAutoRechargeAttemptJob の
+        // Stripe 4〜5 呼び出し × SDK 上限 80s (Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT)
+        // = 約 400s。ワーカー --timeout 540 (< 600) がそれを上回る
+        // (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
         'database' => [
             'driver' => 'database',
             'connection' => env('DB_QUEUE_CONNECTION'),
             'table' => env('DB_QUEUE_TABLE', 'jobs'),
             'queue' => env('DB_QUEUE', 'default'),
-            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
+            'retry_after' => 600,
             'after_commit' => false,
         ],
 
diff --git a/docs/architecture.md b/docs/architecture.md
index d2deafe..a2514cc 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -242,11 +242,50 @@ ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
 - 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
   「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)
 
+### キューのリース期間とワーカー制限時間の規約
+
+DB driver のキューには**実行中にリース (`retry_after`) を延長する API が無い**ため、
+「まだ走っている処理を落ちたと誤認させない」手段は設定の大小関係を保つことだけである。
+リースが切れると、まだ走っているジョブが**別のワーカーへ再配布される** (二重実行)。
+2 本の規則を**互いに独立に**満たす (両者のあいだに大小関係は課さない)。
+
+- **規則 1 (無条件)**: その接続で有効なワーカー / supervisor の `--timeout` が、
+  その接続の `retry_after` を**下回る**。1 つのワーカーは同じ接続の複数種類のジョブを
+  処理するため、`$timeout` を持つジョブが 1 本あっても免除されない。
+- **規則 2**: その接続で動くジョブの明示的な `$timeout` が、その接続の `retry_after` を下回る。
+
+| 接続 | `retry_after` | ワーカー `--timeout` | 備考 |
+|---|---|---|---|
+| `database` | 600 | **540** | 既知の有限上限は Stripe 4〜5 呼び出し × SDK 上限 80s = 約 400s |
+| `database-analysis` | 1680 | **1620** | ジョブ側 `$timeout` 1,560 を上回る帯 |
+| `database-render` | 1680 | **1620** | ジョブ側 `$timeout` 1,500 を上回る帯 |
+| `database-media` | 300 | **240** | 削除は冪等 + `$tries=3` なので kill されても再配布で完了する |
+
+**本番/ステージングの supervisor 定義にもこの `--timeout` を必ず設定する**
+(リポジトリ外にあるため CI は検知しない。上表が正本)。
+
+- `driver=database` の接続は **dev ワーカーペイン (`mprocs.yaml`) を必ず持つ**。
+  接続だけ増やしてワーカーを足し忘れるとジョブが黙って滞留する。
+- 静的検査: `tests/Architecture/QueueWorkerLeaseInvariantTest.php` (規則 1。
+  `mprocs.yaml` と `scripts/bug-hunt-shard.sh` の両方) /
+  `tests/Architecture/QueuedJobLeaseInventoryTest.php` (規則 2 + キューに載る全クラスの
+  接続目録を deny-by-default で固定)。ワーカー timeout 到達時の遷移は
+  `tests/Feature/Queue/WorkerTimeoutTransitionTest.php` が behavioral に固定する。
+- **`queue:listen` ではジョブ側 `$timeout` が効かない**
+  (`Listener` が子 `queue:work --once` へ `--timeout` を渡さず、`Worker::runNextJob()` は
+  SIGALRM を張らない)。dev / bug-hunt では `--timeout` が唯一の上限であり、
+  到達すると listener 本体も終了する。**Laravel のメジャー更新時はこの前提を再確認する**
+  (前提が変わると規則 1 の重要度そのものが変わる)。
+- `database` の `retry_after` は **env で上書きできないリテラル**で持つ
+  (静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
+  「gate は通るが本番の実値は別」を作れてしまう)。
+
 ### AI 解析ジョブの運用契約
 
 - 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**
   (queue=analysis、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
-  デプロイ手順・監視対象に `php artisan queue:work database-analysis` を必須項目として登録する**
+  デプロイ手順・監視対象に `php artisan queue:work database-analysis --timeout=1620` を
+  必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
   (専用 worker が居ないとジョブは滞留する。queued 滞留は `analysis:recover-stale-jobs` cron が
   30 分で failJob するため、滞留 = 監視で気づける)
 - 時間 budget の連鎖 `job timeout (1,560s) < retry_after (1,680s) < 予約 TTL (1,800s) ≤ stale 閾値 (1,800s)`
@@ -272,7 +311,8 @@ ### レンダジョブの運用契約
 
 - レンダジョブ (`RunManualRender`) は専用 queue connection **`database-render`**
   (queue=render、retry_after=1680) で流れる。**本番/ステージングの worker プロセス定義・
-  デプロイ手順・監視対象に `php artisan queue:work database-render` を必須項目として登録する**
+  デプロイ手順・監視対象に `php artisan queue:work database-render --timeout=1620` を
+  必須項目として登録する** (`--timeout` は規則 1。§キューのリース期間とワーカー制限時間の規約)
   (専用 worker が居ないとジョブは滞留する。queued 滞留は `render:recover-stale-jobs` cron が
   **10 分** (queued 短 SLA。enqueue 時点で編集を止めるため) / running 滞留は **30 分** で
   failJob するため、滞留 = 監視で気づける)
@@ -580,8 +620,9 @@ ## 撮影 PWA (presigned アップロード + 容量 Quota) の運用契約
   org 合計 / bytes_pending = pending 未失効 + verifying 全件。カウンタキャッシュは持たない)
 - **media queue**: S3 オブジェクト削除 (`Jobs/Capture/DeleteTakeObjectsJob`) は専用 connection
   **`database-media`** (queue=media、retry_after=300) で流れる。**本番/ステージングの worker
-  プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media` を必須項目
-  として登録する** (専用 worker が居ないと削除ジョブは滞留する)
+  プロセス定義・デプロイ手順・監視対象に `php artisan queue:work database-media --timeout=240`
+  を必須項目として登録する** (専用 worker が居ないと削除ジョブは滞留する。`--timeout` は
+  規則 1。§キューのリース期間とワーカー制限時間の規約)
 - **孤児掃除 cron**: `capture:release-stale-upload-reservations` (10 分毎・onOneServer) が
   期限切れ pending / stale verifying (updated_at 15 分超過) を released 化して bytes_pending を
   解放し、PUT 済み未登録の S3 オブジェクトを削除する (`Capture/StaleUploadReservationSweeper`。
diff --git a/mprocs.yaml b/mprocs.yaml
index 327145d..534bc3a 100644
--- a/mprocs.yaml
+++ b/mprocs.yaml
@@ -8,18 +8,29 @@ procs:
   # Stripe webhook をローカル dev server に転送する (課金フェーズ導入後に有効化)。
   # stripe:
   #   shell: "stripe listen --forward-to localhost:8001/stripe/webhook"
+  # queue worker の --timeout は「その接続で有効なワーカー制限時間」であり、
+  # 必ずその接続の retry_after を下回らせる (規則 1。config/queue.php と
+  # tests/Architecture/QueueWorkerLeaseInvariantTest.php が対応)。
+  # ★ queue:listen では **ジョブ側の $timeout が効かない** (Listener は子
+  #   `queue:work --once` に --timeout を渡さず、Worker::runNextJob() は SIGALRM を
+  #   張らない)。ここの --timeout が dev における唯一の上限である。
+  # ★ 接続名は必ず明示する (既定接続に頼ると QUEUE_CONNECTION 次第で
+  #   どの retry_after と比較すべきか静的に決まらない)。
   queue:
-    shell: "php artisan queue:listen --tries=1 --timeout=0"
+    shell: "php artisan queue:listen database --tries=1 --timeout=540"
   # 専用 connection (analysis/render/media) は既定 database connection の default
   # キューを見る上の worker では拾われないため、docs/architecture.md の運用契約どおり
   # connection ごとに worker を分けて常駐させる (retry_after が connection 固有のため
   # 1 本にまとめない)。
   queue-analysis:
-    shell: "php artisan queue:listen database-analysis --tries=1 --timeout=0"
+    shell: "php artisan queue:listen database-analysis --tries=1 --timeout=1620"
   queue-render:
-    shell: "php artisan queue:listen database-render --tries=1 --timeout=0"
+    shell: "php artisan queue:listen database-render --tries=1 --timeout=1620"
   queue-media:
-    shell: "php artisan queue:listen database-media --tries=1 --timeout=0"
+    shell: "php artisan queue:listen database-media --tries=1 --timeout=240"
+  # pail はログ追尾クライアントであってキューワーカーではない。--timeout は
+  # 「追尾を何秒で打ち切るか」であり retry_after とは無関係
+  # (QueueWorkerLeaseInvariantTest の MPROCS_NON_WORKER_TIMEOUT_PROCS に理由付きで登録)。
   logs:
     shell: "php artisan pail --timeout=0"
   vite:
diff --git a/scripts/bug-hunt-shard.sh b/scripts/bug-hunt-shard.sh
index f080c42..02bec3d 100755
--- a/scripts/bug-hunt-shard.sh
+++ b/scripts/bug-hunt-shard.sh
@@ -709,6 +709,21 @@ cmd_keepdb_check() {
 #   一致させること (self-test [y] が PHP 実評価で drift を機械検出する。順序は不問 = sort 比較)。
 BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)
 
+# 接続ごとの listener timeout (規則 1: その接続の retry_after を必ず下回る)。
+# ★ 一律値にしない: retry_after は接続ごとに違う (1680 / 1680 / 300)。
+# ★ 旧実装は 3 接続すべてに一律 1800 を与えており、database-media (retry_after=300) では
+#   6 倍の違反だった (二重取得の窓)。
+# ★ queue:listen では Job 側 $timeout は効かない (Listener は子 `queue:work --once` へ
+#   --timeout を渡さず、Worker::runNextJob() は SIGALRM を張らない)。ここが唯一の上限。
+# ★ BUGHUNT_WORKER_CONNECTIONS の全要素が鍵を持つこと / 各値が retry_after 未満で
+#   あることは self-test [y3b] と tests/Architecture/QueueWorkerLeaseInvariantTest.php が
+#   二段で固定する。
+declare -A BUGHUNT_WORKER_TIMEOUTS=(
+    [database-analysis]=1620
+    [database-render]=1620
+    [database-media]=240
+)
+
 # worker pid が「当該 connection の queue:listen」として生きているかの検証 (kill -0 では
 # stale pidfile / pid 再利用を誤判定するため /proc cmdline を照合する。Linux 前提 = teardown と同じ)。
 # 照合は artisan / queue:listen / connection 名 / --env=bughunt.local を独立に確認する
@@ -733,16 +748,20 @@ worker_alive() {
 #   静かに死に F-01 が再発しうる)。
 # - setsid で専用 process group (pid==pgid) 化: teardown が process group 一括 kill で
 #   master と子を race なく停止するため。
-# - --tries=1 は Job 側の $tries=1 と整合。--timeout=1800 は listener が子を kill する天井で、
-#   Job 側の $timeout (1,380/1,500) が pcntl alarm で先に効く (予約 TTL 1,800 と同値)。
+# - --tries=1 は Job 側の $tries=1 と整合。--timeout は BUGHUNT_WORKER_TIMEOUTS の
+#   接続別の値 (規則 1: その接続の retry_after を必ず下回る)。listener が子を kill する
+#   天井であり、queue:listen では Job 側 $timeout が効かないためこれが唯一の上限になる。
 start_shard_workers() {
     local shard=$1 db=$2 url=$3
     guard_bughunt_runtime "${db}" bughunt
-    local conn pid
+    local conn pid wtimeout
     # 秘密 (LLM_KEY_ENV) を展開するプロセス起動を xtrace ガードで挟む (-x 有効時も値を trace に出さない)。
     # worker は serve と同一の env 隔離 + モードフラグ + 実キー (real-llm 時のみ) を注入する。
     secret_xtrace_off
     for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        wtimeout="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"
+        [[ -n "${wtimeout}" ]] \
+            || die 1 "BUGHUNT_WORKER_TIMEOUTS に ${conn} の値が無い (規則 1: listener timeout 未定義では起動しない)"
         env -i PATH="${PATH}" HOME="${HOME}" \
             DB_CONNECTION=pgsql \
             DB_HOST="$(env_file_required DB_HOST)" DB_PORT="$(env_file_required DB_PORT)" \
@@ -750,7 +769,7 @@ start_shard_workers() {
             APP_URL="${url}" \
             ${MODE_ENV[@]+"${MODE_ENV[@]}"} ${LLM_KEY_ENV[@]+"${LLM_KEY_ENV[@]}"} \
             setsid php artisan queue:listen "${conn}" --env=bughunt.local \
-                --sleep=1 --tries=1 --timeout=1800 \
+                --sleep=1 --tries=1 --timeout="${wtimeout}" \
             > "$(worker_logfile "${shard}" "${conn}")" 2>&1 &
         pid=$!
         echo "${pid}" > "$(worker_pidfile "${shard}" "${conn}")"
@@ -1742,6 +1761,40 @@ CURLEOF
     echo "$(declare -f cmd_keepdb_check)" | grep -q 'worker_alive' \
         || t_fail "cmd_keepdb_check に worker 生存確認が無い"
 
+    # (y3b) 接続別 listener timeout: 全 connection が鍵を持ち、値が retry_after 未満であること
+    #       (規則 1 の実行配線側。静的側は QueueWorkerLeaseInvariantTest が二段目)。
+    #       ★ 既存の (y4) 以降を renumber しないため 3b とした (wrk_def は (y3) で取得済み)。
+    local conn conn_rt wt
+    for conn in "${BUGHUNT_WORKER_CONNECTIONS[@]}"; do
+        wt="${BUGHUNT_WORKER_TIMEOUTS[${conn}]:-}"
+        if [[ -z "${wt}" ]]; then
+            t_fail "BUGHUNT_WORKER_TIMEOUTS に ${conn} の値が無い (規則 1 の検査対象から漏れる)"
+            continue
+        fi
+        # vendor/autoload.php を require する (config/queue.php が env() を呼ぶため。既存 (y2) と同じ)
+        conn_rt="$(cd "${WORKSPACE}" && php -r '
+            require "vendor/autoload.php";
+            $cfg = require "config/queue.php";
+            echo (int) ($cfg["connections"][$argv[1]]["retry_after"] ?? 0);
+        ' "${conn}" 2>/dev/null || echo "__php_failed__")"
+        # 算術比較の前に形式検査する (不正値で bash の算術評価エラーにしない)
+        if [[ "${conn_rt}" == "__php_failed__" ]]; then
+            t_fail "規則 1 検査 実行不能: config/queue.php を PHP 評価できない (composer install 後に再実行)"
+        elif [[ ! "${wt}" =~ ^[0-9]+$ ]]; then
+            t_fail "BUGHUNT_WORKER_TIMEOUTS[${conn}] が正の整数でない (${wt})"
+        elif [[ ! "${conn_rt}" =~ ^[0-9]+$ || "${conn_rt}" -le 0 ]]; then
+            t_fail "config の ${conn}.retry_after が正の整数でない (${conn_rt})"
+        elif [[ "${wt}" -le 0 || "${wt}" -ge "${conn_rt}" ]]; then
+            t_fail "規則 1 違反: ${conn} の listener timeout (${wt}) が retry_after (${conn_rt}) 以上"
+        fi
+    done
+    # 起動行が数値リテラル直書きへ戻っていないこと (配列経由の強制)
+    echo "${wrk_def}" | grep -q -- 'BUGHUNT_WORKER_TIMEOUTS' \
+        || t_fail "start_shard_workers が BUGHUNT_WORKER_TIMEOUTS 経由で --timeout を渡していない"
+    # `--timeout=1800` だけでなく `--timeout 1800` (空白区切り) も禁止する
+    echo "${wrk_def}" | grep -qE -- '--timeout(=|[[:space:]]+)[0-9]+' \
+        && t_fail "start_shard_workers に --timeout の数値直書きが復活している (接続別の値を潰す)"
+
     # (y4) worker_alive: stale pidfile (存在しない pid) と cmdline 不一致 (自プロセス pid) を fail 判定
     mkdir -p "${TMP_BASE}"
     echo 999999999 > "$(worker_pidfile 7 database-analysis)"
diff --git a/tests/Architecture/QueueWorkerLeaseInvariantTest.php b/tests/Architecture/QueueWorkerLeaseInvariantTest.php
new file mode 100644
index 0000000..ab82aad
--- /dev/null
+++ b/tests/Architecture/QueueWorkerLeaseInvariantTest.php
@@ -0,0 +1,423 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Support\Env;
+use Symfony\Component\Yaml\Yaml;
+use Tests\Support\QueueLeaseConfig;
+use Webmozart\Assert\Assert;
+
+/*
+ * 規則 1 (無条件): その接続で有効なワーカー / supervisor の `--timeout` は、
+ * その接続の `retry_after` を **下回る**。
+ *
+ * DB driver のキューには実行中にリース (retry_after) を延長する API が無いため、
+ * 「まだ走っている処理を落ちたと誤認させない」手段は設定の大小関係を保つことだけである。
+ * 1 つのワーカーは同じ接続の複数種類のジョブを処理するため、`$timeout` を宣言した
+ * ジョブが 1 本あってもワーカー側の制限時間は免除されない。
+ *
+ * ★ `queue:listen` 配下では **ジョブ側の `$timeout` がまったく効かない**
+ *   (`Listener::createCommand()` は子 `queue:work --once` へ `--timeout` を渡さず、
+ *    `Worker::runNextJob()` は SIGALRM を張らない)。dev (mprocs) / bug-hunt では
+ *   ここで検査する `--timeout` が唯一の上限である。
+ *
+ * 検査対象は「リポジトリ内にあるワーカー起動定義」= `mprocs.yaml` (dev) と
+ * `scripts/bug-hunt-shard.sh` (bug-hunt) の 2 面。本番 supervisor 定義はリポジトリに
+ * 実体が無いため本 gate では検知できない (正本は docs/architecture.md の値表)。
+ *
+ * 運用契約: docs/architecture.md §キューのリース期間とワーカー制限時間の規約
+ */
+
+/**
+ * ワーカー起動定義に `--timeout` を持つが **キューワーカーではない** proc。
+ *
+ * key = mprocs の proc 名 / value = なぜ規則 1 の対象外か。
+ * ★ 黙って除外しない。理由を書けないものをここに足さないこと。
+ *
+ * @var array<string, string>
+ */
+const MPROCS_NON_WORKER_TIMEOUT_PROCS = [
+    'logs' => 'php artisan pail はログ追尾クライアント。--timeout は追尾の打ち切り秒数であり、キューのリース (retry_after) とは無関係',
+];
+
+/**
+ * mprocs の proc 定義を「proc 名 => shell 文字列」へ正規化する (純関数)。
+ *
+ * @return array<string, string>
+ */
+function queueLeaseMprocsShellCommands(string $yamlPath): array
+{
+    $yaml = Yaml::parseFile($yamlPath);
+    Assert::isArray($yaml, 'mprocs.yaml が map ではありません');
+    Assert::keyExists($yaml, 'procs', 'mprocs.yaml に procs がありません');
+
+    $procs = $yaml['procs'];
+    Assert::isArray($procs, 'mprocs.yaml の procs が map ではありません');
+
+    $commands = [];
+    foreach ($procs as $name => $definition) {
+        Assert::string($name, 'mprocs.yaml の proc 名が文字列ではありません');
+        Assert::isArray($definition, "mprocs.yaml の proc {$name} が map ではありません");
+        Assert::keyExists($definition, 'shell', "mprocs.yaml の proc {$name} に shell がありません");
+
+        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
+        $shell = $definition['shell'];
+        Assert::string($shell, "mprocs.yaml の proc {$name} の shell が文字列ではありません");
+
+        $commands[$name] = $shell;
+    }
+
+    return $commands;
+}
+
+/**
+ * shell 文字列がキューワーカー起動かを判定し、接続名と --timeout を返す (純関数)。
+ *
+ * `queue:work` / `queue:listen` の **両方**をワーカーとして扱う
+ * (どちらでも --timeout は「その接続で有効な制限時間」= 規則 1 の対象)。
+ *
+ * 正規表現ではなくトークン分割で足りる根拠: mprocs の shell は引用符・変数展開・
+ * パイプを含まない単純な 1 コマンド行である。将来それが崩れたらケース 1/2 が
+ * 「接続名が取れない」で落ちるので、黙って通ることはない。
+ *
+ * @return array{connection: string|null, timeout: int|null}|null ワーカーでなければ null
+ */
+function queueLeaseParseWorkerCommand(string $shell): ?array
+{
+    $tokens = preg_split('/\s+/', trim($shell), -1, PREG_SPLIT_NO_EMPTY);
+    Assert::isArray($tokens);
+
+    if (! in_array('artisan', $tokens, true)) {
+        return null;
+    }
+
+    $subcommandIndex = null;
+    foreach ($tokens as $index => $token) {
+        if ($token === 'queue:work' || $token === 'queue:listen') {
+            $subcommandIndex = $index;
+            break;
+        }
+    }
+
+    if ($subcommandIndex === null) {
+        return null;
+    }
+
+    // サブコマンドの次のトークンがオプションでなければ接続名
+    $connection = null;
+    $next = $tokens[$subcommandIndex + 1] ?? null;
+    if (is_string($next) && ! str_starts_with($next, '-')) {
+        $connection = $next;
+    }
+
+    $timeout = null;
+    foreach ($tokens as $index => $token) {
+        $raw = null;
+        if (str_starts_with($token, '--timeout=')) {
+            $raw = substr($token, strlen('--timeout='));
+        } elseif ($token === '--timeout') {
+            $raw = $tokens[$index + 1] ?? null;
+        }
+
+        if ($raw === null) {
+            continue;
+        }
+
+        Assert::string($raw, "--timeout の値が取得できません: {$shell}");
+        Assert::regex($raw, '/\A\d+\z/', "--timeout の値が数値ではありません: {$shell}");
+        $timeout = (int) $raw;
+        break;
+    }
+
+    return ['connection' => $connection, 'timeout' => $timeout];
+}
+
+/**
+ * bash ソースから `declare -A BUGHUNT_WORKER_TIMEOUTS=( [conn]=N ... )` を抽出する (純関数)。
+ *
+ * @return array<string, int>
+ */
+function queueLeaseBughuntWorkerTimeouts(string $bashSource): array
+{
+    $matched = preg_match(
+        '/declare\s+-A\s+BUGHUNT_WORKER_TIMEOUTS=\((?<body>[^)]*)\)/',
+        $bashSource,
+        $block,
+    );
+    Assert::same($matched, 1, 'bug-hunt の接続別 timeout 定義 (declare -A BUGHUNT_WORKER_TIMEOUTS) が見つかりません (一律値へ戻された可能性があります)');
+
+    preg_match_all('/\[([A-Za-z0-9_-]+)\]=(\d+)/', $block['body'], $entries, PREG_SET_ORDER);
+    Assert::notEmpty($entries, 'BUGHUNT_WORKER_TIMEOUTS に entry がありません');
+
+    $timeouts = [];
+    foreach ($entries as $entry) {
+        $timeouts[$entry[1]] = (int) $entry[2];
+    }
+
+    return $timeouts;
+}
+
+/**
+ * bash ソースから `BUGHUNT_WORKER_CONNECTIONS=(a b c)` を抽出する (純関数)。
+ *
+ * @return list<string>
+ */
+function queueLeaseBughuntWorkerConnections(string $bashSource): array
+{
+    $matched = preg_match(
+        '/^BUGHUNT_WORKER_CONNECTIONS=\((?<body>[^)]*)\)/m',
+        $bashSource,
+        $block,
+    );
+    Assert::same($matched, 1, 'bug-hunt の BUGHUNT_WORKER_CONNECTIONS 定義が見つかりません');
+
+    $connections = preg_split('/\s+/', trim($block['body']), -1, PREG_SPLIT_NO_EMPTY);
+    Assert::isArray($connections);
+    Assert::allString($connections);
+    Assert::notEmpty($connections, 'BUGHUNT_WORKER_CONNECTIONS が空です');
+
+    return array_values($connections);
+}
+
+/**
+ * bash ソースから `名前() { ... }` の関数定義本体を切り出す (純関数)。
+ *
+ * ★ 波括弧カウントは使わない (`${...}` を関数ブロックと誤認する)。**行単位**で抽出する:
+ *   - 開始行: `/^{名前}\(\)[ \t]*\{$/` — 0 件または 2 件以上なら fail
+ *   - 終了行: 開始行より後で最初に現れる列頭 `/^\}$/` — 見つからなければ fail
+ *   対象スクリプトはこの整形規約に従っている。
+ */
+function queueLeaseExtractBashFunction(string $bashSource, string $functionName): string
+{
+    $lines = preg_split('/\R/u', $bashSource);
+    Assert::isArray($lines);
+
+    $startPattern = '/\A'.preg_quote($functionName, '/').'\(\)[ \t]*\{\z/';
+    $starts = [];
+    foreach ($lines as $index => $line) {
+        Assert::string($line);
+        if (preg_match($startPattern, $line) === 1) {
+            $starts[] = $index;
+        }
+    }
+
+    Assert::count($starts, 1, "bash 関数 {$functionName} の定義行がちょうど 1 つ見つかりません (整形規約から外れた可能性があります)");
+
+    $start = $starts[0];
+    $end = null;
+    for ($i = $start + 1, $count = count($lines); $i < $count; $i++) {
+        if ($lines[$i] === '}') {
+            $end = $i;
+            break;
+        }
+    }
+
+    Assert::integer($end, "bash 関数 {$functionName} の終端 (列頭の }) が見つかりません");
+
+    return implode("\n", array_slice($lines, $start, $end - $start + 1));
+}
+
+/** 行頭 (前置空白可) の `#` で始まる行を除去する (純関数)。 */
+function queueLeaseStripBashCommentLines(string $bash): string
+{
+    $lines = preg_split('/\R/u', $bash);
+    Assert::isArray($lines);
+
+    $kept = [];
+    foreach ($lines as $line) {
+        Assert::string($line);
+        if (preg_match('/\A[ \t]*#/', $line) === 1) {
+            continue;
+        }
+        $kept[] = $line;
+    }
+
+    return implode("\n", $kept);
+}
+
+/**
+ * mprocs.yaml のワーカー proc (proc 名 => 解析結果)。
+ *
+ * @return array<string, array{connection: string|null, timeout: int|null}>
+ */
+function queueLeaseMprocsWorkers(): array
+{
+    $workers = [];
+    foreach (queueLeaseMprocsShellCommands(base_path('mprocs.yaml')) as $name => $shell) {
+        $parsed = queueLeaseParseWorkerCommand($shell);
+        if ($parsed !== null) {
+            $workers[$name] = $parsed;
+        }
+    }
+
+    return $workers;
+}
+
+/** scripts/bug-hunt-shard.sh の中身。 */
+function queueLeaseBughuntSource(): string
+{
+    $source = file_get_contents(base_path('scripts/bug-hunt-shard.sh'));
+    Assert::string($source, 'scripts/bug-hunt-shard.sh を読み込めません');
+
+    return $source;
+}
+
+test('規則 1: mprocs のキューワーカーは接続名を明示する', function (): void {
+    $workers = queueLeaseMprocsWorkers();
+    expect($workers)->not->toBeEmpty();
+
+    foreach ($workers as $name => $worker) {
+        expect($worker['connection'])->not->toBeNull(
+            "規則 1: mprocs の proc {$name} が接続名を明示していない。既定接続に頼ると QUEUE_CONNECTION 次第で"
+            .'どの retry_after と比較すべきかが静的に決まらないため、接続名を必ず書くこと'
+            .' (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)',
+        );
+    }
+});
+
+test('規則 1: mprocs のキューワーカーは --timeout を明示し retry_after を下回る', function (): void {
+    $retryAfters = QueueLeaseConfig::databaseConnections();
+
+    foreach (queueLeaseMprocsWorkers() as $name => $worker) {
+        $connection = $worker['connection'];
+        expect($connection)->not->toBeNull("規則 1: mprocs の proc {$name} が接続名を明示していない");
+        Assert::string($connection);
+        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
+            "規則 1: mprocs の proc {$name} の接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
+        );
+
+        $timeout = $worker['timeout'];
+        expect($timeout)->not->toBeNull(
+            "規則 1: mprocs の proc {$name} に --timeout がない。queue:listen ではジョブ側 \$timeout が効かないため、"
+            .'ここが唯一の上限である',
+        );
+        Assert::integer($timeout);
+
+        expect($timeout)->toBeGreaterThan(
+            0,
+            "規則 1: mprocs の proc {$name} の --timeout が 0 (無制限)。retry_after を必ず下回る有限値にすること",
+        );
+        expect($timeout)->toBeLessThan(
+            $retryAfters[$connection],
+            "規則 1: mprocs の proc {$name} の --timeout ({$timeout}) が接続 {$connection} の retry_after"
+            ." ({$retryAfters[$connection]}) 以上。二重取得の窓が開く",
+        );
+    }
+});
+
+test('規則 1: mprocs のワーカーは driver=database の全接続を覆う', function (): void {
+    $covered = [];
+    foreach (queueLeaseMprocsWorkers() as $worker) {
+        if (is_string($worker['connection'])) {
+            $covered[] = $worker['connection'];
+        }
+    }
+    sort($covered);
+
+    $expected = array_keys(QueueLeaseConfig::databaseConnections());
+    sort($expected);
+
+    expect($covered)->toBe(
+        $expected,
+        '規則 1: driver=database の接続は dev ワーカーペイン (mprocs.yaml) を必ず持つ。'
+        .'接続だけ増やしてワーカーを足し忘れるとジョブが黙って滞留する'
+        .' (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)',
+    );
+});
+
+test('規則 1: --timeout を持つ非ワーカー proc は理由付きで除外登録されている', function (): void {
+    $nonWorkers = [];
+    foreach (queueLeaseMprocsShellCommands(base_path('mprocs.yaml')) as $name => $shell) {
+        if (! str_contains($shell, '--timeout')) {
+            continue;
+        }
+        if (queueLeaseParseWorkerCommand($shell) !== null) {
+            continue;
+        }
+        $nonWorkers[] = $name;
+    }
+    sort($nonWorkers);
+
+    $registered = array_keys(MPROCS_NON_WORKER_TIMEOUT_PROCS);
+    sort($registered);
+
+    expect($nonWorkers)->toBe(
+        $registered,
+        '規則 1: --timeout を持つ非ワーカー proc は MPROCS_NON_WORKER_TIMEOUT_PROCS へ理由付きで登録すること (黙って除外しない)',
+    );
+
+    foreach (MPROCS_NON_WORKER_TIMEOUT_PROCS as $name => $reason) {
+        expect(mb_strlen($reason))->toBeGreaterThanOrEqual(
+            20,
+            "規則 1: {$name} の除外理由が短すぎる (なぜ retry_after と無関係かを書くこと)",
+        );
+    }
+});
+
+test('規則 1: bug-hunt の listener timeout は接続ごとに retry_after を下回る', function (): void {
+    $source = queueLeaseBughuntSource();
+    $retryAfters = QueueLeaseConfig::databaseConnections();
+    $timeouts = queueLeaseBughuntWorkerTimeouts($source);
+
+    foreach (queueLeaseBughuntWorkerConnections($source) as $connection) {
+        expect(array_key_exists($connection, $timeouts))->toBeTrue(
+            "規則 1: BUGHUNT_WORKER_TIMEOUTS に {$connection} の値がない (listener timeout 未定義では規則 1 の検査対象から漏れる)",
+        );
+    }
+
+    foreach ($timeouts as $connection => $timeout) {
+        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
+            "規則 1: BUGHUNT_WORKER_TIMEOUTS の {$connection} が config/queue.php の driver=database 接続に存在しない",
+        );
+        expect($timeout)->toBeGreaterThan(0, "規則 1: BUGHUNT_WORKER_TIMEOUTS[{$connection}] が正の整数でない");
+        expect($timeout)->toBeLessThan(
+            $retryAfters[$connection],
+            "規則 1: bug-hunt の listener timeout ({$timeout}) が接続 {$connection} の retry_after"
+            ." ({$retryAfters[$connection]}) 以上。二重取得の窓が開く",
+        );
+    }
+});
+
+test('規則 1: bug-hunt の起動行は --timeout を配列経由で渡す', function (): void {
+    // ★ 検査範囲は start_shard_workers の関数本体から **コメント行を除いたもの**に限定する。
+    //   全文を対象にすると、説明コメント中の旧値 (`--timeout=1800`) を自分で拾って必ず失敗する。
+    //   self-test 側が `declare -f start_shard_workers` を対象にしているのと範囲を揃えている。
+    $body = queueLeaseStripBashCommentLines(
+        queueLeaseExtractBashFunction(queueLeaseBughuntSource(), 'start_shard_workers'),
+    );
+
+    expect(str_contains($body, '--timeout="${wtimeout}"'))->toBeTrue(
+        '規則 1: start_shard_workers が BUGHUNT_WORKER_TIMEOUTS 由来の値で --timeout を渡していない',
+    );
+    expect(preg_match('/--timeout(?:=|\s+)\d+/', $body))->toBe(
+        0,
+        '規則 1: start_shard_workers に --timeout の数値直書きが復活している (接続別の値を潰し、'
+        .'retry_after が短い接続 (database-media) で二重取得の窓を開く)',
+    );
+});
+
+test('規則 1: database の retry_after は env で上書きできない', function (): void {
+    // 静的 gate は config をテスト環境の値で読むため、env 上書きが残ると
+    // 「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
+    $repository = Env::getRepository();
+    $hadOriginal = $repository->has('DB_QUEUE_RETRY_AFTER');
+    $original = $hadOriginal ? $repository->get('DB_QUEUE_RETRY_AFTER') : null;
+
+    $repository->set('DB_QUEUE_RETRY_AFTER', '1');
+
+    try {
+        $connections = QueueLeaseConfig::databaseConnections();
+        expect($connections)->toHaveKey('database');
+        expect($connections['database'])->toBe(
+            600,
+            '規則 1: config/queue.php の database.retry_after が env で上書きされた。'
+            .'env('."'DB_QUEUE_RETRY_AFTER'".') ではなくリテラル 600 で持つこと',
+        );
+    } finally {
+        if ($hadOriginal && is_string($original)) {
+            $repository->set('DB_QUEUE_RETRY_AFTER', $original);
+        } else {
+            $repository->clear('DB_QUEUE_RETRY_AFTER');
+        }
+    }
+});
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
new file mode 100644
index 0000000..bc715d4
--- /dev/null
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -0,0 +1,592 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Jobs\Billing\HandleAutoRechargeChargeFailureJob;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Jobs\Manual\DeleteRenderOutputsJob;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Mail\InquiryAcknowledgementMail;
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Billing\AutoRechargeActionRequiredNotification;
+use App\Notifications\Billing\AutoRechargeDisabledNotification;
+use App\Notifications\Billing\AutoRechargeEnabledNotification;
+use App\Notifications\Billing\AutoRechargeFailedNotification;
+use App\Notifications\Billing\PaymentFailedNotification;
+use App\Notifications\Billing\RenewalReminderNotification;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Tests\Support\QueueLeaseConfig;
+use Webmozart\Assert\Assert;
+
+/*
+ * 規則 2 + 接続経路の網羅を deny-by-default で固定する。
+ *
+ * - **規則 2**: その接続で動くジョブの明示的な `$timeout` が、その接続の `retry_after` を下回る。
+ * - **接続経路**: キューに載る (ShouldQueue を実装する) クラスは全数を目録に登録し、
+ *   接続の指定は `$this->onConnection('リテラル')` に限る。動的に決まる接続は
+ *   静的に retry_after と比較できず、規則 2 の検査そのものが空洞化するため。
+ *
+ * ★ `Queue::connection(...)` は検出対象に入れない。`connection` は汎用名で Eloquent の
+ *   `->connection()` 等と衝突して偽陽性が大量に出る。ジョブの接続を実際に差し替えられる
+ *   経路は `onConnection` / `viaConnections` / `$connection` プロパティの 3 つであり、
+ *   `Queue::connection()->push()` は「どのキューへ push するか」であってジョブクラスの
+ *   契約ではない (かつ本アプリに 1 件も無い)。
+ *
+ * 運用契約: docs/architecture.md §キューのリース期間とワーカー制限時間の規約
+ */
+
+/**
+ * キューに載る全クラス (ShouldQueue 実装) の接続目録。
+ *
+ * value = `$this->onConnection('...')` で pin した接続名 / null = 既定接続。
+ *
+ * ★ deny-by-default: app/ の走査結果とこの目録の**対称差が空**であること。
+ *   新しい Job / Mailable / Notification を足したら必ずここに登録する。
+ * ★ null (既定接続) の entry は `$timeout` の宣言を禁止する
+ *   (既定接続は QUEUE_CONNECTION 次第でどの接続にも化けるため、静的に retry_after と
+ *    比較できない。`$timeout` が要るなら `onConnection()` で接続を pin する)。
+ *
+ * @var array<class-string, string|null>
+ */
+const QUEUED_JOB_LEASE_INVENTORY = [
+    AutoRechargeTriggerJob::class => null,
+    ExecuteAutoRechargeAttemptJob::class => null,
+    HandleAutoRechargeChargeFailureJob::class => null,
+    ReuseSubscriptionPaymentMethodJob::class => null,
+    SetDefaultPaymentMethodJob::class => null,
+    SyncBillingCustomerDetails::class => null,
+    DeleteTakeObjectsJob::class => 'database-media',
+    DeleteRenderOutputsJob::class => 'database-media',
+    RunManualAnalysis::class => 'database-analysis',
+    RunManualRender::class => 'database-render',
+    InquiryAcknowledgementMail::class => null,
+    InquiryReceivedMail::class => null,
+    AutoRechargeActionRequiredNotification::class => null,
+    AutoRechargeDisabledNotification::class => null,
+    AutoRechargeEnabledNotification::class => null,
+    AutoRechargeFailedNotification::class => null,
+    PaymentFailedNotification::class => null,
+    RenewalReminderNotification::class => null,
+];
+
+/**
+ * app/ 配下の ShouldQueue 実装クラスを列挙する (純関数)。
+ *
+ * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
+ * `isInstantiable()`。親クラス / trait 経由の実装も拾えるため、Job だけでなく
+ * Mailable / Notification も自動的に母集団へ入る。
+ *
+ * @return list<class-string>
+ */
+function jobLeaseShouldQueueClasses(): array
+{
+    $classes = [];
+    foreach (jobLeaseAppPhpFiles() as $path) {
+        $class = jobLeaseClassNameForPath($path);
+        if (! class_exists($class)) {
+            continue;
+        }
+
+        $reflection = new ReflectionClass($class);
+        if (! $reflection->isInstantiable()) {
+            continue;
+        }
+        if (! $reflection->implementsInterface(ShouldQueue::class)) {
+            continue;
+        }
+
+        $classes[] = $reflection->getName();
+    }
+
+    sort($classes);
+
+    return $classes;
+}
+
+/**
+ * app/ 配下の PHP ファイル絶対パス一覧 (純関数)。
+ *
+ * @return list<string>
+ */
+function jobLeaseAppPhpFiles(): array
+{
+    $appPath = base_path('app');
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($appPath, FilesystemIterator::SKIP_DOTS),
+    );
+
+    $paths = [];
+    foreach ($iterator as $file) {
+        Assert::isInstanceOf($file, SplFileInfo::class);
+        if (! $file->isFile() || $file->getExtension() !== 'php') {
+            continue;
+        }
+        $paths[] = $file->getPathname();
+    }
+
+    sort($paths);
+
+    return $paths;
+}
+
+/** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
+function jobLeaseClassNameForPath(string $path): string
+{
+    $appPath = base_path('app').DIRECTORY_SEPARATOR;
+    Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");
+
+    $relative = substr($path, strlen($appPath), -strlen('.php'));
+
+    return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
+}
+
+/**
+ * PHP ソースをトークン解析し、接続 / timeout の決定に関わる site をすべて列挙する (純関数)。
+ *
+ * 検出対象:
+ *   - `->onConnection(...)` / `?->onConnection(...)` / `::onConnection(...)`
+ *   - `->viaConnections(...)` / `->viaConnection(...)`
+ *   - **クラス直下**の `$connection` / `$timeout` プロパティ宣言 (デフォルト値の有無つき)
+ *   - `$this->connection = ...` / `$this->timeout = ...` 代入
+ *
+ * @return list<array{
+ *     class: string|null,
+ *     kind: 'onConnection'|'viaConnections'|'viaConnection'|'connectionProperty'|'connectionAssignment'|'timeoutProperty'|'timeoutAssignment',
+ *     receiverIsThis: bool,
+ *     literal: string|null,
+ *     hasDefault: bool,
+ *     line: int,
+ * }>
+ */
+function jobLeaseConnectionDeclarationSites(string $phpSource): array
+{
+    $tokens = jobLeaseNormalizedTokens($phpSource);
+    $count = count($tokens);
+
+    $namespace = '';
+    $braceDepth = 0;
+    $parenDepth = 0;
+    /** @var list<array{class: string|null, bodyDepth: int}> $scopes */
+    $scopes = [];
+    /** @var array{class: string|null}|null $pendingScope */
+    $pendingScope = null;
+    $sites = [];
+
+    for ($i = 0; $i < $count; $i++) {
+        $token = $tokens[$i];
+        $id = $token['id'];
+        $text = $token['text'];
+
+        // namespace 宣言
+        if ($id === T_NAMESPACE) {
+            $next = $tokens[$i + 1] ?? null;
+            if ($next !== null && ($next['id'] === T_NAME_QUALIFIED || $next['id'] === T_STRING)) {
+                $namespace = $next['text'];
+            }
+
+            continue;
+        }
+
+        // クラス様宣言 (class / trait / interface / enum)。次に現れる `{` で scope を push する
+        if ($id === T_CLASS || $id === T_TRAIT || $id === T_INTERFACE || $id === T_ENUM) {
+            $previous = $tokens[$i - 1] ?? null;
+            if ($previous !== null && $previous['id'] === T_DOUBLE_COLON) {
+                continue; // `Foo::class`
+            }
+
+            $next = $tokens[$i + 1] ?? null;
+            $isNamedClass = $id === T_CLASS && $next !== null && $next['id'] === T_STRING;
+            // 匿名クラス / trait / interface / enum は class = null (内部の site を
+            // 外側のクラスへ誤帰属させない)
+            $pendingScope = [
+                'class' => $isNamedClass && $next !== null
+                    ? ($namespace === '' ? $next['text'] : $namespace.'\\'.$next['text'])
+                    : null,
+            ];
+
+            continue;
+        }
+
+        // 深さ追跡。文字列補間の `{$…}` / `${…}` も対応する `}` を持つため開きとして数える
+        if ($text === '{' || $id === T_CURLY_OPEN || $id === T_DOLLAR_OPEN_CURLY_BRACES) {
+            $braceDepth++;
+            if ($pendingScope !== null && $text === '{' && $parenDepth === 0) {
+                $scopes[] = ['class' => $pendingScope['class'], 'bodyDepth' => $braceDepth];
+                $pendingScope = null;
+            }
+
+            continue;
+        }
+
+        if ($text === '}') {
+            // ★ pop の順序を固定する: 先に braceDepth-- するとメソッド終端の `}` で誤って pop する
+            $top = $scopes === [] ? null : $scopes[count($scopes) - 1];
+            if ($top !== null && $top['bodyDepth'] === $braceDepth) {
+                array_pop($scopes);
+            }
+            $braceDepth--;
+
+            continue;
+        }
+
+        if ($text === '(') {
+            $parenDepth++;
+
+            continue;
+        }
+
+        if ($text === ')') {
+            $parenDepth--;
+
+            continue;
+        }
+
+        $currentClass = $scopes === [] ? null : $scopes[count($scopes) - 1]['class'];
+        $currentBodyDepth = $scopes === [] ? null : $scopes[count($scopes) - 1]['bodyDepth'];
+
+        // メソッド呼び出し (onConnection / viaConnections / viaConnection)
+        if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR || $id === T_DOUBLE_COLON) {
+            $name = $tokens[$i + 1] ?? null;
+            if ($name === null || $name['id'] !== T_STRING) {
+                continue;
+            }
+
+            $previous = $tokens[$i - 1] ?? null;
+            $receiverIsThis = $id === T_OBJECT_OPERATOR
+                && $previous !== null
+                && $previous['id'] === T_VARIABLE
+                && $previous['text'] === '$this';
+
+            if (in_array($name['text'], ['onConnection', 'viaConnections', 'viaConnection'], true)) {
+                $sites[] = [
+                    'class' => $currentClass,
+                    'kind' => $name['text'] === 'onConnection' ? 'onConnection' : $name['text'],
+                    'receiverIsThis' => $receiverIsThis,
+                    'literal' => jobLeaseSingleStringArgument($tokens, $i + 1),
+                    'hasDefault' => false,
+                    'line' => $name['line'],
+                ];
+
+                continue;
+            }
+
+            // $this->connection = / $this->timeout = 代入
+            if (in_array($name['text'], ['connection', 'timeout'], true) && $receiverIsThis) {
+                $assign = $tokens[$i + 2] ?? null;
+                if ($assign !== null && $assign['text'] === '=') {
+                    $sites[] = [
+                        'class' => $currentClass,
+                        'kind' => $name['text'] === 'connection' ? 'connectionAssignment' : 'timeoutAssignment',
+                        'receiverIsThis' => true,
+                        'literal' => null,
+                        'hasDefault' => false,
+                        'line' => $name['line'],
+                    ];
+                }
+            }
+
+            continue;
+        }
+
+        // クラス直下のプロパティ宣言
+        if ($id === T_VARIABLE && in_array($text, ['$connection', '$timeout'], true)) {
+            if ($currentBodyDepth !== $braceDepth || $parenDepth !== 0) {
+                continue; // メソッド本体のローカル変数 / 引数
+            }
+
+            $next = $tokens[$i + 1] ?? null;
+            $sites[] = [
+                'class' => $currentClass,
+                'kind' => $text === '$connection' ? 'connectionProperty' : 'timeoutProperty',
+                'receiverIsThis' => false,
+                'literal' => null,
+                'hasDefault' => $next !== null && $next['text'] === '=',
+                'line' => $token['line'],
+            ];
+        }
+    }
+
+    return $sites;
+}
+
+/**
+ * `token_get_all()` を「空白・コメントを除いた添字連番のリスト」へ正規化する (純関数)。
+ *
+ * @return list<array{id: int|null, text: string, line: int}>
+ */
+function jobLeaseNormalizedTokens(string $phpSource): array
+{
+    $normalized = [];
+    foreach (token_get_all($phpSource) as $token) {
+        if (is_array($token)) {
+            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
+                continue;
+            }
+            $normalized[] = ['id' => $token[0], 'text' => $token[1], 'line' => $token[2]];
+
+            continue;
+        }
+
+        $line = $normalized === [] ? 0 : $normalized[count($normalized) - 1]['line'];
+        $normalized[] = ['id' => null, 'text' => $token, 'line' => $line];
+    }
+
+    return $normalized;
+}
+
+/**
+ * メソッド名トークンの直後が `('文字列')` のときだけリテラルを返す (純関数)。
+ *
+ * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+ * @param  int  $nameIndex  メソッド名トークンの添字
+ */
+function jobLeaseSingleStringArgument(array $tokens, int $nameIndex): ?string
+{
+    $open = $tokens[$nameIndex + 1] ?? null;
+    $argument = $tokens[$nameIndex + 2] ?? null;
+    $close = $tokens[$nameIndex + 3] ?? null;
+
+    if ($open === null || $open['text'] !== '(') {
+        return null;
+    }
+    if ($argument === null || $argument['id'] !== T_CONSTANT_ENCAPSED_STRING) {
+        return null;
+    }
+    if ($close === null || $close['text'] !== ')') {
+        return null;
+    }
+
+    return trim($argument['text'], "'\"");
+}
+
+/**
+ * ReflectionClass の default properties から `$timeout` を int|null へ正規化する (純関数)。
+ *
+ * - `array_key_exists('timeout', $defaults)` が false のときだけ null を返す (未宣言 = 正常)
+ * - 宣言されている値が null / 非 int / 0 以下 → fail
+ *   (明示 `public ?int $timeout = null` を未宣言と同一視すると規則 2 を素通りする)
+ *
+ * @param  ReflectionClass<object>  $class
+ */
+function jobLeaseDeclaredJobTimeout(ReflectionClass $class): ?int
+{
+    $defaults = $class->getDefaultProperties();
+    if (! array_key_exists('timeout', $defaults)) {
+        return null;
+    }
+
+    $timeout = $defaults['timeout'];
+    Assert::integer($timeout, "規則 2: {$class->getName()} の \$timeout は正の int デフォルト値を持つプロパティ宣言に限る (実行時に決まる \$timeout は静的検査できない)");
+    Assert::greaterThan($timeout, 0, "規則 2: {$class->getName()} の \$timeout が正の整数ではない");
+
+    return $timeout;
+}
+
+/**
+ * app/ 全体の site を「ファイル絶対パス => site 一覧」で返す。
+ *
+ * @return array<string, list<array{class: string|null, kind: string, receiverIsThis: bool, literal: string|null, hasDefault: bool, line: int}>>
+ */
+function jobLeaseAllSites(): array
+{
+    $all = [];
+    foreach (jobLeaseAppPhpFiles() as $path) {
+        $source = file_get_contents($path);
+        Assert::string($source, "ファイルを読み込めません: {$path}");
+
+        $sites = jobLeaseConnectionDeclarationSites($source);
+        if ($sites !== []) {
+            $all[$path] = $sites;
+        }
+    }
+
+    return $all;
+}
+
+/** base_path() からの相対パス表示 (失敗メッセージ用)。 */
+function jobLeaseRelativePath(string $path): string
+{
+    $base = base_path().DIRECTORY_SEPARATOR;
+
+    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
+}
+
+test('接続経路: キューに載る全クラスが目録に登録されている', function (): void {
+    $scanned = jobLeaseShouldQueueClasses();
+    $registered = array_keys(QUEUED_JOB_LEASE_INVENTORY);
+    sort($registered);
+
+    $missing = array_values(array_diff($scanned, $registered));
+    $stale = array_values(array_diff($registered, $scanned));
+
+    expect($missing)->toBe(
+        [],
+        '接続経路: 目録 (QUEUED_JOB_LEASE_INVENTORY) 未登録の ShouldQueue 実装がある: '
+        .implode(', ', $missing),
+    );
+    expect($stale)->toBe(
+        [],
+        '接続経路: 目録に実在しないクラスが残っている: '.implode(', ', $stale),
+    );
+});
+
+test('接続経路: Job / Mailable / Notification の 3 系統が母集団に入っている', function (): void {
+    $scanned = jobLeaseShouldQueueClasses();
+
+    // 母集団判定が Job ディレクトリだけに縮んでいないことの behavioral 固定
+    expect($scanned)->toContain(RunManualAnalysis::class);
+    expect($scanned)->toContain(InquiryReceivedMail::class);
+    expect($scanned)->toContain(PaymentFailedNotification::class);
+});
+
+test("接続経路: 接続の指定は \$this->onConnection('リテラル') に限る", function (): void {
+    $connectionKinds = ['onConnection', 'viaConnections', 'viaConnection', 'connectionProperty', 'connectionAssignment'];
+    $violations = [];
+
+    foreach (jobLeaseAllSites() as $path => $sites) {
+        foreach ($sites as $site) {
+            if (! in_array($site['kind'], $connectionKinds, true)) {
+                continue; // $timeout 関連は規則 2 のケースが担当する
+            }
+
+            $allowed = $site['class'] !== null
+                && array_key_exists($site['class'], QUEUED_JOB_LEASE_INVENTORY)
+                && $site['kind'] === 'onConnection'
+                && $site['receiverIsThis']
+                && $site['literal'] !== null;
+
+            if (! $allowed) {
+                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' ('.$site['kind'].')';
+            }
+        }
+    }
+
+    expect($violations)->toBe(
+        [],
+        "接続経路: 接続の指定は目録登録済みクラス内の \$this->onConnection('リテラル') に限る。"
+        .'動的に決まる接続は静的検査できない (規則 2 の検査が空洞化する)。'
+        ."ジョブ側で \$this->onConnection('リテラル') に寄せるか、実行時 fail-fast の対象として個別に扱うこと: "
+        .implode(', ', $violations),
+    );
+});
+
+test('接続経路: 目録の接続宣言がソースと一致する', function (): void {
+    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $expectedConnection) {
+        $reflection = new ReflectionClass($class);
+        $file = $reflection->getFileName();
+        Assert::string($file, "{$class} のファイルパスを取得できません");
+
+        $source = file_get_contents($file);
+        Assert::string($source, "{$class} のソースを読み込めません");
+
+        $literals = [];
+        foreach (jobLeaseConnectionDeclarationSites($source) as $site) {
+            if ($site['class'] === $class && $site['kind'] === 'onConnection') {
+                $literals[] = $site['literal'];
+            }
+        }
+
+        expect(count($literals))->toBeLessThanOrEqual(
+            1,
+            "接続経路: {$class} が onConnection() を複数回呼んでいる (どちらが効くか読めない)",
+        );
+
+        if ($literals === []) {
+            expect($expectedConnection)->toBeNull(
+                "接続経路: 目録は {$class} を接続 {$expectedConnection} と記録しているが、ソースに onConnection() が無い",
+            );
+
+            continue;
+        }
+
+        expect($literals[0])->toBe(
+            $expectedConnection,
+            "接続経路: {$class} の onConnection() リテラルが目録と一致しない",
+        );
+    }
+});
+
+test('規則 2: キューに載るクラスの $timeout は正の int デフォルト値を持つプロパティ宣言に限る', function (): void {
+    $violations = [];
+
+    foreach (jobLeaseAllSites() as $path => $sites) {
+        foreach ($sites as $site) {
+            if ($site['class'] === null || ! array_key_exists($site['class'], QUEUED_JOB_LEASE_INVENTORY)) {
+                continue; // キューと無関係なクラスの $timeout は本不変条件の対象外
+            }
+
+            if ($site['kind'] === 'timeoutAssignment') {
+                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' ($this->timeout への代入)';
+
+                continue;
+            }
+
+            if ($site['kind'] === 'timeoutProperty' && ! $site['hasDefault']) {
+                $violations[] = jobLeaseRelativePath($path).':'.$site['line'].' (デフォルト値なしの $timeout 宣言)';
+            }
+        }
+    }
+
+    expect($violations)->toBe(
+        [],
+        '規則 2: 実行時に決まる $timeout は静的検査できない。正の int デフォルト値を持つプロパティ宣言に限ること: '
+        .implode(', ', $violations),
+    );
+});
+
+test('規則 2: 接続を pin したジョブの $timeout は retry_after を下回る', function (): void {
+    $retryAfters = QueueLeaseConfig::databaseConnections();
+
+    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
+        if ($connection === null) {
+            continue;
+        }
+
+        $timeout = jobLeaseDeclaredJobTimeout(new ReflectionClass($class));
+        if ($timeout === null) {
+            continue;
+        }
+
+        expect(array_key_exists($connection, $retryAfters))->toBeTrue(
+            "規則 2: {$class} が pin した接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
+        );
+        expect($timeout)->toBeLessThan(
+            $retryAfters[$connection],
+            "規則 2: {$class} の \$timeout ({$timeout}) が接続 {$connection} の retry_after"
+            ." ({$retryAfters[$connection]}) 以上",
+        );
+    }
+});
+
+test('規則 2: 既定接続のジョブは $timeout を宣言しない', function (): void {
+    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
+        if ($connection !== null) {
+            continue;
+        }
+
+        expect(jobLeaseDeclaredJobTimeout(new ReflectionClass($class)))->toBeNull(
+            "規則 2: {$class} は既定接続だが \$timeout を宣言している。既定接続は QUEUE_CONNECTION 次第で"
+            .'接続が変わるため静的に retry_after と比較できない。$this->onConnection() で接続を pin すること',
+        );
+    }
+});
+
+test('規則 2: 目録の接続名が config/queue.php に実在する', function (): void {
+    $connections = QueueLeaseConfig::databaseConnections();
+
+    foreach (QUEUED_JOB_LEASE_INVENTORY as $class => $connection) {
+        if ($connection === null) {
+            continue;
+        }
+
+        expect(array_key_exists($connection, $connections))->toBeTrue(
+            "規則 2: {$class} が pin した接続 {$connection} が config/queue.php の driver=database 接続に存在しない",
+        );
+    }
+});
diff --git a/tests/Feature/Queue/WorkerTimeoutTransitionTest.php b/tests/Feature/Queue/WorkerTimeoutTransitionTest.php
new file mode 100644
index 0000000..8f9ee82
--- /dev/null
+++ b/tests/Feature/Queue/WorkerTimeoutTransitionTest.php
@@ -0,0 +1,88 @@
+<?php
+
+declare(strict_types=1);
+
+use Illuminate\Contracts\Queue\Job as QueueJobContract;
+use Illuminate\Queue\Events\JobFailed;
+use Illuminate\Queue\Worker;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
+use Illuminate\Support\Facades\Queue;
+use Tests\Support\Queue\TriesOnceProbeJob;
+use Tests\Support\Queue\TriesThriceProbeJob;
+use Webmozart\Assert\Assert;
+
+/*
+ * ワーカー制限時間 (--timeout) に到達したとき何が起きるかを behavioral に固定する。
+ * 「規則 1 が守る窓」(= 予約が残ったまま処理が消えている時間帯) が実在することをコードで示す。
+ *
+ * 経路 A (`queue:work`): SIGALRM ハンドラ (Worker::registerTimeoutHandler) が
+ *   `markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。本テストはこの 1 メソッドを
+ *   ReflectionMethod で直接叩く (実プロセス・実 SIGALRM・実時間経過を使わない)。
+ *
+ * 経路 B (`queue:listen`) は自動テストにしない (実プロセス起動と最短でも --timeout 秒の
+ * 実時間経過が要り、グローバルテストロック配下のテストレーンを数分占有するため)。
+ * 代わりに vendor 実読の結果をここに固定する:
+ *
+ *   Listener::createCommand() は子へ --timeout を渡さない
+ *   → WorkCommand の --once は Worker::runNextJob() を呼び、runNextJob() は SIGALRM を張らない
+ *   → queue:listen 配下では Job 側 $timeout が効かず、親 Symfony Process の timeout が唯一の上限
+ *   → 到達時は markJobAsFailedIfWillExceedMaxAttempts を通らず、予約が残ったまま子が kill され、
+ *     ProcessTimedOutException が Listener::listen() を抜けて listener 本体も終了する
+ *
+ * この前提が変わると規則 1 の重要度そのものが変わるため、**Laravel のメジャー更新時は
+ * ここを再確認する** (docs/architecture.md §キューのリース期間とワーカー制限時間の規約)。
+ */
+
+/**
+ * ジョブを database 接続へ push して 1 件 pop し、SIGALRM ハンドラと同じ経路を叩く。
+ *
+ * テスト env は QUEUE_CONNECTION=sync のため接続名を必ず明示する。
+ *
+ * 戻り値は「失敗として確定したか」= `JobFailed` イベントの発火有無。
+ * ★ `failed_jobs` テーブルへの記録そのものは Worker ではなく **`queue:work` コマンド側**の
+ *   `JobFailed` リスナ (`WorkCommand::logFailedJob()`) が行うため、Worker 層だけを叩く
+ *   本テストでは観測できない。失敗確定の分岐点はこのイベントであり、ここを固定すれば
+ *   「timeout 到達で failed になるか / 予約が残るか」の遷移は behavioral に固定できる。
+ */
+function workerTimeoutProbe(object $job, int $maxTries): bool
+{
+    $failed = false;
+    Event::listen(JobFailed::class, function () use (&$failed): void {
+        $failed = true;
+    });
+
+    Queue::connection('database')->push($job);
+
+    $popped = Queue::connection('database')->pop();
+    Assert::isInstanceOf($popped, QueueJobContract::class, 'database 接続から予約済みジョブを取得できませんでした');
+
+    $worker = app('queue.worker');
+    Assert::isInstanceOf($worker, Worker::class);
+
+    // SIGALRM ハンドラ (Worker::registerTimeoutHandler) が呼ぶのと同じ protected メソッド。
+    // $maxTries は「CLI --tries とジョブ $tries の合成後の値」を直接渡す
+    // (合成ロジック自体は Laravel の責務なのでテストしない)。
+    $method = new ReflectionMethod(Worker::class, 'markJobAsFailedIfWillExceedMaxAttempts');
+    $method->invoke($worker, 'database', $popped, $maxTries, new RuntimeException('worker timeout'));
+
+    return $failed;
+}
+
+test('tries=1 のジョブは worker timeout で即座に失敗として確定する', function (): void {
+    $failed = workerTimeoutProbe(new TriesOnceProbeJob, 1);
+
+    expect($failed)->toBeTrue('tries=1 のジョブは timeout 到達で JobFailed (= failed_jobs 記録の契機) になるべき');
+    expect(DB::table('jobs')->count())->toBe(0, '失敗確定後は jobs から削除されるべき');
+});
+
+test('tries=3 のジョブは worker timeout では failed にならず予約が残る', function (): void {
+    $failed = workerTimeoutProbe(new TriesThriceProbeJob, 3);
+
+    expect($failed)->toBeFalse('tries=3 のジョブは timeout 到達だけでは失敗確定しない');
+
+    // 予約されたまま残る = retry_after 経過まで再配布されない = 規則 1 が守る窓。
+    // ワーカー --timeout が retry_after 以上だと、この窓の中で同じジョブが二重取得される。
+    $reserved = DB::table('jobs')->whereNotNull('reserved_at')->count();
+    expect($reserved)->toBe(1, 'timeout で kill されたジョブは予約 (reserved_at) を残したまま jobs に残る');
+});
diff --git a/tests/Support/Queue/TriesOnceProbeJob.php b/tests/Support/Queue/TriesOnceProbeJob.php
new file mode 100644
index 0000000..354fc5e
--- /dev/null
+++ b/tests/Support/Queue/TriesOnceProbeJob.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+
+/**
+ * worker timeout 到達時の遷移検証用のプローブジョブ (tries=1)。
+ *
+ * ★ `app/` 配下ではないので QueuedJobLeaseInventoryTest の目録走査を汚さない。
+ *   handle() は何もしない (検証対象は「失敗記録の有無」であって処理内容ではない)。
+ */
+final class TriesOnceProbeJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+
+    /** 再試行しない = timeout 到達で即 failed になる側 */
+    public int $tries = 1;
+
+    public function handle(): void {}
+}
diff --git a/tests/Support/Queue/TriesThriceProbeJob.php b/tests/Support/Queue/TriesThriceProbeJob.php
new file mode 100644
index 0000000..1526880
--- /dev/null
+++ b/tests/Support/Queue/TriesThriceProbeJob.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Queue\InteractsWithQueue;
+
+/**
+ * worker timeout 到達時の遷移検証用のプローブジョブ (tries=3)。
+ *
+ * ★ `app/` 配下ではないので QueuedJobLeaseInventoryTest の目録走査を汚さない。
+ *   timeout で kill されても failed にならず、予約 (reserved_at) が残る側を再現する。
+ */
+final class TriesThriceProbeJob implements ShouldQueue
+{
+    use Dispatchable;
+    use InteractsWithQueue;
+    use Queueable;
+
+    /** 再試行あり = timeout 到達では failed にならず retry_after 経過まで予約が残る側 */
+    public int $tries = 3;
+
+    public function handle(): void {}
+}
diff --git a/tests/Support/QueueLeaseConfig.php b/tests/Support/QueueLeaseConfig.php
new file mode 100644
index 0000000..b2843ce
--- /dev/null
+++ b/tests/Support/QueueLeaseConfig.php
@@ -0,0 +1,66 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support;
+
+use Webmozart\Assert\Assert;
+
+/**
+ * キューのリース期間 (retry_after) の「リポジトリに書かれている値」を読む helper。
+ *
+ * ★ `config()` 経由にしない。テスト env は `QUEUE_CONNECTION=sync` であり、
+ *   env 上書き (`DB_QUEUE_RETRY_AFTER` 等) も混ざるため、config() で読むと
+ *   「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
+ *   ここでは `config/queue.php` を **直接 require** して素の配列を読む。
+ *
+ * Pest のファイルスコープ関数はテストファイル間で衝突しうるため、
+ * `Tests\Support\AnalysisBudget` と同じくクラスの static メソッドへ集約する
+ * (QueueWorkerLeaseInvariantTest / QueuedJobLeaseInventoryTest の両方から使う)。
+ */
+final class QueueLeaseConfig
+{
+    /**
+     * `driver` が `database` の接続 (接続名 => retry_after 秒)。
+     *
+     * @return array<string, int>
+     */
+    public static function databaseConnections(): array
+    {
+        $config = require self::configPath();
+        Assert::isArray($config, 'config/queue.php が配列を返していません');
+        Assert::keyExists($config, 'connections', 'config/queue.php に connections がありません');
+
+        // 配列 offset 式のままだと narrowing が保たれないためローカル変数へ移す
+        $connections = $config['connections'];
+        Assert::isArray($connections, 'config/queue.php の connections が配列ではありません');
+
+        $result = [];
+        foreach ($connections as $name => $connection) {
+            Assert::string($name, 'config/queue.php の接続名が文字列ではありません');
+            Assert::isArray($connection, "config/queue.php の接続 {$name} が配列ではありません");
+
+            $driver = $connection['driver'] ?? null;
+            if ($driver !== 'database') {
+                continue;
+            }
+
+            Assert::keyExists($connection, 'retry_after', "接続 {$name} に retry_after がありません");
+            $retryAfter = $connection['retry_after'];
+            Assert::integer($retryAfter, "接続 {$name} の retry_after が int ではありません");
+            Assert::greaterThan($retryAfter, 0, "接続 {$name} の retry_after が正の整数ではありません");
+
+            $result[$name] = $retryAfter;
+        }
+
+        Assert::notEmpty($result, 'driver=database の接続が 1 つもありません');
+
+        return $result;
+    }
+
+    /** `config/queue.php` の絶対パス (テストは worktree のルートから走る)。 */
+    public static function configPath(): string
+    {
+        return dirname(__DIR__, 2).'/config/queue.php';
+    }
+}

```
