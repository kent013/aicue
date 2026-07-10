# 実装レビュー依頼: T002 シナリオ編集 (document 一括保存・楽観ロック) 最終確認

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ。PromptGuardrailTest が検出)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割 (system)

あなたはシニア Laravel 12 / Svelte 5 エンジニアとして、TODO T002「シナリオ編集 (document 一括保存・楽観ロック)」の main マージ直前の最終実装レビューを行う。

対象コードは worktree `/workspace/.claude/worktrees/tasks/T002` にある(必要ならファイルを読んでよい)。設計ドキュメントは `/workspace/devnotes/20260711-0007-scenario-editing/detailed-design.md`。

### 特に重点確認してほしい点(直前の修正)

前回レビューで以下の Warning が出て修正済み。この修正が正しく問題を解消しているかを重点的に見ること:

- 指摘: 409 version_mismatch からの回復導線「サーバの最新を取得」(`reloadScenario`)が `router.reload({only:[...]})` だけで、Inertia の部分リロードは preserveState のためコンポーネントを再マウントせず、`$state` の作業コピー (version/steps/snapshot) が stale のまま → 楽観ロック競合の無限ループ。
- 修正: `reloadScenario` の `router.reload` に `onSuccess` を追加し、`page.props.scenario` を type guard (`isScenarioDocument`) で検証して `reseed()` (version/steps/snapshot/errors の再初期化) を明示実行。明示同意済みリロード中は dirty 離脱確認 (`router.on("before")`) をスキップする `reloading` フラグを追加。once-only seed には `svelte-ignore state_referenced_locally` を意図コメント付きで明記。Vitest 2 件追加(最新 document への置換成功 / 応答 shape 不正時の汎用エラーフォールバック)。

対象ファイル: `resources/js/components/features/manual/ScenarioEditor.svelte` / `tests/js/components/features/manual/ScenarioEditor.test.ts`

### レビュー観点

1. 正確性・並行制御: 楽観ロック (expected_version / 409 recovery) が全経路で成立するか。修正がデータ喪失・二重確認・レースを生まないか
2. 型安全・防御的パース: 実行時 type guard の妥当性
3. テスト網羅: 修正に対応するテストが実在し、回帰を検出できるか
4. 禁止事項・セキュリティ不変条件への抵触

### 出力形式

- 指摘は `[Critical]` / `[Warning]` / `[Suggestion]` で分類し、それぞれ「対象ファイル・該当箇所・問題・根拠・修正案」を書く
- Critical: マージを止めるべき欠陥(データ喪失・セキュリティ・楽観ロック破綻)
- 問題がなければ「Critical なし」と明言する

---

## レビュー対象 diff (user 部)

main...todo/T002 の全 diff:

