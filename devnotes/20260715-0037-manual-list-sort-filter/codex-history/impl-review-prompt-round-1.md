# Codex 実装レビュー依頼 (T053 manual-list-sort-filter / impl-review Round 1)

## アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory 経由のみ)
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)

セキュリティ不変条件(本件に関わる): tenant/actor キー不信(`created_by` を payload から受けず auth user の id を使う) / 子は親に属する(認可より前に 404) / cross-org 不可(`$project->manuals()` 起点維持) / PII(name)は CipherSweet で検索は whereBlind(本件は**表示のみで検索しない**)。

## 思考原則 — 全議論に適用

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。

先人の知恵を探せ。乗るべき巨人の肩があるなら乗れ(Laravel / Svelte のエコシステム)。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## system: 役割

あなたは Laravel + Svelte のコードレビュアーである。本 diff は TODO T053「動画一覧の並べ替え・自作フィルタ・作成者/更新日メタ表示」の実装。以下の観点でレビューし、ファイルごとに判定、指摘は Critical / Warning / Suggestion に分類、最後に全体判定 (APPROVED / CHANGES_REQUESTED) を明示せよ。

レビュー観点:
1. **設計との一致性**: 下記詳細設計書の施策 A〜F を満たしているか
2. **正確性**: sort allowlist / tie-breaker / mine 絞り込み / creator nullable の扱いにバグは無いか
3. **PHPStan 適合**: 型の widen が無いか (level 10。実行結果は「No errors」)
4. **DTO/Inertia パターン**: `response()->json()` 直書きが無いか (Inertia render のみ)
5. **テスト網羅性**: sort 各値の順序・ページ境界 tie-breaker・allowlist 外フォールバック・mine 絞り込み・結合絞り込み・creator/updated_at 供給・PHP↔TS キー集合契約
6. **セキュリティ**: `created_by` を payload から受けていないか (viewer id / auth user id のみ)、cross-org 非漏洩、LIKE メタ文字のエスケープ統一
7. **DESIGN.md 準拠**: color/radius/typography は token 経由か、hex 直書き (`#RRGGBB`) を増やしていないか
8. **Atomic Design 準拠**: `resources/js/components/` の atom (Checkbox/Select) を正しく利用しているか、素の `<input type=checkbox>` を書いていないか、階層の逆流が無いか

---

## user

### 詳細設計書 (要旨)

施策 A: `ManualSortOption` enum (updated_desc/updated_asc/title_asc/title_desc) を新規追加。全 sort に id の tie-breaker。既定 (null/allowlist 外) は created_at desc, id desc。`ProjectController::parseManualFilters` に sort/mine を追加、`manualRows` に viewer id を渡し sort 適用・mine 絞り込み・creator/updated_at row 追加、`toManualFilterProps` で enum→string 変換 (単一変換点)。

施策 B: PWA `CaptureManualController::index` に mine 絞り込み (viewer id) と with('creator')、`CaptureManualSummaryData` に creatorName 追加。LIKE エスケープを PC と統一 (`addcslashes($search,'%_\\')`)。

施策 C: TS 型 (ManualSortOption / ManualFilters.sort,mine / ManualListItem.creator,updated_at / CaptureManualSummary.creator_name)。

施策 D/E: PC (Projects/Show.svelte) は sort Select + 自作 Checkbox + 行メタ (creator/updated)。PWA (Capture/Index.svelte) は自作 Checkbox + カードメタ。disabled 不使用 (禁止事項 8)。sort/mine 変更時は page を引き継がない (1 ページ目へ)。

施策 F: Feature (ProjectShowManualsTest / CaptureManualBrowsingTest) + Vitest (ProjectsShow.test.ts / CaptureIndex.test.ts 新規)。creator null 分岐は FK RESTRICT のため Feature で作れず vitest で担保。

制約: PHPStan level 10 / Pest + RefreshDatabase グローバル + --parallel (個別 DatabaseTransactions 禁止) / テストは Factory / DTO・typed array / Svelte 5 runes + DS token・atom のみ。

### design system 参照

- DESIGN.md が canonical。color/radius/typography は tokens.css 経由。hex 直書き禁止。
- Checkbox は `components/atoms/Checkbox.svelte` (インラインラベル + エラー内包)。ページ側で素の `<input type=checkbox>` を書かない。
- 本 diff で触れた atomic ディレクトリ: `components/atoms/` の Checkbox / Select / Input / Button / Card / Badge / TextLink (いずれも既存 atom の利用のみ。新規 atom・新規 token・hex 直書きは無し)。
- 日付整形は既存 `lib/date-format.ts` の `formatDate` を利用 (PWA カード)。

### 実装差分 (git diff HEAD)

