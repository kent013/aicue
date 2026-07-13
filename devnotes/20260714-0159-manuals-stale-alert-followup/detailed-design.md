# 詳細設計: manuals-stale-alert-followup

bug-hunt 回帰 run の **F-1-1 (High) + F-1-2 (Low) + F-1-3 (Low)** を修正する。中核は F-1-1:
失敗した job（解析/レンダ/プレビュー）の error alert が、その後シナリオが保存されて状態が進んでも
残留する「stale alert」を、**DB 権威の `scenario_version` スナップショット比較**でサーバ側から抑制する。

## 使命・制約（絶対遵守）

### アプリの使命（North Star / AGENTS.md）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を
生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも
**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- v1: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md）

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（factory 経由のみ）
6. prompt 文字列のコード直書き
7. 操作系 POST 応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）／ **RefreshDatabase** グローバル適用 + `--parallel`（個別 `DatabaseTransactions` 禁止）
- **テストデータは Factory 生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン（本 PR は Inertia props + 既存 DTO の `toArray()` のみ、shape 不変）
- アーリーリターン推奨 / null 安全は `Webmozart\Assert\Assert`
- コードフォーマット: `composer fix`（Pint）/ `pnpm lint:fix`
- フロントは Svelte 5 runes + DS token/ramp のみ（DESIGN.md canonical）。アイコンは `@lucide/svelte`。
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 概念設計リファレンス

- [`conceptual-design.md`](./conceptual-design.md)（Codex `gpt-5.4` レビュー **Round 5 で APPROVED**）。
- staleness 定義（**保存世代基準**）: 失敗 job が stale = 「失敗確定**後に** scenario 保存が成立し
  `scenario_version` が進んだ」。判定 = `failed && snapshot!==null && manual.scenario_version > snapshot`。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | `scenario_version_at_terminal` カラム追加（migration） | `database/migrations/{ts}_add_scenario_version_at_terminal_to_job_tables.php` (新規) | High |
| 2 | 両 failJob で失敗確定時 `scenario_version` を snapshot 書込み + Model @property | `app/Services/Manual/AnalysisJobService.php` / `app/Services/Manual/RenderJobService.php` / `app/Models/AnalysisJob.php` / `app/Models/RenderJob.php` | High |
| 3 | `VideoManualService` に表示用 job 解決 + staleness 述語を追加 | `app/Services/Manual/VideoManualService.php` | High |
| 4 | `VideoManualController::show` を display 系メソッド委譲に置換 | `app/Http/Controllers/Projects/VideoManualController.php` | High |
| 5 | SOP 抽出「短すぎ」を `unextractable` から `tooShort` に分離 | `app/Exceptions/Manual/AnalysisFailedException.php` / `app/Services/Manual/SopTextExtractor.php` | Low |
| 6 | 作成フォームのタイトル入力で `oninput` エラークリア | `resources/js/pages/Manuals/Create.svelte` | Low |
| 7 | テスト（Feature / Unit / Vitest） | 下記各テストファイル | High |

> **波及変更の全体像**: DTO（`AnalysisJobData`/`RenderJobData`）・Inertia props shape・TS 型
> （`types/manual.ts`）・`AnalysisPanel.svelte`/`RenderPanel.svelte` の**ロジックは変更不要**
> （stale 抑制は controller が `job: null` で表現し、既存 nullable 契約で自然に alert 非表示になる）。
> Factory は nullable 既定 null のため定義変更不要（`failed()` state に snapshot 引数を追加するのは任意）。

---

## 施策1: `scenario_version_at_terminal` カラム追加（migration）

### 変更箇所
- 新規: `database/migrations/{ts}_add_scenario_version_at_terminal_to_job_tables.php`

### 波及変更
- TypeScript 型定義: なし（DTO に露出しない内部列）。
- API Resource/DTO: なし（`AnalysisJobData`/`RenderJobData` に含めない。staleness は server 内で判定）。
- テストファイル: 施策7 の Feature テストが本列を前提にする。

