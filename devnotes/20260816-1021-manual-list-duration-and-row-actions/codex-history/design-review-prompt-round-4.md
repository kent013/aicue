# Round 4: Round 3 指摘への対応

Critical 1 件・Warning 2 件をすべて対応しました。Critical は実測 (PHP 8.4.24) で確認しています。

## 対応マトリクス

# 対応マトリクス: design-review Round 3

## [Critical] M1: `const int MAX_PAGE = intdiv(...)` はコンパイルできない
- 判断: **対応する (実測で確認)**
- 根拠: 指摘のとおり。開発コンテナの PHP 8.4.24 で実測した:

  ```
  $ php -r 'class A { const int X = intdiv(PHP_INT_MAX, 10); } var_dump(A::X);'
  Fatal error: Constant expression contains invalid operations
  ```

  (純粋な算術式なら定数にできる — `(PHP_INT_MAX - (PHP_INT_MAX % self::PER_PAGE)) / self::PER_PAGE`
  は `int(922337203685477580)` を返すことも確認したが、読み手に意図が伝わらないので採らない。)
- 対応内容: `public static function maxPage(): int { return intdiv(PHP_INT_MAX, self::PER_PAGE); }`
  へ変更。呼び出し側 (`min(max(1, (int) $pageRaw), self::maxPage())`)、docblock、
  テスト計画の期待値 (`ManualListQuery::maxPage()`) をすべて合わせた。
  「定数ではなくメソッドである理由」を docblock に残し、後から定数へ戻されないようにした。

## [Warning] M9: Factory state が null ケースを表現できない
- 判断: **対応する**
- 根拠: テスト計画に `published + total_length_ms=null` があるのに、state の署名が
  `published(int $totalLengthMs)` では表現できず、計画と実装が食い違っていた。
- 対応内容: `published(?int $totalLengthMs = null)` にし、実際の state 実装を設計へ明記した。

## [Warning] M9: 検証コマンドが AGENTS.md の VERIFICATION_COMMANDS と一致していない
- 判断: **対応する**
- 対応内容: 実装モード表の検証コマンドを 10 本すべてに揃えた
  (`composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` /
  `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` /
  `pnpm build:packages` / `pnpm test:packages`)。

## M2〜M8 (APPROVE 部分)
- 判断: 指摘なし。変更しない。


---

## 修正後の詳細設計 (全文)

# 詳細設計: manual-list-duration-and-row-actions (動画一覧の再生時間表示と行内操作)

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う)
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する)
9. Artifact の使用

本設計は 4・8 に直接関係する: 応答は Inertia props と redirect のみ (JSON を返さない)、
行内 DL は「押せない状態 (disabled)」を作らず**サーバが受け取れると判断した行にだけ導線を出す**。

### コーディングルール

- **PHPStan level 10** 必須（`composer phpstan`）
- **Pest**（`composer test`）。**RefreshDatabase** はグローバル適用・`--parallel` 実行、
  個別 `DatabaseTransactions` は使わない
- **テストデータは必ず Factory で生成**（`Model::create()` 手組み禁止）
- **DTO + JsonResource** パターン
- アーリーリターン推奨 / `composer fix`（Pint）・`pnpm lint:fix`
- PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript
- フロントは DS token のみ・アイコンは `@lucide/svelte`・component 階層は
  `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import

## 概念設計リファレンス

`devnotes/20260816-1021-manual-list-duration-and-row-actions/conceptual-design.md`
（conceptual-review Round 2 で **APPROVED**）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| M1 | 一覧クエリの値オブジェクト化 (allowlist の単一化 + `q` 上限 + `page`) | `app/DataTransferObjects/Manual/ManualListQuery.php` (新規)、`app/Http/Controllers/Projects/ProjectController.php` | 高 |
| M2 | 「いま受け取れる完成動画」の relation 追加 (eager load 可能に) | `app/Models/VideoManual.php` | 高 |
| M3 | 行の操作可否をページで 1 回だけ評価する Service | `app/Services/Manual/ManualRowAbilities.php` (新規) | 高 |
| M4 | 行 props の DTO 化 + `duration_ms` / `downloadable` / `deletable` 追加 + 範囲外ページの丸め | `app/DataTransferObjects/Manual/ManualListItemData.php` (新規)、`app/DataTransferObjects/Manual/ManualListRefData.php` (新規)、`ProjectController.php` | 高 |
| M5 | 行削除後の着地で絞り込み・ページを維持する | `app/Http/Controllers/Projects/VideoManualController.php` | 高 |
| M6 | Inertia Props の TS 型追加 | `resources/js/types/manual.ts` | 高 |
| M7 | 再生時間の整形ヘルパ | `resources/js/lib/manual/format-duration.ts` (新規) | 高 |
| M8 | 一覧行の component 化 + DL / 削除導線 + ConfirmDialog 結線 | `resources/js/components/features/manual/ManualListRow.svelte` (新規)、`resources/js/pages/Projects/Show.svelte` | 高 |
| M9 | テスト (Feature 5 本 / Vitest 3 本) | `tests/Feature/...`、`tests/js/...` | 高 |

---

## M1: 一覧クエリの値オブジェクト化

### 変更箇所

- 新規: `app/DataTransferObjects/Manual/ManualListQuery.php`
- 変更: `app/Http/Controllers/Projects/ProjectController.php`
  - `parseManualFilters()` (L154-179) と `toManualFilterProps()` (L188-197) を削除し VO へ移す
  - `show()` (L115, L136-138) の呼び出しを差し替え

### 波及変更

- TypeScript 型定義: **なし** (`ManualFilters` の shape は不変。`page` は `manuals.meta` 側が既に持つ)
- API Resource/DTO: 新規 VO 1 本 (`ManualListQuery`)
- テストファイル: `tests/Feature/Projects/ProjectShowManualsTest.php` に `q` 上限のケースを追加
  (既存ケースは shape 不変のため修正不要)

### 現行コード

```php
// ProjectController.php L144-197 (抜粋)
    private function parseManualFilters(Request $request): array
    {
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if ($category !== null && $category !== 'uncategorized' && ! ctype_digit($category)) {
            $category = null;
        }

        $status = $request->query('status');
        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;

        $q = $request->query('q');
        $q = is_string($q) && trim($q) !== '' ? trim($q) : null;

        $sortRaw = $request->query('sort');
        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;

        return [
            'category' => $category,
            'status' => $status,
            'q' => $q,
            'sort' => $sort,
            'mine' => $request->boolean('mine'),
        ];
    }

    private function toManualFilterProps(array $filters): array
    {
        return [
            'category' => $filters['category'],
            'status' => $filters['status'],
            'q' => $filters['q'],
            'sort' => $filters['sort']?->value,
            'mine' => $filters['mine'],
        ];
    }
