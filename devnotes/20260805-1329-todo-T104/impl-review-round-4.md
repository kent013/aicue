## Critical

### `scripts/audit-gate.ts`

fallback accept-risk 経路は正しく塞がれました。ただし「ID 欠損 advisory を無条件 fail」というコメントと実装が一致していません。

```ts
if (adv.severity !== "high" && adv.severity !== "critical") continue;

if (adv.id.trim().length === 0) {
```

ID 検査より先に severity で除外しているため、次の malformed entry は fail しません。

```json
{"advisories":[{"error":"boom","severity":"moderate"}]}
```

normalizer が明示された `moderate` を維持する場合、ID 欠損でも step 4 を通過します。取得結果の破損は severity policy とは別の異常なので、ID 欠損検査を severity filter より前へ移す必要があります。

```ts
for (const adv of advisories) {
    if (adv.id.trim().length === 0) {
        failures.push(...);
        continue;
    }

    if (adv.severity !== "high" && adv.severity !== "critical") continue;
    // accepted 照合
}
```

### `scripts/audit-gate.test.ts`

追加された fallback キー統合テストは適切で、Round 3 の経路を正確に固定しています。

追加で、ID 欠損かつ明示 severity が `moderate` または `low` の advisory も `exitCode=1` になる負のコントロールが必要です。これにより「unknown severity → high」という別の防壁に依存せず、同定不能性そのものを固定できます。

## 全体判定

**CHANGES_REQUESTED**

fallback キーによる偽グリーンは解消しています。残る修正は、ID 欠損の無条件 fail を severity 分岐の外へ出すことです。