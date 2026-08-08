# 確認ラウンド (2 回目 / one-shot): 前回の確認ラウンド指摘への対応

あなたは Laravel 12 のコードレビュアーである。これは **T137 (キュー投入の業務 tx 内移設と起動時検査)**
の最終確認である。既にセッション形式 4 ラウンド + 確認ラウンド 1 回を経ており、
指摘 15 件 (Critical 0) すべてに対応済みである。

**今回判定してほしいのは「直前の確認ラウンドで出た 2 件 (D6 の欠落 / D2 の表示契約) が解消したか」と
「その修正が新たな問題を持ち込んでいないか」だけ**である。既に決着した論点は蒸し返さないこと。

【ツール使用制限】コマンド実行・ファイル書き込みは行わない。ファイル読み込みは許可
(必要なら `/workspace/.claude/worktrees/tasks/T137/` 配下を読んでよい)。

---

## 直前の確認ラウンドの指摘と対応

# 対応マトリクス: impl-review 確認ラウンド (Round 5 / one-shot)

全体判定: `CHANGES_REQUESTED` (Critical 0 / Warning 1 / Suggestion 1)

## [Warning] `ShouldDispatchAfterCommit` (event 側) が 0 件 pin の対象外
- 判断: **対応する** (新規論点。設計の D1〜D5 に無い第 4 の迂回路)
- 根拠: 指摘が正しい。`Events\Dispatcher::dispatch()` は
  `Illuminate\Contracts\Events\ShouldDispatchAfterCommit` を見て**イベント発火そのもの**を
  commit 後へ回す。queued listener がぶら下がっていれば **enqueue も commit 後になる**。
  この経路は D1〜D5 のどれにも映らず、event クラスは `ShouldQueue ∪ Mailable` の母集団にも
  入らないため、「どの層からも迂回できない」という docblock の主張が成立していなかった。
- 対応内容: **D6 を新設**した。
  - `QueuedJobPopulation::appClasses()` を追加 (app/ の読み込み可能な全クラス。
    event には marker interface が無く母集団を静的に絞れないため、**超集合**を
    deny-by-default で見る = event 検出のヒューリスティクスを持たない)。
  - `QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents()` を追加。
  - 0 件 pin テスト 1 本 + 負のコントロール 1 本 + 母集団の超集合性テスト 1 本を追加。
  - `docs/architecture.md` の検出表に D6 行と根拠を、`AGENTS.md` にも interface を追記。
  - 現行 `app/` の実装数は 0 件 (grep 実測)。

## [Suggestion] D2 の表示契約が実装より狭い (実装は静的 `::afterCommit()` 全般を検出)
- 判断: **対応する** (実装を狭めるのではなく、表示を実装に合わせる)
- 根拠: 妥当。実装は `T_DOUBLE_COLON + afterCommit(` なので `SomeClass::afterCommit()` も検出する。
  実装を DB facade へ絞ると「facade alias を経由した別名」で抜けられるため、
  **広いまま**にして名前と説明を実装へ合わせるのが安全側。
- 対応内容: テスト名を
  `D2: first-party ランタイム PHP に静的 ::afterCommit() の呼び出しは 1 件も無い (DB:: を含む)` へ、
  失敗メッセージも「静的 ::afterCommit() (DB::afterCommit 等)」へ変更した。


---

## 修正差分

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 7d57e41..cf835be 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -490,3 +490,34 @@ ## ドメイン固有規約
       将来別 prefix の機械向け API には**沈黙する**。fatal error で `processing` が残る窓も
       閉じない (保持期間満了まで 409 が続く)。詳細は `docs/api-idempotency.md` と
       `docs/architecture.md` §冪等キーの claim と保持期間
