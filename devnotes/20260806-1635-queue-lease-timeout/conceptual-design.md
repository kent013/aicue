# 概念設計: キューのリース期間とワーカー制限時間の整合 (規則 1)

- c2c feature: `queue-lease-timeout-consistency` (revision `6-507568dc7e82`)
- 裁定: **AG-084** (開発用プロセス定義 4 ペインの制限なしを是正) / **AG-080** (標準形 v1 の不足 2 点)
- canonical_version: v1 / origin: spirux (実行時 fail-fast) + aicue (静的検査)

**位置づけ**: これは機能改善ではなく**信頼性の下支え**である。使命 (思考ゼロ・編集ゼロ) への
貢献は間接的で、「解析・レンダ・課金のジョブが黙って二重に走らない」ことを保証する基盤品質にあたる。

---

## 仮説

**このアプリのキュー設定は、規則 1 (「その接続で有効なワーカーの `timeout` が、その接続の
`retry_after` を下回る」) を 1 つも満たしていない。**しかも規則 1 が破れていることの実害は
「ジョブ側が `$timeout` を持っているから大丈夫」という**偶然**でしか防がれておらず、
`$timeout` を持たないジョブが載っている接続では**現に二重実行の窓が開いている**。

検証方法: `mprocs.yaml` / `scripts/bug-hunt-shard.sh` のワーカー起動定義と
`config/queue.php` の `retry_after`、および全 `ShouldQueue` クラスの `$timeout` 宣言を突き合わせる。

成功判定: (a) 全ワーカー起動定義で `timeout < retry_after` が成立し、(b) その関係が
人の注意力ではなく Architecture テストで固定され、(c) 新しく足された接続・ジョブ・ワーカー定義が
**黙って検査外に落ちない**こと。

---

## 現状 (実査結果。ブリーフ・台帳の記述は鵜呑みにせず実コードで裏を取った)

### `config/queue.php` の `retry_after` (driver=database の 4 接続)

| 接続 | queue | `retry_after` | 載るジョブ | ジョブ側 `$timeout` |
|---|---|---|---|---|
| `database` (既定) | `default` | **90** (`DB_QUEUE_RETRY_AFTER` 既定。**env で上書き可**) | Billing 6 / Mail 2 / Notification 6 | **全て未宣言** |
| `database-analysis` | `analysis` | 1680 | `RunManualAnalysis` | 1560 |
| `database-render` | `render` | 1680 | `RunManualRender` | 1500 |
| `database-media` | `media` | **300** | `DeleteTakeObjectsJob` / `DeleteRenderOutputsJob` | **両方とも未宣言** |

### ワーカー起動定義 (規則 1 の対象)

**(A) `mprocs.yaml`** — `composer dev` (`npx mprocs`) が起動する開発用プロセス定義。

| ペイン | コマンド | 接続 | `--timeout` | 規則 1 |
|---|---|---|---|---|
| `queue` | `queue:listen --tries=1 --timeout=0` | **未指定 (既定接続 = env 依存)** | 0 (制限なし) | **違反** |
| `queue-analysis` | `queue:listen database-analysis … --timeout=0` | `database-analysis` | 0 | **違反** |
| `queue-render` | `queue:listen database-render … --timeout=0` | `database-render` | 0 | **違反** |
| `queue-media` | `queue:listen database-media … --timeout=0` | `database-media` | 0 | **違反** |
| `logs` | `php artisan pail --timeout=0` | — | 0 | **対象外** (後述) |

**(B) `scripts/bug-hunt-shard.sh`** — bug-hunt 環境 (直列 :8010 / 並列 shard :8011..8014) の
worker 起動 (`start_shard_workers`, L738-758)。**ブリーフはこの面を名指ししていないが、
同じ規則 1 の違反が実在した**。

```bash
BUGHUNT_WORKER_CONNECTIONS=(database-analysis database-render database-media)   # L710
setsid php artisan queue:listen "${conn}" --env=bughunt.local \
    --sleep=1 --tries=1 --timeout=1800                                          # L752-753
```

3 接続すべてに**同一リテラル `--timeout=1800`** を与えている。

- `database-analysis` (1680) → 1800 > 1680 → **違反**
- `database-render` (1680) → 1800 > 1680 → **違反**
- `database-media` (**300**) → 1800 > 300 → **違反 (6 倍)**

さらに L736 のコメントが「Job 側の `$timeout` (1,380/1,500)」と書いているが実値は **1,560/1,500**。
`RunManualAnalysis::$timeout` の変更がこの面へ伝わっていない = 手作業の同期が既にドリフトしている
実例である。

**(C) 本番/ステージングの supervisor 定義** — リポジトリ内に**存在しない**
(`docker/Dockerfile` にも `.github/` にも supervisor conf / `queue:work` の記述なし)。
`docs/architecture.md` が「worker プロセス定義・デプロイ手順・監視対象に
`php artisan queue:work database-{analysis,render,media}` を必須項目として登録する」と
**散文で運用側に要求している**だけ。静的検査の対象にできる実体がリポジトリに無い。

### 外部 I/O の実測上限 (値を決めるための与件)

裁定が「修正の方向は実測にもとづいて判断せよ」と要求しているので、
`database` 接続に載るジョブの外部 I/O 上限を実測した。

