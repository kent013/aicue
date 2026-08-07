# impl-review Round 2

Round 1 の 1 件の [Critical] と 3 件の [Warning] に対する対応です。
対応マトリクスをまず提示し、そのあとに (1) 修正差分、(2) Round 1 の diff スコープから
漏れていた S7 (文書) の差分、を添付します。

---

## 対応マトリクス (Round 1 の指摘へ)

# 対応マトリクス: impl-review Round 1

## [Critical] `writeProgress()` の mass update が `result_json` の cast を通らない

- 判断: **対応する**
- 根拠: 事実確認した結果、**指摘の半分は正しく、半分は誤り**だった。
  - 誤り: 「runtime で失敗しうる / 不正値が入りうる」— 本アプリの DB は pgsql で、
    `PostgresGrammar::prepareBindingsForUpdate()` が `is_array($value)` の値を
    `json_encode()` するため、実際には正しい JSON が入る
    (既存の成功パステスト `expect($job->result_json)->toHaveKey('steps')` が
    round-trip を behavioral に固定しており green)。
  - 正しい: `Illuminate\Database\Eloquent\Builder::update()` は
    `addUpdatedAtColumn()` **だけ**が cast を通す実装で、それ以外の列に
    モデルの cast (`castAttributeAsJson` / `getJsonCastFlags`) を適用しない。
    つまり `save()` 経路と**エンコード主体が違う**。将来 cast を
    `AsArrayObject` / 暗号化 cast などへ変えたり、driver が変わったりすると静かにずれる。
    「素の save() と同じ表現で書く」という意図がコードから読めないのも良くない。
- 対応内容: `writeProgress()` で `(new AnalysisJob)->forceFill($attributes)->getAttributes()` を
  経由し、**cast 済みの生値**を条件付き UPDATE に渡すようにした
  (Laravel 自身が `addUpdatedAtColumn()` で使っている手口と同じ)。
  条件付き UPDATE (`where status=running`) はそのまま維持。
  `RenderPipeline::updateProgress()` は書く 2 列が「cast 適用後と同一表現のスカラー」
  (enum の backing value と int) のみなので正規化は挟まず、**その理由と、
  配列 / 日時列を足すときは同じ処理を通すこと**を docblock に明記した
  (今必要のない churn を作らない = AGENTS.md 思考原則 2)。
- 再検証: `composer test -- tests/Feature/Projects/AnalysisPipelineTest.php` 40 passed /
  `composer phpstan` OK / mutation **M11 の赤化を再確認**。

## [Warning] `JobExclusionOrderingInvariantTest` が `queue.connections.database.retry_after` をハードコードしている

- 判断: **対応する (ただし提案された実装 (`config('queue.default')` を読む) は採らない)**
- 根拠: 指摘の懸念 (「既定接続が変わっても gate が green のまま別レーンと比較する」) は正しい。
  一方、提案どおり実行時の `config('queue.default')` を読むと**壊れる**:
  テストレーンは `phpunit.xml` が `QUEUE_CONNECTION=sync` を force しており、
  `sync` 接続は `retry_after` を持たないため gate 自体が error になる (実測で確認した)。
  「本番の既定接続」は `config/queue.php` の `env('QUEUE_CONNECTION', 'database')` の
  **フォールバック値**であって実行時の値ではない。
- 対応内容: 比較先は `database` のまま (定数 `JOB_EXCLUSION_DEFAULT_CONNECTION` に切り出し)、
  前提を固定するテストを 1 本追加した:
  `入口の排他: 比較先の前提 — 本番の既定キュー接続は database である`。
  `config/queue.php` のソースに `'default' => env('QUEUE_CONNECTION', 'database')` が
  あることを検査する (既存 `QueueWorkerLeaseInvariantTest` が「env 上書きを残すと
  gate が嘘をつく」として retry_after をリテラルで持たせているのと同じ発想)。
  加えて比較先が実在すること (`> 0`) も確認し degenerate PASS を防ぐ。
  なぜ実行時 `queue.default` を使えないかを関数の docblock に残した。
- 再検証: `composer test -- tests/Architecture/JobExclusionOrderingInvariantTest.php` 5 passed /
  mutation **M5 / M6 の赤化を再確認**。

## [Warning] `terminateInvoiceBestEffort()` が `$exception->getMessage()` を構造化ログへ入れている

