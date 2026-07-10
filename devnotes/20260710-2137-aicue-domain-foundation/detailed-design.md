# 詳細設計: aicue-domain-foundation（AI-CUE フェーズ1・データ基盤 + Category/VideoManual）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）
**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業を起点に AI が教材設計し撮影を指示する。v1 スコープ: 字幕のみ / 撮影 PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（AGENTS.md より）
1. テストなしの実装完了報告（不変条件は Architecture/Feature テスト登録まで含めて「実装済み」）
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作（`migrate:fresh` 等）をエージェント判断で実行
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）
5. LLM 呼び出しの Prism 直呼び（`app/Prompts/` factory 経由のみ）
6. prompt 文字列のコード直書き（`resources/prompts/*.yaml`）
7. 操作系 POST 応答の `redirect()->intended()`（`back()->with(...)` で完結）
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示）

### セキュリティ不変条件（Architecture テストで強制）
tenant キー不信 / 子は親に属する（認可前 404・NestedRouteIdorDefenseTest 登録）/ cross-org 不可 / 権限は `laratrust_team_id` 明示 / PII は CipherSweet（本フェーズは PII カラムなし）。

### コーディングルール
- **PHPStan level 10** 必須（`composer phpstan`）/ **Pest** + **RefreshDatabase** グローバル適用（個別 `DatabaseTransactions` 禁止）/ `--parallel`
- テストデータは Factory 生成。新モデルは Factory 作成 + `docs/architecture.md`・`docs/factories.md` 追記必須
- **Inertia PC 画面の props は typed array（`@return list<array{...}>` / `array{...}` PHPDoc）+ 対応 TS interface**（本アプリの実規約。`Projects/Show` 見本。spatie Data / JsonResource は API v1 用で本フェーズは不使用）
- 保護キーは forceFill / relation で明示代入。Controller 薄く・transaction は Service 内
- `declare(strict_types=1)` + 日本語コメント。`Webmozart\Assert\Assert` で null 安全化
- PHP 8.4 + Laravel 12(13.x) + Svelte 5 runes + Inertia + Tailwind4 + TypeScript。アイコンは `@lucide/svelte`

## 概念設計リファレンス
[conceptual-design.md](conceptual-design.md)（Codex 概念設計レビュー APPROVED・Round 5）。確定仕様は `doc/10_実装仕様.md`（§10.8 が §10.1〜§10.7 に優先）。

## 見本トレース元（実コード）
| 要素 | 見本ファイル |
|---|---|
| Model | `app/Models/Item.php` |
| Controller（書き込み系のみ・一覧は親 show 内包）| `app/Http/Controllers/Projects/ItemController.php` |
| Policy（親委譲）| `app/Policies/ItemPolicy.php` |
| FormRequest（ProhibitsProtectedKeys）| `app/Http/Requests/Projects/StoreItemRequest.php` / `UpdateItemRequest.php` |
| Factory（親連鎖 + forProject）| `database/factories/ItemFactory.php` |
| 保護キー single source | `app/Support/Security/MassAssignmentProtectedKeys.php` |
| web route（scopeBindings）| `routes/web.php`（items 節）|
| IDOR inventory | `tests/Architecture/NestedRouteIdorDefenseTest.php`（`$s = ScopeBindings`）|
| Inertia 一覧 props | `app/Http/Controllers/Projects/ProjectController.php::show` / `resources/js/pages/Projects/Show.svelte` |

---

## 施策一覧

| # | 施策名 | 主な変更ファイル | 優先度 | Tier |
|---|--------|------------|--------|------|
| 1 | Enum 定義（5 種）| `app/Enums/Manual/*.php` | High | 基盤 |
| 2 | マイグレーション（categories/video_manuals/source_documents/cuts/takes + cuts FK 後付け）| `database/migrations/*` | High | A+B |
| 3 | 保護キー追記 | `app/Support/Security/MassAssignmentProtectedKeys.php` | High | 基盤 |
| 4 | Model（5）| `app/Models/{Category,VideoManual,SourceDocument,Cut,Take}.php` | High | A+B |
| 5 | Factory（5）+ docs 追記 | `database/factories/*` / `docs/{architecture,factories}.md` | High | A+B |
| 6 | FormRequest（Category Store/Update/Reorder・VideoManual Store/Update）| `app/Http/Requests/Projects/*` | High | A |
| 7 | Service（CategoryService・VideoManualService）| `app/Services/*` | High | A |
| 8 | Policy（Category/VideoManual、親委譲）| `app/Policies/*` | High | A |
| 9 | Controller（Category・VideoManual 書き込み系）+ 権限 rename | `app/Http/Controllers/Projects/*` | High | A |
| 10 | route + IDOR inventory 登録 | `routes/web.php` / `tests/Architecture/NestedRouteIdorDefenseTest.php` | High | A |
| 11 | Inertia props 拡張（Projects/Show に manuals/categories 内包）+ 新規 create/show/edit 画面 | `ProjectController::show` / `resources/js/pages/Projects/*` | High | A |
| 12 | Feature テスト + Vitest | `tests/Feature/*` / `tests/js/*` | High | A |