| 経路 | client timeout | 1 ジョブあたりの呼び出し数 | 有限上限 |
|---|---|---|---|
| Stripe (Billing 6 ジョブ) | `Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT = 80` / `DEFAULT_CONNECT_TIMEOUT = 30`。**アプリ側で上書きなし** (`config/cashier.php` / `config/services.php` に timeout 設定なし。`CashierStripeGateway` / `CashierAutoRechargeGateway` は `Cashier::stripe()` をそのまま使う) | `ExecuteAutoRechargeAttemptJob` が最大: `createOrGetStripeCustomer` (0〜1) + `invoices->create` + `invoiceItems->create` + `invoices->finalizeInvoice` + `invoices->pay` = **4〜5 回** | **約 400 秒** |
| Mail (Mailable 2 本) | smtp mailer は `'timeout' => null`。production 想定の `ses` (`ses-v2`) は `config/services.php` に HTTP timeout 設定なし = **AWS SDK 既定 (無制限)** | 1 送信 = 複数往復 | **無し (無限)** |
| Notification (6 本) | 全て `via() === ['mail']` (DB channel なし) → 上と同じ経路 | 同上 | **無し (無限)** |
| S3 (`database-media` の削除 2 本) | `config/filesystems.php` の s3 disk に timeout 設定なし = AWS SDK 既定 | オブジェクト数本 | **無し (無限)** |

**ここから 2 つの結論が出る**:

1. **現行の `database.retry_after = 90` は小さすぎる**。既知の有限上限 (Stripe 400 秒) に
   4 倍以上足りない。ワーカー側を Laravel 既定の 60 に揃えるだけでは、規則 1 は満たしても
   正常に完了しうる課金処理を誤って kill する。この接続は `retry_after` を上げる側が正しい。
2. **既定接続 (と media) には SDK 由来の有限上限が存在しない**。したがって
   **ワーカー timeout は「導出される値」ではなく「上限を作る運用 SLA」である**。
   実測から導出できるのは「既知の有限上限 (400 秒) を上回らなければならない」という
   **下限だけ**であり、上側は運用判断で決めるしかない。
   現状はそもそも上限が無い (`--timeout=0`) ので、値を置くことは
   **初めて上限を与える変更**であり、「無限待機と二重取得を防ぐ代わりに
   遅い成功を失敗へ変える」トレードオフを伴う (後述)。

### 既存の規則 2 検査

`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` が
`RunManualAnalysis` / `RunManualRender` / `DeleteRenderOutputsJob` を**クラス名決め打ち**で見ている。
`ShouldQueue` 実装は全 **18 クラス** (Jobs 10 / Mail 2 / Notification 6) あり、
**15 クラスが誰にも見られていない**。新しいジョブが `$timeout = 3600` を宣言して
`database` (retry_after 90) に載っても、CI は一言も言わない。

---

## 課題 (なぜこれが事故になるか)

DB driver のキューは、ジョブを取り出すとき `reserved_at` を打刻し、
`reserved_at < now - retry_after` の行を「担当が落ちた」とみなして**別のワーカーへ再配布**する。
**実行中にこのリース期間を延長する API は DB driver に存在しない**
(SQS の `ChangeMessageVisibility` に相当するものが無い)。したがって
「まだ走っている処理を落ちたと誤認させない」手段は**設定の大小関係を保つことだけ**である。

規則 1 が「**無条件**」なのは、1 つのワーカーが同じ接続の**複数種類**のジョブを処理するからである。
ある 1 本のジョブが `$timeout` を持っていても、同じ接続の**別のジョブ**が持っていなければ、
そのジョブはワーカー側の制限時間まで走り、`retry_after` を超えて二重取得される。

**この「偶然の防壁」が既に外れている面が 2 つある**:

1. **`database` 接続 (retry_after 90) × mprocs `queue` ペイン (制限なし)**
   載っているのは Billing 6 ジョブ・Mail・Notification で、**どれも `$timeout` を宣言していない**。
   ワーカーが制限なしなので、Stripe 呼び出しが 90 秒を超えた時点で二重取得される。
   上の実測どおり `ExecuteAutoRechargeAttemptJob` は 1 本で Stripe を 4〜5 回叩き、
   SDK 上限は 1 回 80 秒である。しかもこれは **`$tries = 1` の課金実行ジョブ**であり、
   spirux で 2026-07-25 に起きた事故 (二重取得 → 「やり直しは 1 回まで」に抵触 → 処理失敗) と
   **同じ構図**が成立する (Stripe の冪等キーで二重課金そのものは止まるが、
   attempt の状態機械は 2 本の実行で踏み荒らされる)。
2. **`database-media` 接続 (retry_after 300) × bug-hunt worker (`--timeout=1800`)**
   `DeleteTakeObjectsJob` / `DeleteRenderOutputsJob` は `$timeout` 未宣言。
   オブジェクトストレージが詰まって 300 秒を超えれば二重取得され、`$tries = 3` と合わさって
   同じ削除が最大 6 回走る。

`database-analysis` / `database-render` は今のところジョブ側 `$timeout` が先に効くため潜在的だが、
**それは規則 1 が禁じている「免除されると思い込むこと」そのもの**である。

---

## 方針

### 4 つの施策

| # | 施策 | 種別 | 対象 |
|---|---|---|---|
| 0 | `database` 接続の `retry_after` を 90 → 600 のリテラルへ (env 上書きを畳む) | 値の修正 | `config/queue.php` |
| 1 | `mprocs.yaml` 4 ペインの `--timeout` を接続別に是正 + `queue` ペインへ接続名明示 (AG-084) | 値の修正 | `mprocs.yaml` |
| 2 | `scripts/bug-hunt-shard.sh` の `--timeout=1800` を接続別に是正 | 値の修正 | `scripts/bug-hunt-shard.sh` |
| 3 | **規則 1** の静的検査 `QueueWorkerLeaseInvariantTest` を新設 | 目録型 gate | 1・2 の両面 |
| 4 | **規則 2** の配線網羅 `QueuedJobLeaseInventoryTest` を新設 | 目録型 gate | 全 `ShouldQueue` (18) |
| 5 | 運用契約の明文化 (接続ごとの値表 + dev ワーカー必須) | ドキュメント | `docs/architecture.md` |

