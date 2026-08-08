【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

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

なお AGENTS.md の思考原則には次も含まれる (本設計の判断根拠として頻出する):
1. フレームワークのレンジ内でやる 2. **今必要なものだけ作る (オーバーエンジニアリング禁止。「あったら便利」は作らない)** 3. 後方互換の並走を残さない 4. **別物の概念を「似ているから」で統合しない** 5. テストファースト 6. タコツボ実装を避ける

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。


---

あなたは経験豊富なWebアプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10
- Pestテストフレームワーク (RefreshDatabase グローバル適用 + --parallel、個別 DatabaseTransactions 禁止)
- DTO + JsonResource パターン
- Laratrust RBAC（Organization → Team → Project階層）
- DB は PostgreSQL

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null安全性）
2. 既存コードとの整合性（命名規約、パターン、API）
3. PHPStan level 10 適合性（型安全性、generics、Assert使用）
4. テスト計画の網羅性（各施策にPestテスト、RefreshDatabaseグローバル適用に従う）
5. DTO/JsonResource パターンの遵守
6. Inertia Props vs API Responseの使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript型定義、API Resource、テストが変更対象に含まれているか）
9. セキュリティ（認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件）
10. 検査が空振りしないことの保証（負のコントロール / 母集団 0 件で fail / exact-fit）が実際に機能するか
11. 「保証しないもの」に誇張・抜けがないか

【この設計の背景 (概念設計は別セッションで APPROVED 済み)】
台帳裁定 AG-114 確定1 (キュー投入を業務トランザクション内で行い afterCommit 依存を廃す) / 確定2 (原子性の前提を起動時 fail-closed 検査) / AG-126 (適用除外ゼロの 0 件 pin) / AG-127 (付随的副作用の除外基準) を実装する詳細設計です。

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

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
            // rollback すれば take 行も削除 job も一緒に巻き戻るため、
            // 「行は消えたのに S3 オブジェクトが残る」孤児が構造的に発生しない。
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
  - `解析トリガの enqueue は業務 tx の内側で行われる` — `JobQueueing` を listen して
    `DB::transactionLevel()` を記録し、`>= 2` (RefreshDatabase のラッパ tx が level 1) を assert
  - `外側 tx が rollback すると analysis_jobs も jobs 行も残らない` —
    `config()->set('queue.default', 'database')` で実 `jobs` 表を観測
  - `レンダトリガの enqueue は業務 tx の内側で行われる`
  - `プレビュートリガの enqueue は業務 tx の内側で行われる`
- [ ] `tests/Feature/Capture/TakeDeletionQueueAtomicityTest.php`
  - `テイク削除の外側 tx が rollback すると take 行も削除 job も残らない`
- [ ] `tests/Feature/Manual/VideoManualDeletionQueueAtomicityTest.php`
  - `マニュアル削除の外側 tx が rollback すると manual 行も削除 job も残らない`
- [ ] 既存テスト `tests/Feature/Manual/AnalysisJobServiceTest.php` 等の更新: **不要**
  (M1 により sync レーンの実行順序が保たれるため)。実装時に全レーンを走らせて確認する
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (`VideoManual::factory()` / `Take::factory()` 等)

### リスク

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
        // 閾値クロスの有無を tx の外へ持ち出す (通知は tx を抜けてから行うため)
        $crossing = null;

        $reservation = DB::transaction(function () use ($organization, $amount, &$crossing): TicketReservation {
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

            return $reservation;
        });

        // 低残高通知 (AG-127 の付随的副作用)。**tx を抜けた最後**に実行する。
        // 保証範囲を誇張しない: reserve が呼び出し側の tx にネストされている場合、
        // ここは依然として外側 tx の内側であり、(a) 外側のロックを保持したまま INSERT が走る、
        // (b) SQL 層の失敗は PostgreSQL の tx abort を経て業務操作ごと失敗させる。
        // アプリケーション層の例外は NotificationCenterService::safely() が握る。
        if ($crossing !== null) {
            $this->notifications->notifyTicketBalanceLow($organization, $crossing['balance'], $crossing['threshold']);
        }

        return $reservation;
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
- [x] null 安全 — `$crossing` は `?array{balance: int, threshold: int}` として
  `/** @var array{balance: int, threshold: int}|null $crossing */` を付ける
  (参照渡し `&$crossing` の型を PHPStan へ明示する)
