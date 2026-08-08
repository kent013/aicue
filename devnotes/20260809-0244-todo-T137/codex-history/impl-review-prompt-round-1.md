## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

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

あなたは Laravel 12 + Svelte 5 + Inertia のコードレビュアーである。以下の実装差分を、添付の詳細設計書と突き合わせてレビューせよ。

【レビュー観点】
1. 設計との一致性 (設計から外れた実装、実装されていない施策、設計より弱い保証)
2. 正確性 (競合・原子性・例外経路・境界条件のバグ)
3. PHPStan level 10 適合性 (mixed の漏れ、narrowing 不足)
4. DTO / JsonResource パターン (response()->json() 直書き禁止、DTO で返す)
5. テスト網羅性 (0 件 pin の gate が本当に赤くなるか、偽グリーンになる箇所は無いか)
6. セキュリティ (tenant キー不信 / cross-org / 課金の冪等性)
7. DESIGN.md 準拠 / Atomic Design 準拠 (本 diff は resources/js を含まないため該当なしと判断してよい)

【本タスク固有の重点】
- **キュー投入を業務トランザクションの内側へ移設した**変更である。dispatch が tx 内に入ったことで
  新たに生じうる問題 (ロック保持時間の増加、tx 内での外部副作用、ネスト tx との相互作用、
  例外時のロールバック範囲) を厳しく見よ。
- **契約の反転** (既存テストの主張を逆向きに書き換えた箇所) が「保護対象を消しただけ」に
  なっていないか。反転 docblock (旧主張/旧目的/新主張/新前提/前提を守る機構/反転根拠) の
  主張が実装と一致しているか。
- **0 件 pin の gate** (QueueDispatchAtomicityInventoryTest) が deny-by-default として
  実効を持つか。すり抜けられる経路があれば指摘せよ。
- **保証範囲の誇張** (docblock / ドキュメントが実装より強いことを主張していないか)。

【出力形式】
- ファイルごとに判定を書く
- 指摘は [Critical] / [Warning] / [Suggestion] に分類する
- 最後に全体判定を `APPROVED` または `CHANGES_REQUESTED` の 1 語で明示する

---

## 詳細設計書

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
  `母集団 (ShouldQueue 実装 ∪ Mailable) のうち ShouldQueueAfterCommit /
  ShouldHandleEventsAfterCommit を implement するものは 0 件`
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
- 同 class に **`mailableClasses()` を 1 メソッド追加**する (`app/` 配下の
  `Illuminate\Mail\Mailable` subclass を `ShouldQueue` 実装の有無を問わず列挙。
  既存の `appPhpFiles()` / `classNameForPath()` を再利用する)。
  - ★ **`isInstantiable()` は要求しない** (Codex Round 8)。first-party の abstract な
    base Mailable は `$afterCommit` の既定値や宣言的迂回 interface を concrete subclass へ
    伝播させる carrier であり、除外すると 0 件 pin が抜ける。vendor の
    `Illuminate\Mail\Mailable` 本体は `app/` 探索に入らないので母集団には現れない
    (`shouldQueueClasses()` 側の `isInstantiable()` は既存挙動なので**変更しない**)
  **`shouldQueueClasses()` は変更しない** — 既存 2 gate
  (`QueuedJobLeaseInventoryTest` / `JobExecutionDedupInventoryTest`) の母集団を
  本 PR で動かさないため (対称差テストが巻き添えで落ちる)

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
 * - D3 宣言的迂回 interface の実装            … **リフレクション判定** (文字列走査ではない)。
 *   `ShouldQueueAfterCommit` に加え **`ShouldHandleEventsAfterCommit`** も見る
 *   (`Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` が
 *   この interface でも commit 後ずらしを発動するため。ShouldQueue な listener では
 *   これが**キュー投入そのもの**を commit 後へずらす)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
 *   **実行時代入は token 走査**。`public bool $afterCommit = true;` /
 *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
 *   これを落とすと「0 件 pin」の主張が嘘になる
 *
 * 【D3 / D5(既定値) の母集団は `ShouldQueue` 実装だけでは足りない — Mailable を足す】
 * `Mailable` は **`ShouldQueue` を実装していなくても** `Mail::to(...)->queue()` /
 * `Mail::queue()` でキューへ載る。このとき vendor の `SendQueuedMailable::__construct()` が
 * `$mailable instanceof ShouldQueueAfterCommit ? true : ($mailable->afterCommit ?? null)` を
 * **wrapper job へコピーする**ため、非 `ShouldQueue` な Mailable の
 * `public $afterCommit = true;` / `implements ShouldQueueAfterCommit` が
 * そのまま commit 後ずらしになる (Codex Round 7 の Warning)。
 * **本リポジトリでは現に `CreateInquiryAction` が `Mail::to(...)->queue(...)` を使っている**
 * (仮想の穴ではない。現行 2 クラスは `ShouldQueue` を併記しているので今は母集団に入るが、
 * 併記を外した瞬間に検出器から消える)。
 * よって D3 / D5(既定値) の母集団は
 * **`QueuedJobPopulation::shouldQueueClasses()` ∪ `QueuedJobPopulation::mailableClasses()`** とする。
 * Notification と listener は vendor 側 (`NotificationSender` / `Events\Dispatcher`) が
 * `ShouldQueue` を要求するため `shouldQueueClasses()` で尽きており、追加は要らない。
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

    /**
     * D3: 宣言的迂回 interface を implement するクラス。
     * `ShouldQueueAfterCommit` と `ShouldHandleEventsAfterCommit` の**両方**を見る
     * (`ReflectionClass::implementsInterface()` なので中間 interface / 親クラス経由も拾う)。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitInterfaces(array $classes): array { /* D3 */ }

    /**
     * D3 / D5(既定値) の母集団 = `ShouldQueue` 実装 ∪ Mailable subclass。
     * **和集合にする理由**は上の docblock (Mailable は `ShouldQueue` なしでも
     * `Mail::queue()` でキューに載り、`SendQueuedMailable` が `$afterCommit` を
     * wrapper job へコピーする) を参照。重複は除去し昇順で返す。
     *
     * @return list<class-string>
     */
    public static function deferralCandidateClasses(): array
    {
        $classes = array_values(array_unique(array_merge(
            QueuedJobPopulation::shouldQueueClasses(),
            QueuedJobPopulation::mailableClasses(),
        )));
        sort($classes);

        return $classes;
    }

    /** @param array<mixed> $connections @return list<string> 違反した接続名 */
    public static function detectAfterCommitEnabledConnections(array $connections): array { /* D4 */ }

    /**
     * D5 (既定値): `$afterCommit` プロパティの default が `true` のクラス。
     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
     * コンストラクタ引数が必要な job でも判定できる)。
     *
     * ★ 判定は **`=== true` の厳密比較**である。`Queueable` trait の既定値は `null` で
     *   あり、`null` を truthy 側へ落とすと全 job が偽陽性になる。
     *
     * @param  list<class-string>  $classes
     * @return list<class-string>
     */
    public static function detectAfterCommitProperty(array $classes): array { /* D5 (既定値) */ }

    /**
     * D5 (実行時代入): `->afterCommit = true` の **token 走査**。
     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = true;`
     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う。
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
| 3 | `D3: 母集団に ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit を implement するクラスは 1 件も無い` | 0 件 pin |
| 4 | `D4: after_commit=true を持ってよい接続は sync だけである` | 0 件 pin (全接続集合) |
| 4b | `D5: 母集団に $afterCommit の既定値が true のクラスは 1 件も無い` | 0 件 pin |
| 4c | `D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い` | 0 件 pin |
| 5 | `母集団: runtimePhpFiles() は Finder による独立列挙と対称差が空である` | 母集団境界の exact-fit |
| 5b | `母集団: RUNTIME_ROOTS はテスト側で独立に固定した期待ルート集合と一致する` | **ルート集合の独立 pin** |
| 6 | `母集団: 期待ルート集合の各ルートについて 1 件以上のファイルが列挙される` | 母集団 0 件 fail (ルート単位) |
| 7 | `母集団: ShouldQueue 実装クラスの列挙は 0 件でない` | 母集団 0 件 fail |
| 7b | `母集団: Mailable subclass の列挙は 0 件でない` | 母集団 0 件 fail |
| 7c | `母集団: deferralCandidateClasses() は unique(shouldQueueClasses ∪ mailableClasses) と一致し、Mailable 全件を含む` | **和集合の固定** |
| 8 | `母集団: queue.connections は 0 件でない` | 母集団 0 件 fail |
| 9 | `負のコントロール: fixture ツリーを列挙して D1 を検出する` | 経路統合 |
| 10 | `負のコントロール: fixture ツリーを列挙して D2 を検出する` | 経路統合 |
| 11 | `負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 11b | `負のコントロール: ShouldHandleEventsAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 12 | `負のコントロール: after_commit=true の非 sync 接続を D4 が検出する` | 経路統合 |
| 12b | `負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する` | 経路統合 |
| 12b2 | `負のコントロール: ShouldQueue を実装しないダミー Mailable の $afterCommit = true を D5 (既定値) が検出する` | **Mailable 経路の固定** |
| 12c | `負のコントロール: $this->afterCommit = true; を含む fixture を D5 (代入) が検出する` | 経路統合 |
| 12e | `負のコントロール: $job->afterCommit = true; (外部からの代入) も D5 (代入) が検出する` | 経路統合 |
| 12f | `偽陰性の負のコントロール: $afterCommit の既定値が null / false のクラスは D5 (既定値) が検出しない` | 誤検出の固定 |
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
- **D3 / D5(既定値) の母集団は `deferralCandidateClasses()`**
  (= `shouldQueueClasses()` ∪ `mailableClasses()`)。`routes/` を含めないのは
  宣言的迂回もプロパティ既定値も**クラス定義**にしか書けず、`routes/` に
  クラス定義を置かないため。
  - **Mailable を足す根拠 (Codex Round 7 の Warning)**: `Mailable` は
    `ShouldQueue` なしでも `Mail::to(...)->queue()` でキューに載り、
    vendor の `SendQueuedMailable::__construct()` が
    `instanceof ShouldQueueAfterCommit` と `$mailable->afterCommit` を
    **wrapper job へコピーする**。本リポジトリは `CreateInquiryAction` が
    現に `Mail::to(...)->queue(...)` を使っており、現行 2 クラス
    (`InquiryReceivedMail` / `InquiryAcknowledgementMail`) は
    `implements ShouldQueue` を併記しているだけである。併記を外せば
    `shouldQueueClasses()` から消え、`$afterCommit = true` が gate をすり抜ける
  - **Notification / listener に同じ拡張は要らない**: vendor 側が
    `NotificationSender` (`$notification instanceof ShouldQueue`) と
    `Events\Dispatcher::handlerShouldBeQueued()` で `ShouldQueue` を要求するため、
    キューに載る母集団は `shouldQueueClasses()` で尽きる (思考原則 2 — 現に到達不能な
    経路のために母集団を広げない)
  - **`ShouldHandleEventsAfterCommit` を D3 に足す根拠**:
    `Events\Dispatcher::handlerShouldBeDispatchedAfterDatabaseTransactions()` は
    `ShouldQueueAfterCommit` ではなく**この interface**を見る。ShouldQueue な listener に
    付けるとキュー投入そのものが commit 後へずれるため、D3 の interface 集合に加える
    (新しい検出器ではなく既存リフレクション判定の対象 interface が 1 つ増えるだけ)。
    現行 `app/` の使用は 0 件
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
   `ShouldQueueAfterCommit` / `ShouldHandleEventsAfterCommit` /
   `$afterCommit = true` プロパティ (**`ShouldQueue` 実装だけでなく Mailable も** —
   Mailable は `ShouldQueue` なしでも `Mail::queue()` でキューに載る) /
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
   - **保証しないもの**: 検出は token 走査 (D1/D2/D5 の代入形) とリフレクション
     (D3/D5 の既定値) の併用で、動的な迂回 (`$m = 'afterCommit'` /
     `$this->afterCommit = $flag;`) や helper 経由の呼び出しには沈黙する。
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

14c. **D3 / D5(既定値) の母集団は `app/` 配下の `ShouldQueue` 実装 ∪ Mailable subclass** である。
    ここに入らない形でキューに載る経路 — vendor / package が定義するクラス、
    `app/` の外に置かれた first-party クラス、`class_exists()` で解決できない
    動的生成クラス — には沈黙する。
    また `ShouldHandleEventsAfterCommit` を**非 ShouldQueue の listener**に付けた場合は
    「同期ハンドラの実行が commit 後へずれる」だけでキュー投入ではないため、
    本 gate の対象外である (母集団にも入らない)
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
| 22 | `app/Mail/InquiryReceivedMail.php` から `implements ShouldQueue` を外し `public bool $afterCommit = true;` を足す | 同 (D5 既定値)。**母集団を `shouldQueueClasses()` だけに戻すと落ちない**ことも同時に確認する (Mailable 和集合の要) |
| 23 | 任意の ShouldQueue listener に `implements ShouldHandleEventsAfterCommit` を足す | 同 (D3)。`ShouldQueueAfterCommit` だけを見る実装では**落ちない**ことも同時に確認する |
| 24 | `deferralCandidateClasses()` を `shouldQueueClasses()` だけ返すよう潰す | M7 テスト 7c (和集合の固定) と 12b2 (Mailable 経路の負のコントロール) |

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


---

## 実装差分 (git diff。app/ tests/ config/ のみ。docs/ AGENTS.md .env.example は文書変更のため省略)

```diff
diff --git a/app/DataTransferObjects/Support/QueueDispatchAtomicityViolation.php b/app/DataTransferObjects/Support/QueueDispatchAtomicityViolation.php
new file mode 100644
index 0000000..a784a85
--- /dev/null
+++ b/app/DataTransferObjects/Support/QueueDispatchAtomicityViolation.php
@@ -0,0 +1,30 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Support;
+
+use App\Enums\Support\QueueAtomicityRule;
+
+/**
+ * QueueDispatchAtomicityGuard が報告する 1 件の違反 (内部 DTO)。
+ *
+ * ★ **mixed を公開しない**。実測値は表示用に正規化した string (`var_export()` 相当) で持つ。
+ *   config から読んだ値をそのまま公開すると、level 10 の呼び出し側で narrowing が必要になり
+ *   「違反を報告するための DTO」が新たな型不明値の出口になる。
+ */
+final readonly class QueueDispatchAtomicityViolation
+{
+    /**
+     * @param  QueueAtomicityRule  $rule  違反した規則
+     * @param  string  $connection  検査対象のキュー接続名 (接続に紐づかない違反は '-')
+     * @param  string  $actual  実測値を表示用に正規化した文字列
+     * @param  string  $message  起動時例外に載せる説明 (原因と対処を含む)
+     */
+    public function __construct(
+        public QueueAtomicityRule $rule,
+        public string $connection,
+        public string $actual,
+        public string $message,
+    ) {}
+}
diff --git a/app/Enums/Support/QueueAtomicityRule.php b/app/Enums/Support/QueueAtomicityRule.php
new file mode 100644
index 0000000..eedc076
--- /dev/null
+++ b/app/Enums/Support/QueueAtomicityRule.php
@@ -0,0 +1,32 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Support;
+
+/**
+ * QueueDispatchAtomicityGuard が検査する規則の識別子 (AG-114 確定 2)。
+ *
+ * 違反 DTO (QueueDispatchAtomicityViolation) が「どの規則に落ちたか」を型で持つため、
+ * テスト側は message 文字列ではなく規則で assert できる。
+ */
+enum QueueAtomicityRule: string
+{
+    /** 参照接続 (sync 以外) の driver は database である */
+    case DatabaseDriver = 'database_driver';
+
+    /** driver=database の参照接続は業務 DB と同一の DB 接続を使う */
+    case SameDatabaseConnection = 'same_database_connection';
+
+    /** driver=database の参照接続は after_commit=false である */
+    case AfterCommitDisabled = 'after_commit_disabled';
+
+    /** sync 接続は after_commit=true である (テスト・dev の実行順序の保存) */
+    case SyncAfterCommitEnabled = 'sync_after_commit_enabled';
+
+    /** production の既定接続は database である (sync の本番投入を拒否する) */
+    case ProductionAsyncDriver = 'production_async_driver';
+
+    /** 検査の前提となる config 値 (queue.default / database.default / queue.connections) が読めない */
+    case ConfigUnreadable = 'config_unreadable';
+}
diff --git a/app/Jobs/Billing/AutoRechargeTriggerJob.php b/app/Jobs/Billing/AutoRechargeTriggerJob.php
index b365e04..1aa62b9 100644
--- a/app/Jobs/Billing/AutoRechargeTriggerJob.php
+++ b/app/Jobs/Billing/AutoRechargeTriggerJob.php
@@ -8,7 +8,6 @@
 use App\Models\Organization;
 use App\Services\Billing\AutoRechargeService;
 use Illuminate\Bus\Queueable;
-use Illuminate\Contracts\Queue\ShouldBeUnique;
 use Illuminate\Contracts\Queue\ShouldQueue;
 use Illuminate\Foundation\Bus\Dispatchable;
 use Illuminate\Queue\InteractsWithQueue;
@@ -22,8 +21,21 @@
  * 重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する。
  *
  * $tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — 二重課金面の安全側)。
