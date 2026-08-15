# 課金ゲート反転 (P4) のデプロイ運用契約

> **対象**: `todo/T075` (決済 parity P4) を本番へ出すとき。**一度きりの移行**だが、
> 順序を誤ると**既存の無料利用組織を一時的に締め出す**ため、契約として明文化する。
> 設計: `devnotes/20260717-0035-aigenba-billing-parity/detailed-design.md` §P4。

## なぜ順序が問題になるか

反転前の判定は「`plan_code IS NULL` = 未契約 = 支払い不要の free tier として許可」だった。
反転後の `BillingAccess::state()` は **`plan_code` を一切見ない**ため、
この経路で許可されていた既存組織は `free_plan_code='personal'` を持たない限り遮断される。

backfill migration (`2026_07_17_000300_backfill_grandfathered_free_plan_code`) が
その集合を空にする。したがって:

```
backfill 完了 (残余 0 件)  →  新コード (ゲート反転) の活性化
```

**この順序が崩れると、backfill 前に反転コードが動いた時間だけ既存組織が遮断される。**

## 必須の順序 (非交渉)

1. `php artisan migrate --force` を**先に完走させる**
   - backfill は末尾で**残余 0 件検証**を行い、違反すれば `RuntimeException` を投げる
   - **throw したらデプロイを中断し、旧リリースを生かしたままにする**
2. migration が成功したことを確認してから、新コードへトラフィックを切り替える

> **重要**: 「migrate してから切り替える」は多くのデプロイ手順の既定だが、
> **本リポジトリにはデプロイ自動化が存在しない** (`deploy/` は空、CI に migrate ステップ無し)。
> よってこの順序は**現時点でコードでは強制されていない**。
> デプロイ手順を実装する際に、この順序を必ず組み込むこと。

## なぜ実行時ガードを入れないか

「backfill 完了マーカーを `BillingAccess` が確認し、未完了なら旧判定を維持する」実装も検討したが、
採らなかった。理由:

- **一度きりの移行のために、恒久的なコード経路へ実行時チェックを常設することになる**
  (思考原則 #2「今必要なものだけ作る」)
- そのチェック自体が後で**削除を要する後方互換の並走**になる (思考原則 #3)
- 判定経路が 2 本に増え、`BillingAccess` を「利用可否判定の単一経路」に保つ不変条件が濁る

代わりに **(a) migration の残余 0 件検証で fail-closed にする** ことと
**(b) 本 runbook で順序を契約として固定する** ことで対処する。

## 冪等性とロールバック

- backfill は `whereNull('free_plan_code')` ガードで**冪等**。再実行しても二重適用にならない
- `down()` は**意図的に no-op**。「どの org が migration 起因か」を識別できないため
  (declarer NULL は移行由来だが、それだけでは元が NULL だった保証にならない)
- ロールバックは「コード / config を revert する」運用手順で行う。
  旧コードは `free_plan_code` を見ないため、backfill が書いたデータは**無害に無視される**

## 反転後に成立していること

| | |
|---|---|
| 既存の無料利用組織 | `ActiveFreePlan` として許可される (締め出しゼロ) |
| `plan_code` 非 null の組織 | **結論は P4 前後で 1 ビットも変わらない** (`RequireActiveSubscriptionMiddlewareTest` が固定) |
| 新規に発生する未契約組織 | `NoSubscription` で遮断され、onboarding へ誘導される (= ゲート反転の実体) |
| 遮断時の着地 | `manageBilling` 保持者 → `onboarding.checkout` / 非保持者 → `onboarding.billing-required` |

## 支払い猶予の導入 (T163) の移行手順

支払い失敗の猶予期限化 (`subscriptions.past_due_since` + `PaymentGracePolicy`) は、
「past_due + カード有りは無期限に利用継続」から「猶予日数まで利用継続」への**挙動の反転**を含む。
影響を受けるのは支払い失敗が猶予日数以上続いた組織だけだが、移行の順序に契約がある。

### migration は 2 本を同じデプロイで流す (非交渉)

| 順 | migration | 役割 |
|---|---|---|
| 1 | `2026_08_15_000200_add_past_due_since_to_subscriptions` | 列追加 (nullable。既定 NULL) |
| 2 | `2026_08_15_000210_backfill_past_due_since_on_subscriptions` | 既存の past_due 行に**実行時刻**を打つ |

- backfill を流さないと既存の past_due 行は起点 NULL のままになる。起点 NULL は
  **遮断しない**側に倒すので締め出しは起きないが、猶予がいつまでも始まらない
  (日次突き合わせが `past_due` を再観測して打刻するまで動かない)。
- backfill が打つのは **migration 実行時刻**であり、実際に支払いが失敗した時刻ではない。
  よって**デプロイ直後は、既存の全 past_due 行の猶予がデプロイ時刻を起点に数え直される**。
  これは意図した設計で、遡って遮断すると告知なしに突然止まるため採らない。
- backfill は `whereNull('past_due_since')` ガードで**冪等**。`down()` は「どの行が
  migration 起因か」を識別できないため**意図的に no-op**。

### 手動 SQL / tinker で `past_due_since` を書かない (非交渉)

この列は遮断の期日を決める状態キーで、書込は `SubscriptionService` 1 ファイルに閉じている
(`PastDueSinceWriteInvariantTest` が機械固定)。手で書くと、猶予の起点が観測と無関係な値に
なり、日次突き合わせも「食い違い無し」と見て直さない。値がおかしいときは
**Stripe 側の状態を正した上で `billing:reconcile-subscription-status` を流す**。

### 猶予日数を変えるとき

`config/billing.php` の `payment_grace_days` (env `BILLING_PAYMENT_GRACE_DAYS`) だけを変える。
`0` は「観測した瞬間に止める」を意味する有効な値で、負値は設定不備として起動時ではなく
判定時に例外で落ちる。**日数を短くすると、既に猶予中の組織がその場で遮断されうる**
(起点は動かないため)。短縮は告知とセットで行う。

### 日次突き合わせを止めない

`billing:reconcile-subscription-status` が止まると、webhook を落とした契約は
**遮断も復旧もされないまま固まる**。監視対象は本コマンドの終了コードと `report()`
(詳細は `docs/architecture.md` §支払い失敗の猶予と Stripe 契約状態の突き合わせ)。