### 「制限時間を下げるか `retry_after` を上げるか」の判断 (裁定が実測を要求している)

**接続ごとに向きを分ける**。一律に「下げる」でも「上げる」でもない。

- **`database-analysis` / `database-render`: ワーカー側を下げる (`retry_after` は触らない)**。
  この 2 つの `retry_after` (1680) は
  `job timeout < retry_after < 予約 TTL (1800) ≤ stale 閾値 (1800)` という
  **4 項連鎖の中間項**であり、上げれば予約 TTL 1800 を突き抜けて
  `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` を壊す
  (予約 TTL は `TicketLedgerService` 側の値で「変更しない」と両テストが明記)。
- **`database-media`: ワーカー側を下げる (`retry_after` 300 は触らない)**。
  載っているのはオブジェクト削除 2 本で、240 秒で足りないケースは想定されない。
- **`database`: `retry_after` を上げる (90 → 600)**。実測どおり 90 は
  `ExecuteAutoRechargeAttemptJob` (Stripe 4〜5 呼び出し / SDK 上限 80s = 400 秒) に対して
  小さすぎる。この接続の `retry_after` は**どの連鎖にも属していない**
  (予約 TTL とも budget とも無関係) ので、上げても既存の不変条件を 1 つも壊さない。

### `database.retry_after` を env 上書き可からリテラルへ

現行は `(int) env('DB_QUEUE_RETRY_AFTER', 90)` で、`.env.example` にも記載が無い。
静的 gate は config を**テスト環境の値**で読むため、env 上書きが残っていると
「gate は通るが本番の実値は別」という状態を作れてしまう
(= gate が嘘をつく)。他 3 接続は既にリテラルなので、`database` も**リテラル `600`** に揃える
(AGENTS.md 思考原則 3「後方互換の並走を残さない」)。`DB_QUEUE_RETRY_AFTER` は
`.env.example` にも `.env.testing` にも無いので、削除しても壊れる箇所は無い (実査済み)。

### 採用する値と根拠

安全余白 **60 秒**を共通に置く (`retry_after` − ワーカー timeout = 60)。
既存の解析 budget が使う S=90 (worker alarm → `run()` 入口 P + タイマー精度 + シグナル配送) と
同系の余白で、ワーカーが子プロセスを kill してから DB の `reserved_at` が解放されるまでの
猶予に充てる。

| 接続 | `retry_after` | ワーカー timeout | 関係 | 変更 |
|---|---|---|---|---|
| `database` | **600** (90 から) | **540** | 400 (既知の有限上限) < 540 < 600 | config + ワーカー |
| `database-analysis` | 1680 (据置) | **1620** | 1560 (job) < 1620 < 1680 | ワーカーのみ |
| `database-render` | 1680 (据置) | **1620** | 1500 (job) < 1620 < 1680 | ワーカーのみ |
| `database-media` | 300 (据置) | **240** | 240 < 300 | ワーカーのみ |

### 運用 SLA としての受入条件 (「切りのよい値」で済ませない)

| 接続 | 値 | timeout 到達時の業務影響 | 回収経路 | 受入根拠 |
|---|---|---|---|---|
| `database` | 540 | 課金 attempt / Mail / Notification の**遅い成功が失敗に変わる** | `queue:work` 経路なら `$tries=1` で failed 記録 → リコンサイル (i)。`queue:listen` 経路なら retry_after 後に再配布 | **既知の有限上限 (Stripe 5 呼び出し × SDK 上限 80s = 400 秒) を上回る最小の切りのよい値**。400 を下回る値は正常に完了しうる課金処理を kill する |
| `database-analysis` | 1620 | 解析が中断 | `analysis:recover-stale-jobs` cron (stale 閾値 1800) | ジョブ側 `$timeout` 1560 を上回り `retry_after` 1680 を下回る唯一の帯。`queue:work` ではジョブ側 alarm が先に効いて finalize が走る |
| `database-render` | 1620 | レンダが中断 | `render:recover-stale-jobs` cron | 同上 (ジョブ側 1500) |
| `database-media` | 240 | S3 削除が中断 | `retry_after` 300 後に再配布 (`$tries = 3`) | **削除は冪等** (「既に無いキーの削除は no-op」と `DeleteTakeObjectsJob` の PHPDoc が明記) かつ再試行付き。kill されても再配布で完了する = **時間見積りではなく構造で受け入れられる**。実処理量はマニュアル削除時で最大数百キー (1 件数百 ms なら数十秒) |

### トレードオフを正確に書く

「上限なしから有限上限を置くのは後退ではない」という言い方は不正確である。
これは**無限待機と二重取得を防ぐ代わりに、遅い成功を失敗へ変えるトレードオフ**である。
とくに Mail / Notification は client timeout を持たないため、540 秒を超えて成功しえた送信は
新設定では失敗になる (failed_jobs に記録される)。上表の回収経路がその受け皿である。

