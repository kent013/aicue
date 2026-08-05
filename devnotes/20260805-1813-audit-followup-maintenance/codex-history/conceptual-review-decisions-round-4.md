# 対応マトリクス: conceptual-review Round 4

## [Warning] 「制約・前提」に DDL 方針の旧表現が残っている

- 判断: **対応する**
- 根拠: 指摘のとおり文言統一漏れ。「新しい生 DDL を書かない」は
  `COMMENT ON DATABASE` / `pgsqlCommentDatabaseSql()` の新設と矛盾する。
  また「新しい DROP 経路を作らない」も `--orphans` という新しい入口を足す以上、不正確。
- 対応内容: 2 箇所を訂正した。
  - §制約・前提: 「施策 4 は新しい生 DDL を書かない」→
    「**DROP の実行責務を既存ファイルから分散させない** — DROP DDL は既存の
    `scripts/ci/drop-test-db.php` に集約したままにする。追加する DDL は
    `ensure-test-db.php` から実行する**非破壊の `COMMENT ON DATABASE` のみ**」
  - §実装方針 グループ C: 「DROP DDL を実行するファイルは 1 本に限定する
    (新しい DROP 経路を作らない)」→「**DROP の実行責務を既存ファイルから分散させない** —
    DROP DDL を実行するのは `drop-test-db.php` の 1 本のままとし、`--orphans` は
    『どの DB を落とすかを決める入口』を足すだけで DROP の実装は共有する」

## [Suggestion] 分類の優先順位を詳細設計で明記する

- 判断: **対応する** (詳細設計へ引き継ぐ)
- 対応内容: 詳細設計の施策 4 で分類優先順位を
  `Protected → Live → provenance による分類 (Foreign / Orphan) → Unlabeled` と明記し、
  同一候補が複数条件を満たす場合も結果が一意になることをテストで固定する。

## [Suggestion] TOCTOU の適用範囲 / 純関数シグネチャ

- 判断: **対応不要** (解消済みと確認された)
