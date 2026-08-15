全体判定: **CHANGES_REQUESTED**

## 1. 使命との整合性

[Suggestion]  
支払い失敗の無期限利用を止める設計は、LLM解析・動画合成の実費を守るため、AI-CUEの継続提供に本質的に貢献する。North Starとの整合性はある。

ただし、これは利用体験改善ではなく事業継続・課金健全性の改善なので、ドキュメント上も「標準化動画作成体験を維持するための原価管理」と位置づけるのがよい。

## 2. 禁止事項違反

[Warning]  
`past_due_since` の backfill migration が「既存 past_due 行を更新する」ため、dev DB 破壊操作ではないが、課金状態に関わるデータ補正である。実装時は migration のみで完結させ、手動 artisan / tinker / SQL での補正手順を前提にしないこと。

修正提案: runbook に「手動 UPDATE ではなく migration による一回限りの backfill」と明記する。

[Suggestion]  
新しい console command が Stripe API を読むため、テストでは必ず gateway fake 経由にする方針は妥当。`Http::fake` の話ではなく Stripe SDK / gateway 層の fake で閉じることをテスト方針に明記するとよい。

## 3. 実現可能性

[Warning]  
`past_due_since` の writer を `SubscriptionService::applySubscriptionSnapshot()` に閉じる方針は妥当だが、日次 reconcile からも同じ snapshot 経路を通すには、Stripe から取得した Subscription を既存 webhook と同じ DTO / snapshot 形式へ正規化する必要がある。ここが曖昧だと、reconcile 側で列を直接更新する抜け道が生まれる。

修正提案: `StripeSubscriptionSnapshotData` のような既存または新規 DTO を単一入力にし、webhook / reconcile / fake の全てがそれを渡す設計に固定する。

[Warning]  
`has_payment_method=false` から `true` への単調修復だけでは、「PM 削除 webhook を落として true のまま残る」方向は収束しない。設計本文では「取りこぼしの両方向の回復」と主張しているため、効果の主張と実装対象がずれている。

修正提案: どちらかに寄せる。  
- 両方向回復を主張するなら、Stripe 側の現在値を正として `has_payment_method` も true/false 両方向に同期する。  
- 単調 true のみにするなら、「PM 登録取りこぼしによる誤遮断だけを修復し、PM 削除取りこぼしは対象外」と明記する。

## 4. 期待効果の妥当性

[Warning]  
webhook 欠落時に reconcile が初めて `past_due` を観測すると、`past_due_since` は「実際の支払い失敗日」ではなく「reconcile 観測日」になる。通常は最大 1 日程度のズレで済むが、reconcile 未稼働・長期障害・初回導入時には猶予が実態より延びる。

修正提案: 期待効果を「翌日以降に収束する」ではなく、「観測日を起点に少なくとも無期限利用を止める」に弱める。可能なら Stripe の `latest_invoice` / payment failure 情報から安全に起点を復元できるかを別途検証項目にする。

[Warning]  
AG-035 (5) は「支払い失敗・残高切れ時にどこまで使わせるかの猶予」を標準形に持つ、という内容だが、本設計では残高切れ猶予をスコープ外としている。前払いチケットなので即拒否という判断自体は合理的だが、AG-035 (5) の充足として扱うには「残高切れの標準形は猶予なし」という明示が必要。

修正提案: `PaymentGracePolicy` とは別に、設計または architecture doc に「ticket balance exhaustion policy: graceなし、予約時点で即拒否」を標準形として記録する。

## 5. リスク

[Critical]  
猶予切れ後に `BillingAccess::state()` が `free_plan_code='personal'` を見て `ActiveFreePlan` に落ちる穴は重大。設計で塞ぐ方針は正しいが、条件が「past_due / paused の間」だけだと不足する可能性がある。`unpaid` や `incomplete_expired` など、Stripe 側では有償契約の失敗・終了寄りだがアプリ上は無料枠に落としてよいのか、状態ごとの意味を固定しないと回帰しやすい。

修正提案: `SubscriptionState` ごとに「無料枠読み替え可 / 不可」を明示する policy メソッドを置く。少なくとも支払い失敗・猶予・未決済の状態は free plan fallback 不可にするテーブル駆動テストを追加する。

[Warning]  
二重契約防止を `OnboardingController` 側に寄せると、別経路から checkout を開始する実装が追加されたときに漏れる。本文では service 層 guard と書いているので、実装方針 #10 の controller 変更は補助に留めるべき。

修正提案: 新規契約作成の拒否は checkout service / subscription service の最下流で必ず実施し、controller は遷移先選択だけにする。

## 6. スコープの適切さ

[Warning]  
課金状態、entitlement、Stripe reconcile、onboarding、free plan fallback、migration、architecture test、runbook まで触るためスコープは大きい。ただし AG-035 (5)(6) の欠落を閉じるには概ね必要な範囲。

修正提案: 実装順を分けるなら、先に `past_due_since` + `PaymentGracePolicy` + entitlement + tests、その後に reconcile、最後に checkout/free-plan 穴塞ぎではなく、穴塞ぎまで同一PRで完了させる。途中状態では課金回避が残るため。

## 7. 型安全性

[Warning]  
Stripe subscription 読み取りメソッドの戻り値が未定義。Cashier / Stripe SDK の生オブジェクトを service 層全体へ流すと PHPStan level 10 とテスト fake が重くなる。

修正提案: gateway interface は SDK オブジェクトではなく、アプリ用 DTO を返す形にする。例: `StripeSubscriptionSnapshotData` に `stripeId`, `status`, `hasPaymentMethod`, `observedAt` を持たせる。

[Suggestion]  
`EntitlementDeniedReason::PaymentGraceExpired` 追加は妥当。Inertia props や画面文言に露出する場合は、enum の文字列値を直接 UI に渡さず、既存の DTO / Resource / page props 変換層で扱うのがよい。