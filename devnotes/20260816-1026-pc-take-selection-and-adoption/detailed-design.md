# 詳細設計: pc-take-selection-and-adoption (PC 側のテイク選択・採用画面)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(本タスクでは LLM 呼び出しを一切追加しない)
6. prompt 文字列のコード直書き(本タスク該当なし)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(成果物はリポジトリ内のファイルとして出力する)

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`。解析対象は app / config / database / routes）
- **Pest** テストフレームワーク（`composer test`）
- **RefreshDatabase** + `--parallel` 並列実行（`tests/Pest.php` でグローバル適用、個別 `DatabaseTransactions` 使用禁止）
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- 新モデルは追加しない（Factory 追加も無い）
- **DTO + JsonResource** パターン（本タスクの新規 props は専用 DTO、書き込み応答は既存 Resource）
- **アーリーリターン** 推奨
- **コードフォーマット**: `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- `declare(strict_types=1)` + 日本語コメント。Controller は薄く（Service / DTO 委譲）

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex conceptual-review Round 4 で **APPROVED**）

### 概念設計からの差分（現行コードを読んで判明した修正）

| # | 概念設計 | 詳細設計 | 理由 |
|---|---|---|---|
| 1 | props に `outline`（cut の id/type 列）を載せ、frontend の `buildCutLabels` でラベルを導出する | **`outline` を廃止**し、`cut.label` をサーバで確定して載せる | `Services/Manual/CutSequencer::orderedWithLabels()` が「手順N / 急所N-M」の**サーバ側の既存導出元**として実在した（レンダの欠落ラベルとマニフェストが共用）。これを使えば導出元を増やさずに済む。`buildCutLabels` の signature 変更も不要になり、`lib/capture/cut-labels.ts` は無変更で済む |
| 2 | props のキー名は `cut.adopted_take_id` | **`cut.adopted` = `{ id, status } \| null`** | `ScenarioWritePathInventoryTest` 検出 4a/4b は、app/ 配下で識別子 `adopted_take_id` と配列キー `'adopted_take_id' => …` を deny-by-default で検出する（allowlist は 3 ファイルのみ、書き込み形と読み取りをトークンでは区別できないため）。表示のためだけに security gate の allowlist を広げるのは筋が悪い。読み取りは `$cut->adoptedTake` relation 経由にし、キー名から `_id` を外すことで**gate を一切緩めずに**同じ情報を出せる |

## 施策一覧

**4 施策すべてが完了条件**（順序は実装順）。

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | テイク選択・採用画面の新設 | `routes/web.php` / `app/Http/Controllers/Projects/CutTakeController.php`(新) / `app/DataTransferObjects/Manual/{TakeSelectionPageData,SelectableTakeData}.php`(新) / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/pages/Manuals/Takes.svelte`(新) / `resources/js/components/features/manual/{TakePickerList,TakePreviewPanel,TakeThumbnail}.svelte`(新) / `resources/js/lib/capture/take-endpoints.ts`(新) / `resources/js/components/features/capture/TakeStrip.svelte` / `resources/js/types/manual.ts` / `tests/Support/Routing/NestedRouteDefenseInventory.php` | P1 |
| 2 | 字幕 overlay の molecules 昇格と表示 ON/OFF | `resources/js/components/molecules/SubtitleOverlay.svelte`(移設先) / `resources/js/components/features/capture/SubtitleOverlay.svelte`(削除) / `resources/js/components/features/capture/CameraRecorder.svelte` / `tests/js/components/features/capture/SubtitleOverlay.test.ts`(移設) | P1 |
| 3 | シナリオ編集画面の「動画」列 | `app/Http/Controllers/Projects/VideoManualController.php` / `app/Support/Security/AdoptedTakeReferenceInventory.php` / `resources/js/components/features/manual/ScenarioEditor.svelte` / `resources/js/pages/Manuals/Edit.svelte` / `resources/js/types/manual.ts` | P1 |
| 4 | PC ローカル動画のアップロード | `resources/js/lib/capture/upload-queue.ts` / `resources/js/components/features/manual/TakeFileUpload.svelte`(新) / `resources/js/pages/Manuals/Takes.svelte` | P1 |

---

## 施策 1: テイク選択・採用画面の新設

### 変更箇所

- `routes/web.php` (L521-565 の業務 route `Route::scopeBindings()` group 内に 1 本追加)
- `app/Http/Controllers/Projects/CutTakeController.php` (新規)
- `app/DataTransferObjects/Manual/TakeSelectionPageData.php` (新規)
- `app/DataTransferObjects/Manual/SelectableTakeData.php` (新規)
- `app/Support/Security/AdoptedTakeReferenceInventory.php` (entry 追加)
- `tests/Support/Routing/NestedRouteDefenseInventory.php` (entry 追加)
- `resources/js/pages/Manuals/Takes.svelte` (新規)
- `resources/js/components/features/manual/TakePickerList.svelte` (新規)
- `resources/js/components/features/manual/TakePreviewPanel.svelte` (新規)
- `resources/js/components/features/manual/TakeThumbnail.svelte` (新規)
- `resources/js/lib/capture/take-endpoints.ts` (新規。URL 導出の単一化)
- `resources/js/components/features/capture/TakeStrip.svelte` (L86-88 の `takeUrl` を上記へ寄せる)
- `resources/js/types/manual.ts` (型追加)

### 権限境界（意図的な非対称。誤読しないこと）

| 対象 | 認可 | 撮影者 (project_member) | 編集者 (project_admin / org owner・admin) |
|---|---|---|---|
| **画面 route** `projects.manuals.cuts.takes.index` | `Gate::authorize('update', $manual)` = `VideoManualPolicy::update` → `ProjectPolicy::update` | **403** | 200 |
| **操作 API** `capture.takes.{upload-url,store,adopt,destroy,playback}` | `TakePolicy` → `ProjectPolicy::capture` | **可（従来どおり）** | 可 |

**PC 画面が編集者限定であることと、テイク操作 API が撮影者にも開いていることは別**である。
撮影者は PWA から従来どおり採用・削除できる（doc/10 §10.5 の確定仕様）。
この非対称は**テストで固定する**（テスト計画に「撮影者が `capture.takes.adopt` を叩ける」を含める。
この行が消えたら非対称が事故で壊れたと分かる）。
PC の操作まで編集者限定にしたい場合は API を分ける必要があるが、それは別タスクの議題である。

### 再利用する frontend helper（新設しない）

`resources/js/lib/capture/http.ts` に実装済みのものをそのまま使う（新しい fetch ラッパを作らない）:

| export | 挙動 |
|---|---|
| `captureFetch(url, init)` | `credentials: "same-origin"` / `Accept: application/json` / `X-Requested-With` / `X-XSRF-TOKEN` を常時付与。**419 は `/app/csrf-cookie` を取り直して 1 回だけ再送** |
| `captureJson(url, method, body?)` | 上記に `Content-Type: application/json` + JSON body |
| `extractErrorMessage(response)` | 422/409 等の body から `message` → `errors` の先頭を取り出す（無ければ既定文言） |

409 (`scenario_conflict` = rendering/analyzing) / 422 (not ready / DL 済み削除 / quota) は
**サーバ供給の文言をそのまま表示**する（UI 側で理由を再実装しない）。

### 波及変更

- **TypeScript 型定義**: `resources/js/types/manual.ts` に `SelectableTakeStatus` / `SelectableTake` /
  `TakeSelectionCut` / `TakeSelectionPageProps` を追加。**`types/capture.ts` は変更しない**
  （PC の shape は署名 URL の口を持たない別物。概念設計 D2）。
- **API Resource/DTO**: 新規 `TakeSelectionPageData` / `SelectableTakeData`。
  **既存 `CaptureCutData` / `CaptureTakeData` / `CaptureTakeResource` は無変更**
  （PC からの書き込み応答はこれらをそのまま受け取る）。
- **テストファイル**: `tests/Feature/Manual/TakeSelectionPageTest.php`(新) /
  `tests/Feature/Manual/PcTakeOperationTest.php`(新) /
  `tests/js/pages/ManualsTakes.test.ts`(新) / `tests/js/lib/capture/take-endpoints.test.ts`(新) /
  `tests/js/components/features/capture/TakeStrip.test.ts`(既存があれば URL 回帰を追加)。
- **目録**: `NestedRouteDefenseInventory`（未登録なら `NestedRouteIdorDefenseTest` が fail） /
  `AdoptedTakeReferenceInventory`（未登録なら `AdoptedReadyTakeCriterionInventoryTest` が fail）。
- **bug-hunt 目録**: `.claude/skills/app-bug-hunt/inventory/annotations.toml` に
  `projects.manuals.cuts.takes.index` の注釈を 1 行足して再生成。
- **ドキュメント**: `doc/10` / `docs/architecture.md` §撮影 PWA の運用契約 /
  `routes/web.php` の撮影 PWA group コメント。

### 現行コード

`routes/web.php` L521-565（業務 route の scopeBindings group。末尾は複製 route）:

```php
Route::scopeBindings()->group(function (): void {
    Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
        ->name('projects.manuals.show');
    // …（中略: edit / update / scenario.update / source-documents / analyze / jobs / render …）
    Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
        ->name('projects.manuals.duplicate');
});
```

`resources/js/components/features/capture/TakeStrip.svelte` L86-88（URL 組み立ての現在の唯一の場所）:

```svelte
function takeUrl(take: CaptureTake, suffix = ""): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cut.id}/takes/${take.id}${suffix}`;
}
```

