# 詳細設計レビュー Round 1 対応マトリクス (gpt-5.3-codex)

全体判定: **APPROVED**（Critical なし。Warning/Suggestion のみ）

| # | 分類 | 施策 | 指摘 | 判断 | 対応 |
|---|------|------|------|------|------|
| 1 | Warning | 3 | ready+no-document の意図的文言変更を PR 説明に明文化推奨 | 対応 | 詳細設計に「PR 本文へ 1 行明記」を追記（実装時反映） |
| 2 | Warning | 4 | published/rendering で CTA 消失の理由がユーザーに見えにくい | 見送り | 「生成済みのシナリオは編集画面で確認できます」で状態は伝わる。再解析不可の明示は文言肥大化・スコープ拡大になるため v1 では見送り（別 UX 改善候補）。設計は APPROVE 済み |
| 3 | Warning | 5 | `published + hasDocument=false` テストを追加すると再発耐性向上 | 対応 | テスト #7 (published/false) を追加。未生成文言非表示 + analyze-button 非表示を固定 |
| 4 | Suggestion | 5 | analyzing 中 CTA 非表示テストの追加 | 確認のみ | 既存 `AnalysisPanel.test.ts` L291 で固定済み。追加不要と明記 |
| 5 | Suggestion | 1・2 | map 2 分割・satisfies は妥当 | 維持 | 変更なし（設計肯定） |

論点回答（Codex）:
- 分岐順序 isScenarioEstablished 優先: 妥当（本質修正）。
- CTA を draft/ready 限定: 妥当（prohibition #8 非抵触）。
- map 2 分割: 過剰でなく適切（責務分離）。
- テスト 6 条件: 概ね十分。published+no-document 追加でより堅牢 → 対応済み（#7）。

結論: Round 1 で APPROVED。Warning#1/#3 を反映し詳細設計を確定。合議ループ終了。