---

## 施策1: Enum 定義

### 変更箇所
新規 `app/Enums/Manual/`（既存 `app/Enums/{OAuth,Inquiry,Security,...}` の名前空間分割に倣う）:
- `VideoManualStatus`（draft/analyzing/ready/rendering/published）
- `CutType`（step/point）
- `ShotType`（hiki/yori）
- `TakeStatus`（uploading/processing/ready/failed）
- `MaterialType`（video/still）

いずれも `: string` backed enum。`JobStatus` は本フェーズ対象外（analysis_jobs/render_jobs 導入フェーズで追加）。

### 変更後コード（例）
```php
<?php
declare(strict_types=1);
namespace App\Enums\Manual;

/** 動画マニュアルの状態遷移 (10.2)。遷移メソッドは後続フェーズ。 */
enum VideoManualStatus: string
{
    case Draft = 'draft';
    case Analyzing = 'analyzing';
    case Ready = 'ready';
    case Rendering = 'rendering';
    case Published = 'published';
}
```

### PHPStan 適合チェック
- [x] backed enum は Model `casts()` で `VideoManualStatus::class` を指定 → 型は enum に確定
- [x] 配列返却なし

### テスト計画
- [ ] `casts()` 経由で enum に解決されることは Model の Feature テストで間接検証（作成→再取得で `status === VideoManualStatus::Draft`）

### リスク
- なし（定義のみ）。

---

## 施策2: マイグレーション

### 変更箇所
`database/migrations/` に新規 6 ファイル（循環 FK 対応で cuts FK 後付けを分離）。作成順 = categories → video_manuals → source_documents → cuts（adopted_take_id は FK なし nullable）→ takes → cuts への adopted_take_id FK 追加。

### スキーマ（§10.1 + §10.8 確定）
**categories**【Tier A】
- `project_id` `foreignId()->constrained()->cascadeOnDelete()`（NOT NULL・protected）
- `name` string(50)、`sort_order` int
- **複合ユニーク** `unique(['project_id','name'])`

**video_manuals**【Tier A】
- `project_id` constrained cascade（protected）、`created_by` `foreignId()->constrained('users')`（protected）
- `category_id` `foreignId()->nullable()->constrained()->nullOnDelete()`（protected・§10.8-8）
- `title` string(200)、`status` string（default `draft`）
- `scenario_version` int default 0（§10.8-2）、`total_length_ms` int nullable

**source_documents**【Tier B】
- `video_manual_id` constrained cascade（protected）
- `file_path` string、`original_name` string、`mime` string、`size_bytes` bigint
- `extracted_json` json nullable

**cuts**【Tier B】（1st migration: 自己参照/循環 FK はどちらも張らない）
- `video_manual_id` constrained cascade（protected）
- `parent_cut_id` `unsignedBigInteger()->nullable()`（**この時点で FK は張らない**・protected。自己参照 FK は DB 依存で CREATE 内不安定になりうるため後付けに分離）
- `adopted_take_id` `unsignedBigInteger()->nullable()`（**この時点で FK は張らない**・protected）
- `type` string、`shot_type` string、`material_type` string nullable、`sort_order` int
- `scene` text、`shooting_point` text nullable、`narration` text、`subtitle_primary` string(100) nullable、`subtitle_secondary` text
- `static_display_seconds` int nullable、`cut_length_ms` int nullable

**takes**【Tier B】
- `cut_id` constrained cascade（protected）
- `client_take_id` string(26)、**`unique(['cut_id','client_take_id'])`**（同期冪等キー）
- `video_path` string、`thumbnail_path` string nullable、`size_bytes` bigint
- `duration_ms` int nullable、`status` string、`comment` text nullable、`captured_at` timestamp nullable、`sort_order` int

**cuts への FK 後付け**（6th migration・自己参照と循環 FK をまとめて張る）
```php
Schema::table('cuts', function (Blueprint $t): void {
    $t->foreign('parent_cut_id')->references('id')->on('cuts')->nullOnDelete();
    $t->foreign('adopted_take_id')->references('id')->on('takes')->nullOnDelete();
});
```
（down は 2 つの `dropForeign` → 各テーブル drop の逆順）

### 波及変更
- TypeScript 型定義: 施策11 で追加（一覧/フォーム props）
- テストファイル: 施策12

### PHPStan 適合チェック
- [x] マイグレーションは型判定対象薄い（`Blueprint` クロージャ）
- [x] enum カラムは string 格納（アプリ層 cast）

### テスト計画
- [ ] マイグレーション実行は `RefreshDatabase` により全 Feature テストで暗黙検証。個別の schema assert は行わない（Item 規約に倣う）

### リスク
- 循環 FK の down 順序。後付け migration の `down()` で **`parent_cut_id` / `adopted_take_id` の両 FK を明示 `dropForeign`** し、その後「takes → cuts → source_documents → video_manuals → categories」の逆順で各テーブルを drop する（各 migration の `down()` に分担）。

