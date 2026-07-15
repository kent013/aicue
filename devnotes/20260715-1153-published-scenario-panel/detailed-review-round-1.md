以下、提示された「詳細設計」に対するレビュー結果です（提供テキストのみで評価）。

**施策1・2（`types/manual.ts` helper追加）: APPROVE**
- [Suggestion] `SCENARIO_ESTABLISHED_BY_STATUS` と `SCENARIO_ANALYZABLE_BY_STATUS` の分離は妥当です。  
  表示相（ユーザー認知）と操作適用可能状態（サーバ制約）は別概念で、将来の状態追加時も誤結合を防げます。
- [Suggestion] `satisfies Record<VideoManualStatus, boolean>` を両 map に使う方針は型安全性として非常に良いです。  
  `status` 追加時の漏れ検知が効くため、回帰防止に有効です。

**施策3（説明文分岐の順序変更）: APPROVE**
- [Suggestion] `isScenarioEstablished(status)` を `!hasDocument` より先に判定するのは本件バグの根治として正しいです。  
  特に `published + no-document` / `ready + no-document` で未生成文言へ落ちる誤りを防げます。
- [Warning] `ready+no-document` が意図的変更である点は妥当ですが、リリースノート/PR説明に「仕様明文化済み」で残すことを推奨。  
  修正案: PR本文に「確定相優先のため ready+no-document 表示文言をシナリオ有りへ統一」と1行追記。

**施策4（CTA表示を `isAnalyzable` に限定）: APPROVE**
- [Suggestion] prohibition #8 には抵触しません。  
  これは「未充足条件で disabled」ではなく「状態的に操作不可な相の非表示」で、設計意図は明確です。
- [Warning] UX上、`published/rendering` で CTA が消える理由がユーザーに見えにくい可能性があります。  
  修正案: 非表示のままでも、説明文で「この状態では再解析不可」を短く補足するか、既存文言で暗黙説明を明示化。

**施策5（テスト計画）: APPROVE（条件付き）**
- [Warning] 6条件は今回の主リスクには十分ですが、`published + hasDocument=false` を1ケース追加すると再発耐性が上がります。  
  修正案: `#7 published/false` で「未生成文言を含まない」「analyze-button 非表示」を固定。
- [Suggestion] 既存の `analyzing` 中 CTA 非表示テストが未固定なら、1ケース追加を推奨（現行仕様の不変条件として価値あり）。

**論点への回答**
- 分岐順序（`isScenarioEstablished` 優先）: **妥当**。本不具合に対する本質修正。  
- CTA を draft/ready 限定: **妥当**。prohibition #8 非抵触。  
- map を2つに分離: **過剰でなく適切**。責務分離として正解。  
- テスト6条件: **概ね十分**。ただし `published+no-document` を追加するとより堅牢。

**全体判定: APPROVED**  
（上記 Warning は品質向上提案であり、現設計を止める Critical はありません）