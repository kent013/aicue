# 概念設計レビュー Round 2

Round 1 の指摘に対する対応マトリクスと、修正後の概念設計を提示する。
Critical 2 件は両方とも「対応する」で処理した。特に 2 件目は指摘を受けて実測した結果、
想定より深刻な事実 (Stripe SDK の 1 呼び出し上限 80 秒 × ジョブ 1 本で 4〜5 呼び出し) が出てきたため、
`database` 接続だけは修正の向きを「ワーカーを下げる」から「retry_after を上げる」へ反転させた。

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 1

## [Critical] 接続決定経路が `onConnection('リテラル')` 限定で網羅性が足りない (観点 3)

- 判断: **対応する** (指摘は正しい)
- 根拠: 実査すると現状 aicue には `onConnection()` リテラル 4 箇所しか無く
  (`$connection` プロパティ代入 / `viaConnections()` / dispatch 側の `->onConnection()` は 0 件)、
  「今の実装は網羅できている」が「**将来別経路が足されたら黙って検査外に落ちる**」のは事実。
  それは本 feature が閉じようとしている欠落 (a) そのもの。
- 対応内容: 目録 gate に「**接続決定経路の deny-by-default 走査**」を追加する。
  `app/` 全体を対象に `onConnection(` / `viaConnections(` / `viaConnection(` /
  `public .*\$connection` / `$this->connection =` / `Queue::connection(` /
  `->onConnection(` (dispatch 側チェーン) を走査し、**目録に登録された
  「クラス内の `onConnection('リテラル')`」以外の hit はすべて fail** させる。
  非リテラル引数 (`onConnection($x)`) も fail (「実行時に決まる接続は静的検査できない」)。

## [Critical] `database` の worker timeout 60 秒への引き下げの影響評価が不足 (観点 5)

- 判断: **対応する** (指摘は正しく、実測したら想定より深刻だった)
- 根拠: 実測 —
  - Stripe SDK の `CurlClient::DEFAULT_TIMEOUT = 80` (`CURLOPT_TIMEOUT`)、
    `DEFAULT_CONNECT_TIMEOUT = 30`。アプリ側で上書きしていない。
  - `ExecuteAutoRechargeAttemptJob` → `AutoRechargeService::executeAttempt()` →
    `createOrGetStripeCustomer` + `invoices->create` + `invoiceItems->create` +
    `invoices->finalizeInvoice` + `invoices->pay` = **1 ジョブで 4〜5 回の Stripe 呼び出し**。
    最悪 400 秒。**現行の `retry_after = 90` はこの面に対して既に小さすぎる**。
  - つまり 60 秒案は「規則 1 は満たすが実運用で誤 kill が出る」値だった。
- 対応内容: `database` 接続の `retry_after` を **90 → 300 (リテラル化)**、
  worker timeout を **240** にする。あわせて
  - 覆えない最悪ケース (5 呼び出しすべてが 80s 上限に張り付く 400 秒) を設計に明記し、
    その場合の挙動が「240 秒で kill → `$tries=1` で failed → リコンサイルが回収。
    **二重実行にはならない**」ことを示す。
  - 真の worst-case を覆うには Stripe client timeout の上限固定 (既存
    `PromptClientTimeoutInvariantTest` と同型) が要るが、これは課金経路の挙動変更なので
    **後続 TODO 候補**として分離する。
- 補足: `retry_after` を上げてよいのは `database` だけである
  (`database-analysis` / `database-render` は予約 TTL 1800 との連鎖に縛られる。
  `database-media` は 300 のままで 240 に十分)。

## [Warning] 「値を先に決める」比重が強い / 外部 I/O 上限との照合が弱い (観点 2)

- 判断: **対応する**
- 対応内容: 上記の Stripe SDK timeout 実測を「現状」節に組み込み、
  値の根拠を「十分と思われる」から「実測した外部 I/O 上限との関係」に置き換えた。

## [Warning] mprocs の接続集合完全一致は将来「dev では起動しない接続」で過剰に落ちる (観点 3)

- 判断: **対応する (明文化する側を採る)**
- 根拠: exemption 機構を今作るのは思考原則 2 違反 (除外したい接続が現時点で 0 件)。
  Codex の 2 択のうち「アーキテクチャ規約として明文化する」を採る。
