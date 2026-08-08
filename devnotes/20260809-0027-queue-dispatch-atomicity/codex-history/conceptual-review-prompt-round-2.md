# Round 2: 対応マトリクスと修正内容

Round 1 の指摘に対する判断と、概念設計の修正内容を報告します。反論している箇所は根拠を添えています。

# 対応マトリクス: conceptual-review Round 1

## [Critical] `AutoRechargeTriggerJob` の `ShouldBeUnique` rollback ロック残留

- 判断: **対応する (設計変更。ただし Codex の提示した 3 択のいずれでもない第 4 の解)**
- 根拠:
  - Codex の指摘は正しい。`UniqueLock` は `PendingDispatch` (vendor:218) で **dispatch 呼び出し時**に
    取得され、rollback 時の解放は `Queue::enqueueUsing` の afterCommit 経路 (vendor:368-374) でしか
    行われない。tx 内 dispatch にすると rollback で `uniqueFor=30` 秒の抑止が残る。
  - しかし根本の誤りは「`AutoRechargeTriggerJob` を確定 1 の適用対象に入れたこと」の側にある。
    この job は **AG-127 の付随的副作用の定義に合致する**:
    - 実装コメントが「$tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 —
      **二重課金面の安全側**)」と明記している = 失われる方が安全という設計判断が既にある
    - 喪失の回収経路が実在し、cron 化されている (`billing:reconcile-auto-recharge` を
      `everyFifteenMinutes` / `onOneServer` / `withoutOverlapping` + `onFailure` で report。
      routes/console.php:92-98)。`AutoRechargeService` の (v)「取りこぼし起票: enabled な org で
      閾値割れ・pending なし (job 消失の回収)」がまさにこの経路
    - `maybeCreateAttempt` が org 行ロック + pending 検査 + DB partial unique で結果の一回性を
      持っており、trigger job の投入は「起票のきっかけ」でしかない
- 対応内容: `AutoRechargeTriggerJob` を **AG-127 の除外対象**に変更する。除外の形は
  「tx 外 dispatch のまま + 失敗を観測へ」= `DB::afterCommit` を撤去し、`reserve()` の
  `DB::transaction()` を**抜けた直後**に dispatch する (afterCommit 温存ではない)。
  これにより unique lock 残留の窓も同時に消える。§3・§4・§6 を書き換えた。

## [Warning] `TicketLedgerService` の低残高通知を tx 内へ入れる方針

- 判断: **対応する (Critical と同じ形に揃える)**
- 根拠: Codex の指摘どおり「0 件 pin のために tx 内へ移す」は理由として不適切だった。
  `docs/architecture.md` が既に「配信保証は at-most-once、通知は補助チャネル、outbox 台帳は
  作らない」と明記しており、AG-127 の除外に該当する。
  また tx 内へ入れると organizations 行ロック (reserve の直列化点) の保持時間が伸びる。
- 対応内容: 低残高通知も **AG-127 の除外**とし、`reserve()` の `DB::transaction()` を
  抜けた直後に呼ぶ形へ変更。既存の `safely()` (catch + report) が失敗観測を担う。
  「reserve が外側 tx にネストされている場合は外側 tx の内側で書かれ、rollback で巻き戻る」
  = 既存 pin (`TicketBalanceLowNotificationTest:104`) は維持されることを設計に明記した。

## [Warning] sync driver 利用時の例外伝播差分が書かれていない

- 判断: **対応する**
- 根拠: 正しい。tx 内 dispatch + `sync.after_commit=true` にすると、job 本体は
  `Connection::commit()` の中 (after-commit callback) で走る。job が投げると
  **SQL COMMIT 済みなのに `commit()` が throw** し、`Connection::transaction()` の
  `handleCommitTransactionException` を通る。concurrency error と判定されると
  **業務クロージャが再実行されうる** (commit 済みなのに)。これは sync レーン固有で、
  本番 (database driver = jobs 行の INSERT のみ) には無い。
- 対応内容: §6 リスク表に R-10 を追加し、§8「保証しないもの」にも記載した。

## [Warning] 期待効果の表現が強い (connection 名一致は同一 tx の代理にすぎない)

- 判断: **対応する**
- 根拠: 正しい。guard が見るのは config の値であり、実 PDO の同一性ではない。
- 対応内容: §9 の効果を「本リポジトリの database queue 構成では」に限定し、
  §8 に「異なる PDO / connection resolver 差し替え / 別 DB サーバまでは保証しない」を追記した。

## [Warning] 検出器ごとに走査母集団を持つべき (0 件 fail が ShouldQueue 母集団に寄りすぎ)

