Round 2 の実質的な残件は解消されています。focused test を `PipelineSmokeCommandTest` に置かず、集合演算を純関数として直接テストする判断も主旨を満たしています。

ただし、index と SQL 関数について古い表現が2箇所残っています。

## 施策 1: APPROVE

変更ありません。帰属の型・構造・実地確認の境界は一貫しています。

## 施策 2: REQUEST_CHANGES

[Warning] enumのコメントは修正されていますが、「改訂の記録」に古い断定が残っています。

> 全軸が素の列 GROUP BY (= index に乗る) になる

素の列であることから「index に乗る」とは断定できません。次のように修正してください。

> 全軸が素の列 GROUP BY になり、GROUP BY キーへの SQL 関数適用がなくなる

[Warning] 最終確認の次の記述も、COALESCE導入後は文字どおりには成立しません。

> SQL 関数をゼロにした

集計では `SUM`、`COUNT`、`COALESCE`を使用します。意図は「GROUP BYキーへ適用するSQL関数がゼロ」なので、その表現に限定してください。

COALESCEの適用範囲、0件時のTOTAL、nullableな金額列については問題ありません。

## 施策 3: APPROVE

施策2の文言修正を除き、変更は不要です。

## 施策 4: APPROVE

DB名判定のSSOT化は必要最小限です。

## 施策 5: APPROVE

fixtureとbehavioral testの設計は妥当です。

## 施策 6: APPROVE

`llmRecordingIncomplete()` の追加は許容範囲です。新しい抽象層ではなく、既存分類器が使用する入力の導出規則を同じ場所に置いたものです。

判定順も成立しています。

- 成功行0件: `llmRecordingIncomplete()` は `false`、#9で `Llm`
- 成功行あり・templateまたは帰属不足: #8で `Wiring`
- `llm-evidence` 以外: #8には入らない

[Suggestion] 実装時は、`$succeededTemplates` と `$attributedTemplates` をrequired templateに限定した集合として渡すことをコメントまたは呼び出し側で明確にしてください。無関係なtemplateが混ざると、後半の `array_diff($succeededTemplates, $attributedTemplates)` が本smoke対象外の行まで不完全と判定します。新しい検査や引数は不要で、DBクエリを既存の3 templateへ `whereIn` すれば足ります。

## 施策 7: APPROVE

変更不要です。

## 施策 8: APPROVE

変更不要です。orchestratorとartisanの責務境界も維持されています。

## 施策 9: APPROVE

保証範囲の記述に新しい矛盾はありません。

## 施策 10: APPROVE

focused testの配置変更は妥当です。

Feature testで実LLMとworkerを通さず `llm-evidence` まで到達させるには、大規模なfake配線が必要になります。それを避け、次を別々に固定する現在案の方が小さい設計です。

- DBから得た集合に対する不完全判定: 純関数のUnit test
- 不完全時の分類: classifierのUnit test
- DB記録を含むend-to-end: 実smoke

重複していたclassifierケースを削った判断も適切です。導出表のケース2・3・4で、#8/#9の境界とtemplate欠落・帰属欠落を十分に固定しています。

## 削減余地

新たに削るべきproduction機構はありません。`llmRecordingIncomplete()` を別クラスへ分離する必要もありません。

設計文では、施策2の「何をこれ以上削らないか」の表がやや長いものの、今回の差し戻し理由に直接答える記録なので残して問題ありません。

## 全体判定: CHANGES_REQUESTED

実装設計そのものは承認可能な状態です。残っているのは次の文言修正だけです。

1. 「素の列 GROUP BY = index に乗る」という断定を削除
2. 「SQL関数ゼロ」を「GROUP BYキーへ適用するSQL関数ゼロ」に限定

この2箇所を直せば、全体判定は `APPROVED` です。