### 変更後コード

**(1) `routes/web.php`**（上記 group の末尾に追加）

```php
    // テイク選択・採用画面 (doc/04 「テイクのプレビュー / 選択画面」)。編集者のみ (撮影者は 403)。
    // **この GET は画面 props を返すだけ**で、採用・削除・アップロード・再生は
    // capture.takes.* を再利用する (テイク資源の API 面を 2 本にしない)。
    // {cut} は $manual->cuts() 経由 (scopeBindings) = cross-manual/cross-project は認可より前に 404。
    Route::get('/projects/{project}/manuals/{manual}/cuts/{cut}/takes', [CutTakeController::class, 'index'])
        ->name('projects.manuals.cuts.takes.index');
```

**(2) `app/Http/Controllers/Projects/CutTakeController.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\DataTransferObjects\Manual\TakeSelectionPageData;
use App\Http\Concerns\ResolvesCurrentOrganization;
use App\Http\Controllers\Controller;
use App\Models\Cut;
use App\Models\Project;
use App\Models\VideoManual;
use App\Support\Seo\SeoManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * テイク選択・採用画面 (doc/04)。編集者がカットごとのテイクを見て採用を確定する面。
 *
 * nested route の URL 整合は 2 層 (認可より前に 404):
 * 1. {project} ∈ current org (project.in-route-org middleware + resolveOrganizationProject)
 * 2. {manual} ∈ {project}, {cut} ∈ {manual} (Route::scopeBindings())
 *
 * 本 controller は**読み取りのみ**である。採用・削除・アップロード・再生は
 * capture.takes.* (撮影 PWA と共用の API 面) が担い、cuts の採用テイク外部キーを書くのは
 * 従来どおり Capture/CaptureTakeService::adopt() だけである
 * (AGENTS.md ドメイン固有規約 1 / ScenarioWritePathInventoryTest 検出 4)。
 */
class CutTakeController extends Controller
{
    use ResolvesCurrentOrganization;

    /** テイク選択画面 (編集者のみ。撮影者は 403 = PWA 側に採用導線がある) */
    public function index(
        Request $request,
        Project $project,
        VideoManual $manual,
        Cut $cut,
        SeoManager $seo,
    ): Response {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual}∈{project}, {cut}∈{manual} は scopeBindings)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('update', $manual); // VideoManualPolicy::update = 編集者

        $page = TakeSelectionPageData::fromCut($project, $manual, $cut);
        // 並行編集タブを判別できる動的固有名 (noindex 維持。既存 edit/show と同方針)
        $seo->setPrivateTitle($manual->title.' / '.$page->label.' のテイク選択');

        return Inertia::render('Manuals/Takes', $page->toArray());
    }
}
```

**(3) `app/DataTransferObjects/Manual/SelectableTakeData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Take;

/**
 * PC テイク選択画面が受け取るテイク 1 件の shape。
 * TS 側 types/manual.ts の SelectableTake と対で保守する。
 *
 * **署名 URL / video_path / thumbnail_path のスロットを構造として持たない**。
 * 撮影 PWA 用の CaptureTakeData は採用テイクへ署名 URL を載せる口を持つため、
 * 似ていても合流させない (概念設計 D2。「今は null だから安全」を作らない)。
 * 再生は capture.takes.playback (302 + no-store) 経由のみである。
 */
final readonly class SelectableTakeData
{
    public function __construct(
        public Take $take,
    ) {}

    public static function fromTake(Take $take): self
    {
        return new self($take);
    }

    /**
     * @return array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *   comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->take->id,
            'status' => $this->take->status->value,
            'size_bytes' => $this->take->size_bytes,
            'duration_ms' => $this->take->duration_ms,
            'comment' => $this->take->comment,
            'captured_at' => $this->take->captured_at?->toIso8601String(),
            'sort_order' => $this->take->sort_order,
            // DL 済みテイクは削除できない (422)。理由を押下前に説明するために出す
            'downloaded' => $this->take->downloaded_at !== null,
        ];
    }
}
```