- 判断: **反論する (現状維持)**
- 根拠:
  1. **PII は入らない**。この経路の例外は (a) `CashierAutoRechargeGateway::terminateInvoice()` の
     `Assert` (メッセージは invoice id と status のみ)、(b) Stripe SDK の
     `InvalidRequestException` (「No such invoice: in_xxx」等の API エラー文言) の 2 種で、
     顧客の email / name / カード情報を含まない。ログの PII 禁止契約は
     `LOG_EVENT` (抑止ログ) の 7 キー schema が担っており、そちらには一切入っていない
     (`JobOwnershipLostContextTest` が固定)。
  2. **`error` は運用契約上 load-bearing である**。`docs/architecture.md`
     §ジョブの重複実行と結果の一回性 で「恒久回収を持たない open invoice」の検知シグナルを
     **`event = job_ownership_lost_cleanup` かつ `terminated=false`** と定義し、
     その原因判別に `error` を使うと明記した。カテゴリだけに丸めると
     「なぜ void できなかったのか」が失われ、手動収束の手順が成立しなくなる。
  3. **同一クラスの既存実装と揃っている**。すぐ隣の `tryTerminateInvoice()` が
     以前から `'error' => $e->getMessage()` を出しており、片方だけ丸めると
     同じ事象の観測が 2 系統に割れる (後方互換の並走を残さない = 思考原則 3 に反する)。
  4. 詳細設計 (design-review Round 7 APPROVED) が cleanup ログの 7 キー schema を確定しており、
     `後始末ログは別 event 名 job_ownership_lost_cleanup を使い独自 schema を持つ` テストが
     キー集合を固定している。schema 変更は設計の再合議が要る規模である。
- 対応内容: 変更しない。Round 2 で上記を提示して合意を取る。

## [Warning] `docs/architecture.md` の S7 差分が見当たらない

- 判断: **見送る (指摘は差分の切り出し範囲に起因する誤認)**
- 根拠: Round 1 で渡した diff は `app/ resources/ tests/ routes/ config/ bootstrap/` に
  スコープしていた (app-implement スキル A-2 の規定)。`docs/` と `AGENTS.md` は
  この範囲外なので写っていないだけで、S7 は実装済みである。
- 対応内容: Round 2 のプロンプトに `docs/architecture.md` と `AGENTS.md` の diff を添付して
  実在を示し、内容 (規約 ↔ テスト対応表 / 閉じない窓 / 運用所有者) をレビューしてもらう。


---

## (1) 修正差分 (Critical 1 件 + Warning 1 件への対応)

