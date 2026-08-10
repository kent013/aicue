## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | REQUEST_CHANGES |
| A3 | APPROVE |
| B0 | REQUEST_CHANGES |
| B1 | APPROVE |
| B2 | APPROVE |
| B3 | APPROVE |
| B4 | REQUEST_CHANGES |
| B5 | APPROVE |
| B6 | REQUEST_CHANGES |
| B7 | APPROVE |
| B8 | REQUEST_CHANGES |
| C1a | APPROVE |
| C1b | REQUEST_CHANGES |
| C1c | APPROVE |
| C1d | REQUEST_CHANGES |
| C2a | APPROVE |
| C2b | APPROVE |
| C2c | APPROVE |
| C2d | APPROVE |
| C3a | APPROVE |
| C3b | APPROVE |

Round 1 の指摘は概ね適切に解消されています。残る問題は以下です。

## 指摘

### B0: 猶予日数の単一出典

[Critical] `addDaysNoOverflow()` は30暦日後を表す手段として不適切です。

Carbonのoverflow制御が必要なのは主に月・年など可変長単位の加減算です。日数加算に「上位単位を越えない」意味が適用されると、例えば月末をまたぐ30日が月末に丸められ、30日未満になる危険があります。またAGENTS.mdも月・年・四半期を対象としており、`addDays()` を禁止していません。

修正案:

```php
public static function purgeAfter(CarbonImmutable $requestedAt): CarbonImmutable
{
    return $requestedAt->addDays(self::days());
}
```

`AccountDeletionGraceConfigTest` の「`addDays(` 不使用」検査は削除し、次をbehavioralに固定してください。

- 2026-01-31から30日後
- うるう年の2月をまたぐ30日後
- DSTがあるtimezoneでも、要件が「暦日」なら期待するローカル時刻になること

`CarbonOverflowArithmeticGateTest` の既存母集団が日加算を誤検出しないことも確認対象です。

### A2: redaction記録列

[Warning] 「2列が同時に埋まる」という不変条件がアプリケーションテストだけでは完全には固定されません。

将来の別コマンドや直接更新で片側だけ書けます。監査証跡として扱うならDB制約が適切です。

修正案: PostgreSQLのCHECK制約をmigrationに追加してください。

```sql
CHECK (
  (stripe_customer_redacted_at IS NULL AND stripe_customer_redacted_id IS NULL)
  OR
  (stripe_customer_redacted_at IS NOT NULL AND stripe_customer_redacted_id IS NOT NULL)
)
```

migrationテストで片側だけのINSERT/UPDATEが拒否されることを固定します。

### B4: 凍結middleware

[Warning] `RecentAuthConfirm`、`RecentAuthStatus`、`RecentAuthPassword`と、`A ⊆ U`の契約が両立するか不明です。

設計では認証回復系routeは`auth + verified` group外だと説明しています。一方、これら3 routeをallowlist `A`へ入れ、すべて凍結middleware付き母集団`U`に含まれることを要求しています。実際にgroup外ならArchitecture gateが必ず失敗します。

修正案:

- 3 routeが`U`外ならenumから削除する
- `U`内なら現在のallowlistを維持し、その事実をroute gateで固定する

取消はrecent-auth不要なので、通常はenumから外す方が設計上自然です。

### B6: 通知・監査

[Critical] user削除時に送信しないという実装契約とコードが逆です。

```php
$notifiable->fresh() ?? $notifiable
```

`fresh()`が`null`なら、削除前の状態を持つシリアライズ済み`$notifiable`へフォールバックします。その状態が予約値と一致すればメールが送信されます。

修正案:

```php
$fresh = $notifiable->fresh();

if (! $fresh instanceof User) {
    return [];
}

$state = AccountDeletionStateDto::fromUser($fresh);

return $state->matches($this->requestedAt, $this->purgeAfter)
    ? ['mail']
    : [];
```

「執行済みuserのqueued notificationは送られない」テストも追加してください。

[Warning] 「配送はat-most-once」という記述は、再試行による重複を許容する契約と矛盾します。

外部メールサービスが受理した後、workerが完了記録前に停止すれば、retryで再送され得ます。

修正案: 「予約操作からのjob生成は最大1件。ただしjob実行・外部配送は重複し得るbest-effort」と記載してください。

これに伴い、`JobExecutionDedupInventoryTest`の`JobDedupGuarantee`分類も不正確です。job実行のdedupを保証していないため、既存gateが許す型付き例外・非保証分類へ登録するか、gate名とcaseの実契約に沿う分類を追加してください。永続状態遷移が保証するのは「二重POSTから二重dispatchしないこと」であり、「job実行のdedup」ではありません。

### C1b/C1d: null起算点の定義

[Warning] 「起算列がnullかつ古い行」の抽出条件が未定義です。

起算列が`null`なら、その列だけでは「古い」を判定できません。`processed_at`などがnullの未完了レコードを`failClosed`へ計上する際、全期間のnull行を数えるのか、`created_at <= threshold`を補助時計として使うのかが必要です。

修正案: targetごとに次の2つを型付きで定義してください。

- 正規の保持起算点
- 起算点null状態を異常として検出し始める補助列

例えば未処理webhookなら、`processed_at IS NULL AND created_at <= threshold`を`failClosed`とする形です。補助列もschema gateで実在確認し、境界テストを追加してください。継続中Subscriptionの`ends_at IS NULL`は正常な起算未到来なので、この異常検出対象から明示的に除外します。

## 補足

[Suggestion] `AccountDeletionStateDto::isDue()`は比較演算子よりCarbon APIの方が意図と型が明確です。

```php
return $this->purgeAfter !== null
    && $this->requestedAt !== null
    && $this->purgeAfter->lessThanOrEqualTo($now);
```

[Suggestion] `idempotency_key`の`source === null`表現と日時の正規化形式を固定してください。空文字との衝突を避けるため、例えば`source=null`という明示トークンとUTC日時を使い、同一groupの再実行で同じキーになることをテストすると堅実です。

## 全体判定

**CHANGES_REQUESTED**

特に修正必須なのは、B0の30日計算とB6の削除済みuserへの通知です。ほかのRound 1 Critical対応は、設計上解消されています。