```diff
diff --git a/AGENTS.md b/AGENTS.md
index 5e8b15a..30a2ae3 100644
--- a/AGENTS.md
+++ b/AGENTS.md
@@ -175,3 +175,10 @@ ## ドメイン固有規約
 <!-- TEMPLATE-MARKER: アプリ固有の規約 (ドメインモデルの不変条件、外部 API、
      固有のテスト規約等) をここに追記していく。テンプレート共通部 (上記) は
      テンプレート更新の取り込みを容易にするため、できるだけ書き換えない。 -->
+
+1. **シナリオ整合の共有ロック規約**: `cuts` / `video_manuals.scenario_version` /
+   `video_manuals.status` を書き込む全経路は、対象 VideoManual 行を `lockForUpdate()` で
+   取得した同一トランザクション内で反映する (準拠実装: `Manual/ScenarioService::save()`。
+   後続の AI 解析 materialize / RenderJob 状態遷移 / テイク採用 API も同規約に従う。
+   書き込み経路が 2 つ以上になった時点で経路 inventory を持つ Architecture テストへ昇格する。
+   詳細は `docs/architecture.md` §シナリオ整合の共有不変条件)
diff --git a/app/DataTransferObjects/Manual/ScenarioDocumentData.php b/app/DataTransferObjects/Manual/ScenarioDocumentData.php
new file mode 100644
index 0000000..c4bc063
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioDocumentData.php
@@ -0,0 +1,65 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Cut;
+use App\Models\VideoManual;
+use Illuminate\Support\Collection;
+
+/**
+ * シナリオ document 全体 (steps→points ツリー + 楽観ロック version)。
+ * edit 画面の Inertia props と保存成功応答の共通 shape で、
+ * fromManual() が sort_order 順に step/point を組み上げる唯一の変換点。
+ */
+final readonly class ScenarioDocumentData
+{
+    /** @param list<ScenarioStepData> $steps */
+    public function __construct(
+        public int $scenarioVersion,
+        public array $steps,
+    ) {}
+
+    public static function fromManual(VideoManual $manual): self
+    {
+        // 1 パス整形: parent_cut_id で groupBy し O(n) で組み上げる (per-step where の O(n^2) 回避)。
+        // トップレベル (parent_cut_id = null) は key 0 に寄せる (cut id は 1 始まりのため衝突しない)
+        /** @var \Illuminate\Database\Eloquent\Collection<int, Cut> $cuts */
+        $cuts = $manual->cuts()->orderBy('sort_order')->get();
+        /** @var Collection<int, Collection<int, Cut>> $grouped */
+        $grouped = $cuts->toBase()->groupBy(static fn (Cut $cut): int => $cut->parent_cut_id ?? 0);
+        /** @var Collection<int, Cut> $empty */
+        $empty = new Collection;
+
+        $steps = [];
+        foreach ($grouped->get(0) ?? $empty as $step) {
+            $points = array_values(($grouped->get($step->id) ?? $empty)
+                ->map(static fn (Cut $cut): ScenarioPointData => ScenarioPointData::fromCut($cut))
+                ->all());
+            $steps[] = ScenarioStepData::fromCut($step, $points);
+        }
+
+        return new self($manual->scenario_version, $steps);
+    }
+
+    /**
+     * @return array{scenario_version: int, steps: list<array{id: int, scene: string,
+     *   shot_type: string, shooting_point: string|null, narration: string,
+     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *   static_display_seconds: int|null, points: list<array{id: int, scene: string,
+     *     shot_type: string, shooting_point: string|null, narration: string,
+     *     subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *     static_display_seconds: int|null}>}>}
+     */
+    public function toArray(): array
+    {
+        return [
+            'scenario_version' => $this->scenarioVersion,
+            'steps' => array_map(
+                static fn (ScenarioStepData $step): array => $step->toArray(),
+                $this->steps,
+            ),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/ScenarioPointData.php b/app/DataTransferObjects/Manual/ScenarioPointData.php
new file mode 100644
index 0000000..b997041
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioPointData.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Cut;
+
+/**
+ * シナリオの急所 (point) 1 行。edit 画面 props / 保存成功応答の共通 shape。
+ * TS 側 types/manual.ts の ScenarioPoint と対で保守する。
+ * id は常に int (サーバ確定 id)。未保存行はクライアント専用型 (DraftPoint) が担う。
+ */
+final readonly class ScenarioPointData
+{
+    public function __construct(
+        public int $id,
+        public string $scene,
+        public string $shotType,
+        public ?string $shootingPoint,
+        public string $narration,
+        public ?string $subtitlePrimary,
+        public string $subtitleSecondary,
+        public ?string $materialType,
+        public ?int $staticDisplaySeconds,
+    ) {}
+
+    public static function fromCut(Cut $cut): self
+    {
+        return new self(
+            id: $cut->id,
+            scene: $cut->scene,
+            shotType: $cut->shot_type->value,
+            shootingPoint: $cut->shooting_point,
+            narration: $cut->narration,
+            subtitlePrimary: $cut->subtitle_primary,
+            subtitleSecondary: $cut->subtitle_secondary,
+            materialType: $cut->material_type?->value,
+            staticDisplaySeconds: $cut->static_display_seconds,
+        );
+    }
+
+    /**
+     * @return array{id: int, scene: string, shot_type: string, shooting_point: string|null,
+     *   narration: string, subtitle_primary: string|null, subtitle_secondary: string,
+     *   material_type: string|null, static_display_seconds: int|null}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'scene' => $this->scene,
+            'shot_type' => $this->shotType,
+            'shooting_point' => $this->shootingPoint,
+            'narration' => $this->narration,
+            'subtitle_primary' => $this->subtitlePrimary,
+            'subtitle_secondary' => $this->subtitleSecondary,
+            'material_type' => $this->materialType,
+            'static_display_seconds' => $this->staticDisplaySeconds,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/ScenarioPointInput.php b/app/DataTransferObjects/Manual/ScenarioPointInput.php
new file mode 100644
index 0000000..741f1c2
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioPointInput.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Enums\Manual\MaterialType;
+use App\Enums\Manual\ShotType;
+
+/**
+ * シナリオ保存 payload の急所 (point) 1 行 (id=null は新規行)。
+ * UpdateScenarioRequest::toScenarioSaveInput() が validated() から組み上げる
+ * (FormRequest → Service の型付き受け渡し)。
+ */
+final readonly class ScenarioPointInput
+{
+    public function __construct(
+        public ?int $id,
+        public string $scene,
+        public ShotType $shotType,
+        public ?string $shootingPoint,
+        public string $narration,
+        public ?string $subtitlePrimary,
+        public string $subtitleSecondary,
+        public ?MaterialType $materialType,
+        public ?int $staticDisplaySeconds,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Manual/ScenarioSaveInput.php b/app/DataTransferObjects/Manual/ScenarioSaveInput.php
new file mode 100644
index 0000000..86a81ca
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioSaveInput.php
@@ -0,0 +1,18 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+/**
+ * シナリオ document 一括保存の型付き入力 (expected_version + steps ツリー)。
+ * validated() 配列の shape を UpdateScenarioRequest 内の 1 箇所で固定する。
+ */
+final readonly class ScenarioSaveInput
+{
+    /** @param list<ScenarioStepInput> $steps */
+    public function __construct(
+        public int $expectedVersion,
+        public array $steps,
+    ) {}
+}
diff --git a/app/DataTransferObjects/Manual/ScenarioStepData.php b/app/DataTransferObjects/Manual/ScenarioStepData.php
new file mode 100644
index 0000000..ea48f31
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioStepData.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Models\Cut;
+
+/**
+ * シナリオの手順 (step) 1 行 + 配下の急所 (points)。
+ * TS 側 types/manual.ts の ScenarioStep と対で保守する。
+ */
+final readonly class ScenarioStepData
+{
+    /** @param list<ScenarioPointData> $points */
+    public function __construct(
+        public int $id,
+        public string $scene,
+        public string $shotType,
+        public ?string $shootingPoint,
+        public string $narration,
+        public ?string $subtitlePrimary,
+        public string $subtitleSecondary,
+        public ?string $materialType,
+        public ?int $staticDisplaySeconds,
+        public array $points,
+    ) {}
+
+    /** @param list<ScenarioPointData> $points */
+    public static function fromCut(Cut $cut, array $points): self
+    {
+        return new self(
+            id: $cut->id,
+            scene: $cut->scene,
+            shotType: $cut->shot_type->value,
+            shootingPoint: $cut->shooting_point,
+            narration: $cut->narration,
+            subtitlePrimary: $cut->subtitle_primary,
+            subtitleSecondary: $cut->subtitle_secondary,
+            materialType: $cut->material_type?->value,
+            staticDisplaySeconds: $cut->static_display_seconds,
+            points: $points,
+        );
+    }
+
+    /**
+     * @return array{id: int, scene: string, shot_type: string, shooting_point: string|null,
+     *   narration: string, subtitle_primary: string|null, subtitle_secondary: string,
+     *   material_type: string|null, static_display_seconds: int|null,
+     *   points: list<array{id: int, scene: string, shot_type: string, shooting_point: string|null,
+     *     narration: string, subtitle_primary: string|null, subtitle_secondary: string,
+     *     material_type: string|null, static_display_seconds: int|null}>}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'scene' => $this->scene,
+            'shot_type' => $this->shotType,
+            'shooting_point' => $this->shootingPoint,
+            'narration' => $this->narration,
+            'subtitle_primary' => $this->subtitlePrimary,
+            'subtitle_secondary' => $this->subtitleSecondary,
+            'material_type' => $this->materialType,
+            'static_display_seconds' => $this->staticDisplaySeconds,
+            'points' => array_map(
+                static fn (ScenarioPointData $point): array => $point->toArray(),
+                $this->points,
+            ),
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/ScenarioStepInput.php b/app/DataTransferObjects/Manual/ScenarioStepInput.php
new file mode 100644
index 0000000..7fe6569
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ScenarioStepInput.php
@@ -0,0 +1,28 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Enums\Manual\MaterialType;
+use App\Enums\Manual\ShotType;
+
+/**
+ * シナリオ保存 payload の手順 (step) 1 行 + 配下の急所 (id=null は新規行)。
+ */
+final readonly class ScenarioStepInput
+{
+    /** @param list<ScenarioPointInput> $points */
+    public function __construct(
+        public ?int $id,
+        public string $scene,
+        public ShotType $shotType,
+        public ?string $shootingPoint,
+        public string $narration,
+        public ?string $subtitlePrimary,
+        public string $subtitleSecondary,
+        public ?MaterialType $materialType,
+        public ?int $staticDisplaySeconds,
+        public array $points,
+    ) {}
+}
diff --git a/app/Enums/Manual/ScenarioConflictType.php b/app/Enums/Manual/ScenarioConflictType.php
new file mode 100644
index 0000000..06c0702
--- /dev/null
+++ b/app/Enums/Manual/ScenarioConflictType.php
@@ -0,0 +1,26 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Manual;
+
+/**
+ * シナリオ保存が 409 になる理由の判別子 (doc/10 §10.8-2 / §10.8-6)。
+ * TS 側 types/manual.ts の ScenarioConflictType union と対で保守する。
+ */
+enum ScenarioConflictType: string
+{
+    case VersionMismatch = 'version_mismatch';
+    case Rendering = 'rendering';
+    case Analyzing = 'analyzing';
+
+    /** UI 向け説明文 (サーバ側で確定しクライアントの文言分岐を減らす) */
+    public function message(): string
+    {
+        return match ($this) {
+            self::VersionMismatch => '他の編集と競合しました。最新のシナリオを取得してから再度保存してください。',
+            self::Rendering => '動画の書き出し中のため保存できません。完了後に再度お試しください。',
+            self::Analyzing => 'AI 解析中のため保存できません。完了後に再度お試しください。',
+        };
+    }
+}
diff --git a/app/Exceptions/Manual/ScenarioConflictException.php b/app/Exceptions/Manual/ScenarioConflictException.php
new file mode 100644
index 0000000..e7fc279
--- /dev/null
+++ b/app/Exceptions/Manual/ScenarioConflictException.php
@@ -0,0 +1,33 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Exceptions\Manual;
+
+use App\Enums\Manual\ScenarioConflictType;
+use App\Http\Resources\Manual\ScenarioConflictResource;
+use Exception;
+use Illuminate\Http\JsonResponse;
+use Illuminate\Http\Request;
+
+/**
+ * シナリオ保存の競合 (409)。ScenarioService が投げ、render() が JsonResource 応答を返す
+ * (`response()->json()` 直書き禁止の遵守。RequireRecentAuth の 409 契約と同じ
+ * 「code 厳格一致」構造)。
+ */
+final class ScenarioConflictException extends Exception
+{
+    public function __construct(
+        public readonly ScenarioConflictType $type,
+        public readonly int $currentVersion,
+    ) {
+        parent::__construct($type->message());
+    }
+
+    public function render(Request $request): JsonResponse
+    {
+        return ScenarioConflictResource::make($this)
+            ->response($request)
+            ->setStatusCode(409);
+    }
+}
diff --git a/app/Http/Controllers/Projects/ManualScenarioController.php b/app/Http/Controllers/Projects/ManualScenarioController.php
new file mode 100644
index 0000000..5c798ff
--- /dev/null
+++ b/app/Http/Controllers/Projects/ManualScenarioController.php
@@ -0,0 +1,43 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Controllers\Projects;
+
+use App\Http\Concerns\ResolvesCurrentOrganization;
+use App\Http\Controllers\Controller;
+use App\Http\Requests\Projects\UpdateScenarioRequest;
+use App\Http\Resources\Manual\ScenarioResource;
+use App\Models\Project;
+use App\Models\VideoManual;
+use App\Services\Manual\ScenarioService;
+use Illuminate\Support\Facades\Gate;
+
+/**
+ * シナリオ (Cut 群) の document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。
+ * 同一オリジン XHR (JSON 応答)。409 契約のため Inertia でなく JsonResource を返す。
+ *
+ * nested route の URL 整合は VideoManualController と同じ 2 層 (認可より前に 404):
+ * 1. {project} ∈ current org (resolveOrganizationProject = inline guard)
+ * 2. {manual} ∈ {project} (routes 側の Route::scopeBindings() = $project->manuals() 経由)
+ */
+class ManualScenarioController extends Controller
+{
+    use ResolvesCurrentOrganization;
+
+    public function update(
+        UpdateScenarioRequest $request,
+        Project $project,
+        VideoManual $manual,
+        ScenarioService $scenarios,
+    ): ScenarioResource {
+        $organization = $this->resolveCurrentOrganization($request);
+        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::authorize('update', $manual);
+
+        $document = $scenarios->save($project, $manual, $request->toScenarioSaveInput());
+
+        return ScenarioResource::make($document);
+    }
+}
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 9497722..1574ea0 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Projects;
 
+use App\DataTransferObjects\Manual\ScenarioDocumentData;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\StoreVideoManualRequest;
@@ -109,7 +110,7 @@ public function show(Request $request, Project $project, VideoManual $manual): R
         ]);
     }
 
-    /** 編集フォーム (メタデータ = title / category) */
+    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
     public function edit(Request $request, Project $project, VideoManual $manual): Response
     {
         $organization = $this->resolveCurrentOrganization($request);
@@ -126,8 +127,11 @@ public function edit(Request $request, Project $project, VideoManual $manual): R
                 'id' => $manual->id,
                 'title' => $manual->title,
                 'category' => $manual->category_id,
+                'status' => $manual->status->value, // rendering / analyzing 中の警告表示用
             ],
             'categories' => $this->categoryOptions($project),
+            // シナリオ document (保存成功応答 ScenarioResource と同一 shape)
+            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
         ]);
     }
 
diff --git a/app/Http/Requests/Projects/UpdateScenarioRequest.php b/app/Http/Requests/Projects/UpdateScenarioRequest.php
new file mode 100644
index 0000000..7899278
--- /dev/null
+++ b/app/Http/Requests/Projects/UpdateScenarioRequest.php
@@ -0,0 +1,230 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Projects;
+
+use App\DataTransferObjects\Manual\ScenarioPointInput;
+use App\DataTransferObjects\Manual\ScenarioSaveInput;
+use App\DataTransferObjects\Manual\ScenarioStepInput;
+use App\Enums\Manual\MaterialType;
+use App\Enums\Manual\ShotType;
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Support\Security\MassAssignmentProtectedKeys;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+use Webmozart\Assert\Assert;
+
+/**
+ * シナリオ document 一括保存 (doc/10 §10.8-5)。
+ *
+ * 入力境界の不変条件:
+ * - 保護キー (parent_cut_id / adopted_take_id / video_manual_id 等) はトップレベルだけでなく
+ *   steps.* / steps.*.points.* にも missing を明示 (ProhibitsProtectedKeys trait はトップレベル
+ *   のみのため、ネスト配列は本 Request が自前で張る)
+ * - sort_order / type もサーバ導出のため missing (§10.8-5: 構造から決定)
+ * - id は「照合用」。存在検証は Service がロック下で行う (ここでは integer のみ)
+ */
+class UpdateScenarioRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    /** 有界入力 (DoS guard)。仕様確定までの暫定値 */
+    private const int MAX_STEPS = 100;
+
+    private const int MAX_POINTS_PER_STEP = 20;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * narration / subtitle_secondary の null → '' 正規化はここで行う (下書き途中の空セル許容)。
+     * DTO / DB は非 null 文字列で統一 (Request と Service で責務を分散させない)。
+     *
+     * 注意: 正規化は「キーが存在し、かつ値が null の場合だけ」行う (array_key_exists 判定)。
+     * キー欠落を '' で補完すると present ルールが無効化され、未知キー・保護キーを含む
+     * 元配列を作り直すと missing ルールの検査対象が失われるため、既存配列への最小変更に留める。
+     */
+    protected function prepareForValidation(): void
+    {
+        $steps = $this->input('steps');
+        if (! is_array($steps)) {
+            return;
+        }
+
+        foreach ($steps as $stepKey => $step) {
+            if (! is_array($step)) {
+                continue;
+            }
+            $row = $this->normalizeNullableTextKeys($step);
+            $points = $row['points'] ?? null;
+            if (is_array($points)) {
+                foreach ($points as $pointKey => $point) {
+                    if (is_array($point)) {
+                        $points[$pointKey] = $this->normalizeNullableTextKeys($point);
+                    }
+                }
+                $row['points'] = $points;
+            }
+            $steps[$stepKey] = $row;
+        }
+
+        $this->merge(['steps' => $steps]);
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        return array_merge(
+            [
+                'expected_version' => ['required', 'integer', 'min:0'],
+                'steps' => ['present', 'array', 'max:'.self::MAX_STEPS],
+                // points キー欠落はクライアント直列化バグ。行単位で明示エラーにする
+                'steps.*' => ['array', 'required_array_keys:points'],
+                'steps.*.points' => ['present', 'array', 'max:'.self::MAX_POINTS_PER_STEP],
+                'steps.*.points.*' => ['array'],
+            ],
+            $this->cutRowRules('steps.*'),
+            $this->cutRowRules('steps.*.points.*'),
+            $this->nestedProtectedKeyRules('steps.*'),
+            $this->nestedProtectedKeyRules('steps.*.points.*'),
+            $this->protectedKeyMissingRules(),
+        );
+    }
+
+    /** validated() を型付き入力 DTO に変換する唯一の変換点 (Assert で narrow)。 */
+    public function toScenarioSaveInput(): ScenarioSaveInput
+    {
+        $validated = $this->validated();
+        Assert::isArray($validated);
+        $expectedVersion = $validated['expected_version'] ?? null;
+        Assert::integerish($expectedVersion);
+        $rawSteps = $validated['steps'] ?? [];
+        Assert::isArray($rawSteps);
+        // validated() は wildcard ルールの展開順で配列を再構築するため兄弟キーの順序を保証しない。
+        // steps / points は JSON 配列由来の数値キーなので ksort で payload の表示順に戻す
+        ksort($rawSteps);
+
+        $steps = [];
+        foreach ($rawSteps as $rawStep) {
+            Assert::isArray($rawStep);
+            $rawPoints = $rawStep['points'] ?? [];
+            Assert::isArray($rawPoints);
+            ksort($rawPoints);
+
+            $points = [];
+            foreach ($rawPoints as $rawPoint) {
+                Assert::isArray($rawPoint);
+                $values = $this->cutRowValues($rawPoint);
+                $points[] = new ScenarioPointInput(...$values);
+            }
+
+            $values = $this->cutRowValues($rawStep);
+            $steps[] = new ScenarioStepInput(...$values, points: $points);
+        }
+
+        return new ScenarioSaveInput((int) $expectedVersion, $steps);
+    }
+
+    /**
+     * cut 1 行分の本文フィールド検証 (step / point 共通)。
+     * scene は必須 (カットの定義)、narration / subtitle_secondary は下書き途中の保存を許す
+     * (prepareForValidation で null → '' 正規化済みのため present + string。DB は NOT NULL)。
+     * subtitle_primary の max:100 は DB string(100) と一致。
+     *
+     * @return array<string, list<mixed>>
+     */
+    private function cutRowRules(string $prefix): array
+    {
+        return [
+            "{$prefix}.id" => ['nullable', 'integer'],
+            "{$prefix}.scene" => ['required', 'string', 'max:1000'],
+            "{$prefix}.shot_type" => ['required', Rule::enum(ShotType::class)],
+            "{$prefix}.shooting_point" => ['nullable', 'string', 'max:1000'],
+            "{$prefix}.narration" => ['present', 'string', 'max:2000'],
+            "{$prefix}.subtitle_primary" => ['nullable', 'string', 'max:100'],
+            "{$prefix}.subtitle_secondary" => ['present', 'string', 'max:2000'],
+            "{$prefix}.material_type" => ['nullable', Rule::enum(MaterialType::class)],
+            "{$prefix}.static_display_seconds" => ['nullable', 'integer', 'min:1', 'max:60'],
+        ];
+    }
+
+    /**
+     * ネスト行に対する保護キー + サーバ導出キー (sort_order / type) の拒否 (存在するだけで 422)。
+     * MassAssignmentProtectedKeys::all() 由来のため保護キー追加時に自動追従する (drift しない)。
+     *
+     * @return array<string, list<string>>
+     */
+    private function nestedProtectedKeyRules(string $prefix): array
+    {
+        $rules = [];
+        foreach ([...MassAssignmentProtectedKeys::all(), 'sort_order', 'type'] as $key) {
+            $rules["{$prefix}.{$key}"] = ['missing'];
+        }
+
+        return $rules;
+    }
+
+    /**
+     * cut 1 行分の生配列を DTO コンストラクタ引数へ narrow する (step / point 共通)。
+     *
+     * @param  array<array-key, mixed>  $row
+     * @return array{id: int|null, scene: string, shotType: ShotType, shootingPoint: string|null,
+     *   narration: string, subtitlePrimary: string|null, subtitleSecondary: string,
+     *   materialType: MaterialType|null, staticDisplaySeconds: int|null}
+     */
+    private function cutRowValues(array $row): array
+    {
+        $id = $row['id'] ?? null;
+        Assert::nullOrIntegerish($id);
+        $scene = $row['scene'] ?? null;
+        Assert::string($scene);
+        $shotType = $row['shot_type'] ?? null;
+        Assert::string($shotType);
+        $shootingPoint = $row['shooting_point'] ?? null;
+        Assert::nullOrString($shootingPoint);
+        $narration = $row['narration'] ?? null;
+        Assert::string($narration);
+        $subtitlePrimary = $row['subtitle_primary'] ?? null;
+        Assert::nullOrString($subtitlePrimary);
+        $subtitleSecondary = $row['subtitle_secondary'] ?? null;
+        Assert::string($subtitleSecondary);
+        $materialType = $row['material_type'] ?? null;
+        Assert::nullOrString($materialType);
+        $staticDisplaySeconds = $row['static_display_seconds'] ?? null;
+        Assert::nullOrIntegerish($staticDisplaySeconds);
+
+        return [
+            'id' => $id === null ? null : (int) $id,
+            'scene' => $scene,
+            'shotType' => ShotType::from($shotType),
+            'shootingPoint' => $shootingPoint,
+            'narration' => $narration,
+            'subtitlePrimary' => $subtitlePrimary,
+            'subtitleSecondary' => $subtitleSecondary,
+            'materialType' => $materialType === null ? null : MaterialType::from($materialType),
+            'staticDisplaySeconds' => $staticDisplaySeconds === null ? null : (int) $staticDisplaySeconds,
+        ];
+    }
+
+    /**
+     * 下書き許容の 2 キー (narration / subtitle_secondary) のみ null → '' 正規化する。
+     *
+     * @param  array<array-key, mixed>  $row
+     * @return array<array-key, mixed>
+     */
+    private function normalizeNullableTextKeys(array $row): array
+    {
+        foreach (['narration', 'subtitle_secondary'] as $key) {
+            if (array_key_exists($key, $row) && $row[$key] === null) {
+                $row[$key] = '';
+            }
+        }
+
+        return $row;
+    }
+}
diff --git a/app/Http/Resources/Manual/ScenarioConflictResource.php b/app/Http/Resources/Manual/ScenarioConflictResource.php
new file mode 100644
index 0000000..21bbf07
--- /dev/null
+++ b/app/Http/Resources/Manual/ScenarioConflictResource.php
@@ -0,0 +1,38 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Manual;
+
+use App\Exceptions\Manual\ScenarioConflictException;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * シナリオ保存競合の 409 ボディ ({ code, conflict_type, message, current_version })。
+ * code 厳格一致でクライアントが自分宛て応答のみ処理する (recent_auth_required と同方式)。
+ * TS 側 types/manual.ts の ScenarioConflictBody と対で保守する。
+ *
+ * @property-read ScenarioConflictException $resource
+ */
+final class ScenarioConflictResource extends JsonResource
+{
+    /** 409 契約の判別子 (他の 409 契約との誤食防止) */
+    public const string CODE = 'scenario_conflict';
+
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{code: 'scenario_conflict', conflict_type: string, message: string, current_version: int}
+     */
+    public function toArray(Request $request): array
+    {
+        return [
+            'code' => self::CODE,
+            'conflict_type' => $this->resource->type->value,
+            'message' => $this->resource->getMessage(),
+            'current_version' => $this->resource->currentVersion,
+        ];
+    }
+}
diff --git a/app/Http/Resources/Manual/ScenarioResource.php b/app/Http/Resources/Manual/ScenarioResource.php
new file mode 100644
index 0000000..b4c2c35
--- /dev/null
+++ b/app/Http/Resources/Manual/ScenarioResource.php
@@ -0,0 +1,36 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Resources\Manual;
+
+use App\DataTransferObjects\Manual\ScenarioDocumentData;
+use Illuminate\Http\Request;
+use Illuminate\Http\Resources\Json\JsonResource;
+
+/**
+ * シナリオ保存成功応答 ({ scenario_version, steps })。
+ * edit 画面 props と同じ ScenarioDocumentData から生成し shape を一元化する
+ * (クライアントは応答の確定 id を取り込み再編集を継続できる)。
+ *
+ * @property-read ScenarioDocumentData $resource
+ */
+final class ScenarioResource extends JsonResource
+{
+    /** @var string|null */
+    public static $wrap = null;
+
+    /**
+     * @return array{scenario_version: int, steps: list<array{id: int, scene: string,
+     *   shot_type: string, shooting_point: string|null, narration: string,
+     *   subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *   static_display_seconds: int|null, points: list<array{id: int, scene: string,
+     *     shot_type: string, shooting_point: string|null, narration: string,
+     *     subtitle_primary: string|null, subtitle_secondary: string, material_type: string|null,
+     *     static_display_seconds: int|null}>}>}
+     */
+    public function toArray(Request $request): array
+    {
+        return $this->resource->toArray();
+    }
+}
diff --git a/app/Services/Manual/ScenarioService.php b/app/Services/Manual/ScenarioService.php
new file mode 100644
index 0000000..c326601
--- /dev/null
+++ b/app/Services/Manual/ScenarioService.php
@@ -0,0 +1,230 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\DataTransferObjects\Manual\ScenarioDocumentData;
+use App\DataTransferObjects\Manual\ScenarioPointInput;
+use App\DataTransferObjects\Manual\ScenarioSaveInput;
+use App\DataTransferObjects\Manual\ScenarioStepInput;
+use App\Enums\Manual\CutType;
+use App\Enums\Manual\ScenarioConflictType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Exceptions\Manual\ScenarioConflictException;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\VideoManual;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Illuminate\Support\Collection;
+use Illuminate\Support\Facades\DB;
+use Illuminate\Validation\ValidationException;
+use Webmozart\Assert\Assert;
+
+/**
+ * シナリオ (Cut 群) の document 単位保存 (doc/09 §9.4 / doc/10 §10.8-2,5,6)。
+ *
+ * シナリオ整合の共有不変条件 (本サービスが最初の準拠実装。後続の AI 解析 materialize /
+ * RenderJob 状態遷移 / テイク採用 API も同じ規約に従う。docs/architecture.md 参照):
+ *   「cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
+ *     対象 VideoManual 行を lockForUpdate() で取得した同一トランザクション内で反映する」
+ *
+ * - 直列化点: VideoManual 行ロック (親 relation 経由再解決で「子は親に属する」も同時担保)
+ * - 409: rendering / analyzing 中、または expected_version 不一致 (ScenarioConflictException)
+ * - 404: payload に他 manual の cut id 混入 (ModelNotFoundException。存在を漏らさない)
+ * - 422: payload 内 id 重複、既存 cut の階層/型不一致 (v1 は階層/型変更禁止)
+ */
+class ScenarioService
+{
+    public function save(Project $project, VideoManual $manual, ScenarioSaveInput $input): ScenarioDocumentData
+    {
+        return DB::transaction(function () use ($project, $manual, $input): ScenarioDocumentData {
+            // 1. 行ロック + 親子再解決 (cross-project は 404)
+            /** @var VideoManual $locked */
+            $locked = $project->manuals()->whereKey($manual->id)->lockForUpdate()->firstOrFail();
+
+            // 2. 状態 guard (§10.8-6。analyzing は AI materialize との clobber 防止)
+            if ($locked->status === VideoManualStatus::Rendering) {
+                throw new ScenarioConflictException(ScenarioConflictType::Rendering, $locked->scenario_version);
+            }
+            if ($locked->status === VideoManualStatus::Analyzing) {
+                throw new ScenarioConflictException(ScenarioConflictType::Analyzing, $locked->scenario_version);
+            }
+
+            // 3. 楽観ロック照合 (§10.8-2)
+            if ($locked->scenario_version !== $input->expectedVersion) {
+                throw new ScenarioConflictException(ScenarioConflictType::VersionMismatch, $locked->scenario_version);
+            }
+
+            // 4. 既存 cut 集合のロード (step / point を事前分離し、payload 位置と厳密照合)
+            $cuts = $locked->cuts()->get();
+            /** @var Collection<int, Cut> $existingSteps */
+            $existingSteps = $cuts->toBase()->where('type', CutType::Step)->keyBy('id');
+            /** @var Collection<int, Cut> $existingPoints */
+            $existingPoints = $cuts->toBase()->where('type', CutType::Point)->keyBy('id');
+            $this->assertPayloadIds($existingSteps, $existingPoints, $input);
+
+            // 5. reconcile (2 段階 + 削除)。$changed で「意味のある実変更」を追跡する
+            $changed = false;
+            $keptIds = [];
+            foreach ($input->steps as $stepIndex => $stepInput) {
+                $step = $this->upsertCut($locked, $existingSteps, $stepInput, CutType::Step, null, $stepIndex, $changed);
+                $keptIds[] = $step->id;
+                foreach ($stepInput->points as $pointIndex => $pointInput) {
+                    $point = $this->upsertCut($locked, $existingPoints, $pointInput, CutType::Point, $step->id, $pointIndex, $changed);
+                    $keptIds[] = $point->id;
+                }
+            }
+
+            // 段階 3: payload に現れなかった既存 cut を削除 (配下 Take は FK cascade)。
+            // bulk delete でなく each->delete() (将来 Cut に deleting イベントが付いても素通りしない。
+            // 件数は有界: steps≤100 × points≤20 のため chunk 化はしない)
+            $removed = $cuts->pluck('id')->diff($keptIds);
+            if ($removed->isNotEmpty()) {
+                $locked->cuts()->whereIn('id', $removed->all())->get()->each->delete();
+                $changed = true;
+            }
+
+            // 6. version は成功保存で常に +1 (§10.8-2 の確定契約)。実変更時のみ状態遷移
+            $locked->forceFill(['scenario_version' => $locked->scenario_version + 1]);
+            if ($changed) {
+                $this->transitionStatusAfterEdit($locked, hasCuts: $keptIds !== []);
+            }
+            $locked->save();
+
+            return ScenarioDocumentData::fromManual($locked);
+        });
+    }
+
+    /**
+     * 1 cut の update/create。本文は fill、parent/sort/type はサーバ導出値の forceFill 明示代入。
+     * 新規は relation 経由 make (video_manual_id を payload から受けない)。
+     * 保存前の isDirty() 検査で $changed を更新する (意味差分 = 実変更判定。
+     * サーバ導出値の変化 = 並べ替えも実変更になる)。
+     *
+     * @param  Collection<int, Cut>  $existing
+     */
+    private function upsertCut(
+        VideoManual $locked,
+        Collection $existing,
+        ScenarioStepInput|ScenarioPointInput $input,
+        CutType $type,
+        ?int $parentCutId,
+        int $sortOrder,
+        bool &$changed,
+    ): Cut {
+        $cut = $input->id === null ? $locked->cuts()->make() : $existing->get($input->id);
+        // 既存 id は assertPayloadIds で存在検証済み
+        Assert::isInstanceOf($cut, Cut::class);
+
+        $cut->fill([
+            'scene' => $input->scene,
+            'shot_type' => $input->shotType,
+            'shooting_point' => $input->shootingPoint,
+            'narration' => $input->narration,
+            'subtitle_primary' => $input->subtitlePrimary,
+            'subtitle_secondary' => $input->subtitleSecondary,
+            'material_type' => $input->materialType,
+            'static_display_seconds' => $input->staticDisplaySeconds,
+        ]);
+        $cut->forceFill([
+            'type' => $type,
+            'parent_cut_id' => $parentCutId,
+            'sort_order' => $sortOrder,
+        ]);
+
+        if (! $cut->exists || $cut->isDirty()) {
+            $changed = true;
+        }
+        $cut->save();
+
+        return $cut;
+    }
+
+    /**
+     * payload id の検証 (step / point の既存集合を分離して厳密照合):
+     * - 重複 id → ValidationException (422)
+     * - 当該 manual に無い id → ModelNotFoundException (404。tenant キー不信・存在を漏らさない)
+     * - step 位置の id が existingSteps に無く existingPoints にある (またはその逆)
+     *   → ValidationException (422。v1 は階層/型変更禁止)
+     *
+     * @param  Collection<int, Cut>  $existingSteps
+     * @param  Collection<int, Cut>  $existingPoints
+     */
+    private function assertPayloadIds(Collection $existingSteps, Collection $existingPoints, ScenarioSaveInput $input): void
+    {
+        /** @var array<int, true> $seen */
+        $seen = [];
+        foreach ($input->steps as $stepIndex => $stepInput) {
+            if ($stepInput->id !== null) {
+                $this->assertPayloadId(
+                    $stepInput->id,
+                    $existingSteps,
+                    $existingPoints,
+                    $seen,
+                    "steps.{$stepIndex}.id",
+                );
+            }
+            foreach ($stepInput->points as $pointIndex => $pointInput) {
+                if ($pointInput->id !== null) {
+                    $this->assertPayloadId(
+                        $pointInput->id,
+                        $existingPoints,
+                        $existingSteps,
+                        $seen,
+                        "steps.{$stepIndex}.points.{$pointIndex}.id",
+                    );
+                }
+            }
+        }
+    }
+
+    /**
+     * 1 id の照合。$expected = その位置に許される既存集合、$opposite = 逆階層の既存集合。
+     *
+     * @param  Collection<int, Cut>  $expected
+     * @param  Collection<int, Cut>  $opposite
+     * @param  array<int, true>  $seen
+     */
+    private function assertPayloadId(int $id, Collection $expected, Collection $opposite, array &$seen, string $key): void
+    {
+        if (isset($seen[$id])) {
+            throw ValidationException::withMessages([
+                $key => ['同じカット id が複数回指定されています。'],
+            ]);
+        }
+        $seen[$id] = true;
+
+        if ($expected->has($id)) {
+            return;
+        }
+        if ($opposite->has($id)) {
+            // 当該 manual には属するが階層/型が不一致 (v1 は変更禁止)
+            throw ValidationException::withMessages([
+                $key => ['カットの階層 (手順/急所) は変更できません。'],
+            ]);
+        }
+
+        // 当該 manual に属さない id は存在を漏らさない (404)
+        throw (new ModelNotFoundException)->setModel(Cut::class, [$id]);
+    }
+
+    /**
+     * 実変更後の状態遷移:
+     * - published → ready (§10.8-6: 完成動画は要再合成)
+     * - draft → ready (cuts が 1 件以上になったとき。§10.2「ready = シナリオ確定・編集/撮影可」。
+     *   自作シナリオ経路で撮影フェーズへ進めるようにする v1 設計判断)
+     * ready→ready / cut 全削除での後退遷移は行わない (過剰な状態機械を作らない)。
+     */
+    private function transitionStatusAfterEdit(VideoManual $locked, bool $hasCuts): void
+    {
+        if ($locked->status === VideoManualStatus::Published) {
+            $locked->forceFill(['status' => VideoManualStatus::Ready]);
+
+            return;
+        }
+        if ($locked->status === VideoManualStatus::Draft && $hasCuts) {
+            $locked->forceFill(['status' => VideoManualStatus::Ready]);
+        }
+    }
+}
diff --git a/database/factories/CutFactory.php b/database/factories/CutFactory.php
index df024d8..8ed1aae 100644
--- a/database/factories/CutFactory.php
+++ b/database/factories/CutFactory.php
@@ -46,4 +46,21 @@ public function forManual(VideoManual $manual): static
     {
         return $this->state(fn () => ['video_manual_id' => $manual->id]);
     }
+
+    /** 指定手順カット配下の急所カットとして作る (親と同一 manual に揃える) */
+    public function asPointOf(Cut $step): static
+    {
+        return $this->state(fn () => [
+            'type' => CutType::Point->value,
+            'shot_type' => ShotType::Yori->value,
+            'parent_cut_id' => $step->id,
+            'video_manual_id' => $step->video_manual_id,
+        ]);
+    }
+
+    /** 並び順を指定する (親スコープ内 0 始まり) */
+    public function withSortOrder(int $sortOrder): static
+    {
+        return $this->state(fn () => ['sort_order' => $sortOrder]);
+    }
 }
diff --git "a/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md" "b/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
index b7f455e..dd956b0 100644
--- "a/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
+++ "b/doc/10_\345\256\237\350\243\205\344\273\225\346\247\230.md"
@@ -122,6 +122,7 @@ ## 10.2 Enum と状態遷移
   - rendering:  合成中（RenderJob 実行中）
   - published:  完成動画あり
   - 編集で cut を変更したら published → ready へ戻す（完成動画は要再合成）
+  - draft → ready（シナリオ保存で cuts ≥ 1 になったとき。自作シナリオ経路）
   - 解析失敗は draft へ戻し AnalysisJob.error に理由
 
 CutType: step | point          ShotType: hiki | yori
diff --git a/docs/architecture.md b/docs/architecture.md
index b0486be..fd08c21 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -67,6 +67,7 @@ ## 主要 Service (テンプレート同梱)
 | `Project/ProjectService` | プロジェクト CRUD |
 | `Manual/CategoryService` | AI-CUE: カテゴリ create/update/reorder/delete (Project 行ロックで直列化・sort_order 専有) |
 | `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete (created_by サーバ導出・category 保存時再解決) |
