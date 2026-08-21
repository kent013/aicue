## 判定

前回の collector に関する Critical は解消しています。ただし、T110 全体には規約 11 違反が残るため、最終判定は承認できません。

### 1. collector に関する Critical

解消済みです。

パスキー削除では、次の構造になりました。

- `PasskeyDeleted` は `EnsureLoginMethodRemains` の業務トランザクション内で同期発火する。
- `NotifyAuthMethodChange::handlePasskeyDeleted()` はその場で `notify()` を呼ぶ。
- database queue、業務 DB と同じ接続、`after_commit=false` という強制済み前提により、`jobs` の INSERT も同じトランザクションに参加する。
- 後続 listener が失敗すれば、パスキー削除と queue 行が一緒に rollback される。
- collector、`notifyAfterCommit()`、D36 の免除登録はいずれも撤去されている。

したがって、規約 11 の

> 業務状態の保存とキュー投入は同一トランザクション内で行う

をパスキー削除経路では満たしています。また、独自の post-commit 機構にも `afterCommit` にも依存せず、免除も追加していないため、

> 免除機構を持たない

にも反していません。

`PasskeyPackageContractTest` の同期購読者 pin と、後続例外で削除・監査・通知 job がまとめて rollback される Feature テストは、この判断を直接裏付けています。

### 2. PasswordCredentialService / SocialAccountService

これは collector の解消可否とは独立した問題ですが、今回の diff のブロッカーです。新しい論点というより、Round 1 で既に指摘されていた残存論点です。

- [PasswordCredentialService.php](/workspace/.claude/worktrees/tasks/T110/app/Services/Auth/PasswordCredentialService.php:90) は、パスワード保存の commit 後に `afterPersist()` から通知を投入しています。パスワード保存と queue INSERT は同一トランザクション内ではありません。
- [SocialAccountService.php](/workspace/.claude/worktrees/tasks/T110/app/Services/Auth/SocialAccountService.php:94) は、連携行を保存した後、トランザクションを持たずに通知を投入しています。ここにも両者を包含する同一トランザクションがありません。

監督裁定は実装作業の範囲を限定できますが、それだけでは「免除機構を持たない」という規約の適用除外にはなりません。しかも通知呼び出し自体は今回の diff で追加されているため、単なる無関係な既存債務として扱うこともできません。

さらに、[architecture.md](/workspace/.claude/worktrees/tasks/T110/docs/architecture.md:3120) の対応表は `PasswordReset`、2FA 有効化・無効化、回復コード再発行、パスキー登録についても「transaction 内か: 否」と明記しています。この記述が正しいなら、これらも同じ理由で規約 11 を満たしません。外側の業務トランザクションが実際には存在するなら表を訂正し、その事実をテストで固定する必要があります。

collector 撤去についての追加 Warning/Suggestion はありません。

**CHANGES_REQUESTED**