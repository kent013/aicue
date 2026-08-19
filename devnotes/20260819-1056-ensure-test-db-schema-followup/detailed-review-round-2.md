# 全体判定: CHANGES_REQUESTED

Round 1 の主要論点は大部分が適切に解消されています。特に callable 注入、dev DB 再検証、専用設定キャッシュパス、到達確認の保証範囲是正、D33分離は妥当です。

ただし、テストファイル間の読み込み順によって関数・enumの再宣言エラーになり得る新しい Critical が1件あります。

## 施策1: 接続 resolver — REQUEST_CHANGES

- [Critical] `ensure-test-db.php` 内部が引き続き次の通常 `require` になっています。

```php
require __DIR__.'/pgsql_test_conn.php';
```

`BaseTestDatabaseSchemaTest.php` や `TestDatabaseProvenanceTest.php` は同ファイルを `require_once` します。同一Pest/PHPUnitプロセスで先にそれらが読み込まれ、その後 `TestDatabaseSchemaUpdateTest.php` が `ensure-test-db.php` を読み込むと、通常 `require` により関数と `TestDatabaseEnsureAction` enumが再宣言され、fatal errorになります。

Round 2の「Unit側では個別に読み込まない」対応だけでは、別のテストファイルによる先行読み込みを防げません。

  修正案: `ensure-test-db.php` 内部を次へ変更してください。

```php
require_once __DIR__.'/pgsql_test_conn.php';
```

可能なら `drop-test-db.php` も同じ共有ファイルを通常 `require` していないか確認し、共有ライブラリの読み込み規約を `require_once` に統一してください。読み込み順を変えた正例も追加すると確実です。

- [Warning] `pgsqlTestConfigCachePath()` のdocblockにある「多重起動自体はグローバルテストロックが排除する」は正確ではありません。`setup-worktree.sh` は明示的にロック外で直接実行します。

  修正案: 多重起動排除を安全性の前提から外してください。通常の `config:cache` が専用パスを書かず、異常に存在した場合はfail-closedになる、という説明だけで十分です。

## 施策2: callable注入型オーケストレーション — REQUEST_CHANGES

- [Warning] `ensureTestDatabaseSchemaUpdated()` は純関数ではありません。内部で次の外部状態を直接読みます。

  - `TestDatabaseEnv` の静的判定
  - `pgsqlTestArtisanEnv()`を通した環境変数・`.env.testing`
  - `is_file($configCachePath)`

  callable注入によって主要実行境界を分離できている点は評価できますが、「純粋な意思決定関数」という表現は過大です。

  修正案: 「実DB・子プロセスを直接持たない、主要境界を注入可能なオーケストレーション関数」などへ修正してください。

- [Warning] `ConfigCacheStale` の分岐は2箇所ありますが、テストされるのはmigrate前だけです。migrate実行中に専用パスが出現し、`migrate:status` を起動せず停止する分岐は未検証です。

  修正案: migrate用runnerのフェイクが専用キャッシュファイルを生成するケースを追加し、次を確認してください。

  - 結果が `ConfigCacheStale`
  - runner呼び出しはmigrateの1回だけ
  - `migrate:status`、ファイル列挙、PDO確認へ進まない

- [Warning] `performTestDatabaseSchemaUpdate()` の結線は、Architectureテストで決定的には検証されません。基点DBが既に最新なら、実runnerや実verifyの結線が壊れてもB-2が通る場合があります。設計書自身も「更新したプロセスの監査ではない」と正しく認めているため、「Architectureテストがラッパの結線を検出する」という記述とは整合しません。

  修正案: 次のいずれかを採用してください。

  - 実物callableの組み立てを戻り値として作る小さなfactoryへ分離し、その結線をUnitテストする。
  - `performTestDatabaseSchemaUpdate()`にも依存注入可能な薄い境界を設け、結果のstderr出力・終了判定を別関数で検証する。
  - 少なくとも「Architectureテストが結線を検出する」という保証を削り、非検証範囲として明記する。

## 施策3: Architectureテスト — APPROVE

Round 1の指摘どおり、共有判定が独立した二重検証ではなく一元化であることが明記されました。B-0/B-1/B-2の責務も妥当です。

ただし、施策1の `require` 問題を解消することが前提です。

## 施策4: Unitテスト — REQUEST_CHANGES

