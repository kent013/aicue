# 対応マトリクス: impl-review Round 1

## [Warning] `processing={busyTakeId === deleteTargetId}` が両者 null 時に true になる
- 判断: 対応する
- 根拠: 現状 dialog が閉じている間は実害がないが、`open` 制御と独立に true になり得るのは将来の再利用・改修時の地雷。意図 (「削除対象があり、それが処理中」) を式で明示するのが安全。
- 対応内容: `processing={deleteTargetId !== null && busyTakeId === deleteTargetId}` へ変更。`deleteTargetId` が null (対象未確定) の間は必ず false になり、両者 null で誤って true になることがなくなる。Codex 提案の `deleteDialogOpen &&` は冗長 (対象未確定なら開かない前提だが、`deleteTargetId !== null` で十分に意図が閉じる) のため簡潔版を採用。

## [APPROVED] tests/js/.../TakeStrip.test.ts
- 判断: 対応不要
- 根拠: 4 系統網羅・`within(dialog)` スコープクエリ・型安全すべて APPROVED 判定。変更なし。
