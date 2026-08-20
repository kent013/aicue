# 対応マトリクス: impl-review-t224 Round 1

## [Warning] test_active_successor_of_a001_watches_toast_ts が「別々の登録がそれぞれの条件を満たすだけで通る」
- 判断: 対応する
- 根拠: 指摘のとおり、旧テストは (1) 同じ species_key/scope_value を持つ active 登録が toast.ts を持つこと、
  (2) 何らかの登録が A-001 を supersede していること、を独立に検証していた。この 2 条件は別々の登録が
  満たしても通ってしまい、A-004 が両方を兼ねることを固定できていなかった。
- 対応内容: `test_active_successor_of_a001_is_a004_and_watches_toast_ts` に置き換え、
  A-001 を **直接** supersede する登録を 1 件取得し、その 1 件が同じ species_key/scope_value を
  持つこと・active であること・機械項目 (verdict/conditions/symptom/rationale_ref/
  source_finding_ids/adjudicated_at_run/adjudicated_at_commit) が A-001 と同じであること・
  watch_globs に toast.ts を含むことをすべて同一レコードに対して検証するようにした。
  さらに「同じ種別・対象面を持つ active な登録がこの 1 件だけ」であることも別途固定した。

## [Warning] test_a001_itself_is_unchanged_and_now_superseded が species_key と watch_globs しか固定していない
- 判断: 対応する
- 根拠: 指摘のとおり、scope / conditions / symptom / verdict / rationale_ref / source_finding_ids /
  adjudicated_at_run / adjudicated_at_commit / review_after_days の改変を検出できていなかった。
- 対応内容: 移行時点の A-001 の全機械項目 (context を除く) を `EXPECTED_A001` として明示し、
  `records["A-001"]` から `context` を除いた辞書全体を `assertEqual` で比較するように書き換えた。
  これにより A-001 の機械項目のどの 1 項目が変わっても即座に検出できる。

再実行結果: `python3 -m unittest discover -s .claude/skills/app-bug-hunt/ledger -p 'test_*.py'` Ran 120 tests, OK。
`render_spec_ledger.py --check` exit 0。`validate_findings.py` errors 0 (adjudications: 4, invalid: 0)。