```

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\ManualSortOption;
use App\Enums\Manual\VideoManualStatus;
use Illuminate\Http\Request;

/**
 * 動画マニュアル一覧の GET クエリ (allowlist 済みの値)。
 *
 * **唯一の解析点**である: 一覧の絞り込み (ProjectController::show) と、
 * 行内削除の着地先 (VideoManualController::destroy が redirect に載せ直す値) が
 * 同じ VO を通るため、両者が食い違うことが構造的に起きない。
 *
 * 値の約束:
 * - `category`: 数値 id 文字列 | 'uncategorized' (未分類 sentinel) | null。それ以外は null
 * - `status`: VideoManualStatus の値のみ。それ以外は null
 * - `keyword`: 前後の空白を除いた検索語。**先頭 MAX_KEYWORD_LENGTH 文字だけを使う (truncate)**。
 *   破棄 (= 絞り込み無し) にしないのは「全件が出る」驚きの方向へ倒れるためで、
 *   切り詰めは「より広く当たる」方向にしか倒れない。title の validation が max:200 なので、
 *   201 文字目以降が一致に寄与することは無い
 * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
 * - `mine`: 自分の作成分のみ
 * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
 *   (「最後の方を見たい」意図に近い側へ倒す。着地は一覧側の丸めで最終ページになる)
 */
final readonly class ManualListQuery
{
    /** 検索語の最大長 (StoreVideoManualRequest の title max:200 と一致させる) */
    public const int MAX_KEYWORD_LENGTH = 200;

    /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
    public const int PER_PAGE = 10;

    /**
     * 受け付けるページ番号の上限。
     *
     * チューニング値ではなく**計算安全性の境界**である: paginator の offset は
     * `($page - 1) * PER_PAGE` で求まるため、この上限が無いと
     * `ctype_digit` を通った巨大な数字列 ((int) キャストで PHP_INT_MAX へ飽和する) が
     * int 範囲を超える乗算 (= float 化) を起こす。PER_PAGE から導出しているので
     * 説明のつかない定数にはならない。
     *
     * **定数ではなくメソッドである理由**: クラス定数の初期化式に関数呼び出しは書けない
     * (`const int MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE);` は PHP 8.4 で
     *  「Constant expression contains invalid operations」= コンパイルエラー。実測確認済み)。
     */
    public static function maxPage(): int
    {
        return intdiv(PHP_INT_MAX, self::PER_PAGE);
    }

    public function __construct(
        public ?string $category,
        public ?string $status,
        public ?string $keyword,
        public ?ManualSortOption $sort,
        public bool $mine,
        public int $page,
    ) {}

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if ($category !== null && $category !== 'uncategorized' && ! ctype_digit($category)) {
            $category = null;
        }

        $status = $request->query('status');
        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;

        $keyword = $request->query('q');
        $keyword = is_string($keyword) && trim($keyword) !== ''
            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
            : null;

        $sortRaw = $request->query('sort');
        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;

        // (int) は PHP_INT_MAX へ飽和するため、上限で丸めてから使う
        // (offset 計算 ($page - 1) * PER_PAGE を int 範囲に収める)
        $pageRaw = $request->query('page');
        $page = is_string($pageRaw) && ctype_digit($pageRaw)
            ? min(max(1, (int) $pageRaw), self::maxPage())
            : 1;

        return new self(
            category: $category,
            status: $status,
            keyword: $keyword,
            sort: $sort,
            mine: $request->boolean('mine'), // "1"/"true" を bool 正規化
            page: $page,
        );
    }

    /**
     * Inertia へ返す manualFilters prop (sort enum → string 値へ落とす単一変換点)。
     * **page を含めない**: ページ位置は manuals.meta.current_page が唯一の正本である
     * (2 か所に持つと必ず食い違う)。
     *
     * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
     */
    public function toProps(): array
    {
        return [
            'category' => $this->category,
            'status' => $this->status,
            'q' => $this->keyword,
            'sort' => $this->sort?->value,
            'mine' => $this->mine,
        ];
    }

    /**
     * この絞り込みを再現する route() 用クエリ (既定値は載せない = URL を短く保つ)。
     * 値は上の allowlist を通った後のものだけである (生の入力を Location に素通ししない)。
     *
     * @return array<string, string|int>
     */
    public function toQueryParams(): array
    {
        $params = [];
        if ($this->category !== null) {
            $params['category'] = $this->category;
        }
        if ($this->status !== null) {
            $params['status'] = $this->status;
        }
        if ($this->keyword !== null) {
            $params['q'] = $this->keyword;
        }
        if ($this->sort !== null) {
            $params['sort'] = $this->sort->value;
        }
        if ($this->mine) {
            $params['mine'] = 1;
        }
        if ($this->page > 1) {
            $params['page'] = $this->page;
        }

        return $params;
    }
}
```

`ProjectController::show()` 側:

```php
        $listQuery = ManualListQuery::fromRequest($request);
        // ...
            'manuals' => $this->manualRows($project, $listQuery, $user),
            'categories' => $this->categoryRows($project),
            'manualFilters' => $listQuery->toProps(),
```

### PHPStan適合チェック

- [x] 戻り値の型が明示されている (`self` / `array{...}` shape)
- [x] `PER_PAGE` は `int` 型付き定数、上限は `maxPage(): int` (定数式に関数呼び出しを書けないため)。
      どちらも `PER_PAGE` から導出し、perPage の知識を Controller と VO に分散させない (正本は VO だけ)
- [x] `min(max(1, (int) $pageRaw), self::maxPage())` で `page` は必ず `1..maxPage()` の int
- [x] null 安全 (`$request->query()` は mixed → `is_string()` で narrow してから利用)
- [x] DTO を返している (配列返却は `toProps()` / `toQueryParams()` の shape 明示のみ)
- [x] Generics の型パラメータ: 該当なし (VO)
- [x] `ctype_digit()` へ渡す前に string へ narrow 済み

### テスト計画

- [x] 既存 `tests/Feature/Projects/ProjectShowManualsTest.php` の絞り込みケースが**そのまま緑**
      (shape・挙動の非退行)
- [ ] 新規: `q` が 200 文字を超えるとき、先頭 200 文字で絞り込む (一致する行が返る)
- [ ] 新規: `page=abc` / `page=0` は 1 ページ目として扱う
- [ ] 新規: `?page=99999999` (件数を超える値) は最終ページに着地する (M4 の丸め)
- [ ] 新規: **`PHP_INT_MAX` を超える数字列** (`?page=99999999999999999999999`) でも
      200 で最終ページに着地し、例外 / 500 にならない (offset の float 化が起きない)
- [ ] 新規: `?page=` に `PHP_INT_MAX` そのものを渡しても同上
      (`ManualListQuery::maxPage()` へ丸められ、`($page - 1) * PER_PAGE` が int 範囲に収まる)
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- `q` の 200 文字切り詰めは**既存挙動の変更**である。201 文字以上の検索語は title (max:200) に
  一致し得ないため実害は無いが、`ProjectShowManualsTest` に契約として記録する。

---

## M2: 「いま受け取れる完成動画」の relation 追加

### 変更箇所

- `app/Models/VideoManual.php` (relation 追加 + `@property-read` 追加)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: M4 が参照する
- テストファイル: 新規 `tests/Feature/Manual/ManualRowDownloadableParityTest.php`
  (`CurrentRenderArtifact` との世代定義一致を固定)

### 現行コード

