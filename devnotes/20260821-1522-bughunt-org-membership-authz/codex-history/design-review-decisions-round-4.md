# 対応マトリクス: design-review Round 4

**全体判定: APPROVED** (全 9 施策 APPROVE。Critical/Warning 残件なし)。

## [Suggestion] 施策1: PHPStan チェックの「$lockedUser は Assert::isInstanceOf」が提示コード (@var User) と不一致
- 判断: 対応する (非ブロッキングだが整合させる)
- 対応内容: PHPStan チェック行を「firstOrFail() の戻りを `/** @var User $lockedUser */` で確定 (既存 $locked 招待行 reload と同じ様式)。Eloquent generic 推論で User 確定なら追加 Assert 不要」に統一。実装時は実コードに合わせる。

以上で詳細設計フェーズの Codex 合議は APPROVED に到達。
