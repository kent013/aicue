# Round 3: 対応マトリクスと修正内容

Round 2 の指摘への判断と修正です。Critical はご指摘のとおり Round 1 の対応が誤りだったため、方針を変えました。

# 対応マトリクス: conceptual-review Round 2

## [Critical] `AutoRechargeTriggerJob` の unique lock 残留は**ネスト経路で未解決**

- 判断: **対応する (指摘は完全に正しい。Round 1 の対応は誤りだった)**
- 根拠: `reserve()` は `AnalysisPipeline::startJob` / `RenderPipeline::startJob` の
  `DB::transaction` の内側から呼ばれる。「reserve の内側 tx を抜けた直後」は
  **外側 tx の内側**であり、`PendingDispatch` (vendor:218) の unique lock 取得は起きる。
  外側 rollback で jobs 行は消えるが lock は `uniqueFor=30` 秒残る。Round 1 の
  「除外にすることで回避済み」は誤りだった。
- 対応内容: Codex の 3 択のうち **3 番目 (`ShouldBeUnique` を外す)** を採用し、
  あわせて `AutoRechargeTriggerJob` を **AG-127 除外から外して確定 1 の適用対象へ戻す**
  (tx 内 dispatch)。根拠:
  - AGENTS.md ドメイン固有規約 6 が「**入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
    best-effort であり保証を担わない。結果の一回性は永続状態遷移が担う**」と既に規定している
  - `JobExecutionDedupInventoryTest` は既にこの job を
    `JobDedupExemption::GuardedByDownstreamConstraint` で登録しており、その根拠文が
    「起票先の partial unique が『org に pending は 1 つ』を DB で拒否するため…
    **ジョブ自身は課金も状態確定も行わない**」と書いている。つまり
    **この job の一回性を `ShouldBeUnique` が担っていないことは既に裁定済み**である
  - `AutoRechargeTriggerJob` 自身の docblock も
    「重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する」と書く
  - よって `ShouldBeUnique` は **保証を担わない機構**であり、思考原則 2
    (今必要なものだけ作る) から撤去が正しい。撤去すればネスト深さに依らず問題が消え、
    条件分岐 (Codex の 1 番目の案 = `DB::transactionLevel() > 0` なら dispatch しない) のような
    「黙って投入しない経路」も作らずに済む
  - Codex の 2 番目の案 (最外層まで dispatch intent を返す) は `reserve()` の戻り値契約を
    変え、全呼び出し元へ波及する。得るものに対して過大 (思考原則 2)
- **波及**: `JobExclusionOrderingInvariantTest` の 2 テスト
  (`uniqueFor は既定接続の retry_after を下回る` / `uniqueFor は正の値である`) が
  `AutoRechargeTriggerJob->uniqueFor` を参照している。**削除ではなく反転**で扱う
  (M8 の 5 件目として追加。旧主張・旧目的・新主張・新前提・前提を守る機構・反転根拠の 6 行 docblock)。
  `JobExecutionDedupInventoryTest` の登録は根拠文が `ShouldBeUnique` に言及していないため変更不要。
- コスト (受容する): trigger job の投入量が「org あたり 30 秒に高々 1 件」から
  「reserve 1 回につき 1 件」へ増える。job 本体は `exists()` 1 本で早期 return する薄い箱であり、
  reserve は人間の操作 (解析/レンダ開始) 起点なので実運用上の増分は無視できる。

## [Warning] 低残高通知の「ロック保持時間が伸びない」はネスト経路で不成立

- 判断: **対応する (指摘は正しい)**
- 根拠: 同上。ネスト時は外側 tx がロックを保持したまま通知 INSERT が走る。
- 対応内容: §3 の記述を「**最外層の `reserve()` では伸びない。ネスト時は外側 tx の
  ロック保持中に実行される**」へ訂正した。
- **さらに重要な帰結を自分で見つけたので設計に反映した**: `DB::afterCommit` は
  ネスト時に「最外層 commit 後 = 全 tx の外」で実行していた。撤去すると
  **通知 INSERT の失敗が業務 tx を巻き込みうる**ようになり、これは AG-127 の
  「付随的副作用の失敗で業務 tx を巻き戻さない」に正面から反する。
  §3 に「AG-127 の保証がネスト時に狭まること」を明示し、緩和として
  (a) 通知は `reserve()` の tx を抜けた**最後**に行う、
  (b) `safely()` が握るのは**アプリケーション層の例外**であり、そこは behavioral test で固定する、
  (c) SQL 層の失敗 (PostgreSQL の tx abort) は fail-closed に倒れることを §8 に明記する、
  の 3 点を書いた。

## [Warning] PostgreSQL abort を behavioral test で固定せよ

- 判断: **一部対応・一部反論**
- 根拠 (反論): tx 内で SQL 失敗を意図的に起こすには DDL (制約追加/トリガ) をテスト内で
  流す必要があり、`RefreshDatabase` + `--parallel` のレーンに DDL を持ち込む機構を
  新設することになる。得られるのは「PostgreSQL の仕様どおりに abort する」という
  **PostgreSQL の仕様の再確認**であって、本アプリの不変条件ではない (思考原則 2)。
- 対応内容 (受容部分): 現実的な失敗クラス (アプリケーション層の例外) については
  behavioral test を置く — `NotificationCenterService` をモックして throw させ、
  **reserve が成功し予約行が残る**ことを固定する (= AG-127 の性質を実際に検査する)。
  SQL 層の abort は §8 の「保証しないもの」に明記する。

## [Warning] R-10 (sync レーンでの業務クロージャ再実行)

- 判断: **対応する (ただし Codex の想定より状況は良い。実測で否定できた)**
- 根拠: `Connection::handleCommitTransactionException()` が再実行 (return) するのは
  `causedByConcurrencyError($e) && $currentAttempt < $maxAttempts` のときのみ。
  `DB::transaction($callback)` の既定 `$attempts = 1` では `1 < 1` が偽なので**常に rethrow** する。
  **`app/` 配下で `DB::transaction()` の第 2 引数 (attempts) を使っている箇所は 0 件**
  (`grep -rnE 'DB::transaction\([^)]*\},\s*[0-9]' app/` で 0 hit / `DB::transaction(` の
  出現は 35 ファイル)。よって再実行は起きない。
- 対応内容: §6 の R-10 を「**前提: `DB::transaction` の attempts は全経路で既定の 1。
  よって再実行は起きない。attempts>1 を導入したら sync レーンでのみ再実行が起きうる**」
  へ書き換え、§8 にも前提として記載した。

## [Warning] §9 の「6 本消える」が列挙と対応していない

- 判断: **対応する**
- 対応内容: §9 を名称列挙 + 3 分類 (「commit 済み・未投入の窓を除去」/
  「reconcile まで残る」/「at-most-once として受容」) の表に書き換えた。件数表現をやめた
  (Codex の言う「件数表現のドリフト」を構造的に避けるため、数を書かない)。