+| `Manual/ScenarioService` | AI-CUE: シナリオ (Cut 群) の document 単位保存 (VideoManual 行ロック → rendering/analyzing・楽観ロック guard → 2 段階 reconcile → version+1)。§シナリオ整合の共有不変条件の最初の準拠実装 |
 | `Auth/SocialAccountService` | ソーシャルログイン連携 |
 | `Billing/BillingAccess` | 課金ゲート判定 (`subscription('default')` が active/trialing なら許可)。**課金による利用可否の判定は本クラス経由のみ** (アプリは本クラスの差し替えで gate 方針を変更する)。適用は `require-active-subscription` middleware (業務 route group。billing / webhook は構造的 allowlist) |
 | `Billing/QuotaService` | quota の消費・検証 |
@@ -83,6 +84,22 @@ ## 主要 Service (テンプレート同梱)
 | `Security/SecurityEventRecorder` | セキュリティ監査イベント記録 |
 | `LlmCallLogWriter` / `FxRateService` | LLM コスト記録と為替換算 |
 
+## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
+
+> **cuts / video_manuals.scenario_version / video_manuals.status を書き込む全経路は、
+> 対象 VideoManual 行を `lockForUpdate()` で取得した同一トランザクション内で反映する。**
+
+- 直列化点は VideoManual 行 (Project 行はロックしない。カテゴリ等 project 集合との整合は
+  シナリオ書き込みに無関係のため、直列化粒度を manual に意図的に絞る)。
+  親 relation 経由の再解決 (`$project->manuals()->whereKey(...)->lockForUpdate()`) で
+  「子は親に属する」も同時に担保する
+- 現在の準拠実装は `Manual/ScenarioService::save()` のみ。後続フェーズの
+  **AI 解析 job の Cut materialize / RenderJob の状態遷移 / テイク採用 API** も本規約に従うこと
+- 状態 guard (rendering/analyzing 中の保存は 409) は第一防衛、共有行ロックは
+  「job 側の書き込みと保存が絶対に交差しない」ための構造的防衛 (二重防御)
+- **書き込み経路が 2 つ以上になった時点で、経路 inventory を持つ Architecture テストへ昇格させる**
+  (現時点は経路が 1 つで機械検証対象がないためテスト化は見送り = 過剰設計回避)
+
 ## 公開面
 
 | 面 | 入口 | 認証 |
