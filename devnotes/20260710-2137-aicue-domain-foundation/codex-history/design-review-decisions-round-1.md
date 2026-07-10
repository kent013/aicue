# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED（Round 1）→ 対応の上 Round 2 へ。

## [Critical] cuts.parent_cut_id 自己参照 FK が同一 migration 内で不安定
- 判断: 対応する
- 対応内容: parent_cut_id を adopted_take_id と同様に「nullable bigint（FK なし）」で cuts 作成 → 後付け migration で `foreign('parent_cut_id')->on('cuts')->nullOnDelete()` と adopted_take_id FK をまとめて張る。

## [Critical] relation 名不整合（videoManuals() と manuals() 混在）
- 判断: 対応する
- 対応内容: Project/Category 双方の VideoManual への relation を `manuals()` に統一。設計内・Controller・Service・Query・TS すべて `manuals`。scopeBindings 推論 `$project->manuals()` と route パラメータ `{manual}` を一致させ IDOR を確実化。

## [Critical] category 入力名と保護キー検知の境界が曖昧
- 判断: 対応する
- 対応内容: 入力名は `category`（?int）維持。Controller は `validated('category')` のみ参照（`validated('category_id')` 禁止）。Service 引数 `?int $categoryId`。テストで「category_id 送信→422」「category 送信→成功」を両方固定。施策6 に「入力名の境界」節を追加。

## [Warning] firstOrFail の 404 変換が曖昧（500 化リスク）
- 判断: 対応する（一部は既定挙動の明示）
- 根拠: Laravel 既定ハンドラは ModelNotFoundException を 404 に変換するため 500 化しない。
- 対応内容: 施策7 に「例外方針」節。404（firstOrFail 既定）/ 422（ValidationException）の使い分けを明記。

## [Warning] reorder の N+1 update
- 判断: 対応する
- 対応内容: 1 件ずつ update → CASE 式一括更新（1 クエリ）に変更。id は Request で integer 検証済み + 実装で (int) 明示のため raw 連結でも安全。

## [Warning] Factory の guarded 説明が不正確
- 判断: 対応する
- 対応内容: 「Factory は内部的に属性注入可能で、HTTP 入力経路の mass-assignment 制約とは別境界」に文言修正。

## [Warning] TS status: string が弱い
- 判断: 対応する
- 対応内容: `type VideoManualStatus = 'draft'|...` literal union を manual.ts に定義し props で使用。PHP enum と値集合一致を保つ（乖離検知は手動、将来生成を検討）。

## [Warning] paginate meta shape が省略され過ぎ
- 判断: 対応する
- 対応内容: PHPDoc を `meta: array{current_page,last_page,per_page,total}` まで固定。private manualRows の @return を具体化。

## [Warning] Architecture テスト追加観点が不足
- 判断: 対応する
- 対応内容: 施策12 に既存 Architecture テスト（MassAssignmentSafetyTest / NestedRouteIdorDefenseTest / ProhibitsProtectedKeys / ds-purity / atomic-import-graph）のカバレッジ確認を明記。

## [Suggestion] store 成功遷移の with 統一 / reorder i18n / template-divergence 先行記述
- 判断: 採用
- 対応内容: store は `->with('success', ...)` 統一・intended 不使用を明記。reorder メッセージを `__('categories.reorder_mismatch')` に。template-divergence の先行記述節を追加。