- 判断: **対応する (良い指摘)**
- 根拠: 4 種検出のうち `DB::afterCommit` と config 検出は `ShouldQueue` 母集団と独立で、
  片方の走査だけが死んでも気付けない。
- 対応内容: §5-1 を検出器ごとの母集団 + 個別 0 件 fail に書き換えた。あわせて
  `ShouldQueueAfterCommit` の検出は文字列走査ではなく **`QueuedJobPopulation` に対する
  `implementsInterface()` のリフレクション判定**を主とする (中間 interface 経由・
  親クラス経由の実装まで拾えるため文字列走査より強い) 形に変更した。

## [Warning] スコープが大きい。2 つに分割すべき

- 判断: **一部反論・一部対応**
- 根拠 (反論):
  - AGENTS.md 思考原則 3「後方互換の並走を残さない。書き換えると決めたら同じ PR で旧実装を消す」。
    分割すると main に「afterCommit を撤去した経路と温存した経路」が並走する期間が生まれる。
  - AG-126 の到達基準は「残存 0 件 pin」であり、**全部直し終わるまで gate を有効化できない**。
    前段 PR では gate を無効か虚偽の期待値で入れることになり、
    「gate が嘘をつく」状態を自分で作ることになる (`QueueWorkerLeaseInvariantTest` の
    docblock が config を env 上書きさせない理由として挙げているのと同じ失敗形)。
  - 既存 4 契約の反転は、業務経路の移設と同時でないと「保護対象を消した」変更と区別できない。
- 対応内容 (一部受容): 分割はしないが、**PR 内の実装順序**を設計に明記した (§11-2)。
  1. M1 (config) + M6 (guard) + guard の単体テスト → 2. M9 の behavioral テストを **先に赤で置く**
  (思考原則 5 テストファースト) → 3. M2〜M5 の移設 → 4. M8 の反転 → 5. M7 の 0 件 pin 有効化 →
  6. M10 文書。各段でテストを回す。

## [Suggestion] `QueueDispatchAtomicityGuard` の config 読み出しは PHPStan level 10 向けに shape を明示

- 判断: **対応する (詳細設計で具体化)**
- 対応内容: guard は `config()` の生 array を `mixed` として受け、`is_string` / `is_bool` の
  narrowing を通してから判定する純関数にする。未知の型・キー欠落は **違反として報告**する
  (例外ではなく violations リストに載せる = `ProductionEnvGuard` と同じ流儀)。詳細設計 §施策 M6 に書く。

## [Suggestion] §8 に 3 項目追加

- 判断: **対応する**
- 対応内容: 「sync driver 利用時の job 例外伝播」「connection 名一致は同一 tx の代理検査にすぎない」
  「静的走査は facade alias / helper wrapper 経由の afterCommit を検出しない」を §8 に追記した。

## [Suggestion] 使命との整合性 / 型安全性

- 判断: **見送る (指摘なし。現状維持)**

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

**除外する (tx 外 dispatch のまま据置。ただし afterCommit 系の記述は 0 にする)**

| 対象 | 除外根拠 | 失敗の観測 |
|---|---|---|
| `CreateInquiryAction` の Mail 2 本 | 問い合わせ受付は通知の成否に依存しない。**先例として既に実装済み** (:60-89 の try/catch + report + `Log::warning('inquiry.notification_dispatch_failed')`) | 実装済み (本設計はこのファイルを変更しない) |
| `BillingNotificationDispatcher` 経由の請求通知 6 種 | 台帳 `billing_notifications` の `insertOrIgnore` で冪等・`markFailed` で失敗を永続化・`Log::warning` で観測済み。呼び出し側 (`StripeWebhookProcessor::handleInvoicePaymentFailed` / `AutoRechargeService` の通知群 / `SendBillingReminders`) は業務 tx の外 | 実装済み (`BillingNotificationDispatcher.php:70-82` / `:126-139`) |
| `NotificationCenterService` 経由のアプリ内通知 | そもそも **queue dispatch ではない** (`AppNotification` は `ShouldQueue` 非実装 = 同期 DB 書き込み)。`safely()` が catch + report 済み | 実装済み |

