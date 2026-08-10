## 施策別判定

| 施策 | 判定 |
|---|---|
| A1 / A2 / A3 | APPROVE |
| B0 / B1 / B2 / B3 | APPROVE |
| B4 / B5 / B6 / B7 / B8 | APPROVE |
| C1a / C1b / C1c / C1d | APPROVE |
| C2a / C2b / C2c / C2d | APPROVE |
| C3a / C3b | APPROVE |

Round 3のCritical/Warningはすべて解消されています。新たな変更必須事項はありません。

## 指摘

[Suggestion] B4が依存するPortal設定について、M29が本当に赤化することを実測してください。

`billing:ensure-portal-configuration --verify`が保証するのが「Stripe側設定と`PortalConfigurationSpec`の一致」だけの場合、spec自体を`subscription_update=true`へ変更すると、それを正しい設定として受け入れる可能性があります。

M29で既存テストが赤化するなら現設計のままで問題ありません。赤化しない場合のみ、`AccountDeletionFreezeRouteGateTest`へ次の前提検査を追加してください。

- `subscription_update.enabled === false`
- `subscription_cancel.enabled === true`
- `subscription_cancel.mode === 'at_period_end'`

これは新しい検証機構ではなく、`BillingPortal`をallowlistへ入れる前提のpinです。

[Suggestion] B5のdefense-in-depth検査に、時刻順序違反も含めるとCHECK制約2本と対称になります。

```php
->orWhereColumn('deletion_purge_after', '<', 'deletion_requested_at')
```

DB制約が通常経路を遮断するため必須ではありませんが、制約無効化時に早期削除候補へ入る異常も検知できます。

[Suggestion] C1bの表末尾に`TicketLedgerEntry`が重複し、2行目が5列構造になっていません。

次の重複行は削除してください。

```text
| TicketLedgerEntry | created_at (起算済み) | C2 の畳み込み |
```

直前の5列の行だけで契約は十分に表現されています。

## 全体判定

**APPROVED**

予約状態はDB制約で閉じられ、凍結中の課金操作は縮小方向に限定され、通知・保持期間・ledger畳み込みの契約とテスト計画も整合しています。M29を含むmutation実測を実装完了条件として維持してください。