+ *
+ * 【入口排他 (ShouldBeUnique) を持たない理由 — 契約の反転 (AG-114 確定 1 / T137)】
+ * - 旧主張: `ShouldBeUnique` + `uniqueFor = 30` で同一 org の重複 dispatch を抑止する
+ * - 旧目的: reserve のたびに trigger job が積まれるのを減らす
+ * - 新主張: 入口排他を持たない。重複 dispatch は下流が no-op へ収束させる
+ * - 新前提: 本 job は業務 tx の内側から dispatch される (AG-114 確定 1)
+ * - 前提を守る機構: maybeCreateAttempt の organizations 行ロック + pending 存在検査 +
+ *   `tar_attempts_org_pending_unique` (partial unique) + unique violation の no-op 化
+ * - 反転根拠: `UniqueLock` は PendingDispatch の dispatch 呼び出し時に取得され、
+ *   rollback 時の解放は afterCommit 経路でしか行われない。業務 tx の内側で dispatch すると
+ *   rollback しても `uniqueFor` 秒の抑止が残り、**ネスト深さに依らず解消できない**。
+ *   AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため、撤去して
+ *   永続状態遷移へ責務を一本化する
  */
-final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
+final class AutoRechargeTriggerJob implements ShouldQueue
 {
     use Dispatchable;
     use InteractsWithQueue;
@@ -32,15 +44,8 @@ final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
 
     public int $tries = 1;
 
-    public int $uniqueFor = 30;
-
     public function __construct(public readonly int $organizationId) {}
 
-    public function uniqueId(): string
-    {
-        return (string) $this->organizationId;
-    }
-
     public function handle(AutoRechargeService $autoRecharge): void
     {
         // enabled 設定がない org は即 return (opt-in / 既定 off のガード)。
@@ -57,9 +62,8 @@ public function handle(AutoRechargeService $autoRecharge): void
             return;
         }
 
-        $attempt = $autoRecharge->maybeCreateAttempt($organization);
-        if ($attempt !== null) {
-            ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
-        }
+        // 起票と ExecuteAutoRechargeAttemptJob の投入は maybeCreateAttempt の tx 内で完結する
+        // (AG-114 確定 1。ここで dispatch すると二重投入になる)。
+        $autoRecharge->maybeCreateAttempt($organization);
     }
 }
diff --git a/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php b/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php
index c98b9e4..4a300cf 100644
--- a/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php
+++ b/app/Notifications/Billing/AutoRechargeActionRequiredNotification.php
@@ -9,7 +9,6 @@
 use App\Support\Billing\BillingNotificationRecorder;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -19,8 +18,18 @@
  * P8a: オートリチャージ課金の SCA (3D セキュア) 認証要求通知。
  * dedup_key = auto_recharge_sca:{invoice_id}:{JST date} (日次で再通知を許す — 放置での失効を防ぐ)。
  * action URL は invoice の hosted_invoice_url (Stripe ホストページで認証完了できる)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class AutoRechargeActionRequiredNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+class AutoRechargeActionRequiredNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
 {
     use Queueable;
 
diff --git a/app/Notifications/Billing/AutoRechargeDisabledNotification.php b/app/Notifications/Billing/AutoRechargeDisabledNotification.php
index 0622311..eeb733c 100644
--- a/app/Notifications/Billing/AutoRechargeDisabledNotification.php
+++ b/app/Notifications/Billing/AutoRechargeDisabledNotification.php
@@ -9,7 +9,6 @@
 use App\Support\Billing\BillingNotificationRecorder;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -18,8 +17,18 @@
 /**
  * P8a: 連続失敗によるオートリチャージ自動停止の通知。
  * dedup_key = auto_recharge_disabled:{attempt_ulid} (停止イベント単位)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class AutoRechargeDisabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+class AutoRechargeDisabledNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
 {
     use Queueable;
 
diff --git a/app/Notifications/Billing/AutoRechargeEnabledNotification.php b/app/Notifications/Billing/AutoRechargeEnabledNotification.php
index 93bdc40..5c99c0b 100644
--- a/app/Notifications/Billing/AutoRechargeEnabledNotification.php
+++ b/app/Notifications/Billing/AutoRechargeEnabledNotification.php
@@ -9,7 +9,6 @@
 use App\Support\Billing\BillingNotificationRecorder;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -21,8 +20,18 @@
  * 同意の代替ではない (同意成立はオンボーディング画面の affirmative action)。有効化された
  * 条件 (閾値 / 補充後枚数 / 1 回の上限額 = 同意時金額) と停止方法を明記する。
  * dedup_key = auto_recharge_enabled:{org_id}:{payment_method_id} (setup 完了イベント単位)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class AutoRechargeEnabledNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+class AutoRechargeEnabledNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
 {
     use Queueable;
 
diff --git a/app/Notifications/Billing/AutoRechargeFailedNotification.php b/app/Notifications/Billing/AutoRechargeFailedNotification.php
index 583b932..3ffbb09 100644
--- a/app/Notifications/Billing/AutoRechargeFailedNotification.php
+++ b/app/Notifications/Billing/AutoRechargeFailedNotification.php
@@ -9,7 +9,6 @@
 use App\Support\Billing\BillingNotificationRecorder;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -18,8 +17,18 @@
 /**
  * P8a: オートリチャージ課金失敗の通知。dedup_key = auto_recharge_failed:{attempt_ulid}
  * (attempt 単位 — 同一 attempt の webhook 再送で再通知しない)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class AutoRechargeFailedNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+class AutoRechargeFailedNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
 {
     use Queueable;
 
diff --git a/app/Notifications/Billing/PaymentFailedNotification.php b/app/Notifications/Billing/PaymentFailedNotification.php
index c0e727f..e3ea2b5 100644
--- a/app/Notifications/Billing/PaymentFailedNotification.php
+++ b/app/Notifications/Billing/PaymentFailedNotification.php
@@ -9,7 +9,6 @@
 use App\Support\Billing\BillingNotificationRecorder;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -18,9 +17,19 @@
 /**
  * 支払い失敗通知。invoice.payment_failed 受信で組織の請求宛先へ送る。
  *
- * queue 送信 + DB commit 後発火 (webhook 本処理を巻き込まない)。
+ * queue 送信 (webhook 本処理を巻き込まない)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class PaymentFailedNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingDelivery
+class PaymentFailedNotification extends Notification implements ShouldQueue, TracksBillingDelivery
 {
     use Queueable;
 
diff --git a/app/Notifications/Billing/RenewalReminderNotification.php b/app/Notifications/Billing/RenewalReminderNotification.php
index ecce972..d62c791 100644
--- a/app/Notifications/Billing/RenewalReminderNotification.php
+++ b/app/Notifications/Billing/RenewalReminderNotification.php
@@ -10,7 +10,6 @@
 use Carbon\CarbonImmutable;
 use Illuminate\Bus\Queueable;
 use Illuminate\Contracts\Queue\ShouldQueue;
-use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
 use Illuminate\Notifications\Messages\MailMessage;
 use Illuminate\Notifications\Notification;
 use Illuminate\Support\Facades\Config;
@@ -19,8 +18,18 @@
 /**
  * 更新予告。次回請求 (current_period_end) の N 日前に組織の請求宛先へ送る
  * (billing:send-billing-reminders が日次 dispatch する)。
+ *
+ * 【`ShouldQueueAfterCommit` を持たない理由 — AG-114 確定 1 / AG-126 の 0 件 pin (T137)】
+ * 本通知の送信元は BillingNotificationDispatcher 1 経路で、その呼び出し元
+ * (StripeWebhookProcessor / AutoRechargeService の通知群 / SendBillingReminders) は
+ * **すべて業務 tx の外**である。よって `ShouldQueueAfterCommit` は実行時に何の効果も
+ * 持っていなかった (`addCallback` は pending tx が 0 件なら即時実行する)。
+ * 一方この interface は「grep afterCommit では見えない宣言的迂回」であり、
+ * 将来 tx 内から送ったときに黙って投入を commit 後へずらす。撤去して
+ * QueueDispatchAtomicityInventoryTest (D3) の 0 件 pin に載せる。
+ * 失敗の吸収は従来どおり dispatcher 側 (insertOrIgnore + markFailed + Log::warning) が担う。
  */
-class RenewalReminderNotification extends Notification implements ShouldQueue, ShouldQueueAfterCommit, TracksBillingReminderDelivery
+class RenewalReminderNotification extends Notification implements ShouldQueue, TracksBillingReminderDelivery
 {
     use Queueable;
 
diff --git a/app/Providers/AppServiceProvider.php b/app/Providers/AppServiceProvider.php
index 67a5a9e..52a909d 100644
--- a/app/Providers/AppServiceProvider.php
+++ b/app/Providers/AppServiceProvider.php
@@ -41,6 +41,7 @@
 use App\Support\Http\RouteThrottleBinder;
 use App\Support\PasswordPolicy;
 use App\Support\ProductionEnvGuard;
+use App\Support\QueueDispatchAtomicityGuard;
 use Aws\Sns\SnsClient;
 use Illuminate\Auth\Events\Login;
 use Illuminate\Cache\RateLimiting\Limit;
@@ -143,6 +144,13 @@ public function boot(): void
             (new ProductionEnvGuard)->enforce();
         }
 
+        // キュー投入の原子性の前提を**全環境**で fail-closed 検査する (AG-114 確定 2)。
+        // ProductionEnvGuard に相乗りしないのは、適用範囲が production 限定ではないため
+        // (sync レーンの実行順序 R4 はテスト・dev でこそ意味を持つ)。
+        // container 解決にしておくと boot からの呼び出しをテストで差し替えられる
+        $this->app->make(QueueDispatchAtomicityGuard::class)
+            ->enforce($this->app->environment('production'));
+
         // email が CipherSweet 暗号化カラムのため、credentials 検索を blind index 経由にする
         // (config/auth.php providers.users.driver = 'encrypted-eloquent')
         Auth::provider('encrypted-eloquent', function (Application $app, array $config) {
diff --git a/app/Services/Billing/AutoRechargeService.php b/app/Services/Billing/AutoRechargeService.php
index 91092d7..648e1a6 100644
--- a/app/Services/Billing/AutoRechargeService.php
+++ b/app/Services/Billing/AutoRechargeService.php
@@ -498,6 +498,12 @@ private function createAttemptLocked(Organization $organization): ?TicketAutoRec
                 ]);
                 $attempt->save();
 
+                // 実行 job の投入を**起票と同一 tx**で行う (AG-114 確定 1)。
+                // 旧: 呼び出し側 (AutoRechargeTriggerJob::handle / reconcile (v)) が tx 成功後に
+                // dispatch していたため「attempt=pending・実行未投入」の窓があり、
+                // reconcile (v) の 15 分周期に依存していた。
+                ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
+
                 return $attempt;
             });
         } catch (QueryException $e) {
@@ -1014,9 +1020,10 @@ public function reconcile(): array
             Assert::isInstanceOf($organization, Organization::class);
 
             try {
+                // 実行 job の投入は createAttemptLocked が起票と同一 tx で行う (AG-114 確定 1)。
+                // ここで dispatch すると二重投入になる。
                 $attempt = $this->maybeCreateAttempt($organization);
                 if ($attempt !== null) {
-                    ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
                     $stats['triggered']++;
                 }
             } catch (Throwable $e) {
diff --git a/app/Services/Billing/BillingCustomerSynchronizer.php b/app/Services/Billing/BillingCustomerSynchronizer.php
index 254d839..39e13bc 100644
--- a/app/Services/Billing/BillingCustomerSynchronizer.php
+++ b/app/Services/Billing/BillingCustomerSynchronizer.php
@@ -19,9 +19,19 @@ final class BillingCustomerSynchronizer
     /**
      * Stripe customer 同期 job を dispatch する。
      *
-     * **必ず `DB::transaction` クロージャの内側から呼ぶこと。** transaction 内で
-     * `afterCommit()` を付けることで outer commit 後に発火し、commit 前の stale read を防ぐ (IV-3)。
-     * transaction の外で呼ぶと `afterCommit()` が即時実行になり遅延保証が崩れるため禁止。
+     * **必ず `DB::transaction` クロージャの内側から呼ぶこと** (呼び出し元 2 経路は既にそう)。
+     *
+     * 【契約の反転 (AG-114 確定 1 / T137)】
+     * - 旧主張: transaction 内で `afterCommit()` を付け、outer commit 後に発火させる
+     * - 旧目的: commit 前の stale read を防ぐ (IV-3)
+     * - 新主張: `afterCommit()` を付けず、業務 tx の内側で素直に dispatch する
+     * - 新前提: jobs 行が業務 tx に乗るため、**worker が job を可視化できるのは commit 後**
+     * - 前提を守る機構: QueueDispatchAtomicityGuard (driver=database / キュー DB 接続 =
+     *   業務 DB / after_commit=false を起動時に fail-closed 検査)
+     * - 反転根拠: 本 job は SerializesModels で organization を **ID で直列化し handle 時に
+     *   再取得**する。可視化が commit 後である以上、再取得値は必ず commit 後の値になり、
+     *   IV-3 は afterCommit なしで (むしろより強く) 保たれる。加えて afterCommit は
+     *   「commit したのに dispatch されない」窓を残すため、確定 1 の下では有害である
      *
      * Stripe customer 未作成 (`stripe_id === null`) の組織は no-op (IV-4、例外にしない)。
      */
@@ -31,6 +41,6 @@ public function dispatchFor(Organization $organization): void
             return;
         }
 
-        SyncBillingCustomerDetails::dispatch($organization)->afterCommit();
+        SyncBillingCustomerDetails::dispatch($organization);
     }
 }
diff --git a/app/Services/Billing/StripeWebhookProcessor.php b/app/Services/Billing/StripeWebhookProcessor.php
index 63c4787..c4a8246 100644
--- a/app/Services/Billing/StripeWebhookProcessor.php
+++ b/app/Services/Billing/StripeWebhookProcessor.php
@@ -474,6 +474,8 @@ private function handleInvoicePaymentFailed(array $payload): void
             $attemptUlid = $this->stringAt($payload, 'data.object.metadata.recharge_attempt_ulid');
             $attempt = $attemptUlid === null ? null : $this->autoRecharge->findPendingAttemptByUlid($attemptUlid);
             if ($attempt !== null) {
+                // ★ ここは tx で括らない (AG-114 確定 1 の対象外)。先行する自 DB 書き込みが無く、
+                //   原子性の対象になる業務 tx が存在しないため (findPendingAttemptByUlid は読み取りのみ)。
                 HandleAutoRechargeChargeFailureJob::dispatch($attempt->id);
             }
 
@@ -619,8 +621,13 @@ private function settleSubscriptionCheckout(array $payload): void
         //     (未決済 completed への伝播防止)。再送は (4) の終局 no-op で到達しない。
         $subscriptionId = $this->subscriptionIdFrom($payload);
         if ($local->funding_choice === SignupFundingChoice::AutoRecharge->value && $subscriptionId !== null) {
-            $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
-            ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
+            // 打刻と投入を同一 tx で括る (AG-114 確定 1)。
+            // pm_reuse_dispatched_at は「自動的に有効になります」表示の出典であり、
+            // 打刻だけ残って job が投入されない状態は**表示と実態の食い違い**になる。
+            DB::transaction(function () use ($local, $subscriptionId): void {
+                $local->forceFill(['pm_reuse_dispatched_at' => CarbonImmutable::now()])->save();
+                ReuseSubscriptionPaymentMethodJob::dispatch($local->organization_id, $subscriptionId);
+            });
         }
     }
 
@@ -684,15 +691,21 @@ private function completeAutoRechargeSetup(array $payload): void
             throw new RuntimeException("auto-recharge setup webhook: setup_intent 欠落 (session {$sessionId})");
         }
 
-        if ($session->status !== CheckoutSessionStatus::Completed->value) {
-            $session->status = CheckoutSessionStatus::Completed->value;
-            $session->completed_at = now();
-            $session->save();
-        }
-
         $organizationId = $organization->getKey();
         Assert::integer($organizationId);
