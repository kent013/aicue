# 実装レビュー依頼: T049 マニュアル(シナリオ)の別名保存/複製

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

## セキュリティ不変条件
1. tenant キー不信: ownership/actor/tenant キーを payload から受けない
2. 子は親に属する: nested route の不整合は認可より前に 404
3. cross-org 不可: 組織を跨ぐ read/write をしない(relation / org-scoped 解決経由のみ)
5. 権限判定は常に `laratrust_team_id` を明示
- シナリオ整合の共有ロック規約: cuts / scenario_version / status を書き込む全経路は対象 VideoManual 行を lockForUpdate した同一 tx 内で反映する(新経路は inventory 登録必須)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。
先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ。
機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: あなたの役割

あなたは Laravel 12 (PHP 8.4) + Svelte 5 (runes) + Inertia + TypeScript アプリのコードレビュアーである。以下の観点で実装差分をレビューせよ:

1. **設計との一致性**: 詳細設計書どおりに実装されているか。逸脱があれば妥当か。
2. **正確性**: 複製ロジック (step→point の parent_cut_id 張り替え、takes/成果物/SOP 非複製、status/scenario_version/adopted_take_id/cut_length_ms のリセット) にバグはないか。
3. **PHPStan 適合性** (level 10): 型の widen や暗黙 mixed がないか。
4. **DTO/JsonResource/Inertia パターン**: `response()->json()` 直書きなし。Inertia redirect を使っているか。
5. **セキュリティ**: IDOR (cross-org/cross-project 404)、保護キー不信 (category_id 直送 422)、共有ロック規約 (lockForUpdate 同一 tx)、認可 (撮影者 403)。
6. **テスト網羅性**: Feature テストが複製の正しさ・非複製・リセット・孤児 point・後続接続・権限・IDOR・契約を検証しているか。個別 DatabaseTransactions 不使用。
7. **DESIGN.md 準拠**: color/radius/typography は token 経由 (hex 直書きを増やさない)。
8. **Atomic Design 準拠**: `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。アイコンは Lucide。SVG 直書きを増やさない。

出力形式: ファイルごとに Critical / Warning / Suggestion に分類し、最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記せよ。

---

## user: レビュー対象

### 詳細設計書
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
 * ただし {project} ∈ current org は route の project.in-route-org middleware が
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

    /**
     * 検証済みカテゴリ id (null = 未分類)。ConvertEmptyStringsToNull 経由の '' も null 化される。
     * Select 由来の数値文字列も許容する (nullOrIntegerish で narrow)。
     */
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
use App\Enums\Manual\CutType;            // 追加 (step/point 判定)
use App\Models\Cut;                       // 既存 import 済 (delete で使用)
use Illuminate\Support\Facades\Log;       // 追加 (孤児 point の warning)

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
            // 保存時再解決: 既存 create() と同一の firstOrFail。通常の不正/他 project category は
            // FormRequest の Rule::exists で 422 (検証時) に落ち、ここで 404 になるのは
            // 「検証通過後に category が削除/移動された」ごく稀な競合のみ (create と完全一致・後退なし)。
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
    // initial orderBy(sort_order,id) を維持したまま filter する (Eloquent Collection の
    // filter/where は順序を保持 = 親内 point 順序は sort_order 準拠 = CutSequencer と同順)。
    $cuts = $source->cuts()->orderBy('sort_order')->orderBy('id')->get();
    /** @var array<int, Cut> $newStepByOldId 旧 step id → 新 step Cut */
    $newStepByOldId = [];

    // 段階1: step を複製 (parent_cut_id=null)
    foreach ($cuts->where('type', CutType::Step) as $step) {
        $newStepByOldId[$step->id] = $this->replicateCut($target, $step, null);
    }
    // 段階2: point を複製 (親 step の新 id へ張り替え)。
    // 孤児 point (親不明。通常発生しない) は skip し warning ログで観測可能にする (データ破損を黙殺しない)。
    foreach ($cuts->where('type', CutType::Point) as $point) {
        $parentOldId = $point->parent_cut_id;
        if ($parentOldId === null || ! isset($newStepByOldId[$parentOldId])) {
            Log::warning('マニュアル複製: 親不明の急所カットを複製対象から除外しました', [
                'source_manual_id' => $source->id,
                'cut_id' => $point->id,
                'parent_cut_id' => $parentOldId,
            ]);

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
                // category は Select 固定値 (option value=id 文字列 or "") のため Number 変換は安全 ('' のみ null)
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
- [ ] **リセット確認**: 新 manual の status=Draft・scenario_version=0。複製された **step および point の両層**で adopted_take_id=null・cut_length_ms=null（元 step/point が adopted_take_id・cut_length_ms を持っていても複製先はリセット）。
- [ ] **非複製**: 元 manual に Take（adopted 済み）・SourceDocument・RenderJob・AnalysisJob を付与 → 複製後、新 manual 配下の takes / source_documents / render_jobs / analysis_jobs が 0 件。
- [ ] **孤児 point**: 親 step を持たない point（parent_cut_id が複製対象外）を含む元 manual を複製 → 新 manual に当該 point が複製されず、warning ログが出る（`Log::spy()` 等で検証）。step とその正常 point は複製される。
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

### 実装差分 (git diff HEAD)

```diff
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 66e3bc8..95334d7 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -11,6 +11,7 @@
 use App\Enums\Manual\RenderKind;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
+use App\Http\Requests\Projects\DuplicateVideoManualRequest;
 use App\Http\Requests\Projects\StoreVideoManualRequest;
 use App\Http\Requests\Projects\UpdateVideoManualRequest;
 use App\Models\Category;
@@ -147,9 +148,28 @@ public function show(Request $request, Project $project, VideoManual $manual, Se
                     ->value('id'),
             ],
             'canManage' => $user->can('update', $manual),
