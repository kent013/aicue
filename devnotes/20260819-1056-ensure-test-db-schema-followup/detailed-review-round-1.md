# 全体判定: CHANGES_REQUESTED

方向性は妥当ですが、現状では新しい子プロセス実行境界の安全性と fail-closed 契約をテストで証明できていません。特に dev DB 保護は、設定キャッシュの確認時刻と DB 名再検証の位置に穴があります。

## 施策1: 接続 resolver の拡張 — REQUEST_CHANGES

- [Critical] `pgsqlTestArtisanEnv()` は任意の `$database` をそのまま `DB_DATABASE` に入れます。呼び出し側で一度検証しているだけで、新しい危険境界である子プロセス起動直前には再検証されません。「CREATE / スキーマ更新の直前に再確認」という設計上の主張とも一致しません。

  修正案: `ensureTestDatabaseSchemaUpdated()` 内で、環境構築直前か各 `proc_open()` 直前に `isDevDatabase()` と `isAllowedTestDatabase()` を再実行してください。少なくとも、汎用的に見える `pgsqlTestArtisanEnv()` 単独では安全な実行境界にならないことを関数契約に明記してください。

- [Warning] 親環境から悪意ある値が渡された場合の上書きテストが不足しています。現在のテストは `SOME_SECRET` の非継承と結果の `DB_DATABASE` を別々に見ていますが、親に `DB_DATABASE=dev名`、`DB_URL=pgsql://...`、`APP_CONFIG_CACHE=...` を実際に設定した状態で固定値が勝つことを確認していません。

  修正案: これらを親環境へ設定した負例を追加し、`try/finally` で元の環境を復元してください。

- [Warning] `putenv('SOME_SECRET')` は、テスト開始前から同名変数が存在した場合に元の値を破壊します。アサーション失敗時には後始末も走りません。

  修正案: 元の値を保存し、`try/finally` で「元の値へ戻す／元々無ければ削除」を行ってください。

- [Suggestion] `pgsqlTestMigrationFileNames()` は空入力を許容する純関数として問題ありません。実行境界で非空を要求している点とも矛盾しません。

## 施策2: `ensure-test-db.php` のスキーマ更新 — REQUEST_CHANGES

- [Critical] 設定キャッシュの確認と子プロセス起動の間に時間差があります。

  現在の順序は「キャッシュ確認 → maintenance DB 接続 → 存在確認 → provenance 計算・記録 → 子プロセス起動」です。この間に `bootstrap/cache/config.php` が生成されると、固定した DB 環境変数を Laravel が無視する可能性があります。さらに `migrate` と `migrate:status` の間にも同じ問題があります。

  修正案:

  - 各 `proc_open()` の直前にキャッシュを再検査する。
  - より安全には、通常の `config.php` とは別の、ensure 専用で予測困難または通常の `config:cache` が生成しないパスを `APP_CONFIG_CACHE` に設定し、そのパスが存在しないことを各起動直前に確認する。
  - この変更が正典との差分になる場合は、セキュリティ上の上積みとして別途記録する。

- [Critical] 更新後の直接確認は「子プロセスが基点 DB を更新した証明」にはなりません。基点 DB が既に最新なら、子プロセスが誤って dev DB を更新しても基点 DB の確認は通ります。

  修正案: docblock の「子プロセスの環境変数の解決が壊れていても気付ける」という主張を狭めてください。dev DB 保護は、起動直前の名前再検証、専用の非キャッシュ設定パス、明示的な環境固定で成立させる必要があります。直接 PDO 確認は「基点 DB の最終状態確認」であって、実行先の監査証明ではありません。

- [Critical] 新設する実行境界がほぼ未テストです。純関数のテストと、成功後の DB 状態を観測する Architecture テストだけでは、次を検証できません。

  - 正確な artisan 引数と実行順
  - 構築した `$env` が実際の runner に渡ること
  - 起動失敗、migrate 失敗、status 失敗
  - migration ファイル空振り
  - PDO・テーブル確認失敗
  - 未適用検出
  - それぞれが非ゼロ終了になること

  修正案: スキーマ更新のオーケストレーションを、runner・ファイル列挙・PDO 確認を callable として注入できる関数へ分離してください。関数内で直接 `exit()` せず、型付き結果または例外を返し、main 境界だけがメッセージ出力と `exit(1)` を担当する形が適切です。少なくとも次の7条件を一対一でテストしてください。

  1. 安全でない DB 名または設定状態
  2. `migrate` の起動・終了失敗
  3. `migrate:status` の起動・終了失敗
  4. migration ファイル列挙失敗またはゼロ件
  5. 確認 PDO・SQL の失敗
  6. `migrations` 表不在
  7. 未適用ファイル残存