diff --git a/docs/factories.md b/docs/factories.md
index 66d809e..727a266 100644
--- a/docs/factories.md
+++ b/docs/factories.md
@@ -23,7 +23,7 @@ ## Factory 一覧 (テンプレート同梱)
 | `CategoryFactory` | Category | `forProject($project)` |
 | `VideoManualFactory` | VideoManual | `forProject($project)`, `forCategory($category)`, `createdBy($user)` |
 | `SourceDocumentFactory` | SourceDocument | `forManual($manual)` |
-| `CutFactory` | Cut | `forManual($manual)` |
+| `CutFactory` | Cut | `forManual($manual)` / `asPointOf($step)` / `withSortOrder($n)` |
 | `TakeFactory` | Take | `forCut($cut)` |
 | `ApiKeyFactory` | ApiKey | `forOrganization($org)`, `revoked()`, `expired(?Carbon $expiresAt = null)` |
 | `OrganizationInvitationFactory` | OrganizationInvitation | `forOrganization($org)`, `expired()`, `accepted()`, `revoked()`, `asAdmin()`。加えて `createWithPlainToken(array): array` (invitation と平文 token を tuple で返す。URL 生成用。DB には sha256 hash のみ保存) |
diff --git a/docs/template-divergence.md b/docs/template-divergence.md
index 436e3d9..22e0173 100644
--- a/docs/template-divergence.md
+++ b/docs/template-divergence.md
@@ -123,3 +123,30 @@ ### 揃えている不変条件(これは保証し続ける)
 ### 関連
 - 実装: `app/Http/Middleware/EnsureProjectBelongsToCurrentOrganization.php`, `routes/web.php`, `bootstrap/app.php`
 - テンプレート側の根拠: `docs/app-integration-guide.md` §2 (URL 整合 guard 行を 2 層構成に更新済み)