### 変更後コード
```php
public function up(): void
{
    Schema::table('analysis_jobs', function (Blueprint $table): void {
        // 失敗確定時の manual.scenario_version スナップショット (stale alert 判定用)。
        // nullable: 既存行・非失敗行は null。scenario_version と同じ unsignedInteger。
        $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error');
    });
    Schema::table('render_jobs', function (Blueprint $table): void {
        $table->unsignedInteger('scenario_version_at_terminal')->nullable()->after('error_code');
    });
}

public function down(): void
{
    Schema::table('analysis_jobs', fn (Blueprint $t) => $t->dropColumn('scenario_version_at_terminal'));
    Schema::table('render_jobs', fn (Blueprint $t) => $t->dropColumn('scenario_version_at_terminal'));
}
```

### PHPStan適合チェック
- [x] マイグレーションはクロージャ内 `Blueprint` 型注釈明示。ロジックなし。

### テスト計画
- [x] 施策7 の Feature テストが列存在に依存（暗黙カバレッジ）。migration 単体テストは新設しない。

### リスク
- 既存 dev DB への `migrate` は通常運用で流れる（本 PR はカラム追加のみ・破壊操作なし）。禁止事項3
  （`migrate:fresh` 等の破壊操作）には抵触しない。

---

## 施策2: 両 failJob で失敗確定時 `scenario_version` を snapshot 書込み + Model @property

### 変更箇所
- `app/Services/Manual/AnalysisJobService.php` `failJob()`（現行 L114-）
- `app/Services/Manual/RenderJobService.php` `failJob()`（現行 L175-）
- `app/Models/AnalysisJob.php` / `app/Models/RenderJob.php` の `@property` PHPDoc

### 波及変更
- TypeScript 型定義: なし。
- API Resource/DTO: なし。
- テストファイル: 施策7 Feature（snapshot が失敗確定時 version と一致 / terminal 後不変）。

### 現行コード（AnalysisJobService::failJob 抜粋）
```php
$locked->status = JobStatus::Failed;
$locked->error = $error;
$locked->save();

/** @var VideoManual $manual */
$manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
if ($manual->status === VideoManualStatus::Analyzing) {
    $manual->forceFill([
        'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
    ])->save();
}
```

### 変更後コード（AnalysisJobService::failJob）
```php
// manual を先に lock で取得し (job → manual のロック順を維持)、失敗確定時の
// scenario_version を job にスナップショットする (stale alert 判定の順序基準)。
/** @var VideoManual $manual */
$manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

$locked->status = JobStatus::Failed;
$locked->error = $error;
$locked->scenario_version_at_terminal = $manual->scenario_version; // 追加
$locked->save();

// manual 復帰 (analyzing のときのみ。cuts があれば ready、無ければ draft)
if ($manual->status === VideoManualStatus::Analyzing) {
    $manual->forceFill([
        'status' => $manual->cuts()->exists() ? VideoManualStatus::Ready : VideoManualStatus::Draft,
    ])->save();
}
```
> 注: manual の取得を job 保存より前に移動する（version を読むため）。ロック順は **job → manual** で
> 従来と不変（job は関数冒頭で lock 済み）。`forceFill` の status 遷移条件・タイミングは不変。

### 現行コード（RenderJobService::failJob 抜粋）
```php
$locked->status = JobStatus::Failed;
$locked->error = $error;
$locked->error_code = $code;
$locked->save();

if ($locked->kind === RenderKind::Render) {
    /** @var VideoManual $manual */
    $manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();
    if ($manual->status === VideoManualStatus::Rendering) {
        $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
    }
}
```

### 変更後コード（RenderJobService::failJob）
```php
// preview/render とも失敗確定時の scenario_version を snapshot する必要があるため、
// manual を常に lock で取得する (従来は kind=render のみ取得だった)。ロック順 job → manual。
/** @var VideoManual $manual */
$manual = VideoManual::query()->whereKey($locked->video_manual_id)->lockForUpdate()->firstOrFail();

$locked->status = JobStatus::Failed;
$locked->error = $error;
$locked->error_code = $code;
$locked->scenario_version_at_terminal = $manual->scenario_version; // 追加
$locked->save();

// manual 復帰 (kind=render かつ rendering のときのみ。preview は status を触らない)
if ($locked->kind === RenderKind::Render && $manual->status === VideoManualStatus::Rendering) {
    $manual->forceFill(['status' => VideoManualStatus::Ready])->save();
}
```
> 注: preview 失敗でも manual を lock 取得するようになる（version 読取りのため）。これは read+snapshot で
> あり、preview の manual.status は従来どおり触らない。既存の「preview は status を触らない」不変は保持。

