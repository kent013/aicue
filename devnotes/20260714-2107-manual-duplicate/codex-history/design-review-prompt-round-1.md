## アプリの使命（North Star / AGENTS.md より）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。v1 スコープ: 字幕のみ(TTS 後回し) / PWA 撮影 / ffmpeg 合成 / 単一 Default Project。

## 禁止事項（AGENTS.md より）

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて実装済み）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` 直書き（DTO/JsonResource/Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI

## セキュリティ不変条件（本設計に効くもの）
- tenant/所有権キー不信（project_id/created_by/category_id は payload から受けない）
- 子は親に属する: nested route 不整合は認可より前に 404
- cross-org 不可 / シナリオ整合の共有ロック規約（cuts/scenario_version/status を書く経路は対象 VideoManual 行を lockForUpdate した同一 tx 内で反映。新経路は inventory 登録必須）

【思考原則】まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。
【ツール使用制限】コマンド実行・ファイル書き込みは行わず、テキスト分析に集中。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel 12 + Svelte 5 + Inertia + TypeScript / PHPStan level 10 / Pest / DTO+JsonResource / Laratrust RBAC（Organization→Team→Project 階層）の詳細設計をレビューしてください。

【レビュー観点】
1. コードの正確性（ロジック・エッジケース・null 安全性）
2. 既存コードとの整合性（命名・パターン・API）
3. PHPStan level 10 適合性（型・generics・Assert）
4. テスト計画の網羅性（各施策に Pest、RefreshDatabase グローバル前提）
5. DTO/JsonResource パターン遵守
6. Inertia Props vs API Response の使い分け
7. 副作用・後退リスク
8. 波及変更の網羅性（TypeScript 型・Resource・テストが変更対象に含まれるか）
9. セキュリティ（認可・入力バリデーション・OWASP・共有ロック規約・IDOR）
10. DESIGN.md 準拠（token 経由・hex 直書きしない）
11. Atomic Design 準拠（atoms/molecules/organisms/features/pages の単方向 import・アイコン Lucide）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning に修正案必須
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

# 詳細設計: manual-duplicate（マニュアル(シナリオ)の別名保存/複製）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」で、熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
**v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び / 6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`（`back()->with(...)` / 明示 route で完結）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示）

### セキュリティ不変条件（本設計に効くもの）

- tenant/所有権キー不信（project_id/created_by/category_id は payload から受けない）
- 子は親に属する: nested route 不整合は認可より前に 404
- cross-org 不可: org-scoped 解決経由のみ
- シナリオ整合の共有ロック規約: cuts / scenario_version / status を書く全経路は対象 VideoManual 行を lockForUpdate した同一 tx 内で反映（新経路は inventory 登録必須）

### コーディングルール

