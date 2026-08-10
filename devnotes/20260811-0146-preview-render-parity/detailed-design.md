# 詳細設計: preview-render-parity (プレビューと完成生成の判断基準を揃える)

> 対応 finding: bug-hunt run `20260811-003230` **F-1-01 (High)** (実ブラウザで再現済み)。
> 概念設計: [`conceptual-design.md`](./conceptual-design.md) (Codex Round 2 で APPROVED)
> 実査ブリーフ: [`recon-brief.md`](./recon-brief.md)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より転記）

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. **必須条件未充足を理由にボタンを disabled にする UI**(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用

> 本設計は **禁止事項 8 が最重要**である。「未撮影があるからプレビューを押させない」は
> 本設計の否定であり、実装がそちらへ倒れたら設計違反として差し戻す。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）/ **RefreshDatabase** はグローバル適用・`--parallel` 実行
  （個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `declare(strict_types=1)` + 日本語コメント
- `composer fix`（Pint）/ `pnpm lint:fix`
- フロントは Svelte 5 runes + **DESIGN.md token のみ**(hex 直書きを増やさない)、
  component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript

## 前提として確認した現行コード（実在確認済み）

| ファイル | 位置 | 事実 |
|---|---|---|
| `app/Services/Manual/RenderJobService.php` | L363-377 | render の 422 判定 `assertAllCutsHaveAdoptedReadyTakes()` |
| 同上 | L126-168 | `triggerPreview()`。**採用テイクの判定を持たない** |
| `app/Services/Manual/RenderPipeline.php` | L240-273 | `clipSpecFor()`。preview のみ `RenderClipSource::Placeholder` |
| 同上 | L280-347 | `finalize()` (terminal tx。`render_jobs` → `video_manuals` の順でロック) |
| 同上 | L437-443 | `updateProgress()` は `where status=running` の条件付き UPDATE |
| `app/Services/Render/FfmpegVideoComposer.php` | L148-163 | 黒背景プレースホルダ (`color=black`, `preview_placeholder_seconds`=3 秒) |
| `app/Services/Manual/CutSequencer.php` | L24-49 | 表示順 + ラベル (`手順N` / `急所N-M`)、`adoptedTake` を eager load |
| `app/Http/Controllers/Projects/VideoManualController.php` | L113-153 | `Manuals/Show` の props (`render` キー) |
| `resources/js/components/features/manual/RenderPanel.svelte` | 全体 | プレビュー/完成の UI。ボタンは disabled にしない方針が既にコメントで明示 |
| `app/DataTransferObjects/Manual/RenderJobData.php` | 全体 | 201 / ポーリング / props 共用の DTO |
| `app/Models/RenderJob.php` | L45-62 | `$fillable` を持たず明示代入のみ。cast 宣言 |
| `tests/Architecture/ScenarioWritePathInventoryTest.php` | L83-107 | `adopted_take_id` の deny-by-default ファイル allowlist (**本設計の gate の先例**) |

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 判定の単一化 (`AdoptedReadyTakeCoverage`) | `app/Services/Manual/AdoptedReadyTakeCoverage.php` (新) / `app/DataTransferObjects/Manual/TakeCoverageData.php` (新) / `RenderJobService.php` / `RenderPipeline.php` | High |
| 2 | 事前告知 (props + UI 注記) | `VideoManualController.php` / `resources/js/types/manual.ts` / `pages/Manuals/Show.svelte` / `features/manual/RenderPanel.svelte` | High |
| 3 | 事後説明 (`placeholder_cut_count`) | migration (新) / `RenderJob.php` / `RenderJobFactory.php` / `RenderManifest.php` / `RenderResult.php` / `RenderPipeline.php` / `RenderJobData.php` / `types/manual.ts` / `RenderPanel.svelte` | High |
| 4 | 再発防止 (Architecture gate) | `app/Enums/Security/AdoptedTakeReferenceKind.php` (新) / `app/Support/Security/AdoptedTakeReferenceInventory.php` (新) / `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php` (新) | High |
| 5 | ドキュメント同期 | `docs/architecture.md` / `AGENTS.md` (ドメイン規約) | Medium |

---

## 施策 1: 判定の単一化 (`AdoptedReadyTakeCoverage`)

### 変更箇所

- 新規: `app/Services/Manual/AdoptedReadyTakeCoverage.php`
- 新規: `app/DataTransferObjects/Manual/TakeCoverageData.php`
- 変更: `app/Services/Manual/RenderJobService.php` (L84-85, L363-377)
- 変更: `app/Services/Manual/RenderPipeline.php` (L240-258)

### 波及変更

- TypeScript 型定義: 施策 2 で `TakeCoverageProps` を追加（本施策単体では無し）
- API Resource/DTO: `TakeCoverageData` 新設。既存 Resource の shape 変更は無し
- テストファイル: `tests/Feature/Manual/RenderTriggerTest.php` は **422 の文言・キーを変えないため更新不要**
  （変更が必要になったらそれは設計違反 = 契約を壊している合図）

### 現行コード

```php
// RenderJobService.php L363-377
private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
{
    $missing = [];
    foreach ($ordered as $entry) {
        $take = $entry->cut->adoptedTake;
        if ($take === null || $take->status !== TakeStatus::Ready) {
            $missing[] = $entry->label;
        }
    }
    if ($missing !== []) {
        throw ValidationException::withMessages([
            'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $missing)],
        ]);
    }
}

// RenderPipeline.php L240-247 (抜粋)
$take = $cut->adoptedTake;
if ($take === null || $take->status !== TakeStatus::Ready) {
    if ($job->kind === RenderKind::Render) { throw new LogicException(...); }
    return new RenderClipSpec(source: RenderClipSource::Placeholder, ...);
}
```

**同じ式が 2 ファイルに複製され、preview トリガーには存在しない** — これが F-1-01 の構造的原因。

### 変更後コード

```php
// app/DataTransferObjects/Manual/TakeCoverageData.php (新規)
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 採用テイクの充足状況 (「採用済みかつ ready のテイクを持つカットが揃っているか」)。
 * render の 422 と、詳細画面の事前告知が**同じ値**を見るための唯一の shape。
 *
 * ★ props 用の toProps() はラベルを PROP_LABEL_LIMIT 件で打ち切るが、
 *   missingCount は**常に全件数**である (件数を打ち切ると嘘になる)。
 */
final readonly class TakeCoverageData
{
    /** props に載せるラベルの上限 (全 67 カット分の文字列を毎描画で送らない) */
    public const int PROP_LABEL_LIMIT = 10;

    /**
     * @param  list<string>  $missingLabels  未充足カットの表示ラベル (CutSequencer の表示順)
     */
    public function __construct(
        public int $totalCuts,
        public array $missingLabels,
    ) {}

    public function missingCount(): int
    {
        return count($this->missingLabels);
    }

    /**
     * @return array{total_cuts: int, missing_count: int, missing_labels: list<string>}
     */
    public function toProps(): array
    {
        return [
            'total_cuts' => $this->totalCuts,
            'missing_count' => $this->missingCount(),
            'missing_labels' => array_slice($this->missingLabels, 0, self::PROP_LABEL_LIMIT),
        ];
    }
}
```

```php
// app/Services/Manual/AdoptedReadyTakeCoverage.php (新規)
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\DataTransferObjects\Manual\Render\OrderedCut;
use App\DataTransferObjects\Manual\TakeCoverageData;
use App\Enums\Manual\TakeStatus;
use App\Models\Cut;
use App\Models\VideoManual;

/**
 * 「採用済みかつ ready のテイクを持つか」の**唯一の判定**。
 *
 * render (422 でブロック) と preview (ブロックせず告知) は**制裁が違うだけで基準は同じ**である。
 * 基準がファイルをまたいで複製されると再び乖離する (bug-hunt F-1-01 の構造的原因) ため、
 * 述語 isMissing() をここ 1 箇所に閉じ、`AdoptedReadyTakeCriterionInventoryTest` が
 * deny-by-default で「他ファイルが同じ判定を書き直していないこと」を機械検査する。
 *
 * 読み取り専用 (cuts / takes / status を 1 バイトも書かない)。
 */
final class AdoptedReadyTakeCoverage
{
    /**
     * 唯一の述語。**この式を他所へ写経しない**。
     *
     * 前提: $cut は adoptedTake を eager load 済みで呼ぶこと
     * (CutSequencer::orderedWithLabels が `with('adoptedTake')` を張っている)。
     * lazy load でも結果は同じだが N+1 になる。
     */
    public static function isMissing(Cut $cut): bool
    {
        $take = $cut->adoptedTake;

        return $take === null || $take->status !== TakeStatus::Ready;
    }

    /** 表示順カット列からの集計 (トリガー tx が既に持っている列を再利用する経路) */
    public static function fromOrdered(array $ordered): TakeCoverageData
    {
        $missing = [];
        foreach ($ordered as $entry) {
            if (self::isMissing($entry->cut)) {
                $missing[] = $entry->label;
            }
        }

        return new TakeCoverageData(totalCuts: count($ordered), missingLabels: $missing);
    }

    /** manual からの集計 (詳細画面 props の経路) */
    public static function for(VideoManual $manual): TakeCoverageData
    {
        return self::fromOrdered(CutSequencer::orderedWithLabels($manual));
    }
}
```

`fromOrdered` の PHPDoc には `@param list<OrderedCut> $ordered` を付ける (PHPStan level 10)。

```php
// RenderJobService.php — 判定を委譲し、422 の**文言と例外キーは一字も変えない**
private function assertAllCutsHaveAdoptedReadyTakes(array $ordered): void
{
    $coverage = AdoptedReadyTakeCoverage::fromOrdered($ordered);
    if ($coverage->missingCount() === 0) {
        return; // アーリーリターン
    }

    throw ValidationException::withMessages([
        'takes' => ['採用テイクが未設定のカットがあります: '.implode('、', $coverage->missingLabels)],
    ]);
}
```

```php
// RenderPipeline.php clipSpecFor() — 述語のみ委譲 (分岐の意味は変えない)
private function clipSpecFor(RenderJob $job, Cut $cut, string $label): RenderClipSpec
{
    if (AdoptedReadyTakeCoverage::isMissing($cut)) {
        if ($job->kind === RenderKind::Render) {
            throw new LogicException("render job {$job->id}: 採用テイク欠落 ({$label})");
        }

        return new RenderClipSpec(cutId: $cut->id, label: $label,
            source: RenderClipSource::Placeholder, takeVideoPath: null, stillDisplaySeconds: null,
            subtitlePrimary: $cut->subtitle_primary, subtitleSecondary: $cut->subtitle_secondary);
    }

    $take = $cut->adoptedTake;
    Assert::notNull($take, 'isMissing() が false なら採用テイクは必ず存在する');
    // ... 以降は現行どおり ($take->video_path 等)
}
```

> `Assert::notNull` を挟むのは PHPStan level 10 のため（述語が false でも静的には
> `?Take` のまま）。**述語の再実装ではない**（`TakeStatus::Ready` を参照しない）。

`triggerPreview()` は**判定を追加しない**（ブロックしないので判定不要）。preview が使う
coverage は詳細画面 props 側 (施策 2) と manifest 側 (施策 3) で消費される。

### PHPStan適合チェック

- [x] 戻り値の型が明示されている（`bool` / `TakeCoverageData`）
- [x] null 安全（`Assert::notNull` で `?Take` を絞る。`@phpstan-ignore` は使わない）
- [x] DTO を返している（配列返却は `toProps()` のみで shape を array-shape 型で宣言）
- [x] Generics: `@param list<OrderedCut>` / `@param list<string>` / `@return array{...}`

### リスク

- `RenderJobService::trigger()` は `$ordered` を 1 回だけ作り `fromOrdered` に渡すため
  **クエリは増えない**。詳細画面 props (施策 2) では `for()` が cuts + adoptedTake の
  2 クエリを増やす（67 カット規模で無視できる。N+1 は `with('adoptedTake')` で回避済み）。
- 422 の文言・キーを変えないので既存クライアント (RenderPanel の alert) に影響なし。

---

## 施策 2: 事前告知 (props + RenderPanel の注記)

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` L134-149 (`render` props)
- `resources/js/types/manual.ts` (`RenderProps` / `TakeCoverageProps`)
- `resources/js/pages/Manuals/Show.svelte` L130-138 (props 受け渡し)
- `resources/js/components/features/manual/RenderPanel.svelte` (Props + 注記の描画)

### 波及変更

- TypeScript 型定義: `TakeCoverageProps` 新設、`RenderProps.coverage` 追加（**必須**フィールド）
- API Resource/DTO: `TakeCoverageData::toProps()`（Inertia props であり JSON API ではない）
- テストファイル: `tests/js/pages/ManualsShow.test.ts`（props 配線）/
  `tests/js/components/features/manual/RenderPanel.test.ts`（描画）/
  `tests/Feature/Manual/PreviewCoverageParityTest.php`（props の内容）

### 変更後コード

```php
// VideoManualController::show() の 'render' キー (施策 3 の playbackJob 置換を織り込んだ最終形)
'render' => [
    'job' => ...,
    'previewJob' => ...,
    'playbackJob' => ...,   // 施策 3 で playbackJobId から置換 (旧キーは残さない)
    // 「使用できる採用テイクがない」カットの充足状況。
    // render の 422 と同じ述語から出す = 判断基準を 1 箇所に置く (F-1-01)
    'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
],
```

```ts
// resources/js/types/manual.ts
/** PHP: App\DataTransferObjects\Manual\TakeCoverageData::toProps() と対 */
export interface TakeCoverageProps {
    /** カット総数 */
    total_cuts: number;
    /** 使用できる採用テイクがないカット数 (**打ち切らない全件数**) */
    missing_count: number;
    /** 該当カットの表示ラベル (先頭 10 件で打ち切られる。件数は missing_count が正) */
    missing_labels: string[];
}

export interface RenderProps {
    job: RenderJobProps | null;
    previewJob: RenderJobProps | null;
    /** 再生できるプレビュー (施策 3 で playbackJobId から置換。旧キーは残さない) */
    playbackJob: RenderJobProps | null;
    /** 描画時点のスナップショット (常に最新ではない。生成物の実績は playbackJob.placeholder_cut_count) */
    coverage: TakeCoverageProps;
}
```

#### 文言の正確さ（Round 2 [Warning] 対応）

述語は `adoptedTake === null || status !== Ready` であり、`TakeStatus` は
`uploading / processing / ready / failed` の 4 値を持つ。つまり
**「まだ撮っていない」だけでなく「採用済みだがアップロード中・処理中・失敗」も同じ扱い**になる。
よって「未撮影」「テイクが採用されていません」と断定する文言は事実と食い違う。
**告知文は述語の意味 (=「使用できる採用テイクがない」) をそのまま言う**。

```svelte
<!-- RenderPanel.svelte: preview ブロックの先頭 (ボタン群の下・進捗の上) -->
{#if canManage && coverage.missing_count > 0}
    <div data-testid="preview-coverage-note">
        <Alert type="warning" title="プレビューに黒背景の区間があります">
            {coverage.missing_count} / {coverage.total_cuts} 件のカットに、撮影・処理が完了した
            採用テイクがありません ({missingLabelSummary})。プレビューは生成できますが、
            該当区間は黒背景になります。完成動画の生成には、すべてのカットで撮影・処理が完了した
            採用テイクが必要です。
        </Alert>
    </div>
{/if}
```

```ts
// 先頭 10 件 + 残数の要約 (props 側で打ち切られている前提を UI 側にも書く)
const missingLabelSummary = $derived(
    coverage.missing_labels.length < coverage.missing_count
        ? `${coverage.missing_labels.join("、")} ほか ${coverage.missing_count - coverage.missing_labels.length} 件`
        : coverage.missing_labels.join("、"),
);
```

- **ボタンは `disabled` にしない / 確認ダイアログも足さない**（禁止事項 8・概念設計 判断 1）。
- 色・余白は既存 `Alert` atom (`type="warning"`) と既存 utility class のみ。
  **hex 直書きを 1 つも増やさない**。新規 atom / molecule も作らない
  （Atomic Design の層をまたがない = `features/manual` 内で完結）。

### PHPStan適合チェック

- [x] `toProps()` の array-shape が宣言されている（Inertia props に `mixed` を渡さない）
- [x] `AdoptedReadyTakeCoverage::for()` は `VideoManual` を受け `TakeCoverageData` を返す

### リスク

- **props は描画時点のスナップショット**。別タブ・別ユーザーの撮影で古くなる
  （押下は止めないので詰みにはならない）。「常に最新」とは書かない。
- `coverage` を必須フィールドにするため、`Manuals/Show` を描画する他経路があれば
  そこも props を足す必要がある → `InertiaRenderPageExistsInvariantTest` の対象外なので
  **実装時に `grep "Manuals/Show"` で経路が 1 本 (`VideoManualController::show`) だけであることを確認する**。

---

## 施策 3: 事後説明 (`render_jobs.placeholder_cut_count`)

### 変更箇所

- 新規 migration: `database/migrations/2026_08_11_xxxxxx_add_placeholder_cut_count_to_render_jobs_table.php`
- `app/Models/RenderJob.php`（`@property` + cast）
- `database/factories/RenderJobFactory.php`（既定 `null` + state の明示。下記）
- `app/DataTransferObjects/Manual/Render/RenderManifest.php`（`placeholderCutCount()` メソッド）
- `app/DataTransferObjects/Manual/Render/RenderResult.php`（`placeholderCutCount` フィールド）
- `app/Services/Manual/RenderPipeline.php` L110-114（`RenderResult` 生成）/ L316-319（finalize の書き込み）
- `app/DataTransferObjects/Manual/RenderJobData.php`（DTO フィールド + `toArray()`）
- `resources/js/types/manual.ts`（`RenderJobProps`）/ `RenderPanel.svelte`（注記）

### 波及変更

- TypeScript 型定義: `RenderJobProps.placeholder_cut_count: number | null`（**必須**）、
  `RenderProps.playbackJobId: number | null` → **`playbackJob: RenderJobProps | null` へ置換**
- API Resource/DTO: `RenderJobResource` の shape に 1 キー追加（201 / ポーリング 200 の両方）
- Svelte Props: `RenderPanel` の `playbackJobId` → `playbackJob`（`Manuals/Show.svelte` も同時変更）
- テストファイル: `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`
  （応答 shape の期待値に新キー / props の `playbackJob`）、
  `tests/Feature/Manual/RenderPipelineTest.php`（`RenderResult` 生成の検証がある場合）、
  `tests/js/components/.../RenderPanel.test.ts`（`renderJobBody()` ヘルパに新キー・props 名変更）、
  `tests/js/pages/ManualsShow.test.ts`（props 配線）

### 変更後コード

```php
// migration
public function up(): void
{
    Schema::table('render_jobs', function (Blueprint $table): void {
        // その動画が実際に含んだプレースホルダ (黒背景) クリップ数。
        // null = 「その動画について言えることが無い」(既存行 / queued / running / finalize 未到達の failed)。
        // 索引は張らない (検索経路が無く、常に単一行の表示に使う)。
        $table->unsignedInteger('placeholder_cut_count')->nullable()->after('output_path');
    });
}

public function down(): void
{
    Schema::table('render_jobs', function (Blueprint $table): void {
        $table->dropColumn('placeholder_cut_count');
    });
}
```

```php
// RenderJobFactory (Round 1 [Warning] 対応: 「アプリが作った succeeded」と「legacy 行」を fixture で区別する)
// definition(): 'placeholder_cut_count' => null,

/** 成功確定の状態 (output_path 付き)。アプリ生成後は必ず件数を持つ = 既定 0 */
public function succeeded(string $outputPath, int $placeholderCutCount = 0): static
{
    return $this->state(fn () => [
        'status' => JobStatus::Succeeded->value,
        'progress' => 100,
        'output_path' => $outputPath,
        'placeholder_cut_count' => $placeholderCutCount,
    ]);
}

/** 本変更**以前**から在る succeeded 行の再現 (placeholder_cut_count は null)。UI の null 分岐用 */
public function legacySucceeded(string $outputPath): static
{
    return $this->succeeded($outputPath)->state(fn () => ['placeholder_cut_count' => null]);
}
```

```php
// RenderManifest (クリップ列から導出する = 二重管理を作らない)
/** プレースホルダ (黒背景) に落ちたクリップ数。読み取り一貫性の確定点である clips から導く */
public function placeholderCutCount(): int
{
    return count(array_filter(
        $this->clips,
        static fn (RenderClipSpec $clip): bool => $clip->source === RenderClipSource::Placeholder,
    ));
}
```

```php
// RenderResult に 1 フィールド追加 (生成は RenderPipeline::run の 1 箇所のみ)
public function __construct(
    public string $outputPath,
    public array $clipDurationsMs,
    public int $totalDurationMs,
    /** manifest 由来。**現在の manual 状態から数え直さない** (生成物の説明であるため) */
    public int $placeholderCutCount,
) {}
```

```php
// RenderPipeline::run() L110-114
$result = new RenderResult(
    outputPath: $manifest->outputKey,
    clipDurationsMs: $composed->clipDurationsMs,
    totalDurationMs: $composed->totalDurationMs,
    placeholderCutCount: $manifest->placeholderCutCount(),
);

// RenderPipeline::finalize() — job 行ロック済みの terminal tx 内 (L316 付近)
$locked->status = JobStatus::Succeeded;
$locked->progress = 100;
$locked->output_path = $result->outputPath;
$locked->placeholder_cut_count = $result->placeholderCutCount; // manifest 由来の実績値
$locked->save();
```

**書き込み位置を finalize にする理由（ロック順序）**: 値が確定するのは buildManifest だが、
そこは `video_manuals` を先にロックしている。同 tx で `render_jobs` を UPDATE すると
グローバル順 `render_jobs → video_manuals` の**逆順取得**になり、`finalize` / `failJob` と
循環待ちを構成しうる。finalize は既に `render_jobs → video_manuals` の正順でロック済みなので、
そこに 1 列足すのが唯一の順序安全な置き場である（`updateProgress` の条件付き UPDATE と同様に、
terminal 化後の書き戻しも起きない = finalize の `status !== Running` guard が先に return する）。

```php
// RenderJobData
public function __construct(
    ...,
    public ?int $placeholderCutCount,   // 追加
) {}

// fromJob(): placeholderCutCount: $job->placeholder_cut_count
// toArray(): 'placeholder_cut_count' => $this->placeholderCutCount,
// @return array{..., placeholder_cut_count: int|null} に更新 (Resource の docblock も同じ)
```

#### 再生対象 job の props 化（Round 1 [Warning] 対応。**追加ではなく置換**）

現行の props は `playbackJobId: number | null`（最新 succeeded preview の **id だけ**）で、
注記の出所（`previewJob` = 最新 preview job）と**別世代になりうる**。
そこで **`playbackJobId` を `playbackJob: RenderJobProps | null` へ置き換える**
（両方は残さない = 思考原則 3「後方互換の並走を残さない」）。

```php
// VideoManualController::show() の 'render' キー (playbackJobId を置換)
$playbackJob = $manual->renderJobs()
    ->where('kind', RenderKind::Preview->value)
    ->where('status', JobStatus::Succeeded->value)
    ->whereNotNull('output_path')
    ->latest('id')
    ->first(); // 従来は ->value('id') だった (クエリ本数は増えない)

'render' => [
    'job' => ...,
    'previewJob' => ...,
    // 再生できるプレビューの DTO。動画 URL と注記が**同一オブジェクト**から出る
    'playbackJob' => $playbackJob === null ? null : RenderJobData::fromJob($playbackJob, $manual)->toArray(),
    'coverage' => AdoptedReadyTakeCoverage::for($manual)->toProps(),
],
```

```svelte
<!-- RenderPanel.svelte: 再生ブロック全体を playbackJob の null 検査の内側に置く
     (既存の表示条件 !previewInFlight も維持する。TS の null 安全もここで閉じる) -->
{#if playbackJob !== null && !previewInFlight}
    {#if playbackNote !== null}
        <p class="text-caption text-text-secondary" data-testid="preview-placeholder-note">
            このプレビューは {playbackNote} 件のカットに使用できる採用テイクがないため、
            その区間が黒背景になっています。
        </p>
    {/if}
    <video
        src={`/projects/${projectId}/manuals/${manualId}/render-jobs/${playbackJob.id}/playback`}
        ...
    ></video>
{/if}
```

```ts
// ローカル state も job オブジェクトで持つ (poll の preview 成功分岐は playbackJob = body)
let playbackJob = $state<RenderJobProps | null>(playbackJobProp);

/** 再生している動画**そのもの**の実績値だけを出す (別世代の値で説明しない) */
const playbackNote = $derived(
    playbackJob !== null &&
    playbackJob.placeholder_cut_count !== null &&
    playbackJob.placeholder_cut_count > 0
        ? playbackJob.placeholder_cut_count
        : null,
);
```

> この置換により「最新 preview job と再生対象が別世代」という穴が**条件分岐ではなく構造で**消える。
> `playbackJob` は必ず succeeded かつ `output_path` 非 NULL の preview であり、
> 注記はその行の `placeholder_cut_count` からしか出ない。

### PHPStan適合チェック

- [x] 追加フィールドは `?int`（DB nullable と一致）。cast は `'placeholder_cut_count' => 'integer'`
- [x] `RenderJobData::toArray()` / `RenderJobResource::toArray()` の array-shape docblock を同時更新
- [x] `RenderManifest::placeholderCutCount()` は `int` を返す（`array_filter` の closure に型宣言）

### リスク

- **既存行はすべて `null`**（backfill しない）。UI は `null` で注記を出さないため、
  過去のプレビューには何も表示されない = **嘘をつかない側に倒す**（0 と null を同一視しない）。
- `playbackJobId` → `playbackJob` の置換は **props の破壊的変更**である。旧キーを残さないため
  （思考原則 3）、`Manuals/Show.svelte` / `RenderPanel.svelte` / TS 型 / 既存テストを
  **同一 PR ですべて追随**させる（片方だけ直すと型エラーで即座に落ちる = 検出可能）。
- `RenderJobResource` の shape に 1 キー増える。**同一オリジン XHR の自前クライアントのみ**が
  消費者であり外部公開 API ではない（`routes/web.php` の web group）。
- `RenderResult` のコンストラクタ引数追加は名前付き引数で呼ばれており生成箇所は 1 つ。

---

## 施策 4: 再発防止 (Architecture gate)

### 変更箇所

- 新規: `app/Enums/Security/AdoptedTakeReferenceKind.php`
- 新規: `app/Support/Security/AdoptedTakeReferenceInventory.php`
- 新規: `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`

### 不変条件（この gate が守るもの）

> **「採用済みかつ ready のテイクを持つか」の判定式を書いてよいのは
> `AdoptedReadyTakeCoverage` ただ 1 ファイルである。**
> `adoptedTake` に触れる app/ 配下のファイルは、区分と 30 文字以上の根拠を付けて
> 目録に登録しなければならない（deny-by-default）。

### 区分 enum

```php
enum AdoptedTakeReferenceKind: string
{
    /** 判定式 (adoptedTake と TakeStatus::Ready の同居) の実体。**1 ファイルのみ** */
    case Canonical = 'canonical';
    /** 判定を AdoptedReadyTakeCoverage へ委譲し、自前の ready 判定を持たない参照 */
    case DelegatedToCoverage = 'delegated_to_coverage';
    /** relation 宣言・eager load 指定など、判定を含まない構造上の参照 */
    case RelationWiring = 'relation_wiring';
    /**
     * ready 状態を見ない別基準 (「採用テイクが紐づいているか」だけを数える面)。
     * 統合してよいという意味ではなく、**別概念として意図的に残していること**の記録。
     */
    case DifferentCriterion = 'different_criterion';
}
```

### 目録（実装時の初期値。現行コードの実在確認済み）

| ファイル (app/ 相対) | 区分 | 根拠の要点 |
|---|---|---|
| `Services/Manual/AdoptedReadyTakeCoverage.php` | Canonical | 判定式の実体。render の 422 と preview の告知が同じ述語を通るための唯一の場所 |
| `Services/Manual/CutSequencer.php` | RelationWiring | 表示順の取得で `with('adoptedTake')` を張るのみ。判定式を持たない |
| `Services/Manual/RenderJobService.php` | DelegatedToCoverage | 尺上限計算で `adoptedTake->duration_ms` を読むだけ。充足判定は coverage へ委譲済み |
| `Services/Manual/RenderPipeline.php` | DelegatedToCoverage | clipSpecFor が `isMissing()` を呼ぶ。素材パス取得のため take 実体を読む |
| `Models/Cut.php` | RelationWiring | belongsTo relation の宣言 |
| `DataTransferObjects/Capture/CaptureManualDetailData.php` | DifferentCriterion | 撮影ナビの表示用に採用テイクを読む。ready 判定はしない |
| `Http/Controllers/Capture/CaptureManualController.php` | DifferentCriterion | `whereHas('adoptedTake')` の件数集計。ready を見ない別基準 |
| `Services/Dashboard/DashboardService.php` | DifferentCriterion | `whereDoesntHave('adoptedTake')` の撮影待ち集計。ready を見ない別基準 |
| `Console/Commands/Development/PipelineSmokeCommand.php` | DifferentCriterion | bug-hunt の通し確認で未採用件数を数えるのみ |

> 実装時に `rg -n "adoptedTake" app/` を再実行し、**列挙が実在と一致すること**を確認してから
> 目録を確定する（この表は設計時点のスナップショットである）。

### テスト（`tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`）

走査は `Tests\Support\PhpTokenScan::normalize()` を使う（既存の token 走査基盤。
コメント・docblock 内の出現は数えない）。検出は 2 系統:

- **検出 A（参照の母集団）**: 識別子 `adoptedTake`（プロパティフェッチ）または
  文字列リテラル `'adoptedTake'`（`with` / `whereHas` / `whereDoesntHave` / `doesntHave` 引数）を
  含む app/ 配下の .php
- **検出 B（判定式の同居）**: 検出 A に該当し、**かつ** `TakeStatus::Ready` を含むファイル

テストケース:

| # | テスト名 | 検証 |
|---|---|---|
| 1 | `adoptedTake を参照する app/ のファイルはすべて目録に登録されている` | 検出 A ∖ 目録 = ∅（deny-by-default） |
| 2 | `目録の全エントリが実在の参照を持つ` | 目録 ∖ 検出 A = ∅（**exact-fit**。stale entry で常時緑になるのを防ぐ） |
| 3 | `走査母集団が空でない` | 検出 A の件数 ≥ 5（**負のコントロール**。走査が壊れて 0 件になったら fail） |
| 4 | `ready 判定を持てるのは Canonical の 1 ファイルだけ` | 検出 B == `{Services/Manual/AdoptedReadyTakeCoverage.php}` |
| 5 | `判定式の同居ファイルが 0 件なら fail する` | 検出 B の件数 == 1（**規則が空振りしていないことの保証**。4 と分ける） |
| 6 | `目録の根拠は 30 文字以上ある` | 全エントリの rationale 長 |
| 7 | `Canonical 区分の登録は 1 件だけである` | 目録側の cap（exact-fit を目録側からも閉じる） |

### mutation で赤化を確認する手順（実装時に必ず実行し、結果を PR に残す）

| # | 変異 | 期待して赤くなるテスト |
|---|---|---|
| M1 | `RenderPipeline::clipSpecFor()` の `isMissing()` 呼び出しを元の `$take === null \|\| $take->status !== TakeStatus::Ready` に戻す | 検出 B が 2 ファイルになり **ケース 4** が fail |
| M2 | 新ファイル `app/Services/Manual/Dummy.php` に `$cut->adoptedTake` を 1 行書く（目録には足さない） | **ケース 1** が fail |
| M3 | 目録から `Models/Cut.php` を残したまま `Cut.php` の relation 名を変える | **ケース 2** が fail |
| M4 | 走査ルート を存在しないディレクトリへ差し替える | **ケース 3・5** が fail（空振り検出） |
| M5 | `AdoptedReadyTakeCoverage::isMissing()` の条件から `status !== Ready` を落とす | `PreviewCoverageParityTest` の「採用済みだが ready でないテイクも数える」が fail |
| M6 | `triggerPreview()` に render と同じ 422 を足す | `PreviewCoverageParityTest` の「preview は 201」が fail |
| M7 | `finalize` を manifest 由来ではなく現在状態からの再計算に変える | `RenderPlaceholderCountTest` の「生成後に採用しても件数が変わらない」が fail |
| M8 | `VideoManualController::show` から `coverage` を落とす | Feature の props テストと `RenderPanel.test.ts` が fail |
| M9 | 注記を `playbackJob` ではなく最新 `preview` job の値から出すように戻す | `RenderPanel.test.ts` D-6 が fail |

---

## 施策 5: ドキュメント同期

- `docs/architecture.md` §レンダジョブの運用契約 に小節を追加:
  「**採用テイク充足判定の単一化と告知契約**」— 述語の所在、render=422 / preview=告知の非対称の
  理由、`placeholder_cut_count` の値契約表、**保証しないもの**。
- `AGENTS.md` ドメイン固有規約に 1 項追加（既存規約 1 の隣）:
  「採用済み ready 判定は `AdoptedReadyTakeCoverage` のみ。新しい参照は
  `AdoptedTakeReferenceInventory` へ区分 + 根拠付きで登録（deny-by-default）」。
  **番号は末尾に追加**し既存番号を renumber しない（相互参照を壊さないため）。

---

## テスト計画（全体）

> **テストファースト**（思考原則 5）: F-1-01 の再現テスト（下記 A-2 / A-3）を先に書き、
> **赤を確認してから**実装に入る。

### A. `tests/Feature/Manual/PreviewCoverageParityTest.php`（新規）

fixture は Factory のみ（`Cut::factory()` / `Take::factory()`、既存 `renderTriggerContext()` と
同型のヘルパを本ファイル内に持つ。`RefreshDatabase` はグローバル適用のため個別宣言しない）。

| # | テストケース名 | 検証 |
|---|---|---|
| A-1 | `render は未採用カットがあると 422 で未採用カットを列挙する` | 既存契約の回帰（土台） |
| A-2 | `preview は未採用カットがあっても 201 で受け付ける（ブロックしない）` | **F-1-01 の第三の道** |
| A-3 | `render 422 の列挙件数と詳細画面 coverage の missing_count が一致する` | **乖離しないことの核**（同一 fixture・同一時点） |
| A-4 | `詳細画面 props に total_cuts / missing_count / missing_labels が載る` | Inertia props（`assertInertia`） |
| A-5 | `すべて採用済みなら missing_count は 0 でラベルは空になる` | 正常系（注記を出さない条件） |
| A-6 | `採用済みだが status が ready でないテイクも missing として数える` | 基準の同一性（`whereDoesntHave('adoptedTake')` 系の別基準との差。uploading/processing/failed の 3 状態で検証） |
| A-7 | `missing が 11 件のとき missing_labels は 10 件で missing_count は 11 になる` | 打ち切りの契約（件数は打ち切らない） |
| A-8 | `撮影者 (project_member) には coverage 注記の対象 props が返るが操作は 403 のまま` | 既存の権限境界を壊していないこと |

### B. `tests/Feature/Manual/RenderPlaceholderCountTest.php`（新規）

| # | テストケース名 | 検証 |
|---|---|---|
| B-1 | `succeeded な preview に生成時のプレースホルダ件数が記録される` | **fixture を明示**: n カット中 k カットが「未採用 or 採用テイクが ready でない」manual に preview job を作り `RenderPipeline::run()` を直接実行（`Process::fake()` + `VideoComposer` の fake 実装 `app/Services/Render/Fakes`）。`render_jobs.placeholder_cut_count === k` |
| B-1b | `RenderManifest::placeholderCutCount() は clips から数える`（`tests/Unit/Render/RenderManifestTest.php` 新規） | 値の出所が clips ただ 1 つであること（DB も現在状態も見ない） |
| B-2 | `succeeded な render の placeholder_cut_count は 0 になる` | render は欠落し得ない |
| B-3 | `queued / running / failed の placeholder_cut_count は null のまま` | 値契約 |
| B-4 | `プレビュー生成後にテイクを採用しても記録済み件数は変わらない` | **再計算禁止**の behavioral 固定 |
| B-5 | `ポーリング応答と詳細画面 props に placeholder_cut_count が載る` | DTO 波及 |

### C. `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest.php`（新規）

施策 4 の 7 ケース（負のコントロール・exact-fit を含む）。

### D. フロント（vitest）

- `tests/js/components/features/manual/RenderPanel.test.ts`（既存を更新。**削除・上書きはしない**）
  - D-1 `missing_count>0 でプレビュー近傍に注記を出す`
  - D-2 `missing_count>0 でもプレビュー生成ボタンは disabled にならない`（禁止事項 8）
  - D-3 `missing_count が 0 なら注記を出さない`
  - D-4 `playbackJob.placeholder_cut_count>0 なら動画の上に注記を出す`
  - D-5 `placeholder_cut_count が null なら注記を出さない（0 と同一視しない）`
  - D-6 `注記と動画 URL は同一の playbackJob から出る`（最新 preview が別世代でも
    再生中の動画の値だけを使う。props 置換で構造的に保証されることの回帰）
  - D-7 `missing_labels が打ち切られているとき「ほか N 件」を出す`
  - D-8 `preview 成功のポーリング応答で playbackJob が更新され注記も追随する`
- `tests/js/pages/ManualsShow.test.ts`（既存を更新）
  - D-9 `render.coverage と render.playbackJob が RenderPanel へ渡る`

### E. Browser lane（**Chromium + WebKit の 2 レーン契約**。`tests/Browser/PreviewCoverageNoticeTest.php` 新規）

UI を変えるため必須（`docs/testing-browser.md`。実行時間を理由に WebKit を落とさない）。

- E-1 `採用テイクが揃っていないマニュアルの詳細画面で、プレビュー生成前に注記が見える`
- E-2 `注記が出ていてもプレビュー生成ボタンは押下可能である`
  — **クリックしない**（Round 1 [Warning] 対応）。`disabled` 属性・`aria-disabled` の**不在**と
  可視であることのみを assert する。Browser lane に ffmpeg / storage は無く、
  クリックすると環境次第で `RunManualRender` の実行経路へ進みうるため。

> Browser lane では実 ffmpeg を回さない（プレビューの完了までは追わない）。
> E は**押す前の告知と押下可能性**のみを対象とし、生成物の説明 (D-4) は vitest 側で固定する。

### F. 既存テストへの追随（削除・上書きは行わない）

- `tests/Feature/Manual/RenderPollingAndArtifactAccessTest.php`: 応答 shape に
  `placeholder_cut_count` を追加
- `tests/Feature/Manual/RenderPipelineTest.php`: `RenderResult` 生成箇所があれば引数追加
- `tests/Unit/Render/FfmpegVideoComposer*Test.php`: `RenderManifest` の**コンストラクタは変えない**
  ため影響なし（`placeholderCutCount()` はメソッド）

### 検証コマンド（全 green でコミット）

AGENTS.md の `VERIFICATION_COMMANDS` 全量 + Browser lane:

`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
`pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
`pnpm build:packages` / `pnpm test:packages` / **`composer test:browser`**（UI 変更のため必須）

---

## 保証しないもの（誇張しない）

1. **事前告知は描画時点のスナップショット**である。別タブ・別ユーザー・別デバイスの撮影で
   古くなる。押下を止めないため詰みは作らないが「常に最新」ではない。
2. **自然言語メッセージの文意は機械照合しない**。テストが固定するのは
   `data-testid` の存在・件数・`disabled` 属性の不在までであり、文面の妥当性は人のレビュー責任。
3. **gate は静的走査**であり、`adoptedTake` を文字列変数経由で組み立てる参照
   （`$rel = 'adopted'.'Take'`）、動的プロパティアクセス、`Take` を別経路で引いて
   status を判定するコード（`Take::query()->where(...)`）には**沈黙する**。
   検出 B も「同一ファイル内に `TakeStatus::Ready` が出現するか」という近似であり、
   別ファイルへ切り出して同じ判定を書く経路は検出できない。
4. **`placeholder_cut_count` が語るのは「プレースホルダに落ちたクリップ数」だけ**である。
   その動画が実用に足るか（品質）は何も語らない。既存行は `null` のままで backfill しない。
5. **プレースホルダ映像自体は変えない**（黒背景 + 字幕は意図的な仕様）。
   「未撮影」テロップの焼き込みは行わない。
6. **ダッシュボード / 撮影ナビの撮影待ちカウントとの差は残る**
   （`whereDoesntHave('adoptedTake')` は「採用済みだが ready でないテイク」を撮影済みとして数える）。
   本設計は統合せず `DifferentCriterion` として記録するだけである。
7. Browser lane は**告知の可視性と押下可能性**のみを見る。実 ffmpeg 合成・黒画面の
   目視確認は staging worker での運用確認（`docs/architecture.md` の既存運用項目）に委ねる。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | migration（`render_jobs` 列追加）+ 共有 DTO（`RenderJobData` / `RenderResult`）+ Architecture gate 追加を含み、レンダ系ファイルの広い範囲に触れる。並走中の他 2 設計（同 run の別 finding）と同時にマージすると衝突・レビュー困難になる |
| 競合リスク | 同 bug-hunt run の他設計は 2FA middleware / dashboard props 系で**ファイル重複は無い見込み**。ただし `resources/js/types/manual.ts` と `RenderPanel.svelte` に触る設計が他に無いことをマージ前に確認する |