### Model @property 追記
```php
// AnalysisJob.php / RenderJob.php いずれも error 系 property の並びに追加
 * @property int|null $scenario_version_at_terminal
```
`$fillable` には**追加しない**（保護的にサービス内で明示代入する。cuts.adopted_take_id 等と同方針）。

### PHPStan適合チェック
- [x] `scenario_version_at_terminal` は `int|null`（@property 明示）。`manual.scenario_version` は
      `int`（既存 @property）を代入するため型整合。
- [x] `$manual` は `firstOrFail()` で非 null（`/** @var VideoManual $manual */`）。
- [x] 戻り値型・例外挙動は不変（`failJob(): bool`）。

### テスト計画（施策7で実装）
- [ ] 解析失敗確定時、`scenario_version_at_terminal` = そのときの `manual.scenario_version` になる。
- [ ] preview 失敗でも snapshot が入る（従来 manual を触らなかった経路の回帰）。
- [ ] terminal 済み job への再 `failJob` は no-op（snapshot も status も不変）＝ `isTerminal()` 早期 return。

### リスク
- preview 失敗経路で manual を lock 取得するようになるため、既存 preview 失敗テストの副作用（クエリ数等）に
  影響しうる。挙動（status 不変・戻り値 bool）は保持。既存 `RenderJobService` テスト全 green を確認する。

---

## 施策3: `VideoManualService` に表示用 job 解決 + staleness 述語を追加

### 変更箇所
- `app/Services/Manual/VideoManualService.php`（メソッド追加）

### 波及変更
- テストファイル: 施策7 Feature（controller 経由で間接、あるいは Service 直接の Unit）。

### 追加コード
```php
use App\Enums\Manual\JobStatus;
use App\Enums\Manual\RenderKind;
use App\Models\AnalysisJob;
use App\Models\RenderJob;

/**
 * 表示用の最新解析 job。stale な失敗 (失敗確定後に scenario 保存が成立) は null を返す。
 * これにより Show の解析パネルは矛盾した「解析失敗」alert を出さない。
 */
public function displayAnalysisJob(VideoManual $manual): ?AnalysisJob
{
    $job = $manual->analysisJobs()->latest('id')->first();

    return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
        ? null
        : $job;
}

/** 表示用の最新 kind=render の job (stale 失敗は null)。 */
public function displayRenderJob(VideoManual $manual): ?RenderJob
{
    return $this->latestRenderJobForDisplay($manual, RenderKind::Render);
}

/** 表示用の最新 kind=preview の job (stale 失敗は null)。 */
public function displayPreviewJob(VideoManual $manual): ?RenderJob
{
    return $this->latestRenderJobForDisplay($manual, RenderKind::Preview);
}

private function latestRenderJobForDisplay(VideoManual $manual, RenderKind $kind): ?RenderJob
{
    $job = $manual->renderJobs()->where('kind', $kind->value)->latest('id')->first();

    return $job !== null && $this->isStaleFailure($manual, $job->status, $job->scenario_version_at_terminal)
        ? null
        : $job;
}

/**
 * 失敗 job が stale か (失敗確定後に scenario 保存が成立 = version が進んだ)。
 * snapshot が null (旧データ / 非失敗) の場合は not stale = 表示 (保守的に隠さない)。
 */
private function isStaleFailure(VideoManual $manual, JobStatus $status, ?int $versionAtTerminal): bool
{
    return $status === JobStatus::Failed
        && $versionAtTerminal !== null
        && $manual->scenario_version > $versionAtTerminal;
}
```
> `isStaleFailure` は `AnalysisJob`/`RenderJob` の共通形（status enum + snapshot int）を引数で受け、
> モデル型に依存しない（両 job で再利用）。

### PHPStan適合チェック
- [x] 戻り値型 `?AnalysisJob` / `?RenderJob` / `bool` 明示。
- [x] `->first()` は `Model|null`。relation の generic は既存モデルの `@return HasMany<AnalysisJob, ...>` に依存。
- [x] `$job->status` は `JobStatus`（enum）、`$job->scenario_version_at_terminal` は `int|null`。
- [x] 配列返却なし。DTO 変換は controller が既存 `fromJob()->toArray()` で行う。

### テスト計画（施策7）
- [ ] staleness 判定行列（下記 施策7 の受け入れ仕様）を controller Feature で固定。

