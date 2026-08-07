## Round 2 レビュー

### S1: APPROVE

Round 1 指摘への対応は妥当です。Manual 専用例外を現時点で共通 namespace へ移さない判断にも問題ありません。

### S2: REQUEST_CHANGES

[Warning] `writeProgress()` の引数が `array<string, mixed>` では、将来 `status` や保護対象列を渡せてしまいます。今回導入する「進捗書き込みは状態遷移を行わない」という境界が型で閉じていません。

修正案: 用途別の引数に閉じるか、少なくとも array shape を指定してください。

```php
/**
 * @param array{
 *   step: string,
 *   progress: int,
 *   result_json?: array<string, mixed>
 * } $attributes
 */
```

より堅くするなら、`result_json` 保存用と通常の進捗更新を別 private メソッドにします。

[Suggestion] 「終端後の自前書き込みの禁止」としつつ `SourceDocument::extracted_json` を例外にしているため、規約名は「終端後のジョブ状態・進捗書き込みの禁止」の方が実態に合います。

### S3: APPROVE

条件付き UPDATE と behavioral test により、Round 1 の進捗書き戻し問題は閉じています。主契約と補助契約の分離も適切です。

### S4: REQUEST_CHANGES

[Critical] `PreflightCheckpoint` の契約と `stillPending()` の戻り型が矛盾しています。

S6 は再検証メソッドについて `void` を要求していますが、登録される `AutoRechargeService::stillPending()` は `bool` を返します。このままでは Architecture テストが必ず失敗します。

修正案は次のどちらかです。

- `PreflightCheckpoint` に期待する制御方式を型として持たせ、`throws/void` と `bool` の両方を明示的に扱う
- Billing 側も `void` + 専用例外に統一する

Manual と Billing を無理に統合しない方針を維持するなら、前者が自然です。例えば `PreflightControlFlow::Throws` / `ReturnsBoolean` を持たせ、Reflection 検査もそれに合わせます。

[Critical] 条件付き invoice attach が失敗した場合、`Canceled` 以外では新しく作成した invoice が放置されます。

```php
if ($attached !== 1) {
    $this->terminateInvoiceAfterOwnershipLost($attempt->refresh(), $invoiceId);
}
```

ところが後始末は `Canceled` の場合しか実行しません。invoice 作成中に別経路が `Failed` へ遷移した場合、その経路は invoice ID を知らないため終端できません。それにもかかわらず本ワーカーも `Failed` を理由に終端せず、未紐付け invoice が残ります。

修正案: 「DB attach に失敗した新規 invoice」と「既存 invoice の pay preflight 失敗」を分けてください。

- attach 失敗: 新規作成した `$invoiceId` を原則終端する
- pay 前の所有権喪失: status 別に `Canceled` のみ終端する

paid の可能性は gateway の状態検査で fail-closed に分類できます。少なくとも `Failed` を「既に終端済み」とみなせるのは、invoice ID が遷移側から見えていた場合だけです。

[Critical] `ExecuteAutoRechargeAttemptJob` の保証分類が、`GuaranteeEntry` の単一 mechanism では表現できていません。

現在の登録は `ConditionalStatusUpdate` ですが、付与はその UPDATE より先に行われます。反論のとおり付与自体は `recharge:{invoiceId}` の UNIQUE で守られるため、処理全体は安全になり得ます。しかし、それは以下の複合保証です。

- 台帳付与: `DatabaseUniqueConstraint`
- attempt 遷移: `ConditionalStatusUpdate`

`ConditionalStatusUpdate` の enum 定義には「0行更新なら後続を行わない」とあるため、現行登録は enum 自身の適用条件にも一致しません。

修正案: `GuaranteeEntry` を `non-empty-list<JobDedupGuarantee>` にするか、結果軸ごとの typed value object にしてください。単に rationale へ UNIQUE を書くだけでは、型付き目録が誤った分類を保持します。

[Warning] S4 のテスト手順が競合点と一致していません。

「invoice 作成の直前に canceled 化」すると preflight 1 で作成自体が止まり、作成後の条件付き UPDATE 0件をテストできません。

修正案: gateway fake の `createAutoRechargeInvoice()` 内で、invoice ID を返す直前に attempt を terminal 化するフックを設けてください。これにより次を正確に再現できます。

```text
preflight 1成功 → Stripe作成成功 → concurrent terminal化 → attach 0行
```

[Warning] `job_ownership_lost` を使う cleanup ログが、固定した最小7キーを満たしていません。`terminateInvoiceAfterOwnershipLost()` のログには `expected_status`、`actual_status`、`stage`、`external_call` がありません。

修正案: cleanup は別 event 名にするか、同じ event を使うなら最小7キーを揃えてください。現在のままでは「同じ event の全ログが同じ集計 schema」という説明と一致しません。

### S5: APPROVE

接続 pin によって比較先が無効になる問題は閉じています。なお、テスト計画の「上記3ケース」は実際には4ケースなので文言だけ修正してください。

### S6: REQUEST_CHANGES

[Critical] S4 のとおり、`void` 固定の Reflection gate と Billing の `bool` preflight が不整合です。型付き制御方式を導入して検査を分岐させる必要があります。

[Critical] `GuaranteeEntry::$mechanism` が単一値のため、auto-recharge の複合保証を正確に記述できません。目録の名前が「結果の一回性」である以上、rationale だけで補完せず、保証機構を型として複数登録できる構造にしてください。

[Warning] 次のループは意味がなく、sealed 検査にも寄与していません。

```php
foreach (QueuedJobPopulation::appPhpFiles() as $_) { /* app/ には無い */ }
```

全 `app/` ファイルを走査するコストだけが発生します。削除してください。

### S7: REQUEST_CHANGES

[Warning] 規約とテスト対応表は、S4/S6 の保証モデルを修正してから確定する必要があります。特に「preflight の再検証点が実在し void を返す」は Billing に適用できません。

修正案: 「登録された制御方式に一致する戻り型を持つ」など、S6 の型モデルに合わせて記述してください。

## 全体判定

**CHANGES_REQUESTED**

Round 1 の主要な状態汚染問題は適切に解消されています。また、`recordSuccessfulCharge()` の順序に対する反論は成立します。課金済み invoice の台帳付与を attempt 遷移より優先し、invoice 単位 UNIQUE で二重付与を防ぐ設計は合理的です。

残る問題は、その複合保証を S6 の型付き目録が正確に表現できていないこと、Billing preflight の `bool` と gate の `void` 契約が衝突していること、attach 失敗時の `Failed` invoice が未回収になることです。この3点を直せば承認可能です。