`database-analysis` / `database-render` の 1620 は「ジョブ側 `$timeout` (1560/1500) より上」に
置いてある。これによりジョブは従来どおり自分の pcntl alarm で `failed()` を通って終わり、
既存の finalize 予算 (M₁=30s) と terminal transaction の契約が変わらない
(ワーカー側が先に効くと finalize が走らず、解析の状態が `running` のまま残る)。
**この上下関係は運用上の意図であって不変条件として固定しない** (規則 1 と規則 2 のあいだに
大小関係を課さない、という標準形 v1 の裁定に従う)。

### ワーカー timeout に達したときに何が起きるか (起動形態で 2 経路)

**経路 A: `queue:work` (常駐。本番運用契約)** —
`Illuminate\Queue\Worker::daemon()` が SIGALRM を張り、
`registerTimeoutHandler()` のハンドラは**プロセスを kill する前に**
`markJobAsFailedIfWillExceedMaxAttempts()` を呼ぶ。制限時間は
`timeoutForJob()` により**ジョブ側 `$timeout` が優先**、無ければ `--timeout`。
`maxTries` は CLI `--tries` とジョブ `$tries` の合成。

| ジョブ | timeout 到達時 | 再実行の経路 |
|---|---|---|
| `$tries = 1` (課金 3 本 / 解析 / レンダ) | **その場で failed 記録** (`failed()` フックも走る) → kill | 各ドメインのリコンサイル / stale 回収 cron |
| `$tries = 3` (`ReuseSubscriptionPaymentMethodJob` / `SetDefaultPaymentMethodJob` / media 削除 2 本) | failed にならず**予約が残ったまま** kill | `retry_after` 経過後に再配布 = ワーカー timeout との差 **60 秒後** |

**経路 B: `queue:listen` (mprocs / bug-hunt)** —
制限の主体は**親 Listener の Symfony `Process` timeout** ただ 1 つ
(子には `--timeout` が渡らず、`runNextJob()` は SIGALRM を張らない)。
到達時は Symfony が子を kill するだけで、**`markJobAsFailedIfWillExceedMaxAttempts()` を
通らない**。したがって `$tries` に関係なく:

| 全ジョブ共通 | timeout 到達時 | 再実行の経路 |
|---|---|---|
| `queue:listen` 配下 | failed 記録は**通らない**。予約が残ったまま子が kill され、**listener 本体も終了** | `retry_after` 経過後に再配布 = **60 秒後** |

経路 B の「予約が残ったまま kill」が**規則 1 が守っているまさにその窓**である:
規則 1 が破れていると、kill されたプロセスがまだ生きているうちに再配布されて 2 本同時に走る。
経路 A の `$tries > 1` も同じ窓を持つ。
守られていれば、再配布は必ず kill 完了後になる。

### 回収遅延の許容 (`retry_after` 90 → 600 の副作用)

**ワーカーが異常死した場合**の再取得が最大 510 秒遅くなる。許容する理由:

- 遅延が効くのは worker のクラッシュ時のみで、通常運転では発生しない。
- 既定接続に載るのは Mail / Notification (遅延はユーザー操作をブロックしない) と
  課金ジョブである。課金ジョブは `$tries = 1` + リコンサイル回収が正規の再試行経路で、
  そもそも `retry_after` による再配布に依存していない
  (`ExecuteAutoRechargeAttemptJob` の PHPDoc が「再試行はリコンサイル (i) の管轄」と明記)。

### 本 gate が保証しないこと (誠実な限界)

本 feature が保証するのは **リポジトリ内の dev / bug-hunt 設定とジョブ契約**であり、
**本番設定値の正本を提供する**ところまでである。
本番プロセス定義はリポジトリ外にあるため、**本番設定とのドリフトは検知しない**
(標準形が言う「実行時が捕まえるもの」= env 上書き・実 supervisor 設定の取り違え。
本 feature のスコープ外)。

また、Mail (SES/SMTP) と S3 には client timeout が設定されておらず、
**ワーカー timeout が唯一の上限**になっている。この上限を短くしたくなったときは
Stripe / AWS SDK 側の client timeout を pin する必要がある
(既存 `PromptClientTimeoutInvariantTest` と同型)。これは課金・送信経路の挙動変更なので
本 feature に混ぜず、**後続 TODO 候補**として詳細設計に記す。

### mprocs `queue` ペインの接続名を明示する

現状 `php artisan queue:listen --tries=1 --timeout=0` は接続を書いていないため、
**どの接続に対する規則 1 なのかが静的に決まらない** (`QUEUE_CONNECTION` env 次第)。
`php artisan queue:listen database --tries=1 --timeout=540` と**接続名を明示**する。
1 トークンの追加で env 依存が消え、静的 gate が「この行はこの `retry_after` と比較すればよい」と
確定できる。他 3 ペインは既に接続名を書いており、書式が揃う副次効果もある。

### `queue:listen` では規則 1 が**唯一の**防壁である (vendor 実読で確定)

mprocs / bug-hunt はいずれも `queue:listen`、本番運用契約は `queue:work` (常駐) である。
両者は `--timeout` の**効き方が根本的に違う** (`vendor/laravel/framework` 実読):

- `Illuminate\Queue\Listener::createCommand()` が子に渡すのは
  `queue:work {connection} --once --name --queue --backoff --memory --sleep --tries` だけで、
  **`--timeout` は子へ渡らない**。
- `Listener::makeProcess()` は `--timeout` を **Symfony `Process` の timeout** としてのみ使う。
- `WorkCommand::runWorker()` は `--once` のとき `Worker::runNextJob()` を呼ぶが、
  **`runNextJob()` は SIGALRM ハンドラを張らない** (張るのは `daemon()` だけ)。

