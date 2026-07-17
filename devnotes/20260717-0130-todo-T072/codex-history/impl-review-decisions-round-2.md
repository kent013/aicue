# 対応マトリクス: impl-review Round 2（CHANGES_REQUESTED / Warning 1）

## [Warning] webhook 側の `claimSignupGrantMarker()` + `grantSignupGrant()` が同一 tx / org 行ロック下でない
- 判断: **対応する**（**指摘が正しく、実害があった**。Round 1 の私の修正が新しい穴を作っていた）
- 事実確認（自分で検証）:
  - `claimSignupGrantMarker()` は**素の条件付き UPDATE**で、自前の tx / ロックを持たない
    （`activate()` 側は `activateWithinTransaction()` が `DB::transaction` + `lockForUpdate` で包んでいるから安全だった）。
  - webhook の `DB::transaction`（L120）は**冪等記録の獲得 `claim()` だけ**を包んでおり、
    `grantMonthlyTickets()` は **tx の外**（L178 の dispatch）で走る。
  - つまり **marker の UPDATE が単独 commit され、その後 `grantSignupGrant()` が失敗すると
    marker だけ残って付与が永久に失われる**（marker が真実源なので再送でも二度と付与されない）。
- 対応: webhook の claim+grant を **org 行ロック下の単一 transaction** に閉じた
  （`DB::transaction` + `Organization::query()->lockForUpdate()->findOrFail()` = `activate()` と同一パターン）。
- **回帰テストを追加**: 「paid webhook: 付与が失敗したら marker も rollback される
  （marker だけ残って付与が永久に失われない）」。
- **負のコントロールで検証済み**: tx を外すと当該テストが **fail**（marker が残る）/ 戻すと pass。
