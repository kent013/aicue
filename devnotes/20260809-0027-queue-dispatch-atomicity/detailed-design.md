# 詳細設計: queue-dispatch-atomicity (キュー投入の業務tx内移設と起動時検査)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）— AGENTS.md より転記

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項 — AGENTS.md より転記

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

> 加えて app-design SKILL の禁止事項: 既存テストの削除・上書き / `DatabaseTransactions` の個別使用 /
> やたらに複雑な案の提案。

### 関連するドメイン固有規約 — AGENTS.md より転記（本設計が直接触れるもの）

- **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` / `video_manuals.status` を
  書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する
  (`ScenarioWritePathInventoryTest` が経路を deny-by-default で固定)
- **ジョブの重複実行と結果の一回性**: 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
  **best-effort であり保証を担わない**。結果の一回性は永続状態遷移 (条件付き UPDATE / 悲観ロック +
  status guard / 予約 CAS) と外部側の冪等キーが担う。取り消せない外部副作用の直前には
  所有権の再検証 (preflight) を置く。キューに載る全クラスは `JobExecutionDedupInventoryTest` の
  目録へ登録が必須 (deny-by-default)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、
  個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン / **アーリーリターン** 推奨
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く、transaction は Service 内
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- `devnotes/20260809-0027-queue-dispatch-atomicity/conceptual-design.md` (Codex Round 5 で APPROVED)
- 一次入力: `devnotes/20260809-0027-queue-dispatch-atomicity/recon-brief.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| M1 | sync 接続の実行意味論の確定 | `config/queue.php` | 最高 (他施策の前提) |
| M2 | 業務ジョブ dispatch の tx 内移設 (Manual / Capture) | `AnalysisJobService` / `RenderJobService` / `RenderPipeline` / `CaptureTakeService` / `VideoManualService` | 高 |
| M3 | Billing の afterCommit 撤去 | `BillingCustomerSynchronizer` / `TicketLedgerService` / `AutoRechargeService` / `AutoRechargeTriggerJob` | 高 |
| M4 | webhook の save+dispatch を同一 tx で括る | `StripeWebhookProcessor` | 高 |
| M5 | 宣言的迂回 (`ShouldQueueAfterCommit`) の撤去 | `app/Notifications/Billing/` 6 クラス | 高 |
| M6 | 起動時 fail-closed 検査 | 新規 `QueueDispatchAtomicityGuard` + DTO + enum / `AppServiceProvider` | 高 |
| M7 | deny-by-default 目録型 gate (0 件 pin + 負のコントロール) | 新規 `QueueDispatchAtomicityInventoryTest` + `QueueDispatchDeferralInventory` | 高 |
| M8 | 既存 5 契約の反転 (削除ではない) | 既存テスト 5 本 | 高 |
| M9 | 原子性の behavioral 検証 | 新規 Feature テスト 2 本 + 既存 2 本の書き換え | 高 |
| M10 | 契約の文書化 | `docs/architecture.md` / `AGENTS.md` / `.env.example` | 中 |

**実装順序は概念設計 §11-2 に従う** (施策ごとに「テストを書いて赤 → 実装 → 緑」)。

---

## M1: sync 接続の実行意味論の確定

### 変更箇所

- ファイル: `config/queue.php` (L34-36)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: **全テストレーンの実行意味論が変わる** (`phpunit.xml:55` /
  `phpunit.browser.xml:46` が `QUEUE_CONNECTION=sync` を force しているため)。
  影響は「業務 tx の内側から dispatch する箇所」に限られる (現行はすべて tx 外のため
  この施策単独では挙動が変わらない)。M6 の R4 がこの 1 行を機械固定する
- `.env.example`: M10 で説明コメントを足す

### 現行コード

```php
'sync' => [
    'driver' => 'sync',
],
```

### 変更後コード

```php
// sync は「テストレーン (phpunit.xml / phpunit.browser.xml が force) と local dev」専用。
// **after_commit => true が必須**: これが無いと業務 tx の内側からの dispatch が
// その場でインライン実行され、RunManualAnalysis / RunManualRender が trigger の tx の
// 内側で走って AnalysisPipeline / RenderPipeline の startJob (lockForUpdate + status===queued)
// が自分自身のロック下で成立してしまう (= 共有ロック規約の意味論が壊れる)。
//
// SyncQueue::push() は shouldDispatchAfterCommit() を尊重し db.transactions へ倒す。
// テストレーンでは RefreshDatabase が Illuminate\Foundation\Testing\DatabaseTransactionsManager
// を差し込み、ラッパ tx を skip したうえで level 1 を root 扱いするため、
// 「業務 tx の commit 直後・テスト tx の内側でインライン実行」= 本番の
// 「commit 後に worker が拾う」と同じ順序意味論になる。
//
// この 1 行は QueueDispatchAtomicityGuard の規則 R4 が起動時に機械固定する。
'sync' => [
    'driver' => 'sync',
    'after_commit' => true,
],
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (config 配列のため N/A)
- [x] null 安全 (N/A)
- [x] DTO を返している (N/A)
- [x] Generics の型パラメータが正しい (N/A)

### テスト計画

- [x] 新規テスト: `tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php` —
  「R4: sync 接続の `after_commit` が true でなければ違反」(M6 と同時に書く)
- [x] 新規テスト: `tests/Architecture/QueueDispatchAtomicityInventoryTest.php` —
  「D4: `after_commit=true` を持ってよい接続は `sync` **だけ**」(全接続集合に対して評価)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 全テストレーンに波及する。ただし現行の業務 dispatch はすべて tx 外にあり、
  tx 外 dispatch は `callbackApplicableTransactions()` が 0 件のため即時実行のまま。
  この施策単独では既存テストの挙動は変わらない
- **`Queue::fake()` を張ったテストでは原子性を検証できない** (`QueueFake::push()` は
  `enqueueUsing` を通らない)。M9 は `Queue::fake()` を使わない

---

## M2: 業務ジョブ dispatch の tx 内移設 (Manual / Capture)

### 変更箇所

- `app/Services/Manual/AnalysisJobService.php` (L94-104)
- `app/Services/Manual/RenderJobService.php` (L105-116 / L157-164)
- `app/Services/Manual/RenderPipeline.php` (L280-346: `finalize()`)
- `app/Services/Capture/CaptureTakeService.php` (L92-117: `delete()`)
- `app/Services/Manual/VideoManualService.php` (L196-223: `delete()`)

### 波及変更

- TypeScript 型定義: なし (公開 API・Props に変更なし)
- API Resource/DTO: なし
- テストファイル: M9 の新規 Feature テスト。既存の
  `tests/Feature/Manual/*` / `tests/Feature/Capture/*` は挙動が変わらないため更新不要
  (M1 により sync は commit 後インライン実行を維持する)
- Architecture テスト: `ScenarioWritePathInventoryTest` の母集団は「status を書く経路」であり
  dispatch 位置は見ていないため更新不要 (実装時に走らせて確認する)

### 現行コード

```php
// AnalysisJobService::trigger()
        $job = DB::transaction(function () use ($project, $manual, $actor): AnalysisJob {
            // ... 中略 ...
            $job->save();
            $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();

            return $job;
        });

        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
        RunManualAnalysis::dispatch($job->id);

        return $job;
```

```php
// CaptureTakeService::delete()
        if ($paths !== []) {
            DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ
        }
```

```php
// RenderPipeline::finalize()
        if ($succeeded) {
            // dispatch は commit 後 (喪失は render:reconcile-outputs が回収)
            foreach ($oldJobIds as $oldJobId) {
                DeleteRenderOutputsJob::dispatch($oldJobId);
            }
            $job->refresh();
        }
```

### 変更後コード

```php
// AnalysisJobService::trigger()
        $job = DB::transaction(function () use ($project, $manual, $actor): AnalysisJob {
            // ... 中略 ...
            $job->save();
            $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();

            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。
            // jobs 行が同一 tx に乗るため「保存済み・未投入」が構造的に消える。
            // rollback すれば jobs 行ごと巻き戻る。原子性の前提 (driver=database /
            // キュー DB 接続 = 業務 DB / after_commit=false) は
            // QueueDispatchAtomicityGuard が起動時に fail-closed 検査する。
            RunManualAnalysis::dispatch($job->id);

            return $job;
        });

        return $job;
```

`RenderJobService::trigger()` / `triggerPreview()` も同型 (`RunManualRender::dispatch($job->id)` を
`return $job;` の直前へ移す)。

```php
// CaptureTakeService::delete() — 変数 $paths の tx 外受け渡しをやめ、tx 内で完結させる
        DB::transaction(function () use ($project, $manual, $cut, $take): void {
            // ... 中略 (lockedTake の削除と renumber) ...
            /** @var list<string> $paths */
            $paths = array_values(array_filter([$lockedTake->video_path, $lockedTake->thumbnail_path]));
            $lockedTake->delete();
            $this->renumber($lockedCut);

            // S3 削除の投入を同一 tx 内で行う (AG-114 確定 1)。
            // 保証するのは「take 行を消したのに削除 job が投入されない窓」の解消だけである
            // (worker 停止 / job 失敗 / ストレージ失敗ではオブジェクトは残る = 誇張しない)。
            if ($paths !== []) {
                DeleteTakeObjectsJob::dispatch($paths);
            }
        });
```

`VideoManualService::delete()` も同型 (戻り値 `array` の受け渡しをやめ `void` のクロージャにする)。

```php
// RenderPipeline::finalize() — oldJobIds の参照渡しをやめ、tx 内で dispatch する
        $succeeded = DB::transaction(function () use ($job, $result): bool {
            // ... 中略 ...
            $oldJobIds = RenderJob::query()/* ... */->map(...)->all();

            // 旧世代 output の削除投入を terminal tx の内側で行う (AG-114 確定 1)。
            // 削除 job は冪等のため重複無害。喪失時の回収役 (render:reconcile-outputs) は
            // 別要因 (worker 異常終了) のために残す。
            foreach ($oldJobIds as $oldJobId) {
                DeleteRenderOutputsJob::dispatch($oldJobId);
            }

            return true;
        });

        if ($succeeded) {
            $job->refresh();
        }

        return $succeeded;
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`AnalysisJob` / `RenderJob` / `void` / `bool`)
- [x] null 安全 — `firstOrFail()` + `/** @var */` の既存注釈をそのまま維持する
- [x] DTO を返している — 既存の戻り値型 (Eloquent モデル / `bool`) を変えない
- [x] Generics の型パラメータが正しい — `RenderPipeline::finalize` の
  `&$oldJobIds` (参照渡し `list<int>`) を**廃止**するため、`@var list<int>` 注釈も削除する
  (参照渡しは PHPStan で narrowing が効きにくく、tx 内完結で不要になる)

### テスト計画

- [ ] **再現テストを先に書く** (概念設計 §11-2 の 3):
  `tests/Feature/Manual/QueueDispatchAtomicityTest.php`
  - `解析トリガの RunManualAnalysis は業務 tx の内側で投入される` — **主契約**。
    action 直前の `DB::transactionLevel()` を `baseline` として記録し、
    **対象ジョブクラスで filter した** `JobQueueing` の level が
    **`baseline + 1` 以上**であることを assert する
  - `レンダトリガの RunManualRender は業務 tx の内側で投入される` (同型)
  - `プレビュートリガの RunManualRender は業務 tx の内側で投入される` (同型)
  - `外側 tx が rollback すると analysis_jobs も jobs 行も残らない` — **補助**。
    旧実装でも緑になるため「赤化必須」の主張には使わない (下記リスク参照)
