全体判定: **CHANGES_REQUESTED**

Round 1 の Critical 2件は解消されています。追加事実により、案2は案1より明確に重く、課金額・販売可否の不変条件も弱めるため、案1の採用判断は妥当です。ただし、採用案の課金・冪等性設計に新たな Critical があります。

## 1. 使命との整合性

[Suggestion] paid→paid の双方向変更を成功条件に限定したことで、North Star との距離感とスコープが明確になりました。案1は本質機能ではありませんが、quota による制作停止を利用者自身で解消する経路として合理的です。

## 2. 禁止事項違反

[Suggestion] 禁止事項への明確な違反はありません。CTA 活性維持、Inertia/redirect、Webhook writer 一本化、テスト方針はいずれも規約に沿っています。

## 3. 実現可能性

[Critical] `create_prorations` と「upgrade は即時差額請求」という説明が一致しません。Stripe では `create_prorations` は日割り項目を作りますが、即時請求は一部条件に限られます。即時請求を保証するのは `always_invoice` です。また、`payment_behavior` 未指定では支払い失敗時に subscription が `past_due` へ変わり得ます。[Stripe Subscription Update](https://docs.stripe.com/api/subscriptions/update)、[Stripe Prorations](https://docs.stripe.com/billing/subscriptions/prorations)

修正提案: 次のどちらを契約として採るか明記してください。

- 即時徴収するなら `always_invoice` とし、`pending_if_incomplete` など支払い失敗時の状態遷移・Webhook・利用者導線まで設計する。
- `create_prorations` を維持するなら、「即時差額請求」を削除し、「日割り調整額は原則として次回請求へ反映」に文言を合わせる。

## 4. 期待効果の妥当性

[Warning] 成功条件3の「Stripe に受理された」と「変更が確定した」が区別されていません。支払い失敗や pending update を採用する場合、API 成功だけでは変更完了とは限りません。

修正提案: `accepted / payment_action_required / applied / failed` のどこを成功扱いにするかを、採用する `payment_behavior` に合わせて定義してください。

## 5. リスク

[Critical] 冪等性キーに ABA 衝突があります。

`Starter → Standard → Starter → Standard` を同一請求期間内に行うと、3回目は1回目と同じ  
`change-plan:{stripe_id}:{period_end}:standard:swap`  
になります。Stripe のキー保持期間内なら、3回目の変更が新規処理されず、古いレスポンスが返る可能性があります。

修正提案: 「対象プラン」ではなく「論理的な変更試行」を識別する operation ID を導入してください。例えば、サーバ発行の attempt token を永続化して同一試行の再送だけ同じキーに収束させ、新しい変更操作には新しいキーを割り当てます。少なくとも ABA 往復を固定する Feature/Gateway テストが必要です。

[Warning] Fake の no-op と独立した Webhook payload 注入だけでは、swap が正しい subscription item、price、proration、idempotency key で呼ばれたことを証明できません。

修正提案: Fake は状態変更不要ですが、呼び出し引数を記録し、Gateway contract test で mutation 内容を検証してください。Webhook 同期テストとは責務を分けます。

## 6. スコープの適切さ

[Suggestion] 案2の却下と P0/P1 分割を採らない判断は、今回の追加調査で妥当になりました。product ID 管理、価格改定・販売停止との同期、drift 検知まで必要なら、Portal 再開放は暫定策ではありません。

upgrade-only を採らない判断も妥当です。swap 自体は双方向共通であり、片方向制限は追加ロジックと新しい行き止まりを生みます。

## 7. 型安全性

[Warning] DTO、Request、TypeScript props の構成は PHPStan level 10 と整合しますが、Gateway の戻り値契約が未定義です。支払い状態を扱う場合、Stripe SDK object や曖昧な配列を Service へ漏らすと型安全性が崩れます。

修正提案: `swapSubscriptionPrices()` は、採用した決済意味論を表す専用 result DTO を返すか、確定成功以外を型付き例外へ変換してください。Stripe object を Controller まで伝播させない設計にします。