# 対応マトリクス: design-review Round 3

## [Critical] 施策4.5: request 一律 102 許可は「通常手順100+定型2」の内訳を保証できない
- 判断: 対応する（仕様を実際に強制可能な形へ修正）
- 対応内容: MAX_STEPS=LLM生成step上限 / MAX_TOP_LEVEL_CUTS=手動保存 top-level 総数上限、とコメント修正。手動保存は「通常手順100件」を保証しない（総数≤102 のみ）と明文化。厳密維持には bookend 識別の永続属性が必要で v1 対象外。

## [Warning] 施策6: 200 は既存 endpoint 応答契約と要照合
- 判断: 対応する
- 対応内容: 既存 ScenarioUpdateTest 成功パスと同じ assertOk() + assertJsonPath('scenario_version',...) で検証（当 endpoint は仕様固定 JSON、200/assertOk が正）。その後 DB で 102 件・順序・version+1 を確認。

## [Suggestion] 施策3: $render closure は PHPStan L10 で値型未指定になりうる
- 判断: 対応する
- 対応内容: renderRecap(array $items): string の private メソッドに分離し @param list<string> を付与。