- PHPStan level 10（`composer phpstan`）/ Pest（`composer test`）/ RefreshDatabase グローバル + `--parallel`（個別 `DatabaseTransactions` 禁止）
- テストデータは Factory 生成 / DTO+JsonResource または Inertia redirect / アーリーリターン / `composer fix`（Pint）/ `pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 runes + Inertia + TypeScript

## 概念設計リファレンス

`devnotes/20260714-2107-manual-duplicate/conceptual-design.md`（conceptual-review Round 3 で APPROVED）。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 複製 route 追加 | `routes/web.php` | High |
| 2 | `DuplicateVideoManualRequest`（FormRequest + 型付きアクセサ） | `app/Http/Requests/Projects/DuplicateVideoManualRequest.php`（新規） | High |
| 3 | Policy に `duplicate` ability | `app/Policies/VideoManualPolicy.php` | High |
| 4 | `VideoManualService::duplicate()` | `app/Services/Manual/VideoManualService.php` | High |
| 5 | Controller `duplicate()` + show props に categories 追加 | `app/Http/Controllers/Projects/VideoManualController.php` | High |
| 6 | frontend: 複製ダイアログ + ボタン + Props/型 | `resources/js/pages/Manuals/Show.svelte`, `resources/js/components/features/manual/DuplicateManualDialog.svelte`（新規） | High |
| 7 | IDOR inventory 登録 | `tests/Architecture/NestedRouteIdorDefenseTest.php` | High |
| 8 | 共有ロック書き込み経路 inventory 追記 | `tests/Architecture/ScenarioWritePathInventoryTest.php`（docblock）, `docs/architecture.md` | Medium |
| 9 | Feature テスト（複製の正しさ・非複製・権限・契約・後続接続） | `tests/Feature/Projects/ManualDuplicateTest.php`（新規） | High |
| 10 | vitest（UI） | `tests/js/pages/ManualsShow.test.ts` | Medium |

---

## 施策1: 複製 route 追加

### 変更箇所
- `routes/web.php` の既存 `Route::scopeBindings()->group(...)`（manuals 群、L412 付近）内に追加。

### 変更後コード
```php
// マニュアル複製 (別名保存の実体。保存済み cuts を雛形に新タイトル・カテゴリで新規作成)。
// {manual} は $project->manuals() 経由 (scopeBindings) = cross-manual/cross-project は 404。
Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
    ->name('projects.manuals.duplicate');
```

### 波及変更
- TypeScript 型定義: なし（URL は文字列直書き。既存 Show の delete と同流儀）
- API Resource/DTO: なし（Inertia redirect）
- テストファイル: 施策7（IDOR inventory）・施策9（Feature）

### リスク
- scopeBindings group 内に置くこと（外に置くと {manual}∈{project} の 404 防御が外れる）。`throttle` は不要（破壊的でなく作成系。既存 store も throttle 無し）。

---

## 施策2: DuplicateVideoManualRequest

### 変更箇所
- 新規 `app/Http/Requests/Projects/DuplicateVideoManualRequest.php`。`StoreVideoManualRequest` を厳密踏襲（document フィールドは持たない）。

### 変更後コード
```php
<?php

declare(strict_types=1);

namespace App\Http\Requests\Projects;

use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
use App\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Webmozart\Assert\Assert;

/**
 * VideoManual 複製 (別名保存)。保存済みシナリオを新タイトル・カテゴリで別 manual 化する。
 *
 * 入力名の境界: カテゴリ選択の入力名は保護キー (category_id) と別名の category (id 値・null 可)。
 * exists の project スコープは検証時点の保証。保存時は Service がロック済み project relation から
 * 再解決して associate する (二段構え)。
 *
 * 認可は Controller の Gate::authorize('duplicate', $manual) に一元化するため authorize() は true。
 * ただし {project} ∈ current org は route の project.in-current-org middleware が
 * FormRequest 検証より前に 404 に落とすため、category exists の project スコープは
 * cross-org/cross-project の存在差を漏らさない (存在オラクル防御)。
 */
class DuplicateVideoManualRequest extends FormRequest
{
    use ProhibitsProtectedKeys;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        $project = $this->route('project');
        $projectId = $project instanceof Project ? $project->id : 0;

