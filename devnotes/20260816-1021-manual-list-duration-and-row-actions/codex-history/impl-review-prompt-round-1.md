## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 思考原則


## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

## セキュリティ不変条件(アプリ都合で緩めない)


【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

# あなたの役割

Laravel 12 (PHP 8.4) + Svelte 5 (runes) + Inertia.js のアプリ AI-CUE の**コードレビュアー**である。
TODO T182「動画一覧の再生時間表示と行内操作」の実装差分をレビューせよ。

## レビュー観点

1. **詳細設計との一致性**: 設計書の各施策 (M1〜M9) が実装されているか。設計から逸脱している箇所は、逸脱の理由が妥当か
2. **正確性**: 論理バグ・境界値 (ページ範囲外・巨大な page・q の切り詰め)・N+1・競合状態
3. **PHPStan level 10 適合性**: 型の緩めがないか、array shape の宣言が実体と合っているか
4. **DTO / JsonResource パターン**: `response()->json()` の直書きが無いか、props の shape が DTO に集約されているか
5. **テスト網羅性**: 各施策にテストがあるか。テストが「空虚な真」になっていないか (assert が実質何も固定していない)。Factory 生成のみか
6. **セキュリティ**: 認可 (層 2 の 404 が層 3 の 403 より前)、tenant/actor キーを payload から受け取っていないか、削除の着地先 URL に生のユーザー入力が載っていないか
7. **DESIGN.md 準拠**: color / radius / typography は design token 経由。hex 直書きを増やしていないか
8. **Atomic Design 準拠**: atoms -> molecules -> organisms -> features/{domain} -> templates -> pages の単方向 import。アイコンは @lucide/svelte のみ (SVG 直書きを増やさない)
9. **アーキテクチャ目録 (deny-by-default) の扱い**: 本実装は T154 の目録 (RenderArtifactSelectionInventory) に新しい区分 EagerLoadCandidate を足している。この判断が「gate を弱めた」ことになっていないか、前提の機械検査が実効性を持つかを厳しく見よ

## 出力形式

- ファイルごとに判定を書く
- 指摘は **[Critical] / [Warning] / [Suggestion]** で分類する
- 最後に **全体判定: APPROVED または CHANGES_REQUESTED** を明記する

---

# 詳細設計書

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
        <!-- タイトルは 1 行省略にする (空白の無い長いタイトルでも行の操作領域を押し出さない)。
             TextLink は class prop を受け取れるので、幅制約用の要素で包まずに付与する -->
        <TextLink
            href={`/projects/${projectId}/manuals/${manual.id}`}
            class="block truncate"
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
      - **長いタイトル (空白を含まない 200 文字) でも省略スタイルが当たっている**:
        タイトル要素の class に `truncate` があり、包む `div` が `min-w-0` を持つ
        (jsdom はレイアウトを計算しないため、固定できるのはスタイル契約まで =
         実寸で溢れないことの保証ではない)
      - DL / 削除のいずれも `disabled` 属性を持たない (禁止事項 8 の回帰封じ)
- [ ] `ProjectsShow.test.ts` に追加:
      - fixture に `duration_ms` / `downloadable` / `deletable` を追加 (既存アサーションは非退行)
      - 削除ボタン押下 → ConfirmDialog が開く → 確認で
        `router.delete` が `/projects/1/manuals/1?...` (現在の絞り込み + ページ) で呼ばれる
      - 絞り込みが空のときは query string 無しの URL で呼ばれる
      - `deletable=false` / `downloadable=false` の行では導線が出ない

### リスク

- 行の要素が 2 つ増えるため狭い画面で窮屈になる。モバイルは縦積み
  (`flex-col` → `sm:flex-row`) に落として操作群を次行へ逃がし、`min-w-0` +
  **タイトル (`block truncate`) とメタ行 (`truncate`) の両方**で溢れを止める。
  sm 以上のレイアウトは現行と同じ。
- **jsdom は レイアウトを計算しない**ため、Vitest で固定できるのは
  「省略スタイルが当たっている」という**スタイル契約**までである
  (実寸で溢れないことは固定できない)。この非対称をテスト名とコメントに明記する。
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


# 実装差分 (git diff)