- [Warning] `migrate:status` の非ゼロは「未適用あり」だけでなく、接続・コマンド実行失敗も表します。現在のメッセージは原因を「未適用」に限定しています。

  修正案: 「migration 状態確認に失敗、または未適用が残っている」と表現し、取得した出力を必ず併記してください。

- [Warning] `glob()` 失敗は `Assert::isArray()` の未捕捉例外により非ゼロ終了するため fail-closed ではありますが、設計が約束する明示エラーにはなりません。

  修正案: `false` と空配列を明示分岐し、別々の診断メッセージを返してください。

## 施策3: `BaseTestDatabaseSchemaTest.php` — APPROVE

B-0、B-1、B-2 の分離、RefreshDatabase を意図的に付けないこと、基点 DB へ PDO で直接接続する判断は妥当です。

- [Suggestion] スクリプトとテストが同じ判定関数を共有するため、これは独立した二重検証ではなく「判定基準の一元化」です。その性質を明記し、純関数の Unit テストを判定関数自体の独立した裏取りとして位置づけると、保証範囲がより正確になります。

## 施策4: `TestDatabaseSchemaUpdateTest.php` — REQUEST_CHANGES

- [Critical] 破壊的コマンドのソース走査は完全ではありません。少なくとも `migrate:reset` が抜けています。また、文字列を分割して組み立てる呼び出しを検出できず、コメント中の同じ文字列は誤検出します。

  修正案: 最も強い方法は、注入した runner が受け取った実際の引数列をテストし、許可された呼び出しが次の2種類だけであることを固定することです。

  - `['migrate', '--force', '--no-interaction']`
  - `['migrate:status', '--pending=1']`

  ソース gate を残すなら、`token_get_all()` 等で配列引数を解析し、解決不能な動的組み立ては fail-closed にしてください。最低限 `migrate:reset` も禁止対象です。

- [Critical] fail-closed の主要分岐をテストしていません。負のコントロールは差分関数が定数 `[]` でないことしか確認せず、子プロセス・確認接続・終了判定の回帰を検出できません。

  修正案: 施策2で示した注入可能なオーケストレーションに対し、7失敗条件と正常系を追加してください。

- [Warning] 親環境を書き換えるテストは必ず元の値を保存し、`try/finally` で復元してください。並列テストや後続テストへの副作用を残さないためです。

- [Suggestion] `tests/Unit` にグローバルな RefreshDatabase が適用される構成なら、「実 DB を作らない」はテスト本体が明示的に DB 操作しないという意味に限定して記述してください。

## 施策5: `TestDatabaseProvenanceTest.php` の更新 — APPROVE

既存契約の削除ではなく、`UpdateSchema` を加えた契約拡張です。両分岐の provenance 冪等性、既存 DB で Create しないこと、実行順の固定が維持されており、カバレッジ後退ではありません。

- [Suggestion] ファイル冒頭の「固定する不変条件」に、両分岐で `UpdateSchema` を含むことと `StampProvenance → UpdateSchema` の順序も追記してください。

## 施策6: `GlobalTestLockInventoryTest.php` — REQUEST_CHANGES

- [Warning] 現在の検出器は実際の呼び出しを確認していません。次の行はいずれも合格します。

```sh
global_test_lock_run echo scripts/ci/ensure-test-db.php
global_test_lock_run true # scripts/ci/ensure-test-db.php
global_test_lock_run php scripts/ci/not-ensure-test-db.php.bak
```

したがって「ensure-test-db.php の呼び出しがロック配下」という不変条件を保証できません。

  修正案: `global_test_lock_run` の後続トークンが正確に `php scripts/ci/ensure-test-db.php` であることを検証してください。現在の2ファイルが完全に同形なら、許容構文を狭く固定する方が安全です。

