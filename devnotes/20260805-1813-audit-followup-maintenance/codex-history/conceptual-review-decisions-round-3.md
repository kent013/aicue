# 対応マトリクス: conceptual-review Round 3

## [Warning] 「TOCTOU を閉じる」の適用範囲が広すぎる (別クローンとは lock を共有しない)

- 判断: **対応する** (範囲を明記 + lock 保持区間を明記。cross-clone advisory lock は
  **意図的な非目標**として理由付きで宣言する)
- 根拠: 指摘は正しい。`.claude/worktrees/.setup.lock` はファイルシステム上の 1 クローンに閉じた
  lock であり、別クローンの setup/teardown とは排他しない。
  PostgreSQL advisory lock なら cross-clone まで排他できるが、それを
  `ensure-test-db.php` に入れると **CI・全 worktree の test 実行前処理がロック待ちで
  ハングしうる経路**を新設することになり、本バッチ (偽赤を減らす) の目的と逆行する。
  cross-clone の防御は lock ではなく **provenance 分類 (foreign 判定) + `--protect-hash` +
  人間承認**の 3 段で行う設計になっており、そちらが正しい防御線である。
- 対応内容:
  - 「TOCTOU を閉じる」→「**同一クローンの協調スクリプト (setup / teardown / sweep) 間の
    TOCTOU を閉じる**」と範囲を明記
  - **lock は token 再計算の直前に取得し、全 DROP の完了まで保持する**ことを明記
  - 「別クローンとは lock を共有しない」ことと、その場合の防御が
    foreign 分類 / `--protect-hash` / 人間承認であることを制約として明記
  - PostgreSQL advisory lock による cross-clone 排他は**スコープ外**とし、理由を記す

## [Warning] 「DDL を実行するファイルを 1 本に固定」と `COMMENT ON DATABASE` 追加の矛盾

- 判断: **対応する** (方針の表現が不正確だったので訂正する)
- 根拠: 指摘のとおり。元の表現は「あらゆる DDL を 1 本に固定」と読めるが、実際に守りたいのは
  **不可逆な破壊操作 = DROP** の一元化である。`ensure-test-db.php` は既に
  `CREATE DATABASE` を実行しており、`COMMENT ON DATABASE` は同じ DB に対する
  非破壊のメタデータ付与なので、置き場所として自然。
- 対応内容:
  - 方針を「**DROP DDL を実行するファイルは `scripts/ci/drop-test-db.php` の 1 本に限定する**」へ訂正
  - `COMMENT ON DATABASE` は既存の `pgsqlQuoteIdentifier()` (識別子) と
    PDO の文字列クォート (リテラル) で生成する専用ヘルパ
    `pgsqlCommentDatabaseSql()` を `pgsql_test_conn.php` に置く (既存の
    `pgsqlCreateDatabaseSql` / `pgsqlDropDatabaseSql` と同じ作法)
  - **comment は base DB にのみ付き、hash グループ全体の出自として扱う**。
    base が不在で worker DB (`_test_N`) だけが残っている場合は **unlabeled** になる、と明記
  - provenance の取得・パース・分類に**単体テストを受入条件として追加**
- 追加対応 ([Suggestion] より): **DB comment は信頼境界ではなく分類材料**であり、
  allowlist regex / dev DB denylist / 生存 hash 突合 / confirm token を**置き換えない補助情報**
  であることを明記する (comment は誰でも書き換えられるため、単独では guard になり得ない)

## [Warning] 純関数のシグネチャが旧設計のまま (分類入力が拡張されている)

- 判断: **対応する**
- 根拠: 指摘のとおり。入力は DB 名 + 生存 hash だけでなく provenance / protected hash /
  `--include-unlabeled` へ拡張されており、`list<string>` 2 本では表現できない。
  PHPStan level 10 で意味のある型を付けるには値オブジェクト化が必要。
- 対応内容: 概念設計に型を明記する。
  - `TestDatabaseCandidate` (readonly): `name` / `hash` / `isWorker` / `provenancePath (?string)`
  - `TestDatabaseClassification` (enum): `Live` / `Foreign` / `Orphan` / `Unlabeled` / `Protected`
  - `TestDatabaseDecision` (readonly): `candidate` / `classification` / `reason` / `shouldDrop`
  - 純関数:
    `classifyTestDatabases(list<TestDatabaseCandidate>, list<string> $liveHashes,
    list<string> $protectedHashes, bool $includeUnlabeled): list<TestDatabaseDecision>`
  - **境界で正規化する**: `pg_database` の問い合わせ結果と `git worktree list --porcelain` の
    出力は `mixed` 由来なので、値オブジェクト生成時に検証して `list<...>` へ正規化してから
    純関数へ渡す (PHPStan level 10 適合の要件として明記)

## [Suggestion] main contingency / 実測値 55 対 58

- 判断: **対応不要** (Round 2 で解消済みと確認された)