+11. **キュー投入の原子性**: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
+    (`afterCommit` に依存しない)。`->afterCommit()` / `DB::afterCommit` /
+    `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` /
+    `ShouldDispatchAfterCommit` (event 側。母集団は `app/` の全クラス) /
+    `$afterCommit` の truthy な既定値・promoted parameter・`= true` 代入
+    (**`ShouldQueue` 実装だけでなく Mailable も** —
+    Mailable は `ShouldQueue` なしでも `Mail::queue()` でキューに載る) /
+    config の `after_commit => true` (sync 以外) は **すべて 0 件で pin** されている
+    (`QueueDispatchAtomicityInventoryTest` が deny-by-default。allow-list は持たない =
+    免除機構そのものが無い)。原子性の前提 (driver=database / キュー DB 接続 = 業務 DB /
+    after_commit=false / production の既定接続が sync でない) は
+    `QueueDispatchAtomicityGuard` が **全環境の起動時**に fail-closed 検査する。
+    - `config/queue.php` の `sync` は **`after_commit => true` が必須**。これが無いと
+      tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob` が
+      自分自身のロック下で成立してしまう
+    - **`Queue::fake()` では原子性を検証できない** (`QueueFake::push()` は
+      `enqueueUsing` を通らない)。原子性の検証は実 `jobs` 表と
+      `JobQueueing` の `DB::transactionLevel()` 観測で行う (主契約は
+      「action 直前の level + 1 以上」。**rollback テストは移設を検出しない**)
+    - `ShouldBeUnique` は業務 tx 内 dispatch と両立しない (unique lock は dispatch 時に取得され
+      rollback で解放されない)。`AutoRechargeTriggerJob` からは撤去済みで、一回性は
+      永続状態遷移が担う (ドメイン規約 6)
+    - **保証しないもの**: 検出は token 走査 (D1/D2/D5 の代入形) とリフレクション
+      (D3/D5 の既定値) の併用で、動的な迂回 (`$m = 'afterCommit'` /
+      `$this->afterCommit = $flag;`) や helper 経由の呼び出しには沈黙する。
+      guard は config の値だけを見るため、`connection` 名の一致は
+      「同一トランザクションに乗る」ことの**代理検査**にすぎない。
+      また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
+      gate が固定するのは「commit 後ずらしの機構を使っていないこと」までで、
+      既知経路が実際に tx 内で投入していることは behavioral test が固定する
+    - 詳細は `docs/architecture.md` §キュー投入の原子性
diff --git a/docs/architecture.md b/docs/architecture.md
index 30eca05..31ac92a 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -242,6 +242,102 @@ ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
 - 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
   「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)
 
+### キュー投入の原子性
+
+**業務状態の保存とキュー投入は同一トランザクション内で行う** (裁定 AG-114 確定 1 / 到達基準 AG-126 /
+除外基準 AG-127。設計は `devnotes/20260809-0027-queue-dispatch-atomicity/`)。
+旧実装は commit 後に dispatch していたため、その間にプロセスが落ちると
+`RunManualAnalysis` / `RunManualRender` / `DeleteRenderOutputsJob` / `DeleteTakeObjectsJob` /
+Stripe webhook 由来の 2 ジョブが「保存済み・未投入」のまま残った。
+`recoverStale` は**再投入ではなく failJob へ倒す**ため、ユーザーの再実行なしには前進しない。
+
+1. **0 件 pin (commit 後ずらしの機構を使わない)** — 次の 5 種は
+   `QueueDispatchAtomicityInventoryTest` が deny-by-default で **0 件**に固定する。
+   **allow-list は持たない** (case を 1 つも持たない免除 enum は死んだ機構になるため、
+   除外が必要になった時点で免除機構ごと設計し直す)。
+
+   | # | 迂回路 | 検出方法 | 母集団 |
+   |---|---|---|---|
+   | D1 | `->afterCommit()` | token 走査 | `app` / `routes` / `bootstrap` / `database` / `config` |
+   | D2 | `DB::afterCommit()` | token 走査 | 同上 |
+   | D3 | `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` | リフレクション | `ShouldQueue` 実装 ∪ Mailable subclass |
+   | D4 | config の `after_commit => true` (sync 以外) | config 走査 | `queue.connections` 全件 |
+   | D5 | `$afterCommit` の truthy な既定値 / promoted parameter / truthy な単一リテラル代入 | リフレクション + token 走査 | D3 と同じ / D1 と同じ |
+   | D6 | `ShouldDispatchAfterCommit` (event 側) | リフレクション | `app/` の**全クラス** |
+
+   - **D3 / D5 の母集団に Mailable を足す**のは、Mailable が `ShouldQueue` なしでも
+     `Mail::to(...)->queue()` でキューに載り、vendor の `SendQueuedMailable::__construct()` が
+     `$afterCommit` を wrapper job へコピーするため (本リポジトリは `CreateInquiryAction` が
+     現に `Mail::to(...)->queue(...)` を使っている)。
+   - **D6 が要る**のは、`Events\Dispatcher::dispatch()` が `ShouldDispatchAfterCommit` を見て
+     **イベント発火そのもの**を commit 後へ回すためである (queued listener がぶら下がっていれば
+     enqueue も commit 後になる)。event には marker interface が無く母集団を静的に絞れないので、
+     `app/` の全クラスという**超集合**を deny-by-default で見る。
+   - **`ShouldHandleEventsAfterCommit` も D3 で見る**のは、
+     `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
+     `ShouldQueueAfterCommit` ではなくこちらを見るためである (ShouldQueue な listener では
+     これが**キュー投入そのもの**を commit 後へずらす)。
+   - **token 走査である理由**: 素の文字列 grep にすると、契約の反転 docblock が旧主張として
+     `->afterCommit()` を引用した瞬間に gate が自壊する。
+   - **D5 の判定は vendor と同じ真偽値文脈**である (`Queue::shouldDispatchAfterCommit()` は
+     `isset($job->afterCommit)` で拾った値をそのまま真偽値評価する)。`1` のような truthy 値も違反、
+     `null` / `false` / `0` は違反にしない。**promoted parameter は既定値に依らず違反**とする
+     (呼び出し側が `new Job(afterCommit: true)` で任意に渡せるため)。
+
+2. **起動時 fail-closed 検査** — `QueueDispatchAtomicityGuard` が
+   `AppServiceProvider::boot()` から**全環境で**走る (production 限定ではない —
+   R4 はテスト・dev でこそ意味を持つため `ProductionEnvGuard` には相乗りしない)。
+
+   | 規則 | 内容 |
+   |---|---|
+   | R1 | 参照接続 (既定接続 ∪ pin 済み 3 接続) の driver は `database` |
+   | R2 | 同接続の DB 接続は業務 DB と同一 (`connection` が null = 既定 DB は許可) |
+   | R3 | 同接続の `after_commit` は `false` を明示 (キー欠落も違反) |
+   | R4 | `sync` 接続は driver=sync かつ `after_commit=true` |
+   | R5 | production の既定接続の driver は `database` (sync の本番投入を拒否) |
+
+   - **sync の除外は driver ではなく接続「名」で判定する**。driver で除外すると
+     `database-analysis.driver = sync` にした構成が R1〜R3 を丸ごと skip して通ってしまう。
+   - **pin 済み接続集合の drift** は `QueuedJobLeaseInventoryTest` の対称差テストが閉じる
+     (guard 単体では閉じない)。
+   - `Bus::batch` / `Bus::chain` は `app/` に 0 件のため束台帳の検査は持たない
+     (導入時は `config('queue.batching')` の接続一致検査を guard に足すこと)。
+
+3. **`config/queue.php` の `sync` は `after_commit => true` が必須** — これが無いと
+   tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob`
+   (lockForUpdate + `status===queued`) が**自分自身のロック下で成立**してしまう。
+   `after_commit=true` の sync では「業務 tx の commit 直後・テスト tx の内側でインライン実行」
+   となり、本番の「commit 後に worker が拾う」と同じ順序意味論になる。
+
+4. **検証方法** — **`Queue::fake()` では原子性を検証できない**
+   (`QueueFake::push()` は `enqueueUsing` を通らず、after_commit の解決も観測点も素通りする)。
+   原子性の検証は `queue.default='database'` + 実 `jobs` 表 +
+   `JobQueueing` の `DB::transactionLevel()` 観測 (`RecordsJobQueueingTransactionLevel`) で行う。
+   判定は **action 直前の level (baseline) + 1 以上**であり、固定値では判定しない。
+   **rollback テストは移設を検出しない** — 旧実装でもテストが外側 tx で包めば jobs 行は
+   rollback で消えるため、主契約は tx level 観測だけである。
+
+5. **入口排他との関係** — `AutoRechargeTriggerJob` から `ShouldBeUnique` を撤去した。
+   `UniqueLock` は dispatch 呼び出し時に取得され、rollback 時の解放は afterCommit 経路でしか
+   行われないため、業務 tx の内側で dispatch すると rollback しても `uniqueFor` 秒の抑止が残る
+   (ネスト深さに依らず解消できない)。一回性は永続状態遷移が担う (§ジョブの重複実行と結果の一回性)。
+
+6. **保証しないもの (誇張しない)**
+   - 消えるのは「業務状態を commit したのにキューへ投入されない」窓**だけ**である。
+     commit 前の障害は両方 rollback し (不整合ではない)、commit 後に jobs 行が残っても
+     **worker がそれを処理することは保証しない**。
+   - guard は **config の値だけ**を見る。`connection` 名の一致は「同一トランザクションに乗る」
+     ことの**代理検査**にすぎず、別 PDO / connection resolver 差し替え / 同名で別サーバを指す
+     構成は検査しない。
+   - **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない**。gate が固定するのは
+     「commit 後ずらしの機構を使っていないこと」までで、tx 外に置かれた新しい dispatch は
+     gate に映らない (既知経路は behavioral テストが経路ごとに固定する)。
+   - D1/D2/D5(代入) は token 走査なので、動的な迂回 (`$m = 'afterCommit'; $job->$m();` /
+     `$this->afterCommit = $flag;` / `= 1 + 1` のような式 / helper 経由) には沈黙する
+     (D5 の代入は**単一リテラルの代入だけ**を真偽値評価する。基数付き数値と数値区切りは扱うが、
+     **エスケープを含む文字列リテラルは評価不能に倒して検出しない**)。
+   - **低残高通知は原子的でない** (at-most-once = 既定仕様。§アプリ内通知の配信保証)。
+
 ### キューのリース期間とワーカー制限時間の規約
 
 DB driver のキューには**実行中にリース (`retry_after`) を延長する API が無い**ため、
@@ -311,7 +407,7 @@ ### ジョブの重複実行と結果の一回性
 
    | 層 | 機構 | 何を保証するか |
    |---|---|---|
-   | 入口 | org `Cache::lock` (TTL 180s) / `AutoRechargeTriggerJob::$uniqueFor` (30s) | best-effort の直列化のみ |
+   | 入口 | org `Cache::lock` (TTL 180s) | best-effort の直列化のみ (T137 で `AutoRechargeTriggerJob` の `ShouldBeUnique` は撤去。§キュー投入の原子性) |
    | 起票 | `tar_attempts_org_pending_unique` (partial unique) | org に pending は 1 つまで |
    | 遷移 | `where status='pending'` の条件付き UPDATE | 1 attempt = 1 遷移 |
    | 効果 | 台帳 `recharge:{invoiceId}` の UNIQUE + Stripe idempotency key | 付与と課金の一回性 |
@@ -325,8 +421,10 @@ ### ジョブの重複実行と結果の一回性
    (b) **送信結果の不明**: 送信直後にプロセスが死ぬと結果が分からない (S3 PUT / Stripe pay 同型)。
    (c) **LLM に冪等キーが無い**: provider 側で重複排除できない (だから preflight を置く)。
    (d) **`queue:listen` ではジョブ側 `$timeout` が効かない** (dev / bug-hunt)。
-7. **序列** — `LOCK_TTL_SECONDS` / `uniqueFor` < 既定接続の `retry_after`
+7. **序列** — `LOCK_TTL_SECONDS` < 既定接続の `retry_after`
    (鍵の残留が正当な再実行を封鎖する時間を、キューの再配送間隔の内側に収める)。
+   `uniqueFor` の系統は T137 で撤去済み (`ShouldBeUnique` の unique lock は業務 tx 内 dispatch と
+   両立しない — dispatch 時に取得され rollback で解放されないため)。
    ジョブ側 `$timeout` < `retry_after` < 予約 TTL ≤ stale 閾値 (上節)。
    成立前提は「pcntl 有効 / 遅延なし / 時計ずれが小さい / シグナル順序 / supervisor 設定」。
 8. **運用契約 (所有者 = 課金運用担当)** —
@@ -748,9 +846,17 @@ ## アプリ内通知センター (T008) の運用契約
   既存ユーザーのみ (平文 token 非含有) / 残高低下 = org の owner/admin
   (`organizationRole` = laratrust_team_id 明示判定)
 - **残高低下のクロス検知**: `TicketLedgerService::reserve` の org 行ロック内で
-  「実効残高 (Reserved 拘束込み) が `billing.ticket_low_balance_threshold` を跨いだ」ときのみ
-  `DB::afterCommit` で 1 回通知 (commit は拘束と台帳が相殺し balance 不変 = クロスを発生させない。
+  「実効残高 (Reserved 拘束込み) が `billing.ticket_low_balance_threshold` を跨いだ」ことを判定し、
+  **クロスの事実だけをクロージャの戻り値で持ち出して tx を抜けた最後に 1 回通知する**
+  (commit は拘束と台帳が相殺し balance 不変 = クロスを発生させない。
   release/grant で回復して再度跨げば再通知)。`billing_notifications` (メール送達台帳) には行を作らない
+  - **T137 で `DB::afterCommit` を撤去した** (§キュー投入の原子性)。afterCommit は
+    「commit したのに未投入」の窓を作る機構であり、AG-127 の付随的副作用は
+    「tx の外へ出す」であって「afterCommit で温存する」ではない
+  - **保証範囲を誇張しない**: `reserve()` が呼び出し側の tx にネストされている場合、通知は
+    依然として外側 tx の内側で走る (= 外側のロックを保持したまま INSERT され、SQL 層の失敗は
+    PostgreSQL の transaction abort を経て業務操作ごと失敗させうる)。
+    `NotificationCenterService::safely()` が握るのは**アプリケーション層の例外だけ**である
 - **読み出し**: 自分宛 (notifiable = 自分) で構造的に閉じる (org フィルタなし = 全 org 横断)。
   `{notification}` は implicit binding を使わず relation 経由解決 (cross-user は 404 = 存在秘匿)。
   `open` は POST + 303 のサーバ解決遷移 (認可判断は複製せず遷移先の Gate が唯一の判断点)。
diff --git a/tests/Architecture/QueueDispatchAtomicityInventoryTest.php b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
new file mode 100644
index 0000000..7209123
--- /dev/null
+++ b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
@@ -0,0 +1,652 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Billing\PaymentFailedNotification;
+use Illuminate\Bus\Queueable;
+use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
+use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
+use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use Illuminate\Foundation\Bus\Dispatchable;
+use Illuminate\Mail\Mailable;
+use Symfony\Component\Finder\Finder;
+use Tests\Support\Queue\QueueDispatchDeferralInventory;
+use Tests\Support\QueuedJobPopulation;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の commit 後ずらしを deny-by-default で 0 件に固定する (AG-114 確定 1 / AG-126)
+|--------------------------------------------------------------------------
+|
+| ★ **allow-list を持たない deny-by-default** である。免除目録 (enum) は**作っていない** —
+|   確定 1 の queue dispatch 母集団における除外が 0 件だからで、case を 1 つも持たない
+|   exemption enum は死んだ機構になる (思考原則 2)。将来除外が必要になったら
+|   この gate が落ちるので、そのときに免除機構ごと設計し直すこと。
+|
+| ★ D1/D2/D5(代入) は **token 走査** (PhpTokenScan) で行い、コメント・docblock・
+|   文字列リテラルは対象外にする。素の grep にすると契約の反転 docblock
+|   (旧主張として `->afterCommit()` を引用する) で gate が落ちてしまう。
+|
+| ★ 母集団の**完全性**そのものは QueuedJobLeaseInventoryTest / JobExecutionDedupInventoryTest が
+|   対称差 0 で既に固定しているため、本 gate では 0 件 fail のみとし二重実装しない。
+|
+| ★ 保証しないもの: token 走査でも**動的な迂回**には沈黙する —
+|   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
+|   `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
+|   また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
+|   固定するのは「commit 後ずらしの機構を使っていないこと」までである。
+|   (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
+*/
+
+/**
+ * 期待ルート集合を**テスト側でリテラルに独立固定**する。
+ *
+ * ★ これが要である。テスト 5 (対称差) と テスト 6 (ルート単位 0 件 fail) が両方とも
+ *   `RUNTIME_ROOTS` を参照していると、**定数から `routes` を消したときに実装列挙と
+ *   Finder 列挙が同時に狭まり、どちらも通ってしまう**。
+ *
+ * @var list<string>
+ */
+const QUEUE_DEFERRAL_EXPECTED_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];
+
+/** base_path() からの相対パス表示 (失敗メッセージ用)。 */
+function queueDeferralRelativePath(string $path): string
+{
+    $base = base_path().DIRECTORY_SEPARATOR;
+
+    return str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;
+}
+
+/** 検出結果を「相対パス:行」の list に整形する。 */
+function queueDeferralFormatHits(array $hits): array
+{
+    return array_map(
+        static fn (array $hit): string => queueDeferralRelativePath((string) $hit['path']).':'.$hit['line'],
+        $hits,
+    );
+}
+
+/** プロセスごとに一意な fixture ディレクトリを作る (--parallel での衝突回避)。 */
+function queueDeferralFixtureRoot(): string
+{
+    $root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'queue-deferral-'.getmypid().'-'.uniqid();
+    mkdir($root.DIRECTORY_SEPARATOR.'nested', 0o777, true);
+
+    return $root;
+}
+
+/** fixture ディレクトリを再帰削除する。 */
+function queueDeferralRemoveFixture(string $root): void
+{
+    if (! is_dir($root)) {
+        return;
+    }
+
+    $iterator = new RecursiveIteratorIterator(
+        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+        RecursiveIteratorIterator::CHILD_FIRST,
+    );
+    foreach ($iterator as $file) {
+        $path = (string) $file;
+        is_dir($path) ? rmdir($path) : unlink($path);
+    }
+    rmdir($root);
+}
+
+// ------------------------------------------------------------------
+// ダミークラス (D3 / D5(既定値) の負のコントロール。リポジトリ内に fixture PHP を置かない)
+// ------------------------------------------------------------------
+
+/** D3 の負のコントロール: ShouldQueueAfterCommit を implement する job。 */
+final class DeferralProbeAfterCommitJob implements ShouldQueue, ShouldQueueAfterCommit
+{
+    use Dispatchable;
+    use Queueable;
+}
+
+/** D3 の負のコントロール: ShouldHandleEventsAfterCommit を implement する listener。 */
+final class DeferralProbeAfterCommitListener implements ShouldHandleEventsAfterCommit, ShouldQueue
+{
+    use Queueable;
+}
+
+/** D5 (既定値) の負のコントロール: $afterCommit の既定値が true な job。 */
+final class DeferralProbeAfterCommitPropertyJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /**
+     * ★ `Illuminate\Bus\Queueable` trait は使わない。同 trait が `public $afterCommit;`
+     *   (型なし・既定値なし) を持つため、既定値付きで再宣言すると composition が fatal になる
+     *   (= PHP の言語仕様上、Queueable を使うクラスではこの迂回路そのものが書けない)。
+     *   既定値 true が現実に書けるのは Mailable のように trait を使わないクラスである。
+     *
+     * @var bool
+     */
+    public $afterCommit = true;
+}
+
+/** D5 (既定値) の偽陰性コントロール: 既定値が null / false のクラスは検出しない。 */
+final class DeferralProbeNullAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+    use Queueable;
+}
+
+/** D5 (既定値) の偽陰性コントロール: 明示 false。 */
+final class DeferralProbeFalseAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /** @var bool */
+    public $afterCommit = false;
+}
+
+/** D5 の偽陰性コントロール: falsy な既定値 (0) は vendor でも発動しないので検出しない。 */
+final class DeferralProbeZeroAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /** @var int */
+    public $afterCommit = 0;
+}
+
+/** D5 の負のコントロール: promoted parameter は既定値 false でも違反 (呼び出し側が渡せる)。 */
+final class DeferralProbePromotedFalseAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    public function __construct(public bool $afterCommit = false) {}
+}
+
+/** D5 (既定値) の負のコントロール: truthy だが `true` ではない既定値 (vendor は真偽値文脈で見る)。 */
+final class DeferralProbeTruthyAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    /** @var int */
+    public $afterCommit = 1;
+}
+
+/** D5 (既定値) の負のコントロール: constructor promotion で既定値を持たせた形。 */
+final class DeferralProbePromotedAfterCommitJob implements ShouldQueue
+{
+    use Dispatchable;
+
+    public function __construct(public bool $afterCommit = true) {}
+}
+
+/** D6 の負のコントロール: event 側の commit 後ずらし。 */
+final class DeferralProbeAfterCommitEvent implements ShouldDispatchAfterCommit {}
+
+/** D5 (既定値) の負のコントロール: **ShouldQueue を実装しない** Mailable。 */
+final class DeferralProbeMailable extends Mailable
+{
+    /** @var bool */
+    public $afterCommit = true;
+}
+
+// ------------------------------------------------------------------
+// 0 件 pin
+// ------------------------------------------------------------------
+
+test('D1: first-party ランタイム PHP に ->afterCommit() の呼び出しは 1 件も無い', function (): void {
+    $hits = array_values(array_filter(
+        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
+        static fn (array $hit): bool => $hit['kind'] === 'D1',
+    ));
+
+    expect(queueDeferralFormatHits($hits))->toBe(
+        [],
+        'キュー投入を commit 後へずらす ->afterCommit() が残っています。業務 tx の内側で'
+        .'素直に dispatch してください (AG-114 確定 1)。',
+    );
+});
+
+test('D2: first-party ランタイム PHP に静的 ::afterCommit() の呼び出しは 1 件も無い (DB:: を含む)', function (): void {
+    $hits = array_values(array_filter(
+        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
+        static fn (array $hit): bool => $hit['kind'] === 'D2',
+    ));
+
+    expect(queueDeferralFormatHits($hits))->toBe(
+        [],
+        '静的 ::afterCommit() (DB::afterCommit 等) への退避が残っています。付随的副作用 (AG-127) は'
+        .'tx の外へ出し、キュー投入は業務 tx の内側で行ってください。',
+    );
+});
+
+test('D3: 母集団に ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit を implement するクラスは 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitInterfaces(
+        QueueDispatchDeferralInventory::deferralCandidateClasses(),
+    );
+
+    expect($hits)->toBe(
+        [],
+        '宣言的迂回 (grep afterCommit では見えない) が残っています: '.implode(', ', $hits),
+    );
+});
+
+test('D4: after_commit=true を持ってよい接続は sync だけである', function (): void {
+    $connections = config('queue.connections');
+    expect($connections)->toBeArray();
+
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections((array) $connections);
+
+    expect($hits)->toBe([], 'sync 以外の接続で after_commit=true になっています: '.implode(', ', $hits));
+});
+
+test('D5: 母集団に commit 後ずらしを発動する $afterCommit を持つクラスは 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitProperty(
+        QueueDispatchDeferralInventory::deferralCandidateClasses(),
+    );
+
+    expect($hits)->toBe(
+        [],
+        'commit 後ずらしを発動する $afterCommit (truthy な既定値 / promoted parameter) を持つクラスがあります: '
+        .implode(', ', $hits),
+    );
+});
+
+test('D5: first-party ランタイム PHP に $afterCommit への truthy な単一リテラル代入は 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+        QueueDispatchDeferralInventory::runtimePhpFiles(),
+    );
+
+    expect(queueDeferralFormatHits($hits))->toBe([], '$afterCommit への truthy な代入が残っています。');
+});
+
+test('D6: app/ の全クラスに ShouldDispatchAfterCommit を implement するものは 1 件も無い', function (): void {
+    // ★ event クラス側の commit 後ずらし。Events\Dispatcher::dispatch() がこの interface を見て
+    //   **イベント発火ごと** commit 後へ回すため、queued listener がぶら下がっていると
+    //   enqueue も commit 後になる (D1〜D5 のどれにも映らない第 4 の迂回路)。
+    //   event に marker interface は無いので、母集団は app/ の全クラス (超集合) で見る。
+    $hits = QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents(
+        QueuedJobPopulation::appClasses(),
+    );
+
+    expect($hits)->toBe(
+        [],
+        'ShouldDispatchAfterCommit を implement するクラスがあります (event 発火ごと commit 後へ'
+        .'ずれるため、queued listener の enqueue も commit 後になる): '.implode(', ', $hits),
+    );
+});
+
+test('負のコントロール: ShouldDispatchAfterCommit 実装ダミークラスを D6 が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectDispatchAfterCommitEvents([DeferralProbeAfterCommitEvent::class]))
+        ->toBe([DeferralProbeAfterCommitEvent::class]);
+});
+
+test('母集団: appClasses() は 0 件でなく、ShouldQueue 実装も Mailable も含む超集合である', function (): void {
+    $appClasses = QueuedJobPopulation::appClasses();
+
+    expect(count($appClasses))->toBeGreaterThan(0);
+    foreach (QueueDispatchDeferralInventory::deferralCandidateClasses() as $class) {
+        expect($appClasses)->toContain($class);
+    }
+});
+
+// ------------------------------------------------------------------
+// 母集団の境界と 0 件 fail
+// ------------------------------------------------------------------
+
+test('母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である', function (): void {
+    $finder = Finder::create()
+        ->in(array_map(static fn (string $root): string => base_path($root), QUEUE_DEFERRAL_EXPECTED_ROOTS))
+        ->files()
+        ->name('*.php');
+
+    $expected = [];
+    foreach ($finder as $file) {
+        $expected[] = (string) $file->getRealPath();
+    }
+    sort($expected);
+
+    $actual = QueueDispatchDeferralInventory::runtimePhpFiles();
+
+    expect(array_values(array_diff($expected, $actual)))->toBe([], '実装列挙から漏れているファイルがあります');
+    expect(array_values(array_diff($actual, $expected)))->toBe([], '実装列挙に余分なファイルがあります');
+});
+
+test('母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する', function (): void {
+    expect(QueueDispatchDeferralInventory::RUNTIME_ROOTS)
+        ->toEqualCanonicalizing(QUEUE_DEFERRAL_EXPECTED_ROOTS);
+});
+
+test('母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される', function (): void {
+    // ★ ループはテスト側リテラルを回す (定数を回すと定数を削るだけで空振りする)
+    foreach (QUEUE_DEFERRAL_EXPECTED_ROOTS as $root) {
+        $files = QueueDispatchDeferralInventory::phpFilesUnder([base_path($root)]);
+
+        expect(count($files))->toBeGreaterThan(0, "ルート {$root} から 1 件も列挙されていません");
+    }
+});
+
+test('母集団: ShouldQueue 実装クラスの列挙は 0 件でない', function (): void {
+    expect(count(QueuedJobPopulation::shouldQueueClasses()))->toBeGreaterThan(0);
+});
+
+test('母集団: Mailable subclass の列挙は 0 件でない', function (): void {
+    expect(QueuedJobPopulation::mailableClasses())->toContain(InquiryReceivedMail::class);
+});
+
+test('母集団: deferralCandidateClasses() は unique(shouldQueueClasses ∪ mailableClasses) と一致し、Mailable 全件を含む', function (): void {
+    $expected = array_values(array_unique(array_merge(
+        QueuedJobPopulation::shouldQueueClasses(),
+        QueuedJobPopulation::mailableClasses(),
+    )));
+    sort($expected);
+
+    $actual = QueueDispatchDeferralInventory::deferralCandidateClasses();
+
+    expect($actual)->toBe($expected);
+    foreach (QueuedJobPopulation::mailableClasses() as $mailable) {
+        expect($actual)->toContain($mailable);
+    }
+    // ShouldQueue 側の代表 (Notification) も含まれている = 和集合が片側に潰れていない
+    expect($actual)->toContain(PaymentFailedNotification::class);
+});
+
+test('母集団: Mailable が ShouldQueue を併記しているあいだ和集合は degenerate である (trip-wire)', function (): void {
+    // ★ **これは「壊れたら直す」テストではなく、被覆の穴を可視化する trip-wire である。**
+    //   現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
+    //   shouldQueueClasses() 片側へ潰す変異は**結果が変わらず検出できない** (mutation #24 の実測)。
+    //   この包含が崩れた瞬間 (= Mailable から implements ShouldQueue が外れた瞬間) に、
+    //   和集合は実効を持ち D3 / D5 の 0 件 pin が Mailable を独立に見るようになる。
+    //   本テストはその転換点を**赤で知らせる**ためのものである。
+    $outside = array_values(array_diff(
+        QueuedJobPopulation::mailableClasses(),
+        QueuedJobPopulation::shouldQueueClasses(),
+    ));
+
+    expect($outside)->toBe(
+        [],
+        'ShouldQueue を実装しない Mailable が現れました: '.implode(', ', $outside).PHP_EOL
+        .'これ自体は不具合ではありません。ただしこの時点から deferralCandidateClasses() の'
+        .'和集合が実効を持つため、(1) 同関数が今も mergeCandidateClasses() 経由であること、'
+        .'(2) D3 / D5 の 0 件 pin が当該 Mailable を含めて緑であること を確認し、'
+        .'確認できたら本 trip-wire の期待値をその Mailable を含む形へ更新してください'
+        .' (devnotes の mutation-evidence #24 参照)。',
+    );
+});
+
+test('負のコントロール: mergeCandidateClasses() は disjoint な 2 集合の和を返す (片側に潰れていない)', function (): void {
+    // ★ 現状 mailableClasses() ⊆ shouldQueueClasses() のため、deferralCandidateClasses() を
+    //   片側へ潰す変異は**実結果が変わらず検出できない**。和集合を取る意図そのものは
+    //   ここで disjoint な集合を食わせて固定する (併記が外れて集合が乖離した瞬間に効く)。
+    $merged = QueueDispatchDeferralInventory::mergeCandidateClasses(
+        [DeferralProbeAfterCommitJob::class],
+        [DeferralProbeMailable::class],
+    );
+
+    expect($merged)->toContain(DeferralProbeAfterCommitJob::class);
+    expect($merged)->toContain(DeferralProbeMailable::class);
+    expect($merged)->toHaveCount(2);
+});
+
+test('母集団: queue.connections は 0 件でない', function (): void {
+    $connections = config('queue.connections');
+    expect($connections)->toBeArray();
+    expect(count((array) $connections))->toBeGreaterThan(0);
+});
+
+// ------------------------------------------------------------------
+// 負のコントロール (検出器が生きていることの固定)
+// ------------------------------------------------------------------
+
+test('負のコントロール: fixture ツリーを列挙して D1 を検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nSomeJob::dispatch(1)->afterCommit();\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectInFiles(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect(array_column($hits, 'kind'))->toContain('D1');
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: fixture ツリーを列挙して D2 を検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nDB::afterCommit(static fn () => null);\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectInFiles(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect(array_column($hits, 'kind'))->toContain('D2');
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitJob::class]))
+        ->toBe([DeferralProbeAfterCommitJob::class]);
+});
+
+test('負のコントロール: ShouldHandleEventsAfterCommit 実装ダミークラスを D3 が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitInterfaces([DeferralProbeAfterCommitListener::class]))
+        ->toBe([DeferralProbeAfterCommitListener::class]);
+});
+
+test('負のコントロール: after_commit=true の非 sync 接続を D4 が検出する', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitEnabledConnections([
+        'sync' => ['driver' => 'sync', 'after_commit' => true],
+        'database' => ['driver' => 'database', 'after_commit' => false],
+        'database-analysis' => ['driver' => 'database', 'after_commit' => true],
+    ]);
+
+    expect($hits)->toBe(['database-analysis']);
+});
+
+test('負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (プロパティ) が検出する', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeAfterCommitPropertyJob::class]))
+        ->toBe([DeferralProbeAfterCommitPropertyJob::class]);
+});
+
+test('負のコントロール: ShouldQueue を実装しないダミー Mailable の $afterCommit = true を D5 (既定値) が検出する', function (): void {
+    // Mailable を母集団に含めない実装では、このクラスは検出器へ届かない
+    expect(is_subclass_of(DeferralProbeMailable::class, ShouldQueue::class))->toBeFalse();
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeMailable::class]))
+        ->toBe([DeferralProbeMailable::class]);
+});
+
+test('負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\nfinal class Probe\n{\n    public function __construct()\n    {\n        \$this->afterCommit = true;\n    }\n}\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(1);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: $job->afterCommit = true; (外部からの代入) も D5 (代入) が検出する', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$job = new SomeJob;\n\$job->afterCommit = true;\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(1);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: truthy だが true ではない既定値 (= 1) も D5 (既定値) が検出する', function (): void {
+    // vendor の Queue::shouldDispatchAfterCommit() は isset() で拾った値を真偽値文脈で評価するため、
+    // `=== true` だけを見る実装ではこの迂回路がすり抜ける
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbeTruthyAfterCommitJob::class]))
+        ->toBe([DeferralProbeTruthyAfterCommitJob::class]);
+});
+
+test('負のコントロール: constructor promotion の $afterCommit は既定値に依らず D5 (プロパティ) が検出する', function (): void {
+    // promoted property の既定値は getDefaultProperties() に現れないため、
+    // プロパティ宣言だけを見る実装ではすり抜ける。
+    // ★ 既定値が false でも違反にする — 呼び出し側が `new Job(afterCommit: true)` で任意に渡せるため
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbePromotedAfterCommitJob::class]))
+        ->toBe([DeferralProbePromotedAfterCommitJob::class]);
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([DeferralProbePromotedFalseAfterCommitJob::class]))
+        ->toBe([DeferralProbePromotedFalseAfterCommitJob::class]);
+});
+
+test('偽陰性の負のコントロール: $afterCommit の既定値が falsy (null / false / 0) のクラスは D5 が検出しない', function (): void {
+    // vendor も真偽値文脈で評価するため、falsy な既定値では commit 後ずらしは発動しない。
+    // ここを違反にすると Queueable trait を使う全 job が偽陽性になる
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([
+        DeferralProbeNullAfterCommitJob::class,
+        DeferralProbeFalseAfterCommitJob::class,
+        DeferralProbeZeroAfterCommitJob::class,
+    ]))->toBe([]);
+});
+
+test('負のコントロール: truthy リテラルの代入 (= 1 / = \'yes\') も D5 (代入) が検出する', function (): void {
+    // vendor は代入値を真偽値文脈で見るため、`= true` だけを見る実装ではすり抜ける
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$a->afterCommit = 1;\n\$b->afterCommit = 'yes';\n\$c->afterCommit = 2.5;\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(3);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('負のコントロール: 基数付き数値リテラル (0x / 0b / 0o / 先頭 0 の 8 進) の非ゼロも D5 (代入) が検出する', function (): void {
+    // 素の `(float) '0x1'` は 0.0 になるため、基数を解さない実装ではすり抜ける
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$a->afterCommit = 0x1;\n\$b->afterCommit = 0b1;\n\$c->afterCommit = 0o1;\n"
+            ."\$d->afterCommit = 010;\n\$e->afterCommit = 1_000;\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toHaveCount(5);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('偽陰性の負のコントロール: 基数付きのゼロ / エスケープ入り文字列は D5 (代入) が検出しない', function (): void {
+    // 基数付きゼロは実行時も falsy。エスケープ入り文字列は評価不能へ倒す
+    // ("\x30" の実行時値は "0" = falsy であり、ソース表記のまま truthy 判定すると偽陽性になる)
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$a->afterCommit = 0x0;\n\$b->afterCommit = 0b0;\n\$c->afterCommit = 0o0;\n"
+            ."\$d->afterCommit = 000;\n\$e->afterCommit = 0e5;\n\$f->afterCommit = \"\\x30\";\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toBe([]);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('偽陰性の負のコントロール: falsy リテラル / 評価不能な式の代入は D5 (代入) が検出しない', function (): void {
+    // falsy は vendor でも発動しない。変数・式は静的に真偽値を決められないため検出しない
+    // (0 件 pin の既知の穴。docblock と docs/architecture.md に明記済み)
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n\$a->afterCommit = false;\n\$b->afterCommit = null;\n\$c->afterCommit = 0;\n"
+            ."\$d->afterCommit = '';\n\$e->afterCommit = '0';\n\$f->afterCommit = \$flag;\n",
+        );
+
+        $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+            QueueDispatchDeferralInventory::phpFilesUnder([$root]),
+        );
+
+        expect($hits)->toBe([]);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+test('偽陽性の負のコントロール: コメント / docblock / 文字列リテラル中の ->afterCommit() は検出しない', function (): void {
+    $root = queueDeferralFixtureRoot();
+
+    try {
+        file_put_contents(
+            $root.'/nested/Probe.php',
+            "<?php\n\n/**\n * 旧主張: SomeJob::dispatch()->afterCommit() で commit 後に発火する\n */\n"
+            ."// DB::afterCommit(fn () => null);\n"
+            ."\$sql = 'SomeJob::dispatch()->afterCommit()';\n"
+            ."\$assignment = '\$this->afterCommit = true;';\n",
+        );
+
+        $paths = QueueDispatchDeferralInventory::phpFilesUnder([$root]);
+
+        expect(QueueDispatchDeferralInventory::detectInFiles($paths))->toBe([]);
+        expect(QueueDispatchDeferralInventory::detectAfterCommitAssignments($paths))->toBe([]);
+    } finally {
+        queueDeferralRemoveFixture($root);
+    }
+});
+
+// ------------------------------------------------------------------
+// 契約の固定 (黙って 0 件を返さない)
+// ------------------------------------------------------------------
+
+test('phpFilesUnder(): 相対パスを渡すと例外になる', function (): void {
+    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder(['app']))
+        ->toThrow(InvalidArgumentException::class);
+});
+
+test('phpFilesUnder(): 存在しないディレクトリを渡すと例外になる (黙って 0 件を返さない)', function (): void {
+    expect(fn () => QueueDispatchDeferralInventory::phpFilesUnder([base_path('no-such-directory')]))
+        ->toThrow(InvalidArgumentException::class);
+});
diff --git a/tests/Support/Queue/QueueDispatchDeferralInventory.php b/tests/Support/Queue/QueueDispatchDeferralInventory.php
new file mode 100644
index 0000000..88c082b
--- /dev/null
+++ b/tests/Support/Queue/QueueDispatchDeferralInventory.php
@@ -0,0 +1,462 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use FilesystemIterator;
+use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
+use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
+use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
+use RecursiveDirectoryIterator;
+use RecursiveIteratorIterator;
+use ReflectionClass;
+use SplFileInfo;
+use Tests\Support\PhpTokenScan;
+use Tests\Support\QueuedJobPopulation;
+use Webmozart\Assert\Assert;
+
+/**
+ * キュー投入の commit 後ずらし (deferral) を検出する純関数群。
+ *
+ * 【5 種の検出器】`Queue::shouldDispatchAfterCommit()` の解決順
+ * (ShouldQueueAfterCommit → job の `$afterCommit` プロパティ → 接続 config) に
+ * 1:1 で対応させ、どの層からも迂回できないようにしている。
+ * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
+ * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
+ * - D3 宣言的迂回 interface の実装            … **リフレクション判定** (文字列走査ではない)。
+ *   `ShouldQueueAfterCommit` に加え **`ShouldHandleEventsAfterCommit`** も見る
+ *   (`Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
+ *   この interface でも commit 後ずらしを発動するため。ShouldQueue な listener では
+ *   これが**キュー投入そのもの**を commit 後へずらす)
+ * - D4 config の `after_commit => true`       … sync 以外の接続
+ * - D6 `ShouldDispatchAfterCommit` の実装      … **event クラス側**の commit 後ずらし。
+ *   `Events\Dispatcher::dispatch()` がこの interface を見て**イベント発火ごと** commit 後へ回すため、
+ *   queued listener がぶら下がっていると enqueue も commit 後になる。
+ *   event には marker interface が無く母集団を静的に絞れないので、**`app/` の全クラス**という
+ *   超集合を deny-by-default で見る (Codex 実装レビュー 確認ラウンド)
+ * - D5 `Queueable` の `$afterCommit` プロパティ … **プロパティ (既定値 + constructor promotion)
+ *   はリフレクション** + **実行時代入は token 走査**。`public $afterCommit = true;` /
+ *   `public function __construct(public bool $afterCommit = ...)` / `$this->afterCommit = true;` は
+ *   **D1〜D4 のどれにも映らない第 3 の迂回路**であり、これを落とすと「0 件 pin」の主張が嘘になる
+ *
+ * 【D3 / D5(既定値) の母集団は `ShouldQueue` 実装だけでは足りない — Mailable を足す】
+ * `Mailable` は **`ShouldQueue` を実装していなくても** `Mail::to(...)->queue()` /
+ * `Mail::queue()` でキューへ載る。このとき vendor の `SendQueuedMailable::__construct()` が
+ * `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
+ * **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
+ * `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` が
+ * そのまま commit 後ずらしになる。
+ * **本リポジトリでは現に `CreateInquiryAction` が `Mail::to(...)->queue(...)` を使っている**
+ * (仮想の穴ではない。現行 2 クラスは `ShouldQueue` を併記しているので今は母集団に入るが、
+ * 併記を外した瞬間に検出器から消える)。
+ * よって D3 / D5(既定値) の母集団は
+ * **`QueuedJobPopulation::shouldQueueClasses()` ∪ `QueuedJobPopulation::mailableClasses()`** とする。
+ * Notification と listener は vendor 側 (`NotificationSender` / `Events\Dispatcher`) が
+ * `ShouldQueue` を要求するため `shouldQueueClasses()` で尽きており、追加は要らない。
+ *
+ * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
+ * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
+ * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
+ * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
+ *
+ * 【D1 / D2 / D5(代入) は「文字列 grep」ではなく token 走査で行う】
+ * 既存の `Tests\Support\PhpTokenScan::normalize()` を再利用する
+ * (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み)。
+ * 素の `str_contains()` にすると **本設計自身が破綻する** — 契約の反転 docblock は
+ * 旧主張として `->afterCommit()` を引用するため、コメントを見る検出器では
+ * 反転を書いた瞬間に gate が落ちる。token 走査ならコメントも文字列リテラルも
+ * (`T_CONSTANT_ENCAPSED_STRING` を明示除外して) 対象外にできる。
+ *
+ * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
+ * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
+ * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
+ * 偽グリーンを閉じられない。
+ *
+ * 【保証しないもの (誇張しない)】token 走査でも**動的な迂回**には沈黙する —
+ * `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
+ * `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
+ * (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
+ */
+final class QueueDispatchDeferralInventory
+{
+    /**
+     * D1/D2/D5(代入) の走査母集団となる first-party ランタイム PHP のルート。
+     * **`app/` だけでは狭い** — `DB::afterCommit` は `routes/console.php` や
+     * `bootstrap/app.php` にも書けるため。
+     * `vendor/` / `tests/` / `storage/` は対象外 (前者は自リポジトリの管轄外、
+     * 後者 2 つはランタイム経路ではない)。この定数が母集団境界の唯一の正本。
+     *
+     * @var list<string>
+     */
+    public const RUNTIME_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];
+
+    /**
+     * 指定ディレクトリ配下の PHP ファイル絶対パス (昇順) を列挙する純関数。
+     * **ルートを引数で受ける**ことで、負のコントロールが fixture root を渡して
+     * 「列挙 → 読み込み → 検出」の**列挙部分まで**同じコードを通せる。
+     *
+     * ★ **引数は絶対パス**である。相対ルートを受けて内部で `base_path()` を掛ける形にすると、
+     *   `sys_get_temp_dir()` 配下の fixture root を渡したときにパスが連結されて列挙できない。
+     *   本番側の相対→絶対変換は `runtimePhpFiles()` が行う。
+     *
+     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
+     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
+     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、母集団 0 件 fail の
+     *   意図が空洞化するため。
+     *
+     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
+     * @return list<string>
+     */
+    public static function phpFilesUnder(array $absoluteRoots): array
+    {
+        $paths = [];
+        foreach ($absoluteRoots as $root) {
+            Assert::true(
+                str_starts_with($root, DIRECTORY_SEPARATOR),
+                "phpFilesUnder() には絶対パスを渡すこと (受け取った値: {$root})",
+            );
+            Assert::directory($root, "phpFilesUnder() のルートが存在しません: {$root}");
+
+            $iterator = new RecursiveIteratorIterator(
+                new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
+            );
+            foreach ($iterator as $file) {
+                Assert::isInstanceOf($file, SplFileInfo::class);
+                if (! $file->isFile() || $file->getExtension() !== 'php') {
+                    continue;
+                }
+                $paths[] = $file->getPathname();
+            }
+        }
+
+        sort($paths);
+
+        return array_values(array_unique($paths));
+    }
+
+    /**
+     * 本番母集団 (RUNTIME_ROOTS を絶対パスへ変換して列挙する)。
+     *
+     * @return list<string>
+     */
+    public static function runtimePhpFiles(): array
+    {
+        return self::phpFilesUnder(array_map(
+            static fn (string $root): string => base_path($root),
+            self::RUNTIME_ROOTS,
+        ));
+    }
+
+    /**
+     * D1 (`->afterCommit(`) と D2 (`DB::afterCommit(`) を token 走査で検出する。
+     *
+     * @param  list<string>  $paths
+     * @return list<array{path: string, line: int, kind: string}>
+     */
+    public static function detectInFiles(array $paths): array
+    {
+        $hits = [];
+        foreach ($paths as $path) {
+            $source = file_get_contents($path);
+            Assert::string($source, "ファイルを読み込めません: {$path}");
+
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+            for ($i = 0; $i < $count; $i++) {
+                $token = $tokens[$i];
+                $name = $tokens[$i + 1] ?? null;
+                $open = $tokens[$i + 2] ?? null;
+                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
+                    continue;
+                }
+                if ($open === null || $open['text'] !== '(') {
+                    continue; // 呼び出しでない (プロパティ参照など) は D5 の担当
+                }
+
+                $id = $token['id'];
+                if ($id === T_OBJECT_OPERATOR || $id === T_NULLSAFE_OBJECT_OPERATOR) {
+                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D1'];
+
+                    continue;
+                }
+                if ($id === T_DOUBLE_COLON) {
+                    // `DB::afterCommit(` / `Illuminate\Support\Facades\DB::afterCommit(`
+                    $hits[] = ['path' => $path, 'line' => $name['line'], 'kind' => 'D2'];
+                }
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D3: 宣言的迂回 interface を implement するクラス。
+     * `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` の**両方**を見る
+     * (`ReflectionClass::implementsInterface()` なので中間 interface / 親クラス経由も拾う)。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectAfterCommitInterfaces(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            $reflection = new ReflectionClass($class);
+            if ($reflection->implementsInterface(ShouldQueueAfterCommit::class)
+                || $reflection->implementsInterface(ShouldHandleEventsAfterCommit::class)) {
+                $hits[] = $reflection->getName();
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D6: `ShouldDispatchAfterCommit` を implement するクラス (event 側の commit 後ずらし)。
+     * 母集団は `QueuedJobPopulation::appClasses()` (= `app/` の全クラス) を渡す想定。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectDispatchAfterCommitEvents(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            if ((new ReflectionClass($class))->implementsInterface(ShouldDispatchAfterCommit::class)) {
+                $hits[] = $class;
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D3 / D5(既定値) の母集団 = `ShouldQueue` 実装 ∪ Mailable subclass。
+     * **和集合にする理由**は class docblock を参照。重複は除去し昇順で返す。
+     *
+     * @return list<class-string>
+     */
+    public static function deferralCandidateClasses(): array
+    {
+        return self::mergeCandidateClasses(
+            QueuedJobPopulation::shouldQueueClasses(),
+            QueuedJobPopulation::mailableClasses(),
+        );
+    }
+
+    /**
+     * 和集合の生成そのものを**引数で検証できる形**に切り出した純関数。
+     *
+     * ★ **なぜ切り出すか**: 現状の本リポジトリでは `mailableClasses()` ⊆ `shouldQueueClasses()`
+     *   (2 つの Mailable が `implements ShouldQueue` を併記している) のため、
+     *   `deferralCandidateClasses()` を「shouldQueueClasses だけ返す」に潰しても**結果が変わらず、
+     *   ブラックボックスのテストでは検出できない**。和集合を取る意図そのものは、
+     *   ここへ disjoint な集合を食わせる負のコントロールで固定する。
+     *   (併記が外れて集合が乖離した瞬間に、D3 / D5 の 0 件 pin が実効を持つ)
+     *
+     * @param  list<class-string>  $shouldQueueClasses
+     * @param  list<class-string>  $mailableClasses
+     * @return list<class-string>
+     */
+    public static function mergeCandidateClasses(array $shouldQueueClasses, array $mailableClasses): array
+    {
+        $classes = array_values(array_unique(array_merge($shouldQueueClasses, $mailableClasses)));
+        sort($classes);
+
+        return $classes;
+    }
+
+    /**
+     * D4: `after_commit => true` を持つ接続名 (sync を除く)。
+     *
+     * @param  array<mixed>  $connections
+     * @return list<string> 違反した接続名
+     */
+    public static function detectAfterCommitEnabledConnections(array $connections): array
+    {
+        $hits = [];
+        foreach ($connections as $name => $config) {
+            if ($name === 'sync') {
+                continue; // sync の after_commit=true は M1 の契約 (R4 が別途固定する)
+            }
+            if (! is_array($config)) {
+                continue;
+            }
+            if (($config['after_commit'] ?? null) === true) {
+                $hits[] = (string) $name;
+            }
+        }
+
+        sort($hits);
+
+        return $hits;
+    }
+
+    /**
+     * D5 (既定値): `$afterCommit` プロパティの既定値が「commit 後ずらしを起こす値」のクラス。
+     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
+     * コンストラクタ引数が必要な job でも判定できる)。
+     *
+     * ★ **判定は vendor と同じ真偽値文脈 (`(bool) $value`)** である。設計は `=== true` の
+     *   厳密比較を指定していたが、vendor 側 (`Queue::shouldDispatchAfterCommit()`) は
+     *   `isset($job->afterCommit)` で拾った値を**そのまま真偽値文脈で**評価するため、
+     *   `1` / `'yes'` のような truthy 値でも commit 後ずらしが起きる
+     *   (`=== true` だけだとすり抜ける)。逆に `null` / `false` / `0` / `''` は
+     *   vendor 側でも発動しないので**違反にしない** (設計が禁じた偽陽性の失敗形)。
+     *
+     * ★ **constructor promotion の `$afterCommit` は値に依らず違反**である。
+     *   promoted parameter は呼び出し側が `new SomeJob(afterCommit: true)` で任意に渡せるため、
+     *   既定値が `false` でも 0 件 pin の穴になる (Codex 実装レビュー Round 2)。
+     *   promoted property の既定値は `getDefaultProperties()` に現れないため、
+     *   プロパティ宣言だけを見る実装ではこの経路が丸ごと見えない。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectAfterCommitProperty(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            $reflection = new ReflectionClass($class);
+
+            $defaults = $reflection->getDefaultProperties();
+            if (array_key_exists('afterCommit', $defaults) && self::defersAfterCommit($defaults['afterCommit'])) {
+                $hits[] = $reflection->getName();
+
+                continue;
+            }
+
+            $constructor = $reflection->getConstructor();
+            if ($constructor === null) {
+                continue;
+            }
+            foreach ($constructor->getParameters() as $parameter) {
+                // promoted な $afterCommit は**既定値に依らず**違反 (呼び出し側が任意に渡せる)
+                if ($parameter->getName() === 'afterCommit' && $parameter->isPromoted()) {
+                    $hits[] = $reflection->getName();
+
+                    break;
+                }
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * 単一トークンのリテラルを真偽値へ評価する (評価できなければ null)。
+     * `true` / 非ゼロ数値 / `'0'` 以外の非空文字列が truthy、
+     * `false` / `null` / `0` / `''` / `'0'` が falsy。それ以外 (変数・式・配列) は null。
+     *
+     * @param  array{id: int|null, text: string, line: int}  $token
+     */
+    private static function truthyLiteral(array $token): ?bool
+    {
+        if ($token['id'] === T_STRING) {
+            return match (strtolower($token['text'])) {
+                'true' => true,
+                'false', 'null' => false,
+                default => null, // 定数参照は評価しない
+            };
+        }
+        if ($token['id'] === T_LNUMBER || $token['id'] === T_DNUMBER) {
+            // ★ 基数付きリテラル (0x / 0b / 0o / 先頭 0 の 8 進) と数値区切り `_` を扱う。
+            //   素の `(float) '0x1'` は 0.0 になり、truthy な値を falsy と誤判定する。
+            $text = str_replace('_', '', strtolower($token['text']));
+
+            if (str_starts_with($text, '0x')) {
+                return (float) hexdec(substr($text, 2)) !== 0.0;
+            }
+            if (str_starts_with($text, '0b')) {
+                return (float) bindec(substr($text, 2)) !== 0.0;
+            }
+            if (str_starts_with($text, '0o')) {
+                return (float) octdec(substr($text, 2)) !== 0.0;
+            }
+            if (preg_match('/^0[0-7]+$/', $text) === 1) {
+                return (float) octdec($text) !== 0.0;   // 旧式 8 進 (先頭 0)
+            }
+
+            return ((float) $text) !== 0.0;
+        }
+        if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
+            $literal = substr($token['text'], 1, -1);
+            // ★ エスケープを含む文字列は**評価不能に倒す** (`"\x30"` の実行時値は "0" = falsy だが、
+            //   ソース表記のままだと truthy と誤判定して偽陽性になる)。完全なエスケープ評価は行わない。
+            if (str_contains($literal, '\\')) {
+                return null;
+            }
+
+            return $literal !== '' && $literal !== '0';
+        }
+
+        return null;
+    }
+
+    /**
+     * その既定値が commit 後ずらしを発動させるか。
+     * vendor (`Queue::shouldDispatchAfterCommit()`) が真偽値文脈で評価するため、判定も同じにする。
+     */
+    private static function defersAfterCommit(mixed $value): bool
+    {
+        return (bool) $value;
+    }
+
+    /**
+     * D5 (実行時代入): `->afterCommit = <truthy リテラル>` の **token 走査**。
+     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = 1;`
+     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
+     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + <リテラル 1 個> + `;` の並びで行う。
+     *
+     * ★ **truthy 判定は vendor と同じ真偽値文脈**である (`true` だけでなく `1` / `'yes'` も違反。
+     *   `false` / `null` / `0` / `''` / `'0'` は違反にしない)。`= true` だけを見る実装では
+     *   `$this->afterCommit = 1;` がすり抜ける (Codex 実装レビュー Round 3)。
+     *
+     * ★ **保証しないもの**: 評価するのは**単一リテラルの代入**だけである。
+     *   変数 (`= $flag`)・定数・式 (`= 1 + 1`)・関数呼び出し・配列、および
+     *   **エスケープを含む文字列リテラル** (`= "\x31"`) は真偽値を静的に決められないため
+     *   **検出しない** (0 件 pin の既知の穴。誇張しない)。
+     *   数値は基数付き (`0x` / `0b` / `0o` / 先頭 0 の 8 進) と数値区切り `_` まで評価する。
+     *
+     * @param  list<string>  $paths
+     * @return list<array{path: string, line: int}>
+     */
+    public static function detectAfterCommitAssignments(array $paths): array
+    {
+        $hits = [];
+        foreach ($paths as $path) {
+            $source = file_get_contents($path);
+            Assert::string($source, "ファイルを読み込めません: {$path}");
+
+            $tokens = PhpTokenScan::normalize($source);
+            $count = count($tokens);
+            for ($i = 0; $i < $count; $i++) {
+                $operator = $tokens[$i];
+                if ($operator['id'] !== T_OBJECT_OPERATOR && $operator['id'] !== T_NULLSAFE_OBJECT_OPERATOR) {
+                    continue;
+                }
+                $name = $tokens[$i + 1] ?? null;
+                $assign = $tokens[$i + 2] ?? null;
+                $value = $tokens[$i + 3] ?? null;
+                if ($name === null || $name['id'] !== T_STRING || $name['text'] !== 'afterCommit') {
+                    continue;
+                }
+                if ($assign === null || $assign['text'] !== '=') {
+                    continue;
+                }
+                $terminator = $tokens[$i + 4] ?? null;
+                if ($value === null || $terminator === null || $terminator['text'] !== ';') {
+                    continue; // 単一リテラルの代入以外は静的に真偽値を決められない (既知の穴)
+                }
+                if (self::truthyLiteral($value) !== true) {
+                    continue; // falsy リテラル / 評価不能はいずれも違反にしない
+                }
+
+                $hits[] = ['path' => $path, 'line' => $name['line']];
+            }
+        }
+
+        return $hits;
+    }
+}
diff --git a/tests/Support/QueuedJobPopulation.php b/tests/Support/QueuedJobPopulation.php
index 2a7543e..6df1705 100644
--- a/tests/Support/QueuedJobPopulation.php
+++ b/tests/Support/QueuedJobPopulation.php
@@ -6,6 +6,7 @@
 
 use FilesystemIterator;
 use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Mail\Mailable;
 use RecursiveDirectoryIterator;
 use RecursiveIteratorIterator;
 use ReflectionClass;
@@ -52,6 +53,73 @@ public static function shouldQueueClasses(): array
         return $classes;
     }
 
+    /**
+     * app/ 配下の `Illuminate\Mail\Mailable` subclass を **`ShouldQueue` 実装の有無を問わず**列挙する。
+     *
+     * ★ **なぜ `shouldQueueClasses()` と別に要るか**: Mailable は `ShouldQueue` を実装して
+     *   いなくても `Mail::to(...)->queue()` / `Mail::queue()` でキューへ載る。このとき vendor の
+     *   `SendQueuedMailable::__construct()` が
+     *   `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
+     *   **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
+     *   `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` がそのまま
+     *   commit 後ずらしになる (QueueDispatchAtomicityInventoryTest の D3 / D5 が使う)。
+     *
+     * ★ **`isInstantiable()` は要求しない**。first-party の abstract な base Mailable は
+     *   `$afterCommit` の既定値や宣言的迂回 interface を concrete subclass へ伝播させる
+     *   carrier であり、除外すると 0 件 pin が抜ける。vendor の `Illuminate\Mail\Mailable`
+     *   本体は `app/` 探索に入らないので母集団には現れない
+     *   (`shouldQueueClasses()` 側の `isInstantiable()` は既存挙動なので変更しない)。
+     *
+     * @return list<class-string>
+     */
+    public static function mailableClasses(): array
+    {
+        $classes = [];
+        foreach (self::appPhpFiles() as $path) {
+            $class = self::classNameForPath($path);
+            if (! class_exists($class)) {
+                continue;
+            }
+
+            $reflection = new ReflectionClass($class);
+            if (! $reflection->isSubclassOf(Mailable::class)) {
+                continue;
+            }
+
+            $classes[] = $reflection->getName();
+        }
+
+        sort($classes);
+
+        return $classes;
+    }
+
+    /**
+     * app/ 配下の **読み込み可能な全クラス**を列挙する (interface / trait / enum を含む)。
+     *
+     * ★ D6 (`ShouldDispatchAfterCommit`) の母集団に使う。event クラスには marker interface が
+     *   無く「どれが event か」を静的に決められないため、**app/ 全クラスという超集合**を
+     *   母集団にして deny-by-default で見る (event 検出のヒューリスティクスを持たない)。
+     *
+     * @return list<class-string>
+     */
+    public static function appClasses(): array
+    {
+        $classes = [];
+        foreach (self::appPhpFiles() as $path) {
+            $class = self::classNameForPath($path);
+            if (! class_exists($class) && ! interface_exists($class) && ! trait_exists($class) && ! enum_exists($class)) {
+                continue;
+            }
+
+            $classes[] = (new ReflectionClass($class))->getName();
+        }
+
+        sort($classes);
+
+        return $classes;
+    }
+
     /**
      * app/ 配下の PHP ファイル絶対パス一覧。
      *

```

---

## 再検証結果

- `composer phpstan`: OK (838 files, No errors)
- `vendor/bin/pint --test`: passed
- `composer test`: **4102 tests / 4100 passed / 0 failed / 2 skipped** (全レーン)
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1268) / `pnpm build` /
  `pnpm typecheck:packages` / `build:packages` / `test:packages` (106): passed
- `composer test:browser` は route/UI を変更していないため省略

---

## 質問

上記 2 件は解消しているか。新たな問題があれば [Critical] / [Warning] / [Suggestion] で指摘し、
最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示せよ。