**(4) `app/DataTransferObjects/Manual/TakeSelectionPageData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use App\Models\Project;
use App\Models\Take;
use App\Models\VideoManual;
use App\Services\Manual\CutSequencer;

/**
 * PC テイク選択画面 (Manuals/Takes) の Inertia props 全体。
 * TS 側 types/manual.ts の TakeSelectionPageProps と対で保守する。
 *
 * 表示ラベル (手順N / 急所N-M) は CutSequencer::orderedWithLabels() から取る
 * (レンダの欠落ラベル・マニフェストと同じ導出元。ラベル規則を増やさない)。
 * 採用テイクは `adopted` キーで出す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 */
final readonly class TakeSelectionPageData
{
    /** @param list<SelectableTakeData> $takes */
    public function __construct(
        public Project $project,
        public VideoManual $manual,
        public Cut $cut,
        public string $label,
        public array $takes,
    ) {}

    public static function fromCut(Project $project, VideoManual $manual, Cut $cut): self
    {
        // route binding 済みの $cut は relation 未ロードなので明示的に読む。
        // (本リポジトリは Model::preventLazyLoading() を有効化していないので落ちはしないが、
        //  暗黙の追加クエリを残さない)
        $cut->loadMissing('adoptedTake');

        // 見つからないのは「親を持たない急所」= データ異常のときだけ。
        // 画面タイトルを空にせず中立語へ倒す (静かに空にして異常を隠さない)
        $label = 'カット';
        foreach (CutSequencer::orderedWithLabels($manual) as $ordered) {
            if ($ordered->cut->id === $cut->id) {
                $label = $ordered->label;
                break;
            }
        }

        /** @var list<SelectableTakeData> $takes */
        $takes = $cut->takes()
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Take $take): SelectableTakeData => SelectableTakeData::fromTake($take))
            ->values()
            ->all();

        return new self($project, $manual, $cut, $label, $takes);
    }

    /**
     * @return array{project: array{id: int, name: string},
     *   manual: array{id: int, title: string, status: string},
     *   cut: array{id: int, type: string, label: string, scene: string, narration: string,
     *     subtitle_primary: string|null, subtitle_secondary: string,
     *     adopted: array{id: int, status: string}|null},
     *   takes: list<array{id: int, status: string, size_bytes: int, duration_ms: int|null,
     *     comment: string|null, captured_at: string|null, sort_order: int, downloaded: bool}>}
     */
    public function toArray(): array
    {
        $adopted = $this->cut->adoptedTake;

        return [
            'project' => ['id' => $this->project->id, 'name' => $this->project->name],
            'manual' => [
                'id' => $this->manual->id,
                'title' => $this->manual->title,
                // rendering / analyzing 中は採用が 409 になることの事前告知に使う
                'status' => $this->manual->status->value,
            ],
            'cut' => [
                'id' => $this->cut->id,
                'type' => $this->cut->type->value,
                'label' => $this->label,
                'scene' => $this->cut->scene,
                'narration' => $this->cut->narration,
                'subtitle_primary' => $this->cut->subtitle_primary,
                'subtitle_secondary' => $this->cut->subtitle_secondary,
                'adopted' => $adopted === null
                    ? null
                    : ['id' => $adopted->id, 'status' => $adopted->status->value],
            ],
            'takes' => array_map(
                static fn (SelectableTakeData $take): array => $take->toArray(),
                $this->takes,
            ),
        ];
    }
}
```

> **コメントでの語彙**: `app/` 配下の新規コメントでは採用テイク外部キーの識別子を直接書かない。
> `ScenarioWritePathInventoryTest` の走査は `token_get_all()` ベースで `T_STRING` /
> `T_CONSTANT_ENCAPSED_STRING` しか見ない（コメントは `T_COMMENT` / `T_DOC_COMMENT` なので
> 現状は検出されない）が、**コメントが走査対象にならないことに賭ける理由が無い**ため
> 「採用テイク外部キー」と言い換える。

**(5) `app/Support/Security/AdoptedTakeReferenceInventory.php`**（entry 追加）

```php
            'DataTransferObjects/Manual/TakeSelectionPageData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'PC テイク選択画面が「今どれを採用しているか」を青枠で示すために'
                    .'採用テイクの id と status を読むだけで、ready 判定も充足判定もしない。'
                    .'レンダの充足判定 (AdoptedReadyTakeCoverage) とは意図的に統合しない。',
            ],
```

**(6) `tests/Support/Routing/NestedRouteDefenseInventory.php`**（業務 route ブロックへ追加）

```php
            // {cut} は $manual->cuts() 経由 (PC テイク選択画面)
            'projects.manuals.cuts.takes.index' => [...$project, 'manual' => $scoped, 'cut' => $scoped],
```

**(7) `resources/js/lib/capture/take-endpoints.ts`**（新規。URL 導出の唯一の場所）

```ts
/**
 * テイク API (capture.takes.*) の URL 導出。**規則をここ 1 箇所に置く**。
 *
 * この API 面は撮影 PWA (Capture/Show の TakeStrip) と PC 編集面
 * (Manuals/Takes) の**両方が叩く**。URL prefix が /app なのは歴史的経緯であり、
 * テイク資源の唯一の API 面である (doc/10 / docs/architecture.md §撮影 PWA の運用契約)。
 */
export interface TakeEndpointTarget {
    projectId: number;
    manualId: number;
    cutId: number;
}

/** カット配下のテイクコレクション URL (POST = 登録) */
export function cutTakesUrl({ projectId, manualId, cutId }: TakeEndpointTarget): string {
    return `/app/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`;
}

/** テイク単体の URL (suffix で /adopt /playback 等を足す) */
export function takeUrl(target: TakeEndpointTarget, takeId: number, suffix = ""): string {
    return `${cutTakesUrl(target)}/${takeId}${suffix}`;
}

/** presigned upload-url 発行 URL */
export function takeUploadUrlEndpoint(target: TakeEndpointTarget): string {
    return `${cutTakesUrl(target)}/upload-url`;
}
```

**(8) `resources/js/components/features/capture/TakeStrip.svelte`**（既存 `takeUrl` を寄せる）

```svelte
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";
    // …
    function takeUrl(take: CaptureTake, suffix = ""): string {
        return buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, suffix);
    }
```

**(9) `resources/js/types/manual.ts`**（型追加）

```ts
/** PHP: App\Enums\Manual\TakeStatus と値集合を一致させる (literal union) */
export type SelectableTakeStatus = "uploading" | "processing" | "ready" | "failed";

/** テイクの状態ラベル (UI 共通)。satisfies でキー漏れをコンパイル時検出する */
export const TAKE_STATUS_LABELS = {
    uploading: "アップロード中",
    processing: "処理中",
    ready: "使用できます",
    failed: "失敗",
} as const satisfies Record<SelectableTakeStatus, string>;

/** 採用できる状態か (サーバ CaptureTakeService::adopt の ready 条件と一致させる) */
export const TAKE_ADOPTABLE_BY_STATUS = {
    uploading: false,
    processing: false,
    ready: true,
    failed: false,
} as const satisfies Record<SelectableTakeStatus, boolean>;

/** PHP: SelectableTakeData と対 */
export interface SelectableTake {
    id: number;
    status: SelectableTakeStatus;
    size_bytes: number;
    duration_ms: number | null;
    comment: string | null;
    captured_at: string | null;
    sort_order: number;
    downloaded: boolean;
}

/** PHP: TakeSelectionPageData の cut キーと対 */
export interface TakeSelectionCut {
    id: number;
    type: "step" | "point";
    label: string;
    scene: string;
    narration: string;
    subtitle_primary: string | null;
    subtitle_secondary: string;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
```

**(10) `resources/js/pages/Manuals/Takes.svelte`**（新規。配線のみ。判断は子コンポーネント）