- 対応内容: `docs/architecture.md` に
  「**`driver=database` の接続は dev ワーカーペインを必ず持つ**」を運用契約として明記し、
  gate はその写しであると設計に書く。除外が本当に必要になった時点で
  理由付き目録を足す (今は作らない) と明示。

## [Warning] 本番 supervisor は gate の外なので効果は限定的 (観点 4)

- 判断: **対応する**
- 対応内容: 「本番の二重実行を直接 gate するものではない」を設計本文に明記し、
  `docs/architecture.md` の運用契約に**接続ごとの具体値表**まで載せる。

## [Warning] `queue:listen` と `queue:work` の運用特性の違い (観点 5)

- 判断: **対応する**
- 対応内容: `queue:listen --timeout` は「子 (`queue:work --once`) を kill する天井」であり
  規則 1 における役割は `queue:work --timeout` と同一であることを設計に明記
  (両サブコマンドの既定値はいずれも 60 = `ListenCommand` / `WorkCommand` で実確認)。
  gate は**両サブコマンドを等しくワーカーとして扱う**。

## [Warning] 施策 3 / 4 の変更範囲差 — 失敗理由を規則ごとに分離せよ (観点 6)

- 判断: **対応する**
- 対応内容: テストファイルを規則ごとに 2 本に分け
  (`QueueWorkerLeaseInvariantTest` = 規則 1 / `QueuedJobLeaseInventoryTest` = 規則 2 + 網羅)、
  失敗メッセージの冒頭に「規則 1:」「規則 2:」を付けることを詳細設計で規定する。

## [Warning] `getDefaultProperties()['timeout']` の型崩れ (観点 7)

- 判断: **対応する**
- 対応内容: `int|null` へ正規化する純関数 helper に閉じ、
  非 int / 0 以下は明示的に fail させる規定を詳細設計に書く。

## [Suggestion] 使命への位置づけ (観点 1)

- 判断: **対応する (文言のみ)**
- 対応内容: 「機能改善ではなく信頼性の下支え」であることを冒頭に明記した。

---

## 修正後の概念設計 (全文)

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

- Stripe SDK: `Stripe\HttpClient\CurlClient::DEFAULT_TIMEOUT = 80`
  (`CURLOPT_TIMEOUT` = 接続込みの総時間)、`DEFAULT_CONNECT_TIMEOUT = 30`。
  **アプリ側で上書きしていない** (`config/cashier.php` / `config/services.php` に
  timeout 設定なし、`CashierStripeGateway` / `CashierAutoRechargeGateway` も
  `Cashier::stripe()` をそのまま使う)。
- `ExecuteAutoRechargeAttemptJob` → `AutoRechargeService::executeAttempt()` の
  Stripe 呼び出し数: `createOrGetStripeCustomer` (0〜1) + `invoices->create` +
  `invoiceItems->create` + `invoices->finalizeInvoice` + `invoices->pay` = **4〜5 回**。
  全部が SDK 上限に張り付く最悪ケースは **約 400 秒**。

→ **現行の `database.retry_after = 90` は、この接続に載っているジョブに対して
既に小さすぎる**。ワーカー側を Laravel 既定の 60 に揃えるだけでは、規則 1 は満たしても
実運用で誤 kill が出る。この接続だけは `retry_after` を上げる側が正しい (後述)。

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
| 0 | `database` 接続の `retry_after` を 90 → 300 のリテラルへ (env 上書きを畳む) | 値の修正 | `config/queue.php` |
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
- **`database`: `retry_after` を上げる (90 → 300)**。実測どおり 90 は
  `ExecuteAutoRechargeAttemptJob` (Stripe 4〜5 呼び出し / SDK 上限 80s) に対して小さすぎる。
  この接続の `retry_after` は**どの連鎖にも属していない** (予約 TTL とも budget とも無関係) ので、
  上げても既存の不変条件を 1 つも壊さない。

### `database.retry_after` を env 上書き可からリテラルへ

現行は `(int) env('DB_QUEUE_RETRY_AFTER', 90)` で、`.env.example` にも記載が無い。
静的 gate は config を**テスト環境の値**で読むため、env 上書きが残っていると
「gate は通るが本番の実値は別」という状態を作れてしまう
(= gate が嘘をつく)。他 3 接続は既にリテラルなので、`database` も**リテラル `300`** に揃える
(AGENTS.md 思考原則 3「後方互換の並走を残さない」)。`DB_QUEUE_RETRY_AFTER` は
`.env.example` にも `.env.testing` にも無いので、削除しても壊れる箇所は無い (実査済み)。

