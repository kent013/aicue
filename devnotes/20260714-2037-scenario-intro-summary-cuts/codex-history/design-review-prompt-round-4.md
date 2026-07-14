Round 3 の Critical 1 点＋Warning 1 点＋Suggestion 1 点に対応しました。再評価をお願いします。

- [Critical] 施策4.5 仕様整合: **対応**。ScenarioLimits コメントを「MAX_STEPS=LLM生成step上限 / MAX_TOP_LEVEL_CUTS=手動保存 top-level 総数上限」に修正。手動保存は「通常手順100件」の内訳を保証しない（総数≤102 のみ）と明文化。厳密維持には bookend 識別の永続属性が必要で v1 対象外と記載。

- [Warning] 施策6 応答契約: **対応**。round-trip テストは既存 ScenarioUpdateTest 成功パスと同じ `putJson(...)->assertOk()` + `assertJsonPath('scenario_version', ...)`（当 endpoint は仕様固定 JSON）で検証し、その後 DB で 102 件・順序・version+1 を確認、と修正。

- [Suggestion] 施策3 closure: **対応**。`$render` closure を `private renderRecap(array $items): string`（`@param list<string>`）へ分離。

これで残指摘は解消と考えます。APPROVED 可否をお願いします。