---

## 施策3: 保護キー追記

### 変更箇所
`app/Support/Security/MassAssignmentProtectedKeys.php::all()` に追記（tenant/ownership FK ブロック）:
`video_manual_id` / `cut_id` / `parent_cut_id` / `category_id` / `adopted_take_id` / `created_by` / `source_document_id`。
（`ticket_reservation_id` はジョブテーブル導入フェーズで追記）

### 波及変更
- `MassAssignmentSafetyTest`（出口防御）が全モデルの `$fillable` に保護キーが無いことを自動検証 → 追記だけで担保
- 各 FormRequest の `protectedKeyMissingRules()` に自動反映（missing rule）

### PHPStan 適合チェック
- [x] `list<string>` 返却は変わらず

### テスト計画
- [ ] `MassAssignmentSafetyTest` が新モデルを走査して green（既存テストが自動でカバー）
- [ ] 保護キー 422 は施策12 の Feature で明示（`category_id` 直送で 422）

### リスク
- `created_by` は他モデル（既存）と名称衝突しないか要確認。既存は `created_by_user_id`。本ドメインは `created_by`（§10.1 準拠）。衝突しないが命名が既存と非対称なので、`MassAssignmentProtectedKeys` に両方を残す（`created_by` 追加）。

---

## 施策4: Model

### 変更箇所
`app/Models/` に 5 モデル。`Item` の型注釈規約を踏襲。

### 変更後コード（VideoManual 例）
```php
<?php
declare(strict_types=1);
namespace App\Models;

use App\Enums\Manual\VideoManualStatus;
use Database\Factories\VideoManualFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * VideoManual (Project 配下の動画マニュアル)。
 * project_id / created_by / category_id は protected のため $fillable 外。
 * category は project スコープで解決した Category を associate する (payload 直代入しない)。
 *
 * @property int $id
 * @property int $project_id
 * @property int|null $category_id
 * @property int $created_by
 * @property string $title
 * @property VideoManualStatus $status
 * @property int $scenario_version
 * @property int|null $total_length_ms
 */
class VideoManual extends Model
{
    /** @use HasFactory<VideoManualFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = ['title'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => VideoManualStatus::class];
    }

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // 注: Project 側は $project->manuals() で本モデルを参照する（relation 名を manuals に統一）。

    /** @return HasMany<SourceDocument, $this> */
    public function sourceDocuments(): HasMany
    {
        return $this->hasMany(SourceDocument::class);
    }

    /** @return HasMany<Cut, $this> */
    public function cuts(): HasMany
    {
        return $this->hasMany(Cut::class);
    }
}
```
- **relation 名の統一（Round 1 Critical 対応）**: `Project` 側の VideoManual への relation は **`manuals()`** に統一（route パラメータ `{manual}` の scopeBindings 推論 `$project->manuals()` と一致させ IDOR 防御を確実にする）。`Category` 側からの relation も `Category::videoManuals()` ではなく **`Category::manuals()`** に統一。設計内・Controller・Service・Query・TS すべて `manuals` に統一。
- `Category`: `$fillable=['name']`（sort_order は fillable 外＝Service のみが設定）。`project()` BelongsTo、`manuals()` HasMany。
- `SourceDocument`: `$fillable=['file_path','original_name','mime','size_bytes']`、`casts=['extracted_json'=>'array']`、`videoManual()` BelongsTo。
- `Cut`: `$fillable`= 本文フィールド（scene/shot_type…、ただし enum は casts）。`videoManual()` / `parent()`（self BelongsTo）/ `takes()` HasMany / `adoptedTake()` BelongsTo(Take)。`casts`: type/shot_type/material_type。
- `Take`: `$fillable=['client_take_id','video_path','thumbnail_path','size_bytes','duration_ms','comment','captured_at']`、`casts=['status'=>TakeStatus::class,'captured_at'=>'datetime']`、`cut()` BelongsTo。

### 波及変更
- TypeScript: なし（Model 段階）

### PHPStan 適合チェック
- [x] `@property` PHPDoc で enum/nullable を明示
- [x] relation generics `BelongsTo<Parent,$this>` / `HasMany<Child,$this>`
- [x] `casts()` メソッド形式（`@return array<string,string>`）

### テスト計画
- [ ] Factory + relation の往復（作成→relation 取得）を Feature で検証

### リスク
- `sort_order` を Category の `$fillable` から外すため、Factory との整合（施策5 で state 経由の属性注入）に注意。
- **relation 名 `manuals()` の一貫性**が IDOR（scopeBindings）の前提。Project/Category 双方で `manuals()` に統一し、`videoManuals()` は使わない。

---

## 施策5: Factory + docs

### 変更箇所
`database/factories/` に 5 Factory（`ItemFactory` の親連鎖 + `forProject`/`forManual` 規約）。`docs/architecture.md`（モデル一覧）と `docs/factories.md`（Factory 台帳）に追記。

