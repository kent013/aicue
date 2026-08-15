# 対応マトリクス: impl-review Round 1

## [Warning] 施策 6 の中核テストが足りない (チケット予約の並行競合・述語再評価)

- 判断: 対応する
- 根拠: 指摘のとおり。行ロック下の述語再評価が今回の主眼で、実装だけあってテストが無いのは
  「テストなしの実装完了報告」にあたる (禁止事項 1)。
- 対応内容: `tests/Feature/Billing/TicketCommitWinsTest.php` に 2 本追加した。
  - 「候補列挙後に commit された予約は Skipped で、回収は成功のまま終わる」
    (`candidateIds` で id を取る → `commit()` → `recover()` の順で再現。例外を投げないことも見る)
  - 「候補列挙後に expires_at が延長された予約は解放されない」
    (述語が不成立になった行を名指しで回収しても `Skipped` で、status が Reserved のまま)

## [Warning] 施策 7 の「4 つの結果の種類がコマンド出力に現れる」テストが不足

- 判断: 対応する
- 根拠: 旧語彙で監視していた運用者が新語彙で探せることは docs の対応表だけでは固定できない。
  出力そのものを見るテストが要る。
- 対応内容: `tests/Feature/Billing/StripeWebhookStaleRecoveryTest.php` に 3 本追加した。
  コマンド (`work:recover-stuck --stream=webhook_event --apply`) の出力について、
  件数と語彙と並び順を含む**1 行まるごと**を部分文字列として照合し、
  `replayed → recovered` / `moved-to-recovery-pending → escalated` /
  `retry-scheduled → deferred` (かつ `errors=0` のまま) / 世代を追い越された回収 → `skipped`
  の 4 つを固定した。

## [Suggestion] `possibleOutcomes` の検査が「空でない」だけで弱い

- 判断: 対応する (ただし Codex の言う exact-fit そのものは採らない)
- 根拠: 「各系列の申告が期待集合と一致する」形にすると、期待集合を書く場所が目録しか無く
  同語反復になる (目録が目録と一致する、としか言えない)。代わりに**目録の外側から効く 2 つ**を足した。
- 対応内容: `StuckWorkRecoveryInventoryTest` に 2 本追加した。
  - 全系列が `Recovered` と `Skipped` を必ず申告する
    (回収の系列である以上この 2 つは必ず起こりうる。起こりえないなら回収ではない)
  - 申告の合併が `RecoveryOutcome` の全 case を覆う
    (どの系列も返さない値を enum に残さない = 死んだ語彙を作らない)
  併せて「申告は申告であって、実際にその種類を返しうるかは各系列の Feature テストが担う」ことを
  検査の中に明記した (保証範囲を誇張しない)。

## [Suggestion] `staleEventIds()` は主キーを返すのに `event_id` と読める

- 判断: 対応する
- 根拠: このクラスでは `event_id` が Stripe 側の識別子として重要語彙であり、
  同じ語で主キーを指すのは将来の誤読を招く。名前は役割を示すべきである。
- 対応内容: `staleEventIds` → `staleRecordIds` へ改名した
  (`StripeWebhookProcessor` / `StaleWebhookEventStream` / `DirectFetchInventory` の根拠文)。