+
+## D5 ✅ Cut のシナリオ編集は per-row CRUD でなく document 単位保存 (PUT .../scenario)
+
+| 観点 | テンプレート | 本アプリ |
+|---|---|---|
+| 子リソースの書き込み | Item 見本の per-row CRUD (store/update/destroy を行単位で張る) | シナリオ (Cut 群) は `PUT /projects/{project}/manuals/{manual}/scenario` で document (steps→points ツリー) を一括保存し、サーバが 1 トランザクションで reconcile |
+
+### なぜ正当な差分か(logic-driven)
+シナリオ編集は「行追加/削除/並べ替え/手順削除で配下急所も削除」を伴う。per-row CRUD では
+(a) 親子カスケード + 並べ替えの原子性が壊れる、(b) 編集途中の中間状態がサーバに漏れる。
+document 保存 + 楽観ロック (`expected_version` / 409) が原子性と後勝ち破壊防止を両立する
+(doc/09 §9.4 / doc/10 §10.8-2)。
+
+### 揃えている不変条件(これは保証し続ける)
+> 「保護キー不信 / 認可前 404 / relation 経由 create を document 保存でも同じ機構で維持する」
+- 保護キー + サーバ導出キー (`parent_cut_id` / `adopted_take_id` / `sort_order` / `type`) は
+  ネスト行にも `missing` ルールを張り送出で 422 (`UpdateScenarioRequest::nestedProtectedKeyRules` は
+  `MassAssignmentProtectedKeys::all()` 由来で drift しない)
+- payload の cut id は照合専用。他 manual の id 混入は 404 (存在を漏らさない)、
+  階層/型変更 (step↔point) と id 重複は 422
+- `{manual}` ∈ `{project}` は scopeBindings、`{project}` ∈ current org は middleware + inline guard
+drift 防止テスト: `ScenarioUpdateTest` (保護キー 422 / 異物 id 404 / 409 系) と
+`NestedRouteIdorDefenseTest` (`projects.manuals.scenario.update` 登録)。
+
+### 関連
+- 実装: `app/Services/Manual/ScenarioService.php`, `app/Http/Requests/Projects/UpdateScenarioRequest.php`, `app/Http/Controllers/Projects/ManualScenarioController.php`
+- 設計: `devnotes/20260711-0007-scenario-editing/detailed-design.md`
diff --git a/resources/js/components/features/manual/ScenarioEditor.svelte b/resources/js/components/features/manual/ScenarioEditor.svelte
new file mode 100644
index 0000000..a8ed382
--- /dev/null
+++ b/resources/js/components/features/manual/ScenarioEditor.svelte
@@ -0,0 +1,648 @@
+<script lang="ts">
+    import { router } from "@inertiajs/svelte";
+    import { ChevronDown, ChevronUp, ListPlus, Plus, Trash2 } from "@lucide/svelte";
+    import Alert from "@/components/atoms/Alert.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Card from "@/components/atoms/Card.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import Select from "@/components/atoms/Select.svelte";
+    import Textarea from "@/components/atoms/Textarea.svelte";
+    import EmptyState from "@/components/molecules/EmptyState.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import { csrfToken } from "@/lib/csrf";
+    import { addToast } from "@/lib/stores/toast";
+    import type {
+        DraftPoint,
+        DraftStep,
+        ScenarioConflictBody,
+        ScenarioDocument,
+        ScenarioStep,
+    } from "@/types/manual";
+
+    /**
+     * シナリオ (手順 step / 急所 point の 2 階層ツリー) の document 編集エディタ。
+     * クライアントは作業コピー (DraftStep[]) を編集し、「シナリオを更新」で document 全体を
+     * 1 回の PUT で送信する (doc/09 §9.4)。楽観ロックの 409 / 行別 422 は自前で描画する。
+     * parent_cut_id / sort_order / type は payload に含めない (サーバ導出。doc/10 §10.8-5)。
+     */
+    interface Props {
+        projectId: number;
+        manualId: number;
+        scenario: ScenarioDocument;
+    }
+
+    let { projectId, manualId, scenario }: Props = $props();
+
+    /** サーバ shape → 編集用作業コピー (新しい配列/オブジェクトに clone し props と分離する) */
+    function toDraftSteps(steps: ScenarioStep[]): DraftStep[] {
+        return steps.map((step) => ({
+            ...rowOf(step),
+            id: step.id,
+            points: step.points.map((point) => ({ ...rowOf(point), id: point.id })),
+        }));
+    }
+
+    /** 本文フィールドのみの正規形 (キー順固定。payload / dirty 比較の共通基盤) */
+    function rowOf(row: Omit<DraftPoint, "id">): Omit<DraftPoint, "id"> {
+        return {
+            scene: row.scene,
+            shot_type: row.shot_type,
+            shooting_point: row.shooting_point,
+            narration: row.narration,
+            subtitle_primary: row.subtitle_primary,
+            subtitle_secondary: row.subtitle_secondary,
+            material_type: row.material_type,
+            static_display_seconds: row.static_display_seconds,
+        };
+    }
+
+    /**
+     * PUT payload の steps を組み立てる (呼び出しごとに新しい配列/オブジェクトを生成)。
+     * parent_cut_id / sort_order / type は含めない (サーバ導出)。
+     */
+    function payloadSteps(): Array<Record<string, unknown>> {
+        return steps.map((step) => ({
+            id: step.id,
+            ...rowOf(step),
+            points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
+        }));
+    }
+
+    /** 正規化シリアライザ (キー順固定・payload 対象フィールドのみ)。比較と送信の正規形を一本化する */
+    function serializeSteps(list: DraftStep[]): string {
+        return JSON.stringify(
+            list.map((step) => ({
+                id: step.id,
+                ...rowOf(step),
+                points: step.points.map((point) => ({ id: point.id, ...rowOf(point) })),
+            })),
+        );
+    }
+
+    // 作業コピーは scenario prop から「マウント時に一度だけ」seed する (意図的)。
+    // prop 追随で編集中の内容を握り潰さないため。サーバ最新への置換は
+    // applySaved (保存成功) / reloadScenario (409 からの明示同意リロード) が reseed で行う。
+    // svelte-ignore state_referenced_locally
+    let version = $state(scenario.scenario_version);
+    // svelte-ignore state_referenced_locally
+    let steps = $state<DraftStep[]>(toDraftSteps(scenario.steps));
+    /** 保存済みスナップショット (正規形の JSON 文字列。$state proxy と参照を共有しない) */
+    // svelte-ignore state_referenced_locally
+    let snapshot = $state(serializeSteps(toDraftSteps(scenario.steps)));
+    let saving = $state(false);
+    let errors = $state<Record<string, string[]>>({});
+    let conflict = $state<ScenarioConflictBody | null>(null);
+    let genericError = $state<string | null>(null);
+    let confirmingStepIndex = $state<number | null>(null);
+    let confirmingReload = $state(false);
+    /** 明示同意済みの最新取得中フラグ (dirty 離脱確認を二重に出さない) */
+    let reloading = false;
+
+    const dirty = $derived(serializeSteps(steps) !== snapshot);
+
+    /** 新規行の空値 (scene のみ必須のため空で作る) */
+    function emptyRow(shotType: "hiki" | "yori"): Omit<DraftPoint, "id"> {
+        return {
+            scene: "",
+            shot_type: shotType,
+            shooting_point: null,
+            narration: "",
+            subtitle_primary: null,
+            subtitle_secondary: "",
+            material_type: null,
+            static_display_seconds: null,
+        };
+    }
+
+    function addStep(): void {
+        steps.push({ ...emptyRow("hiki"), id: null, points: [] });
+    }
+
+    function addPoint(stepIndex: number): void {
+        steps[stepIndex].points.push({ ...emptyRow("yori"), id: null });
+    }
+
+    function removeStep(index: number): void {
+        steps.splice(index, 1);
+        confirmingStepIndex = null;
+    }
+
+    function removePoint(stepIndex: number, pointIndex: number): void {
+        steps[stepIndex].points.splice(pointIndex, 1);
+    }
+
+    /** ▲▼ 並べ替え (同一スコープ内のみ。階層をまたぐ移動は提供しない) */
+    function moveStep(index: number, delta: -1 | 1): void {
+        const next = index + delta;
+        if (next < 0 || next >= steps.length) return;
+        [steps[index], steps[next]] = [steps[next], steps[index]];
+    }
+
+    function movePoint(stepIndex: number, index: number, delta: -1 | 1): void {
+        const points = steps[stepIndex].points;
+        const next = index + delta;
+        if (next < 0 || next >= points.length) return;
+        [points[index], points[next]] = [points[next], points[index]];
+    }
+
+    async function save(): Promise<void> {
+        if (saving) return; // 多重送信ガード (disabled にはしない。押下は受けて即 return)
+        saving = true;
+        errors = {};
+        conflict = null;
+        genericError = null; // 前回の失敗表示をクリア (再保存成功後に旧エラーを残さない)
+        try {
+            const res = await putScenario();
+            await handleResponse(res);
+        } catch {
+            // ネットワーク断・fetch reject (419 回復 GET / 再試行 PUT の reject も含む)。
+            // 作業コピーは保持したまま汎用エラーを表示 (未処理 Promise を漏らさない)
+            genericError = "通信に失敗しました。接続を確認して再度お試しください。";
+        } finally {
+            saving = false;
+        }
+    }
+
+    async function putScenario(): Promise<Response> {
+        return fetch(`/projects/${projectId}/manuals/${manualId}/scenario`, {
+            method: "PUT",
+            headers: {
+                "Content-Type": "application/json",
+                Accept: "application/json",
+                "X-XSRF-TOKEN": csrfToken(),
+                "X-Requested-With": "XMLHttpRequest",
+            },
+            credentials: "same-origin",
+            body: JSON.stringify({ expected_version: version, steps: payloadSteps() }),
+        });
+    }
+
+    async function handleResponse(res: Response, retried = false): Promise<void> {
+        if (res.ok) {
+            // 成功応答も実行時検証 (JSON 破損・期待外 shape は汎用エラーへフォールバック)
+            const body = (await res.json().catch(() => null)) as unknown;
+            if (isScenarioDocument(body)) {
+                applySaved(body);
+                return;
+            }
+            genericError = "保存結果の取得に失敗しました。画面を再読み込みしてください。";
+            return;
+        }
+        if (res.status === 419 && !retried) {
+            // CSRF 失効: cookie を再取得して 1 回だけ自動リトライ (doc/10 §10.8-3 の共通処理方針)
+            await fetch(window.location.pathname, {
+                credentials: "same-origin",
+                headers: { Accept: "text/html" },
+            });
+            await handleResponse(await putScenario(), true);
+            return;
+        }
+        if (res.status === 401 || res.status === 419) {
+            // セッション失効: 作業コピーは破棄せず、別タブでの再ログインを案内 (リダイレクトしない)
+            genericError =
+                "セッションが切れました。別のタブでログインし直してから、もう一度保存してください。";
+            return;
+        }
+        if (res.status === 409) {
+            const body = (await res.json().catch(() => null)) as ScenarioConflictBody | null;
+            if (body?.code === "scenario_conflict") {
+                conflict = body; // 作業コピーは保持 (黙って編集内容を失わない)
+                return;
+            }
+        }
+        if (res.status === 422) {
+            // Laravel 標準 { errors: Record<string, string[]> } を実行時に判別 (防御的パース)
+            const body = (await res.json().catch(() => null)) as { errors?: unknown } | null;
+            if (body !== null && isValidationErrors(body.errors)) {
+                errors = body.errors; // "steps.0.points.1.scene" 形式のキーを行別セルに表示
+                return;
+            }
+        }
+        genericError = "保存に失敗しました。時間をおいて再度お試しください。";
+    }
+
+    /**
+     * サーバ document で作業コピーを再 seed する (保存成功 / 明示リロードの共通処理)。
+     * version / steps / snapshot を最新へ置換し、旧作業コピー由来の行別エラーも消す。
+     */
+    function reseed(document: ScenarioDocument): void {
+        version = document.scenario_version;
+        steps = toDraftSteps(document.steps);
+        snapshot = serializeSteps(steps);
+        errors = {};
+    }
+
+    /** 成功応答の取り込み: 確定 id + version + スナップショット更新 + 成功トースト */
+    function applySaved(document: ScenarioDocument): void {
+        reseed(document);
+        addToast("success", "シナリオを保存しました");
+    }
+
+    /** Record<string, string[]> かを実行時検証する type guard */
+    function isValidationErrors(value: unknown): value is Record<string, string[]> {
+        if (value === null || typeof value !== "object" || Array.isArray(value)) return false;
+        return Object.values(value).every(
+            (messages) =>
+                Array.isArray(messages) && messages.every((m) => typeof m === "string"),
+        );
+    }
+
+    /** 成功応答 (scenario_version: number + steps 配列) の type guard */
+    function isScenarioDocument(value: unknown): value is ScenarioDocument {
+        if (value === null || typeof value !== "object") return false;
+        const doc = value as { scenario_version?: unknown; steps?: unknown };
+        return typeof doc.scenario_version === "number" && Array.isArray(doc.steps);
+    }
+
+    /**
+     * 409 バナーからの明示同意リロード (編集内容の破棄を ConfirmDialog で確認済み)。
+     * Inertia の部分リロードは preserveState のためコンポーネントを再マウントしない。
+     * scenario prop が差し替わっても $state の作業コピーは自動では追随しないので、
+     * onSuccess で最新 document を明示的に再 seed する (楽観ロック競合からの復帰)。
+     */
+    function reloadScenario(): void {
+        confirmingReload = false;
+        conflict = null;
+        reloading = true;
+        router.reload({
+            only: ["scenario", "manual"],
+            onSuccess: (visited) => {
+                const latest: unknown = (visited.props as Record<string, unknown>).scenario;
+                if (isScenarioDocument(latest)) {
+                    reseed(latest);
+                    return;
+                }
+                genericError =
+                    "最新シナリオの取得に失敗しました。画面を再読み込みしてください。";
+            },
+            onFinish: () => {
+                reloading = false;
+            },
+        });
+    }
+
+    // dirty 離脱警告: beforeunload + Inertia before イベント (dirty 時 confirm)
+    $effect(() => {
+        const onBeforeUnload = (event: BeforeUnloadEvent): void => {
+            if (dirty) event.preventDefault();
+        };
+        window.addEventListener("beforeunload", onBeforeUnload);
+        const offBefore = router.on("before", (event) => {
+            if (
+                !reloading && // 明示同意済みリロードでは破棄確認を二重に出さない
+                dirty &&
+                !window.confirm("シナリオの変更が保存されていません。ページを離れますか?")
+            ) {
+                event.preventDefault();
+            }
+        });
+        return () => {
+            window.removeEventListener("beforeunload", onBeforeUnload);
+            offBefore();
+        };
+    });
+
+    function fieldError(prefix: string, field: string): string | undefined {
+        return errors[`${prefix}.${field}`]?.[0];
+    }
+
+    /** 数値 input (string) → number | null 変換 (空文字は null) */
+    function toSeconds(value: string): number | null {
+        const parsed = Number.parseInt(value, 10);
+        return Number.isNaN(parsed) ? null : parsed;
+    }
+
+    const CONFLICT_TITLES: Record<ScenarioConflictBody["conflict_type"], string> = {
+        version_mismatch: "他の編集と競合しました",
+        rendering: "処理中のため保存できません",
+        analyzing: "処理中のため保存できません",
+    };
+</script>
+
+{#snippet rowFields(row: DraftPoint, prefix: string, idPrefix: string)}
+    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
+        <FormField label="シーン (何を撮るか)" id="{idPrefix}-scene" error={fieldError(prefix, "scene")} required>
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="text"
+                    bind:value={row.scene}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                    testId="{idPrefix}-scene"
+                />
+            {/snippet}
+        </FormField>
+        <div class="grid grid-cols-2 gap-3">
+            <FormField label="画角" id="{idPrefix}-shot-type" error={fieldError(prefix, "shot_type")}>
+                {#snippet children({ id, describedBy, invalid })}
+                    <Select
+                        {id}
+                        bind:value={row.shot_type}
+                        error={invalid}
+                        aria-describedby={describedBy}
+                    >
+                        <option value="hiki">引き (全体)</option>
+                        <option value="yori">寄り (手元)</option>
+                    </Select>
+                {/snippet}
+            </FormField>
+            <FormField label="素材" id="{idPrefix}-material" error={fieldError(prefix, "material_type")}>
+                {#snippet children({ id, describedBy, invalid })}
+                    <Select
+                        {id}
+                        bind:value={
+                            () => row.material_type ?? "",
+                            (next) =>
+                                (row.material_type = next === "" ? null : (next as "video" | "still"))
+                        }
+                        error={invalid}
+                        aria-describedby={describedBy}
+                    >
+                        <option value="">未指定</option>
+                        <option value="video">動画</option>
+                        <option value="still">静止画</option>
+                    </Select>
+                {/snippet}
+            </FormField>
+        </div>
+        <FormField
+            label="撮影ポイント"
+            id="{idPrefix}-shooting-point"
+            error={fieldError(prefix, "shooting_point")}
+        >
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="text"
+                    bind:value={
+                        () => row.shooting_point ?? "",
+                        (next) => (row.shooting_point = next === "" ? null : next)
+                    }
+                    error={invalid}
+                    aria-describedby={describedBy}
+                />
+            {/snippet}
+        </FormField>
+        <FormField
+            label="字幕① (要点・100文字まで)"
+            id="{idPrefix}-subtitle-primary"
+            error={fieldError(prefix, "subtitle_primary")}
+        >
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="text"
+                    maxlength={100}
+                    bind:value={
+                        () => row.subtitle_primary ?? "",
+                        (next) => (row.subtitle_primary = next === "" ? null : next)
+                    }
+                    error={invalid}
+                    aria-describedby={describedBy}
+                />
+            {/snippet}
+        </FormField>
+        <FormField label="ナレーション" id="{idPrefix}-narration" error={fieldError(prefix, "narration")}>
+            {#snippet children({ id, describedBy, invalid })}
+                <Textarea
+                    {id}
+                    rows={2}
+                    bind:value={row.narration}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                />
+            {/snippet}
+        </FormField>
+        <FormField
+            label="字幕② (補足)"
+            id="{idPrefix}-subtitle-secondary"
+            error={fieldError(prefix, "subtitle_secondary")}
+        >
+            {#snippet children({ id, describedBy, invalid })}
+                <Textarea
+                    {id}
+                    rows={2}
+                    bind:value={row.subtitle_secondary}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                />
+            {/snippet}
+        </FormField>
+        {#if row.material_type === "still"}
+            <FormField
+                label="静止表示秒数 (1〜60)"
+                id="{idPrefix}-static-seconds"
+                error={fieldError(prefix, "static_display_seconds")}
+            >
+                {#snippet children({ id, describedBy, invalid })}
+                    <Input
+                        {id}
+                        type="number"
+                        min={1}
+                        max={60}
+                        bind:value={
+                            () =>
+                                row.static_display_seconds === null
+                                    ? ""
+                                    : String(row.static_display_seconds),
+                            (next) => (row.static_display_seconds = toSeconds(next))
+                        }
+                        error={invalid}
+                        aria-describedby={describedBy}
+                    />
+                {/snippet}
+            </FormField>
+        {/if}
+    </div>
+{/snippet}
+
+<section aria-label="シナリオ編集">
+    {#if conflict}
+        <Alert type="warning" title={CONFLICT_TITLES[conflict.conflict_type]} testId="scenario-conflict-banner">
+            <p>{conflict.message}</p>
+            {#snippet action()}
+                {#if conflict?.conflict_type === "version_mismatch"}
+                    <Button
+                        variant="neutral"
+                        size="sm"
+                        onclick={() => (confirmingReload = true)}
+                        testId="scenario-conflict-reload"
+                    >
+                        サーバの最新を取得
+                    </Button>
+                {/if}
+            {/snippet}
+        </Alert>
+    {/if}
+    {#if genericError}
+        <div class="mt-3">
+            <Alert type="danger" testId="scenario-generic-error">
+                <p>{genericError}</p>
+            </Alert>
+        </div>
+    {/if}
+
+    {#if steps.length === 0}
+        <div class="mt-4">
+            <EmptyState
+                title="シナリオがまだありません"
+                description="手順を追加して、マニュアル動画の台本を組み立てましょう。"
+                icon={ListPlus}
+                bordered
+                cta={{ kind: "action", label: "最初の手順を追加", onclick: addStep }}
+                testId="scenario-empty-state"
+            />
+        </div>
+    {:else}
+        <ol class="mt-4 flex flex-col gap-4" data-testid="scenario-steps">
+            {#each steps as step, stepIndex (step)}
+                <li>
+                    <Card padding="md">
+                        <div class="flex items-start justify-between gap-2">
+                            <h3 class="text-body font-medium text-text">手順 {stepIndex + 1}</h3>
+                            <div class="flex items-center gap-1">
+                                <Button
+                                    variant="ghost"
+                                    size="sm"
+                                    iconOnly
+                                    ariaLabel={`手順 ${stepIndex + 1} を上へ移動`}
+                                    onclick={() => moveStep(stepIndex, -1)}
+                                    testId="step-{stepIndex}-move-up"
+                                >
+                                    <ChevronUp class="size-4" aria-hidden="true" />
+                                </Button>
+                                <Button
+                                    variant="ghost"
+                                    size="sm"
+                                    iconOnly
+                                    ariaLabel={`手順 ${stepIndex + 1} を下へ移動`}
+                                    onclick={() => moveStep(stepIndex, 1)}
+                                    testId="step-{stepIndex}-move-down"
+                                >
+                                    <ChevronDown class="size-4" aria-hidden="true" />
+                                </Button>
+                                <Button
+                                    variant="danger-ghost"
+                                    size="sm"
+                                    iconOnly
+                                    ariaLabel={`手順 ${stepIndex + 1} を削除`}
+                                    onclick={() => (confirmingStepIndex = stepIndex)}
+                                    testId="step-{stepIndex}-remove"
+                                >
+                                    <Trash2 class="size-4" aria-hidden="true" />
+                                </Button>
+                            </div>
+                        </div>
+                        <div class="mt-3">
+                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
+                        </div>
+
+                        {#if step.points.length > 0}
+                            <ol class="mt-4 flex flex-col gap-3 border-l-2 border-border pl-4">
+                                {#each step.points as point, pointIndex (point)}
+                                    <li>
+                                        <div class="flex items-start justify-between gap-2">
+                                            <h4 class="text-caption font-medium text-text-secondary">
+                                                急所 {stepIndex + 1}-{pointIndex + 1}
+                                            </h4>
+                                            <div class="flex items-center gap-1">
+                                                <Button
+                                                    variant="ghost"
+                                                    size="sm"
+                                                    iconOnly
+                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を上へ移動`}
+                                                    onclick={() => movePoint(stepIndex, pointIndex, -1)}
+                                                    testId="point-{stepIndex}-{pointIndex}-move-up"
+                                                >
+                                                    <ChevronUp class="size-4" aria-hidden="true" />
+                                                </Button>
+                                                <Button
+                                                    variant="ghost"
+                                                    size="sm"
+                                                    iconOnly
+                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を下へ移動`}
+                                                    onclick={() => movePoint(stepIndex, pointIndex, 1)}
+                                                    testId="point-{stepIndex}-{pointIndex}-move-down"
+                                                >
+                                                    <ChevronDown class="size-4" aria-hidden="true" />
+                                                </Button>
+                                                <Button
+                                                    variant="danger-ghost"
+                                                    size="sm"
+                                                    iconOnly
+                                                    ariaLabel={`急所 ${stepIndex + 1}-${pointIndex + 1} を削除`}
+                                                    onclick={() => removePoint(stepIndex, pointIndex)}
+                                                    testId="point-{stepIndex}-{pointIndex}-remove"
+                                                >
+                                                    <Trash2 class="size-4" aria-hidden="true" />
+                                                </Button>
+                                            </div>
+                                        </div>
+                                        <div class="mt-2">
+                                            {@render rowFields(
+                                                point,
+                                                `steps.${stepIndex}.points.${pointIndex}`,
+                                                `point-${stepIndex}-${pointIndex}`,
+                                            )}
+                                        </div>
+                                    </li>
+                                {/each}
+                            </ol>
+                        {/if}
+
+                        <div class="mt-4">
+                            <Button
+                                variant="ghost"
+                                size="sm"
+                                onclick={() => addPoint(stepIndex)}
+                                testId="step-{stepIndex}-add-point"
+                            >
+                                <Plus class="size-4" aria-hidden="true" />
+                                急所を追加
+                            </Button>
+                        </div>
+                    </Card>
+                </li>
+            {/each}
+        </ol>
+
+        <div class="mt-4">
+            <Button variant="neutral" onclick={addStep} testId="scenario-add-step">
+                <Plus class="size-4" aria-hidden="true" />
+                手順を追加
+            </Button>
+        </div>
+    {/if}
+
+    <div class="mt-6 flex items-center gap-2">
+        <Button onclick={save} loading={saving} testId="scenario-submit">シナリオを更新</Button>
+        {#if dirty}
+            <span class="text-caption text-text-secondary" data-testid="scenario-dirty-indicator">
+                未保存の変更があります
+            </span>
+        {/if}
+    </div>
+</section>
+
+<ConfirmDialog
+    open={confirmingStepIndex !== null}
+    title="手順を削除しますか?"
+    message="この手順を削除すると、配下の急所と登録済みのテイク (撮影動画) も一緒に削除されます。この操作は「シナリオを更新」で保存すると元に戻せません。"
+    confirmLabel="削除する"
+    confirmVariant="danger"
+    onConfirm={() => confirmingStepIndex !== null && removeStep(confirmingStepIndex)}
+    onCancel={() => (confirmingStepIndex = null)}
+    testId="scenario-step-remove-dialog"
+/>
+
+<ConfirmDialog
+    bind:open={confirmingReload}
+    title="サーバの最新を取得しますか?"
+    message="現在編集中の内容は破棄され、サーバに保存されている最新のシナリオに置き換わります。"
+    confirmLabel="破棄して最新を取得"
+    confirmVariant="danger"
+    onConfirm={reloadScenario}
+    testId="scenario-reload-dialog"
+/>
diff --git a/resources/js/components/organisms/RecentAuthModal.svelte b/resources/js/components/organisms/RecentAuthModal.svelte
index 29fa7ff..a136bf9 100644
--- a/resources/js/components/organisms/RecentAuthModal.svelte
+++ b/resources/js/components/organisms/RecentAuthModal.svelte
@@ -6,6 +6,7 @@
     import Divider from "@/components/molecules/Divider.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
+    import { csrfToken } from "@/lib/csrf";
     import type { AvailableReauthProvider } from "@/lib/recent-auth";
     import { providerLabel } from "@/lib/social";
 
@@ -47,12 +48,6 @@
         }
     });
 
-    /** Laravel が発行する XSRF-TOKEN cookie (encrypted cookie 対応の URL エンコード済み値) */
-    function csrfToken(): string {
-        const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
-        return match ? decodeURIComponent(match[1]) : "";
-    }
-
     async function submitPassword(event: SubmitEvent): Promise<void> {
         event.preventDefault();
         if (submitting) return;
diff --git a/resources/js/lib/csrf.ts b/resources/js/lib/csrf.ts
new file mode 100644
index 0000000..cbc229c
--- /dev/null
+++ b/resources/js/lib/csrf.ts
@@ -0,0 +1,9 @@
+/**
+ * 同一オリジン XHR (fetch) 用の CSRF ヘルパ。
+ * Laravel が発行する XSRF-TOKEN cookie (encrypted cookie 対応の URL エンコード済み値) を読み、
+ * X-XSRF-TOKEN ヘッダ値へ変換する。RecentAuthModal / ScenarioEditor で共用する。
+ */
+export function csrfToken(): string {
+    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
+    return match ? decodeURIComponent(match[1]) : "";
+}
diff --git a/resources/js/pages/Manuals/Edit.svelte b/resources/js/pages/Manuals/Edit.svelte
index 0550c3b..ac44010 100644
--- a/resources/js/pages/Manuals/Edit.svelte
+++ b/resources/js/pages/Manuals/Edit.svelte
@@ -4,23 +4,32 @@
     import Card from "@/components/atoms/Card.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
+    import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
     import FormField from "@/components/molecules/FormField.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { CategoryOption } from "@/types/manual";
+    import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";
 
     /**
-     * 動画マニュアルのメタデータ編集 (タイトル / カテゴリ)。
+     * 動画マニュアルの編集 (基本情報 + シナリオ)。
+     * 保存単位は完全分離: 「基本情報を保存」(メタ PATCH = Inertia form) と
+     * 「シナリオを更新」(document PUT = ScenarioEditor 内の XHR) を独立セクションで表示する。
      * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
      * 空選択 = 未分類 (null で送信 = dissociate)。
      */
     interface Props {
         project: { id: number; name: string };
-        manual: { id: number; title: string; category: number | null };
+        manual: {
+            id: number;
+            title: string;
+            category: number | null;
+            status: VideoManualStatus;
+        };
         categories: CategoryOption[];
+        scenario: ScenarioDocument;
     }
 
-    let { project, manual, categories }: Props = $props();
+    let { project, manual, categories, scenario }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
@@ -42,12 +51,13 @@
 <AppLayout {appName}>
     <h1 class="text-h2">動画マニュアルの編集</h1>
     <p class="mt-1 text-caption text-text-secondary">
-        タイトルとカテゴリを変更できます。
+        基本情報とシナリオ (撮影台本) を編集できます。
     </p>
 
     <div class="mt-6 max-w-2xl">
         <Card padding="lg">
-            <form onsubmit={submit} class="flex flex-col gap-4">
+            <h2 class="text-h3">基本情報</h2>
+            <form onsubmit={submit} class="mt-4 flex flex-col gap-4">
                 <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                     {#snippet children({ id, describedBy, invalid })}
                         <Input
@@ -77,7 +87,7 @@
                 </FormField>
                 <div class="flex items-center gap-2">
                     <Button type="submit" loading={form.processing} testId="manual-submit">
-                        保存
+                        基本情報を保存
                     </Button>
                     <Button
                         variant="ghost"
@@ -90,4 +100,12 @@
             </form>
         </Card>
     </div>
+
+    <div class="mt-8 max-w-4xl">
+        <h2 class="text-h3">シナリオ</h2>
+        <p class="mt-1 text-caption text-text-secondary">
+            手順と急所のカットを編集し、「シナリオを更新」でまとめて保存します。
+        </p>
+        <ScenarioEditor {scenario} projectId={project.id} manualId={manual.id} />
+    </div>
 </AppLayout>
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index ce64323..b96ee6c 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -43,3 +43,49 @@ export interface ManualFilters {
     status: string | null;
     q: string | null;
 }
+
+/**
+ * PHP: App\DataTransferObjects\Manual\ScenarioPointData と対。
+ * サーバ shape の id は常に number (確定 id)。未保存行 (id: null) は
+ * 編集中の作業コピー専用型 DraftPoint / DraftStep で表現し、型を分離する。
+ */
+export interface ScenarioPoint {
+    id: number;
+    scene: string;
+    shot_type: "hiki" | "yori";
+    shooting_point: string | null;
+    narration: string;
+    subtitle_primary: string | null;
+    subtitle_secondary: string;
+    material_type: "video" | "still" | null;
+    static_display_seconds: number | null;
+}
+
+/** PHP: ScenarioStepData と対 (step 行 + 配下の points) */
+export interface ScenarioStep extends ScenarioPoint {
+    points: ScenarioPoint[];
+}
+
+/** PHP: ScenarioDocumentData と対 (edit props / PUT 成功応答の共通 shape) */
+export interface ScenarioDocument {
+    scenario_version: number;
+    steps: ScenarioStep[];
+}
+
+/** 編集中の作業コピー (未保存行は id: null)。PUT payload の steps はこの型を直列化する */
+export type DraftPoint = Omit<ScenarioPoint, "id"> & { id: number | null };
+export type DraftStep = Omit<ScenarioStep, "id" | "points"> & {
+    id: number | null;
+    points: DraftPoint[];
+};
+
+/** PHP: App\Enums\Manual\ScenarioConflictType と対 (discriminated union) */
+export type ScenarioConflictType = "version_mismatch" | "rendering" | "analyzing";
+
+/** PHP: ScenarioConflictResource と対 (409 ボディ。code 厳格一致で自分宛て応答のみ処理する) */
+export interface ScenarioConflictBody {
+    code: "scenario_conflict";
+    conflict_type: ScenarioConflictType;
+    message: string;
+    current_version: number;
+}
diff --git a/routes/web.php b/routes/web.php
index 303f102..33bc922 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -19,6 +19,7 @@
 use App\Http\Controllers\Organizations\OrganizationSwitchController;
 use App\Http\Controllers\Projects\CategoryController;
 use App\Http\Controllers\Projects\ItemController;
+use App\Http\Controllers\Projects\ManualScenarioController;
 use App\Http\Controllers\Projects\ProjectController;
 use App\Http\Controllers\Projects\ProjectMemberController;
 use App\Http\Controllers\Projects\VideoManualController;
@@ -359,6 +360,11 @@
                 ->name('projects.manuals.edit');
             Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
                 ->name('projects.manuals.update');
+            // シナリオ document 一括保存 (doc/09 §9.4 / doc/10 §10.3)。同一オリジン XHR (JSON 応答)。
+            // {manual} ∈ {project} は scopeBindings、{project} ∈ current org は
+            // project.in-current-org middleware + controller inline guard の 2 層 (既存 group が担保)
+            Route::put('/projects/{project}/manuals/{manual}/scenario', [ManualScenarioController::class, 'update'])
+                ->name('projects.manuals.scenario.update');
             Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
                 ->name('projects.manuals.destroy');
         });
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index c049bb4..ed44a07 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -55,6 +55,8 @@ function nestedRouteIdorInventory(): array
         'projects.manuals.show' => $s,
         'projects.manuals.edit' => $s,
         'projects.manuals.update' => $s,
+        // シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
+        'projects.manuals.scenario.update' => $s,
         'projects.manuals.destroy' => $s,
         // --- inline 親子整合 guard (authorize 前に 子∈親テナント を検査、不整合は 404) ---
         // OrganizationMemberController::resolveOrganizationMember (非 member は 404)
diff --git a/tests/Feature/Projects/ScenarioServiceTest.php b/tests/Feature/Projects/ScenarioServiceTest.php
new file mode 100644
index 0000000..8f36bd8
--- /dev/null
+++ b/tests/Feature/Projects/ScenarioServiceTest.php
@@ -0,0 +1,154 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\ScenarioPointInput;
+use App\DataTransferObjects\Manual\ScenarioSaveInput;
+use App\DataTransferObjects\Manual\ScenarioStepInput;
+use App\Enums\Manual\ShotType;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\VideoManual;
+use App\Services\Manual\ScenarioService;
+use Illuminate\Database\Eloquent\ModelNotFoundException;
+use Illuminate\Validation\ValidationException;
+
+/*
+ * ScenarioService の境界防御 (route binding とは別レイヤ)。
+ * assertPayloadIds の中核 (重複 422 / 異物 404 / 階層・型不一致 422) と
+ * cross-project 拒否を Service 直叩きで固定する (すべて DB 不変を併せて検証)。
+ * ManualServiceBoundaryTest と同じ流儀 (reconcile 検証中心のため専用ファイル)。
+ */
+
+/**
+ * step 1 行の入力 DTO を組む (points は引数で付ける)。
+ *
+ * @param  list<ScenarioPointInput>  $points
+ */
+function scenarioStepInput(?int $id = null, array $points = [], string $scene = '手順シーン'): ScenarioStepInput
+{
+    return new ScenarioStepInput(
+        id: $id,
+        scene: $scene,
+        shotType: ShotType::Hiki,
+        shootingPoint: null,
+        narration: 'ナレーション',
+        subtitlePrimary: null,
+        subtitleSecondary: '字幕',
+        materialType: null,
+        staticDisplaySeconds: null,
+        points: $points,
+    );
+}
+
+/** point 1 行の入力 DTO を組む。 */
+function scenarioPointInput(?int $id = null, string $scene = '急所シーン'): ScenarioPointInput
+{
+    return new ScenarioPointInput(
+        id: $id,
+        scene: $scene,
+        shotType: ShotType::Yori,
+        shootingPoint: null,
+        narration: 'ナレーション',
+        subtitlePrimary: null,
+        subtitleSecondary: '字幕',
+        materialType: null,
+        staticDisplaySeconds: null,
+    );
+}
+
+test('ScenarioService::save は cross-project の VideoManual を拒否し DB を変更しない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $projectA = Project::factory()->forOrganization($organization)->create();
+    $projectB = Project::factory()->forOrganization($organization)->create();
+    $manualB = VideoManual::factory()->forProject($projectB)->create();
+    $countBefore = Cut::query()->count();
+
+    expect(fn () => app(ScenarioService::class)->save(
+        $projectA,
+        $manualB,
+        new ScenarioSaveInput(0, [scenarioStepInput()]),
+    ))->toThrow(ModelNotFoundException::class);
+
+    expect($manualB->refresh()->scenario_version)->toBe(0);
+    expect(Cut::query()->count())->toBe($countBefore);
+});
+
+test('ScenarioService::save は payload 内 id 重複を 422 相当で拒否し DB を変更しない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);
+
+    expect(fn () => app(ScenarioService::class)->save(
+        $project,
+        $manual,
+        new ScenarioSaveInput(0, [
+            scenarioStepInput($step->id, scene: '改竄1'),
+            scenarioStepInput($step->id, scene: '改竄2'),
+        ]),
+    ))->toThrow(ValidationException::class);
+
+    expect($step->refresh()->scene)->toBe('元のシーン');
+    expect($manual->refresh()->scenario_version)->toBe(0);
+});
+
+test('ScenarioService::save は他 manual の cut id 混入を 404 で拒否し DB を変更しない', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $otherManual = VideoManual::factory()->forProject($project)->create();
+    $foreignCut = Cut::factory()->forManual($otherManual)->create(['scene' => '元のシーン']);
+
+    expect(fn () => app(ScenarioService::class)->save(
+        $project,
+        $manual,
+        new ScenarioSaveInput(0, [scenarioStepInput($foreignCut->id, scene: '改竄')]),
+    ))->toThrow(ModelNotFoundException::class);
+
+    expect($foreignCut->refresh()->scene)->toBe('元のシーン');
+    expect($manual->refresh()->scenario_version)->toBe(0);
+    expect($manual->cuts()->count())->toBe(0);
+});
+
+test('ScenarioService::save は既存 step の id を point 位置に置く降格を 422 相当で拒否する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $step = Cut::factory()->forManual($manual)->create();
+    $countBefore = Cut::query()->count();
+
+    expect(fn () => app(ScenarioService::class)->save(
+        $project,
+        $manual,
+        new ScenarioSaveInput(0, [
+            scenarioStepInput(points: [scenarioPointInput($step->id)]),
+        ]),
+    ))->toThrow(ValidationException::class);
+
+    expect($step->refresh()->parent_cut_id)->toBeNull();
+    expect(Cut::query()->count())->toBe($countBefore);
+    expect($manual->refresh()->scenario_version)->toBe(0);
+});
+
+test('ScenarioService::save は既存 point の id を step 位置に置く昇格を 422 相当で拒否する', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $step = Cut::factory()->forManual($manual)->create();
+    $point = Cut::factory()->asPointOf($step)->create();
+    $countBefore = Cut::query()->count();
+
+    expect(fn () => app(ScenarioService::class)->save(
+        $project,
+        $manual,
+        new ScenarioSaveInput(0, [
+            scenarioStepInput($step->id, points: []),
+            scenarioStepInput($point->id),
+        ]),
+    ))->toThrow(ValidationException::class);
+
+    expect($point->refresh()->parent_cut_id)->toBe($step->id);
+    expect(Cut::query()->count())->toBe($countBefore);
+    expect($manual->refresh()->scenario_version)->toBe(0);
+});
diff --git a/tests/Feature/Projects/ScenarioUpdateTest.php b/tests/Feature/Projects/ScenarioUpdateTest.php
new file mode 100644
index 0000000..681b436
--- /dev/null
+++ b/tests/Feature/Projects/ScenarioUpdateTest.php
@@ -0,0 +1,539 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\CutType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
+use App\Models\Cut;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use Inertia\Testing\AssertableInertia as Assert;
+
+/*
+ * シナリオ document 一括保存 (PUT .../manuals/{manual}/scenario)。
+ * - 楽観ロック (expected_version) + rendering/analyzing guard は 409 (code=scenario_conflict)
+ * - parent_cut_id / adopted_take_id / sort_order / type はサーバ導出 (payload では 422)
+ * - payload の cut id は照合専用 (他 manual の id は 404、階層/型不一致・重複は 422)
+ */
+
+/**
+ * 編集画面 + 編集者 (owner) 一式のセットアップ。
+ *
+ * @return array{Organization, User, Project, VideoManual}
+ */
+function scenarioTestContext(): array
+{
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+
+    return [$organization, $owner, $project, $manual];
+}
+
+/**
+ * 保存 payload の step 1 行 (points 込み)。
+ *
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function scenarioStepPayload(array $overrides = []): array
+{
+    return array_merge([
+        'id' => null,
+        'scene' => '作業台を準備する',
+        'shot_type' => 'hiki',
+        'shooting_point' => null,
+        'narration' => '作業台の準備を行います',
+        'subtitle_primary' => null,
+        'subtitle_secondary' => '作業台を準備',
+        'material_type' => null,
+        'static_display_seconds' => null,
+        'points' => [],
+    ], $overrides);
+}
+
+/**
+ * 保存 payload の point 1 行。
+ *
+ * @param  array<string, mixed>  $overrides
+ * @return array<string, mixed>
+ */
+function scenarioPointPayload(array $overrides = []): array
+{
+    return array_merge([
+        'id' => null,
+        'scene' => '工具の持ち方',
+        'shot_type' => 'yori',
+        'shooting_point' => '手元を大きく写す',
+        'narration' => '工具はこのように持ちます',
+        'subtitle_primary' => null,
+        'subtitle_secondary' => '持ち方に注意',
+        'material_type' => null,
+        'static_display_seconds' => null,
+    ], $overrides);
+}
+
+/** 既存 Cut を payload 行 (現在値そのまま) に変換する (no-op 保存テスト用)。 */
+function scenarioRowFromCut(Cut $cut): array
+{
+    return [
+        'id' => $cut->id,
+        'scene' => $cut->scene,
+        'shot_type' => $cut->shot_type->value,
+        'shooting_point' => $cut->shooting_point,
+        'narration' => $cut->narration,
+        'subtitle_primary' => $cut->subtitle_primary,
+        'subtitle_secondary' => $cut->subtitle_secondary,
+        'material_type' => $cut->material_type?->value,
+        'static_display_seconds' => $cut->static_display_seconds,
+    ];
+}
+
+test('編集者は steps+points を一括保存できる (type/parent/sort はサーバ採番・version+1)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+
+    $response = $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [
+                scenarioStepPayload([
+                    'scene' => '手順1',
+                    'points' => [
+                        scenarioPointPayload(['scene' => '急所1-1']),
+                        scenarioPointPayload(['scene' => '急所1-2']),
+                    ],
+                ]),
+                scenarioStepPayload(['scene' => '手順2']),
+            ],
+        ],
+    );
+
+    $response->assertOk();
+    $response->assertJsonPath('scenario_version', 1);
+    $response->assertJsonPath('steps.0.scene', '手順1');
+    $response->assertJsonPath('steps.0.points.0.scene', '急所1-1');
+    $response->assertJsonPath('steps.0.points.1.scene', '急所1-2');
+    $response->assertJsonPath('steps.1.scene', '手順2');
+    $response->assertJsonPath('steps.1.points', []);
+
+    $manual->refresh();
+    expect($manual->scenario_version)->toBe(1);
+
+    $steps = $manual->cuts()->where('type', CutType::Step->value)->orderBy('sort_order')->get();
+    expect($steps)->toHaveCount(2);
+    expect($steps[0]?->parent_cut_id)->toBeNull();
+    expect($steps[0]?->sort_order)->toBe(0);
+    expect($steps[1]?->sort_order)->toBe(1);
+
+    $points = $manual->cuts()->where('type', CutType::Point->value)->orderBy('sort_order')->get();
+    expect($points)->toHaveCount(2);
+    expect($points[0]?->parent_cut_id)->toBe($steps[0]?->id);
+    expect($points[0]?->sort_order)->toBe(0);
+    expect($points[1]?->parent_cut_id)->toBe($steps[0]?->id);
+    expect($points[1]?->sort_order)->toBe(1);
+});
+
+test('既存 cut の本文更新が反映される (fill 対象フィールドのみ変化)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [
+                array_merge(scenarioRowFromCut($step), [
+                    'scene' => '更新後のシーン',
+                    'subtitle_primary' => '字幕①',
+                    'points' => [],
+                ]),
+            ],
+        ],
+    )->assertOk();
+
+    $step->refresh();
+    expect($step->scene)->toBe('更新後のシーン');
+    expect($step->subtitle_primary)->toBe('字幕①');
+    expect($step->type)->toBe(CutType::Step);
+    expect($step->parent_cut_id)->toBeNull();
+});
+
+test('並べ替えが反映される (sort_order 0..N-1 の gap 除去)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $first = Cut::factory()->forManual($manual)->withSortOrder(3)->create(['scene' => 'A']);
+    $second = Cut::factory()->forManual($manual)->withSortOrder(7)->create(['scene' => 'B']);
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [
+                array_merge(scenarioRowFromCut($second), ['points' => []]),
+                array_merge(scenarioRowFromCut($first), ['points' => []]),
+            ],
+        ],
+    )->assertOk();
+
+    expect($second->refresh()->sort_order)->toBe(0);
+    expect($first->refresh()->sort_order)->toBe(1);
+});
+
+test('payload から外した cut は削除される (step 削除で配下 point も)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $keep = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
+    $removedStep = Cut::factory()->forManual($manual)->withSortOrder(1)->create();
+    $removedPoint = Cut::factory()->asPointOf($removedStep)->create();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [array_merge(scenarioRowFromCut($keep), ['points' => []])],
+        ],
+    )->assertOk();
+
+    expect(Cut::query()->whereKey($keep->id)->exists())->toBeTrue();
+    expect(Cut::query()->whereKey($removedStep->id)->exists())->toBeFalse();
+    expect(Cut::query()->whereKey($removedPoint->id)->exists())->toBeFalse();
+});
+
+test('expected_version 不一致は 409 (code 厳格一致) で保存されない', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['scenario_version' => 2])->save();
+
+    $response = $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 1,
+            'steps' => [scenarioStepPayload()],
+        ],
+    );
+
+    $response->assertStatus(409);
+    $response->assertJsonPath('code', 'scenario_conflict');
+    $response->assertJsonPath('conflict_type', 'version_mismatch');
+    $response->assertJsonPath('current_version', 2);
+
+    $manual->refresh();
+    expect($manual->scenario_version)->toBe(2);
+    expect($manual->cuts()->count())->toBe(0);
+});
+
+test('rendering 中の保存は 409 (conflict_type=rendering) で DB 不変', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['status' => VideoManualStatus::Rendering])->save();
+
+    $response = $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
+    );
+
+    $response->assertStatus(409);
+    $response->assertJsonPath('code', 'scenario_conflict');
+    $response->assertJsonPath('conflict_type', 'rendering');
+    expect($manual->refresh()->scenario_version)->toBe(0);
+    expect($manual->cuts()->count())->toBe(0);
+});
+
+test('analyzing 中の保存は 409 (conflict_type=analyzing)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['status' => VideoManualStatus::Analyzing])->save();
+
+    $response = $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
+    );
+
+    $response->assertStatus(409);
+    $response->assertJsonPath('conflict_type', 'analyzing');
+});
+
+test('実変更があると published→ready へ戻る (version も +1)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['status' => VideoManualStatus::Published, 'scenario_version' => 5])->save();
+    $step = Cut::factory()->forManual($manual)->create(['scene' => '元のシーン']);
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 5,
+            'steps' => [array_merge(scenarioRowFromCut($step), ['scene' => '変更', 'points' => []])],
+        ],
+    )->assertOk();
+
+    $manual->refresh();
+    expect($manual->status)->toBe(VideoManualStatus::Ready);
+    expect($manual->scenario_version)->toBe(6);
+});
+
+test('実変更なしの no-op 保存は published を維持し version は +1', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['status' => VideoManualStatus::Published, 'scenario_version' => 5])->save();
+    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
+
+    $response = $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 5,
+            'steps' => [array_merge(scenarioRowFromCut($step), ['points' => []])],
+        ],
+    );
+
+    $response->assertOk();
+    $response->assertJsonPath('scenario_version', 6);
+    $manual->refresh();
+    expect($manual->status)->toBe(VideoManualStatus::Published);
+    expect($manual->scenario_version)->toBe(6);
+});
+
+test('初回保存 (cuts>=1) で draft→ready へ遷移する (自作シナリオ経路)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    expect($manual->status)->toBe(VideoManualStatus::Draft);
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => [scenarioStepPayload()]],
+    )->assertOk();
+
+    expect($manual->refresh()->status)->toBe(VideoManualStatus::Ready);
+});
+
+test('draft のまま空 steps を保存しても draft を維持する (version は +1)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => []],
+    )->assertOk();
+
+    $manual->refresh();
+    expect($manual->status)->toBe(VideoManualStatus::Draft);
+    expect($manual->scenario_version)->toBe(1);
+});
+
+test('保護キー・サーバ導出キーのネスト送出は 422', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";
+
+    // steps.0.parent_cut_id
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload(['parent_cut_id' => 1])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.parent_cut_id');
+
+    // steps.0.points.0.adopted_take_id
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload([
+            'points' => [scenarioPointPayload(['adopted_take_id' => 1])],
+        ])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points.0.adopted_take_id');
+
+    // steps.0.sort_order / steps.0.type (サーバ導出)
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload(['sort_order' => 0])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.sort_order');
+
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload(['type' => 'step'])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.type');
+
+    // トップレベル video_manual_id
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [],
+        'video_manual_id' => $manual->id,
+    ])->assertStatus(422)->assertJsonValidationErrors('video_manual_id');
+
+    expect($manual->cuts()->count())->toBe(0);
+});
+
+test('expected_version 欠落は 422', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['steps' => []],
+    )->assertStatus(422)->assertJsonValidationErrors('expected_version');
+});
+
+test('本文検証: subtitle_primary 101 文字 / scene 空 / points キー欠落は 422', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";
+
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload(['subtitle_primary' => str_repeat('あ', 101)])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.subtitle_primary');
+
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload(['scene' => ''])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.scene');
+
+    // points キー欠落は行単位の明示エラー (クライアント直列化バグの早期検出)
+    $step = scenarioStepPayload();
+    unset($step['points']);
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [$step],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0');
+});
+
+test('narration / subtitle_secondary の null は空文字へ正規化され保存できる (下書き許容)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [scenarioStepPayload(['narration' => null, 'subtitle_secondary' => null])],
+        ],
+    )->assertOk();
+
+    /** @var Cut $cut */
+    $cut = $manual->cuts()->firstOrFail();
+    expect($cut->narration)->toBe('');
+    expect($cut->subtitle_secondary)->toBe('');
+});
+
+test('他 manual の cut id 混入は 404 で DB 不変 (tenant キー不信)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $otherManual = VideoManual::factory()->forProject($project)->create();
+    $foreignCut = Cut::factory()->forManual($otherManual)->create(['scene' => '元のシーン']);
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [array_merge(scenarioRowFromCut($foreignCut), ['scene' => '改竄', 'points' => []])],
+        ],
+    )->assertNotFound();
+
+    expect($foreignCut->refresh()->scene)->toBe('元のシーン');
+    expect($manual->refresh()->scenario_version)->toBe(0);
+    expect($manual->cuts()->count())->toBe(0);
+});
+
+test('payload 内の cut id 重複は 422', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $step = Cut::factory()->forManual($manual)->create();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        [
+            'expected_version' => 0,
+            'steps' => [
+                array_merge(scenarioRowFromCut($step), ['points' => []]),
+                array_merge(scenarioRowFromCut($step), ['points' => []]),
+            ],
+        ],
+    )->assertStatus(422)->assertJsonValidationErrors('steps.1.id');
+});
+
+test('既存 cut の階層/型変更は 422 (step→point 降格・point→step 昇格)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create();
+    $point = Cut::factory()->asPointOf($step)->create();
+
+    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";
+
+    // step の id を points 配下に置く (降格)
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [
+            scenarioStepPayload([
+                'points' => [array_merge(scenarioRowFromCut($step))],
+            ]),
+        ],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points.0.id');
+
+    // point の id をトップレベルに置く (昇格)
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [array_merge(scenarioRowFromCut($point), ['points' => []])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.id');
+});
+
+test('撮影者 (project_member) は 403', function (): void {
+    [$organization, , $project, $manual] = scenarioTestContext();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $member, ProjectRole::Member);
+
+    $this->actingAs($member)->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => []],
+    )->assertForbidden();
+});
+
+test('未ログインは 401 (JSON)', function (): void {
+    [, , $project, $manual] = scenarioTestContext();
+
+    $this->putJson(
+        "/projects/{$project->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => []],
+    )->assertUnauthorized();
+});
+
+test('cross-org の manual への PUT は 404 (存在オラクル封じ)', function (): void {
+    [, $owner] = createOrganizationWithOwner('自組織');
+    [, , $otherProject, $otherManual] = scenarioTestContext();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$otherProject->id}/manuals/{$otherManual->id}/scenario",
+        ['expected_version' => 0, 'steps' => []],
+    )->assertNotFound();
+});
+
+test('cross-project の manual への PUT は 404 (scopeBindings)', function (): void {
+    [$organization, $owner, , $manual] = scenarioTestContext();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->putJson(
+        "/projects/{$otherProject->id}/manuals/{$manual->id}/scenario",
+        ['expected_version' => 0, 'steps' => []],
+    )->assertNotFound();
+});
+
+test('steps / points の上限超過は 422 (有界入力)', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $url = "/projects/{$project->id}/manuals/{$manual->id}/scenario";
+
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => array_fill(0, 101, scenarioStepPayload()),
+    ])->assertStatus(422)->assertJsonValidationErrors('steps');
+
+    $this->actingAs($owner)->putJson($url, [
+        'expected_version' => 0,
+        'steps' => [scenarioStepPayload([
+            'points' => array_fill(0, 21, scenarioPointPayload()),
+        ])],
+    ])->assertStatus(422)->assertJsonValidationErrors('steps.0.points');
+});
+
+test('edit 画面 props に scenario ツリーと version / manual.status が載る', function (): void {
+    [, $owner, $project, $manual] = scenarioTestContext();
+    $manual->forceFill(['scenario_version' => 3])->save();
+    $step = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['scene' => '手順シーン']);
+    $point = Cut::factory()->asPointOf($step)->create(['scene' => '急所シーン']);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}/manuals/{$manual->id}/edit")
+        ->assertInertia(fn (Assert $page) => $page
+            ->component('Manuals/Edit')
+            ->where('manual.status', 'draft')
+            ->where('scenario.scenario_version', 3)
+            ->where('scenario.steps.0.id', $step->id)
+            ->where('scenario.steps.0.scene', '手順シーン')
+            ->where('scenario.steps.0.points.0.id', $point->id)
+            ->where('scenario.steps.0.points.0.scene', '急所シーン'),
+        );
+});
diff --git a/tests/js/components/features/manual/ScenarioEditor.test.ts b/tests/js/components/features/manual/ScenarioEditor.test.ts
new file mode 100644
index 0000000..faf9ed6
--- /dev/null
+++ b/tests/js/components/features/manual/ScenarioEditor.test.ts
@@ -0,0 +1,548 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
+import { get } from "svelte/store";
+import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
+import { clearToasts, toasts } from "@/lib/stores/toast";
+import type { ScenarioDocument } from "@/types/manual";
+
+// router.reload (部分リロード) はテスト環境では実行できないためモックする。
+// onSuccess をテスト側から呼び、サーバ最新 document の再取り込みを検証する。
+const { routerReloadMock } = vi.hoisted(() => ({
+    routerReloadMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", () => ({
+    router: {
+        reload: routerReloadMock,
+        on: vi.fn(() => () => {}),
+    },
+}));
+
+/** routerReloadMock に渡された reload オプションを取り出す */
+function lastReloadOptions(): {
+    only: string[];
+    onSuccess: (page: { props: Record<string, unknown> }) => void;
+    onFinish: () => void;
+} {
+    const last = routerReloadMock.mock.calls[routerReloadMock.mock.calls.length - 1];
+    if (!last?.[0]) throw new Error("router.reload が呼ばれていません");
+    return last[0] as {
+        only: string[];
+        onSuccess: (page: { props: Record<string, unknown> }) => void;
+        onFinish: () => void;
+    };
+}
+
+/*
+ * シナリオエディタ (document 一括保存)。
+ * - 保存 payload に parent_cut_id / sort_order / type を含めない (サーバ導出)
+ * - 409 / 401 / ネットワーク断でも作業コピーを破棄しない
+ * - 419 は cookie 再取得後 1 回だけ自動リトライ
+ */
+
+function makeDocument(): ScenarioDocument {
+    return {
+        scenario_version: 3,
+        steps: [
+            {
+                id: 11,
+                scene: "手順シーンA",
+                shot_type: "hiki",
+                shooting_point: null,
+                narration: "ナレーションA",
+                subtitle_primary: null,
+                subtitle_secondary: "字幕A",
+                material_type: null,
+                static_display_seconds: null,
+                points: [
+                    {
+                        id: 21,
+                        scene: "急所シーンA-1",
+                        shot_type: "yori",
+                        shooting_point: "手元",
+                        narration: "急所ナレーション",
+                        subtitle_primary: null,
+                        subtitle_secondary: "急所字幕",
+                        material_type: null,
+                        static_display_seconds: null,
+                    },
+                ],
+            },
+            {
+                id: 12,
+                scene: "手順シーンB",
+                shot_type: "hiki",
+                shooting_point: null,
+                narration: "ナレーションB",
+                subtitle_primary: null,
+                subtitle_secondary: "字幕B",
+                material_type: null,
+                static_display_seconds: null,
+                points: [],
+            },
+        ],
+    };
+}
+
+const baseProps = { projectId: 1, manualId: 5 };
+
+/** fetch Response の最小スタブ */
+function jsonResponse(status: number, body: unknown): Response {
+    return {
+        ok: status >= 200 && status < 300,
+        status,
+        json: () => Promise.resolve(body),
+    } as unknown as Response;
+}
+
+/** JSON として読めない応答 (破損 body) */
+function brokenResponse(status: number): Response {
+    return {
+        ok: status >= 200 && status < 300,
+        status,
+        json: () => Promise.reject(new Error("broken")),
+    } as unknown as Response;
+}
+
+const fetchMock = vi.fn<(input: RequestInfo | URL, init?: RequestInit) => Promise<Response>>();
+
+beforeEach(() => {
+    fetchMock.mockReset();
+    routerReloadMock.mockReset();
+    vi.stubGlobal("fetch", fetchMock);
+    clearToasts();
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+});
+
+/** 直近の PUT リクエスト body を取り出す */
+function lastPutPayload(): { expected_version: number; steps: Array<Record<string, unknown>> } {
+    const calls = fetchMock.mock.calls.filter(([, init]) => init?.method === "PUT");
+    const last = calls[calls.length - 1];
+    if (!last?.[1]?.body) throw new Error("PUT リクエストがありません");
+    return JSON.parse(String(last[1].body)) as {
+        expected_version: number;
+        steps: Array<Record<string, unknown>>;
+    };
+}
+
+/** セルに値を入力する */
+async function typeInto(testId: string, value: string): Promise<void> {
+    await fireEvent.input(screen.getByTestId(testId), { target: { value } });
+}
+
+describe("ScenarioEditor", () => {
+    it("空シナリオは EmptyState を表示し、最初の手順を追加できる", async () => {
+        render(ScenarioEditor, {
+            props: { ...baseProps, scenario: { scenario_version: 0, steps: [] } },
+        });
+
+        expect(screen.getByTestId("scenario-empty-state")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByRole("button", { name: "最初の手順を追加" }));
+
+        expect(screen.queryByTestId("scenario-empty-state")).not.toBeInTheDocument();
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("");
+        // 行追加で dirty (未保存の変更) 表示
+        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+    });
+
+    it("既存シナリオを描画し、編集していない間は dirty 表示なし", () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
+        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所シーンA-1");
+        expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
+        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+    });
+
+    it("セル編集で dirty になり、元へ戻すと dirty が消える (正規化比較)", async () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        await typeInto("step-0-scene", "手順シーンAX");
+        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
+
+        await typeInto("step-0-scene", "手順シーンA");
+        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+    });
+
+    it("急所を追加できる (行内の急所を追加ボタン)", async () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        await fireEvent.click(screen.getByTestId("step-1-add-point"));
+
+        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("");
+    });
+
+    it("手順の削除は確認ダイアログを経由し、配下の急所ごと消える", async () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        await fireEvent.click(screen.getByTestId("step-0-remove"));
+        // ダイアログにテイクも消える旨の説明がある
+        await waitFor(() => {
+            expect(screen.getByText(/登録済みのテイク/)).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
+
+        // 手順A が消え、手順B が繰り上がる
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
+        expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
+    });
+
+    it("急所の削除はダイアログなしで行える", async () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        await fireEvent.click(screen.getByTestId("point-0-0-remove"));
+
+        expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
+    });
+
+    it("▲▼ で同一スコープ内の並べ替えができる", async () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+
+        await fireEvent.click(screen.getByTestId("step-1-move-up"));
+
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
+        expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンA");
+    });
+
+    it("保存成功: payload にサーバ導出キーを含めず、応答の version を取り込む", async () => {
+        const saved: ScenarioDocument = { ...makeDocument(), scenario_version: 4 };
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, saved));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(fetchMock).toHaveBeenCalledTimes(1);
+        });
+
+        const [url, init] = fetchMock.mock.calls[0];
+        expect(String(url)).toBe("/projects/1/manuals/5/scenario");
+        expect(init?.method).toBe("PUT");
+        const payload = lastPutPayload();
+        expect(payload.expected_version).toBe(3);
+        expect(payload.steps[0]).not.toHaveProperty("sort_order");
+        expect(payload.steps[0]).not.toHaveProperty("type");
+        expect(payload.steps[0]).not.toHaveProperty("parent_cut_id");
+        expect(payload.steps[0].points).toBeInstanceOf(Array);
+
+        // 応答取り込みで dirty が消え、成功トーストが出る
+        await waitFor(() => {
+            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+        });
+        expect(get(toasts).some((toast) => toast.type === "success")).toBe(true);
+
+        // 次回保存は新 version を使う
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...saved, scenario_version: 5 }));
+        await typeInto("step-0-scene", "手順シーンAY");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(lastPutPayload().expected_version).toBe(4);
+        });
+    });
+
+    it("保存中の再押下は no-op (fetch は 1 回のみ)", async () => {
+        let resolveFetch: ((res: Response) => void) | undefined;
+        fetchMock.mockImplementationOnce(
+            () =>
+                new Promise<Response>((resolve) => {
+                    resolveFetch = resolve;
+                }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        expect(fetchMock).toHaveBeenCalledTimes(1);
+        resolveFetch?.(jsonResponse(200, makeDocument()));
+        await waitFor(() => {
+            expect(get(toasts).length).toBeGreaterThan(0);
+        });
+    });
+
+    it("409 は conflict バナーを表示し作業コピーを保持する", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "version_mismatch",
+                message: "他の編集と競合しました。",
+                current_version: 9,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-banner")).toBeInTheDocument();
+        });
+        // 作業コピー保持 (編集内容が消えていない)
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+        // version_mismatch のみ「サーバの最新を取得」導線がある
+        expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
+    });
+
+    it("409 後の「サーバの最新を取得」で作業コピーがサーバ最新 document に置換される", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "version_mismatch",
+                message: "他の編集と競合しました。",
+                current_version: 9,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
+        });
+
+        // 明示同意 (ConfirmDialog) を経て部分リロードが発火する
+        await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
+        await waitFor(() => {
+            expect(
+                screen.getByRole("button", { name: "破棄して最新を取得" }),
+            ).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));
+
+        expect(routerReloadMock).toHaveBeenCalledTimes(1);
+        const options = lastReloadOptions();
+        expect(options.only).toEqual(["scenario", "manual"]);
+
+        // サーバ最新 (version 9・別内容) を返す部分リロード成功をシミュレート
+        const latest: ScenarioDocument = {
+            scenario_version: 9,
+            steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
+        };
+        options.onSuccess({ props: { scenario: latest } });
+        options.onFinish();
+
+        // 作業コピーが最新で再 seed される (編集内容破棄・バナー消滅・dirty なし)
+        await waitFor(() => {
+            expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
+        });
+        expect(screen.queryByTestId("scenario-conflict-banner")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
+
+        // 以後の保存は最新 version を expected_version に使う (無限 409 ループに陥らない)
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...latest, scenario_version: 10 }));
+        await typeInto("step-0-scene", "再編集シーン");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(lastPutPayload().expected_version).toBe(9);
+        });
+    });
+
+    it("最新取得の応答 shape が不正なら汎用エラーを表示し作業コピーを保持する", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "version_mismatch",
+                message: "他の編集と競合しました。",
+                current_version: 9,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
+        await waitFor(() => {
+            expect(
+                screen.getByRole("button", { name: "破棄して最新を取得" }),
+            ).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));
+
+        const options = lastReloadOptions();
+        options.onSuccess({ props: { scenario: { unexpected: true } } });
+        options.onFinish();
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "最新シナリオの取得に失敗しました",
+            );
+        });
+        // 再 seed されず作業コピーは保持されたまま
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+    });
+
+    it("409 (rendering) はリロード導線なしのバナーを表示する", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(409, {
+                code: "scenario_conflict",
+                conflict_type: "rendering",
+                message: "書き出し中です。",
+                current_version: 3,
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-conflict-banner")).toBeInTheDocument();
+        });
+        expect(screen.queryByTestId("scenario-conflict-reload")).not.toBeInTheDocument();
+    });
+
+    it("419 は cookie 再取得後 1 回だけ自動リトライする", async () => {
+        fetchMock
+            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
+            .mockResolvedValueOnce(jsonResponse(200, "")) // 回復 GET
+            .mockResolvedValueOnce(jsonResponse(200, makeDocument())); // PUT #2 (リトライ)
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(fetchMock).toHaveBeenCalledTimes(3);
+        });
+        const putCalls = fetchMock.mock.calls.filter(([, init]) => init?.method === "PUT");
+        expect(putCalls).toHaveLength(2);
+    });
+
+    it("419 が続く場合は 2 回目でセッション失効メッセージ (多重リトライしない)", async () => {
+        fetchMock
+            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
+            .mockResolvedValueOnce(jsonResponse(200, "")) // 回復 GET
+            .mockResolvedValueOnce(jsonResponse(419, {})); // PUT #2 も 419
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "セッションが切れました",
+            );
+        });
+        expect(fetchMock).toHaveBeenCalledTimes(3);
+    });
+
+    it("401 はセッション失効メッセージを表示し作業コピーを保持する", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(401, {}));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "セッションが切れました",
+            );
+        });
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+    });
+
+    it("422 は行別セルにエラーを表示する", async () => {
+        fetchMock.mockResolvedValueOnce(
+            jsonResponse(422, {
+                message: "invalid",
+                errors: {
+                    "steps.0.scene": ["シーンは必須です。"],
+                    "steps.0.points.0.subtitle_primary": ["字幕①は100文字までです。"],
+                },
+            }),
+        );
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByText("シーンは必須です。")).toBeInTheDocument();
+        });
+        expect(screen.getByText("字幕①は100文字までです。")).toBeInTheDocument();
+    });
+
+    it("422 の body が期待外 shape なら汎用エラーへフォールバックする", async () => {
+        fetchMock.mockResolvedValueOnce(brokenResponse(422));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "保存に失敗しました",
+            );
+        });
+    });
+
+    it("成功応答の shape が不正なら汎用エラーへフォールバックする", async () => {
+        fetchMock.mockResolvedValueOnce(jsonResponse(200, { unexpected: true }));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "保存結果の取得に失敗しました",
+            );
+        });
+    });
+
+    it("PUT の reject (ネットワーク断) は作業コピーを保持し汎用エラーを表示する", async () => {
+        fetchMock.mockRejectedValueOnce(new TypeError("network error"));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await typeInto("step-0-scene", "手順シーンAX");
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "通信に失敗しました",
+            );
+        });
+        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
+    });
+
+    it("419 回復 GET の reject も汎用エラーで止まる (多重 retry なし)", async () => {
+        fetchMock
+            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
+            .mockRejectedValueOnce(new TypeError("network error")); // 回復 GET reject
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
+                "通信に失敗しました",
+            );
+        });
+        expect(fetchMock).toHaveBeenCalledTimes(2);
+    });
+
+    it("失敗後の再保存成功で旧エラーが消える", async () => {
+        fetchMock
+            .mockRejectedValueOnce(new TypeError("network error"))
+            .mockResolvedValueOnce(jsonResponse(200, makeDocument()));
+
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.getByTestId("scenario-generic-error")).toBeInTheDocument();
+        });
+
+        await fireEvent.click(screen.getByTestId("scenario-submit"));
+        await waitFor(() => {
+            expect(screen.queryByTestId("scenario-generic-error")).not.toBeInTheDocument();
+        });
+    });
+
+    it("保存ボタンは disabled にしない", () => {
+        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
+        expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
+    });
+});
diff --git a/tests/js/pages/ManualsEdit.test.ts b/tests/js/pages/ManualsEdit.test.ts
index 6efdc71..3d39165 100644
--- a/tests/js/pages/ManualsEdit.test.ts
+++ b/tests/js/pages/ManualsEdit.test.ts
@@ -1,14 +1,21 @@
 import { describe, expect, it } from "vitest";
 import { render, screen } from "@testing-library/svelte";
 import Edit from "@/pages/Manuals/Edit.svelte";
