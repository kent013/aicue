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
