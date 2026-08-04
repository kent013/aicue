# 対応マトリクス: impl-review Round 4

Round 4 の Codex 返答 (`impl-review-round-4.md`) は **指摘 0 件で APPROVED**。
統合規則 (`seen`/`present_new` は和集合、`installed_now`/`errors` は sticky、`pending` は最終応答)
により Round 3 の [Critical] が閉じたこと、判定不能 / document 置換 / arm 漏れのいずれも
H7 陰性へ落ちる経路が無いこと、N4 が `errors` の batch drain 前提を固定していることを確認された。

→ **反映すべき Critical / Warning / Suggestion は残っていない。実装側の変更は無し。**

## レビュー後に発生した追加変更 (Round 5 で確認する)

`.claude/skills/app-bug-hunt/ledger/test_validate_findings.py` の `EmptySeedRegistryTest` を修正した。

- 事象: `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger` が **2 件 fail** していた
  (`test_seed_has_no_entries` / `test_empty_registry_reports_zero_and_exits_zero`)。
- 原因: 本 TODO とは**無関係の既存不具合**。commit `062a822` が `adjudications.jsonl` に
  実 run 由来の裁定 A-001 を登録した際、「同梱 seed は空」というテンプレート由来の前提を
  置いたままのテストを更新しなかった。**main でも同じ 2 件が fail する**ことを実測確認済み。
- 判断: **直す**。本 TODO は同じ `ledger/` に新規テスト (`test_spec_ledger.py`) を足す変更であり、
  設計 §検証計画がこの discover コマンドの green を担保手段として明記している。
  赤いまま放置すると、本 TODO が足したテストの green すら読み取れない。
- 対応内容: 守りたい不変条件は「**registry が空でも validator が落ちない** (fail-closed による
  全面停止を作らない)」であって「同梱 seed が空であること」ではない。したがって
  - `test_seed_has_no_entries` (前提が崩れた assertion) を**削除**、
  - `test_empty_registry_reports_zero_and_exits_zero` を **tempfile の空ファイル**に対して実行するよう変更。
  同梱 seed 自体の妥当性は既存の `AdjudicationBackwardCompatTest::test_seed_registry_is_valid`
  (validator を通ること) が引き続き見るので、カバレッジは落ちていない。
- 設計との関係: 設計の施策 1〜6 には無い変更。**deviations_from_design に記録する。**
  `adjudications.jsonl` 本体は設計どおり**一切触っていない**。