```diff
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index f7214f3..39b63f0 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -11,8 +11,10 @@
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\AnalysisStep;
 use App\Enums\Manual\JobStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\InsufficientTicketsException;
 use App\Exceptions\Manual\AnalysisFailedException;
+use App\Exceptions\Manual\JobOwnershipLostException;
 use App\Exceptions\Manual\LlmOutputInvalidException;
 use App\Models\AnalysisJob;
 use App\Models\Organization;
@@ -106,6 +108,13 @@ public function run(int $analysisJobId): void
                 // succeeded 到達時のみ・terminal tx の commit 後に通知 (stale 先勝ち false は通知しない)
                 $this->notifications->notifyAnalysisFinished($job->refresh());
             }
+        } catch (JobOwnershipLostException $exception) {
+            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
+            // failJob も通知もチケット release も呼ばない (すべて先着が済ませている)。
+            // report() しない — これは「正常だが観測したい事象」であり、固定 event 名で集計する。
+            Log::warning('解析ジョブの所有権を失ったため外部呼び出しを中止しました', $exception->logContext());
+
+            return;
         } catch (Throwable $exception) {
             report($exception);
             $this->jobs->failJob($job, $this->userMessageFor($exception));
@@ -161,7 +170,15 @@ private function ensureReservation(AnalysisJob $locked, Organization $organizati
         $locked->save();
     }
 
-    /** extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット) */
+    /**
+     * extract 段: 統一 JSON 化 + extracted_json 保存 (write-only 監査スナップショット)。
+     *
+     * ★ `SourceDocument::extracted_json` は**条件付き UPDATE にしない** (T131):
+     *   これは write-only の監査スナップショットであって状態機械の一部ではなく、guard には
+     *   job → document の join が要る。failed 行の document に抽出結果が残っても不整合にならない
+     *   (むしろ調査に役立つ)。「終端後の**ジョブ状態・進捗**書き込みの禁止」が対象を
+     *   ジョブ行に限っているのはこのためである。
+     */
     private function runExtractStep(
         AnalysisJob $job,
         SourceDocument $document,
@@ -169,6 +186,7 @@ private function runExtractStep(
         CarbonImmutable $deadline,
     ): ExtractedSopData {
         $extracted = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Extract,
             fn (): ExtractedSopData => ExtractedSopData::fromLlmText(
@@ -190,6 +208,7 @@ private function runDecomposeStep(
         CarbonImmutable $deadline,
     ): WorkDecompositionData {
         $decomposition = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Decompose,
             fn (): WorkDecompositionData => WorkDecompositionData::fromLlmText(
@@ -197,10 +216,12 @@ private function runDecomposeStep(
             ),
         );
 
-        $job->result_json = $decomposition->toArray();
-        $job->step = AnalysisStep::Generate;
-        $job->progress = 65;
-        $job->save();
+        // 終端後の自前書き込みを塞ぐ: 進捗と result_json は running のときだけ書く
+        $this->writeProgress($job, [
+            'result_json' => $decomposition->toArray(),
+            'step' => AnalysisStep::Generate->value,
+            'progress' => 65,
+        ]);
 
         return $decomposition;
     }
@@ -212,6 +233,7 @@ private function runGenerateStep(
         CarbonImmutable $deadline,
     ): GeneratedScenarioData {
         $generated = $this->withBoundedRetry(
+            $job,
             $deadline,
             AnalysisStep::Generate,
             fn (): GeneratedScenarioData => GeneratedScenarioData::fromLlmText(
@@ -299,18 +321,30 @@ private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): b
      * 「D + C」という単純な形に閉じている (概念設計 §時間 budget)。
      * 残り時間を timeout に渡す実装へ変えるとこのモデルが壊れる。
      *
+     * ★ preflight suppression (裁定 AG-082 標準形 (2)): **`$attempt()` の直前**で所有権を
+     *   再検証する。ここに 1 箇所置くだけで extract / decompose / generate の 3 段 ×
+     *   全リトライ試行を覆う (挿入点が 1 つ = 新しい段を足しても抜けようがない)。
+     *   deadline 判定 (時計の読み取り) は自前の書き込みではないため、
+     *   preflight と `$attempt()` の間に書き込みは 1 つも無い。
+     *
      * @template T
      *
      * @param  callable(): T  $attempt
      * @return T
      */
-    private function withBoundedRetry(CarbonImmutable $deadline, AnalysisStep $step, callable $attempt): mixed
-    {
+    private function withBoundedRetry(
+        AnalysisJob $job,
+        CarbonImmutable $deadline,
+        AnalysisStep $step,
+        callable $attempt,
+    ): mixed {
         $maxRetries = config()->integer('manual.analysis_llm_max_retries');
         for ($tryCount = 0; ; $tryCount++) {
             if (CarbonImmutable::now()->greaterThanOrEqualTo($deadline)) {
                 throw AnalysisFailedException::timedOut();
             }
+            // ★外部呼び出しの直前 (これより後に自前の書き込みを挟まない)
+            $this->assertStillOwned($job, $step);
             try {
                 return $attempt();
             } catch (Throwable $exception) {
@@ -385,14 +419,68 @@ private function extractHttpStatus(Throwable $exception): ?int
     }
 
     /**
-     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
-     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
+     * 所有権の再検証 (preflight suppression)。
+     *
+     * 所有権 = (行の主キー, `running`)。`startJob()` の `lockForUpdate + status === Queued`
+     * guard により 1 行が `running` になるのは高々 1 回で、再実行は新しい行を起票するため、
+     * `status` の再読込がそのまま所有権の再検証になる (claim token を持たない根拠は
+     * docs/architecture.md §ジョブの重複実行と結果の一回性)。
+     *
+     * 行が消えている (null) 場合も所有権喪失として扱う (deny-by-default)。
+     *
+     * @throws JobOwnershipLostException
      */
+    private function assertStillOwned(AnalysisJob $job, AnalysisStep $step): void
+    {
+        $current = AnalysisJob::query()->whereKey($job->getKey())->first();
+        if ($current !== null && $current->status === JobStatus::Running) {
+            return; // アーリーリターン (正常系)
+        }
+
+        throw JobOwnershipLostException::whileRunning(
+            jobType: AnalysisJob::class,
+            jobId: $job->id,
+            actualStatus: $current?->status,
+            stage: $step->value,
+            externalCall: ExternalCallKind::LlmCompletion,
+        );
+    }
+
+    /**
+     * ジョブ行の進捗系列の更新 (status は書かない)。
+     *
+     * ★ **条件付き UPDATE (`where status=running`)** にする理由:
+     *   preflight で「terminal 化後は外部を呼ばない」ようにした以上、
+     *   「terminal 化後に自前の DB を書く」経路も同時に塞ぐ。素の `save()` だと
+     *   stale 回復 cron が failed にした行へ step/progress/updated_at を書き戻し、
+     *   「failed なのに progress=65」という不整合を作る。
+     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
+     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
+     * ★ 状態機械は status のみが真実源であり、本メソッドは status を書かない。
+     *   **array shape で書ける列を閉じている** — `status` 等の保護列を渡せないことを
+     *   PHPStan level 10 が静的に弾く。
+     * ★ `Builder::update()` は `updated_at` 以外の列に**モデルの cast を適用しない**
+     *   (`addUpdatedAtColumn()` だけが cast を通す)。素で渡すと `result_json` (cast=array) の
+     *   エンコードが driver の grammar 任せになり、`save()` 経路と表現がずれうる。
+     *   そこでモデルへ `forceFill()` してから `getAttributes()` を取り、**cast 済みの生値**を
+     *   UPDATE に渡す (Laravel 自身が `addUpdatedAtColumn()` で使っているのと同じ手口)。
+     *
+     * @param  array{step: string, progress: int, result_json?: array<string, mixed>}  $attributes
+     */
+    private function writeProgress(AnalysisJob $job, array $attributes): void
+    {
+        $casted = (new AnalysisJob)->forceFill($attributes)->getAttributes();
+
+        AnalysisJob::query()
+            ->whereKey($job->getKey())
+            ->where('status', JobStatus::Running->value)
+            ->update($casted);
+    }
+
+    /** step/progress の表示用更新 (条件付き UPDATE 経路へ寄せる)。 */
     private function updateProgress(AnalysisJob $job, AnalysisStep $step, int $progress): void
     {
-        $job->step = $step;
-        $job->progress = $progress;
-        $job->save();
+        $this->writeProgress($job, ['step' => $step->value, 'progress' => $progress]);
     }
 
     /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index cefbaad..831a4b7 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -16,7 +16,9 @@
 use App\Enums\Manual\RenderStep;
 use App\Enums\Manual\TakeStatus;
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\Security\ExternalCallKind;
 use App\Exceptions\Billing\InsufficientTicketsException;
+use App\Exceptions\Manual\JobOwnershipLostException;
 use App\Exceptions\Manual\RenderScenarioChangedException;
 use App\Jobs\Manual\DeleteRenderOutputsJob;
 use App\Models\Cut;
@@ -30,6 +32,7 @@
 use App\Services\Render\VideoComposer;
 use Illuminate\Support\Facades\DB;
 use Illuminate\Support\Facades\File;
+use Illuminate\Support\Facades\Log;
 use LogicException;
 use Throwable;
 use Webmozart\Assert\Assert;
@@ -93,6 +96,13 @@ public function run(int $renderJobId): void
             );
             $this->updateProgress($job, RenderStep::Concat, 90);
 
+            // ★ preflight suppression (裁定 AG-082 標準形 (2)): S3 PUT の直前で所有権を再検証する。
+            //   updateProgress() という**自前の書き込みの後**に置くことが要点
+            //   (書き込みの前に検証すると、書き込み中の接続断で旧担当が PUT できる窓が開く)。
+            //   ffmpeg compose / S3 GET の前には置かない — ローカル CPU と冪等な読み取りであり、
+            //   取り消せない外部副作用を持たないため (docs/architecture.md の残余窓 3)。
+            $this->assertStillOwned($job, RenderStep::Concat);
+
             // upload → finalize (terminal tx)
             $this->storage->upload($composed->localPath, $manifest->outputKey);
             $uploadedKey = $manifest->outputKey;
@@ -110,6 +120,12 @@ public function run(int $renderJobId): void
                     $this->notifications->notifyRenderFinished($job);
                 }
             }
+        } catch (JobOwnershipLostException $exception) {
+            // preflight suppression: 既に terminal 化されている = 自分は旧担当。
+            // failJob も通知もチケット release も呼ばない。$uploadedKey は null のままなので
+            // finally の後始末は work dir の削除だけを行う (孤児オブジェクトを作らずに降りる)。
+            // return ではなく catch で受けるのは、片付け経路 (finally) を 1 本に保つため。
+            Log::warning('レンダジョブの所有権を失ったため出力アップロードを中止しました', $exception->logContext());
         } catch (Throwable $exception) {
             report($exception);
             $this->jobs->failJob($job, $this->errorCodeFor($exception), $this->userMessageFor($exception));
@@ -380,14 +396,49 @@ private function onClipComposed(RenderJob $job, int $composedClips, int $totalCl
     }
 
     /**
-     * step/progress の表示用更新 (tx 不要の単発 update。状態機械は status のみが真実源。
-     * updated_at の更新が stale 判定の「最終 step 更新時刻」を兼ねる)。
+     * 所有権の再検証 (preflight suppression)。AnalysisPipeline と同型
+     * (§10.8 方針: 共通抽象化しない。個別実装を見本に合わせる)。
+     *
+     * 所有権 = (行の主キー, `running`)。行が消えている (null) 場合も所有権喪失として扱う
+     * (deny-by-default)。
+     *
+     * @throws JobOwnershipLostException
+     */
+    private function assertStillOwned(RenderJob $job, RenderStep $step): void
+    {
+        $current = RenderJob::query()->whereKey($job->getKey())->first();
+        if ($current !== null && $current->status === JobStatus::Running) {
+            return; // アーリーリターン (正常系)
+        }
+
+        throw JobOwnershipLostException::whileRunning(
+            jobType: RenderJob::class,
+            jobId: $job->id,
+            actualStatus: $current?->status,
+            stage: $step->value,
+            externalCall: ExternalCallKind::ObjectStoragePut,
+        );
+    }
+
+    /**
+     * step/progress の表示用更新 (AnalysisPipeline::writeProgress と同型)。
+     *
+     * ★ **条件付き UPDATE (`where status=running`)**。compose は最大 25 分走り、
+     *   `onClipComposed()` から高頻度に呼ばれるため、terminal 化後の書き戻しが
+     *   最も起きやすい経路である (「failed なのに progress=62」を作らない)。
+     * ★ `Builder::update()` は `updated_at` を自動付与する (stale 判定の
+     *   「最終 step 更新時刻」という意味は従来どおり。ただし terminal 行では動かない)。
+     * ★ AnalysisPipeline::writeProgress と違い cast の正規化を挟まないのは、ここで書く 2 列が
+     *   **cast 適用後と同一表現のスカラー** (`RenderStep` の backing value と int) だけだからである。
+     *   配列 / 日時など cast で表現が変わる列をここへ足すときは、あちらと同じく
+     *   `forceFill()->getAttributes()` を通すこと。
      */
     private function updateProgress(RenderJob $job, RenderStep $step, int $progress): void
     {
-        $job->step = $step;
-        $job->progress = $progress;
-        $job->save();
+        RenderJob::query()
+            ->whereKey($job->getKey())
+            ->where('status', JobStatus::Running->value)
+            ->update(['step' => $step->value, 'progress' => $progress]);
     }
 
     /** job → manual → project の導出 (payload 不信任。DB から relation 経由で再解決) */
diff --git a/tests/Architecture/JobExclusionOrderingInvariantTest.php b/tests/Architecture/JobExclusionOrderingInvariantTest.php
new file mode 100644
index 0000000..d7541ad
--- /dev/null
+++ b/tests/Architecture/JobExclusionOrderingInvariantTest.php
@@ -0,0 +1,91 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Services\Billing\AutoRechargeService;
+
+/*
+ * 入口の排他 (Cache::lock TTL / ShouldBeUnique の uniqueFor) の**序列**を CI 固定する。
+ *
+ * 裁定 AG-082: 入口の排他は best-effort であり、結果の一回性を保証しない。
+ * したがって「保証を代替できるほど長く」してはならない — 鍵が残留すると、
+ * 正当な再実行 (§10.8-1「再実行は analyze/render 再トリガーのみ」) を最大 TTL 秒ブロックする。
+ *
+ * ★比較先を「マジックナンバー」ではなく **その接続の retry_after** にしているのが要点。
+ *   鍵の残留がキューの再配送間隔を超えないことを保証すれば、封鎖時間が構造的に有界化される。
+ *
+ * 運用契約: docs/architecture.md §ジョブの重複実行と結果の一回性
+ */
+
+/** 入口の排他が乗るレーン (= 本番の既定キュー接続) の名前。 */
+const JOB_EXCLUSION_DEFAULT_CONNECTION = 'database';
+
+/**
+ * 比較先の retry_after。
+ *
+ * ★ **実行時の `config('queue.default')` は使えない** — テストレーンは phpunit.xml が
+ *   `QUEUE_CONNECTION=sync` を force しており、`sync` 接続は `retry_after` を持たない。
+ *   「本番の既定接続」は `config/queue.php` の `env('QUEUE_CONNECTION', 'database')` の
+ *   **フォールバック値**であり、それが `database` から動いていないことは
+ *   下の「比較先の前提」テストがソースレベルで固定する
+ *   (既存 QueueWorkerLeaseInvariantTest が「env 上書きを残すと gate が嘘をつく」として
+ *    retry_after をリテラルで持たせているのと同じ発想)。
+ */
+function jobExclusionDefaultRetryAfter(): int
+{
+    return config()->integer('queue.connections.'.JOB_EXCLUSION_DEFAULT_CONNECTION.'.retry_after');
+}
+
+test('入口の排他: auto-recharge の org lock TTL は既定接続の retry_after を下回る', function (): void {
+    $retryAfter = jobExclusionDefaultRetryAfter();
+
+    expect(AutoRechargeService::LOCK_TTL_SECONDS)->toBeLessThan(
+        $retryAfter,
+        'org lock TTL がキューの再配送間隔以上です。ゴーストロックが同一ジョブの再配送より'
+        .'長く残ると、正当な再実行を封鎖します。TTL は保証を担わないため短い側に倒すこと。',
+    );
+});
+
+test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
+    $retryAfter = jobExclusionDefaultRetryAfter();
+
+    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
+        $retryAfter,
+        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
+        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
+    );
+});
+
+test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
+    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
+    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
+});
+
+test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
+    // ★ 接続 pin (T127: 既定キュー接続の分割) が入ると retry_after との比較が意味を失う。
+    //   前提が崩れた瞬間に赤くする。
+    //   ★ 他テストファイルのグローバル定数 (QUEUED_JOB_LEASE_INVENTORY) は参照しない —
+    //     Pest の --parallel はファイル単位でプロセスを分けるため未定義になりうる。
+    //     ジョブ実体から直接読めば単体で成立する。
+    expect((new AutoRechargeTriggerJob(1))->connection)->toBeNull();
+    expect((new ExecuteAutoRechargeAttemptJob(1))->connection)->toBeNull();
+});
+
+test('入口の排他: 比較先の前提 — 本番の既定キュー接続は database である', function (): void {
+    // ★ 既定接続が差し替わると「どのレーンの再配送間隔と比べているのか」が変わり、
+    //   gate は green のまま**別レーンと比較する偽グリーン**になる。
+    //   実行時の config('queue.default') はテストレーンで sync に force されるため使えない。
+    //   本番既定は config/queue.php の env() フォールバック値なので、そこをソースで固定する。
+    $source = file_get_contents(config_path('queue.php'));
+    expect($source)->toBeString();
+    expect(str_contains((string) $source, "'default' => env('QUEUE_CONNECTION', '".JOB_EXCLUSION_DEFAULT_CONNECTION."')"))
+        ->toBeTrue(
+            '本番の既定キュー接続が変わりました。入口の排他 TTL / uniqueFor の比較先 (retry_after) が'
+            .'意図したレーンかを再検討し、本テストと docs/architecture.md の序列を更新すること。',
+        );
+
+    // 比較先そのものが実在すること (degenerate PASS 防止)
+    expect(jobExclusionDefaultRetryAfter())->toBeGreaterThan(0);
+});

```

