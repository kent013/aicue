# 詳細設計: manual-list-sort-filter (動画一覧の並べ替え・自作フィルタ・メタ表示)

> 概念設計 APPROVED (gpt-5.4 Round 3)。原稿(SOP)検索・作成者名検索・サムネイルは out-of-scope (将来施策)。

## 使命・制約(絶対遵守)

### アプリの使命(North Star)
AI-CUE は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。標準作業を起点に AI が教材設計し撮影を指示する。v1 スコープ: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項(抜粋・本設計に関わる核)
1. テストなしの実装完了報告。2. PHPStan エラーの widen・baseline 化。4. `response()->json()` 直書き(DTO/JsonResource/Inertia)。8. 必須条件未充足を理由にボタンを disabled にする UI(押下時エラー)。

### セキュリティ不変条件(本設計に関わる)
1. tenant/actor キー不信: `created_by` を payload から受け取らない(auth user の id を使う)。2. 子は親に属する(認可前 404)。3. cross-org 不可(`$project->manuals()` 起点維持)。6. PII(name)は CipherSweet(検索は whereBlind。本設計は**表示のみで検索しない**)。

### コーディングルール
- PHPStan level 10 / Pest / RefreshDatabase グローバル + `--parallel`(個別 DatabaseTransactions 禁止) / テストデータは Factory / DTO・typed array / declare(strict_types=1) + 日本語コメント / Svelte 5 runes + DS token・atom のみ / `composer fix`(Pint) `pnpm lint:fix`。

## 概念設計リファレンス
`devnotes/20260715-0037-manual-list-sort-filter/conceptual-design.md`

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| A | sort allowlist enum + PC 一覧クエリ拡張 (sort/mine/creator/updated_at) | `app/Enums/Manual/ManualSortOption.php`(新規), `app/Http/Controllers/Projects/ProjectController.php` | High |
| B | PWA 一覧クエリ拡張 (mine/creator) + summary DTO | `app/Http/Controllers/Capture/CaptureManualController.php`, `app/DataTransferObjects/Capture/CaptureManualSummaryData.php` | High |
| C | TS 型の波及更新 | `resources/js/types/manual.ts`, `resources/js/types/capture.ts` | High |
| D | PC 画面 UI (sort select / 自作 checkbox / 行メタ) | `resources/js/pages/Projects/Show.svelte` | Medium |
| E | PWA 画面 UI (自作 toggle / カードメタ) | `resources/js/pages/Capture/Index.svelte` | Medium |
| F | テスト (Feature + Vitest) | `tests/Feature/Projects/ProjectShowManualsTest.php`, `tests/Feature/Capture/CaptureManualBrowsingTest.php`, `tests/js/pages/ProjectsShow.test.ts`, `tests/js/pages/CaptureIndex.test.ts`(新規) | High |

---

## 施策 A: sort allowlist enum + PC 一覧クエリ拡張

### 変更箇所
- 新規 `app/Enums/Manual/ManualSortOption.php`
- `app/Http/Controllers/Projects/ProjectController.php`: `parseManualFilters` (L148-163), `manualRows` (L175-220), `show` の `manualRows` 呼び出し (L133)

### 波及変更
- TypeScript 型定義: `ManualFilters` / `ManualListItem` (施策 C)
- API Resource/DTO: なし (typed array を継続)。sort allowlist は enum に集約
- テストファイル: `ProjectShowManualsTest.php` (施策 F)