- [ ] `tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php`
  - `テイク削除の DeleteTakeObjectsJob は業務 tx の内側で投入される` (**主契約**)
  - `テイク削除の外側 tx が rollback すると take 行も削除 job も残らない` (補助)
- [ ] `tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php`
  - `マニュアル削除の DeleteTakeObjectsJob は業務 tx の内側で投入される` (**主契約**)
  - `マニュアル削除の外側 tx が rollback すると manual 行も削除 job も残らない` (補助)
- [ ] `tests/Feature/Manual/RenderFinalizeQueueAtomicityTest.php`
  - `finalize の DeleteRenderOutputsJob は terminal tx の内側で投入される` (**主契約**)
- [ ] 既存テスト `tests/Feature/Manual/AnalysisJobServiceTest.php` 等の更新: **不要**
  (M1 により sync レーンの実行順序が保たれるため)。実装時に全レーンを走らせて確認する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (`VideoManual::factory()` / `Take::factory()` 等)

### リスク

- **rollback テストは赤化保証にならない** (Codex Round 1 の Critical)。旧実装は
  service 内 tx の commit **後**に dispatch するが、テストが外側 `DB::transaction()` で
  包むとその dispatch も外側 tx の内側に入るため、**旧実装でも jobs 行は外側 rollback で消える**。
  よって「業務 tx 内移設」を検出するのは **tx level 観測 (`baseline + 1` 以上)** だけである。
  rollback テストは「原子性が実際に成立していること」の補助的な確認として置く
- `RenderPipeline::finalize` の参照渡し `&$oldJobIds` を廃止する。既存テストが
  `oldJobIds` の内容を直接検査していないことを実装時に確認する
- `CaptureTakeService::delete` / `VideoManualService::delete` の戻り値
  (`array` を返すクロージャ) を `void` に変える。**メソッド自体の戻り値は元から `void`** のため
  外部シグネチャは変わらない
- S3 削除 job が tx 内で dispatch されるようになるため、**sync レーンでは commit 後に
  インライン実行**される (M1 の効果)。`Storage::fake()` を張っていないテストがあると
  外部呼び出しになるが、`TESTING_FAKE_STORAGE` により fake に差し替わる (既存の配線)

---

## M3: Billing の afterCommit 撤去

### 変更箇所

- `app/Services/Billing/BillingCustomerSynchronizer.php` (L18-35)
- `app/Services/Billing/TicketLedgerService.php` (L420-445 付近: `reserve()` の末尾)
- `app/Services/Billing/AutoRechargeService.php`
  (L427-511: `createAttemptLocked()` / L1013-1022: reconcile (v))
- `app/Jobs/Billing/AutoRechargeTriggerJob.php` (`ShouldBeUnique` / `$uniqueFor` / `uniqueId()` の撤去)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル (M8 で反転):
  `tests/Feature/Billing/BillingCustomerSynchronizerTest.php` /
  `tests/Feature/Billing/AutoRechargeTriggerTest.php` /
  `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php` /
  `tests/Architecture/BillingSyncDispatchInvariantTest.php` /
  `tests/Architecture/JobExclusionOrderingInvariantTest.php`
- Architecture テスト: `JobExecutionDedupInventoryTest` の `AutoRechargeTriggerJob` 登録は
  **変更不要** (根拠文が `ShouldBeUnique` に言及せず、partial unique を保証源としているため。
  実コードを Read して確認済み)

### 現行コード

```php
// BillingCustomerSynchronizer::dispatchFor()
    /**
     * Stripe customer 同期 job を dispatch する。
     *
     * **必ず `DB::transaction` クロージャの内側から呼ぶこと。** transaction 内で
     * `afterCommit()` を付けることで outer commit 後に発火し、commit 前の stale read を防ぐ (IV-3)。
     * transaction の外で呼ぶと `afterCommit()` が即時実行になり遅延保証が崩れるため禁止。
     */
    public function dispatchFor(Organization $organization): void
    {
        if ($organization->stripe_id === null) {
            return;
        }

        SyncBillingCustomerDetails::dispatch($organization)->afterCommit();
    }
```

```php
// TicketLedgerService::reserve() の末尾 (DB::transaction クロージャの内側)
            if ($balance >= $threshold && $after < $threshold) {
                // afterCommit: reserve は pipeline の startJob tx 内から savepoint で呼ばれ得るため、
                // 最外層 commit 成立後にのみ通知する (rollback 時は発火しない)
                DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
            }
            // ... 中略 ...
            $organizationId = $organization->getKey();
            Assert::integer($organizationId);
            DB::afterCommit(static fn () => AutoRechargeTriggerJob::dispatch($organizationId));

            return $reservation;
        });
```

```php
// AutoRechargeTriggerJob
final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
{
    public int $uniqueFor = 30;

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(AutoRechargeService $autoRecharge): void
    {
        // ... 中略 ...
        $attempt = $autoRecharge->maybeCreateAttempt($organization);
        if ($attempt !== null) {
            ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
        }
    }
}
```

### 変更後コード

```php
// BillingCustomerSynchronizer::dispatchFor()
    /**
     * Stripe customer 同期 job を dispatch する。
     *
     * **必ず `DB::transaction` クロージャの内側から呼ぶこと** (呼び出し元 2 経路は既にそう)。
     *
     * 【契約の反転 (AG-114 確定 1)】
     * - 旧主張: transaction 内で `afterCommit()` を付け、outer commit 後に発火させる
     * - 旧目的: commit 前の stale read を防ぐ (IV-3)
     * - 新主張: `afterCommit()` を付けず、業務 tx の内側で素直に dispatch する
     * - 新前提: jobs 行が業務 tx に乗るため、**worker が job を可視化できるのは commit 後**
     * - 前提を守る機構: QueueDispatchAtomicityGuard (driver=database / キュー DB 接続 =
     *   業務 DB / after_commit=false を起動時に fail-closed 検査)
     * - 反転根拠: 本 job は SerializesModels で organization を **ID で直列化し handle 時に
     *   再取得**する。可視化が commit 後である以上、再取得値は必ず commit 後の値になり、
     *   IV-3 は afterCommit なしで (むしろより強く) 保たれる。加えて afterCommit は
     *   「commit したのに dispatch されない」窓を残すため、確定 1 の下では有害である
     *
     * Stripe customer 未作成 (`stripe_id === null`) の組織は no-op (IV-4、例外にしない)。
     */
    public function dispatchFor(Organization $organization): void
    {
        if ($organization->stripe_id === null) {
            return;
        }

        SyncBillingCustomerDetails::dispatch($organization);
    }
```

```php
// TicketLedgerService::reserve()
    public function reserve(Organization $organization, int $amount): TicketReservation
    {
        // 閾値クロスの有無は **クロージャの戻り値**で持ち出す (参照渡しにしない)。
        // 参照渡しだと、将来 transaction retry (attempts>1) が入ったときに
        // rollback された試行の副作用がクロージャの外に残る。
        /** @var array{reservation: TicketReservation, crossing: array{balance: int, threshold: int}|null} $result */
        $result = DB::transaction(function () use ($organization, $amount): array {
            $crossing = null;
            // ... 中略 (org 行ロック〜 $reservation->save()) ...

            $balance = $availableMonthly + $availablePurchased;
            $threshold = config()->integer('billing.ticket_low_balance_threshold');
            $after = $balance - $amount;
            if ($balance >= $threshold && $after < $threshold) {
                // 通知は**付随的副作用** (AG-127)。tx の内側では実行せず、閾値クロスの事実だけ持ち出す。
                // TicketBalanceLowNotification は ShouldQueue ではない同期 DB 書き込みのため、
                // ここで実行すると organizations 行ロック (reserve の直列化点) を通知 INSERT の
                // 分だけ長く保持することになる。
                $crossing = ['balance' => $after, 'threshold' => $threshold];
            }

            // P8a: オートリチャージのトリガ点。**業務 tx の内側で投入する** (AG-114 確定 1)。
            // jobs 行が同一 tx に乗るため rollback すれば投入ごと巻き戻る
            // (旧: DB::afterCommit。afterCommit は「commit したのに未投入」の窓を残す)。
            $organizationId = $organization->getKey();
            Assert::integer($organizationId);
            AutoRechargeTriggerJob::dispatch($organizationId);

            return ['reservation' => $reservation, 'crossing' => $crossing];
        });

        $crossing = $result['crossing'];

        // 低残高通知 (AG-127 の付随的副作用)。**tx を抜けた最後**に実行する。
        // 保証範囲を誇張しない: reserve が呼び出し側の tx にネストされている場合、
        // ここは依然として外側 tx の内側であり、(a) 外側のロックを保持したまま INSERT が走る、
        // (b) SQL 層の失敗は PostgreSQL の tx abort を経て業務操作ごと失敗させる。
        // アプリケーション層の例外は NotificationCenterService::safely() が握る。
        if ($crossing !== null) {
            $this->notifications->notifyTicketBalanceLow($organization, $crossing['balance'], $crossing['threshold']);
        }

        return $result['reservation'];
    }
```

```php
// AutoRechargeTriggerJob — ShouldBeUnique / $uniqueFor / uniqueId() を撤去
/**
 * P8a: チケット消費 (reserve) 後の残高閾値判定 → attempt 起票の薄い箱。
 *
 * 【入口排他 (ShouldBeUnique) を持たない理由 — 契約の反転】
 * - 旧主張: `ShouldBeUnique` + `uniqueFor = 30` で同一 org の重複 dispatch を抑止する
 * - 旧目的: reserve のたびに trigger job が積まれるのを減らす
 * - 新主張: 入口排他を持たない。重複 dispatch は下流が no-op へ収束させる
 * - 新前提: 本 job は業務 tx の内側から dispatch される (AG-114 確定 1)
 * - 前提を守る機構: maybeCreateAttempt の organizations 行ロック + pending 存在検査 +
 *   `tar_attempts_org_pending_unique` (partial unique) + unique violation の no-op 化
 * - 反転根拠: `UniqueLock` は PendingDispatch の dispatch 呼び出し時に取得され、
 *   rollback 時の解放は afterCommit 経路でしか行われない。業務 tx の内側で dispatch すると
 *   rollback しても `uniqueFor` 秒の抑止が残り、**ネスト深さに依らず解消できない**。
 *   AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため、撤去して
 *   永続状態遷移へ責務を一本化する
 */
final class AutoRechargeTriggerJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $organizationId) {}

    public function handle(AutoRechargeService $autoRecharge): void
    {
        // ... 中略 (enabled 検査 / organization 解決) ...
        // 起票と ExecuteAutoRechargeAttemptJob の投入は maybeCreateAttempt の tx 内で完結する。
        $autoRecharge->maybeCreateAttempt($organization);
    }
}
```

```php
// AutoRechargeService::createAttemptLocked() — 起票と同一 tx で投入する
                $attempt->save();

                // 実行 job の投入を**起票と同一 tx**で行う (AG-114 確定 1)。
                // 旧: 呼び出し側 (AutoRechargeTriggerJob::handle / reconcile (v)) が
                // tx 成功後に dispatch していたため「attempt=pending・実行未投入」の窓があり、
                // reconcile (v) の 15 分周期に依存していた。
                ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);

                return $attempt;
```

