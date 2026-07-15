# 対応マトリクス: impl-review Round 1 (item3)

全体判定: APPROVED (Critical/Warning 0 件)。Suggestion のみ。

## [Suggestion] UI 層コメント最小化
- 判断: 見送り。既に 1 行に短縮済み。導線意図の明示は保守性に寄与するため現状維持。

## [Suggestion] href 検証の pathname 比較をヘルパー化
- 判断: 見送り。1 箇所のみのため過度な抽象化は不要 (禁止事項#6)。

## [Suggestion] backend テスト名に接頭辞
- 判断: 見送り (許容)。既に「CTA 導線:」接頭辞で意図を明示済み。

## 設計差分の記録
- 設計は variant="secondary" と記載したが Button atom に secondary variant が無いため
  variant="ghost" で実装 (Codex も妥当と確認)。href の vitest は Inertia Link の絶対 URL 化に
  対応し pathname 比較へ変更。