### A-1. 新規 enum `ManualSortOption`
```php
<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアル一覧の並べ替え allowlist (PC 一覧・doc/04 §4.2)。
 * 全 sort に id の安定 tie-breaker を付ける (同値行でページ間の重複/欠落を防ぐ)。
 * 既定 (null) は Controller 側で created_at desc, id desc を適用する (この enum には持たせない)。
 * TS 側 ManualSortOption 相当の literal union と値集合を一致させる。
 * 順序は DB collation に従う (title の大文字小文字・日本語順は collation 依存。将来
 * title_sort_key 導入が必要になれば別施策とする)。
 *
 * @phpstan-type ManualOrderColumn 'created_at'|'updated_at'|'title'|'id'
 * @phpstan-type ManualOrdering array{column: ManualOrderColumn, direction: 'asc'|'desc'}
 */
enum ManualSortOption: string
{
    case UpdatedDesc = 'updated_desc';
    case UpdatedAsc = 'updated_asc';
    case TitleAsc = 'title_asc';
    case TitleDesc = 'title_desc';

    /**
     * orderBy へ適用する (column, direction) の列。column は enum 由来の allowlist union =
     * ユーザー入力をカラム名に渡さない (SQL インジェクション不可)。direction は literal。
     *
     * @return non-empty-list<ManualOrdering>
     */
    public function orderings(): array
    {
        return match ($this) {
            self::UpdatedDesc => [['column' => 'updated_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
            self::UpdatedAsc => [['column' => 'updated_at', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            self::TitleAsc => [['column' => 'title', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
            self::TitleDesc => [['column' => 'title', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
        };
    }

    /**
     * 既定順 (sort 未指定 / allowlist 外)。現行踏襲。
     *
     * @return non-empty-list<ManualOrdering>
     */
    public static function defaultOrderings(): array
    {
        return [['column' => 'created_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']];
    }
}
```
> `@phpstan-type` は enum 内で定義し、`ProjectController` 側で `@phpstan-import-type ManualOrdering from ManualSortOption` して `manualRows` の orderings ループ型に流用する。

### A-2. `parseManualFilters` 拡張
現行の戻り値 shape に `sort: ManualSortOption|null` と `mine: bool` を足す。
```php
/**
 * @return array{category: string|null, status: string|null, q: string|null,
 *   sort: ManualSortOption|null, mine: bool}
 */
private function parseManualFilters(Request $request): array
{
    // ... category / status / q は現行のまま ...

    $sortRaw = $request->query('sort');
    $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null; // allowlist 外は null (= 既定)

    return [
        'category' => $category,
        'status' => $status,
        'q' => $q,
        'sort' => $sort,
        'mine' => $request->boolean('mine'), // "1"/"true" を bool 正規化
    ];
}
```

### A-3. `manualRows` 拡張 (sort / mine / creator / updated_at)
`show` から viewer(auth user)の id を渡す。row 生成に `with('creator')` と作成者・更新日を追加。
```php
// show(): 呼び出しを $this->manualRows($project, $filters, $user->id) に変更

/**
 * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
 * @return array{
 *   data: list<array{id: int, title: string, status: string,
 *     category: array{id: int, name: string}|null,
 *     creator: array{id: int, name: string}|null,
 *     created_at: string, updated_at: string}>,
 *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
 * }
 */
private function manualRows(Project $project, array $filters, int $viewerId): array
{
    $query = $project->manuals()->with(['category', 'creator']);

    // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲)
    $orderings = $filters['sort']?->orderings() ?? ManualSortOption::defaultOrderings();
    foreach ($orderings as $ordering) {
        $query->orderBy($ordering['column'], $ordering['direction']);
    }

    if ($filters['mine']) {
        $query->where('created_by', $viewerId); // 自ユーザー id のみ (payload 非受領)
    }
    // category / status / q は現行ロジックのまま (q は title LIKE を維持)
    // ...

    $paginated = $query->paginate(10)->withQueryString();

    $data = [];
    foreach ($paginated->items() as $manual) {
        Assert::isInstanceOf($manual, VideoManual::class);
        $category = $manual->category;
        $creator = $manual->creator; // 退会/削除で null になり得る
        $data[] = [
            'id' => $manual->id,
            'title' => $manual->title,
            'status' => $manual->status->value,
            'category' => $category === null ? null : ['id' => $category->id, 'name' => $category->name],
            'creator' => $creator === null ? null : ['id' => $creator->id, 'name' => $creator->name],
            'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
            'updated_at' => $manual->updated_at?->format('Y-m-d H:i') ?? '',
        ];
    }
    // meta は現行のまま
}
```
> `manualFilters` prop も sort/mine を含める。show() の `'manualFilters' => $filters` は enum を含むため、Inertia へは `sort` を string 値へ変換して渡す (下記 A-4)。

### A-4. `manualFilters` prop の shape (enum → string 変換を単一メソッド化)
`$filters` は PHP 内部表現に `ManualSortOption` を持つため、prop 化時に `sort` を `?->value` (string|null) へ落とす。二重管理を避けるため **専用メソッド 1 点** で変換する (Round 1 W2):
```php
/**
 * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
 *
 * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
 * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
 */
private function toManualFilterProps(array $filters): array
{
    return [
        'category' => $filters['category'],
        'status' => $filters['status'],
        'q' => $filters['q'],
        'sort' => $filters['sort']?->value, // string|null (TS の ManualFilters.sort と一致)
        'mine' => $filters['mine'],
    ];
}

// show(): 'manualFilters' => $this->toManualFilterProps($filters),
```