→ **`queue:listen` 配下ではジョブ側 `$timeout` が一切効かない**。
`RunManualAnalysis::$timeout = 1560` は dev / bug-hunt では 1 秒も保護を与えていない。
唯一の上限は親の `--timeout` である。

これは規則 1 が「**無条件**」であることの、このリポジトリにおける実例そのものである
——「ジョブ側に `$timeout` があるから大丈夫」は `queue:listen` では**文字どおり成立しない**。
現状の `--timeout=0` (Symfony timeout 無効) は、dev / bug-hunt のジョブに
**上限が 1 つも無い**ことを意味する。

gate は `queue:work` / `queue:listen` の**両サブコマンドを等しくワーカーとして扱う**
(どちらでも `--timeout` は「その接続で有効な制限時間」であり、規則 1 の対象は同じ)。
既定値もどちらも 60 (`ListenCommand` / `WorkCommand` で実確認)。

### 有限 `--timeout` の副作用: listener 本体が落ちる

`Listener::listen()` は `runProcess()` を `while (true)` で回すだけで
`ProcessTimedOutException` を catch しない。したがって **`--timeout` に到達すると
listener プロセス自体が終了する**。

- mprocs: 該当ペインが死ぬ (復帰は手動再起動)。
- bug-hunt: 既存の `worker_alive`(`/proc` cmdline 照合) が検出し、
  `--keep-db` reuse は「worker が起動していない」で中止される (F-01 再発を防ぐ既存機構)。

現状 (`--timeout=0`) では落ちない代わりに**ジョブが無限に走って必ず二重取得される**。
つまりこれは「無限待機を許す」か「上限に達したら worker を落として気づかせる」かの
トレードオフであり、本設計は後者を採る (裁定 AG-084 が制限なしの是正を求めている)。
選んだ値 (540 / 1620 / 1620 / 240) はいずれも通常の dev / bug-hunt 実行で
到達しない水準に置いてある。

### 施策 3 の gate 設計 (規則 1)

`tests/Architecture/QueueWorkerLeaseInvariantTest.php` (DB 不使用の静的検査)。

- **(1) mprocs**: `mprocs.yaml` を Symfony Yaml で読み、各 proc の `shell` を走査する。
  `artisan` と `queue:work`/`queue:listen` を含む proc を**自動的に**ワーカーとみなす
  (allowlist を持たないので、新しいペインを足しても検査から漏れない)。各ワーカーに対し
  「接続名の明示がある」「`--timeout=N` の明示がある」「`1 ≤ N < retry_after(接続)`」を要求。
  `--timeout=0` は「制限なし」なので**必ず違反**として落ちる。
- **(2) ワーカーの網羅**: mprocs のワーカーが覆う接続集合が、`config/queue.php` の
  `driver=database` 接続集合と**一致**することを要求する。新しい専用接続を足したのに
  dev ワーカーを足し忘れたら落ちる。
  これは強い制約なので、**アーキテクチャ規約として明文化する側**を採る:
  `docs/architecture.md` に「**`driver=database` の接続は dev ワーカーペインを必ず持つ**」を
  運用契約として書き、gate はその写しであると位置づける。
  「dev では起動しない接続」が実際に必要になった時点で理由付き除外目録を足す
  (今は 0 件なので作らない = 思考原則 2)。
- **(3) 非ワーカーの明示除外**: `shell` に `--timeout` を含むが上の判定でワーカーにならない proc
  (= `logs` ペインの `php artisan pail --timeout=0`) は、**理由付きの除外目録**に登録されている
  ことを要求する。黙って除外しない (ブリーフの要求)。`pail` の `--timeout` は
  「ログ追尾を何秒で打ち切るか」であってキューのリースとは無関係、というのが理由。
- **(4) bug-hunt**: `scripts/bug-hunt-shard.sh` から
  `BUGHUNT_WORKER_TIMEOUTS` (施策 2 で導入する連想配列リテラル) を正規表現で抽出し、
  各 `[接続]=N` に同じ `1 ≤ N < retry_after(接続)` を課す。あわせて
  `BUGHUNT_WORKER_CONNECTIONS` の全要素が `BUGHUNT_WORKER_TIMEOUTS` に鍵を持つこと、
  `start_shard_workers` が `--timeout=` の**数値リテラル直書きに戻っていない**こと
  (= 必ず配列経由で渡すこと) を構造で固定する。
  bash を PHP のテストから構造検査する先例は `BughuntShardCapInvariantTest` にある。

### 施策 4 の gate 設計 (規則 2 + 配線網羅)

`tests/Architecture/QueuedJobLeaseInventoryTest.php` (DB 不使用の静的検査)。
台帳が「本 feature の真の欠落」と名指ししている**(a) 新しいジョブが検査対象に入らない**を閉じる。

- **走査 (母集団)**: `app/` 配下の全 PHP をクラス化し、
  **`ReflectionClass::implementsInterface(ShouldQueue::class)` かつ `isInstantiable()`** を
  母集団判定の正本にする (現在 18 クラス)。親クラス・trait 経由の実装も拾えるため、
  Job だけでなく Mail (2) / Notification (6) も自動的に母集団へ入る
  (この 3 系統が実際に入っていることを代表クラス名指しで assert する)。
- **目録**: `QUEUED_JOB_LEASE_INVENTORY` に `クラス => 接続` を宣言する。
  走査結果と目録の**対称差が空**であることを要求する (deny-by-default。
  新しいジョブを足すと必ず落ち、実装者に接続の宣言を強制する)。