呼び出し側 2 箇所 (`AutoRechargeTriggerJob::handle` / `AutoRechargeService` の reconcile (v)) から
`ExecuteAutoRechargeAttemptJob::dispatch(...)` を**削除する** (二重投入の防止)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`void` / `TicketReservation` / `?TicketAutoRechargeAttempt`)
- [x] null 安全 — クロージャの戻り値を
  `array{reservation: TicketReservation, crossing: array{balance: int, threshold: int}|null}` として
  `@var` で明示する (`DB::transaction()` はコールバックの戻り値型を伝播できるが、
  解析結果が十分に具体化されない場合に備えて shape を明示する)
- [x] DTO を返している — `$crossing` は**メソッド内で閉じた一時値**であり公開されないため
  array shape で足りる。公開されるのは既存の `TicketReservation` のみ
- [x] Generics の型パラメータが正しい

> **参照渡し (`&$crossing`) は使わない** (Codex Round 1 の Warning)。将来 transaction retry
> (`DB::transaction($cb, N)`) が入ったときに、rollback された試行の副作用がクロージャの外に
> 残るため。戻り値で持ち出す形なら retry でも PHPStan でも素直になる。
> 小さな readonly DTO (`TicketLowBalanceCrossing`) にする案もあるが、公開されない一時値のため
> 思考原則 2 に従い array shape で足りる。

### テスト計画

- [ ] 反転: `tests/Feature/Billing/BillingCustomerSynchronizerTest.php`
  - 旧「dispatch した job は afterCommit フラグを持つ」→
    新 `dispatch した job は afterCommit フラグを持たない (業務 tx に乗る)` +
    新 `外側 tx が rollback すると jobs 行が残らない` (実 `jobs` 表観測)
- [ ] 反転: `tests/Feature/Billing/AutoRechargeTriggerTest.php:76`
  - 旧「reserve が rollback したら dispatch されない (afterCommit の保証)」→
    新 `reserve が rollback したら jobs 行ごと巻き戻る (業務 tx への相乗り)` (実 `jobs` 表観測)
- [ ] 維持: `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php:104`
  - 「rollback される外側 tx 内の reserve は通知されない」は**そのまま緑になる**
    (通知行が外側 tx に乗るため)。docblock の根拠だけ反転する (M8)
- [ ] 新規: `tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php`
  - `通知チャネルが例外を投げても reserve は成功し予約行が残る` —
    `DatabaseChannel` の bind を throw する fake channel に差し替え、
    `NotificationCenterService::safely()` 本体を通す (サービス全体を mock しない)
- [ ] 新規: `tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php`
  (`ShouldBeUnique` 撤去後の一回性を 3 点に分けて固定)
  - `pending attempt があるとき maybeCreateAttempt は null を返し attempt が増えない`
  - `同一 org の 2 件目の pending 行は tar_attempts_org_pending_unique が拒否する`
    (直接 INSERT して `QueryException` を assert)
  - `unique violation は no-op へ収束し呼び出し側へ例外が漏れない`
- [ ] 新規: `tests/Feature/Billing/AutoRechargeAttemptDispatchAtomicityTest.php`
  - `attempt 起票と ExecuteAutoRechargeAttemptJob の投入は同一 tx である` —
    対象ジョブ `ExecuteAutoRechargeAttemptJob` で filter した `baseline + 1` 以上
  - `起票 tx が rollback すると attempt 行も jobs 行も残らない` (補助)
- [ ] 新規: `tests/Feature/Billing/TicketReserveDispatchAtomicityTest.php`
  **(`reserve()` 内の `AutoRechargeTriggerJob` は別経路なので別テストが要る。
  Codex Round 3 の Critical — `AutoRechargeAttemptDispatchAtomicityTest` は
  `createAttemptLocked()` 内の別ジョブを見ているため、この変異を検出できない)**
  - `reserve の AutoRechargeTriggerJob は業務 tx の内側で投入される` —
    対象ジョブ `AutoRechargeTriggerJob` で filter した `baseline + 1` 以上 (**主契約**)
  - `reserve が rollback すると jobs 行が残らない` (補助。M8 の反転テストと同義)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (`Organization::factory()` / `TicketAutoRecharge::factory()`)

### リスク

- **`ShouldBeUnique` 撤去で trigger job の投入量が増える** (org あたり 30 秒に高々 1 件 →
  reserve 1 回につき 1 件)。job 本体は `exists()` 1 本で早期 return する薄い箱で、
  reserve は人間の操作起点のため実運用上の増分は無視できる
- **`isUniqueViolation()` は SQLSTATE (23505/23000) だけを見ており制約名を識別しない**
  (実コード確認済み)。すなわち「別の unique 制約の違反」も no-op に収束させる。
  これは**本設計で新たに作る問題ではない**が、Codex Round 5 の指摘どおり
  「本当の障害が隠れる」余地がある。**本 PR では変更しない** (思考原則 2。
  変更すると auto-recharge の失敗分類の設計に踏み込むことになる) が、
  §未解決事項に記録する
- `reserve()` のクロージャ戻り値が `TicketReservation` から array shape に変わる。
  `DB::transaction()` はコールバックの戻り値型を伝播できるが、解析結果が十分に
  具体化されない場合に備えて `@var` で shape を明示する (PHPStan level 10)

---

## M4: webhook の save+dispatch を同一 tx で括る

### 変更箇所

- `app/Services/Billing/StripeWebhookProcessor.php` (L616-624: `completeSubscriptionCheckout` /
  L686-696: `completeAutoRechargeSetup`)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: `tests/Feature/Billing/StripeWebhook*Test.php` (挙動は変わらないため更新不要。
  実装時に走らせて確認する)

### 現行コード

```php
// completeSubscriptionCheckout()
        $subscriptionId = $this->subscriptionIdFrom($payload);
        if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value && $subscriptionId !== null) {
            $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
            ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
        }
```

```php
// completeAutoRechargeSetup()
        if ($session->status !== CheckoutSessionStatus::Completed->value) {
            $session->status = CheckoutSessionStatus::Completed->value;
            $session->completed_at = now();
            $session->save();
        }

        $organizationId = $organization->getKey();
        Assert::integer($organizationId);
        SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
```

### 変更後コード

```php
// completeSubscriptionCheckout()
        $subscriptionId = $this->subscriptionIdFrom($payload);
        if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value && $subscriptionId !== null) {
            // 打刻と投入を同一 tx で括る (AG-114 確定 1)。
            // pm_reuse_dispatched_at は「自動的に有効になります」表示の出典であり、
            // 打刻だけ残って job が投入されない状態は**表示と実態の食い違い**になる。
            DB::transaction(function () use ($local, $subscriptionId): void {
                $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
                ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
            });
        }
```

```php
// completeAutoRechargeSetup()
        $organizationId = $organization->getKey();
        Assert::integer($organizationId);

        // 台帳の completed 化と PM 既定設定 job の投入を同一 tx で括る (AG-114 確定 1)。
        // status だけ completed になって job が投入されないと、PM が既定にならないまま
        // 「設定完了」の表示になる。
        DB::transaction(function () use ($session, $organizationId, $setupIntentId): void {
            if ($session->status !== CheckoutSessionStatus::Completed->value) {
                $session->status = CheckoutSessionStatus::Completed->value;
                $session->completed_at = now();
                $session->save();
            }

            SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
        });
```

**`HandleAutoRechargeChargeFailureJob::dispatch` (L477) は変更しない。**
先行する自 DB 書き込みが無く、原子性の対象になる業務 tx が存在しないため
(`findPendingAttemptByUlid` は読み取りのみ)。この根拠をコード上のコメントに残す。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (クロージャは `: void`)
- [x] null 安全 — `$subscriptionId` は `!== null` guard 済み、`$organizationId` は `Assert::integer`
- [x] DTO を返している (N/A)
- [x] Generics の型パラメータが正しい

### テスト計画

- [ ] 新規: `tests/Feature/Billing/StripeWebhookDispatchAtomicityTest.php`
  - `checkout.session.completed (funding=auto_recharge) の打刻と PM 流用 job 投入は同一 tx である`
    (`JobQueueing` の `DB::transactionLevel()` 観測)
  - `auto_recharge_setup 完了の台帳更新と PM 既定設定 job 投入は同一 tx である`
- [ ] 既存 `tests/Feature/Billing/` の webhook テスト群が緑のままであることを確認
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **`claim()` の tx と入れ子にならない**ことを確認済み: `handle()` は `claim()`
  (自前 tx) を閉じてから `process()` を tx 外で呼ぶ (L100 付近を Read して確認)。
  よって今回追加する tx は webhook 処理の中で独立している
- webhook の tx が 2 本増えるが、いずれも「1〜2 行の UPDATE + jobs 行 INSERT」であり
  ロック保持時間の増分は小さい

---

## M5: 宣言的迂回 (`ShouldQueueAfterCommit`) の撤去

### 変更箇所

- `app/Notifications/Billing/PaymentFailedNotification.php` (L12 / L23)
- `app/Notifications/Billing/RenewalReminderNotification.php` (L13 / L23)
- `app/Notifications/Billing/AutoRechargeEnabledNotification.php` (L12 / L25)
- `app/Notifications/Billing/AutoRechargeDisabledNotification.php` (L12 / L22)
- `app/Notifications/Billing/AutoRechargeFailedNotification.php` (L12 / L22)
- `app/Notifications/Billing/AutoRechargeActionRequiredNotification.php` (L12 / L23)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- テストファイル: なし (実行時の挙動が変わらないため。下記参照)
- Architecture テスト: `QueuedJobLeaseInventoryTest` /
  `JobExecutionDedupInventoryTest` の登録は `ShouldQueue` 実装で決まるため**変更不要**

### 現行コード

```php
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/**
 * 支払い失敗通知。invoice.payment_failed 受信で組織の請求宛先へ送る。
 *
 * queue 送信 + DB commit 後発火 (webhook 本処理を巻き込まない)。
 */
class PaymentFailedNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingDelivery
```

### 変更後コード

```php
/**
 * 支払い失敗通知。invoice.payment_failed 受信で組織の請求宛先へ送る。
 *
 * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin】
 * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
 * (StripeWebhookProcessor::handleInvoicePaymentFailed / AutoRechargeService の通知群 /
 * SendBillingReminders) は**すべて業務 tx の外**である。よって
 * `ShouldQueueAfterCommit` は実行時に何の効果も持っていなかった
 * (`addCallback` は pending tx が 0 件なら即時実行する)。
 * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
 * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。
 * 撤去して QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
 * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
 */
class PaymentFailedNotification extends Notification implements ShouldQueue, TracksBillingDelivery
```

残り 5 クラスも同型 (import 行と `implements` からの除去 + docblock)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (変更なし)
- [x] null 安全 (変更なし)
- [x] DTO を返している (N/A)
- [x] Generics の型パラメータが正しい — `implements` から interface を外すだけで
  型パラメータには影響しない

### テスト計画

- [ ] 新規 (M7 の一部): `QueueDispatchAtomicityInventoryTest` の D3 —
  `ShouldQueue 実装クラスのうち ShouldQueueAfterCommit を implement するものは 0 件`
  (リフレクション判定。中間 interface / 親クラス経由も拾う)
- [ ] 既存の請求通知テスト (`tests/Feature/Billing/BillingNotification*Test.php` /
  `tests/Feature/Billing/SendBillingRemindersTest.php` 等) が緑のままであることを確認
  (呼び出し元が tx 外のため実行時の挙動は変わらない)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- 実行時の挙動は現行の呼び出し元では変わらない (§概念設計 §7 の追加発見)。
  ただし **`AutoRechargeService::notifyFailed` / `notifyDisabled` / `notifyActionRequired` が
  将来 tx 内から呼ばれるようになった場合**、通知の enqueue が業務 tx に乗る。
  それが確定 1 の意図する挙動であり、失敗は dispatcher の try/catch が吸収する