| `TicketLedgerService::reserve()` の低残高通知 (:424-426) | そもそも **queue dispatch ではない** (`TicketBalanceLowNotification` は `AppNotification` 継承の同期 DB 書き込み)。`docs/architecture.md` が「配信保証は at-most-once、通知は補助チャネル、outbox 台帳は作らない」と既に明記 | `NotificationCenterService::safely()` の catch + report (実装済み) |
| `TicketLedgerService::reserve()` の `AutoRechargeTriggerJob` (:437-442) | **失われる方が安全**という設計判断が既にコードにある (`AutoRechargeTriggerJob` docblock: 「$tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — **二重課金面の安全側**)」)。喪失の回収は `billing:reconcile-auto-recharge` が **15 分周期**で行う (`routes/console.php:92-98`。`onFailure` で report まで配線済み)。結果の一回性は `maybeCreateAttempt` の org 行ロック + pending 検査 + DB partial unique が担う | 新設: dispatch を try/catch し `report()` + `Log::warning('auto_recharge.trigger_dispatch_failed')` |

**除外の実装形 (重要 — afterCommit の温存ではない)**

上記 2 件はいずれも `reserve()` の `DB::transaction()` の **内側**から `DB::afterCommit` で
呼ばれている。除外の実装は「`DB::afterCommit` を撤去し、`DB::transaction()` を**抜けた直後**に
素直に呼ぶ」である。これにより:

- `reserve()` が最外層のとき: commit 後に実行される (= 現行と同じ意味論。喪失窓は残るが、
  それが除外の意味である)
- `reserve()` が呼び出し側の tx にネストされているとき (`AnalysisPipeline` / `RenderPipeline` の
  `startJob`): 呼び出し側 tx の内側で実行され、その tx が rollback すれば
  **通知行も jobs 行も一緒に巻き戻る**。既存 pin
  (`TicketBalanceLowNotificationTest:104` /「rollback される外側 tx 内の reserve は通知されない」、
  `AutoRechargeTriggerTest:76` /「reserve が rollback したら dispatch されない」) は
  **維持される** (根拠が afterCommit から「外側 tx への相乗り」へ変わる = M8 の反転対象)
- organizations 行ロック (reserve の直列化点) の保持時間が伸びない
  (tx を抜けてから通知 INSERT を行うため)
- `AutoRechargeTriggerJob` は `ShouldBeUnique`。tx 内 dispatch にすると rollback 時に
  `uniqueFor=30` 秒の unique lock が残る (`UniqueLock` は `PendingDispatch` vendor:218 で
  dispatch 時に取得され、解放は afterCommit 経路 vendor:368-374 でしか行われない)。
  除外にすることでこの窓も同時に消える

**除外しない (確定 1 を適用 = tx 内へ移す)**

`RunManualAnalysis` / `RunManualRender` ×2 / `DeleteRenderOutputsJob` (finalize) /
`DeleteTakeObjectsJob` ×2 / `SyncBillingCustomerDetails` /
`ExecuteAutoRechargeAttemptJob` (attempt 起票と同一 tx へ) / `ReuseSubscriptionPaymentMethodJob` /
`SetDefaultPaymentMethodJob`。
いずれも「業務状態が保存された以上、必ず実行されなければならない」もので、
未実行のまま放置すると **ユーザーが再操作しない限り前進しない** か、
**表示と実態が食い違う** (`pm_reuse_dispatched_at` 打刻済みで PM 流用が起きない)。

> 除外と非除外を分ける実質的な基準は「**喪失を自動で回収する経路が実在し、cron で回っているか**」
> である。`recoverStale` は再投入しない (failJob へ倒す) ので回収経路ではない。
> `billing:reconcile-auto-recharge` (15 分) と `render:reconcile-outputs` (5 分) は回収経路である。
> `DeleteRenderOutputsJob` は `reconcileOutputs` が回収するが、**S3 の課金が発生し続ける**ため
> 「回収されるまで無害」とは言えず、確定 1 を適用する。

## 4. 実装方針 (概要)