        return array_merge([
            'title' => ['required', 'string', 'max:200'],
            'category' => [
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('project_id', $projectId),
            ],
        ], $this->protectedKeyMissingRules());
    }

    /** 検証済みタイトル (型付きアクセサで mixed を narrow) */
    public function title(): string
    {
        $title = $this->validated('title');
        Assert::string($title);

        return $title;
    }

    /** 検証済みカテゴリ id (null = 未分類)。ConvertEmptyStringsToNull 経由の '' も null 化される */
    public function categoryId(): ?int
    {
        $category = $this->validated('category');
        Assert::nullOrIntegerish($category);

        return $category === null ? null : (int) $category;
    }
}
```

### 波及変更
- TypeScript / DTO / Resource: なし
- テスト: 施策9（title required / max:200 / 他 project category=422 / protectedKey category_id=422 / categoryId() の '' → null）

### PHPStan適合チェック
- [x] `title(): string` / `categoryId(): ?int` で戻り型明示。`Assert::string` / `Assert::nullOrIntegerish` で narrow
- [x] 配列返却は rules() のみ（FormRequest 標準。DTO 不要）

### リスク
- `Rule::exists` は `project_id = $projectId` スコープ必須（外すと cross-project category を関連付けられ IDOR）。`$projectId=0` fallback は route binding 済みのため実際には到達しないが型安全のため保持（StoreVideoManualRequest と同一）。

---

## 施策3: Policy に duplicate ability

### 変更箇所
- `app/Policies/VideoManualPolicy.php` にメソッド追加。

### 変更後コード
```php
/** 複製 (別名保存): 元を閲覧でき、かつ同一プロジェクトに作成できる人 = プロジェクト編集者のみ。撮影者は不可 */
public function duplicate(User $user, VideoManual $manual): bool
{
    $project = $manual->project;

    return $project !== null && $this->projectPolicy->update($user, $project);
}
```

### 波及変更
- テスト: 施策9（撮影者 403 / 編集者 200）

### リスク
- `projectPolicy->update` は編集者判定（既存 create/update/delete と同一）。撮影者（project_member）は false = 403。整合済み。

---

## 施策4: VideoManualService::duplicate()

### 変更箇所
- `app/Services/Manual/VideoManualService.php` にメソッド追加。use に `Cut` を追加（既存 import 済みなら不要 — 現状未 import のため追加）。

### 現行コード
`create()`（L35-53）がロック順・category 再解決の見本。

### 変更後コード
```php
use App\Models\Category; // 追加 (category 再解決の型明示)
use App\Models\Cut;      // 既存 import 済 (delete で使用)

/**
 * VideoManual の複製 (別名保存)。保存済み cuts (シナリオ) を雛形に、新タイトル・カテゴリで
 * 新規 manual を作る。**takes / adopted_take_id / render 成果物 / source_documents /
 * analysis_jobs は複製しない** (新規撮影・再合成前提)。status=draft・scenario_version=0
 * (いずれも DB default) にリセットする。
 *
 * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の新しい書き込み経路:
 *  - 元 manual を lockForUpdate してシナリオを一貫読み取り
 *  - cuts の書き込み先は**新規** manual。新 manual を save() 後に同一 tx 内で
 *    lockForUpdate 再取得し、その locked インスタンスの relation 経由で cut を作成する
 *    (「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす)
 *  - scenario_version / status のリテラル書き込みはしない (新規行は DB default 依存)
 */
public function duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual
{
    return DB::transaction(function () use ($project, $source, $title, $categoryId, $userId): VideoManual {
        // ロック順は create/updateMeta と同じ project → manual
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        // 子は親に属する: 元 manual をロック済み親 relation から再解決 (cross-project は 404) + 一貫読み取り
        /** @var VideoManual $lockedSource */
        $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();

        // 新 manual (status/scenario_version は DB default = draft/0)。created_by はサーバ導出
        $new = $locked->manuals()->make(['title' => $title]);
        $new->forceFill(['created_by' => $userId])->save();
        if ($categoryId !== null) {
            // 保存時再解決: 検証後に category が削除/移動された競合のみ firstOrFail で 404
            $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
            $new->category()->associate($category)->save();
        }

        // 共有ロック規約 literal 準拠: cuts 書き込み先の新 manual をロックして再取得
        /** @var VideoManual $lockedNew */
        $lockedNew = $locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail();
        $this->copyCuts($lockedSource, $lockedNew);

        return $lockedNew;
    });
}

/**
 * 元 manual の cuts を新 manual へ複製する (ロック済み tx 内前提)。
 * step を sort_order 順に複製 → 各 step 配下 point を sort_order 順に複製。
 * parent_cut_id は旧 step id→新 step id で張り替え、adopted_take_id/cut_length_ms は複製しない。
 */