---

## M6: 起動時 fail-closed 検査 (`QueueDispatchAtomicityGuard`)

### 変更箇所

- 新規: `app/Support/QueueDispatchAtomicityGuard.php`
- 新規: `app/DataTransferObjects/Support/QueueDispatchAtomicityViolation.php`
- 新規: `app/Enums/Support/QueueAtomicityRule.php`
- `app/Providers/AppServiceProvider.php` (`boot()` 冒頭。既存の `ProductionEnvGuard` 配線の直後)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: 新規 DTO 1 件 (内部利用。JsonResource は不要)
- テストファイル: 新規 `tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php`
- `ProductionEnvGuard` には**相乗りしない** (適用範囲が production 限定でないため)

### 現行コード

`app/Providers/AppServiceProvider.php::boot()` 冒頭:

```php
    public function boot(): void
    {
        // production 起動時の env baseline fail-fast (設定ミスを deploy 時点で表面化させる)。
        // 検査項目の SSOT は ProductionEnvGuard (production:preflight コマンドも同 guard に委譲)
        if ($this->app->environment('production')) {
            (new ProductionEnvGuard)->enforce();
        }
```

### 変更後コード

```php
// app/Enums/Support/QueueAtomicityRule.php
enum QueueAtomicityRule: string
{
    /** 参照接続 (sync 以外) の driver は database である */
    case DatabaseDriver = 'database_driver';
    /** driver=database の参照接続は業務 DB と同一の DB 接続を使う */
    case SameDatabaseConnection = 'same_database_connection';
    /** driver=database の参照接続は after_commit=false である */
    case AfterCommitDisabled = 'after_commit_disabled';
    /** sync 接続は after_commit=true である (テスト・dev の実行順序の保存) */
    case SyncAfterCommitEnabled = 'sync_after_commit_enabled';
    /** production の既定接続は database である (sync の本番投入を拒否する) */
    case ProductionAsyncDriver = 'production_async_driver';
}
```

```php
// app/DataTransferObjects/Support/QueueDispatchAtomicityViolation.php
final readonly class QueueDispatchAtomicityViolation
{
    /**
     * @param  string  $connection  検査対象のキュー接続名
     * @param  string  $actual  実測値を**表示用に正規化した文字列** (mixed を公開しない)
     */
    public function __construct(
        public QueueAtomicityRule $rule,
        public string $connection,
        public string $actual,
        public string $message,
    ) {}
}
```

```php
// app/Support/QueueDispatchAtomicityGuard.php
/**
 * キュー投入の原子性の前提を起動時に fail-closed 検査する SSOT (AG-114 確定 2)。
 *
 * 【なぜ必要か】業務 tx の内側でキュー投入を行う設計 (確定 1) は
 * 「キューの実体が業務 DB と同一トランザクションに乗る」ことを前提にする。この前提は
 * config と env で簡単に崩れ、**テストは全部緑のまま原子性だけ消える**。
 *
 * 【検査する項目 (AG-126「使っている機能に応じて選ぶ」)】
 * - R1 DatabaseDriver / R2 SameDatabaseConnection / R3 AfterCommitDisabled:
 *   参照接続 (既定接続 + onConnection でリテラル pin された 3 種) について
 * - R4 SyncAfterCommitEnabled: sync 接続の実行順序の保存 (config/queue.php の当該コメント参照)
 * - R5 ProductionAsyncDriver: production の既定接続が sync だと job が HTTP リクエスト内で
 *   インライン実行され、原子性・非同期化・worker 分離がすべて失われるため拒否する
 *
 * 【検査しない項目とその根拠】
 * - **Bus::batch / Bus::chain の束台帳**: `app/` 配下に使用が 0 件のため対象外。
 *   導入するときは `config('queue.batching')` の接続一致検査を本 guard に追加すること
 * - **beanstalkd / sqs / redis / deferred / background / failover**: config に定義があるだけで
 *   どの job からも参照されていない (参照集合に入らない)
 *
 * 【保証しないもの (誇張しない)】
 * - 見るのは **config の値だけ**である。`connection` 名の一致は「同一トランザクションに乗る」
 *   ことの**代理検査**にすぎず、異なる PDO インスタンス / connection resolver の差し替え /
 *   同名で別サーバを指す構成までは検査しない
 */
class QueueDispatchAtomicityGuard
{
    /**
     * `onConnection('...')` でリテラル pin されている接続名。
     * 正本は QueuedJobLeaseInventoryTest の QUEUED_JOB_LEASE_INVENTORY で、
     * そちらが deny-by-default で全 ShouldQueue クラスの接続を固定している。
     *
     * @var list<string>
     */
    public const PINNED_CONNECTIONS = ['database-analysis', 'database-render', 'database-media'];
    // ★ この定数と実際の pin 集合の drift は QueuedJobLeaseInventoryTest 側で
    //   対称差 0 を assert して閉じる (下記 M6 テスト計画)。guard 単体では閉じない。

    /** @return list<QueueDispatchAtomicityViolation> */
    public function violations(bool $isProduction): array { /* 純関数的に config を読む */ }

    public function enforce(bool $isProduction): void
    {
        $violations = $this->violations($isProduction);
        if ($violations !== []) {
            throw new RuntimeException(
                "Queue dispatch atomicity violations:\n- ".implode("\n- ", array_map(
                    static fn (QueueDispatchAtomicityViolation $v): string => $v->message,
                    $violations,
                ))
            );
        }
    }
}
```

判定ロジック (要点):

```php
        // 想定外の型・空文字はすべて**違反として報告**し、報告した対象は
        // **以降の offset 参照を行わず早期 continue / return** する (fail-closed)。
        // 空文字へ丸めて比較を続けると、R2 の「connection が null = 既定 DB なので OK」の
        // 判定が比較対象不在のまま通ってしまう。
        $connections = config('queue.connections');
        if (! is_array($connections)) {
            return [/* queue.connections が配列でない = 全規則を検査不能 (fail-closed) */];
        }

        $defaultQueue = config('queue.default');
        $defaultDatabase = config('database.default');
        $violations = [];
        if (! is_string($defaultQueue) || $defaultQueue === '') {
            $violations[] = /* queue.default 不正 */;
        }
        if (! is_string($defaultDatabase) || $defaultDatabase === '') {
            $violations[] = /* database.default 不正 */;
        }
        // 既定値が取れないなら以降の比較は無意味なので打ち切る (fail-closed)
        if ($violations !== []) {
            return $violations;
        }

        // R4: sync 接続。**未定義・非配列・driver が sync でない場合も違反**。
        // driver を見ないと「sync という名前の database 接続」で R4 を通せてしまう
        // (Codex Round 3 の Warning)。
        $sync = $connections['sync'] ?? null;
        if (! is_array($sync)
            || ($sync['driver'] ?? null) !== 'sync'
            || ($sync['after_commit'] ?? null) !== true) {
            $violations[] = /* R4 違反 */;
        }

        // R5: production の既定接続 driver
        if ($isProduction && $this->driverOf($connections, $defaultQueue) !== 'database') {
            $violations[] = /* R5 違反 */;
        }

        // R1〜R3: 参照集合 = [既定接続] ∪ PINNED_CONNECTIONS (重複除去)。
        // ★ **除外は接続「名」で判定する** (Codex Round 4 の Critical)。
        //   driver === 'sync' で除外すると、`database-analysis.driver = sync` にした構成が
        //   R1〜R3 を全部 skip して通ってしまう (R4 が見るのは connections.sync だけのため)。
        //   名前で除外すれば pin 済み接続を sync へ差し替える構成は R1 違反になる。
        foreach ($this->referencedConnections($defaultQueue) as $name) {
            if ($name === 'sync') {
                continue;                       // sync 接続の契約は R4 / R5 が担う
            }
            $config = $connections[$name] ?? null;
            if (! is_array($config)) {
                $violations[] = /* 接続定義の欠落・非配列 = R1 違反 */;

                continue;                       // ← 以降の offset 参照をしない
            }
            $driver = $config['driver'] ?? null;
            if ($driver !== 'database') {
                $violations[] = /* R1 違反 */;

                continue;
            }
            // R2 は **三分岐** (Codex Round 2 の Warning)
            $connection = $config['connection'] ?? null;
            if ($connection === null) {
                // 既定 DB 接続を使う = 許可
            } elseif (is_string($connection) && $connection !== '') {
                if ($connection !== $defaultDatabase) {
                    $violations[] = /* R2 違反 */;
                }
            } else {
                $violations[] = /* R2 違反 (null | 非空 string 以外は不正) */;
            }
            // R3 はキー欠落も非 bool も違反 (厳密比較)
            if (($config['after_commit'] ?? null) !== false) {
                $violations[] = /* R3 違反 */;
            }
        }
```

`AppServiceProvider::boot()`:

```php
    public function boot(): void
    {
        // production 起動時の env baseline fail-fast (検査項目の SSOT は ProductionEnvGuard)
        if ($this->app->environment('production')) {
            (new ProductionEnvGuard)->enforce();
        }

        // キュー投入の原子性の前提を**全環境**で fail-closed 検査する (AG-114 確定 2)。
        // ProductionEnvGuard に相乗りしないのは、適用範囲が production 限定ではないため
        // (sync レーンの実行順序 R4 はテスト・dev でこそ意味を持つ)。
        // container 解決にしておくと boot からの呼び出しをテストで spy できる
        $this->app->make(QueueDispatchAtomicityGuard::class)
            ->enforce($this->app->environment('production'));
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている — `violations(): list<QueueDispatchAtomicityViolation>` /
  `enforce(): void`
- [x] null 安全 — `config()` の戻り値は `mixed`。`is_array` / `is_string` で narrowing してから使い、
  narrowing に失敗した場合は**例外ではなく違反として報告**する (`ProductionEnvGuard` と同じ流儀)
- [x] DTO を返している — 配列ではなく `QueueDispatchAtomicityViolation` の list を返す。
  **DTO は `mixed` を公開せず**、実測値は表示用に正規化した `string` (`var_export($v, true)` 相当) で持つ
- [x] Generics の型パラメータが正しい — `list<...>` を PHPDoc で明示

### テスト計画

- [ ] 新規: `tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php`
  (純関数のテーブルテスト。config を `config()->set()` で組み替える)
  - `既定構成では違反が 0 件である` (本番相当の構成)
  - `R1: 参照接続の driver が redis なら違反する`
  - `R2: 参照接続の connection が業務 DB と異なれば違反する`
  - `R3: 参照接続の after_commit が true なら違反する`
  - `R3: 参照接続に after_commit キーが無ければ違反する (fail-closed)`
  - `R4: sync の after_commit が true でなければ違反する`
  - `R4: sync 接続の定義自体が無ければ違反する`
  - `R5: production で既定接続が sync なら違反する` ← Codex Round 5 の持ち越し 1
  - `R5: production で既定接続が redis なら違反する`
  - `R5: production で既定接続が未定義なら違反する`
  - `R5: production で既定接続が database なら違反しない`
  - `R5 は非 production では評価されない (テストレーンの sync が通る)`
  - `pin 済み 3 接続はいずれも検査対象に入る (既定接続だけを見ていない)`
  - `queue.connections が配列でない場合は違反として報告する (例外を投げない)`
  - `queue.default が非 string / 空文字 / 未定義なら違反する (fail-closed)`
  - `database.default が非空 string でなければ違反する (fail-closed)`
  - `参照接続の定義が欠落 / 非配列なら違反する (fail-closed)`
  - `参照接続の connection が null なら許可される (既定 DB 接続)`
  - `参照接続の connection が非 string / 空文字なら違反する (fail-closed)`
  - `sync 接続が非配列なら違反する (fail-closed)`
  - `sync 接続の driver が欠落 / 非 string / 'database' なら違反する`
  - `pin 済み接続 (database-analysis) の driver が sync なら R1 違反になる`
    ← sync 除外を driver ではなく**接続名**で行うことの固定 (Codex Round 4 の Critical)
    ← これが無いと「接続の `connection` が null = 既定 DB なので OK」の判定が
    比較対象不在のまま通ってしまう (Codex Round 1 の Warning)
- [ ] **drift 防止 (既存テストへの追記)**: `tests/Architecture/QueuedJobLeaseInventoryTest.php` に
  1 テスト追加 — `QueueDispatchAtomicityGuard::PINNED_CONNECTIONS は
  QUEUED_JOB_LEASE_INVENTORY の明示接続集合と一致する` (対称差 0)。
  同 inventory は全 `ShouldQueue` クラスに対して deny-by-default で接続を固定しているため、
  ここに繋げば **新しい pinned connection が増えたときに guard 側の見落としが検出できる**。
  既存テストの削除・書き換えは行わず**追加のみ**
  - **抽出規則を固定する** (実装ごとに意味が揺れないように):
    「明示接続集合」= `QUEUED_JOB_LEASE_INVENTORY` の**値**のうち
    (1) `null` を除外 (= 既定接続の entry)、(2) `array_unique`、(3) `sort` で正規化したもの。
    `sync` と既定接続名は**含めない** (`onConnection` リテラルで pin された接続だけが対象)
  - **負のコントロール**: inventory 側に架空の接続 (`'database-imaginary'`) を 1 件足す変異で
    この対称差テストが落ちることを確認する (mutation 表 #15)
- [ ] 新規: `tests/Feature/Support/QueueDispatchAtomicityGuardBootTest.php`
  - `AppServiceProvider::boot() から guard が呼ばれている` (違反構成を set した上で
    アプリを再 boot して例外を assert)
  - `config:cache 相当 (config を配列から直接読む状態) でも判定が変わらない`
    ← Codex Round 5 の持ち越し 1 (config cache 後の同値性)
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (DB を使わないため N/A)

### リスク

- **全環境で fail-fast する**ため、構成不備のある環境が起動できなくなる。
  想定される環境の通過性を実装時に確認する:
  - テストレーン: `QUEUE_CONNECTION=sync` → R1〜R3 は sync のため対象外、R4 は M1 で満たす、
    R5 は非 production のため評価されない → 通る
  - bug-hunt (`scripts/bug-hunt-shard.sh` が `env -i` で起動): `QUEUE_CONNECTION` 未定義 →
    既定 `database`、`DB_QUEUE_CONNECTION` 未定義 → `connection` は null (既定 DB 一致) → 通る
  - `production:preflight` / `php artisan` 全般: 同上
  - **確認手順**: `scripts/bug-hunt-shard.sh self-test` と `php artisan production:preflight`、
    および `php artisan route:list` を実装時に実行する
- R5 は `app()->environment('production')` に依存する。`APP_ENV` の設定ミスがあると
  検査が緩む。これは `ProductionEnvGuard` と同じ前提であり、本設計では強化しない

---

## M7: deny-by-default 目録型 gate (0 件 pin + 負のコントロール)

### 変更箇所

- 新規: `tests/Support/Queue/QueueDispatchDeferralInventory.php` (検出器の純関数群)
- 新規: `tests/Architecture/QueueDispatchAtomicityInventoryTest.php`

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- 母集団は既存の `Tests\Support\QueuedJobPopulation` を**再利用**する (2 実装を作らない。
  同ファイルの docblock が「2 実装に分かれると片方だけ更新される drift が起きる」と明記)

### 現行コード

`tests/Architecture/` に `QueueDispatch*` は 0 件 (実確認済み)。

### 変更後コード

```php
// tests/Support/Queue/QueueDispatchDeferralInventory.php
/**
 * キュー投入の commit 後ずらし (deferral) を検出する純関数群。
 *
 * 【5 種の検出器】`Queue::shouldDispatchAfterCommit()` の解決順
 * (ShouldQueueAfterCommit → job の `$afterCommit` プロパティ → 接続 config) に
 * 1:1 で対応させ、どの層からも迂回できないようにしている。
 * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
 * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
 * - D3 `ShouldQueueAfterCommit` の実装        … **リフレクション判定** (文字列走査ではない)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
 *   **実行時代入は文字列走査**。`public bool $afterCommit = true;` /
 *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
 *   これを落とすと「0 件 pin」の主張が嘘になる
 *
 * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
 * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
 * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
 * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
 *
 * 【D1 / D2 / D5(代入) は「文字列 grep」ではなく token 走査で行う】
 * 既存の `Tests\Support\PhpTokenScan::normalize()` を再利用する
 * (`token_get_all()` の正規化。`T_WHITESPACE` / `T_COMMENT` / `T_DOC_COMMENT` を除去済み。
 * `QueuedJobLeaseInventoryTest` と `ExternalClientBoundaryScanner` が既に共用しており、
 * 「同じ正規化を 2 本持たない」と docblock が明記している)。
 * 素の `str_contains()` にすると **本設計自身が破綻する** — M8 の反転 docblock は
 * 旧主張として `->afterCommit()` を引用するため、コメントを見る検出器では
 * 反転を書いた瞬間に gate が落ちる。token 走査ならコメントも文字列リテラルも
 * (`T_CONSTANT_ENCAPSED_STRING` を明示除外して) 対象外にできる。
 *
 * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
 * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
 * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
 * 偽グリーンを閉じられない。
 */
final class QueueDispatchDeferralInventory
{
    /**
     * D1/D2 の走査母集団となる first-party ランタイム PHP のルート。
     * **`app/` だけでは狭い** — `DB::afterCommit` は `routes/console.php` や
     * `bootstrap/app.php` にも書けるため (Codex Round 1 の Warning)。
     * `vendor/` / `tests/` / `storage/` は対象外 (前者は自リポジトリの管轄外、
     * 後者 2 つはランタイム経路ではない)。この定数が母集団境界の唯一の正本。
     *
     * @var list<string>
     */
    public const RUNTIME_ROOTS = ['app', 'routes', 'bootstrap', 'database', 'config'];

    /**
     * 指定ディレクトリ配下の PHP ファイル絶対パス (昇順) を列挙する純関数。
     * **ルートを引数で受ける**ことで、負のコントロールが fixture root を渡して
     * 「列挙 → 読み込み → 検出」の**列挙部分まで**同じコードを通せる
     * (`detectInFiles()` へ直接パスを渡すだけでは列挙部分が検証されない)。
     *
     * ★ **引数は絶対パス**である (Codex Round 3 の Warning)。相対ルートを受けて
     *   内部で `base_path()` を掛ける形にすると、`sys_get_temp_dir()` 配下の
     *   fixture root を渡したときにパスが連結されて列挙できない。
     *   本番側の相対→絶対変換は `runtimePhpFiles()` が行う。
     *
     * ★ 各入力について「**絶対パスであること**」「**存在するディレクトリであること**」を
     *   明示検査し、満たさなければ例外を投げる (docblock だけの契約にしない)。
     *   タイポで存在しないルートを渡したときに黙って 0 件を返すと、
     *   母集団 0 件 fail の意図が空洞化するため。
     *
     * @param  list<string>  $absoluteRoots  絶対パスの既存ディレクトリ
     * @return list<string>
     */
    public static function phpFilesUnder(array $absoluteRoots): array { /* 独立列挙 */ }

    /** @return list<string> 本番母集団 */
    public static function runtimePhpFiles(): array
    {
        return self::phpFilesUnder(array_map(
            static fn (string $root): string => base_path($root),
            self::RUNTIME_ROOTS,
        ));
    }

    /** @param list<string> $paths @return list<array{path: string, line: int, kind: string}> */
    public static function detectInFiles(array $paths): array { /* D1 + D2 */ }

    /** @param list<class-string> $classes @return list<class-string> */
    public static function detectShouldQueueAfterCommit(array $classes): array { /* D3 */ }

    /** @param array<mixed> $connections @return list<string> 違反した接続名 */
    public static function detectAfterCommitEnabledConnections(array $connections): array { /* D4 */ }

    /**
     * D5 (既定値): `$afterCommit` プロパティの default が `true` のクラス。
     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
     * コンストラクタ引数が必要な job でも判定できる)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitProperty(array $classes): array { /* D5 (既定値) */ }

    /**
     * D5 (実行時代入): `$this->afterCommit = true` / `->afterCommit = true` の文字列走査。
     *
     * @param  list<string>  $paths
     * @return list<array{path: string, line: int}>
     */
    public static function detectAfterCommitAssignments(array $paths): array { /* D5 (代入) */ }
}
```

```php
// tests/Architecture/QueueDispatchAtomicityInventoryTest.php (構成)
/*
| キュー投入の commit 後ずらしを deny-by-default で 0 件に固定する (AG-114 確定 1 / AG-126)。
|
| ★ **allow-list を持たない deny-by-default** である。免除目録 (enum) は**作っていない** —
|   確定 1 の queue dispatch 母集団における除外が 0 件だからで、case を 1 つも持たない
|   exemption enum は死んだ機構になる (思考原則 2)。将来除外が必要になったら
|   この gate が落ちるので、そのときに免除機構ごと設計し直すこと。
|
| ★ D1/D2/D5(代入) は **token 走査** (PhpTokenScan) で行い、コメント・docblock・
|   文字列リテラルは対象外にする。素の grep にすると M8 の反転 docblock
|   (旧主張として `->afterCommit()` を引用する) で gate が落ちてしまう。
|
| ★ 保証しないもの: token 走査でも**動的な迂回**には沈黙する —
|   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
|   `$this->afterCommit = $flag;` のような動的値 / vendor 内の afterCommit 使用。
|   (D3 と D5(既定値) はリフレクション判定なので中間 interface・親クラス経由まで拾う)
*/
```

テスト本体 (12 本):

| # | テスト名 | 種別 |
|---|---|---|
| 1 | `D1: first-party ランタイム PHP に ->afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 2 | `D2: first-party ランタイム PHP に DB::afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 3 | `D3: ShouldQueue 実装で ShouldQueueAfterCommit を implement するクラスは 1 件も無い` | 0 件 pin |
| 4 | `D4: after_commit=true を持ってよい接続は sync だけである` | 0 件 pin (全接続集合) |
| 4b | `D5: ShouldQueue 実装で $afterCommit の既定値が true のクラスは 1 件も無い` | 0 件 pin |
| 4c | `D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い` | 0 件 pin |
| 5 | `母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である` | 母集団境界の exact-fit |
| 5b | `母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する` | **ルート集合の独立 pin** |
| 6 | `母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される` | 母集団 0 件 fail (ルート単位) |
| 7 | `母集団: ShouldQueue 実装クラスの列挙は 0 件でない` | 母集団 0 件 fail |
| 8 | `母集団: queue.connections は 0 件でない` | 母集団 0 件 fail |
| 9 | `負のコントロール: fixture ツリーを列挙して D1 を検出する` | 経路統合 |
| 10 | `負のコントロール: fixture ツリーを列挙して D2 を検出する` | 経路統合 |
| 11 | `負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 12 | `負のコントロール: after_commit=true の非 sync 接続を D4 が検出する` | 経路統合 |
| 12b | `負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する` | 経路統合 |
| 12c | `負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する` | 経路統合 |
| 12d | `偽陽性の負のコントロール: コメント / docblock / 文字列リテラル中の ->afterCommit() は検出しない` | 誤検出の固定 |
| 13 | `phpFilesUnder(): 相対パスを渡すと例外になる` | 契約の固定 |
| 14 | `phpFilesUnder(): 存在しないディレクトリを渡すと例外になる (黙って 0 件を返さない)` | 契約の固定 |