### リスク / 設計注記
- **「最新」の定義 = id 降順（`latest('id')`）**: 既存 `VideoManualController::show` と**同一**（現行も
  `analysisJobs()->latest('id')->first()` / render/preview 同様）。job の id はアプリ内 auto-increment で
  単調増加し、バックフィル等の非時系列挿入経路は無い。`created_at` へ変えると既存挙動からの逸脱・回帰
  リスクになるため、**id 降順を固定**する（Codex design-review R1 [Critical] 対応）。

---

## 施策4: `VideoManualController::show` を display 系メソッド委譲に置換

### 変更箇所
- `app/Http/Controllers/Projects/VideoManualController.php` `show()`（現行 L92-145）

### 波及変更
- TypeScript 型定義: なし（props shape 不変）。
- API Resource/DTO: なし。
- テストファイル: 施策7 Feature。

### 現行コード（該当 props 部分）
```php
'analysis' => [
    'job' => ($latest = $manual->analysisJobs()->latest('id')->first()) === null
        ? null
        : AnalysisJobData::fromJob($latest, $manual)->toArray(),
    'hasDocument' => $manual->sourceDocuments()->exists(),
],
'render' => [
    'job' => ($render = $manual->renderJobs()->where('kind', RenderKind::Render->value)->latest('id')->first()) === null
        ? null
        : RenderJobData::fromJob($render, $manual)->toArray(),
    'previewJob' => ($preview = $manual->renderJobs()->where('kind', RenderKind::Preview->value)->latest('id')->first()) === null
        ? null
        : RenderJobData::fromJob($preview, $manual)->toArray(),
    'playbackJobId' => $manual->renderJobs()
        ->where('kind', RenderKind::Preview->value)
        ->where('status', JobStatus::Succeeded->value)
        ->whereNotNull('output_path')
        ->latest('id')
        ->value('id'),
],
```

### 変更後コード
```php
public function show(Request $request, Project $project, VideoManual $manual, SeoManager $seo, VideoManualService $manuals): Response
{
    // ... (guard / authorize / seo / user は不変) ...

    $analysisJob = $manuals->displayAnalysisJob($manual);
    $renderJob = $manuals->displayRenderJob($manual);
    $previewJob = $manuals->displayPreviewJob($manual);

    return Inertia::render('Manuals/Show', [
        // ... project / manual は不変 ...
        'analysis' => [
            'job' => $analysisJob === null ? null : AnalysisJobData::fromJob($analysisJob, $manual)->toArray(),
            'hasDocument' => $manual->sourceDocuments()->exists(),
        ],
        'render' => [
            'job' => $renderJob === null ? null : RenderJobData::fromJob($renderJob, $manual)->toArray(),
            'previewJob' => $previewJob === null ? null : RenderJobData::fromJob($previewJob, $manual)->toArray(),
            // playbackJobId は succeeded preview のみを見るため staleness 抑制の対象外 (不変)
            'playbackJobId' => $manual->renderJobs()
                ->where('kind', RenderKind::Preview->value)
                ->where('status', JobStatus::Succeeded->value)
                ->whereNotNull('output_path')
                ->latest('id')
                ->value('id'),
        ],
        'canManage' => $user->can('update', $manual),
    ]);
}
```
> `VideoManualService` を method injection で追加（他アクションと同じ DI 流儀）。薄い controller /
> Service 委譲（AGENTS 実装規約）を維持。DTO 変換は従来どおり controller 内で `fromJob()->toArray()`。

### PHPStan適合チェック
- [x] `displayAnalysisJob` 等は `?Model` を返す。null 分岐で `fromJob` を呼ばない（既存と同型）。
- [x] `VideoManualService` の method injection は Laravel の DI で解決（他アクションで実績あり）。
- [x] `response()->json()` 直書きなし（Inertia props / 既存 DTO）。

### テスト計画（施策7）
- [ ] staleness 判定行列を HTTP レベル（Inertia props）で検証。

### リスク
- `playbackJobId` は独立クエリのまま。stale 抑制と無関係（succeeded 限定）。回帰なし。

---

## 施策5: SOP 抽出「短すぎ」を `unextractable` から `tooShort` に分離（F-1-2）