- **接続決定経路の deny-by-default 走査** (Codex 概念 R1 Critical 反映):
  Laravel で接続が決まる経路は `onConnection()` だけではない
  (`public $connection` プロパティ / `$this->connection = ` 代入 /
  Notification の `viaConnections()` / dispatch 側チェーン `Job::dispatch()->onConnection()` /
  `Queue::connection()`)。実査では aicue に現存するのは `onConnection('リテラル')` 4 箇所のみだが、
  **将来別経路が足されたら黙って検査外に落ちる**のが本 feature の欠落 (a) そのものである。
  そこで `app/` 全体を走査し、
  **「目録登録済みクラス内の `onConnection('リテラル')`」以外の hit をすべて fail** させる。

  **走査は正規表現ではなく `token_get_all()` によるトークン解析で行う**
  (Codex 概念 R2 Critical 反映)。正規表現は別名 import・trait・改行・コメント・
  静的呼び出し・変数経由 dispatch で誤検出/検出漏れを起こし、
  とくに `->onConnection(` の「ジョブ内部 / dispatch 側」の分類が字句だけでは決まらないため、
  「黙って検査外に落ちない」という成功条件を満たせない。トークン解析なら:
  - 空白 / コメント / DocComment を落としたトークン列で
    `T_OBJECT_OPERATOR|T_NULLSAFE_OBJECT_OPERATOR|T_DOUBLE_COLON` + `T_STRING`
    (`onConnection` / `viaConnections` / `viaConnection`) を確実に拾える
  - 引数が `T_CONSTANT_ENCAPSED_STRING` 1 個 + `)` の形**だけ**を「リテラル」と認め、
    それ以外は**解析不能として fail** できる (「動的に決まる接続は静的検査できない ——
    実行時 fail-fast の対象として個別に扱え」)
  - `$this->connection = ` 代入 / `connection` という名前のプロパティ宣言も検出できる
  - 呼び出し元クラスを同じトークン列の `T_NAMESPACE` + `T_CLASS` から決められる
    (ファイル名 → クラス名の推測に頼らない)

  **許容形は `$this->onConnection('リテラル')` ただ 1 つ**とする (Codex 概念 R3 Critical 反映)。
  トークン解析は字句を正確に拾えても**呼び出し対象の意味までは解決しない**ため、
  receiver が `T_VARIABLE` = `$this` であることまで検証しないと
  `OtherJob::dispatch()->onConnection('database-media')` を自クラスの指定と誤認する。
  それ以外の receiver (`Foo::dispatch()->onConnection()` / `$job->onConnection()` /
  `Queue::connection()`) は**すべて接続経路違反として fail** させる。
  dispatch 側で接続を差し替える形は本アプリに 1 件も無いことを実査済みなので、
  deny-by-default にしても既存コードは通る。

  nikic/php-parser は直接依存ではないので、stdlib の `token_get_all()` で足りる。
  正規表現は **bash (`scripts/bug-hunt-shard.sh`) と YAML (`mprocs.yaml`) の
  限定された構文にのみ**使う。
- **宣言の裏取り**: 上のトークン解析で得た `onConnection('リテラル')` が
  目録の宣言と一致することを要求する。
  `onConnection` を持たないクラスは目録で `null` (= 既定接続) と宣言する。
- **規則 2 の検査**: `ReflectionClass::getDefaultProperties()['timeout']` で
  インスタンス化せずに `$timeout` を読む。戻り値は
  **`int|null` へ正規化する純関数 helper に閉じ込め**、非 int / 0 以下は明示的に fail させる
  (PHPStan level 10 で `mixed` が漏れないようにする)。
  `array_key_exists('timeout', $defaults)` で**未宣言と明示的な `null` を区別**する。判定は:
  - 接続が明示されている entry: `$timeout < retry_after(接続)` を要求
  - 既定接続 (`null`) の entry: **`$timeout` の宣言自体を禁止**する。
    既定接続は `QUEUE_CONNECTION` env 次第でどの接続にも化けるため、静的に
    `retry_after` と比較できない。`$timeout` が要るなら `onConnection()` で接続を pin せよ、
    というメッセージで落とす (現状 12 クラスすべて `$timeout` 未宣言なので通る)。
  - `$timeout` 未宣言: 規則 2 は空に成立。上限はワーカー側 (規則 1) が担保する。

### 3 責務を独立して診断できるようにする

テストファイルは 2 本だが、**テストケースは 3 責務に分ける**:
`規則 1:` (ワーカー起動定義) / `規則 2:` (ジョブ `$timeout`) / `接続経路:` (接続決定経路の網羅)。
失敗メッセージの先頭にこの接頭辞を必ず付け、どの責務が壊れたかを 1 行目で判別できるようにする。
ファイルを 3 本に割らないのは、規則 2 と接続経路走査が**同じ目録定数を共有する**ため
(定数を跨いで参照すると Pest のファイルスコープ const 衝突を招く)。

**2 本の規則のあいだに大小関係は課さない** (標準形 v1 / Codex 合議で確定済み。
ジョブ側 `$timeout` はワーカー起動時の値に優先するため、3 項連鎖は公式文面から導けない。
中央の関係は Horizon 固有の事情で、本家系は Horizon 未導入)。
本設計で `1560 < 1620` を選んだのは**運用上の意図**であって不変条件として固定しない。

---

## 代替案と却下理由