```diff
diff --git a/app/DataTransferObjects/Manual/ManualListItemData.php b/app/DataTransferObjects/Manual/ManualListItemData.php
new file mode 100644
index 0000000..b652580
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ManualListItemData.php
@@ -0,0 +1,95 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+use App\Enums\Manual\VideoManualStatus;
+use App\Models\VideoManual;
+use App\Services\Manual\ManualRowAbilities;
+
+/**
+ * 動画マニュアル一覧 (Projects/Show に内包) の 1 行。TS の ManualListItem と対。
+ *
+ * **判断はここで 1 回だけ**行う (UI 側で published / ability を再判定しない。
+ * RenderPanel の finishedJob と同じ流儀)。
+ */
+final readonly class ManualListItemData
+{
+    /**
+     * @param  ManualListRefData|null  $category  null = 未分類
+     * @param  ManualListRefData|null  $creator  null = 退会/削除で解決不可
+     * @param  int|null  $durationMs  いま公開されている完成動画の長さ (ms)。null = 未確定
+     * @param  bool  $downloadable  download endpoint が 302 を返す条件と 1 対 1
+     */
+    public function __construct(
+        public int $id,
+        public string $title,
+        public VideoManualStatus $status,
+        public ?ManualListRefData $category,
+        public ?ManualListRefData $creator,
+        public string $createdAt,
+        public string $updatedAt,
+        public ?int $durationMs,
+        public bool $downloadable,
+        public bool $deletable,
+    ) {}
+
+    public static function fromManual(VideoManual $manual, ManualRowAbilities $abilities): self
+    {
+        $category = $manual->category;
+        $creator = $manual->creator; // 退会/削除で null になり得る (実運用では FK RESTRICT)
+        $isPublished = $manual->status === VideoManualStatus::Published;
+
+        // 再生時間は「**いま公開されている**完成動画の長さ」。published が外れた行
+        // (公開後にシナリオを保存すると ScenarioService が ready へ戻す) の total_length_ms は
+        // 最新シナリオと対応しない古い尺なので出さない。
+        $durationMs = $isPublished ? $manual->total_length_ms : null;
+
+        // 受け取れる完成動画: download ability × published × 現行世代の succeeded render に
+        // output_path がある。**ストレージ実体の存在確認ではない** (それは download endpoint も
+        // していない。ここは endpoint が 302 を返す条件と同じものを見ているだけ)。
+        // 世代の選び方は CurrentRenderArtifact と同一 (latestSucceededRender の docblock 参照)。
+        $currentRender = $manual->latestSucceededRender;
+        $downloadable = $abilities->canDownload
+            && $isPublished
+            && $currentRender !== null
+            && $currentRender->output_path !== null;
+
+        return new self(
+            id: $manual->id,
+            title: $manual->title,
+            status: $manual->status,
+            category: $category === null ? null : new ManualListRefData($category->id, $category->name),
+            creator: $creator === null ? null : new ManualListRefData($creator->id, $creator->name),
+            createdAt: $manual->created_at?->format('Y-m-d H:i') ?? '',
+            updatedAt: $manual->updated_at?->format('Y-m-d H:i') ?? '',
+            durationMs: $durationMs,
+            downloadable: $downloadable,
+            deletable: $abilities->canDelete,
+        );
+    }
+
+    /**
+     * @return array{id: int, title: string, status: string,
+     *   category: array{id: int, name: string}|null,
+     *   creator: array{id: int, name: string}|null,
+     *   created_at: string, updated_at: string,
+     *   duration_ms: int|null, downloadable: bool, deletable: bool}
+     */
+    public function toArray(): array
+    {
+        return [
+            'id' => $this->id,
+            'title' => $this->title,
+            'status' => $this->status->value,
+            'category' => $this->category?->toArray(),
+            'creator' => $this->creator?->toArray(),
+            'created_at' => $this->createdAt,
+            'updated_at' => $this->updatedAt,
+            'duration_ms' => $this->durationMs,
+            'downloadable' => $this->downloadable,
+            'deletable' => $this->deletable,
+        ];
+    }
+}
diff --git a/app/DataTransferObjects/Manual/ManualListQuery.php b/app/DataTransferObjects/Manual/ManualListQuery.php
new file mode 100644
index 0000000..66d6250
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ManualListQuery.php
@@ -0,0 +1,149 @@
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
+        if ($category !== null && $category !== 'uncategorized' && ! ctype_digit($category)) {
+            $category = null;
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
diff --git a/app/DataTransferObjects/Manual/ManualListRefData.php b/app/DataTransferObjects/Manual/ManualListRefData.php
new file mode 100644
index 0000000..e722357
--- /dev/null
+++ b/app/DataTransferObjects/Manual/ManualListRefData.php
@@ -0,0 +1,25 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\DataTransferObjects\Manual;
+
+/**
+ * 一覧行が参照する {id, name} の対 (カテゴリ / 作成者)。
+ * 「id と name は必ず揃う」ことを型で保つ (片方だけ null になる状態を作らない)。
+ */
+final readonly class ManualListRefData
+{
+    public function __construct(
+        public int $id,
+        public string $name,
+    ) {}
+
+    /**
+     * @return array{id: int, name: string}
+     */
+    public function toArray(): array
+    {
+        return ['id' => $this->id, 'name' => $this->name];
+    }
+}
diff --git a/app/Enums/Security/RenderArtifactSelectionKind.php b/app/Enums/Security/RenderArtifactSelectionKind.php
index b1083ef..c7d292a 100644
--- a/app/Enums/Security/RenderArtifactSelectionKind.php
+++ b/app/Enums/Security/RenderArtifactSelectionKind.php
@@ -24,4 +24,12 @@ enum RenderArtifactSelectionKind: string
      * **選択ではない** — id の大小比較だけで「どれを受け取るか」を決めない。
      */
     case SupersessionCriterion = 'supersession_criterion';
+
+    /**
+     * 一覧が eager load する**候補行**の relation (最新 succeeded 1 行)。
+     * **受け取れるかを判断しない** — `output_path` を見ないため、
+     * 「受け取れる成果物はどれか」の決定は Canonical に残る
+     * (両者が同じ行を指すことは behavioral な parity テストが固定する)。
+     */
+    case EagerLoadCandidate = 'eager_load_candidate';
 }
diff --git a/app/Http/Controllers/Projects/ProjectController.php b/app/Http/Controllers/Projects/ProjectController.php
index f7ed3fe..f74891b 100644
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
@@ -133,135 +135,86 @@ public function show(Request $request, Project $project, SeoManager $seo): Respo
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
+        // URL の ?page=99 と meta.current_page は食い違うが、ページ送り UI は
+        // meta.current_page を見る (**props が正本**であり redirect はしない)。
+        if ($paginated->currentPage() > $paginated->lastPage() && $paginated->total() > 0) {
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
diff --git a/app/Http/Controllers/Projects/VideoManualController.php b/app/Http/Controllers/Projects/VideoManualController.php
index 76994bf..3e0c199 100644
--- a/app/Http/Controllers/Projects/VideoManualController.php
+++ b/app/Http/Controllers/Projects/VideoManualController.php
@@ -5,6 +5,7 @@
 namespace App\Http\Controllers\Projects;
 
 use App\DataTransferObjects\Manual\AnalysisJobData;
+use App\DataTransferObjects\Manual\ManualListQuery;
 use App\DataTransferObjects\Manual\RenderJobData;
 use App\DataTransferObjects\Manual\ScenarioDocumentData;
 use App\Enums\Manual\RenderKind;
@@ -235,7 +236,17 @@ public function update(UpdateVideoManualRequest $request, Project $project, Vide
         return back()->with('success', '動画マニュアルを更新しました');
     }
 
-    /** 削除 */
+    /**
+     * 削除。
+     *
+     * 一覧の行から消したときは、削除要求に一覧の絞り込み・ページが付いてくる
+     * (`?category=…&status=…&q=…&sort=…&mine=1&page=2`)。受け取った値は**一覧と同じ allowlist**
+     * (ManualListQuery) を通してから着地先に載せ直す = 生のユーザー入力を Location に素通ししない。
+     * クエリが無いとき (詳細画面からの削除) は現行と同じ `/projects/{project}` へ着地する。
+     * 消した結果そのページが範囲外になった場合は、着地先の一覧が最終ページへ丸める。
+     *
+     * 付いてくるクエリは**対象の決定には一切使わない** (対象は route パラメータのみが決める)。
+     */
     public function destroy(Request $request, Project $project, VideoManual $manual, VideoManualService $manuals): RedirectResponse
     {
         $organization = $this->resolveCurrentOrganization($request);
@@ -243,10 +254,12 @@ public function destroy(Request $request, Project $project, VideoManual $manual,
         $this->resolveOrganizationProject($organization, $project);
         Gate::authorize('delete', $manual);
 
+        $listQuery = ManualListQuery::fromRequest($request);
+
         $manuals->delete($project, $manual);
 
         return redirect()
-            ->route('projects.show', $project)
+            ->route('projects.show', ['project' => $project, ...$listQuery->toQueryParams()])
             ->with('success', '動画マニュアルを削除しました');
     }
 
diff --git a/app/Models/VideoManual.php b/app/Models/VideoManual.php
index ca08adc..aba9352 100644
--- a/app/Models/VideoManual.php
+++ b/app/Models/VideoManual.php
@@ -4,12 +4,16 @@
 
 namespace App\Models;
 
+use App\Enums\Manual\JobStatus;
+use App\Enums\Manual\RenderKind;
 use App\Enums\Manual\VideoManualStatus;
 use Database\Factories\VideoManualFactory;
+use Illuminate\Database\Eloquent\Builder;
 use Illuminate\Database\Eloquent\Factories\HasFactory;
 use Illuminate\Database\Eloquent\Model;
 use Illuminate\Database\Eloquent\Relations\BelongsTo;
 use Illuminate\Database\Eloquent\Relations\HasMany;
+use Illuminate\Database\Eloquent\Relations\HasOne;
 
 /**
  * VideoManual (Project 配下の動画マニュアル)。
@@ -27,6 +31,7 @@
  * @property VideoManualStatus $status
  * @property int $scenario_version
  * @property int|null $total_length_ms
+ * @property-read RenderJob|null $latestSucceededRender
  */
 class VideoManual extends Model
 {
@@ -107,4 +112,32 @@ public function renderJobs(): HasMany
     {
         return $this->hasMany(RenderJob::class);
     }
+
+    /**
+     * 「いま受け取れる完成動画」の**候補行** (kind=render の最新 succeeded 1 行)。
+     *
+     * 世代の選び方は `CurrentRenderArtifact::currentSucceeded($manual, RenderKind::Render)` と
+     * **同一**である (同 manual・同 kind の最新 succeeded。旧世代へフォールバックしない)。
+     * 違いは 1 点だけで、**こちらは受け取れるかを判断しない** — `output_path` を見ないため
+     * NULL (掃除済み) の行も返す。受け取れるかの決定は呼び出し側 (ManualListItemData) が
+     * `output_path !== null` を足して行い、両者が同じ行を指すことは
+     * `ManualRowDownloadableParityTest` が固定する。
+     *
+     * 一覧が行ごとに `currentSucceeded()` を呼ぶと N+1 になるため、eager load できる形を用意する
+     * (`ManualListQueryCountTest` がクエリ数の行数非依存を固定する)。
+     * 目録登録は `RenderArtifactSelectionInventory` (区分 EagerLoadCandidate)。
+     *
+     * @return HasOne<RenderJob, $this>
+     */
+    public function latestSucceededRender(): HasOne
+    {
+        return $this->hasOne(RenderJob::class)->ofMany(
+            ['id' => 'max'],
+            /** @param Builder<RenderJob> $query */
+            function (Builder $query): void {
+                $query->where('kind', RenderKind::Render->value)
+                    ->where('status', JobStatus::Succeeded->value);
+            }
+        );
+    }
 }
diff --git a/app/Services/Manual/ManualRowAbilities.php b/app/Services/Manual/ManualRowAbilities.php
new file mode 100644
index 0000000..6aedae8
--- /dev/null
+++ b/app/Services/Manual/ManualRowAbilities.php
@@ -0,0 +1,62 @@
+<?php
+
+declare(strict_types=1);
+
+namespace App\Services\Manual;
+
+use App\Models\Project;
+use App\Models\User;
+use App\Models\VideoManual;
+
+/**
+ * 一覧の行に出す操作 (完成動画のダウンロード / 削除) の可否。
+ *
+ * **前提 (名前が示す約束)**: download / delete の可否は「その manual が属する project」で決まり、
+ * manual 個別の属性 (status / 作成者 / カテゴリ) には依存しない。
+ * VideoManualPolicy::download / ::delete が対象から `project` しか読まず
+ * ProjectPolicy::update へ委譲しているためである。よって**ページで 1 回だけ**評価して全行へ配る。
+ *
+ * **なぜ畳むか**: ProjectPolicy は毎回 DB を見る (Project::memberRole() は memo 無しのクエリ、
+ * Laratrust のキャッシュは config/laratrust.php の既定で production 以外は無効)。
+ * 行ごとに can() を呼ぶと権限解決クエリが行数に比例する (per_page=10 × 2 ability)。
+ *
+ * **なぜ ProjectPolicy::update を直接問わないか**: それは委譲関係を呼び出し側へ
+ * ハードコードすることであり、policy が分岐した日に**赤くならずに間違う**。
+ * 問う ability 名は download / delete のまま保ち、評価の**回数**だけを畳む。
+ *
+ * 前提は ManualRowAbilityPremiseTest が固定し (manual 依存になったら赤くなる)、
+ * 行数に比例しないことは ManualListQueryCountTest が固定する。読み取り専用。
+ *
+ * **前提が崩れたときの手順**: ManualRowAbilityPremiseTest が赤くなったら、
+ * 評価を行ループへ移す (そのとき N+1 の解消も同時に設計し直す)。
+ */
+final readonly class ManualRowAbilities
+{
+    private function __construct(
+        public bool $canDownload,
+        public bool $canDelete,
+    ) {}
+
+    /**
+     * ページに載る行に対する可否。行が 1 件も無いページでは両方 false
+     * (出す導線が無いので評価しない = 無駄な権限クエリを撃たない)。
+     *
+     * @param  list<VideoManual>  $manuals  同一 $project 配下であること (呼び出し側が保証する)
+     */
+    public static function forPage(User $user, Project $project, array $manuals): self
+    {
+        $representative = $manuals[0] ?? null;
+        if ($representative === null) {
+            return new self(canDownload: false, canDelete: false);
+        }
+
+        // policy が親を読み直すクエリを避けるため、解決済みの project を先に確定させる
+        // (同一 project 配下であることは呼び出し側 = $project->manuals() が保証している)
+        $representative->setRelation('project', $project);
+
+        return new self(
+            canDownload: $user->can('download', $representative),
+            canDelete: $user->can('delete', $representative),
+        );
+    }
+}
diff --git a/app/Support/Security/RenderArtifactSelectionInventory.php b/app/Support/Security/RenderArtifactSelectionInventory.php
index d641938..4b9dc4a 100644
--- a/app/Support/Security/RenderArtifactSelectionInventory.php
+++ b/app/Support/Security/RenderArtifactSelectionInventory.php
@@ -41,6 +41,12 @@ public static function entries(): array
                 'rationale' => 'newerSucceededExists() は「より新しい succeeded が在るか」の世代交代判定であり、'
                     .'受け取り対象を 1 件選ぶ式ではない (削除 job と reconcile の前提条件)。',
             ],
+            'Models/VideoManual.php' => [
+                'kind' => RenderArtifactSelectionKind::EagerLoadCandidate,
+                'rationale' => 'latestSucceededRender() は一覧が eager load する候補行の relation であり、'
+                    .'output_path を見ないため受け取れるかを判断しない (決定は Canonical に残る)。'
+                    .'世代定義の一致は ManualRowDownloadableParityTest が固定する。',
+            ],
             'Services/Manual/RenderPipeline.php' => [
                 'kind' => RenderArtifactSelectionKind::SupersessionCriterion,
                 'rationale' => 'finalize が自分より古い succeeded 行を集めて削除 job を投入するための収集であり、'
diff --git a/database/factories/VideoManualFactory.php b/database/factories/VideoManualFactory.php
index a50cff9..b791ecd 100644
--- a/database/factories/VideoManualFactory.php
+++ b/database/factories/VideoManualFactory.php
@@ -46,6 +46,19 @@ public function forCategory(Category $category): static
         return $this->state(fn () => ['category_id' => $category->id]);
     }
 
+    /**
+     * 公開済み (レンダ完了) の状態。
+     * $totalLengthMs = null は「総尺が記録されていない published 行」の再現
+     * (一覧の duration_ms が null になるケース)。
+     */
+    public function published(?int $totalLengthMs = null): static
+    {
+        return $this->state(fn (): array => [
+            'status' => VideoManualStatus::Published->value,
+            'total_length_ms' => $totalLengthMs,
+        ]);
+    }
+
     /** 作成者を指定する */
     public function createdBy(User $user): static
     {
diff --git a/resources/js/components/features/manual/ManualListRow.svelte b/resources/js/components/features/manual/ManualListRow.svelte
new file mode 100644
index 0000000..59b9027
--- /dev/null
+++ b/resources/js/components/features/manual/ManualListRow.svelte
@@ -0,0 +1,90 @@
+<script lang="ts">
+    import { Download, Trash2 } from "@lucide/svelte";
+    import Badge from "@/components/atoms/Badge.svelte";
+    import Button from "@/components/atoms/Button.svelte";
+    import TextLink from "@/components/atoms/TextLink.svelte";
+    import { formatDurationMs } from "@/lib/manual/format-duration";
+    import type { ManualListItem } from "@/types/manual";
+    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+
+    /**
+     * 動画マニュアル一覧の 1 行 (doc/04: 状態 / タイトル / カテゴリ / 再生時間 / 更新日 / DL / 削除)。
+     *
+     * 表示の出し分けは**サーバが決めた行 props だけ**で行う
+     * (downloadable / deletable。published も ability も UI 側で再判定しない)。
+     * 削除の実行は一覧ページが持つ (この component は確認ダイアログを開く要求を上へ返すだけ)。
+     */
+    interface Props {
+        projectId: number;
+        manual: ManualListItem;
+        /** 削除確認ダイアログを開く要求 */
+        onRequestDelete: (manual: ManualListItem) => void;
+    }
+
+    let { projectId, manual, onRequestDelete }: Props = $props();
+
+    const durationLabel = $derived(formatDurationMs(manual.duration_ms));
+</script>
+
+<!-- 狭い画面では縦積み (操作群を次行へ逃がす)、sm 以上で現行と同じ横並びに戻す。
+     操作が 2 つ増えて shrink-0 側が広がるため、モバイルで行が潰れないようにする -->
+<li
+    class="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
+    data-testid={`manual-row-${manual.id}`}
+>
+    <div class="min-w-0">
+        <!-- タイトルは 1 行省略にする (空白の無い長いタイトルでも行の操作領域を押し出さない)。
+             TextLink は class prop を受け取れるので、幅制約用の要素で包まずに付与する -->
+        <TextLink
+            href={`/projects/${projectId}/manuals/${manual.id}`}
+            class="block truncate"
+            testId={`manual-link-${manual.id}`}
+        >
+            {manual.title}
+        </TextLink>
+        <p class="mt-1 truncate text-caption text-text-secondary">
+            {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ?? "不明"} ・ 更新 {manual.updated_at}
+        </p>
+    </div>
+    <div class="flex shrink-0 items-center gap-2">
+        <!-- 再生時間: 公開済みの完成動画の長さ。未確定は「—」。権限では隠さない -->
+        <span
+            class="text-caption text-text-secondary"
+            data-testid={`manual-duration-${manual.id}`}
+        >
+            {durationLabel}
+        </span>
+        <Badge tone={STATUS_TONES[manual.status]} testId={`manual-status-${manual.id}`}>
+            {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
+        </Badge>
+        {#if manual.downloadable}
+            <!-- 受け取れるとサーバが判断した行にだけ出す。押せない (disabled) ボタンは作らない。
+                 出ていない行の理由は状態バッジと再生時間「—」が語り、書き出しの CTA は
+                 詳細画面 (RenderPanel) が唯一持つ。
+                 素の <a> (inertia なし) = 非 Inertia 遷移。成功時は attachment 応答のため
+                 画面は遷移しない。 -->
+            <Button
+                variant="ghost"
+                size="sm"
+                href={`/projects/${projectId}/manuals/${manual.id}/download`}
+                ariaLabel={`${manual.title} の完成動画をダウンロード`}
+                testId={`manual-download-${manual.id}`}
+            >
+                <Download class="size-4" />
+                DL
+            </Button>
+        {/if}
+        {#if manual.deletable}
+            <Button
+                variant="danger-ghost"
+                size="sm"
+                onclick={() => onRequestDelete(manual)}
+                ariaLabel={`${manual.title} を削除`}
+                testId={`manual-remove-${manual.id}`}
+            >
+                <Trash2 class="size-4" />
+                削除
+            </Button>
+        {/if}
+    </div>
+</li>
diff --git a/resources/js/lib/manual/format-duration.ts b/resources/js/lib/manual/format-duration.ts
new file mode 100644
index 0000000..0615698
--- /dev/null
+++ b/resources/js/lib/manual/format-duration.ts
@@ -0,0 +1,31 @@
+/**
+ * 再生時間の可読表記 (動画一覧の再生時間表示)。
+ *
+ * ms → "M:SS"、1 時間以上は "H:MM:SS"。秒は**四捨五入**する
+ * (表示専用であり、長さの比較・判定には使わない = format-bytes.ts と同じ位置づけ。
+ * 切り捨てにしないのは、59.6 秒を "0:59" と書くより "1:00" と書く方が実尺に近いためで、
+ * 差は 1 秒未満であり配布判断に影響しない)。
+ * サーバ整形にしないのは、日時と違いタイムゾーンに依存しないため。
+ *
+ * null / 有限でない値 / 負値は「未確定」を表す DURATION_UNKNOWN を返す
+ * (未確定を 0:00 と書くと「長さゼロの動画がある」という別の嘘になる)。
+ */
+export const DURATION_UNKNOWN = "—";
+
+export function formatDurationMs(durationMs: number | null): string {
+    if (durationMs === null || !Number.isFinite(durationMs) || durationMs < 0) {
+        return DURATION_UNKNOWN;
+    }
+
+    const totalSeconds = Math.round(durationMs / 1000);
+    const hours = Math.floor(totalSeconds / 3600);
+    const minutes = Math.floor((totalSeconds % 3600) / 60);
+    const seconds = totalSeconds % 60;
+    const ss = String(seconds).padStart(2, "0");
+
+    if (hours > 0) {
+        return `${hours}:${String(minutes).padStart(2, "0")}:${ss}`;
+    }
+
+    return `${minutes}:${ss}`;
+}
diff --git a/resources/js/pages/Projects/Show.svelte b/resources/js/pages/Projects/Show.svelte
index 84bce94..267c60e 100644
--- a/resources/js/pages/Projects/Show.svelte
+++ b/resources/js/pages/Projects/Show.svelte
@@ -15,6 +15,7 @@
     import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
     import Pagination from "@/components/molecules/Pagination.svelte";
     import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
+    import ManualListRow from "@/components/features/manual/ManualListRow.svelte";
     import Modal from "@/components/organisms/Modal.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import PageContainer from "@/components/templates/PageContainer.svelte";
@@ -26,7 +27,7 @@
         ManualListItem,
         PaginationMeta,
     } from "@/types/manual";
-    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
+    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";
 
     /**
      * プロジェクト詳細。動画マニュアル一覧 (フィルタ + paginate)・カテゴリ管理・
@@ -129,6 +130,48 @@
         });
     }
 
+    /* ---- 動画マニュアル: 行内削除 (ConfirmDialog → destroy) ---- */
+    let removeManualTarget = $state<ManualListItem | null>(null);
+    let removeManualDialogOpen = $state(false);
+    let removingManual = $state(false);
+
+    function openRemoveManualDialog(manual: ManualListItem): void {
+        removeManualTarget = manual;
+        removeManualDialogOpen = true;
+    }
+
+    /** 現在の絞り込み + 表示中ページを query string 化する (削除の着地先を保つため) */
+    function manualQueryString(): string {
+        // 使い捨ての組み立てなので URLSearchParams / SvelteURLSearchParams は使わない
+        // (反応性の要らない場面で反応クラスを持ち込まない。svelte/prefer-svelte-reactivity)
+        const serialized = Object.entries(manualQuery(manuals.meta.current_page))
+            .map(
+                ([key, value]) =>
+                    `${encodeURIComponent(key)}=${encodeURIComponent(String(value))}`,
+            )
+            .join("&");
+
+        return serialized === "" ? "" : `?${serialized}`;
+    }
+
+    function removeManual(): void {
+        const target = removeManualTarget;
+        // 二重送信ガードは handler 早期 return で行う (ボタンに disabled を付けない = 禁止事項 8)
+        if (target === null || removingManual) return;
+        // 絞り込み・ページを付けて送る。サーバは同じ allowlist を通して着地先に載せ直すため、
+        // 削除後も同じ絞り込み・同じページに戻る (そのページが消えたらサーバが最終ページへ丸める)
+        router.delete(`/projects/${project.id}/manuals/${target.id}${manualQueryString()}`, {
+            preserveScroll: true,
+            onStart: () => {
+                removingManual = true;
+            },
+            onFinish: () => {
+                removingManual = false;
+                removeManualDialogOpen = false;
+            },
+        });
+    }
+
     /* ---- プロジェクトメンバー管理 ---- */
     // ProjectRole の value → 日本語ラベル (追加/変更 select の option 定数。サーバ enum に対応)
     const PROJECT_ROLE_OPTIONS: { value: string; label: string }[] = [
@@ -424,26 +467,11 @@
                 {:else}
                     <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                         {#each manuals.data as manual (manual.id)}
-                            <li class="flex items-center justify-between gap-4 py-3">
-                                <div class="min-w-0">
-                                    <TextLink
-                                        href={`/projects/${project.id}/manuals/${manual.id}`}
-                                        testId={`manual-link-${manual.id}`}
-                                    >
-                                        {manual.title}
-                                    </TextLink>
-                                    <p class="mt-1 text-caption text-text-secondary">
-                                        {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ??
-                                            "不明"} ・ 更新 {manual.updated_at}
-                                    </p>
-                                </div>
-                                <Badge
-                                    tone={STATUS_TONES[manual.status]}
-                                    testId={`manual-status-${manual.id}`}
-                                >
-                                    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
-                                </Badge>
-                            </li>
+                            <ManualListRow
+                                projectId={project.id}
+                                {manual}
+                                onRequestDelete={openRemoveManualDialog}
+                            />
                         {/each}
                     </ul>
                     <div class="mt-4">
@@ -768,6 +796,17 @@
             testId="remove-item-dialog"
         />
 
+        <ConfirmDialog
+            bind:open={removeManualDialogOpen}
+            title="動画マニュアル削除"
+            message={`「${removeManualTarget?.title ?? ""}」を削除しますか？ 撮影テイクや完成動画も削除され、この操作は取り消せません。`}
+            confirmLabel="削除する"
+            confirmVariant="danger"
+            processing={removingManual}
+            onConfirm={removeManual}
+            testId="remove-manual-dialog"
+        />
+
         <ConfirmDialog
             bind:open={removeMemberDialogOpen}
             title="メンバー削除"
diff --git a/resources/js/types/manual.ts b/resources/js/types/manual.ts
index d9517a6..8b2bec2 100644
--- a/resources/js/types/manual.ts
+++ b/resources/js/types/manual.ts
@@ -99,6 +99,7 @@ export interface PaginationMeta {
 /** PHP App\Enums\Manual\ManualSortOption と値集合を一致させる (allowlist) */
 export type ManualSortOption = "updated_desc" | "updated_asc" | "title_asc" | "title_desc";
 
+/** PHP: App\DataTransferObjects\Manual\ManualListItemData::toArray() と対 */
 export interface ManualListItem {
     id: number;
     title: string;
@@ -109,6 +110,22 @@ export interface ManualListItem {
     creator: { id: number; name: string } | null;
     created_at: string;
     updated_at: string;
+    /**
+     * いま公開されている完成動画の長さ (ms)。**null = 未確定**
+     * (published でない / 総尺が記録されていない)。
+     * published が外れた行の古い総尺はサーバが null に畳んでいるため、
+     * UI 側で status を見て再判定しない。
+     */
+    duration_ms: number | null;
+    /**
+     * 完成動画を受け取れるか。サーバが「download ability × published ×
+     * 現行世代の succeeded render に output_path がある」を判定した結果そのもので、
+     * **UI 側で条件を再判定しない**。download endpoint が 302 を返す条件と 1 対 1
+     * (描画時点のスナップショットであり、ストレージ実体の存在確認ではない)。
+     */
+    downloadable: boolean;
+    /** 削除できるか (サーバの delete ability 判定結果。撮影者は false) */
+    deletable: boolean;
 }
 
 export interface CategoryOption {
diff --git a/tests/Architecture/CurrentRenderArtifactInventoryTest.php b/tests/Architecture/CurrentRenderArtifactInventoryTest.php
index cb4d204..16589b8 100644
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
@@ -168,6 +173,26 @@ public static function hasIdComparison(array $tokens): bool
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
     /** @return list<string> 指定区分に登録された app/ 相対パス (昇順) */
     public static function filesOfKind(RenderArtifactSelectionKind $kind): array
     {
@@ -313,6 +338,32 @@ public static function phpFiles(string $dir): array
     }
 });
 
+test('ケース 7: EagerLoadCandidate は output_path を参照しない (前提の機械検査)', function (): void {
+    $candidateFiles = RenderArtifactSelectionScanner::filesOfKind(
+        RenderArtifactSelectionKind::EagerLoadCandidate,
+    );
+
+    expect($candidateFiles)->not->toBeEmpty();
+    foreach ($candidateFiles as $relative) {
+        $tokens = RenderArtifactSelectionScanner::tokensOf($relative);
+        expect(RenderArtifactSelectionScanner::hasOutputPathReference($tokens))->toBeFalse(
+            "{$relative} が output_path を参照しました。候補行の relation が「受け取れるか」の"
+            .'判断を持ち始めた可能性があります (選択式の単一化が崩れるため区分を再審査してください)');
+    }
+});
+
+test('scanner 自己検証: EagerLoadCandidate の前提検査は output_path の参照を捉える', function (): void {
+    $propertyAccess = PhpTokenScan::normalize('<?php $p = $job->output_path;');
+    $literal = PhpTokenScan::normalize("<?php \$q->whereNotNull('output_path');");
+    $none = PhpTokenScan::normalize("<?php \$q->where('kind', 'render');");
+    $commentOnly = PhpTokenScan::normalize("<?php\n// output_path はコメント\nclass Example {}");
+
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($propertyAccess))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($literal))->toBeTrue();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($none))->toBeFalse();
+    expect(RenderArtifactSelectionScanner::hasOutputPathReference($commentOnly))->toBeFalse();
+});
+
 test('scanner 自己検証: コメント / docblock 内の出現は数えない', function (): void {
     $tokens = PhpTokenScan::normalize(<<<'PHP'
     <?php
diff --git a/tests/Feature/Manual/ManualRowDownloadableParityTest.php b/tests/Feature/Manual/ManualRowDownloadableParityTest.php
new file mode 100644
index 0000000..2b9b7fe
--- /dev/null
+++ b/tests/Feature/Manual/ManualRowDownloadableParityTest.php
@@ -0,0 +1,108 @@
+<?php
+
+declare(strict_types=1);
+
+use App\Enums\Manual\RenderKind;
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
index 0000000..5950a64
--- /dev/null
+++ b/tests/Feature/Projects/ManualRowActionsTest.php
@@ -0,0 +1,112 @@
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
diff --git a/tests/Feature/Projects/ProjectShowManualsTest.php b/tests/Feature/Projects/ProjectShowManualsTest.php
index 911bef6..ec58792 100644
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
 
@@ -271,3 +273,153 @@
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
diff --git a/tests/js/components/features/manual/ManualListRow.test.ts b/tests/js/components/features/manual/ManualListRow.test.ts
new file mode 100644
index 0000000..f7789ee
--- /dev/null
+++ b/tests/js/components/features/manual/ManualListRow.test.ts
@@ -0,0 +1,116 @@
+import { describe, expect, it, vi } from "vitest";
+import { fireEvent, render, screen } from "@testing-library/svelte";
+import ManualListRow from "@/components/features/manual/ManualListRow.svelte";
+import type { ManualListItem } from "@/types/manual";
+
+/*
+ * 動画マニュアル一覧の 1 行 (T182)。
+ *
+ * 固定する契約:
+ * - 再生時間はサーバの duration_ms をそのまま整形する (未確定は「—」)
+ * - DL / 削除の導線はサーバが決めた downloadable / deletable **だけ**で出し分ける
+ *   (UI 側で published や権限を再判定しない)
+ * - DL は**通常 anchor** (非 Inertia 遷移)。Inertia の Link へ退行したら赤くなる
+ * - どちらの導線も disabled を持たない (禁止事項 8 の回帰封じ)
+ *
+ * Inertia の `Link` は素の <a href> として描画され判別できる属性を持たないため、
+ * 既存のスタブ (tests/js/support/InertiaLinkStub.svelte) へ差し替えて
+ * 「描画されたら印が残る」状態で検証する。
+ */
+vi.mock("@inertiajs/svelte", async () => ({
+    Link: (await import("../../../support/InertiaLinkStub.svelte")).default,
+}));
+
+function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
+    return {
+        id: 1,
+        title: "ネジ締め作業",
+        status: "published",
+        category: { id: 1, name: "準備作業" },
+        creator: { id: 2, name: "編集 花子" },
+        created_at: "2026-07-10 12:00",
+        updated_at: "2026-07-11 09:00",
+        duration_ms: 185_000,
+        downloadable: true,
+        deletable: true,
+        ...overrides,
+    };
+}
+
+function renderRow(overrides: Partial<ManualListItem> = {}, onRequestDelete = vi.fn()) {
+    render(ManualListRow, {
+        props: { projectId: 7, manual: manualItem(overrides), onRequestDelete },
+    });
+
+    return onRequestDelete;
+}
+
+describe("features/manual/ManualListRow", () => {
+    it("再生時間・状態バッジ・カテゴリ / 作成者 / 更新日を描画する", () => {
+        renderRow();
+
+        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("3:05");
+        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("公開済み");
+        expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
+    });
+
+    it("duration_ms が null のときは「—」を表示する (0:00 と書かない)", () => {
+        renderRow({ duration_ms: null });
+
+        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
+    });
+
+    it("downloadable=true のとき DL リンクを download endpoint へ出す", () => {
+        renderRow();
+
+        const link = screen.getByTestId("manual-download-1");
+        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
+            "/projects/7/manuals/1/download",
+        );
+    });
+
+    it("DL は通常 anchor である (Inertia Link へ退行したら赤くなる)", () => {
+        renderRow();
+
+        const link = screen.getByTestId("manual-download-1");
+        expect(link.tagName).toBe("A");
+        // Inertia Link スタブが描画されるのはタイトルリンクの 1 本だけ (DL は素の <a>)
+        expect(screen.getAllByTestId("inertia-link-stub")).toHaveLength(1);
+    });
+
+    it("downloadable=false のとき DL リンクを出さない (押せないボタンを置かない)", () => {
+        renderRow({ downloadable: false });
+
+        expect(screen.queryByTestId("manual-download-1")).toBeNull();
+    });
+
+    it("deletable=true のとき削除ボタンを出し、押すとその行で onRequestDelete が呼ばれる", async () => {
+        const onRequestDelete = renderRow();
+
+        await fireEvent.click(screen.getByTestId("manual-remove-1"));
+
+        expect(onRequestDelete).toHaveBeenCalledTimes(1);
+        expect(onRequestDelete.mock.calls[0][0]).toMatchObject({ id: 1, title: "ネジ締め作業" });
+    });
+
+    it("deletable=false のとき削除ボタンを出さない", () => {
+        renderRow({ deletable: false });
+
+        expect(screen.queryByTestId("manual-remove-1")).toBeNull();
+    });
+
+    it("DL / 削除のいずれも disabled を持たない (禁止事項 8)", () => {
+        renderRow();
+
+        expect(screen.getByTestId("manual-download-1")).not.toBeDisabled();
+        expect(screen.getByTestId("manual-remove-1")).not.toBeDisabled();
+    });
+
+    it("長いタイトルでも省略スタイルが当たっている (jsdom は実寸を計算しないためスタイル契約まで)", () => {
+        renderRow({ title: "あ".repeat(200) });
+
+        const title = screen.getAllByTestId("inertia-link-stub")[0];
+        expect(title.getAttribute("class") ?? "").toContain("truncate");
+        expect(title.closest("div")?.getAttribute("class") ?? "").toContain("min-w-0");
+    });
+});
diff --git a/tests/js/lib/manual/format-duration.test.ts b/tests/js/lib/manual/format-duration.test.ts
new file mode 100644
index 0000000..76882bb
--- /dev/null
+++ b/tests/js/lib/manual/format-duration.test.ts
@@ -0,0 +1,43 @@
+import { describe, expect, it } from "vitest";
+import { DURATION_UNKNOWN, formatDurationMs } from "@/lib/manual/format-duration";
+
+/*
+ * 再生時間の整形 (表示専用)。
+ * - 未確定 (null / 有限でない / 負値) は「—」= 0:00 と書かない
+ * - 秒は四捨五入 (切り捨てにしない。差は 1 秒未満で配布判断に影響しない)
+ */
+
+describe("formatDurationMs", () => {
+    it("未確定 (null) は DURATION_UNKNOWN を返す", () => {
+        expect(formatDurationMs(null)).toBe(DURATION_UNKNOWN);
+        expect(DURATION_UNKNOWN).toBe("—");
+    });
+
+    it("0 は 0:00 (長さゼロの動画という事実をそのまま書く)", () => {
+        expect(formatDurationMs(0)).toBe("0:00");
+    });
+
+    it("分未満は M:SS で表示する", () => {
+        expect(formatDurationMs(1_000)).toBe("0:01");
+        expect(formatDurationMs(59_400)).toBe("0:59");
+    });
+
+    it("秒は四捨五入する (59.6 秒は 1:00 へ繰り上がる)", () => {
+        expect(formatDurationMs(59_600)).toBe("1:00");
+    });
+
+    it("分・秒を 2 桁ゼロ埋めで表示する", () => {
+        expect(formatDurationMs(185_000)).toBe("3:05");
+    });
+
+    it("1 時間以上は H:MM:SS で表示する", () => {
+        expect(formatDurationMs(3_600_000)).toBe("1:00:00");
+        expect(formatDurationMs(3_725_000)).toBe("1:02:05");
+    });
+
+    it("負値 / NaN / Infinity は未確定として扱う", () => {
+        expect(formatDurationMs(-1)).toBe(DURATION_UNKNOWN);
+        expect(formatDurationMs(Number.NaN)).toBe(DURATION_UNKNOWN);
+        expect(formatDurationMs(Number.POSITIVE_INFINITY)).toBe(DURATION_UNKNOWN);
+    });
+});
diff --git a/tests/js/pages/ProjectsShow.test.ts b/tests/js/pages/ProjectsShow.test.ts
index 0dee1a9..f742d95 100644
--- a/tests/js/pages/ProjectsShow.test.ts
+++ b/tests/js/pages/ProjectsShow.test.ts
@@ -22,6 +22,9 @@ const manualsFixture: ManualListItem[] = [
         creator: { id: 2, name: "編集 花子" },
         created_at: "2026-07-10 12:00",
         updated_at: "2026-07-11 09:00",
+        duration_ms: null,
+        downloadable: false,
+        deletable: true,
     },
     {
         id: 2,
@@ -31,6 +34,9 @@ const manualsFixture: ManualListItem[] = [
         creator: null,
         created_at: "2026-07-10 13:00",
         updated_at: "2026-07-11 10:00",
+        duration_ms: 185_000,
+        downloadable: true,
+        deletable: true,
     },
 ];
 
@@ -289,6 +295,88 @@ describe("Projects/Show 並べ替え・自作フィルタ", () => {
     });
 });
 
+describe("Projects/Show 動画マニュアルの行内操作", () => {
+    afterEach(() => {
+        vi.restoreAllMocks();
+    });
+
+    it("行の再生時間と DL 導線をサーバの props どおりに出し分ける", () => {
+        render(Show, { props: baseProps });
+
+        // duration_ms=null の行は「—」、値のある行は整形して出す
+        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
+        expect(screen.getByTestId("manual-duration-2")).toHaveTextContent("3:05");
+        // downloadable の行にだけ DL 導線が出る
+        expect(screen.queryByTestId("manual-download-1")).toBeNull();
+        expect(screen.getByTestId("manual-download-2").getAttribute("href")).toMatch(
+            /\/projects\/1\/manuals\/2\/download$/,
+        );
+    });
+
+    it("deletable=false の行には削除導線を出さない", () => {
+        render(Show, {
+            props: {
+                ...baseProps,
+                manuals: {
+                    data: manualsFixture.map((manual) => ({ ...manual, deletable: false })),
+                    meta: { ...emptyMeta, total: 2 },
+                },
+            },
+        });
+
+        expect(screen.queryByTestId("manual-remove-1")).toBeNull();
+        expect(screen.queryByTestId("manual-remove-2")).toBeNull();
+    });
+
+    it("削除ボタン → 確認ダイアログ確定で router.delete が対象 URL で発火する (絞り込み無しなら query なし)", async () => {
+        const deleteSpy = vi.spyOn(router, "delete").mockImplementation(() => {});
+        render(Show, { props: baseProps });
+
+        await fireEvent.click(screen.getByTestId("manual-remove-1"));
+        const dialog = screen.getByTestId("remove-manual-dialog");
+        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));
+
+        expect(deleteSpy).toHaveBeenCalledTimes(1);
+        expect(deleteSpy.mock.calls[0][0]).toBe("/projects/1/manuals/1");
+    });
+
+    it("削除 URL に現在の絞り込みと表示中ページが載る (着地先を保つため)", async () => {
+        const deleteSpy = vi.spyOn(router, "delete").mockImplementation(() => {});
+        render(Show, {
+            props: {
+                ...baseProps,
+                manualFilters: {
+                    category: "3",
+                    status: "published",
+                    q: "ネジ",
+                    sort: "title_asc",
+                    mine: true,
+                },
+                manuals: {
+                    data: manualsFixture,
+                    meta: { current_page: 2, last_page: 3, per_page: 10, total: 25 },
+                },
+            },
+        });
+
+        await fireEvent.click(screen.getByTestId("manual-remove-2"));
+        const dialog = screen.getByTestId("remove-manual-dialog");
+        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));
+
+        expect(deleteSpy).toHaveBeenCalledTimes(1);
+        const url = new URL(deleteSpy.mock.calls[0][0] as string, "http://localhost");
+        expect(url.pathname).toBe("/projects/1/manuals/2");
+        expect(Object.fromEntries(url.searchParams.entries())).toEqual({
+            category: "3",
+            status: "published",
+            q: "ネジ",
+            sort: "title_asc",
+            mine: "1",
+            page: "2",
+        });
+    });
+});
+
 describe("Projects/Show メンバー管理", () => {
     afterEach(() => {
         vi.restoreAllMocks();
diff --git a/tests/js/support/InertiaLinkStub.svelte b/tests/js/support/InertiaLinkStub.svelte
index 3312a23..1900ed0 100644
--- a/tests/js/support/InertiaLinkStub.svelte
+++ b/tests/js/support/InertiaLinkStub.svelte
@@ -10,7 +10,12 @@
      * 本スタブを `vi.mock` で注入し、**描画されたら判別できる印**を残すことで
      * SPA 遷移化への退行を確実に赤くする (Codex impl-review R1 [Critical])。
      */
-    let { href, children }: { href?: string; children?: Snippet } = $props();
+    let {
+        href,
+        class: className = "",
+        children,
+    }: { href?: string; class?: string; children?: Snippet } = $props();
 </script>
 
-<a {href} data-testid="inertia-link-stub">{@render children?.()}</a>
+<!-- class は素通しする (呼び出し側が付けた表示契約 = 省略スタイル等をテストから検証できるように) -->
+<a {href} class={className} data-testid="inertia-link-stub">{@render children?.()}</a>

```

