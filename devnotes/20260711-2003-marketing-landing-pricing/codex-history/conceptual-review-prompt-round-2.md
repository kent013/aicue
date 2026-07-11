# conceptual-review Round 2: Round 1 指摘への対応報告

全 Critical / Warning に対応（1 件は構造誤解の解消として反論）した。再判定を依頼する。

## 対応マトリクス

### [Critical] attempt_token 冪等だけでは複数 session 並立を防げない → 対応
aigenba の **pending dedup（T680 相当）+ 別 count pending の expire** を移植対象に戻した。checkout は org 単位 Cache::lock 内で:
1. 同一 attempt_token の既存行 → 同 count pending は URL replay / completed は「受付済み」/ 不一致・非 replayable は再読み込みエラー。
2. **live pending dedup**: 同 (org, user, status=pending) の既存行が同 count → 新規作成せず既存 checkout_url へ replay（別タブ・新 token でも session は 1 本）。別 count → gateway 経由で Stripe session を expire（expire 結果が complete なら「処理中」エラー）、成功時のみ行を expired 化して新規作成。expire 失敗時は新規作成しない（二重 live session を作らない）。
3. INSERT unique 違反（並行 race / Stripe idempotency replay）→ 既存行 re-read で replay / エラー収束（500 にしない）。
status enum に `expired` を追加（pending / completed / expired の 3 状態）。gateway interface に `expireCheckoutSession()` を追加。

### [Warning] amount_total 照合が税・割引で壊れる → 対応
照合対象を `amount_subtotal`（+ `currency` の pin 値一致）に変更。Checkout 作成側でも promotion code / automatic tax を使わない構成に固定（二重防御）。不一致は report + 付与しない（fail-closed）を維持。テーブルに `currency` pin 列を追加。

### [Warning] 2 状態では期限切れ・放棄が曖昧 → 対応
上記のとおり expired を追加。放棄 session は Stripe Checkout 自体の有効期限（既定 24h）で Stripe 側が expire し、stale pending 行は次回の別 count 購入時の expire 経路で回収（専用 cron は v1 では作らない = 局所回収で十分・過剰実装回避、根拠を設計に記載）。

### [Warning] cheapestPlanAmountJpy が Free で ¥0 になり訴求とずれる → 対応
`cheapestActiveAmountJpy` の移植を落とし、LP は「無料で始める（Free プラン + 初回チケット 10 枚）」を正面訴求に統一。料金 CTA は金額なし。JSON-LD lowPriceJpy は 0（Free）で「無料開始 + チケット制（AI 解析 1 枚 / 動画レンダ 3 枚）」の粒度を Hero・CTA・FAQ・料金注記で一貫させる。LandingPageDto は signupGrantTickets / contactUrl / contactIsExternal / isAuthenticated。

### [Warning] checkout 応答の Inertia 統一 → 対応
応答規約を設計に明文化: 成功 = `Inertia::location($checkoutUrl)` / バリデーション失敗 = FormRequest 422（Inertia 標準）/ 業務エラー = `back()->with('error', ...)`。`response()->json()` 直書きゼロ・`redirect()->intended()` 不使用。

### [Warning] manageBilling なしメンバーが購入画面で詰む → 対応
購入画面は全メンバー閲覧可（Gate view）+ `canManage` prop で role-aware 表示: 非管理者には「チケットの購入は組織のオーナー / 管理者が行えます」の案内を表示（disabled は使わない）。POST 側の Gate manageBilling は維持。

### [Warning] require-active-subscription allowlist の拡げすぎ → 反論（構造誤解の解消）
AI-CUE の課金ゲートは routes/web.php で「gated group に入れた route のみ」に適用される opt-in 構造。billing.index / billing.checkout / billing.portal は元から group 外に個別登録されている。新 route 2 本（purchase-tickets の GET/POST）も同じ位置に**個別登録**するだけで、allowlist の集合演算を変える変更ではない（= 指摘の「route 名ベースで最小化」がまさに現行構造）。設計にこの構造を明記した。

### [Warning] 価格改定（Standard ¥1,980→¥4,980）は別のプロダクト判断 → 対応
独立施策（別コミット・テスト観点分離）に切り出した。値自体は「価格値は aigenba のものをそのまんま移植（plan_prices 含む）」というユーザーの明示指示に基づく。

### [Warning] DTO array shape / TS 契約の固定が粗い → 対応
全 DTO に `@phpstan-type XxxShape array{...}` + shape を返す `toArray()` を定義（aigenba LandingPageDto / PricingPageDto と同形式）。TS 側は同名 interface を exact に対で保守し、Feature テストで Inertia props の key/型を固定する。

### [Suggestion] /contact source 列挙漏れ → 対応（normalize は cases 由来で自動追随。Filament 等 label 定義を詳細設計の波及変更に含める）
### [Suggestion] 402 で必要枚数提示 → 既存挙動で充足（`InsufficientTicketsException::forReserve` メッセージが「必要 N / 残高 M」を含む。UI はメッセージ + 購入リンク併記）
### [Suggestion] PlanDto の配置 → 対応（`Marketing/PricingPlanDto` に配置し Billing 内部 DTO と責務分離）

## 改訂後の概念設計の変更点（差分要約）

- 施策群 A: cheapestActiveAmountJpy 廃止・無料開始訴求へ一貫化、JSON-LD lowPriceJpy=0。
- 施策群 C: 冪等マシンを「attempt_token replay + live pending dedup + 別 count expire + unique 収束」の 4 段に強化。status 3 状態化・currency pin 列・gateway expireCheckoutSession 追加・応答規約明文化・購入画面 role-aware 化。
- 実装方針表: PricingPlanDto を Marketing へ、DTO shape 固定を明記、価格改定を独立施策化。

改訂版の全文は devnotes/20260711-2003-marketing-landing-pricing/conceptual-design.md（読み込み可）。

再判定（APPROVED / CHANGES_REQUESTED）と、残る指摘があれば [Critical]/[Warning]/[Suggestion] で提示してほしい。