| 案 | 却下理由 |
|---|---|
| **`database-analysis` / `database-render` の `retry_after` を 1800+ へ引き上げてワーカー 1800 を維持** | 予約 TTL 1800 を突き抜け、`retry_after < 予約 TTL ≤ stale 閾値` の既存連鎖を壊す。裁定の注意書きが名指しで禁じている |
| **`database` も `retry_after` 90 据置でワーカーを 60 に下げる** | 規則 1 は満たすが、Stripe SDK の 1 呼び出し上限が 80 秒 (実測) なので**正常な処理が誤 kill される**。実測に基づいて `retry_after` 側を上げるのが正しい向き |
| **`database` を 300/240 にする (Round 1 で一度採った案)** | 既知の有限上限 400 秒 (Stripe 5 呼び出し) を**下回る**ため、正常に完了しうる課金処理を新たに kill する変更になる。実測を採用値に反映していない = 却下 (Codex 概念 R2 Critical) |
| **Stripe / AWS SDK の client timeout を本 feature で同時に絞る** | 課金・送信経路の挙動変更 (遅い成功呼び出しの client 側中断) を infra タスクに混ぜることになる。分離して後続 TODO 候補にする |
| **既定接続を Billing 用と Mail/Notification 用に分割する (`database-billing` 新設)** | 「短いジョブと長いジョブに同じ `retry_after` を被せている」構造の指摘としては正しいが、接続 1 本の新設は config + mprocs ペイン + bug-hunt worker + 6 ジョブの `onConnection` + docs に波及する。回収遅延 510 秒が実害になった時点でやればよい (思考原則 2)。**後続 TODO 候補**として記す |
| **PHP の接続決定経路を正規表現で走査する** | 別名 import・trait・改行・コメント・静的呼び出し・変数経由 dispatch で誤検出/検出漏れが起きる。「黙って検査外に落ちない」という成功条件を満たせない。`token_get_all()` を使う (Codex 概念 R2 Critical) |
| **トークン解析で `onConnection('リテラル')` を receiver 不問で認める** | `OtherJob::dispatch()->onConnection('x')` を自クラスの指定と誤認する。receiver を `$this` に限定する (Codex 概念 R3 Critical) |
| **`queue:listen` の終了挙動を `queue:work` と同一視する** | 実読の結果まったく別経路だった (子に `--timeout` が渡らず SIGALRM も張られない)。同一視すると「ジョブ側 `$timeout` があるから dev も守られている」という誤った安心を設計に埋め込む (Codex 概念 R3 Critical) |
| **全接続に一律の `--timeout` 値を使う** | `retry_after` が 90 / 300 / 1680 と 18 倍の開きがある。最小 (90) に合わせると解析 (1560s) が完走できず、最大に合わせると規則 1 違反が残る。ブリーフも「一律で済ませない」と明記 |
| **全ジョブに `$timeout` を明示させて規則 2 だけで守る** | 規則 1 は「無条件」であり、ジョブ側宣言で免除されない (漏れ 1 本で窓が開く)。18 クラスへの手配線は「選択制だと漏れる」という標準形の指摘そのもの |
| **実行時 fail-fast (spirux 形) を全ジョブに入れる** | 標準形 v1 で**限定適用の補助**と決着済み。全ジョブ配線はまた漏れる。今回は静的側を固める (ブリーフのスコープ外に明記) |
| **`mprocs.yaml` から `--timeout` を削って Laravel 既定 (60) に任せる** | 60 < 90 は満たすが解析 (1560s) / レンダ (1500s) が 60 秒で殺される。かつ「既定に頼る」は静的検査が値を読めない = gate が書けない |
| **gate を `mprocs.yaml` だけに閉じる (ブリーフの文面どおり)** | `scripts/bug-hunt-shard.sh` に実害のある違反 (media で 6 倍) が実在する。同じ規則の違反を隣に残したまま片方だけ固定するのは gate の体裁だけを整える行為 |
| **本番 supervisor 定義をリポジトリに追加して gate 対象にする** | 実体が無い (インフラは別管理)。今無いものを設計のために作るのはオーバーエンジニアリング。`docs/architecture.md` の運用契約に**接続ごとの具体値表**を載せるに留める。**本 gate は本番の二重実行を直接止めるものではない**ことを設計本文に明記する |
| **`config/queue.php` から dev worker の timeout を導出する仕組みを作る** | mprocs.yaml は静的 YAML、bug-hunt は bash で、両者から config を読む共通機構は自前機構になる。値をリテラルで 2 箇所に置き、gate でドリフトを止めるほうが小さい |

---

## スコープに入れないものとその理由