### 変更箇所
- `app/Exceptions/Manual/AnalysisFailedException.php`（factory 追加）
- `app/Services/Manual/SopTextExtractor.php` L49-51（例外差し替え）

### 波及変更
- TypeScript 型定義 / DTO: なし（例外メッセージは job.error 列に保存されフロントは文言をそのまま表示）。
- テストファイル: `tests/Unit/Manual/SopTextExtractorTest.php`（既存「実質空 → unextractable」ケースの
  期待文言を `tooShort` に更新）。

### 変更後コード（AnalysisFailedException）
```php
/** テキスト抽出不能 (画像/スキャン手順書・破損・バイナリ) */
public static function unextractable(): self
{
    return new self('テキストを抽出できません。画像・スキャンの手順書は現在未対応です。');
}

/** 抽出できたが本文が実質空 (min_text_bytes 未満)。画像扱いと混同しない明示文言 */
public static function tooShort(): self
{
    return new self('手順書の本文が短すぎます。もう少し詳しい手順書をアップロードしてください。');
}
```

### 変更後コード（SopTextExtractor）
```php
$bytes = strlen($text);
if ($bytes < config()->integer('manual.analysis_min_text_bytes')) {
    throw AnalysisFailedException::tooShort(); // 短い有効テキスト → 画像未対応と別文言
}
```

> **判定基準は byte を意図的に維持（Codex design-review R1 [Warning] 対応）**: `analysis_min_text_bytes`
> (=100) は `analysis_max_text_bytes` と対の **token budget 整合の byte 基準**（`AnalysisTokenBudgetInvariantTest`
> が byte 算術を固定）。閾値の見直し・`mb_strlen` 化はスコープ外（概念設計で「文言の弁別のみ」と合意）。
> 本施策は**分岐先の例外種別と文言のみ**を変える。ユーザー文言は byte 値を露出しない汎用表現
> （「本文が短すぎます」）とし、100 bytes ≈ 日本語 33 文字程度の下限は据え置く。

### PHPStan適合チェック
- [x] `tooShort(): self` 戻り値型明示。ロジック不変（分岐先の例外種別のみ変更）。

### テスト計画（施策7）
- [ ] 既存「実質空 (min_text_bytes 未満)」テストの期待文言を「本文が短すぎます」に更新。
- [ ] 「画像/スキャン相当（未知 mime・バイナリ・破損 PDF）は従来どおり『テキストを抽出できません』」を維持。

### リスク
- `unextractable` を参照する他箇所（`kindFor` の default / parser catch / `ensureUtf8`）は**変更しない**
  （真に抽出不能なケースなので従来文言が正しい）。文言変更の影響は min-bytes 分岐に限定。

---

## 施策6: 作成フォームのタイトル入力で `oninput` エラークリア（F-1-3）

### 変更箇所
- `resources/js/pages/Manuals/Create.svelte` タイトル `FormField` 内の `Input`（現行 L57-67）

### 波及変更
- TypeScript 型定義 / props: なし。
- テストファイル: `tests/js/pages/ManualsCreate.test.ts`（クリア挙動のケース追加）。

### 現行コード
```svelte
<FormField label="タイトル" id="manual-title" error={form.errors.title} required>
    {#snippet children({ id, describedBy, invalid })}
        <Input
            {id}
            type="text"
            bind:value={form.title}
            error={invalid}
            aria-describedby={describedBy}
        />
    {/snippet}
</FormField>
```

### 変更後コード
```svelte
<FormField label="タイトル" id="manual-title" error={form.errors.title} required>
    {#snippet children({ id, describedBy, invalid })}
        <Input
            {id}
            type="text"
            bind:value={form.title}
            error={invalid}
            aria-describedby={describedBy}
            oninput={() => {
                // 入力し始めたらその場でタイトルエラーをクリア (次 submit を待たない)
                if (form.errors.title) form.clearErrors("title");
            }}
        />
    {/snippet}
</FormField>
```
> `Input` atom は `...rest`（`HTMLInputAttributes`）で `oninput` を透過するため、atom 変更は不要。
> `form.clearErrors("title")` は Inertia `useForm` の API。`FormField` の `invalid` は `error` prop
> （= `form.errors.title`）由来なので、error クリアで赤枠とエラー文言が同時に消える。
> `bind:value` の更新と `oninput` は両立する（Svelte は両方発火）。

