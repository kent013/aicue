Round 5 の [Warning] 2 件に対応しました。どちらも「対応マトリクスには書いたが本文に反映されていなかった」もので、指摘は正しいです。

## 対応マトリクス

# 対応マトリクス: design-review Round 5

[Warning] 2 件に対応した。いずれも Round 4 の修正が**本文に実際には反映されていなかった**もので、
指摘は正しい (対応マトリクスに書いた修正文と本文が食い違っていた)。

## [Warning] 「保証しないこと」の A-001 節が旧文面のまま
- 判断: **対応する**
- 根拠: Round 4 で置換したつもりだったが、置換対象の文字列が既に別の版になっており
  実際には当たっていなかった。**マトリクスにだけ書いて本文が直っていない**状態で、
  台帳の腐りを直す設計としては笑えない不整合である。
- 対応内容: 本文を施策 8 と同じ確定内容へ置換した —
  **A-001 は機械項目・`context`・移行鍵・`provenance`・既存 hash pin をいずれも変更せず、
  新登録を append して supersede し、修正済み `watch_globs` と `context` を新登録に持たせる。
  新登録は hash pin の対象外。本タスクでは穴を閉じないが、施策 8 で後続 TODO を必ず登録する。**
  `grep "候補として申し送る"` が 0 件であることを確認した。

## [Warning] `test_key_kind_and_target_vocabulary_is_closed` が本文から参照されているのに一覧に無い
- 判断: **対応する**
- 根拠: 指摘のとおり。参照名だけ残して検証ロジックのテストが一覧から落ちていた。
  `load_migration()` は閉じた語彙 (`key_kind` / `target` / `field_minimums` の欄名 /
  `required_fragments` の `field`) を持つのに、その異常系の契約が無かった。
- 対応内容: D 節へ契約を 1 つ足し、**全 43 契約**に振り直した
  (参照名を消すのではなくテストを足す方を採った)。

---

## 該当箇所 (修正後の本文。実際のファイルから抜粋)

### 「保証しないこと」の A-001 節

- **A-001 の invalidation の穴を閉じない**: A-001 の再オープン条件 (b) は
  `resources/js/lib/stores/toast.ts` の `AUTO_DISMISS_MS` を挙げているが、
  A-001 の `watch_globs` にこのファイルは無い。したがって
  **`toast.ts` が変わっても照合器の invalidation は発火せず**、
  本物の退行を旧 false-positive として downrank し続けうる。
  直し方は確定している — **A-001 は機械項目・`context`・移行台帳の鍵・`provenance`・
  既存の hash pin をいずれも変更せず**、新登録を 1 行 append して A-001 を supersede し、
  修正済みの `watch_globs` と `context` を新登録に持たせる
  (新登録は移行時点に存在しないので hash pin の対象外)。
  **移行と判断の変更を 1 つの変更に混ぜるとどちらが原因で赤くなったか分からなくなる**ため、
  本タスクでは穴を閉じない。ただし**施策 8 で後続 TODO を必ず登録する** (「候補」では終わらせない)。
- **`spec_basis` のパス実在検査はテストの担当**であり、生成の必須条件ではない。

### D 節 (移行台帳) の契約一覧 (追加後)

**D. 移行台帳**

| # | テスト | 固定する事実 |
|---|---|---|
| 27 | `test_migration_manifest_matches_expected_semantics` | テスト側の定数 `EXPECTED_MIGRATION` と**完全一致** (鍵 / `key_kind` / `target` / `field_minimums` の値 / 必須断片の**列挙**。要素数も含めて比較)。**台帳を弱める変更をこのテストが赤にする** |
| 28 | `test_duplicate_required_fragment_is_rejected` | `(field, value)` が重複した台帳を拒否する |
| 29 | `test_block_count_change_fails` / `test_entries_count_mismatch_fails` | 件数の三点一致 (`block_count` / `len(entries)` / `EXPECTED_BLOCK_COUNT`) |
| 30 | `test_duplicate_key_in_manifest_fails` / `test_unknown_key_does_not_resolve` | 鍵の重複と解決不能を拒否 |
| 31 | `test_key_kind_and_target_vocabulary_is_closed` | 語彙外の `key_kind` / `target` / `field_minimums` の欄名 / `required_fragments` の `field` を `RenderError` で拒否する (`load_migration()` の閉じた語彙検証の異常系) |
| 32 | `test_integer_fields_reject_bool_and_non_positive` | `version` / `block_count` / `field_minimums` の各値で `True` / `0` / `-1` / `"900"` / `None` を拒否 |
| 33 | `test_field_below_minimum_fails` | `narrative` または `reopen_condition` を削ると `RenderError` (痩せの検出) |
| 34 | `test_required_fragment_missing_fails` | 必須断片を消すと `RenderError` |
| 35 | `test_fragment_is_searched_only_in_its_declared_field` | `reopen_condition` の断片が `narrative` に紛れていても通らない |
| 36 | `test_fragment_identifier_boundary` | `T095` を要求して本文が `T0950` なら不一致 / 「`T095` の実装フェーズ」「\`T095\`」は一致 / `xT095` `T095-extra` は不一致 |
| 37 | `test_provenance_shape_and_heading_count` | `provenance` の必須キー・型、`source_block_headings` の件数が `block_count` と一致・一意・非空 |
| 38 | `test_machine_projection_sha256_is_pinned_in_three_places` | 各登録の**機械項目だけの射影** (context を除いた正規化 JSON) の sha256 が、**テスト定数 `EXPECTED_MACHINE_PROJECTION_SHA256` / 移行台帳の `provenance.machine_projection_sha256` / 現在の登録から計算した値**の三点で一致する |
| 39 | `test_machine_field_change_turns_red` | 写しの機械項目を書き換え、台帳の hash も同時に更新しても、テスト定数と食い違うので落ちる |
| 40 | `test_manifest_shape_is_rejected_when_not_a_single_object` | 配列 / 不在ファイルを拒否 |

**E. 既存方針の継承 / 構造的保証**

### 契約数の表記

検出できない。これが実測 1 (A-002 / A-003 が文書に無い) を見逃した機序である。

### 変更後: テスト一覧 (43 契約)

`staged(...)` ヘルパで入力 2 点 (`adjudications.jsonl` / `spec-ledger-migration.json`) を
一時ディレクトリへ写し、必要なら壊してから生成器へ渡す。**現物は絶対に書き換えない**
| 43 | `test_matcher_source_never_names_the_handover_files` | `validate_findings.py` の本文に `spec-ledger` / `spec_ledger` / `render_spec_ledger` / `spec-notes` が 1 つも現れない |

> 表の番号は**契約の通し番号** (43 契約) である。1 行に複数ケースを含む契約
> (型と空の拒否・整数項目の bool 拒否・脱出の 4 ケースなど) は**ケースごとにテストを分けることを推奨**する
> ため、**実装時のテストメソッド数はこれ以上になる**。
> **実装完了報告で実際のテスト件数を確定して書く** (ここでは本数を主張しない)。
>
> テストの前提確認として `REPO_ROOT / "AGENTS.md"` が実在することを最初に assert する
> (根拠パスの実在検査が別ディレクトリを見ていたら全件緑になってしまうため)。

---

改めて全体判定 (APPROVED / CHANGES_REQUESTED) をお願いします。
