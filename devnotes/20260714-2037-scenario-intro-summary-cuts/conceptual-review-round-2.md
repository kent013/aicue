**全体判定: CHANGES_REQUESTED**

Round 1 の主要論点 B・D は概ね解消しています。独立 `CutType` を導入しないスコープ判断と、サーバ側決定的挿入は妥当です。ただし、locked phase と総括生成元の関係に実装上の曖昧さが残っています。

- [Warning] **locked phase の「再掲元カット読み取り」が不明確**  
  materialize 前には新しい `cuts` はまだ DB に存在しません。locked manual の既存 relation から読むと、再生成時に旧シナリオを総括する危険があります。  
  **修正提案:** terminal tx 内で、`GeneratedScenarioData::toScenarioSteps()` が返した今回の `ScenarioStepInput` リストを builder に渡し、そこから総括を生成すると明記してください。DB の既存 `cuts` は参照しない設計に固定すべきです。

- [Warning] **責務記述が相互に矛盾している**  
  「AnalysisPipeline が list を拡張」「AnalysisPipeline は意思決定のみ」「実文面は locked manual を参照する位置で組み立てる」「ScenarioService は汎用のまま」が同時には成立しにくい記述です。  
  **修正提案:** 例えば `finalize` の terminal tx 内で `ScenarioBookendBuilder::wrap($lockedManual, $generatedSteps)` を呼び、その戻り値を既存 `materializeIntoLockedManual()` に渡す、と呼び出し位置と責務を一本化してください。

- [Warning] **決定的な要点抽出規則が未確定**  
  「先頭 N 件（例3件）」では N、point の走査順、親子構造の扱い、区切り、文字数超過時の優先順位が仕様化されていません。また point 不在時の `scene` は撮影指示であり、必ずしも要点ではありません。  
  **修正提案:** N を定数として確定し、深さ優先などの走査順と truncate 単位を定義してください。point 不在時は「作業名＋完了確認」の定型総括へフォールバックし、「要点再掲」を保証できる場合との表現を分けるのが安全です。

- [Warning] **intro/summary の識別不変条件が定義不足**  
  DB 上では通常の `step` と区別できないため、「先頭が導入、末尾が総括」は型だけでは検証できません。翻訳文面そのものへの依存は、文言変更でテストが壊れやすくなります。  
  **修正提案:** v1 では builder が返す DTO の全フィールド値と位置を Feature テストで検証する、と明記してください。恒久的な識別子が必要なら、それは独立型導入時の後続課題として扱えます。

- [Suggestion] `config/manual.php` と lang の責務を分け、定型文は lang、件数・上限などの構造値は config に限定すると明快です。翻訳取得値が PHPStan level 10 で `string` と確定する typed accessor も詳細設計に含めるべきです。

上記、とくに「今回生成した DTO から terminal tx 内で総括を作る」ことを明文化できれば、概念設計としては `APPROVED` に移せる状態です。