```php
// VideoManual.php L101-109
    /**
     * レンダジョブ (route param {renderJob} の scopeBindings 推論と一致する relation 名)。
     *
     * @return HasMany<RenderJob, $this>
     */
    public function renderJobs(): HasMany
    {
        return $this->hasMany(RenderJob::class);
    }
```

### 変更後コード

```php
    /**
     * 「いま受け取れる完成動画」の候補行 (kind=render の**最新 succeeded 1 行**)。
     *
     * **CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render) と同一世代定義**である
     * (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。違いは 1 点だけ:
     * こちらは `output_path` が NULL の行も返す (`output_path` の有無は呼び出し側が判定する)。
     * 一覧が行ごとに currentSucceeded() を呼ぶと N+1 になるため、eager load できる形を用意する。
     * 両者が同じ行を指すことは ManualRowDownloadableParityTest が固定する。
     *
     * @return HasOne<RenderJob, $this>
     */
    public function latestSucceededRender(): HasOne
    {
        return $this->hasOne(RenderJob::class)->ofMany(
            ['id' => 'max'],
            /** @param Builder<RenderJob> $query */
            function (Builder $query): void {
                $query->where('kind', RenderKind::Render->value)
                    ->where('status', JobStatus::Succeeded->value);
            }
        );
    }
```

クラス docblock へ追記:

```php
 * @property-read RenderJob|null $latestSucceededRender
```

追加 import: `App\Enums\Manual\JobStatus` / `App\Enums\Manual\RenderKind` /
`Illuminate\Database\Eloquent\Builder` / `Illuminate\Database\Eloquent\Relations\HasOne`。

### PHPStan適合チェック

- [x] `@return HasOne<RenderJob, $this>` を明示 (既存 relation と同じ generics 記法)
- [x] closure 引数に `@param Builder<RenderJob>` を付け generics 欠落を防ぐ
- [x] `@property-read RenderJob|null` を宣言し、`$manual->latestSucceededRender` の
      プロパティアクセスが `mixed` にならないようにする
- [x] 配列返却なし

### テスト計画

- [ ] 新規 `ManualRowDownloadableParityTest`: 次の 4 状況で
      `latestSucceededRender` と `CurrentRenderArtifact::currentSucceeded()` の判断が一致する
      1. succeeded が 2 世代あり新しい方に `output_path` がある → 両者とも新しい行
      2. 最新 succeeded の `output_path` が NULL (掃除済み) → relation は行を返すが
         `currentSucceeded()` は null → **一覧は downloadable=false**、endpoint も 404
      3. kind=preview の succeeded しか無い → 両者とも「無し」
      4. failed / running しか無い → 両者とも「無し」
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- `ofMany` は eager load 時に副問い合わせ + join を使う。`render_jobs` に
  `(video_manual_id, kind, status)` の索引が無いと件数増加で遅くなる余地がある。
  **今回は索引を追加しない** (per_page=10・1 プロジェクトあたりの job 数は小さい。
  必要になってから測って足す = 思考原則 2)。

---

## M3: 行の操作可否をページで 1 回だけ評価する Service

### 変更箇所

- 新規: `app/Services/Manual/ManualRowAbilities.php`

### 波及変更

- TypeScript 型定義: なし (結果は M4 の行 props に畳まれる)
- テストファイル: 新規 `tests/Feature/Projects/ManualRowAbilityPremiseTest.php`、
  新規 `tests/Feature/Projects/ManualListQueryCountTest.php`

### 現行コード

なし (新規)。現状は `ProjectController::show` が `$user->can('update', $project)` を
1 回だけ評価して `canManage` prop に載せている (L104, L128)。

### 変更後コード

```php
<?php

declare(strict_types=1);

namespace App\Services\Manual;

use App\Models\Project;
use App\Models\User;
use App\Models\VideoManual;

/**
 * 一覧の行に出す操作 (完成動画のダウンロード / 削除) の可否。
 *
 * **前提 (名前が示す約束)**: download / delete の可否は「その manual が属する project」で決まり、
 * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
 * VideoManualPolicy::download / ::delete が対象から `project` しか読まず
 * ProjectPolicy::update へ委譲しているためである。よって**ページで 1 回だけ**評価して全行へ配る。
 *
 * **なぜ畳むか**: ProjectPolicy は毎回 DB を見る (Project::memberRole() は memo 無しのクエリ、
 * Laratrust のキャッシュは config/laratrust.php の既定で production 以外は無効)。
 * 行ごとに can() を呼ぶと権限解決クエリが行数に比例する (per_page=10 × 2 ability)。
 *
 * **なぜ ProjectPolicy::update を直接問わないか**: それは委譲関係を呼び出し側へ
 * ハードコードすることであり、policy が分岐した日に**赤くならずに間違う**。
 * 問う ability 名は download / delete のまま保ち、評価の**回数**だけを畳む。
 *
 * 前提は ManualRowAbilityPremiseTest が固定し (manual 依存になったら赤くなる)、
 * 行数に比例しないことは ManualListQueryCountTest が固定する。読み取り専用。
 */
final readonly class ManualRowAbilities
{
    private function __construct(
        public bool $canDownload,
        public bool $canDelete,
    ) {}

    /**
     * ページに載る行に対する可否。行が 1 件も無いページでは両方 false
     * (出す導線が無いので評価しない = 無駄な権限クエリを撃たない)。
     *
     * @param  list<VideoManual>  $manuals  同一 $project 配下であること (呼び出し側が保証する)
     */
    public static function forPage(User $user, Project $project, array $manuals): self
    {
        $representative = $manuals[0] ?? null;
        if ($representative === null) {
            return new self(canDownload: false, canDelete: false);
        }

        // policy が親を読み直すクエリを避けるため、解決済みの project を先に確定させる
        // (同一 project 配下であることは呼び出し側 = $project->manuals() が保証している)
        $representative->setRelation('project', $project);

        return new self(
            canDownload: $user->can('download', $representative),
            canDelete: $user->can('delete', $representative),
        );
    }
}
```

### PHPStan適合チェック

- [x] `@param list<VideoManual>` で配列要素型を明示
- [x] 戻り値 `self` を明示、public プロパティは `bool` 確定
- [x] `$manuals[0] ?? null` で null 安全 (空配列でも例外を投げない)
- [x] `User::can()` は `Illuminate\Foundation\Auth\Access\Authorizable` 由来で bool を返す

### テスト計画

- [ ] `ManualRowAbilityPremiseTest` (Feature):
      - 同一 project に status / 作成者 / カテゴリが異なる 3 manual を作り、
        **各 manual を個別に `can('download')` / `can('delete')` した結果**が
        `ManualRowAbilities::forPage()` の結果と**全行一致**する
        (代表行の結果と行ごとの実評価を突き合わせる = 行属性依存になったら確実に赤くなる)
      - 撮影者 (project_member) は両方 false / 編集者 (project_admin) は両方 true /
        組織 owner は両方 true
      - この前提が崩れる policy 変更をしたら赤くなることをテスト名とコメントに明記する