## [Warning] §5-1 の負のコントロールでは「母集団から一部が脱落する故障」を検出できない

- 判断: **対応する (良い指摘)**
- 対応内容: §5-1 を次の 3 層に書き換えた。
  1. **経路統合の負のコントロール**: 検出器を「ファイルパス配列 / クラス名配列を受ける純関数」
     として設計し、テストは **fixture ディレクトリツリー**を作って
     「列挙 → 読み込み → 検出」の**全経路**を通す (検出関数だけを直接叩かない)
  2. **母集団の完全性アンカー**: 本番母集団に既知の代表要素が含まれることを assert する
     (`app/Services/Billing/TicketLedgerService.php` ∈ `appPhpFiles()` /
     `RunManualAnalysis::class` ∈ `shouldQueueClasses()` /
     `database-analysis` ∈ `config('queue.connections')` のキー集合)。
     特定ディレクトリだけ脱落する故障をこれで検出する
  3. **既存 inventory との接続**: `shouldQueueClasses()` の完全性は
     `QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` が
     **対称差が空**の形で既に deny-by-default 固定している。その契約を docblock で参照し、
     本 gate では二重実装しない (ブリーフ申し送り (d) と同じ理由)
  4. **D4 は全接続集合で検証**: 「`after_commit=true` を持ってよいのは `sync` **だけ**」を
     `queue.connections` 全件に対して評価する (既定接続だけを見ない)

## [Suggestion] AG-127 の判定基準を 6 条件へ厳格化し enum + premise test に落とせ

- 判断: **反論する (基準そのものは受け入れるが、機構は作らない)**
- 根拠: 上の Critical 対応で `AutoRechargeTriggerJob` を確定 1 の適用対象へ戻した結果、
  **確定 1 の母集団 (業務 tx の内側から投入される queue dispatch) における AG-127 除外は 0 件**
  になった。残るのは
  - 低残高通知 = `ShouldQueue` ではない (同期 DB 書き込み) ため確定 1 の母集団外
  - `CreateInquiryAction` の Mail 2 本 = **そもそも業務 tx が無い**
    (同ファイル docblock「単一 save のため明示 `DB::transaction` は使わない」を Read で確認済み)
  - `BillingNotificationDispatcher` 経由の請求通知 = 呼び出し元がすべて業務 tx の外
  であり、いずれも「除外」ではなく「**元から tx の外**」である。
  case を 1 つも持たない exemption enum と premise test を作るのは、
  思考原則 2 (今必要なものだけ作る) に反する死んだ機構である。
- 対応内容: §3 を「**AG-127 の除外は 0 件。除外機構 (enum) は作らない**」と書き換え、
  「除外が必要になったら gate が落ちるので、そのときに設計し直す」= deny-by-default の
  最も強い形 (allow-list なしの deny) であることを gate の docblock に書く方針にした。
  Codex の 6 条件は「将来除外を作るときの判断チェックリスト」として §3 に残した。

## [Suggestion] §11-2 の順序を施策ごとに「テスト追加・赤化確認 → 実装」にせよ

- 判断: **対応する**
- 対応内容: §11-2 を施策単位の「赤 → 実装 → 緑」の並びへ書き換えた。

## [Suggestion] violations を value object にせよ

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: guard は `list<QueueDispatchAtomicityViolation>` (readonly DTO: 規則 ID enum +
  接続名 + 実値 + メッセージ) を返し、`enforce()` がメッセージへ写像する設計にする。
  詳細設計 §M6 に書く。

---

## 修正後の概念設計 (全文)

# 概念設計: queue-dispatch-atomicity (キュー投入の業務tx内移設と起動時検査)

> 一次入力: `devnotes/20260809-0027-queue-dispatch-atomicity/recon-brief.md` (2026-08-08 実査 / main = c71061e)
> 本設計は実査ブリーフの記述を鵜呑みにせず、現行コードを直接 Read して再確認した上で書いている
> (確認箇所は §7「実査の再確認結果」)。

## 1. 背景・課題

### 1-1. 実害 (台帳の想定より大きい)

業務ジョブの投入がすべて業務トランザクションの **外** にある。

```php
// app/Services/Manual/AnalysisJobService.php:99-102
});                                   // ← ここで COMMIT
// commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
RunManualAnalysis::dispatch($job->id);  // ← ここまでの間にプロセスが落ちると「保存済み・未投入」
```

commit と dispatch の間で落ちると、`analysis_jobs` / `render_jobs` 行は `queued` で永続化され、
キューには 1 件も載らない。回収役の `recoverStale()` は **再投入しない**:

- `AnalysisJobService::recoverStale()` (:171-201) は queued 閾値超過を `failJob()` へ倒す
- `RenderJobService::recoverStale()` (:266-300) も同様。docblock に
  「queued: created_at が render_queued_stale_after_minutes (10 分) 超過 (dispatch 喪失。
  render は enqueue 時点で編集を止めるため短 SLA で fail させる)」と明記されている

つまり **dispatch 喪失 = 「10 分待たされた末に失敗表示」+ ユーザーの手動再実行が要る**。
使命 (「思考ゼロ・編集ゼロ」で現場作業者が動画マニュアルを作れる) に対して、
現場作業者に「もう一度押してください」を強いる欠落であり、自動前進しない点が本質的な問題である。

同じ窓は以下にもある:

| 経路 | 位置 | 落ちたときに残るもの |
|---|---|---|
| `AnalysisJobService::trigger` | :101-102 | manual=analyzing / job=queued・未投入 → 10 分後に失敗 |
| `RenderJobService::trigger` | :112-113 | manual=rendering / job=queued・未投入 → 10 分後に失敗 |
| `RenderJobService::triggerPreview` | :162 | preview job=queued・未投入 → 10 分後に失敗 |
| `RenderPipeline::finalize` | :338-341 | 旧世代 output が S3 に残置 (reconcileOutputs が回収する) |
| `CaptureTakeService::delete` | :115 | S3 オブジェクトの孤児 (回収機構なし) |
| `VideoManualService::delete` | :221 | S3 オブジェクトの孤児 (回収機構なし) |
| `StripeWebhookProcessor` | :623 / :695 | `pm_reuse_dispatched_at` 打刻済み・PM 流用未実行 (「自動的に有効になります」と表示して実行されない) |
| `AutoRechargeTriggerJob::handle` | (Job 内) | attempt=pending・実行未投入 (reconcile (v) が 15 分周期で回収する) |

### 1-2. 宣言的な迂回が grep に映らない

`app/Notifications/Billing/` の 6 クラス (PaymentFailed / RenewalReminder / AutoRechargeEnabled /
Disabled / Failed / ActionRequired) が `Illuminate\Contracts\Queue\ShouldQueueAfterCommit` を
implement している。`grep afterCommit` はこの interface 名に一致するが、
「`->afterCommit()` を探す」つもりの検索では見落とされる。宣言的迂回を機械検査で押さえないと、
今回直しても再発する。

