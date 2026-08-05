# 対応マトリクス: design-review Round 2 (全体判定 APPROVED)

Round 2 で全 12 施策が APPROVE / 全体 APPROVED。残る Suggestion 3 件はすべて反映した。

## [Suggestion] `SecurityEventType::PasswordSet` のコメントが記録者と食い違う
- 判断: 対応する
- 対応内容: 「`PasswordSetupController` が直接記録」→「`PasswordCredentialService` が直接記録」に修正
  (記録は Service の `afterPersist()` が行うため)。

## [Suggestion] `afterPersist()` の best-effort 範囲が不正確
- 判断: 対応する
- 対応内容: best-effort なのは**監査記録と DB session 行削除**の 2 つ (どちらも内部で例外を握る) と限定し、
  `Auth::logoutOtherDevices()` は例外を捕捉しない (他デバイス失効は correctness 要求のため
  失敗を表面化させる。既存 `UpdateUserPassword` の挙動維持) ことを明記した。

## [Suggestion] 施策 11: 再認証待ちの間に名前入力が変わりうる
- 判断: 対応する
- 対応内容: **押下時点の名前をキャプチャ**して pending action に渡す仕様に確定
  (`const capturedName = trimmedName;` → `guard(() => startCeremonyAndPost(capturedName))`)。
  ユーザーが押したときに見えていた名前で登録される。