- [ ] `ManualListQueryCountTest` (Feature):
      - 同一プロジェクトで **1 行のページ**と **10 行のページ**を描画し、
        `DB::enableQueryLog()` の件数が**同数**であること
      - 計測範囲を明確にする (fixture 生成 → `DB::flushQueryLog()` → GET 1 回 →
        `count(DB::getQueryLog())`)。ログ有効化は当該テスト内のみ
      - 失敗時に**増えたクエリの SQL 一覧**をアサーションメッセージに出す
        (どこで N+1 が生えたかがテスト出力だけで分かるようにする)
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- 代表行方式は「同一 project 内で可否が一致する」前提に立つ。前提が崩れたら
  `ManualRowAbilityPremiseTest` が赤くなり、実装者は評価を行ループへ移す (そのとき
  N+1 の解消も同時に設計し直す) — この手順を Service の docblock に書いておく。
- `setRelation('project', ...)` は行の model を書き換えるが、設定する値は
  その行が実際に属する project であり、後段の描画 (`$manual->category` 等) に影響しない。

---

## M4: 行 props の DTO 化 + 3 フィールド追加 + 範囲外ページの丸め

### 変更箇所

- 新規: `app/DataTransferObjects/Manual/ManualListRefData.php` (id / name の対)
- 新規: `app/DataTransferObjects/Manual/ManualListItemData.php`
- 変更: `app/Http/Controllers/Projects/ProjectController.php` の `manualRows()` (L199-272)

### 波及変更

- TypeScript 型定義: `resources/js/types/manual.ts` の `ManualListItem` に
  `duration_ms` / `downloadable` / `deletable` を追加 (M6)
- API Resource/DTO: `ManualListItemData` 新規 (行 props の唯一の shape)
- テストファイル:
  - `tests/Feature/Projects/ProjectShowManualsTest.php` (新フィールドの契約を追加)
  - `tests/js/pages/ProjectsShow.test.ts` の `manualsFixture` (**必須 3 フィールド追加。
    入れないと型検査が落ちる**)
  - 新規 `tests/js/components/features/manual/ManualListRow.test.ts`

### 現行コード

```php
// ProjectController.php L199-272 (抜粋)
    private function manualRows(Project $project, array $filters, int $viewerId): array
    {
        $query = $project->manuals()->with(['category', 'creator']);

        $orderings = $filters['sort']?->orderings() ?? ManualSortOption::defaultOrderings();
        foreach ($orderings as $ordering) {
            /** @var ManualOrdering $ordering */
            $query->orderBy($ordering['column'], $ordering['direction']);
        }

        if ($filters['mine']) {
            $query->where('created_by', $viewerId);
        }
        // ... category / status / q の絞り込み ...

        $paginated = $query->paginate(10)->withQueryString();

        $data = [];
        foreach ($paginated->items() as $manual) {
            Assert::isInstanceOf($manual, VideoManual::class);
            $category = $manual->category;
            $creator = $manual->creator;
            $data[] = [
                'id' => $manual->id,
                // ... title / status / category / creator / created_at / updated_at ...
            ];
        }

        return ['data' => $data, 'meta' => [...]];
    }
```

### 変更後コード

参照 DTO (id / name の対。**配列プロパティを持たないため PHPStan level 10 の
iterable value type 指摘が構造的に起きない**):

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

/**
 * 一覧行が参照する {id, name} の対 (カテゴリ / 作成者)。
 * 「id と name は必ず揃う」ことを型で保つ (片方だけ null になる状態を作らない)。
 */
final readonly class ManualListRefData
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    /**
     * @return array{id: int, name: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name];
    }
}
```

行 DTO:

```php
<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\VideoManualStatus;
use App\Models\VideoManual;
use App\Services\Manual\ManualRowAbilities;

/**
 * 動画マニュアル一覧 (Projects/Show に内包) の 1 行。TS の ManualListItem と対。
 *
 * **判断はここで 1 回だけ**行う (UI 側で published / ability を再判定しない。
 * RenderPanel の finishedJob と同じ流儀)。
 */
