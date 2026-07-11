# 対応マトリクス: conceptual-review Round 1

## [Critical] attempt_token 冪等だけでは複数 Checkout Session 作成（別タブ・リロード新 token）を防げない
- 判断: 対応する
- 根拠: 指摘どおり attempt_token replay は「同一 token 再送」にしか効かない。webhook/台帳冪等は同一 session の再送防止であり、別 session の並立は「両方決済完了 → 2 回付与」が正当挙動になってしまう。
- 対応内容: aigenba の pending dedup / 他 count pending expire を移植対象に戻す。checkout 作成前に (org, user, status=pending) の live pending を検索し、(1) 同 count → 既存 session の checkout_url へ replay（新規作成しない）、(2) 別 count → gateway 経由で Stripe 側 session を expire 成功後に expired 化してから新規作成（expire 失敗時は新規作成せずエラー着地）。status enum に `expired` を追加。gateway に `expireCheckoutSession()` を追加。

## [Warning] amount_total 照合は税・割引・promo で壊れる
- 判断: 対応する
- 対応内容: (1) Checkout 作成時に promotion code / automatic tax を使わない構成を明文化（gateway 実装で固定）。(2) 照合対象を `amount_subtotal`（+ `currency` の pin 値一致）に変更。不一致は report + 付与しない（fail-closed）は維持。

## [Warning] pending/completed の 2 状態では期限切れ・放棄が曖昧
- 判断: 対応する
- 対応内容: `TicketCheckoutSessionStatus` を pending / completed / expired の 3 状態に。Stripe Checkout 自体の有効期限（既定 24h）で放棄 session は Stripe 側で expire する。stale pending は「別 count で購入し直す」操作時に expire 経路で回収される（専用 cron は v1 では作らない = 過剰実装回避、根拠を設計に記載）。

## [Warning] cheapestPlanAmountJpy が Free で ¥0 になり訴求とずれる
- 判断: 対応する
- 対応内容: LP は「無料で始める（Free プラン + 初回チケット 10 枚）」を正面訴求とし、`cheapestActiveAmountJpy` の移植を落とす（LandingPageDto は signupGrantTickets / contactUrl / contactIsExternal / isAuthenticated）。料金 CTA は金額なしの「料金プランを見る」。JSON-LD lowPriceJpy は 0（Free）を供給し「無料開始 + チケット制」を FAQ・料金注記と同一粒度で一貫させる。

## [Warning] checkout 応答系の Inertia 統一を明文化せよ
- 判断: 対応する
- 対応内容: 成功 = `Inertia::location($checkoutUrl)`（外部遷移）、バリデーション失敗 = ValidationException（422 → Inertia 標準）、業務エラー（進行中・stale token 等）= `back()->with('error', ...)` に固定。`response()->json()` 直書きゼロを設計に明記。

## [Warning] 402 導線で manageBilling なしメンバーが購入画面で詰む
- 判断: 対応する
- 対応内容: 購入画面は全メンバー閲覧可（傾斜表・残高の透明性）。`canManage` prop で role-aware 表示: 非管理者には「チケットの購入は組織のオーナー / 管理者が行えます」の案内を出す（ボタン disabled にはしない = 押下時 403 は発生させず案内文で分岐。POST 側 Gate は維持）。

## [Warning] require-active-subscription allowlist の拡げすぎ
- 判断: 反論する（誤解の解消）
- 根拠: AI-CUE の課金ゲートは「gated group に入れた route のみ」に適用される opt-in 構造（routes/web.php）。billing.* は元から group 外。新 route 2 本も billing.* と同じ位置（group 外）に**個別登録**するだけで、allowlist の集合を広げる変更ではない。設計にこの構造を明記する。

## [Warning] 価格改定（Standard ¥1,980→¥4,980）は別のプロダクト判断
- 判断: 対応する（スコープには残す）
- 根拠: 「価格値は aigenba のものをそのまんま移植（plan_prices 含む）」はユーザーの明示指示。ただし指摘どおり検証観点が別物。
- 対応内容: 独立施策（seeder + fixture + 関連テストの期待値更新のみ）として切り出し、テスト観点を導線実装と分離。実装時も独立コミットとする。

## [Warning] DTO array shape / TS 契約の固定が粗い
- 判断: 対応する
- 対応内容: 全 DTO に `@phpstan-type XxxShape array{...}` + shape を返す `toArray()` を定義（aigenba の LandingPageDto / PricingPageDto と同形式）。TS 側は同名 interface を exact に定義し、Feature テストで Inertia props の key/型を固定する。

## [Suggestion] /contact の source バリデーション・集計の列挙漏れ
- 判断: 対応する
- 対応内容: `InquirySource::normalize()` は enum cases 由来の allowlist のため case 追加で自動追随するが、Filament 管理画面等の表示 label 定義を波及変更として詳細設計の変更ファイルに含める。

## [Suggestion] 402 時に必要枚数を示す
- 判断: 対応する（既存挙動の活用）
- 対応内容: `InsufficientTicketsException::forReserve` のメッセージが既に「必要 N / 残高 M」を含む。UI はこのメッセージ + 購入リンクを併記する。

## [Suggestion] PlanDto を Marketing/PricingPlanDto に寄せる
- 判断: 対応する
- 対応内容: pricing 表示専用 DTO は `Marketing/PricingPlanDto` に配置（Billing 内部 DTO と責務分離）。
