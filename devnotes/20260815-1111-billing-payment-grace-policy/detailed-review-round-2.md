## 再レビュー結果

Round 1 の指摘は、いずれも設計上は適切に反映されています。特に mapper の共通化と差分判定の拡張は、元の問題を表面的でなく構造的に解消しています。

ただし、施策 10 と施策 11 に新たな Warning が各 1 件あります。

### 施策別判定

| 施策 | 判定 |
|---|---|
| 1. 猶予の起点列 | APPROVE |
| 2. 猶予日数設定 | APPROVE |
| 3. PaymentGracePolicy | APPROVE |
| 4. 支払い未解決状態 | APPROVE |
| 5. 猶予起点の打刻 | APPROVE |
| 6. entitlement 否定 | APPROVE |
| 7. 無料枠すり抜け防止 | APPROVE |
| 8. 新規契約拒否 | APPROVE |
| 9. Stripe 状態読み取り | APPROVE |
| 10. 日次突き合わせ | REQUEST_CHANGES |
| 11. Architecture テスト | REQUEST_CHANGES |
| 12. ドキュメント | APPROVE |

### 施策 6: APPROVE

Round 1 の露出範囲の矛盾は解消されています。

[Suggestion] `EntitlementReasonExposureTest` は「現在は非露出」という設計判断を固定するテストなので、将来意図的に露出するときには単純に参照を追加するだけでなく、このテストを削除または契約変更する必要があります。その意図をテストコメントに一文残すと、機械検査が単なる禁止規則として誤解されません。

### 施策 9: APPROVE

共通 mapper への抽出は Round 1 の指摘を満たしています。

webhook と gateway が同じ配列写像を使うため、`current_period_end`、item 選択、`endsAt` の優先順位が経路間で分岐する問題は構造的に解消されています。`observePaymentMethod()` が `null` を返した場合に、webhook 側で `false` を渡しても `recordPaymentMethodSnapshot()` が true 方向にしか更新しないため、現行の単調更新契約も維持されます。

既存 webhook の回帰テストに加え、mapper の境界値を個別に固定する計画も十分です。

### 施策 10: REQUEST_CHANGES

Round 1 の null fatal と差分列不足は解消されています。`timesDiffer()` の秒精度比較、`currentPeriodEnd=null` の非比較も、writer の規則と一致しています。

[Warning] 実行時間上限を chunk 開始時にしか検査しないため、「ロック期限を跨がない」という保証が成立しません。

1 chunk は最大 100 回の外部 API 呼び出しを含みます。chunk 開始時点で残り時間が少ない場合や Stripe 応答が遅い場合、同じ chunk 内で 600 秒を超え、さらに 900 秒のロック TTL を超えて処理を続ける可能性があります。ロックが失効すると、2 本目のプロセスが同時実行できます。

修正案:

- `foreach` の各契約を処理する前にも deadline を確認し、超過時は `$timedOut = true` として chunk を終了する。
- クロージャから外側の `chunkById()` まで停止を伝播できる制御にする。
- 「各 API 呼び出しの timeout × CHUNK_SIZE が残りロック時間未満」という暗黙前提には依存しない。
- テストは「2 chunk 目に入らない」だけでなく、同一 chunk の途中で deadline を超えた場合に残り契約を照会せず FAILURE になるケースを固定する。

概念的には次の位置で検査が必要です。

```php
foreach ($subs as $sub) {
    if (CarbonImmutable::now()->greaterThan($deadline)) {
        $timedOut = true;

        return false;
    }

    // Stripe lookup
}
```

なお、差分判定の拡張による過剰更新・見落としは見当たりません。`applySubscriptionSnapshot()` が null で上書きする列と維持する列の区別にも整合しています。

### 施策 11: REQUEST_CHANGES

Round 1 の cast 誤検出は解消されていますが、新しい exemption が実際の書き込みも許可できます。

[Warning] 行が `'past_due_since' => 'datetime',` に完全一致すれば、その行が `casts()` 内かどうかを確認せず免除されます。

例えば model 内に次の書き込みが追加されても通過します。

```php
$model->forceFill([
    'past_due_since' => 'datetime',
]);
```

値として現実的でないため発生確率は低いものの、「model 内の将来の直書きを検出する」という保証を設計どおりには満たしていません。

修正案:

- `Subscription.php` の `casts()` メソッド範囲内にある場合だけ cast 行を免除する。
- 可能なら `token_get_all()` などでメソッド範囲を識別する。
- 最低限、行判定関数に「cast と同じ文字列が `forceFill()` 内にある fixture」を渡し、違反になる負のコントロールを追加する。

現在予定されている `CarbonImmutable::now()` の負のコントロールだけでは、この exemption の過剰許可を検出できません。

### DESIGN / Atomic Design

該当なしです。今回の変更に UI コンポーネント、Inertia props、Svelte の import graph の変更はありません。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の Critical 2 件と Warning 3 件は解消済みです。残件は、施策 10 の chunk 内 deadline 検査と、施策 11 の cast exemption を `casts()` の文脈に限定することの2点です。いずれも設計の局所修正で対応でき、課金ポリシー全体や mapper 方針の再検討は不要です。