final readonly class ManualListItemData
{
    /**
     * @param  ManualListRefData|null  $category  null = 未分類
     * @param  ManualListRefData|null  $creator   null = 退会/削除で解決不可
     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
     * @param  bool  $downloadable  download endpoint が 302 を返す条件と 1 対 1
     */
    public function __construct(
        public int $id,
        public string $title,
        public VideoManualStatus $status,
        public ?ManualListRefData $category,
        public ?ManualListRefData $creator,
        public string $createdAt,
        public string $updatedAt,
        public ?int $durationMs,
        public bool $downloadable,
        public bool $deletable,
    ) {}

    public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
    {
        $category = $manual->category;
        $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
        $isPublished = $manual->status === VideoManualStatus::Published;

        // 再生時間は「**いま公開されている**完成動画の長さ」。published が外れた行
        // (公開後にシナリオを保存すると ScenarioService が ready へ戻す) の total_length_ms は
        // 最新シナリオと対応しない古い尺なので出さない。
        $durationMs = $isPublished ? $manual->total_length_ms : null;

        // 受け取れる完成動画: download ability × published × 現行世代の succeeded render に
        // output_path がある。**ストレージ実体の存在確認ではない** (それは download endpoint も
        // していない。ここは endpoint が 302 を返す条件と同じものを見ているだけ)。
        // 世代の選び方は CurrentRenderArtifact と同一 (latestSucceededRender の docblock 参照)。
        $currentRender = $manual->latestSucceededRender;
        $downloadable = $abilities->canDownload
            && $isPublished
            && $currentRender !== null
            && $currentRender->output_path !== null;

        return new self(
            id: $manual->id,
            title: $manual->title,
            status: $manual->status,
            category: $category === null ? null : new ManualListRefData($category->id, $category->name),
            creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
            createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
            updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
            durationMs: $durationMs,
            downloadable: $downloadable,
            deletable: $abilities->canDelete,
        );
    }

    /**
     * @return array{id: int, title: string, status: string,
     *   category: array{id: int, name: string}|null,
     *   creator: array{id: int, name: string}|null,
     *   created_at: string, updated_at: string,
     *   duration_ms: int|null, downloadable: bool, deletable: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'status' => $this->status->value,
            'category' => $this->category?->toArray(),
            'creator' => $this->creator?->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'duration_ms' => $this->durationMs,
            'downloadable' => $this->downloadable,
            'deletable' => $this->deletable,
        ];
    }
}
```

Controller:

```php
    /**
     * 動画マニュアル一覧 rows (paginate + DTO で shape を固定)。
     *
     * @return array{
     *   data: list<array{id: int, title: string, status: string,
     *     category: array{id: int, name: string}|null,
     *     creator: array{id: int, name: string}|null,
     *     created_at: string, updated_at: string,
     *     duration_ms: int|null, downloadable: bool, deletable: bool}>,
     *   meta: array{current_page: int, last_page: int, per_page: int, total: int}
     * }
     */
    private function manualRows(Project $project, ManualListQuery $listQuery, User $user): array
    {
        // latestSucceededRender も eager load する (行ごとの現行世代判定で N+1 を作らない)
        $baseQuery = $project->manuals()->with(['category', 'creator', 'latestSucceededRender']);

        $orderings = $listQuery->sort?->orderings() ?? ManualSortOption::defaultOrderings();
        foreach ($orderings as $ordering) {
            /** @var ManualOrdering $ordering */
            $baseQuery->orderBy($ordering['column'], $ordering['direction']);
        }

        if ($listQuery->mine) {
            // 自ユーザー id のみ (payload 非受領 = tenant/actor キー不信)
            $baseQuery->where('created_by', $user->id);
        }
        if ($listQuery->category === 'uncategorized') {
            $baseQuery->whereNull('category_id');
        } elseif ($listQuery->category !== null) {
            $baseQuery->where('category_id', (int) $listQuery->category);
        }
        if ($listQuery->status !== null) {
            $baseQuery->where('status', $listQuery->status);
        }
        if ($listQuery->keyword !== null) {
            // LIKE メタ文字 (%/_/\) はリテラル検索として扱う
            $baseQuery->where('title', 'like', '%'.addcslashes($listQuery->keyword, '%_\\').'%');
        }

        $paginated = (clone $baseQuery)->paginate(perPage: ManualListQuery::PER_PAGE, page: $listQuery->page)
            ->withQueryString();

        // 範囲外ページ (行内削除で件数が減った / 古いブックマーク) は最終ページへ丸める。
        // 「空の一覧」に着地させない (行き先のない詰みを作らない)。
        if ($paginated->currentPage() > $paginated->lastPage() && $paginated->total() > 0) {
            $paginated = (clone $baseQuery)->paginate(perPage: ManualListQuery::PER_PAGE, page: $paginated->lastPage())
                ->withQueryString();
        }

        /** @var list<VideoManual> $manuals */
        $manuals = [];
        foreach ($paginated->items() as $manual) {
            Assert::isInstanceOf($manual, VideoManual::class);
            $manuals[] = $manual;
        }

        // ability はページで 1 回だけ評価する (理由は ManualRowAbilities の docblock)
        $abilities = ManualRowAbilities::forPage($user, $project, $manuals);

        return [
            'data' => array_map(
                fn (VideoManual $manual): array => ManualListItemData::fromManual($manual, $abilities)->toArray(),
                $manuals,
            ),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ];
    }
```

`show()` の呼び出しは `$this->manualRows($project, $listQuery, $user)` へ変更する
(現行は `$user->id` を渡していた)。

### PHPStan適合チェック

- [x] 戻り値の array shape を PHPDoc で完全に明示 (`duration_ms: int|null` 等)
- [x] **DTO に配列プロパティを持たせない** (`ManualListRefData` に分離)。
      promoted property の iterable value type 不足を構造的に起こさない
- [x] `array_map` の callable に引数型 `VideoManual` と戻り型 `array` を明示
- [x] `list<VideoManual>` を `Assert::isInstanceOf` で構築 (mixed のまま使わない)
- [x] `$manual->latestSucceededRender` は `@property-read RenderJob|null` により narrow 済み、
      `output_path` は `string|null` を明示比較
- [x] `paginate(perPage:, page:)` は名前付き引数で型を確定
- [x] 配列返却は Inertia props の最終形のみ (DTO を経由している)

### テスト計画

- [ ] `ProjectShowManualsTest` に追加:
      - published + `total_length_ms=185_000` → `duration_ms = 185000`
      - published + `total_length_ms=null` → `duration_ms = null`
      - ready / draft / rendering (`total_length_ms` に値があっても) → `duration_ms = null`
      - `downloadable`: published + succeeded render (`output_path` あり) → true
      - `downloadable`: published + 最新 succeeded の `output_path` が null → false
      - `downloadable`: published + preview の succeeded しか無い → false
      - `downloadable`: ready + succeeded render あり → false
      - `downloadable` / `deletable`: 撮影者 (project_member) は両方 false
      - `deletable`: 編集者は true
      - 一覧が 0 件でも props が壊れない (`data: []` / `meta.total: 0`)
      - 範囲外ページ (`?page=99`) は最終ページの行を返す (`meta.current_page = last_page`)
- [ ] 既存の絞り込み・並べ替え・paginate のケースが**そのまま緑** (非退行)
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- `clone $baseQuery` を 2 回使うのは、`paginate()` が limit/offset を builder に適用するため。
  clone しないと 2 回目の paginate が 1 回目の状態を引き継ぐ恐れがある。
- 丸めを行うと URL の `?page=99` と `meta.current_page` が食い違う (redirect はしない)。
  ページ送り UI は `meta.current_page` を見るため操作は破綻しない。**props が正本**である旨を
  コメントに残す。

---

## M5: 行削除後の着地で絞り込み・ページを維持する

### 変更箇所

- `app/Http/Controllers/Projects/VideoManualController.php` の `destroy()` (L238-251)

### 波及変更

- TypeScript 型定義: なし (URL の組み立ては M8)
- テストファイル: 新規 `tests/Feature/Projects/ManualRowActionsTest.php`、
  既存 `tests/Feature/Projects/VideoManualCrudTest.php` の destroy ケース (非退行の確認)

### 現行コード

```php
    /** 削除 */
    public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $manual);

        $manuals->delete($project, $manual);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', '動画マニュアルを削除しました');
    }
```

### 変更後コード

```php
    /**
     * 削除。
     *
     * 一覧の行から消したときは、削除要求に一覧の絞り込み・ページが付いてくる
     * (`?category=…&status=…&q=…&sort=…&mine=1&page=2`)。受け取った値は**一覧と同じ allowlist**
     * (ManualListQuery) を通してから着地先に載せ直す = 生のユーザー入力を Location に素通ししない。
     * クエリが無いとき (詳細画面からの削除) は現行と同じ `/projects/{project}` へ着地する。
     * 消した結果そのページが範囲外になった場合は、着地先の一覧が最終ページへ丸める (M4)。
     */
    public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
    {
        $organization = $this->resolveCurrentOrganization($request);
        // URL 整合 guard: 認可より前に 404 ({manual} ∈ {project} は scopeBindings が担保済み)
        $this->resolveOrganizationProject($organization, $project);
        Gate::authorize('delete', $manual);

        $listQuery = ManualListQuery::fromRequest($request);

        $manuals->delete($project, $manual);

        return redirect()
            ->route('projects.show', ['project' => $project, ...$listQuery->toQueryParams()])
            ->with('success', '動画マニュアルを削除しました');
    }
