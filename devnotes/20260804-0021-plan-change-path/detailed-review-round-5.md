Round 4 の対応は妥当ですが、local projectionによるno-opに課金上のロジックエラーが残っています。

**施策別判定**

- A: **APPROVE**
- B: **REQUEST_CHANGES**
- C: **APPROVE**
- D: **APPROVE**
- E: **REQUEST_CHANGES**

**指摘**

- [Critical] 段4が `$org->plan_code === $plan->code` だけで成功を返しますが、設計自身が `organizations.plan_code` はwebhook遅延するprojectionであり、remote判定には使えないと定義しています。remoteは別Priceなのに「受付済み」と返す可能性があります。  
  修正案: local同一プランでもGatewayを呼び、remote Priceで `Applied` / `AlreadyOnTargetPrice` を決定してください。stale判定は「要求先が現在のlocal planと異なる場合だけ」行えば、同一プランをstaleで誤拒否する問題も回避できます。

```php
if ($org->plan_code !== $plan->code
    && $org->plan_code !== $expectedCurrentPlanCode) {
    throw new StalePlanChangeException(...);
}

// local同一でもremote確認のため必ずGatewayへ進む
return $this->gateway->swapSubscriptionPrices(...);
```

追加テスト:

- localは対象Price、remoteも対象Price → Gatewayが呼ばれ `AlreadyOnTargetPrice`
- localは対象Price、remoteは別Price → Gatewayが呼ばれ `Applied`
- localと要求先が異なり、期待値も不一致 → stale / Gateway 0回

- [Suggestion] `ChangePlanRequest::rules()` の直前コメントには、まだ「表示用currentPlanCode」と残っています。`planChangeExpectedPlanCode` へ修正してください。

**全体判定**

- **CHANGES_REQUESTED**