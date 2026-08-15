## 再レビュー結果

Round 3 の指摘は実装可能な形まで反映されています。ただし、施策10の安全余白式には、再試行時のバックオフ待機が含まれていないという新たな不整合が1件あります。

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
| 11. Architecture テスト | APPROVE |
| 12. ドキュメント | APPROVE |

### 施策 10: REQUEST_CHANGES

契約ごとのdeadline検査、soft limitの明記、単一出典のtimeoutを使った安全余白という修正方針は妥当です。現行値では再試行が0回なので、`625 < 900` の評価も成立します。

[Warning] 安全余白の式は、`STRIPE_MAX_NETWORK_RETRIES > 0`になった場合の再試行バックオフ時間を含んでいません。

また、「再試行回数を緩めてもテストが赤くなる」とありますが、例えば0回から1回へ変更しても、現在の式では次のようにテストが通ります。

```text
600 + (5 + 20) × 2 = 650 < 900
```

Stripe SDKが再試行間に待機する場合、実際の最大経過時間はこの式より長くなります。したがって、式が保証する内容とテストの説明が一致していません。

修正案は、現行要件に合わせて再試行0回を明示的な前提として固定するのが最も単純です。

```php
expect(ExternalClientTimeouts::STRIPE_MAX_NETWORK_RETRIES)->toBe(0);

expect(
    self::TIME_BUDGET_SECONDS
    + ExternalClientTimeouts::STRIPE_CONNECT_TIMEOUT_SECONDS
    + ExternalClientTimeouts::STRIPE_TIMEOUT_SECONDS
)->toBeLessThan(self::LOCK_SECONDS);
```

再試行を将来許可する必要が生じた時点で、SDKの最大バックオフ待機を含む上限式へ契約を変更します。現時点で将来の再試行モデルまで一般化する必要はありません。

なお、DBロック待ちなどAPI照会後の処理時間まで含む絶対的なTTL保証ではないため、コメントは「Stripe照会による待機ではロック期限を跨がない」と保証範囲を限定すると正確です。

### 施策 11: APPROVE

`getPathname()`への統一により、PHPStan level 10での`string|false`問題は解消されています。

cast免除も以下の条件に限定されており、過剰許可はありません。

- トークン解析で特定した`casts()`本体内
- 宣言が`'past_due_since' => 'datetime',`と完全一致
- 同じ文字列が`casts()`外にあれば違反

負のコントロール数の記述も3本で統一されています。保証範囲をファイル粒度と明記している点も適切です。

### DESIGN / Atomic Design

該当なしです。UI、Inertia props、Svelteコンポーネントの変更はありません。

## 全体判定

**CHANGES_REQUESTED**

施策11を含むRound 3の指摘は解消済みです。残件は施策10のみで、再試行を0回に固定した安全余白テストへ修正し、保証範囲をStripe照会時間に限定すれば承認可能です。