| # | 施策 | 対象 |
|---|---|---|
| M1 | sync 接続の実行意味論の確定 | `config/queue.php` (`sync` に `after_commit => true`) |
| M2 | 業務ジョブ dispatch の tx 内移設 | `AnalysisJobService` / `RenderJobService` ×2 / `RenderPipeline` / `CaptureTakeService` / `VideoManualService` |
| M3 | Billing の afterCommit 撤去 (tx 内移設 1 件 + AG-127 除外 2 件) | `BillingCustomerSynchronizer` (tx 内へ) / `TicketLedgerService` ×2 (除外 = tx を抜けた直後へ) / `AutoRechargeService` (起票と同一 tx へ集約) |
| M4 | webhook の save+dispatch を同一 tx で括る | `StripeWebhookProcessor` :623 / :695 (:477 は対象外・根拠明記) |
| M5 | 宣言的迂回の撤去 | `app/Notifications/Billing/` 6 クラスの `ShouldQueueAfterCommit` |
| M6 | 起動時 fail-closed 検査 | 新規 `app/Support/QueueDispatchAtomicityGuard.php` + `AppServiceProvider::boot()` 配線 |
| M7 | deny-by-default 目録型 gate (0 件 pin + 負のコントロール) | 新規 `tests/Architecture/QueueDispatchAtomicityInventoryTest.php` |
| M8 | 既存 4 契約の**反転** (削除ではない) | `BillingSyncDispatchInvariantTest` / `BillingCustomerSynchronizerTest` / `AutoRechargeTriggerTest` / `TicketBalanceLowNotificationTest` |
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
| D4 config の `after_commit => true` (sync 以外) | `config('queue.connections')` | 接続数 0 なら fail | `after_commit=true` を含む擬似 connections 配列を判定器に食わせて検出することを assert |

- **D3 は文字列走査ではなくリフレクション判定を主とする**:
  `(new ReflectionClass($class))->implementsInterface(ShouldQueueAfterCommit::class)`。
  文字列走査だと「`ShouldQueueAfterCommit` を継承した中間 interface を implement する」
  「親クラス経由で implement される」形を丸ごと見落とす。ブリーフの申し送り
  (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと見えない」) への
  正しい応答は、grep を強化することではなく判定を型システム側へ移すことである
- **母集団の件数は pin しない**。0 件で fail のみ (件数を pin するとクラス追加のたびに
  無関係な失敗が出る = gate が信用されなくなる)
- **exact-fit**: 免除目録 (型付き enum) の case 集合と、実際に免除が適用された対象の集合の
  **対称差が空**であることを assert する。今回は enum に case を 1 つも置かない
  (= 免除 0 件・検出 0 件) ため、上表の負のコントロールと母集団 0 件 fail が
  「空振りしていないこと」の唯一の担保になる

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

## 6. リスク

| # | リスク | 影響 | 緩和 |
|---|---|---|---|
| R-1 | `sync.after_commit=true` の全レーン波及 | 既存テストで「tx 内 dispatch の即時実行」に依存しているものがあれば挙動が変わる | 現行の業務 dispatch はすべて tx 外にあり、tx 外 dispatch は `callbackApplicableTransactions()` が 0 件 → 即時実行のまま。影響は「tx 内 dispatch」を新設する箇所に限られる。全レーン (`composer test` / `composer test:browser`) を実行して確認する |
| R-2 | `Queue::fake()` ベースの既存アサーションが偽グリーンになる | 原子性の退行を検出できない | 原子性の検証は実 `jobs` 表観測に統一 (§5-3)。`Queue::fake()` を使う既存テストはそのまま残す (それらは「dispatch されたか」を見ており、原子性を主張していない) |
| R-3 | 既存 4 契約との正面衝突 | 「保護対象を消す変更」と区別がつかなくなる | **削除でなく反転**。旧主張 / 旧目的 / 新主張 / 新前提 / 前提を守る機構 / 反転根拠の 6 行 docblock を各所に置く (M8) |
| R-4 | `SyncBillingCustomerDetails` の afterCommit 撤去で Stripe への stale read が復活する (IV-3) | Stripe に古い組織名を送る | 同 job は `SerializesModels` を使い `public readonly Organization $organization` を **ID で直列化 → handle 時に再取得**する。かつ jobs 行が業務 tx に乗るため、**worker が job を可視化できるのは commit 後**。よって再取得値は必ず commit 後の値になり IV-3 は保たれる (むしろ強化される) |
| R-5 | webhook 処理の tx が伸び、冪等マシンの tx (`claim()`) と入れ子になる | ロック保持時間の増大 | `claim()` は `process()` の **前に閉じている** (`handle()` :100 で `process()` は tx 外)。今回括るのは `completeSubscriptionCheckout` / `completeAutoRechargeSetup` 内の save+dispatch のみで、入れ子にはならない |
| R-6 | `AutoRechargeTriggerJob` は `ShouldBeUnique`。tx 内 dispatch が rollback すると unique lock が `uniqueFor` (30s) 残る (`UniqueLock` は `PendingDispatch` vendor:218 で dispatch 時取得。解放は afterCommit 経路 vendor:368-374 のみ) | 30 秒間、同一 org の再 dispatch が no-op になる | **AG-127 除外にすることで回避済み** (§3)。この job は tx の外で dispatch するため、rollback 時に lock を取ること自体が起きない |
| R-7 | AG-127 除外の 2 件が「reserve が最外層のとき」は commit→実行の窓を残す | 通知欠落 / trigger 欠落 | それが除外の意味である。trigger は `billing:reconcile-auto-recharge` (15 分) が回収し、通知は at-most-once が既定の仕様 (`docs/architecture.md`)。§8 に明記 |
| R-8 | PostgreSQL で tx 内の INSERT が失敗すると tx 全体が abort する。`try-catch` が握ると「握ったのに commit で落ちる」 | 業務操作が失敗する | tx 内へ入れるのは jobs 行の INSERT のみ (UNIQUE 制約なし)。fail-closed 側に倒れるため受容。§8 に明記 |
| R-10 | sync レーンで job 本体が `Connection::commit()` の中 (after-commit callback) で走るため、job が投げると **SQL COMMIT 済みなのに `commit()` が throw** する | `Connection::transaction()` の `handleCommitTransactionException` を通り、concurrency error と判定されると業務クロージャが**再実行されうる** | sync レーン固有 (本番の database driver では tx 内に載るのは jobs 行の INSERT だけで job 本体は走らない)。dev で `QUEUE_CONNECTION=sync` を使う場合の受容リスクとして §8 に明記する。テストレーンでは job 本体の例外は失敗として観測される |
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
- **AG-127 除外の 2 件 (低残高通知 / `AutoRechargeTriggerJob`) は原子的でない**。
  `reserve()` が最外層のときは commit→実行の窓が残る。trigger の喪失は
  `billing:reconcile-auto-recharge` (15 分周期) が回収し、通知の喪失は at-most-once の既定仕様に
  従って回収しない