### DESIGN.md / Atomic 準拠チェック
- [x] 新規 hex / SVG / コンポーネントなし。既存 `Input`/`FormField` の props 透過のみ。
- [x] atom（`Input`）は無状態のまま。状態（errors）は page（`Create.svelte`）が保持。層の逆流なし。

### テスト計画（施策7）
- [ ] 「タイトル未入力 submit → エラー表示 → タイトル入力（oninput）でエラー文言と invalid が消える」。

### リスク
- `oninput` は毎キーストロークで発火するが、`if (form.errors.title)` ガードで error がある時のみ
  `clearErrors` を呼ぶため無駄な再描画は最小。category / document のエラー挙動は変更しない（スコープ限定）。

---

## 施策7: テスト（Feature / Unit / Vitest）

**テスト先行の実装順（Codex design-review R1 [Critical] 対応 — red→green を機械的に固定）**:
実装は必ず「最初の失敗テスト」を先に追加し red を確認してから着手する。順序:

1. **7-C-1**（`SopTextExtractorTest`「実質空 → 本文が短すぎます」）を red 化 → 施策5 実装で green。
2. **7-B-1**（`ManualJobSnapshotTest`「analysis failJob 後に snapshot=失敗時 version」）を red 化
   → 施策1（migration）+ 施策2（AnalysisJobService）実装で green。
3. **7-B-2**（「render/preview failJob 後 snapshot 記録・preview は status 不変」）red → 施策2（RenderJobService）で green。
4. **7-A-1**（`ManualStaleJobDisplayTest`「HIGH 本丸: 解析失敗後 save で analysis.job=null」）red
   → 施策3（Service）+ 施策4（Controller）実装で green。
5. **7-A-2..7**（判定行列の残ケース）を追加 → green を確認。
6. **7-D-1**（`ManualsCreate`「タイトル入力でエラークリア」）red → 施策6 実装で green。
7. **7-E**（パネル job=null 非退行）は既存 green の維持確認。

各テストを先に追加し red を確認 → 実装 → green（AGENTS 思考原則5）。

### 7-A. Feature: staleness 判定行列（`tests/Feature/Projects/ManualStaleJobDisplayTest.php` 新規）

対象: `GET projects.manuals.show` の Inertia props（`Inertia\Testing\AssertableInertia`）。
Factory 生成（`VideoManual::factory()` / `AnalysisJobFactory::failed()` 等）で状態を作る。

受け入れ仕様（Codex R5 締め指摘を固定）:
- [ ] **HIGH 本丸**: analysis 失敗（snapshot=V）→ scenario 保存で `scenario_version`=V+1 → `analysis.job` が
      **null**（stale 抑制）。
- [ ] **not stale**: analysis 失敗（snapshot=V）で `scenario_version`=V のまま → `analysis.job` が失敗 job を返す。
- [ ] **legacy**: `scenario_version_at_terminal = null` の失敗 job は **null 化されない**（表示）。
- [ ] **no-op 保存の帰結（意図仕様）**: render 失敗（snapshot=V）→ `scenario_version`=V+1 → `render.job` が null。
- [ ] **scenario_version_changed CTA 保持**: render 失敗（error_code=scenario_version_changed, snapshot=N+1）
      で `scenario_version`=N+1 → `render.job` が失敗 job を返す（`previewJob` も同基準）。
- [ ] **succeeded は非抑制**: succeeded な analysis/render/preview job は version が進んでいても返る。
- [ ] **preview 独立**: preview 失敗（snapshot=V）→ 保存 V+1 → `previewJob` null、`playbackJobId` は
      succeeded preview があれば維持。
- [ ] **save 実経路の統合ケース（Codex design-review R1 [Warning] 対応）**: 上記のうち最低 1 ケースは
      `scenario_version` を直接更新せず **`ScenarioService::save()` の実経路**を通す。具体: 解析失敗（cuts あり,
      status=ready, snapshot=V）→ `ScenarioService::save()` で **no-op 保存**（内容無変更でも version=V+1）→
      `analysis.job` が null。「保存世代基準（no-op でも version++ で stale）」の契約と実装のズレを固定する。

### 7-B. Feature: snapshot 書込み・不変（`tests/Feature/Projects/` または既存 `ManualAnalyzeTest` 拡張）

