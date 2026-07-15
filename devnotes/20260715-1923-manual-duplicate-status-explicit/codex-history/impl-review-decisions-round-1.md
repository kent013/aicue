# 対応マトリクス: impl-review Round 1 (item2)

## [Critical] ManualDuplicateTest の Reflection 文字列検査は brittle な実装詳細固定テスト
- 判断: 対応 (手法を差し替え)
- 根拠: 指摘は妥当 (改行/整形/一時変数抽出で壊れる)。ただし fail-first 契約自体は必要
  (振る舞いだけでは DB default で pass するため = 詳細設計レビューで Codex 自身が要求した点)。
- 対応内容: Feature テストの Reflection 文字列検査を**削除**し、代わりに
  ScenarioWritePathInventoryTest の既存 "degenerate PASS 防止" パターンに倣った
  **token ベースの契約テスト**を追加。`ScenarioWritePathScanner::containsStatusWrite(
  VideoManualService source)` が true であることを要求 = 明示 status write の実在を保証し、
  明示代入を消すと fail (fail-first)。token 走査なので整形に頑健で、既存
  adopted_take_id degenerate 防止テストと同型・同ファイルに配置 (実装詳細固定でなく inventory 契約)。

## [Warning] Webmozart\Assert 導入はテストで過剰
- 判断: 対応 (Reflection テスト撤去に伴い Assert / VideoManualService import も削除)

## [Warning] status を ->value に揃える (enum cast 依存)
- 判断: 反論 (詳細設計レビューで既に合意済み)
- 根拠: コードベースの status 書き込みは全て enum インスタンス (ScenarioService/RenderJobService/
  AnalysisJobService に `->value` は 0 件)。`'status' => VideoManualStatus::class` cast 済みで
  enum を forceFill するのが canonical。詳細設計レビュー Round 2 で Codex がこの反論を受理済み。

## [Suggestion] T066 番号を 1 箇所に寄せる / docblock を inventory 側へ寄せる
- 判断: 見送り (許容範囲)
- 根拠: allowlist コメントと inventory docblock の双方に T066/理由を書くのは監査性向上のため意図的。
  過度な集約はかえって参照性を下げる。現状維持。