# テスト結果

- `composer test`: 5337 tests / 5335 passed / 2 skipped / 22891 assertions (0 failed)
- `composer phpstan`: No errors (level 10)
- `vendor/bin/pint --test`: passed
- `pnpm lint` / `pnpm typecheck`: passed
- `pnpm test`: 140 files / 1571 tests passed
- `pnpm build` / `pnpm typecheck:packages` / `pnpm build:packages`: passed
- `pnpm test:packages`: 10 files / 106 tests passed

# 実装者からの申し送り (設計からの逸脱)

1. **T154 目録への登録 (設計書に記載が無かった)**: `VideoManual::latestSucceededRender()` は
   render_jobs に succeeded 条件つきの直接クエリを書くため、`CurrentRenderArtifactInventoryTest`
   (deny-by-default) の母集団に入る。既存区分は Canonical (1 ファイル固定) と
   SupersessionCriterion (前提: latest(/orderByDesc( を持たず where('id','>'|'<',...) を持つ)
   のどちらにも当てはまらないため、区分 EagerLoadCandidate を新設し、
   **「output_path を 1 度も参照しない」= 受け取れるかの判断を持ち込んでいない**という
   機械検査される前提を付けた (ケース 7 + scanner 自己検証)。
   世代定義の一致は `ManualRowDownloadableParityTest` が behavioral に固定する。
2. **`URLSearchParams` を使わない**: 設計書の manualQueryString() は URLSearchParams を
   使っていたが、ESLint の svelte/prefer-svelte-reactivity が禁止する
   (SvelteURLSearchParams を要求する)。反応性の要らない使い捨ての組み立てに反応クラスを
   持ち込む理由が無いため、encodeURIComponent で組み立てる形にした。
3. **`InertiaLinkStub.svelte` に class の素通しを追加**: DL 導線が通常 anchor であることの
   検証に既存スタブを再利用したが、タイトルの省略スタイル (truncate) を検証するために
   class を素通しする必要があった (既存テストへの影響なし)。
4. **`published()` Factory state** を VideoManualFactory に追加 (設計どおり)。
