<?php

declare(strict_types=1);

namespace App\DataTransferObjects\Manual;

use App\Enums\Manual\ManualProgress;
use App\Enums\Manual\ManualSortOption;
use App\Services\Manual\ManualKeywordSearch;
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
 * - `progress`: ManualProgress の値のみ (not_started / in_progress / completed)。それ以外は null。
 *   **旧 `?status=` (制作状態 5 値) は受け付けない**。値域が変わった時点で意味を保てないため、
 *   互換の受理経路を残さない (思考原則 3)。旧 URL は未知キーとして無視され「すべて」になる
 *   (allowlist 外は絞り込み無し = より広く当たる方向へ倒す、という本 VO の既定方針と一致)
 * - `keyword`: 検索語。正規化 (前後の空白を除く / 先頭 ManualKeywordSearch::MAX_LENGTH 文字)
 *   の正本は ManualKeywordSearch::normalize であり、撮影 PWA 一覧も同じ関数を通る。
 *   空白のみ・空文字は null (= 絞り込み無し)。**上限は負荷制御のためであり、
 *   超えた分は検索に寄与しない** (打った語と違う条件で検索されることになる)。
 *   かつて書かれていた「title の max:200 なので 201 文字目以降は寄与しない」という根拠は
 *   カット本文 (narration / subtitle_secondary は max:2000) を対象に含めた時点で成立しない
 * - `sort`: ManualSortOption の allowlist のみ (ユーザー入力をカラム名に渡さない)
 * - `mine`: 自分の作成分のみ
 * - `page`: 1 以上 maxPage() 以下。数字以外は 1、上限超過は maxPage()
 *   (「最後の方を見たい」意図に近い側へ倒す。着地は一覧側の丸めで最終ページになる)
 */
final readonly class ManualListQuery
{
    // 検索語の最大長は ManualKeywordSearch::MAX_LENGTH へ移した。
    // 「検索語とは何か」の定義を 1 箇所に持たせるため (撮影 PWA も同じ定義を使う)。

    /** 1 ページあたり件数 (現行踏襲)。**一覧の perPage はここだけが正本** */
    public const int PER_PAGE = 10;

    public function __construct(
        public ?string $category,
        public ?ManualProgress $progress,
        public ?string $keyword,
        public ?ManualSortOption $sort,
        public bool $mine,
        public int $page,
    ) {}

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
     * (`const int MAX_PAGE = intdiv(PHP_INT_MAX, self::PER_PAGE);` はコンパイルエラー)。
     */
    public static function maxPage(): int
    {
        return intdiv(PHP_INT_MAX, self::PER_PAGE);
    }

    public static function fromRequest(Request $request): self
    {
        $category = $request->query('category');
        $category = is_string($category) && $category !== '' ? $category : null;
        if ($category !== null && $category !== 'uncategorized') {
            // 数値 id 以外は破棄。数値は**正規形へ畳む** ('0003' → '3')。
            // 破棄にしないのは絞り込みが消えて全件が出る方向に倒れるためで、正規化なら
            // 同じ結果集合のまま「フィルタ select の選択値」「着地先 URL」と一致する。
            // 桁溢れは (int) が PHP_INT_MAX へ飽和して該当なしになる (URL も有界に保たれる)。
            $category = ctype_digit($category) ? (string) (int) $category : null;
        }

        // allowlist 外は null (= 既定「すべて」)。旧 `?status=` (5 値) は未知キーとして無視される
        $progressRaw = $request->query('progress');
        $progress = is_string($progressRaw) ? ManualProgress::tryFrom($progressRaw) : null;

        $rawKeyword = $request->query('q');
        // 正規化 (trim + 先頭 MAX_LENGTH 文字) の正本は ManualKeywordSearch。
        // 撮影 PWA 一覧と**同じ関数**を通す (面ごとに検索語の定義が違う状態を作らない)
        $keyword = ManualKeywordSearch::normalize(is_string($rawKeyword) ? $rawKeyword : null);

        $sortRaw = $request->query('sort');
        // allowlist 外は null (= 既定順)。ユーザー入力をカラム名に渡さない
        $sort = is_string($sortRaw) ? ManualSortOption::tryFrom($sortRaw) : null;

        // (int) は PHP_INT_MAX へ飽和するため、上限で丸めてから使う
        // (offset 計算 ($page - 1) * PER_PAGE を int 範囲に収める)
        $pageRaw = $request->query('page');
        $page = is_string($pageRaw) && ctype_digit($pageRaw)
            ? min(max(1, (int) $pageRaw), self::maxPage())
            : 1;

        return new self(
            category: $category,
            progress: $progress,
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
     * @return array{category: string|null, progress: string|null, q: string|null, sort: string|null, mine: bool}
     */
    public function toProps(): array
    {
        return [
            'category' => $this->category,
            'progress' => $this->progress?->value, // string|null (TS の ManualFilters.progress と一致)
            'q' => $this->keyword,
            'sort' => $this->sort?->value, // string|null (TS の ManualFilters.sort と一致)
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
        if ($this->progress !== null) {
            $params['progress'] = $this->progress->value;
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