### 採用する値と根拠

安全余白 **60 秒**を共通に置く (`retry_after` − ワーカー timeout = 60)。
既存の解析 budget が使う S=90 (worker alarm → `run()` 入口 P + タイマー精度 + シグナル配送) と
同系の余白で、ワーカーが子プロセスを kill してから DB の `reserved_at` が解放されるまでの
猶予に充てる。

| 接続 | `retry_after` | ワーカー timeout | 関係 | 変更 |
|---|---|---|---|---|
| `database` | **300** (90 から) | **240** | 240 < 300 | config + ワーカー |
| `database-analysis` | 1680 (据置) | **1620** | 1560 (job) < 1620 < 1680 | ワーカーのみ |
| `database-render` | 1680 (据置) | **1620** | 1500 (job) < 1620 < 1680 | ワーカーのみ |
| `database-media` | 300 (据置) | **240** | 240 < 300 | ワーカーのみ |

`database-analysis` / `database-render` の 1620 は「ジョブ側 `$timeout` (1560/1500) より上」に
置いてある。これによりジョブは従来どおり自分の pcntl alarm で `failed()` を通って終わり、
既存の finalize 予算 (M₁=30s) と terminal transaction の契約が変わらない
(ワーカー側が先に効くと finalize が走らず、解析の状態が `running` のまま残る)。
**この上下関係は運用上の意図であって不変条件として固定しない** (規則 1 と規則 2 のあいだに
大小関係を課さない、という標準形 v1 の裁定に従う)。

### 覆えない最悪ケースを明示する (誠実な限界)

`database` の 240/300 は、`ExecuteAutoRechargeAttemptJob` の Stripe 呼び出しが
**全部 SDK 上限 80 秒に張り付く最悪ケース (約 400 秒) を覆っていない**。
その場合の挙動は「ワーカーが 240 秒で kill → `$tries = 1` なので failed →
リコンサイル (i) が回収」であり、**二重実行にはならない** (規則 1 が守られているため)。
つまり本設計は「二重実行を防ぐ」目的を満たしたうえで、
残る失敗モードを「静かな二重実行」から「明示的な failed + 既存の回収経路」へ移す。

真の worst-case を覆うには **Stripe client timeout の上限固定**
(既存 `PromptClientTimeoutInvariantTest` と同型の pin) が要るが、これは課金経路の
挙動変更なので本 feature に混ぜない。**後続 TODO 候補**として詳細設計に記す。

### mprocs `queue` ペインの接続名を明示する

現状 `php artisan queue:listen --tries=1 --timeout=0` は接続を書いていないため、
**どの接続に対する規則 1 なのかが静的に決まらない** (`QUEUE_CONNECTION` env 次第)。
`php artisan queue:listen database --tries=1 --timeout=240` と**接続名を明示**する。
1 トークンの追加で env 依存が消え、静的 gate が「この行はこの `retry_after` と比較すればよい」と
確定できる。他 3 ペインは既に接続名を書いており、書式が揃う副次効果もある。

### `queue:listen` と `queue:work` は規則 1 において等価に扱う

mprocs / bug-hunt はいずれも `queue:listen` (毎回 framework を起動し直すスーパーバイザ構成)、
本番運用契約は `queue:work` (常駐) である。`queue:listen --timeout` は
**子 (`queue:work --once`) を kill する天井**であり、規則 1 における役割は
`queue:work --timeout` と同一である (既定値もどちらも 60 =
`Illuminate\Queue\Console\ListenCommand` / `WorkCommand` で実確認)。
gate は**両サブコマンドを等しくワーカーとして扱う**ので、将来 `queue:work` へ
切り替えても検査の意味が変わらない。

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

- **走査**: `app/` 配下の全 PHP から `Illuminate\Contracts\Queue\ShouldQueue` を実装するクラスを
  Reflection で列挙する (現在 18 クラス)。
- **目録**: `QUEUED_JOB_LEASE_INVENTORY` に `クラス => 接続` を宣言する。
  走査結果と目録の**対称差が空**であることを要求する (deny-by-default。
  新しいジョブを足すと必ず落ち、実装者に接続の宣言を強制する)。
