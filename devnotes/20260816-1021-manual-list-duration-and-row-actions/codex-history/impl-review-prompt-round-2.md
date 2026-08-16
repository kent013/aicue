# Round 2: Round 1 指摘への対応

Round 1 の Warning 4 件は**すべて対応**した (反論・見送りは無し)。
対応マトリクスと、変更後の差分 (Round 1 からの追加分のみ) を示す。

## 対応マトリクス

# 対応マトリクス: impl-review Round 1

Codex 返答: `impl-review-round-1.md` (全体判定 CHANGES_REQUESTED / Warning 4 件・Critical 0 件)

## [Warning] 範囲外ページの丸めが `total() > 0` のときだけ (ProjectController::manualRows)

- 判断: **対応する**
- 根拠: 0 件の一覧でも `lastPage()` は 1 を返すため、丸めないと
  `current_page=99 / last_page=1` という**内部で食い違った meta** を props に載せることになる。
  「meta が正本」と宣言している以上、正本が矛盾した値を持つ状態を残さない。
  再クエリは 0 件ページに着地したときだけ発生し、一覧の常時コストは増えない。
- 対応内容: 丸め条件から `&& $paginated->total() > 0` を削除し、理由をコメントに明記。
  テスト追加 (`ProjectShowManualsTest`): 0 件の一覧に `?page=99` /
  `?page=99999999999999999999999` で入っても `current_page=1 / last_page=1 / total=0`。

## [Warning] `category` が生文字列のまま Location に戻る (ManualListQuery)

- 判断: **対応する**
- 根拠: `0003` は絞り込みとしては効くのに `manualFilters.category` が `'0003'` のままなので、
  フィルタ select (値は `String(category.id)`) が選択状態を復元できず**表示と実際の絞り込みが食い違う**。
  巨大な数字列がそのまま着地先 URL に残るのも「生の入力を素通ししない」という設計意図に反する。
  破棄 (null 化) にしないのは、絞り込みが消えて**全件が出る**驚きの方向に倒れるためで、
  正規化なら結果集合は変わらない。
- 対応内容: `ManualListQuery::fromRequest()` で数値 category を `(string) (int)` の正規形へ畳む。
  桁溢れは `(int)` が PHP_INT_MAX へ飽和して該当なしになる (URL も有界)。
  テスト追加: 一覧側 (`0003` → `3` で絞り込み + `manualFilters.category` が正規形 /
  桁溢れは 0 件)、削除の着地側 (`ManualRowActionsTest`: `?category=000003` → `?category=3`)。

## [Warning] `EagerLoadCandidate` の前提が弱く VideoManual.php が免除領域になる

- 判断: **対応する**
- 根拠: 指摘のとおり「`output_path` を参照しない」だけでは、同じファイルに 2 本目の候補 relation を
  足しても赤くならない。deny-by-default の目録に**ファイル単位の免除**を作ってしまう。
  区分を新設した以上、前提は「1 本しか無い」ところまで機械で押さえるべきである。
- 対応内容: gate (`CurrentRenderArtifactInventoryTest`) の前提検査を強化した。
  - ケース 7: `EagerLoadCandidate` は `Models/VideoManual.php` ちょうど 1 ファイル (Canonical と同じ形)
  - ケース 8: (a) `output_path` を参照しない / (b) succeeded 条件の出現数がちょうど 1・
    `ofMany(` 1 回・`hasOne(` 1 回 / (c) `latestSucceededRender()` の宣言が在り
    `RenderKind::Render` の参照がちょうど 1 (preview を混ぜたら赤くなる)
  - scanner 自己検証を追加 (個数計測と宣言名検出が空振りしないこと)
  - 保証しないもの (helper 切り出し・動的呼び出し・別ファイルへの移設) をコメントに明記
- 補足: 「将来同ファイルに別の候補 relation が増えたら止められない」という指摘は (b) で塞いだ。

## [Warning] parity テストに `ready + succeeded render (output_path あり)` の endpoint 側が無い

- 判断: **対応する**
- 根拠: 「download endpoint が 302 を返す条件と 1 対 1」と書くなら、published が外れている
  ケースでも**両者が一致する**ことをテストが示すべきである (一覧側だけでは片肺)。
- 対応内容: `ManualRowDownloadableParityTest` に追加。選択式は行を返す (published 判定を持たない
  = 名前どおりの責務) が、一覧 props は `downloadable=false` / endpoint は 404 になることを固定。
  同じケースで `duration_ms` が null であることも併せて固定した。

## 検証

- `composer test`: 5342 tests / 5340 passed / 2 skipped (0 failed)
- `composer phpstan`: No errors / `vendor/bin/pint --test`: passed


## 追加変更の差分 (Round 1 実装からの差分)

