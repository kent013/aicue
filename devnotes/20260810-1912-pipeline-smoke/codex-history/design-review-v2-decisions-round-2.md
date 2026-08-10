# 対応マトリクス: design-review-v2 Round 2

## [Warning] 施策 2: enum のコメントに「既存 index に乗る」という古い断定が残っている
- 判断: **対応する**
- 根拠: 指摘どおり、同じ設計内でリスク欄と主張が矛盾していた (修正漏れ)。
- 対応内容: enum の docblock を
  「素の列 GROUP BY とし SQL 関数による driver 差を持ち込まない。既存 index を使えるかは
  期間条件と実行計画に依存する (index 前提の設計にしない)」へ差し替えた。

## [Warning] 施策 10: classifier のケース 11・12 が同一入力で、導出ロジックを検証していない
- 判断: **対応する** (ただし配置は変更した。理由を下に書く)
- 根拠: 指摘は正しい。`classify()` へ手で `llmRecordingIncomplete = true` を渡すテストは、
  「実装が帰属欠落だけ見て template 欠落を見落とす」バグを**通してしまう**。
  原因の切り分けは classifier の責務ではなく、フラグを組み立てる側の責務である。
- 対応内容:
  1. **classifier の判定表からケース 12 を削除**し 15 行 → 14 行に減らした (指摘どおりテストが減った)
  2. `$llmRecordingIncomplete` の**導出を純関数として明文化**した:
     `SmokeFailureClassifier::llmRecordingIncomplete(required, succeeded, attributed): bool`。
     **新しいクラス・新しいファイルは作らない** (既存の classifier クラスに public static を 1 本足すだけ)。
     DB 読み出しはコマンド側に残し、本関数は**template 名の集合演算だけ**を行う
  3. 導出表を 5 行で追加した。**ケース 3 (帰属は正しいが template が 1 つ足りない → true)** が
     指摘どおりの回帰テストであり、ケース 2 (成功行 0 件 → false) が
     「#8 が #9 を食わない」ことの負のコントロールである

### 反論を含む点: 「`PipelineSmokeCommandTest` に focused test を足す」案は採らない
- 根拠: `llm-evidence` 段へ到達するには fail-secure 4 条件 (`bughunt.local` / bug-hunt DB) を
  満たしたうえで `analysis` 段を成功させる必要があり、**実 LLM と worker を要求する**。
  テストレーンでは段へ到達できないため、DB に成功行を仕込んでも判定部分は走らない。
  Codex の指摘の**主旨 (導出ロジックを実入力から固定せよ)** は上記 2 で満たしている。
  設計にもこの理由を明記した (「なぜ全段を fake で通すテストを書かないか」の節)。

## [Suggestion] 削減余地: enum / DTO 2 本 / service / command の統合は不要
- 判断: **そのまま維持** (Codex も「過剰ではない」と結論)
- 根拠: 各要素の「削れない理由」は施策 2 の表に明記済み。DTO は AGENTS.md の規約、
  enum は列名を SQL へ素通しさせない型境界、service は 1 実装 2 入口の置き場所。
