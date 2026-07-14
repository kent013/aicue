# 実装レビュー依頼: T046 AIシナリオ生成の導入/総括カット自動挿入 (impl-review Round 1)

## アプリの使命 (North Star)

AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md 正本)

1. テストなしの実装完了報告 (不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen (型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作 (migrate:fresh 等) をエージェント判断で実行
4. response()->json() の直書き (DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び (app/Prompts/ の factory 経由のみ)
6. prompt 文字列のコード直書き (resources/prompts/*.yaml に置く)
7. 操作系 POST の応答での redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI

### ドメイン規約 (シナリオ整合の共有ロック規約)
cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する。新しい書き込み経路は ScenarioWritePathInventoryTest への登録が必須。

## 思考原則 — 全議論に適用
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵(Laravel/Svelte エコシステム)を探せ。機能の名前に立ち返れ。不必要な複雑化を避けろ。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリのコードレビュアー。本 diff は「AI 生成シナリオの前後にサーバ側で決定的に導入カット/総括カットを付与する」機能 (T046) の実装。以下の観点でレビューし、ファイルごとに Critical / Warning / Suggestion を分類し、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を述べよ。

レビュー観点:
- 設計との一致性 (detailed-design.md の施策1-7 を満たすか)
- 正確性 (境界条件・順序・全置換・再掲元が今回生成のみ・長さ制御)
- PHPStan L10 適合 (型 widen なし・typed accessor)
- DTO / JsonResource パターン (response()->json() 直書きなし)
- テスト網羅性 (禁止事項1: 不変条件が Feature/Unit テストで固定されているか)
- セキュリティ (共有ロック規約: materialize は locked manual の terminal tx 内か。書き込み経路が増えていないか)
- 不必要な複雑化がないか

## 設計の要点 (実装が準拠すべき仕様)
- materialize 時にサーバ側で決定的に導入/総括カットを前後挿入。LLM プロンプト・出力スキーマは不変。
- 導入/総括は通常の CutType::Step / ShotType::Hiki のトップレベルカット (v1 は独立 CutType を持たない)。
- 総括の要点再掲は「今回生成の steps」からのみ抽出 (DB 既存 cuts 不参照 = 再生成時に旧シナリオを総括しない)。
- ScenarioBookendBuilder は純関数的 (DB/tx/ロックに触れない)。AnalysisPipeline::finalize の terminal tx 内で wrap → materialize。
- MAX_STEPS(100)=LLM 生成 step 上限 (据え置き)。MAX_TOP_LEVEL_CUTS(102)=手動保存の top-level 上限 (導入/総括 2 込み)。
- 導入/総括の文面は lang/ja/manual.php。builder は line() で ja ロケール固定・未定義キーは LogicException (fail-fast)。

## 実装メモ (レビュー時の判断材料)
- テスト env は APP_LOCALE=en。bookend は v1 Japanese 単一ロケールの動画ドメインコンテンツ (UI i18n ではない) かつ DB 書き込み経路のため、ambient APP_LOCALE に依存させず文面が存在する ja に pin した (ScenarioBookendBuilder::CONTENT_LOCALE)。この判断の妥当性も評価してほしい。
- 品質ゲート: composer test (1735 passed, 2 skipped) / composer phpstan (No errors) / vendor/bin/pint --test (passed) / pnpm typecheck・lint・test(565)・build すべて green。

---

## 実装差分 (git diff)

```diff
diff --git a/app/Http/Requests/Projects/UpdateScenarioRequest.php b/app/Http/Requests/Projects/UpdateScenarioRequest.php
index d596352..74ad185 100644
--- a/app/Http/Requests/Projects/UpdateScenarioRequest.php
+++ b/app/Http/Requests/Projects/UpdateScenarioRequest.php
@@ -78,7 +78,9 @@ public function rules(): array
         return array_merge(
             [
                 'expected_version' => ['required', 'integer', 'min:0'],
-                'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_STEPS],
+                // v1 は定型カットを識別できないため、手動保存の上限は「top-level cut 総数 ≤ 102」で表現する
+                // (生成 100 手順 + 導入/総括 2 の materialized をそのまま再保存できる)。内訳 (通常/定型) は強制しない。
+                'steps' => ['present', 'array', 'max:'.ScenarioLimits::MAX_TOP_LEVEL_CUTS],
                 // points キー欠落はクライアント直列化バグ。行単位で明示エラーにする
                 'steps.*' => ['array', 'required_array_keys:points'],
                 'steps.*.points' => ['present', 'array', 'max:'.ScenarioLimits::MAX_POINTS_PER_STEP],
diff --git a/app/Services/Manual/AnalysisPipeline.php b/app/Services/Manual/AnalysisPipeline.php
index 0397927..824c92c 100644
--- a/app/Services/Manual/AnalysisPipeline.php
+++ b/app/Services/Manual/AnalysisPipeline.php
@@ -47,6 +47,7 @@ public function __construct(
         private readonly SopTextExtractor $extractor,
         private readonly TicketLedgerService $tickets,
         private readonly NotificationCenterService $notifications,
+        private readonly ScenarioBookendBuilder $bookend,
     ) {}
 
     public function run(int $analysisJobId): void
@@ -207,9 +208,13 @@ private function finalize(AnalysisJob $job, GeneratedScenarioData $generated): b
             $lockedManual = $project->manuals()
                 ->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
 
+            // 導入/総括カットを terminal tx 内 (locked manual 参照) で決定的に前後付与する。
+            // 再掲元は今回生成の steps のみ (DB 既存 cuts 不参照)。
+            $steps = $this->bookend->wrap($lockedManual, $generated->toScenarioSteps());
+
             // cuts + version + status(analyzing→ready) はロック済み manual 前提メソッドで反映
             // (内側 transaction を張らない。analyzing guard 違反は LogicException → 全体 rollback)
-            $this->scenarios->materializeIntoLockedManual($lockedManual, $generated->toScenarioSteps());
+            $this->scenarios->materializeIntoLockedManual($lockedManual, $steps);
 
             // ロック 3: reservation/org 行 (TicketLedgerService::commit 内部。savepoint)
             $reservation = $locked->ticketReservation;
diff --git a/app/Services/Manual/ScenarioBookendBuilder.php b/app/Services/Manual/ScenarioBookendBuilder.php
new file mode 100644
index 0000000..99cbacd
--- /dev/null
+++ b/app/Services/Manual/ScenarioBookendBuilder.php
@@ -0,0 +1,204 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\DataTransferObjects\Manual\ScenarioStepInput;
+use App\Enums\Manual\ShotType;
+use App\Models\VideoManual;
+use App\Support\Manual\ScenarioLimits;
+use Illuminate\Support\Facades\Lang;
+use LogicException;
+use Webmozart\Assert\Assert;
+
+/**
+ * AI 生成シナリオの前後へ導入/総括カットを決定的に付与する (概念設計 §改善アイデア)。
+ *
+ * - 純関数的: DB / トランザクション / ロックに触れない。呼び出し側 (AnalysisPipeline::finalize の
+ *   terminal tx 内) が locked manual と今回生成の steps を渡す。
+ * - 追加カットは既存 CutType::Step / ShotType::Hiki のトップレベル step として表現する
+ *   (v1 は独立 CutType を持たない。doc/10 §10.1 の step/point 限定を維持)。
+ * - 総括の要点再掲は「今回生成の $generatedSteps」からのみ抽出する (DB 既存 cuts 不参照 =
+ *   再生成時に旧シナリオを総括する事故を構造的に排除)。
+ */
+final class ScenarioBookendBuilder
+{
+    /**
+     * 導入/総括の定型文面を解決する固定ロケール。
+     * v1 は Japanese 単一ロケールの動画マニュアル (North Star) であり、この文面は UI i18n ではなく
+     * 「動画に載る日本語ドメインコンテンツ」。materialize は DB 書き込み経路のため、ambient な
+     * APP_LOCALE (テストは en) に依存させず、文面が存在する ja に pin して決定性・堅牢性を担保する。
+     */
+    private const string CONTENT_LOCALE = 'ja';
+
+    /**
+     * @param  list<ScenarioStepInput>  $generatedSteps
+     * @return list<ScenarioStepInput> [導入, ...generatedSteps, 総括]
+     */
+    public function wrap(VideoManual $lockedManual, array $generatedSteps): array
+    {
+        $title = $this->truncatedTitle($lockedManual->title);
+
+        $intro = $this->intro($title);
+        $summary = $this->summary($title, $generatedSteps);
+
+        return [$intro, ...$generatedSteps, $summary];
+    }
+
+    private function intro(string $title): ScenarioStepInput
+    {
+        return new ScenarioStepInput(
+            id: null,
+            scene: $this->line('manual.bookend.intro.scene'),
+            shotType: ShotType::Hiki,
+            shootingPoint: null,
+            narration: $this->line('manual.bookend.intro.narration', ['title' => $title]),
+            subtitlePrimary: $this->clamp(
+                $this->line('manual.bookend.intro.subtitle_primary', ['title' => $title]),
+                ScenarioLimits::MAX_SUBTITLE_PRIMARY_CHARS,
+            ),
+            subtitleSecondary: $this->line('manual.bookend.intro.subtitle_secondary', ['title' => $title]),
+            materialType: null,
+            staticDisplaySeconds: null,
+            points: [],
+        );
+    }
+
+    /** @param list<ScenarioStepInput> $generatedSteps */
+    private function summary(string $title, array $generatedSteps): ScenarioStepInput
+    {
+        $secondary = $this->summarySecondary($title, $generatedSteps);
+
+        return new ScenarioStepInput(
+            id: null,
+            scene: $this->line('manual.bookend.summary.scene'),
+            shotType: ShotType::Hiki,
+            shootingPoint: null,
+            narration: $this->line('manual.bookend.summary.narration', ['title' => $title]),
+            subtitlePrimary: $this->line('manual.bookend.summary.subtitle_primary'),
+            subtitleSecondary: $this->clamp($secondary, ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS),
+            materialType: null,
+            staticDisplaySeconds: null,
+            points: [],
+        );
+    }
+
+    /**
+     * 総括 subtitle_secondary の決定的組み立て（Codex R2 反映: lang 接頭辞込みの「完成文」で長さ判定）。
+     *  - 再掲候補（3 段）: (i) point.subtitlePrimary 非空を深さ優先 → (ii) 0 件なら top-level
+     *    step.subtitlePrimary 非空 → (iii) いずれも 0 件なら定型フォールバック文面。
+     *  - 件数 N (config 既定 3、`max(1,$max)` で下限 1)。「／」連結し接頭辞付き完成文を作る。
+     *  - **完成文（接頭辞込み）**が上限超過なら件数を減らす（>1 件のみ）。1 件でも超過なら最後に
+     *    完成文を文字単位 truncate（接頭辞ごと収める）。
+     *
+     * @param  list<ScenarioStepInput>  $generatedSteps
+     */
+    private function summarySecondary(string $title, array $generatedSteps): string
+    {
+        $candidates = $this->recapCandidates($generatedSteps);
+        if ($candidates === []) {
+            return $this->clamp(
+                $this->line('manual.bookend.summary.subtitle_secondary_fallback', ['title' => $title]),
+                ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS,
+            );
+        }
+
+        $n = max(1, config()->integer('manual.summary_recap_max_points'));
+        $picked = array_slice($candidates, 0, $n);
+
+        // 件数優先: 完成文（lang 接頭辞込み）で上限判定
+        while (count($picked) > 1
+            && mb_strlen($this->renderRecap($picked)) > ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS) {
+            array_pop($picked);
+        }
+
+        // 1 件でも超過するなら完成文を文字単位 truncate
+        return $this->clamp($this->renderRecap($picked), ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
+    }
+
+    /**
+     * 要点再掲の完成文 (lang 接頭辞込み)。PHPStan L10 のため closure でなく typed メソッドに分離。
+     *
+     * @param  list<string>  $items
+     */
+    private function renderRecap(array $items): string
+    {
+        return $this->line(
+            'manual.bookend.summary.subtitle_secondary_recap',
+            ['points' => implode('／', $items)],
+        );
+    }
+
+    /**
+     * 再掲候補の決定的抽出（3 段の (i)(ii) まで。空なら空配列）。
+     *
+     * @param  list<ScenarioStepInput>  $generatedSteps
+     * @return list<string>
+     */
+    private function recapCandidates(array $generatedSteps): array
+    {
+        $candidates = [];
+        foreach ($generatedSteps as $step) {
+            foreach ($step->points as $point) {
+                $v = $this->normalize($point->subtitlePrimary);
+                if ($v !== '') {
+                    $candidates[] = $v;
+                }
+            }
+        }
+        if ($candidates !== []) {
+            return $candidates;
+        }
+        foreach ($generatedSteps as $step) {
+            $v = $this->normalize($step->subtitlePrimary);
+            if ($v !== '') {
+                $candidates[] = $v;
+            }
+        }
+
+        return $candidates;
+    }
+
+    private function truncatedTitle(string $title): string
+    {
+        return $this->clamp(
+            $this->normalize($title),
+            config()->integer('manual.scenario_bookend_title_max_chars'),
+        );
+    }
+
+    private function clamp(string $value, int $max): string
+    {
+        return mb_strlen($value) > $max ? mb_substr($value, 0, $max) : $value;
+    }
+
+    /** 全角空白含めた前後空白除去 (Codex 反映。trim は全角空白を落とせない)。null は '' 扱い。 */
+    private function normalize(?string $value): string
+    {
+        if ($value === null) {
+            return '';
+        }
+        $result = preg_replace('/^[\p{Z}\s]+|[\p{Z}\s]+$/u', '', $value);
+        Assert::string($result); // preg エラー(null)を空文字で握りつぶさず異常を露出 (Codex 反映)
+
+        return $result;
+    }
+
+    /**
+     * lang 取得を string に確定させる typed accessor (PHPStan L10。__() は array|string を返しうる)。
+     * 未定義キーは静かに見逃さず LogicException (fail-fast。lang 追加漏れを即検出。Codex 反映)。
+     *
+     * @param  array<string, string>  $replace
+     */
+    private function line(string $key, array $replace = []): string
+    {
+        if (! Lang::has($key, self::CONTENT_LOCALE)) {
+            throw new LogicException("シナリオ導入/総括の lang キーが未定義: {$key}");
+        }
+        $value = trans($key, $replace, self::CONTENT_LOCALE);
+        Assert::string($value); // has() 済みで配列ノードではないことを型に閉じる
+
+        return $value;
+    }
+}
diff --git a/app/Support/Manual/ScenarioLimits.php b/app/Support/Manual/ScenarioLimits.php
index 1f9ef05..02fbfb4 100644
--- a/app/Support/Manual/ScenarioLimits.php
+++ b/app/Support/Manual/ScenarioLimits.php
@@ -10,8 +10,15 @@
  */
 final class ScenarioLimits
 {
+    /** LLM 生成/手動編集の「手順 step」上限 (生成 DTO 検証が強制。DoS/桁 guard)。 */
     public const int MAX_STEPS = 100;
 
+    /**
+     * 手動保存で許容する top-level cut 総数上限 (生成 100 手順 + 導入/総括 2 の
+     * materialized をそのまま再保存できる)。内訳 (通常/定型) は v1 では強制しない。
+     */
+    public const int MAX_TOP_LEVEL_CUTS = self::MAX_STEPS + 2;
+
     public const int MAX_POINTS_PER_STEP = 20;
 
     public const int MAX_SCENE_CHARS = 1000;
diff --git a/config/manual.php b/config/manual.php
index 9d2ff9c..2526de4 100644
--- a/config/manual.php
+++ b/config/manual.php
@@ -26,6 +26,12 @@
     // stale ジョブ回復閾値 (分)。queued: dispatch 喪失、running: worker 異常終了
     'analysis_stale_after_minutes' => 30,
 
+    // ── シナリオ導入/総括カット (概念設計 §改善アイデア) ──────────────
+    // 総括カットの要点再掲に載せる最大件数 (先頭から)。0 以下は builder が 1 件扱いに補正。
+    'summary_recap_max_points' => 3,
+    // 導入/総括の作業名補間で用いるタイトルの truncate 上限 (subtitle_primary=100 に収める)。
+    'scenario_bookend_title_max_chars' => 60,
+
     // SOP アップロード上限 (bytes) と許可拡張子 (mime rule 用)
     'source_document_max_bytes' => 20 * 1024 * 1024,
     'source_document_mimes' => ['pdf', 'xlsx', 'xls', 'txt'],
diff --git a/lang/ja/manual.php b/lang/ja/manual.php
new file mode 100644
index 0000000..9550a00
--- /dev/null
+++ b/lang/ja/manual.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+// シナリオ導入/総括カットの定型文面 (DB の cut コンテンツ。プロンプトではないため resources/prompts 対象外)。
+// :title は VideoManual->title を truncate した作業名。:points は決定的に抽出した要点再掲。
+return [
+    'bookend' => [
+        'intro' => [
+            'scene' => '作業全体の俯瞰（導入）',
+            'narration' => 'この動画では「:title」の手順と注意点を示します。',
+            'subtitle_primary' => ':title',
+            'subtitle_secondary' => 'この動画では「:title」の手順と注意点を確認します。',
+        ],
+        'summary' => [
+            'scene' => '作業全体の俯瞰（総括）',
+            'narration' => '以上で「:title」は完了です。要点を振り返ります。',
+            'subtitle_primary' => '要点の再確認',
+            // 要点再掲あり
+            'subtitle_secondary_recap' => '要点の再確認：:points',
+            // 再掲元が無い場合のフォールバック (締めカット)
+            'subtitle_secondary_fallback' => '以上で「:title」の作業は完了です。安全に留意して作業しましょう。',
+        ],
+    ],
+];
diff --git a/tests/Feature/Llm/CannedAnalysisPipelineTest.php b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
index f3f9ed8..80a9ddd 100644
--- a/tests/Feature/Llm/CannedAnalysisPipelineTest.php
+++ b/tests/Feature/Llm/CannedAnalysisPipelineTest.php
@@ -4,6 +4,7 @@
 
 use App\Enums\Manual\CutType;
 use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\ShotType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Models\AnalysisJob;
 use App\Models\Project;
@@ -55,16 +56,24 @@
     expect($job->status)->toBe(JobStatus::Succeeded);
     expect($job->error)->toBeNull();
 
-    // manual: cuts ツリー (step 1 + point 1) / ready。
+    // manual: cuts ツリー (導入 + 生成 step + 生成 point + 総括) / ready。
     $manual->refresh();
     expect($manual->status)->toBe(VideoManualStatus::Ready);
-    $cuts = $manual->cuts()->get();
-    expect($cuts)->toHaveCount(2);
-    $step = $cuts->firstWhere('type', CutType::Step);
+    $cuts = $manual->cuts()->orderBy('sort_order')->get();
+    expect($cuts)->toHaveCount(4); // 導入 + step + point + 総括
+    $topLevel = $cuts->where('parent_cut_id', null)->values();
+    expect($topLevel)->toHaveCount(3);
+    // 先頭=導入 / 末尾=総括: 位置・型・shot_type・親子を退行検出 (件数のみに頼らない)
+    expect($topLevel->first()->parent_cut_id)->toBeNull();
+    expect($topLevel->first()->shot_type)->toBe(ShotType::Hiki);
+    expect($topLevel->first()->narration)->toContain($manual->title);
+    expect($topLevel->last()->parent_cut_id)->toBeNull();
+    expect($topLevel->last()->shot_type)->toBe(ShotType::Hiki);
+    // 生成 step / point は従来どおり存在し、point は中間 step にぶら下がる
+    $generatedStep = $topLevel->get(1); // 導入(0) と 総括(2) の間
     $point = $cuts->firstWhere('type', CutType::Point);
-    expect($step)->not->toBeNull();
     expect($point)->not->toBeNull();
-    expect($point->parent_cut_id)->toBe($step->id);
+    expect($point->parent_cut_id)->toBe($generatedStep->id);
 
     // 実 LLM provider へは 1 度も到達していない (fake の recorded に 3 段が記録されている)。
     $fake = Prompt::getFake();
diff --git a/tests/Feature/Projects/AnalysisPipelineTest.php b/tests/Feature/Projects/AnalysisPipelineTest.php
index 3f9ff06..85497cf 100644
--- a/tests/Feature/Projects/AnalysisPipelineTest.php
+++ b/tests/Feature/Projects/AnalysisPipelineTest.php
@@ -6,6 +6,7 @@
 use App\Enums\Billing\TicketReservationStatus;
 use App\Enums\Manual\CutType;
 use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\ShotType;
 use App\Enums\Manual\VideoManualStatus;
 use App\Models\AnalysisJob;
 use App\Models\Billing\TicketReservation;
@@ -136,11 +137,23 @@ function fakeSuccessfulLlm(): void
     $manual->refresh();
     expect($manual->status)->toBe(VideoManualStatus::Ready);
     expect($manual->scenario_version)->toBe(1);
+    // 導入 + 生成 step + 生成 point + 総括 = 4 (top-level は 導入/生成 step/総括 の 3)
     $cuts = $manual->cuts()->orderBy('sort_order')->get();
-    expect($cuts)->toHaveCount(2);
-    $step = $cuts->firstWhere('type', CutType::Step);
+    expect($cuts)->toHaveCount(4);
+    $topLevel = $cuts->where('parent_cut_id', null)->values();
+    expect($topLevel)->toHaveCount(3);
+    // 先頭=導入 / 末尾=総括 (位置・型・shot_type・親子を退行検出)
+    $intro = $topLevel->first();
+    expect($intro->parent_cut_id)->toBeNull();
+    expect($intro->shot_type)->toBe(ShotType::Hiki);
+    expect($intro->narration)->toContain($manual->title);
+    $summary = $topLevel->last();
+    expect($summary->parent_cut_id)->toBeNull();
+    expect($summary->shot_type)->toBe(ShotType::Hiki);
+    // 生成 step は導入と総括の間 / 生成 point はその step 配下 (fixture 由来の本文は不変)
+    $step = $topLevel->get(1);
     $point = $cuts->firstWhere('type', CutType::Point);
-    expect($step)->not->toBeNull();
+    expect($step->type)->toBe(CutType::Step);
     expect($point)->not->toBeNull();
     expect($point->parent_cut_id)->toBe($step->id);
     expect($step->scene)->toBe('ネジ締めの全体');
@@ -355,7 +368,8 @@ function fakeSuccessfulLlm(): void
 
     expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
     $manual->refresh();
-    expect($manual->cuts()->count())->toBe(2);
+    // 導入 + 生成 step + 生成 point + 総括 = 4 (旧 cut は全置換で消える)
+    expect($manual->cuts()->count())->toBe(4);
     expect($manual->cuts()->whereKey($oldCut->id)->exists())->toBeFalse();
 });
 
diff --git a/tests/Feature/Projects/ScenarioBookendMaterializeTest.php b/tests/Feature/Projects/ScenarioBookendMaterializeTest.php
new file mode 100644
index 0000000..0c03d44
--- /dev/null
+++ b/tests/Feature/Projects/ScenarioBookendMaterializeTest.php
@@ -0,0 +1,292 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\CutType;
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\ShotType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\AnalysisJob;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\SourceDocument;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Billing\TicketLedgerService;
+use App\Services\Manual\AnalysisPipeline;
+use App\Support\Manual\ScenarioLimits;
+use Illuminate\Support\Facades\Http;
+use Illuminate\Support\Facades\Storage;
+use Kent013\PrismPrompt\Prompt;
+use Kent013\PrismPrompt\Testing\TextResponseFake;
+
+/*
+ * 導入/総括カットの materialize 不変条件 (T046)。
+ * - 生成シナリオの前後に導入(先頭 top-level)/総括(末尾 top-level)が決定的に付与される
+ * - 再解析は全置換 (導入/総括が重複しない・再掲元は今回生成のみ)
+ * - MAX_STEPS(100) 生成 → top-level 102 が切り捨てなく materialize され編集 round-trip できる
+ */
+
+beforeEach(function (): void {
+    // executeSync は fake 中も PromptExecutionCompleted を発火し FX 解決 (HTTP) を試みるため stray を防ぐ
+    Http::fake(['*' => Http::response(['base' => 'USD', 'rates' => ['JPY' => 150.0]])]);
+});
+
+/**
+ * queued job 一式 (analyzing manual + 保存済み txt SOP + チケット残高)。
+ *
+ * @return array{Project, VideoManual, AnalysisJob, SourceDocument, User}
+ */
+function bookendPipelineContext(string $title = 'ネジ締め作業'): array
+{
+    Storage::fake();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => 'analyzing',
+        'title' => $title,
+    ]);
+    $path = "projects/{$project->id}/manuals/{$manual->id}/source-documents/sop.txt";
+    Storage::put($path, str_repeat("手順: 部品を取り付けてネジを締める。急所: トルクは 5Nm。\n", 5));
+    $document = SourceDocument::factory()->forManual($manual)->create([
+        'file_path' => $path,
+        'mime' => 'text/plain',
+    ]);
+    $job = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
+    app(TicketLedgerService::class)->grant($organization, 5, 'テスト残高');
+
+    return [$project, $manual, $job, $document, $owner];
+}
+
+function bookendExtractJson(): string
+{
+    return json_encode([
+        'header' => ['title' => 'SOP', 'department' => null, 'revision' => null],
+        'sections' => [[
+            'title' => null,
+            'steps' => [[
+                'no' => 1,
+                'work_process' => 'ネジを締める',
+                'work_points' => ['トルクレンチを使う'],
+                'safety_points' => [],
+                'quality_points' => ['トルク 5Nm'],
+                'pm_points' => [],
+            ]],
+        ]],
+    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+}
+
+function bookendDecomposeJson(): string
+{
+    return json_encode([
+        'steps' => [['no' => 1, 'action' => 'ネジを締める', 'points' => ['トルクは 5Nm']]],
+    ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+}
+
+/**
+ * scenario-generation 出力 JSON を組み立てる。
+ *
+ * @param  list<array{primary: ?string, points?: list<?string>}>  $steps
+ */
+function bookendScenarioJson(array $steps): string
+{
+    $cuts = [];
+    $no = 0;
+    foreach ($steps as $step) {
+        $no++;
+        $stepNo = $no;
+        $cuts[] = [
+            'no' => $stepNo, 'type' => 'step', 'parent_no' => null,
+            'scene' => "手順{$stepNo}のシーン", 'shot_type' => 'hiki', 'shooting_point' => null,
+            'narration' => "手順{$stepNo}の説明", 'subtitle_primary' => $step['primary'],
+            'subtitle_secondary' => "手順{$stepNo}の補足",
+        ];
+        foreach ($step['points'] ?? [] as $pointPrimary) {
+            $no++;
+            $cuts[] = [
+                'no' => $no, 'type' => 'point', 'parent_no' => $stepNo,
+                'scene' => '急所のシーン', 'shot_type' => 'yori', 'shooting_point' => '手元を大きく',
+                'narration' => '急所の説明', 'subtitle_primary' => $pointPrimary,
+                'subtitle_secondary' => '急所の補足',
+            ];
+        }
+    }
+
+    return json_encode(['cuts' => $cuts], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
+}
+
+/** 3 段 (extract / decompose / generate) の Prompt fake を張る (generate は与えた scenario JSON)。 */
+function bookendFakeLlm(string $scenarioJson): void
+{
+    Prompt::fake([
+        TextResponseFake::make()->withText(bookendExtractJson()),
+        TextResponseFake::make()->withText(bookendDecomposeJson()),
+        TextResponseFake::make()->withText($scenarioJson),
+    ]);
+}
+
+test('初回生成: 先頭 top-level=導入 / 末尾 top-level=総括 / 間に生成 step・point', function (): void {
+    [, $manual, $job] = bookendPipelineContext('ネジ締め作業');
+    bookendFakeLlm(bookendScenarioJson([
+        ['primary' => '5Nm で締める', 'points' => ['トルク確認']],
+    ]));
+
+    app(AnalysisPipeline::class)->run($job->id);
+
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+    $manual->refresh();
+    expect($manual->status)->toBe(VideoManualStatus::Ready);
+
+    $topLevel = $manual->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
+    // 導入 + 生成 step + 総括
+    expect($topLevel)->toHaveCount(3);
+
+    $intro = $topLevel->first();
+    expect($intro->parent_cut_id)->toBeNull();
+    expect($intro->type)->toBe(CutType::Step);
+    expect($intro->shot_type)->toBe(ShotType::Hiki);
+    expect($intro->narration)->toContain('ネジ締め作業');
+    // 導入 scene は lang 由来 (文言変更耐性のため lang キーで照合)
+    expect($intro->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));
+
+    $summary = $topLevel->last();
+    expect($summary->parent_cut_id)->toBeNull();
+    expect($summary->type)->toBe(CutType::Step);
+    expect($summary->shot_type)->toBe(ShotType::Hiki);
+    expect($summary->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
+    // 総括 subtitle_secondary は生成 point 由来の再掲を含む
+    expect($summary->subtitle_secondary)->toContain('トルク確認');
+
+    // 生成 step は導入と総括の間 / 生成 point はその step 配下
+    $generatedStep = $topLevel->get(1);
+    expect($generatedStep->scene)->toBe('手順1のシーン');
+    $point = $manual->cuts()->where('type', CutType::Point->value)->get();
+    expect($point)->toHaveCount(1);
+    expect($point->first()->parent_cut_id)->toBe($generatedStep->id);
+});
+
+test('再解析は全置換: 導入/総括が重複せず先頭1件・末尾1件のみ', function (): void {
+    [, $manual, $job, $document] = bookendPipelineContext();
+    // 事前に無関係な cut がある状態でも全置換される
+    Cut::factory()->forManual($manual)->create();
+
+    bookendFakeLlm(bookendScenarioJson([['primary' => '要点A', 'points' => []]]));
+    app(AnalysisPipeline::class)->run($job->id);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    // 2 回目の解析 (新しい queued job を発行して再実行)
+    $manual->refresh();
+    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();
+    $job2 = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
+    bookendFakeLlm(bookendScenarioJson([['primary' => '要点B', 'points' => []]]));
+    app(AnalysisPipeline::class)->run($job2->id);
+    expect($job2->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    $topLevel = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
+    // 導入 + 生成 step + 総括 = 3 (重複なし)
+    expect($topLevel)->toHaveCount(3);
+    expect($topLevel->first()->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));
+    expect($topLevel->last()->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
+});
+
+test('再生成の総括再掲は今回生成のみを参照する (旧 cut 不参照)', function (): void {
+    [, $manual, $job, $document] = bookendPipelineContext();
+
+    bookendFakeLlm(bookendScenarioJson([['primary' => '旧要点', 'points' => ['旧急所']]]));
+    app(AnalysisPipeline::class)->run($job->id);
+    $summary1 = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
+    expect($summary1->subtitle_secondary)->toContain('旧急所');
+
+    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();
+    $job2 = AnalysisJob::factory()->forManual($manual)->forDocument($document)->create();
+    bookendFakeLlm(bookendScenarioJson([['primary' => '新要点', 'points' => ['新急所']]]));
+    app(AnalysisPipeline::class)->run($job2->id);
+
+    $summary2 = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
+    expect($summary2->subtitle_secondary)->toContain('新急所');
+    expect($summary2->subtitle_secondary)->not->toContain('旧急所');
+});
+
+test('生成 point / step subtitle が全欠なら総括は定型フォールバック文面', function (): void {
+    [, $manual, $job] = bookendPipelineContext('配線作業');
+    bookendFakeLlm(bookendScenarioJson([['primary' => null, 'points' => [null]]]));
+
+    app(AnalysisPipeline::class)->run($job->id);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    $summary = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get()->last();
+    expect($summary->subtitle_secondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_fallback', [
+        'title' => '配線作業',
+    ], 'ja'));
+});
+
+test('MAX_STEPS(100) 生成 → top-level 102 が切り捨てなく materialize される', function (): void {
+    [, $manual, $job] = bookendPipelineContext();
+    $steps = [];
+    for ($i = 1; $i <= ScenarioLimits::MAX_STEPS; $i++) {
+        $steps[] = ['primary' => "要点{$i}", 'points' => []];
+    }
+    bookendFakeLlm(bookendScenarioJson($steps));
+
+    app(AnalysisPipeline::class)->run($job->id);
+    expect($job->refresh()->status)->toBe(JobStatus::Succeeded);
+
+    $topLevel = $manual->refresh()->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
+    // 導入 + 100 生成 step + 総括
+    expect($topLevel)->toHaveCount(ScenarioLimits::MAX_TOP_LEVEL_CUTS);
+    expect($topLevel->first()->scene)->toBe(trans('manual.bookend.intro.scene', [], 'ja'));
+    expect($topLevel->last()->scene)->toBe(trans('manual.bookend.summary.scene', [], 'ja'));
+});
+
+test('materialize された 102 件 top-level を編集画面から再保存できる (MAX_TOP_LEVEL_CUTS 整合)', function (): void {
+    [$project, $manual, $job, , $owner] = bookendPipelineContext();
+    $steps = [];
+    for ($i = 1; $i <= ScenarioLimits::MAX_STEPS; $i++) {
+        $steps[] = ['primary' => "要点{$i}", 'points' => []];
+    }
+    bookendFakeLlm(bookendScenarioJson($steps));
+    app(AnalysisPipeline::class)->run($job->id);
+
+    $manual->refresh();
+    $topLevel = $manual->cuts()->whereNull('parent_cut_id')->orderBy('sort_order')->get();
+    expect($topLevel)->toHaveCount(ScenarioLimits::MAX_TOP_LEVEL_CUTS);
+
+    // 全 102 top-level を payload 化 (points は各 step 配下を復元)
+    $payloadSteps = $topLevel->map(function (Cut $cut) use ($manual): array {
+        $points = $manual->cuts()->where('parent_cut_id', $cut->id)->orderBy('sort_order')->get()
+            ->map(fn (Cut $p): array => [
+                'id' => $p->id,
+                'scene' => $p->scene,
+                'shot_type' => $p->shot_type->value,
+                'shooting_point' => $p->shooting_point,
+                'narration' => $p->narration,
+                'subtitle_primary' => $p->subtitle_primary,
+                'subtitle_secondary' => $p->subtitle_secondary,
+                'material_type' => $p->material_type?->value,
+                'static_display_seconds' => $p->static_display_seconds,
+            ])->all();
+
+        return [
+            'id' => $cut->id,
+            'scene' => $cut->scene,
+            'shot_type' => $cut->shot_type->value,
+            'shooting_point' => $cut->shooting_point,
+            'narration' => $cut->narration,
+            'subtitle_primary' => $cut->subtitle_primary,
+            'subtitle_secondary' => $cut->subtitle_secondary,
+            'material_type' => $cut->material_type?->value,
+            'static_display_seconds' => $cut->static_display_seconds,
+            'points' => $points,
+        ];
+    })->all();
+
+    $version = $manual->scenario_version;
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => $version, 'steps' => $payloadSteps],
+    )->assertOk()->assertJsonPath('scenario_version', $version + 1);
+
+    $manual->refresh();
+    expect($manual->scenario_version)->toBe($version + 1);
+    expect($manual->cuts()->whereNull('parent_cut_id')->count())->toBe(ScenarioLimits::MAX_TOP_LEVEL_CUTS);
+});
diff --git a/tests/Feature/Projects/ScenarioUpdateTest.php b/tests/Feature/Projects/ScenarioUpdateTest.php
index 681b436..79a389b 100644
--- a/tests/Feature/Projects/ScenarioUpdateTest.php
+++ b/tests/Feature/Projects/ScenarioUpdateTest.php
@@ -10,6 +10,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Support\Manual\ScenarioLimits;
 use Inertia\Testing\AssertableInertia as Assert;
 
 /*
@@ -506,9 +507,10 @@ function scenarioRowFromCut(Cut $cut): array
     [, $owner, $project, $manual] = scenarioTestContext();
     $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";
 
+    // top-level 上限は MAX_TOP_LEVEL_CUTS(=102。生成 100 + 導入/総括 2)。超過 (103) で 422
     $this->actingAs($owner)->putJson($url, [
         'expected_version' => 0,
-        'steps' => array_fill(0, 101, scenarioStepPayload()),
+        'steps' => array_fill(0, ScenarioLimits::MAX_TOP_LEVEL_CUTS + 1, scenarioStepPayload()),
     ])->assertStatus(422)->assertJsonValidationErrors('steps');
 
     $this->actingAs($owner)->putJson($url, [
diff --git a/tests/Unit/Manual/ScenarioBookendBuilderTest.php b/tests/Unit/Manual/ScenarioBookendBuilderTest.php
new file mode 100644
index 0000000..c30ab42
--- /dev/null
+++ b/tests/Unit/Manual/ScenarioBookendBuilderTest.php
@@ -0,0 +1,220 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\ScenarioPointInput;
+use App\DataTransferObjects\Manual\ScenarioStepInput;
+use App\Enums\Manual\ShotType;
+use App\Models\VideoManual;
+use App\Services\Manual\ScenarioBookendBuilder;
+use App\Support\Manual\ScenarioLimits;
+
+/*
+ * ScenarioBookendBuilder の抽出/組み立て規則 (純関数)。
+ * - 先頭=導入 / 末尾=総括 / 中間=渡した steps 保持
+ * - 総括 subtitle_secondary の 3 段フォールバック (point → step → 定型) と長さ制御
+ * - normalize (全角空白) / title truncate / lang キー欠落 fail-fast
+ */
+
+/**
+ * @param  list<ScenarioPointInput>  $points
+ */
+function bookendStep(?string $subtitlePrimary = null, array $points = []): ScenarioStepInput
+{
+    return new ScenarioStepInput(
+        id: null,
+        scene: '手順シーン',
+        shotType: ShotType::Yori,
+        shootingPoint: null,
+        narration: '手順の説明',
+        subtitlePrimary: $subtitlePrimary,
+        subtitleSecondary: '手順の補足',
+        materialType: null,
+        staticDisplaySeconds: null,
+        points: $points,
+    );
+}
+
+function bookendPoint(?string $subtitlePrimary): ScenarioPointInput
+{
+    return new ScenarioPointInput(
+        id: null,
+        scene: '急所シーン',
+        shotType: ShotType::Yori,
+        shootingPoint: null,
+        narration: '急所の説明',
+        subtitlePrimary: $subtitlePrimary,
+        subtitleSecondary: '急所の補足',
+        materialType: null,
+        staticDisplaySeconds: null,
+    );
+}
+
+function bookendManual(string $title = 'ネジ締め作業'): VideoManual
+{
+    return VideoManual::factory()->make(['title' => $title]);
+}
+
+test('wrap は先頭=導入・末尾=総括・中間=渡した steps を順序保持で返す', function (): void {
+    $steps = [bookendStep('手順A'), bookendStep('手順B')];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+
+    expect($result)->toHaveCount(4);
+    // 中間は渡した step を同一オブジェクトで保持
+    expect($result[1])->toBe($steps[0]);
+    expect($result[2])->toBe($steps[1]);
+});
+
+test('導入カットは Hiki / points=[] / id=null / narration に作業名補間 / subtitle_primary <=100', function (): void {
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual('ネジ締め作業'), [bookendStep('手順A')]);
+
+    $intro = $result[0];
+    expect($intro->id)->toBeNull();
+    expect($intro->shotType)->toBe(ShotType::Hiki);
+    expect($intro->points)->toBe([]);
+    expect($intro->narration)->toContain('ネジ締め作業');
+    expect(mb_strlen((string) $intro->subtitlePrimary))->toBeLessThanOrEqual(ScenarioLimits::MAX_SUBTITLE_PRIMARY_CHARS);
+});
+
+test('総括カットは Hiki / points=[] / id=null で末尾に置かれる', function (): void {
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), [bookendStep('手順A')]);
+
+    $summary = $result[array_key_last($result)];
+    expect($summary->id)->toBeNull();
+    expect($summary->shotType)->toBe(ShotType::Hiki);
+    expect($summary->points)->toBe([]);
+});
+
+test('総括再掲は point.subtitle_primary を先頭 N 件「／」連結する (config 既定 3)', function (): void {
+    $steps = [
+        bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2')]),
+        bookendStep('手順B', [bookendPoint('急所3'), bookendPoint('急所4')]),
+    ];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    // 既定 3 件: 急所1／急所2／急所3
+    expect($summary->subtitleSecondary)->toContain('急所1');
+    expect($summary->subtitleSecondary)->toContain('急所2');
+    expect($summary->subtitleSecondary)->toContain('急所3');
+    expect($summary->subtitleSecondary)->not->toContain('急所4');
+    // lang 接頭辞込みの完成文
+    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_recap', [
+        'points' => '急所1／急所2／急所3',
+    ], 'ja'));
+});
+
+test('summary_recap_max_points で再掲件数が可変になる', function (): void {
+    config(['manual.summary_recap_max_points' => 2]);
+    $steps = [bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2'), bookendPoint('急所3')])];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect($summary->subtitleSecondary)->toContain('急所2');
+    expect($summary->subtitleSecondary)->not->toContain('急所3');
+});
+
+test('point が全て空なら top-level step.subtitle_primary へフォールバックする', function (): void {
+    $steps = [
+        bookendStep('手順A', [bookendPoint(null)]),
+        bookendStep('手順B'),
+    ];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect($summary->subtitleSecondary)->toContain('手順A');
+    expect($summary->subtitleSecondary)->toContain('手順B');
+});
+
+test('point / step ともに空なら定型フォールバック文面を使う', function (): void {
+    $steps = [bookendStep(null, [bookendPoint(null)])];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual('配線作業'), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_fallback', [
+        'title' => '配線作業',
+    ], 'ja'));
+});
+
+test('全角空白のみの subtitle_primary は再掲元に採らない (normalize)', function (): void {
+    $steps = [
+        bookendStep('手順A', [bookendPoint('　　'), bookendPoint('急所X')]),
+    ];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect($summary->subtitleSecondary)->toContain('急所X');
+    // 全角空白は候補外なので、それが 1 件目として拾われることはない
+    expect($summary->subtitleSecondary)->toBe(trans('manual.bookend.summary.subtitle_secondary_recap', [
+        'points' => '急所X',
+    ], 'ja'));
+});
+
+test('長い title は scenario_bookend_title_max_chars で truncate される', function (): void {
+    $max = config()->integer('manual.scenario_bookend_title_max_chars');
+    $longTitle = str_repeat('長', $max + 20);
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual($longTitle), [bookendStep('手順A')]);
+    $intro = $result[0];
+
+    // narration に埋まる作業名が max 文字に収まっている
+    expect(mb_strlen((string) $intro->subtitlePrimary))->toBeLessThanOrEqual($max);
+    expect($intro->subtitlePrimary)->toBe(str_repeat('長', $max));
+});
+
+test('複数件で完成文が上限超過なら件数を減らす', function (): void {
+    // 1 件で ~1000 文字 → 接頭辞込み 2 件で MAX_SUBTITLE_SECONDARY_CHARS(2000) 超過
+    $long = str_repeat('あ', 1000);
+    $steps = [bookendStep('手順A', [bookendPoint($long), bookendPoint($long), bookendPoint('短い')])];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect(mb_strlen($summary->subtitleSecondary))->toBeLessThanOrEqual(ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
+});
+
+test('1 件でも完成文が上限超過なら完成文を文字単位 truncate する', function (): void {
+    $long = str_repeat('あ', ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS + 500);
+    $steps = [bookendStep('手順A', [bookendPoint($long)])];
+
+    $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+    $summary = $result[array_key_last($result)];
+
+    expect(mb_strlen($summary->subtitleSecondary))->toBe(ScenarioLimits::MAX_SUBTITLE_SECONDARY_CHARS);
+});
+
+test('summary_recap_max_points が 0 / -1 でも 1 件扱いに補正される', function (): void {
+    $steps = [bookendStep('手順A', [bookendPoint('急所1'), bookendPoint('急所2')])];
+
+    foreach ([0, -1] as $value) {
+        config(['manual.summary_recap_max_points' => $value]);
+        $result = app(ScenarioBookendBuilder::class)->wrap(bookendManual(), $steps);
+        $summary = $result[array_key_last($result)];
+
+        expect($summary->subtitleSecondary)->toContain('急所1');
+        expect($summary->subtitleSecondary)->not->toContain('急所2');
+    }
+});
+
+test('利用する bookend lang キーがすべて定義済みである', function (): void {
+    $keys = [
+        'manual.bookend.intro.scene',
+        'manual.bookend.intro.narration',
+        'manual.bookend.intro.subtitle_primary',
+        'manual.bookend.intro.subtitle_secondary',
+        'manual.bookend.summary.scene',
+        'manual.bookend.summary.narration',
+        'manual.bookend.summary.subtitle_primary',
+        'manual.bookend.summary.subtitle_secondary_recap',
+        'manual.bookend.summary.subtitle_secondary_fallback',
+    ];
+    foreach ($keys as $key) {
+        expect(Lang::has($key, 'ja'))->toBeTrue("lang キー欠落: {$key}");
+    }
+});
```

## テスト結果サマリー
- composer test: 1735 passed, 2 skipped, 0 failed (assertions 7160)
- 新規 Unit (ScenarioBookendBuilderTest): 13 passed
- 新規 Feature (ScenarioBookendMaterializeTest): 6 passed
- 波及更新 (CannedAnalysisPipelineTest / AnalysisPipelineTest / ScenarioUpdateTest): green
- composer phpstan: No errors / pint --test: passed