### PHPStan 適合チェック
- [x] `orderings()` / `defaultOrderings()` の戻り値は `non-empty-list<array{column: string, direction: 'asc'|'desc'}>` で固定
- [x] `parseManualFilters` の戻り値 shape に sort/mine を追記
- [x] `manualRows` の戻り値 shape に creator(nullable)/updated_at を追記
- [x] `ManualSortOption::tryFrom` で allowlist 外は null (widen なし)
- [x] `$manual->creator` は `BelongsTo<User>` の nullable、`?->` で null 安全

### テスト計画
- [ ] sort 各値で順序を **期待 ID 配列の完全一致**で検証 (`expect->assertSame([id...], array_column($data, 'id'))` 相当。updated/title × asc/desc)
- [ ] **同値データ (同一 updated_at / 同一 title) で id tie-breaker のページ境界に重複/欠落無し** (page=1/page=2 の id 集合が排他かつ全件被覆)
- [ ] sort allowlist 外値 (`sort=bogus`) は既定順へフォールバック
- [ ] sort 無しは現行 `created_at desc, id desc` 不変 (回帰)
- [ ] `mine=1` で自ユーザー作成分のみ (他ユーザー作成分の除外)
- [ ] `manuals.data.*.creator` (正常系で `{id,name}` が供給される) / `updated_at` の供給
- [ ] `manualFilters.sort` (string|null) / `manualFilters.mine` (bool) の供給
- [ ] cross-org / cross-project の manual が漏れない (回帰)
- [ ] mine + category/status/q/sort 併用の代表 1 ケースで結合絞り込みを確認 (Round 1 S)

> creator === null 分岐は Feature では作れない (FK RESTRICT)。vitest で props に null を渡して UI フォールバック「不明」を検証する (施策 D)。

### リスク
- `with('creator')` の追加ロードは 1 クエリ増のみ (N+1 回避)。既存 `with('category')` と同等コスト。
- creator 削除時の null は既存 UI が member 一覧で name 表示済みのため退行なし。

---

## 施策 B: PWA 一覧クエリ拡張 + summary DTO

### 変更箇所
- `app/Http/Controllers/Capture/CaptureManualController.php`: `index` (L50-85)
- `app/DataTransferObjects/Capture/CaptureManualSummaryData.php`: コンストラクタ / `fromManual` / `toArray` / PHPDoc

### 波及変更
- TypeScript 型定義: `CaptureManualSummary` (creator_name 追加) / Index filters (mine 追加) — 施策 C
- テストファイル: `CaptureManualBrowsingTest.php` — 施策 F

### B-1. `CaptureManualController::index`
```php
$user = $request->user();
Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
$userId = $user->id;

$mine = $request->boolean('mine');

// $manuals クエリに:
//   ->when($mine, fn (Builder $q) => $q->where('created_by', $userId)) // 自ユーザー id のみ (payload 非受領)
//   ->with(['category', 'creator'])   // creator を追加
// sort は追加しない (現行 orderByDesc('updated_at') を維持)

// filters prop:
'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
```