- **dev で `QUEUE_CONNECTION=sync` を使う場合、job 本体の例外は `Connection::commit()` から
  投げられる** (R-10)。SQL COMMIT は済んでいるのに `DB::transaction()` が throw し、
  concurrency error と判定されれば業務クロージャが再実行されうる。
  この差分は sync レーン固有であり、本設計は解消しない
- **`Queue::fake()` を張ったテストでは原子性を検証できない**。この非対称は
  gate の docblock と `docs/architecture.md` に明記する
- **`ShouldBeUnique` の unique lock は rollback で解放されない** (R-6)。tx 内 dispatch が
  巻き戻ると `uniqueFor` 秒間だけ再 dispatch が抑止される
- **`Bus::batch` / `Bus::chain` の原子性は検査しない** (app/ に 0 件のため)。
  導入するときは検査項目の追加が必要になる旨を guard の docblock に書く

## 9. 期待効果

- **使命への寄与**: 「保存済み・未投入」で 10 分待たされた末に失敗表示され、
  現場作業者が自分で再実行しないと前進しない経路が、**本リポジトリの database queue 構成
  (driver=database / キュー DB 接続 = 業務 DB / after_commit=false) において 6 本消える**。
  「思考ゼロ」で作れるという約束の穴を塞ぐ。
  構成が崩れれば効果も消えるので、その前提を M6 が起動時に fail-closed で押さえる
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

1. **M1 (config) + M6 (guard) + guard の単体テスト**。R1〜R4 の判定を純関数として先に固める
2. **M9 の behavioral テストを赤で置く** (現行実装では落ちることを確認する)。
   ここが「commit 済み・未投入」の再現テストにあたる
3. **M2〜M5 の移設** (Manual/Capture → Billing → webhook → Notification の順)。M9 が緑になる
4. **M8 の反転** (既存 4 契約の docblock 6 行反転 + テスト更新)
5. **M7 の 0 件 pin を有効化** (この時点で残存 0 件になっているはず。負のコントロールも同時に入れる)
6. **§5-2 の mutation 手順を 1 つずつ実施**し、結果を実装 PR の devnotes に記録
7. **M10 の文書更新** (`docs/architecture.md` / `AGENTS.md` / `.env.example`)

---

上記を踏まえ、再度レビューしてください。特に:

1. Critical (`AutoRechargeTriggerJob`) への対応として選んだ「AG-127 の除外へ移す」が妥当か。除外と非除外を分ける基準として §3 に置いた「喪失を自動で回収する経路が実在し cron で回っているか」は、判断基準として機械的に運用できるか
2. 低残高通知・trigger job を「reserve の tx を抜けた直後に呼ぶ」形にしたことで、既存 pin (rollback で発火しない) が本当に維持されるか。見落としがないか
3. 分割しない判断への反論の根拠が成立しているか
4. §5-1 の検出器ごとの母集団 + 負のコントロールで「空振りしない gate」として十分か

全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