```diff
diff --git a/app/DataTransferObjects/Manual/ManualListQuery.php b/app/DataTransferObjects/Manual/ManualListQuery.php
new file mode 100644
index 0000000..29cf97c
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ManualListQuery.php
@@ -0,0 +1,153 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Enums\Manual\ManualSortOption;
+use App\Enums\Manual\VideoManualStatus;
+use Illuminate\Http\Request;
+
+/**
+ * 動画マニュアル一覧の GET クエリ (allowlist 済みの値)。
+ *
+ * **唯一の解析点**である: 一覧の絞り込み (ProjectController::show) と、
+ * 行内削除の着地先 (VideoManualController::destroy が redirect に載せ直す値) が
+ * 同じ VO を通るため、両者が食い違うことが構造的に起きない。
+ *
+ * 値の約束:
+ * - `category`: 数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null。それ以外は null
+ * - `status`: VideoManualStatus の値のみ。それ以外は null
+ * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
+ *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
+ *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
+ *   201 文字目以降が一致に寄与することは無い
+ * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
+ * - `mine`: 自分の作成分のみ
+ * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
+ *   (「最後の方を見たい」意図に近い側へ倒す。着地は一覧側の丸めで最終ページになる)
+ */
+final readonly class ManualListQuery
+{
+    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
+    public const int MAX_KEYWORD_LENGTH = 200;
+
+    /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
+    public const int PER_PAGE = 10;
+
+    public function __construct(
+        public ?string $category,
+        public ?string $status,
+        public ?string $keyword,
+        public ?ManualSortOption $sort,
+        public bool $mine,
+        public int $page,
+    ) {}
+
+    /**
+     * 受け付けるページ番号の上限。
+     *
+     * チューニング値ではなく**計算安全性の境界**である: paginator の offset は
+     * `($page - 1) * PER_PAGE` で求まるため、この上限が無いと
+     * `ctype_digit` を通った巨大な数字列 ((int) キャストで PHP_INT_MAX へ飽和する) が
+     * int 範囲を超える乗算 (= float 化) を起こす。PER_PAGE から導出しているので
+     * 説明のつかない定数にはならない。
+     *
+     * **定数ではなくメソッドである理由**: クラス定数の初期化式に関数呼び出しは書けない
+     * (`const int MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE);` はコンパイルエラー)。
+     */
+    public static function maxPage(): int
+    {
+        return intdiv(PHP_INT_MAX, self::PER_PAGE);
+    }
+
+    public static function fromRequest(Request $request): self
+    {
+        $category = $request->query('category');
+        $category = is_string($category) && $category !== '' ? $category : null;
+        if ($category !== null && $category !== 'uncategorized') {
+            // 数値 id 以外は破棄。数値は**正規形へ畳む** ('0003' → '3')。
+            // 破棄にしないのは絞り込みが消えて全件が出る方向に倒れるためで、正規化なら
+            // 同じ結果集合のまま「フィルタ select の選択値」「着地先 URL」と一致する。
+            // 桁溢れは (int) が PHP_INT_MAX へ飽和して該当なしになる (URL も有界に保たれる)。
+            $category = ctype_digit($category) ? (string) (int) $category : null;
+        }
+
+        $status = $request->query('status');
+        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;
+
+        $keyword = $request->query('q');
+        $keyword = is_string($keyword) && trim($keyword) !== ''
+            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
+            : null;
+
+        $sortRaw = $request->query('sort');
+        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
+        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;
+
+        // (int) は PHP_INT_MAX へ飽和するため、上限で丸めてから使う
+        // (offset 計算 ($page - 1) * PER_PAGE を int 範囲に収める)
+        $pageRaw = $request->query('page');
+        $page = is_string($pageRaw) && ctype_digit($pageRaw)
+            ? min(max(1, (int) $pageRaw), self::maxPage())
+            : 1;
+
+        return new self(
+            category: $category,
+            status: $status,
+            keyword: $keyword,
+            sort: $sort,
+            mine: $request->boolean('mine'), // "1"/"true" を bool 正規化
+            page: $page,
+        );
+    }
+
+    /**
+     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
+     * **page を含めない**: ページ位置は manuals.meta.current_page が唯一の正本である
+     * (2 か所に持つと必ず食い違う)。
+     *
+     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
+     */
+    public function toProps(): array
+    {
+        return [
+            'category' => $this->category,
+            'status' => $this->status,
+            'q' => $this->keyword,
+            'sort' => $this->sort?->value, // string|null (TS の ManualFilters.sort と一致)
+            'mine' => $this->mine,
+        ];
+    }
+
+    /**
+     * この絞り込みを再現する route() 用クエリ (既定値は載せない = URL を短く保つ)。
+     * 値は上の allowlist を通った後のものだけである (生の入力を Location に素通ししない)。
+     *
+     * @return array<string, string|int>
+     */
+    public function toQueryParams(): array
+    {
+        $params = [];
+        if ($this->category !== null) {
+            $params['category'] = $this->category;
+        }
+        if ($this->status !== null) {
+            $params['status'] = $this->status;
+        }
+        if ($this->keyword !== null) {
+            $params['q'] = $this->keyword;
+        }
+        if ($this->sort !== null) {
+            $params['sort'] = $this->sort->value;
+        }
+        if ($this->mine) {
+            $params['mine'] = 1;
+        }
+        if ($this->page > 1) {
+            $params['page'] = $this->page;
+        }
+
+        return $params;
+    }
+}
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index f7ed3fe..1842fe9 100644
--- a/app/Http/Controllers/Projects/ProjectController.php
+++ b/app/Http/Controllers/Projects/ProjectController.php
@@ -4,8 +4,9 @@
 
 namespace App\Http\Controllers\Projects;
 
+use App\DataTransferObjects\Manual\ManualListItemData;
+use App\DataTransferObjects\Manual\ManualListQuery;
 use App\Enums\Manual\ManualSortOption;
-use App\Enums\Manual\VideoManualStatus;
 use App\Http\Concerns\ResolvesCurrentOrganization;
 use App\Http\Controllers\Controller;
 use App\Http\Requests\Projects\StoreProjectRequest;
@@ -16,6 +17,7 @@
 use App\Models\Project;
 use App\Models\User;
 use App\Models\VideoManual;
+use App\Services\Manual\ManualRowAbilities;
 use App\Services\Project\ProjectService;
 use App\Support\Seo\SeoManager;
 use Illuminate\Http\RedirectResponse;
@@ -112,7 +114,7 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
             ->values()
             ->all();
 
-        $filters = $this->parseManualFilters($request);
+        $listQuery = ManualListQuery::fromRequest($request);
 
         // memberRows は members prop と assignableUsers 導出の双方で使うため 1 度だけ算出する
         $memberRows = $this->memberRows($organization, $project, $canManage);
@@ -133,135 +135,88 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
             // payload 生成時点で絞る = canViewMemberEmails と同じ流儀)
             'assignableUsers' => $this->assignableUserRows($organization, $memberRows, $canManage),
             // 動画マニュアル一覧 (専用 index は持たず本画面に内包。GET クエリで絞り込み + paginate)
-            'manuals' => $this->manualRows($project, $filters, $user->id),
+            'manuals' => $this->manualRows($project, $listQuery, $user),
             'categories' => $this->categoryRows($project),
-            'manualFilters' => $this->toManualFilterProps($filters),
+            'manualFilters' => $listQuery->toProps(),
             // 管理メニュー導線 (doc/04: 管理者のみサイドバー表示)。単一根拠は Gate
             'canManageMembers' => $user->can('manageMembers', $organization),
         ]);
     }
 
     /**
-     * 動画マニュアル一覧の GET クエリ絞り込み条件。
-     * category は「数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null」、
-     * status は VideoManualStatus の値のみ許容 (不正値は無視 = null)、
-     * sort は ManualSortOption の allowlist のみ (不正値は null = 既定順)、
-     * mine は自分の作成分のみに絞る bool。
-     *
-     * @return array{category: string|null, status: string|null, q: string|null,
-     *   sort: ManualSortOption|null, mine: bool}
-     */
-    private function parseManualFilters(Request $request): array
-    {
-        $category = $request->query('category');
-        $category = is_string($category) && $category !== '' ? $category : null;
-        if ($category !== null && $category !== 'uncategorized' && ! ctype_digit($category)) {
-            $category = null;
-        }
-
-        $status = $request->query('status');
-        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;
-
-        $q = $request->query('q');
-        $q = is_string($q) && trim($q) !== '' ? trim($q) : null;
-
-        $sortRaw = $request->query('sort');
-        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
-        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;
-
-        return [
-            'category' => $category,
-            'status' => $status,
-            'q' => $q,
-            'sort' => $sort,
-            'mine' => $request->boolean('mine'), // "1"/"true" を bool 正規化
-        ];
-    }
-
-    /**
-     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
-     * PHP 内部表現は ManualSortOption を持つため、prop 化時に string|null へ落とす。
-     *
-     * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
-     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
-     */
-    private function toManualFilterProps(array $filters): array
-    {
-        return [
-            'category' => $filters['category'],
-            'status' => $filters['status'],
-            'q' => $filters['q'],
-            'sort' => $filters['sort']?->value, // string|null (TS の ManualFilters.sort と一致)
-            'mine' => $filters['mine'],
-        ];
-    }
-
-    /**
-     * 動画マニュアル一覧 rows (paginate + typed array で shape を固定)。
+     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
      * 未分類は category => null (フロントは「未分類」を表示する)。
      * creator は退会/削除で解決不可のとき null (実運用では FK RESTRICT で常に解決)。
      *
-     * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
      * @return array{
      *   data: list<array{id: int, title: string, status: string,
      *     category: array{id: int, name: string}|null,
      *     creator: array{id: int, name: string}|null,
-     *     created_at: string, updated_at: string}>,
+     *     created_at: string, updated_at: string,
+     *     duration_ms: int|null, downloadable: bool, deletable: bool}>,
      *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
      * }
      */
-    private function manualRows(Project $project, array $filters, int $viewerId): array
+    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
     {
-        $query = $project->manuals()->with(['category', 'creator']);
+        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
+        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);
 
         // 並べ替え (allowlist enum 由来のカラム名のみ。既定は現行踏襲 created_at desc, id desc)
-        $orderings = $filters['sort']?->orderings() ?? ManualSortOption::defaultOrderings();
+        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
         foreach ($orderings as $ordering) {
             /** @var ManualOrdering $ordering */
-            $query->orderBy($ordering['column'], $ordering['direction']);
+            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
         }
 
-        if ($filters['mine']) {
+        if ($listQuery->mine) {
             // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
-            $query->where('created_by', $viewerId);
+            $baseQuery->where('created_by', $user->id);
         }
-        if ($filters['category'] === 'uncategorized') {
-            $query->whereNull('category_id');
-        } elseif ($filters['category'] !== null) {
-            $query->where('category_id', (int) $filters['category']);
+        if ($listQuery->category === 'uncategorized') {
+            $baseQuery->whereNull('category_id');
+        } elseif ($listQuery->category !== null) {
+            $baseQuery->where('category_id', (int) $listQuery->category);
         }
-        if ($filters['status'] !== null) {
-            $query->where('status', $filters['status']);
+        if ($listQuery->status !== null) {
+            $baseQuery->where('status', $listQuery->status);
         }
-        if ($filters['q'] !== null) {
+        if ($listQuery->keyword !== null) {
             // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
-            $query->where('title', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
+            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
         }
 
-        $paginated = $query->paginate(10)->withQueryString();
+        $paginated = (clone $baseQuery)
+            ->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
+            ->withQueryString();
+
+        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
+        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
+        // **0 件のときも丸める**: 一覧が空でも lastPage() は 1 なので、丸めないと
+        // current_page=99 / last_page=1 という食い違った meta を渡すことになる。
+        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
+        // meta.current_page を見る (**props が正本**であり redirect はしない)。
+        if ($paginated->currentPage() > $paginated->lastPage()) {
+            $paginated = (clone $baseQuery)
+                ->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
+                ->withQueryString();
+        }
 
-        $data = [];
+        /** @var list<VideoManual> $manuals */
+        $manuals = [];
         foreach ($paginated->items() as $manual) {
             Assert::isInstanceOf($manual, VideoManual::class);
-            $category = $manual->category;
-            $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
-            $data[] = [
-                'id' => $manual->id,
-                'title' => $manual->title,
-                'status' => $manual->status->value,
-                'category' => $category === null
-                    ? null
-                    : ['id' => $category->id, 'name' => $category->name],
-                'creator' => $creator === null
-                    ? null
-                    : ['id' => $creator->id, 'name' => $creator->name],
-                'created_at' => $manual->created_at?->format('Y-m-d H:i') ?? '',
-                'updated_at' => $manual->updated_at?->format('Y-m-d H:i') ?? '',
-            ];
+            $manuals[] = $manual;
         }
 
+        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
+        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);
+
         return [
-            'data' => $data,
+            'data' => array_map(
+                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
+                $manuals,
+            ),
             'meta' => [
                 'current_page' => $paginated->currentPage(),
                 'last_page' => $paginated->lastPage(),
diff --git a/tests/Architecture/CurrentRenderArtifactInventoryTest.php b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
index cb4d204..8ecb9df 100644
--- a/tests/Architecture/CurrentRenderArtifactInventoryTest.php
+++ b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
@@ -27,6 +27,11 @@
  *   **連続 token 列**を持つ (= 世代の大小比較であって最新 1 件の選択ではない)。
  *   前提が崩れた瞬間に区分ごと再審査になる。
  *
+ * 免除区分 (EagerLoadCandidate = 一覧が eager load する候補行の relation) の前提:
+ *   `output_path` を 1 度も参照しない (= 受け取れるかを判断しない。決定は Canonical に残る)。
+ *   候補行と Canonical が同じ行を指すことは behavioral な parity テストの担当で、
+ *   ここが固定するのは「判断を持ち込んでいない」ことだけである。
+ *
  * 保証しないもの (誇張しない):
  * - 閉じるのは**ファイル粒度**の直接クエリだけである。登録済みファイル内でメソッドを増やして
  *   選択式を書く経路は検出しない (fail-first は behavioral テストが担う)
@@ -168,6 +173,117 @@ public static function hasIdComparison(array $tokens): bool
         return false;
     }
 
+    /**
+     * EagerLoadCandidate の前提: `output_path` を 1 度も参照しない
+     * (受け取れるかの判断を持ち込んでいない = 決定は Canonical に残っている)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function hasOutputPathReference(array $tokens): bool
+    {
+        foreach ($tokens as $token) {
+            if ($token['id'] === T_STRING && $token['text'] === 'output_path') {
+                return true;
+            }
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'output_path') {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
+    /**
+     * status 群の出現**数** (JobStatus::Succeeded と 'succeeded' リテラルの合計)。
+     * 候補行が 1 つだけであることを数で固定するために使う
+     * (「1 ファイルまるごと免除」にしない = 同じファイルに 2 本目の選択式を足せない)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function countSucceededStatusMarkers(array $tokens): int
+    {
+        $count = 0;
+        $total = count($tokens);
+        for ($i = 0; $i < $total; $i++) {
+            $token = $tokens[$i];
+            if ($token['id'] === T_CONSTANT_ENCAPSED_STRING && trim($token['text'], "'\"") === 'succeeded') {
+                $count++;
+
+                continue;
+            }
+            if (self::classNameAt($tokens, $i) !== 'JobStatus') {
+                continue;
+            }
+            if ($i + 2 < $total && $tokens[$i + 1]['id'] === T_DOUBLE_COLON
+                && $tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === 'Succeeded') {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /**
+     * `{name}(` の呼び出し回数。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function countCalls(array $tokens, string $name): int
+    {
+        $count = 0;
+        $total = count($tokens);
+        for ($i = 0; $i < $total - 1; $i++) {
+            if ($tokens[$i]['id'] === T_STRING && $tokens[$i]['text'] === $name
+                && $tokens[$i + 1]['id'] === null && $tokens[$i + 1]['text'] === '(') {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /**
+     * `{Enum}::{Case}` の参照回数 (部分修飾・完全修飾も末尾セグメントで判定)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function countEnumCaseReferences(array $tokens, string $enum, string $case): int
+    {
+        $count = 0;
+        $total = count($tokens);
+        for ($i = 0; $i < $total - 2; $i++) {
+            if (self::classNameAt($tokens, $i) !== $enum) {
+                continue;
+            }
+            if ($tokens[$i + 1]['id'] !== T_DOUBLE_COLON) {
+                continue;
+            }
+            if ($tokens[$i + 2]['id'] === T_STRING && $tokens[$i + 2]['text'] === $case) {
+                $count++;
+            }
+        }
+
+        return $count;
+    }
+
+    /**
+     * `function {name}` の宣言があるか (候補行 relation の名前を pin する)。
+     *
+     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
+     */
+    public static function declaresFunction(array $tokens, string $name): bool
+    {
+        $total = count($tokens);
+        for ($i = 0; $i < $total - 1; $i++) {
+            if ($tokens[$i]['id'] === T_FUNCTION
+                && $tokens[$i + 1]['id'] === T_STRING && $tokens[$i + 1]['text'] === $name) {
+                return true;
+            }
+        }
+
+        return false;
+    }
+
     /** @return list<string> 指定区分に登録された app/ 相対パス (昇順) */
     public static function filesOfKind(RenderArtifactSelectionKind $kind): array
     {
@@ -313,6 +429,68 @@ public static function phpFiles(string $dir): array
     }
 });
 
+test('ケース 7: EagerLoadCandidate は Models/VideoManual.php ただ 1 ファイルである', function (): void {
+    expect(RenderArtifactSelectionScanner::filesOfKind(RenderArtifactSelectionKind::EagerLoadCandidate))
+        ->toBe(['Models/VideoManual.php']);
+});
+
+test('ケース 8: EagerLoadCandidate の前提 (受け取れるかを判断しない / 候補行はちょうど 1 本)', function (): void {
+    $tokens = RenderArtifactSelectionScanner::tokensOf('Models/VideoManual.php');
+
+    // (a) 受け取れるかの判断を持ち込んでいない (決定は Canonical に残る)
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($tokens))->toBeFalse(
+        'Models/VideoManual.php が output_path を参照しました。候補行の relation が「受け取れるか」の'
+        .'判断を持ち始めた可能性があります (選択式の単一化が崩れるため区分を再審査してください)');
+
+    // (b) 候補行はちょうど 1 本 (「1 ファイルまるごと免除」にしない = 2 本目の選択式を足せない)
+    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($tokens))->toBe(1,
+        'succeeded 条件が 2 つ以上あります。候補行 relation が増えた可能性があるため区分を再審査してください');
+    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'ofMany'))->toBe(1,
+        'ofMany( が 1 回ではありません (候補行の選び方が増減しています)');
+    expect(RenderArtifactSelectionScanner::countCalls($tokens, 'hasOne'))->toBe(1,
+        'hasOne( が 1 回ではありません (候補行 relation が増減しています)');
+
+    // (c) 候補行の名前と対象種別を pin する (rename / kind 変更は再審査の合図)
+    expect(RenderArtifactSelectionScanner::declaresFunction($tokens, 'latestSucceededRender'))->toBeTrue(
+        '候補行 relation latestSucceededRender() が見つかりません (rename したら目録と parity テストを見直すこと)');
+    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($tokens, 'RenderKind', 'Render'))->toBe(1,
+        '候補行が見る種別 (RenderKind::Render) の参照数が変わりました (preview を混ぜていないか再審査)');
+
+    // **保証しないもの**: これは字句の検査であり、helper へ切り出した同義式・動的呼び出し・
+    // 別ファイルへ移した候補 relation は捉えない (母集団の検査は ケース 2 が担う)。
+});
+
+test('scanner 自己検証: EagerLoadCandidate の前提検査 (output_path / 個数 / 宣言名)', function (): void {
+    $propertyAccess = PhpTokenScan::normalize('<?php $p = $job->output_path;');
+    $literal = PhpTokenScan::normalize("<?php \$q->whereNotNull('output_path');");
+    $none = PhpTokenScan::normalize("<?php \$q->where('kind', 'render');");
+    $commentOnly = PhpTokenScan::normalize("<?php\n// output_path はコメント\nclass Example {}");
+
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($propertyAccess))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($literal))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($none))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($commentOnly))->toBeFalse();
+
+    $twoMarkers = PhpTokenScan::normalize(
+        "<?php \$a = JobStatus::Succeeded; \$b = 'succeeded';",
+    );
+    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($twoMarkers))->toBe(2);
+    expect(RenderArtifactSelectionScanner::countSucceededStatusMarkers($none))->toBe(0);
+
+    $calls = PhpTokenScan::normalize('<?php $q->ofMany([])->ofMany([]);');
+    expect(RenderArtifactSelectionScanner::countCalls($calls, 'ofMany'))->toBe(2);
+    expect(RenderArtifactSelectionScanner::countCalls($calls, 'hasOne'))->toBe(0);
+
+    $kinds = PhpTokenScan::normalize('<?php $a = RenderKind::Render; $b = RenderKind::Preview;');
+    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($kinds, 'RenderKind', 'Render'))->toBe(1);
+    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($kinds, 'RenderKind', 'Preview'))->toBe(1);
+    expect(RenderArtifactSelectionScanner::countEnumCaseReferences($none, 'RenderKind', 'Render'))->toBe(0);
+
+    $declaration = PhpTokenScan::normalize('<?php class E { public function latestSucceededRender() {} }');
+    expect(RenderArtifactSelectionScanner::declaresFunction($declaration, 'latestSucceededRender'))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::declaresFunction($declaration, 'other'))->toBeFalse();
+});
+
 test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
     $tokens = PhpTokenScan::normalize(<<<'PHP'
     <?php
diff --git a/tests/Feature/Manual/ManualRowDownloadableParityTest.php b/tests/Feature/Manual/ManualRowDownloadableParityTest.php
new file mode 100644
index 0000000..e59d4a3
--- /dev/null
+++ b/tests/Feature/Manual/ManualRowDownloadableParityTest.php
@@ -0,0 +1,132 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\RenderKind;
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\Organization;
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Manual\CurrentRenderArtifact;
+use Illuminate\Support\Facades\Storage;
+use Inertia\Testing\AssertableInertia;
+
+/*
+ * T182: 一覧の候補行 relation (VideoManual::latestSucceededRender) と
+ * 受け取り対象の選択式 (CurrentRenderArtifact::currentSucceeded) の**世代定義が一致**すること、
+ * および一覧の downloadable と download endpoint (302 / 404) の判断が一致すること。
+ *
+ * 両者の違いは 1 点だけである: relation は output_path を見ない (候補行を返す) ので、
+ * 「受け取れるか」は呼び出し側が output_path を足して判断する。
+ */
+
+/**
+ * 署名 URL を stub した上で組織・所有者・プロジェクトを用意する
+ * (fake local disk は temporaryUrl を標準サポートしないため)。
+ *
+ * @return array{Organization, User, Project}
+ */
+function parityFixture(): array
+{
+    Storage::fake('s3');
+    Storage::disk('s3')->buildTemporaryUrlsUsing(
+        fn (string $path): string => "https://signed.example/{$path}",
+    );
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    return [$organization, $owner, Project::factory()->forOrganization($organization)->create()];
+}
+
+test('succeeded が 2 世代あるとき両者とも最新の行を指す', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
+    $newest = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')->create();
+
+    $manual->refresh();
+
+    expect($manual->latestSucceededRender?->id)->toBe($newest->id);
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($newest->id);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', true));
+    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
+        ->assertRedirect();
+});
+
+test('最新 succeeded の output_path が null なら旧世代へフォールバックしない (一覧 false / endpoint 404)', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/old.mp4')->create();
+    $stale = RenderJob::factory()->forManual($manual)->succeeded('renders/new.mp4')
+        ->state(fn (): array => ['output_path' => null])->create();
+
+    $manual->refresh();
+
+    // relation は候補行 (output_path を見ない) を返し、選択式は「受け取れない」と答える
+    expect($manual->latestSucceededRender?->id)->toBe($stale->id);
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
+        ->assertNotFound();
+});
+
+test('preview の succeeded しか無いときは両者とも「無し」', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->preview()->succeeded('renders/preview.mp4')->create();
+
+    $manual->refresh();
+
+    expect($manual->latestSucceededRender)->toBeNull();
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
+        ->assertNotFound();
+});
+
+test('failed / running しか無いときは両者とも「無し」', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->failed()->create();
+    RenderJob::factory()->forManual($manual)->running()->create();
+
+    $manual->refresh();
+
+    expect($manual->latestSucceededRender)->toBeNull();
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render))->toBeNull();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (AssertableInertia $page) => $page->where('manuals.data.0.downloadable', false));
+    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
+        ->assertNotFound();
+});
+
+test('published でない行は succeeded render があっても一覧 false / endpoint 404 (公開状態の一致)', function (): void {
+    [, $owner, $project] = parityFixture();
+    $manual = VideoManual::factory()->forProject($project)->create([
+        'status' => VideoManualStatus::Ready->value,
+        'total_length_ms' => 60_000,
+    ]);
+    $job = RenderJob::factory()->forManual($manual)->succeeded('renders/ready.mp4')->create();
+
+    $manual->refresh();
+
+    // 選択式は「どの行か」だけを答える (published 判定は持たない) ので行を返す。
+    // 受け取れるかの判断は一覧 props と endpoint が同じ条件で行い、両者とも「不可」になる。
+    expect($manual->latestSucceededRender?->id)->toBe($job->id);
+    expect(CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)?->id)->toBe($job->id);
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (AssertableInertia $page) => $page
+            ->where('manuals.data.0.downloadable', false)
+            ->where('manuals.data.0.duration_ms', null));
+    $this->actingAs($owner)->get("/projects/{$project->id}/manuals/{$manual->id}/download")
+        ->assertNotFound();
+});
diff --git a/tests/Feature/Projects/ManualListQueryCountTest.php b/tests/Feature/Projects/ManualListQueryCountTest.php
new file mode 100644
index 0000000..c954b67
--- /dev/null
+++ b/tests/Feature/Projects/ManualListQueryCountTest.php
@@ -0,0 +1,55 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Models\Project;
+use App\Models\RenderJob;
+use App\Models\VideoManual;
+use Illuminate\Support\Facades\DB;
+
+/*
+ * T182: 一覧描画のクエリ数が**行数に比例しない**ことを固定する。
+ *
+ * 行ごとに ability を評価したり現行世代の render を引いたりすると、
+ * per_page=10 の一覧で権限解決と render 取得が 10 倍になる。
+ * 計測は「GET 1 回ぶん」に限る (fixture 生成は flushQueryLog で計測外にする)。
+ * 初回リクエスト固有の初期化を計測に混ぜないよう、計測前に暖機の GET を 1 回撃つ。
+ */
+
+test('一覧のクエリ数は行数に比例しない (1 行のページと 10 行のページで同数)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+
+    $singleRowProject = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($singleRowProject)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/1.mp4')->create();
+
+    $tenRowsProject = Project::factory()->forOrganization($organization)->create();
+    foreach (range(1, 10) as $i) {
+        $row = VideoManual::factory()->forProject($tenRowsProject)->published(60_000)->create();
+        RenderJob::factory()->forManual($row)->succeeded("renders/{$i}.mp4")->create();
+    }
+
+    /** @return list<string> 実行された SQL */
+    $measure = function (Project $project) use ($owner): array {
+        DB::enableQueryLog();
+        DB::flushQueryLog();
+        $this->actingAs($owner)->get("/projects/{$project->id}")->assertOk();
+        $log = DB::getQueryLog();
+        DB::disableQueryLog();
+
+        return array_map(fn (array $entry): string => (string) $entry['query'], $log);
+    };
+
+    // 暖機 (初回リクエストだけに出る初期化を計測から外す)
+    $measure($singleRowProject);
+
+    $singleQueries = $measure($singleRowProject);
+    $tenQueries = $measure($tenRowsProject);
+
+    expect($singleQueries)->not->toBeEmpty();
+    expect(count($tenQueries))->toBe(
+        count($singleQueries),
+        '一覧のクエリ数が行数に比例しました (1 行: '.count($singleQueries).' 件 / 10 行: '
+        .count($tenQueries)." 件)。\n10 行ページの SQL:\n".implode("\n", $tenQueries)
+    );
+});
diff --git a/tests/Feature/Projects/ManualRowAbilityPremiseTest.php b/tests/Feature/Projects/ManualRowAbilityPremiseTest.php
new file mode 100644
index 0000000..cc1f2e2
--- /dev/null
+++ b/tests/Feature/Projects/ManualRowAbilityPremiseTest.php
@@ -0,0 +1,100 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
+use App\Models\Category;
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+use App\Services\Manual\ManualRowAbilities;
+
+/*
+ * T182: ManualRowAbilities の**前提**を固定する。
+ *
+ * 前提: download / delete の可否は「その manual が属する project」で決まり、
+ * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
+ * よってページで 1 回だけ評価して全行へ配ってよい。
+ *
+ * **この前提が崩れる policy 変更をしたらこのテストが赤くなる**。そのときは
+ * 可否の評価を行ループへ移し (同時に N+1 の解消も設計し直す)、
+ * ManualRowAbilities の docblock と本テストを書き換えること。
+ */
+
+/**
+ * 同一 project 配下に属性の異なる 3 行を作る (status / 作成者 / カテゴリが全部違う)。
+ *
+ * @return list<VideoManual>
+ */
+function manualRowsWithDifferingAttributes(Project $project, User $creator): array
+{
+    $category = Category::factory()->forProject($project)->create();
+    $other = User::factory()->create();
+
+    return [
+        VideoManual::factory()->forProject($project)->createdBy($creator)->published(60_000)
+            ->forCategory($category)->create(),
+        VideoManual::factory()->forProject($project)->createdBy($other)->create([
+            'status' => VideoManualStatus::Draft->value,
+        ]),
+        VideoManual::factory()->forProject($project)->createdBy($creator)->create([
+            'status' => VideoManualStatus::Ready->value,
+        ]),
+    ];
+}
+
+test('代表行の可否は同一 project の全行を個別評価した結果と一致する (組織 owner)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manuals = manualRowsWithDifferingAttributes($project, $owner);
+
+    $abilities = ManualRowAbilities::forPage($owner, $project, $manuals);
+
+    expect($abilities->canDownload)->toBeTrue();
+    expect($abilities->canDelete)->toBeTrue();
+    foreach ($manuals as $manual) {
+        expect($owner->can('download', $manual))->toBe($abilities->canDownload);
+        expect($owner->can('delete', $manual))->toBe($abilities->canDelete);
+    }
+});
+
+test('撮影者は全行で両方 false、編集者は全行で両方 true (行ごとの実評価と一致)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $shooter = attachOrganizationMember($organization);
+    $shooter->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $shooter, ProjectRole::Member);
+
+    $editor = attachOrganizationMember($organization);
+    $editor->forceFill(['current_organization_id' => $organization->id])->save();
+    attachProjectMember($project, $editor, ProjectRole::Admin);
+
+    $manuals = manualRowsWithDifferingAttributes($project, $owner);
+
+    $shooterAbilities = ManualRowAbilities::forPage($shooter, $project, $manuals);
+    expect($shooterAbilities->canDownload)->toBeFalse();
+    expect($shooterAbilities->canDelete)->toBeFalse();
+
+    $editorAbilities = ManualRowAbilities::forPage($editor, $project, $manuals);
+    expect($editorAbilities->canDownload)->toBeTrue();
+    expect($editorAbilities->canDelete)->toBeTrue();
+
+    foreach ($manuals as $manual) {
+        expect($shooter->can('download', $manual))->toBeFalse();
+        expect($shooter->can('delete', $manual))->toBeFalse();
+        expect($editor->can('download', $manual))->toBeTrue();
+        expect($editor->can('delete', $manual))->toBeTrue();
+    }
+});
+
+test('行が 1 件も無いページでは両方 false (評価しない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $abilities = ManualRowAbilities::forPage($owner, $project, []);
+
+    expect($abilities->canDownload)->toBeFalse();
+    expect($abilities->canDelete)->toBeFalse();
+});
diff --git a/tests/Feature/Projects/ManualRowActionsTest.php b/tests/Feature/Projects/ManualRowActionsTest.php
new file mode 100644
index 0000000..a64e7a0
--- /dev/null
+++ b/tests/Feature/Projects/ManualRowActionsTest.php
@@ -0,0 +1,123 @@
+<?php
+
+declare(strict_types=1);
+
+use App\DataTransferObjects\Manual\ManualListQuery;
+use App\Enums\ProjectRole;
+use App\Models\Category;
+use App\Models\Project;
+use App\Models\VideoManual;
+
+/*
+ * T182: 一覧の行から削除したときの着地 (絞り込み・ページを維持する)。
+ *
+ * 削除要求に付くクエリは**対象の決定には使わない** (対象は route パラメータのみ)。
+ * 着地先の組み立てだけに使い、一覧と同じ allowlist (ManualListQuery) を通す。
+ */
+
+test('絞り込み付きの削除は同じ絞り込み・同じページへ着地する', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    $manual = VideoManual::factory()->forProject($project)->forCategory($category)->create();
+
+    $query = "category={$category->id}&status=published&q=".urlencode('ネジ')
+        .'&sort=title_asc&mine=1&page=2';
+
+    $response = $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/manuals/{$manual->id}?{$query}");
+
+    $response->assertRedirect(
+        "/projects/{$project->id}?category={$category->id}&status=published&q=".urlencode('ネジ')
+        .'&sort=title_asc&mine=1&page=2'
+    );
+    $response->assertSessionHas('success');
+    $this->assertDatabaseMissing('video_manuals', ['id' => $manual->id]);
+});
+
+test('クエリ無しの削除は /projects/{project} へ着地する (詳細画面からの削除の非退行)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}")
+        ->assertRedirect("/projects/{$project->id}");
+});
+
+test('allowlist 外のクエリは着地先の URL に載らない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/manuals/{$manual->id}?sort=".urlencode(';DROP')
+            .'&category=abc&status=bogus')
+        ->assertRedirect("/projects/{$project->id}");
+});
+
+test('page は 1 以下なら着地先の URL に載せない', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    foreach (['abc', '0', '1'] as $raw) {
+        $manual = VideoManual::factory()->forProject($project)->create();
+        $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}?page={$raw}")
+            ->assertRedirect("/projects/{$project->id}");
+    }
+});
+
+test('極端な page の削除でも 500 にならず正規化後の値へ丸まる', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/manuals/{$manual->id}?page=99999999999999999999999")
+        ->assertRedirect("/projects/{$project->id}?page=".ManualListQuery::maxPage());
+});
+
+test('q が 200 文字超のとき着地先の q は先頭 200 文字 (一覧の絞り込みと同じ値)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $keyword = str_repeat('あ', 200);
+
+    $this->actingAs($owner)
+        ->delete("/projects/{$project->id}/manuals/{$manual->id}?q=".urlencode($keyword.'ZZZ'))
+        ->assertRedirect("/projects/{$project->id}?q=".urlencode($keyword));
+});
+
+test('撮影者の行内削除はサーバでも 403 (導線を出さないだけに頼らない)', function (): void {
+    [$organization] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    $project = Project::factory()->forOrganization($organization)->create();
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $manual = VideoManual::factory()->forProject($project)->create();
+
+    $this->actingAs($member)->delete("/projects/{$project->id}/manuals/{$manual->id}?page=2")
+        ->assertForbidden();
+    $this->assertDatabaseHas('video_manuals', ['id' => $manual->id]);
+});
+
+test('他プロジェクトの manual を指す削除は認可より前に 404 (scopeBindings の非退行)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $other = Project::factory()->forOrganization($organization)->create();
+    $manual = VideoManual::factory()->forProject($other)->create();
+
+    $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}?page=2")
+        ->assertNotFound();
+    $this->assertDatabaseHas('video_manuals', ['id' => $manual->id]);
+});
+
+test('着地先の category は正規形になる (生の入力を Location に素通ししない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    $manual = VideoManual::factory()->forProject($project)->create();
+    $padded = str_pad((string) $category->id, 6, '0', STR_PAD_LEFT);
+
+    $this->actingAs($owner)->delete("/projects/{$project->id}/manuals/{$manual->id}?category={$padded}")
+        ->assertRedirect("/projects/{$project->id}?category={$category->id}");
+});
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index 911bef6..448fd6e 100644
--- a/tests/Feature/Projects/ProjectShowManualsTest.php
+++ b/tests/Feature/Projects/ProjectShowManualsTest.php
@@ -3,8 +3,10 @@
 declare(strict_types=1);
 
 use App\Enums\Manual\VideoManualStatus;
+use App\Enums\ProjectRole;
 use App\Models\Category;
 use App\Models\Project;
+use App\Models\RenderJob;
 use App\Models\VideoManual;
 use Inertia\Testing\AssertableInertia as Assert;
 
@@ -271,3 +273,187 @@
             ->has('manuals.data', 1)
             ->where('manuals.data.0.id', $target->id));
 });
+
+/*
+ * T182: 行の再生時間 (duration_ms) と行内操作の可否 (downloadable / deletable)、
+ * 範囲外ページの丸め、q の 200 文字上限。
+ */
+
+test('duration_ms は published の総尺のみ供給する (それ以外は null)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $published = VideoManual::factory()->forProject($project)->published(185_000)
+        ->create(['title' => '公開済み']);
+    // published だが総尺が記録されていない行 (duration_ms = null)
+    $noLength = VideoManual::factory()->forProject($project)->published()
+        ->create(['title' => '尺なし']);
+    // published でない行は総尺が入っていても出さない (古い尺で語らない)
+    $ready = VideoManual::factory()->forProject($project)->create([
+        'title' => '準備完了',
+        'status' => VideoManualStatus::Ready->value,
+        'total_length_ms' => 999_000,
+    ]);
+
+    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+    $byId = array_column($rows, null, 'id');
+
+    expect($byId[$published->id]['duration_ms'])->toBe(185_000);
+    expect($byId[$noLength->id]['duration_ms'])->toBeNull();
+    expect($byId[$ready->id]['duration_ms'])->toBeNull();
+});
+
+test('downloadable は published × 現行世代の succeeded render (output_path あり) のときだけ true', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $ok = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '受取可']);
+    RenderJob::factory()->forManual($ok)->succeeded('renders/ok.mp4')->create();
+
+    // 最新 succeeded の実体が消えている (掃除済み) → 旧世代へフォールバックしない
+    $stale = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => '実体なし']);
+    RenderJob::factory()->forManual($stale)->succeeded('renders/old.mp4')->create();
+    RenderJob::factory()->forManual($stale)->succeeded('renders/new.mp4')
+        ->state(fn (): array => ['output_path' => null])->create();
+
+    // preview の succeeded しか無い
+    $previewOnly = VideoManual::factory()->forProject($project)->published(60_000)->create(['title' => 'preview のみ']);
+    RenderJob::factory()->forManual($previewOnly)->preview()->succeeded('renders/preview.mp4')->create();
+
+    // published でない (succeeded render はある)
+    $notPublished = VideoManual::factory()->forProject($project)->create([
+        'title' => '未公開', 'status' => VideoManualStatus::Ready->value,
+    ]);
+    RenderJob::factory()->forManual($notPublished)->succeeded('renders/ready.mp4')->create();
+
+    $rows = $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->inertiaPage()['props']['manuals']['data'];
+    $byId = array_column($rows, null, 'id');
+
+    expect($byId[$ok->id]['downloadable'])->toBeTrue();
+    expect($byId[$stale->id]['downloadable'])->toBeFalse();
+    expect($byId[$previewOnly->id]['downloadable'])->toBeFalse();
+    expect($byId[$notPublished->id]['downloadable'])->toBeFalse();
+});
+
+test('撮影者は downloadable / deletable ともに false、編集者は deletable=true', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $member = attachOrganizationMember($organization);
+    $member->forceFill(['current_organization_id' => $organization->id])->save();
+    $project = Project::factory()->forOrganization($organization)->create();
+    attachProjectMember($project, $member, ProjectRole::Member);
+    $manual = VideoManual::factory()->forProject($project)->published(60_000)->create();
+    RenderJob::factory()->forManual($manual)->succeeded('renders/ok.mp4')->create();
+
+    $this->actingAs($member)->get("/projects/{$project->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.data.0.downloadable', false)
+            ->where('manuals.data.0.deletable', false));
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->where('manuals.data.0.downloadable', true)
+            ->where('manuals.data.0.deletable', true));
+});
+
+test('一覧が 0 件でも props が壊れない (data: [] / meta.total: 0)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}")
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 0)
+            ->where('manuals.meta.total', 0)
+            ->where('manuals.meta.current_page', 1));
+});
+
+test('範囲外ページは最終ページへ丸める (空の一覧に着地させない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->count(12)->create();
+
+    $this->actingAs($owner)->get("/projects/{$project->id}?page=99")
+        ->assertOk()
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 2)
+            ->where('manuals.meta.current_page', 2)
+            ->where('manuals.meta.last_page', 2));
+});
+
+test('page が数字でない / 0 のときは 1 ページ目として扱う', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->count(12)->create();
+
+    foreach (['abc', '0', '-3'] as $raw) {
+        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
+            ->assertOk()
+            ->assertInertia(fn (Assert $page) => $page
+                ->has('manuals.data', 10)
+                ->where('manuals.meta.current_page', 1));
+    }
+});
+
+test('PHP_INT_MAX 超の page でも 500 にならず最終ページへ着地する (offset の float 化なし)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    VideoManual::factory()->forProject($project)->count(12)->create();
+
+    foreach (['99999999999999999999999', (string) PHP_INT_MAX] as $raw) {
+        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
+            ->assertOk()
+            ->assertInertia(fn (Assert $page) => $page
+                ->has('manuals.data', 2)
+                ->where('manuals.meta.current_page', 2));
+    }
+});
+
+test('q は先頭 200 文字で絞り込む (201 文字目以降は一致に寄与しない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $title = str_repeat('あ', 200);
+    VideoManual::factory()->forProject($project)->create(['title' => $title]);
+    VideoManual::factory()->forProject($project)->create(['title' => '別のマニュアル']);
+
+    // 200 文字を超える検索語は先頭 200 文字へ切り詰められるため、上記 title に一致する
+    $this->actingAs($owner)->get("/projects/{$project->id}?q=".urlencode($title.'ZZZ'))
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.title', $title)
+            ->where('manualFilters.q', $title));
+});
+
+test('一覧 0 件でも範囲外ページは 1 ページ目へ丸める (meta が食い違わない)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+
+    foreach (['99', '99999999999999999999999'] as $raw) {
+        $this->actingAs($owner)->get("/projects/{$project->id}?page={$raw}")
+            ->assertOk()
+            ->assertInertia(fn (Assert $page) => $page
+                ->has('manuals.data', 0)
+                ->where('manuals.meta.total', 0)
+                ->where('manuals.meta.current_page', 1)
+                ->where('manuals.meta.last_page', 1));
+    }
+});
+
+test('category は正規形へ畳まれる (0003 → 3。フィルタ select の値と一致する)', function (): void {
+    [$organization, $owner] = createOrganizationWithOwner();
+    $project = Project::factory()->forOrganization($organization)->create();
+    $category = Category::factory()->forProject($project)->create();
+    VideoManual::factory()->forProject($project)->forCategory($category)->create(['title' => '分類済み']);
+    VideoManual::factory()->forProject($project)->create(['title' => '未分類マニュアル']);
+
+    $padded = str_pad((string) $category->id, 6, '0', STR_PAD_LEFT);
+    $this->actingAs($owner)->get("/projects/{$project->id}?category={$padded}")
+        ->assertInertia(fn (Assert $page) => $page
+            ->has('manuals.data', 1)
+            ->where('manuals.data.0.title', '分類済み')
+            ->where('manualFilters.category', (string) $category->id));
+
+    // 桁溢れする数字列は該当なしへ倒れる (全件が出る方向へは倒さない)
+    $this->actingAs($owner)->get("/projects/{$project->id}?category=99999999999999999999999")
+        ->assertInertia(fn (Assert $page) => $page->has('manuals.data', 0));
+});

```

## 再検証結果

- `composer test`: 5342 tests / 5340 passed / 2 skipped / 22970 assertions (0 failed)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed

## 確認してほしいこと

1. 4 件の対応が指摘の意図を満たしているか (特に EagerLoadCandidate の前提強化が
   「gate を弱めない」水準に達しているか)
2. 新たに入れた `category` の正規化が、既存の絞り込み挙動・テストと矛盾していないか
3. 0 件ページの丸めが余計な副作用 (無限ループ・過剰クエリ) を生んでいないか

再レビューの上、**全体判定 (APPROVED / CHANGES_REQUESTED)** を明記せよ。