### 1-3. 起動時検査が無い

キュー投入を tx 内へ移す原子性は「**キューの実体が業務 DB と同じトランザクションに乗る**」ことが
前提である。この前提は config と env で簡単に崩れる:

- `config/queue.php` の `connection` は `env('DB_QUEUE_CONNECTION')`。運用が別 DB を指すと
  jobs 行は別 tx になり、**テストは全部緑のまま**原子性だけ消える
- `driver` が `redis` / `sqs` に変わると同様
- `after_commit => true` が入ると tx 内 dispatch が commit 後へ倒れ、原子性が消える

`app/Support/ProductionEnvGuard.php` に queue の検査項目は 1 件も無い (実確認: `grep -i queue` で 0 hit)。

## 2. 改善アイデア

台帳裁定 AG-114 の確定 1・2 を、AG-126 (適用除外ゼロの 0 件 pin) と AG-127 (付随的副作用の除外基準)
の線引き込みで aicue に入れる。専用機構 (outbox 表・経路一本化・所有権取得) は作らない
(AGENTS.md 思考原則 2「今必要なものだけ作る」)。

- **確定 1**: キュー投入を業務トランザクションの中で行い、`afterCommit` 依存を廃す
- **確定 2**: 原子性の前提 (driver / キュー DB 接続の同一性 / after_commit) を起動時に fail-closed 検査する
- **AG-126**: 4 種検出 (`->afterCommit()` / `DB::afterCommit` / `ShouldQueueAfterCommit` /
  config の `after_commit => true`) の deny-by-default 目録型 gate を置き、**残存 0 件で pin** する
- **AG-127**: 付随的副作用 (通知等) は除外できる。ただし **除外の形は「tx 外 dispatch のまま +
  失敗を観測へ」であって、afterCommit の温存ではない**

## 3. 設計で最初に決めるべき論点への結論

### 論点: `config/queue.php` の `sync` 接続に `after_commit => true` を入れるか

**結論: 入れる。** これが本設計の前提であり、前回見送りの実体を解く唯一の点である。

#### 根拠 (vendor 実測で確認済み)

1. `Illuminate\Queue\SyncQueue::push()` (vendor:159-184) は `shouldDispatchAfterCommit($job)` が
   真かつ `db.transactions` が bind 済みなら `addCallback(fn () => $this->executeJob(...))` へ倒す。
   **sync driver は after_commit を尊重する**。
2. `Illuminate\Queue\Queue::shouldDispatchAfterCommit()` (vendor:408-419) は
   job 個別の `$afterCommit` → `ShouldQueueAfterCommit` → **接続 config の
   `dispatchAfterCommit`** の順で解決する。`SyncQueue::__construct($dispatchAfterCommit)` に
   `config('queue.connections.sync.after_commit')` が渡る。
3. テストレーンでは `RefreshDatabase` が
   `Illuminate\Foundation\Testing\DatabaseTransactionsManager` を `db.transactions` に差し込む
   (vendor `RefreshDatabase.php:133`)。この testing 版は
   - `callbackApplicableTransactions()` = ラッパ tx (level 1) を **skip** する
   - `afterCommitCallbacksShouldBeExecuted($level)` = `$level === 1` (level 1 を root 扱い)

   よって「業務 `DB::transaction()` (level 2) の commit 時に、テスト tx の内側でインライン実行」
   になる。**本番の「commit 後に worker が拾う」と順序意味論が一致する**。

#### 入れないと何が起きるか (前回見送りの実体)

sync は `after_commit` を見ないまま `executeJob()` を即時実行する。すると
`RunManualAnalysis` が `AnalysisJobService::trigger()` の tx の **内側** で走り、
`AnalysisPipeline::startJob()` の `lockForUpdate() + status===queued` guard が
**自分自身が保持しているロックの下で成立**する。finalize の terminal tx も外側 tx の
savepoint に化け、`ScenarioWritePathInventoryTest` が守っている共有ロック規約の意味論が壊れる。

#### レーンごとの実行意味論 (設計として確定させる)

| レーン | 接続 | tx 内 dispatch の意味論 |
|---|---|---|
| 本番 / dev (`QUEUE_CONNECTION=database`) | `database*` (`after_commit=false`) | jobs 行が業務 tx に乗る。commit で可視化 → worker が拾う。rollback で jobs 行ごと消える |
| テスト (`phpunit.xml:55` / `phpunit.browser.xml:46` が `sync` を force) | `sync` (`after_commit=true`) | 業務 tx の commit 後にインライン実行。rollback なら実行されない |
| `Queue::fake()` を張ったテスト | (fake) | **tx 状態を完全に無視して即時記録**する |

3 行目が最重要の帰結である。`QueueFake::push()` (vendor:584-607) は `enqueueUsing` を通らず
`$this->jobs[...][] = ...` へ直接積む。したがって:

> **`Queue::fake()` を使ったアサーションでは、原子性 (tx 内投入 / rollback 時の非投入) を
> 一切検証できない。** 既存の `tests/Feature/Billing/BillingCustomerSynchronizerTest.php:47-50` の
> docblock は既にこの落とし穴を警告している。

よって本設計では、**原子性の behavioral 検証は `Queue::fake()` を使わず、
`queue.default='database'` を明示 set した上で実 `jobs` テーブルを観測する**方式に統一する (§5-3)。

### 論点: AG-127 の除外対象の線引き

**結論: 確定 1 の母集団における AG-127 の除外は 0 件。除外機構 (exemption enum) は作らない。**

確定 1 の母集団は「**業務トランザクションの内側から投入される queue dispatch**」である。
実コードを当たった結果、その母集団に属するものはすべて tx 内へ移せる。

| 対象 | 確定 1 の母集団か | 扱い |
|---|---|---|
| `RunManualAnalysis` / `RunManualRender` x2 / `DeleteRenderOutputsJob` / `DeleteTakeObjectsJob` x2 | Yes | tx 内へ移設 |
| `SyncBillingCustomerDetails` | Yes (呼び出し元 `RenameOrganizationAction` / `UpdateBillingContactAction` が tx 内) | `->afterCommit()` を撤去して tx 内 dispatch へ |
| `AutoRechargeTriggerJob` | Yes (`reserve()` の tx 内) | `DB::afterCommit` を撤去して tx 内 dispatch へ。**あわせて `ShouldBeUnique` を撤去する** (下記) |
| `ExecuteAutoRechargeAttemptJob` | Yes (`maybeCreateAttempt` の tx 直後) | 起票と同一 tx へ集約 |
| `ReuseSubscriptionPaymentMethodJob` / `SetDefaultPaymentMethodJob` | Yes (save 直後) | save と同一 tx で括る |
| `TicketLedgerService` の低残高通知 | **No** — `TicketBalanceLowNotification` は `AppNotification` 継承で `ShouldQueue` 非実装 = **queue dispatch ではない**同期 DB 書き込み | `DB::afterCommit` は撤去 (D2 の 0 件 pin 対象)。`reserve()` の tx を抜けた直後の素の呼び出しにする |
| `CreateInquiryAction` の Mail 2 本 | **No** — **業務 tx が存在しない** (同ファイル docblock「単一 save のため明示 `DB::transaction` は使わない (不要な複雑化を避ける)」を Read で確認) | 変更しない (先例として docs から参照するのみ) |
| `BillingNotificationDispatcher` 経由の請求通知 6 種 | **No** — 呼び出し元 (`StripeWebhookProcessor::handleInvoicePaymentFailed` / `AutoRechargeService` の通知群 / `SendBillingReminders`) がすべて業務 tx の外 | `ShouldQueueAfterCommit` のみ撤去 (実行時は現状すでに no-op。§7 参照) |
| `NotificationCenterService` 経由のアプリ内通知 | **No** — queue dispatch ではない | 変更しない |

