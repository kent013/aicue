# 対応マトリクス: conceptual-review Round 5 (APPROVED)

全体判定: **APPROVED**。Critical / Warning はゼロ。
以下は判定に影響しない [Suggestion] 2 件で、どちらも取り込んだ。

## [Suggestion] 観点4: 新設テストは 2 本なので「既存 7 + 新設 1 = 8 本」は「既存 7 + 新設 2 = 9 本」

- 判断: **対応する**
- 根拠: 単純な数え間違い。Round 4 で `saver.test.ts` を追加したときに
  §期待効果 の数字を更新し忘れていた。
- 対応内容: 「`packages/cli` の 9 本のテスト (既存 7 + 新設 2 =
  `delete.test.ts` / `saver.test.ts`) が初めて CI で走る」に訂正した。

## [Suggestion] 観点5: `saver.test.ts` の失敗用 tmp ディレクトリを `finally` で確実に除去せよ

- 判断: **対応する**
- 根拠: 妥当かつ重要。`atomicWriteFile` の一時パスは
  `{path}.{process.pid}.tmp` で **pid 依存**であり、vitest は同一プロセスで
  複数テストファイルを走らせうる。除去し損ねると**後続テストの正常な保存まで
  巻き添えで失敗**し、原因の切り分けが難しいフレーク源になる。
- 対応内容: 施策 6 のテスト節に「後始末 (必須)」の注記を追加し、
  `finally` での確実な除去とその理由を明記した。

---

## 判定

**APPROVED (Round 5)**。Phase 2 (詳細設計) へ進む。