## (2) S7 の差分 (Round 1 の diff スコープ `app/ resources/ tests/ routes/ config/ bootstrap/` から外れていたため未提示だったもの)

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 74d8dc9..b0e5d99 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -318,3 +318,16 @@ ## ドメイン固有規約
      無効 body の連打で正当通知を 429 にできる = 攻撃者が業務を止められる口になる)。
      IP 単位は署名検証コストの上限であり正当通知の保護ではない (429 発生率を監視する)
    - 詳細は `docs/app-integration-guide.md` §7b
+6. **ジョブの重複実行と結果の一回性**: 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は
+   **best-effort であり保証を担わない**。結果の一回性は永続状態遷移 (条件付き UPDATE /
+   悲観ロック + status guard / 予約 CAS) と外部側の冪等キーが担う。
+   **取り消せない外部副作用 (LLM 呼び出し / S3 PUT / Stripe 課金) の直前には
+   所有権の再検証 (preflight) を置く**。検証と外部呼び出しの間に自前の書き込みを挟まない
+   (挟んだら書き込みの後にもう一度置く)。terminal 化された後に旧ワーカーが**ジョブ行**の
+   状態・進捗を書き戻さないよう、進捗更新は `where status=…` の条件付き UPDATE にする。
+   キューに載る全クラス (`ShouldQueue` 実装) は `JobExecutionDedupInventoryTest` の目録へ
+   「保証側 (`JobDedupGuarantee` + preflight)」か「免除 (`JobDedupExemption` +
+   30 文字以上の根拠)」で登録が必須 (deny-by-default)。排他 TTL / `uniqueFor` は
+   保証を代替できる長さに伸ばさない (`JobExclusionOrderingInvariantTest` が
+   `retry_after` 未満を固定)。**閉じない窓と運用上の所有者**は `docs/architecture.md`
+   §ジョブの重複実行と結果の一回性 が正本。
diff --git a/docs/architecture.md b/docs/architecture.md
index a2514cc..b293b1c 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -280,6 +280,86 @@ ### キューのリース期間とワーカー制限時間の規約
   (静的 gate は config をテスト環境の値で読むため、env 上書きを残すと
   「gate は通るが本番の実値は別」を作れてしまう)。
 
