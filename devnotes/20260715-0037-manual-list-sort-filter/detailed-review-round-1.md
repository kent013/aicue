全体として、設計の方向性はかなり良いです。  
特に `ManualSortOption` による allowlist 化・`$project->manuals()` 起点固定・`mine` を auth user id 起点にしている点は、AGENTS.md の不変条件に整合しています。  
ただし、いくつか **Warning/Critical 相当の詰め不足** があるため、判定は **CHANGES_REQUESTED** です。

**全体判定**  
- **CHANGES_REQUESTED**

---

**施策A: sort allowlist enum + PC一覧クエリ拡張**  
- **REQUEST_CHANGES**

- [Warning] `manualFilters` の内部型と Inertia 返却型が二重管理になり、将来ズレやすい。  
  修正案: `ProjectController` 内で `toManualFilterProps(array $filters): array{...}` を1メソッド化し、`sort` の `?->value` 変換を単一点化。

- [Warning] `orderings()` の `column: string` は PHPStan 的に弱い（将来 enum 追加時の誤カラム混入を静的に検出しにくい）。  
  修正案: `@phpstan-type ManualOrderColumn = 'created_at'|'updated_at'|'title'|'id'` を定義し、`column` をその union にする。

- [Suggestion] `title` ソート時の DB collation 差異（大文字小文字・日本語順）で UI 期待とズレる可能性。  
  提案: 仕様に「DB collation に従う」を明記し、必要なら将来 `title_sort_key` 導入を別施策化。

- [Suggestion] tie-breaker のページ境界テストは非常に良い。加えて「page=2 に遷移後 sort 変更で page リセット」も JS テストで固定すると UX 回帰に強い。

---

**施策B: PWA一覧クエリ拡張 + summary DTO**  
- **REQUEST_CHANGES**

- [Critical] `q` 検索が現行コードでは `addcslashes` なしの `like '%'.$search.'%'`。PC 側方針と不一致で、`%/_/\` を含む入力で検索意味が変わる。  
  修正案: PC と同様に `addcslashes($search, '%_\\')` を適用し、挙動を統一する（セキュリティというより仕様一貫性・予期せぬマッチ防止）。

- [Warning] `mine` の user 取得で「認可済みだから non-null」の前提は妥当だが、実装時に nullable のまま `when` へ渡すと型が揺れる。  
  修正案: `Assert::isInstanceOf($user, User::class); $userId = $user->id;` で早期確定し、`mine` 条件に素直に利用。

- [Suggestion] `with(['category','creator'])` 追加は妥当。DTO の docblock に「creator は表示目的のみ（検索対象外）」を一文追加すると PII 方針が保守しやすい。

---

**施策C: TS型の波及更新**  
- **APPROVE**

- [Suggestion] PHP enum 値と TS union の同期漏れ検知は将来リスク。  
  提案: 今回は out-of-scope で可だが、次施策で `ManualEnumTsSyncInvariantTest` 拡張を TODO 化推奨。

---

**施策D: PC UI (Show.svelte)**  
- **APPROVE**

- [Suggestion] `mine` は checkbox 即時適用で良い。`q` 入力中に mine/sort 操作した際の query 合成（trim 済み q 維持）を JS テストで1本足すと安心。

- [Suggestion] 「不明」表示は妥当。文言は i18n 方針があるなら定数化を検討（現時点では必須ではない）。

---

**施策E: PWA UI (Capture/Index.svelte)**  
- **APPROVE**

- [Suggestion] モバイルでメタ情報が長くなるため、省略表示ルール（1行truncate等）が既存デザイン規約にあるなら追従確認を推奨。

---

**施策F: テスト計画**  
- **REQUEST_CHANGES**

- [Warning] Feature テストで creator null を直接作れない整理は正しいが、Aのテスト計画に「creator null Feature」を残すと実装者が迷う。  
  修正案: A/Bの Feature 項目から null ケースを外し、Vitest 側に一本化する旨を明記して整合。

- [Warning] sort の順序検証は「期待ID配列の完全一致」まで固定しないと、部分一致で取りこぼしが出る。  
  修正案: 各 sort ケースで `assertSame([id...], array_column(...))` を明示。

- [Suggestion] `mine` と他フィルタの AND 条件（category/status/q/sort 併用）を1ケースずつでなく代表1ケースで統合検証すると、コストを増やさず結合回帰を拾える。

---

**特に依頼された論点への回答**

- `ManualSortOption` + `orderings()` による SQLi 防止設計: **妥当**。ユーザー入力をカラム名に使わないため有効。  
  ただし型を literal-union 化するとさらに堅い（上記 Warning）。

- `mine` が `created_by` を payload から受けず auth user id を使う点: **妥当（tenantキー不信に適合）**。

- 作成者名を表示のみ・検索しない方針、creator null 防御的契約: **妥当**。  
  FK RESTRICT 前提の運用契約と「型は nullable維持」の両立も合理的。

- `$project->manuals()` 起点固定で cross-org 非漏洩: **妥当**。  
  既存の org→project 整合 404 ガードとの組み合わせで防御層が成立。

必要なら次に、上記指摘を反映した「修正版テスト項目一覧（そのまま実装タスクに貼れる粒度）」を作成します。