+            'categories' => $this->categoryOptions($project), // 複製ダイアログのカテゴリ選択肢 (既存 helper 再利用)
         ]);
     }
 
+    /** VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成し詳細へ遷移 */
+    public function duplicate(DuplicateVideoManualRequest $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
+    {
+        $organization = $this->resolveCurrentOrganization($request);
+        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
+        $this->resolveOrganizationProject($organization, $project);
+        Gate::authorize('duplicate', $manual);
+
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class);
+
+        $copy = $manuals->duplicate($project, $manual, $request->title(), $request->categoryId(), $user->id);
+
+        return redirect()
+            ->route('projects.manuals.show', [$project, $copy])
+            ->with('success', '動画マニュアルを複製しました（手順書は引き継がれません）');
+    }
+
     /** 編集フォーム (メタデータ = title / category + シナリオ document) */
     public function edit(Request $request, Project $project, VideoManual $manual, SeoManager $seo): Response
     {
diff --git a/app/Http/Requests/Projects/DuplicateVideoManualRequest.php b/app/Http/Requests/Projects/DuplicateVideoManualRequest.php
new file mode 100644
index 0000000..a6bf778
--- /dev/null
+++ b/app/Http/Requests/Projects/DuplicateVideoManualRequest.php
@@ -0,0 +1,72 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Http\Requests\Projects;
+
+use App\Http\Requests\Concerns\ProhibitsProtectedKeys;
+use App\Models\Project;
+use Illuminate\Foundation\Http\FormRequest;
+use Illuminate\Validation\Rule;
+use Webmozart\Assert\Assert;
+
+/**
+ * VideoManual 複製 (別名保存)。保存済みシナリオを新タイトル・カテゴリで別 manual 化する。
+ *
+ * 入力名の境界: カテゴリ選択の入力名は保護キー (category_id) と別名の category (id 値・null 可)。
+ * exists の project スコープは検証時点の保証。保存時は Service がロック済み project relation から
+ * 再解決して associate する (二段構え)。
+ *
+ * 認可は Controller の Gate::authorize('duplicate', $manual) に一元化するため authorize() は true。
+ * ただし {project} ∈ current org は route の project.in-route-org middleware が
+ * FormRequest 検証より前に 404 に落とすため、category exists の project スコープは
+ * cross-org/cross-project の存在差を漏らさない (存在オラクル防御)。
+ */
+class DuplicateVideoManualRequest extends FormRequest
+{
+    use ProhibitsProtectedKeys;
+
+    public function authorize(): bool
+    {
+        return true;
+    }
+
+    /**
+     * @return array<string, list<mixed>>
+     */
+    public function rules(): array
+    {
+        $project = $this->route('project');
+        $projectId = $project instanceof Project ? $project->id : 0;
+
+        return array_merge([
+            'title' => ['required', 'string', 'max:200'],
+            'category' => [
+                'nullable',
+                'integer',
+                Rule::exists('categories', 'id')->where('project_id', $projectId),
+            ],
+        ], $this->protectedKeyMissingRules());
+    }
+
+    /** 検証済みタイトル (型付きアクセサで mixed を narrow) */
+    public function title(): string
+    {
+        $title = $this->validated('title');
+        Assert::string($title);
+
+        return $title;
+    }
+
+    /**
+     * 検証済みカテゴリ id (null = 未分類)。ConvertEmptyStringsToNull 経由の '' も null 化される。
+     * Select 由来の数値文字列も許容する (nullOrIntegerish で narrow)。
+     */
+    public function categoryId(): ?int
+    {
+        $category = $this->validated('category');
+        Assert::nullOrIntegerish($category);
+
+        return $category === null ? null : (int) $category;
+    }
+}
diff --git a/app/Policies/VideoManualPolicy.php b/app/Policies/VideoManualPolicy.php
index 9db546e..ea9d5c4 100644
--- a/app/Policies/VideoManualPolicy.php
+++ b/app/Policies/VideoManualPolicy.php
@@ -51,6 +51,14 @@ public function delete(User $user, VideoManual $manual): bool
         return $project !== null && $this->projectPolicy->update($user, $project);
     }
 