+### ジョブの重複実行と結果の一回性
+
+キューは at-least-once であり、上のリース規約を守っても**二重実行そのものは無くならない**
+(worker 停止・再開、リース切れ、cron による stale 回復)。したがって守るのは「実行が 1 回」ではなく
+**「結果が 1 回」**である (裁定 AG-082 の追従。設計は
+`devnotes/20260807-1235-job-execution-dedup/`)。
+
+1. **2 層の役割** — 入口の排他 (`ShouldBeUnique` / `Cache::lock`) は **best-effort** であり、
+   保証を担わない (鍵は失敗・timeout で解放されないことがあり、TTL でも切れる)。
+   結果の一回性は**永続状態遷移** (条件付き UPDATE / 悲観ロック + status guard / 予約 CAS) と
+   **外部側の冪等性** (Stripe idempotency key / invoice の状態検査) が担う。
+   **preflight** (外部呼び出し直前の所有権再検証) は「既に失われた所有権を検出して送信を止める」
+   **抑止策**であって保証ではない。
+2. **所有権の定義** — **(行の主キー, 進行中 status)**。`AnalysisJob` / `RenderJob` /
+   `TicketAutoRechargeAttempt` はいずれも単調な状態機械で、再実行は**新しい行を起票する**ため、
+   `status` の再読込がそのまま所有権の再検証になる (claim token 列を持たない根拠)。
+   行が消えている場合も所有権喪失として扱う (deny-by-default)。
+3. **preflight の配置規則** — **外部呼び出しの直前**に置く。再検証と外部呼び出しの間に
+   **自前の書き込みを挟まない**。挟んだ場合は書き込みの**後**に再度置く
+   (auto-recharge は `invoice_id` の永続化を挟むため create 前と pay 前の 2 箇所)。
+4. **終端後のジョブ状態・進捗書き込みの禁止** — preflight を置いた経路では、terminal 化された後に
+   旧ワーカーが自前の書き込みを行う経路も同時に塞ぐ。**ジョブ行**への進捗書き込み
+   (`step` / `progress` / `result_json` / `stripe_invoice_id`) は `where status=…` の
+   **条件付き UPDATE** にする (「failed なのに progress=65」を作らない。副次的に `updated_at` の
+   更新も止まるため stale 判定の基準が terminal 行で動かない)。
+   **対象はジョブ行に限る** — `SourceDocument::extracted_json` のような write-only の
+   監査スナップショットは状態機械の一部ではないため対象外である。
+5. **auto-recharge の保証層** (課金は最も高価なので 4 層で持つ):
+
+   | 層 | 機構 | 何を保証するか |
+   |---|---|---|
+   | 入口 | org `Cache::lock` (TTL 180s) / `AutoRechargeTriggerJob::$uniqueFor` (30s) | best-effort の直列化のみ |
+   | 起票 | `tar_attempts_org_pending_unique` (partial unique) | org に pending は 1 つまで |
+   | 遷移 | `where status='pending'` の条件付き UPDATE | 1 attempt = 1 遷移 |
+   | 効果 | 台帳 `recharge:{invoiceId}` の UNIQUE + Stripe idempotency key | 付与と課金の一回性 |
+
+   **冪等キーは 2 本ある**: 付与の一回性は台帳の `recharge:{invoiceId}` (**invoice 単位**)、
+   attempt 遷移の一回性は条件付き UPDATE (**attempt 単位**)。`recordSuccessfulCharge()` が
+   「grant → attempt 遷移」の順なのはこのためで、**逆順にしない**
+   (逆順は「Stripe で課金済みなのにチケット未付与」というより悪い不整合を生む)。
+6. **閉じない窓 (受容済み)** —
+   (a) **送信権の競合**: preflight 通過から送信までの間に terminal 化されうる。
+   (b) **送信結果の不明**: 送信直後にプロセスが死ぬと結果が分からない (S3 PUT / Stripe pay 同型)。
+   (c) **LLM に冪等キーが無い**: provider 側で重複排除できない (だから preflight を置く)。
+   (d) **`queue:listen` ではジョブ側 `$timeout` が効かない** (dev / bug-hunt)。
+7. **序列** — `LOCK_TTL_SECONDS` / `uniqueFor` < 既定接続の `retry_after`
+   (鍵の残留が正当な再実行を封鎖する時間を、キューの再配送間隔の内側に収める)。
+   ジョブ側 `$timeout` < `retry_after` < 予約 TTL ≤ stale 閾値 (上節)。
+   成立前提は「pcntl 有効 / 遅延なし / 時計ずれが小さい / シグナル順序 / supervisor 設定」。
+8. **運用契約 (所有者 = 課金運用担当)** —
+   - `event = job_ownership_lost` の**連続発生**は「ワーカーの停止・再開が多い」または
+     「序列の前提が崩れた」の兆候。頻度を監視する。
+   - **恒久回収を持たない open invoice が 2 種ある**。どちらも `reconcile()` は
+     DB の pending attempt を走査するため**母集団外**であり、手動収束が必要:
+     (a) 所有権喪失後の void 失敗で残ったもの (`event = job_ownership_lost_cleanup` かつ
+     `terminated=false` で検知)、
+     (b) invoice 作成成功 → `stripe_invoice_id` 保存前のワーカー死亡で残ったもの。
+     どちらも Stripe metadata の `recharge_attempt_ulid` から attempt を逆引きできる。
+
+**規約 ↔ テスト対応表** (AGENTS.md 禁止事項 1 = 不変条件はテスト登録まで含めて「実装済み」):
+
+| 規約の文 | 保証するテスト |
+|---|---|
+| キューに載る全クラスが保証側 or 免除に分類される | `JobExecutionDedupInventoryTest` |
+| 登録された**すべての** preflight checkpoint が実在し、制御方式 (`PreflightControlFlow`) に一致する戻り型を持つ (**存在まで**) | `JobExecutionDedupInventoryTest` |
+| 期待する外部呼び出し種別 (`jobDedupRequiredExternalCalls()` が正本) と checkpoint 登録の集合一致 / `NoExternalCall` と混在しない | `JobExecutionDedupInventoryTest` |
+| preflight が**外部呼び出しの直前に置かれている** (配置) | `AnalysisPipelineTest` / `RenderPipelineTest` / `AutoRechargeServiceTest`。★**分担**: Architecture gate = 集合一致 + 実在 + 戻り型 / Feature テスト = 配置。Manual は既存 fake のフック (`onAttempt` / `duringCompose`)、**Billing は注入可能な `FakeAttemptOwnershipPreflight`** (競合注入シーム) で配置を赤化する |
+| 終端後にジョブ行の進捗を書き戻さない (条件付き UPDATE) | `AnalysisPipelineTest` / `RenderPipelineTest` |
+| 終端後に `stripe_invoice_id` を書き込まない (条件付き UPDATE) | `AutoRechargeServiceTest` |
+| 同一 invoice への付与は台帳に 1 件しか入らない | `AutoRechargeServiceTest` |
+| 免除は型付き enum + 30 文字以上の根拠 / 件数は宣言と一致 | `JobExecutionDedupInventoryTest` + value object の `Assert` |
+| 入口の排他 TTL / `uniqueFor` < `retry_after` | `JobExclusionOrderingInvariantTest` |
+| `$timeout < retry_after < 予約 TTL ≤ stale 閾値` | `AnalysisTimeBudgetInvariantTest` / `RenderTimeBudgetInvariantTest` |
+| worker `--timeout` < `retry_after` | `QueueWorkerLeaseInvariantTest` |
+| 所有権喪失時に LLM を呼ばない | `AnalysisPipelineTest` |
+| 所有権喪失時に S3 PUT しない | `RenderPipelineTest` |
+| 所有権喪失時に invoice 作成・支払いを抑止し、必要な既作成 invoice を終端する | `AutoRechargeServiceTest` |
+| ログコンテキストに PII を含めない | `JobOwnershipLostContextTest` |
+| 固定 event 名の literal が 1 箇所に閉じる | `JobExecutionDedupInventoryTest` |
+
 ### AI 解析ジョブの運用契約
 
 - 解析ジョブ (`RunManualAnalysis`) は専用 queue connection **`database-analysis`**

