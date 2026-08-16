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

    public function __construct(
        public ?string $category,
        public ?string $status,
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

        $status = $request->query('status');
        $status = is_string($status) && VideoManualStatus::tryFrom($status) !== null ? $status : null;

        $keyword = $request->query('q');
        $keyword = is_string($keyword) && trim($keyword) !== ''
            ? mb_substr(trim($keyword), 0, self::MAX_KEYWORD_LENGTH)
            : null;

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
