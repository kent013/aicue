[Suggestion] [docs/architecture.md](/workspace/.claude/worktrees/tasks/T137/docs/architecture.md:251) の D2 表示がまだ `DB::afterCommit()` のままです。実装とテスト失敗メッセージは「静的 `::afterCommit()` 全般」に揃っていますが、canonical な設計表だけ狭い表現が残っています。同じ箇所の「次の 5 種」も D6 追加後は「6 種」です。

D6 自体は解消しています。`ShouldDispatchAfterCommit` を `app/` 全クラスの超集合で見る実装、0 件 pin、負のコントロール、母集団テストまで入っており、前回の欠落は閉じています。D2 も検出器とテスト表示は安全側に揃っています。

APPROVED