```

---

## 再検証の結果

- `composer phpstan` (level 10): **No errors**
- `vendor/bin/pint --test`: passed
- `composer test -- tests/Feature/Projects/AnalysisPipelineTest.php`: 40 passed
- `composer test -- tests/Architecture/JobExclusionOrderingInvariantTest.php`: 5 passed
- mutation の再確認 (実装を触ったため再実施): **M5 / M6 / M11 とも赤化することを再確認**
  - M11 (`writeProgress` の `where('status', running)` を外す) →
    「preflight: cron failed 後に step / progress が旧ワーカーから書き戻されない」が赤
  - M5 (`LOCK_TTL_SECONDS=700`) → 「org lock TTL は既定接続の retry_after を下回る」が赤
  - M6 (`uniqueFor=0`) → 「uniqueFor は正の値である」が赤
- 全件 (`composer test`) はこの返答の後、最終確認としてもう一度回します。

---

## 確認してほしいこと

1. [Critical] への対応 (`forceFill()->getAttributes()` で cast 済みの生値を条件付き UPDATE へ渡す)
   が意図どおりか。`RenderPipeline` 側を正規化しない判断 (書く 2 列が cast 適用後と同一表現の
   スカラーのみ) に見落としはないか。
2. [Warning] `queue.default` の件: 実行時 `config('queue.default')` がテストレーンで `sync` に
   force されるため使えず、**ソースの env フォールバック値**を固定する形にしました。
   この形で「既定接続が変わったら赤くなる」を満たせているか。
3. [Warning] `error` へ `$exception->getMessage()` を入れる件の**反論に同意できるか**
   (PII 非混入 / 運用契約で load-bearing / 同一クラスの既存実装と揃っている /
   設計が確定した 7 キー schema)。同意できない場合、運用契約 (open invoice の手動収束) を
   壊さずに満たせる具体案があるかを示してほしい。
4. S7 (文書) の内容 — 特に「規約 ↔ テスト対応表」が実在するテストを正しく指しているか、
   「閉じない窓 (4 つ)」と「恒久回収を持たない open invoice 2 種」の記述が実装と整合しているか。

全体判定 (APPROVED / CHANGES_REQUESTED) を最後に 1 行で書いてください。
