# 概念設計レビュー Round 4

Round 3 の残 Warning への対応を報告します。

## [Warning] 候補ゼロ時の 2 状態を UI が判別できない → 案内文を単一文に統一 (状態分岐廃止・新 prop なし)
分岐用の boolean prop は追加しません (分岐のためだけの prop は AGENTS.md 原則2 に反する)。案内文を単一文「アサインできる組織メンバーがいません。」に統一し、`canManageMembers` (既存 prop) が true のときのみ /manage/users への導線を併記します (未割当メンバーの招待・割当見直しのどちらにも有用)。これにより判別不能な 2 状態を UI が区別する必要自体がなくなります。

## [Suggestion] last-writer-wins の根拠 → 「upsert 結果を正とする」に修正
競合セマンティクスの根拠を「選択されたロールへの upsert を正しい結果と定義する (last-writer-wins をドメイン契約とする)」に書き換えました。「stale 窓が小さい」は補足に格下げ。

以上で残 Warning は解消したと考えます。承認可能でしょうか。
