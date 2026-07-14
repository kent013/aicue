## 詳細設計レビュー Round 2

Round 1 の指摘への対応を反映しました。判定を更新してください。

### Round 1 指摘への対応

| # | 施策 | 指摘 | 分類 | 対応 |
|---|------|------|------|------|
| 1 | B | PWA `q` が addcslashes 無しで PC と不一致 | Critical | **対応**。B-1 で `addcslashes($search, '%_\\')` を適用し PC と統一(既存挙動不一致の是正) |
| 2 | A | manualFilters が二重管理でズレやすい | Warning | **対応**。`toManualFilterProps(array $filters): array{...}` を新設し sort の `?->value` 変換を単一点化。show() は `'manualFilters' => $this->toManualFilterProps($filters)` |
| 3 | A | orderings() の column: string が弱い | Warning | **対応**。enum に `@phpstan-type ManualOrderColumn 'created_at'\|'updated_at'\|'title'\|'id'` と `ManualOrdering` を定義、`orderings()`/`defaultOrderings()` は `non-empty-list<ManualOrdering>`。ProjectController は `@phpstan-import-type ManualOrdering from ManualSortOption` |
| 4 | B | mine の user が nullable のまま when へ | Warning | **対応**。`Assert::isInstanceOf($user, User::class); $userId = $user->id;` で早期確定してから `when($mine, ...)` |
| 5 | F | A/B の Feature 計画に creator null が残る | Warning | **対応**。A/B の Feature 項目から creator null を除去し、null 分岐は vitest 一本化と明記(FK RESTRICT で Feature 作成不可) |
| 6 | F | sort 順序は期待 ID 配列の完全一致まで固定 | Warning | **対応**。各 sort ケースで `assertSame([id...], array_column($data,'id'))`。tie-breaker は page=1/page=2 の id 集合が排他かつ全件被覆で検証 |
| 7 | A | title ソートの collation 差異 | Suggestion | **一部採用**。enum docblock に「順序は DB collation に従う。title_sort_key は将来施策」を明記 |
| 8 | A/D | sort 変更で page リセットを JS テスト | Suggestion | **採用**。vitest に「sort/mine 変更時 page 非引継ぎ」を追加 |
| 9 | B | DTO docblock に creator 表示専用を追記 | Suggestion | **採用**。CaptureManualSummaryData の docblock に「creator は表示目的のみ・検索対象外(User.name は whereBlind の対象にしない)」 |
| 10 | C | PHP enum ↔ TS union 同期テスト | Suggestion | **見送り(将来施策)**。out-of-scope。残課題に記載 |
| 11 | D | q 維持の JS テスト | Suggestion | **採用**。vitest に「q 入力中 sort/mine 操作で trim 済み q 維持」を追加 |
| 12 | E | モバイル truncate 追従 | Suggestion | **採用(実装時確認)**。既存カード caption truncate 規約に追従 |

### 反映後の主要変更点(抜粋)

**enum ManualSortOption** — `@phpstan-type` 追加:
```php
/**
 * ... 順序は DB collation に従う (title_sort_key は将来施策) ...
 * @phpstan-type ManualOrderColumn 'created_at'|'updated_at'|'title'|'id'
 * @phpstan-type ManualOrdering array{column: ManualOrderColumn, direction: 'asc'|'desc'}
 */
enum ManualSortOption: string { /* cases */
    /** @return non-empty-list<ManualOrdering> */
    public function orderings(): array { /* match */ }
    /** @return non-empty-list<ManualOrdering> */
    public static function defaultOrderings(): array { /* created_at desc, id desc */ }
}
```

**toManualFilterProps** (単一変換点):
```php
/**
 * @param  array{category: string|null, status: string|null, q: string|null, sort: ManualSortOption|null, mine: bool}  $filters
 * @return array{category: string|null, status: string|null, q: string|null, sort: string|null, mine: bool}
 */
private function toManualFilterProps(array $filters): array
{
    return ['category' => $filters['category'], 'status' => $filters['status'], 'q' => $filters['q'],
        'sort' => $filters['sort']?->value, 'mine' => $filters['mine']];
}
```

**CaptureManualController::index** (user 早期確定 + q エスケープ統一):
```php
$user = $request->user();
Assert::isInstanceOf($user, User::class);
$userId = $user->id;
$mine = $request->boolean('mine');
// ...
->when($search !== null, fn (Builder $q) => $q->where('title', 'like', '%'.addcslashes($search, '%_\\').'%'))
->when($mine, fn (Builder $q) => $q->where('created_by', $userId))
->with(['category', 'creator'])
// 'filters' => ['category' => $categoryId, 'q' => $search, 'mine' => $mine]
```

これらで承認可能か判定してください。残る out-of-scope は「原稿(SOP)検索・作成者名検索・サムネイル・PWA sort・PHP↔TS enum 同期テスト」です。