**[Round 1 Critical] `q` の LIKE エスケープ統一**: 現行 PWA は `->where('title', 'like', '%'.$search.'%')` で `addcslashes` が無く、`%/_/\` を含む入力で PC と挙動が食い違う。PC (`manualRows`) と同一の `addcslashes($search, '%_\\')` を適用して統一する:
```php
->when($search !== null, fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
```

### B-2. `CaptureManualSummaryData` に creatorName 追加
- コンストラクタに `public ?string $creatorName` を追加 (updatedAt は既存)
- `fromManual`: `creatorName: $manual->creator?->name`
- `toArray`: `'creator_name' => $this->creatorName`
- PHPDoc の `@return array{...}` に `creator_name: string|null` を追記
- クラス docblock に「**creator は表示目的のみ (検索対象外)**。User.name は CipherSweet PII のため whereBlind 検索の対象にはしない」を一文追記 (PII 方針の保守性・Round 1 S9)

### PHPStan 適合チェック
- [x] `$manual->creator?->name` は `string|null` (BelongsTo nullable)
- [x] `toArray` の shape に `creator_name: string|null` を追記
- [x] `$request->boolean('mine')` は bool 確定
- [x] user id は Assert で int 確定後に where へ

### テスト計画
- [ ] `mine=1` で自作シナリオのみ (ready/published の絞り込みと AND)
- [ ] `manuals.*.creator_name` の供給 (正常系で string)
- [ ] `filters.mine` の供給
- [ ] cross-org 非漏洩 (回帰。`$project->manuals()` 起点維持)

> creator_name === null 分岐は Feature では作れない (FK RESTRICT)。vitest (施策 E) で null props を渡して「不明」フォールバックを検証する。

### リスク
- `with('creator')` 追加で 1 クエリ増のみ。撮影者 (project_member) が creator name を閲覧する認可上の前提は概念設計で確定済み (project view 済み)。

---

## 施策 C: TS 型の波及更新

### 変更箇所 / 波及変更
- `resources/js/types/manual.ts`: `ManualSortOption` 型追加、`ManualFilters` に sort/mine、`ManualListItem` に creator/updated_at
- `resources/js/types/capture.ts`: `CaptureManualSummary` に creator_name
- Svelte 側 (施策 D/E) が新キーを参照

### C-1. `manual.ts`
```typescript
/** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";

export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    category: { id: number; name: string } | null;
    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
    creator: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}

export interface ManualFilters {
    category: string | null;
    status: string | null;
    q: string | null;
    /** 並べ替え。null = 既定 (作成日降順) */
    sort: ManualSortOption | null;
    /** 自分の作成分のみ */
    mine: boolean;
}
```

### C-2. `capture.ts`
```typescript
export interface CaptureManualSummary {
    // ... 既存 ...
    updated_at: string | null;
    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
    creator_name: string | null;
}
```

### PHPStan/型 適合チェック
- [x] `ManualSortOption` literal union は PHP enum 値集合と一致
- [x] creator/creator_name の nullable を型で固定

### テスト計画
- [ ] vitest (施策 F) が新 props shape を使用してコンパイル整合を担保

### リスク
- PHP typed array と TS 型の乖離は当面手動確認 (既存方針。`ManualEnumTsSyncInvariantTest` の対象拡張は本施策では行わない = out-of-scope)

---

## 施策 D: PC 画面 UI (Projects/Show.svelte)

### 変更箇所
- `resources/js/pages/Projects/Show.svelte`: フィルタ form (L314-365)、行表示 (L374-396)、フィルタ state (L82-111)

### 波及変更
- Props `manualFilters` / `manuals.data` の新キー参照 (施策 A/C)

### D-1. フィルタ state + query 組み立て
```typescript
let filterSort = $state(manualFilters.sort ?? "");
let filterMine = $state(manualFilters.mine);