- **接続決定経路の deny-by-default 走査** (Codex 概念 R1 Critical 反映):
  Laravel で接続が決まる経路は `onConnection()` だけではない
  (`public $connection` プロパティ / `$this->connection = ` 代入 /
  Notification の `viaConnections()` / dispatch 側チェーン `Job::dispatch()->onConnection()` /
  `Queue::connection()`)。実査では aicue に現存するのは `onConnection('リテラル')` 4 箇所のみだが、
  **将来別経路が足されたら黙って検査外に落ちる**のが本 feature の欠落 (a) そのものである。
  そこで `app/` 全体を対象に上記パターン群を走査し、
  **「目録登録済みクラス内の `onConnection('リテラル')`」以外の hit をすべて fail** させる。
  非リテラル引数 (`onConnection($x)`) も fail (「実行時に決まる接続は静的検査できない ——
  実行時 fail-fast の対象として個別に扱え」というメッセージ)。
- **宣言の裏取り**: 各クラスのソースから `onConnection('リテラル')` を抽出し、
  目録の宣言と一致することを要求する。
  `onConnection` を持たないクラスは目録で `null` (= 既定接続) と宣言する。
- **規則 2 の検査**: `ReflectionClass::getDefaultProperties()['timeout']` で
  インスタンス化せずに `$timeout` を読む。戻り値は
  **`int|null` へ正規化する純関数 helper に閉じ込め**、非 int / 0 以下は明示的に fail させる
  (PHPStan level 10 で `mixed` が漏れないようにする)。判定は:
  - 接続が明示されている entry: `$timeout < retry_after(接続)` を要求
  - 既定接続 (`null`) の entry: **`$timeout` の宣言自体を禁止**する。
    既定接続は `QUEUE_CONNECTION` env 次第でどの接続にも化けるため、静的に
    `retry_after` と比較できない。`$timeout` が要るなら `onConnection()` で接続を pin せよ、
    というメッセージで落とす (現状 12 クラスすべて `$timeout` 未宣言なので通る)。
  - `$timeout` 未宣言: 規則 2 は空に成立。上限はワーカー側 (規則 1) が担保する。

**2 本の規則のあいだに大小関係は課さない** (標準形 v1 / Codex 合議で確定済み。
ジョブ側 `$timeout` はワーカー起動時の値に優先するため、3 項連鎖は公式文面から導けない。
中央の関係は Horizon 固有の事情で、本家系は Horizon 未導入)。
本設計で `1560 < 1620` を選んだのは**運用上の意図**であって不変条件として固定しない。

---

## 代替案と却下理由

| 案 | 却下理由 |
|---|---|
| **`database-analysis` / `database-render` の `retry_after` を 1800+ へ引き上げてワーカー 1800 を維持** | 予約 TTL 1800 を突き抜け、`retry_after < 予約 TTL ≤ stale 閾値` の既存連鎖を壊す。裁定の注意書きが名指しで禁じている |
| **`database` も `retry_after` 90 据置でワーカーを 60 に下げる** | 規則 1 は満たすが、Stripe SDK の 1 呼び出し上限が 80 秒 (実測) なので**正常な処理が誤 kill される**。「仕組みが機能していない段階で値を弄るな」ではなく、実測に基づいて `retry_after` 側を上げるのが正しい向き |
| **Stripe client timeout を本 feature で同時に絞る** | 課金経路の挙動変更 (遅い成功呼び出しの client 側中断) を infra タスクに混ぜることになる。分離して後続 TODO 候補にする |
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
| **Stripe client timeout の上限固定** | 課金経路の挙動変更。`PromptClientTimeoutInvariantTest` と同型の pin が要る。**後続 TODO 候補**として詳細設計に記す (本 feature の完了条件には含めない) |
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
| bug-hunt の実行配線が壊れていないこと | `scripts/bug-hunt-shard.sh self-test` | 全ケース pass (実資源に触れない) |
| 型安全 | `composer phpstan` | level 10 green |
| 全体 | `composer test` / `vendor/bin/pint --test` | green |

**手動確認** (値が実運用に耐えるか): `composer dev` で mprocs を起動し、
`queue-analysis` ペインで解析ジョブを 1 本流して**ワーカー timeout 1620 に達する前に
ジョブ側 1560 の alarm で終わる**ことをログで確認する (順序が逆だと finalize が走らない)。
