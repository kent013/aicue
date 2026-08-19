# 対応マトリクス: design-review Round 2

セッション ID (resume 用): `01a017d6-062a-7ff3-939c-83934cac13cb`(Round 1 から継続)

## 施策1: 接続 resolver

### [Critical] `ensure-test-db.php` 内部が引き続き通常 `require` で、テストの読み込み順によって関数/enum の再宣言 fatal error になり得る
- 判断: **対応する**
- 根拠: 指摘のとおり。`BaseTestDatabaseSchemaTest.php` / `TestDatabaseProvenanceTest.php` が
  `pgsql_test_conn.php` を先に `require_once` した後、同一プロセスで `ensure-test-db.php` が
  読み込まれると、内部の通常 `require` が同じファイルを再パース・再実行し fatal error になる。
- 対応内容: `ensure-test-db.php` 内部を `require_once` へ変更する。加えて、
  `scripts/ci/drop-test-db.php` の同じ行も同じ理由で `require_once` へ揃える
  (施策そのものではないが、直さないと `DropTestDbScriptTest.php` と新規テストが
  同一プロセスで実行されたときに同じ fatal error が再発し得る)。読み込み順を変えた
  回帰テスト(別プロセスで多重 require_once させ fatal にならないことを確認)を追加する。

### [Warning] 「多重起動自体はグローバルテストロックが排除する」という docblock の主張が不正確
- 判断: **対応する**
- 根拠: `setup-worktree.sh` はロックの外で本スクリプトを呼ぶため、多重起動の排除を
  グローバルテストロックだけに帰することはできない。
- 対応内容: 「専用パスは通常存在せず、存在したら原因を問わず fail-closed で停止する」
  という記述だけに絞る(`pgsqlTestConfigCachePath()` の docblock と D33 の両方を修正)。

## 施策2: callable 注入型オーケストレーション

### [Warning] 「純粋な意思決定関数」という表現が過大
- 判断: **対応する**
- 対応内容: 「実 DB / 実子プロセスを直接持たない、主要実行境界を注入可能な
  オーケストレーション関数」という正確な表現へ改め、`TestDatabaseEnv` の静的判定・
  `.env.testing` 経由の環境変数・`is_file()` は直接読む外部状態であることを明記する。

### [Warning] 2 つ目の `ConfigCacheStale` 分岐 (migrate 実行中に専用パスが出現) が未検証
- 判断: **対応する**
- 対応内容: migrate 用フェイク runner が専用キャッシュファイルを生成する負例テストを追加し、
  結果が `ConfigCacheStale`・runner 呼び出しが migrate の 1 回だけであることを固定する。

### [Warning] `performTestDatabaseSchemaUpdate()` の結線が Architecture テストでは決定的に検証されない
- 判断: **対応する**
- 対応内容: 実物 callable の組み立てを `realTestDatabaseSchemaUpdateCallables()` という
  純粋な factory へ切り出し、実 DB・実子プロセスに触れない `listMigrationFiles` の結線だけを
  単体テストで直接固定する。`runArtisan` / `verifyAppliedMigrations` の結線は単体テストの
  対象にせず、その保証範囲の限界(Architecture テストは監査ではなく最終状態の観測)を明記する。

## 施策3: Architecture テスト

- 判断: 見送る(施策1 の `require` 修正が前提という指摘のみ。施策1 の対応で解消)

## 施策4: Unit テスト

### [Warning] 「全ての失敗系を通しで走らせる」の主張と実装(実際は2ケースのみ)の乖離
- 判断: **対応する**
- 対応内容: データセット化したテストへ書き直し、runner へ実際に到達する主要分岐
  (成功・migrate 失敗・migrate:status 失敗・到達確認の 3 失敗)を明示的に列挙して回す。
  対象外にした分岐(短絡する分岐・呼び出し列が他分岐と構造的に同一な分岐)は理由を明記する。

### [Warning] 2 つ目の `ConfigCacheStale` 分岐の負例不足
- 判断: **対応する**(施策2 と同じ対応)

### [Warning] 一時フィクスチャの後始末が不完全 (bootstrap / フィクスチャルートが残る)
- 判断: **対応する**
- 対応内容: 内側から 3 階層を確実に削除するヘルパー `cleanupEnsureTestDbFixtureRoot()` を
  追加し、既存テストと新規テストの両方で使う。

### [Suggestion] `runTestDatabaseArtisan()` 自体の専用テスト不足
- 判断: 見送る(根拠を明記)
- 根拠: 正典 `laravel-claude-template@ccf465a7` からそのまま移植した部分であり
  (施策7 の三者 diff で「正典からの移植」に分類)、aicue 側で変更していない。
  思考原則2(今必要なものだけ作る)に照らして新規テストを追加しない。
  aicue が将来この関数へ変更を加える場合は、その時点で専用テストを追加する方針を明記する。

## 施策5: provenance plan

- 判断: 見送る(APPROVE のみ)

## 施策6: グローバルロック gate

### [Warning] 共通規約(e)の独立した接頭辞形が不足(既存の `not-ensure-test-db.php` は打ち消しの意味も持つ)
- 判断: **対応する**
- 対応内容: 打ち消しを伴わない独立した接頭辞形 (`prefix-ensure-test-db.php`) の負例を追加する。

### [Warning] 「基点DBのスキーマ更新はここで初めて実DBへ触れる」が事実と異なる
- 判断: **対応する**
- 対応内容: 「`ensure-test-db.php` 自体は既に CREATE/出自記録で実 DB へ触れているが、
  今回新たに追加されるスキーマ更新の子プロセス起動点は新しい競合対象である」という
  限定した表現へ改める。

## 施策7: D30/D33

### [Warning] 「多重起動はグローバルテストロックが排除する」の矛盾
- 判断: **対応する**(施策1・2 と同じ対応)

### [Warning] 「本文が指すテストファイルの実在は施策3・4のテストが担保する」が厳密には正しくない
- 判断: **対応する**
- 対応内容: 単なる関連リンクとして扱い、実在を機械的に保証する仕組みは本設計に
  含まないことを明記する。

## 施策8: worktree 文書 — APPROVE

- 判断: 見送る(APPROVE のみ)

## 施策9: setup 文言 — APPROVE

- 判断: 見送る(APPROVE のみ)

## Round 2 総括

最優先の Critical (`require` → `require_once`) を含め、全ての Critical / Warning に対応した。
Round 3 プロンプトで全文を再送し、再レビューを依頼する。
