# 対応マトリクス: design-review Round 3 (APPROVED)

## [Suggestion] 「独自の型は増やさない」が不正確

- 判断: 対応する
- 根拠: テストファイル内に局所の `CollectedDeclarations` interface を 1 つ足すため、
  そのままだと記述が事実と食い違う。
- 対応内容: 施策 3 の波及変更を
  「テストファイル内に局所の `CollectedDeclarations` interface を 1 つ持つが、
  **アプリ側 (`resources/js`) の共有型は増やさない**」に直した。

## 判定

- 全体判定: **APPROVED** (施策 1〜7 すべて APPROVE)
- 実装完了の判定は、感度確認の記録 (`red-verification.md`) と全検証コマンドの Green をもって行う