```diff
diff --git a/app/DataTransferObjects/Capture/CaptureManualSummaryData.php b/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
index 4afc7c5..9a6faaf 100644
--- a/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
+++ b/app/DataTransferObjects/Capture/CaptureManualSummaryData.php
@@ -10,6 +10,8 @@
 /**
  * 撮影一覧 (Capture/Index) の 1 行分。TS 側 types/capture.ts の CaptureManualSummary と対で保守。
  * 進捗カウント (cuts_total / cuts_adopted / cuts_with_takes) は withCount 済みモデルから読む。
+ * creator は表示目的のみ (検索対象外)。User.name は CipherSweet PII のため whereBlind 検索の
+ * 対象にはしない (自作フィルタは created_by の id 一致で行う)。
  */
 final readonly class CaptureManualSummaryData
 {
@@ -23,11 +25,12 @@ public function __construct(
         public int $cutsAdopted,
         public int $cutsWithTakes,
         public ?string $updatedAt,
+        public ?string $creatorName,
     ) {}
 
     /**
      * withCount('cuts', 'cuts as cuts_adopted_count', 'cuts as cuts_with_takes_count') +
-     * with('category') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
+     * with('category', 'creator') 済みの manual から生成する (Capture/IndexController の一覧クエリと対)。
      */
     public static function fromManual(VideoManual $manual): self
     {
@@ -48,13 +51,14 @@ public static function fromManual(VideoManual $manual): self
             cutsAdopted: $cutsAdopted,
             cutsWithTakes: $cutsWithTakes,
             updatedAt: $manual->updated_at?->toIso8601String(),
+            creatorName: $manual->creator?->name, // 退会/削除で null (実運用では FK RESTRICT)
         );
     }
 
     /**
      * @return array{id: int, title: string, status: string, category_id: int|null,
      *   category_name: string|null, cuts_total: int, cuts_adopted: int, cuts_with_takes: int,
-     *   updated_at: string|null}
+     *   updated_at: string|null, creator_name: string|null}
      */
     public function toArray(): array
     {
@@ -68,6 +72,7 @@ public function toArray(): array
             'cuts_adopted' => $this->cutsAdopted,
             'cuts_with_takes' => $this->cutsWithTakes,
             'updated_at' => $this->updatedAt,
+            'creator_name' => $this->creatorName,
         ];
     }
 }
diff --git a/app/Enums/Manual/ManualSortOption.php b/app/Enums/Manual/ManualSortOption.php
new file mode 100644
index 0000000..91b0bc5
--- /dev/null
+++ b/app/Enums/Manual/ManualSortOption.php
@@ -0,0 +1,50 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Enums\Manual;
+
+/**
+ * 動画マニュアル一覧の並べ替え allowlist (PC 一覧・doc/04 §4.2)。
+ * 全 sort に id の安定 tie-breaker を付ける (同値行でページ間の重複/欠落を防ぐ)。
+ * 既定 (null) は defaultOrderings() を適用する (created_at desc, id desc)。
+ * TS 側 ManualSortOption 相当の literal union と値集合を一致させる。
+ * 順序は DB collation に従う (title の大文字小文字・日本語順は collation 依存。将来
+ * title_sort_key 導入が必要になれば別施策とする)。
+ *
+ * @phpstan-type ManualOrderColumn 'created_at'|'updated_at'|'title'|'id'
+ * @phpstan-type ManualOrdering array{column: ManualOrderColumn, direction: 'asc'|'desc'}
+ */
+enum ManualSortOption: string
+{
+    case UpdatedDesc = 'updated_desc';
+    case UpdatedAsc = 'updated_asc';
+    case TitleAsc = 'title_asc';
+    case TitleDesc = 'title_desc';
+
+    /**
+     * orderBy へ適用する (column, direction) の列。column は enum 由来の allowlist union =
+     * ユーザー入力をカラム名に渡さない (SQL インジェクション不可)。direction は literal。
+     *
+     * @return non-empty-list<ManualOrdering>
+     */
+    public function orderings(): array
+    {
+        return match ($this) {
+            self::UpdatedDesc => [['column' => 'updated_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
+            self::UpdatedAsc => [['column' => 'updated_at', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
+            self::TitleAsc => [['column' => 'title', 'direction' => 'asc'], ['column' => 'id', 'direction' => 'asc']],
+            self::TitleDesc => [['column' => 'title', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']],
+        };
+    }
+
+    /**
+     * 既定順 (sort 未指定 / allowlist 外)。現行踏襲 (created_at desc, id desc)。
+     *
+     * @return non-empty-list<ManualOrdering>
+     */
+    public static function defaultOrderings(): array
+    {
+        return [['column' => 'created_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']];
+    }
+}
diff --git a/app/Http/Controllers/Capture/CaptureManualController.php b/app/Http/Controllers/Capture/CaptureManualController.php
index d932bb3..0176db1 100644
--- a/app/Http/Controllers/Capture/CaptureManualController.php
+++ b/app/Http/Controllers/Capture/CaptureManualController.php
@@ -53,14 +53,25 @@ public function index(Request $request, Project $project): Response
         $this->resolveOrganizationProject($organization, $project); // 認可より前に 404
         Gate::authorize('view', $project);
 
+        $user = $request->user();
+        Assert::isInstanceOf($user, User::class); // view 認可済み = 認証済み。早期に int を確定
+        $userId = $user->id;
+
         $categoryId = $request->filled('category') ? (int) $request->string('category')->value() : null;
         $search = $request->filled('q') ? $request->string('q')->value() : null;
+        $mine = $request->boolean('mine'); // "1"/"true" を bool 正規化
 
         $manuals = $project->manuals()
             ->whereIn('status', [VideoManualStatus::Ready, VideoManualStatus::Published])
             ->when($categoryId !== null, fn (Builder $query) => $query->where('category_id', $categoryId))
-            ->when($search !== null, fn (Builder $query) => $query->where('title', 'like', '%'.$search.'%'))
-            ->with('category')
+            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う (PC 一覧 manualRows と統一)
+            ->when($search !== null, function (Builder $query) use ($search): void {
+                Assert::string($search);
+                $query->where('title', 'like', '%'.addcslashes($search, '%_\\').'%');
+            })
+            // 自作フィルタ: 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
+            ->when($mine, fn (Builder $query) => $query->where('created_by', $userId))
+            ->with(['category', 'creator'])
             ->withCount([
                 'cuts',
                 // 採用済み cut 数 (relation 経由 = 'adopted_take_id' リテラルを撮影経路に増やさない)
@@ -80,7 +91,7 @@ public function index(Request $request, Project $project): Response
                 ->get()
                 ->map(static fn (Category $category): array => ['id' => $category->id, 'name' => $category->name])
                 ->all(),
-            'filters' => ['category' => $categoryId, 'q' => $search],
+            'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine],
         ]);
     }
 
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index 6f3948e..f7ed3fe 100644
--- a/app/Http/Controllers/Projects/ProjectController.php
+++ b/app/Http/Controllers/Projects/ProjectController.php
@@ -4,6 +4,7 @@
 
 namespace App\Http\Controllers\Projects;
 
+use App\Enums\Manual\ManualSortOption;
 use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
@@ -30,6 +31,8 @@
  * - current org スコープ: ResolvesCurrentOrganization で解決 (URL に org セグメントを持たない)
  * - URL 整合 guard: {project} が current org に属さなければ**認可より前に 404**
  * - teams_visible=false の既定では Team 概念を UI に出さない (Default Team は Service が自動割当)
+ *
+ * @phpstan-import-type ManualOrdering from ManualSortOption
  */
 class ProjectController extends Controller
 {
@@ -130,9 +133,9 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
             // payload 生成時点で絞る = canViewMemberEmails と同じ流儀)
             'assignableUsers' => $this->assignableUserRows($organization, $memberRows, $canManage),
             // 動画マニュアル一覧 (専用 index は持たず本画面に内包。GET クエリで絞り込み + paginate)
-            'manuals' => $this->manualRows($project, $filters),
+            'manuals' => $this->manualRows($project, $filters, $user->id),
             'categories' => $this->categoryRows($project),
-            'manualFilters' => $filters,
+            'manualFilters' => $this->toManualFilterProps($filters),
             // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
             'canManageMembers' => $user->can('manageMembers', $organization),
         ]);
@@ -141,9 +144,12 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
     /**
      * 動画マニュアル一覧の GET クエリ絞り込み条件。
      * category は「数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null」、
-     * status は VideoManualStatus の値のみ許容 (不正値は無視 = null)。
+     * status は VideoManualStatus の値のみ許容 (不正値は無視 = null)、
+     * sort は ManualSortOption の allowlist のみ (不正値は null = 既定順)、
+     * mine は自分の作成分のみに絞る bool。
      *
-     * @return array{category: string|null, status: string|null, q: string|null}
+     * @return array{category: string|null, status: string|null, q: string|null,
+     *   sort: ManualSortOption|null, mine: bool}
      */
     private function parseManualFilters(Request $request): array
     {
@@ -159,25 +165,66 @@ private function parseManualFilters(Request $request): array
         $q = $request->query('q');
         $q = is_string($q) && trim($q) !== '' ? trim($q) : null;
 
-        return ['category' => $category, 'status' => $status, 'q' => $q];
+        $sortRaw = $request->query('sort');
+        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
+        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;
+
+        return [
+            'category' => $category,
+            'status' => $status,
+            'q' => $q,
+            'sort' => $sort,
+            'mine' => $request->boolean('mine'), // "1"/"true" を bool 正規化
+        ];
+    }
+
+    /**
+     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
+     * PHP 内部表現は ManualSortOption を持つため、prop 化時に string|null へ落とす。
+     *
+     * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
+     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
+     */
+    private function toManualFilterProps(array $filters): array
+    {
+        return [
+            'category' => $filters['category'],
+            'status' => $filters['status'],
+            'q' => $filters['q'],
+            'sort' => $filters['sort']?->value, // string|null (TS の ManualFilters.sort と一致)
+            'mine' => $filters['mine'],
+        ];
     }
 
     /**
      * 動画マニュアル一覧 rows (paginate + typed array で shape を固定)。
      * 未分類は category => null (フロントは「未分類」を表示する)。
+     * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
      *
-     * @param  array{category: string|null, status: string|null, q: string|null}  $filters
+     * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
      * @return array{
-     *   data: list<array{id: int, title: string, status: string, category: array{id: int, name: string}|null, created_at: string}>,
+     *   data: list<array{id: int, title: string, status: string,
+     *     category: array{id: int, name: string}|null,
+     *     creator: array{id: int, name: string}|null,
+     *     created_at: string, updated_at: string}>,
      *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
      * }
      */
-    private function manualRows(Project $project, array $filters): array
+    private function manualRows(Project $project, array $filters, int $viewerId): array
     {
-        $query = $project->manuals()->with('category')
-            ->orderByDesc('created_at')
-            ->orderByDesc('id');
+        $query = $project->manuals()->with(['category', 'creator']);
 
+        // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
+        $orderings = $filters['sort']?->orderings() ?? ManualSortOption::defaultOrderings();
+        foreach ($orderings as $ordering) {
+            /** @var ManualOrdering $ordering */
+            $query->orderBy($ordering['column'], $ordering['direction']);
+        }
+
+        if ($filters['mine']) {
+            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
+            $query->where('created_by', $viewerId);
+        }
         if ($filters['category'] === 'uncategorized') {
             $query->whereNull('category_id');
         } elseif ($filters['category'] !== null) {
@@ -197,6 +244,7 @@ private function manualRows(Project $project, array $filters): array
         foreach ($paginated->items() as $manual) {
             Assert::isInstanceOf($manual, VideoManual::class);
             $category = $manual->category;
+            $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
             $data[] = [
                 'id' => $manual->id,
                 'title' => $manual->title,
@@ -204,7 +252,11 @@ private function manualRows(Project $project, array $filters): array
                 'category' => $category === null
                     ? null
                     : ['id' => $category->id, 'name' => $category->name],
+                'creator' => $creator === null
+                    ? null
+                    : ['id' => $creator->id, 'name' => $creator->name],
                 'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
+                'updated_at' => $manual->updated_at?->format('Y-m-d H:i') ?? '',
             ];
         }
 
diff --git a/resources/js/pages/Capture/Index.svelte b/resources/js/pages/Capture/Index.svelte
index af8223e..eea308d 100644
--- a/resources/js/pages/Capture/Index.svelte
+++ b/resources/js/pages/Capture/Index.svelte
@@ -3,10 +3,12 @@
     import { Camera, Search } from "@lucide/svelte";
     import Badge from "@/components/atoms/Badge.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import Checkbox from "@/components/atoms/Checkbox.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
     import EmptyState from "@/components/molecules/EmptyState.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
+    import { formatDate } from "@/lib/date-format";
     import type { SharedProps } from "@/lib/shared-props";
     import type { CaptureManualSummary } from "@/types/capture";
 
@@ -18,7 +20,7 @@
         project: { id: number; name: string };
         manuals: CaptureManualSummary[];
         categories: { id: number; name: string }[];
-        filters: { category: number | null; q: string | null };
+        filters: { category: number | null; q: string | null; mine: boolean };
     }
 
     let { project, manuals, categories, filters }: Props = $props();
@@ -28,11 +30,13 @@
 
     let search = $state(filters.q ?? "");
     let categoryId = $state(filters.category === null ? "" : String(filters.category));
+    let mine = $state(filters.mine);
 
     function applyFilters(): void {
         const query: Record<string, string> = {};
         if (search !== "") query.q = search;
         if (categoryId !== "") query.category = categoryId;
+        if (mine) query.mine = "1";
         router.get(`/app/projects/${project.id}/manuals`, query, {
             preserveState: true,
             preserveScroll: true,
@@ -72,6 +76,16 @@
         </div>
     </div>
 
+    <div class="mt-3">
+        <Checkbox
+            id="capture-mine"
+            bind:checked={mine}
+            label="自分が作ったシナリオ"
+            onchange={applyFilters}
+            testId="capture-mine"
+        />
+    </div>
+
     <div class="mt-4 flex flex-col gap-3" data-testid="capture-manual-list">
         {#if manuals.length === 0}
             <EmptyState
@@ -90,6 +104,11 @@
                                 {manual.category_name ?? "未分類"}
                                 ・カット {manual.cuts_total} / 採用済 {manual.cuts_adopted}
                             </p>
+                            <p class="mt-0.5 text-caption text-text-secondary">
+                                {manual.creator_name ?? "不明"} ・ 更新 {formatDate(
+                                    manual.updated_at,
+                                )}
+                            </p>
                         </div>
                         <div class="shrink-0">
                             {#if manual.cuts_total > 0 && manual.cuts_adopted === manual.cuts_total}
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index f755933..8c567a1 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -3,6 +3,7 @@
     import Badge from "@/components/atoms/Badge.svelte";
     import Button from "@/components/atoms/Button.svelte";
     import Card from "@/components/atoms/Card.svelte";
+    import Checkbox from "@/components/atoms/Checkbox.svelte";
     import Input from "@/components/atoms/Input.svelte";
     import Select from "@/components/atoms/Select.svelte";
     import TextLink from "@/components/atoms/TextLink.svelte";
@@ -83,12 +84,26 @@
     let filterCategory = $state(manualFilters.category ?? "");
     let filterStatus = $state(manualFilters.status ?? "");
     let filterQ = $state(manualFilters.q ?? "");
+    let filterSort = $state<string>(manualFilters.sort ?? "");
+    let filterMine = $state(manualFilters.mine);
+
+    // 並べ替え option (空値 = 既定「新しい順(作成)」)。ManualSortOption の allowlist と対
+    const MANUAL_SORT_OPTIONS: { value: string; label: string }[] = [
+        { value: "", label: "新しい順（作成）" },
+        { value: "updated_desc", label: "更新が新しい順" },
+        { value: "updated_asc", label: "更新が古い順" },
+        { value: "title_asc", label: "タイトル昇順" },
+        { value: "title_desc", label: "タイトル降順" },
+    ];
 
     function manualQuery(pageNumber?: number): Record<string, string | number> {
         const query: Record<string, string | number> = {};
         if (filterCategory !== "") query.category = filterCategory;
         if (filterStatus !== "") query.status = filterStatus;
         if (filterQ.trim() !== "") query.q = filterQ.trim();
+        if (filterSort !== "") query.sort = filterSort;
+        if (filterMine) query.mine = 1;
+        // pageNumber 未指定 (フィルタ変更時) は page を載せない = 1 ページ目にリセットする
         if (pageNumber !== undefined && pageNumber > 1) query.page = pageNumber;
         return query;
     }
@@ -350,6 +365,21 @@
                         {/each}
                     </Select>
                 </div>
+                <div class="flex flex-col gap-1">
+                    <label class="text-caption text-text-secondary" for="manual-filter-sort">
+                        並べ替え
+                    </label>
+                    <Select
+                        id="manual-filter-sort"
+                        bind:value={filterSort}
+                        onchange={() => applyManualFilters()}
+                        testId="manual-filter-sort"
+                    >
+                        {#each MANUAL_SORT_OPTIONS as option (option.value)}
+                            <option value={option.value}>{option.label}</option>
+                        {/each}
+                    </Select>
+                </div>
                 <div class="flex min-w-40 grow flex-col gap-1">
                     <label class="text-caption text-text-secondary" for="manual-filter-q">
                         キーワード
@@ -361,6 +391,13 @@
                         testId="manual-filter-q"
                     />
                 </div>
+                <Checkbox
+                    id="manual-filter-mine"
+                    bind:checked={filterMine}
+                    label="自分の作成分のみ"
+                    onchange={() => applyManualFilters()}
+                    testId="manual-filter-mine"
+                />
                 <Button type="submit" variant="ghost" testId="manual-filter-submit">検索</Button>
             </form>
 
@@ -382,7 +419,8 @@
                                     {manual.title}
                                 </TextLink>
                                 <p class="mt-1 text-caption text-text-secondary">
-                                    {manual.category?.name ?? "未分類"} ・ {manual.created_at}
+                                    {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ??
+                                        "不明"} ・ 更新 {manual.updated_at}
                                 </p>
                             </div>
                             <Badge
diff --git a/resources/js/types/capture.ts b/resources/js/types/capture.ts
index 75a33a3..659ad5a 100644
--- a/resources/js/types/capture.ts
+++ b/resources/js/types/capture.ts
@@ -52,6 +52,8 @@ export interface CaptureManualSummary {
     cuts_adopted: number;
     cuts_with_takes: number;
     updated_at: string | null;
+    /** 作成者名。退会/削除で解決不可のときは null (UI は「不明」) */
+    creator_name: string | null;
 }
 
 /** POST .../takes/upload-url の応答 (TakeUploadTicketResource と対) */
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index 2199a63..0ae657a 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -37,13 +37,19 @@ export interface PaginationMeta {
     total: number;
 }
 
+/** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
+export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";
+
 export interface ManualListItem {
     id: number;
     title: string;
     status: VideoManualStatus;
     /** null = 未分類 */
     category: { id: number; name: string } | null;
+    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
+    creator: { id: number; name: string } | null;
     created_at: string;
+    updated_at: string;
 }
 
 export interface CategoryOption {
@@ -56,6 +62,10 @@ export interface ManualFilters {
     category: string | null;
     status: string | null;
     q: string | null;
+    /** 並べ替え。null = 既定 (作成日降順) */
+    sort: ManualSortOption | null;
+    /** 自分の作成分のみ */
+    mine: boolean;
 }
 
 /**
diff --git a/tests/Feature/Capture/CaptureManualBrowsingTest.php b/tests/Feature/Capture/CaptureManualBrowsingTest.php
index 90a4dd0..0132fc0 100644
--- a/tests/Feature/Capture/CaptureManualBrowsingTest.php
+++ b/tests/Feature/Capture/CaptureManualBrowsingTest.php
@@ -90,6 +90,45 @@ function browsingContext(): array
         ->assertInertia(fn (Assert $page) => $page->has('manuals', 0));
 });
 
+test('index は mine=1 で自作シナリオのみに絞る (ready/published と AND)', function (): void {
+    [$organization, $owner, $project] = browsingContext();
+    $other = attachOrganizationMember($organization);
+    $mine = VideoManual::factory()->forProject($project)->createdBy($owner)->create([
+        'status' => 'ready', 'title' => '自作 ready',
+    ]);
+    // 他人作 (mine で除外) / 自作だが draft (status で除外)
+    VideoManual::factory()->forProject($project)->createdBy($other)->create(['status' => 'ready']);
+    VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'draft']);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals?mine=1")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals', 1)
+            ->where('manuals.0.id', $mine->id)
+            ->where('filters.mine', true));
+});
+
+test('index は manuals.*.creator_name と filters.mine を供給する', function (): void {
+    [, $owner, $project] = browsingContext();
+    VideoManual::factory()->forProject($project)->createdBy($owner)->create(['status' => 'ready']);
+
+    $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.0.creator_name', $owner->name)
+            ->where('filters.mine', false));
+});
+
+test('index の summary shape は TS CaptureManualSummary と対のキー集合 (PHP↔TS 契約)', function (): void {
+    [, $owner, $project] = browsingContext();
+    VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
+
+    $summary = $this->actingAs($owner)->get("/app/projects/{$project->id}/manuals")
+        ->inertiaPage()['props']['manuals'][0];
+    expect(array_keys($summary))->toBe([
+        'id', 'title', 'status', 'category_id', 'category_name',
+        'cuts_total', 'cuts_adopted', 'cuts_with_takes', 'updated_at', 'creator_name',
+    ]);
+});
+
 test('show は cuts+takes を返し、採用テイクのみ playback_url / download_ack_token を持つ', function (): void {
     [, $owner, $project] = browsingContext();
     $manual = VideoManual::factory()->forProject($project)->create(['status' => 'ready']);
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index f6d3055..911bef6 100644
--- a/tests/Feature/Projects/ProjectShowManualsTest.php
+++ b/tests/Feature/Projects/ProjectShowManualsTest.php
@@ -137,3 +137,137 @@
             ->has('manuals.data', 1)
             ->where('manuals.data.0.title', '自分のマニュアル'));
 });
+
+test('manuals.data.* は creator / updated_at を供給する (正常系)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
+        'title' => 'メタ確認', 'updated_at' => '2026-07-10 09:30:00',
+    ]);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.data.0.creator.id', $owner->id)
+            ->where('manuals.data.0.creator.name', $owner->name)
+            ->where('manuals.data.0.updated_at', '2026-07-10 09:30'));
+});
+
+test('sort 未指定は既定順 (created_at desc, id desc) を維持する (回帰)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
+    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.data.0.id', $new->id)
+            ->where('manuals.data.1.id', $old->id)
+            ->where('manualFilters.sort', null)
+            ->where('manualFilters.mine', false));
+});
+
+test('sort 各値で並べ替える (updated / title × asc/desc)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $a = VideoManual::factory()->forProject($project)->create([
+        'title' => 'apple', 'updated_at' => '2026-07-01 00:00:00',
+    ]);
+    $b = VideoManual::factory()->forProject($project)->create([
+        'title' => 'banana', 'updated_at' => '2026-07-02 00:00:00',
+    ]);
+    $c = VideoManual::factory()->forProject($project)->create([
+        'title' => 'cherry', 'updated_at' => '2026-07-03 00:00:00',
+    ]);
+
+    $order = function (string $sort) use ($owner, $project): array {
+        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort={$sort}")
+            ->inertiaPage()['props'];
+
+        return array_column($props['manuals']['data'], 'id');
+    };
+
+    expect($order('updated_desc'))->toBe([$c->id, $b->id, $a->id]);
+    expect($order('updated_asc'))->toBe([$a->id, $b->id, $c->id]);
+    expect($order('title_asc'))->toBe([$a->id, $b->id, $c->id]);
+    expect($order('title_desc'))->toBe([$c->id, $b->id, $a->id]);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc")
+        ->assertInertia(fn (Assert $page) => $page->where('manualFilters.sort', 'updated_desc'));
+});
+
+test('sort allowlist 外は既定順へフォールバック (manualFilters.sort=null)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $old = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-01 00:00:00']);
+    $new = VideoManual::factory()->forProject($project)->create(['created_at' => '2026-07-05 00:00:00']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?sort=bogus")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.data.0.id', $new->id)
+            ->where('manuals.data.1.id', $old->id)
+            ->where('manualFilters.sort', null));
+});
+
+test('同値 updated_at でも id tie-breaker でページ境界に重複/欠落が無い', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    // 15 件すべて同一 updated_at (tie-breaker が無いとページ間で不安定になる)
+    VideoManual::factory()->forProject($project)->count(15)->create(['updated_at' => '2026-07-01 00:00:00']);
+
+    $ids = function (int $page) use ($owner, $project): array {
+        $props = $this->actingAs($owner)->get("/projects/{$project->id}?sort=updated_desc&page={$page}")
+            ->inertiaPage()['props'];
+
+        return array_column($props['manuals']['data'], 'id');
+    };
+
+    $page1 = $ids(1);
+    $page2 = $ids(2);
+
+    expect($page1)->toHaveCount(10);
+    expect($page2)->toHaveCount(5);
+    // 排他 (重複なし) かつ全 15 件を被覆 (欠落なし)
+    expect(array_intersect($page1, $page2))->toBe([]);
+    expect(count(array_unique(array_merge($page1, $page2))))->toBe(15);
+});
+
+test('mine=1 は自ユーザー作成分のみに絞る', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $mine = VideoManual::factory()->forProject($project)->createdBy($owner)->create(['title' => '自作']);
+    VideoManual::factory()->forProject($project)->createdBy($other)->create(['title' => '他人作']);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?mine=1")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $mine->id)
+            ->where('manualFilters.mine', true));
+});
+
+test('mine と category/status/q/sort の併用で結合絞り込みできる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $other = attachOrganizationMember($organization);
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    // 目標: 自作 + 該当カテゴリ + published + タイトル一致
+    $target = VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
+        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value, 'updated_at' => '2026-07-05 00:00:00',
+    ]);
+    // ノイズ: 他人作 / 別カテゴリ / 別 status / 別タイトル
+    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($other)->create([
+        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
+    ]);
+    VideoManual::factory()->forProject($project)->createdBy($owner)->create([
+        'title' => 'ネジ締め', 'status' => VideoManualStatus::Published->value,
+    ]);
+    VideoManual::factory()->forProject($project)->forCategory($category)->createdBy($owner)->create([
+        'title' => 'ネジ締め', 'status' => VideoManualStatus::Draft->value,
+    ]);
+
+    $this->actingAs($owner)
+        ->get("/projects/{$project->id}?mine=1&category={$category->id}&status=published&q=ネジ&sort=updated_desc")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.id', $target->id));
+});
diff --git a/tests/js/pages/CaptureIndex.test.ts b/tests/js/pages/CaptureIndex.test.ts
new file mode 100644
index 0000000..0382e5a
--- /dev/null
+++ b/tests/js/pages/CaptureIndex.test.ts
@@ -0,0 +1,72 @@
+import { afterEach, describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import { router } from "@inertiajs/svelte";
+import CaptureIndex from "@/pages/Capture/Index.svelte";
+import type { CaptureManualSummary } from "@/types/capture";
+
+/*
+ * 撮影 PWA 一覧 Capture/Index: 自作フィルタ (mine トグル) の GET クエリと
+ * カードの作成者名 (null 時「不明」) 表示を固定する。
+ */
+
+function makeSummary(overrides: Partial<CaptureManualSummary> = {}): CaptureManualSummary {
+    return {
+        id: 1,
+        title: "ネジ締め作業",
+        status: "ready",
+        category_id: 1,
+        category_name: "準備作業",
+        cuts_total: 3,
+        cuts_adopted: 1,
+        cuts_with_takes: 2,
+        updated_at: "2026-07-11T09:00:00+09:00",
+        creator_name: "編集 花子",
+        ...overrides,
+    };
+}
+
+const baseProps = {
+    project: { id: 1, name: "サンプルプロジェクト" },
+    manuals: [makeSummary()],
+    categories: [{ id: 1, name: "準備作業" }],
+    filters: { category: null, q: null, mine: false },
+};
+
+describe("Capture/Index 自作フィルタ・作成者表示", () => {
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("カードに作成者名と更新日を描画する", () => {
+        render(CaptureIndex, { props: baseProps });
+
+        expect(screen.getByText(/編集 花子 ・ 更新/)).toBeInTheDocument();
+    });
+
+    it("creator_name が null のときは「不明」を表示する", () => {
+        render(CaptureIndex, {
+            props: { ...baseProps, manuals: [makeSummary({ creator_name: null })] },
+        });
+
+        expect(screen.getByText(/不明 ・ 更新/)).toBeInTheDocument();
+    });
+
+    it("自作トグルで GET クエリに mine=1 が載る", async () => {
+        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
+        render(CaptureIndex, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("capture-mine"));
+
+        expect(getSpy).toHaveBeenCalledTimes(1);
+        expect(getSpy.mock.calls[0][0]).toBe("/app/projects/1/manuals");
+        expect(getSpy.mock.calls[0][1]).toEqual({ mine: "1" });
+    });
+
+    it("filters.mine=true は props からトグル状態を復元する", () => {
+        render(CaptureIndex, {
+            props: { ...baseProps, filters: { category: null, q: null, mine: true } },
+        });
+
+        expect((screen.getByTestId("capture-mine") as HTMLInputElement).checked).toBe(true);
+    });
+});
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index 99497a4..248d4b1 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -5,7 +5,13 @@ import Show from "@/pages/Projects/Show.svelte";
 import type { ManualFilters, ManualListItem, PaginationMeta } from "@/types/manual";
 
 const emptyMeta: PaginationMeta = { current_page: 1, last_page: 1, per_page: 10, total: 0 };
-const emptyFilters: ManualFilters = { category: null, status: null, q: null };
+const emptyFilters: ManualFilters = {
+    category: null,
+    status: null,
+    q: null,
+    sort: null,
+    mine: false,
+};
 
 const manualsFixture: ManualListItem[] = [
     {
@@ -13,14 +19,18 @@ const manualsFixture: ManualListItem[] = [
         title: "ネジ締め作業",
         status: "draft",
         category: { id: 1, name: "準備作業" },
+        creator: { id: 2, name: "編集 花子" },
         created_at: "2026-07-10 12:00",
+        updated_at: "2026-07-11 09:00",
     },
     {
         id: 2,
         title: "洗浄手順",
         status: "published",
         category: null,
+        creator: null,
         created_at: "2026-07-10 13:00",
+        updated_at: "2026-07-11 10:00",
     },
 ];
 
@@ -110,9 +120,10 @@ describe("Projects/Show", () => {
             /\/projects\/1\/manuals\/1$/,
         );
         expect(screen.getByTestId("manual-status-1")).toHaveTextContent("下書き");
-        expect(screen.getByText(/準備作業 ・ 2026-07-10 12:00/)).toBeInTheDocument();
+        // カテゴリ ・ 作成者 ・ 更新日 (作成者 null は「不明」)
+        expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
         expect(screen.getByTestId("manual-status-2")).toHaveTextContent("公開済み");
-        expect(screen.getByText(/未分類 ・ 2026-07-10 13:00/)).toBeInTheDocument();
+        expect(screen.getByText(/未分類 ・ 不明 ・ 更新 2026-07-11 10:00/)).toBeInTheDocument();
     });
 
     it("manuals が空のときは EmptyState を表示する", () => {
@@ -191,6 +202,78 @@ describe("Projects/Show", () => {
     });
 });
 
+describe("Projects/Show 並べ替え・自作フィルタ", () => {
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("並べ替え select と自作 checkbox を描画する (disabled 不使用)", () => {
+        render(Show, { props: baseProps });
+
+        const sortSelect = screen.getByTestId("manual-filter-sort");
+        expect(sortSelect).toBeInTheDocument();
+        expect(sortSelect).not.toBeDisabled();
+        expect(screen.getByRole("option", { name: "更新が新しい順" })).toBeInTheDocument();
+        expect(screen.getByRole("option", { name: "タイトル昇順" })).toBeInTheDocument();
+
+        const mine = screen.getByTestId("manual-filter-mine");
+        expect(mine).toBeInTheDocument();
+        expect(mine).not.toBeDisabled();
+    });
+
+    it("並べ替え変更で GET クエリに sort が載る (page は載せない = 1 ページ目へ)", async () => {
+        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
+        render(Show, { props: baseProps });
+
+        await fireEvent.change(screen.getByTestId("manual-filter-sort"), {
+            target: { value: "updated_desc" },
+        });
+
+        expect(getSpy).toHaveBeenCalledTimes(1);
+        expect(getSpy.mock.calls[0][0]).toBe("/projects/1");
+        expect(getSpy.mock.calls[0][1]).toEqual({ sort: "updated_desc" });
+        expect(getSpy.mock.calls[0][1]).not.toHaveProperty("page");
+    });
+
+    it("自作 checkbox で GET クエリに mine=1 が載る", async () => {
+        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
+        render(Show, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("manual-filter-mine"));
+
+        expect(getSpy).toHaveBeenCalledTimes(1);
+        expect(getSpy.mock.calls[0][1]).toEqual({ mine: 1 });
+    });
+
+    it("q 入力中に並べ替えを操作しても trim 済み q がクエリに維持される", async () => {
+        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
+        render(Show, { props: baseProps });
+
+        await fireEvent.input(screen.getByTestId("manual-filter-q"), {
+            target: { value: "  ネジ  " },
+        });
+        await fireEvent.change(screen.getByTestId("manual-filter-sort"), {
+            target: { value: "title_asc" },
+        });
+
+        expect(getSpy.mock.calls[0][1]).toEqual({ q: "ネジ", sort: "title_asc" });
+    });
+
+    it("既存フィルタ状態は props から復元される (sort / mine)", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                manualFilters: { ...emptyFilters, sort: "title_desc", mine: true },
+            },
+        });
+
+        expect((screen.getByTestId("manual-filter-sort") as HTMLSelectElement).value).toBe(
+            "title_desc",
+        );
+        expect((screen.getByTestId("manual-filter-mine") as HTMLInputElement).checked).toBe(true);
+    });
+});
+
 describe("Projects/Show メンバー管理", () => {
     afterEach(() => {
         vi.restoreAllMocks();

```

### テスト結果

- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test app/`: passed
- `pnpm typecheck`: 通過 (tsc --noEmit)
- `pnpm lint`: 通過 (eslint)
- `pnpm build`: 成功
- `pnpm test` (vitest): 77 files / 659 passed
- `composer test` (Pest --parallel): 1769 tests / 1767 passed / 2 skipped / 0 failed
  - 内 T053 関連: ProjectShowManualsTest + CaptureManualBrowsingTest = 26 passed / 381 assertions

上記を踏まえ、Critical / Warning / Suggestion に分類し、最後に APPROVED / CHANGES_REQUESTED を明示してレビューせよ。
