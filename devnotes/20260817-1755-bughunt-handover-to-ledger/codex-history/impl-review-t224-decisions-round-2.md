# 対応マトリクス: impl-review-t224 Round 2

## [Warning] successor の id が A-004 であることを明示的に固定していない
- 判断: 対応する
- 根拠: 指摘のとおり、A-001 を直接 supersede する登録がちょうど 1 件であることは検証していたが、
  その id が `A-004` であることまでは固定していなかった。id が別の値 (例: A-005) に変わっても
  テストは通ってしまう。
- 対応内容: `test_active_successor_of_a001_is_a004_and_watches_toast_ts` の先頭で
  `self.assertEqual(successor["adjudication_id"], "A-004", ...)` を追加した。

## [Warning] A-001 の context が不変であることを検証していない
- 判断: 対応する
- 根拠: 指摘のとおり、旧テストは `context` を `pop()` して比較から除外しており、
  設計 (施策 8 / 保証しないこと) が要求する「A-001 の context もいずれも変更しない」を
  固定できていなかった。
- 対応内容: `EXPECTED_A001` に `context` (title / spec_basis / narrative / reopen_condition)
  を移行時点の値そのまま (adjudications.jsonl から `repr()` で機械的に抽出し転記ミスを防いだ)
  追加し、`records["A-001"]` を `pop()` せず丸ごと `EXPECTED_A001` と比較するように変更した。

再実行結果: `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` Ran 120 tests, OK。
`render_spec_ledger.py --check` exit 0。`validate_findings.py` errors 0 (adjudications: 4, invalid: 0)。