- [x] DTO を返している — `$crossing` は**内部の一時値**であり公開 API ではないため
  array shape で足りる。公開されるのは既存の `TicketReservation` のみ
- [x] Generics の型パラメータが正しい

> `$crossing` を array shape ではなく小さな readonly DTO
> (`App\DataTransferObjects\Billing\TicketLowBalanceCrossing`) にする案もあるが、
> **メソッド内で閉じた一時値**であり公開されないため、思考原則 2 に従い array shape で足りる。
> PHPStan level 10 は array shape でも通る (`@var` 注釈で narrowing する)。

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
  - `attempt 起票と ExecuteAutoRechargeAttemptJob の投入は同一 tx である`
    (`JobQueueing` の `DB::transactionLevel()` 観測)
  - `起票 tx が rollback すると attempt 行も jobs 行も残らない`
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
- `reserve()` に参照渡しが 1 つ増える。PHPStan level 10 で narrowing が効くよう
  `@var` 注釈を付ける

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
        $connections = config('queue.connections');
        if (! is_array($connections)) { /* 違反として報告 (fail-closed) */ }

        $defaultQueue = config('queue.default');
        $defaultQueue = is_string($defaultQueue) ? $defaultQueue : '';
        $defaultDatabase = config('database.default');
        $defaultDatabase = is_string($defaultDatabase) ? $defaultDatabase : '';

        // R5: production の既定接続 driver
        if ($isProduction && $this->driverOf($connections, $defaultQueue) !== 'database') { /* 違反 */ }

        // R4: sync 接続 (定義されている場合のみ。未定義は違反として報告)
        if (($connections['sync']['after_commit'] ?? null) !== true) { /* 違反 */ }

        // R1〜R3: 参照集合 = [既定接続] ∪ PINNED_CONNECTIONS のうち driver !== 'sync' のもの
        foreach ($referenced as $name) {
            $config = $connections[$name] ?? null;   // 欠落は違反 (fail-closed)
            $driver = $config['driver'] ?? null;      // !== 'database' は R1 違反
            $connection = $config['connection'] ?? null; // null は既定 DB を意味するので OK
            if (is_string($connection) && $connection !== $defaultDatabase) { /* R2 違反 */ }
            if (($config['after_commit'] ?? null) !== false) { /* R3 違反 (キー欠落も違反) */ }
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
        (new QueueDispatchAtomicityGuard)->enforce($this->app->environment('production'));
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
  - `config が配列でない場合は違反として報告する (例外を投げない)`
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
 * 【4 種の検出器】
 * - D1 `->afterCommit(` / `?->afterCommit(`  … PendingDispatch の明示指定
 * - D2 `DB::afterCommit(`                    … トランザクション callback への退避
 * - D3 `ShouldQueueAfterCommit` の実装        … **リフレクション判定** (文字列走査ではない)
 * - D4 config の `after_commit => true`       … sync 以外の接続
 *
 * 【D3 を文字列走査にしない理由】文字列走査だと「`ShouldQueueAfterCommit` を継承した中間
 * interface を implement する」「親クラス経由で implement される」形を丸ごと見落とす。
 * 家系の申し送り (「grep afterCommit は interface 名に一致しないので宣言的迂回が丸ごと
 * 見えない」) への正しい応答は、grep を強化することではなく判定を型システム側へ移すこと。
 *
 * 【引数で母集団を受け取る理由】テストが fixture ディレクトリツリー / ダミークラス /
 * 擬似 config を同じ関数へ食わせて「列挙 → 読み込み → 検出」の**全経路**を通せるようにするため。
 * 検出関数だけを直接叩く形にすると「検出器は生きているが実ファイルが渡されていない」
 * 偽グリーンを閉じられない。
 */
final class QueueDispatchDeferralInventory
{
    /** @param list<string> $paths @return list<array{path: string, line: int, kind: string}> */
    public static function detectInFiles(array $paths): array { /* D1 + D2 */ }

    /** @param list<class-string> $classes @return list<class-string> */
    public static function detectShouldQueueAfterCommit(array $classes): array { /* D3 */ }

    /** @param array<mixed> $connections @return list<string> 違反した接続名 */
    public static function detectAfterCommitEnabledConnections(array $connections): array { /* D4 */ }
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
| ★ 保証しないもの: D1/D2 は文字列パターン検出であり、`$m = 'afterCommit'; $job->$m();` /
|   helper・facade alias で包んだ呼び出し / vendor 内の afterCommit 使用には沈黙する。
|   (D3 はリフレクション判定なので中間 interface・親クラス経由まで拾う)
*/
```

テスト本体 (12 本):

| # | テスト名 | 種別 |
|---|---|---|
| 1 | `D1: app/ に ->afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 2 | `D2: app/ に DB::afterCommit() の呼び出しは 1 件も無い` | 0 件 pin |
| 3 | `D3: ShouldQueue 実装で ShouldQueueAfterCommit を implement するクラスは 1 件も無い` | 0 件 pin |
| 4 | `D4: after_commit=true を持ってよい接続は sync だけである` | 0 件 pin (全接続集合) |
| 5 | `母集団: app/ の PHP ファイル列挙は Finder による独立列挙と対称差が空である` | 母集団境界の exact-fit |
| 6 | `母集団: app/ の PHP ファイル列挙は 0 件でない` | 母集団 0 件 fail |
| 7 | `母集団: ShouldQueue 実装クラスの列挙は 0 件でない` | 母集団 0 件 fail |
| 8 | `母集団: queue.connections は 0 件でない` | 母集団 0 件 fail |
| 9 | `負のコントロール: fixture ツリーを列挙して D1 を検出する` | 経路統合 |
| 10 | `負のコントロール: fixture ツリーを列挙して D2 を検出する` | 経路統合 |
| 11 | `負のコントロール: ShouldQueueAfterCommit 実装ダミークラスを D3 が検出する` | 経路統合 |
| 12 | `負のコントロール: after_commit=true の非 sync 接続を D4 が検出する` | 経路統合 |

- テスト 5 は `Symfony\Component\Finder\Finder` で `app/**/*.php` の正規化済み集合を作り、
  `QueuedJobPopulation::appPhpFiles()` との**対称差が空**を assert する
  (`Finder` は既に `BillingSyncDispatchInvariantTest` で使われている)。
  検出ロジックの二重実装ではなく**母集団境界の固定**である
- テスト 7 の「完全性」自体は `QueuedJobLeaseInventoryTest` /
  `JobExecutionDedupInventoryTest` が対称差 0 で既に固定しているため、
  本 gate では 0 件 fail のみとし二重実装しない (docblock でその契約を参照する)
- 負のコントロールの fixture は `sys_get_temp_dir()` 配下に `beforeEach` で作り
  `afterEach` で削除する (リポジトリ内にダミー PHP を置かない)。
  D3 のダミークラスはテストファイル内で `class` 宣言し、クラス名の list を判定器へ渡す

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
 * ★ RefreshDatabase のラッパ tx が level 1 を占めるため、
 *   「業務 tx の内側」= **level >= 2** である。
 */
final class RecordsJobQueueingTransactionLevel
{
    /** @return list<array{job: string, level: int}> */
    public static function capture(callable $action): array
    {
        $records = [];
        Event::listen(JobQueueing::class, function (JobQueueing $event) use (&$records): void {
            $job = $event->job;
            $records[] = [
                'job' => is_object($job) ? $job::class : (string) $job,
                'level' => DB::transactionLevel(),
            ];
        });

        $action();

        return $records;
    }
}
```

テスト例:

```php
// tests/Feature/Manual/QueueDispatchAtomicityTest.php
test('解析トリガの enqueue は業務 tx の内側で行われる', function (): void {
    config()->set('queue.default', 'database'); // Queue::fake は使わない (原子性を観測できない)
    [$project, $manual] = analyzableManual();   // Factory 経由のヘルパ

    $records = RecordsJobQueueingTransactionLevel::capture(
        fn () => app(AnalysisJobService::class)->trigger($project, $manual),
    );

    expect($records)->toHaveCount(1);
    expect($records[0]['job'])->toBe(RunManualAnalysis::class);
    // RefreshDatabase のラッパ tx が level 1。level >= 2 = 業務 tx の内側
    expect($records[0]['level'])->toBeGreaterThanOrEqual(2);
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

- [x] 戻り値の型が明示されている (`list<array{job: string, level: int}>`)
- [x] null 安全 — `JobQueueing::$job` は `mixed` 相当のため `is_object` で narrowing
- [x] DTO を返している — テスト Support のため array shape で足りる
- [x] Generics の型パラメータが正しい

### テスト計画

- [ ] 上記の各テストを**先に赤で置く** (現行実装では tx 外 dispatch のため
  level が 1 になり、rollback テストも jobs 行が残らないことを確認できない)
- [ ] 全 5 ファイルで「tx level 観測」と「rollback で両方巻き戻る」の 2 系統を持つ
- [x] 個別の `DatabaseTransactions` を使っていないことを確認
- [x] テストデータは Factory (`Project::factory()` / `VideoManual::factory()` /
  `SourceDocument::factory()` / `Organization::factory()` 等)

### リスク

- `config()->set('queue.default', 'database')` はテスト内で `sync` を上書きする。
  **その結果ジョブ本体は実行されない** (jobs 行が積まれるだけ)。
  「ジョブが実行されること」を検査する既存テストとは目的が違うため混同しない
- `jobs` テーブルは `RefreshDatabase` のラッパ tx 内にあるため、テスト終了時に巻き戻る。
  他テストとの干渉はない (`--parallel` でも DB が分かれる)
- `Event::listen` はテスト後にリセットされる (`RefreshApplication`)。
  念のためヘルパ内で listener を張るスコープをテスト 1 本に閉じる

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
   `ShouldQueueAfterCommit` / config の `after_commit => true` (sync 以外) は
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
     「同一トランザクションに乗る」ことの**代理検査**にすぎない
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

1. **プロセスが commit 直前に落ちる窓は消えない**。消えるのは「commit したのに投入されない」窓だけ
2. **worker が実際に起動していることは保証しない**。jobs 行が載っても
   `queue:work database-analysis` 等が動いていなければ前進しない (既存の運用契約の管轄)
3. **D1/D2 は文字列パターン検出**。`$m = 'afterCommit'; $job->$m();` / helper・facade alias で
   包んだ呼び出し / vendor 内の afterCommit 使用には沈黙する (D3 はリフレクションなので
   中間 interface・親クラス経由まで拾う)
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

---

## mutation で赤化を確認する手順 (実装時に必ず実施)

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
| 9 | D1〜D4 の各検出器を「常に 0 件を返す」に潰す (1 つずつ) | 対応する負のコントロール (9〜12 番) が**それぞれ**落ちる |
| 10 | `QueuedJobPopulation::appPhpFiles()` / `::shouldQueueClasses()` を空配列返しにする | 母集団 0 件 fail (テスト 6 / 7) と対称差テスト (テスト 5) |
| 11 | `appPhpFiles()` から `app/Jobs` を除外する | 母集団境界の exact-fit (テスト 5) |
| 12 | `AutoRechargeTriggerJob` に `ShouldBeUnique` を戻す | `JobExclusionOrderingInvariantTest` の反転テスト |
| 13 | `AutoRechargeTriggerJob` の dispatch を `reserve()` の tx の外へ戻す | `AutoRechargeTriggerTest` の反転テスト (実 `jobs` 表) |
| 14 | `tar_attempts_org_pending_unique` を外す | `AutoRechargeAttemptUniquenessTest` の 2 番目 |

**各変異は 1 個ずつ入れて 1 回テストし、必ず戻す。** 手順と結果 (どのテストがどう落ちたか) は
実装 PR の devnotes に記録する。

---

## 未解決事項 (本 PR では扱わない)

1. **`AutoRechargeService::isUniqueViolation()` が制約名を識別しない**。
   SQLSTATE (23505/23000) だけを見るため、別の unique 制約の違反も no-op に収束する。
   変更すると auto-recharge の失敗分類の設計に踏み込むため本 PR では触らない (思考原則 2)
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

## 関連する現行コード (抜粋)

### app/Services/Manual/AnalysisJobService.php (trigger 部分)
```php
    public function trigger(Project $project, VideoManual $manual, ?User $actor = null): AnalysisJob
    {
        $job = DB::transaction(function () use ($project, $manual, $actor): AnalysisJob {
            // 共有ロック規約: status を書くため VideoManual 行ロック (親 relation 経由 = 子∈親も担保)
            /** @var VideoManual $locked */
            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();

            // 実行可能状態 guard (ready→analyzing は再解析の正式遷移。doc/10 §10.2)
            if (! in_array($locked->status, [VideoManualStatus::Draft, VideoManualStatus::Ready], true)) {
                throw new AnalysisConflictException(AnalysisConflictType::StatusNotAnalyzable);
            }
            // analyze 冪等: 同一 manual の in-flight は 1 つ (§10.8-8)
            $inFlight = $locked->analysisJobs()
                ->whereIn('status', [JobStatus::Queued->value, JobStatus::Running->value])
                ->exists();
            if ($inFlight) {
                throw new AnalysisConflictException(AnalysisConflictType::InFlight);
            }
            // 解析対象 SOP (追記型の最新。行ロック下で決定的に選択)
            $document = $locked->sourceDocuments()->latest('id')->first();
            if ($document === null) {
                throw ValidationException::withMessages(['document' => ['手順書をアップロードしてください。']]);
            }
            // 残高事前チェック (reserve はジョブ開始時 = §10.5。ここは fail-fast の入口ゲート)。
            // 判定は表示 clamp 済みの balance() ではなく真値 availableTrueBalance() を使う
            // (返金債務で負に振れた出所を clamp が隠すと誤判定になる)
            $organization = $this->resolveOrganization($project);
            $cost = config()->integer('manual.analysis_ticket_cost');
            $balance = $this->tickets->availableTrueBalance($organization);
            if ($balance < $cost) {
                throw InsufficientTicketsException::forReserve($cost, $balance);
            }

            $job = $locked->analysisJobs()->make();
            $job->status = JobStatus::Queued;
            $job->sourceDocument()->associate($document);
            if ($actor !== null) {
                $job->triggeredBy()->associate($actor); // Auth 導出のみ (保護キー。payload 直送は 422)
            }
            $job->save();

            $locked->forceFill(['status' => VideoManualStatus::Analyzing])->save();

            return $job;
        });

        // commit 後に dispatch (payload は job id のみ。dispatch 喪失は recoverStale が回収)
        RunManualAnalysis::dispatch($job->id);

        return $job;
    }
```

### app/Services/Billing/TicketLedgerService.php (reserve 末尾)
```php

            // 残高低下の閾値クロス検知。クロス判定を reserve に置く理由: 実効残高が減る唯一の
            // 消費イベントは reserve (Reserved→Committed の commit は拘束 -amount と台帳 -amount が
            // 相殺し実効残高は不変)。reserve は org 行ロック下で直列化済みのため、並行 reserve でも
            // クロスを観測するのはちょうど 1 回 (release/grant で回復して再度跨げば再通知 = 仕様)
            $balance = $availableMonthly + $availablePurchased; // = availableTrueBalance と同一意味論
            $threshold = config()->integer('billing.ticket_low_balance_threshold');
            $after = $balance - $amount;
            if ($balance >= $threshold && $after < $threshold) {
                // afterCommit: reserve は pipeline の startJob tx 内から savepoint で呼ばれ得るため、
                // 最外層 commit 成立後にのみ通知する (rollback 時は発火しない)
                DB::afterCommit(fn () => $this->notifications->notifyTicketBalanceLow($organization, $after, $threshold));
            }

            // P8a: オートリチャージ (裏チャージ) のトリガ点。**低残高通知と同居**させる
            // (parity の名で既存の低残高通知を置き換えない)。
            //
            // AI-CUE の実効残高が減る唯一の消費イベントは reserve であり、commit は拘束 −amount と
            // 台帳 −amount が相殺して balance 不変。よって移植元の commit ではなく reserve に置く
            // (commit に置くと閾値クロスを取り逃す)。
            //
            // 閾値判定・pending 検査・数量確定は Job 側 (AutoRechargeService) が org 行ロック下で
            // 再評価するため、ここでは条件を絞らない = 過剰 dispatch は無害
            // (設定行なし org は Job 冒頭で即 return。既定 off の org には何も起きない)。
            // afterCommit で rollback 時は発火しない。
            $organizationId = $organization->getKey();
            Assert::integer($organizationId);
            DB::afterCommit(static fn () => AutoRechargeTriggerJob::dispatch($organizationId));

            return $reservation;
        });
    }

```

### app/Services/Billing/AutoRechargeService.php (createAttemptLocked の末尾と catch)
```php
                // UI (AutoRechargeCard の「1 枚あたり」表示) も同じ Max 枚 tier 単価を提示しており、
                // 表示・同意・実請求の 3 者がこれで一致する。
                $tier = TicketVolumePrice::currentTierFor($config->max_count);

                $attempt = new TicketAutoRechargeAttempt;
                $attempt->organization()->associate($locked);
                $attempt->fill([
                    'attempt_ulid' => strtolower((string) Str::ulid()),
                    'status' => AutoRechargeAttemptStatus::Pending->value,
                    'quantity' => $quantity,
                    'unit_amount' => $tier->unitAmount,
                    'stripe_price_id' => $tier->stripePriceId,
                ]);
                $attempt->save();

                return $attempt;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueViolation($e)) {
                // DB partial unique (tar_attempts_org_pending_unique) が最終防衛。並行起票は no-op。
                return null;
            }

            throw $e;
        }
    }

```

### app/Jobs/Billing/AutoRechargeTriggerJob.php (全文)
```php
<?php

declare(strict_types=1);

namespace App\Jobs\Billing;

use App\Models\Billing\TicketAutoRecharge;
use App\Models\Organization;
use App\Services\Billing\AutoRechargeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * P8a: チケット消費 (reserve) 後の残高閾値判定 → attempt 起票の薄い箱。
 *
 * 判定は Job 側に完全委譲 (reserve hot path で閾値を見ない)。**enabled 設定の存在確認で
 * 早期 return する** = opt-in 未設定の組織では何も起きない (既定 off の回帰点)。
 * 重複 dispatch は maybeCreateAttempt の pending 検査 / DB partial unique が吸収する。
 *
 * $tries = 1: 自動リトライしない (取りこぼしはリコンサイル (v) の管轄 — 二重課金面の安全側)。
 */
final class AutoRechargeTriggerJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $uniqueFor = 30;

    public function __construct(public readonly int $organizationId) {}

    public function uniqueId(): string
    {
        return (string) $this->organizationId;
    }

    public function handle(AutoRechargeService $autoRecharge): void
    {
        // enabled 設定がない org は即 return (opt-in / 既定 off のガード)。
        $configured = TicketAutoRecharge::query()
            ->where('organization_id', $this->organizationId)
            ->where('enabled', true)
            ->exists();
        if (! $configured) {
            return;
        }

        $organization = Organization::query()->find($this->organizationId);
        if (! $organization instanceof Organization) {
            return;
        }

        $attempt = $autoRecharge->maybeCreateAttempt($organization);
        if ($attempt !== null) {
            ExecuteAutoRechargeAttemptJob::dispatch($attempt->id);
        }
    }
}
```

### app/Support/ProductionEnvGuard.php (violations/enforce の流儀)
```php
class ProductionEnvGuard
{
    /**
     * production env に必要な必須項目を検査し、違反メッセージのリストを返す。
     *
     * @return list<string>
     */
    public function violations(): array
    {
        $errors = [];

        $appKeyValue = config('app.key');
        $appKey = is_string($appKeyValue) ? $appKeyValue : '';
        if ($appKey === '') {
            $errors[] = 'APP_KEY is required in production.';
        }

        $cipherKeyValue = config('ciphersweet.providers.string.key');
        $cipherKey = is_string($cipherKeyValue) ? $cipherKeyValue : '';
        if ($cipherKey === '') {
            $errors[] = 'CIPHERSWEET_KEY is required in production (PII encryption key).';
        }


    /**
     * production 起動時に違反があれば例外で fail-fast。
     */
    public function enforce(): void
    {
        $errors = $this->violations();
        if ($errors !== []) {
            throw new RuntimeException(
                "Production env baseline violations:\n- ".implode("\n- ", $errors)
            );
        }
    }

    /**
     * config 値を string list へ正規化する (非 string 要素を除外)。
```

### tests/Support/QueuedJobPopulation.php (母集団の唯一実装)
```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use FilesystemIterator;
use Illuminate\Contracts\Queue\ShouldQueue;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use SplFileInfo;
use Webmozart\Assert\Assert;

/**
 * 「キューに載るクラス」の母集団を決める**唯一の実装**。
 *
 * QueuedJobLeaseInventoryTest (接続 / リース期間の目録) と
 * JobExecutionDedupInventoryTest (重複実行の保証の目録) が**同じ母集団**を見ることを
 * 構造的に保証する (2 実装に分かれていると、片方だけ更新される drift が起きる)。
 *
 * 母集団判定の正本は `ReflectionClass::implementsInterface(ShouldQueue::class)` +
 * `isInstantiable()`。親クラス / trait 経由の実装も拾うため Job だけでなく
 * Mailable / Notification も自動的に母集団へ入る。
 *
 * ★ `class_exists()` により **autoload の副作用を伴う** (既存 QueuedJobLeaseInventoryTest の
 *   方式をそのまま移設したもの)。token parser / composer classmap へ寄せる案はあるが、
 *   既存 gate の振る舞いまで変わるため本設計では踏襲する (方式変更は独立した課題)。
 */
final class QueuedJobPopulation
{
    /** @return list<class-string> */
    public static function shouldQueueClasses(): array
    {
        $classes = [];
        foreach (self::appPhpFiles() as $path) {
            $class = self::classNameForPath($path);
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->isInstantiable() || ! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }

            $classes[] = $reflection->getName();
        }

        sort($classes);

        return $classes;
    }

    /**
     * app/ 配下の PHP ファイル絶対パス一覧。
     *
     * @return list<string>
     */
    public static function appPhpFiles(): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('app'), FilesystemIterator::SKIP_DOTS),
        );

        $paths = [];
        foreach ($iterator as $file) {
            Assert::isInstanceOf($file, SplFileInfo::class);
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $paths[] = $file->getPathname();
        }

        sort($paths);

        return $paths;
    }

    /** app/ 配下のパスを PSR-4 でクラス名へ変換する (純関数)。 */
    public static function classNameForPath(string $path): string
    {
        $appPath = base_path('app').DIRECTORY_SEPARATOR;
        Assert::startsWith($path, $appPath, "app/ 配下ではないパスです: {$path}");

        $relative = substr($path, strlen($appPath), -strlen('.php'));

        return 'App\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
    }
}
```

### config/queue.php (sync と database 系接続)
```php
    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        // 既定接続 (Billing 6 / Mail 2 / Notification 6)。retry_after は **リテラル**で持つ:
        // 静的 gate (QueueWorkerLeaseInvariantTest) は config をテスト環境の値で読むため、
        // env 上書きを残すと「gate は通るが本番の実値は別」を作れてしまう (gate が嘘をつく)。
        // 360s の根拠 (T126 で SDK 既定依存を解消):
        //   外部予算 200s (= Stripe 20s × 呼び出し予算 10 回。App\Support\ExternalClientTimeouts)
        //   + 局所予算 90s = 290s < ワーカー --timeout 300s < retry_after 360s。
        //   序列は ExternalClientTimeoutInventoryTest が厳密不等号で固定する
        //   (docs/architecture.md §キューのリース期間とワーカー制限時間の規約 /
        //    §外部 SDK の待ち上限の規約)。
        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => 360,
            'after_commit' => false,
        ],

        // AI 解析専用 (RunManualAnalysis)。retry_after は job timeout (1,560s) より長く
        // 予約 TTL (1,800s) より短い (AnalysisTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-analysis` を必須登録
        // (docs/architecture.md。滞留は analysis:recover-stale-jobs cron が回収)
        'database-analysis' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'analysis',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // レンダ専用 (RunManualRender)。retry_after は job timeout (1,500s) より長く
        // 予約 TTL (1,800s) より短い (RenderTimeBudgetInvariantTest が連鎖を固定)。
        // 運用契約: worker は `php artisan queue:work database-render` を必須登録
        // (docs/architecture.md。滞留は render:recover-stale-jobs cron が回収)
        'database-render' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'render',
            'retry_after' => 1680,
            'after_commit' => false,
        ],

        // メディア掃除専用 (DeleteTakeObjectsJob)。運用契約: worker は
        // `php artisan queue:work database-media` を必須登録 (docs/architecture.md)
        'database-media' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'media',
            'retry_after' => 300,
            'after_commit' => false,
        ],

```

---

特に確認してほしい点:

1. M3 の `TicketLedgerService::reserve()` の書き換え (参照渡し `&$crossing` + tx を抜けてから通知) に、ロジックエラー・PHPStan level 10 の問題はないか
2. M2 の `CaptureTakeService::delete` / `VideoManualService::delete` / `RenderPipeline::finalize` で「tx 外へ値を持ち出す」形をやめる変更に見落としはないか
3. M6 の guard 仕様 (R1〜R5) と「参照接続」の定義に穴はないか。config の値が想定外の型のときに fail-closed になっているか
4. M7 の 12 テストで「0 件 pin が空振りしない」ことが本当に担保できているか
5. M9 の `JobQueueing` + `DB::transactionLevel()` 観測が意図どおり機能するか (Laravel 12 の実装に照らして)
6. 「保証しないもの」10 項目に誇張・抜けがないか
