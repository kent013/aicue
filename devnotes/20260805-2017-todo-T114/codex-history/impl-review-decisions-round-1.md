# 対応マトリクス: impl-review Round 1

## [Warning] `dropTestDbDropAll()` が DROP 失敗を握りつぶし、`--apply` も exit 0 になる

- 判断: **対応する**
- 根拠: 指摘のとおり。teardown の best-effort (失敗しても worktree 削除を止めない) と、
  人間が明示承認して回す `--apply` (手動回収の成否を判定する入口) は要求が違う。
  「一部が残ったのに成功扱い」は偽グリーンであり、AGENTS.md の「テストなし / 偽グリーン禁止」の趣旨に反する。
- 対応内容:
  - `dropTestDbDropAll()` の戻り値を `int` から `array{dropped, failed, skipped}` に変更。
  - **従来経路 (無引数 = teardown) は exit 0 のまま**(best-effort の契約を維持)。
  - **`--apply` は `dropped !== targets` のとき exit 1** + 「手動での確認・回収が必要」を stderr へ。
    出力にも `failed` / `skipped` の内訳を出す。
  - あわせて `$exec($sql) === false` も失敗として数えるようにした
    (下の [Suggestion] と同じ理由。`PDO::exec()` は ERRMODE 次第で false を返す)。

## [Suggestion] `pgsqlStampProvenance()` が `$exec` の戻り値 `false` を成功扱いにしている

- 判断: **対応する**
- 根拠: `@return bool 成功したか` という契約と実装がずれていた。
  provenance ラベルは分類材料なので best-effort のままでよいが、
  「付かなかったのに true」は将来 `Unlabeled` の原因を追う際に嘘の手がかりになる。
- 対応内容: `$exec($sql) === false` を warning + `false` として扱うようにした。
  best-effort である (例外を伝播させない / ensure は exit 0 で続行する) 点は変更していない。

## 追加した検証 (指摘への対応がテストで固定されていること)

`--apply` は **LLM が実行してはならない**契約なので、実走で検証する経路を持てない。
そこで DROP の DDL 実行境界 (`$exec`) を注入可能にし、
`scripts/ci/drop-test-db.php` を「直接実行されたときだけ main を走らせる」形にして
require 可能にした (DDL を組み立てる場所は本ファイルの 1 箇所のまま = 設計方針を崩していない)。

新規 `tests/Unit/Ci/DropTestDbScriptTest.php` (実 DB を触らない) が固定する不変条件:

- dev DB (`app`) / `bug_hunt*` / allowlist 外の名前は **executor に 1 度も到達しない**
- 例外・戻り値 `false` のどちらも `failed` として数え、ループを中断しない
- `dropped !== targets` の部分成功が呼び出し側に伝わる (= exit 1 の根拠)
- 引数解析が fail-closed (未知の引数 / `--include-unlabeled` のような
  **意図的に用意していない一括フラグ** / 不正 hash / `--confirm` 無しの `--apply` を拒否)
- usage に「`--apply` は LLM が実行しない」契約が書かれている

`pgsqlStampProvenance()` の `false` 戻り値ケースも
`tests/Unit/Ci/TestDatabaseProvenanceTest.php` に追加した。