```

追加 import: `App\DataTransferObjects\Manual\ManualListQuery`。

### PHPStan適合チェック

- [x] 戻り値 `RedirectResponse` を明示
- [x] `toQueryParams()` は `array<string, string|int>` で spread 先も配列 (`route()` の
      第 2 引数は `array<string, mixed>` を受ける)
- [x] `redirect()->intended()` を使っていない (禁止事項 7)
- [x] 認可は `Gate::authorize('delete', $manual)` のまま (層 2 の 404 が層 3 より前)

### テスト計画

- [ ] `ManualRowActionsTest` (新規):
      - 絞り込み付き DELETE (`?category=3&status=published&q=ネジ&sort=title_asc&mine=1&page=2`)
        → `/projects/{id}?category=3&status=published&q=ネジ&sort=title_asc&mine=1&page=2` へ redirect、
        flash `success` あり、行が消えている
      - クエリ無し DELETE → `/projects/{id}` へ redirect (**現行と同じ** = 詳細画面からの削除の非退行)
      - allowlist 外 (`?sort=;DROP&category=abc&status=bogus`) は redirect の URL に載らない
      - 撮影者 (project_member) の DELETE は 403 (行内導線を出さないだけでなくサーバでも拒否)
      - 他プロジェクトの manual id を指す DELETE は 404 (認可より前。既存 scopeBindings の非退行)
      - `q` が 200 文字超のとき、redirect の `q` は先頭 200 文字 (一覧の絞り込みと同じ値)
      - `?page=abc` / `?page=0` / `?page=1` は redirect の URL に `page` を載せない
        (`toQueryParams()` は page<=1 を載せない契約)
      - 極端な `page` (`PHP_INT_MAX` 超の数字列) 付きの DELETE でも 500 にならず、
        redirect の `page` は正規化後の値 (`ManualListQuery::maxPage()` 以下) になる
- [ ] 既存 `VideoManualCrudTest` の削除ケースが**そのまま緑**
- [ ] 個別の `DatabaseTransactions` を使わない

### リスク

- Location ヘッダに検索語が載る。値は allowlist と 200 文字上限を通っており、
  `route()` が URL エンコードするため改行注入は起きない。
- 削除要求に付くクエリはユーザー入力だが、**認可・対象の解決には一切使われない**
  (route パラメータのみが対象を決める)。着地先の組み立てだけに使う。

---

## M6: Inertia Props の TS 型追加

### 変更箇所

- `resources/js/types/manual.ts` の `ManualListItem` (L102-112)

### 波及変更

- テストファイル: `tests/js/pages/ProjectsShow.test.ts` の `manualsFixture` (必須 3 フィールド)、
  新規 `tests/js/components/features/manual/ManualListRow.test.ts`
- `tests/js/types/manual.test.ts`: 既存の値集合テストに影響なし

### 現行コード

```ts
export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    /** null = 未分類 */
    category: { id: number; name: string } | null;
    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
    creator: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}
```

### 変更後コード

```ts
/** PHP: App\DataTransferObjects\Manual\ManualListItemData::toArray() と対 */
export interface ManualListItem {
    id: number;
    title: string;
    status: VideoManualStatus;
    /** null = 未分類 */
    category: { id: number; name: string } | null;
    /** 作成者。退会/削除で解決不可のときは null (UI は「不明」) */
    creator: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
    /**
     * いま公開されている完成動画の長さ (ms)。**null = 未確定**
     * (published でない / 総尺が記録されていない)。
     * published が外れた行の古い総尺はサーバが null に畳んでいるため、
     * UI 側で status を見て再判定しない。
     */
    duration_ms: number | null;
    /**
     * 完成動画を受け取れるか。サーバが「download ability × published ×
     * 現行世代の succeeded render に output_path がある」を判定した結果そのもので、
     * **UI 側で条件を再判定しない**。download endpoint が 302 を返す条件と 1 対 1
     * (描画時点のスナップショットであり、ストレージ実体の存在確認ではない)。
     */
    downloadable: boolean;
    /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
    deletable: boolean;
}
```

### PHPStan適合チェック

該当なし (TypeScript)。`pnpm typecheck` で必須フィールド欠落を検出する。

### テスト計画

- [ ] `pnpm typecheck` が緑 (fixture 未更新なら落ちることを確認 = 波及の検出)
- [ ] `tests/js/pages/ProjectsShow.test.ts` の fixture に 3 フィールドを追加

### リスク

- 追加は必須フィールドなので、既存 fixture を更新しないと型検査が落ちる (意図的な検出)。

---

## M7: 再生時間の整形ヘルパ

### 変更箇所

- 新規: `resources/js/lib/manual/format-duration.ts`

### 波及変更

- テストファイル: 新規 `tests/js/lib/manual/format-duration.test.ts`

### 現行コード

なし (再生時間の整形は現在どこにも無い。`resources/js/lib/format-bytes.ts` が
同種の表示専用ヘルパの先例)。

### 変更後コード

```ts
/**
 * 再生時間の可読表記 (動画一覧の再生時間表示)。
 *
 * ms → "M:SS"、1 時間以上は "H:MM:SS"。秒は**四捨五入**する
 * (表示専用であり、長さの比較・判定には使わない = format-bytes.ts と同じ位置づけ)。
 * サーバ整形にしないのは、日時と違いタイムゾーンに依存しないため。
 *
 * null / 有限でない値 / 負値は「未確定」を表す DURATION_UNKNOWN を返す
 * (未確定を 0:00 と書くと「長さゼロの動画がある」という別の嘘になる)。
 */
export const DURATION_UNKNOWN = "—";