function manualQuery(pageNumber?: number): Record<string, string | number> {
    const query: Record<string, string | number> = {};
    if (filterCategory !== "") query.category = filterCategory;
    if (filterStatus !== "") query.status = filterStatus;
    if (filterQ.trim() !== "") query.q = filterQ.trim();
    if (filterSort !== "") query.sort = filterSort;
    if (filterMine) query.mine = 1;
    if (pageNumber !== undefined && pageNumber > 1) query.page = pageNumber;
    return query;
}
```
- sort `Select` (atom)・自作 `Checkbox` (atom) の `onchange` は既存 `applyManualFilters()` を呼ぶ (category/status と同流儀)。`only: ["manuals", "manualFilters"]` の部分更新を維持。

### D-2. UI 追加 (DS token / atom のみ)
- 並べ替え `Select` (option: 既定「新しい順(作成)」= 空値 / 更新が新しい順 / 更新が古い順 / タイトル昇順 / タイトル降順)。
- 「自分の作成分のみ」`Checkbox` (label 文字列)。**disabled を使わない** (禁止事項 8)。
- 行に作成者/更新日を追加 (既存の `{category} ・ {created_at}` 行を `{category} ・ {creator?.name ?? "不明"} ・ 更新 {updated_at}` へ)。

### PHPStan/型 適合チェック
- [x] Svelte props 型は施策 C の型に一致

### テスト計画 (vitest)
- [ ] sort select 変更で GET クエリに sort が載る
- [ ] 自作 checkbox で mine=1 が載る
- [ ] 行に creator.name (null 時「不明」) と updated_at が表示される
- [ ] **sort/mine 変更時に page を引き継がない** (manualQuery が pageNumber 未指定なら page を載せない = page リセット。Round 1 S8)
- [ ] q 入力中に sort/mine を操作しても trim 済み q がクエリに維持される (Round 1 S11)

### リスク
- 既存フィルタ form のレイアウト崩れ。DS の flex-wrap で吸収 (既存パターン)。

---

## 施策 E: PWA 画面 UI (Capture/Index.svelte)

### 変更箇所
- `resources/js/pages/Capture/Index.svelte`: filters state (L29-40)、カード表示 (L83-105)

### E-1. mine トグル + query
```typescript
let mine = $state(filters.mine);
// applyFilters(): if (mine) query.mine = "1";
```
- 「自分が作ったシナリオ」トグル (Checkbox atom)。`onchange` で `applyFilters()`。

### E-2. カードメタ
- 既存 `{category_name ?? "未分類"} ・カット …` 行に作成者/更新日を追加。表示は「{category} ・ {creator_name ?? "不明"} ・ {updated_at 整形}」。updated_at は ISO 文字列のため既存の日付整形方針に合わせて表示 (行内は日付のみで可)。

### 波及変更
- Props `filters.mine` / `manuals.*.creator_name` の参照 (施策 B/C)

### テスト計画 (vitest 新規 `CaptureIndex.test.ts`)
- [ ] mine トグルで GET クエリに mine が載る
- [ ] カードに creator_name (null 時「不明") 表示

### リスク
- PWA の縦画面レイアウト。既存カード内 caption 行に収めるため崩れは軽微。

---

## 施策 F: テスト

### 変更/新規ファイル
| ファイル | 追加テスト |
|---------|-----------|
| `tests/Feature/Projects/ProjectShowManualsTest.php` | sort 各値の順序 / tie-breaker ページ境界 / allowlist 外フォールバック / 既定不変 / mine 絞り込み / creator・updated_at props / creator null / manualFilters.sort・mine |
| `tests/Feature/Capture/CaptureManualBrowsingTest.php` | mine 絞り込み (ready/published と AND) / creator_name props / creator null / filters.mine / cross-org 非漏洩回帰 |
| `tests/js/pages/ProjectsShow.test.ts` | sort select・mine checkbox の GET クエリ / 行 creator・updated_at 表示 |
| `tests/js/pages/CaptureIndex.test.ts`(新規) | mine トグルの GET クエリ / カード creator_name 表示 |

### テストデータ
- 全て Factory 生成 (`VideoManual::factory()->forProject()->createdBy($user)`, `->forCategory()`)。
- 個別 `DatabaseTransactions` は使わない (グローバル RefreshDatabase)。

### creator null 契約の扱い (FK 確認済み)
`video_manuals.created_by` は `$table->foreignId('created_by')->constrained('users')` = **NOT NULL + FK 既定 RESTRICT** (onDelete 未指定)。したがって作成者 User の hard-delete は DB が拒否し、既存 manual の `creator` relation は**常に解決する** (実運用で null にならない)。User に SoftDeletes は無い。

方針:
- **DTO/型は null 契約を維持 (防御的)**。将来 created_by の FK 方針が変わっても UI/型が壊れないため。
- Feature テストは `$user->delete()` を**行わない** (FK RESTRICT で失敗するため)。creator props は「正常系で常に解決 = `creator.name` が供給される」ことを検証する。
- null 分岐 (creator===null → "不明") は **vitest (施策 D/E)** で props に `creator: null` / `creator_name: null` を渡して UI フォールバックを検証する (型・UI 契約の単体確認)。

### リスク
- creator は常に解決するため、Feature 側で null 分岐を作れない。UI の null フォールバックは vitest で担保する (上記)。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | PC (施策 A) と PWA (施策 B) は独立 Controller。TS 型 (C) は両者の前提。単一 PR/worktree でまとめて実装しテスト green を確認するのが整合的 (型と Controller と UI が相互依存)。他 Open TODO との共有ファイル競合は低い |
| 競合リスク | `ProjectController.php` / `CaptureManualController.php` / `manual.ts` / `capture.ts` を触る他施策があれば競合。現状の一覧改善系施策は本件のみ |
