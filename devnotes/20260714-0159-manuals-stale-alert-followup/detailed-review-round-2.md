各対応により、Round 1 の Critical 2件・Warning 3件は解消しています。

- 施策1: **APPROVE**
- 施策2: **APPROVE**
- 施策3: **APPROVE**
- 施策4: **APPROVE**
- 施策5: **APPROVE**
- 施策6: **APPROVE**
- 施策7: **APPROVE**

特に以下が明確になりました。

- 「最新 = ID降順」を既存契約として固定
- 具体的なテスト名と red→green の実装順を規定
- byte基準を token budget の既存不変条件として維持
- `ScenarioService::save()` 実経路による統合テストを追加
- preview失敗時の `manual.status` 不変を回帰テストで固定

新たな Critical / Warning はありません。

**全体判定: APPROVED**