## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 | APPROVE |
| A2 | APPROVE |
| A3 | APPROVE |
| B0 | APPROVE |
| B1 | REQUEST_CHANGES |
| B2 | REQUEST_CHANGES |
| B3 | APPROVE |
| B4 | REQUEST_CHANGES |
| B5 | REQUEST_CHANGES |
| B6 | APPROVE |
| B7 | APPROVE |
| B8 | APPROVE |
| C1a | APPROVE |
| C1b | APPROVE |
| C1c | APPROVE |
| C1d | APPROVE |
| C2a | APPROVE |
| C2b | APPROVE |
| C2c | APPROVE |
| C2d | APPROVE |
| C3a | APPROVE |
| C3b | APPROVE |

Round 2の指摘はすべて適切に解消されています。残る変更要求は、予約列の整合性と凍結中に許可する操作の方向制約です。

## 指摘

### B1/B2: 予約列の整合性

[Warning] `users`の予約列には、A2と同種のDB制約がありません。

現在の契約では片列状態を「非正規」としてバッチが検出しますが、HTTP側では次の挙動になります。

- `isPending()`は`false`
- 凍結middlewareを通過する
- `cancelAccountDeletion()`はno-opになり、非正規状態を解消しない
- 日次バッチは毎日FAILUREになる

つまり検出はできますが、状態機械はfail-closedになっていません。

修正案: migrationにCHECK制約を追加してください。

```sql
CHECK (
  (deletion_requested_at IS NULL AND deletion_purge_after IS NULL)
  OR
  (deletion_requested_at IS NOT NULL AND deletion_purge_after IS NOT NULL)
)
```

併せて以下をテストします。

- 片列だけのINSERT/UPDATEをDBが拒否する
- `deletion_purge_after >= deletion_requested_at`
- migration前から非正規データが存在しないことを、非破壊のprecondition検査で確認する

B5の非正規行検出は、DB破損や制約無効化に対するdefense-in-depthとして残して構いません。

### B4: auto-recharge更新route

[Critical] `billing.auto-recharge.update`をroute単位で許可すると、凍結中でも自動チャージを有効化・増額できる可能性があります。

設計上の目的は「退会ブロッカーの解消」ですが、同じ更新endpointが有効化、閾値変更、購入設定変更も受けるなら、allowlistが新しい課金責務を作る入口になります。これはdeny-by-defaultの凍結契約と矛盾します。

修正案は次のいずれかです。

- 凍結中は自動チャージの無効化だけを許す専用routeを設け、そちらだけallowlistへ登録する
- 既存controller/serviceで予約状態を検査し、凍結中は「有効→無効」の遷移だけ許可する
- 既存routeが既に無効化専用なら、その入力契約と認可を設計書に明記する

最低限、次のbehavioralテストが必要です。

- 凍結中に自動チャージを無効化できる
- 凍結中に有効化できない
- 凍結中に閾値・金額を増やせない
- リクエストpayloadの細工でも方向制約を迂回できない

[Warning] `billing.portal`にも同じ方向性リスクがあります。

Stripe Billing Portalで新規契約、再開、プラン変更などが可能な設定なら、凍結中に新しい課金責務を作れます。

修正案: 退会ブロッカー解消用のPortal Configurationを明示し、キャンセルなど縮小操作だけを許可してください。既存設定が既にその契約なら、設定IDの解決元と「再開・upgrade不可」のテストまたは運用不変条件を設計へ追加します。

### B5: 非正規行検出の実装境界

[Warning] 本文では非正規行を`unexpected`へ計上するとありますが、提示された`handle()`コードにはそのクエリと加算処理がありません。

このまま実装者がコード例を正本として実装すると、テスト計画と処理が食い違います。

修正案: due走査より前に、XOR条件で件数を取得する処理を明記してください。

```php
$invalidStateCount = User::query()
    ->where(function (Builder $query): void {
        $query
            ->whereNull('deletion_requested_at')
            ->whereNotNull('deletion_purge_after');
    })
    ->orWhere(function (Builder $query): void {
        $query
            ->whereNotNull('deletion_requested_at')
            ->whereNull('deletion_purge_after');
    })
    ->count();
```

0件でなければ件数だけをreportし、`$unexpected += $invalidStateCount`とします。個々のuserをログへ出さない契約も維持できます。

## 補足

[Suggestion] `deletion_purge_after >= deletion_requested_at`もDB制約に含めると、両列non-nullだが期限が予約時刻より前という別の非正規状態を防げます。

[Suggestion] C1bの表では`TicketLedgerEntry`が2行に分かれているため、起算点・補助時計・方式を1行に統合すると実装時の読み違いを防げます。設計内容自体に問題はありません。

## 全体判定

**CHANGES_REQUESTED**

B0、B6、C1、C2、C3を含むRound 2対応は妥当です。B4の許可routeが「責務を減らす方向だけ」に制限されていることを固定し、予約列のDB不変条件を追加すれば、詳細設計として承認可能な状態です。