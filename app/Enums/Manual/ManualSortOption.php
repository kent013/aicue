<?php

declare(strict_types=1);

namespace App\Enums\Manual;

/**
 * 動画マニュアル一覧の並べ替え allowlist (PC 一覧・doc/04 §4.2)。
 * 全 sort に id の安定 tie-breaker を付ける (同値行でページ間の重複/欠落を防ぐ)。
 * 既定 (null) は defaultOrderings() を適用する (created_at desc, id desc)。
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
     * 既定順 (sort 未指定 / allowlist 外)。現行踏襲 (created_at desc, id desc)。
     *
     * @return non-empty-list<ManualOrdering>
     */
    public static function defaultOrderings(): array
    {
        return [['column' => 'created_at', 'direction' => 'desc'], ['column' => 'id', 'direction' => 'desc']];
    }
}