private function copyCuts(VideoManual $source, VideoManual $target): void
{
    $cuts = $source->cuts()->orderBy('sort_order')->orderBy('id')->get();
    /** @var array<int, Cut> $newStepByOldId 旧 step id → 新 step Cut */
    $newStepByOldId = [];

    // 段階1: step を複製 (parent_cut_id=null)
    foreach ($cuts->where('type', CutType::Step) as $step) {
        $newStepByOldId[$step->id] = $this->replicateCut($target, $step, null);
    }
    // 段階2: point を複製 (親 step の新 id へ張り替え)。孤児 point (親不明) は skip
    foreach ($cuts->where('type', CutType::Point) as $point) {
        $parentOldId = $point->parent_cut_id;
        if ($parentOldId === null || ! isset($newStepByOldId[$parentOldId])) {
            continue;
        }
        $this->replicateCut($target, $point, $newStepByOldId[$parentOldId]->id);
    }
}

/**
 * 1 cut の複製。本文は fill、type/sort_order/parent_cut_id はサーバ導出値を forceFill。
 * adopted_take_id / cut_length_ms は複製しない (前者は default null、後者は明示 null リセット)。
 */
private function replicateCut(VideoManual $target, Cut $source, ?int $parentCutId): Cut
{
    $cut = $target->cuts()->make([
        'scene' => $source->scene,
        'shot_type' => $source->shot_type,
        'shooting_point' => $source->shooting_point,
        'narration' => $source->narration,
        'subtitle_primary' => $source->subtitle_primary,
        'subtitle_secondary' => $source->subtitle_secondary,
        'material_type' => $source->material_type,
        'static_display_seconds' => $source->static_display_seconds,
    ]);
    $cut->forceFill([
        'type' => $source->type,
        'sort_order' => $source->sort_order,
        'parent_cut_id' => $parentCutId,
        'cut_length_ms' => null, // レンダ由来。撮影前はリセット
    ]);
    $cut->save();

    return $cut;
}
```

補足: `CutType` は既存 import（ScenarioService と別ファイルのため VideoManualService に `use App\Enums\Manual\CutType;` を追加）。

### 波及変更
- TypeScript / DTO / Resource: なし
- テスト: 施策9（複製の正しさ・非複製・後続接続）、施策8（inventory 追記）

### PHPStan適合チェック
- [x] 戻り値型 `VideoManual` 明示。`copyCuts`/`replicateCut` は private・型付き
- [x] `firstOrFail()` で null 安全（`whereKey()->firstOrFail()`）。`/** @var VideoManual $lockedSource */` で narrow
- [x] `$newStepByOldId` は `array<int, Cut>` を PHPDoc 明示
- [x] DTO でなく Model を返す（Service 内契約。Controller が redirect に使うのみ。array 返却なし）

### リスク
- **fill と fillable の整合**: `Cut::$fillable` に本文 8 フィールドが含まれる（scene/shot_type/shooting_point/narration/subtitle_primary/subtitle_secondary/material_type/static_display_seconds）。`type`/`parent_cut_id`/`cut_length_ms` は forceFill（`sort_order` も fillable だが型導出値として forceFill で明示）。
- **孤児 point**: parent 不明の point は skip（データ健全性防御。通常発生しないが 404 でなく静かに落とす方が複製の頑健性が高い）。
- **cuts 件数**: 有界（step≤100×point≤20）のため chunk 化しない（ScenarioService と同方針）。

---

## 施策5: Controller duplicate() + show props に categories

### 変更箇所
- `app/Http/Controllers/Projects/VideoManualController.php`。

### 変更後コード（duplicate メソッド追加）
```php
use App\Http\Requests\Projects\DuplicateVideoManualRequest;

/** VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成し詳細へ遷移 */
public function duplicate(DuplicateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
    $this->resolveOrganizationProject($organization, $project);
    Gate::authorize('duplicate', $manual);

    $user = $request->user();
    Assert::isInstanceOf($user, User::class);

    $copy = $manuals->duplicate($project, $manual, $request->title(), $request->categoryId(), $user->id);

    return redirect()
        ->route('projects.manuals.show', [$project, $copy])
        ->with('success', '動画マニュアルを複製しました（手順書は引き継がれません）');
}
```

### 変更後コード（show の Inertia props に categories 追加）
`Manuals/Show` render の props 配列（L112-）に 1 行追加:
```php
'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
```

### 波及変更
- TypeScript 型定義: `resources/js/pages/Manuals/Show.svelte` の Props interface に `categories: CategoryOption[]` 追加（施策6）
- API Resource/DTO: なし
- テスト: 施策9（Feature）、施策10（vitest は props を与える）

### PHPStan適合チェック
- [x] `DuplicateVideoManualRequest` 型注入で validated アクセサが型安全
- [x] `Assert::isInstanceOf($user, User::class)` で user null 安全（既存 store と同一）
- [x] `RedirectResponse` 戻り型明示。`response()->json()` 不使用・`redirect()->intended()` 不使用

### リスク
- `categoryOptions()` は既存 private helper（sort_order 順・`list<array{id:int,name:string}>`）。Show に足すことで撮影者にも category 一覧が props で渡るが、category 名はプロジェクト内で閲覧可能な情報であり漏洩ではない（一覧フィルタでも既に開示済み）。複製ボタン自体は `canManage` gate。

---

## 施策6: frontend 複製ダイアログ + ボタン + Props/型

### 変更箇所
- 新規 `resources/js/components/features/manual/DuplicateManualDialog.svelte`（feature/manual 層。Modal + FormField + Select を組む organism 相当の feature component）
- `resources/js/pages/Manuals/Show.svelte`（Props に categories 追加・ボタン・ダイアログ配線）

### DuplicateManualDialog.svelte（新規）
```svelte
<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { CategoryOption } from "@/types/manual";

    /**
     * マニュアル複製 (別名保存) ダイアログ。保存済みシナリオを新タイトル・カテゴリで複製する。
     * タイトルは「{元タイトル} のコピー」、カテゴリは元 category をプリフィルする。
     */
    interface Props {
        open: boolean;
        projectId: number;
        manualId: number;
        defaultTitle: string;                 // 「{元タイトル} のコピー」
        defaultCategory: number | null;       // 元 category id (null = 未分類)
        categories: CategoryOption[];
    }

    let {
        open = $bindable(false),
        projectId,
        manualId,
        defaultTitle,
        defaultCategory,
        categories,
    }: Props = $props();

    const form = useForm<{ title: string; category: string }>({
        title: defaultTitle,
        category: defaultCategory === null ? "" : String(defaultCategory),
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form
            .transform((data) => ({
                title: data.title,
                category: data.category === "" ? null : Number(data.category),
            }))
            .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
                // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
                onError: () => {
                    /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
                },
            });
    }
</script>