- [Warning] 「正常系・全ての失敗系を通しで走らせる」とする許可引数テストは、実際には正常系と未適用残存の2ケースしか実行していません。

  将来、特定の失敗分岐だけに別のartisan呼び出しが加わった場合、この包括テストでは検出できません。

  修正案: 各失敗ケースのrunner呼び出し列も固定するか、シナリオをデータセット化して全分岐の記録を同じ許可集合へ照合してください。少なくともコメントと保証の主張を実態へ合わせる必要があります。

- [Warning] 前述のとおり、migrate後に専用キャッシュが出現する2番目の `ConfigCacheStale` 分岐が未テストです。

  修正案: 独立した負例を追加してください。

- [Warning] 設定キャッシュテストの後始末が不完全です。再帰的に作成するのは次の階層です。

```text
<fixture>/bootstrap/cache
```

しかし削除するのはcacheディレクトリだけで、`bootstrap` とfixtureルートが `/tmp` に残ります。

  修正案: 作成した3階層を内側から明示的に削除するか、既存の一時ディレクトリ支援パターンを利用してください。

- [Suggestion] `runTestDatabaseArtisan()` 自体の、captureあり・なし、起動失敗、出力結合については未検証です。正典から完全移植した部分として三者diffで一致を確認するなら許容できますが、aicue側で変更した場合は専用テストが必要です。

## 施策5: provenance plan — APPROVE

契約拡張として正当です。既存の冪等性を維持し、順序不変条件も明示されています。

## 施策6: グローバルロックgate — REQUEST_CHANGES

- [Warning] 共通規約(e)は「接頭辞つき・打ち消しつき・接尾辞つき」の3形を要求しますが、提示テストは次の2種類です。

  - `not-ensure-test-db.php`：打ち消し形
  - `.bak` / `.disabled`：接尾辞形

独立した接頭辞形がありません。

  修正案: 例えば次を追加してください。

```text
scripts/ci/prefix-ensure-test-db.php
```

- [Warning] docblockの「基点DBのスキーマ更新はここで初めて実DBへ触れる」は事実と異なります。`ensure-test-db.php` は従来からmaintenance DBへの接続とCREATEを行っています。

  修正案: 「新たに追加されるスキーマ更新の子プロセス起動点」など、今回追加する競合対象へ限定してください。

トークン完全一致、echo・行末コメントの拒否、変数展開・改行継続のfail-closed、母集団空振り検査自体は妥当です。

## 施策7: D30/D33 — REQUEST_CHANGES

- [Warning] D33の「多重起動自体はグローバルテストロックが排除する」は、ロック外で実行する `setup-worktree.sh` と矛盾します。

  修正案: この前提を削除し、専用パスは通常処理では生成されず、存在した場合は原因を問わず停止する、と記述してください。

- [Warning] 「本文が指すテストファイルの実在は施策3・4のテストが担保する」は厳密には正しくありません。テストファイルを追加し忘れた場合、そのテスト自身は実行されないためです。

  修正案: 単なる関連リンクとして扱い、実在保証を主張しないでください。既存の文書リンクgateがある場合だけ、そちらを根拠にしてください。

D30とD33の概念分離、三者diffによる差分分類はRound 1の指摘を適切に解消しています。

## 施策8: worktree文書 — APPROVE

到達確認を監査証明としない保証範囲が正しく反映されています。base DBとworker DBの責務分離も明確です。

## 施策9: setup文言 — APPROVE

既存テストの有無を確認し、恒久契約にしないという判断を明記した点、browser laneも案内へ含めた点は妥当です。

## Round 1指摘の解消状況

- callable注入とfail-closed分岐: 概ね解消。ただし2番目のキャッシュ分岐と実ラッパ結線が不足。
- dev DB名再検証: 解消。
- 設定キャッシュTOCTOU: 現実的な既定パス競合は解消。
- 到達確認の過大主張: 解消。
- 7条件のテスト: enum単位では概ね解消。ただし同一enumの別分岐が1件未検証。
- 破壊的コマンド検出: 主軸の変更は妥当。ただし「全失敗系」の主張が実装と不一致。
- global-lock gate: 大幅改善。共通規約(e)の独立した接頭辞負例が不足。
- D30/D33分離: 解消。
- 直接実行ガード: 解消。ただし共有依存を通常 `require` するため、別テストファイルとの読み込み順問題が残る。
- standalone判断: 引き続き妥当。

最優先の修正は `pgsql_test_conn.php` の内部読み込みを `require_once` にすることです。これは設計上の懸念ではなく、テストの読み込み順次第で即座にfatal errorになり得る実装上の欠陥です。