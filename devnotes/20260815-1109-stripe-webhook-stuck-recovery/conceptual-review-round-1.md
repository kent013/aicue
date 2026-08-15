全体判定: **CHANGES_REQUESTED**

**1. 使命との整合性**
[Warning] 改善の方向は North Star に本質的に貢献します。決済済みチケット付与の取りこぼしは、撮影・レンダ実行権の喪失なので、AI-CUE の中核体験を直接壊します。

ただし「付与系イベントが無音で失われないこと」が主目的なら、`customer.subscription.*` を同じ回収状態に混ぜる設計は危険です。使命貢献の中心は `invoice.paid` / `checkout.session.completed` / `charge.refunded` 等の付与・台帳系に絞るべきです。

修正提案: 回収 cron の主対象を `SafeToReplay` のみに限定し、`OrderSensitive` は別の観測・手動対応状態または単なる `report()` 対象として明確に分離してください。

**2. 禁止事項違反**
[Warning] 現時点の概念設計自体は、明示的な禁止事項には直接抵触していません。DB 破壊操作、`response()->json()` 直書き、Prism 直呼び、prompt 直書きなども含まれていません。

ただし実装時に `billing:recover-stale-webhook-events` の結果を JSON endpoint として足す、または payload 由来の tenant/actor キーを使って再処理するような拡張をすると不変条件違反になり得ます。

修正提案: 設計に「保存済み Stripe payload から tenant/organization を信用せず、既存 service の org-scoped 解決・Stripe ID 解決に委譲する」と明記してください。

**3. 実現可能性**
[Critical] `recovery_pending` を `claim()` が無条件に受理する設計だと、`OrderSensitive` を「自動再実行しない」という設計意図が破れます。

理由は、cron が `customer.subscription.*` を `recovery_pending` に落としたあと、Stripe の再送または手動再投入が `handle()` に入ると、`claim()` が `recovery_pending` を受理して通常処理してしまうためです。結果として、古い subscription payload が後から `SubscriptionService::applySubscriptionSnapshot()` に流れ、`plan_code` / `stripe_status` / `current_period_end` を巻き戻す可能性があります。

修正提案: `claim()` は `recovery_pending` を一律受理しないでください。少なくとも次のどちらかが必要です。

- `RecoveryPending` は `SafeToReplay` のイベントにしか設定しない
- `claim()` 側で `HandledStripeWebhookEvent::replaySafety()` を見て、`OrderSensitive` の `recovery_pending` は受理せず `report()` のみにする

この修正が入るまで承認不可です。

[Warning] `received` は「受信済み」と「処理中」を兼ねているため、15 分を超えて本当に処理中の worker がいる場合、cron が同じ行を `recovery_pending` に落として再処理を始める競合があります。金銭付与は idempotency key で守られているとしても、並行した状態更新やログ・失敗記録の上書きは起こり得ます。

修正提案: 滞留判定閾値は「通常処理時間の十分な上限」を根拠付きで決め、`recoverStale()` のテストに「live process が後から成功保存する競合」を入れて、最終状態が壊れないことを確認してください。

**4. 期待効果の妥当性**
[Warning] `SafeToReplay` に限定すれば、期待効果は合理的です。台帳側の UNIQUE idempotency key があるため、少なくとも二重付与の追加被害は抑えられます。

一方で、設計文中の「決済済みなのにチケットが増えない事故が構造的に消える」はやや強い表現です。`MAX_PROCESSING_ATTEMPTS` 到達、payload 不整合、Stripe API 取得失敗、実装バグでは依然として付与されません。

修正提案: 効果表現を「クラッシュで `received` に残った SafeToReplay イベントが無音で失われる経路を塞ぐ」に弱めてください。

**5. リスク**
[Critical] `OrderSensitive` の扱いが現在のままだと、課金状態の巻き戻りという重大な後退を生みます。これは金銭付与の取りこぼし修正よりも広い副作用です。

修正提案: `WebhookReplaySafety` を単なる分類 enum にせず、回収対象 query または状態遷移の gate として使ってください。`OrderSensitive` は `recovery_pending` にしない、または `manual_attention_required` 相当の別状態に分けるのが安全です。

[Warning] `recovery_pending` の残存件数と `report()` を観測点にするだけでは、運用が拾える保証が弱いです。

修正提案: `docs/architecture.md` に監視対象、閾値、対応手順、再実行してよいイベント種別を明記してください。可能ならログメッセージに `event_id` / `type` / `attempts` / `status` を必ず含める設計にしてください。

**6. スコープの適切さ**
[Warning] スコープは概ね適切です。共通回収基盤を作らず、既存の `recoverStale` 作法に寄せる判断は妥当です。

ただし `customer.subscription.*` を同じ仕組みで「回収待ち」に入れる部分だけがスコープ過大です。順序判定列を作らないなら、自動回収の対象に含めるべきではありません。

修正提案: 本 TODO のスコープを「SafeToReplay webhook の stuck recovery」に絞り、subscription snapshot の順序安全化は明確に後続 TODO としてください。

**7. 型安全性**
[Warning] `WebhookReplaySafety` と `HandledStripeWebhookEvent::replaySafety()` を網羅 `match` にする方針は PHPStan level 10 と相性が良いです。

ただし保存済み `payload` は `array` のままなので、回収経路で `$payload['type']` や `$payload['id']` を直接読む実装にすると型安全性が崩れます。

修正提案: 既存の `stringAt()` のような accessor を再利用し、`HandledStripeWebhookEvent::tryFrom($type)` などで未対応 type を明示的に分岐してください。`recoverStale()` の戻り値は件数 `int`、内部の再処理結果は enum か小さな DTO にして、ログ用の連想配列に混ぜ込まない形が安全です。

結論として、改善の方向性は正しいですが、`recovery_pending` を `claim()` が無条件に受理する点が設計上の穴です。`SafeToReplay` だけが自動再処理される状態遷移に修正できれば、承認可能な設計に近いです。