<Modal bind:open title="動画マニュアルを複製" size="sm" processing={form.processing} testId="duplicate-manual-dialog">
    <form id="duplicate-manual-form" onsubmit={submit} class="flex flex-col gap-4">
        <p class="text-caption text-text-secondary">
            シナリオ（カット）を引き継いだ新しい動画マニュアルを作成します。撮影データ・手順書（SOP）は引き継がれません。
        </p>
        <FormField label="タイトル" id="duplicate-title" error={form.errors.title} required>
            {#snippet children({ id, describedBy, invalid })}
                <Input {id} type="text" bind:value={form.title} error={invalid} aria-describedby={describedBy}
                    oninput={() => { if (form.errors.title) form.clearErrors("title"); }} />
            {/snippet}
        </FormField>
        <FormField label="カテゴリ" id="duplicate-category" error={form.errors.category}>
            {#snippet children({ id, describedBy, invalid })}
                <Select {id} bind:value={form.category} error={invalid} aria-describedby={describedBy} testId="duplicate-category-select">
                    <option value="">未分類</option>
                    {#each categories as category (category.id)}
                        <option value={String(category.id)}>{category.name}</option>
                    {/each}
                </Select>
            {/snippet}
        </FormField>
    </form>
    {#snippet footer()}
        <Button variant="ghost" onclick={() => (open = false)} disabled={form.processing}>キャンセル</Button>
        <Button type="submit" form="duplicate-manual-form" loading={form.processing} testId="duplicate-manual-confirm">複製する</Button>
    {/snippet}
</Modal>
```

### Manuals/Show.svelte（差分）
1. Props interface に追加:
```ts
categories: CategoryOption[];
```
（import に `import type { CategoryOption, ... } from "@/types/manual";` を追加）
2. `let { ..., categories }: Props = $props();`
3. state: `let duplicateDialogOpen = $state(false);`
4. ヘッダのアクション群（編集ボタン隣・`{#if canManage}` 内）に複製ボタン:
```svelte
<Button variant="ghost" onclick={() => (duplicateDialogOpen = true)} testId="duplicate-manual-button">複製</Button>
```
5. テンプレート末尾（ConfirmDialog 隣）にダイアログ:
```svelte
{#if canManage}
    <DuplicateManualDialog
        bind:open={duplicateDialogOpen}
        projectId={project.id}
        manualId={manual.id}
        defaultTitle={`${manual.title} のコピー`}
        defaultCategory={manual.category?.id ?? null}
        {categories}
    />
{/if}
```

### 波及変更
- TypeScript 型定義: Manuals/Show Props に `categories: CategoryOption[]`（`CategoryOption` は既存）
- Inertia Props: 施策5（Controller show が categories を供給）
- Atomic import: `pages → features/manual → organisms/molecules/atoms` の単方向。DuplicateManualDialog は features/manual に置き Modal(organisms)/FormField(molecules)/Input・Select・Button(atoms) を import（合法）。ページからの feature import も合法。

### DESIGN.md / Atomic 準拠チェック
- [x] token/ramp のみ（既存 Manuals/Create と同一 class 群。hex 直書きなし）
- [x] Modal/FormField/Select/Input/Button は既存 DS component 再利用（新規 atom/SVG 追加なし）
- [x] 必須未充足で disabled にしない（タイトル空でも押下可 → サーバ 422 を FormField 表示。禁止事項8 遵守）

### リスク
- ボタン form 送信: footer の submit ボタンは `form="duplicate-manual-form"` 属性で form 外からの submit を発火（Modal footer は form の外）。ブラウザ標準の form attribute で対応。
- ESC/overlay での閉じは Modal 既定（processing 中は ignore）。form state は再オープン時 useForm の初期値で再構築されないため、`{#if canManage}` でマウントし open state で開閉（useForm はコンポーネント存続中 state を保持するが、複製後は redirect で画面遷移するため残留しない）。

---

## 施策7: IDOR inventory 登録

### 変更箇所
- `tests/Architecture/NestedRouteIdorDefenseTest.php` の `nestedRouteIdorInventory()` の scopeBindings 群（manuals セクション）に追加。

### 変更後コード
```php
'projects.manuals.destroy' => $s,
'projects.manuals.duplicate' => $s, // {manual} は $project->manuals() 経由 (保存済み cuts を複製)
```

### リスク
- 登録漏れは NestedRouteIdorDefenseTest が deny-by-default で fail させる（= 追加必須）。

---

## 施策8: 共有ロック書き込み経路 inventory 追記

### 変更箇所
- `tests/Architecture/ScenarioWritePathInventoryTest.php` の docblock 経路表（冒頭コメント）
- `docs/architecture.md` のシナリオ整合 書き込み経路表

### 変更内容（docblock 経路表に 1 行）
```
| VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。
  元 manual を lockForUpdate して一貫読み取り。scenario_version/status/adopted_take_id の
  リテラル書き込みはしない (新規行は DB default 依存) ため scanner 検出 1/2/4 は非対象 = allowlist 変更不要 |
```

### リスク
- scanner（`ScenarioWritePathScanner`）のコード変更は不要（duplicate は検出対象リテラルを書かない）。docblock/architecture.md への台帳追記のみ。もし将来 duplicate が status を書くよう変わったら STATUS_WRITE_ALLOWED への追加が必要になる旨を docblock に残す。

---

## 施策9: Feature テスト（新規 `tests/Feature/Projects/ManualDuplicateTest.php`）

### テスト計画
既存 `VideoManualCrudTest` のヘルパ（`createOrganizationWithOwner()`, `Project::factory()->forOrganization()`, `Category::factory()->forProject()`, `Cut::factory()->forManual()/asPointOf()/withSortOrder()`, `Take::factory()`）を用いる。`Queue::fake()` は不要（duplicate は job を投げない）。

- [ ] **複製の正しさ**: step2 + 各 step に point を持つ元 manual を作り複製。新 manual の cuts が step/point 二層・sort_order・本文フィールドまで一致し、point の parent_cut_id が**新 step id**を指す（元 id ではない）。新タイトル・カテゴリが反映。応答は新 manual show へ redirect + `success` フラッシュ。元 manual の cuts は不変（件数・id 保持）。
- [ ] **リセット確認**: 新 manual の status=Draft・scenario_version=0。複製された全 cut の adopted_take_id=null・cut_length_ms=null。
- [ ] **非複製**: 元 manual に Take（adopted 済み）・SourceDocument・RenderJob・AnalysisJob を付与 → 複製後、新 manual 配下の takes / source_documents / render_jobs / analysis_jobs が 0 件。
- [ ] **後続接続**: 複製直後に `CutSequencer::orderedWithLabels($newManual)` が全 cuts を「手順N / 急所N-M」ラベル付きで返す（順序・件数一致）。
- [ ] **権限**: 撮影者（project_member ロール付与ユーザ）の複製 POST は 403。編集者（owner / project_admin）は成功。
- [ ] **組織スコープ / IDOR**: 他組織 owner が当該 manual を複製 → 404（cross-org）。`{manual}` が別 project の場合 → 404（scopeBindings）。
- [ ] **category 契約**: 他 project の category を渡すと 422（`assertSessionHasErrors('category')`）。保護キー `category_id` 直送は 422（`assertSessionHasErrors('category_id')`）。`category` 未指定は未分類で複製。
- [ ] **title 契約**: title 空は 422（required）。201 文字は 422（max:200）。
- [ ] 個別 `DatabaseTransactions` を使わない（グローバル RefreshDatabase）。

### リスク
- `laratrust_team_id` 明示の権限判定は既存 ProjectPolicy が担保（テストは actingAs + ロール付与で駆動）。撮影者ロール付与ヘルパは既存 CRUD テストの撮影者ケースに倣う。

---

## 施策10: vitest（`tests/js/pages/ManualsShow.test.ts` に追記）

### テスト計画
- [ ] `canManage=true` かつ categories props ありでレンダすると複製ボタン（`duplicate-manual-button`）が表示される。`canManage=false` では非表示。
- [ ] 複製ボタン押下で `duplicate-manual-dialog` が開き、タイトル入力に「{manual.title} のコピー」がプリフィルされ、カテゴリ select が元 category を選択している。
- [ ] 「複製する」押下で `/projects/{id}/manuals/{id}/duplicate` に POST される（Inertia `router.post`/`useForm.post` の呼び出しを spy）。

### リスク
- 既存 ManualsShow.test.ts のモック（Inertia `useForm`/`router`）に合わせる。props に categories を追加する必要あり（既存テストの mount props 更新 = 波及）。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 新規 route/Controller メソッド/Service メソッド/FormRequest/Policy メソッド/feature component の追加が中心で、既存経路の挙動を変えない（show props に categories を足す 1 行のみ既存改変）。他施策との干渉が小さく単独 worktree で完結。 |
| 競合リスク | 低。`VideoManualController` / `VideoManualService` / `Manuals/Show.svelte` / IDOR・ScenarioWritePath inventory テストに触れるため、同ファイルを触る他タスクとの同時進行時のみ注意。 |


---

## 関連する現行コード（抜粋）

### app/Services/Manual/VideoManualService.php::create（見本・ロック順/category 再解決）
```php
public function create(Project $project, string $title, ?int $categoryId, int $userId, ?UploadedFile $document = null): VideoManual
{
    return DB::transaction(function () use ($project, $title, $categoryId, $userId, $document): VideoManual {
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        $manual = $locked->manuals()->make(['title' => $title]);
        $manual->forceFill(['created_by' => $userId])->save();
        if ($categoryId !== null) {
            $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
            $manual->category()->associate($category)->save();
        }
        if ($document !== null) { $this->sourceDocuments->appendDocument($manual, $document); }
        return $manual;
    });
}
```

### Cut モデル $fillable / 保護キー
```
$fillable = [type, shot_type, material_type, sort_order, scene, shooting_point, narration,
             subtitle_primary, subtitle_secondary, static_display_seconds, cut_length_ms];
// 保護キー(fillable外): video_manual_id, parent_cut_id, adopted_take_id
// casts: type=CutType, shot_type=ShotType, material_type=MaterialType
```
注: 詳細設計の replicateCut は type/sort_order/parent_cut_id/cut_length_ms を forceFill、本文8フィールドを fill としている（sort_order・cut_length_ms は fillable だが導出値として forceFill）。レビューで fill/forceFill の切り分けが妥当か確認されたし。

### VideoManualController::store（既存パターン）
```php
public function store(StoreVideoManualRequest $request, Project $project, VideoManualService $manuals): RedirectResponse
{
    $organization = $this->resolveCurrentOrganization($request);
    $this->resolveOrganizationProject($organization, $project); // cross-org 404
    Gate::authorize('create', [VideoManual::class, $project]);
    $user = $request->user(); Assert::isInstanceOf($user, User::class);
    // ... validated title/category ...
    $manual = $manuals->create($project, $title, $category, $user->id, $document);
    return redirect()->route('projects.manuals.show', [$project, $manual])->with('success', '...');
}
```

### CutSequencer::orderedWithLabels（読み取り専用・step を sort_order 順→配下 point）
step を type=Step で sort_order 順、point を parent_cut_id でグルーピングし手順N/急所N-M ラベル付与。

### 既存 route（scopeBindings group 内、抜粋）
`projects.manuals.show/edit/update/scenario.update/source-documents.store/analyze/render/.../destroy` はすべて scopeBindings group 内。group 外側に `project.in-current-org` middleware + `require-active-subscription`。

### DuplicateVideoManualRequest の見本 = StoreVideoManualRequest
`title=[required,string,max:200]`、`category=[nullable,integer,Rule::exists('categories','id')->where('project_id',$projectId)]`、`+ protectedKeyMissingRules()`（ProhibitsProtectedKeys）。authorize()=true。

質問: 特に (a) 新規 manual を再 lockForUpdate して cuts を書く共有ロック規約 literal 準拠、(b) fill/forceFill の切り分けと adopted_take_id/cut_length_ms リセット、(c) FormRequest 型付きアクセサの PHPStan L10 適合、(d) frontend の Modal footer submit（form 属性）と Atomic 配置、(e) 波及（Show props/型・vitest 既存テスト更新）の網羅性、を重点確認。
