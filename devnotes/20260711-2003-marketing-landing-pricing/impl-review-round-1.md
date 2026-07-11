## Critical
- `app/Services/Billing/TicketCheckoutService.php:145` — `route('billing.tickets.show', ['purchased' => 1])` が current org 未設定時に 404 へ落ちうる（`TicketPurchaseController::show()` は `resolveCurrentOrganization()` 必須）— 根拠: Webhook 完了後に Stripe `success_url` へ戻っても、ユーザーが別組織に切替済み/脱退済みだと「購入完了の案内」より先に NotFound になる — 修正案: `success_url` は org 非依存の完了ページ（例 `/billing/tickets/receipt?session=...`）に分離し、そこから org 解決可能時のみ残高ページへ誘導する。少なくとも `show()` 側で `purchased` 表示を org 解決失敗に依存させない設計にする。

## Warning
- `app/Services/Billing/TicketCheckoutService.php:108-116` — live pending dedup が `(organization_id, initiated_by_user_id)` 単位のため、同一 org の管理者 A/B が同時に別 count 購入すると Stripe 側に live session が複数並立する — 根拠: コメントは「live session は 1 本」だが実装は user スコープで矛盾。Webhook 冪等で二重付与は防げても「二重決済誘発」UX は残る — 修正案: dedup を org 単位に変更（`initiated_by_user_id` 条件を外す）し、別管理者開始時も既存 live を replay/expire 対象に含める。
- `app/Services/Billing/StripeWebhookProcessor.php:445` — 金額照合が `amount_subtotal === ticket_count * unit_amount` の厳密一致のみで、Stripe 側通貨小文字正規化差異や将来の最小仕様変更に脆い — 根拠: 現状 invariant テストで守っているが、運用で設定変更時に即 failed 連鎖し terminal-ack へ到達しやすい — 修正案: 現仕様維持は妥当だが、`failure_reason` に差分値（expected/actual）を構造化記録して運用復旧を高速化。
- `resources/js/pages/Billing/PurchaseTickets.svelte:92-104` — `attemptToken` を初回 props 固定で送っており、バリデーションエラー後に同画面滞在したまま再送すると stale 化しやすい — 根拠: サーバは stale 時に「再読み込みして再試行」前提。UI 上は再読み込み導線が明示されず詰まりやすい — 修正案: `error` 受信時に `router.reload({ only: ['page'] })` 等で新 token を再取得、または「再読み込み」CTA を明示表示。
- `app/Http/Controllers/Billing/TicketPurchaseController.php:80-82` — `alreadyCompleted` 分岐で `with('info', ...)` のみ付与し `purchased=1` を付けないため、成功バナー文言と体験が経路で不統一 — 根拠: success_url 帰還時は `purchased` バナー、replay completed 時は toast のみ — 修正案: UX を統一（どちらも同じ完了画面/同じバナー）。

## Suggestion
- `app/Services/Marketing/PricingService.php:38` — `Plan::query()->orderBy('sort_order')->get()->map(...)` で `currentPrice()` が内部クエリなら N+1 になりうる — 根拠: テスト規模では問題化しないが公開ページで常時アクセス対象 — 修正案: `with(['prices' => fn(...)])` などで eager load し、`currentPrice` をメモ化可能に。
- `resources/js/pages/Pricing.svelte:146` — 内部リンク `<a href="#ticket-pricing">` は良いが、同ページ内アクセシビリティ向上のため `aria-label` 付与を検討 — 根拠: 文脈依存リンク語が増えると SR 利用時に曖昧 — 修正案: `aria-label="チケット料金セクションへ移動"`。
- `tests/Feature/Billing/TicketPurchaseWebhookTest.php` — 強い網羅だが「`ticket_checkout_sessions.status=expired` に対する completed webhook 再送」の期待挙動テストがない — 根拠: 現実には遅延到着があり得る — 修正案: expired 行でも付与許可/拒否の方針を固定するテストを追加。

## 総評
T007 は全体として設計意図（tenant キー不信・webhook 冪等・DTO 化・UI 規約）に高い整合で、重大な実装品質です。  
一方で、購入完了の着地経路（success_url の org 依存）と live session の dedup 粒度（user 単位）は、実運用で課金 UX 事故になり得るため先に是正推奨です。  
上記2点を潰せば、課金系として本番投入の堅牢性はかなり高い水準です。