export function formatDurationMs(durationMs: number | null): string {
    if (durationMs === null || !Number.isFinite(durationMs) || durationMs < 0) {
        return DURATION_UNKNOWN;
    }

    const totalSeconds = Math.round(durationMs / 1000);
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;
    const ss = String(seconds).padStart(2, "0");

    if (hours > 0) {
        return `${hours}:${String(minutes).padStart(2, "0")}:${ss}`;
    }

    return `${minutes}:${ss}`;
}
```

### PHPStan適合チェック

該当なし (TypeScript。`pnpm lint` / `pnpm typecheck` 対象)。

### テスト計画

- [ ] `format-duration.test.ts` (Vitest):
      - `null` → `"—"`
      - `0` → `"0:00"`
      - `1_000` → `"0:01"` / `59_400` → `"0:59"` (四捨五入で 59)
      - `59_600` → `"1:00"` (四捨五入で繰り上がる)
      - `185_000` → `"3:05"`
      - `3_600_000` → `"1:00:00"` / `3_725_000` → `"1:02:05"`
      - 負値 / `NaN` / `Infinity` → `"—"`

### リスク

- 四捨五入なので `59_600ms` が `1:00` になる。実体 (1 分未満) との差は 1 秒未満であり、
  配布判断に影響しない。切り捨てにしない理由をコメントに残す。

---

## M8: 一覧行の component 化 + DL / 削除導線

### 変更箇所

- 新規: `resources/js/components/features/manual/ManualListRow.svelte`
- 変更: `resources/js/pages/Projects/Show.svelte` (一覧の `<li>` 部分 L426-447、
  script への削除ハンドラ追加、ConfirmDialog 追加)

### 波及変更

- TypeScript 型定義: M6 の `ManualListItem` を使う
- テストファイル: `tests/js/pages/ProjectsShow.test.ts` (fixture + 削除フロー)、
  新規 `tests/js/components/features/manual/ManualListRow.test.ts`
- component 階層: `pages → features/manual` の単方向 import (既存 `Manuals/Show` →
  `features/manual/RenderPanel` と同じ向き)。`ManualListRow` が import するのは
  `atoms` (Badge / Button / TextLink) と `lib` / `types` のみ

### 前提の確認: `Button` に `href` を渡すと通常 anchor になる

DL 導線はブラウザの通常遷移 (302 → S3 の attachment 応答) でなければ機能しない。
`Button` atom の分岐は 3 枝で、**`inertia` を渡したときだけ** `@inertiajs/svelte` の `Link` になる:

```svelte
<!-- resources/js/components/atoms/Button.svelte -->
{#if href !== undefined && inertia}
    <Link {href} …>{@render content()}</Link>
{:else if href !== undefined}
    <a {href} {target} rel={computedRel} …>{@render content()}</a>
{:else}
    <button …>{@render content()}</button>
{/if}
```

本設計の DL は `inertia` を渡さない (既定 `inertia = false`) ため**素の `<a>`** になる。
既存の `RenderPanel.svelte` の「完成動画をダウンロード」も同じ書き方で本番稼働している。
この前提は `ManualListRow.test.ts` が固定する (要素が `A` タグであること + クリックで
Inertia router を呼ばないこと。atom 側の実装が変わったら赤くなる)。

### 現行コード

```svelte
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                        {#each manuals.data as manual (manual.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <TextLink
                                        href={`/projects/${project.id}/manuals/${manual.id}`}
                                        testId={`manual-link-${manual.id}`}
                                    >
                                        {manual.title}
                                    </TextLink>
                                    <p class="mt-1 text-caption text-text-secondary">
                                        {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ??
                                            "不明"} ・ 更新 {manual.updated_at}
                                    </p>
                                </div>
                                <Badge
                                    tone={STATUS_TONES[manual.status]}
                                    testId={`manual-status-${manual.id}`}
                                >
                                    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                                </Badge>
                            </li>
                        {/each}
                    </ul>
```

### 変更後コード

`ManualListRow.svelte` (新規):

```svelte
<script lang="ts">
    import { Download, Trash2 } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import { formatDurationMs } from "@/lib/manual/format-duration";
    import type { ManualListItem } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 / DL / 削除)。
     *
     * 表示の出し分けは**サーバが決めた行 props だけ**で行う
     * (downloadable / deletable。published も ability も UI 側で再判定しない)。
     * 削除の実行は一覧ページが持つ (この component は確認ダイアログを開く要求を上へ返すだけ)。
     */
    interface Props {
        projectId: number;
        manual: ManualListItem;
        /** 削除確認ダイアログを開く要求 */
        onRequestDelete: (manual: ManualListItem) => void;
    }

    let { projectId, manual, onRequestDelete }: Props = $props();

    const durationLabel = $derived(formatDurationMs(manual.duration_ms));
</script>

<!-- 狭い画面では縦積み (操作群を次行へ逃がす)、sm 以上で現行と同じ横並びに戻す。
     操作が 2 つ増えて shrink-0 側が広がるため、モバイルで行が潰れないようにする -->
<li
    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
    data-testid={`manual-row-${manual.id}`}
>
    <div class="min-w-0">
        <TextLink
            href={`/projects/${projectId}/manuals/${manual.id}`}
            testId={`manual-link-${manual.id}`}
        >
            {manual.title}
        </TextLink>
        <p class="mt-1 truncate text-caption text-text-secondary">
            {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ?? "不明"} ・ 更新 {manual.updated_at}
        </p>
    </div>
    <div class="flex shrink-0 items-center gap-2">
        <!-- 再生時間: 公開済みの完成動画の長さ。未確定は「—」。権限では隠さない -->
        <span
            class="text-caption text-text-secondary"
            data-testid={`manual-duration-${manual.id}`}
        >
            {durationLabel}
        </span>
        <Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
            {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
        </Badge>
        {#if manual.downloadable}
            <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
                 出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
                 詳細画面 (RenderPanel) が唯一持つ。
                 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
                 画面は遷移しない。 -->
            <Button
                variant="ghost"
                size="sm"
                href={`/projects/${projectId}/manuals/${manual.id}/download`}
                ariaLabel={`${manual.title} の完成動画をダウンロード`}
                testId={`manual-download-${manual.id}`}
            >
                <Download class="size-4" />
                DL
            </Button>
        {/if}
        {#if manual.deletable}
            <Button
                variant="danger-ghost"
                size="sm"
                onclick={() => onRequestDelete(manual)}
                ariaLabel={`${manual.title} を削除`}
                testId={`manual-remove-${manual.id}`}
            >
                <Trash2 class="size-4" />
                削除
            </Button>
        {/if}
    </div>
</li>
```

`Projects/Show.svelte` (script 追加分):

```ts
    import ManualListRow from "@/components/features/manual/ManualListRow.svelte";

    /* ---- 動画マニュアル: 行内削除 (ConfirmDialog → destroy) ---- */
    let removeManualTarget = $state<ManualListItem | null>(null);
    let removeManualDialogOpen = $state(false);
    let removingManual = $state(false);

    function openRemoveManualDialog(manual: ManualListItem): void {
        removeManualTarget = manual;
        removeManualDialogOpen = true;
    }

    /** 現在の絞り込み + 表示中ページを query string 化する (削除の着地先を保つため) */
    function manualQueryString(): string {
        // URLSearchParams のコンストラクタに map した配列を渡すと tuple 推論に依存して
        // typecheck が不安定になるため、set で組み立てる
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(manualQuery(manuals.meta.current_page))) {
            params.set(key, String(value));
        }
        const serialized = params.toString();

        return serialized === "" ? "" : `?${serialized}`;
    }

    function removeManual(): void {
        const target = removeManualTarget;
        // 二重送信ガードは handler 早期 return で行う (ボタンに disabled を付けない = 禁止事項 8)
        if (target === null || removingManual) return;
        // 絞り込み・ページを付けて送る。サーバは同じ allowlist を通して着地先に載せ直すため、
        // 削除後も同じ絞り込み・同じページに戻る (そのページが消えたらサーバが最終ページへ丸める)
        router.delete(`/projects/${project.id}/manuals/${target.id}${manualQueryString()}`, {
            preserveScroll: true,
            onStart: () => {
                removingManual = true;
            },
            onFinish: () => {
                removingManual = false;
                removeManualDialogOpen = false;
            },
        });
    }
```

markup:

```svelte
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                        {#each manuals.data as manual (manual.id)}
                            <ManualListRow
                                projectId={project.id}
                                {manual}
                                onRequestDelete={openRemoveManualDialog}
                            />
                        {/each}
                    </ul>
```

ConfirmDialog (既存 3 つの並びに追加):

```svelte
        <ConfirmDialog
            bind:open={removeManualDialogOpen}
            title="動画マニュアル削除"
            message={`「${removeManualTarget?.title ?? ""}」を削除しますか？ 撮影テイクや完成動画も削除され、この操作は取り消せません。`}
            confirmLabel="削除する"
            confirmVariant="danger"
            processing={removingManual}
            onConfirm={removeManual}
            testId="remove-manual-dialog"
        />
```

`Badge` / `STATUS_TONES` / `VIDEO_MANUAL_STATUS_LABELS` / `TextLink` が
`Projects/Show.svelte` で他に使われていなければ import を整理する
(`VIDEO_MANUAL_STATUS_LABELS` はフィルタの option で使用中のため**残す**。
`STATUS_TONES` と `Badge` は行以外で使っていなければ削除。`TextLink` は管理メニューで
使用中のため残す)。

### PHPStan適合チェック

該当なし (Svelte / TypeScript)。`pnpm lint` / `pnpm typecheck` /
`tests/js/architecture/atomic-import-graph.test.ts` / ds-purity テストで検査する。

- [x] DS token のみ (`text-caption` / `text-text-secondary` / `divide-border`。hex 直書きなし)
- [x] アイコンは `@lucide/svelte` の `Download` / `Trash2` のみ (SVG 直書きなし)
- [x] `disabled` を条件で付けていない (二重送信は handler 早期 return)
- [x] import は `atoms` / `lib` / `types` のみ (下層 → 上層の逆流なし)

### テスト計画

- [ ] `ManualListRow.test.ts` (Vitest):
      - `duration_ms` があるとき `3:05` を表示 / `null` のとき `—`
      - `downloadable=true` のとき DL リンクの href が
        `/projects/{p}/manuals/{m}/download`、`false` のとき**存在しない**
      - **DL 導線は通常 anchor である**: 要素の `tagName === "A"` であること、
        クリックしても Inertia router (`visit` / `get` / `delete`) が呼ばれないこと。
        `@inertiajs/svelte` の mock では `Link` を**マーカー属性
        (`data-inertia-link="true"`) 付きの要素を描画するスタブ**にし、DL 要素が
        そのマーカーを持たないことも assert する
        (Link も `<a>` を描くため、タグ名だけでは分岐の回帰を捕まえられない)
      - `deletable=true` のとき削除ボタンがあり、押すと `onRequestDelete` が
        その行の manual で呼ばれる。`false` のとき存在しない
      - DL / 削除のいずれも `disabled` 属性を持たない (禁止事項 8 の回帰封じ)
- [ ] `ProjectsShow.test.ts` に追加:
      - fixture に `duration_ms` / `downloadable` / `deletable` を追加 (既存アサーションは非退行)
      - 削除ボタン押下 → ConfirmDialog が開く → 確認で
        `router.delete` が `/projects/1/manuals/1?...` (現在の絞り込み + ページ) で呼ばれる
      - 絞り込みが空のときは query string 無しの URL で呼ばれる
      - `deletable=false` / `downloadable=false` の行では導線が出ない

### リスク

- 行の要素が 2 つ増えるため狭い画面で窮屈になる。モバイルは縦積み
  (`flex-col` → `sm:flex-row`) に落として操作群を次行へ逃がし、`min-w-0` + `truncate` で
  テキスト側が溢れないようにする。sm 以上のレイアウトは現行と同じ。
- 既存 Vitest の `getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)` は
  同じ `<p>` を保つため緑のままである (行を component へ移すだけで DOM は等価)。

---

## M9: テスト (一覧)

| ファイル | 種別 | 固定する事実 |
|---|---|---|
| `tests/Feature/Projects/ProjectShowManualsTest.php` (追記) | Feature | `duration_ms` / `downloadable` / `deletable` の値、範囲外ページの丸め、`q` 200 文字上限、既存絞り込みの非退行 |
| `tests/Feature/Projects/ManualRowActionsTest.php` (新規) | Feature | 行削除の着地 (絞り込み・ページ維持 / クエリ無しは現行どおり / allowlist 外は載らない)、撮影者 403、cross-project 404 |
| `tests/Feature/Projects/ManualRowAbilityPremiseTest.php` (新規) | Feature | 「同一 project では manual の属性に依らず download/delete の可否が一致する」前提 |
| `tests/Feature/Projects/ManualListQueryCountTest.php` (新規) | Feature | 一覧のクエリ数が行数に比例しない (1 行 = 10 行) |
| `tests/Feature/Manual/ManualRowDownloadableParityTest.php` (新規) | Feature | 一覧の `downloadable` と download endpoint (302/404) の一致、`latestSucceededRender` と `CurrentRenderArtifact` の世代一致、**stale 時は 404** (既存契約の pin) |
| `tests/js/lib/manual/format-duration.test.ts` (新規) | Vitest | 整形規則 (境界値・未確定) |
| `tests/js/components/features/manual/ManualListRow.test.ts` (新規) | Vitest | 行の表示分岐と `onRequestDelete` 発火 |
| `tests/js/pages/ProjectsShow.test.ts` (追記) | Vitest | 削除フローの結線 (ConfirmDialog → router.delete の URL) |

補足 (テスト実装時の注意):

- すべて Factory 生成 (`VideoManual::factory()->forProject()` / `RenderJob::factory()->forManual()->succeeded('path.mp4')`)。
  `Model::create()` の手組みはしない。`VideoManualFactory` に published 用の state を追加して
  テストから使う (**Factory 追加も施策に含む**)。総尺が記録されていない published 行
  (`duration_ms = null` のケース) も表現できるよう**引数は nullable** にする:

  ```php
  /** 公開済み (レンダ完了) の状態。$totalLengthMs = null は総尺が記録されていない行の再現 */
  public function published(?int $totalLengthMs = null): static
  {
      return $this->state(fn (): array => [
          'status' => VideoManualStatus::Published->value,
          'total_length_ms' => $totalLengthMs,
      ]);
  }
  ```
- `ManualListQueryCountTest` は fixture 生成後に `DB::flushQueryLog()` してから GET を 1 回だけ行い、
  `count(DB::getQueryLog())` を比較する (計測範囲を GET に限定する)。
- 撮影者ロールは既存テストの作り方 (`ProjectShowMemberManagementTest` / `ProjectMemberTest`) に合わせる。

---

## 使命・禁止事項チェック

- **使命**: 完成した動画マニュアルを現場へ配る導線 (DL) と、作りかけを片付ける導線 (削除) を
  一覧に置き、配布判断に要る尺を見えるようにする。「思考ゼロ・編集ゼロ」の後工程を短くする。
- **禁止事項 2 (テストなし)**: 全施策に Feature / Vitest を割り当て済み (M9)。
- **禁止事項 4 (`response()->json()`)**: 使わない。Inertia props と redirect のみ。
- **禁止事項 8 (disabled)**: DL / 削除に `disabled` を付けない。二重送信は handler 早期 return。
- **セキュリティ不変条件**: 認可は既存 Gate をそのまま通す (層 2 の 404 が層 3 の 403 より前)。
  tenant/actor キーを payload から受け取らない (`mine` は viewer 自身の id をサーバが使う)。
  削除要求に付くクエリは**対象の決定に使わず**着地先の組み立てだけに使い、allowlist を通す。
- **新しい route / ability / DB カラムを増やしていない**。

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **standalone** |
| 判断根拠 | PHP (Controller 2 / Model / DTO 2 / Service 1) と フロント (types / lib / component 2) と テスト 8 本の協調変更であり、`resources/js/types/manual.ts` と `ProjectController` は他の動画マニュアル系タスクと衝突しやすい。単独の worktree で一括して実装・検証する |
| 競合リスク | `resources/js/types/manual.ts` (他タスクが型を追加する可能性)、`resources/js/pages/Projects/Show.svelte`、`tests/js/pages/ProjectsShow.test.ts`。いずれも追記中心のため衝突しても解決は容易 |
| 検証コマンド | AGENTS.md の VERIFICATION_COMMANDS と完全一致させる: `composer test` / `composer phpstan` / `vendor/bin/pint --test` / `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` (全 green でコミット) |


---

再判定をお願いします。施策ごとの判定と全体判定 (APPROVED / CHANGES_REQUESTED) を明示してください。
