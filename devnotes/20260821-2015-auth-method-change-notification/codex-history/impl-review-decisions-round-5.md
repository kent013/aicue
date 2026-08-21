# 対応マトリクス: impl-review Round 5 (最終)

## [判定] T110 diff の APPROVED / CHANGES_REQUESTED
- 判断: Codex が (a) を選び APPROVED。collector に関する Critical (規約 11 との衝突) は
  解消済みと最終確認され、監督裁定が明示的にスコープ外とした
  `PasswordCredentialService::afterPersist()` / `SocialAccountService::linkToUser()` の
  規約 11 適合可否は、今回の承認条件から切り出し「別 TODO」として扱うことに同意した。
- 根拠: Codex 自身の言葉 (Round 5 回答より):
  > 監督裁定が `PasswordCredentialService::afterPersist()` と
  > `SocialAccountService::linkToUser()` を明示的に T110 のスコープ外とした以上、
  > その既存パターンの規約 11 適合まで今回の承認条件にするのは適切ではありません。
  > collector に関する Critical は解消済みであり、今回の割当範囲に残るブロッカーはありません。
  ただし Codex は「この 2 経路が規約 11 準拠であると評価するわけではない」ことも明記し、
  影響する経路を明記した**別 TODO** として、人間の設計判断のもとで
  transactional outbox / 呼び出し構造の再設計 / 正式な適用除外のいずれかを検討すべきだと
  推奨した。
- 対応内容: **APPROVED として確定**。Codex が推奨した「別 TODO」の起票は本タスクの
  割当範囲外 (監督裁定の適用 + フルスイート green + Codex APPROVED + main へのマージ) の
  ため本セッションでは行わないが、最終報告 (StructuredOutput) で監督へ申し送りとして明記する。
