# 対応マトリクス: impl-review Round 1

## [Critical] success_url が current org 依存で、org 切替/脱退時に 404・誤バナーになりうる
- 判断: 対応する（帰還表示の照合を実装）/ 404 部分は反論
- 根拠: 課金の正しさは webhook (DB 行が真実源) が担保しており金銭事故は起きない。問題は「帰還表示の正確性」。org 非依存の専用完了ページ新設は「今必要なものだけ作る」原則と current-org スコープの routing 方針 (ResolvesCurrentOrganization) に反するスコープ拡大。current org 未設定時の 404 は全 current-org ルート共通の設計 (`TicketCheckoutTest` の既存テストで pin 済み) であり、checkout 発起者が決済中に current org を失うのは極端なエッジ。
- 対応内容:
  - success_url に Stripe 置換テンプレート `&session_id={CHECKOUT_SESSION_ID}` を追加。
  - `TicketCheckoutService::confirmsPurchaseReturn()` を新設し、`show()` は session_id が current org の checkout 行と一致した時のみ `purchased=true` を返す (fail-closed)。
  - これにより org 切替中の帰還での誤バナー、および任意 query `?purchased=1` によるバナー偽装を排除。
  - テスト追加: 一致→表示 / session_id なし・未知→非表示 / 他 org の session_id→非表示。

## [Warning] live pending dedup が (org, user) 単位で、同一 org の別管理者は live session が並立する
- 判断: 反論する（意図した設計）
- 根拠: class docblock は当初から「同 (org, user) の決済待ち session を 1 本に収束」と明記しており、コメントと実装の矛盾はない (「live session は 1 本」は (org, user) スコープ内の記述)。org 単位 dedup にすると管理者 B の操作が管理者 A の決済途中の live session を expire できてしまい、ユーザー間干渉という別の UX/整合性問題を生む。別管理者の同時購入は「2 つの意図した独立購入」であり二重課金ではない。付与の冪等性は session 単位で webhook が担保。
- 対応内容: 変更なし。

## [Warning] 金額照合不一致時の failure_reason に expected/actual がなく運用復旧が遅い
- 判断: 対応する
- 対応内容: `StripeWebhookProcessor` の照合不一致 RuntimeException メッセージに expected (count×pin単価, pin通貨) / actual (amount_subtotal, currency) を含めた。既存テストの部分一致 (`金額/通貨照合不一致`) は維持。

## [Warning] attemptToken が初回 props 固定で validation エラー後に stale 化する
- 判断: 反論する（既に成立している）
- 根拠: `PurchaseTickets.svelte` の submit は `page.attemptToken` を送信時に読む ($props は reactive)。サーバ validation エラー / 業務エラーは `back()` redirect となり、Inertia が show() を再訪して新しい attemptToken を含む props で再レンダーするため、次回送信は常に新 token。stale になるのはクライアント側事前チェックで弾かれた場合のみで、そのとき token は未消費。
- 対応内容: 変更なし。

## [Warning] alreadyCompleted 分岐が info toast のみで success_url 帰還のバナーと不統一
- 判断: 見送る
- 根拠: 意味が異なる 2 経路 (Stripe 決済完了からの帰還=「購入ありがとうございます」/ 受付済み attempt の再送=「既に受付済み」)。文言はそれぞれの状況に正確で、統一には session id の plumbing が必要になり得るが得られる価値が小さい。purchased バナーは今回 session 照合制になったため、replay 経路に purchased=1 を付ける安易な統一はむしろ不正確になる。
- 対応内容: 変更なし。

## [Suggestion] PricingService の currentPrice が N+1
- 判断: 見送る
- 根拠: プラン数は 2〜3 件で毎リクエスト高々 3 クエリ + リクエスト内メモ化済み。`currentPrice()` は T007 以前からの共有モデル API で、eager load への作り替えはスコープ外の最適化。
- 対応内容: 変更なし。

## [Suggestion] #ticket-pricing アンカーの aria-label
- 判断: 対応する
- 対応内容: `Pricing.svelte` のアンカーに `aria-label="チケット料金セクションへ移動"` を付与。

## [Suggestion] expired 行への遅延 completed webhook の方針テストがない
- 判断: 対応する
- 対応内容: `TicketPurchaseWebhookTest` に「expired 行への遅延 completed webhook でも付与し completed 化する (決済成立が真実源)」テストを追加し、支払い済み付与を落とさない方針を pin。
