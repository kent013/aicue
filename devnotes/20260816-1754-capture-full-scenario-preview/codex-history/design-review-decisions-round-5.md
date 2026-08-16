# 対応マトリクス: design-review Round 5 (最終)

## 全体判定
**APPROVED** (Critical / Warning ともに残件なし)。S1〜S8 すべて APPROVE。

## [Suggestion] S5: `play()` の rejection でも呼び出し時点の世代を closure へ退避する
- 判断: 対応する (設計へ反映済み)
- 根拠: `catch` の中で `slotGeneration[slot]` を読み直すと、`assignmentId` による要素再生成の後は
  **新しい世代**を読みうる。世代検査の目的 (古い非同期結果を捨てる) が、その 1 箇所だけ抜ける。
- 対応内容: 実装仕様の `play()` 行に
  「**呼び出し時点の `generation` を closure へ退避してから `play()` する**。
  退避した世代が `null` なら何も送らない」を追加した。
  既に計画済みの「旧クリップの遅延 reject が新クリップを壊さない」テストがこの実装条件を固定する。

## 実装フェーズへの申し送り
- テストファーストで各段 (S1 → S8) を閉じる。
- 完了条件は全検証レーン: `composer test` / `composer phpstan` / `vendor/bin/pint --test` /
  `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`。
- S5 / S6 は `pnpm build` を**個別に**通す (typecheck を通っても build で落ちることがあるため)。
