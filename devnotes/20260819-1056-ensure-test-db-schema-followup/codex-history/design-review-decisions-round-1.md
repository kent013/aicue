# 対応マトリクス: design-review Round 1

セッション ID (resume 用): `01a017d6-062a-7ff3-939c-83934cac13cb`
ログ: `/tmp/codex-review/session-design-review-agdb135.5ENvYh.jsonl` (リポジトリ外・機械可読用。人間可読の議論履歴は本ファイルとプロンプト/返答ファイルで完結)

## 施策1: 接続 resolver の拡張

### [Critical] `pgsqlTestArtisanEnv()` の呼び出し境界で dev DB 名の再検証が無い
- 判断: **対応する**
- 根拠: 指摘のとおり。設計書は「CREATE / スキーマ更新の直前に再確認する」と謳っているが、
  `ensureTestDatabaseSchemaUpdated()` 内には dev DB 再検証が実装されていない。
- 対応内容: `ensureTestDatabaseSchemaUpdated()` の冒頭(env 構築の直前)で
  `TestDatabaseEnv::isDevDatabase($base)` / `isAllowedTestDatabase($base)` を再実行する。
  `pgsqlTestArtisanEnv()` 自体の docblock に「単独では安全な実行境界にならない
  (呼び出し側が直前に再検証する契約)」を明記する。

### [Warning] 親環境からの上書き負例が不足
- 判断: **対応する**
- 対応内容: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` に、親環境へ
  `DB_DATABASE=<dev DB名>` / `DB_URL=pgsql://...` / `APP_CONFIG_CACHE=...` を実際に設定した
  状態で `pgsqlTestArtisanEnv()` の固定値が勝つことを確認する負例を追加する。

### [Warning] `putenv('SOME_SECRET')` のテスト後始末漏れ
- 判断: **対応する**
- 対応内容: 元の値を保存し `try/finally` で復元する形へ書き直す。

## 施策2: `ensure-test-db.php` のスキーマ更新

### [Critical] 設定キャッシュ確認と子プロセス起動の間の時間差 (TOCTOU)
- 判断: **対応する**
- 対応内容: 各 `proc_open()` 直前にもキャッシュ残存を再検査する。加えて、通常の
  `config:cache` の既定パスとは別の ensure 専用パスを `APP_CONFIG_CACHE` に固定し、
  そのパスが各起動直前に存在しないことを確認する形へ設計を見直す。
  これは正典に無い aicue 独自の強化点になるため、D30 とは別の逸脱登録の要否を
  施策7 の対応と合わせて検討する。

### [Critical] 到達確認は「子プロセスが基点 DB を更新した証明」ではない
- 判断: **対応する (docblock の主張を是正)**
- 対応内容: 「子プロセスの環境変数解決が壊れていても気付ける」という主張を、
  「基点 DB の最終状態確認であり、更新の実行先を監査するものではない」へ書き直す。
  dev DB 保護は起動直前の名前再検証 + 専用非キャッシュパス + 明示的環境固定で成立させる。

### [Critical] 新設する実行境界がほぼ未テスト (7 条件の一対一固定が無い)
- 判断: **対応する**
- 対応内容: `ensureTestDatabaseSchemaUpdated()` を、artisan runner・migration ファイル列挙・
  確認用 PDO を callable として注入できる形へ分離する。`exit()` を関数内部で直接呼ばず、
  型付き結果 (成功 / 失敗理由の enum か DTO 相当の配列) を返し、
  main 境界だけが標準エラー出力と `exit(1)` を担当する形へ設計を変更する。
  7 条件 (安全でない DB 名・migrate 失敗・migrate:status 失敗・migration ファイル
  列挙失敗またはゼロ件・確認 PDO/SQL 失敗・migrations 表不在・未適用ファイル残存) を
  それぞれ独立したテストケースで固定する。

### [Warning] `migrate:status` 非ゼロの原因表現が「未適用」に限定されている
- 判断: **対応する**
- 対応内容: メッセージを「migration 状態確認に失敗、または未適用が残っている」へ変更し、
  取得した出力を必ず併記する。

### [Warning] `glob()` 失敗と空配列を区別していない
- 判断: **対応する**
- 対応内容: `false` (失敗) と `[]` (ファイル無し) を明示的に分岐し、別の診断メッセージにする。

## 施策3: `BaseTestDatabaseSchemaTest.php` — APPROVE

- 判断: 見送る (指摘は Suggestion のみ)
- 対応内容: 「判定基準の一元化であって独立した二重検証ではない」ことを docblock に明記する
  (次ラウンドの改訂で反映)。

## 施策4: `TestDatabaseSchemaUpdateTest.php`

