【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【思考原則 — AGENTS.md より】

1. **フレームワークのレンジ内でやる**。自前機構の前に Laravel / 同梱モジュールの公式作法を確認する
2. **今必要なものだけ作る**(オーバーエンジニアリング禁止。「あったら便利」は作らない)
3. **後方互換の並走を残さない**。書き換えると決めたら同じ PR で旧実装を消す
4. **別物の概念を「似ているから」で統合しない**
5. **テストファースト**。fail を確認してから実装に入る
6. **タコツボ実装を避ける**。各ステップで他要素との結合観点を確認する

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【セキュリティ不変条件の要点 (アプリ都合で緩めない) — AGENTS.md より】
tenant キー不信 / 子は親に属する(認可より前に 404) / cross-org 不可 / untrusted 文字列は UserInput 型経由 / 権限判定は laratrust_team_id 明示 / PII は CipherSweet / 課金の冪等性(webhook は冪等マシン、チケットは reserve→commit/release の 2 フェーズ) / 外部 URL は SSRF 検査経由 / 変更系 route は認可を通る / 層 2(テナント境界 404)は層 3(認可 403)より前 / キャッシュに入れるのは素のデータだけ。

【ドメイン固有規約の関連項目 — AGENTS.md より】
- シナリオ整合の共有ロック規約: cuts / video_manuals.scenario_version / status を書く全経路は、対象 VideoManual 行を lockForUpdate() した同一トランザクション内で反映する (ScenarioWritePathInventoryTest が経路を deny-by-default で固定)
- ジョブの重複実行と結果の一回性: 入口の排他 (ShouldBeUnique / Cache::lock) は best-effort であり保証を担わない。結果の一回性は永続状態遷移と外部側の冪等キーが担う。取り消せない外部副作用 (LLM 呼び出し / S3 PUT / Stripe 課金) の直前には所有権の再検証 (preflight) を置く。キューに載る全クラスは JobExecutionDedupInventoryTest の目録へ登録が必須 (deny-by-default)
- 決済 gateway 失敗の観測語彙 / 2FA 面の step-up 規約 / 流量制限の付与規約 (deny-by-default の目録型 gate が既存の作法)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【特にこの設計で確認してほしい点】
- 「設計で最初に決めるべき論点」(§3) の結論 (sync 接続に after_commit => true を入れる) が、vendor 実測の根拠に照らして妥当か。見落としている波及はないか
- AG-127 の除外の線引き (§3 後半) が「除外 = tx 外 dispatch のまま + 失敗を観測へ」という基準に正しく従っているか
- 「検査が空振りしないことの保証」(§5) が十分か。0 件 pin を持つ gate が実際には何も見ていない状態を防げているか
- 「保証しないもの」(§8) に誇張・抜けがないか
- スコープが過大でないか。思考原則 2 (今必要なものだけ作る) に照らして削れるものはないか。逆に、分割すべきか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
| `AutoRechargeTriggerJob::handle` | (Job 内) | attempt=pending・実行未投入 (reconcile (v) が回収する) |

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

**除外しない (確定 1 を適用 = tx 内へ移す)**

`RunManualAnalysis` / `RunManualRender` ×2 / `DeleteRenderOutputsJob` (finalize) /
`DeleteTakeObjectsJob` ×2 / `SyncBillingCustomerDetails` / `AutoRechargeTriggerJob` /
`ExecuteAutoRechargeAttemptJob` (起票と同時) / `ReuseSubscriptionPaymentMethodJob` /
`SetDefaultPaymentMethodJob`。
いずれも「業務状態が保存された以上、必ず実行されなければならない」もので、
未実行のまま放置すると **ユーザーが再操作しない限り前進しない** か、
**表示と実態が食い違う** (`pm_reuse_dispatched_at` 打刻済みで PM 流用が起きない)。

**除外でも afterCommit でもなく「tx 内へ移す」で処理する境界事例**

`TicketLedgerService::reserve()` の低残高通知 (`DB::afterCommit(fn () => notifyTicketBalanceLow(...))`,
:426)。これは queue dispatch ではない (同期 DB 書き込み) ので確定 1 の母集団外だが、
AG-126 の 4 種検出のひとつ `DB::afterCommit` に該当するため 0 件 pin のために消す必要がある。
**tx 内へ移す**ことで「rollback される外側 tx 内の reserve は通知されない」という既存 pin
(`TicketBalanceLowNotificationTest:104`) は **通知行ごと巻き戻る** という、より強い形で維持される。
代償 (organizations 行ロック保持時間が通知 INSERT 分だけ伸びる) は §6 のリスクに記す。

## 4. 実装方針 (概要)