-        SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
+
+        // 台帳の completed 化と PM 既定設定 job の投入を同一 tx で括る (AG-114 確定 1)。
+        // status だけ completed になって job が投入されないと、PM が既定にならないまま
+        // 「設定完了」の表示になる。
+        DB::transaction(function () use ($session, $organizationId, $setupIntentId): void {
+            if ($session->status !== CheckoutSessionStatus::Completed->value) {
+                $session->status = CheckoutSessionStatus::Completed->value;
+                $session->completed_at = now();
+                $session->save();
+            }
+
+            SetDefaultPaymentMethodJob::dispatch($organizationId, $setupIntentId);
+        });
     }
 
     /**
diff --git a/app/Services/Billing/TicketLedgerService.php b/app/Services/Billing/TicketLedgerService.php
index 5b37f02..d9c7883 100644
--- a/app/Services/Billing/TicketLedgerService.php
+++ b/app/Services/Billing/TicketLedgerService.php
@@ -374,12 +374,17 @@ public function availableTrueBalance(Organization $organization): int
      * 消費優先順位は monthly (期限付き = 先に失効する) → purchased (無期限)。予約時に
      * 「どの出所をどの期限で消費するか」を consume_source / consume_expires_at へ固定し、
      * commit は再探索しない。残高不足は InsufficientTicketsException。
+     *
+     * 低残高通知 (AG-127 の付随的副作用) は **tx を抜けた最後**に実行する。閾値クロスの事実は
+     * クロージャの**戻り値**で持ち出す (参照渡しにしない — 将来 transaction retry が入ったとき、
+     * rollback された試行の副作用がクロージャの外に残るため)。
      */
     public function reserve(Organization $organization, int $amount): TicketReservation
     {
         Assert::positiveInteger($amount, 'reserve の amount は正の整数のみ');
 
-        return DB::transaction(function () use ($organization, $amount): TicketReservation {
+        /** @var array{reservation: TicketReservation, crossing: array{balance: int, threshold: int}|null} $result */
+        $result = DB::transaction(function () use ($organization, $amount): array {
             // 残高判定の直列化点: organizations 行ロックで並行 reserve の TOCTOU を防ぐ
             $this->lockOrganizationRow($organization);
 
@@ -420,10 +425,13 @@ public function reserve(Organization $organization, int $amount): TicketReservat
             $balance = $availableMonthly + $availablePurchased; // = availableTrueBalance と同一意味論
             $threshold = config()->integer('billing.ticket_low_balance_threshold');
             $after = $balance - $amount;
+            $crossing = null;
             if ($balance >= $threshold && $after < $threshold) {
-                // afterCommit: reserve は pipeline の startJob tx 内から savepoint で呼ばれ得るため、
-                // 最外層 commit 成立後にのみ通知する (rollback 時は発火しない)
-                DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
+                // 通知は**付随的副作用** (AG-127)。tx の内側では実行せず、閾値クロスの事実だけ持ち出す。
+                // TicketBalanceLowNotification は ShouldQueue ではない同期 DB 書き込みのため、
+                // ここで実行すると organizations 行ロック (reserve の直列化点) を通知 INSERT の
+                // 分だけ長く保持することになる。
+                $crossing = ['balance' => $after, 'threshold' => $threshold];
             }
 
             // P8a: オートリチャージ (裏チャージ) のトリガ点。**低残高通知と同居**させる
@@ -436,13 +444,29 @@ public function reserve(Organization $organization, int $amount): TicketReservat
             // 閾値判定・pending 検査・数量確定は Job 側 (AutoRechargeService) が org 行ロック下で
             // 再評価するため、ここでは条件を絞らない = 過剰 dispatch は無害
             // (設定行なし org は Job 冒頭で即 return。既定 off の org には何も起きない)。
-            // afterCommit で rollback 時は発火しない。
+            //
+            // **業務 tx の内側で投入する** (AG-114 確定 1)。jobs 行が同一 tx に乗るため
+            // rollback すれば投入ごと巻き戻る (旧: DB::afterCommit。afterCommit は
+            // 「commit したのに未投入」の窓を残す)。
             $organizationId = $organization->getKey();
             Assert::integer($organizationId);
-            DB::afterCommit(static fn () => AutoRechargeTriggerJob::dispatch($organizationId));
+            AutoRechargeTriggerJob::dispatch($organizationId);
 
-            return $reservation;
+            return ['reservation' => $reservation, 'crossing' => $crossing];
         });
+
+        $crossing = $result['crossing'];
+
+        // 低残高通知 (AG-127 の付随的副作用)。**tx を抜けた最後**に実行する。
+        // 保証範囲を誇張しない: reserve が呼び出し側の tx にネストされている場合、
+        // ここは依然として外側 tx の内側であり、(a) 外側のロックを保持したまま INSERT が走る、
+        // (b) SQL 層の失敗は PostgreSQL の tx abort を経て業務操作ごと失敗させる。
+        // アプリケーション層の例外は NotificationCenterService::safely() が握る。
+        if ($crossing !== null) {
+            $this->notifications->notifyTicketBalanceLow($organization, $crossing['balance'], $crossing['threshold']);
+        }
+
+        return $result['reservation'];
     }
 
     /**
diff --git a/app/Services/Capture/CaptureTakeService.php b/app/Services/Capture/CaptureTakeService.php
index 83edc54..d75df70 100644
--- a/app/Services/Capture/CaptureTakeService.php
+++ b/app/Services/Capture/CaptureTakeService.php
@@ -85,11 +85,11 @@ public function update(Project $project, VideoManual $manual, Cut $cut, Take $ta
     }
 
     /**
-     * 削除。DL 済み (downloaded_at 非 null) は 422。採用中なら null 化 + S3 削除 Job (tx 成功後)。
+     * 削除。DL 済み (downloaded_at 非 null) は 422。採用中なら null 化 + S3 削除 Job (業務 tx 内)。
      */
     public function delete(Project $project, VideoManual $manual, Cut $cut, Take $take): void
     {
-        $paths = DB::transaction(function () use ($project, $manual, $cut, $take): array {
+        DB::transaction(function () use ($project, $manual, $cut, $take): void {
             /** @var VideoManual $lockedManual */
             $lockedManual = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
             /** @var Cut $lockedCut */
@@ -108,12 +108,13 @@ public function delete(Project $project, VideoManual $manual, Cut $cut, Take $ta
             $lockedTake->delete();
             $this->renumber($lockedCut);
 
-            return $paths;
+            // S3 削除の投入を**同一 tx 内**で行う (AG-114 確定 1)。
+            // 保証するのは「take 行を消したのに削除 job が投入されない窓」の解消だけである
+            // (worker 停止 / job 失敗 / ストレージ失敗ではオブジェクトは残る = 誇張しない)。
+            if ($paths !== []) {
+                DeleteTakeObjectsJob::dispatch($paths); // media queue へ
+            }
         });
-
-        if ($paths !== []) {
-            DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ
-        }
     }
 
     /**
diff --git a/app/Services/Manual/AnalysisJobService.php b/app/Services/Manual/AnalysisJobService.php
index 84593c9..b84cb12 100644
--- a/app/Services/Manual/AnalysisJobService.php
+++ b/app/Services/Manual/AnalysisJobService.php
@@ -95,12 +95,15 @@ public function trigger(Project $project, VideoManual $manual, ?User $actor = nu
 
             $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();
 
+            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。payload は job id のみ。
+            // jobs 行が同一 tx に乗るため「保存済み・未投入」が構造的に消え、rollback すれば
+            // jobs 行ごと巻き戻る。原子性の前提 (driver=database / キュー DB 接続 = 業務 DB /
+            // after_commit=false) は QueueDispatchAtomicityGuard が起動時に fail-closed 検査する。
+            RunManualAnalysis::dispatch($job->id);
+
             return $job;
         });
 
-        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
-        RunManualAnalysis::dispatch($job->id);
-
         return $job;
     }
 
diff --git a/app/Services/Manual/RenderJobService.php b/app/Services/Manual/RenderJobService.php
index 5852777..3bf519d 100644
--- a/app/Services/Manual/RenderJobService.php
+++ b/app/Services/Manual/RenderJobService.php
@@ -106,12 +106,14 @@ public function trigger(Project $project, VideoManual $manual, ?User $actor = nu
 
             $locked->forceFill(['status' => VideoManualStatus::Rendering])->save();
 
+            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。payload は job id のみ。
+            // jobs 行が同一 tx に乗るため「保存済み・未投入」が構造的に消える。
+            // 前提は QueueDispatchAtomicityGuard が起動時に fail-closed 検査する。
+            RunManualRender::dispatch($job->id);
+
             return $job;
         });
 
-        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
-        RunManualRender::dispatch($job->id);
-
         return $job;
     }
 
@@ -156,11 +158,12 @@ public function triggerPreview(Project $project, VideoManual $manual, ?User $act
             }
             $job->save();
 
+            // キュー投入は**業務 tx の内側**で行う (AG-114 確定 1)。
+            RunManualRender::dispatch($job->id);
+
             return $job; // manual status は変更しない (編集と並走)
         });
 
-        RunManualRender::dispatch($job->id);
-
         return $job;
     }
 
diff --git a/app/Services/Manual/RenderPipeline.php b/app/Services/Manual/RenderPipeline.php
index 831a4b7..96b8417 100644
--- a/app/Services/Manual/RenderPipeline.php
+++ b/app/Services/Manual/RenderPipeline.php
@@ -279,9 +279,7 @@ private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderCli
      */
     private function finalize(RenderJob $job, RenderResult $result): bool
     {
-        /** @var list<int> $oldJobIds */
-        $oldJobIds = [];
-        $succeeded = DB::transaction(function () use ($job, $result, &$oldJobIds): bool {
+        $succeeded = DB::transaction(function () use ($job, $result): bool {
             // ロック 1: job 行 (stale 回復 cron との直列化点)
             /** @var RenderJob $locked */
             $locked = RenderJob::query()->whereKey($job->getKey())->lockForUpdate()->firstOrFail();
@@ -331,14 +329,17 @@ private function finalize(RenderJob $job, RenderResult $result): bool
                 ->map(static fn (RenderJob $old): int => $old->id)
                 ->all();
 
+            // 旧世代 output の削除投入を **terminal tx の内側**で行う (AG-114 確定 1)。
+            // 削除 job は冪等のため重複無害。喪失時の回収役 (render:reconcile-outputs) は
+            // 別要因 (worker 異常終了) のために残す。
+            foreach ($oldJobIds as $oldJobId) {
+                DeleteRenderOutputsJob::dispatch($oldJobId);
+            }
+
             return true;
         });
 
         if ($succeeded) {
-            // dispatch は commit 後 (喪失は render:reconcile-outputs が回収)
-            foreach ($oldJobIds as $oldJobId) {
-                DeleteRenderOutputsJob::dispatch($oldJobId);
-            }
             $job->refresh();
         }
 
diff --git a/app/Services/Manual/VideoManualService.php b/app/Services/Manual/VideoManualService.php
index a4934a5..8f69ce1 100644
--- a/app/Services/Manual/VideoManualService.php
+++ b/app/Services/Manual/VideoManualService.php
@@ -195,7 +195,7 @@ public function updateMeta(Project $project, VideoManual $manual, string $title,
      */
     public function delete(Project $project, VideoManual $manual): void
     {
-        $paths = DB::transaction(function () use ($project, $manual): array {
+        DB::transaction(function () use ($project, $manual): void {
             $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
             // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
             /** @var VideoManual $lockedManual */
@@ -214,12 +214,15 @@ public function delete(Project $project, VideoManual $manual): void
                 ->all();
             $lockedManual->delete(); // cuts / takes / source_documents は FK cascade
 
-            return array_values(array_unique([...$takePaths, ...$documentPaths]));
-        });
+            /** @var list<string> $paths */
+            $paths = array_values(array_unique([...$takePaths, ...$documentPaths]));
 
-        if ($paths !== []) {
-            DeleteTakeObjectsJob::dispatch($paths); // tx 成功後に media queue へ (重複キーは除去済み)
-        }
+            // S3 削除の投入を**同一 tx 内**で行う (AG-114 確定 1)。
+            // 保証するのは「manual を消したのに削除 job が投入されない窓」の解消だけである。
+            if ($paths !== []) {
+                DeleteTakeObjectsJob::dispatch($paths); // media queue へ (重複キーは除去済み)
+            }
+        });
     }
 
     /**
diff --git a/app/Support/QueueDispatchAtomicityGuard.php b/app/Support/QueueDispatchAtomicityGuard.php
new file mode 100644
index 0000000..a35f370
--- /dev/null
+++ b/app/Support/QueueDispatchAtomicityGuard.php
@@ -0,0 +1,268 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Support;
+
+use App\DataTransferObjects\Support\QueueDispatchAtomicityViolation;
+use App\Enums\Support\QueueAtomicityRule;
+use RuntimeException;
+
+/**
+ * キュー投入の原子性の前提を起動時に fail-closed 検査する SSOT (AG-114 確定 2)。
+ *
+ * 【なぜ必要か】業務 tx の内側でキュー投入を行う設計 (確定 1) は
+ * 「キューの実体が業務 DB と同一トランザクションに乗る」ことを前提にする。この前提は
+ * config と env で簡単に崩れ、**テストは全部緑のまま原子性だけ消える**。
+ *
+ * 【検査する項目 (AG-126「使っている機能に応じて選ぶ」)】
+ * - R1 DatabaseDriver / R2 SameDatabaseConnection / R3 AfterCommitDisabled:
+ *   参照接続 (既定接続 + onConnection でリテラル pin された 3 種) について
+ * - R4 SyncAfterCommitEnabled: sync 接続の実行順序の保存 (config/queue.php の当該コメント参照)
+ * - R5 ProductionAsyncDriver: production の既定接続が sync だと job が HTTP リクエスト内で
+ *   インライン実行され、原子性・非同期化・worker 分離がすべて失われるため拒否する
+ *
+ * 【検査しない項目とその根拠】
+ * - **Bus::batch / Bus::chain の束台帳**: `app/` 配下に使用が 0 件のため対象外。
+ *   導入するときは `config('queue.batching')` の接続一致検査を本 guard に追加すること
+ * - **beanstalkd / sqs / redis / deferred / background / failover**: config に定義があるだけで
+ *   どの job からも参照されていない (参照集合に入らない)
+ *
+ * 【保証しないもの (誇張しない)】
+ * - 見るのは **config の値だけ**である。`connection` 名の一致は「同一トランザクションに乗る」
+ *   ことの**代理検査**にすぎず、異なる PDO インスタンス / connection resolver の差し替え /
+ *   同名で別サーバを指す構成までは検査しない
+ * - 「dispatch が業務 tx の内側にあること」自体は検査しない (behavioral テストの担当)
+ */
+class QueueDispatchAtomicityGuard
+{
+    /**
+     * `onConnection('...')` でリテラル pin されている接続名。
+     * 正本は QueuedJobLeaseInventoryTest の QUEUED_JOB_LEASE_INVENTORY で、
+     * そちらが deny-by-default で全 ShouldQueue クラスの接続を固定している。
+     * この定数と実際の pin 集合の drift は同テストの対称差テストが閉じる
+     * (guard 単体では閉じない)。
+     *
+     * @var list<string>
+     */
+    public const PINNED_CONNECTIONS = ['database-analysis', 'database-render', 'database-media'];
+
+    /**
+     * config を読んで違反の一覧を返す (純関数的。例外を投げない)。
+     *
+     * ★ 想定外の型・空文字はすべて**違反として報告**し、報告した対象は以降の offset 参照を
+     *   行わず早期 continue / return する (fail-closed)。空文字へ丸めて比較を続けると、
+     *   R2 の「connection が null = 既定 DB なので OK」の判定が比較対象不在のまま通る。
+     *
+     * @return list<QueueDispatchAtomicityViolation>
+     */
+    public function violations(bool $isProduction): array
+    {
+        $connections = config('queue.connections');
+        if (! is_array($connections)) {
+            return [new QueueDispatchAtomicityViolation(
+                QueueAtomicityRule::ConfigUnreadable,
+                '-',
+                $this->display($connections),
+                'queue.connections が配列ではありません (キュー接続を 1 つも検査できない = fail-closed)。',
+            )];
+        }
+
+        $defaultQueue = config('queue.default');
+        $defaultDatabase = config('database.default');
+
+        $violations = [];
+        if (! is_string($defaultQueue) || $defaultQueue === '') {
+            $violations[] = new QueueDispatchAtomicityViolation(
+                QueueAtomicityRule::ConfigUnreadable,
+                '-',
+                $this->display($defaultQueue),
+                'queue.default が非空文字列ではありません (既定キュー接続を特定できない)。',
+            );
+        }
+        if (! is_string($defaultDatabase) || $defaultDatabase === '') {
+            $violations[] = new QueueDispatchAtomicityViolation(
+                QueueAtomicityRule::ConfigUnreadable,
+                '-',
+                $this->display($defaultDatabase),
+                'database.default が非空文字列ではありません (業務 DB 接続を特定できない)。',
+            );
+        }
+        // 既定値が取れないなら以降の比較は無意味なので打ち切る (fail-closed)
+        if ($violations !== []) {
+            return $violations;
+        }
+
+        // ここまでで両方 string かつ非空であることが確定している
+        /** @var non-empty-string $defaultQueue */
+        /** @var non-empty-string $defaultDatabase */
+
+        // R4: sync 接続。**未定義・非配列・driver が sync でない場合も違反**。
+        // driver を見ないと「sync という名前の database 接続」で R4 を通せてしまう。
+        $sync = $connections['sync'] ?? null;
+        if (! is_array($sync)) {
+            $violations[] = new QueueDispatchAtomicityViolation(
+                QueueAtomicityRule::SyncAfterCommitEnabled,
+                'sync',
+                $this->display($sync),
+                'queue.connections.sync が配列として定義されていません '
+                .'(テスト・dev レーンの実行順序 (after_commit=true) を検査できない)。',
+            );
+        } elseif (($sync['driver'] ?? null) !== 'sync' || ($sync['after_commit'] ?? null) !== true) {
+            $violations[] = new QueueDispatchAtomicityViolation(
+                QueueAtomicityRule::SyncAfterCommitEnabled,
+                'sync',
+                'driver='.$this->display($sync['driver'] ?? null)
+                .' after_commit='.$this->display($sync['after_commit'] ?? null),
+                'queue.connections.sync は driver=sync かつ after_commit=true でなければなりません '
+                .'(業務 tx 内 dispatch がその場でインライン実行され、pipeline の startJob が'
+                .'自分自身のロック下で成立してしまう)。',
+            );
+        }
+
+        // R5: production の既定接続 driver
+        if ($isProduction) {
+            $defaultDriver = $this->driverOf($connections, $defaultQueue);
+            if ($defaultDriver !== 'database') {
+                $violations[] = new QueueDispatchAtomicityViolation(
+                    QueueAtomicityRule::ProductionAsyncDriver,
+                    $defaultQueue,
+                    $this->display($defaultDriver),
+                    "production の既定キュー接続 ({$defaultQueue}) の driver が database ではありません "
+                    .'(sync だと job が HTTP リクエスト内でインライン実行され、原子性・非同期化・'
+                    .'worker 分離がすべて失われる)。',
+                );
+            }
+        }
+
+        // R1〜R3: 参照集合 = [既定接続] ∪ PINNED_CONNECTIONS (重複除去)。
+        // ★ **除外は接続「名」で判定する**。driver === 'sync' で除外すると、
+        //   `database-analysis.driver = sync` にした構成が R1〜R3 を全部 skip して通ってしまう
+        //   (R4 が見るのは connections.sync だけのため)。
+        foreach ($this->referencedConnections($defaultQueue) as $name) {
+            if ($name === 'sync') {
+                continue; // sync 接続の契約は R4 / R5 が担う
+            }
+
+            $config = $connections[$name] ?? null;
+            if (! is_array($config)) {
+                $violations[] = new QueueDispatchAtomicityViolation(
+                    QueueAtomicityRule::DatabaseDriver,
+                    $name,
+                    $this->display($config),
+                    "キュー接続 {$name} の定義が配列として存在しません (原子性の前提を検査できない)。",
+                );
+
+                continue; // 以降の offset 参照をしない
+            }
+
+            $driver = $config['driver'] ?? null;
+            if ($driver !== 'database') {
+                $violations[] = new QueueDispatchAtomicityViolation(
+                    QueueAtomicityRule::DatabaseDriver,
+                    $name,
+                    $this->display($driver),
+                    "キュー接続 {$name} の driver が database ではありません "
+                    .'(業務 DB と同一トランザクションに jobs 行を乗せられない)。',
+                );
+
+                continue;
+            }
+
+            // R2 は三分岐 (null = 既定 DB 接続 / 非空 string = 一致判定 / それ以外 = 不正)
+            $connection = $config['connection'] ?? null;
+            if ($connection === null) {
+                // 既定 DB 接続を使う = 許可
+            } elseif (is_string($connection) && $connection !== '') {
+                if ($connection !== $defaultDatabase) {
+                    $violations[] = new QueueDispatchAtomicityViolation(
+                        QueueAtomicityRule::SameDatabaseConnection,
+                        $name,
+                        $connection,
+                        "キュー接続 {$name} の DB 接続 ({$connection}) が業務 DB "
+                        ."({$defaultDatabase}) と異なります (別トランザクションになる)。",
+                    );
+                }
+            } else {
+                $violations[] = new QueueDispatchAtomicityViolation(
+                    QueueAtomicityRule::SameDatabaseConnection,
+                    $name,
+                    $this->display($connection),
+                    "キュー接続 {$name} の connection は null か非空文字列でなければなりません。",
+                );
+            }
+
+            // R3 はキー欠落も非 bool も違反 (厳密比較)
+            $afterCommit = array_key_exists('after_commit', $config) ? $config['after_commit'] : null;
+            if ($afterCommit !== false) {
+                $violations[] = new QueueDispatchAtomicityViolation(
+                    QueueAtomicityRule::AfterCommitDisabled,
+                    $name,
+                    $this->display($afterCommit),
+                    "キュー投入接続 {$name} の after_commit は false を明示してください "
+                    .'(true だと投入が commit 後へずれ「commit したのに未投入」の窓が復活する)。',
+                );
+            }
+        }
+
+        return $violations;
+    }
+
+    /**
+     * 違反があれば起動を止める (fail-closed)。
+     */
+    public function enforce(bool $isProduction): void
+    {
+        $violations = $this->violations($isProduction);
+        if ($violations === []) {
+            return;
+        }
+
+        throw new RuntimeException(
+            "Queue dispatch atomicity violations:\n- ".implode("\n- ", array_map(
+                static fn (QueueDispatchAtomicityViolation $violation): string => $violation->message,
+                $violations,
+            ))
+        );
+    }
+
+    /**
+     * R1〜R3 の参照集合 (既定接続 + pin 済み接続。重複除去・順序は宣言順)。
+     *
+     * @return list<string>
+     */
+    private function referencedConnections(string $defaultQueue): array
+    {
+        return array_values(array_unique([$defaultQueue, ...self::PINNED_CONNECTIONS]));
+    }
+
+    /**
+     * 接続名から driver を取り出す (取れなければ null)。
+     *
+     * @param  array<mixed>  $connections
+     */
+    private function driverOf(array $connections, string $name): ?string
+    {
+        $config = $connections[$name] ?? null;
+        if (! is_array($config)) {
+            return null;
+        }
+
+        $driver = $config['driver'] ?? null;
+
+        return is_string($driver) ? $driver : null;
+    }
+
+    /** 実測値を表示用の文字列へ正規化する (DTO へ mixed を漏らさないため)。 */
+    private function display(mixed $value): string
+    {
+        if (is_array($value)) {
+            return 'array('.count($value).')';
+        }
+        if (is_object($value)) {
+            return 'object('.$value::class.')';
+        }
+
+        return var_export($value, true);
+    }
+}
diff --git a/config/queue.php b/config/queue.php
index b666eb6..b10c52f 100644
--- a/config/queue.php
+++ b/config/queue.php
@@ -31,8 +31,22 @@
 
     'connections' => [
 
+        // sync は「テストレーン (phpunit.xml / phpunit.browser.xml が force) と local dev」専用。
+        // **after_commit => true が必須**: これが無いと業務 tx の内側からの dispatch が
+        // その場でインライン実行され、RunManualAnalysis / RunManualRender が trigger の tx の
+        // 内側で走って AnalysisPipeline / RenderPipeline の startJob (lockForUpdate + status===queued)
+        // が自分自身のロック下で成立してしまう (= 共有ロック規約の意味論が壊れる)。
+        //
+        // SyncQueue::push() は shouldDispatchAfterCommit() を尊重し db.transactions へ倒す。
+        // テストレーンでは RefreshDatabase が Illuminate\Foundation\Testing\DatabaseTransactionsManager
+        // を差し込み、ラッパ tx を skip したうえで level 1 を root 扱いするため、
+        // 「業務 tx の commit 直後・テスト tx の内側でインライン実行」= 本番の
+        // 「commit 後に worker が拾う」と同じ順序意味論になる。
+        //
+        // この 1 行は QueueDispatchAtomicityGuard の規則 R4 が起動時に機械固定する。
         'sync' => [
             'driver' => 'sync',
+            'after_commit' => true,
         ],
 
         // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
diff --git a/tests/Architecture/BillingSyncDispatchInvariantTest.php b/tests/Architecture/BillingSyncDispatchInvariantTest.php
index 38ddc0b..ae041eb 100644
--- a/tests/Architecture/BillingSyncDispatchInvariantTest.php
+++ b/tests/Architecture/BillingSyncDispatchInvariantTest.php
@@ -10,9 +10,19 @@
 |--------------------------------------------------------------------------
 |
 | SyncBillingCustomerDetails の dispatch は BillingCustomerSynchronizer 1 経路に閉じる (IV-2)。
-| 窓口を単一化することで「必ず transaction 内から afterCommit で発火する」(IV-3) /
+| 窓口を単一化することで「必ず transaction の内側から発火する」(IV-3) /
 | 「stripe_id 未作成は no-op」(IV-4) の契約が構造的に守られる。webhook ハンドラがこの経路を
 | 通らないことが Stripe→アプリ→Stripe の同期ループを構造的に防いでいる。
+|
+| 【契約の反転 (AG-114 確定 1 / T137)】
+| - 旧主張: SyncBillingCustomerDetails は transaction 内から afterCommit で発火する (IV-3)
+| - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ
+| - 新主張: transaction の内側で素直に dispatch し、jobs 行を業務 tx に乗せる
+| - 新前提: worker が job を可視化できるのは commit 後 (jobs 行が同一 tx にあるため)
+| - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
+| - 反転根拠: 本 job は SerializesModels で organization を再取得するため、可視化が commit 後で
+|   ある限り IV-3 は afterCommit なしで保たれる。一方 afterCommit は「commit したのに未投入」の
+|   窓を残すため、確定 1 の下では有害である
 */
 
 test('app/ 内の SyncBillingCustomerDetails::dispatch は BillingCustomerSynchronizer に閉じる', function (): void {
diff --git a/tests/Architecture/JobExclusionOrderingInvariantTest.php b/tests/Architecture/JobExclusionOrderingInvariantTest.php
index d7541ad..3e9e8d0 100644
--- a/tests/Architecture/JobExclusionOrderingInvariantTest.php
+++ b/tests/Architecture/JobExclusionOrderingInvariantTest.php
@@ -5,9 +5,12 @@
 use App\Jobs\Billing\AutoRechargeTriggerJob;
 use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
 use App\Services\Billing\AutoRechargeService;
+use Illuminate\Contracts\Queue\ShouldBeUnique;
 
 /*
- * 入口の排他 (Cache::lock TTL / ShouldBeUnique の uniqueFor) の**序列**を CI 固定する。
+ * 入口の排他 (Cache::lock TTL) の**序列**を CI 固定する。
+ * ★ T137 で `ShouldBeUnique` (uniqueFor) の系統は AutoRechargeTriggerJob から撤去され、
+ *   本ファイルの当該 2 テストは「実装しないこと」の固定へ**反転**した (下の反転 docblock)。
  *
  * 裁定 AG-082: 入口の排他は best-effort であり、結果の一回性を保証しない。
  * したがって「保証を代替できるほど長く」してはならない — 鍵が残留すると、
@@ -48,19 +51,33 @@ function jobExclusionDefaultRetryAfter(): int
     );
 });
 
-test('入口の排他: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回る', function (): void {
-    $retryAfter = jobExclusionDefaultRetryAfter();
-
-    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeLessThan(
-        $retryAfter,
-        'uniqueFor がキューの再配送間隔以上です。ShouldBeUnique の鍵は失敗や timeout で'
-        .'解放されないことがあるため (Laravel 公式)、残留時間を再配送間隔の内側に収めること。',
+/**
+ * 【契約の反転 (AG-114 確定 1 / T137)】
+ * - 旧主張: AutoRechargeTriggerJob の uniqueFor は既定接続の retry_after を下回り、正の値である
+ * - 旧目的: 入口排他 (ShouldBeUnique) の鍵が再配送間隔を跨いで抑止を残さないようにする
+ * - 新主張: AutoRechargeTriggerJob は ShouldBeUnique を **実装しない** (入口排他を持たない)
+ * - 新前提: 結果の一回性は maybeCreateAttempt の organizations 行ロック + pending 検査 +
+ *   tar_attempts_org_pending_unique (partial unique) + unique violation の no-op 化が担う
+ * - 前提を守る機構: AutoRechargeAttemptUniquenessTest (3 点の behavioral 固定) +
+ *   JobExecutionDedupInventoryTest の GuardedByDownstreamConstraint 登録
+ * - 反転根拠: UniqueLock は dispatch 呼び出し時に取得され rollback で解放されない。業務 tx の
+ *   内側で dispatch する設計 (確定 1) では、ネスト深さに依らず rollback 後も uniqueFor 秒の
+ *   抑止が残る。AGENTS.md ドメイン規約 6 のとおり入口排他は保証を担わないため撤去する
+ */
+test('入口の排他: AutoRechargeTriggerJob は ShouldBeUnique を実装しない', function (): void {
+    expect(is_subclass_of(AutoRechargeTriggerJob::class, ShouldBeUnique::class))->toBeFalse(
+        'AutoRechargeTriggerJob が入口排他 (ShouldBeUnique) を持っています。業務 tx の内側で'
+        .'dispatch する設計では UniqueLock が rollback で解放されず、uniqueFor 秒の抑止が残ります。'
+        .'一回性は永続状態遷移 (org 行ロック + pending 検査 + partial unique) が担います。',
     );
 });
 
-test('入口の排他: uniqueFor は正の値である (実質無効化の検出)', function (): void {
-    // 0 / 負値は「鍵を持たない」に等しく、ShouldBeUnique の宣言が静かに空洞化する
-    expect((new AutoRechargeTriggerJob(1))->uniqueFor)->toBeGreaterThan(0);
+test('入口の排他: AutoRechargeTriggerJob は uniqueFor / uniqueId を持たない (死んだ宣言の検出)', function (): void {
+    // ShouldBeUnique 無しの uniqueFor / uniqueId は何も効かない宣言であり、
+    // 「排他がある」という誤読を生むため残さない
+    $reflection = new ReflectionClass(AutoRechargeTriggerJob::class);
+    expect(array_key_exists('uniqueFor', $reflection->getDefaultProperties()))->toBeFalse();
+    expect($reflection->hasMethod('uniqueId'))->toBeFalse();
 });
 
 test('入口の排他: 比較先の前提 — auto-recharge の 2 ジョブは既定接続で動く', function (): void {
diff --git a/tests/Architecture/QueueDispatchAtomicityInventoryTest.php b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
new file mode 100644
index 0000000..2a8fe6a
--- /dev/null
+++ b/tests/Architecture/QueueDispatchAtomicityInventoryTest.php
@@ -0,0 +1,452 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Mail\InquiryReceivedMail;
+use App\Notifications\Billing\PaymentFailedNotification;
+use Illuminate\Bus\Queueable;
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
+test('D2: first-party ランタイム PHP に DB::afterCommit() の呼び出しは 1 件も無い', function (): void {
+    $hits = array_values(array_filter(
+        QueueDispatchDeferralInventory::detectInFiles(QueueDispatchDeferralInventory::runtimePhpFiles()),
+        static fn (array $hit): bool => $hit['kind'] === 'D2',
+    ));
+
+    expect(queueDeferralFormatHits($hits))->toBe(
+        [],
+        'DB::afterCommit() への退避が残っています。付随的副作用 (AG-127) は tx の外へ出し、'
+        .'キュー投入は業務 tx の内側で行ってください。',
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
+test('D5: 母集団に $afterCommit の既定値が true のクラスは 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitProperty(
+        QueueDispatchDeferralInventory::deferralCandidateClasses(),
+    );
+
+    expect($hits)->toBe([], '$afterCommit の既定値が true のクラスがあります: '.implode(', ', $hits));
+});
+
+test('D5: first-party ランタイム PHP に $afterCommit への true 代入は 1 件も無い', function (): void {
+    $hits = QueueDispatchDeferralInventory::detectAfterCommitAssignments(
+        QueueDispatchDeferralInventory::runtimePhpFiles(),
+    );
+
+    expect(queueDeferralFormatHits($hits))->toBe([], '$afterCommit への true 代入が残っています。');
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
+test('負のコントロール: $afterCommit = true を持つダミー job クラスを D5 (既定値) が検出する', function (): void {
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
+test('偽陰性の負のコントロール: $afterCommit の既定値が null / false のクラスは D5 (既定値) が検出しない', function (): void {
+    expect(QueueDispatchDeferralInventory::detectAfterCommitProperty([
+        DeferralProbeNullAfterCommitJob::class,
+        DeferralProbeFalseAfterCommitJob::class,
+    ]))->toBe([]);
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
diff --git a/tests/Architecture/QueuedJobLeaseInventoryTest.php b/tests/Architecture/QueuedJobLeaseInventoryTest.php
index 2efc2f1..7312d1b 100644
--- a/tests/Architecture/QueuedJobLeaseInventoryTest.php
+++ b/tests/Architecture/QueuedJobLeaseInventoryTest.php
@@ -20,6 +20,7 @@
 use App\Notifications\Billing\AutoRechargeFailedNotification;
 use App\Notifications\Billing\PaymentFailedNotification;
 use App\Notifications\Billing\RenewalReminderNotification;
+use App\Support\QueueDispatchAtomicityGuard;
 use Tests\Support\PhpTokenScan;
 use Tests\Support\QueuedJobPopulation;
 use Tests\Support\QueueLeaseConfig;
@@ -576,6 +577,33 @@ function jobLeaseRelativePath(string $path): string
     }
 });
 
+test('接続経路: QueueDispatchAtomicityGuard::PINNED_CONNECTIONS は目録の明示接続集合と一致する', function (): void {
+    // ★ drift 防止 (T137)。guard の R1〜R3 は「既定接続 ∪ pin 済み接続」を検査対象にするが、
+    //   guard 側の定数は静的リテラルなので、新しい pin 済み接続が増えても guard は黙る。
+    //   本目録は全 ShouldQueue クラスの接続を deny-by-default で固定しているため、
+    //   ここに繋げば guard 側の見落としが対称差 0 で検出できる。
+    //
+    //   抽出規則 (実装ごとに意味が揺れないよう固定する):
+    //   「明示接続集合」= QUEUED_JOB_LEASE_INVENTORY の**値**のうち
+    //   (1) null を除外 (= 既定接続の entry)、(2) array_unique、(3) sort で正規化したもの。
+    //   `sync` と既定接続名は含めない (onConnection リテラルで pin された接続だけが対象)。
+    $pinned = array_values(array_unique(array_filter(
+        array_values(QUEUED_JOB_LEASE_INVENTORY),
+        static fn (?string $connection): bool => $connection !== null,
+    )));
+    sort($pinned);
+
+    $guarded = QueueDispatchAtomicityGuard::PINNED_CONNECTIONS;
+    sort($guarded);
+
+    expect($guarded)->toBe(
+        $pinned,
+        'QueueDispatchAtomicityGuard::PINNED_CONNECTIONS が目録の明示接続集合と一致しません。'
+        .'guard が検査しない接続へジョブを pin すると、その接続の driver / DB 接続 / after_commit が'
+        .'起動時検査から漏れます (原子性の前提が黙って崩れる)。',
+    );
+});
+
 test('規則 2: 目録の接続名が config/queue.php に実在する', function (): void {
     $connections = QueueLeaseConfig::databaseConnections();
 
diff --git a/tests/Feature/Billing/AutoRechargeAttemptDispatchAtomicityTest.php b/tests/Feature/Billing/AutoRechargeAttemptDispatchAtomicityTest.php
new file mode 100644
index 0000000..e90b85f
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeAttemptDispatchAtomicityTest.php
@@ -0,0 +1,88 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Jobs\Billing\ExecuteAutoRechargeAttemptJob;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\FakeAutoRechargeGateway;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (attempt 起票 → ExecuteAutoRechargeAttemptJob。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| 旧実装は呼び出し側 (AutoRechargeTriggerJob::handle / reconcile (v)) が起票 tx の
+| 成功後に dispatch していたため「attempt=pending・実行未投入」の窓があり、
+| reconcile (v) の 15 分周期に依存していた。投入は起票と同一 tx へ移す。
+*/
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+/**
+ * 閾値割れの enabled 組織を用意する。
+ *
+ * @return array{Organization, User}
+ */
+function attemptAtomicityContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    /** @var FakeAutoRechargeGateway $gateway */
+    $gateway = app(AutoRechargeGatewayInterface::class);
+    $gateway->withDefaultPaymentMethod();
+    app(AutoRechargeService::class)->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    return [$organization, $owner];
+}
+
+test('attempt 起票と ExecuteAutoRechargeAttemptJob の投入は同一 tx である', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database.after_commit'))->toBeFalse();
+
+    [$organization] = attemptAtomicityContext();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(AutoRechargeService::class)->maybeCreateAttempt($organization),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), ExecuteAutoRechargeAttemptJob::class);
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('起票 tx が rollback すると attempt 行も jobs 行も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = attemptAtomicityContext();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
new file mode 100644
index 0000000..f9202ba
--- /dev/null
+++ b/tests/Feature/Billing/AutoRechargeAttemptUniquenessTest.php
@@ -0,0 +1,117 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Billing\AutoRechargeConsentDto;
+use App\Enums\Billing\AutoRechargeAttemptStatus;
+use App\Models\Billing\TicketAutoRechargeAttempt;
+use App\Models\Organization;
+use App\Models\User;
+use App\Services\Billing\AutoRechargeService;
+use App\Services\Billing\Contracts\AutoRechargeGatewayInterface;
+use Illuminate\Database\QueryException;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Tests\Support\FakeAutoRechargeGateway;
+
+/*
+|--------------------------------------------------------------------------
+| ShouldBeUnique 撤去後の「結果の一回性」 (AG-114 確定 1 / AGENTS.md ドメイン規約 6)
+|--------------------------------------------------------------------------
+|
+| 入口排他 (ShouldBeUnique) は撤去された。一回性を担うのは 3 点:
+|  (1) maybeCreateAttempt の organizations 行ロック + pending 存在検査
+|  (2) tar_attempts_org_pending_unique (partial unique) — DB の最終防衛
+|  (3) unique violation の no-op 収束 (呼び出し側へ例外を漏らさない)
+|
+| ★ (3) の判定 (isUniqueViolation) は SQLSTATE だけを見て制約名を識別しない。
+|   これは本 PR で作った問題ではなく、docs/TODO.md へ Low で追跡起票済みである。
+*/
+
+beforeEach(function (): void {
+    $this->gateway = new FakeAutoRechargeGateway;
+    app()->instance(AutoRechargeGatewayInterface::class, $this->gateway);
+});
+
+/**
+ * 閾値割れ + enabled な組織。
+ *
+ * @return array{Organization, User}
+ */
+function attemptUniquenessContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    /** @var FakeAutoRechargeGateway $gateway */
+    $gateway = app(AutoRechargeGatewayInterface::class);
+    $gateway->withDefaultPaymentMethod();
+    app(AutoRechargeService::class)->updateSettings(
+        $organization,
+        $owner,
+        enabled: true,
+        threshold: 5,
+        max: 50,
+        consent: new AutoRechargeConsentDto(config()->string('billing.auto_recharge.consent_version')),
+    );
+
+    return [$organization, $owner];
+}
+
+test('pending attempt があるとき maybeCreateAttempt は null を返し attempt が増えない', function (): void {
+    [$organization] = attemptUniquenessContext();
+
+    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+    expect($first)->not->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+
+    $second = app(AutoRechargeService::class)->maybeCreateAttempt($organization->refresh());
+
+    expect($second)->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+});
+
+test('同一 org の 2 件目の pending 行は tar_attempts_org_pending_unique が拒否する', function (): void {
+    [$organization] = attemptUniquenessContext();
+    $first = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+    expect($first)->not->toBeNull();
+
+    // pending 検査を迂回して直接 INSERT する = DB 制約が最終防衛であることの固定。
+    // ★ PostgreSQL は失敗した文でトランザクション全体を abort させるため、
+    //   savepoint (ネストした DB::transaction) の中で起こして外側を巻き込まない。
+    expect(fn () => DB::transaction(fn () => DB::table('ticket_auto_recharge_attempts')->insert([
+        'organization_id' => $organization->getKey(),
+        'attempt_ulid' => strtolower((string) Str::ulid()),
+        'status' => AutoRechargeAttemptStatus::Pending->value,
+        'quantity' => 10,
+        'unit_amount' => 70,
+        'stripe_price_id' => 'price_test',
+        'created_at' => now(),
+        'updated_at' => now(),
+    ])))->toThrow(QueryException::class);
+
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(1);
+});
+
+test('unique violation は no-op へ収束し呼び出し側へ例外が漏れない', function (): void {
+    [$organization] = attemptUniquenessContext();
+
+    // pending 検査の**後**・INSERT の**直前**に別経路で pending 行が生まれた状況
+    // (= 並行起票の敗者側) を模す。DB::table は model event を発火しないため再入しない。
+    TicketAutoRechargeAttempt::creating(function () use ($organization): void {
+        DB::table('ticket_auto_recharge_attempts')->insert([
+            'organization_id' => $organization->getKey(),
+            'attempt_ulid' => strtolower((string) Str::ulid()),
+            'status' => AutoRechargeAttemptStatus::Pending->value,
+            'quantity' => 10,
+            'unit_amount' => 70,
+            'stripe_price_id' => 'price_race',
+            'created_at' => now(),
+            'updated_at' => now(),
+        ]);
+    });
+
+    $result = app(AutoRechargeService::class)->maybeCreateAttempt($organization);
+
+    // 例外は漏れず null に収束し、tx ごと巻き戻るため attempt 行も残らない
+    expect($result)->toBeNull();
+    expect(TicketAutoRechargeAttempt::query()->count())->toBe(0);
+});
diff --git a/tests/Feature/Billing/AutoRechargeTriggerTest.php b/tests/Feature/Billing/AutoRechargeTriggerTest.php
index 117e8b6..8c1f745 100644
--- a/tests/Feature/Billing/AutoRechargeTriggerTest.php
+++ b/tests/Feature/Billing/AutoRechargeTriggerTest.php
@@ -73,10 +73,21 @@
     Queue::assertNotPushed(AutoRechargeTriggerJob::class);
 });
 
-test('reserve が rollback したら dispatch されない (afterCommit の保証)', function (): void {
-    Queue::fake();
+/**
+ * 【契約の反転 (AG-114 確定 1 / T137)】
+ * - 旧主張: reserve が rollback したら dispatch されない (afterCommit の保証)
+ * - 旧目的: rollback した予約でオートリチャージを起票させない
+ * - 新主張: reserve が rollback したら **jobs 行ごと巻き戻る** (業務 tx への相乗り)
+ * - 新前提: キュー投入が業務 tx の内側で行われ、jobs 行が同一トランザクションに乗る
+ * - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + TicketReserveDispatchAtomicityTest
+ * - 反転根拠: Queue::fake は enqueueUsing を経由せず即時記録するため、この主張を fake では
+ *   検証できない (偽グリーンになる)。実 jobs 表の観測へ切り替える
+ */
+test('reserve が rollback したら jobs 行が残らない (業務 tx への相乗り)', function (): void {
+    config()->set('queue.default', 'database');
     [$organization] = createOrganizationWithOwner();
     app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+    $before = DB::table('jobs')->count();
 
     try {
         DB::transaction(function () use ($organization): void {
@@ -88,7 +99,7 @@
         // 期待どおり
     }
 
-    Queue::assertNotPushed(AutoRechargeTriggerJob::class);
+    expect(DB::table('jobs')->count())->toBe($before);
 });
 
 test('amount ベース reserve (可変コスト) が壊れていない', function (): void {
diff --git a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
index 6ac59a1..137748d 100644
--- a/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
+++ b/tests/Feature/Billing/BillingCustomerSynchronizerTest.php
@@ -12,7 +12,7 @@
 /*
  * BillingCustomerSynchronizer: Stripe customer 同期 job の dispatch を集約する単一窓口。
  * - Stripe customer 未作成 (stripe_id === null) は no-op (例外にしない)
- * - dispatch は afterCommit (transaction rollback では発火しない)
+ * - dispatch は業務 tx の内側 (jobs 行が同一 tx に乗り、rollback では jobs 行ごと巻き戻る)
  */
 
 function synchronizer(): BillingCustomerSynchronizer
@@ -43,13 +43,17 @@ function synchronizer(): BillingCustomerSynchronizer
     );
 });
 
-/*
- * IV-3 (commit 前の stale read を防ぐ) の固定。job が afterCommit フラグを立てて積まれることを
- * 検証する。「rollback では発火しない」という実挙動そのものは Queue::fake では観測できない
- * (QueueFake は afterCommit を解決する Queue::enqueueUsing を経由せず即時記録するため)。
- * afterCommit フラグ = 実 queue driver における「outer commit 後に発火」の唯一の入力。
+/**
+ * 【契約の反転 (AG-114 確定 1 / T137)】
+ * - 旧主張: dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)
+ * - 旧目的: commit 前の値を Stripe へ送る stale read を防ぐ (IV-3)
+ * - 新主張: job は afterCommit フラグを**持たない**。投入は業務 tx に乗る
+ * - 新前提: jobs 行が業務 tx と同一トランザクションにあるため、可視化は commit 後になる
+ * - 前提を守る機構: QueueDispatchAtomicityGuard (R1〜R3) + QueueDispatchAtomicityInventoryTest (D1)
+ * - 反転根拠: afterCommit は「commit したのに未投入」の窓を残す。job は organization を
+ *   ID で直列化して handle 時に再取得するため、IV-3 は afterCommit なしで保たれる
  */
-test('dispatch した job は afterCommit フラグを持つ (outer commit 後に発火する)', function (): void {
+test('dispatch した job は afterCommit フラグを持たない (業務 tx に乗る)', function (): void {
     Queue::fake();
     [$organization] = createOrganizationWithOwner();
     $organization->forceFill(['stripe_id' => 'cus_test_2'])->save();
@@ -58,10 +62,44 @@ function synchronizer(): BillingCustomerSynchronizer
 
     Queue::assertPushed(
         SyncBillingCustomerDetails::class,
-        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit === true,
+        fn (SyncBillingCustomerDetails $job): bool => $job->afterCommit !== true,
     );
 });
 
+/**
+ * 実挙動 (jobs 行が業務 tx に乗ること) は **実 jobs 表**で観測する。
+ * `Queue::fake()` では検証できない (QueueFake::push は enqueueUsing を通らない)。
+ */
+test('外側 tx が rollback すると jobs 行が残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_rollback'])->save();
+    $before = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            synchronizer()->dispatchFor($organization);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(DB::table('jobs')->count())->toBe($before);
+});
+
+test('業務 tx の内側で dispatch した job は commit 後に jobs 行として残る', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_test_commit'])->save();
+    $before = DB::table('jobs')->count();
+
+    DB::transaction(fn () => synchronizer()->dispatchFor($organization));
+
+    expect(DB::table('jobs')->count())->toBe($before + 1);
+});
+
 test('job は StripeGatewayInterface へ委譲する (fake bind 時は実 Stripe を叩かない)', function (): void {
     [$organization] = createOrganizationWithOwner();
     $organization->forceFill(['stripe_id' => 'cus_test_3'])->save();
diff --git a/tests/Feature/Billing/StripeWebhookDispatchAtomicityTest.php b/tests/Feature/Billing/StripeWebhookDispatchAtomicityTest.php
new file mode 100644
index 0000000..b4355dd
--- /dev/null
+++ b/tests/Feature/Billing/StripeWebhookDispatchAtomicityTest.php
@@ -0,0 +1,107 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Billing\SignupFundingChoice;
+use App\Enums\CheckoutSessionStatus;
+use App\Jobs\Billing\ReuseSubscriptionPaymentMethodJob;
+use App\Jobs\Billing\SetDefaultPaymentMethodJob;
+use App\Models\Billing\BillingCheckoutSession;
+use App\Models\Organization;
+use App\Services\Billing\StripeWebhookProcessor;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Str;
+use Laravel\Cashier\Events\WebhookReceived;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (Stripe webhook の save + dispatch。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| 打刻 / 台帳更新だけが残って job が投入されない状態は「表示と実態の食い違い」になる。
+| 同一 tx に括り、tx level 観測 (baseline + 1 以上) で固定する。
+*/
+
+/** @param array<string, mixed> $payload */
+function webhookAtomicityDispatch(array $payload): void
+{
+    app(StripeWebhookProcessor::class)->handle(new WebhookReceived($payload));
+}
+
+test('checkout.session.completed (funding=auto_recharge) の打刻と PM 流用 job 投入は同一 tx である', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database.after_commit'))->toBeFalse();
+
+    [$organization, $owner] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_wh_atomicity_1'])->save();
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->initiatedBy((int) $owner->id)
+        ->withAttempt((string) Str::ulid(), 'standard')
+        ->create([
+            'stripe_session_id' => 'cs_wh_atomicity_1',
+            'funding_choice' => SignupFundingChoice::AutoRecharge->value,
+        ]);
+
+    $payload = [
+        'id' => 'evt_wh_atomicity_1',
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => [
+            'id' => 'cs_wh_atomicity_1',
+            'mode' => 'subscription',
+            'customer' => 'cus_wh_atomicity_1',
+            'payment_status' => 'paid',
+            'subscription' => 'sub_wh_atomicity_1',
+            'metadata' => [
+                'purpose' => 'subscription_start',
+                'org_ref' => (string) $organization->id,
+                'plan_code' => 'standard',
+            ],
+        ]],
+    ];
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => webhookAtomicityDispatch($payload),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), ReuseSubscriptionPaymentMethodJob::class);
+
+    expect($session->refresh()->pm_reuse_dispatched_at)->not->toBeNull();
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('auto_recharge_setup 完了の台帳更新と PM 既定設定 job 投入は同一 tx である', function (): void {
+    config()->set('queue.default', 'database');
+
+    /** @var Organization $organization */
+    [$organization] = createOrganizationWithOwner();
+    $organization->forceFill(['stripe_id' => 'cus_wh_atomicity_2'])->save();
+    $session = BillingCheckoutSession::factory()
+        ->for($organization)
+        ->setupPaymentMethod()
+        ->create(['stripe_session_id' => 'cs_wh_atomicity_2']);
+
+    $payload = [
+        'id' => 'evt_wh_atomicity_2',
+        'type' => 'checkout.session.completed',
+        'data' => ['object' => [
+            'id' => 'cs_wh_atomicity_2',
+            'mode' => 'setup',
+            'customer' => 'cus_wh_atomicity_2',
+            'setup_intent' => 'seti_wh_atomicity_2',
+            'metadata' => ['purpose' => 'auto_recharge_setup'],
+        ]],
+    ];
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => webhookAtomicityDispatch($payload),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), SetDefaultPaymentMethodJob::class);
+
+    expect($session->refresh()->status)->toBe(CheckoutSessionStatus::Completed->value);
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
diff --git a/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php b/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php
new file mode 100644
index 0000000..07601f1
--- /dev/null
+++ b/tests/Feature/Billing/TicketLowBalanceNotificationIsolationTest.php
@@ -0,0 +1,48 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Billing\TicketReservation;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Notifications\Channels\DatabaseChannel;
+use Illuminate\Notifications\Notification;
+use Illuminate\Support\Facades\Log;
+
+/*
+|--------------------------------------------------------------------------
+| 低残高通知の隔離 (AG-127 の付随的副作用)
+|--------------------------------------------------------------------------
+|
+| 通知は reserve の tx を抜けた最後に同期実行される。**通知チャネルの例外で reserve を
+| 巻き戻さない**ことを固定する (NotificationCenterService::safely() 本体を通す =
+| サービス全体を mock しない)。
+|
+| ★ 保証範囲を誇張しない: ここが保証するのは**アプリケーション層の例外分離だけ**である。
+|   reserve が呼び出し側の tx にネストされている場合、通知 INSERT の SQL 層の失敗は
+|   PostgreSQL の transaction abort を経て業務操作ごと失敗させうる (設計 §保証しないもの 6)。
+*/
+
+/** database channel を必ず throw する fake へ差し替える。 */
+final class ThrowingDatabaseChannel extends DatabaseChannel
+{
+    public function send($notifiable, Notification $notification): void
+    {
+        throw new RuntimeException('通知チャネルの意図的な失敗');
+    }
+}
+
+test('通知チャネルが例外を投げても reserve は成功し予約行が残る', function (): void {
+    Log::spy();
+    config()->set('billing.ticket_low_balance_threshold', 5);
+    app()->bind(DatabaseChannel::class, ThrowingDatabaseChannel::class);
+
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    // 10 → 4 で閾値 5 を跨ぐ = 通知が走る (そして必ず throw する)
+    $reservation = app(TicketLedgerService::class)->reserve($organization, 6);
+
+    expect($reservation->amount)->toBe(6);
+    expect(TicketReservation::query()->whereKey($reservation->getKey())->exists())->toBeTrue();
+    expect(app(TicketLedgerService::class)->availableTrueBalance($organization))->toBe(4);
+});
diff --git a/tests/Feature/Billing/TicketReserveDispatchAtomicityTest.php b/tests/Feature/Billing/TicketReserveDispatchAtomicityTest.php
new file mode 100644
index 0000000..ebaeb1c
--- /dev/null
+++ b/tests/Feature/Billing/TicketReserveDispatchAtomicityTest.php
@@ -0,0 +1,56 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Models\Billing\TicketReservation;
+use App\Services\Billing\TicketLedgerService;
+use Illuminate\Support\Facades\DB;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (reserve → AutoRechargeTriggerJob。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| ★ 本ファイルは **reserve() 内の経路専用**である。
+|   AutoRechargeAttemptDispatchAtomicityTest は createAttemptLocked() 内の**別ジョブ**
+|   (ExecuteAutoRechargeAttemptJob) を見ているため、この経路の変異を検出できない。
+*/
+
+test('reserve の AutoRechargeTriggerJob は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database.after_commit'))->toBeFalse();
+
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(TicketLedgerService::class)->reserve($organization, 1),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('reserve が rollback すると予約行も jobs 行も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$organization] = createOrganizationWithOwner();
+    app(TicketLedgerService::class)->grant($organization, 10, '初期付与');
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($organization): void {
+            app(TicketLedgerService::class)->reserve($organization, 1);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(TicketReservation::query()->count())->toBe(0);
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php b/tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php
new file mode 100644
index 0000000..d8b7f75
--- /dev/null
+++ b/tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php
@@ -0,0 +1,76 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Capture\CaptureTakeService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Storage;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (テイク削除経路。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助である。
+| 保証するのは「take 行を消したのに削除 job が投入されない窓」の解消だけで、
+| worker 停止 / job 失敗 / ストレージ失敗ではオブジェクトは残る (誇張しない)。
+*/
+
+/**
+ * 削除対象テイク一式 (S3 パスを持つ take)。
+ *
+ * @return array{Project, VideoManual, Cut, Take}
+ */
+function takeDeletionContext(): array
+{
+    Storage::fake();
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create();
+
+    return [$project, $manual, $cut, $take];
+}
+
+test('テイク削除の DeleteTakeObjectsJob は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();
+
+    [$project, $manual, $cut, $take] = takeDeletionContext();
+    expect($take->video_path)->not->toBeNull();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(CaptureTakeService::class)->delete($project, $manual, $cut, $take),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteTakeObjectsJob::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('テイク削除の外側 tx が rollback すると take 行も削除 job も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$project, $manual, $cut, $take] = takeDeletionContext();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($project, $manual, $cut, $take): void {
+            app(CaptureTakeService::class)->delete($project, $manual, $cut, $take);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(Take::query()->whereKey($take->id)->exists())->toBeTrue();
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Manual/QueueDispatchAtomicityTest.php b/tests/Feature/Manual/QueueDispatchAtomicityTest.php
new file mode 100644
index 0000000..7046247
--- /dev/null
+++ b/tests/Feature/Manual/QueueDispatchAtomicityTest.php
@@ -0,0 +1,158 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Jobs\Manual\RunManualAnalysis;
+use App\Jobs\Manual\RunManualRender;
+use App\Models\AnalysisJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\SourceDocument;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\AnalysisJobService;
+use App\Services\Manual\RenderJobService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Storage;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (Manual 経路。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| **主契約は tx level 観測**である。action 直前の DB::transactionLevel() を baseline とし、
+| 対象ジョブで filter した JobQueueing の level が baseline + 1 以上であることを見る。
+|
+| ★ rollback テストは**補助**である。旧実装 (service 内 tx の commit 後に dispatch) でも
+|   テストが外側 tx で包めば jobs 行は rollback で消えるため、移設の検出には使えない。
+| ★ `Queue::fake()` は使わない (QueueFake::push は enqueueUsing を通らず原子性を観測できない)。
+| ★ 観測の前提: 対象ジョブの **pin 先接続** が after_commit=false であること
+|   (`queue.default='database'` は onConnection で pin されたジョブには効かない)。
+*/
+
+/**
+ * 解析トリガ可能な manual 一式 (draft + SOP + 残高)。
+ *
+ * @return array{Project, VideoManual}
+ */
+function atomicityAnalyzableManual(): array
+{
+    Storage::fake();
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => VideoManualStatus::Draft->value]);
+    SourceDocument::factory()->forManual($manual)->create();
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');
+
+    return [$project, $manual];
+}
+
+/**
+ * レンダトリガ可能な manual 一式 (ready + cut + 採用テイク + 残高)。
+ *
+ * @return array{Project, VideoManual}
+ */
+function atomicityRenderableManual(): array
+{
+    Storage::fake();
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create(['status' => VideoManualStatus::Ready->value]);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    app(TicketLedgerService::class)->grant($organization, 10, 'テスト残高');
+
+    return [$project, $manual];
+}
+
+test('解析トリガの RunManualAnalysis は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-analysis.after_commit'))->toBeFalse();
+
+    [$project, $manual] = atomicityAnalyzableManual();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(AnalysisJobService::class)->trigger($project, $manual),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualAnalysis::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('レンダトリガの RunManualRender は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-render.after_commit'))->toBeFalse();
+
+    [$project, $manual] = atomicityRenderableManual();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(RenderJobService::class)->trigger($project, $manual),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualRender::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('プレビュートリガの RunManualRender は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-render.after_commit'))->toBeFalse();
+
+    [$project, $manual] = atomicityRenderableManual();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(RenderJobService::class)->triggerPreview($project, $manual),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), RunManualRender::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('外側 tx が rollback すると analysis_jobs も jobs 行も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$project, $manual] = atomicityAnalyzableManual();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($project, $manual): void {
+            app(AnalysisJobService::class)->trigger($project, $manual);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(AnalysisJob::query()->count())->toBe(0);
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
+
+test('外側 tx が rollback すると render_jobs も jobs 行も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$project, $manual] = atomicityRenderableManual();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($project, $manual): void {
+            app(RenderJobService::class)->trigger($project, $manual);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(RenderJob::query()->where('status', JobStatus::Queued->value)->count())->toBe(0);
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Manual/RenderFinalizeQueueAtomicityTest.php b/tests/Feature/Manual/RenderFinalizeQueueAtomicityTest.php
new file mode 100644
index 0000000..fd9d6f1
--- /dev/null
+++ b/tests/Feature/Manual/RenderFinalizeQueueAtomicityTest.php
@@ -0,0 +1,101 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\Render\ComposedLocalVideo;
+use App\DataTransferObjects\Manual\Render\RenderManifest;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\VideoManualStatus;
+use App\Jobs\Manual\DeleteRenderOutputsJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\RenderJobService;
+use App\Services\Manual\RenderPipeline;
+use App\Services\Render\VideoComposer;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Storage;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (RenderPipeline::finalize の世代交代削除。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| 主契約は tx level 観測 (baseline + 1 以上)。`Queue::fake()` は使わない
+| (QueueFake::push は enqueueUsing を通らず原子性を観測できない)。
+| 削除 job は冪等のため重複無害で、喪失時の回収役 (render:reconcile-outputs) は
+| 別要因 (worker 異常終了) のために残す。
+*/
+
+/** finalize テスト専用の fake composer (実 ffmpeg に触れない)。 */
+final class FinalizeAtomicityComposer implements VideoComposer
+{
+    public function compose(RenderManifest $manifest, array $localSources, string $workDir, callable $onClipComposed): ComposedLocalVideo
+    {
+        $durations = [];
+        foreach ($manifest->clips as $index => $clip) {
+            $durations[$clip->cutId] = 1_000 * ($index + 1);
+            $onClipComposed($index + 1, count($manifest->clips));
+        }
+        $localPath = "{$workDir}/output.mp4";
+        file_put_contents($localPath, 'fake-mp4');
+
+        return new ComposedLocalVideo($localPath, $durations, (int) array_sum($durations));
+    }
+}
+
+/**
+ * 世代交代を起こせる render 一式 (1 世代目 succeeded 済み・2 世代目 trigger 済み)。
+ *
+ * @return array{Project, VideoManual, RenderJob, RenderJob}
+ */
+function finalizeAtomicityContext(): array
+{
+    Storage::fake('s3');
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'scenario_version' => 2,
+    ]);
+    $cut = Cut::factory()->forManual($manual)->create();
+    $take = Take::factory()->forCut($cut)->create(['duration_ms' => 5_000]);
+    $cut->forceFill(['adopted_take_id' => $take->id])->save();
+    Storage::disk('s3')->put($take->video_path, 'fake-take-video');
+    app(TicketLedgerService::class)->grant($organization, 6, 'テスト残高');
+    app()->instance(VideoComposer::class, new FinalizeAtomicityComposer);
+
+    $first = app(RenderJobService::class)->trigger($project, $manual);
+    app(RenderPipeline::class)->run($first->id);
+    expect($first->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    $manual->refresh()->forceFill([
+        'status' => VideoManualStatus::Ready,
+        'scenario_version' => 3,
+    ])->save();
+    $second = app(RenderJobService::class)->trigger($project, $manual);
+
+    return [$project, $manual, $first, $second];
+}
+
+test('finalize の DeleteRenderOutputsJob は terminal tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();
+
+    [, , $first, $second] = finalizeAtomicityContext();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(RenderPipeline::class)->run($second->id),
+    );
+    expect($second->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteRenderOutputsJob::class);
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+    expect($first->refresh()->status)->toBe(JobStatus::Succeeded);
+});
diff --git a/tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php b/tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php
new file mode 100644
index 0000000..94e5dd1
--- /dev/null
+++ b/tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php
@@ -0,0 +1,75 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Capture\DeleteTakeObjectsJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Manual\VideoManualService;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Storage;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| キュー投入の原子性 (マニュアル削除経路。AG-114 確定 1)
+|--------------------------------------------------------------------------
+|
+| 主契約は tx level 観測 (baseline + 1 以上)。rollback テストは補助である。
+*/
+
+/**
+ * 削除対象マニュアル一式 (take と source document の S3 キーを持つ)。
+ *
+ * @return array{Project, VideoManual}
+ */
+function manualDeletionContext(): array
+{
+    Storage::fake();
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $cut = Cut::factory()->forManual($manual)->create();
+    Take::factory()->forCut($cut)->create();
+    SourceDocument::factory()->forManual($manual)->create();
+
+    return [$project, $manual];
+}
+
+test('マニュアル削除の DeleteTakeObjectsJob は業務 tx の内側で投入される', function (): void {
+    config()->set('queue.default', 'database');
+    expect(config('queue.connections.database-media.after_commit'))->toBeFalse();
+
+    [$project, $manual] = manualDeletionContext();
+
+    $baseline = DB::transactionLevel();
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => app(VideoManualService::class)->delete($project, $manual),
+    );
+    $target = RecordsJobQueueingTransactionLevel::only($collector->all(), DeleteTakeObjectsJob::class);
+
+    expect($target)->toHaveCount(1);
+    expect($target[0]['level'])->toBeGreaterThanOrEqual($baseline + 1);
+});
+
+test('マニュアル削除の外側 tx が rollback すると manual 行も削除 job も残らない', function (): void {
+    config()->set('queue.default', 'database');
+    [$project, $manual] = manualDeletionContext();
+    $jobsBefore = DB::table('jobs')->count();
+
+    try {
+        DB::transaction(function () use ($project, $manual): void {
+            app(VideoManualService::class)->delete($project, $manual);
+
+            throw new RuntimeException('意図的な rollback');
+        });
+    } catch (RuntimeException) {
+        // 期待どおり
+    }
+
+    expect(VideoManual::query()->whereKey($manual->id)->exists())->toBeTrue();
+    expect(DB::table('jobs')->count())->toBe($jobsBefore);
+});
diff --git a/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
index feae86e..f085f0f 100644
--- a/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
+++ b/tests/Feature/Notifications/TicketBalanceLowNotificationTest.php
@@ -15,7 +15,7 @@
  * - 既に閾値未満でさらに reserve → 通知されない (クロスのみ)
  * - 複数 pending 予約: 跨いだ 2 件目の reserve でのみ発火。その後の commit (順序不問) は追加なし
  * - release で閾値以上へ回復 → 再度跨ぐ reserve で再通知される
- * - rollback される外側 tx 内の reserve → 通知されない (afterCommit)
+ * - rollback される外側 tx 内の reserve → 通知されない (通知行が外側 tx に乗るため)
  */
 
 beforeEach(function (): void {
@@ -101,7 +101,19 @@ function lowBalanceNotificationCountFor(User $user): int
     expect(lowBalanceNotificationCountFor($owner))->toBe(2);
 });
 
-test('rollback される外側 tx 内の reserve は通知されない (afterCommit)', function (): void {
+/**
+ * 【契約の反転 (AG-114 確定 1 / T137) — 本体は変わらず緑のままだが根拠が変わった】
+ * - 旧主張: 通知は DB::afterCommit へ退避され、外側 tx の rollback では発火しない
+ * - 旧目的: reserve が pipeline の startJob tx 内から savepoint で呼ばれ得るため、
+ *   最外層 commit 成立後にのみ通知する
+ * - 新主張: 通知は tx を抜けた最後に同期実行する。外側 tx の内側なら通知行も一緒に巻き戻る
+ * - 新前提: TicketBalanceLowNotification は ShouldQueue ではない同期 DB 書き込みであり、
+ *   通知行そのものが外側 tx に乗る
+ * - 前提を守る機構: QueueDispatchAtomicityInventoryTest (D2 = DB::afterCommit の 0 件 pin)
+ * - 反転根拠: DB::afterCommit は「commit したのに未投入」の窓を作る機構であり、AG-127 の
+ *   付随的副作用は「tx の外へ出す」であって「afterCommit で温存する」ではない
+ */
+test('rollback される外側 tx 内の reserve は通知されない (通知行が外側 tx に乗る)', function (): void {
     [$organization, $owner] = balanceLowContext(tickets: 10);
 
     try {
diff --git a/tests/Feature/Support/Queue/RecordsJobQueueingTransactionLevelTest.php b/tests/Feature/Support/Queue/RecordsJobQueueingTransactionLevelTest.php
new file mode 100644
index 0000000..49ab1ad
--- /dev/null
+++ b/tests/Feature/Support/Queue/RecordsJobQueueingTransactionLevelTest.php
@@ -0,0 +1,98 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Jobs\Billing\AutoRechargeTriggerJob;
+use App\Jobs\Billing\SyncBillingCustomerDetails;
+use App\Models\Organization;
+use Illuminate\Queue\Events\JobQueueing;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
+use Tests\Support\Queue\RecordsJobQueueingTransactionLevel;
+
+/*
+|--------------------------------------------------------------------------
+| capture ヘルパ自身の挙動固定 (M9 の観測装置が嘘をつかないこと)
+|--------------------------------------------------------------------------
+|
+| ★ **実 database queue 経由で確認する**。`Event::dispatch()` を直接叩くだけでは
+|   `QueueManager` 経由の発火経路を検証したことにならない。
+| ★ `Queue::fake()` は使わない (QueueFake::push は enqueueUsing を通らない)。
+*/
+
+beforeEach(function (): void {
+    // 実 jobs 表へ積む (ジョブ本体は実行されない = 行が積まれるだけ)
+    config()->set('queue.default', 'database');
+});
+
+test('capture 中は JobQueueing を記録する', function (): void {
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => AutoRechargeTriggerJob::dispatch(1),
+    );
+
+    $records = RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class);
+    expect($records)->toHaveCount(1);
+    expect($records[0]['level'])->toBe(DB::transactionLevel());
+});
+
+test('capture 前から存在する listener は capture 中も capture 後も動く', function (): void {
+    $seen = 0;
+    Event::listen(JobQueueing::class, function () use (&$seen): void {
+        $seen++;
+    });
+
+    RecordsJobQueueingTransactionLevel::capture(static fn () => AutoRechargeTriggerJob::dispatch(1));
+    expect($seen)->toBe(1);
+
+    AutoRechargeTriggerJob::dispatch(2);
+    expect($seen)->toBe(2);
+});
+
+test('capture 後に別ジョブを dispatch しても collector->all() の件数が増えない', function (): void {
+    $collector = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => AutoRechargeTriggerJob::dispatch(1),
+    );
+    expect($collector->all())->toHaveCount(1);
+
+    AutoRechargeTriggerJob::dispatch(2);
+
+    // ★ 同一 collector オブジェクトを capture 前後で比較する
+    //   (配列を返す設計だと copy-on-write でこの検査が空振りする)
+    expect($collector->all())->toHaveCount(1);
+});
+
+test('action が例外を投げても capture は例外を伝播し、直前の collector を汚染しない', function (): void {
+    // ★ 保証範囲を誇張しない: 例外経路で戻り値 (collector) は呼び出し側へ渡らないため、
+    //   「その collector に記録が増えないこと」は**外から観測できない** (finally の不活性化は
+    //   メモリ上の効果に留まる)。ここで観測できるのは (a) 例外がそのまま伝播すること、
+    //   (b) 例外を投げた capture が**先行 capture の collector を汚染しない**ことの 2 点である。
+    //   finally 削除の変異 (#18) を赤くするのは「capture 後に増えない」テストの方である。
+    $first = RecordsJobQueueingTransactionLevel::capture(
+        static fn () => AutoRechargeTriggerJob::dispatch(1),
+    );
+
+    expect(function (): void {
+        RecordsJobQueueingTransactionLevel::capture(static function (): void {
+            AutoRechargeTriggerJob::dispatch(2);
+
+            throw new RuntimeException('意図的な失敗');
+        });
+    })->toThrow(RuntimeException::class, '意図的な失敗');
+
+    expect($first->all())->toHaveCount(1);
+});
+
+test('only() は対象ジョブクラスの記録だけを返す', function (): void {
+    $organization = Organization::factory()->create(['stripe_id' => 'cus_only_test']);
+
+    $collector = RecordsJobQueueingTransactionLevel::capture(static function () use ($organization): void {
+        AutoRechargeTriggerJob::dispatch(1);
+        SyncBillingCustomerDetails::dispatch($organization);
+    });
+
+    expect($collector->all())->toHaveCount(2);
+    expect(RecordsJobQueueingTransactionLevel::only($collector->all(), AutoRechargeTriggerJob::class))
+        ->toHaveCount(1);
+    expect(RecordsJobQueueingTransactionLevel::only($collector->all(), SyncBillingCustomerDetails::class))
+        ->toHaveCount(1);
+});
diff --git a/tests/Feature/Support/QueueDispatchAtomicityGuardBootTest.php b/tests/Feature/Support/QueueDispatchAtomicityGuardBootTest.php
new file mode 100644
index 0000000..463cbd1
--- /dev/null
+++ b/tests/Feature/Support/QueueDispatchAtomicityGuardBootTest.php
@@ -0,0 +1,52 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Providers\AppServiceProvider;
+use App\Support\QueueDispatchAtomicityGuard;
+
+/*
+|--------------------------------------------------------------------------
+| guard の boot 配線 (AG-114 確定 2)
+|--------------------------------------------------------------------------
+|
+| guard 単体の判定は QueueDispatchAtomicityGuardTest が固定する。ここで固定するのは
+| **AppServiceProvider::boot() が実際に guard を通ること** と、config:cache 相当
+| (config/queue.php の値をそのまま配列で読む状態) でも判定が変わらないことである。
+|
+| ★ guard の呼び出しは boot() の冒頭にあるため、違反構成では boot() の残りは実行されない
+|   (= 本テストが provider の他の副作用を二重に走らせることはない)。
+*/
+
+test('AppServiceProvider::boot() から guard が呼ばれている', function (): void {
+    // container 解決であることの固定 (差し替えた double が呼ばれる = boot が make している)
+    app()->instance(QueueDispatchAtomicityGuard::class, new class extends QueueDispatchAtomicityGuard
+    {
+        public function enforce(bool $isProduction): void
+        {
+            throw new RuntimeException('guard-called:'.var_export($isProduction, true));
+        }
+    });
+
+    expect(fn () => (new AppServiceProvider(app()))->boot())
+        ->toThrow(RuntimeException::class, 'guard-called:false');
+});
+
+test('違反構成では boot() が fail-closed で止まる', function (): void {
+    config()->set('queue.connections.sync', ['driver' => 'sync']); // after_commit 欠落 = R4 違反
+
+    expect(fn () => (new AppServiceProvider(app()))->boot())
+        ->toThrow(RuntimeException::class, 'Queue dispatch atomicity violations');
+});
+
+test('config:cache 相当 (config を配列から直接読む状態) でも判定が変わらない', function (): void {
+    // config:cache は config/*.php の評価結果 (env() 解決済み) を配列として凍結する。
+    // 凍結された値そのものを guard へ食わせ、実行時 config と同じく違反 0 件になることを固定する。
+    $queue = require config_path('queue.php');
+    expect($queue)->toBeArray();
+
+    config()->set('queue.default', $queue['default']);
+    config()->set('queue.connections', $queue['connections']);
+
+    expect((new QueueDispatchAtomicityGuard)->violations(false))->toBe([]);
+});
diff --git a/tests/Support/Queue/JobQueueingTransactionRecords.php b/tests/Support/Queue/JobQueueingTransactionRecords.php
new file mode 100644
index 0000000..35b2338
--- /dev/null
+++ b/tests/Support/Queue/JobQueueingTransactionRecords.php
@@ -0,0 +1,40 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+/**
+ * `RecordsJobQueueingTransactionLevel::capture()` の記録先 (可変 collector)。
+ * **テスト Support 内部だけの機構**である。
+ *
+ * ★ 配列で返すと PHP の copy-on-write により「capture 後に記録が増えないこと」を
+ *   検査できない (不活性化を消しても呼び出し側の配列は増えず緑のままになる)。
+ *   同一オブジェクトを参照させることで、その自己テストが実効を持つ。
+ * ★ 保持するのは **クラス名 (string) と深さ (int) だけ**で job payload は持たない
+ *   (不活性 listener がテスト終了まで生き残るため)。
+ *
+ * ★ PSR-4 の 1 ファイル 1 クラス規約に従い `RecordsJobQueueingTransactionLevel` とは
+ *   別ファイルに置く (詳細設計では 1 ファイルに併記されていた)。
+ */
+final class JobQueueingTransactionRecords
+{
+    /** @var list<array{job: string, level: int}> */
+    private array $records = [];
+
+    /** capture 終了後に false へ倒され、以降の記録を捨てる (listener の不活性化)。 */
+    public bool $active = true;
+
+    public function record(string $job, int $level): void
+    {
+        if ($this->active) {
+            $this->records[] = ['job' => $job, 'level' => $level];
+        }
+    }
+
+    /** @return list<array{job: string, level: int}> */
+    public function all(): array
+    {
+        return $this->records;
+    }
+}
diff --git a/tests/Support/Queue/QueueDispatchDeferralInventory.php b/tests/Support/Queue/QueueDispatchDeferralInventory.php
new file mode 100644
index 0000000..c660884
--- /dev/null
+++ b/tests/Support/Queue/QueueDispatchDeferralInventory.php
@@ -0,0 +1,338 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use FilesystemIterator;
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
+ * - D5 `Queueable` の `$afterCommit` プロパティ … **既定値はリフレクション** +
+ *   **実行時代入は token 走査**。`public bool $afterCommit = true;` /
+ *   `$this->afterCommit = true;` は **D1〜D4 のどれにも映らない第 3 の迂回路**であり、
+ *   これを落とすと「0 件 pin」の主張が嘘になる
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
+     * D5 (既定値): `$afterCommit` プロパティの default が `true` のクラス。
+     * `ReflectionClass::getDefaultProperties()` を使う (**インスタンス化しない**ので、
+     * コンストラクタ引数が必要な job でも判定できる)。
+     *
+     * ★ 判定は **`=== true` の厳密比較**である。`Queueable` trait の既定値は `null` であり、
+     *   `null` を truthy 側へ落とすと全 job が偽陽性になる。
+     *
+     * @param  list<class-string>  $classes
+     * @return list<class-string>
+     */
+    public static function detectAfterCommitProperty(array $classes): array
+    {
+        $hits = [];
+        foreach ($classes as $class) {
+            $reflection = new ReflectionClass($class);
+            $defaults = $reflection->getDefaultProperties();
+            if (($defaults['afterCommit'] ?? null) === true) {
+                $hits[] = $reflection->getName();
+            }
+        }
+
+        return $hits;
+    }
+
+    /**
+     * D5 (実行時代入): `->afterCommit = true` の **token 走査**。
+     * `$this->afterCommit = true;` (自クラス内) と `$job->afterCommit = true;`
+     * (外部からの代入) の**両方**を拾う = 判定は receiver を問わず
+     * `T_OBJECT_OPERATOR` + `afterCommit` + `=` + `true` の並びで行う。
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
+                if ($value === null || strtolower($value['text']) !== 'true') {
+                    continue; // 動的値 (`= $flag`) は検出できない = 0 件 pin の穴 (誇張しない)
+                }
+
+                $hits[] = ['path' => $path, 'line' => $name['line']];
+            }
+        }
+
+        return $hits;
+    }
+}
diff --git a/tests/Support/Queue/RecordsJobQueueingTransactionLevel.php b/tests/Support/Queue/RecordsJobQueueingTransactionLevel.php
new file mode 100644
index 0000000..a5355c6
--- /dev/null
+++ b/tests/Support/Queue/RecordsJobQueueingTransactionLevel.php
@@ -0,0 +1,83 @@
+<?php
+
+declare(strict_types=1);
+
+namespace Tests\Support\Queue;
+
+use Illuminate\Queue\Events\JobQueueing;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Event;
+
+/**
+ * キュー投入時点の DB トランザクション深さを記録するテストヘルパ。
+ *
+ * `Illuminate\Queue\Events\JobQueueing` は `Queue::enqueueUsing()` の内部から発火するため、
+ * 「実際に push が起きた瞬間」の tx level を観測できる。
+ *
+ * ★ **`Queue::fake()` と併用してはならない**。`QueueFake::push()` は `enqueueUsing` を通らず
+ *   即時記録するため、この観測点も after_commit の解決も素通りする
+ *   (BillingCustomerSynchronizerTest の docblock が既に警告している落とし穴)。
+ *
+ * ★ 判定は **action 直前の `DB::transactionLevel()` (baseline) + 1 以上**である。
+ *   固定値 (`>= 2`) では判定しない — ネストの深さはテストの書き方で変わるため。
+ *
+ * ★ 観測の前提: 対象ジョブが使う接続が driver=database かつ **after_commit=false** であること。
+ *   `after_commit=true` の接続では `JobQueueing` が commit 後の callback 内で発火し、
+ *   観測される level が baseline に落ちる。テスト側で前提そのものを assert すること。
+ */
+final class RecordsJobQueueingTransactionLevel
+{
+    /**
+     * `$action` の実行中に発火した `JobQueueing` の tx level を記録する。
+     *
+     * ★ **1 テスト 1 capture**。同一テスト内で複数回呼ぶと listener が重複し記録が混線する。
+     *
+     * ★ listener の隔離は **元 dispatcher に listener を足し、capture 終了後にその closure を
+     *   不活性化する**方式で行う。採らなかった 2 案とその理由:
+     *   - `Event::forget(JobQueueing::class)`: capture 以前から存在した同イベントの listener まで
+     *     削除する。「現時点で grep 0 件」は恒久的な安全性にならない
+     *   - **dispatcher の clone へ swap**: `QueueManager` は解決済みの queue connection を
+     *     キャッシュし、connection は自分が持つ container 経由で event dispatcher を引く。
+     *     swap 前に connection が生成済みなら clone 側の listener が `JobQueueing` を捕捉できず、
+     *     swap 中に生成された connection は capture 後も clone dispatcher を握り続けうる
+     *   不活性化方式なら dispatcher の差し替えも既存 listener の削除も起きない。
+     *   グローバルな application 再生成によりテスト終了時に dispatcher ごと破棄されるため、
+     *   「1 テスト 1 capture」の規約下では不活性 listener はそのテスト中に高々 1 個残るだけである。
+     *
+     * ★ 戻り値は **配列ではなく可変 collector オブジェクト**である (理由は
+     *   `JobQueueingTransactionRecords` の docblock)。
+     */
+    public static function capture(callable $action): JobQueueingTransactionRecords
+    {
+        $collector = new JobQueueingTransactionRecords;
+
+        Event::listen(JobQueueing::class, function (JobQueueing $event) use ($collector): void {
+            $job = $event->job;
+            $collector->record(is_object($job) ? $job::class : (string) $job, DB::transactionLevel());
+        });
+
+        try {
+            $action();
+        } finally {
+            $collector->active = false; // action が例外を投げても必ず不活性化する
+        }
+
+        return $collector;
+    }
+
+    /**
+     * 対象ジョブクラスの記録だけを抜き出す。
+     * action 中に付随ジョブが増えても無関係な理由で壊れないようにするため、
+     * assert は必ずこの filter を通した結果に対して行う。
+     *
+     * @param  list<array{job: string, level: int}>  $records  `$collector->all()` を渡す
+     * @return list<array{job: string, level: int}>
+     */
+    public static function only(array $records, string $jobClass): array
+    {
+        return array_values(array_filter(
+            $records,
+            static fn (array $record): bool => $record['job'] === $jobClass,
+        ));
+    }
+}
diff --git a/tests/Support/QueuedJobPopulation.php b/tests/Support/QueuedJobPopulation.php
index 2a7543e..9b97862 100644
--- a/tests/Support/QueuedJobPopulation.php
+++ b/tests/Support/QueuedJobPopulation.php
@@ -6,6 +6,7 @@
 
 use FilesystemIterator;
 use Illuminate\Contracts\Queue\ShouldQueue;
+use Illuminate\Mail\Mailable;
 use RecursiveDirectoryIterator;
 use RecursiveIteratorIterator;
 use ReflectionClass;
@@ -52,6 +53,47 @@ public static function shouldQueueClasses(): array
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
     /**
      * app/ 配下の PHP ファイル絶対パス一覧。
      *
diff --git a/tests/Support/Security/DirectFetchInventory.php b/tests/Support/Security/DirectFetchInventory.php
index 70b9daa..0bd40ae 100644
--- a/tests/Support/Security/DirectFetchInventory.php
+++ b/tests/Support/Security/DirectFetchInventory.php
@@ -220,9 +220,11 @@ public static function inventory(): array
                 enqueuedBy: 'App\Services\Billing\TicketLedgerService::reserve',
             ),
             'Jobs/Billing/ExecuteAutoRechargeAttemptJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
-                'attempt id は AutoRechargeTriggerJob がサーバ側で作成した attempt 行の主キーであり、'
-                .'client からは指定できない。worker 側は再水和のみ行う',
-                enqueuedBy: 'App\Jobs\Billing\AutoRechargeTriggerJob::handle',
+                'attempt id は AutoRechargeService が起票と同一 tx でサーバ側に作成した attempt 行の'
+                .'主キーであり、client からは指定できない。worker 側は再水和のみ行う',
+                // T137: 投入点が呼び出し側 (AutoRechargeTriggerJob::handle) から起票と同一 tx の
+                // createAttemptLocked へ移った (AG-114 確定 1)。
+                enqueuedBy: 'App\Services\Billing\AutoRechargeService::createAttemptLocked',
             ),
             'Jobs/Billing/HandleAutoRechargeChargeFailureJob.php#handle#TicketAutoRechargeAttempt.find:$this->attemptId#1' => DirectFetchJustificationEntry::queuePayload(
                 'attempt id は署名検証済み Stripe webhook の処理中にサーバが特定した attempt 行の主キーで、'
diff --git a/tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php b/tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php
new file mode 100644
index 0000000..abcb702
--- /dev/null
+++ b/tests/Unit/Support/Queue/QueueDispatchAtomicityGuardTest.php
@@ -0,0 +1,223 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Support\QueueDispatchAtomicityViolation;
+use App\Enums\Support\QueueAtomicityRule;
+use App\Support\QueueDispatchAtomicityGuard;
+
+/*
+|--------------------------------------------------------------------------
+| QueueDispatchAtomicityGuard の規則 R1〜R5 (AG-114 確定 2)
+|--------------------------------------------------------------------------
+|
+| guard は config の値だけを見る純関数である。ここでは `config()->set()` で構成を
+| 組み替え、規則ごとに「違反として報告されること」「例外ではなく違反を返すこと」を固定する。
+|
+| ★ 本番相当の構成 (baseline) を毎テストの起点にする。テストレーンは
+|   phpunit.xml が QUEUE_CONNECTION=sync を force しているため、実行時 config を
+|   そのまま起点にすると R1〜R3 が空振りする。
+*/
+
+/**
+ * 本番相当のキュー構成を config へ流し込む (baseline)。
+ */
+function guardBaselineConfig(): void
+{
+    config()->set('database.default', 'pgsql');
+    config()->set('queue.default', 'database');
+    config()->set('queue.connections', [
+        'sync' => ['driver' => 'sync', 'after_commit' => true],
+        'database' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
+        'database-analysis' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
+        'database-render' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
+        'database-media' => ['driver' => 'database', 'connection' => null, 'after_commit' => false],
+    ]);
+}
+
+/**
+ * 違反の規則 (enum) だけを取り出す。
+ *
+ * @return list<QueueAtomicityRule>
+ */
+function guardViolationRules(bool $isProduction = false): array
+{
+    return array_values(array_map(
+        static fn (QueueDispatchAtomicityViolation $violation): QueueAtomicityRule => $violation->rule,
+        (new QueueDispatchAtomicityGuard)->violations($isProduction),
+    ));
+}
+
+beforeEach(function (): void {
+    guardBaselineConfig();
+});
+
+test('既定構成では違反が 0 件である', function (): void {
+    expect(guardViolationRules())->toBe([]);
+    expect(guardViolationRules(isProduction: true))->toBe([]);
+});
+
+test('R1: 参照接続の driver が redis なら違反する', function (): void {
+    config()->set('queue.connections.database.driver', 'redis');
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
+});
+
+test('R2: 参照接続の connection が業務 DB と異なれば違反する', function (): void {
+    config()->set('queue.connections.database-render.connection', 'pgsql_queue');
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::SameDatabaseConnection);
+});
+
+test('R3: 参照接続の after_commit が true なら違反する', function (): void {
+    config()->set('queue.connections.database.after_commit', true);
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
+});
+
+test('R3: 参照接続に after_commit キーが無ければ違反する (fail-closed)', function (): void {
+    config()->set('queue.connections.database-media', ['driver' => 'database', 'connection' => null]);
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
+});
+
+test('R4: sync の after_commit が true でなければ違反する', function (): void {
+    config()->set('queue.connections.sync', ['driver' => 'sync']);
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
+});
+
+test('R4: sync 接続の定義自体が無ければ違反する', function (): void {
+    $connections = config('queue.connections');
+    expect($connections)->toBeArray();
+    unset($connections['sync']);
+    config()->set('queue.connections', $connections);
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
+});
+
+test('R5: production で既定接続が sync なら違反する', function (): void {
+    config()->set('queue.default', 'sync');
+
+    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
+});
+
+test('R5: production で既定接続が redis なら違反する', function (): void {
+    config()->set('queue.connections.redis', ['driver' => 'redis', 'after_commit' => false]);
+    config()->set('queue.default', 'redis');
+
+    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
+});
+
+test('R5: production で既定接続が未定義なら違反する', function (): void {
+    config()->set('queue.default', 'nonexistent');
+
+    expect(guardViolationRules(isProduction: true))->toContain(QueueAtomicityRule::ProductionAsyncDriver);
+});
+
+test('R5: production で既定接続が database なら違反しない', function (): void {
+    expect(guardViolationRules(isProduction: true))->not->toContain(QueueAtomicityRule::ProductionAsyncDriver);
+});
+
+test('R5 は非 production では評価されない (テストレーンの sync が通る)', function (): void {
+    config()->set('queue.default', 'sync');
+
+    expect(guardViolationRules())->toBe([]);
+});
+
+test('pin 済み 3 接続はいずれも検査対象に入る (既定接続だけを見ていない)', function (): void {
+    config()->set('queue.default', 'sync'); // 既定接続を検査対象から外す
+
+    foreach (QueueDispatchAtomicityGuard::PINNED_CONNECTIONS as $pinned) {
+        guardBaselineConfig();
+        config()->set('queue.default', 'sync');
+        config()->set('queue.connections.'.$pinned.'.after_commit', true);
+
+        expect(guardViolationRules())->toContain(QueueAtomicityRule::AfterCommitDisabled);
+        expect(array_column((new QueueDispatchAtomicityGuard)->violations(false), 'connection'))
+            ->toContain($pinned);
+    }
+});
+
+test('queue.connections が配列でない場合は違反として報告する (例外を投げない)', function (): void {
+    config()->set('queue.connections', 'not-an-array');
+
+    expect(guardViolationRules())->toBe([QueueAtomicityRule::ConfigUnreadable]);
+});
+
+test('queue.default が非 string / 空文字 / 未定義なら違反する (fail-closed)', function (): void {
+    foreach ([123, '', null] as $bad) {
+        guardBaselineConfig();
+        config()->set('queue.default', $bad);
+
+        expect(guardViolationRules())->toContain(QueueAtomicityRule::ConfigUnreadable);
+    }
+});
+
+test('database.default が非空 string でなければ違反する (fail-closed)', function (): void {
+    $original = config('database.default');
+
+    try {
+        foreach (['', 123, null] as $bad) {
+            config()->set('database.default', $bad);
+
+            expect(guardViolationRules())->toContain(QueueAtomicityRule::ConfigUnreadable);
+        }
+    } finally {
+        // ★ database.default を壊したままにすると RefreshDatabase の後片付けが落ちる
+        config()->set('database.default', $original);
+    }
+});
+
+test('参照接続の定義が欠落 / 非配列なら違反する (fail-closed)', function (): void {
+    config()->set('queue.connections.database-analysis', 'nope');
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
+});
+
+test('参照接続の connection が null なら許可される (既定 DB 接続)', function (): void {
+    config()->set('queue.connections.database.connection', null);
+
+    expect(guardViolationRules())->not->toContain(QueueAtomicityRule::SameDatabaseConnection);
+});
+
+test('参照接続の connection が非 string / 空文字なら違反する (fail-closed)', function (): void {
+    foreach ([123, '', ['pgsql']] as $bad) {
+        guardBaselineConfig();
+        config()->set('queue.connections.database.connection', $bad);
+
+        expect(guardViolationRules())->toContain(QueueAtomicityRule::SameDatabaseConnection);
+    }
+});
+
+test('sync 接続が非配列なら違反する (fail-closed)', function (): void {
+    config()->set('queue.connections.sync', 'sync');
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
+});
+
+test("sync 接続の driver が欠落 / 非 string / 'database' なら違反する", function (): void {
+    foreach ([null, 123, 'database'] as $bad) {
+        guardBaselineConfig();
+        config()->set('queue.connections.sync', ['driver' => $bad, 'after_commit' => true]);
+
+        expect(guardViolationRules())->toContain(QueueAtomicityRule::SyncAfterCommitEnabled);
+    }
+});
+
+test('pin 済み接続 (database-analysis) の driver が sync なら R1 違反になる', function (): void {
+    // ★ sync の除外を driver ではなく**接続名**で行っていることの固定。
+    //   driver で除外する実装だと、この構成が R1〜R3 を全部 skip して通ってしまう。
+    config()->set('queue.connections.database-analysis', ['driver' => 'sync', 'after_commit' => true]);
+
+    expect(guardViolationRules())->toContain(QueueAtomicityRule::DatabaseDriver);
+});
+
+test('enforce() は違反があれば RuntimeException を投げ、無ければ何も起きない', function (): void {
+    (new QueueDispatchAtomicityGuard)->enforce(false);
+
+    config()->set('queue.connections.database.after_commit', true);
+
+    expect(fn () => (new QueueDispatchAtomicityGuard)->enforce(false))
+        ->toThrow(RuntimeException::class, 'Queue dispatch atomicity violations');
+});

```

---

## テスト結果

- `composer phpstan`: OK (838 files, No errors)
- `composer test`: 4090 tests / 4088 passed / 0 failed / 2 skipped
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck` / `pnpm test` (1268) / `pnpm build`: passed
- `pnpm typecheck:packages` / `build:packages` / `test:packages` (106): passed
- `php artisan route:list` / `php artisan production:preflight` / `scripts/bug-hunt-shard.sh self-test`: 起動 OK
- `composer test:browser` は route/UI を変更していないため省略

## mutation 赤化確認 (M1〜M24)

# mutation 赤化確認 (M1〜M24)

対象: T137 / 詳細設計 `devnotes/20260809-0027-queue-dispatch-atomicity/detailed-design.md` §mutation 表。

**手順**: 変異は 1 個ずつ入れて対象テストを 1 回走らせ、**必ず元へ戻した**。
最後に `git diff` / `git status --short` で残留がないことを確認済み
(`app/Jobs/Billing/SyncBillingCustomerDetails.php` / `app/Mail/InquiryReceivedMail.php` /
`config/queue.php` / 各 Support は差分なし or 意図した差分のみ)。

**設計の予測とずれた点は辻褄を合わせず、そのまま記録している** (#1 / #3 / #4 / #16 / #20 / #22 / #24)。

凡例: ✅ = 意図した検査が赤化 / ⚠ = 設計の予測とずれた (内容を併記)

| # | 変異 | 結果 | 実測 |
|---|---|---|---|
| 1 | `config/queue.php` の `sync` から `after_commit => true` を削る | ⚠ | `QueueDispatchAtomicityGuardBootTest` の **3 本すべてが起動時例外で赤** (`queue.connections.sync は driver=sync かつ after_commit=true でなければなりません`)。**設計が予測した `QueueDispatchAtomicityGuardTest` (R4) は赤にならない** — 同テストは `config()->set()` で baseline 構成を自前で流し込む純関数テストで、`config/queue.php` の実値を読まないため。実値を見る検査点は boot テスト側にある |
| 2 | `database` の `after_commit` を `true` にする | ✅ | `QueueDispatchAtomicityInventoryTest` の **D4** が赤 (`sync 以外の接続で after_commit=true になっています: database`)。テストレーンの既定接続は `sync` のため R3 の参照集合に `database` は入らず、boot は落ちない (= D4 が唯一の検出点であることが実測で確認できた) |
| 3 | `database-render` の `connection` を別 DB 名 (`other_db`) にする | ⚠ | boot テスト 3 本が赤 (`キュー接続 database-render の DB 接続 (other_db) が業務 DB (pgsql) と異なります`)。#1 と同じ理由で `QueueDispatchAtomicityGuardTest` (R2) は赤にならない |
| 4 | production の R5 検査を潰す (`if ($isProduction)` → `if (false)`) | ⚠ | `QueueDispatchAtomicityGuardTest` の R5 系 **3 本**が赤。**設計の記述「production 判定時の既定接続を sync にする」はそのままでは変異にならない** (それは R5 テストが再現する構成そのもので、guard が正しければ赤にならない)。実装側を潰す形へ読み替えて実施した |
| 5 | `AnalysisJobService::trigger` の dispatch を tx の外へ戻す | ✅ | `QueueDispatchAtomicityTest` の `解析トリガの RunManualAnalysis は業務 tx の内側で投入される` **のみ**が赤 (rollback テストは緑のまま = 設計 §保証しないもの 12 の実測確認) |
| 6 | `BillingCustomerSynchronizer` に `->afterCommit()` を戻す | ✅ | `QueueDispatchAtomicityInventoryTest` の **D1** が赤 |
| 7 | `PaymentFailedNotification` に `ShouldQueueAfterCommit` を戻す | ✅ | 同 **D3** が赤 |
| 8 | `TicketLedgerService` に `DB::afterCommit` を戻す | ✅ | 同 **D2** が赤 + `TicketReserveDispatchAtomicityTest` の tx level テストが赤 (2 本同時) |
| 9 | 各検出器を「常に空配列を返す」に潰す (1 つずつ) | ✅ | `detectInFiles` → 負のコントロール D1 / D2 の 2 本<br>`detectAfterCommitInterfaces` → D3 の 2 本 (ShouldQueueAfterCommit / ShouldHandleEventsAfterCommit)<br>`detectAfterCommitEnabledConnections` → D4 の 1 本<br>`detectAfterCommitProperty` → D5(既定値) の 2 本 (job / Mailable)<br>`detectAfterCommitAssignments` → D5(代入) の 2 本 ($this-> / $job->) |
| 10a | `phpFilesUnder()` を空配列返しにする | ✅ | **8 本**が赤 (母集団の対称差 / ルート単位 0 件 fail / 負のコントロール 4 本 / 契約の固定 2 本) |
| 10b | `QueuedJobPopulation::shouldQueueClasses()` を空配列返しにする | ✅ | `QueueDispatchAtomicityInventoryTest` 2 本 + **既存の `QueuedJobLeaseInventoryTest` 2 本 / `JobExecutionDedupInventoryTest` 2 本**が赤 (巻き添えではなく意図した連動) |
| 11 | `phpFilesUnder()` の走査から `app/Jobs` を除外する | ✅ | 母集団の Finder 対称差テストが赤 |
| 12 | `AutoRechargeTriggerJob` に `ShouldBeUnique` を戻す | ✅ | `JobExclusionOrderingInvariantTest` の反転テスト **2 本** (`ShouldBeUnique を実装しない` / `uniqueFor・uniqueId を持たない`) が赤 |
| 13 | `reserve()` の `AutoRechargeTriggerJob::dispatch` を tx の外へ戻す | ✅ | `TicketReserveDispatchAtomicityTest` の tx level テストのみ赤。**`AutoRechargeAttemptDispatchAtomicityTest` は緑のまま** (設計が予告したとおり別ジョブを見ているため) = 別テストを分けた判断が実測で正当化された |
| 13b | `createAttemptLocked()` の `ExecuteAutoRechargeAttemptJob::dispatch` を tx の外へ戻す | ✅ | `AutoRechargeAttemptDispatchAtomicityTest` の tx level テストが赤 |
| 14 | `tar_attempts_org_pending_unique` を外す | ✅ | `AutoRechargeAttemptUniquenessTest` の **2 本** (`2 件目の pending 行を拒否する` / `unique violation は no-op へ収束する`) が赤。**変異の入れ方**: migration を書き換えると再 migrate が要るため、テストの `beforeEach` で `DROP INDEX`(RefreshDatabase の tx 内なので巻き戻る) を実行する形で行った |
| 15 | `QUEUED_JOB_LEASE_INVENTORY` に架空の接続 (`database-imaginary`) を入れる | ✅ | 新設した `PINNED_CONNECTIONS` 対称差テストを含む **3 本**が赤 (他 2 本は既存の目録整合テスト) |
| 16 | `RUNTIME_ROOTS` から `routes` を消す | ⚠ | **テスト 5b (ルート集合の独立 pin) と テスト 5 (Finder 対称差) の 2 本**が赤。設計は「5b だけが落ち、5 と 6 では落ちない」と予測していたが、実装では**テスト 5 の Finder 側もテスト側リテラル `QUEUE_DEFERRAL_EXPECTED_ROOTS` を回している**ため対称差も同時に赤くなる (設計より強い。テスト 6 は予測どおり緑のまま) |
| 17 | `sync` の `driver` を `database` に変える | ✅ | boot テスト 3 本が R4 で赤 (driver 検査が効いている) |
| 18 | `capture()` の `finally` (`$collector->active = false;`) を削る | ✅ | `RecordsJobQueueingTransactionLevelTest` の `capture 後に別ジョブを dispatch しても件数が増えない` が赤 (collector オブジェクト方式でなければ copy-on-write で空振りする点の実証) + 例外経路のテストも赤 |
| 19 | `database-analysis` の `driver` を `sync` に変える | ✅ | boot テスト 3 本が **R1** で赤 (`キュー接続 database-analysis の driver が database ではありません`)。**sync 除外を接続「名」で行っている**ことの実測確認 (driver で除外する実装ならここは全 skip されて緑になる) |
| 20 | 任意の job クラスに `$afterCommit = true;` を足す | ⚠ | `QueueDispatchAtomicityInventoryTest` の **D5(既定値)** のみが赤 (D1〜D4 は緑 = 設計の予告どおり)。ただし **`public bool $afterCommit = true;` は PHP の言語仕様上そのままでは書けない** — `Illuminate\Bus\Queueable` が `public $afterCommit;` を持つため trait composition が fatal になる。変異は `use Queueable;` を外し `public $afterCommit = true;` (型なし) を足す形で実施した |
| 21 | 任意の job のコンストラクタに `$this->afterCommit = true;` を足す | ✅ | 同 **D5(代入)** が赤 |
| 22 | `InquiryReceivedMail` から `implements ShouldQueue` を外し `$afterCommit = true` を足す | ✅⚠ | 同 **D5(既定値)** が赤。**母集団を `shouldQueueClasses()` だけに戻すと検出できない**ことも実測で確認した:<br>`detectAfterCommitProperty(shouldQueueClasses())` → `[]`<br>`detectAfterCommitProperty(merge(shouldQueue, mailables))` → `["App\Mail\InquiryReceivedMail"]`<br>(⚠ #20 と同じ理由で `use Queueable;` の除去が併せて必要) |
| 23 | ShouldQueue クラスに `implements ShouldHandleEventsAfterCommit` を足す | ✅ | 同 **D3** が赤。**`ShouldQueueAfterCommit` だけを見る実装では検出できない**ことも実測で確認した:<br>ShouldQueueAfterCommit のみ → `[]` / 両 interface → `["App\Jobs\Billing\SyncBillingCustomerDetails"]` |
| 24 | `deferralCandidateClasses()` を `shouldQueueClasses()` だけ返すよう潰す | ⚠ | **どのテストも赤にならなかった**。現状 `mailableClasses()` ⊆ `shouldQueueClasses()` (Mailable 2 クラスが `implements ShouldQueue` を併記している) のため、和集合を片側へ潰しても**結果が変わらない**。<br>**対応**: 和集合の生成を純関数 `mergeCandidateClasses(array, array)` へ切り出し、disjoint な 2 集合を食わせる負のコントロールを追加した。同関数を片側へ潰す変異 (#24b) で当該テストが赤になることを確認済み。<br>**残る穴 (誇張しない)**: `deferralCandidateClasses()` 自体を片側へ潰す変異は、併記が続くかぎり検出できない。併記が外れた瞬間 (= #22 の状態) には D5/D3 の 0 件 pin が実効を持ち検出できる |

## まとめ

- **意図した検査が赤化しなかったのは #24 のみ**で、原因 (母集団の包含関係による degenerate) を特定し、
  負のコントロールを 1 本追加して和集合ロジック自体は固定した。残る穴は上表に明記した。
- #1 / #3 は「設計が指した落ちるべきテスト」が実際には別 (boot テスト) だった。
  guard の純関数テストは config を自前で組み立てるため、`config/queue.php` の実値の変異は
  **boot 経路でのみ**観測できる。両方が揃っていることが実測で確認できた。
- #4 は設計の記述がそのままでは変異にならないため、実装側 (R5 検査) を潰す形へ読み替えた。
- #16 は設計の予測より**強い** (5b に加えて 5 も赤くなる)。
- #20 / #22 は PHP の trait composition 制約により、設計の書式 (`public bool $afterCommit = true;` を
  そのまま足す) では fatal になる。`use Queueable;` を外す形で実施した。
  裏を返すと、**`Queueable` を使うクラスでは D5(既定値) の迂回路そのものが書けない**
  (現実に書けるのは Mailable のように trait を使わないクラス) — Mailable を母集団へ足した
  設計判断の正しさが、この制約からも裏づけられた。