- [Warning] 共通規約(e)を非適用とする判断は不適切です。`str_contains()` でファイル名語彙を判定しているため、接頭辞・打ち消し・接尾辞による誤一致が発生します。

  修正案: シェルトークンの区切り規則を docblock に宣言し、少なくとも以下の負例を加えてください。

  - `not-ensure-test-db.php`
  - `ensure-test-db.php.disabled`
  - `ensure-test-db.php.bak`
  - `echo ensure-test-db.php`
  - 行末コメントだけに名前がある形
  - 変数・改行継続など解決不能な形

- [Warning] 「CI workflow から直接呼ばない」と、`ensure-test-db.php` の docblock にある「CI が test 前に呼ぶ」が曖昧に衝突しています。

  修正案: CI が `run-test.sh` 経由なのか直接実行なのかを明記し、直接実行箇所が存在するなら inventory に含めてください。

## 施策7: `template-divergence.md` — REQUEST_CHANGES

- [Warning] 正典より強いスキーマ到達確認は、D30の「出自記録・孤児回収」とは別の設計判断です。D30へ統合すると、異なる概念を同じ divergence として管理することになります。

  修正案: D30には provenance と schema update の実行順の相互作用だけを記載し、正典より強い到達確認は新しい divergence 項目として登録してください。専用設定キャッシュパスを採用する場合も同じ項目に含められます。

- [Warning] 「正典との差分が独自2関数だけ」という主張を、提示内容からは確認できません。既存D30の provenance 分岐に加え、Architecture テストの判定共有、到達確認方法、docblock、plan 統合にも差分があります。

  修正案: 実装受け入れ条件として、次の3分類をした三者 diff を残してください。

  1. 正典からそのまま移植した部分
  2. 既存D30由来の差分
  3. 今回新たに追加する schema 確認上の差分

  「2関数」は3の実装部品数として表現し、ファイル全体の差分数とは言わない方が正確です。

## 施策8: `worktree-isolation-strategy.md` — APPROVE

既知のギャップから完了事項を外し、通常本文へ移す方針は妥当です。base DB と worker DB の責務分離も明確です。

- [Suggestion] 「到達確認は子プロセスがその DB を更新した証明ではなく、base DB の最終状態確認」と保証範囲を補足してください。

## 施策9: `setup-worktree.sh` の文言更新 — REQUEST_CHANGES

- [Warning] 影響テストを「対象になっている場合は確認する」としており、詳細設計として波及確認が未確定です。

  修正案: `scripts/setup-worktree.contract.test.ts` 等の実在する検査を確定し、更新要否を設計書へ明記してください。該当テストが無ければ「文言は恒久契約にしないため追加しない」と判断を明記してください。

- [Warning] 警告文は `composer test` だけを正式入口のように案内しますが、本文では `run-browser-test.sh` も再 ensure するとしています。

  修正案: `composer test` と browser lane の正式コマンドを併記してください。

## 追加観点 A〜F

- A: dev DB 4重防御 — 不十分です。名前再検証を子プロセス起動境界へ移し、設定キャッシュの時間差を構造的に排除する必要があります。更新後PDO確認は誤接続の完全な検出にはなりません。
- B: gate共通規約4点 — 未達です。正負例と母集団非空はありますが、実行呼び出しの判定が弱く、(e)の適用判断と解決不能形の網羅が不十分です。
- C: 正典との差分限定 — 現資料だけでは証明されていません。三者 diff による分類が必要です。
- D: 既存アサーション変更 — 正当です。契約拡張でありカバレッジ後退ではありません。
- E: fail-closed 7条件 — 実装上は概ね非ゼロ終了へ倒れますが、起動直前の安全確認と各失敗分岐のテストが欠けています。7条件を明示的に列挙し、一対一で固定してください。
- F: standalone — 妥当です。実装・安全境界・Architecture/Unitテスト・divergence文書が密結合しており、分割すると一時的に不変条件が崩れます。

PHPStanについては、`phpstan.neon` の解析パスが本当に `app/config/database/routes` のみに限定されているなら、「scripts/tests は level 10 の直接対象外」という主張は正しいです。ただし、そのことは新規コードの型安全性がlevel 10で検証されることを意味しません。Pestでの実行、PHPDoc、境界テストを代替保証として扱う必要があります。