| # | 施策 | 対象 |
|---|---|---|
| M1 | sync 接続の実行意味論の確定 | `config/queue.php` (`sync` に `after_commit => true`) |
| M2 | 業務ジョブ dispatch の tx 内移設 | `AnalysisJobService` / `RenderJobService` ×2 / `RenderPipeline` / `CaptureTakeService` / `VideoManualService` |
| M3 | Billing の afterCommit 撤去 + tx 内移設 | `BillingCustomerSynchronizer` / `TicketLedgerService` ×2 / `AutoRechargeService`(起票と同時投入へ集約) |
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

M7 の期待集合は **空**である。素直に書くと「検出器が壊れていても緑」になる。したがって:

1. **負のコントロール**: 4 種のパターンをすべて含む固定文字列 (テスト内のヒアドキュメント) を
   検出器に食わせ、**4 件すべてを検出すること**を assert する。検出器が壊れれば
   この assert が落ちる
2. **母集団 0 件で fail**: 走査対象ファイル数 / `ShouldQueue` 実装クラス数が 0 なら fail。
   `QueuedJobPopulation::shouldQueueClasses()` は実測 18 クラスなので、
   下限を「18 件以上」ではなく「**0 件なら fail**」で置く (件数を pin すると
   クラス追加のたびに無関係な失敗が出る)
4. **exact-fit**: 免除目録 (enum) の case 集合と、実際に免除が適用されたファイルの集合の
   **対称差が空**であることを assert する。今回は enum に case を 1 つも置かないので
   「免除は 0 件」= 期待集合と実測集合がともに空であることを、上の 1〜2 が支える

### 5-2. mutation で赤化を確認する手順 (実装時に必ず実施)

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | `QueueDispatchAtomicityGuardTest` (R4) / M9 の順序テスト |
| 2 | `config/queue.php` の `database` の `after_commit` を `true` にする | `QueueDispatchAtomicityGuardTest` (R3) |
| 3 | `config/queue.php` の `database-render` の `connection` を別 DB 名にする | `QueueDispatchAtomicityGuardTest` (R2) |
| 4 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | M9 の「analysis: 業務 tx 内で enqueue される」 |
| 5 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | `QueueDispatchAtomicityInventoryTest` (4 種検出 1) |
| 6 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | 同 (4 種検出 3) |
| 7 | `TicketLedgerService` に `DB::afterCommit` を戻す | 同 (4 種検出 2) |
| 8 | 検出器の正規表現を空文字にする (検出不能化) | 負のコントロール (§5-1 の 1) |
| 9 | `QueuedJobPopulation` を空配列返しにする | 母集団 0 件 fail (§5-1 の 2) |

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
| R-6 | `AutoRechargeTriggerJob` は `ShouldBeUnique`。tx 内 dispatch が rollback すると unique lock が `uniqueFor` (30s) 残る | 30 秒間、同一 org の再 dispatch が no-op になる | `Queue::enqueueUsing` の rollback 時 lock 解放は **afterCommit 経路でしか働かない** (vendor:368-374)。よって 30 秒の窓は受容する (影響は「オートリチャージ起票が 1 周期遅れる」= reconcile (v) が回収する)。**これは保証しないものとして明記する** |
| R-7 | 低残高通知を tx 内へ移すと organizations 行ロック保持時間が伸びる | reserve の直列化点が長くなる | 通知は database channel への INSERT のみ (メンバー数分)。`notifyTicketBalanceLow` の前に `AutoRechargeTriggerJob::dispatch` を置き、通知失敗が投入を飛ばさない順序にする |
| R-8 | PostgreSQL で tx 内の INSERT が失敗すると tx 全体が abort する。`safely()` / `try-catch` が握ると「握ったのに commit で落ちる」 | 業務操作が失敗する | fail-closed 側に倒れるため受容 (無言で通知だけ消えるより良い)。§8 に「保証しないもの」として明記 |
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
- **静的走査 (M7) は文字列パターン検出であり、動的な迂回は検出できない**。
  `$method = 'afterCommit'; $job->$method();` のような書き方、
  `ShouldQueueAfterCommit` を継承した中間 interface、vendor 内の afterCommit 使用には沈黙する
- **起動時 guard (M6) は config の値を見るだけ**である。DB 接続が実際に同一サーバか、
  同一トランザクションを共有するかは検査しない (`connection` 名の一致で代理する)
- **`Queue::fake()` を張ったテストでは原子性を検証できない**。この非対称は
  gate の docblock と `docs/architecture.md` に明記する
- **`ShouldBeUnique` の unique lock は rollback で解放されない** (R-6)。tx 内 dispatch が
  巻き戻ると `uniqueFor` 秒間だけ再 dispatch が抑止される
- **`Bus::batch` / `Bus::chain` の原子性は検査しない** (app/ に 0 件のため)。
  導入するときは検査項目の追加が必要になる旨を guard の docblock に書く

## 9. 期待効果

- **使命への寄与**: 「保存済み・未投入」で 10 分待たされた末に失敗表示され、
  現場作業者が自分で再実行しないと前進しない経路が **6 本消える**。
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
  中途半端な状態が main に残る (思考原則 3「後方互換の並走を残さない」)