- [ ] **7-B-1**: `AnalysisJobService::failJob` 後、job.`scenario_version_at_terminal` = 失敗時 `manual.scenario_version`。
- [ ] **7-B-2**: `RenderJobService::failJob`（**preview 含む**）後、snapshot が入り、かつ **preview 失敗では
      `manual.status` が不変**（snapshot 記録のために manual を lock 取得するようになるが status は触らない）。
- [ ] terminal 済み job への再 failJob は no-op（snapshot も status も不変）＝ `isTerminal()` 早期 return。

### 7-C. Unit: SopTextExtractor 文言分離（`tests/Unit/Manual/SopTextExtractorTest.php` 更新）

- [ ] 既存「実質空 (min_text_bytes 未満)」の期待を「本文が短すぎます」に更新（`tooShort`）。
- [ ] 「判定不能バイナリ」「破損 PDF」「未知 mime」は従来どおり「テキストを抽出できません」（`unextractable`）を維持。

### 7-D. Vitest: 作成フォームのタイトルクリア（`tests/js/pages/ManualsCreate.test.ts` 更新）

- [ ] タイトルエラー prop を与えて描画 → タイトル `Input` に入力（`fireEvent.input`）→ `form.clearErrors`
      経由でエラー文言/invalid が消えることを検証。Inertia `useForm` を使うため、`page` の props 経由で
      `errors.title` を初期投入するか、submit → mock でエラー状態を作る（既存テストの `render` 流儀に合わせる）。

### 7-E. Vitest: パネルの job=null 非表示（`AnalysisPanel.test.ts` / `RenderPanel.test.ts` 非退行）

- [ ] `job: null` で render → `analysis-error` / `render-error` / `preview-error` が出ない（既存の nullable
      挙動の固定。stale 抑制が server で行われる前提の回帰テスト）。既存ケースが green のままであることを確認。

### PHPStan適合チェック
- [x] Feature/Unit は Factory 生成・`AssertableInertia`・`expect()->toThrow()` の既存流儀。型追加なし。

### リスク
- 7-A の version 遷移を作るには `ScenarioService::save`（実保存）or 直接 `scenario_version` 更新が必要。
  実経路（save）で作ると take/step factory 依存が増えるため、**failed job の snapshot と manual.scenario_version
  を factory/直接更新で組み立てる**方針（staleness の単体挙動を最小構成で固定）。実保存経路の統合は
  7-B / 既存 ScenarioService テストがカバー。

---

## 実装後の検証コマンド

```bash
composer phpstan        # level 10
composer test           # Pest (--parallel, RefreshDatabase)
vendor/bin/pint --test  # フォーマット
pnpm lint && pnpm typecheck && pnpm test && pnpm build
```

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 manuals ドメイン（Controller/Service/Model/migration/Svelte）への局所的追加。新規モデルなし、DTO/TS/props shape 不変。カラム追加 migration は 1 本・破壊操作なし。施策間の結合は「migration→failJob→Service→Controller」の一方向で明快。 |
| 競合リスク | 低。`AnalysisJobService`/`RenderJobService`/`VideoManualService`/`VideoManualController`/`SopTextExtractor`/`Create.svelte` を同時に触る他 TODO が無ければ衝突しない。 |

## 使命・禁止事項 最終チェック

- 使命寄与: SOP→シナリオ→撮影の中核導線で、完了/成功後に残る矛盾エラーを決定的に除去（○）。
- 禁止事項1（テストなし禁止）: Feature/Unit/Vitest を施策7で先行必須化（○）。
- 禁止事項2（PHPStan widen 禁止）: 型明示・null 安全で level 10 維持、ignore/baseline 不使用（○）。
- 禁止事項3（破壊操作）: カラム追加 migration のみ。`migrate:fresh` 等は使わない（○）。
- 禁止事項4（`response()->json()` 直書き）: Inertia props + 既存 DTO の `toArray()`（○）。
- 禁止事項8（disabled 禁止）: タイトルは押下時エラー表示のまま。oninput はクリアのみ（○）。
- 共有ロック規約: snapshot は failJob（既 manual lock 済み書込み経路）で job 列にのみ書く。cuts/
  scenario_version/status は書かない → `ScenarioWritePathInventoryTest` 登録不要（○）。
- DS/Atomic: 新規 hex/SVG/コンポーネントなし。既存 atom の props 透過のみ（○）。
