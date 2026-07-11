全体判定: **APPROVED**

## 再判定

- **施策4: APPROVE**
  - 招待行ロックにより同一招待の二重受諾を直列化。
  - `insertOrIgnore()` により別招待経由の同一org参加競合も原子的に処理。
  - `$joined === 0` 時に既存role/pivotを上書きしない契約も明確です。
  - 並行テストを無理に組まず、逐次テストとDBの一意制約で責務を分ける判断も妥当です。

- **施策1: APPROVE**
  - `orgRole === null` の先行判定により、stale pivotからEditor/Shooterへ誤分類されません。

## 軽微な確認

[Suggestion] 「受諾可能状態の再検証」というコメントに合わせ、既存の事前検証が期限切れも扱う場合は、ロック下でも`isExpired()`を確認すると契約が完全になります。

```php
if ($locked->isAccepted() || $locked->isRevoked() || $locked->isExpired()) {
    return;
}
```

これはRound 2の指摘を覆す問題ではなく、期限境界のTOCTOUを閉じる追加強化です。

**全施策: APPROVE**  
**全体判定: APPROVED**