### 変更後コード（CategoryFactory 例）
```php
/** @extends Factory<Category> */
class CategoryFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'sort_order' => 0, // sort_order は $fillable 外のため create 時に forceFill 相当で入る
        ];
    }

    public function forProject(Project $project): static
    {
        return $this->state(fn () => ['project_id' => $project->id]);
    }
}
```
- `VideoManualFactory`: `project_id`→ProjectFactory、`created_by`→UserFactory、`category_id`→null（`forCategory(Category)` state で付与）、`title`、`status`=draft、`scenario_version`=0。
- `SourceDocumentFactory` / `CutFactory` / `TakeFactory`: 親連鎖（`video_manual_id`/`cut_id`）。`TakeFactory` は `client_take_id`=`(string) Str::ulid()`。`CutFactory` は type/shot_type を enum の値で。

### 波及変更
- `docs/architecture.md` / `docs/factories.md`（必須）

### PHPStan 適合チェック
- [x] `@extends Factory<Model>` / `@return array<string,mixed>`

### テスト計画
- [ ] 各 Factory が単体で `create()` 可能（施策12 の各 Feature が親連鎖を暗黙検証）

### リスク
- Factory は保護キー（project_id 等）を definition で直接指定するが、これは **Factory の属性注入であり、アプリの HTTP 入力経路の mass-assignment 制約とは別境界**（Item Factory と同じ。Factory は内部的にモデル属性を直接設定できるテスト用機構で、`$fillable`/`ProhibitsProtectedKeys` が守る「クライアント payload からの代入」とは無関係。実運用コードは relation 経由で親 FK を代入）。

---

## 施策6: FormRequest

### 変更箇所
`app/Http/Requests/Projects/`:
- `StoreCategoryRequest` / `UpdateCategoryRequest`（rules: `name` required string max:50 + **project-scoped `Rule::unique('categories','name')->where('project_id',$projectId)`**。Update は `->ignore($category->id)` で self 除外。**sort_order は受けない**）+ `ProhibitsProtectedKeys`。通常入力の重複は Request で 422、並行競合は Service のロック後再検査（`assertNameUnique`）で 422 に先回り（複合 unique の QueryException=500 を回避）。
- `ReorderCategoriesRequest`（rules: `order` = `array` required、`order.*` = `integer`）。集合一致検証は Service（Project ロック後）で行うため Request は形式のみ
- `StoreVideoManualRequest` / `UpdateVideoManualRequest`（rules: `title` required string max:200、`category` = `nullable` + `Rule::exists('categories','id')->where('project_id', $projectId)`）+ `ProhibitsProtectedKeys`

`category` の exists スコープに使う `project_id` は route から解決（`$this->route('project')`）。

### 変更後コード（StoreVideoManualRequest 例）
```php
/** @return array<string, list<mixed>> */
public function rules(): array
{
    $project = $this->route('project');
    $projectId = $project instanceof Project ? $project->id : 0;

    return array_merge([
        'title' => ['required', 'string', 'max:200'],
        'category' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('project_id', $projectId)],
    ], $this->protectedKeyMissingRules());
}
```

### 入力名の境界（Round 1 Critical 対応）
- HTTP 入力名は **`category`**（値は category id、null 可）。保護キー `category_id` は `protectedKeyMissingRules()` により直送で 422。
- Controller は `$request->validated('category')`（`?int`）のみ参照し、**`validated('category_id')` は参照しない**（取り違え防止）。Service 引数も `?int $categoryId`（呼出し側で `validated('category')` を渡す）。この命名対応を施策7・9 のコメントに明記し、Feature テストで「`category_id` 送信 → 422」「`category` 送信 → 成功」を両方固定する。

### 波及変更
- なし（Request 層で完結）。`category_id` は `protectedKeyMissingRules()` に自動含有 → 直送で 422

### PHPStan 適合チェック
- [x] `@return array<string, list<mixed>>`
- [x] route パラメータの型を `instanceof` で絞る（null 安全）

### テスト計画
- [ ] `category_id` 直送 → 422（保護キー）
- [ ] 他 project の `category` id → exists で 422
- [ ] `sort_order` を Category Store に送っても無視（rules 外）

### リスク
- exists の `where('project_id',...)` は検証時点の保証。**保存時再解決を Service で必須化**（施策7）。

---

## 施策7: Service

### 変更箇所
`app/Services/`:
- `CategoryService`（create / update / reorder / delete）
- `VideoManualService`（create / updateMeta / delete。create は `created_by` をサーバ導出）

**Service 境界の不変条件**: 全メソッドはロック取得後に**対象の子（Category/VideoManual）を親 relation から再解決**してから操作する。route binding 以外の経路（別 Service・将来のバッチ等）から cross-project の子を渡されても `firstOrFail`（→404）で拒否し、DB を変更しない。

### 設計（並行制御 = Project 行ロック）
全操作は 1 transaction 内で先頭に対象 **Project を `lockForUpdate()`**（`Project::whereKey($project->id)->lockForUpdate()->firstOrFail()`）してから実行。これで create/delete/reorder + 末尾採番を project 単位に直列化。