- テスト 5 は `Symfony\Component\Finder\Finder` で `RUNTIME_ROOTS` 配下の
  `**/*.php` 正規化済み集合を作り、`QueueDispatchDeferralInventory::runtimePhpFiles()` との
  **対称差が空**を assert する (`Finder` は既に `BillingSyncDispatchInvariantTest` で使われている)。
  検出ロジックの二重実装ではなく**母集団境界の固定**である
- **テスト 5b が要**である (Codex Round 2 の Warning)。テスト 5 と 6 が両方とも
  `RUNTIME_ROOTS` を参照していると、**定数から `routes` を消したときに
  実装列挙と Finder 列挙が同時に狭まり、対称差 0 もルート単位 0 件 fail も通ってしまう**。
  したがって Architecture テスト側に**期待ルート集合をリテラルで独立に固定**し、
  `expect(QueueDispatchDeferralInventory::RUNTIME_ROOTS)->toEqualCanonicalizing(['app', 'routes', 'bootstrap', 'database', 'config'])`
  を置く。テスト 6 のループもこの**テスト側リテラル**を回す (定数を回さない)
- テスト 6 は**ルート単位**で 1 件以上を要求する。全体件数だけを見ると
  「`routes/` だけ丸ごと脱落」が通ってしまうため
- **D3 の母集団は `QueuedJobPopulation::shouldQueueClasses()`** (`app/` 配下の
  `ShouldQueue` 実装) のままでよい。`ShouldQueueAfterCommit` を implement できるのは
  クラスであり、`routes/` にクラス定義は置かないため
- テスト 7 の「完全性」自体は `QueuedJobLeaseInventoryTest` /
  `JobExecutionDedupInventoryTest` が対称差 0 で既に固定しているため、
  本 gate では 0 件 fail のみとし二重実装しない (docblock でその契約を参照する)
- 負のコントロールの fixture は `sys_get_temp_dir()` 配下に `beforeEach` で作り
  `afterEach` で削除する (リポジトリ内にダミー PHP を置かない)。
  **fixture root を `phpFilesUnder()` に渡す**ことで列挙部分も同じコードを通す。
  D3 のダミークラスはテストファイル内で `class` 宣言し、クラス名の list を判定器へ渡す
- **token 走査で誤検出を排除する**: D1/D2/D5(代入) は
  既存の `Tests\Support\PhpTokenScan::normalize()` を再利用し、
  コメント・docblock・文字列リテラルを対象外にする。
  素の `str_contains()` だと **M8 の反転 docblock が旧主張として `->afterCommit()` を
  引用した瞬間に gate が落ちる** ため、本設計では token 走査が必須である
  (テスト 12d がこの性質を固定する)

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (上記シグネチャのとおり)
- [x] null 安全 — `Finder` / `file_get_contents()` の戻り値を `is_string` で narrowing
- [x] DTO を返している — テスト用 Support のため array shape で足りる
  (PHPDoc の array shape で PHPStan level 10 を通す)
- [x] Generics の型パラメータが正しい — `list<string>` / `list<class-string>` を明示

### テスト計画

本施策そのものがテストである。加えて:

- [ ] **mutation で赤化を確認する** (概念設計 §5-2 の変異 5〜9 と 10〜12)。
  各変異は 1 個ずつ入れて 1 回テストし、必ず戻す。結果は実装 PR の devnotes に記録する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認 (Architecture テストは DB 不使用)

### リスク

- 負のコントロールの fixture ディレクトリ作成が `--parallel` 実行で衝突しないよう、
  `tempnam()` / `uniqid()` でプロセスごとに一意なパスを使う
- `QueuedJobPopulation::shouldQueueClasses()` は `class_exists()` による autoload の副作用を伴う
  (同ファイルの docblock が明記)。既存 gate と同じ方式を踏襲するため新たな問題は生まない

---

## M8: 既存 5 契約の反転 (削除ではない)

### 変更箇所

| ファイル | 反転する主張 |
|---|---|
| `tests/Architecture/BillingSyncDispatchInvariantTest.php` (L13) | 「必ず transaction 内から **afterCommit で**発火する (IV-3)」 |
| `tests/Feature/Billing/BillingCustomerSynchronizerTest.php` (L15 / L47-62) | 「dispatch は afterCommit」「job は afterCommit フラグを持つ」 |
| `tests/Feature/Billing/AutoRechargeTriggerTest.php` (L76-91) | 「reserve が rollback したら dispatch されない (**afterCommit の保証**)」 |
| `tests/Feature/Notifications/TicketBalanceLowNotificationTest.php` (L18 / L104) | 「rollback される外側 tx 内の reserve → 通知されない (**afterCommit**)」 |
| `tests/Architecture/JobExclusionOrderingInvariantTest.php` (L51-64) | 「AutoRechargeTriggerJob の `uniqueFor` は retry_after を下回る」「`uniqueFor` は正の値」 |

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし
- **テストの削除は行わない**。すべて「新しい主張」への書き換え + 6 行 docblock を添える

### 現行コード

```php
// BillingSyncDispatchInvariantTest.php L12-15
| SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 1 経路に閉じる (IV-2)。
| 窓口を単一化することで「必ず transaction 内から afterCommit で発火する」(IV-3) /
| 「stripe_id 未作成は no-op」(IV-4) の契約が構造的に守られる。
```

```php
// AutoRechargeTriggerTest.php L76-91
test('reserve が rollback したら dispatch されない (afterCommit の保証)', function (): void {
    Queue::fake();
    // ... 中略 ...
    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
});
```

### 変更後コード

反転は**必ず 6 行の docblock**を添える (「保護対象を消す変更」と区別するため):

```php
/*
| 【契約の反転 (AG-114 確定 1 / T???)】
| - 旧主張: SyncBillingCustomerDetails は transaction 内から afterCommit で発火する (IV-3)
| - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ
| - 新主張: transaction の内側で素直に dispatch し、jobs 行を業務 tx に乗せる
| - 新前提: worker が job を可視化できるのは commit 後 (jobs 行が同一 tx にあるため)
| - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
| - 反転根拠: 本 job は SerializesModels で organization を再取得するため、可視化が commit 後で
|   ある限り IV-3 は afterCommit なしで保たれる。一方 afterCommit は「commit したのに未投入」の
|   窓を残すため、確定 1 の下では有害である
*/
```

```php
// AutoRechargeTriggerTest.php — Queue::fake をやめ実 jobs 表を観測する
/**
 * 【契約の反転 (AG-114 確定 1)】
 * - 旧主張: reserve が rollback したら dispatch されない (afterCommit の保証)
 * - 旧目的: rollback した予約でオートリチャージを起票させない
 * - 新主張: reserve が rollback したら **jobs 行ごと巻き戻る** (業務 tx への相乗り)
 * - 新前提: キュー投入が業務 tx の内側で行われ、jobs 行が同一トランザクションに乗る
 * - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3)
 * - 反転根拠: Queue::fake は enqueueUsing を経由せず即時記録するため、この主張を
 *   fake では検証できない (偽グリーンになる)。実 jobs 表の観測へ切り替える
 */
test('reserve が rollback したら jobs 行が残らない (業務 tx への相乗り)', function (): void {
    config()->set('queue.default', 'database');
    [$organization] = createOrganizationWithOwner();
    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
    $before = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($organization): void {
            app(TicketLedgerService::class)->reserve($organization, 1);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(DB::table('jobs')->count())->toBe($before);
});
```

```php
// JobExclusionOrderingInvariantTest.php — uniqueFor 参照 2 本の反転
/**
 * 【契約の反転 (AG-114 確定 1)】
 * - 旧主張: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回り、正の値である
 * - 旧目的: 入口排他 (ShouldBeUnique) の鍵が再配送間隔を跨いで抑止を残さないようにする
 * - 新主張: AutoRechargeTriggerJob は ShouldBeUnique を **実装しない** (入口排他を持たない)
 * - 新前提: 結果の一回性は maybeCreateAttempt の organizations 行ロック + pending 検査 +
 *   tar_attempts_org_pending_unique (partial unique) + unique violation の no-op 化が担う
 * - 前提を守る機構: AutoRechargeAttemptUniquenessTest (3 点の behavioral 固定) +
 *   JobExecutionDedupInventoryTest の GuardedByDownstreamConstraint 登録
 * - 反転根拠: UniqueLock は dispatch 呼び出し時に取得され rollback で解放されない。業務 tx の
 *   内側で dispatch する設計 (確定 1) では、ネスト深さに依らず rollback 後も uniqueFor 秒の
 *   抑止が残る。AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため撤去する
 */
test('入口の排他: AutoRechargeTriggerJob は ShouldBeUnique を実装しない', function (): void {
    expect(is_subclass_of(AutoRechargeTriggerJob::class, ShouldBeUnique::class))->toBeFalse();
});
```

`JobExclusionOrderingInvariantTest` の**他の対象** (`Cache::lock` TTL の序列など) は
そのまま維持する (この施策で触るのは `uniqueFor` 参照 2 本だけ)。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (テストクロージャは `: void`)
- [x] null 安全 — `DB::table('jobs')->count()` は `int`
- [x] DTO を返している (N/A)
- [x] Generics の型パラメータが正しい

### テスト計画

本施策そのものがテストである。**既存テストを削除しない**ことをレビューで確認する
(反転後のテスト本数が反転前を下回らないこと)。

### リスク

- 反転の docblock が無いと「保護対象を消した」変更と区別できなくなる。
  6 行形式 (旧主張 / 旧目的 / 新主張 / 新前提 / 前提を守る機構 / 反転根拠) を必須とする
- `TicketBalanceLowNotificationTest:104` は**テスト本体が変わらず緑のまま**になる
  (通知行が外側 tx に乗るため)。docblock の根拠だけ反転する。
  「変えなくても通るから触らない」ではなく、根拠が変わったことを明記する

---

## M9: 原子性の behavioral 検証

### 変更箇所

- 新規: `tests/Feature/Manual/QueueDispatchAtomicityTest.php`
- 新規: `tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php`
- 新規: `tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php`
- 新規: `tests/Feature/Billing/AutoRechargeAttemptDispatchAtomicityTest.php`
- 新規: `tests/Feature/Billing/StripeWebhookDispatchAtomicityTest.php`
- 新規: `tests/Support/Queue/RecordsJobQueueingTransactionLevel.php` (共通ヘルパ)

### 波及変更

- TypeScript 型定義: なし / API Resource/DTO: なし

### 現行コード

該当なし (新規)。

### 変更後コード

