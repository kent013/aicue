以下、**提示された詳細設計書のみ**を対象にしたレビュー結果です（実コード未確認のため、最終承認は実装差分確認が前提）。

## 施策別判定

- 施策1 Enum 定義: **APPROVE**
- 施策2 マイグレーション: **APPROVE**
- 施策3 保護キー追記: **APPROVE**
- 施策4 Model: **REQUEST_CHANGES**
- 施策5 Factory + docs: **APPROVE**
- 施策6 FormRequest: **REQUEST_CHANGES**
- 施策7 Service: **REQUEST_CHANGES**
- 施策8 Policy: **APPROVE**
- 施策9 Controller + 権限 rename: **APPROVE**
- 施策10 route + IDOR inventory: **REQUEST_CHANGES**
- 施策11 Inertia props + Svelte画面: **REQUEST_CHANGES**
- 施策12 Feature + Vitest: **REQUEST_CHANGES**

---

## 指摘事項

- [Critical] **`cuts.parent_cut_id` の FK が想定動作しない可能性**  
  同一 migration 内で `constrained('cuts')->nullOnDelete()` を使う場合、`cuts` 作成時点で自己参照 FK を張るDDLはDB依存で失敗/不安定になり得ます。  
  **修正案**: `parent_cut_id` も `adopted_take_id` と同様に「後付け FK migration」に分離し、`cuts` 作成時は nullable bigint のみ定義する。

- [Critical] **`VideoManual` の relation 名が設計内で不整合**（`videoManuals()` と `manuals()` が混在）  
  `scopeBindings` の子解決で relation 推論とズレると、IDOR防御が崩れる/404にならない恐れがあります。  
  **修正案**: `Project` 側 relation を `manuals()` に統一し、Controller/Service/Query/TS すべてで同名に統一。既存呼び出しは一括置換。

- [Critical] **`Store/UpdateVideoManualRequest` の category 入力名と保護キー検知の境界が曖昧**  
  入力が `category`、保護キーは `category_id` だと、実装者が誤って `validated()['category_id']` を参照した際のバグを誘発。  
  **修正案**: API入力名を `category_id` に寄せず、現行の `category` を維持するなら Service 引数も `?int $category` で統一し、DTO化/専用 accessor で取り違えを防止。テストに「`category_id`送信で422」「`category`送信で成功」を明記。

- [Warning] **Service の `firstOrFail()` 例外が 404 になる前提が曖昧**  
  Validation済みでも競合削除で `ModelNotFoundException` が起こると、ハンドラ次第で500化リスク。  
  **修正案**: Service内で `ModelNotFoundException` を捕捉し `ValidationException`（422）か `NotFoundHttpException`（404）へ明示変換、方針を統一。

- [Warning] **Category reorder の N+1 update**  
  1件ずつ update は件数増で遅く、ロック保持時間が伸びます。  
  **修正案**: CASE式一括更新または `upsert` を使用し、1クエリ化してトランザクション時間短縮。

- [Warning] **`Category` の `sort_order` を fillable外にする方針は妥当だが、Factory説明が誤解を招く**  
  「factory create は guarded を尊重しない」の表現はLaravel挙動説明として不正確。  
  **修正案**: 「Factoryは内部的に属性注入可能で、アプリ入力経路の mass-assignment 制約とは別境界」と明記。

- [Warning] **Inertia typed array で `status: string` は弱い**  
  TS 側で enum literal union にしないとUI分岐漏れを検出できません。  
  **修正案**: `type VideoManualStatus = 'draft' | 'analyzing' | ...` を `resources/js/types/manual.ts` に定義し、propsで使用。

- [Warning] **`manuals` paginate meta の型定義が省略され過ぎ**  
  typed array規約では meta shape も固定が望ましい。  
  **修正案**: PHPDoc を `array{data:list<...>,meta:array{current_page:int,last_page:int,per_page:int,total:int}}` まで具体化。

- [Warning] **テスト計画に Architecture テスト追加観点が不足**  
  「IDOR inventory 追記」以外に、保護キー・親子404・cross-orgを既存Architecture群で守る観点の追記が必要。  
  **修正案**: 既存 Architecture テスト名単位で「今回の新規route/modelが検査対象に入ること」を明記。

- [Suggestion] `VideoManualController@store` の成功遷移を show に飛ばす方針は良い。`with('status',...)` も統一すると UX 一貫性が上がる。
- [Suggestion] `reorder` のエラーメッセージは i18n キー化して将来の多言語対応に備える。
- [Suggestion] Tier B を schema+model+factory のみに留める意図を `docs/template-divergence.md` に先に記述するとレビューが通しやすい。

---

## 観点別総評

- 正確性: 概ね良好だが、**自己参照FK** と **relation命名不整合** は重大。
- 既存整合: Item パターン踏襲は良い。Inertia typed array への意識も適切。
- PHPStan lv10: generics・PHPDoc方針は良いが、例外経路の型/挙動定義を明確化したい。
- テスト網羅: 充実。並行制御の「近似検証」方針は現実的。
- セキュリティ: 保護キー/existsスコープ/再解決の二重防御は良い。IDORは命名整合が前提。
- 並行制御: Project行ロック戦略は妥当。reorderの更新方式は改善余地。
- UI/Design: disabled禁止を守る方針は適切。

---

## 全体判定

**CHANGES_REQUESTED**

上記 Critical（特に **cuts自己参照FKの分離**、**manuals relation命名統一**、**category入力境界の明確化**）を解消できれば、再レビューで APPROVED 相当です。