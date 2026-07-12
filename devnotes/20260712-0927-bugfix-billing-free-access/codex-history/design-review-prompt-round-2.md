Round 1 の指摘への対応を報告します。詳細設計書 (devnotes/20260712-0927-bugfix-billing-free-access/detailed-design.md) を更新済みです。再レビューをお願いします。

## Critical 1 (expectsJson の取りこぼし) への対応: 反論 (コード変更なし) + テストで趣旨に対応

framework 実装を確認しました。`Illuminate/Http/Concerns/InteractsWithContentTypes.php` L24-27:

```php
public function expectsJson()
{
    return ($this->ajax() && ! $this->pjax() && $this->acceptsAnyContentType()) || $this->wantsJson();
}
```

`expectsJson()` は `wantsJson()` を包含します。「Accept: application/json だが XHR ではない」リクエストは `wantsJson()` 経由で必ず true になるため、`expectsJson() || wantsJson()` への変更は恒真的に無意味です。また同 predicate は Laravel 例外ハンドラの shouldReturnJson と同一で、abort 時の JSON/HTML レンダリング判定と自然に一致します (変更するとむしろ乖離リスク)。

ご懸念のケースは regression テストで固定します (設計書 施策 5 に追加済み):
「Accept: application/json・非 XHR (X-Requested-With なし) → 402 + body `message` 固定」。Pest の `getJson()` は Accept ヘッダのみ付与し X-Requested-With を付けないため、まさにこのケースを踏みます。設計書 施策 2 に「JSON 判定の根拠」節としてソース引用付きで明文化しました。

## Critical 2 (テストヘルパ既定変更の blast radius) への対応: 全数調査で「影響なし」を実測に置き換え。段階移行は不採用

指摘は正当でした。`subscribed:` 検索だけでは暗黙依存を検出できないため、検出条件を拡張して全数調査を実施し、結果を設計書 施策 5「影響呼び出し側の全数調査」表に反映しました:

- アプリコードで `subscription()` を読むのは `BillingAccess` のみ (`grep -rn "subscription(" app/` 実測) → ヘルパの暗黙 subscription 行削除がアプリ挙動に波及する経路は BillingAccess だけで、plan_code null では参照すらされない
- テスト側の暗黙依存は `grep -rn "subscriptions()|->subscription(|createFakeSubscription|subscribed:" tests/` の和集合で**実測 5 ファイル**:

| ファイル | 依存の形 | 対応 |
|---------|---------|------|
| RequireActiveSubscriptionMiddlewareTest.php | subscribed: false | 本施策で全面書き換え |
| DashboardTest.php | subscribed: false | 引数除去 + contractPaidPlan 使用 |
| Billing/TicketCheckoutTest.php | subscribed: false | 引数除去 (未契約=既定になるだけ。検証意図不変) |
| Billing/ReconcileSubscriptionSchedulesTest.php | 生成直後の `$organization->subscriptions()->sole()` (8 箇所) | 各生成直後に `createFakeSubscription($organization)` を明示追加 |
| Billing/SendBillingRemindersTest.php | 同上 (2 箇所) | 同上 |

残り 86 ファイル (呼び出し総数 91 ファイル) は subscription 行を参照しません。検証は (1) grep 3 種の再実行、(2) `composer test` 全 green の二段で機械的に確認します。

互換ヘルパ並走 (段階移行) を採らない理由: AGENTS.md 思考原則 3「後方互換の並走を残さない (書き換えると決めたら同じ PR で旧実装を消す)」に反し、新判定下で no-op となる「subscribed: true = 契約済み」という誤った意味論をテスト基盤に残すためです。blast radius が上記のとおり有限・機械的に列挙できたため、同一 PR での一括移行が安全に完了できます。ReconcileSubscriptionSchedulesTest 等への `createFakeSubscription` 明示追加は「subscription ライフサイクルのテストが自分の subscription を自分で作る」形になり、テストデータの明示化という点でも改善です。

## Warning 1 (402 body 形式) への対応: 対応済み

`abort(402, msg)` は HttpException 経由で Laravel 標準ハンドラが JSON 要求に `{"message": "..."}` を返します (仕様固定の framework 挙動。`response()->json()` 直書きではないため禁止事項 4 非抵触)。施策 5 の JSON テストに `assertJsonPath('message', BLOCKED_MESSAGE)` を追加し、施策 2 に「402 応答 body の形式」節を追記しました。

## Warning 2 (payload 契約変更の破壊性) への対応: 対応済み

受け入れ条件に「実装完了時にリポジトリ全体 (app / resources/js / tests) で `rg -n "has_active_subscription|hasActiveSubscription"` が 0 件」を明記。現時点の grep 実測では参照は BillingSummaryData / DashboardService / dashboard.ts / Dashboard.svelte / 両テストのみで、他ページからの参照はありません。契約変更の記録は本設計書 (devnotes) が担います。

## Suggestion への対応

- `hasActiveAccess` → `hasBillingAccess` のメソッド名変更: 見送り (テンプレートの差し替え seam 名を維持)。スコープ外節に「将来 別 TODO で middleware / DTO と合わせて実施」と明記しました。
- free プランが Stripe Price を持たない前提のテスト固定: 対応済み。施策 5 に「free プラン (fallback_plan) は plan_prices を持たない」固定テストを追加しました (syncPlanCode が price.id → Plan 解決でのみ plan_code を set するため、この前提が保たれる限り plan_code に支払い不要プランが載る経路がないことを固定)。

---

上記を反映した最新の詳細設計書は devnotes/20260712-0927-bugfix-billing-free-access/detailed-design.md です (読み直し可)。全体判定をお願いします。
