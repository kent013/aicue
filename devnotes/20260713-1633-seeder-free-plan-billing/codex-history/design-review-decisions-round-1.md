# 対応マトリクス: design-review Round 1

全体判定: **CHANGES_REQUESTED**。最小修正 1 点 (subscription 冪等化) + Warning を反映した。

## [Critical] attachFakeActiveSubscription() をメソッド単体で冪等化 (施策1)
- 判断: 対応する
- 根拠: run() の冪等 guard に依存せず、部分実行・手動呼び出し・将来の guard 変更でも重複行を生まない
  設計が安全。Codex 指摘どおり。
- 対応内容: メソッド冒頭で `$organization->subscription('default') !== null` なら早期 return。
  未存在時のみ `subscriptions()->create([...])`。docblock に「メソッド単体で冪等」を明記。

## [Warning] 有償プランの current base Price 前提を明文化 (施策1)
- 判断: 対応する (文書化 + テストで固定。PlanSeeder への hard fail 追加は見送り)
- 根拠: 「有償プランは必ず current base Price を持つ」は seed データ不変条件。PlanSeeder が保証し、
  施策2 が standard の plan_code + active subscription を検証することで drift を検知できる。
  seeder への hard fail 追加は Critical 修正の責務を超え over-engineering。
- 対応内容: 施策1 リスク節に seed 不変条件として明文化。施策2 が前提固定テストを担う旨を追記。

## [Warning] ManualTestSeederTest の isPaid 変数化 (施策2)
- 判断: 対応する
- 根拠: currentPrice の多重クエリを避け、分岐と期待値を一元化して可読性向上。
- 対応内容: ループ先頭で `$isPaid = $plan->currentPrice(PlanPriceKind::Base) !== null` を算出。
  hasActiveAccess の検証を両 tier 共通の 1 行に集約。free 側の subscription null 検証を明示。

## [Warning] 回帰テストで redirect 非発生を assertOk だけに頼らない (施策3)
- 判断: 対応する
- 根拠: 「200 だが別画面」ケースを取りこぼさないため Inertia コンポーネント名を検証。
- 対応内容: `assertInertia(fn ($page) => $page->component('Projects/Index'))` を追加。
  実際の component 名は ProjectController@index の `Inertia::render('Projects/Index', ...)` で確認済み。

## [Suggestion] stripe_id 命名を helper と揃える (施策1)
- 判断: 見送り (実害なし)
- 根拠: seeder 由来と分かる `sub_seed_` prefix は識別性が高く保守的にむしろ良い。テスト helper の
  `sub_test_` と区別できる方が望ましい。

## [Suggestion] free 側 subscription 不在の明示検証 (施策2)
- 判断: 対応する (既に設計に含む)
- 対応内容: 施策2 で free 側 `subscription('default')` が null であることを明示検証済み。

## [Suggestion] laratrust_team_id 明示のロール付与確認 (施策3)
- 判断: 対応する (確認事項として記録)
- 根拠: ManualTestSeeder の addToOrganization / provision は既に laratrust_team_id 明示でロール付与する
  (既存 ManualTestSeederTest が organizationRole を検証済み)。回帰テストは既存 seeder 挙動に乗るため追加担保不要。