```php
// tests/Support/Queue/RecordsJobQueueingTransactionLevel.php
/**
 * キュー投入時点の DB トランザクション深さを記録するテストヘルパ。
 *
 * Illuminate\Queue\Events\JobQueueing は `Queue::enqueueUsing()` の内部から発火するため、
 * 「実際に push が起きた瞬間」の tx level を観測できる。
 *
 * ★ **Queue::fake() と併用してはならない**。QueueFake::push() は enqueueUsing を通らず
 *   即時記録するため、この観測点も after_commit の解決も素通りする
 *   (BillingCustomerSynchronizerTest の docblock が既に警告している落とし穴)。
 *
 * ★ 判定は **action 直前の `DB::transactionLevel()` (baseline) + 1 以上**である。
 *   固定値 (`>= 2`) では判定しない — ネストの深さはテストの書き方で変わるため。
 */
final class RecordsJobQueueingTransactionLevel
{
    /**
     * ★ **1 テスト 1 capture**。同一テスト内で複数回呼ぶと listener が重複し記録が混線する。
     *
     * ★ listener の隔離は **元 dispatcher に listener を足し、capture 終了後に
     *   その closure を不活性化する**方式で行う。採らなかった 2 案とその理由:
     *   - `Event::forget(JobQueueing::class)`: capture 以前から存在した同イベントの
     *     listener まで削除する。「現時点で grep 0 件」は恒久的な安全性にならない
     *   - **dispatcher の clone へ swap**: Laravel の `QueueManager` は解決済みの
     *     queue connection をキャッシュし、connection は自分が持つ container 経由で
     *     event dispatcher を引く。swap の前に connection が生成済みなら clone 側の
     *     listener が `JobQueueing` を捕捉できず、swap 中に生成された connection は
     *     capture 後も clone dispatcher を握り続けうる (Codex Round 4 の Critical)
     *   不活性化方式なら dispatcher の差し替えも既存 listener の削除も起きない。
     *   グローバルな `RefreshApplication` によりテスト終了時に dispatcher ごと破棄され、
     *   「1 テスト 1 capture」の規約下では不活性 listener はそのテスト中に高々 1 個残るだけである。
     *
     * ★ 戻り値は **配列ではなく可変 collector オブジェクト**である。
     *   配列を返すと、返却後に listener が参照先のローカル配列へ追記しても
     *   PHP の copy-on-write により呼び出し側の配列は増えず、
     *   「capture 後に記録が増えないこと」を検査する自己テストが
     *   **不活性化を消しても緑のまま**になる (Codex Round 5 の Warning)。
     *   同じオブジェクトを見る collector なら mutation #18 で確実に赤になる。
     *   collector が保持するのは **クラス名 (string) と深さ (int) だけ**で、
     *   job payload そのものは保持しない (不活性 listener が長生きするため)。
     */
    public static function capture(callable $action): JobQueueingTransactionRecords
    {
        $collector = new JobQueueingTransactionRecords;

        Event::listen(JobQueueing::class, function (JobQueueing $event) use ($collector): void {
            $job = $event->job;
            $collector->record(is_object($job) ? $job::class : (string) $job, DB::transactionLevel());
        });

        try {
            $action();
        } finally {
            $collector->active = false; // action が例外を投げても必ず不活性化する
        }

        return $collector;
    }

    /**
     * 対象ジョブクラスの記録だけを抜き出す。
     * action 中に付随ジョブが増えても無関係な理由で壊れないようにするため、
     * assert は必ずこの filter を通した結果に対して行う。
     *
     * @param  list<array{job: string, level: int}>  $records  `$collector->all()` を渡す
     * @return list<array{job: string, level: int}>
     */
    public static function only(array $records, string $jobClass): array
    {
        return array_values(array_filter($records, static fn (array $r): bool => $r['job'] === $jobClass));
    }
}

/**
 * capture の記録先 (可変 collector)。**テスト Support 内部だけの機構**である。
 * 配列で返すと copy-on-write により「capture 後に増えないこと」を検査できないため、
 * 同一オブジェクトを参照させる (Codex Round 5 の Warning)。
 */
final class JobQueueingTransactionRecords
{
    /** @var list<array{job: string, level: int}> */
    private array $records = [];

    public bool $active = true;

    public function record(string $job, int $level): void
    {
        if ($this->active) {
            $this->records[] = ['job' => $job, 'level' => $level];
        }
    }

    /** @return list<array{job: string, level: int}> */
    public function all(): array
    {
        return $this->records;
    }
}
```

テスト例:

```php
// tests/Feature/Manual/QueueDispatchAtomicityTest.php
test('解析トリガの RunManualAnalysis は業務 tx の内側で投入される', function (): void {
    // Queue::fake は使わない (原子性を観測できない)。
    // **前提**: database 接続かつ after_commit=false であること
    // (after_commit=true だと JobQueueing は commit 後の callback 内で発火し、
    //  観測される level が baseline に落ちるため主契約が成立しない)。
    config()->set('queue.default', 'database');
    expect(config('queue.connections.database-analysis.after_commit'))->toBeFalse();

    [$project, $manual] = analyzableManual();   // Factory 経由のヘルパ

    $baseline = DB::transactionLevel();
    $collector = RecordsJobQueueingTransactionLevel::capture(
        fn () => app(AnalysisJobService::class)->trigger($project, $manual),
    );
    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualAnalysis::class);

    expect($target)->toHaveCount(1);
    // baseline = action 直前の深さ。業務 tx の内側なら必ず 1 段深い
    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
});

test('外側 tx が rollback すると analysis_jobs も jobs 行も残らない', function (): void {
    config()->set('queue.default', 'database');
    [$project, $manual] = analyzableManual();
    $jobsBefore = DB::table('jobs')->count();

    try {
        DB::transaction(function () use ($project, $manual): void {
            app(AnalysisJobService::class)->trigger($project, $manual);

            throw new RuntimeException('意図的な rollback');
        });
    } catch (RuntimeException) {
        // 期待どおり
    }

    expect(AnalysisJob::query()->count())->toBe(0);
    expect(DB::table('jobs')->count())->toBe($jobsBefore);
});
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`capture(): JobQueueingTransactionRecords` /
  `JobQueueingTransactionRecords::all(): list<array{job: string, level: int}>` /
  `only(): list<array{job: string, level: int}>`)
- [x] null 安全 — `JobQueueing::$job` は `mixed` 相当のため `is_object` で narrowing
- [x] DTO を返している — テスト Support のため array shape で足りる
- [x] Generics の型パラメータが正しい

### テスト計画

- [ ] 上記の各テストを**先に赤で置く** (現行実装では tx 外 dispatch のため
  level が 1 になり、rollback テストも jobs 行が残らないことを確認できない)
- [ ] 全経路で「tx level 観測 (主契約)」と「rollback で両方巻き戻る (補助)」の 2 系統を持つ
- [ ] 新規: `tests/Feature/Support/Queue/RecordsJobQueueingTransactionLevelTest.php`
  **(実 database queue 経由で確認する。`Event::dispatch()` を直接叩くだけでは
  `QueueManager` 経由の発火経路を検証したことにならない — Codex Round 4)**
  - `capture 中は JobQueueing を記録する`
  - `capture 前から存在する listener は capture 中も capture 後も動く`
  - `capture 後に別ジョブを dispatch しても collector->all() の件数が増えない`
    (**同一 collector オブジェクトを capture 前後で比較する**。配列を返す設計だと
    copy-on-write でこの検査が空振りする)
  - `action が例外を投げても、その後 records が増えない`
  - `only() は対象ジョブクラスの記録だけを返す`
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (`Project::factory()` / `VideoManual::factory()` /
  `SourceDocument::factory()` / `Organization::factory()` 等)

### リスク

- `config()->set('queue.default', 'database')` はテスト内で `sync` を上書きする。
  **その結果ジョブ本体は実行されない** (jobs 行が積まれるだけ)。
  「ジョブが実行されること」を検査する既存テストとは目的が違うため混同しない
- `jobs` テーブルは `RefreshDatabase` のラッパ tx 内にあるため、テスト終了時に巻き戻る。
  他テストとの干渉はない (`--parallel` でも DB が分かれる)
- listener の隔離は **元 dispatcher に足して capture 後に不活性化する**方式で行う。
  `Event::swap($original)` では listener が消えず、`Event::forget()` は既存 listener まで消し、
  **dispatcher の clone へ swap すると `QueueManager` の connection キャッシュと不整合を起こす**
  (Codex Round 2 の Critical / Round 3・4 の指摘)。
  docblock に「1 テスト 1 capture」を明記し、自己テストで挙動を固定する
- **観測の前提**: `JobQueueing` は `Queue::enqueueUsing()` の中で発火するため、
  `after_commit=true` の接続では **commit 後の callback 内**で発火し level が baseline に落ちる。
  M9 のテストは `queue.default='database'` (= `after_commit=false`) を前提にし、
  その前提自体をテスト内で assert する

---

## M10: 契約の文書化

### 変更箇所

- `docs/architecture.md` (§ジョブの重複実行と結果の一回性 / §アプリ内通知の配信保証)
- `AGENTS.md` (ドメイン固有規約に 1 項追加)
- `.env.example` (`QUEUE_CONNECTION` / `DB_QUEUE_CONNECTION` の説明コメント)

### 波及変更

- `tests/Architecture/EnvExampleInvariantTest.php` が `.env.example` の内容を検査している
  可能性がある。実装時に確認し、必要なら期待値を更新する
- `AGENTS.md` の変更は `CLAUDE.md` の指示 (「CLAUDE.md への変更依頼はすべて AGENTS.md に書く」)
  に従い AGENTS.md 側のみ

### 変更後コード (AGENTS.md 追記案)

```markdown
9. **キュー投入の原子性**: 業務状態の保存とキュー投入は**同一トランザクション内**で行う
   (`afterCommit` に依存しない)。`->afterCommit()` / `DB::afterCommit` /
   `ShouldQueueAfterCommit` / job の `$afterCommit = true` プロパティ /
   config の `after_commit => true` (sync 以外) は
   **すべて 0 件で pin** されている (`QueueDispatchAtomicityInventoryTest` が
   deny-by-default。allow-list は持たない = 免除機構そのものが無い)。
   原子性の前提 (driver=database / キュー DB 接続 = 業務 DB / after_commit=false /
   production の既定接続が sync でない) は `QueueDispatchAtomicityGuard` が
   **全環境の起動時**に fail-closed 検査する。
   - `config/queue.php` の `sync` は **`after_commit => true` が必須**。これが無いと
     tx 内 dispatch がテストレーンで即時インライン実行され、pipeline の `startJob` が
     自分自身のロック下で成立してしまう
   - **`Queue::fake()` では原子性を検証できない** (`QueueFake::push()` は
     `enqueueUsing` を通らない)。原子性の検証は実 `jobs` 表と
     `JobQueueing` の `DB::transactionLevel()` 観測で行う
   - **保証しないもの**: 検出は文字列パターン (D1/D2) とリフレクション (D3) の併用で、
     動的な迂回 (`$m = 'afterCommit'`) や helper 経由の呼び出しには沈黙する。
     guard は config の値だけを見るため、`connection` 名の一致は
     「同一トランザクションに乗る」ことの**代理検査**にすぎない。
     また **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない** —
     gate が固定するのは「commit 後ずらしの機構を使っていないこと」までで、
     既知経路が実際に tx 内で投入していることは behavioral test が固定する
   - 詳細は `docs/architecture.md` §キュー投入の原子性