+    /** 複製 (別名保存): 元を閲覧でき、かつ同一プロジェクトに作成できる人 = プロジェクト編集者のみ。撮影者は不可 */
+    public function duplicate(User $user, VideoManual $manual): bool
+    {
+        $project = $manual->project;
+
+        return $project !== null && $this->projectPolicy->update($user, $project);
+    }
+
     /** AI 解析の実行: プロジェクトを操作できる人 (編集者)。撮影者は不可 */
     public function analyze(User $user, VideoManual $manual): bool
     {
diff --git a/app/Services/Manual/VideoManualService.php b/app/Services/Manual/VideoManualService.php
index 65dc906..bb96382 100644
--- a/app/Services/Manual/VideoManualService.php
+++ b/app/Services/Manual/VideoManualService.php
@@ -4,10 +4,12 @@
 
 namespace App\Services\Manual;
 
+use App\Enums\Manual\CutType;
 use App\Enums\Manual\JobStatus;
 use App\Enums\Manual\RenderKind;
 use App\Jobs\Capture\DeleteTakeObjectsJob;
 use App\Models\AnalysisJob;
+use App\Models\Cut;
 use App\Models\Project;
 use App\Models\RenderJob;
 use App\Models\SourceDocument;
@@ -15,6 +17,7 @@
 use App\Models\VideoManual;
 use Illuminate\Http\UploadedFile;
 use Illuminate\Support\Facades\DB;
+use Illuminate\Support\Facades\Log;
 
 /**
  * VideoManual の書き込み操作 (create / updateMeta / delete)。
@@ -52,6 +55,109 @@ public function create(Project $project, string $title, ?int $categoryId, int $u
         });
     }
 
+    /**
+     * VideoManual の複製 (別名保存)。保存済み cuts (シナリオ) を雛形に、新タイトル・カテゴリで
+     * 新規 manual を作る。**takes / adopted_take_id / render 成果物 / source_documents /
+     * analysis_jobs は複製しない** (新規撮影・再合成前提)。status=draft・scenario_version=0
+     * (いずれも DB default) にリセットする。
+     *
+     * シナリオ整合の共有ロック規約 (AGENTS.md ドメイン規約 1) の新しい書き込み経路:
+     *  - 元 manual を lockForUpdate してシナリオを一貫読み取り
+     *  - cuts の書き込み先は**新規** manual。新 manual を save() 後に同一 tx 内で
+     *    lockForUpdate 再取得し、その locked インスタンスの relation 経由で cut を作成する
+     *    (「対象 VideoManual 行を lockForUpdate で取得した同一 tx 内で反映」を literal に満たす)
+     *  - scenario_version / status のリテラル書き込みはしない (新規行は DB default 依存)
+     */
+    public function duplicate(Project $project, VideoManual $source, string $title, ?int $categoryId, int $userId): VideoManual
+    {
+        return DB::transaction(function () use ($project, $source, $title, $categoryId, $userId): VideoManual {
+            // ロック順は create/updateMeta と同じ project → manual
+            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
+            // 子は親に属する: 元 manual をロック済み親 relation から再解決 (cross-project は 404) + 一貫読み取り
+            /** @var VideoManual $lockedSource */
+            $lockedSource = $locked->manuals()->whereKey($source->id)->lockForUpdate()->firstOrFail();
+
+            // 新 manual (status/scenario_version は DB default = draft/0)。created_by はサーバ導出
+            $new = $locked->manuals()->make(['title' => $title]);
+            $new->forceFill(['created_by' => $userId])->save();
+            if ($categoryId !== null) {
+                // 保存時再解決: 既存 create() と同一の firstOrFail。通常の不正/他 project category は
+                // FormRequest の Rule::exists で 422 (検証時) に落ち、ここで 404 になるのは
+                // 「検証通過後に category が削除/移動された」ごく稀な競合のみ (create と完全一致・後退なし)。
+                $category = $locked->categories()->whereKey($categoryId)->firstOrFail();
+                $new->category()->associate($category)->save();
+            }
+
+            // 共有ロック規約 literal 準拠: cuts 書き込み先の新 manual をロックして再取得
+            /** @var VideoManual $lockedNew */
+            $lockedNew = $locked->manuals()->whereKey($new->id)->lockForUpdate()->firstOrFail();
+            $this->copyCuts($lockedSource, $lockedNew);
+
+            return $lockedNew;
+        });
+    }
+
+    /**
+     * 元 manual の cuts を新 manual へ複製する (ロック済み tx 内前提)。
+     * step を sort_order 順に複製 → 各 step 配下 point を sort_order 順に複製。
+     * parent_cut_id は旧 step id→新 step id で張り替え、adopted_take_id/cut_length_ms は複製しない。
+     */
+    private function copyCuts(VideoManual $source, VideoManual $target): void
+    {
+        // initial orderBy(sort_order,id) を維持したまま filter する (Eloquent Collection の
+        // filter/where は順序を保持 = 親内 point 順序は sort_order 準拠 = CutSequencer と同順)。
+        $cuts = $source->cuts()->orderBy('sort_order')->orderBy('id')->get();
+        /** @var array<int, Cut> $newStepByOldId 旧 step id → 新 step Cut */
+        $newStepByOldId = [];
+
+        // 段階1: step を複製 (parent_cut_id=null)
+        foreach ($cuts->where('type', CutType::Step) as $step) {
+            $newStepByOldId[$step->id] = $this->replicateCut($target, $step, null);
+        }
+        // 段階2: point を複製 (親 step の新 id へ張り替え)。
+        // 孤児 point (親不明。通常発生しない) は skip し warning ログで観測可能にする (データ破損を黙殺しない)。
+        foreach ($cuts->where('type', CutType::Point) as $point) {
+            $parentOldId = $point->parent_cut_id;
+            if ($parentOldId === null || ! isset($newStepByOldId[$parentOldId])) {
+                Log::warning('マニュアル複製: 親不明の急所カットを複製対象から除外しました', [
+                    'source_manual_id' => $source->id,
+                    'cut_id' => $point->id,
+                    'parent_cut_id' => $parentOldId,
+                ]);
+
+                continue;
+            }
+            $this->replicateCut($target, $point, $newStepByOldId[$parentOldId]->id);
+        }
+    }
+
+    /**
+     * 1 cut の複製。本文は fill、type/sort_order/parent_cut_id はサーバ導出値を forceFill。
+     * adopted_take_id / cut_length_ms は複製しない (前者は default null、後者は明示 null リセット)。
+     */
+    private function replicateCut(VideoManual $target, Cut $source, ?int $parentCutId): Cut
+    {
+        $cut = $target->cuts()->make([
+            'scene' => $source->scene,
+            'shot_type' => $source->shot_type,
+            'shooting_point' => $source->shooting_point,
+            'narration' => $source->narration,
+            'subtitle_primary' => $source->subtitle_primary,
+            'subtitle_secondary' => $source->subtitle_secondary,
+            'material_type' => $source->material_type,
+            'static_display_seconds' => $source->static_display_seconds,
+        ]);
+        $cut->forceFill([
+            'type' => $source->type,
+            'sort_order' => $source->sort_order,
+            'parent_cut_id' => $parentCutId,
+            'cut_length_ms' => null, // レンダ由来。撮影前はリセット
+        ]);
+        $cut->save();
+
+        return $cut;
+    }
+
     /** メタデータ更新 (title / category)。categoryId null は未分類化 (dissociate)。 */
     public function updateMeta(Project $project, VideoManual $manual, string $title, ?int $categoryId): VideoManual
     {
diff --git a/docs/architecture.md b/docs/architecture.md
index f46af44..12b61bd 100644
--- a/docs/architecture.md
+++ b/docs/architecture.md
@@ -69,7 +69,7 @@ ## 主要 Service (テンプレート同梱)
 | `Organization/OrganizationMembershipService` | メンバー追加・削除・ロール変更 |
 | `Project/ProjectService` | プロジェクト CRUD |
 | `Manual/CategoryService` | AI-CUE: カテゴリ create/update/reorder/delete (Project 行ロックで直列化・sort_order 専有) |
-| `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete (created_by サーバ導出・category 保存時再解決) |
+| `Manual/VideoManualService` | AI-CUE: 動画マニュアル create/updateMeta/delete/duplicate (created_by サーバ導出・category 保存時再解決。duplicate = 別名保存: 保存済み cuts を新 manual へ複製し takes/成果物/SOP は引き継がない) |
 | `Manual/ScenarioService` | AI-CUE: シナリオ (Cut 群) の document 単位保存 (VideoManual 行ロック → rendering/analyzing・楽観ロック guard → 2 段階 reconcile → version+1) + AI 解析結果の materialize (`materializeIntoLockedManual` = ロック済み前提メソッド)。§シナリオ整合の共有不変条件の準拠実装 |
 | `Manual/SourceDocumentService` | AI-CUE: SOP (SourceDocument) の保存。追記型 immutable (差し替え = 新規行)。専用 route 経路は VideoManual 行ロック + draft/ready guard、MIME は内容 sniff で再判定 (polyglot 対策) |
 | `Manual/AnalysisJobService` | AI-CUE: AI 解析の状態機械 (trigger = draft/ready→analyzing + in-flight 冪等 + 残高事前チェック / failJob = 行ロック + terminal guard の冪等失敗確定 / recoverStale = stale 回復 cron 本体) |
@@ -123,6 +123,7 @@ ## シナリオ整合の共有不変条件 (AI-CUE ドメイン規約)
   | `RenderJobService::trigger()` | status (ready→rendering のみ。scenario_version はスナップショット読み) |
   | `RenderJobService::failJob()` | status (rendering→ready のみ。kind=render に限る。preview は触らない) |
   | `RenderJobService::completeRenderIntoLockedManual()` | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ。呼び出しは RenderPipeline::finalize の terminal tx に限定 = 検出 5) |
+  | `VideoManualService::duplicate()` | cuts (別名保存。元 manual を lockForUpdate して一貫読み取り、cuts は lockForUpdate 済みの**新** manual 経由で作成)。scenario_version/status/adopted_take_id のリテラル書き込みはしない (新規行は DB default 依存) ため検出 1/2/4 は非対象 |
 
   テイク採用 API は inventory 準拠へ昇格済み (検出 4 = `adopted_take_id` の token 走査 +
   書き込み形検出)。RenderJob の状態遷移も inventory 準拠済み (検出 5 =
diff --git a/resources/js/components/features/manual/DuplicateManualDialog.svelte b/resources/js/components/features/manual/DuplicateManualDialog.svelte
new file mode 100644
index 0000000..2d1066b
--- /dev/null
+++ b/resources/js/components/features/manual/DuplicateManualDialog.svelte
@@ -0,0 +1,107 @@
+<script lang="ts">
+    import { useForm } from "@inertiajs/svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import Input from "@/components/atoms/Input.svelte";
+    import Select from "@/components/atoms/Select.svelte";
+    import FormField from "@/components/molecules/FormField.svelte";
+    import Modal from "@/components/organisms/Modal.svelte";
+    import type { CategoryOption } from "@/types/manual";
+
+    /**
+     * マニュアル複製 (別名保存) ダイアログ。保存済みシナリオを新タイトル・カテゴリで複製する。
+     * タイトルは「{元タイトル} のコピー」、カテゴリは元 category をプリフィルする。
+     */
+    interface Props {
+        open: boolean;
+        projectId: number;
+        manualId: number;
+        defaultTitle: string; // 「{元タイトル} のコピー」
+        defaultCategory: number | null; // 元 category id (null = 未分類)
+        categories: CategoryOption[];
+    }
+
+    let {
+        open = $bindable(false),
+        projectId,
+        manualId,
+        defaultTitle,
+        defaultCategory,
+        categories,
+    }: Props = $props();
+
+    // useForm はマウント時 1 回だけ初期化する (Manuals/Edit と同じ流儀。複製後は redirect で
+    // 画面遷移するため props の再供給は起きない = 初期値のみ参照で足りる)。
+    const form = useForm<{ title: string; category: string }>({
+        title: defaultTitle,
+        category: defaultCategory === null ? "" : String(defaultCategory),
+    });
+
+    // 送信本体。form 送信 (Enter) と footer ボタン onclick の双方から呼ぶ
+    // (Button atom は form 属性を持たないため footer は onclick で発火させる)。
+    function submit(): void {
+        form
+            .transform((data) => ({
+                title: data.title,
+                // category は Select 固定値 (option value=id 文字列 or "") のため Number 変換は安全 ('' のみ null)
+                category: data.category === "" ? null : Number(data.category),
+            }))
+            .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
+                // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
+                onError: () => {
+                    /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
+                },
+            });
+    }
+
+    function onFormSubmit(event: SubmitEvent): void {
+        event.preventDefault();
+        submit();
+    }
+</script>
+
+<Modal bind:open title="動画マニュアルを複製" size="sm" processing={form.processing} testId="duplicate-manual-dialog">
+    <form id="duplicate-manual-form" onsubmit={onFormSubmit} class="flex flex-col gap-4">
+        <p class="text-caption text-text-secondary">
+            シナリオ（カット）を引き継いだ新しい動画マニュアルを作成します。撮影データ・手順書（SOP）は引き継がれません。
+        </p>
+        <FormField label="タイトル" id="duplicate-title" error={form.errors.title} required>
+            {#snippet children({ id, describedBy, invalid })}
+                <Input
+                    {id}
+                    type="text"
+                    bind:value={form.title}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                    oninput={() => {
+                        if (form.errors.title) form.clearErrors("title");
+                    }}
+                />
+            {/snippet}
+        </FormField>
+        <FormField label="カテゴリ" id="duplicate-category" error={form.errors.category}>
+            {#snippet children({ id, describedBy, invalid })}
+                <Select
+                    {id}
+                    bind:value={form.category}
+                    error={invalid}
+                    aria-describedby={describedBy}
+                    testId="duplicate-category-select"
+                >
+                    <option value="">未分類</option>
+                    {#each categories as category (category.id)}
+                        <option value={String(category.id)}>{category.name}</option>
+                    {/each}
+                </Select>
+            {/snippet}
+        </FormField>
+    </form>
+    {#snippet footer()}
+        <Button variant="ghost" onclick={() => (open = false)} disabled={form.processing}>キャンセル</Button>
+        <Button
+            variant="primary"
+            loading={form.processing}
+            onclick={submit}
+            testId="duplicate-manual-confirm">複製する</Button
+        >
+    {/snippet}
+</Modal>
diff --git a/resources/js/pages/Manuals/Show.svelte b/resources/js/pages/Manuals/Show.svelte
index fac458f..5db52ee 100644
--- a/resources/js/pages/Manuals/Show.svelte
+++ b/resources/js/pages/Manuals/Show.svelte
@@ -7,11 +7,12 @@
     import DangerZone from "@/components/molecules/DangerZone.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
     import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
+    import DuplicateManualDialog from "@/components/features/manual/DuplicateManualDialog.svelte";
     import RenderPanel from "@/components/features/manual/RenderPanel.svelte";
     import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import type { SharedProps } from "@/lib/shared-props";
-    import type { AnalysisProps, RenderProps, VideoManualStatus } from "@/types/manual";
+    import type { AnalysisProps, CategoryOption, RenderProps, VideoManualStatus } from "@/types/manual";
     import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
@@ -30,13 +31,17 @@
         analysis: AnalysisProps;
         render: RenderProps;
         canManage: boolean;
+        categories: CategoryOption[];
     }
 
-    let { project, manual, analysis, render, canManage }: Props = $props();
+    let { project, manual, analysis, render, canManage, categories }: Props = $props();
 
     const shared = $derived(page.props as unknown as SharedProps);
     const appName = $derived(shared.appName ?? "");
 
+    /* ---- 複製 (別名保存) ---- */
+    let duplicateDialogOpen = $state(false);
+
     /* ---- 削除 ---- */
     let deleteDialogOpen = $state(false);
     let deleting = $state(false);
@@ -72,14 +77,23 @@
             </div>
         </div>
         {#if canManage}
-            <Button
-                variant="ghost"
-                href={`/projects/${project.id}/manuals/${manual.id}/edit`}
-                inertia
-                testId="edit-manual-button"
-            >
-                編集
-            </Button>
+            <div class="flex items-center gap-2">
+                <Button
+                    variant="ghost"
+                    onclick={() => (duplicateDialogOpen = true)}
+                    testId="duplicate-manual-button"
+                >
+                    複製
+                </Button>
+                <Button
+                    variant="ghost"
+                    href={`/projects/${project.id}/manuals/${manual.id}/edit`}
+                    inertia
+                    testId="edit-manual-button"
+                >
+                    編集
+                </Button>
+            </div>
         {/if}
     </div>
 
@@ -135,6 +149,17 @@
         {/if}
     </div>
 
+    {#if canManage}
+        <DuplicateManualDialog
+            bind:open={duplicateDialogOpen}
+            projectId={project.id}
+            manualId={manual.id}
+            defaultTitle={`${manual.title} のコピー`}
+            defaultCategory={manual.category?.id ?? null}
+            {categories}
+        />
+    {/if}
+
     <ConfirmDialog
         bind:open={deleteDialogOpen}
         title="動画マニュアル削除"
diff --git a/routes/web.php b/routes/web.php
index 91e054e..dac73a8 100644
--- a/routes/web.php
+++ b/routes/web.php
@@ -449,6 +449,10 @@
                 ->name('projects.manuals.download');
             Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
                 ->name('projects.manuals.destroy');
+            // マニュアル複製 (別名保存の実体。保存済み cuts を雛形に新タイトル・カテゴリで新規作成)。
+            // {manual} は $project->manuals() 経由 (scopeBindings) = cross-manual/cross-project は 404。
+            Route::post('/projects/{project}/manuals/{manual}/duplicate', [VideoManualController::class, 'duplicate'])
+                ->name('projects.manuals.duplicate');
         });
 
         // プロジェクトメンバー管理 (追加は payload の user_id、削除は URL の {user})。
diff --git a/tests/Architecture/NestedRouteIdorDefenseTest.php b/tests/Architecture/NestedRouteIdorDefenseTest.php
index 9977c34..a06344e 100644
--- a/tests/Architecture/NestedRouteIdorDefenseTest.php
+++ b/tests/Architecture/NestedRouteIdorDefenseTest.php
@@ -58,6 +58,7 @@ function nestedRouteIdorInventory(): array
         // シナリオ document 保存 (PUT)。{manual} は $project->manuals() 経由 (scopeBindings)
         'projects.manuals.scenario.update' => $s,
         'projects.manuals.destroy' => $s,
+        'projects.manuals.duplicate' => $s, // {manual} は $project->manuals() 経由 (保存済み cuts を複製)
         // SOP アップロード / AI 解析 / job ポーリング ({manual} は $project->manuals()、
         // {analysisJob} は $manual->analysisJobs() 経由。不整合は認可より前に 404)
         'projects.manuals.source-documents.store' => $s,
diff --git a/tests/Architecture/ScenarioWritePathInventoryTest.php b/tests/Architecture/ScenarioWritePathInventoryTest.php
index cb55497..a29c8bc 100644
--- a/tests/Architecture/ScenarioWritePathInventoryTest.php
+++ b/tests/Architecture/ScenarioWritePathInventoryTest.php
@@ -16,6 +16,10 @@
  * | AnalysisJobService::trigger() | status (draft·ready→analyzing のみ) |
  * | AnalysisJobService::failJob() | status (analyzing→ready·draft のみ。cuts 有無で決定。scenario_version は snapshot 読みのみ) |
  * | VideoManualService::displayXxxJob() | 書き込みなし (stale 判定で scenario_version を読むのみ) |
+ * | VideoManualService::duplicate() | cuts (lockForUpdate 済みの新 manual 経由で作成)。元 manual を
+ *   lockForUpdate して一貫読み取り。scenario_version/status/adopted_take_id のリテラル書き込みは
+ *   しない (新規行は DB default 依存) ため検出 1/2/4 は非対象 = allowlist 変更不要。将来 duplicate が
+ *   status を書くよう変わったら検出 2 の STATUS_WRITE_ALLOWED への追加が必要になる |
  * | RenderJobService::trigger() | status (ready→rendering のみ。scenario_version はスナップショット読み) |
  * | RenderJobService::failJob() | status (rendering→ready のみ。kind=render に限る) |
  * | RenderJobService::completeRenderIntoLockedManual() | cuts.cut_length_ms / total_length_ms / status (rendering→published のみ) |
diff --git a/tests/Feature/Projects/ManualDuplicateTest.php b/tests/Feature/Projects/ManualDuplicateTest.php
new file mode 100644
index 0000000..858dc98
--- /dev/null
+++ b/tests/Feature/Projects/ManualDuplicateTest.php
@@ -0,0 +1,284 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\CutType;
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
+use App\Models\AnalysisJob;
+use App\Models\Category;
+use App\Models\Cut;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\SourceDocument;
+use App\Models\Take;
+use App\Models\VideoManual;
+use App\Services\Manual\CutSequencer;
+use Illuminate\Support\Facades\Log;
+
+/*
+ * VideoManual 複製 (別名保存)。保存済み cuts を雛形に新タイトル・カテゴリで新規作成する。
+ * - takes / adopted_take_id / render 成果物 / source_documents / analysis_jobs は複製しない
+ * - status=draft・scenario_version=0 にリセット、created_by はサーバ導出
+ * - 認可: 編集者 = 複製可、撮影者 = 403
+ * - IDOR: cross-org / cross-project は 404、他 project category は 422、保護キー category_id は 422
+ */
+
+/** 元 manual に step2 + 各 step 配下 point1 の 6 cut シナリオを作る */
+function seedScenario(VideoManual $manual): void
+{
+    $step1 = Cut::factory()->forManual($manual)->withSortOrder(0)->create(['scene' => '手順1本文']);
+    Cut::factory()->forManual($manual)->asPointOf($step1)->withSortOrder(0)->create(['scene' => '急所1-1本文']);
+    $step2 = Cut::factory()->forManual($manual)->withSortOrder(1)->create(['scene' => '手順2本文']);
+    Cut::factory()->forManual($manual)->asPointOf($step2)->withSortOrder(0)->create(['scene' => '急所2-1本文']);
+}
+
+test('編集者は保存済みシナリオを新タイトル・カテゴリで複製できる (親子 sort_order・本文・parent 張り替え)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $srcCategory = Category::factory()->forProject($project)->create();
+    $newCategory = Category::factory()->forProject($project)->create(['name' => '複製先カテゴリ']);
+    $source = VideoManual::factory()->forProject($project)->forCategory($srcCategory)->create(['title' => '元マニュアル']);
+    seedScenario($source);
+
+    $response = $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '複製後マニュアル',
+        'category' => $newCategory->id,
+    ]);
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    $response->assertRedirect("/projects/{$project->id}/manuals/{$copy->id}");
+    $response->assertSessionHas('success');
+
+    expect($copy->title)->toBe('複製後マニュアル');
+    expect($copy->category_id)->toBe($newCategory->id);
+    expect($copy->created_by)->toBe($owner->id);
+
+    // cuts が二層 (step2 + point2 = 4 件) で複製され、sort_order・本文が一致
+    $copyCuts = $copy->cuts()->orderBy('sort_order')->orderBy('id')->get();
+    expect($copyCuts)->toHaveCount(4);
+
+    $steps = $copyCuts->where('type', CutType::Step)->values();
+    expect($steps->pluck('scene')->all())->toBe(['手順1本文', '手順2本文']);
+
+    // point の parent_cut_id が**新** step id を指す (元 id ではない)
+    $points = $copyCuts->where('type', CutType::Point)->values();
+    $newStepIds = $steps->pluck('id')->all();
+    foreach ($points as $point) {
+        expect($point->parent_cut_id)->toBeIn($newStepIds);
+    }
+    // 急所1-1 は 手順1 に、急所2-1 は 手順2 に紐づく
+    $step1 = $steps->firstWhere('scene', '手順1本文');
+    $step2 = $steps->firstWhere('scene', '手順2本文');
+    expect($points->firstWhere('scene', '急所1-1本文')?->parent_cut_id)->toBe($step1?->id);
+    expect($points->firstWhere('scene', '急所2-1本文')?->parent_cut_id)->toBe($step2?->id);
+
+    // 元 manual の cuts は不変 (件数・id 保持)
+    expect($source->cuts()->count())->toBe(4);
+});
+
+test('複製先は status=draft・scenario_version=0、step/point 両層で adopted_take_id・cut_length_ms がリセットされる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $source = VideoManual::factory()->forProject($project)->create([
+        'title' => '公開済み元',
+        'status' => VideoManualStatus::Published->value,
+        'scenario_version' => 7,
+    ]);
+    // step / point の両層に adopted_take_id・cut_length_ms を持たせる
+    $step = Cut::factory()->forManual($source)->withSortOrder(0)->create(['cut_length_ms' => 5000]);
+    $stepTake = Take::factory()->forCut($step)->create();
+    $step->forceFill(['adopted_take_id' => $stepTake->id])->save();
+    $point = Cut::factory()->forManual($source)->asPointOf($step)->withSortOrder(0)->create(['cut_length_ms' => 3000]);
+    $pointTake = Take::factory()->forCut($point)->create();
+    $point->forceFill(['adopted_take_id' => $pointTake->id])->save();
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => 'リセット確認',
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    expect($copy->status)->toBe(VideoManualStatus::Draft);
+    expect($copy->scenario_version)->toBe(0);
+
+    foreach ($copy->cuts()->get() as $cut) {
+        expect($cut->adopted_take_id)->toBeNull();
+        expect($cut->cut_length_ms)->toBeNull();
+    }
+});
+
+test('複製は category 未指定なら未分類で作成される', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    $source = VideoManual::factory()->forProject($project)->forCategory($category)->create();
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '未分類で複製',
+        'category' => null,
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    expect($copy->category_id)->toBeNull();
+});
+
+test('takes / source_documents / render_jobs / analysis_jobs は複製されない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $source = VideoManual::factory()->forProject($project)->create();
+    $step = Cut::factory()->forManual($source)->create();
+    Take::factory()->forCut($step)->create();
+    SourceDocument::factory()->forManual($source)->create();
+    RenderJob::factory()->forManual($source)->create();
+    AnalysisJob::factory()->forManual($source)->create();
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '非複製検証',
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    expect($copy->sourceDocuments()->count())->toBe(0);
+    expect($copy->renderJobs()->count())->toBe(0);
+    expect($copy->analysisJobs()->count())->toBe(0);
+    // 複製 cut 配下の takes も 0
+    $takeCount = Take::query()->whereIn('cut_id', $copy->cuts()->select('id'))->count();
+    expect($takeCount)->toBe(0);
+});
+
+test('親不明の急所カットは複製されず warning ログが出る (step とその正常 point は複製される)', function (): void {
+    Log::spy();
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $source = VideoManual::factory()->forProject($project)->create();
+    $step = Cut::factory()->forManual($source)->withSortOrder(0)->create(['scene' => '正常手順']);
+    Cut::factory()->forManual($source)->asPointOf($step)->withSortOrder(0)->create(['scene' => '正常急所']);
+    // 親を持たない孤児 point (通常発生しない異常データ)。parent_cut_id を別 manual の step へ向け複製対象外にする
+    $foreignStep = Cut::factory()->forManual(VideoManual::factory()->forProject($project)->create())->create();
+    Cut::factory()->forManual($source)->withSortOrder(1)->create([
+        'type' => CutType::Point->value,
+        'parent_cut_id' => $foreignStep->id,
+        'scene' => '孤児急所',
+    ]);
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '孤児あり複製',
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->where('id', '!=', $foreignStep->video_manual_id)->firstOrFail();
+    $scenes = $copy->cuts()->pluck('scene')->all();
+    expect($scenes)->toContain('正常手順');
+    expect($scenes)->toContain('正常急所');
+    expect($scenes)->not->toContain('孤児急所');
+
+    Log::shouldHaveReceived('warning')
+        ->withArgs(fn (string $message): bool => str_contains($message, '親不明の急所カット'))
+        ->once();
+});
+
+test('複製直後の CutSequencer が全 cuts をラベル付きで順序どおり返す', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $source = VideoManual::factory()->forProject($project)->create();
+    seedScenario($source);
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '後続接続検証',
+    ])->assertSessionHas('success');
+
+    /** @var VideoManual $copy */
+    $copy = $project->manuals()->where('id', '!=', $source->id)->firstOrFail();
+    $ordered = CutSequencer::orderedWithLabels($copy);
+
+    expect($ordered)->toHaveCount(4);
+    $labels = array_map(fn ($o): string => $o->label, $ordered);
+    expect($labels)->toBe(['手順1', '急所1-1', '手順2', '急所2-1']);
+    $scenes = array_map(fn ($o): string => $o->cut->scene, $ordered);
+    expect($scenes)->toBe(['手順1本文', '急所1-1本文', '手順2本文', '急所2-1本文']);
+});
+
+test('撮影者は複製で 403、編集者 (owner) は成功する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    $project = Project::factory()->forOrganization($organization)->create();
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $source = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($member)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '撮影者の複製',
+    ])->assertForbidden();
+    expect($project->manuals()->count())->toBe(1);
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '編集者の複製',
+    ])->assertSessionHas('success');
+    expect($project->manuals()->count())->toBe(2);
+});
+
+test('cross-org / cross-project の複製は 404 (存在を漏らさない)', function (): void {
+    [, $ownerA] = createOrganizationWithOwner('組織A');
+    [$orgB] = createOrganizationWithOwner('組織B');
+    $projectB = Project::factory()->forOrganization($orgB)->create();
+    $manualB = VideoManual::factory()->forProject($projectB)->create();
+    // {manual} が別 project (同一 org B) の場合 = scopeBindings で 404
+    $otherProjectB = Project::factory()->forOrganization($orgB)->create();
+
+    // cross-org: 組織A の owner が組織B の manual を複製 → 404
+    $this->actingAs($ownerA)->post("/projects/{$projectB->id}/manuals/{$manualB->id}/duplicate", [
+        'title' => 'x',
+    ])->assertNotFound();
+
+    // cross-project (scopeBindings): {manual} ∈ {project} 不整合 → 404
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+    $manualInOther = VideoManual::factory()->forProject($otherProject)->create();
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$manualInOther->id}/duplicate", [
+        'title' => 'x',
+    ])->assertNotFound();
+
+    expect($otherProjectB->manuals()->count())->toBe(0);
+});
+
+test('複製で他 project の category は 422・保護キー category_id 直送は 422', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $otherProject = Project::factory()->forOrganization($organization)->create();
+    $foreignCategory = Category::factory()->forProject($otherProject)->create();
+    $ownCategory = Category::factory()->forProject($project)->create();
+    $source = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => 'x',
+        'category' => $foreignCategory->id,
+    ])->assertSessionHasErrors('category');
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => 'x',
+        'category_id' => $ownCategory->id,
+    ])->assertSessionHasErrors('category_id');
+
+    // どちらも複製されていない
+    expect($project->manuals()->where('id', '!=', $source->id)->count())->toBe(0);
+});
+
+test('複製の title は必須・max:200', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $source = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => '',
+    ])->assertSessionHasErrors('title');
+
+    $this->actingAs($owner)->post("/projects/{$project->id}/manuals/{$source->id}/duplicate", [
+        'title' => str_repeat('あ', 201),
+    ])->assertSessionHasErrors('title');
+
+    expect($project->manuals()->where('id', '!=', $source->id)->count())->toBe(0);
+});
diff --git a/tests/js/pages/ManualsShow.test.ts b/tests/js/pages/ManualsShow.test.ts
index 6f83b7c..dc77d6b 100644
--- a/tests/js/pages/ManualsShow.test.ts
+++ b/tests/js/pages/ManualsShow.test.ts
@@ -1,5 +1,5 @@
 import { describe, expect, it } from "vitest";
-import { render, screen } from "@testing-library/svelte";
+import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
 import Show from "@/pages/Manuals/Show.svelte";
 import type { VideoManualStatus } from "@/types/manual";
 
@@ -15,6 +15,10 @@ const baseProps = {
     analysis: { job: null, hasDocument: false },
     render: { job: null, previewJob: null, playbackJobId: null },
     canManage: true,
+    categories: [
+        { id: 1, name: "準備作業" },
+        { id: 2, name: "仕上げ" },
+    ],
 };
 
 describe("Manuals/Show", () => {
@@ -35,22 +39,40 @@ describe("Manuals/Show", () => {
         expect(screen.getByTestId("manual-category")).toHaveTextContent("未分類");
     });
 
-    it("canManage=true なら編集・削除導線を表示する", () => {
+    it("canManage=true なら複製・編集・削除導線を表示する", () => {
         render(Show, { props: baseProps });
 
+        expect(screen.getByTestId("duplicate-manual-button")).toBeInTheDocument();
         expect(screen.getByTestId("edit-manual-button").getAttribute("href")).toMatch(
             /\/projects\/1\/manuals\/5\/edit$/,
         );
         expect(screen.getByTestId("delete-manual-button")).toBeInTheDocument();
     });
 
-    it("canManage=false なら編集・削除導線を表示しない", () => {
+    it("canManage=false なら複製・編集・削除導線を表示しない", () => {
         render(Show, { props: { ...baseProps, canManage: false } });
 
+        expect(screen.queryByTestId("duplicate-manual-button")).toBeNull();
         expect(screen.queryByTestId("edit-manual-button")).toBeNull();
         expect(screen.queryByTestId("delete-manual-button")).toBeNull();
     });
 
+    it("複製ボタン押下でダイアログが開き、タイトルは『{元タイトル} のコピー』・カテゴリは元 category をプリフィルする", async () => {
+        render(Show, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("duplicate-manual-button"));
+
+        await waitFor(() => {
+            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
+        });
+        const title = screen.getByLabelText(/タイトル/) as HTMLInputElement;
+        expect(title.value).toBe("ネジ締め作業 のコピー");
+        const category = screen.getByTestId("duplicate-category-select") as HTMLSelectElement;
+        expect(category.value).toBe("2");
+        // 送信ボタンは必須未充足でも disabled にしない (禁止事項8)
+        expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
+    });
+
     it("canManage=true (draft) は AI 解析ボタンと手順書アップロード導線を表示する", () => {
         render(Show, { props: baseProps });
 
```

### テスト結果サマリー
- composer test (parallel): 1728 tests, 1726 passed, 2 skipped, 0 failed, 7126 assertions
- 本施策の新規/更新テスト: ManualDuplicateTest (11 test) + NestedRouteIdorDefenseTest + ScenarioWritePathInventoryTest = 22 passed / 129 assertions
- composer phpstan: No errors (level 10)
- vendor/bin/pint --test: passed
- pnpm lint / typecheck: passed
- pnpm test (vitest): 566 passed (ManualsShow.test.ts に複製ボタン表示/非表示・ダイアログ prefill を追加)
- pnpm build: passed

### design system 参照 (resources/js を含むため)
- DuplicateManualDialog.svelte は `components/features/manual/` に配置 (feature 層)。import は Modal(organisms) / FormField(molecules) / Input・Select・Button(atoms) のみ = 単方向 import 準拠。
- 使用 class は既存 Manuals/Create・Edit と同一 token 群 (`text-caption` / `text-text-secondary` / `flex` / `gap-*`)。hex 直書きなし。新規 atom/SVG 追加なし。
- Button atom は `form` 属性を持たない (restProps を button に spread しない) ため、Modal footer (form 外) の送信ボタンは既存 TakeCommentDialog と同じく `onclick` で submit 関数を呼ぶ。`<form onsubmit>` は Enter キー送信用に併設。
- 送信ボタンは必須未充足でも disabled にしない (禁止事項8遵守。空タイトルは押下時サーバ 422 → FormField 表示)。
- useForm を props 初期値で 1 回初期化する箇所で Svelte の `state_referenced_locally` 警告が出るが、これは既存 Manuals/Edit.svelte と同一パターン (初期値のみ参照が意図。複製後は redirect で画面遷移するため props 再供給なし)。