+import type { ScenarioDocument } from "@/types/manual";
+
+const scenario: ScenarioDocument = {
+    scenario_version: 0,
+    steps: [],
+};
 
 const baseProps = {
     project: { id: 1, name: "サンプルプロジェクト" },
-    manual: { id: 5, title: "ネジ締め作業", category: 2 },
+    manual: { id: 5, title: "ネジ締め作業", category: 2, status: "draft" as const },
     categories: [
         { id: 1, name: "準備作業" },
         { id: 2, name: "仕上げ" },
     ],
+    scenario,
 };
 
 describe("Manuals/Edit", () => {
@@ -28,9 +35,26 @@ describe("Manuals/Edit", () => {
         expect(screen.getByTestId("manual-category-select")).toHaveValue("");
     });
 
+    it("2 つの保存系統が分離して描画される (基本情報を保存 / シナリオを更新)", () => {
+        render(Edit, { props: baseProps });
+
+        expect(screen.getByRole("heading", { name: "基本情報" })).toBeInTheDocument();
+        expect(screen.getByRole("heading", { name: "シナリオ" })).toBeInTheDocument();
+        expect(screen.getByTestId("manual-submit")).toHaveTextContent("基本情報を保存");
+        expect(screen.getByTestId("scenario-submit")).toHaveTextContent("シナリオを更新");
+    });
+
+    it("空シナリオでは EmptyState (最初の手順を追加) が表示される", () => {
+        render(Edit, { props: baseProps });
+
+        expect(screen.getByTestId("scenario-empty-state")).toBeInTheDocument();
+        expect(screen.getByRole("button", { name: "最初の手順を追加" })).toBeInTheDocument();
+    });
+
     it("保存ボタンは disabled にしない", () => {
         render(Edit, { props: baseProps });
 
         expect(screen.getByTestId("manual-submit")).not.toBeDisabled();
+        expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
     });
 });
```
