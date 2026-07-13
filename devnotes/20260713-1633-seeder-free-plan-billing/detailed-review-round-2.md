## 施策1: Seeder 修正

判定: **APPROVE**

- `attachFakeActiveSubscription()` はメソッド単体で重複作成を防止できています。
- `currentPrice(Base)` による値ベース判定も、プラン名分岐禁止の規約に適合します。
- `sub_seed_` 維持も妥当です。
- 呼び出し対象が provision 直後の組織であるため、「既存の非active subscriptionを尊重して終了する」ケースも現スコープでは問題ありません。

## 施策2: Seeder テスト更新

判定: **REQUEST_CHANGES**

- [Warning] 「base Price 欠落時に施策2がfailする」という説明は成立しません。期待値側も同じ `currentPrice(Base)` から `$isPaid` を導出しているため、standard のPriceが欠落するとfree扱いになり、`plan_code === null` を期待してテストが通ります。

  修正案: PlanSeederの不変条件を独立したテストで固定してください。例えば、bootstrap対象のstandard Planについて `currentPrice(Base)` が存在することを明示的に検証します。これは本番コードのプラン名分岐ではなく、seed fixture仕様の検証なので「codeで能力分岐禁止」には抵触しません。

- 既存の誤ったアサーションの是正は「上書き禁止」に抵触しません。バグ挙動の固定を正しい不変条件へ置き換える変更です。

## 施策3: 課金アクセス回帰テスト

判定: **APPROVE**

- 修正前はfree組織に `plan_code` が設定されsubscriptionがないため、`BillingAccess` がfalseとなり `/projects` 到達テストがfailします。
- 修正後は `plan_code === null` によりfree tierとして通過するためpassします。
- ロールdataset、`assertOk()`、Inertia component検証の組み合わせも十分です。

## 全体判定

**CHANGES_REQUESTED**

残課題は「有償プランのcurrent base Price不変条件を、同じ判定式に依存しない独立テストで固定する」1点です。これを追加すれば **APPROVED** です。