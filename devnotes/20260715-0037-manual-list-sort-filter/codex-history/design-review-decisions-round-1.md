# 詳細設計レビュー Round 1 対応マトリクス (gpt-5.3-codex)

全体判定: CHANGES_REQUESTED

| # | 施策 | 指摘 | 分類 | 対応 | 根拠 |
|---|------|------|------|------|------|
| 1 | B | PWA `q` が addcslashes 無しの `like '%'.$search.'%'` で PC と不一致。%/_/\ でマッチ意味が変わる | Critical | **対応** | B-1 で PC と同じ `addcslashes($search, '%_\\')` を適用し挙動統一 (既存バグの是正も兼ねる) |
| 2 | A | manualFilters の内部型と Inertia 返却型が二重管理でズレやすい | Warning | **対応** | `toManualFilterProps(array $filters): array{...}` を 1 メソッド化し sort の `?->value` 変換を単一点化 (A-4 差し替え) |
| 3 | A | orderings() の column: string が PHPStan 的に弱い | Warning | **対応** | `@phpstan-type ManualOrderColumn = 'created_at'\|'updated_at'\|'title'\|'id'` を定義し column をこの union に |
| 4 | B | mine の user 取得を nullable のまま when へ渡すと型が揺れる | Warning | **対応** | `Assert::isInstanceOf($user, User::class); $userId = $user->id;` で早期確定 |
| 5 | F | A/B の Feature テスト計画に「creator null Feature」が残り実装者が迷う | Warning | **対応** | A/B の Feature 項目から creator null を外し vitest 一本化を明記 (FK RESTRICT で Feature では作れない) |
| 6 | F | sort 順序検証は期待 ID 配列の完全一致まで固定すべき | Warning | **対応** | 各 sort ケースで `assertSame([id...], array_column(data,'id'))` を明示 |
| 7 | A | title ソートの DB collation 差異で UI 期待とズレる可能性 | Suggestion | **一部採用** | 「順序は DB collation に従う」を仕様に明記。title_sort_key 導入は将来施策 |
| 8 | A/D | page=2 遷移後 sort 変更で page リセットを JS テストで固定 | Suggestion | **採用** | vitest に sort 変更時 page 非引継ぎ (manualQuery が page を載せない) を追加 |
| 9 | B | DTO docblock に「creator は表示目的のみ (検索対象外)」を追記 | Suggestion | **採用** | PII 方針の保守性向上 |
| 10 | C | PHP enum 値と TS union の同期漏れ検知 (ManualEnumTsSyncInvariantTest 拡張) | Suggestion | **見送り (将来施策)** | out-of-scope。残課題に記載 |
| 11 | D | q 入力中に mine/sort 操作時の query 合成 (trim 済み q 維持) を JS テスト | Suggestion | **採用** | vitest に 1 本追加 |
| 12 | E | モバイルのメタ情報 truncate 追従確認 | Suggestion | **採用 (実装時確認)** | 既存カード caption の truncate 規約に追従 |

## 反映方針
- Critical 1 (PWA q エスケープ) を最優先で B-1 に反映。
- Warning 2-6 を設計に反映。Suggestion 7-9,11,12 も低コストのため採用。10 のみ将来施策。