```svelte
<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, Film } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import TakePickerList from "@/components/features/manual/TakePickerList.svelte";
    import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
    import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { SelectableTake, TakeSelectionCut, VideoManualStatus } from "@/types/manual";

    /**
     * テイク選択・採用画面 (doc/04)。左 = テイク一覧、中央 = プレビュー + 採用。
     * 採用・削除・アップロードは capture.takes.* (PWA と共用の API 面) を叩き、
     * 成功したら partial reload で cut と takes を取り直す。
     */
    interface Props {
        project: { id: number; name: string };
        manual: { id: number; title: string; status: VideoManualStatus };
        cut: TakeSelectionCut;
        takes: SelectableTake[];
    }

    let { project, manual, cut, takes }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 選択中テイク: 既定は採用テイク、無ければ先頭 (id で持ち、reload 後も追随させる)
    let selectedTakeId = $state<number | null>(null);
    const selectedTake = $derived(
        takes.find((take) => take.id === selectedTakeId) ??
            takes.find((take) => take.id === cut.adopted?.id) ??
            takes[0] ??
            null,
    );

    /** 採用・削除・アップロード成功後の再取得 (cut と takes は別のトップレベル props) */
    function refresh(): void {
        router.reload({ only: ["cut", "takes"] });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection
            title={`${cut.label} のテイク選択`}
            description={cut.scene}
            icon={Film}
            testId="take-selection-heading"
        >
            <TextLink href={`/projects/${project.id}/manuals/${manual.id}/edit`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                シナリオ編集へ戻る
            </TextLink>
        </PageHeaderSection>
        <PageContent>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <TakePickerList
                    {takes}
                    adoptedTakeId={cut.adopted?.id ?? null}
                    selectedTakeId={selectedTake?.id ?? null}
                    onSelect={(id) => (selectedTakeId = id)}
                    projectId={project.id}
                    manualId={manual.id}
                    cutId={cut.id}
                    onChanged={refresh}
                />
                <div class="flex min-w-0 flex-col gap-4">
                    <TakePreviewPanel
                        take={selectedTake}
                        {cut}
                        manualStatus={manual.status}
                        projectId={project.id}
                        manualId={manual.id}
                        onChanged={refresh}
                    />
                    <TakeFileUpload
                        projectId={project.id}
                        manualId={manual.id}
                        cutId={cut.id}
                        onUploaded={refresh}
                    />
                </div>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
```

**(11) `TakePreviewPanel.svelte`**（中央プレビュー + 採用。要点のみ）

```svelte
    import { captureJson, extractErrorMessage } from "@/lib/capture/http";
    import { takeUrl as buildTakeUrl } from "@/lib/capture/take-endpoints";

    // 再生は 302 経由 (署名 URL を props に載せない)。ready 以外はサーバが 404 を返すため
    // src を張らず、<video> 自体を描かない (無駄な要素とネットワーク要求を出さない)
    const playbackUrl = $derived(
        take !== null && take.status === "ready"
            ? buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/playback")
            : null,
    );

    // 押下は常に受ける (disabled にしない。AGENTS.md 禁止事項 8)
    async function adopt(): Promise<void> {
        error = null;
        if (take === null) { error = "テイクを選択してください。"; return; }
        if (!TAKE_ADOPTABLE_BY_STATUS[take.status]) {
            error = `${TAKE_STATUS_LABELS[take.status]}のテイクは採用できません。`;
            return;
        }
        busy = true;
        try {
            const res = await captureJson(
                buildTakeUrl({ projectId, manualId, cutId: cut.id }, take.id, "/adopt"), "POST");
            if (!res.ok) { error = await extractErrorMessage(res); return; } // 409/422 はサーバ文言
            onChanged();
        } catch {
            error = "通信に失敗しました。ネットワークを確認してください。";
        } finally {
            busy = false;
        }
    }
```

`playbackUrl === null` のときは `<video>` を描かず、`TakeThumbnail` の状態タイルと
「このテイクはまだ再生できません（{状態ラベル}）」を出す。

- 採用テイクの視覚的区別（要件の「青枠」）は `TakePickerList` の各タイルに
  `ring-2 ring-primary`（DS token 経由）を当てる。hex 直書きはしない。
- 削除は `ConfirmDialog`（organisms）で「この操作は取り消せません。動画は完全に削除されます。」を出し、
  確定時のみ `DELETE`。DL 済み (422) は押下後にサーバ文言を表示する。

**(12) `TakeThumbnail.svelte`**（サムネイル未生成時のフォールバック）

```svelte
    /**
     * テイクのタイル。**サムネイル生成は別タスク**のため、現在は状態タイルを描く。
     * サムネイルが入る時点で `thumbnailUrl` prop を足し、この中身だけを差し替える
     * (差し替え点をこの 1 コンポーネントに閉じる)。
     */
    interface Props {
        index: number;
        status: SelectableTakeStatus;
        durationMs: number | null;
        adopted: boolean;
    }
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`index(): Response` / `toArray(): array{...}` / `fromCut(): self`）
- [x] null 安全（`$cut->adoptedTake` は null 合体で分岐、`captured_at?->toIso8601String()`。
      `label` は `'カット'` で初期化済み＝未初期化アクセスも空文字も出ない）
- [x] DTO を返している（Inertia props は `TakeSelectionPageData::toArray()` のみ。生配列の直組みなし）
- [x] Generics の型パラメータが正しい（`@param list<SelectableTakeData> $takes` /
      `->map(...)->values()->all()` で `list<>` を確定）
- [x] `response()->json()` を使わない（Inertia render のみ）
- [x] `declare(strict_types=1)` + 日本語コメント

### テスト計画

- [ ] 新規 `tests/Feature/Manual/TakeSelectionPageTest.php`
  - 編集者 (project_admin / org admin) は 200 で `Manuals/Takes` が描画され、props の
    `cut.label` が「手順1」形式で入る
  - **撮影者 (project_member) は 403**（PWA 側に採用導線がある＝詰まないことをコメントで明示）
  - cross-org / cross-project / cross-manual / cross-cut は**すべて 404**（403 ではない）
  - **props に `playback_url` / `video_path` / `thumbnail_path` / `download_ack_token` の
    いずれのキーも現れない**（shape 契約の機械化）
  - takes は `sort_order` 昇順、`downloaded` が DL 済みで true
  - **step の cut は `手順1`、point の cut は `急所1-1` のラベルになる**（ラベル導出の固定）
  - `require-active-subscription` 未充足の組織は onboarding へ遮断される
- [ ] 新規 `tests/Feature/Manual/PcTakeOperationTest.php`
  - 編集者が `capture.takes.adopt` / `destroy` / `upload-url` / `store` / `playback` を実行できる
    （PC 導線でも認可が通ることの固定＝概念設計 D2 の読み替えの機械化）
  - **撮影者 (project_member) も `capture.takes.adopt` を実行できる**
    （画面は 403 だが API は開いている、という意図的な非対称の固定。
    この test が消えたら非対称が事故で壊れたと分かる）
  - `rendering` / `analyzing` 中の adopt は 409、`ready` でないテイクの adopt は 422、
    DL 済みテイクの削除は 422
- [ ] 既存 `tests/Architecture/NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` が
      inventory 追加で green（登録漏れなら fail することを一度確認してから登録する＝テストファースト）
- [ ] 既存 `tests/Architecture/AdoptedReadyTakeCriterionInventoryTest` が entry 追加で green
- [ ] 既存 `tests/Architecture/ScenarioWritePathInventoryTest` が**無変更で** green
      （新しい書き込み経路を作っていないことの裏取り）
- [ ] 新規 `tests/js/lib/capture/take-endpoints.test.ts`（3 関数の URL 組み立て）
- [ ] 新規 `tests/js/pages/ManualsTakes.test.ts`
  - 採用テイクのタイルに青枠クラスが付く / 非採用には付かない
  - `processing` テイクの「採用する」押下でエラー文言が出る（**要素は disabled でない**）
  - 削除は確認ダイアログを経てから DELETE が飛ぶ（復元不可の文言を含む）
  - 採用成功後に `router.reload({ only: ["cut", "takes"] })` が呼ばれる
  - サムネイル未生成時に状態タイル（テイク番号 + 状態ラベル）が描画される
- [ ] 個別の `DatabaseTransactions` を使っていないことを確認

### リスク

- **URL 空間の読み替え**: `/app/*` が PWA 専用でなくなる。ドキュメント 3 箇所と
  Feature テストで固定するが、**将来 `/app` に PWA 固有 middleware（例: SW 前提の
  no-store 強化）を足すと PC 面にも掛かる**。足すときは本設計を読み直すこと。
- **撮影者が PC 画面で 403 を見る**: 撮影者に本画面の URL が共有されると 403 に着地する。
  詰みではない（PWA に採用導線がある）が、403 画面から撮影ナビへの導線は無い。
  今回は導線を足さない（過剰。403 は共有事故の場合にしか起きない）。
- **採用状態のスナップショット性**: 他タブ・PWA からの採用変更は再取得まで反映されない
  （既存 `coverage` props と同じ性質）。採用は最終的にサーバの行ロックで直列化される。

---

## 施策 2: 字幕 overlay の molecules 昇格と表示 ON/OFF

### 変更箇所

- `resources/js/components/features/capture/SubtitleOverlay.svelte` → **削除**
- `resources/js/components/molecules/SubtitleOverlay.svelte` → **新設（移設先）**
- `resources/js/components/features/capture/CameraRecorder.svelte` (L16 import / L508 使用箇所)
- `tests/js/components/features/capture/SubtitleOverlay.test.ts` →
  `tests/js/components/molecules/SubtitleOverlay.test.ts` へ移設
- `resources/js/components/features/manual/TakePreviewPanel.svelte`（新規利用側）

### 波及変更

- **TypeScript 型定義**: props 型を `CaptureCut["subtitle_primary"]` 参照から
  素の `string | null` / `string` に一般化（`types/capture.ts` への依存を外す。
  molecules が features 側の型に依存し続けるのは階層違反ではないが、
  共有部品としては不要な結合なので切る）。
- **API Resource/DTO**: なし
- **テストファイル**: 上記の移設（アサーションは変更しない＝振る舞いを変えていないことの確認）

### 現行コード

`resources/js/components/features/capture/SubtitleOverlay.svelte`（抜粋）:

```svelte
<script lang="ts">
    import type { CaptureCut } from "@/types/capture";
    interface Props {
        primary: CaptureCut["subtitle_primary"];
        secondary: CaptureCut["subtitle_secondary"];
        visible: boolean;
    }
    let { primary, secondary, visible }: Props = $props();
```

`CameraRecorder.svelte` L16:

```svelte
    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
```

### 変更後コード

`resources/js/components/molecules/SubtitleOverlay.svelte`（移設 + 型の一般化。描画部は不変）:

```svelte
<script lang="ts">
    /**
     * 映像へ重畳する字幕 overlay (焼込ではない DOM overlay)。
     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
     *
     * 利用者は 2 つ:
     * - 撮影中カメラプレビューの字幕ガイド (features/capture/CameraRecorder)
     * - PC テイク選択画面のプレビュー字幕表示 ON/OFF (features/manual/TakePreviewPanel)
     * features の domain 間横参照を作らないため molecules に置く (複製しない)。
     */
    interface Props {
        primary: string | null;
        secondary: string;
        visible: boolean;
    }
    let { primary, secondary, visible }: Props = $props();
    // 以下 (hasPrimary / hasSecondary / shown と markup) は移設前と完全に同一