全操作で **ロック済み `$locked` の relation のみ**を使い、stale な `$project` の relation は使わない。
```php
final class CategoryService
{
    public function create(Project $project, string $name): Category
    {
        return DB::transaction(function () use ($project, $name): Category {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $this->assertNameUnique($locked, $name, null); // ロック後の重複再検査 → 422
            $next = (int) $locked->categories()->max('sort_order') + 1;
            $category = $locked->categories()->make(['name' => $name]);
            $category->forceFill(['sort_order' => $next])->save();
            return $category;
        });
    }

    public function update(Project $project, Category $category, string $name): Category
    {
        return DB::transaction(function () use ($project, $category, $name): Category {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            $lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail();
            $this->assertNameUnique($locked, $name, $lockedCategory->id); // self 除外の重複再検査
            $lockedCategory->fill(['name' => $name])->save();
            return $lockedCategory;
        });
    }

    /** @param list<int> $orderedIds */
    public function reorder(Project $project, array $orderedIds): void
    {
        DB::transaction(function () use ($project, $orderedIds): void {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $existing = $locked->categories()->pluck('id')->all();
            // 集合一致 (distinct・過不足なし)。不一致は 422。
            sort($existing);
            $sorted = $orderedIds;
            sort($sorted);
            if (count($orderedIds) !== count(array_unique($orderedIds)) || $sorted !== $existing) {
                throw ValidationException::withMessages(['order' => __('categories.reorder_mismatch')]);
            }
            if ($orderedIds === []) {
                return; // 空 (0 件 project) は再採番不要。不正 CASE 式生成を防ぐ
            }
            // N+1 回避: CASE 式で 1 クエリ一括更新（ロック保持時間短縮）。id は Request で integer 検証済み
            $cases = collect($orderedIds)
                ->map(fn (int $id, int $i): string => 'WHEN '.(int) $id.' THEN '.$i)
                ->implode(' ');
            $locked->categories()->whereIn('id', $orderedIds)
                ->update(['sort_order' => DB::raw("CASE id {$cases} END")]);
        });
    }

    public function delete(Project $project, Category $category): void
    {
        DB::transaction(function () use ($project, $category): void {
            $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            // 子は親に属する: ロック済み親 relation から再解決 (cross-project は 404)
            $lockedCategory = $locked->categories()->whereKey($category->id)->firstOrFail();
            $lockedCategory->delete(); // video_manuals.category_id は FK nullOnDelete で未分類化
        });
    }

    /** ロック取得後の重複再検査 (複合 unique 違反の QueryException=500 を 422 へ先回り) */
    private function assertNameUnique(Project $locked, string $name, ?int $excludeId): void
    {
        $exists = $locked->categories()->where('name', $name)
            ->when($excludeId !== null, fn ($q) => $q->whereKeyNot($excludeId))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['name' => __('categories.name_taken')]);
        }
    }
}
```
`VideoManualService::create` は `?int $categoryId` を **ロック済み project relation から再解決**して associate（引数名は Service 内 `$categoryId`、呼出し側は `validated('category')` を渡す）:
```php
public function create(Project $project, string $title, ?int $categoryId, int $userId): VideoManual
{
    return DB::transaction(function () use ($project, $title, $categoryId, $userId): VideoManual {
        $locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
        $manual = $locked->manuals()->make(['title' => $title]);
        $manual->forceFill(['created_by' => $userId])->save();
        if ($categoryId !== null) {
            $category = $locked->categories()->whereKey($categoryId)->firstOrFail(); // 保存時再解決
            $manual->category()->associate($category)->save();
        }
        return $manual;
    });
}
```
`updateMeta(Project, VideoManual, string $title, ?int $categoryId)` / `delete(Project, VideoManual)` も**ロック後に対象 manual を親 relation から再解決**する（子は親に属する・cross-project は 404）:
```php
$locked = Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
$lockedManual = $locked->manuals()->whereKey($manual->id)->firstOrFail(); // 再解決
// updateMeta: $lockedManual->fill(['title'=>$title]); category は再解決 associate / null で dissociate; save
// delete:     $lockedManual->delete();
```
category は `$locked->categories()->whereKey($categoryId)->firstOrFail()` で再解決 associate、null 指定で `dissociate()`（未分類化）。VideoManual は unique 制約が無いため重複検査は不要。

### 波及変更
- なし（Service 内で完結）

### PHPStan 適合チェック
- [x] クロージャ返却型注釈、`firstOrFail()` で null 除去
- [x] `@param list<int>`

### テスト計画
- [ ] reorder の集合不一致 422 / 並び反映
- [ ] create の末尾採番（連番）
- [ ] VideoManual create/updateMeta の category 再解決（改竄 id は firstOrFail で 404 相当 → Controller で 404）
- [ ] Category 削除で manual 未分類化

