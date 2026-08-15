## 再レビュー結果

Round 2 の指摘に対する方向性は妥当です。ただし、施策 10 のロック保証には単一 API 呼び出し分の穴が残り、施策 11 には PHPStan と文書整合の修正が必要です。

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

Round 2 の Suggestion は満たされています。非露出テストが恒久的な禁止ではなく、現在の設計契約を固定するものだと明記されました。将来の TypeScript union・表示テストへの波及も明確です。

### 施策 10: REQUEST_CHANGES

契約ごとの deadline 検査により、遅い呼び出しの累積で chunk 全体が無制限に伸びる問題は解消されています。

[Warning] deadline 検査後に開始した最後の Stripe API 呼び出しは、`TIME_BUDGET_SECONDS` を超えて実行できます。したがって、次の定数比較だけではロック期限を跨がないことを保証できません。

```php
TIME_BUDGET_SECONDS < LOCK_SECONDS
```

例えば開始から599秒時点で照会を始め、その1回が301秒を超えれば、900秒のロック TTL を跨ぎます。「1呼び出しの timeout に依存しない」という説明とも一致しません。

修正案:

- Stripe クライアントの最大 timeout を既存の pin から明示的に参照し、次を固定する。

```text
TIME_BUDGET_SECONDS + STRIPE_MAX_REQUEST_SECONDS < LOCK_SECONDS
```

- または deadline を「ロック失効時刻から最大1呼び出し時間を引いた時刻」として設定する。
- テストも単なる `600 < 900` ではなく、安全余白を含む関係を検証する。
- 保証を弱める場合は「実行時間上限は soft limit で、最大1回の照会時間だけ超過しうる」と明記する。ただし、その場合もロック TTL を跨がないための timeout 上限との関係は必要です。

[Suggestion] `TIME_BUDGET_SECONDS` のコメントがまだ「chunk の切れ目で超過を検査」となっています。実装に合わせて「各契約の照会前」に更新してください。また、テスト計画は「2ケース」と書きつつ3項目あるため表現を揃える必要があります。

### 施策 11: REQUEST_CHANGES

`casts()` のトークン範囲内だけを免除する設計により、Round 2 の過剰免除は解消されています。追加した負のコントロールも適切です。

[Warning] `$file->getRealPath()` は `string|false` を返し得ます。`castOnlyViolations()` が `string` を要求する場合、PHPStan level 10 で型エラーになる可能性があります。

修正案:

```php
foreach (castOnlyViolations($file->getPathname()) as $line) {
```

`getPathname()` は文字列を返すため、この用途では narrowing が不要です。`getRealPath()` を維持するなら、`Assert::string($path)` などで絞ってから渡してください。

[Suggestion] テスト計画に「負のコントロールを2本」と残っていますが、設計本文では3本です。「3本必ず置く」へ修正してください。

トークンで `casts()` の対応波括弧を求める方針自体は、文字列検索より堅牢で、今回の不変条件に対して過不足ありません。

### DESIGN / Atomic Design

該当なしです。UI、Inertia props、Svelteコンポーネントの変更はありません。

## 全体判定

**CHANGES_REQUESTED**

Round 2 の指摘は概ね解消されています。残件は、施策 10で単一Stripe照会時間を含めてロックTTLとの安全余白を保証することと、施策 11の`getRealPath()`型安全性およびテスト本数の記述修正です。