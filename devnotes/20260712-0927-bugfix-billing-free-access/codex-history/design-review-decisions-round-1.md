# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED (Critical 2 / Warning 2 / Suggestion 2)

## [Critical] `expectsJson()` 判定だけでは API クライアント種別を取りこぼす可能性
- 判断: 反論する (コード変更なし) + テストで趣旨に対応する
- 根拠: framework 実装 (`Illuminate/Http/Concerns/InteractsWithContentTypes.php` L24-27) で
  `expectsJson()` は `($this->ajax() && ! $this->pjax() && $this->acceptsAnyContentType()) || $this->wantsJson()`
  と定義され、**`wantsJson()` を包含する**。「Accept: application/json だが XHR ではない」
  リクエストは `wantsJson()` 経由で必ず true になるため、`expectsJson() || wantsJson()` は恒真的に無意味。
  また同 predicate は Laravel 例外ハンドラの `shouldReturnJson` と同一で、abort 時の
  JSON/HTML レンダリング判定と自然に一致する (変更するとむしろ乖離リスク)。
- 対応内容: 設計書 施策 2 に「JSON 判定の根拠」節を追記 (framework ソース引用)。
  懸念のケースは regression テストで固定:
  「Accept: application/json・非 XHR (X-Requested-With なし) → 402 + `message` body 固定」を
  施策 5 のマトリクスに追加 (`getJson()` は Accept のみ付与し X-Requested-With を付けないため、
  まさにこのケースを踏む)。

## [Critical] `createOrganizationWithOwner` 既定変更の横断影響 (「影響なし」前提が強すぎる)
- 判断: 一部対応する (全数調査で根拠を実測に置き換える)。段階移行 (互換ヘルパ並走) は反論する
- 根拠: 指摘のとおり「暗黙 subscription 行」への依存は `subscribed:` 検索では検出できない。
  そこで検出条件を拡張して全数調査した:
  (1) アプリコードで `subscription()` を読むのは `BillingAccess` のみ (`grep -rn "subscription(" app/`)
  → ヘルパ変更がアプリ挙動に波及する経路は BillingAccess だけで、plan_code null では参照されない。
  (2) テスト側の暗黙依存は `grep -rn "subscriptions()\|->subscription(\|createFakeSubscription\|subscribed:" tests/`
  の和集合で **実測 5 ファイル** (RequireActiveSubscriptionMiddlewareTest / DashboardTest /
  TicketCheckoutTest / ReconcileSubscriptionSchedulesTest / SendBillingRemindersTest)。
  特に後者 2 ファイルは生成直後の `$organization->subscriptions()->sole()` が暗黙行に依存しており
  (計 10 箇所)、指摘は正しい。設計書に 5 ファイルの対応表を明記した。
  一方、互換ヘルパの並走 (段階移行) は AGENTS.md 思考原則 3「後方互換の並走を残さない」に反し、
  「subscribed: true = 契約済み」という誤った意味論 (新判定では no-op) をテスト基盤に残すため採らない。
  blast radius は上記のとおり有限・機械的に列挙済みで、同一 PR での一括移行が安全に完了できる。
- 対応内容: 設計書 施策 5 に「影響呼び出し側の全数調査」表を追加。
  ReconcileSubscriptionSchedulesTest / SendBillingRemindersTest は生成直後に
  `createFakeSubscription($organization)` を明示追加 (テストデータの明示化)。
  検証手順 (grep 3 種 + `composer test` 全 green) をチェックリスト化。

## [Warning] `abort(402, message)` の body 形式がクライアント期待と一致するか不明
- 判断: 対応する
- 根拠: `abort(402, msg)` は HttpException 経由で Laravel 標準ハンドラが JSON 要求に
  `{"message": "..."}` を返す (仕様固定の framework 挙動。`response()->json()` 直書きではないため
  禁止事項 4 非抵触)。契約としてテストで固定するのが妥当。
- 対応内容: 施策 5 の JSON テストに `assertJsonPath('message', BLOCKED_MESSAGE)` を追加。
  施策 2 に「402 応答 body の形式」節を追記。

## [Warning] `has_billing_access` リネームは Inertia payload 契約変更として破壊的
- 判断: 対応する
- 対応内容: 受け入れ条件に「実装完了時にリポジトリ全体 (app / resources/js / tests) で
  `rg -n "has_active_subscription|hasActiveSubscription"` が 0 件」を明記。
  契約変更の記録は本設計書 (devnotes) が担う。social page 等の他ページはこの key を
  参照していないことを確認済み (grep 実測: 参照は dashboard.ts / Dashboard.svelte /
  両テストのみ)。

## [Suggestion] `hasActiveAccess` のメソッド名を将来 `hasBillingAccess` へ
- 判断: 見送る (スコープ外に明記)
- 根拠: テンプレートの差し替え seam 名を維持 (テンプレート更新の取り込み容易性)。
  将来のリネームは middleware / DTO と合わせ別 TODO とする旨をスコープ外節に追記した。

## [Suggestion] free プランが Stripe Price を持たない前提のテスト固定
- 判断: 対応する
- 対応内容: 施策 5 に「free プラン (fallback_plan) は plan_prices を持たない」の
  固定テストを追加 (syncPlanCode が price.id → Plan 解決でのみ plan_code を set するため、
  この前提が保たれる限り plan_code に支払い不要プランが載る経路がないことを固定する)。
