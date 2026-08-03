# 対応マトリクス: impl-review Round 1 (APPROVED)

全体判定: **APPROVED**。Critical / Warning はゼロ。Suggestion 2 件について判断を記録する。

Codex 評: 「施策 A〜E がすべて設計に一致。非交渉事項 (`organizations.plan_code` の writer 一本化 /
二重 proration 防止の二層冪等 / SDK 例外の非露出 / CTA disabled 禁止) を保持している」。
実装時の逸脱 4 件 (final 解除 + seam、Cashier `valid()` 意味論、enterprise テスト、
`normalizeItems` の id 解決) はいずれも「技術的に妥当」と判定された。

## [Suggestion] `ChangePlanRequest::rules()` の `plan_code` が `exists` のみで `is_active` は Controller の `firstOrFail` 依存 (404 になりうる)

- 判断: **見送る**
- 根拠:
  1. **既存 `billing.checkout` と完全に同型**。`BillingCheckoutRequest` も
     `['required', 'string', 'exists:plans,code']` で、`is_active` は Controller の
     `Plan::query()->where('code', ...)->where('is_active', true)->firstOrFail()` が担う。
     ここだけ非対称にすると「同じ画面の 2 本の CTA で未公開プランの応答コードが違う」
     という新しい差異を作る (思考原則 3 / 4)。
  2. 到達可能性が実質ゼロ。`/billing/plans` が描画するのは
     `PricingService::listPublicPlans()` (= `is_active=true` のみ) であり、
     `is_active=false` の code を送るには手組みリクエストが要る。
  3. UX 上の詰みも作らない (404 ページに落ちるだけで、CTA は次の render で正しく出る)。
- 対応内容: 変更なし。非対称化が必要になるなら `billing.checkout` と同時に行う。

## [Suggestion] `enterprise` の 422 テストが無い

- 判断: **見送る** (Codex も「現 Seeder 制約の説明が成立しているため現状許容」)
- 根拠: `PlanSeeder` が投入するのは personal / starter / standard のみで、
  Plan / PlanPrice の真実源は PlanSeeder (Plan の Factory は存在しない)。
  テストのためだけに Plan 行を手組みするのは「テストデータは Factory / 既存ヘルパで生成」の
  規約に反する。段 0 の順序 (決済対象外プランは `assertStripeBillablePlan` が先に 422 へ倒す)
  という**回帰防止の意図は `personal` のテストで満たされている**
  (`personal` は base Price を持たないため、順序を逆にすると `InvalidArgumentException` になる)。
- 対応内容: 変更なし。`enterprise` が seed されるようになった時点でテストを追加する。