### 例外方針（Round 1 Warning 対応）
- `Project::...->firstOrFail()` / `$project->categories()->...->firstOrFail()` の `ModelNotFoundException` は **Laravel 既定の例外ハンドラが 404 に変換**（500 化しない）。この既定挙動に依拠する。
- reorder の集合不一致は `ValidationException` → **422**。
- 使い分け: 「URL の親子不整合・存在しないリソース」= 404（firstOrFail 既定）、「payload の内容不正」= 422（ValidationException）。cross-project の `category` id は施策6 の exists で先に 422、Service の firstOrFail は保存時再解決の二重防御。

### リスク
- CASE 式の `DB::raw` は id が integer 検証済み（Request で `integer`）である前提。文字列連結だが値は int にキャスト済みのため SQL インジェクション経路にならない（PHPStan/実装で `(int)` を明示）。

---

## 施策8: Policy（親委譲）

### 変更箇所
`app/Policies/CategoryPolicy` / `VideoManualPolicy`。`ItemPolicy` と同じく `ProjectPolicy` 依存注入・親委譲。

- `view`: `projectPolicy->view`
- `create`（Project 引数）/ `reorder`（Project 引数）: `projectPolicy->update($user, $project)`
- `update` / `delete`（Category 引数）: `$category->project !== null && projectPolicy->update($user, $category->project)`

権限表（§10.5・概念設計 §8）: 編集者(project_admin)=write 全可、撮影者(project_member)=show のみ。`ProjectPolicy::update` が編集者判定を担う（既存）。**reorder は対象 Category が単一でないため専用シグネチャ** `CategoryPolicy::reorder(User $user, Project $project)` を定義し、Controller は `Gate::authorize('reorder', [Category::class, $project])`（`create` と同じ「対象なし + Project 追加引数」パターン。`ItemPolicy::create` 見本）。

### 波及変更
- `AuthServiceProvider` / policy auto-discovery（Laravel 12 は規約 discovery。名前空間一致で自動。要確認：明示登録が必要なら追記）

### PHPStan 適合チェック
- [x] Policy メソッドは bool 返却、`$model->project` の null 安全（`!== null &&`）

### テスト計画
- [ ] 撮影者は store/update/destroy/reorder で 403、show は 200
- [ ] 編集者は全 write 可

### リスク
- ロール rename（project_admin=編集者 / project_member=撮影者）は §10.5 では「rename のみ」。既存 seeder/permission との整合を施策9 で確認（表示名だけ変え permission キーは既存流用の可能性 → 詳細は実装時に既存ロール定義を確認）。

---

## 施策9: Controller + 権限 rename

### 変更箇所
`app/Http/Controllers/Projects/`:
- `CategoryController`（store / update / destroy / reorder）
- `VideoManualController`（**create** / store / show / edit / update / destroy）

`ItemController` と同一構造: `ResolvesCurrentOrganization` + `resolveOrganizationProject`（認可前 404）+ `Gate::authorize` + Service 委譲 + `back()->with(...)`（create/show/edit は `Inertia::render`）。

- **`create`**（route `projects.manuals.create` に対応・Round 2 Critical 対応）: `Gate::authorize('create', [VideoManual::class, $project])` → `Inertia::render('Manuals/Create', ['project' => ..., 'categories' => $categoryOptions])`（カテゴリセレクトの選択肢 + 未分類を props で供給）。typed-array PHPDoc（`categories: list<array{id:int,name:string}>`）。撮影者は 403、Feature テストで固定。
- **`show`/`edit`** は `Inertia::render('Manuals/Show'|'Manuals/Edit', [...])`（edit は categories props を含む）。
- **`store`** は `redirect()->route('projects.manuals.show', ...)->with('success', ...)`（作成は新規リソースへ遷移が自然。flash キーは既存 Item と同じ `success` に統一）。Service に `$request->validated('category')`（?int）を `$categoryId` として渡す。
- **`update`/`destroy`** は `back()->with('success', ...)`。`redirect()->intended()` は使わない（禁止事項7）。

### 波及変更
- ルート（施策10）、Svelte 画面（施策11）

### PHPStan 適合チェック
- [x] `$request->validated(...)` の型を `Assert` で絞る（Item 見本準拠）
- [x] Service 返却型明示

### テスト計画
- [ ] cross-org project → 404、cross-project child → 404（scopeBindings）
- [ ] 各アクションの正常系 + 権限

### リスク
- ロール rename 範囲。permission キー自体を rename すると既存テストに波及。**表示名のみ rename・permission キーは既存（project 編集権）を流用**の方針（§10.5「rename のみ」に沿う）。実装時に既存ロール定義（`database/seeders` / Laratrust 設定）を確認して確定。

---

## 施策10: route + IDOR inventory