### [Critical] 破壊的コマンドのソース走査が不完全 (migrate:reset 抜け・文字列分割回避可能)
- 判断: **対応する**
- 対応内容: ソース文字列の grep ではなく、施策2 で分離した runner 注入点が実際に受け取る
  引数列を検証する形へ変更する。許可する呼び出しを
  `['migrate', '--force', '--no-interaction']` と `['migrate:status', '--pending=1']` の
  2 種類だけに固定し、それ以外の引数列が渡ればテストが落ちる形にする。

### [Critical] fail-closed の主要分岐が未テスト
- 判断: **対応する**
- 対応内容: 施策2 の callable 注入設計に対し、7 失敗条件それぞれの単体テストと正常系 1 本を追加する。

### [Warning] 親環境の書き換えテストの後始末
- 判断: **対応する** (施策1 と同じ対応)

### [Suggestion] 「実 DB を作らない」の表現
- 判断: 対応する (次ラウンドの改訂で文言を精緻化)

## 施策5: `TestDatabaseProvenanceTest.php` — APPROVE

- 判断: 見送る (Suggestion のみ)
- 対応内容: 「固定する不変条件」冒頭コメントへ `UpdateSchema` を含む旨と順序を追記する。

## 施策6: `GlobalTestLockInventoryTest.php`

### [Warning] 検出器が実際の呼び出し形を確認していない (擬陽性の合格例が複数)
- 判断: **対応する**
- 対応内容: `global_test_lock_run` の後続トークンが厳密に `php scripts/ci/ensure-test-db.php`
  であることを検証する形へ判定関数を書き直す。指摘の負例
  (`echo scripts/ci/ensure-test-db.php` / 行末コメント化 / 別名ファイル) をすべて追加する。

### [Warning] 共通規約 (e) の非適用判断が不適切
- 判断: **対応する**
- 対応内容: シェルトークンの区切り規則を docblock に明記し、指摘の負例群
  (`not-ensure-test-db.php` / `.disabled` / `.bak` / `echo` 経由 / 行末コメントのみ /
  変数・改行継続) を追加する。

### [Warning] CI が直接呼ぶ経路との記述の衝突
- 判断: **対応する**
- 対応内容: CI は `run-test.sh` / `run-browser-test.sh` 経由のみであり直接実行経路は
  無いことを明記する(実装フェーズで `.github/workflows/` 等の実態を確認して裏取りする)。

## 施策7: `template-divergence.md`

### [Warning] 正典より強い到達確認を D30 に混在させるべきではない
- 判断: **対応する**
- 対応内容: D30 には provenance の記録順とスキーマ更新の相互作用だけを記載し、
  到達確認の強化(および専用非キャッシュ設定パスの採用時はそれも)は新しい逸脱登録として
  分離する。

### [Warning] 「差分は独自2関数だけ」の主張が資料から確認できない
- 判断: **対応する**
- 対応内容: 実装受け入れ条件として (1)正典からの移植 (2)既存 D30 由来の差分
  (3)今回追加する schema 確認上の差分、の三分類 diff を残す設計へ変更する。

## 施策8: `worktree-isolation-strategy.md` — APPROVE

- 判断: 対応する (Suggestion を反映)
- 対応内容: 到達確認の保証範囲(子プロセスの実行先監査ではない)を本文へ明記する。

## 施策9: `setup-worktree.sh`

### [Warning] 波及確認が未確定
- 判断: **対応する**
- 対応内容: `scripts/*.contract.test.ts` 群を実装フェーズで確認し、該当する契約テストが
  文言を検査していれば追随、無ければ「文言は恒久契約にしないため追加しない」と明記する。

### [Warning] 警告文が `composer test` のみを案内し browser lane に触れていない
- 判断: **対応する**
- 対応内容: `composer test` と `composer test:browser` の両方を案内する文言へ変更する。

## 追加観点 A〜F の総括

- A(dev DB 4重防御): 施策1・2 の対応で「起動直前の再検証」を追加し、時間差(TOCTOU)を
  専用非キャッシュパスの採用で構造的に排除する方向で次ラウンドへ反映する。
- B(gate 共通規約4点): 施策6 の対応で呼び出し形の厳密判定・(e)適用・負例網羅を満たす。
- C(正典との差分限定): 施策7 の対応で三者 diff を受け入れ条件へ加える。
- D(既存アサーション変更): 現設計のままで問題なし(見送り)。
- E(fail-closed 7条件): 施策2・4 の対応(callable 注入 + 7条件一対一テスト)で満たす。
- F(standalone): 現設計のままで問題なし(見送り)。

## 次ラウンドへの申し送り

上記の対応はいずれも「設計の書き直し」を要する(特に施策2 のオーケストレーション分離は
`detailed-design.md` の該当節を全面的に書き直す規模)。本ラウンドの対応マトリクスまでを
成果物として保存し、`detailed-design.md` 本体の改訂と Round 2 プロンプトの送付は
後続セッションへ引き継ぐ。resume 用セッション ID は本ファイル冒頭に記載済み。