```

`CameraRecorder.svelte`:

```svelte
    import SubtitleOverlay from "@/components/molecules/SubtitleOverlay.svelte";
```

`TakePreviewPanel.svelte`（表示 ON/OFF。**初期は両方オフ**）:

```svelte
    // doc/04 「プレビューにナレーション/字幕を ON/OFF (初期は両方オフ)」
    // v1 は TTS 非実装のため、ナレーションは**原稿テキストの表示**の切替である
    // (音声は再生しない。概念設計 D6)。ラベルにも「原稿」と書き、音が出ると誤解させない。
    let showSubtitles = $state(false);
    let showNarrationScript = $state(false);
```

```svelte
    {#if playbackUrl !== null}
        <div class="relative">
            <video src={playbackUrl} controls class="w-full rounded-md bg-text"
                   aria-label={`${cut.label} のテイク ${index + 1}`}></video>
            <SubtitleOverlay
                primary={cut.subtitle_primary}
                secondary={cut.subtitle_secondary}
                visible={showSubtitles}
            />
        </div>
    {:else}
        <!-- ready 以外 / 未選択: <video> を作らない (サーバは 404 を返すため要求も出さない) -->
        <TakeThumbnail ... />
        <p class="text-caption text-text-secondary" data-testid="take-not-playable">
            {take === null
                ? "左の一覧からテイクを選ぶと再生できます。"
                : `このテイクはまだ再生できません（${TAKE_STATUS_LABELS[take.status]}）。`}
        </p>
    {/if}
    <Checkbox bind:checked={showSubtitles} label="字幕を表示" testId="toggle-subtitles" />
    <Checkbox bind:checked={showNarrationScript} label="ナレーション原稿を表示"
              testId="toggle-narration-script" />
    {#if showNarrationScript}
        <p class="text-body text-text-secondary" data-testid="narration-script">{cut.narration}</p>
    {/if}
```

### PHPStan 適合チェック

- 本施策は frontend のみ（PHP 変更なし）。`pnpm typecheck` / `pnpm lint` / ds-purity /
  `atomic-import-graph` テストが検査対象。

### テスト計画

- [ ] 既存 `SubtitleOverlay.test.ts` を `tests/js/components/molecules/` へ移設し、
      **アサーションを変えずに** green（移設で振る舞いが変わっていないことの確認）
- [ ] 既存 `tests/js/architecture/atomic-import-graph.test.ts` が green
      （features/manual → molecules は順方向。features 間の横参照は発生しない）
- [ ] 新規 `ManualsTakes.test.ts` に:
  - 初期状態で字幕 overlay が**出ていない** / ナレーション原稿が**出ていない**
  - 「字幕を表示」を ON にすると `subtitle-primary` / `subtitle-secondary` が出る
  - 「ナレーション原稿を表示」を ON にすると `narration-script` が出る
  - **音声再生に関する要素・文言を出さない**（TTS 非実装の読み替えの固定）
- [ ] 既存 `CaptureShow.test.ts` / CameraRecorder 系テストが import 変更後も green

### リスク

- 移設で `CameraRecorder` の字幕ガイドが壊れると**撮影の主要導線**に影響する。
  描画部を 1 文字も変えず、既存テストをそのまま通すことで担保する。
- 型の一般化により `CaptureCut` の subtitle 型が将来変わっても overlay は追随しない。
  overlay 側は `string | null` / `string` を契約として持つ（現行と同じ）。

---

## 施策 3: シナリオ編集画面の「動画」列

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php`（`edit()` に props 追加 + private helper）
- `app/DataTransferObjects/Manual/CutTakeSummaryData.php`（新規）
- `app/Support/Security/AdoptedTakeReferenceInventory.php`（entry 追加）
- `resources/js/pages/Manuals/Edit.svelte`（props 受け取り → `ScenarioEditor` へ中継）
- `resources/js/components/features/manual/ScenarioEditor.svelte`（動画セルの追加）
- `resources/js/types/manual.ts`（`CutTakeSummary` 追加）

### 波及変更

- **TypeScript 型定義**: `CutTakeSummary` を追加し、`Manuals/Edit.svelte` の `Props` と
  `ScenarioEditor` の `Props` に `takeSummaries: CutTakeSummary[]` を足す。
- **API Resource/DTO**: `CutTakeSummaryData`（新規）。
  「本タスクの新規 props は専用 DTO」という自分の規約に例外を作らない
  （3 フィールドでも DTO のコストはほぼゼロで、shape の置き場が 1 つに定まる）。
- **テストファイル**: `tests/Feature/Manual/ScenarioVideoColumnTest.php`(新) /
  `tests/js/pages/ManualsEdit.test.ts`(既存に追加)。

### 現行コード

`app/Http/Controllers/Projects/VideoManualController.php` L192-217:

```php
    /** 編集フォーム (メタデータ = title / category + シナリオ document) */
    public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
    {
        // …（略）
        return Inertia::render('Manuals/Edit', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => [/* … */],
            'categories' => $this->categoryOptions($project),
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
        ]);
    }
```

`resources/js/components/features/manual/ScenarioEditor.svelte` L40-46 / L880-882:

```svelte
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
    }
    let { projectId, manualId, scenario }: Props = $props();
```

```svelte
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>
```

### 変更後コード

**(1) `VideoManualController::edit`**

```php
        return Inertia::render('Manuals/Edit', [
            'project' => ['id' => $project->id, 'name' => $project->name],
            'manual' => [/* 既存のまま */],
            'categories' => $this->categoryOptions($project),
            'scenario' => ScenarioDocumentData::fromManual($manual)->toArray(),
            // 動画列 (カットごとのテイク要約)。描画時点のスナップショットであり常に最新ではない
            // (採用は他タブ / 撮影 PWA からも起きる。判断はサーバの行ロックが直列化する)
            'takeSummaries' => $this->takeSummaries($manual),
        ]);
```

```php
    /**
     * 動画列用のカット別テイク要約。
     *
     * cut 件数に依存しない**定数本のクエリ**で取る (withCount は cuts の SELECT に畳まれ、
     * adoptedTake は eager load の 1 本。cut ごとの追加クエリ = N+1 を作らない)。
     * 並びは CutSequencer と同じ (sort_order, id) にする (同値 sort_order で揺れないため)。
     *
     * @return list<array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}>
     */
    private function takeSummaries(VideoManual $manual): array
    {
        return array_values($manual->cuts()
            ->withCount('takes')
            ->with('adoptedTake')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (Cut $cut): array => CutTakeSummaryData::fromCut($cut)->toArray())
            ->all());
    }
```

> `use App\DataTransferObjects\Manual\CutTakeSummaryData;` と `use App\Models\Cut;` を import に追加する。

**(2) `app/DataTransferObjects/Manual/CutTakeSummaryData.php`**（新規）

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Models\Cut;
use Webmozart\Assert\Assert;

/**
 * シナリオ編集画面「動画」列の 1 カット分。
 * TS 側 types/manual.ts の CutTakeSummary と対で保守する。
 *
 * 採用テイクは `adopted` キーで返す — 採用テイク外部キーの識別子は
 * ScenarioWritePathInventoryTest 検出 4 の deny-by-default 走査対象であり、
 * 表示のために security gate の allowlist を広げないための命名である。
 * 読み取りは adoptedTake relation 経由で行う。
 */
final readonly class CutTakeSummaryData
{
    public function __construct(
        public int $cutId,
        public int $takesCount,
        public ?int $adoptedTakeId,
        public ?string $adoptedTakeStatus,
    ) {}

    /** withCount('takes') + with('adoptedTake') 済みの cut から生成する */
    public static function fromCut(Cut $cut): self
    {
        $takesCount = $cut->getAttribute('takes_count');
        Assert::integer($takesCount, 'withCount(takes) 済みの cut を渡してください');
        $adopted = $cut->adoptedTake;

        return new self(
            cutId: $cut->id,
            takesCount: $takesCount,
            adoptedTakeId: $adopted?->id,
            adoptedTakeStatus: $adopted?->status->value,
        );
    }

    /**
     * @return array{cut_id: int, takes_count: int, adopted: array{id: int, status: string}|null}
     */
    public function toArray(): array
    {
        return [
            'cut_id' => $this->cutId,
            'takes_count' => $this->takesCount,
            // id と status は同時に決まる (両方 null か両方非 null)
            'adopted' => $this->adoptedTakeId === null || $this->adoptedTakeStatus === null
                ? null
                : ['id' => $this->adoptedTakeId, 'status' => $this->adoptedTakeStatus],
        ];
    }
}
```

**(3) `AdoptedTakeReferenceInventory`**（entry 追加。`adoptedTake` を触るのは DTO 側）

```php
            'DataTransferObjects/Manual/CutTakeSummaryData.php' => [
                'kind' => AdoptedTakeReferenceKind::DifferentCriterion,
                'rationale' => 'シナリオ編集画面の動画列が、カットごとに採用テイクの id と status を'
                    .'表示するために読むだけで ready 判定はしない。レンダの充足判定'
                    .'(AdoptedReadyTakeCoverage) とは基準が違うため意図的に統合しない。',
            ],
```

**(4) `types/manual.ts`**

```ts
/** PHP: CutTakeSummaryData と対 (動画列の 1 カット分) */
export interface CutTakeSummary {
    cut_id: number;
    takes_count: number;
    adopted: { id: number; status: SelectableTakeStatus } | null;
}
```

**(5) `ScenarioEditor.svelte`**（Props 追加 + 動画セル）

```svelte
    interface Props {
        projectId: number;
        manualId: number;
        scenario: ScenarioDocument;
        /** 動画列 (カットごとのテイク要約)。未保存行 (id=null) には対応する要約が無い */
        takeSummaries: CutTakeSummary[];
    }
    let { projectId, manualId, scenario, takeSummaries }: Props = $props();

    /** cut_id → 要約の索引 (行ごとの線形探索を避ける) */
    const summaryByCutId = $derived(
        new Map(takeSummaries.map((summary) => [summary.cut_id, summary])),
    );
```

```svelte
{#snippet videoCell(cutId: number | null)}
    <!-- 動画列 (doc/04)。未保存行はリンクを出さず、押せるのに詰むボタンを作らない。
         行 Card の中に角丸カードを入れ子にせず、区切り線で段を分ける -->
    <div class="mt-3 border-t border-border pt-3" data-testid="video-cell">
        <p class="text-caption text-text-secondary">動画</p>
        {#if cutId === null}
            <p class="mt-1 text-caption text-text-secondary" data-testid="video-cell-unsaved">
                「シナリオを更新」で保存すると、このカットに動画を登録できます。
            </p>
        {:else}
            {@const summary = summaryByCutId.get(cutId)}
            <p class="mt-1 text-caption" data-testid="video-cell-count">
                テイク {summary?.takes_count ?? 0} 件
                {#if summary?.adopted}
                    <Badge tone="primary">採用済み</Badge>
                {/if}
            </p>
            <Button
                variant="neutral"
                size="sm"
                href={`/projects/${projectId}/manuals/${manualId}/cuts/${cutId}/takes`}
                inertia
                testId="video-cell-link"
            >
                <Film class="size-4" aria-hidden="true" />
                {summary && summary.takes_count > 0 ? "テイクを選択" : "ファイルの選択"}
            </Button>
        {/if}
    </div>
{/snippet}
```

呼び出しは手順行・急所行の `rowFields` 直後に置く:

```svelte
                        <div class="mt-3">
                            {@render rowFields(step, `steps.${stepIndex}`, `step-${stepIndex}`)}
                        </div>
                        {@render videoCell(step.id)}
```

（急所行も `rowFields` 直後に `{@render videoCell(point.id)}` を置く）

**(6) `Manuals/Edit.svelte`**

```svelte
    interface Props {
        // …既存
        takeSummaries: CutTakeSummary[];
    }
    // …
    <ScenarioEditor {scenario} {takeSummaries} projectId={project.id} manualId={manual.id} />
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている（`takeSummaries(): array` + `@return list<array{...}>`、
      `CutTakeSummaryData::fromCut(): self` / `toArray(): array{...}`）
- [x] null 安全（`withCount` の結果は `getAttribute` + `Assert::integer`（既存
      `CaptureManualSummaryData` と同じ手法）、`adoptedTake` は `?->` と null 合体で分岐）
- [x] DTO を返している（props の shape は `CutTakeSummaryData` に集約。生配列の直組みなし）
- [x] Generics の型パラメータが正しい（`array_values(...->all())` で `list<>` を確定）

### テスト計画

- [ ] 新規 `tests/Feature/Manual/ScenarioVideoColumnTest.php`
  - `edit` の props に `takeSummaries` が含まれ、カット数分の行が `sort_order` 順で並ぶ
  - 採用テイクのあるカットは `adopted.id` / `adopted.status` が入り、無ければ `null`
  - **`takeSummaries` のキーに `adopted_take_id` が現れない**（gate 回避の命名の固定）
  - **cut を増やしてもクエリ本数が増えない**（`DB::listen` でカウントし、
    cut 3 件と 30 件で同数であることを assert。本数の完全一致では固定しない）
- [ ] 既存 `tests/js/pages/ManualsEdit.test.ts` に追加
  - 保存済み行には「テイクを選択」/「ファイルの選択」リンクが出る（href が新 route）
  - **未保存行（手順を追加した直後）にはリンクが出ず、保存を促す文言が出る**
  - 採用済みカットには「採用済み」バッジが出る
- [ ] 既存 `tests/Architecture/ScenarioWritePathInventoryTest` が green
      （`VideoManualController` に `adopted_take_id` を持ち込んでいないことの確認）
- [ ] 既存 `AdoptedReadyTakeCriterionInventoryTest` が entry 追加で green

### リスク

- **未保存編集からの遷移**: 動画セルのリンクを押すと `ScenarioEditor` の dirty 離脱確認
  （`router.on("before")`）が発火する。**これは正しい保護なので抑止しない**が、
  「編集途中にテイクを見たい」動線では毎回確認が出る。頻度が問題になった場合の対処は
  別タスク（例: セルからの遷移前に自動保存を提案する）とし、今回は作らない。
- **props 肥大**: cut 数 × 4 フィールド。100 カットでも数 KB で許容範囲。
- **スナップショット性**: 撮影 PWA 側での採用は編集画面をリロードするまで反映されない。

---

## 施策 4: PC ローカル動画のアップロード

### 変更箇所

- `resources/js/lib/capture/upload-queue.ts`（`createMemoryPendingStore()` を export 追加）
- `resources/js/components/features/manual/TakeFileUpload.svelte`（新規）
- `resources/js/pages/Manuals/Takes.svelte`（配線。施策 1 の (10) に含む）

### 波及変更

- **TypeScript 型定義**: なし（既存 `PendingStore` / `UploadOutcome` を使う）
- **API Resource/DTO**: なし（`capture.takes.upload-url` / `capture.takes.store` を再利用）
- **テストファイル**: `tests/js/lib/capture/upload-queue.test.ts`（既存に memory store のテストを追加） /
  `tests/js/pages/ManualsTakes.test.ts`（アップロード導線）

### 現行コード

`resources/js/lib/capture/upload-queue.ts`（抜粋）— `PendingStore` は注入で受ける:

```ts
export interface PendingStore {
    put(item: PendingUpload): Promise<void>;
    delete(clientTakeId: string): Promise<void>;
    list(): Promise<PendingUpload[]>;
}
// …
export class UploadQueue {
    constructor(options: UploadQueueOptions) { this.store = options.store; /* … */ }
    async enqueue(item: PendingUpload): Promise<UploadOutcome> { /* オンラインなら即時アップロード */ }
}
```

`resources/js/components/features/capture/CaptureFileFallback.svelte`（PC では描画されない）:

```svelte
    <input type="file" accept="video/*" capture="environment" class="hidden" onchange={handleChange} />
```

### 変更後コード

**(1) `upload-queue.ts`**（メモリ実装を追加。既存クラスは無変更）

```ts
/**
 * インスタンス生存中だけ保持する PendingStore (PC 面用)。
 * PC にはオフライン撮影の要件が無く、ページ遷移で失われてよい。
 * 撮影 PWA は従来どおり IndexedDB 実装 (lib/capture/idb.ts) を使う。
 */
export function createMemoryPendingStore(): PendingStore {
    const items = new Map<string, PendingUpload>();
    return {
        put: async (item) => { items.set(item.clientTakeId, item); },
        delete: async (clientTakeId) => { items.delete(clientTakeId); },
        list: async () => [...items.values()],
    };
}
```

**(2) `TakeFileUpload.svelte`**（新規）

```svelte
<script lang="ts">
    import { Upload } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import {
        createMemoryPendingStore,
        generateClientTakeId,
        UploadQueue,
    } from "@/lib/capture/upload-queue";

    /**
     * PC ローカル動画の追加アップロード (doc/04)。
     * 既存の presigned フロー (upload-url → S3 PUT → POST takes) を UploadQueue ごと再利用する
     * (アップロード実装を 2 本にしない)。MediaRecorder の有無に依存しない file input を使い、
     * capture 属性は付けない (PC ではファイルダイアログを開く)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        cutId: number;
        onUploaded: () => void;
    }
    let { projectId, manualId, cutId, onUploaded }: Props = $props();

    // store を自前で保持するのは、queued (オフライン等) の Blob を PC 側に残さないため
    const store = createMemoryPendingStore();
    const queue = new UploadQueue({ store });
    let input: HTMLInputElement | null = $state(null);
    let uploading = $state(false);
    let error = $state<string | null>(null);

    /**
     * 尺の**事前チェック** (doc/04 「尺は 1 分まで」)。
     * これは保証ではない — サーバは尺を強制せず、duration_ms はクライアント申告値である。
     * metadata を読めない形式では判定自体が働かない。
     * 真の尺による拒否はエンコード段 (別タスク) の担当である。
     */
    const MAX_DURATION_MS = 60_000;

    /**
     * メタデータから尺を読む。読めなければ null を返し**事前チェックを行わない** (詰ませない)。
     * loadedmetadata / error / timeout(3s) の 3 経路をすべて閉じ、Object URL は必ず revoke する。
     */
    function readDurationMs(file: File): Promise<number | null> {
        return new Promise((resolve) => {
            const url = URL.createObjectURL(file);
            const video = document.createElement("video");
            let settled = false;
            const finish = (value: number | null): void => {
                if (settled) return;
                settled = true;
                clearTimeout(timer);
                video.onloadedmetadata = null;
                video.onerror = null;
                video.removeAttribute("src");
                URL.revokeObjectURL(url); // 経路によらず必ず解放する
                resolve(value);
            };
            const timer = setTimeout(() => finish(null), 3_000);
            video.preload = "metadata";
            video.onloadedmetadata = () =>
                finish(Number.isFinite(video.duration) ? Math.round(video.duration * 1000) : null);
            video.onerror = () => finish(null);
            video.src = url;
        });
    }

    async function handleChange(): Promise<void> {
        error = null;
        const file = input?.files?.[0];
        // どの経路を通っても input を空に戻す (同じファイルの再選択で change が出ない問題を避ける)
        try {
            if (!file) return;
            if (!file.type.startsWith("video/")) {
                error = "動画ファイルを選択してください。";
                return;
            }
            const durationMs = await readDurationMs(file);
            // 押下は受けてからエラーを出す (disabled にしない。AGENTS.md 禁止事項 8)。
            // 断定形にしない = サーバ強制ではないため「登録できません」とは書かない
            if (durationMs !== null && durationMs > MAX_DURATION_MS) {
                error = "動画の長さが 1 分を超えています。1 分以内に切り出してからアップロードしてください。";
                return; // upload-url を呼ばない = quota を消費しない
            }
            uploading = true;
            const clientTakeId = generateClientTakeId();
            const outcome = await queue.enqueue({
                clientTakeId,
                projectId, manualId, cutId,
                blob: file,
                contentType: file.type.split(";")[0],
                durationMs,
                capturedAt: new Date().toISOString(),
            });
            if (outcome.status === "uploaded") { onUploaded(); return; }
            if (outcome.status === "quota_exceeded") { error = outcome.message; return; }
            // queued = オフライン等。PC は保持しない方針なので Blob を捨ててから理由を出す
            await store.delete(outcome.clientTakeId);
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } catch {
            // ネットワーク断 / presigned PUT の例外 / metadata 読み取りの reject。
            // 無反応にしない (即時アップロード経路は store.put() を通らないので Blob も残らない)
            error = "アップロードできませんでした。接続を確認して再度お試しください。";
        } finally {
            uploading = false;
            if (input) input.value = "";
        }
    }
</script>
```

- **`content_type` の制約**: サーバは `config('capture.allowed_video_content_types')`
  (`video/mp4` / `video/webm` / `video/quicktime`) 以外を 422 にする。
  クライアントで先回りの allowlist は持たない（設定の二重管理を作らない）。
  422 のサーバ文言をそのまま出す。
- **`size_bytes` の上限**: 同じく `config('capture.max_take_bytes')`（500 MiB）で
  サーバが 422。クライアントでは判定しない。

### PHPStan 適合チェック

- 本施策は frontend のみ（PHP 変更なし）。既存 `TakeUploadUrlController` /
  `TakeRegistrationService` は無変更。

### テスト計画

- [ ] 既存 `tests/js/lib/capture/upload-queue.test.ts` に追加
  - `createMemoryPendingStore()` が `put` / `delete` / `list` の契約を満たす
  - オフライン時の `enqueue` が `queued` を返し、`list()` に載る（既存クラスの振る舞い不変の確認）
- [ ] 新規 `tests/js/pages/ManualsTakes.test.ts` に追加
  - 動画以外のファイル選択でエラー文言（アップロードを開始しない）
  - 61 秒の動画で**事前チェック**のエラー文言（**upload-url を呼ばない** = quota を消費しない）。
    テスト名も「事前チェック」と書き、「1 分超は登録できない」という保証の名前にしない
  - **尺を読めない（metadata error / timeout）ファイルは事前チェックを飛ばして
    アップロードに進む**（読めないことで詰ませない）
  - `queued`（オフライン）のとき `store.delete()` が呼ばれ、Blob が残らない
  - **`enqueue()` が throw したとき**: エラー文言が表示され、`input.value` が空に戻り、
    `store.list()` が空のまま（無反応にならないことの固定）
  - 成功時に `router.reload({ only: ["cut", "takes"] })` が呼ばれる
  - 422 `quota_exceeded` のサーバ文言がそのまま表示される
  - どの経路でも `input.value` が空に戻る（同じファイルの再選択が効く）
- [ ] 既存 `tests/Feature/Capture/TakeUploadUrlTest.php` /
      `TakeRegistrationTest.php` が **無変更で** green（サーバ側を触っていないことの確認）
- [ ] 施策 1 の `PcTakeOperationTest` が編集者からの `upload-url` / `store` 成功を固定

### リスク

- **尺の制限は保証ではない（重要）**: 判定はクライアントの `loadedmetadata` だけであり、
  (a) metadata を読めない形式、(b) timeout、(c) 改竄された `duration_ms` のいずれでも
  1 分超の動画が登録される。**サーバは尺を強制しない。**
  したがって設計・UI 文言・テスト名のいずれでも
  **「1 分を超える動画は登録できない」とは書かない**（UI は事実の提示に留める）。
  真の尺による拒否は、エンコード段が入る将来タスクが ffprobe 等で行う。
- **メモリ store の取りこぼし**: アップロード中にページを離れると失われる。
  PC ではオフライン保持の要件が無いため受容する（PWA 側は従来どおり IndexedDB）。
  `queued` になった Blob は即 `store.delete()` するので、メモリに溜まり続けることはない。
- **quota の予約**: `upload-url` を呼んだ時点で容量が予約され、PUT 失敗時は予約が残る。
  既存の掃除 cron（`stale_verifying_minutes` / 期限切れ pending の release）が回収する。
  UI からの release 操作は作らない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | 4 施策は 1 つの画面を成立させるための縦串であり、単体では完成しない（施策 1 の画面が無ければ施策 2・4 の置き場が無く、施策 3 のリンク先も無い）。また施策 2 は `features/capture` の既存ファイルを移設するため、撮影 PWA を触る他タスクと同時進行させると衝突しやすい。1 つの worktree で通しで実装し、`AGENTS.md` の検証コマンド全 10 本（`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`）を green にしてからマージする |
| 競合リスク | **中**。(a) `routes/web.php` の業務 group（他タスクも route を足す位置）、(b) `resources/js/types/manual.ts`（型追加が集中する）、(c) `AdoptedTakeReferenceInventory` / `NestedRouteDefenseInventory`（目録は他タスクも触る）。いずれも追記のみで、意味的な競合ではない。**サムネイル生成タスクとは `SelectableTakeData` / `TakeThumbnail.svelte` で接触する**ため、どちらが先にマージされても後発が `thumbnail_url` を 1 フィールド足すだけで済むよう、差し替え点を `TakeThumbnail.svelte` 1 コンポーネントに閉じてある |

### 実装順序（依存順）

1. **施策 1**（route + Controller + DTO + 目録登録 + 画面の骨格）
   — 先に Feature テストを書いて fail を確認してから実装する（テストファースト）
2. **施策 2**（`SubtitleOverlay` 移設 → プレビューの字幕 / ナレーション原稿 ON/OFF）
3. **施策 3**（動画列と遷移導線。施策 1 の route が無いとリンク先が無い）
4. **施策 4**（アップロード。施策 1 の画面が置き場）
5. bug-hunt 目録の再生成 + ドキュメント 3 箇所の更新

### 完了条件チェック

- [ ] `composer test` / `composer phpstan` / `vendor/bin/pint --test`
- [ ] `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`
- [ ] `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages`
      （AGENTS.md の検証コマンド節が正本。本タスクは `packages/` を触らないが、
      コミット前は全レーン green が条件）
- [ ] `ScenarioWritePathInventoryTest` が**無変更で** green（新しい書き込み経路を作っていない）
- [ ] `NestedRouteIdorDefenseTest` / `TenantBoundaryOrderingTest` /
      `AdoptedReadyTakeCriterionInventoryTest` / `atomic-import-graph` が green
- [ ] `scripts/bug-hunt-inventory-check.sh` が exit 0
- [ ] `doc/10` / `docs/architecture.md` / `routes/web.php` コメントの 3 箇所を更新済み
