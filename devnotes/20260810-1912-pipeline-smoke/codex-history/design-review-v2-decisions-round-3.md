# 対応マトリクス: design-review-v2 Round 3

## [Warning] 施策 2: 「改訂の記録」に「素の列 GROUP BY (= index に乗る)」の断定が残っている
- 判断: **対応する**
- 根拠: Round 2 で enum 側だけ直し、改訂の記録の修正が漏れていた。素の列であることから
  index が効くとは断定できない (指摘どおり)。
- 対応内容: 「**GROUP BY キーへの SQL 関数適用**・driver 差・UTC 日境界の注記とそのテストが
  まるごと消え、全軸が素の列 GROUP BY になる」へ差し替えた (index への言及を削除)。

## [Warning] 施策 2: 「SQL 関数をゼロにした」は COALESCE 導入後は文字どおり成立しない
- 判断: **対応する**
- 根拠: 指摘どおり。集計値側では `COUNT` / `SUM` / `COALESCE` を使う。
  意図は「GROUP BY キーへ適用する SQL 関数がゼロ」であり、表現が意図を超えていた。
- 対応内容: 該当 4 箇所 (改訂の記録 / enum docblock / 施策 2 リスク欄 / 最終確認表) を
  **「GROUP BY キーへ適用する SQL 関数がゼロ (集計値側では COUNT / SUM / COALESCE を使う)」**
  へ統一した。

## [Suggestion] 施策 6: `llmRecordingIncomplete()` の入力を required template に限定すべき
- 判断: **対応する** (指摘どおり。新しい引数も検査も足さない)
- 根拠: 対象外の template が混ざると `array_diff($succeeded, $attributed)` が
  本 smoke と無関係な行まで「不完全」と判定する。実害がある。
- 対応内容:
  1. `llm-evidence` 段の母集団定義に
     **`whereIn('prompt_template', [3 template])`** を明記した
     (「他の prompt が同 shard で走っても混ざらない」)
  2. 純関数の docblock に「呼び出し側の責務: required に限定した集合を渡すこと。
     クエリ側で母集団を絞るのが最小の対処であり、**追加の引数も検査も足さない**」と明記
  3. 導出表の前提にも「呼び出し側は同じ集合で `whereIn` している前提」と追記

## 全体
Round 3 の指摘はすべて文言の統一と 1 つの実装注意であり、機構の増減は無い。すべて受け入れた。
