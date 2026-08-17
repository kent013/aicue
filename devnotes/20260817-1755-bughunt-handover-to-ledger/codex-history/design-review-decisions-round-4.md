# 対応マトリクス: design-review Round 4

全 [Warning] 3 件に対応した (実質は文書内の同期。設計ロジックの変更は無い)。反論なし。

## [Warning] 施策 0: 番号の無い行があり「41 契約」が合わない
- 判断: **対応する**
- 対応内容: `test_render_leaves_no_temp_file_behind` に番号を与え、A〜E 節を通しで振り直した。
  **全 42 契約**に確定した (見出しと注記の数字も 42 へ揃えた)。

## [Warning] 再採番後も旧番号への参照が残っている
- 判断: **対応する**
- 対応内容: 本文中の `テスト N` 参照を**すべてメソッド名**へ置き換えた
  (`test_matcher_source_never_names_the_handover_files` /
  `test_spec_basis_references_are_well_formed_and_exist` /
  `test_migration_manifest_matches_expected_semantics` /
  `test_key_kind_and_target_vocabulary_is_closed` ほか)。
  指摘どおり**番号は再採番に弱い**ので、以後も本文からは番号参照をしない方針にした。
  `grep "テスト [0-9]"` が 0 件であることを確認済み。

## [Warning] 「保証しないこと」の A-001 節が施策 8 の確定内容と矛盾
- 判断: **対応する**
- 対応内容: 「移行台帳の鍵と経緯の置き場所が同時に動く」「候補として申し送る」を削除し、
  施策 8 と同じ確定内容へ置き換えた —
  **A-001 は機械項目・`context`・移行鍵・`provenance`・既存 hash pin を変更しない。
  新登録を append して supersede し、修正済み `watch_globs` と `context` を新登録に持たせる。
  新登録は hash pin の対象外。本タスクでは穴を閉じないが、施策 8 で後続 TODO を必ず登録する。**

## [Warning] 実装モードの判断根拠に `docs/TODO.md` が含まれていない
- 判断: **対応する**
- 対応内容: 「skill 同梱物・`AGENTS.md` 1 項・後続登録の `docs/TODO.md` 1 行に閉じる」へ更新した。
