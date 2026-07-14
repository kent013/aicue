# 対応マトリクス: conceptual-review Round 3

## [Warning] 候補ゼロ時の 2 状態を現行 props で UI が判別できない
- 判断: 対応する (案内文を単一文に統一し、状態分岐を廃止)
- 根拠: Codex 提示の 2 案 (boolean prop 追加 / 案内文統一) のうち、prop 追加は分岐のためだけの新規 prop で AGENTS.md 原則2 に反する。単一文統一が最小。/manage/users 導線は「未割当メンバーを増やす」「割当を見直す」どちらの状況でも有用で、既存の `canManageMembers` prop だけでゲートできる。
- 対応内容: 候補 0 のとき案内文は単一文「アサインできる組織メンバーがいません。」に統一し、`canManageMembers` が true のときのみ /manage/users への導線を併記する (状態判別 prop は追加しない)。

## [Suggestion] last-writer-wins の根拠を「stale 窓が小さい」でなく「upsert 結果を正とする」に
- 判断: 対応する
- 対応内容: 概念設計の競合セマンティクスを「競合時も、選択されたロールへの upsert を**正しい結果と定義する** (last-writer-wins をドメイン契約とする)」に書き換える。stale 窓の話は補足に格下げ。

## [Suggestion] 型安全性 (boolean prop 追加時の固定)
- 判断: 該当なし (新 prop を追加しないため不要)
- 対応内容: prop 追加をやめたので Controller 返却型・Svelte Props・権限別 assertion の追加固定は不要。既存 `canManageMembers` を流用。
