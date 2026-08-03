# 対応マトリクス: conceptual-review Round 3

## [Critical] 「反映前に同じプランを再度押しても同一 key に収束する」は成立しない (再表示で token が変わる)

- 判断: **対応する** (指摘が正しい。redirect → 再表示で新 token が発行され、画面は旧プランのまま)
- 対応内容: 冪等 key は「同一 render の二重送信」だけを守ると**保証範囲を限定して明記**し、
  それ以外は **remote 状態との照合**で構造的に潰す設計に変更した。
  - swap 前に Stripe から subscription を `expand=items.data` で取得する
    (**既存 item id の解決にどのみち必要な 1 回の read**。API 呼び出しは増えない)。
  - base item の price が既に対象と同一なら **update を送らない** (`AlreadyOnTargetPrice`)。
  - ローカル (`subscriptions.stripe_price` / `organizations.plan_code`) は webhook ラグが
    あるため判定に使わない (remote が唯一の真実源)。
  - これで「反映待ち中の再操作」も「idempotency key 保持期間 (24h) 超過後の再操作」も
    二重 proration を作らない。
  - **「成功 → 再表示 (webhook 未到達) → 同じ変更を再送」で update を送らないこと**を
    Feature テストで固定する項目として明記した。
- 副次: gateway の戻り値を `void` → `SubscriptionSwapOutcome` enum
  (`Applied` / `AlreadyOnTargetPrice`) に変更し、UI の flash 文言も出し分ける。

## [Warning] `create_prorations` でも条件次第で即時請求されうる (断定が強すぎる)

- 判断: 対応する
- 対応内容: 「その場では請求されない」の成立条件を
  **同一請求間隔 (月次) / 同一通貨 (jpy) / base price 1 item の差し替え**に限定して明記。
  即時請求を誘発しうるパラメータ (`billing_cycle_anchor` / `trial_end` /
  `payment_behavior` の明示指定) を**送らないこと**を gateway payload 単体テストで pin する
  項目として追加。

## [Warning] `Throwable` の一括変換は実装バグを決済失敗として隠す

- 判断: 対応する
- 対応内容: 変換対象を **`\Stripe\Exception\ApiErrorException` (API / 通信 / 認証系はすべて
  この派生) のみ**に限定。`TypeError` 等は握り潰さず 500 に落として調査対象にする。

## [Suggestion] `applied` より `projection_synced` の方が正確

- 判断: 対応する
- 対応内容: 用語を `accepted` (Stripe 200 = Stripe 側では適用済み) /
  `projection_synced` (webhook で `organizations.plan_code` 追随) に改めた。

## [Suggestion] 使命整合 / 禁止事項 / スコープ / 型安全性

- 判断: 反映済み (変更なし)。