| 入れないもの | 理由 |
|---|---|
| **予約 TTL / 停滞判定閾値 / 外部 Batch API のポーリング予算の連鎖** | c2c `budget-invariant-gates` の管轄。aicue は既に保有 (`AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest`)。**触らない** |
| **二重取得後の結果収束 (冪等化)** | c2c `job-execution-deduplication`。本 feature は「二重取得を起こさない」側 |
| **再試行の終端 / 滞留の回収** | c2c `job-deferral-termination` / `stuck-job-recovery` |
| **キュー投入経路の原子性** | c2c `queue-dispatch-outbox` |
| **実行時 fail-fast (spirux 形) の導入** | 標準形 v1 で限定適用の補助と決着。今回は静的側を固める |
| **`pail` ペインの `--timeout=0`** | `pail` はワーカーではなくログ追尾クライアント。`--timeout` は「追尾を何秒で打ち切るか」であってキューのリースと無関係。**黙って除外せず、施策 3 の除外目録に理由付きで登録する** |
| **本番/ステージングの supervisor 定義の gate 化** | リポジトリに実体が無い (上表)。`docs/architecture.md` の運用契約に接続ごとの具体値表を足すのみ。**本 feature の効果は dev / bug-hunt / リポジトリ内規約に限定される**ことを明示する (「実行時が捕まえるもの」= env 上書き・実 supervisor 設定の取り違えは標準形どおり静的検査の守備範囲外) |
| **Stripe / AWS SDK (SES・S3) client timeout の上限固定** | 課金・送信経路の挙動変更。`PromptClientTimeoutInvariantTest` と同型の pin が要る。**後続 TODO 候補**として詳細設計に記す (本 feature の完了条件には含めない) |
| **既定接続の分割 (`database-billing`)** | 上表の理由。**後続 TODO 候補** |
| **dev ワーカー不在接続の理由付き除外目録** | 除外したい接続が現時点で 0 件。必要になってから作る (思考原則 2) |
| **`RunManualAnalysis` / `RunManualRender` の `$timeout` 値の見直し** | 既存 budget 連鎖が固定済み。本 feature は連鎖の**外側** (ワーカー) を足す |
| **media 系ジョブへの `$timeout` 追加** | 規則 1 (ワーカー 240 < 300) で上限が担保される。今必要ない (思考原則 2) |
| **`ThrottleCoverageInventoryTest` 型の exemption 機構** | 現時点で除外したいジョブが 0 件。除外機構は必要になってから作る |

---

## 検証方法

| 何を確かめるか | コマンド / 手順 | 期待結果 |
|---|---|---|
| 規則 1 gate が現状を落とすこと (テストファースト) | 施策 3 のテストだけを先に足して `composer test -- --filter=QueueWorkerLease` | **7 件の違反で fail** (mprocs 4 ペイン + bug-hunt 3 接続)。うち mprocs `queue` ペインは「接続名未指定」でも落ちる |
| 規則 2 / 網羅 gate が現状を落とすこと | 施策 4 のテストだけを先に足す (目録を空で) | 18 クラス未登録で fail |
| 是正後に両 gate が通ること | `composer test -- --filter='QueueWorkerLease|QueuedJobLease'` | green |
| 既存 budget 連鎖を壊していないこと | `composer test -- --filter='TimeBudget'` | green (`database` の retry_after 変更が analysis/render 連鎖に波及しない) |
| 課金ジョブの経路が壊れていないこと | `composer test -- --filter='AutoRecharge'` | green |
| timeout 到達時の遷移が想定どおり (`queue:work` 経路。tries=1 は即 failed / tries=3 は retry_after 後) | 詳細設計で規定する Feature テスト | green |

**自動テストにしないと決めたこと**: `queue:listen` 経路の終了挙動
(親 Symfony Process timeout → 子 kill → listener 終了)。実プロセス起動と実時間の経過を要し、
グローバルテストロック配下のテストレーンで数分間占有することになるため。
代わりに vendor 実読の結果 (`Listener::createCommand` が `--timeout` を渡さない /
`runNextJob` が SIGALRM を張らない / `listen()` が `ProcessTimedOutException` を catch しない)
を設計とテストのコメントに固定する。
| bug-hunt の実行配線が壊れていないこと | `scripts/bug-hunt-shard.sh self-test` | 全ケース pass (実資源に触れない) |
| 型安全 | `composer phpstan` | level 10 green |
| 全体 | `composer test` / `vendor/bin/pint --test` | green |

**手動確認** (値が実運用に耐えるか): `composer dev` で mprocs を起動し、
`queue-analysis` ペインで解析ジョブを 1 本流して**ワーカー timeout 1620 に達する前に
ジョブ側 1560 の alarm で終わる**ことをログで確認する (順序が逆だと finalize が走らない)。

---

## Codex 合議の結果 (打ち切り記録)

オーケストレータ指示により**最大 3 ラウンド**。全ラウンドの記録は
`conceptual-review-round-{1,2,3}.md` と `codex-history/conceptual-review-decisions-round-{1,2,3}.md`。

| Round | 判定 | Critical | 処理 |
|---|---|---|---|
| 1 | CHANGES_REQUESTED | 2 件 (接続決定経路の網羅 / `database` 60 秒への引き下げの影響評価) | 両方**対応**。実測の結果 `database` の修正方向を「ワーカーを下げる」から「`retry_after` を上げる」へ反転 |
| 2 | CHANGES_REQUESTED | 3 件 (採用値が実測 400 秒を下回る / Mail・Notification 未実測 / 正規表現では網羅保証にならない) | 3 件とも**対応**。値を 600/540 へ、走査を `token_get_all()` へ |
| 3 | CHANGES_REQUESTED | 2 件 (receiver 未識別 / `queue:listen` の終了経路を `queue:work` と同一視) | 2 件とも**対応**。receiver を `$this` 限定に、`queue:listen` の経路を vendor 実読で分離 |

Round 3 の Critical 2 件は**いずれも vendor / アプリの実コードで根拠を取ってその場で設計へ反映済み**であり、
未解決の Critical は残っていない (Round 4 を回していれば APPROVED になる見込みだが、
ラウンド上限に従って打ち切った)。Round 3 の Warning / Suggestion も全件処理済み
(`conceptual-review-decisions-round-3.md`)。

**Round 3 で得られた最重要の発見**: `queue:listen` 配下ではジョブ側 `$timeout` が
**まったく効かない** (`Listener` が子に `--timeout` を渡さず、`runNextJob()` が
SIGALRM を張らない)。dev / bug-hunt のジョブには現在**上限が 1 つも存在しない**。
これは規則 1 が「無条件」である理由の、このリポジトリにおける実例である。