### 変更箇所
`routes/web.php`（projects group 内、items の直後に追加）:
```php
// Category (Project 配下・編集者のみ)。一覧は projects.show 内包。
Route::post('/projects/{project}/categories', [CategoryController::class, 'store'])
    ->name('projects.categories.store');
Route::patch('/projects/{project}/categories/reorder', [CategoryController::class, 'reorder'])
    ->name('projects.categories.reorder');
Route::scopeBindings()->group(function (): void {
    Route::patch('/projects/{project}/categories/{category}', [CategoryController::class, 'update'])
        ->name('projects.categories.update');
    Route::delete('/projects/{project}/categories/{category}', [CategoryController::class, 'destroy'])
        ->name('projects.categories.destroy');
});
// VideoManual
Route::get('/projects/{project}/manuals/create', [VideoManualController::class, 'create'])
    ->name('projects.manuals.create');
Route::post('/projects/{project}/manuals', [VideoManualController::class, 'store'])
    ->name('projects.manuals.store');
Route::scopeBindings()->group(function (): void {
    Route::get('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'show'])
        ->name('projects.manuals.show');
    Route::get('/projects/{project}/manuals/{manual}/edit', [VideoManualController::class, 'edit'])
        ->name('projects.manuals.edit');
    Route::patch('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'update'])
        ->name('projects.manuals.update');
    Route::delete('/projects/{project}/manuals/{manual}', [VideoManualController::class, 'destroy'])
        ->name('projects.manuals.destroy');
});
```
`reorder` は `{category}` を取らないため 2+param ではなく IDOR 対象外（`{project}` のみ）。

`tests/Architecture/NestedRouteIdorDefenseTest.php` の inventory に追記（全て `$s = ScopeBindings`）:
`projects.categories.update` / `projects.categories.destroy` / `projects.manuals.show` / `projects.manuals.edit` / `projects.manuals.update` / `projects.manuals.destroy`。

> `Route::model('manual', VideoManual::class)` の暗黙バインド名は `manual`。scopeBindings が `$project->manuals()` を要求するため、`Project` に `manuals()` = `hasMany(VideoManual::class)` を定義（施策4 で `videoManuals()` としたが、route パラメータ名 `manual` に合わせ **relation 名も `manuals()`** に統一するか、`Route::scopeBindings` の親子解決に用いる relation 名を明示。実装では relation 名 `manuals()` を採用）。

### 波及変更
- IDOR テスト inventory（必須・stale 検出テストが逆方向も固定）

### PHPStan 適合チェック
- [x] route 定義は型対象外

### テスト計画
- [ ] `NestedRouteIdorDefenseTest`（2+param route が inventory に分類済み・green）
- [ ] cross-project の {manual}/{category} → 404

### リスク
- relation 名と route パラメータ名の一致（`manual` ↔ `manuals()`）。scopeBindings は複数形 relation を推論するため `manuals()` に統一。

---

## 施策11: Inertia props + Svelte 画面

### 変更箇所
- `ProjectController::show` に manuals（paginate）+ categories を内包。GET クエリ `?category=&status=&q=` で絞り込み。
- `resources/js/pages/Projects/Show.svelte`（動画一覧セクション + カテゴリ管理 + フィルタ UI 追加）
- 新規 `resources/js/pages/Manuals/{Create,Show,Edit}.svelte`
- `resources/js/types` に TS interface 追加

### props shape（typed array・本アプリ規約・meta shape も固定）
```php
// ProjectController::show 追加分。private メソッドで PHPDoc shape を固定。
/**
 * @return array{
 *   data: list<array{id:int,title:string,status:string,category:array{id:int,name:string}|null,created_at:string}>,
 *   meta: array{current_page:int,last_page:int,per_page:int,total:int}
 * }
 */
private function manualRows(Project $project, array $filters): array { /* paginate → 明示整形 */ }
```
```php
'manuals' => $this->manualRows($project, $filters),
'categories' => $project->categories()->orderBy('sort_order')->get()
    ->map(fn (Category $c): array => ['id' => $c->id, 'name' => $c->name])->values()->all(),
'manualFilters' => ['category' => $filters['category'], 'status' => $filters['status'], 'q' => $filters['q']],
```
未分類は `category => null`。フロントは `category?.name ?? '未分類'`、フィルタ選択肢に「未分類」（値は `'uncategorized'` sentinel、サーバで `whereNull('category_id')` に変換）。

TS interface（`resources/js/types/manual.ts`）— status は literal union（UI 分岐漏れ検出）:
```ts
export type VideoManualStatus = 'draft' | 'analyzing' | 'ready' | 'rendering' | 'published';
export interface PaginationMeta { current_page: number; last_page: number; per_page: number; total: number; }
export interface ManualListItem {
  id: number; title: string; status: VideoManualStatus;
  category: { id: number; name: string } | null; created_at: string;
}
export interface CategoryOption { id: number; name: string; }
```
PHP enum（`VideoManualStatus`）と TS union の値集合が一致することを保つ（乖離検知の仕組みは本フェーズでは手動確認・将来 enum→TS 生成を検討）。

### 波及変更
- TS 型（新規）、Show.svelte props interface 拡張

### PHPStan 適合チェック
- [x] props は `@var array{...}` / private メソッドの `@return list<array{...}>`（`memberRows` 見本）
- [x] paginate 整形は明示 map

