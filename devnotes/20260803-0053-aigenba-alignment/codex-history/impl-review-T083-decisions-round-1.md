# 対応マトリクス: impl-review-T083 Round 1

対象: `todo/T083` (commit 46dc72e) / 施策 9 (adjudication registry の機構修復) + 施策 10 (registry データ棚卸し + 運用ガード固定)
Codex 返答: `devnotes/20260803-0053-aigenba-alignment/impl-review-T083-round-1.md`
verdict (Codex): **APPROVED**

---

## [Critical] 指摘なし — 施策 9/10 への設計逸脱は確認できず

- 判断: **対応不要**（指摘そのものが「逸脱なし」の確認）
- 根拠: `COND_KEYS` への `mode`/`env` 追加 (validate_findings.py:199)、stdin 2-pass 修復
  (validate_findings.py:657)、seed 空化、運用ガード (a)〜(d) の README 追記 (README.md:122-)、
  `spec-ledger.md` の新規枠組み — いずれも詳細設計書 L1142-1290 の記述と 1:1 で対応している。
- 対応内容: 変更なし。

## [Critical] 空振り懸念への対処は十分

- 判断: **対応不要**
- 根拠: seed を空にしたことで `test_seed_registry_is_valid` (L454) は自明に pass する
  (= 空振り化する) が、固定したい不変条件は以下の機構テストで別途固定されている:
  - `GovernedConditionKeysTest` — `mode`/`env` が governed key であること + `conditions_status()` が
    実際に mismatch / unverified / unspecified を返し分けること（= 定数の存在確認だけで終えていない）
  - `StdinTwoPassTest` — stdin `-` + `--annotate` で findings が落ちないこと（修正前は 0 件になっていた）
  - `EmptySeedRegistryTest` — seed が空であること + `adjudications_total: 0` / `invalid: 0` / exit 0
  - `FlashAdjudicationBehaviourTest` — seed データ依存を捨て fixture 化し、known_accepted /
    ambiguous(new_signal) の分岐を機構として固定
- 対応内容: 変更なし。

## [Warning] coverage 側 1 件 fail を T083 で直さない判断は妥当。ただし別タスクで追跡すべき

- 判断: **受け入れる（本 PR では修正しない / 親へ引き継ぐ）**
- 根拠: `.claude/skills/app-bug-hunt/coverage/test_correlate.py` と `operations.md` は本差分で
  1 行も変更されておらず (`git diff main...HEAD` が空)、両ファイルの最終変更は 41d2940
  (T083 の 46dc72e より前) — main 時点からの既存赤。原因は
  `operations.md:27 | POST | logout | logout | S1 | 通常 |` で URL 列と route 名がたまたま同値であり、
  テスト側の行単位 `assertNotEqual(name, operation)` ヒューリスティックが偽陽性を出しているため。
  load_operations 側は正しく name 列を join キーにしている。
  fix-gate #3 ガードの再設計判断を伴い、設計書 (正本) の施策 9/10 の範囲外なので、
  禁止事項 #2 の精神（テストを緩めて黙らせない）に従い**未変更のまま fail として報告**する。
- 対応内容: 本ブランチでは変更しない。**別タスク起票を親へ申し送る**。
  修正方針案: 行単位の `assertNotEqual` を「全行が name == operation ではないこと」へ置き換える。
  ガードが検出したい実際の失敗モード（load_operations が URL 列を join キーにする）では全行が一致するため
  検出力は維持され、単一セグメント route (`logout`) の偽陽性だけが消える。

## [Suggestion] `StdinTwoPassTest._empty_globs_file()` の一時ファイルが回収されない

- 判断: **対応する**
- 根拠: `tempfile.NamedTemporaryFile(delete=False)` を後始末していないため、テスト実行のたび
  `/tmp` に `.json` が残留する。同メソッドは既に `TemporaryDirectory` を使っており、
  そこへ寄せるだけで済む（テストの検証内容は一切変わらない = 空振り化しない）。
  設計書の記述対象外（テストの実装詳細）なので、逸脱には当たらない。
- 対応内容: `_empty_globs_file()` を削除し、`_run_stdin()` 内の `TemporaryDirectory` 配下に
  `changed-globs.json` を作る形へ変更 (`test_validate_findings.py:638-`)。
  再検証: `python3 -m unittest discover -s ledger -p 'test_*.py'` → Ran 68 tests / OK。

---

## 残存 Critical

**なし**（Codex verdict: APPROVED）。