```

### PHPStan適合チェック

該当なし (文書のみ)。

### テスト計画

- [ ] `tests/Architecture/EnvExampleInvariantTest.php` が緑であることを確認
- [ ] `docs/` の記述と実装の齟齬がないことをレビューで確認

### リスク

- AGENTS.md の記述が実装より強くなる (誇張) と、後続の LLM が誤った前提で設計する。
  「保証しないもの」を必ず併記する

---

## 保証しないもの (設計全体。誇張しない)

1. **消えるのは「業務状態を commit したのにキューへ投入されない」窓だけ**である。
   - commit **前**の障害では、業務状態も jobs 行も同一トランザクションなので**両方 rollback する**
     (不整合窓ではない)
   - commit **後**は jobs 行が残るが、**worker がそれを処理することは保証しない**
   - DB の commit 結果自体が不明になる障害 (ネットワーク断など) は本設計の対象外
2. **worker が実際に起動していることは保証しない**。jobs 行が載っても
   `queue:work database-analysis` 等が動いていなければ前進しない (既存の運用契約の管轄)
3. **D1/D2/D5(代入) は token 走査による構文パターン検出**。
   `$m = 'afterCommit'; $job->$m();` / helper・facade alias で包んだ呼び出し /
   vendor 内の afterCommit 使用には沈黙する
   (D3 と D5(既定値) はリフレクションなので中間 interface・親クラス経由まで拾う)
4. **guard は config の値だけを見る**。`connection` 名の一致は「同一トランザクションに乗る」ことの
   **代理検査**であり、異なる PDO / connection resolver の差し替え / 同名で別サーバを指す構成は検査しない
5. **`Queue::fake()` を張ったテストでは原子性を検証できない**
6. **低残高通知は原子的でない**。`reserve()` が最外層のときは commit→通知の窓が残る
   (at-most-once = 既定仕様)。ネスト時は外側 tx に相乗りするため、
   **通知 INSERT の SQL 失敗が業務 tx を abort させうる**。
   fake channel 方式のテストが保証するのは**アプリケーション層の例外分離だけ**であり、
   PostgreSQL の transaction abort までは保証しない
7. **`ShouldBeUnique` の unique lock が rollback で解放されない性質そのものは残る**。
   撤去するのは `AutoRechargeTriggerJob` だけで、今後 `ShouldBeUnique` を持つ job を
   業務 tx の内側から dispatch する設計を足すと同じ問題が再発する。**機械検査しない**
8. **`DB::transaction()` の attempts が全経路で既定の 1 である前提は機械固定していない**。
   現行実査 (2026-08-08 / main = c71061e) では `app/` に第 2 引数の使用が 0 件。
   attempts>1 を導入すると sync レーンで「commit callback 内の concurrency error →
   業務クロージャの重複実行、または commit 済みなのに例外応答」が起きうる。
   本番は M6 の R5 が sync を拒否するため対象外
9. **`isUniqueViolation()` は SQLSTATE だけを見て制約名を識別しない**ため、
   別の unique 制約の違反も no-op に収束する (既存の性質。本 PR では変更しない)
10. **`Bus::batch` / `Bus::chain` の原子性は検査しない** (`app/` に 0 件のため)
11. **「dispatch が業務 tx の内側にあること」の静的完全性は保証しない**。M7 の gate が
    固定するのは「commit 後ずらしの機構 (D1〜D5) を使っていないこと」までである。
    tx 外に置かれた新しい dispatch は gate に映らない。既知経路が実際に tx 内で投入して
    いることは M9 の behavioral test (tx level 観測) が経路ごとに固定する
12. **M9 の rollback テストは「業務 tx 内移設」を検出しない**。旧実装 (service 内 tx の
    commit 後に dispatch) でも、テストが外側 tx で包めば jobs 行は rollback で消えるため。
    移設を検出するのは tx level 観測 (`baseline + 1` 以上) だけである
13. **tx level 観測は「対象ジョブが実際に使う接続が `database` driver かつ
    `after_commit=false` であること」に依存する**。`after_commit=true` の接続では
    `JobQueueing` が commit 後の callback 内で発火し、観測される level が baseline に落ちる。
    `queue.default='database'` の設定が効くのは **`onConnection` で pin されていないジョブ**
    だけで、pin 済みジョブ (`database-analysis` 等) には直接効かない (16 番と対応)。
    テストは対象ジョブの pin 先接続について前提自体を assert する
14. **pinned connection 集合の完全性は `QueuedJobLeaseInventoryTest` の接続抽出能力に依存する**。
    同 inventory が `onConnection` リテラル以外の形 (動的接続) を扱えないことは
    同テストの docblock が明記しており、その限界がそのまま guard の R1〜R3 の適用範囲になる
14b. **D5 は動的な値の代入 (`$this->afterCommit = $flag;`) を検出できない**。
    既定値のリフレクション判定にも `= true` の token パターンにも映らないため。
    これは 0 件 pin の穴として残る (誇張しない)
15. **D1/D2/D5(代入) は token 走査**なのでコメント・docblock・文字列リテラルは
    対象外になる。裏を返すと、**文字列で組み立てた動的呼び出しには沈黙する**
16. (13 番の運用上の注意) **どの接続を検査すべきかを間違えない**。
    `queue.default='database'` が効くのは `onConnection` で pin されていないジョブだけで、
    pin 済みジョブ (`database-analysis` / `database-render` / `database-media`) には効かない。
    テストは**対象ジョブの pin 先接続**の `after_commit=false` を assert すること

---

## mutation で赤化を確認する手順 (実装時に必ず実施)

**表の目的**: 「各変異が**少なくとも意図した検査で赤になる**」ことの確認である
(1 変異 = 1 テストの厳密な 1:1 対応ではない。#2 のように 2 つの検査点を同時に落とす変異もある)。

| # | 変異 | 落ちるべきテスト |
|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | `QueueDispatchAtomicityGuardTest` (R4) |
| 2 | `database` の `after_commit` を `true` にする | 同 (R3) / `QueueDispatchAtomicityInventoryTest` (D4) |
| 3 | `database-render` の `connection` を別 DB 名にする | `QueueDispatchAtomicityGuardTest` (R2) |
| 4 | production 判定時の既定接続を `sync` にする | 同 (R5) |
| 5 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | `QueueDispatchAtomicityTest` の tx level テスト |
| 6 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | `QueueDispatchAtomicityInventoryTest` (D1) |
| 7 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | 同 (D3) |
| 8 | `TicketLedgerService` に `DB::afterCommit` を戻す | 同 (D2) |
| 9 | D1〜D5 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (9〜12c 番) が**それぞれ**落ちる |
| 10a | `QueueDispatchDeferralInventory::phpFilesUnder()` を空配列返しにする | M7 テスト 5 (対称差) と テスト 6 (ルート単位 0 件 fail) |
| 10b | `QueuedJobPopulation::shouldQueueClasses()` を空配列返しにする | M7 テスト 7 (`ShouldQueue` 母集団 0 件 fail) と既存 `QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest` の対称差 |
| 11 | `phpFilesUnder()` の走査から `app/Jobs` を除外する | M7 テスト 5 (Finder による独立列挙との対称差) |
| 12 | `AutoRechargeTriggerJob` に `ShouldBeUnique` を戻す | `JobExclusionOrderingInvariantTest` の反転テスト |
| 13 | `AutoRechargeTriggerJob` の dispatch を `reserve()` の tx の外へ戻す | **`TicketReserveDispatchAtomicityTest`** の tx level テスト。`AutoRechargeAttemptDispatchAtomicityTest` は `createAttemptLocked()` 内の**別ジョブ**を見ているので落ちない。rollback テストも落ちない (テストの外側 tx 内で実行されるため) |
| 13b | `createAttemptLocked()` の `ExecuteAutoRechargeAttemptJob::dispatch` を tx の外へ戻す | `AutoRechargeAttemptDispatchAtomicityTest` の tx level テスト |
| 14 | `tar_attempts_org_pending_unique` を外す | `AutoRechargeAttemptUniquenessTest` の 2 番目 |
| 15 | `QUEUED_JOB_LEASE_INVENTORY` に架空の接続 (`'database-imaginary'`) を 1 件足す | `QueuedJobLeaseInventoryTest` に追加した `PINNED_CONNECTIONS` 対称差テスト |
| 16 | `QueueDispatchDeferralInventory::RUNTIME_ROOTS` から `routes` を消す | テスト 5b (ルート集合の独立 pin)。**テスト 5 と 6 だけでは落ちない**ことも同時に確認する |
| 17 | `config/queue.php` の `sync` の `driver` を `database` に変える | `QueueDispatchAtomicityGuardTest` (R4 の driver 検査) |
| 18 | `RecordsJobQueueingTransactionLevel::capture()` の `$collector->active = false;` (finally) を削る | `RecordsJobQueueingTransactionLevelTest` の「capture 後に別ジョブを dispatch しても `collector->all()` の件数が増えない」 (collector 方式でないと copy-on-write で空振りする) |
| 19 | `config/queue.php` の `database-analysis` の `driver` を `sync` に変える | `QueueDispatchAtomicityGuardTest` (R1。sync 除外を接続名で行っていないと**落ちない**) |
| 20 | 任意の job クラスに `public bool $afterCommit = true;` を足す | `QueueDispatchAtomicityInventoryTest` (D5 既定値)。**D1〜D4 では落ちない**ことも同時に確認する |
| 21 | 任意の job クラスのコンストラクタに `$this->afterCommit = true;` を足す | 同 (D5 代入) |

**各変異は 1 個ずつ入れて 1 回テストし、必ず戻す。** 手順と結果 (どのテストがどう落ちたか) は
実装 PR の devnotes に記録する。

---

## 未解決事項 (本 PR では扱わない)

1. **`AutoRechargeService::isUniqueViolation()` が制約名を識別しない**。
   SQLSTATE (23505/23000) だけを見るため、別の unique 制約の違反も no-op に収束する。
   変更すると auto-recharge の失敗分類の設計に踏み込むため本 PR では触らない (思考原則 2)。
   ただし **本 PR は `ShouldBeUnique` を撤去して DB 制約への依存を強める**ため、
   追跡先を残す: 実装時に `docs/TODO.md` へ「auto-recharge の unique violation 判定を
   対象制約名で識別する」を **Low** で起票し、本 devnotes へのリンクを添える
   (Codex Round 1 の Suggestion)
2. **`DB::transaction()` の attempts を機械固定する gate**。適用範囲が sync レーン
   (テスト / dev) に限られ、本番は R5 が sync を拒否するため作らない
3. **`recoverStale` を「再投入」へ変える**こと。確定 1 が入れば dispatch 喪失は起きないため不要

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | (1) `config/queue.php` の `sync.after_commit` が**全テストレーンの実行意味論**を変えるため、他タスクと同居させると失敗の切り分けができない。(2) 既存 5 契約の反転を含み、他タスクの差分と混ざると「保護対象を消した」のか「反転した」のかがレビューで判別できない。(3) AG-126 の 0 件 pin は全経路を直し終わるまで導入できず、分割すると main に「afterCommit を撤去した経路と温存した経路」が並走する (思考原則 3)。(4) 前段 PR に gate を入れると「無効な gate」か「虚偽の期待値を持つ gate」を自分で作ることになり、`QueueWorkerLeaseInvariantTest` が config の env 上書きを禁じる理由として挙げている「gate が嘘をつく」失敗形をそのまま踏む |
| 競合リスク | **高**。`app/Services/Billing/` (TicketLedgerService / AutoRechargeService / StripeWebhookProcessor / BillingCustomerSynchronizer) と `app/Services/Manual/` の主要ファイルに広く触る。並行タスクが同ファイルを触っている場合は本タスクを先に完了させるか、明示的に順序を決める。**`app/Actions/Inquiry/CreateInquiryAction.php` は読むだけで変更しない** (並行タスクが同ファイルの別行を触っている) |
| 実装順序 | 概念設計 §11-2 (施策ごとに「テストを書いて赤 → 実装 → 緑」)。M6 → M1 → M9 (赤) → M2 → M3 → M4 → M5 → M8 → M7 → mutation → M10 |