つまり **「除外」ではなく「元から tx の外」**であり、AG-127 の除外基準を適用した結果は 0 件になる。
したがって **case を 1 つも持たない exemption enum と premise test は作らない** (思考原則 2)。
gate は allow-list を持たない最も強い deny-by-default になる。将来除外が必要になったら
gate が落ちるので、そのときに設計し直す — この方針を gate の docblock に明記する。

> **将来除外を作るときの判断チェックリスト** (Codex Round 2 の提案を採用。今は機械化しない):
> (1) 回収処理が本番で定期実行される運用契約を持つ / (2) 回収条件が dispatch 喪失状態を確実に
> 包含する / (3) 永続状態遷移が結果の一回性を保証する / (4) 回収までの最大遅延が当該機能の
> SLA と整合する / (5) 取りこぼしと回収失敗が観測される / (6) 回収待ち中の状態がユーザー操作や
> 課金を不当に阻害しない。**「cron があるか」だけを基準にしない**
> (15 分待ちを許容する機能が増えると「思考ゼロ」が後退するため)。

**`AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去する根拠**

`ShouldBeUnique` を残したまま tx 内 dispatch にすると、`UniqueLock` は `PendingDispatch`
(vendor:218) の **dispatch 呼び出し時**に取得され、rollback 時の解放は
`Queue::enqueueUsing` の afterCommit 経路 (vendor:368-374) でしか行われないため、
rollback しても `uniqueFor=30` 秒の抑止が残る。しかもこれは
**`reserve()` の外へ出しても解決しない** — `reserve()` は `AnalysisPipeline::startJob` /
`RenderPipeline::startJob` の tx の内側から呼ばれるため、「reserve の tx を抜けた直後」は
依然として外側 tx の内側だからである (Codex Round 2 の Critical。指摘どおり)。

撤去してよい根拠は、この job の一回性を `ShouldBeUnique` が担っていないことが
**既に本リポジトリで裁定済み**である点にある:

- AGENTS.md ドメイン固有規約 6: 「入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
  **best-effort であり保証を担わない**。結果の一回性は永続状態遷移が担う」
- `JobExecutionDedupInventoryTest` の登録: `JobDedupExemption::GuardedByDownstreamConstraint` +
  根拠文「起票先の partial unique が『org に pending は 1 つ』を DB で拒否するため、
  重複配送は maybeCreateAttempt の pending 検査か unique violation で no-op に収束する。
  **ジョブ自身は課金も状態確定も行わない**」
- `AutoRechargeTriggerJob` 自身の docblock: 「重複 dispatch は maybeCreateAttempt の
  pending 検査 / DB partial unique が吸収する」

代償は「trigger job の投入量が org あたり 30 秒に高々 1 件 → reserve 1 回につき 1 件」に増えること。
job 本体は `exists()` 1 本で早期 return する薄い箱で、reserve は人間の操作
(解析/レンダ開始) 起点なので実運用上の増分は無視できる。
波及として `JobExclusionOrderingInvariantTest` の `uniqueFor` 参照 2 テストを
**削除ではなく反転**で扱う (M8)。

**低残高通知を tx の外へ出すことの限界 (AG-127 の保証がネスト時に狭まる)**

`DB::afterCommit` はネスト時に「**最外層 commit 後 = 全 tx の外**」で実行していた。
撤去して素の呼び出しにすると:

- `reserve()` が最外層のとき: 内側 tx の commit 後に実行される。ロック保持時間は伸びない
- `reserve()` が `startJob` の tx にネストされているとき: **外側 tx がロックを保持したまま**
  通知 INSERT が走る。ロック保持時間は伸び、**通知の失敗が業務 tx を巻き込みうる**
  (PostgreSQL では文エラーが tx 全体を abort させるため、`safely()` が握っても commit で落ちる)

これは AG-127 の「付随的副作用の失敗で業務 tx を巻き戻さない」が**ネスト時に狭まる**ことを意味する。
緩和と誠実な記述:

1. 通知は `reserve()` の tx を**抜けた最後**に置く (tx の内側では実行しない)
2. 現実的な失敗クラス (アプリケーション層の例外) は `safely()` が握る。
   これは behavioral test で固定する (§5-3)
3. SQL 層の失敗による tx abort は **fail-closed に倒れる** (reserve が失敗する)。
   無言で通知だけ消えるより良いと判断し受容する。§8 に明記する

既存 pin (`TicketBalanceLowNotificationTest:104` /「rollback される外側 tx 内の reserve は
通知されない」/ `AutoRechargeTriggerTest:76` /「reserve が rollback したら dispatch されない」) は、
通知行も jobs 行も外側 tx に載って一緒に巻き戻るため **維持される**
(根拠が afterCommit から「外側 tx への相乗り」へ変わる = M8 の反転対象)。


## 4. 実装方針 (概要)

| # | 施策 | 対象 |
|---|---|---|
| M1 | sync 接続の実行意味論の確定 | `config/queue.php` (`sync` に `after_commit => true`) |
| M2 | 業務ジョブ dispatch の tx 内移設 | `AnalysisJobService` / `RenderJobService` ×2 / `RenderPipeline` / `CaptureTakeService` / `VideoManualService` |
| M3 | Billing の afterCommit 撤去 | `BillingCustomerSynchronizer` (tx 内へ) / `TicketLedgerService` ×2 (trigger job = tx 内へ + `ShouldBeUnique` 撤去 / 低残高通知 = tx を抜けた直後の素の呼び出しへ) / `AutoRechargeService` (起票と同一 tx へ集約) |
| M4 | webhook の save+dispatch を同一 tx で括る | `StripeWebhookProcessor` :623 / :695 (:477 は対象外・根拠明記) |
| M5 | 宣言的迂回の撤去 | `app/Notifications/Billing/` 6 クラスの `ShouldQueueAfterCommit` |
| M6 | 起動時 fail-closed 検査 | 新規 `app/Support/QueueDispatchAtomicityGuard.php` + `AppServiceProvider::boot()` 配線 |
| M7 | deny-by-default 目録型 gate (0 件 pin + 負のコントロール) | 新規 `tests/Architecture/QueueDispatchAtomicityInventoryTest.php` |
| M8 | 既存 5 契約の**反転** (削除ではない) | `BillingSyncDispatchInvariantTest` / `BillingCustomerSynchronizerTest` / `AutoRechargeTriggerTest` / `TicketBalanceLowNotificationTest` / `JobExclusionOrderingInvariantTest` (`uniqueFor` 参照 2 テスト) |
| M9 | 原子性の behavioral 検証 | 新規 Feature テスト (実 `jobs` テーブル観測) |
| M10 | 契約の文書化 | `docs/architecture.md` / `AGENTS.md` ドメイン固有規約 / `.env.example` |

### M6 の検査項目 (AG-126「使っている機能に応じて選ぶ」に従う)

| 規則 | 内容 | 適用条件 |
|---|---|---|
| R1 | **参照されている**キュー接続の driver が `database` | 既定接続が `sync` のときは skip (sync はインライン実行で jobs 表を持たない) |
| R2 | その接続の `connection` が業務 DB の既定接続と一致 | R1 と同じ |
| R3 | その接続の `after_commit` が **厳密に `false`** (キー欠落も違反 = fail-closed) | R1 と同じ |
| R4 | `sync` 接続の `after_commit` が **厳密に `true`** | 常時 |

- 「参照されている接続」= 既定接続 + `QueuedJobLeaseInventoryTest` が pin している
  `onConnection` リテラル 3 種 (`database-analysis` / `database-render` / `database-media`)。
  **既定接続だけ見ると 3 接続が抜ける** (ブリーフ申し送り (c))
- `beanstalkd` / `sqs` / `redis` / `deferred` / `background` / `failover` は config に定義が
  あるだけで **どの job からも参照されていない**ため検査対象にしない (検査すると必ず落ちる)
- **Bus::batch / Bus::chain の束台帳は検査しない**。`app/` に 0 件 (実確認済み) で、
  `config/queue.php` の `batching.database` は `env('DB_CONNECTION')` 参照で既定 DB と一致済み。
  根拠は guard の docblock に書く (AG-126「使っている機能に応じて選ぶ」)
- 配線は `AppServiceProvider::boot()` に **production 限定でなく常時**。R1〜R3 は
  「既定接続が sync でない」ときだけ効くので、テストレーン (`sync` force) と
  bug-hunt (`env -i` で `QUEUE_CONNECTION` 未定義 → 既定 `database` / `DB_QUEUE_CONNECTION`
  未定義 → 既定 DB) のいずれも通る
- `ProductionEnvGuard` に相乗りしない: 適用範囲が production 限定でないため

## 5. 検査が空振りしないことの保証

### 5-1. 0 件 pin の落とし穴と対策 (負のコントロール)

M7 の期待集合は **空**である。素直に書くと「検出器が壊れていても緑」になる。
しかも 4 種の検出器は **走査母集団が互いに独立**なので、母集団の 0 件検査を 1 つに束ねると
「片方の検出器だけ死んでいる」状態を見逃す。したがって検出器ごとに母集団と負のコントロールを持つ。

| 検出器 | 走査母集団 | 母集団 0 件で fail | 負のコントロール |
|---|---|---|---|
| D1 `->afterCommit(` / `?->afterCommit(` | `QueuedJobPopulation::appPhpFiles()` (app/ の PHP 全件) | ファイル数 0 なら fail | 4 パターンを含む固定ソース文字列 (テスト内 heredoc) を食わせて D1 が 1 件検出することを assert |
| D2 `DB::afterCommit(` (`DB` facade の import 別名も含む) | 同上 | 同上 | 同上 (D2 が 1 件検出) |
| D3 `ShouldQueueAfterCommit` の実装 | `QueuedJobPopulation::shouldQueueClasses()` (実測 18 クラス) | クラス数 0 なら fail | テスト内で定義した `ShouldQueueAfterCommit` 実装ダミークラスをリフレクション判定器に食わせて検出することを assert |
| D4 config の `after_commit => true` (sync 以外) | `config('queue.connections')` の**全接続** | 接続数 0 なら fail | `after_commit=true` を持つ非 sync 接続を含む擬似 connections 配列を判定器に食わせて検出することを assert。あわせて「`after_commit=true` を持ってよいのは `sync` **だけ**」を全接続集合に対して評価する (既定接続だけを見ない) |

- **D3 は文字列走査ではなくリフレクション判定を主とする**:
  `(new ReflectionClass($class))->implementsInterface(ShouldQueueAfterCommit::class)`。
  文字列走査だと「`ShouldQueueAfterCommit` を継承した中間 interface を implement する」
  「親クラス経由で implement される」形を丸ごと見落とす。ブリーフの申し送り
  (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと見えない」) への
  正しい応答は、grep を強化することではなく判定を型システム側へ移すことである
- **母集団の件数は pin しない**。0 件で fail のみ (件数を pin するとクラス追加のたびに
  無関係な失敗が出る = gate が信用されなくなる)

**「検出関数は生きているが、実ファイルが渡されていない」偽グリーンを閉じる 3 層**

母集団が 1 件以上あれば通る形だと、「特定ディレクトリやクラスだけ脱落する」故障を見逃す
(Codex Round 2 の指摘)。したがって次の 3 層を設計に含める。

1. **経路統合の負のコントロール**: 検出器を「ファイルパス配列 / クラス名配列 / config 配列を
   受ける純関数」として `tests/Support/Queue/QueueDispatchDeferralInventory.php` に置き、
   テストは **fixture ディレクトリツリー** (テスト内で作る一時ディレクトリに D1/D2 対象の
   PHP ファイルを書く) を列挙器に食わせ、**「列挙 → 読み込み → 検出」の全経路**を通す。
   検出関数だけを直接叩く形にしない。D3 も fixture 側にダミークラスを置き、
   「クラス列挙 → リフレクション判定」まで通す
2. **母集団の完全性アンカー**: 本番母集団に既知の代表要素が含まれることを assert する
   (`app/Services/Billing/TicketLedgerService.php` ∈ `appPhpFiles()` /
   `RunManualAnalysis::class` ∈ `shouldQueueClasses()` /
   `database-analysis` ∈ `array_keys(config('queue.connections'))`)。
   特定ディレクトリだけ脱落する故障をこれで検出する
3. **既存 inventory との接続 (二重実装しない)**: `shouldQueueClasses()` の完全性は
   `QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` が
   **対称差が空**の形で既に deny-by-default 固定している。本 gate はその契約を docblock で
   参照し、母集団の完全性検査を自前で二重実装しない (ブリーフ申し送り (d) と同じ理由)

- **exact-fit**: 免除目録は **作らない** (§3 のとおり除外 0 件)。したがって
  「期待集合 = 空」を支えるのは上の 3 層と検出器ごとの負のコントロールだけであり、
  この事実を gate の docblock に明記する (「allow-list を持たない deny-by-default である」)

### 5-2. mutation で赤化を確認する手順 (実装時に必ず実施)

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | `QueueDispatchAtomicityGuardTest` (R4) / M9 の順序テスト |
| 2 | `config/queue.php` の `database` の `after_commit` を `true` にする | `QueueDispatchAtomicityGuardTest` (R3) |
| 3 | `config/queue.php` の `database-render` の `connection` を別 DB 名にする | `QueueDispatchAtomicityGuardTest` (R2) |
| 4 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | M9 の「analysis: 業務 tx 内で enqueue される」 |
| 5 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | `QueueDispatchAtomicityInventoryTest` (D1) |
| 6 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | 同 (D3) |
| 7 | `TicketLedgerService` に `DB::afterCommit` を戻す | 同 (D2) |
| 8 | D1〜D4 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (§5-1 の表) が **それぞれ**落ちる |
| 9 | `QueuedJobPopulation::appPhpFiles()` / `::shouldQueueClasses()` を空配列返しにする | D1・D2 側 / D3 側の母集団 0 件 fail が落ちる |
| 10 | `AutoRechargeTriggerJob` の dispatch を `reserve()` の tx の中へ入れる | M9 の「除外 2 件は reserve の tx を抜けてから実行される」 |

各変異は **1 個ずつ入れて 1 回テストし、必ず戻す**。手順と結果 (どのテストがどう落ちたか) は
実装 PR の devnotes に記録する。

### 5-3. behavioral 検証の方式

`Queue::fake()` を使わず、テスト内で `config()->set('queue.default', 'database')` して
実 `jobs` テーブルを観測する。`RefreshDatabase` のラッパ tx の内側なので、
jobs 行も business 行も同じ tx に乗り、テスト終了時に一緒に巻き戻る。

- **正**: `trigger()` 後に `jobs` 行が 1 件あり、`analysis_jobs` 行も 1 件ある
- **原子性**: `trigger()` を外側 `DB::transaction` で囲んで throw → `analysis_jobs` 0 件 **かつ**
  `jobs` 0 件 (両方巻き戻る)
- **投入時点の tx level**: `Illuminate\Queue\Events\JobQueueing` を listen して
  `DB::transactionLevel()` を記録し、**業務 tx の内側 (level ≥ 2)** で enqueue されたことを assert
  する。dispatch を tx 外へ戻すと level 1 になり落ちる (§5-2 変異 4 の検出点)
- **AG-127 の性質 (通知失敗が業務を巻き込まない)**: `NotificationCenterService` をモックして
  `notifyTicketBalanceLow` に例外を投げさせ、**`reserve()` が成功し予約行が残る**ことを assert する
  (アプリケーション層の例外を `safely()` が握ることの behavioral 固定)

## 6. リスク

| # | リスク | 影響 | 緩和 |
|---|---|---|---|
| R-1 | `sync.after_commit=true` の全レーン波及 | 既存テストで「tx 内 dispatch の即時実行」に依存しているものがあれば挙動が変わる | 現行の業務 dispatch はすべて tx 外にあり、tx 外 dispatch は `callbackApplicableTransactions()` が 0 件 → 即時実行のまま。影響は「tx 内 dispatch」を新設する箇所に限られる。全レーン (`composer test` / `composer test:browser`) を実行して確認する |
| R-2 | `Queue::fake()` ベースの既存アサーションが偽グリーンになる | 原子性の退行を検出できない | 原子性の検証は実 `jobs` 表観測に統一 (§5-3)。`Queue::fake()` を使う既存テストはそのまま残す (それらは「dispatch されたか」を見ており、原子性を主張していない) |
| R-3 | 既存 4 契約との正面衝突 | 「保護対象を消す変更」と区別がつかなくなる | **削除でなく反転**。旧主張 / 旧目的 / 新主張 / 新前提 / 前提を守る機構 / 反転根拠の 6 行 docblock を各所に置く (M8) |
| R-4 | `SyncBillingCustomerDetails` の afterCommit 撤去で Stripe への stale read が復活する (IV-3) | Stripe に古い組織名を送る | 同 job は `SerializesModels` を使い `public readonly Organization $organization` を **ID で直列化 → handle 時に再取得**する。かつ jobs 行が業務 tx に乗るため、**worker が job を可視化できるのは commit 後**。よって再取得値は必ず commit 後の値になり IV-3 は保たれる (むしろ強化される) |
| R-5 | webhook 処理の tx が伸び、冪等マシンの tx (`claim()`) と入れ子になる | ロック保持時間の増大 | `claim()` は `process()` の **前に閉じている** (`handle()` :100 で `process()` は tx 外)。今回括るのは `completeSubscriptionCheckout` / `completeAutoRechargeSetup` 内の save+dispatch のみで、入れ子にはならない |
| R-6 | `AutoRechargeTriggerJob` の `ShouldBeUnique` を撤去することで trigger job の投入量が増える (org あたり 30 秒に高々 1 件 → reserve 1 回につき 1 件) | キュー投入量の増加 | job 本体は `exists()` 1 本で早期 return する薄い箱。reserve は人間の操作起点で頻度が低い。結果の一回性は `maybeCreateAttempt` の org 行ロック + pending 検査 + DB partial unique が担う (AGENTS ドメイン規約 6 / `JobExecutionDedupInventoryTest` の登録根拠で裁定済み)。**代替案 (`ShouldBeUnique` 温存) は rollback 時に unique lock が残り、ネスト tx では解決できない** (§3) |
| R-7 | 低残高通知がネスト時に外側 tx の内側で走る (AG-127 の保証が狭まる) | 通知 INSERT の SQL 失敗が業務 tx を巻き込む | §3 の緩和 3 点 (tx を抜けた最後に置く / アプリ層例外は `safely()` が握り behavioral test で固定 / SQL 層失敗は fail-closed として §8 に明記) |
| R-8 | PostgreSQL で tx 内の INSERT が失敗すると tx 全体が abort する。`try-catch` が握ると「握ったのに commit で落ちる」 | 業務操作が失敗する | tx 内へ入れるのは jobs 行の INSERT のみ (UNIQUE 制約なし)。fail-closed 側に倒れるため受容。§8 に明記 |
| R-10 | sync レーンで job 本体が `Connection::commit()` の中 (after-commit callback) で走るため、job が投げると **SQL COMMIT 済みなのに `commit()` が throw** する | `handleCommitTransactionException` が concurrency error かつ `$currentAttempt < $maxAttempts` のとき業務クロージャを再実行しうる | **前提を実測で確認済み**: `DB::transaction($cb)` の既定 `$attempts = 1` では `1 < 1` が偽なので**常に rethrow** し再実行は起きない。`app/` 配下に `DB::transaction()` の第 2 引数を使う箇所は **0 件** (`grep -rnE 'DB::transaction\([^)]*\},\s*[0-9]' app/`)。attempts>1 を導入したら sync レーンでのみ再実行が起きうる旨を §8 に前提として明記する |
| R-9 | 起動時 guard が env の薄い起動経路を落とす | bug-hunt / preflight が起動できない | R1〜R3 は既定接続が sync でないときのみ。bug-hunt (`env -i`) では `QUEUE_CONNECTION` 未定義 → `database` / `DB_QUEUE_CONNECTION` 未定義 → 既定 DB に一致するため通る。`scripts/bug-hunt-shard.sh self-test` と `production:preflight` で確認する |

## 7. 実査の再確認結果 (ブリーフを鵜呑みにせず Read した結果)

| ブリーフの記述 | 再確認 | 結果 |
|---|---|---|
| tx 外 dispatch 6 経路 | 全ファイル Read | **一致**。加えて `RenderJobService::reconcileOutputs` :327 と `StripeWebhookProcessor` :477 と `AutoRechargeService` :1019 も dispatch site として実在 (いずれも「保存直後」ではないため扱いが異なる。§4 M4 参照) |
| 明示 afterCommit 3 箇所 | `grep -rn afterCommit app/` | **一致** (`BillingCustomerSynchronizer:34` / `TicketLedgerService:426,442`) |
| `ShouldQueueAfterCommit` 6 クラス | grep | **一致** |
| `QueueDispatchAtomicityGuard` 相当が 0 件 / `ProductionEnvGuard` に queue 項目なし | Read | **一致** |
| `config/queue.php` の sync に after_commit キーなし | Read (:34-36) | **一致** |
| `tests/Architecture` に `QueueDispatch*` が 0 件 | `ls` | **一致** |
| `QueuedJobPopulation` が母集団の唯一実装 | Read | **一致**。`QueuedJobLeaseInventoryTest` が実際に共用しており、目録は 18 クラス |
| `AppServiceProvider` :141-143 に配線点 | Read | **一致** (`boot()` 冒頭の production 限定 `ProductionEnvGuard::enforce()`) |
| `Bus::batch` / `Bus::chain` が app/ に 0 件 | grep | **一致** |
| recoverStale は再投入せず failJob へ倒す | Read | **一致** (両サービスの docblock に明記) |
| `CreateInquiryAction` が除外の先例 | Read (:56-89) | **一致** (本設計はこのファイルを変更しない) |

**ブリーフに無かった追加の発見**

- `RefreshDatabase` は `Illuminate\Foundation\Testing\DatabaseTransactionsManager` を差し込み、
  `afterCommitCallbacksShouldBeExecuted($level) === ($level === 1)` / ラッパ tx を skip する
  `callbackApplicableTransactions()` を持つ。**この 2 点があるので sync + after_commit が
  テストレーンで本番と同じ順序意味論になる**。論点の結論はこの実測に依存している
- `ShouldQueueAfterCommit` の 6 通知は、現在の呼び出し元 (`BillingNotificationDispatcher` 経由 =
  すべて業務 tx の外) では **実行時に何の効果も持っていない**
  (`addCallback` は pending tx が 0 件なら即時実行する)。撤去は現行呼び出し元にとって
  no-op で、効果は「将来 tx 内から送ったときに黙って迂回されなくなる」こと
- `AutoRechargeTriggerJob::handle` 内にも save→dispatch の窓がある
  (`maybeCreateAttempt` の tx commit 後に `ExecuteAutoRechargeAttemptJob::dispatch`)。
  `AutoRechargeService::reconcileOutputs` 相当の (v) が回収するが、同型のため確定 1 を適用する

## 8. 保証しないもの (誇張しない)

- **プロセスが commit 直前に落ちる窓は消えない**。消えるのは「commit したのに投入されない」窓
  だけである。commit 前に落ちれば業務状態ごと巻き戻る (これは正しい挙動)
- **worker が実際に起動していることは保証しない**。jobs 行が載っても
  `queue:work database-analysis` が動いていなければ前進しない。これは既存の運用契約
  (`docs/architecture.md`) の管轄で、本設計は変えない
- **D1/D2 の静的走査は文字列パターン検出であり、動的な迂回は検出できない**。
  `$method = 'afterCommit'; $job->$method();`、helper / facade alias で包んだ呼び出し、
  vendor 内の afterCommit 使用には沈黙する。
  (`ShouldQueueAfterCommit` の中間 interface 経由・親クラス経由の実装は D3 の
  リフレクション判定が拾うため、ここは D1/D2 に限った限界である)
- **起動時 guard (M6) は config の値を見るだけ**である。DB 接続が実際に同一サーバか、
  同一トランザクションを共有するかは検査しない。**`connection` 名の一致は
  「同一トランザクションに乗る」ことの代理検査にすぎない**。異なる PDO インスタンス、
  connection resolver の差し替え、同名で別サーバを指す構成までは保証しない
- **低残高通知は原子的でない**。`reserve()` が最外層のときは commit→通知の窓が残り、
  通知は失われうる (at-most-once = `docs/architecture.md` の既定仕様どおり)。
  ネスト時は外側 tx に相乗りするため、**通知 INSERT の SQL 失敗が業務 tx を abort させうる**
  (PostgreSQL の仕様)。`safely()` はアプリケーション層の例外しか実効的に握れない
- **`AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去するため、同一 org へ短時間に
  複数の trigger job が積まれうる**。重複配送の no-op 化は `maybeCreateAttempt` の
  org 行ロック + pending 検査 + DB partial unique が担う (入口の排他は保証を担わない =
  AGENTS ドメイン規約 6)
- **sync レーンでは job 本体が `Connection::commit()` の中で走る** (R-10)。
  SQL COMMIT は済んでいるのに `DB::transaction()` が throw しうる。
  **業務クロージャの再実行は起きない** — 前提は「`DB::transaction()` の attempts が
  全経路で既定の 1」(`app/` に第 2 引数の使用は 0 件。実測済み) であり、
  **attempts>1 を導入したらこの前提は崩れる**
- **`Queue::fake()` を張ったテストでは原子性を検証できない**。この非対称は
  gate の docblock と `docs/architecture.md` に明記する
- **`ShouldBeUnique` の unique lock は rollback で解放されない** (R-6)。tx 内 dispatch が
  巻き戻ると `uniqueFor` 秒間だけ再 dispatch が抑止される
- **`Bus::batch` / `Bus::chain` の原子性は検査しない** (app/ に 0 件のため)。
  導入するときは検査項目の追加が必要になる旨を guard の docblock に書く

## 9. 期待効果

**本リポジトリの database queue 構成 (driver=database / キュー DB 接続 = 業務 DB /
after_commit=false) を前提とした効果**。構成が崩れれば効果も消えるので、
その前提を M6 が起動時に fail-closed で押さえる。

| 経路 | 変更後の分類 |
|---|---|
| `AnalysisJobService::trigger` (`RunManualAnalysis`) | commit 済み・未投入の窓を**除去** |
| `RenderJobService::trigger` (`RunManualRender`) | 同上 |
| `RenderJobService::triggerPreview` (`RunManualRender`) | 同上 |
| `RenderPipeline::finalize` (`DeleteRenderOutputsJob`) | 同上 (従来は `render:reconcile-outputs` が 5 分後に回収していた) |
| `CaptureTakeService::delete` (`DeleteTakeObjectsJob`) | 同上 (従来は回収経路なし = S3 孤児) |
| `VideoManualService::delete` (`DeleteTakeObjectsJob`) | 同上 (従来は回収経路なし = S3 孤児) |
| `StripeWebhookProcessor` :623 / :695 | 同上 (「自動的に有効になります」と表示して実行されない状態を除去) |
| `BillingCustomerSynchronizer` (`SyncBillingCustomerDetails`) | 同上 (IV-3 は §6 R-4 のとおり保たれる) |
| `TicketLedgerService::reserve` (`AutoRechargeTriggerJob`) | 同上 (従来は `billing:reconcile-auto-recharge` が 15 分後に回収) |
| `AutoRechargeTriggerJob::handle` (`ExecuteAutoRechargeAttemptJob`) | 同上 (同 15 分回収) |
| `TicketLedgerService::reserve` の低残高通知 | **at-most-once として受容** (窓は残る) |
| `RenderJobService::reconcileOutputs` / `AutoRechargeService` reconcile (v) | **変更しない** (業務状態の保存直後ではなく、回収役そのもの) |
| `StripeWebhookProcessor` :477 (`HandleAutoRechargeChargeFailureJob`) | **変更しない** (先行する自 DB 書き込みが無く、原子性の対象になる業務 tx が存在しない) |

- **件数は書かない**。件数表現は変更のたびにドリフトして嘘になるため、上表の名称で管理する
- **使命への寄与**: 「保存済み・未投入」で 10 分待たされた末に失敗表示され、
  現場作業者が自分で再実行しないと前進しない経路が消える。
  「思考ゼロ」で作れるという約束の穴を塞ぐ
- 「grep では見えない宣言的迂回」(`ShouldQueueAfterCommit`) が機械検査の対象になり、再発しなくなる
- 原子性の前提 (driver / DB 同一性 / after_commit) が起動時に fail-closed で検査され、
  **テストは緑のまま本番でだけ原子性が消える** 構成ミスがデプロイ時に表面化する

## 10. スコープ外

- outbox 表 / dispatch 経路の一本化 / ジョブ所有権取得の専用機構
  (AG-126 で「各リポジトリ任意の上積みで必須でない」と確定済み。思考原則 2)
- `recoverStale` を「再投入」へ変えること (確定 1 が入れば dispatch 喪失は起きない。
  running 側の回収は引き続き failJob が担う)
- `RenderJobService::reconcileOutputs` / `AutoRechargeService` reconcile (v) の廃止
  (どちらも別要因 = worker 異常終了 / 外部要因の回収役として残す)
- `Bus::batch` / `Bus::chain` 向けの検査項目
- キューワーカーの起動監視・死活監視

## 11. 実装モード

**standalone**。理由:

- 触るファイル数が多い (app 12 / test 6 / docs 3) 上に、`config/queue.php` の
  `sync.after_commit` は **全テストレーンの実行意味論**を変えるため、他タスクと同居させると
  失敗の切り分けができなくなる
- 既存 4 契約の反転を含み、テストの主張が変わる。他タスクの差分と混ざると
  「保護対象を消した」のか「反転した」のかがレビューで判別できない
- AG-126 の 0 件 pin は「全部直し終わってから」でないと導入できず、分割すると
  中途半端な状態が main に残る (思考原則 3「後方互換の並走を残さない」)。
  前段 PR に gate を入れると「無効な gate」か「虚偽の期待値を持つ gate」を自分で作ることになり、
  `QueueWorkerLeaseInvariantTest` が config を env 上書きさせない理由として挙げている
  「gate が嘘をつく」失敗形をそのまま踏む

### 11-2. PR 内の実装順序 (分割しない代わりに順序を固定する)

思考原則 5 (テストファースト = fail を確認してから実装に入る) に従い、次の順で進める。
各段でテストを回し、段を跨いだ「まとめて実装」をしない。

各施策を「**テストを書いて赤を見る → 実装 → 緑を見る**」の単位で進める。

1. **M6**: `QueueDispatchAtomicityGuardTest` (R1〜R4 の純関数判定 + 負のコントロール) を先に書く
   → 赤 (guard クラスが無い) → `QueueDispatchAtomicityGuard` 実装 → 緑
2. **M1**: guard の R4 (sync は `after_commit=true`) が赤であることを確認
   → `config/queue.php` に 1 行追加 → 緑。`AppServiceProvider` 配線もこの段
3. **M9**: 原子性 behavioral テスト (実 `jobs` 表観測 + `JobQueueing` の tx level) を書く
   → **赤** (現行は tx 外 dispatch = 「commit 済み・未投入」の再現テスト)
4. **M2 → M3 → M4 → M5**: 移設を 1 経路ずつ入れ、M9 の該当テストが 1 本ずつ緑になることを確認
5. **M8**: 反転対象 5 件のテストを先に「新しい主張」で書き換える → 赤/緑を確認しながら
   6 行 docblock を添える
6. **M7**: `QueueDispatchAtomicityInventoryTest` を負のコントロール込みで書く
   → この時点で 0 件 pin が緑になることを確認 (先に書くと 4 種すべてが赤で切り分け不能)
7. **§5-2 の mutation を 1 つずつ実施**し、どのテストがどう落ちたかを実装 PR の devnotes に記録
8. **M10 の文書更新** (`docs/architecture.md` / `AGENTS.md` / `.env.example`)

---

再レビューをお願いします。特に:

1. Critical への対応として選んだ「`ShouldBeUnique` 撤去 + 確定 1 適用 (tx 内 dispatch)」が、ネスト深さに依らず問題を閉じているか。見落としはないか
2. AG-127 の除外が 0 件になったという整理 (「除外ではなく元から tx の外」) が正しいか。exemption enum を作らない判断は妥当か
3. R-10 の再実行リスクを「attempts=1 だから起きない」で閉じた判断が正しいか
4. §5-1 の 3 層 (fixture ツリーでの経路統合 / 母集団の完全性アンカー / 既存 inventory への接続) で偽グリーンが閉じているか

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。残っている懸念が Suggestion 相当であれば APPROVED としてください (詳細設計フェーズで扱います)。
