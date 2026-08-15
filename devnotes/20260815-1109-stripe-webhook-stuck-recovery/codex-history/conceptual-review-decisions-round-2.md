# 対応マトリクス: conceptual-review Round 2

## [Critical] `report()` / `Log::warning` をトランザクション内で呼ぶ設計が不適切
- 判断: 対応する
- 根拠: 指摘のとおり。commit 失敗・再試行のときに「状態は保存されていないのに通知だけ出る」
  「同じ行に複数回通知が出る」が起きる。「1 行につき 1 回」の根拠が崩れる。
- 対応内容: `claimStale()` はトランザクション内で**状態遷移だけ**を確定し、
  何が起きたかを型付きの結果 (`WebhookStaleClaimOutcome` enum) で返す。
  `report()` / `Log::warning` は commit 後に呼び出し側 (`recoverStale()`) が出す。

## [Critical] DB に未知 / 未分類の `type` が保存されていた場合の扱いが無い
- 判断: 対応する
- 根拠: 実在する。`config('cashier.webhook.events')` は Cashier の DEFAULT_EVENTS も含むため、
  `HandledStripeWebhookEvent` に無い type の行 (`customer.updated` 等) が実際に記録される
  (`process()` の `null => null` arm で受理のみ)。`from()` を使うと cron 全体が例外で止まる。
- 対応内容: 境界変換は `HandledStripeWebhookEvent::tryFrom()` にし、**未知値は deny-by-default で
  自動再実行しない** (`recovery_pending` + 理由 `UnknownEventType`)。
  行単位の異常で cron を止めず次の行へ進む。
  回収待ちに入る理由は 2 通りから **3 通り**になった。

## [Warning] 回収を止めた理由が status から復元できない
- 判断: 対応する
- 根拠: 「順序に依存する種類だったのか / 上限に到達したのか / 未知の種類だったのか」は
  運用の次の行動が変わる情報で、`status` / `type` / `attempts` からは後で確定できない
  (分類を将来変えたときの当時の判断も残らない)。
- 対応内容: `WebhookRecoveryReason` (3 値の enum) を新設し、
  `stripe_webhook_events.recovery_reason` (nullable) へ保存する。
  自由文の `failure_reason` とは混ぜない。列 1 本の migration を追加する
  (この規模はスコープ過大にあたらないと判断した)。

## [Warning] `recoverStale(): int` の件数の意味が不明確
- 判断: 対応する
- 対応内容: 戻り値を `WebhookRecoveryResultDto` (readonly。`replayed` / `rested` / `skipped` の
  3 件数) にする。既存の `BillingRetentionPurgeResultDto` と同じ作法。
  Artisan の終了コードとは分離する (異常終了させない)。

## [Warning] 条件付き UPDATE は `save()` ではなく条件を含む単一 SQL にする
- 判断: 対応する
- 対応内容: 終局書き込みを
  `where event_id = ? AND status = 'received' AND attempts = 受理時の値` の単一 UPDATE にし、
  **更新件数が 1 のときだけ終局化成功**と判定する。
  成功時は `status=processed` / `processed_at=now` / `failure_reason=null`、
  失敗時は `status=failed` / `failure_reason=例外メッセージ` を同じ UPDATE で確定する。

## [Warning] 並行する `process()` の副作用は CAS では止まらない
- 判断: 対応する (テスト計画で受ける + 保証範囲を明記する)
- 根拠: そのとおり。CAS が守るのは webhook 行の世代だけで、
  元 worker と回収 worker の `process()` は並行し得る。一回性は台帳の
  `idempotency_key` UNIQUE と各ハンドラの終局 guard が担う。
- 対応内容: 詳細設計のテスト計画に「`SafeToReplay` の各種類について、
  同じ payload を 2 回処理しても付与が 1 回であること」を種類ごとに置く。
  併せて「本設計が保証するのは webhook 行の世代管理までで、
  ハンドラの一回性は台帳の UNIQUE が担う」と明記する
  (**真の同時実行はテストしない**ことも誇張せずに書く)。

## [Warning] 必要なテストが実装方針に明記されていない
- 判断: 対応する
- 対応内容: 実装方針の表にテストファイルを変更対象として明記した
  (指摘の 7 項目をすべて含む)。

## [Suggestion] `report()` は配送を保証しない
- 判断: 対応する
- 対応内容: 期待効果の表現を「観測点を作る」に留め、「運用へ確実に通知する」と書かない。

## [Warning] 型安全性 (境界変換 / 返り値)
- 判断: 対応する
- 対応内容: `tryFrom()` での境界変換、`claimStale()` の enum 返却、
  回収理由の enum 化、`payload` の契約維持を設計に明記した。
  PHPStan の widen / 包括 ignore は行わない。

## 補足 (Codex への回答となる設計判断)
- 主キー同一性クエリの目録 (`DirectFetchInventory`) について: 回収経路の行の取り直しは
  **`event_id` (UNIQUE 列)** を handle にする。本クラスは元々 `claim()` が
  `where('event_id', …)` で行を引いており、識別子を 2 本立てにしない方が読みやすい。
  副次的に主キー同一性クエリの母集団にも入らない。
  ただし実装時に `ModelDirectFetchInvariantTest` を実行し、検出されたら
  失敗メッセージのキーで目録へ登録する (deny-by-default を迂回しない)。