### テスト計画（Vitest）
- [ ] Show.svelte が manuals/categories/フィルタを描画、未分類表示、disabled 不使用（押下時エラー）
- [ ] Manuals/Create/Edit のフォーム（category セレクトに未分類）

### リスク
- paginate の props 型と Inertia partial reload（フィルタ変更時）。`router.get` で `only:['manuals']` 部分更新。

---

## 施策12: Feature テスト + Vitest

### テスト計画（Pest Feature）
`tests/Feature/Projects/`:
- **保護キー**: `category_id`/`created_by`/`video_manual_id` 等を各 Store に直送で 422。`category` 送信は成功（入力名境界の両面固定）
- **category 別名**: 他 project の `category` id → 422（exists）、保存時再解決の固定（in-project 指定で associate、DB 改竄不可）
- **Category 名重複**: 同一 project 内 同名 create → 422（Request unique）、update で他カテゴリと同名 → 422、別 project の同名は許容
- **cross-org / cross-project 404**: 別 org の project / 別 project の manual・category を URL 指定 → 404（resolveOrganizationProject + scopeBindings）。**Category update/destroy の cross-project → 404** も明示
- **権限**: 編集者は create画面/store/update/destroy/reorder 200/302、撮影者は 403、show/一覧は両者 200。**Manual create 画面の認可（撮影者 403）と categories props 供給**を固定
- **Category 削除で未分類化**: 削除後 manual.category_id が null
- **reorder**: 並び反映（sort_order 連番）、集合不一致（欠落・重複・余剰）で 422、**空 project（0 件）の reorder は成功（no-op）**
- **並行直列化（統合）**: Project 行ロックで create/reorder が直列化（`DB::transaction` + 2 経路の擬似並行、または少なくとも「reorder 後に create すると末尾採番が最大+1」を検証）※Codex Round 5 の完了条件
- **manuals 一覧フィルタ**: category/status/q で絞り込み結果（未分類フィルタ含む）
- **Service 境界防御（route とは別）**: 別 project の Category/VideoManual を Service の update/delete に直接渡すと `ModelNotFoundException`（→404）となり、対象が変更・削除されないことを検証（親ロック後の子再解決の固定）。**404 だけでなく、更新前後の対象レコードの値とテーブル件数が不変であることも明示 assert**（Codex 詳細レビュー Round 4 Suggestion）

### 既存 Architecture テストのカバレッジ（新規 route/model が検査対象に入ることを確認）
- `MassAssignmentSafetyTest`: 新規 5 モデルの `$fillable` に保護キー（施策3 の追記分）が含まれないことを自動走査 → green を確認
- `NestedRouteIdorDefenseTest`: 新規 2+param route（manuals/categories の update/destroy/show/edit）が inventory に分類済み・stale なしを確認（施策10）
- `ProhibitsProtectedKeys` 系（各 FormRequest の missing rule）: `category_id`/`created_by` 等の直送で 422
- ルート認可（web の auth/verified ミドルウェア）: 新規 route が既存 projects group の保護下に入ることを確認
- ds-purity / atomic-import-graph（施策11 の Svelte が DS token・階層規約に従う）

### Vitest
- Show.svelte / Manuals 画面の描画・未分類・disabled 不使用

### PHPStan 適合チェック
- [x] テストは Factory 生成のみ、`actingAs` で認証

### リスク
- 「並行」再現の忠実度。真の同時実行は困難なため、**Project 行ロックの存在（Service が transaction + lockForUpdate を通る）を単体で検証**し、直列化の結合テストは「順序依存の不変（末尾採番の単調増加・reorder 後の集合維持）」で近似する。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | 新規テーブル 5・保護キー・IDOR inventory・権限 rename と広範。他施策との並行編集で routes.php / MassAssignmentProtectedKeys / IDOR inventory が競合しやすく、単独セッションで一括実装が安全 |
| 競合リスク | `routes/web.php` / `NestedRouteIdorDefenseTest` inventory / `MassAssignmentProtectedKeys` / `Projects/Show.svelte` は共有ファイル。standalone で他 TODO と時間を分ける |

## template-divergence 記録（実装前に先行記述）
実装着手時、`docs/template-divergence.md` に以下の意図的逸脱を「logic-driven な理由 + 保証し続ける不変条件」付きで記録する:
- **Tier B の schema 先取り**（SourceDocument/Cut/Take を振る舞い無しで先行作成）: 後続フェーズのスキーマ確定・IDOR 連鎖の土台。不変条件=route/UI を張るまで外部到達不可。
- **循環 FK の 3 段階マイグレーション**（cuts の parent_cut_id/adopted_take_id を後付け）: DB 非依存で構築するため。
- **Category `sort_order` の Service 専有**（fillable 外・Store/Update で触らない・Project 行ロックで直列化）: reorder 契約の一貫性のため。

## 完了条件
`composer test` / `composer phpstan`(lv10) / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` 全 green。上記逸脱を `docs/template-divergence.md` に記録済み。Codex Round 5（概念）完了条件=Project 行ロック Service の Feature テスト + 並行操作の近似結合テスト登録。
