# 対応マトリクス: conceptual-review Round 2

## [Critical] `create_prorations` と「upgrade は即時差額請求」が一致しない

- 判断: **対応する** (指摘が正しい。`create_prorations` は proration 明細を作るだけで即時請求ではない)
- 根拠: 即時徴収 (`always_invoice`) を採ると、与信失敗時の `incomplete` / `past_due` /
  `pending_update` という決済状態遷移と、それを受ける UI・webhook 設計を丸ごと呼び込む。
  本件で必要なのは「プランを変えられること」であって即時徴収ではない (思考原則 2)。
- 対応内容: **`create_prorations` を維持**し、文言を「日割り調整額はその場では請求されず
  次回請求に反映される (upgrade は差額加算 / downgrade は繰越クレジット)」に修正。
  `always_invoice` を採らない理由も明記。UI の確認ダイアログ文言 (upgrade) も同期修正。

## [Critical] idempotency key の ABA 衝突 (`A→B→A→B` の 3 回目が replay される)

- 判断: **対応する** (指摘が正しい。`change-plan:{sub}:{period_end}:{plan}` は同一請求期間内で再現する)
- 対応内容: key を **`change-plan:{plan_change_token}:{plan_code}`** に変更。
  `plan_change_token` は `/billing/plans` の **1 render 1 個**の ULID をサーバが発行する
  (既存 `subscriptionAttemptToken` / `ticketAttemptToken` / `autoRechargeSetupToken` と同じ
  「操作ごとに別 key 空間」の作法。DB 行は作らない)。
  - 同一 render の二重送信 → 同一 key に収束 (二重 proration を作らない)
  - ABA 往復 → render ごとに token が変わるため別 key (3 回目が握り潰されない)
  - 同一 token で別プラン → key に plan_code が入るため Stripe の「同一 key・別パラメータ」
    エラーにならない
  `plan_change_token` を `BillingPlansPageDto` / TS props / `ChangePlanRequest` に追加。
  **ABA 往復で key が変わることを Feature テストで固定**する。

## [Warning] 成功条件 3 で「Stripe に受理された」と「変更が確定した」が未区別

- 判断: 対応する
- 対応内容: 「成功の定義」を追加。本設計の成功は **accepted** (Stripe が 200 を返した) までで、
  `applied` (= `organizations.plan_code` の追随) は webhook 到達時。`create_prorations` を採る
  以上 `payment_action_required` は発生しないことを明記。UI 文言もこの区別をそのまま表す。

## [Warning] Fake の no-op + webhook 注入だけでは mutation 内容を証明できない

- 判断: 対応する
- 対応内容: 検証方針を 3 層に分割。(1) `buildSwapPayload()` の純関数単体テストで
  `items[0].id/price` / `proration_behavior` / idempotency key を固定、
  (2) `StripeGatewayInterface` mock の Feature テストで「正しい引数で 1 回だけ呼ぶ」ことと
  ABA の key 差異を固定、(3) webhook 注入テストで `plan_code` 追随を固定。

## [Warning] Gateway の戻り値契約が未定義 (Stripe object の漏れ)

- 判断: 対応する
- 対応内容: gateway は `void` (= accepted のみ) を返す契約とし、Stripe SDK の object / 例外を
  Service より外へ漏らさない。Service で `Throwable` を捕捉して型付き
  `PlanChangeFailedException` に変換し、Controller は back + error flash に倒す。

## [Suggestion] 使命整合 / 案 2 却下 / upgrade-only 却下の妥当性

- 判断: 反映済み (Round 2 で APPROVED 相当の評価を得た論点。変更なし)。
