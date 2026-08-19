## Round 3 依頼

Round 2 (`detailed-review-round-2.md`) で CHANGES_REQUESTED を受け、対応マトリクス (`codex-history/design-review-decisions-round-2.md`) のとおり `detailed-design.md` を改訂しました。主な変更点 (Round 2 → Round 3) は次のとおりです (詳細は `detailed-design.md` 冒頭の「Round 2 詳細設計レビューからの改訂点」を参照)。

1. [Critical] `ensure-test-db.php` 内部の `require` を `require_once` へ変更し、同じ理由で `scripts/ci/drop-test-db.php` の同じ行も揃えた。読み込み順を変えた回帰テスト(別プロセスで多重 require_once させ fatal にならないことを確認)を追加した。
2. 「多重起動はグローバルテストロックが排除する」という不正確な前提を `pgsqlTestConfigCachePath()` の docblock と D33 の両方から削除し、`setup-worktree.sh` がロック外で呼ぶ事実と矛盾しない記述へ改めた。
3. `ensureTestDatabaseSchemaUpdated()` を「純粋な意思決定関数」と呼ぶのをやめ、外部状態(TestDatabaseEnv の静的判定・.env.testing・is_file）を直接読むことを明記した。
4. 2 つ目の `ConfigCacheStale` 分岐 (migrate 実行中に専用パスが出現) のテストを追加した。
5. 「全ての失敗系を通しで走らせる」テストをデータセット化し、実際に runner へ到達する主要分岐を明示的に列挙して回すよう書き直した(対象外にした分岐の理由も明記)。
6. 一時フィクスチャの後始末漏れ(bootstrap / フィクスチャルートが残る)を、内側から 3 階層を削除するヘルパーで修正した。
7. 共通規約(e)の負例に、打ち消しを伴わない独立した接頭辞形 (`prefix-ensure-test-db.php`) を追加した。
8. 「基点DBのスキーマ更新はここで初めて実DBへ触れる」という誤った docblock を、「今回新たに追加されるスキーマ更新の子プロセス起動点」という限定表現へ是正した。
9. D30 の「本文が指すテストファイルの実在は施策3・4のテストが担保する」という主張を撤回し、単なる関連リンクである旨を明記した。
10. `performTestDatabaseSchemaUpdate()` の結線を `realTestDatabaseSchemaUpdateCallables()` という factory へ分離し、実 DB・実子プロセスに触れない `listMigrationFiles` の結線だけを単体テストで直接固定した。`runArtisan` / `verifyAppliedMigrations` の結線は対象外であることを保証範囲として明記した。

対応マトリクスの詳細は `codex-history/design-review-decisions-round-2.md` を参照してください(本プロンプトでは要約のみ記載)。

## 依頼事項

`detailed-design.md` の全文 (以下に添付) を再レビューし、Round 2 の Critical / Warning が実際に解消されているか、今回の修正自体に新しい欠陥が無いかを判定してください。全体判定 (APPROVED / CHANGES_REQUESTED) を明記してください。

## 詳細設計書全文 (Round 3)

# 詳細設計: テスト DB 準備スクリプトのスキーマ更新への追従 (家系の裁定 AG-135) — Round 2 改訂

## 使命・制約(絶対遵守)

### アプリの使命 (North Star)

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

本件は機能開発そのものではなく、その土台であるテストの信頼性を扱う。基点 DB のスキーマが古いままだと「新しい worktree でだけ落ちる」「実行順で結果が変わる」失敗が起き、実装を必ず worktree で行う進め方(AGENTS.md §worktree 運用ルール)と組み合わさって頻度が高くなる。ここを塞ぐことは AI-CUE の機能開発の速度を直接支える。

### 禁止事項 (AGENTS.md より抜粋・本件に関わるもの)

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
6. prompt 文字列のコード直書き — 本件は非該当
9. Artifact の使用 — 本件は非該当

本件に直接効くのは 1〜3。特に 3 は「migrate だけを使い、fresh/refresh/rollback/wipe は使わない」という形で本設計の全体に効く。

### コーディングルール

- **PHPStan level 10** 必須(`composer phpstan`)。ただし対象は `app` / `config` / `database` / `routes` のみで、`scripts` / `tests` は解析対象外(`phpstan.neon` で確認済み)。本件の変更は `scripts/ci/` と `tests/` に閉じるため、PHPStan level 10 の直接対象ではない。ただし読み手のためと正典との差分を上積みだけに保つため、PHPDoc の shape (`array<string, string>` / `array{ok: bool, failure: ..., message: string}` / `list<string>`) は必ず書く。
- **Pest** テストフレームワーク(`composer test`)。
- **RefreshDatabase** + `--parallel` 並列実行。Architecture lane は `tests/Pest.php` で `RefreshDatabase` を付けていない — 本件の核心的な前提(この lane が基点 DB をそのまま読む)。
- **テストデータは必ず Factory で生成** — 本件は Factory を使うモデルテストではない(スクリプト・純関数の Unit / Architecture テスト)ため非該当。
- **DTO + JsonResource** パターン — 本件は HTTP レイヤに触れないため非該当。
- PHP 8.4 + Laravel 12。

## 概念設計リファレンス

`devnotes/20260819-1056-ensure-test-db-schema-followup/conceptual-design.md` (Codex 概念レビュー Round 3 で APPROVED)。

判断の出所: 家系の裁定 AG-135(機能台帳 `php-test-pgsql-lane`、観測点 laravel-claude-template@ccf465a7)。オーナーが 2026-08-19 に「追従する」と決めた。aicue 側の分岐は aicue:T114 の上積みとして `docs/template-divergence.md` の D30 に登録済みで、D30 は本件を「扱わない範囲(遅れであって逸脱ではない)」として明示的に外している。本設計はこの遅れを解消する。

## Round 1 詳細設計レビューからの改訂点(必読)

Codex 詳細設計レビュー Round 1 (`devnotes/20260819-1056-ensure-test-db-schema-followup/detailed-review-round-1.md`、対応マトリクス `devnotes/20260819-1056-ensure-test-db-schema-followup/codex-history/design-review-decisions-round-1.md`) で CHANGES_REQUESTED を受け、以下を全面改訂した。詳細は各施策の節を参照。

1. **施策2 のオーケストレーションを callable 注入型へ分離**。`ensureTestDatabaseSchemaUpdated()` は artisan 起動・migration ファイル列挙・到達確認 PDO 検証を callable として受け取る**実 DB / 実子プロセスを直接持たない、主要実行境界を注入可能なオーケストレーション関数**へ変え、`exit()` を一切呼ばない(この関数自身は `TestDatabaseEnv` の静的判定・`.env.testing` 経由の環境変数・`is_file()` による設定キャッシュパスの確認など外部状態は直接読むため、「純関数」ではない。**過大な表現をしない**)。型付き結果 (`array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}`) を返し、main 境界の薄いラッパ (`performTestDatabaseSchemaUpdate()`) だけが標準エラー出力と `exit(1)` を担当する。
2. **dev DB 名の再検証をこの関数の先頭に追加**(env 構築より前)。`pgsqlTestArtisanEnv()` 単独が安全な実行境界にならないことを docblock に明記する。
3. **設定キャッシュの TOCTOU を構造的に減らす**: `pgsqlTestConfigCachePath()` を Laravel の既定パス (`bootstrap/cache/config.php`) ではなく、**ensure 専用の非既定パス**を返す関数へ変更し、子プロセスへは常にこのパスを `APP_CONFIG_CACHE` として明示的に渡す。通常の `config:cache` はこのパスを生成しないため、各 `proc_open()` 直前の存在チェックは「ほぼ起こり得ない異常の検出」になる(完全に確率ゼロにはできないが、既定パスの生成競合という現実的な race を構造的に排除する)。
4. **到達確認の主張を是正**: 「子プロセスの環境変数解決が壊れていても気付ける」という過大な主張をやめ、「基点 DB の最終状態確認であり、更新をどのプロセスが行ったかの監査ではない」と明記する。
5. **7 失敗条件(実装上は 9 の enum ケースに分解)を独立したテストケースで固定**。
6. **破壊的コマンド検出の主軸を、ソース文字列 grep から「注入した runner が実際に受け取った引数列の検証」へ移す**。
7. **`GlobalTestLockInventoryTest` の検出器をトークン完全一致へ書き直し**、擬陽性負例を追加する。
8. **D30 と新しい逸脱登録 (D33) を分離**。D30 には出自記録とスキーマ更新の実行順の相互作用だけを残し、正典より強い到達確認と専用非キャッシュパスは D33 として新規登録する。
9. **(自己点検で追加発見) `ensure-test-db.php` に直接実行ガードを追加**。callable 注入型の意思決定関数を Unit テストが `require_once` で読み込むため、`scripts/ci/drop-test-db.php` が既に持つ「直接実行されたときだけ main を走らせる」ガード (`if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) { return; }`) を移植しないと、Unit テストの `require_once` だけで実 DB へ接続する main 処理が走ってしまう。Round 1 のコードにはこのガードが無く、見落としだった。

## Round 2 詳細設計レビューからの改訂点(必読)

Codex 詳細設計レビュー Round 2 (`devnotes/20260819-1056-ensure-test-db-schema-followup/detailed-review-round-2.md`) で再び CHANGES_REQUESTED を受け、以下を改訂した。

1. **[Critical] 共有ファイルの二重ロード**: `ensure-test-db.php` 内部の `require __DIR__.'/pgsql_test_conn.php';` を `require_once` へ変更した。他のテストファイル (`BaseTestDatabaseSchemaTest.php` / `TestDatabaseProvenanceTest.php`) が先に `pgsql_test_conn.php` を `require_once` した後、同一プロセスで `ensure-test-db.php` が読み込まれると、通常 `require` は関数/enum を再宣言して fatal error になるためである。**同じ理由で `scripts/ci/drop-test-db.php` の同じ行も `require_once` へ揃える**(施策1)。この修正を直接裏取りする回帰テストも追加した(施策4)。
2. **`pgsqlTestConfigCachePath()` / D33 の「多重起動はグローバルテストロックが排除する」という誤った前提を削除**。`setup-worktree.sh` はロックの**外**でこのスクリプトを呼ぶため、多重起動を構造的に排除しているとは言えない。「専用パスは通常存在せず、存在したら原因を問わず fail-closed で停止する」という記述だけに絞った(施策1・7)。
3. **`ensureTestDatabaseSchemaUpdated()` を「純粋な意思決定関数」と呼ぶのをやめた**。`TestDatabaseEnv` の静的判定・`.env.testing` 経由の環境変数・`is_file()` は直接読む外部状態であり、「主要な実行境界だけを callable 注入で分離した、実 DB / 実子プロセスを直接持たないオーケストレーション関数」という正確な表現へ改めた(施策2)。
4. **2 つ目の `ConfigCacheStale` 分岐(migrate 実行中に専用パスが出現するケース)のテストを追加**した(施策4)。
5. **「全ての失敗系を通しで走らせる」という主張と実装の乖離を解消**。データセット化したテストへ書き直し、実際に runner へ到達する主要分岐(成功・migrate 失敗・migrate:status 失敗・到達確認の 3 失敗)を明示的に列挙して回すようにした(施策4)。
6. **一時フィクスチャの後始末漏れを修正**: `bootstrap/cache/...` を作る Unit テストが、内側から 3 階層(cache ディレクトリ・`bootstrap` ディレクトリ・フィクスチャルート)を確実に削除するヘルパー (`cleanupEnsureTestDbFixtureRoot()`) を追加した(施策4)。
7. **共通規約(e)の負例を「打ち消し・独立した接頭辞・接尾辞」の 3 形へ拡充**。従来の `not-ensure-test-db.php` は「打ち消し」の意味も持つため、打ち消しを伴わない独立した接頭辞形 (`prefix-ensure-test-db.php`) を追加した(施策6)。
8. **「基点 DB のスキーマ更新はここで初めて実 DB へ触れる」という誤った docblock を是正**。`ensure-test-db.php` 自体は既に CREATE/出自記録で実 DB へ触れているため、「今回新たに追加されるスキーマ更新の子プロセス起動点」という限定した表現へ改めた(施策6)。
9. **D30 の「本文が指すテストファイルの実在は施策3・4 のテストが担保する」という主張を撤回**し、単なる関連リンクである旨を明記した(施策7)。
10. **`performTestDatabaseSchemaUpdate()` の結線検証**: 実物 callable の組み立てを `realTestDatabaseSchemaUpdateCallables()` という純粋な factory へ切り出し、実 DB・実子プロセスに触れない `listMigrationFiles` の結線だけを単体テストで直接固定した。`runArtisan` / `verifyAppliedMigrations` の結線は単体テストの対象にせず、その保証範囲の限界を明記した(施策2・4)。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 接続 resolver へ正典の 3 関数 + アプリ独自の強化 2 関数を追加 | `scripts/ci/pgsql_test_conn.php` | High |
| 2 | `ensure-test-db.php` にスキーマ更新を追加(callable 注入型オーケストレーション) | `scripts/ci/ensure-test-db.php` | High |
| 3 | 基点 DB のスキーマ最新性を検査する Architecture テストを移植 | `tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規) | High |
| 4 | スキーマ更新の意思決定関数を 9 失敗条件 + 正常系 + 引数列検証で固定する Unit テストを追加 | `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (新規) | High |
| 5 | plan の期待値を 3 手順へ更新 | `tests/Unit/Ci/TestDatabaseProvenanceTest.php` | High |
| 6 | ensure-test-db.php の呼び出しがグローバルテストロック配下であることをトークン完全一致で固定する gate ケースを追加 | `tests/Architecture/GlobalTestLockInventoryTest.php` | Medium |
| 7 | D30 の比較表・扱わない範囲を「追従済み」へ更新し、正典より強い到達確認は D33 として新規登録する | `docs/template-divergence.md` | Medium |
| 8 | 「既知のギャップ」の該当項を解消し本文へ記述を移す(到達確認の保証範囲を補足) | `docs/worktree-isolation-strategy.md` | Medium |
| 9 | 工程 [7/7] の見出し・警告文言を更新 | `scripts/setup-worktree.sh` | Low |

---

## 1. 接続 resolver への関数追加

### 変更箇所

`scripts/ci/pgsql_test_conn.php` (現状 156 行)

### 波及変更 (Round 2 レビューで追加: `scripts/ci/drop-test-db.php` の 1 行修正)

Round 2 レビューの Critical 指摘により、`scripts/ci/drop-test-db.php` の

```php
require __DIR__.'/pgsql_test_conn.php';
```

も同じ理由(共有ファイルの二重ロードによる関数/enum 再宣言 fatal error)で

```php
require_once __DIR__.'/pgsql_test_conn.php';
```

へ変更する。**振る舞いは一切変えない**(`require` → `require_once` は同一プロセス内で
2 回目以降の読み込みを skip するだけで、1 回目の読み込み結果は同じ)。これは
`drop-test-db.php` 自体の施策ではないが、`ensure-test-db.php` と同じ共有ファイルを
同じ理由で `require` している以上、片方だけ直しても `tests/Unit/Ci/DropTestDbScriptTest.php`
(既存)と新規テストが同一プロセスで実行された場合に同じ fatal error が再発し得るため、
本 PR で同時に直す。`scripts/ci` 配下の共有ファイル読み込みは全て `require_once` に統一する、
という規約として扱う。

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (新規、施策4)、`tests/Unit/Ci/TestDatabaseProvenanceTest.php` (更新、施策5)、`tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規、施策3) が本ファイルの関数を直接使う

### 変更後コード

冒頭の docblock を、責務が「存在させる」から「存在させ、スキーマを最新にする」へ広がったことが分かるよう更新する(正典 `laravel-claude-template@ccf465a7` の docblock に揃える)。

```php
<?php

declare(strict_types=1);

/*
 * scripts/ci/pgsql_test_conn.php
 *
 * ensure-test-db.php / drop-test-db.php 共有の接続 resolver。
 * ensure-test-db.php は base DB を「存在させ、スキーマを最新にする」ところまで担う
 * (家系の裁定 AG-135)。本ファイルはその接続値解決・環境組み立て・到達確認の判定を
 * ensure/drop の双方へ共有し、「ensure は作るが drop は別 PostgreSQL を見て回収しない」
 * (stale DB) や「スクリプトと検査で判定がずれる」ことを構造的に排除する。
 *
 * (以下、既存の docblock はそのまま維持)
 */
```

enum とその計画関数を拡張する(この部分は Round 1 と変更なし)。

```php
/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
    case UpdateSchema;
}

/**
 * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
 *
 * 実行順は **CREATE → 出自の記録 → スキーマ更新** で固定する。出自の記録をスキーマ更新より
 * 先に置くのは、スキーマ更新が失敗したときに「ラベルの無い現役 DB」を残さないためである
 * (aicue:D30 が揃え続けると宣言した不変条件の 1 つ)。
 *
 * **両分岐とも StampProvenance と UpdateSchema を含む**のが契約: 既存 DB のときに省くと、
 * 前者は「ラベルの無い現役 DB」、後者は「基点 DB のスキーマが古いまま放置される」につながる
 * (= どちらも冪等にする)。
 *
 * @return list<TestDatabaseEnsureAction>
 *         $exists=false → [Create, StampProvenance, UpdateSchema] /
 *         $exists=true  → [StampProvenance, UpdateSchema]
 */
function testDatabaseEnsurePlan(bool $exists): array
{
    return $exists
        ? [TestDatabaseEnsureAction::StampProvenance, TestDatabaseEnsureAction::UpdateSchema]
        : [
            TestDatabaseEnsureAction::Create,
            TestDatabaseEnsureAction::StampProvenance,
            TestDatabaseEnsureAction::UpdateSchema,
        ];
}
```

正典由来の関数を、正典 `laravel-claude-template@ccf465a7` と同名・同挙動で追加する。**`pgsqlTestConfigCachePath()` の返り値は Round 1 から変更している**(下記参照)。

```php
/**
 * 指定した DB への PDO (スキーマ更新後の到達確認用。maintenance DB ではない)。
 */
function pgsqlTestDatabasePdo(string $projectRoot, string $database): PDO
{
    $c = pgsqlTestConnValues($projectRoot);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname={$database}";

    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

/**
 * スキーマ更新の子プロセス**専用**の設定キャッシュパス。
 *
 * **Round 1 からの変更点**: Laravel の既定パス (`bootstrap/cache/config.php`) を返す形を
 * やめ、通常の `php artisan config:cache` が**絶対に書かない**専用パスを返すようにした。
 * `pgsqlTestArtisanEnv()` はこの値をそのまま `APP_CONFIG_CACHE` として子プロセスへ渡すため、
 * 子プロセスは既定パスの残存状態に一切左右されない(既定パスが別プロセスの `config:cache` で
 * 再生成されても、子プロセスはこの専用パスしか見ない)。
 *
 * この専用パスの存在チェック (`ensureTestDatabaseSchemaUpdated()` が各 artisan 起動の直前に行う)
 * は、したがって「よくある race の検出」ではなく「この専用パスを書くのはこのスクリプト自身の
 * 子プロセスだけのはずなのに、なぜか既に存在している」という**通常は起こらない異常**の検出になる。
 * **多重起動の排除までは主張しない**: `scripts/ci/setup-worktree.sh` はグローバルテストロックの
 * **外**でこのスクリプトを呼ぶため (worktree 作成そのものを壊さないための意図的な設計。
 * 施策8 参照)、同一 worktree 内で本スクリプトが多重に起動される余地は理論上ゼロではない。
 * この専用パスの存在チェックが fail-closed で拾うのは、その多重起動を含む「原因を問わず
 * 専用パスが既に存在する」という異常そのものである。
 */
function pgsqlTestConfigCachePath(string $projectRoot): string
{
    return $projectRoot.'/bootstrap/cache/ensure-test-db-schema-update.config-cache.php';
}

/**
 * スキーマ更新の子プロセスへ渡す環境変数を **継承せず** 組み立てる。
 *
 * 継承しないのが要点である: この devcontainer では shell に dev DB 名が export されており、
 * 素直に継承すると更新が dev DB へ当たる (AGENTS.md 禁止事項 3)。
 * DB 接続先は pgsqlTestConnValues() で解決した値をそのまま渡し、phpunit 本体と
 * 同じ PostgreSQL を見ることを保つ。
 *
 * URL 形の接続指定は DB_URL 1 つだけを空で固定する — config/database.php が読む URL 形の
 * キーは env('DB_URL') だけであり、読み手のいないキーを足すと「効いているつもりの設定」が
 * 増えるだけだからである。
 *
 * **この関数単独は安全な実行境界にならない**。渡された `$database` をそのまま
 * `DB_DATABASE` に固定するだけであり、`$database` が dev DB かどうかの判定は行わない。
 * 呼び出し側 (`ensureTestDatabaseSchemaUpdated()`) が、この関数を呼ぶ**直前**に
 * `TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()` を再検証する契約になっている。
 *
 * @return array<string, string>
 */
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
{
    $conn = pgsqlTestConnValues($projectRoot);

    $inherited = [];
    foreach (['PATH', 'HOME', 'TMPDIR'] as $key) {
        $value = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);
        if (is_string($value) && $value !== '') {
            $inherited[$key] = $value;
        }
    }

    // 固定値が常に勝つ順で合成する。
    return array_merge($inherited, [
        'APP_ENV' => 'testing',
        'APP_CONFIG_CACHE' => pgsqlTestConfigCachePath($projectRoot),
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => '',
        'DB_HOST' => $conn['host'],
        'DB_PORT' => $conn['port'],
        'DB_USERNAME' => $conn['username'],
        'DB_PASSWORD' => $conn['password'],
        'DB_DATABASE' => $database,
        'CACHE_STORE' => 'array',
    ]);
}
```

さらに、**正典より 1 段強い到達確認**を成り立たせるための、aicue 独自の 2 つの純関数を追加する(Round 1 と同じ。D33 として divergence 登録する対象は主にこの 2 関数と専用非キャッシュパスである。施策7 参照)。

```php
/**
 * database/migrations のファイル名一覧 (拡張子・ディレクトリ抜き) を返す。
 *
 * ensure-test-db.php の到達確認と tests/Architecture/BaseTestDatabaseSchemaTest.php の
 * B-2 が **同じ関数** を呼ぶことで、判定基準がスクリプトと検査でずれないようにする。
 *
 * @param  list<string>  $migrationPaths  glob() が返すファイルパスの列
 * @return list<string>
 */
function pgsqlTestMigrationFileNames(array $migrationPaths): array
{
    return array_values(array_map(
        static fn (string $path): string => basename($path, '.php'),
        $migrationPaths,
    ));
}

/**
 * 到達確認の判定 (正典より強い基準)。
 *
 * 正典の到達確認は「migrations 表があり行が 1 件以上ある」で止まる。これでは
 * **古い基点 DB に古い migrations 表が残っている**状態を通してしまう。
 * 本関数は比較の向きをファイル→表の包含にする (集合の一致は求めない。vendor パッケージ由来の
 * migration が表に増えうるため)。
 *
 * @param  list<string>  $appliedMigrations  migrations 表の migration 列
 * @param  list<string>  $migrationFileNames  database/migrations の全ファイル名 (拡張子抜き)
 * @return list<string>  未適用のファイル名 (空 = 到達確認 OK)
 */
function pgsqlTestSchemaUnappliedMigrations(array $appliedMigrations, array $migrationFileNames): array
{
    return array_values(array_diff($migrationFileNames, $appliedMigrations));
}
```

### PHPStan 適合チェック

- [x] 戻り値の型が明示されている(`array<string, string>` / `list<string>` を PHPDoc で明示)
- [x] null 安全(`Webmozart\Assert\Assert` を呼び出し側で使用。本ファイル自体は既存方針どおり Assert を持たない純関数)
- [x] 配列 shape を書いている
- [x] 本ファイルは `phpstan.neon` の解析対象外(`scripts/`)であることを docblock ではなく本設計書に明記済み(誇張しない)

### テスト計画

- [x] 新規: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` — 施策4 で詳述
- [x] 更新: `tests/Unit/Ci/TestDatabaseProvenanceTest.php` — 施策5 で詳述
- [x] 個別の `DatabaseTransactions` を使っていない(本ファイルは Laravel の外で動く素の PHP スクリプトであり RefreshDatabase の対象外)
- [x] **新規(Round 1 Warning 対応)**: 親環境へ `DB_DATABASE=<dev DB 名>` / `DB_URL=pgsql://...` / `APP_CONFIG_CACHE=<任意のパス>` を実際に設定した状態で `pgsqlTestArtisanEnv()` の固定値が勝つことを確認する負例(施策4 で詳述)
- [x] **新規(Round 1 Warning 対応)**: `putenv()` を使うテストは元の値を保存し `try/finally` で復元する(施策4 で詳述)

### リスク

- 正典と関数名を揃えることで、家系キュレーターの md5 比較・実読による「新しい分岐」の誤検出を防ぐ。一方で `pgsqlTestMigrationFileNames` / `pgsqlTestSchemaUnappliedMigrations` の 2 関数と `pgsqlTestConfigCachePath()` の返り値(専用非キャッシュパス)は aicue 独自(正典に無い)なので、D33 として divergence 登録する(施策7)。

---

## 2. `ensure-test-db.php` へのスキーマ更新の追加(callable 注入型オーケストレーション)

### 変更箇所

`scripts/ci/ensure-test-db.php` (現状 62 行)

### 波及変更

- テストファイル: 施策3・4・5・6 が対象。他に波及なし。

### 設計方針(Round 1 からの主要な変更)

意思決定本体 `ensureTestDatabaseSchemaUpdated()` は次の 3 つの実行境界を **callable として受け取り**、それ自体は `exit()` も `fwrite()` も行わない。型付き結果 `array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}` を返す、実 DB / 実子プロセスを直接持たないオーケストレーション関数にする。**「純関数」ではない**: `TestDatabaseEnv::isDevDatabase()` / `isAllowedTestDatabase()` の静的判定、`pgsqlTestArtisanEnv()` が読む `.env.testing` 経由の環境変数、`is_file()` による設定キャッシュパスの確認は、この関数が直接読む外部状態である。「主要な実行境界(子プロセス起動・ファイル列挙・DB 接続)だけを callable 注入で切り離した」という範囲に限定して主張する。

1. `$runArtisan`: `callable(list<string> $args, array<string,string> $env, bool $capture): array{status: int, output: string}` — artisan 起動
2. `$listMigrationFiles`: `callable(string $projectRoot): (list<string>|false)` — `database/migrations/*.php` の列挙。`glob()` の失敗 (`false`) とファイル 0 件 (`[]`) を型で区別する
3. `$verifyAppliedMigrations`: `callable(string $projectRoot, string $base): array{tableExists: bool, applied: list<string>}` — 到達確認の PDO 接続 + クエリ。接続/クエリ失敗は例外を投げる契約

main 境界の薄いラッパ `performTestDatabaseSchemaUpdate()` だけが、これら 3 つに実物 (`runTestDatabaseArtisan()` / `glob()` / `pgsqlTestDatabasePdo()` を使った実装) を注入し、結果を見て `fwrite(STDERR, ...)` と `exit(1)` を行う。**`exit()` を直接呼ぶのはスクリプト全体でここと、既存のトップレベルの数か所だけ**にする。

この分離により、`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` はフェイクの callable を注入するだけで、実 DB・実子プロセスに一切触れずに 9 つの失敗条件と正常系・引数列を固定できる(施策4)。

### 失敗条件の列挙 (Round 1 レビューの「7 条件」を実装上 9 の enum ケースへ分解)

Round 1 レビューが列挙した 7 条件のうち、(1) と (4) はそれぞれ 2 つの異なる失敗経路(判定場所も再現方法も異なる)を含んでいたため、実装ではそれぞれ独立した enum ケースに分けた。**7 条件を 1 つも落としていないことの対応表**を以下に示す。

| Round 1 レビューの条件 | 実装上の enum ケース |
|---|---|
| 1. 安全でない DB 名または設定状態 | `UnsafeDatabaseName`(名前側)/ `ConfigCacheStale`(設定キャッシュ側) |
| 2. `migrate` の起動・終了失敗 | `MigrateFailed` |
| 3. `migrate:status` の起動・終了失敗 | `MigrateStatusFailed` |
| 4. migration ファイル列挙失敗またはゼロ件 | `MigrationFileEnumerationFailed`(glob 失敗)/ `NoMigrationFiles`(0 件) |
| 5. 確認 PDO・SQL の失敗 | `VerificationConnectionFailed` |
| 6. `migrations` 表不在 | `MigrationsTableMissing` |
| 7. 未適用ファイル残存 | `UnappliedMigrationsRemain` |

### 変更後コード

```php
<?php

declare(strict_types=1);

/*
 * scripts/ci/ensure-test-db.php
 *
 * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を「存在させ、
 * スキーマを最新にする」ところまで担う (家系の裁定 AG-135 への追従)。
 * Laravel の ParallelTesting は base に `_test_<token>` を付した worker DB を作るが、
 * DB 系 trait を使わない Architecture のレーンは base DB をそのまま読むため、
 * base DB のスキーマが古いままだと「新しい worktree でだけ落ちる」
 * 「実行順で結果が変わる」失敗になる。
 * run-test.sh / run-browser-test.sh / setup-worktree.sh が test 前に本スクリプトを呼ぶ
 * (CI は run-test.sh / run-browser-test.sh 経由でのみ呼び、ワークフローから直接
 * 本スクリプトを叩く経路は運用していない)。
 *
 * dev-DB 保護 (4 重。AGENTS.md 禁止事項 3):
 *   1. 名前の出所 — 基点名は TestDatabaseEnv::pgsqlBaseDatabase() の 1 か所だけが決める
 *   2. 名前の検査 — allowlist 一致 + dev 名 deny を、CREATE の直前と
 *      スキーマ更新 (ensureTestDatabaseSchemaUpdated()) の先頭の 2 箇所で再確認する
 *   3. 子プロセスの環境 — 継承せず許可リストで組み立て、DB_DATABASE を算出した基点名で固定し、
 *      設定キャッシュも ensure 専用の非既定パスへ固定する (この devcontainer の shell には
 *      dev DB 名が export されており、素直に継承するとスキーマ更新が dev DB に当たる)
 *   4. 到達確認 — 更新後に基点 DB へ直接つなぎ、database/migrations の全ファイルが
 *      適用済みであることまで確かめる (正典より 1 段強い基準。下記参照)
 *
 * 到達確認は正典より強い: 正典 (laravel-claude-template) は「migrations 表があり
 * 行が 1 件以上ある」で止まるが、それでは古い基点 DB に古い migrations 表が残っている
 * 状態を通してしまう。本スクリプトは pgsqlTestSchemaUnappliedMigrations() で
 * 「migrations 表が存在し、database/migrations の全ファイル名がその表に含まれる」を
 * 成功条件にする。tests/Architecture/BaseTestDatabaseSchemaTest.php の B-2 と
 * 同じ関数を共有しており、スクリプトと検査で判定がずれない。
 * **保証しないこと**: この到達確認は「基点 DB の最終状態がスキーマ最新である」ことの
 * 確認であって、直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査では
 * ない (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
 * 更新していても、この確認だけでは検出できない)。dev DB 保護は、この到達確認では
 * なく、上記 1〜3 (名前の出所の一本化・起動直前の再検証・非継承の環境固定) で成立させる。
 *
 * 出自の記録 (COMMENT ON DATABASE) は best-effort、スキーマ更新は fail-closed — この
 * 非対称は意図である。出自は孤児 sweep の分類材料にすぎず権限差で偽赤を増やしたくないが、
 * スキーマ更新の失敗を見逃すと基点 DB が古いまま「たまたま」テストが通ってしまう。
 *
 * 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
 *
 * 保証しないこと: スキーマ更新に実行時間の見張りを持たない (子プロセスが DB のロック待ちで
 * 止まれば本スクリプトも止まる。既存のテスト入口も同じで、待ちの仕掛けは持ち込まない)。
 * 接続の待ちだけは PDO の ATTR_TIMEOUT 10 秒が効く。
 */

use Tests\Support\Ci\TestDatabaseEnv;
use Webmozart\Assert\Assert;

require_once __DIR__.'/../../vendor/autoload.php';
// **Round 2 レビューで発見された Critical の修正**: 通常の require だと、同一プロセスで
// 先に (Architecture/Unit テストなどから) pgsql_test_conn.php が require_once 済みの状態で
// 本ファイルが require_once されたとき、この行が同じファイルをもう一度パース・実行し、
// 関数と TestDatabaseEnsureAction enum の再宣言で fatal error になる。
// require_once へ統一する (drop-test-db.php も同じ行を持つため、本 PR で同時に
// require_once へ揃える。scripts/ci 配下の共有ファイルは全て require_once で読み込む規約にする)。
require_once __DIR__.'/pgsql_test_conn.php';

/** ensureTestDatabaseSchemaUpdated() が返す失敗理由。main 境界がメッセージ選定に使う。 */
enum TestDatabaseSchemaUpdateFailure
{
    case UnsafeDatabaseName;
    case ConfigCacheStale;
    case MigrateFailed;
    case MigrateStatusFailed;
    case MigrationFileEnumerationFailed;
    case NoMigrationFiles;
    case VerificationConnectionFailed;
    case MigrationsTableMissing;
    case UnappliedMigrationsRemain;
}

/**
 * 環境変数を継承しない artisan の起動 (laravel-claude-template@ccf465a7 と同名・同挙動)。
 *
 * shell を通さない配列形の proc_open を使う (引用の取り違えを構造的に無くす)。
 * 出力を捨てずに取りたい場合は一時ファイルへ落とす — pipe を使うと、片方を読み切るまで
 * もう片方が詰まる形になり、出力が増えたときに固まりうるためである (ここで必要なのは
 * 失敗時に見せる文言だけなので、非同期に読む仕掛けは持たない)。
 *
 * @param  list<string>  $args
 * @param  array<string, string>  $env
 * @return array{status: int, output: string}
 */
function runTestDatabaseArtisan(string $projectRoot, array $args, array $env, bool $capture): array
{
    if (! $capture) {
        $descriptors = [0 => ['file', '/dev/null', 'r'], 1 => STDERR, 2 => STDERR];
        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
        if (! is_resource($process)) {
            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
        }

        return ['status' => proc_close($process), 'output' => ''];
    }

    // stdout と stderr は別々の一時ファイルへ落とす。同じファイルを 2 つの descriptor で
    // 開くと書き込み位置が独立するため、片方がもう片方の内容を踏みつぶしうる。
    $outPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-out-');
    $errPath = tempnam(sys_get_temp_dir(), 'ensure-test-db-err-');
    if ($outPath === false || $errPath === false) {
        if ($outPath !== false) {
            @unlink($outPath);
        }
        if ($errPath !== false) {
            @unlink($errPath);
        }

        return ['status' => 1, 'output' => "failed to create temporary files for output\n"];
    }

    try {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $outPath, 'w'],
            2 => ['file', $errPath, 'w'],
        ];

        $process = proc_open([PHP_BINARY, 'artisan', ...$args], $descriptors, $pipes, $projectRoot, $env);
        if (! is_resource($process)) {
            return ['status' => 1, 'output' => "failed to start: artisan {$args[0]}\n"];
        }

        $status = proc_close($process);

        return [
            'status' => $status,
            'output' => (string) file_get_contents($outPath).(string) file_get_contents($errPath),
        ];
    } finally {
        @unlink($outPath);
        @unlink($errPath);
    }
}

/**
 * @return array{ok: false, failure: TestDatabaseSchemaUpdateFailure, message: string}
 */
function testDatabaseSchemaUpdateFailure(TestDatabaseSchemaUpdateFailure $failure, string $message): array
{
    return ['ok' => false, 'failure' => $failure, 'message' => $message];
}

/**
 * base DB のスキーマ更新の**意思決定関数** (UpdateSchema action の本体)。
 *
 * `exit()` も `fwrite()` も行わない。実 artisan 起動・ファイル列挙・PDO 接続はすべて
 * callable として受け取り、実行順・分岐・メッセージ選定だけをこの関数が担う。
 * これにより `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` は実 DB・実子プロセスなしで
 * 9 つの失敗経路と正常系、artisan へ渡る引数列そのものを固定できる。
 *
 * 実行順: (1) dev DB 名の再検証 → (2) 設定キャッシュの残存確認 → (3) migrate →
 * (4) 設定キャッシュの再確認 → (5) migrate:status → (6) migration ファイル列挙 →
 * (7) 到達確認の PDO 検証 → (8) migrations 表の存在確認 → (9) 未適用差分の判定。
 *
 * @param  callable(list<string>, array<string, string>, bool): array{status: int, output: string}  $runArtisan
 * @param  callable(string): (list<string>|false)  $listMigrationFiles  glob() 相当。false = 列挙失敗、[] = ファイル0件 (型で区別する)
 * @param  callable(string, string): array{tableExists: bool, applied: list<string>}  $verifyAppliedMigrations  接続/クエリ失敗時は例外を投げる契約
 * @return array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}
 */
function ensureTestDatabaseSchemaUpdated(
    string $projectRoot,
    string $base,
    callable $runArtisan,
    callable $listMigrationFiles,
    callable $verifyAppliedMigrations,
): array {
    // (1) dev DB 二重防御: pgsqlBaseDatabase() 内でも検査済みだが、
    //     スキーマ更新という実行境界の直前にもう一度確認する (env 構築より前)。
    if (TestDatabaseEnv::isDevDatabase($base) || ! TestDatabaseEnv::isAllowedTestDatabase($base)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName,
            "safety check failed for computed base database name: {$base}",
        );
    }

    $env = pgsqlTestArtisanEnv($projectRoot, $base);
    $configCachePath = pgsqlTestConfigCachePath($projectRoot);
    $where = "db={$base} host={$env['DB_HOST']}:{$env['DB_PORT']}";

    // (2) migrate 起動直前の設定キャッシュ確認。
    if (is_file($configCachePath)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
            "ensure 専用の設定キャッシュが既に存在するため migrate を起動せず中止します: {$configCachePath}",
        );
    }

    // 更新自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
    // 同じ判定を二重に持つことになる (毎回無条件で実行する)。
    $migrate = $runArtisan(['migrate', '--force', '--no-interaction'], $env, false);
    if ($migrate['status'] !== 0) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrateFailed,
            "ensure-test-db: スキーマ更新に失敗しました ({$where}, exit={$migrate['status']})",
        );
    }

    // (4) migrate:status 起動直前にも同じ設定キャッシュを再確認する
    //     (migrate の実行中に生成される異常も見逃さない)。
    if (is_file($configCachePath)) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::ConfigCacheStale,
            "ensure 専用の設定キャッシュが migrate 実行後に出現したため migrate:status を起動せず中止します: {$configCachePath}",
        );
    }

    // 未適用が残っていないことを artisan 自身の判定で確かめる。
    // 値を渡したときだけその値が終了コードになる (値を渡さない形は未適用があっても 0 を返す)。
    $pending = $runArtisan(['migrate:status', '--pending=1'], $env, true);
    if ($pending['status'] !== 0) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrateStatusFailed,
            "ensure-test-db: migration の状態確認に失敗、または未適用が残っています ({$where})\n{$pending['output']}",
        );
    }

    // (6) 別経路の到達確認の準備: 基点 DB へ直接つないで
    //     database/migrations の全ファイルが適用済みであることを確かめる。
    $files = $listMigrationFiles($projectRoot);
    if ($files === false) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed,
            'ensure-test-db: database/migrations の列挙に失敗しました (glob failure)',
        );
    }
    if ($files === []) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::NoMigrationFiles,
            'ensure-test-db: database/migrations にファイルがありません (到達確認が空振りするため中止)',
        );
    }
    $expected = pgsqlTestMigrationFileNames($files);

    try {
        $verification = $verifyAppliedMigrations($projectRoot, $base);
    } catch (Throwable $e) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed,
            "ensure-test-db: 更新後の確認接続に失敗しました ({$where}): {$e->getMessage()}",
        );
    }

    if (! $verification['tableExists']) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::MigrationsTableMissing,
            "ensure-test-db: 更新後も migrations 表がありません ({$where})",
        );
    }

    $unapplied = pgsqlTestSchemaUnappliedMigrations($verification['applied'], $expected);
    if ($unapplied !== []) {
        return testDatabaseSchemaUpdateFailure(
            TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain,
            "ensure-test-db: 更新後も未適用の migration ファイルが残っています ({$where}): ".implode(', ', $unapplied),
        );
    }

    return [
        'ok' => true,
        'failure' => null,
        'message' => 'ensure-test-db: schema up to date: '.$base.' ('.count($verification['applied']).' migrations)',
    ];
}

/**
 * `performTestDatabaseSchemaUpdate()` が使う実物 callable の組み立て (Round 2 レビュー対応)。
 *
 * **Round 2 からの変更点**: Round 2 まではこの組み立てを `performTestDatabaseSchemaUpdate()`
 * の内部に直接書いていたため、「結線 (実物 callable の組み立て自体) が壊れていないこと」を
 * 検証する手段が無かった (Architecture テスト B-1/B-2 は「基点 DB の最終状態」しか見ないため、
 * 既に最新の基点 DB に対しては結線が壊れていても偶然通ってしまう。これは施策2 の到達確認が
 * 「監査ではない」と認めている保証範囲の限界と同じ理由である)。
 * 組み立てを本 factory へ切り出すことで、実 DB・実子プロセスに触れない範囲
 * (`listMigrationFiles` の結線)だけは単体テストできるようにする。
 *
 * **保証しないこと**: `runArtisan` と `verifyAppliedMigrations` の結線自体は、実子プロセス起動・
 * 実 PDO 接続を伴うため単体テストの対象にしない(呼び出す関数本体 `runTestDatabaseArtisan()` /
 * `pgsqlTestDatabasePdo()` は正典からそのまま移植した部分であり、三者 diff で
 * 「正典からの移植」に分類する。施策7 参照)。この 2 つの結線が壊れていないことは、
 * `tests/Architecture/BaseTestDatabaseSchemaTest.php` の B-1/B-2 が(監査ではなく最終状態の
 * 観測として)間接的にしか裏取りしない。
 *
 * @return array{
 *     runArtisan: callable(list<string>, array<string, string>, bool): array{status: int, output: string},
 *     listMigrationFiles: callable(string): (list<string>|false),
 *     verifyAppliedMigrations: callable(string, string): array{tableExists: bool, applied: list<string>},
 * }
 */
function realTestDatabaseSchemaUpdateCallables(string $projectRoot): array
{
    return [
        'runArtisan' => static fn (array $args, array $env, bool $capture): array => runTestDatabaseArtisan($projectRoot, $args, $env, $capture),
        'listMigrationFiles' => static fn (string $root): array|false => glob($root.'/database/migrations/*.php'),
        'verifyAppliedMigrations' => static function (string $root, string $db): array {
            $pdo = pgsqlTestDatabasePdo($root, $db);
            $table = $pdo->query("SELECT to_regclass('public.migrations')")->fetchColumn();
            if ($table === null || $table === false) {
                return ['tableExists' => false, 'applied' => []];
            }
            /** @var list<string> $applied */
            $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

            return ['tableExists' => true, 'applied' => $applied];
        },
    ];
}

/**
 * main 境界のラッパ。`realTestDatabaseSchemaUpdateCallables()` が組み立てた実物 callable を
 * 注入して `ensureTestDatabaseSchemaUpdated()` を呼び、結果を stderr へ書いて非成功時のみ
 * `exit(1)` する。
 *
 * ラッパ自身 (fwrite・exit の配線) は実 DB / 実子プロセスに触れるため単体テストの対象にしない
 * (概念設計の既存方針どおり、意思決定本体である `ensureTestDatabaseSchemaUpdated()` の側を
 * 単体テストする)。
 */
function performTestDatabaseSchemaUpdate(string $projectRoot, string $base): void
{
    $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
    $result = ensureTestDatabaseSchemaUpdated(
        $projectRoot,
        $base,
        $callables['runArtisan'],
        $callables['listMigrationFiles'],
        $callables['verifyAppliedMigrations'],
    );

    fwrite(STDERR, $result['message']."\n");
    if (! $result['ok']) {
        exit(1);
    }
}

// ───────────────────────── entrypoint ─────────────────────────

/*
 * 直接実行されたときだけ main を走らせる (scripts/ci/drop-test-db.php と同じ既存パターン)。
 *
 * **Round 1 からの変更点**: 施策4 の Unit テストは、注入可能な意思決定関数
 * (`ensureTestDatabaseSchemaUpdated()`) を直接呼ぶために本ファイルを `require_once` する。
 * このガードが無いと `require_once` だけで実 DB へ接続する main 処理が走ってしまう
 * (Round 1 のコードにはこのガードが無く、見落としだった)。
 */
if (! isset($argv[0]) || realpath($argv[0]) !== realpath(__FILE__)) {
    return;
}

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE の直前に再確認)。
Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

try {
    $pdo = pgsqlTestMaintenancePdo($projectRoot);
} catch (Throwable $e) {
    fwrite(STDERR, "ensure-test-db: failed to connect to maintenance DB (postgres): {$e->getMessage()}\n");
    exit(1);
}

$stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
$stmt->execute(['name' => $base]);
$exists = $stmt->fetchColumn() !== false;

// 出自 (worktree の realpath) を記録/更新する (非破壊の COMMENT ON DATABASE)。
// 孤児 sweep (drop-test-db.php --orphans) の分類材料であって guard ではない。
// 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$provenance = realpath($projectRoot);
Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");

// 実行順は CREATE → 出自の記録 → スキーマ更新 (aicue:D30 の不変条件)。
foreach (testDatabaseEnsurePlan($exists) as $action) {
    match ($action) {
        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
            static fn (string $sql): mixed => $pdo->exec($sql),
            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
        ),
        TestDatabaseEnsureAction::UpdateSchema => performTestDatabaseSchemaUpdate($projectRoot, $base),
    };
}

fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

### PHPStan 適合チェック

- [x] 戻り値の型明示(`array{ok: bool, failure: TestDatabaseSchemaUpdateFailure|null, message: string}` / `array{status: int, output: string}` / `void`)
- [x] `Assert::isArray` を使わない(`glob()` の戻り値は `$listMigrationFiles` の型 `list<string>|false` で受け、呼び出し側で明示分岐する形にしたため不要になった)
- [x] `Assert::string` で null 安全(realpath の検査は既存どおり維持)
- [x] `scripts/` は PHPStan level 10 の対象外(誇張しない。上記どおり明記)

### テスト計画

- [x] バグ修正ではなく機能追加のため、先に施策4・5 のテストを赤くしてから本体を書く(テストファースト)
- [x] 既存テスト `tests/Unit/Ci/TestDatabaseProvenanceTest.php` の更新(施策5)
- [x] 新規テスト `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`(施策4): `ensureTestDatabaseSchemaUpdated()` の 9 失敗条件 + 正常系 + artisan へ渡る引数列そのものをフェイク callable で固定する
- [x] 新規 Architecture テスト `tests/Architecture/BaseTestDatabaseSchemaTest.php`(施策3)
- [x] 個別の `DatabaseTransactions` は使っていない

### リスク

- **実行時間の増加**: artisan 起動が最大 2 回(migrate + migrate:status)増える。正典の実測は「何もしないとき約 0.53 秒 / 空の DB から全適用で約 0.66 秒」。実装フェーズで aicue でも実測し、docblock に記録する(概念設計の制約・前提に明記済み)。
- **`performTestDatabaseSchemaUpdate()` 自身(fwrite・exit の配線)は単体テストの対象にしない**: 実 DB / 実子プロセスに触れるラッパであり、意思決定本体 (`ensureTestDatabaseSchemaUpdated()`) 側でロジックを固定する。**Round 2 の改訂で `realTestDatabaseSchemaUpdateCallables()` へ結線を分離し、`listMigrationFiles` の結線だけは単体テストで直接固定する**(施策4)。`runArtisan` / `verifyAppliedMigrations` の結線自体(実子プロセス起動・実 PDO 接続を伴う部分)は単体テストの対象にせず、`tests/Architecture/BaseTestDatabaseSchemaTest.php` の B-1/B-2 が間接的に(監査ではなく最終状態の観測として)裏取りする、という保証範囲の限界を明記する。
- **`runTestDatabaseArtisan()` 自体の専用テストは追加しない**: capture の有無・起動失敗・stdout/stderr 結合といった細部は正典 `laravel-claude-template@ccf465a7` からそのまま移植した部分であり(施策7 の三者 diff で「正典からの移植」に分類)、aicue 側で変更していないため、思考原則2(今必要なものだけ作る)に照らして新規テストを追加しない。aicue が将来この関数へ変更を加える場合は、その時点で専用テストを追加する。

---

## 3. `BaseTestDatabaseSchemaTest.php` の移植

### 変更箇所

`tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規)

### 波及変更

なし(新規テストファイルのみ)。

### 変更後コード

正典 `laravel-claude-template@ccf465a7` の `tests/Architecture/BaseTestDatabaseSchemaTest.php` を移植し、B-2 の到達確認を aicue 共有の `pgsqlTestMigrationFileNames` / `pgsqlTestSchemaUnappliedMigrations` を使う形に変える(正典は自前で `array_diff` を書いているが、aicue はスクリプト側と同じ関数を呼ぶことで判定のずれを構造的に防ぐ)。**Round 1 の Suggestion を反映し、判定基準の共有が独立した二重検証ではなく「判定基準の一元化」であることを docblock に明記した。**

```php
<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Ci\TestDatabaseEnv;

/*
|--------------------------------------------------------------------------
| 基点テスト DB が最新スキーマであること (家系の裁定 AG-135)
|--------------------------------------------------------------------------
|
| Architecture lane は tests/Pest.php で RefreshDatabase を付けていない
| (ファイル走査中心で DB を使わないため)。並列実行では DB 系 trait を使うテストのときだけ
| worker DB へ切り替わるので、この lane が読むのは基点 DB そのものである。
| したがって「基点 DB が作られただけでスキーマが当たっていない」状態は、この lane で
| 再現しにくい失敗 (実行順で結果が変わる偽 green) として現れる。
| その状態をその場所で観測するのが本テストである。
|
| ■ RefreshDatabase を付けない
|   付けると自分でスキーマを作ってしまい、「準備が済んでいるか」の検査が成立しない。
|   前提が変わったときに黙って空洞化しないよう、B-0 が前提そのものを検査する。
|
| ■ 接続は Laravel を使わず PDO で直接張る
|   並列実行で接続先が worker DB へ切り替わっていても、見る対象を常に基点 DB に固定するため。
|
| ■ B-2 の到達確認基準は scripts/ci/ensure-test-db.php と**同じ関数**
|   (pgsqlTestMigrationFileNames / pgsqlTestSchemaUnappliedMigrations) を使う。
|   これは「独立した二重検証」ではなく「判定基準を 1 か所 (pgsql_test_conn.php) へ
|   一元化した」ものである — スクリプトと検査で判定がずれると「準備は成功したのに
|   このテストだけ落ちる」逆転が起き得るため、判定基準そのものは共有し、
|   「その基準どおりに基点 DB が本当になっているか」を実 DB への直接接続で確かめる
|   (Unit テスト側 (tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php) が
|   判定関数自体の入出力を purely に固定する独立した裏取りに当たる)。
|
| ■ 本テストが保証しないこと
|   「基点 DB が最新である」ことの観測であって、それを scripts/ci/ensure-test-db.php が
|   行った証明ではない (非並列の直接実行では RefreshDatabase の migrate:fresh が
|   同じ状態を作る)。
|
*/

// ファイル読み込み時点では Laravel が起動していないので base_path() を使わない。
require_once __DIR__.'/../../scripts/ci/pgsql_test_conn.php';

test('B-0: 本テストに RefreshDatabase が適用されていない (検査の前提)', function (): void {
    expect(class_uses_recursive($this))->not->toContain(RefreshDatabase::class);
});

test('B-1: 基点テスト DB に migrations 表が存在する', function (): void {
    $base = TestDatabaseEnv::pgsqlBaseDatabase(base_path());
    $pdo = pgsqlTestDatabasePdo(base_path(), $base);

    $table = $pdo->query("SELECT to_regclass('public.migrations')")->fetchColumn();

    expect($table)->toBe('migrations', "基点 DB {$base} に migrations 表が無い (準備がスキーマ更新まで行っていない)");
});

test('B-2: database/migrations の全ファイルが基点テスト DB に適用済みである', function (): void {
    $base = TestDatabaseEnv::pgsqlBaseDatabase(base_path());
    $pdo = pgsqlTestDatabasePdo(base_path(), $base);

    /** @var list<string> $applied */
    $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

    $files = glob(base_path('database/migrations/*.php'));
    expect($files)->toBeArray()->not->toBeEmpty();

    $expected = pgsqlTestMigrationFileNames($files);

    // 比較の向きは包含 (ファイル -> 表) にする。scripts/ci/ensure-test-db.php の
    // 到達確認と同じ関数 (pgsqlTestSchemaUnappliedMigrations) を使うことで、
    // vendor パッケージ由来の migration が表に増えても (集合の一致を求めないため) 落ちず、
    // かつスクリプトと検査の判定がずれない。
    expect(pgsqlTestSchemaUnappliedMigrations($applied, $expected))
        ->toBe([], "基点 DB {$base} に未適用の migration が残っている");
});
```

### テスト計画

- [x] B-0(前提: RefreshDatabase 不在)/ B-1(migrations 表の存在)/ B-2(全ファイル適用済み)の 3 ケース
- [x] `RefreshDatabase` を使っていない(本テスト自身が固定する不変条件)
- [x] 実 DB(基点テスト DB そのもの)へ接続する。dev DB には触れない(`TestDatabaseEnv::pgsqlBaseDatabase()` が算出する worktree 固有の base 名のみを見る)

### リスク

- 本テストは `composer test` の Architecture lane の一部として実 DB へ接続する。`ensure-test-db.php` が正しく動いていれば必ず通る形にしてあるので、本テストが赤くなる場合は「準備が完了する前にテストが走った」か「スキーマ更新の実装に不備がある」のいずれかであり、原因の切り分けが容易である。

---

## 4. `TestDatabaseSchemaUpdateTest.php` (新規 Unit テスト)

### 変更箇所

`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (新規)

### 設計方針(Round 1 からの主要な変更)

Round 1 は「ソース文字列に破壊的コマンドが含まれないこと」を主な防御にしていたが、Codex から「`migrate:reset` が抜けている」「文字列分割で回避できる」と指摘された。**Round 2 では主軸を、施策2 で分離した `$runArtisan` callable が実際に受け取った引数列の検証へ移す**。`ensureTestDatabaseSchemaUpdated()` は `$runArtisan` を高々 2 回、固定の引数列でしか呼ばない構造になっているため、スパイで呼び出し引数を記録し、正常系・全失敗系を通して**この 2 種類の引数列以外が 1 度も渡らないこと**を直接固定できる。ソース文字列の grep は「将来コードが変わってもこの契約が保てているか」の**副次的な**防御として残すが、保証範囲を docblock に明記し、主張を過大にしない。

### 変更後コード

```php
<?php

declare(strict_types=1);

// pgsql_test_conn.php は個別に require_once しない。ensure-test-db.php 自身が
// (drop-test-db.php と同じパターンで) 内部で `require __DIR__.'/pgsql_test_conn.php'` を
// 実行するため、ここで別途 require_once すると同じファイルが `require`(require_once ではない)
// で二重ロードされ、関数/enum の再宣言エラーになる (既存の DropTestDbScriptTest.php も
// drop-test-db.php 1 本だけを require_once し、pgsql_test_conn.php は個別に require しない
// 同じ理由による)。
require_once __DIR__.'/../../../scripts/ci/ensure-test-db.php';

/*
 * ensure-test-db.php のスキーマ更新まわりを固定する Unit テスト。
 *
 * 固定する不変条件:
 *   1. pgsqlTestArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
 *      3 キーだけ継承する / DB_URL は空で固定する / 親環境の DB_DATABASE・DB_URL・
 *      APP_CONFIG_CACHE を上書きしても固定値が勝つ)
 *   2. pgsqlTestConfigCachePath() は projectRoot からの一意な固定パスを返し、
 *      Laravel の既定パス (bootstrap/cache/config.php) とは異なる
 *   3. pgsqlTestMigrationFileNames() はパスから拡張子・ディレクトリを取り除く
 *   4. pgsqlTestSchemaUnappliedMigrations() は「ファイル -> 表」の包含判定であり、
 *      表側だけ余分にあっても (vendor パッケージ由来) 合格になる一方、
 *      ファイル側にあって表に無いものは 1 件でも検出する
 *   5. ensureTestDatabaseSchemaUpdated() の 9 失敗経路 (Round 1 レビューの 7 条件を
 *      判定場所ごとに分解したもの) がそれぞれ独立して検出され、いずれも ok=false を返す
 *   6. 正常系では $runArtisan に渡る引数列が
 *      ['migrate', '--force', '--no-interaction'] → ['migrate:status', '--pending=1']
 *      の 2 回・この順序・この内容だけであり、それ以外の引数列は 1 度も渡らない
 *      (破壊的コマンドの主たる防御。ソース grep より強い — 文字列分割や動的組み立てで
 *      回避できない)
 *   7. 失敗経路のうち UnsafeDatabaseName は $runArtisan / $listMigrationFiles /
 *      $verifyAppliedMigrations のいずれも 1 度も呼ばない (短絡)
 *   8. ensure-test-db.php のソースが migrate:fresh / migrate:refresh / migrate:rollback /
 *      migrate:reset / db:wipe を使っていない (副次的な防御。負例。文字列を分割して
 *      組み立てる呼び出しやコメント中の同じ文字列は検出できない — 主たる防御は 6)
 *   9. pgsql_test_conn.php を複数の require_once エントリポイント
 *      (ensure-test-db.php / drop-test-db.php / 本テストファイル自身) 経由で 1 プロセス内で
 *      読み込んでも fatal error にならない (Round 2 レビューで発見された Critical の回帰防止。
 *      別プロセスで検証する。fatal error は本テストプロセス自体を巻き込むため)
 *  10. realTestDatabaseSchemaUpdateCallables() の listMigrationFiles 結線が実際の
 *      database/migrations ディレクトリへ正しくつながっている (実 DB・実子プロセスを
 *      使わずに検証できる結線だけを対象にする。runArtisan・verifyAppliedMigrations の結線は
 *      実 DB・実子プロセスに触れるため対象外 — 施策2「保証しないこと」参照)
 *
 * 本テストは実 DB を作らず、実子プロセスも起動しない (純関数の入出力・フェイク callable
 * の呼び出し記録・ソース走査・別プロセスでの require 順検証のみ)。
 */

// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──

it('does not leak arbitrary environment variables into the child process env', function (): void {
    $original = getenv('SOME_SECRET');
    putenv('SOME_SECRET=leaked');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
        expect($env)->not->toHaveKey('SOME_SECRET');
    } finally {
        putenv($original === false ? 'SOME_SECRET' : "SOME_SECRET={$original}");
    }
});

it('carries over only PATH / HOME / TMPDIR from the parent environment', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    foreach (array_keys($env) as $key) {
        expect(in_array($key, ['PATH', 'HOME', 'TMPDIR'], true) || array_key_exists($key, [
            'APP_ENV' => true, 'APP_CONFIG_CACHE' => true, 'DB_CONNECTION' => true, 'DB_URL' => true,
            'DB_HOST' => true, 'DB_PORT' => true, 'DB_USERNAME' => true, 'DB_PASSWORD' => true,
            'DB_DATABASE' => true, 'CACHE_STORE' => true,
        ]))->toBeTrue("unexpected key leaked into artisan env: {$key}");
    }
});

it('forces DB_URL empty so that a URL-form connection string cannot override DB_DATABASE', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_URL'])->toBe('');
});

it('pins the computed base name as DB_DATABASE and APP_ENV as testing', function (): void {
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

    expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
        ->and($env['APP_ENV'])->toBe('testing')
        ->and($env['DB_CONNECTION'])->toBe('pgsql');
});

it('overrides a parent environment that already sets DB_DATABASE / DB_URL / APP_CONFIG_CACHE to a dev DB', function (): void {
    $keys = ['DB_DATABASE', 'DB_URL', 'APP_CONFIG_CACHE'];
    $originals = array_combine($keys, array_map(getenv(...), $keys));

    putenv('DB_DATABASE=app');
    putenv('DB_URL=pgsql://postgres:postgres@127.0.0.1:5432/app');
    putenv('APP_CONFIG_CACHE=/tmp/attacker-controlled-config.php');

    try {
        $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');

        expect($env['DB_DATABASE'])->toBe('app_test_8af22c44')
            ->and($env['DB_URL'])->toBe('')
            ->and($env['APP_CONFIG_CACHE'])->toBe(pgsqlTestConfigCachePath(__DIR__));
    } finally {
        foreach ($originals as $key => $value) {
            putenv($value === false ? $key : "{$key}={$value}");
        }
    }
});

// ── pgsqlTestConfigCachePath(): ensure 専用の非既定パス ──

it('returns a fixed config cache path derived from the project root', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->toBe('/workspace/bootstrap/cache/ensure-test-db-schema-update.config-cache.php');
});

it('does not point at the Laravel default config cache path', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->not->toBe('/workspace/bootstrap/cache/config.php');
});

// ── pgsqlTestMigrationFileNames(): パス -> ファイル名 ──

it('strips directory and extension from migration file paths', function (): void {
    expect(pgsqlTestMigrationFileNames([
        '/workspace/database/migrations/2024_01_01_000000_create_users_table.php',
        '/workspace/database/migrations/2024_01_02_000000_create_teams_table.php',
    ]))->toBe([
        '2024_01_01_000000_create_users_table',
        '2024_01_02_000000_create_teams_table',
    ]);
});

it('returns an empty list for an empty input (does not throw)', function (): void {
    expect(pgsqlTestMigrationFileNames([]))->toBe([]);
});

// ── pgsqlTestSchemaUnappliedMigrations(): ファイル -> 表の包含判定 (正典より強い基準) ──

it('reports no unapplied migrations when every file is present in the applied set', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
    ))->toBe([]);
});

it('tolerates extra applied rows that do not correspond to a repository migration file (vendor packages)', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table', '2099_01_01_000000_vendor_package_table'],
        ['2024_01_01_000000_create_users_table'],
    ))->toBe([]);
});

it('detects a single missing migration file even when most files are applied', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        ['2024_01_01_000000_create_users_table'],
        ['2024_01_01_000000_create_users_table', '2024_01_02_000000_create_teams_table'],
    ))->toBe(['2024_01_02_000000_create_teams_table']);
});

it('reports every file as unapplied when the applied set is empty (stale migrations table)', function (): void {
    expect(pgsqlTestSchemaUnappliedMigrations(
        [],
        ['2024_01_01_000000_create_users_table'],
    ))->toBe(['2024_01_01_000000_create_users_table']);
});

// ── ensureTestDatabaseSchemaUpdated(): テスト用フェイク callable ──

function fakeMigrationFiles(): callable
{
    return static fn (string $root): array => ['/x/database/migrations/2024_01_01_000000_create_users_table.php'];
}

function fakeVerification(array $applied): callable
{
    return static fn (string $root, string $base): array => ['tableExists' => true, 'applied' => $applied];
}

// ── 9 失敗経路 ──

it('rejects the dev database name before touching any injected boundary', function (): void {
    $runnerCalls = 0;
    $listCalls = 0;
    $verifyCalls = 0;

    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app', // dev DB
        function () use (&$runnerCalls): array {
            $runnerCalls++;

            return ['status' => 0, 'output' => ''];
        },
        function () use (&$listCalls): array {
            $listCalls++;

            return [];
        },
        function () use (&$verifyCalls): array {
            $verifyCalls++;

            return ['tableExists' => true, 'applied' => []];
        },
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName)
        ->and($runnerCalls)->toBe(0)
        ->and($listCalls)->toBe(0)
        ->and($verifyCalls)->toBe(0);
});

it('rejects a name that is not on the allowlist', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_XYZ', // allowlist 不一致
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnsafeDatabaseName);
});

/**
 * 一時 projectRoot フィクスチャ (bootstrap/cache/... の 3 階層) を内側から後始末する。
 * Round 2 レビューの Warning (「削除するのはキャッシュディレクトリだけで bootstrap と
 * フィクスチャルートが /tmp に残る」) の対応。
 */
function cleanupEnsureTestDbFixtureRoot(string $projectRoot): void
{
    $cachePath = pgsqlTestConfigCachePath($projectRoot);
    @unlink($cachePath);
    @rmdir(dirname($cachePath)); // .../bootstrap/cache
    @rmdir(dirname($cachePath, 2)); // .../bootstrap
    @rmdir($projectRoot);
}

it('refuses to start migrate when the dedicated config cache path already exists', function (): void {
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
    $cachePath = pgsqlTestConfigCachePath($projectRoot);
    mkdir(dirname($cachePath), recursive: true);
    file_put_contents($cachePath, '<?php return [];');

    try {
        $runnerCalls = 0;
        $result = ensureTestDatabaseSchemaUpdated(
            $projectRoot,
            'app_test_8af22c44',
            function () use (&$runnerCalls): array {
                $runnerCalls++;

                return ['status' => 0, 'output' => ''];
            },
            fakeMigrationFiles(),
            fakeVerification([]),
        );

        expect($result['ok'])->toBeFalse()
            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
            ->and($runnerCalls)->toBe(0);
    } finally {
        cleanupEnsureTestDbFixtureRoot($projectRoot);
    }
});

it('refuses to start migrate:status when the dedicated config cache path appears during migrate (second re-check point)', function (): void {
    // Round 2 レビューで指摘された未検証分岐: ConfigCacheStale の判定箇所は 2 か所あるが、
    // migrate 実行中に専用パスが出現するケースは Round 2 まで検証されていなかった。
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-fixture-'.bin2hex(random_bytes(4));
    $cachePath = pgsqlTestConfigCachePath($projectRoot);

    try {
        $calls = [];
        $result = ensureTestDatabaseSchemaUpdated(
            $projectRoot,
            'app_test_8af22c44',
            function (array $args) use (&$calls, $cachePath): array {
                $calls[] = $args;
                if ($args[0] === 'migrate') {
                    // migrate の実行中に専用パスが (異常として) 出現したことを模す。
                    mkdir(dirname($cachePath), recursive: true);
                    file_put_contents($cachePath, '<?php return [];');
                }

                return ['status' => 0, 'output' => ''];
            },
            fakeMigrationFiles(),
            fakeVerification([]),
        );

        expect($result['ok'])->toBeFalse()
            ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::ConfigCacheStale)
            ->and($calls)->toBe([['migrate', '--force', '--no-interaction']]); // migrate:status へは進んでいない
    } finally {
        cleanupEnsureTestDbFixtureRoot($projectRoot);
    }
});

it('fails when migrate exits non-zero', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (array $args): array => $args[0] === 'migrate'
            ? ['status' => 1, 'output' => 'boom']
            : ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateFailed);
});

it('fails when migrate:status exits non-zero (either connection failure or unapplied migrations)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (array $args): array => $args[0] === 'migrate:status'
            ? ['status' => 1, 'output' => 'pending: 2024_01_02_000000_create_teams_table']
            : ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrateStatusFailed)
        ->and($result['message'])->toContain('pending: 2024_01_02_000000_create_teams_table');
});

it('fails when migration file enumeration itself fails (glob returned false)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        static fn (string $root): bool => false,
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationFileEnumerationFailed);
});

it('fails when there are zero migration files (distinct from glob failure)', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        static fn (string $root): array => [],
        fakeVerification([]),
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::NoMigrationFiles);
});

it('fails when the verification connection throws', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        static function (): array {
            throw new RuntimeException('connection refused');
        },
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::VerificationConnectionFailed)
        ->and($result['message'])->toContain('connection refused');
});

it('fails when the migrations table is missing after update', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(),
        static fn (): array => ['tableExists' => false, 'applied' => []],
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::MigrationsTableMissing);
});

it('fails when an unapplied migration remains after update', function (): void {
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        static fn (): array => ['status' => 0, 'output' => ''],
        fakeMigrationFiles(), // 期待 = ['2024_01_01_000000_create_users_table']
        static fn (): array => ['tableExists' => true, 'applied' => []], // 未適用のまま
    );

    expect($result['ok'])->toBeFalse()
        ->and($result['failure'])->toBe(TestDatabaseSchemaUpdateFailure::UnappliedMigrationsRemain)
        ->and($result['message'])->toContain('2024_01_01_000000_create_users_table');
});

// ── 正常系 + 引数列そのものの検証 (破壊的コマンドの主たる防御) ──

it('succeeds and invokes the artisan runner with exactly two allowed argument lists, in order, and nothing else', function (): void {
    $calls = [];
    $result = ensureTestDatabaseSchemaUpdated(
        '/workspace',
        'app_test_8af22c44',
        function (array $args, array $env, bool $capture) use (&$calls): array {
            $calls[] = $args;

            return ['status' => 0, 'output' => ''];
        },
        fakeMigrationFiles(),
        fakeVerification(['2024_01_01_000000_create_users_table']),
    );

    expect($result['ok'])->toBeTrue()
        ->and($result['failure'])->toBeNull()
        ->and($calls)->toBe([
            ['migrate', '--force', '--no-interaction'],
            ['migrate:status', '--pending=1'],
        ]);
});

it('never calls the artisan runner with an argument list other than the two allowed forms, across every branch that reaches the runner', function (): void {
    // Round 2 レビューの指摘対応: 従来は「正常系・全ての失敗系を通しで走らせる」と書きながら
    // 実際には 2 ケースしか走らせていなかった。本テストはデータセット化し、
    // $runArtisan に到達する分岐 (成功 / migrate 失敗 / migrate:status 失敗 /
    // 到達確認の 3 失敗いずれか) を明示的に列挙して回す。
    //
    // 対象外にした分岐とその理由:
    //   - UnsafeDatabaseName / migrate 前の ConfigCacheStale: $runArtisan を 1 度も呼ばない
    //     (専用のテストで呼び出し回数 0 を固定済み)
    //   - 移行後 ConfigCacheStale (migrate 中出現): 呼び出しが ['migrate', ...] の 1 回だけに
    //     短縮される特殊形であり、専用のテストで固定済み (this dataset の対象は
    //     「2 回とも呼ばれる」形に絞る)
    //   - MigrationFileEnumerationFailed / NoMigrationFiles: migrate + migrate:status が
    //     成功した後で失敗するため、runner への呼び出し列は 'success' と構造的に同一
    //     (どちらも重複してデータセットへ加える意味が無い)
    $allowed = [
        ['migrate', '--force', '--no-interaction'],
        ['migrate:status', '--pending=1'],
    ];

    $scenarios = [
        'success' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => fakeVerification(['2024_01_01_000000_create_users_table']),
        ],
        'migrate failed' => [
            'artisan' => static fn (array $args): array => $args[0] === 'migrate' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
        'migrate:status failed' => [
            'artisan' => static fn (array $args): array => $args[0] === 'migrate:status' ? ['status' => 1, 'output' => ''] : ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
        'verification connection failed' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => static function (): array {
                throw new RuntimeException('connection refused');
            },
        ],
        'migrations table missing' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => static fn (): array => ['tableExists' => false, 'applied' => []],
        ],
        'unapplied migrations remain' => [
            'artisan' => static fn (array $args): array => ['status' => 0, 'output' => ''],
            'verify' => fakeVerification([]),
        ],
    ];

    foreach ($scenarios as $label => $scenario) {
        $seen = [];
        $spy = function (array $args, array $env, bool $capture) use (&$seen, $scenario): array {
            $seen[] = $args;

            return ($scenario['artisan'])($args);
        };

        ensureTestDatabaseSchemaUpdated('/workspace', 'app_test_8af22c44', $spy, fakeMigrationFiles(), $scenario['verify']);

        expect($seen)->not->toBe([], "scenario '{$label}' never called the runner (dataset entry would be vacuous)");
        foreach ($seen as $args) {
            expect($allowed)->toContain($args, "unexpected artisan argument list in scenario '{$label}': ".implode(' ', $args));
        }
    }
});

// ── T-負例: 破壊的コマンドを使っていないこと (副次的な防御。主たる防御は上の引数列検証) ──

it('never mentions migrate:fresh, migrate:refresh, migrate:rollback, migrate:reset, or db:wipe in the source (secondary defense)', function (): void {
    $source = file_get_contents(__DIR__.'/../../../scripts/ci/ensure-test-db.php');
    expect($source)->toBeString();

    foreach (['migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'] as $forbidden) {
        expect($source)->not->toContain($forbidden, "ensure-test-db.php が破壊的コマンド {$forbidden} を含んでいる");
    }
    expect($source)->toContain("'migrate', '--force'");
});

// ── 負のコントロール: 判定関数自身が空振りしていないことの確認 ──

it('negative control: the unapplied-migrations judgement actually flags a real gap', function (): void {
    // 前提: 何も適用されていない状態でファイルが 1 件でもあれば、必ず非空を返す
    // (この判定が定数 [] を返すだけの空振りになっていないことの確認)。
    expect(pgsqlTestSchemaUnappliedMigrations([], ['anything']))->not->toBe([]);
});

// ── realTestDatabaseSchemaUpdateCallables(): 結線の単体テスト (実 DB・実子プロセスを使わない範囲) ──

it('wires listMigrationFiles to the real database/migrations directory (no DB, no child process)', function (): void {
    // Round 2 レビューの Warning 対応: performTestDatabaseSchemaUpdate() の結線自体を
    // Architecture テストは検証できない (基点 DB が既に最新なら結線が壊れていても通ってしまう)。
    // 実 DB・実子プロセスを使わずに検証できる listMigrationFiles の結線だけを、ここで直接固定する
    // (runArtisan / verifyAppliedMigrations の結線は実 DB・実子プロセスに触れるため対象外。
    // 施策2「保証しないこと」参照)。
    $projectRoot = sys_get_temp_dir().'/ensure-test-db-wiring-'.bin2hex(random_bytes(4));
    mkdir($projectRoot.'/database/migrations', recursive: true);
    file_put_contents($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php', '<?php');

    try {
        $callables = realTestDatabaseSchemaUpdateCallables($projectRoot);
        $files = ($callables['listMigrationFiles'])($projectRoot);

        expect($files)->toBe([$projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php']);
    } finally {
        // 後始末は内側から (作成した 3 階層を全て削除する)。
        @unlink($projectRoot.'/database/migrations/2024_01_01_000000_create_users_table.php');
        @rmdir($projectRoot.'/database/migrations');
        @rmdir($projectRoot.'/database');
        @rmdir($projectRoot);
    }
});

// ── 回帰テスト (Round 2 レビューで発見された Critical の直接の裏取り) ──

it('requiring pgsql_test_conn.php via multiple require_once entrypoints in one process does not fatal', function (): void {
    // ensure-test-db.php / drop-test-db.php はどちらも内部で pgsql_test_conn.php を
    // require_once する (Round 2 まで require だったため、他のテストファイルが先に
    // pgsql_test_conn.php を require_once していると関数/enum の再宣言 fatal error になっていた)。
    // 本テストは、それらを 1 つの別プロセスで実際に多重 require_once させ、fatal にならない
    // ことを直接確認する (別プロセスにするのは、fatal error が起きた場合に本テストプロセス
    // 自体を巻き込まないため)。
    $root = dirname(__DIR__, 3);
    $script = <<<'PHP'
    <?php
    require_once $argv[1].'/scripts/ci/pgsql_test_conn.php';
    require_once $argv[1].'/scripts/ci/drop-test-db.php';
    require_once $argv[1].'/scripts/ci/ensure-test-db.php';
    echo "OK";
    PHP;

    $scriptPath = tempnam(sys_get_temp_dir(), 'require-order-check-');
    file_put_contents($scriptPath, $script);

    try {
        $process = proc_open(
            [PHP_BINARY, $scriptPath, $root],
            [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect(is_resource($process))->toBeTrue();
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        expect($status)->toBe(0, "require の多重ロードが fatal error になった: {$stderr}")
            ->and($stdout)->toContain('OK');
    } finally {
        @unlink($scriptPath);
    }
});
```

### PHPStan 適合チェック

- 対象外(`tests/` は `phpstan.neon` の解析対象外)。

### テスト計画

- [x] 再現テストではなく新規機能のテスト(先に赤くしてから `pgsql_test_conn.php` / `ensure-test-db.php` の本体を書く)
- [x] 9 失敗経路それぞれに独立したテストケース(Round 1 レビューの 7 条件を全て包含。対応関係は施策2 の表を参照)。うち `ConfigCacheStale` は migrate 前・migrate 中出現の 2 分岐とも独立に固定する(Round 2 対応)
- [x] 正常系で `$runArtisan` に渡る引数列そのものを固定(破壊的コマンドの主たる防御。ソース grep より回避しにくい)。データセット化し、runner へ実際に到達する主要分岐(成功・migrate 失敗・migrate:status 失敗・到達確認の 3 失敗)それぞれで許可された引数列以外が渡らないことを固定する(Round 2 対応。「全ての失敗系」という主張と実装の乖離を解消)
- [x] `realTestDatabaseSchemaUpdateCallables()` の `listMigrationFiles` 結線を単体テストで直接固定(Round 2 対応。`runArtisan` / `verifyAppliedMigrations` の結線は対象外であることを明記)
- [x] `pgsql_test_conn.php` の多重 require_once が fatal error にならないことの回帰テスト(Round 2 で発見された Critical の直接の裏取り)
- [x] 親環境の上書き負例(`putenv` は `try/finally` で必ず元に戻す)
- [x] 一時フィクスチャ(`bootstrap/cache/...`)は内側から 3 階層を確実に削除する(Round 2 Warning 対応)
- [x] 実 DB を作らず、実子プロセスも起動しない(回帰テストのみ別プロセスを起動するが、DB へは接続しない)

### リスク

- なし(純関数・フェイク callable・ソース走査のみ)。

---

## 5. `TestDatabaseProvenanceTest.php` の更新

### 変更箇所

`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の T-C2-17 ブロック(3 ケース→4 ケース)とファイル冒頭の docblock。

### 変更理由

`testDatabaseEnsurePlan()` の契約を「2 手順」から「3 手順」へ意図的に拡張したための更新である。既存の意図(両分岐とも出自を記録する = 冪等)は変えず、そこへ「両分岐ともスキーマ更新も行う」を追加する。これはテスト削除ではなく、変更した契約に追随する必須の更新である(先に本ファイルを新しい期待値へ書き換えて赤くし、施策1・2 の実装で緑にする = テストファースト)。

### ファイル冒頭 docblock への追記 (Round 1 Suggestion 対応)

```
 *   1. ensure の plan は **作成時・既存時の両方**で StampProvenance と UpdateSchema を含む
 *      (= 冪等。ここを片方だけにすると「ラベルの無い現役 DB」または「スキーマが古いまま放置
 *      される DB」が生まれる)。実行順は StampProvenance → UpdateSchema で固定する
 *      (スキーマ更新の失敗時に「ラベルの無い現役 DB」を残さないため)。
```

### 現行コード

```php
it('plans create + stamp when the base database does not exist yet', function (): void {
    expect(testDatabaseEnsurePlan(false))->toBe([
        TestDatabaseEnsureAction::Create,
        TestDatabaseEnsureAction::StampProvenance,
    ]);
});

it('still plans a stamp when the base database already exists (idempotent labelling)', function (): void {
    expect(testDatabaseEnsurePlan(true))->toBe([TestDatabaseEnsureAction::StampProvenance]);
});

it('never plans a create for an existing database', function (): void {
    expect(testDatabaseEnsurePlan(true))->not->toContain(TestDatabaseEnsureAction::Create);
});
```

### 変更後コード

```php
it('plans create + stamp + schema update when the base database does not exist yet', function (): void {
    expect(testDatabaseEnsurePlan(false))->toBe([
        TestDatabaseEnsureAction::Create,
        TestDatabaseEnsureAction::StampProvenance,
        TestDatabaseEnsureAction::UpdateSchema,
    ]);
});

it('still plans a stamp + schema update when the base database already exists (idempotent)', function (): void {
    expect(testDatabaseEnsurePlan(true))->toBe([
        TestDatabaseEnsureAction::StampProvenance,
        TestDatabaseEnsureAction::UpdateSchema,
    ]);
});

it('never plans a create for an existing database', function (): void {
    expect(testDatabaseEnsurePlan(true))->not->toContain(TestDatabaseEnsureAction::Create);
});

it('always plans the schema update last, after the provenance stamp', function (): void {
    foreach ([false, true] as $exists) {
        $plan = testDatabaseEnsurePlan($exists);
        expect(array_search(TestDatabaseEnsureAction::UpdateSchema, $plan, true))
            ->toBe(count($plan) - 1, 'UpdateSchema is not last in the plan');
        if (in_array(TestDatabaseEnsureAction::StampProvenance, $plan, true)) {
            expect(array_search(TestDatabaseEnsureAction::StampProvenance, $plan, true))
                ->toBeLessThan(array_search(TestDatabaseEnsureAction::UpdateSchema, $plan, true));
        }
    }
});
```

(残りの COMMENT 生成・best-effort stamp のテストは無変更のまま維持する。)

### テスト計画

- [x] 3 手順への拡張を明示的に固定する(4 本目の新規ケースで順序不変条件も固定する)
- [x] 既存の意図(冪等・両分岐で StampProvenance)は 1 つも削らない

### リスク

- なし。契約変更に追随する意図的な更新であり、カバレッジは後退しない(むしろ順序の不変条件が 1 本増える)。

---

## 6. `GlobalTestLockInventoryTest.php` へのケース追加

### 変更箇所

`tests/Architecture/GlobalTestLockInventoryTest.php`

### 設計方針(Round 1 からの主要な変更)

Round 1 の検出器は `str_contains($line, 'ensure-test-db.php')` で「言及」を判定していたため、`not-ensure-test-db.php`(接頭辞つき)・`ensure-test-db.php.bak`/`.disabled`(接尾辞つき)のような別ファイル名の部分文字列一致で誤検出/誤通過し得た。**Round 2 ではシェルの行を空白区切りのトークン列へ分割し、`scripts/ci/ensure-test-db.php` という 1 トークンに完全一致するかどうかで判定する**(共通規約(e)準拠)。あわせて、呼び出し形は `global_test_lock_run php scripts/ci/ensure-test-db.php` の**3 トークン完全一致だけ**を許可する(前後に別トークンが挟まる形・コメント化された形は全て違反にする)。変数展開・改行継続などトークン化しても解決できない形は fail-closed で違反にする(共通規約(b))。

### 波及理由 (AGENTS.md §静的検査 gate と走査器の共通規約)

`ensure-test-db.php` の呼び出しが `global_test_lock_run` 経由であることを固定するのは「判定条件・走査対象の変更」に当たるため、「同じ PR で揃える 4 点」が発火する。以下のとおり揃える。

1. **正例・負例**: 正例は既存の `run-test.sh` / `run-browser-test.sh` の呼び方が通ること。負例は Round 1 レビューが指摘した擬陽性合格例(`echo` 経由・行末コメント・別名ファイル)と、共通規約(e)が要求する接頭辞/打ち消し/接尾辞の 3 形、および解決不能な形(変数展開・改行継続)。テストファースト。
2. **解決できない形を落とす**: 変数展開・改行継続を含む行に対象トークンが現れたら、無条件で違反にする(無言で候補から外さない)。
3. **走査の空振り検査**: 対象スクリプトに `scripts/ci/ensure-test-db.php` という完全一致トークンが 1 件も無ければ、それ自体を違反として報告する(綴りを変えられて判定が空振りする事故を防ぐ)。
4. **docblock**: トークンの区切り規則(空白 1 文字以上)・走査対象(`run-test.sh` / `run-browser-test.sh`)・対象外(`setup-worktree.sh` はロックの外で呼ぶのが仕様。CI の workflow から直に叩く形は運用していない)を明記する。

5 条のうち (a) クラス名・名前参照の解決は非適用(シェル文字列のトークン走査のため)。(e) 語彙一致の否定形は**本節が新たに適用する**(Round 1 は非適用と判断していたが、Codex の指摘どおり不適切だった。トークン完全一致へ書き直すことで適用する)。

### 変更後コード (追加分・既存の `str_contains` ベース実装を置き換え)

```php
/**
 * ensure-test-db.php を呼ぶスクリプト。この 2 レーンだけが対象で、
 * setup-worktree.sh はロックの外で呼ぶのが仕様のため対象外にする
 * (docs/worktree-isolation-strategy.md の [7/7] 参照)。
 * CI の workflow から scripts/ci/ensure-test-db.php を直に叩く形は運用していないため見ない
 * (CI は本 2 レーン経由でのみ呼ぶ)。
 */
const GLOBAL_TEST_LOCK_ENSURE_TEST_DB_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
];

/** 唯一許可する呼び出し形 (トークン完全一致で比較する)。 */
const GLOBAL_TEST_LOCK_ENSURE_TEST_DB_EXACT_TOKENS = ['global_test_lock_run', 'php', 'scripts/ci/ensure-test-db.php'];

/** 検出対象の完全一致トークン (呼び出し対象を指す語彙)。 */
const GLOBAL_TEST_LOCK_ENSURE_TEST_DB_TARGET_TOKEN = 'scripts/ci/ensure-test-db.php';

/**
 * シェルの行を空白 1 文字以上で区切ったトークン列にする (純関数)。
 *
 * クオート結合・変数展開の解決はしない。トークンの**完全一致**だけで判定することで、
 * `not-ensure-test-db.php` (接頭辞) / `ensure-test-db.php.bak` `ensure-test-db.php.disabled`
 * (接尾辞) のような別ファイル名の部分文字列一致による誤検出/誤通過を構造的に防ぐ
 * (共通規約(e))。
 *
 * @return list<string>
 */
function globalTestLockShellTokens(string $line): array
{
    return array_values(array_filter(
        preg_split('/\s+/u', trim($line)) ?: [],
        static fn (string $token): bool => $token !== '',
    ));
}

/**
 * ensure-test-db.php の呼び出し行だけを対象に、global_test_lock_run 経由の
 * 厳密な呼び出し形であることを検査する (純関数)。
 *
 * `ensure-test-db.php` 自体は CREATE / 出自記録の時点で既に実 DB へ触れているが、
 * 家系の裁定 AG-135 で新たに追加される「スキーマ更新の子プロセス起動点」
 * (`migrate` / `migrate:status` の artisan 起動) は、この呼び出し行の外側からは
 * ガードできない新しい競合対象である。グローバルテストロックの外で呼ばれると、
 * 同一マシン上の別レーンの基点 DB 更新と競合しうる (Postgres の DDL 自体は個々に安全でも、
 * artisan の子プロセスが同時に走ることは想定していない)。
 *
 * 許可する呼び出し形は `global_test_lock_run php scripts/ci/ensure-test-db.php` の
 * **3 トークン完全一致だけ**である。前後に別トークンが挟まる形 (`echo` 経由・
 * `true # ...` のような偽装) は全て違反にする。
 *
 * **保証範囲**: 見るのは空白区切りのトークン列だけであり、シェルのクオート結合・
 * 変数展開・コマンド置換までは解決しない。変数展開 (`$`) または行末の改行継続 (`\`) を
 * 含む行に対象トークンが現れた場合は、解決できない形として fail-closed で違反にする
 * (共通規約(b): 解決できない形を見逃す方向へは倒さない)。
 *
 * @return list<string> 違反一覧 (空 = 合格。母集団が空 = 走査の空振りも違反として返す)
 */
function globalTestLockEnsureTestDbViolations(string $path, string $source): array
{
    $violations = [];
    $mentioned = false;
    $targetToken = GLOBAL_TEST_LOCK_ENSURE_TEST_DB_TARGET_TOKEN;

    foreach (preg_split('/\R/u', globalTestLockCodeLines($source)) ?: [] as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') {
            continue;
        }

        $tokens = globalTestLockShellTokens($line);
        if (! in_array(GLOBAL_TEST_LOCK_ENSURE_TEST_DB_TARGET_TOKEN, $tokens, true)) {
            continue; // このトークン集合には呼び出し対象が完全一致で現れていない (無関係行)
        }

        // 解決できない形 (変数展開・改行継続) が対象トークンと同じ行に現れたら、
        // 判定を確定させず fail-closed で違反にする。
        if (str_contains($line, '$') || str_ends_with($line, '\\')) {
            $mentioned = true;
            $violations[] = "{$path} の行が解決不能 (変数展開/改行継続) な形で {$targetToken} に触れている: {$line}";

            continue;
        }

        $mentioned = true;
        if ($tokens !== GLOBAL_TEST_LOCK_ENSURE_TEST_DB_EXACT_TOKENS) {
            $violations[] = "{$path} の ensure-test-db.php 呼び出しが厳密な呼び出し形ではない: {$line}";
        }
    }

    if (! $mentioned) {
        $violations[] = "{$path} に {$targetToken} への言及が無い (走査が空振りしている可能性)";
    }

    return $violations;
}

test('run-test.sh / run-browser-test.sh の ensure-test-db.php 呼び出しがグローバルテストロック配下であること', function (): void {
    foreach (GLOBAL_TEST_LOCK_ENSURE_TEST_DB_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockEnsureTestDbViolations($rel, $source))->toBe([]);
    }
});

// ── 負のコントロール: Round 1 レビューが指摘した擬陽性の合格例 ──

test('負のコントロール: echo 経由で名前だけを混ぜた呼び出しを検出する', function (): void {
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', "#!/usr/bin/env bash\nglobal_test_lock_run echo scripts/ci/ensure-test-db.php\n");
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('厳密な呼び出し形ではない');
});

test('負のコントロール: 行末コメントに名前があるだけの偽装を検出する', function (): void {
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', "#!/usr/bin/env bash\nglobal_test_lock_run true # scripts/ci/ensure-test-db.php\n");
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('厳密な呼び出し形ではない');
});

test('負のコントロール: ロックを通さない ensure-test-db.php 呼び出しを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "lane"
    php scripts/ci/ensure-test-db.php
    global_test_lock_run vendor/bin/pest
    SH;
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('厳密な呼び出し形ではない');
});

// ── 負のコントロール: 共通規約(e) — 接頭辞/打ち消し/接尾辞つきの別ファイル名は無関係行として扱う ──

test('負のコントロール: 打ち消しつきの別ファイル名 (not-ensure-test-db.php) を対象として誤検出しない', function (): void {
    $source = "#!/usr/bin/env bash\nglobal_test_lock_run php scripts/ci/not-ensure-test-db.php\n";
    // 対象トークンが完全一致で現れないため「無関係行」になり、走査は空振り (=言及ゼロ) として報告する。
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $source);
    expect(implode("\n", $violations))->toContain('空振りしている可能性');
    expect(implode("\n", $violations))->not->toContain('厳密な呼び出し形ではない');
});

test('負のコントロール: 打ち消しを伴わない接頭辞つきの別ファイル名 (prefix-ensure-test-db.php) を対象として誤検出しない', function (): void {
    // Round 2 レビューの指摘対応: 上の not-ensure-test-db.php は「打ち消し」の意味も持つため、
    // 共通規約(e)が要求する「接頭辞・打ち消し・接尾辞」の 3 形のうち、打ち消しの意味を
    // 持たない独立した接頭辞形も別途固定する。
    $source = "#!/usr/bin/env bash\nglobal_test_lock_run php scripts/ci/prefix-ensure-test-db.php\n";
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $source);
    expect(implode("\n", $violations))->toContain('空振りしている可能性');
    expect(implode("\n", $violations))->not->toContain('厳密な呼び出し形ではない');
});

test('負のコントロール: 接尾辞つきの別ファイル名 (.bak / .disabled) を対象として誤検出しない', function (string $suffix): void {
    $source = "#!/usr/bin/env bash\nglobal_test_lock_run php scripts/ci/ensure-test-db.php{$suffix}\n";
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $source);
    expect(implode("\n", $violations))->toContain('空振りしている可能性');
    expect(implode("\n", $violations))->not->toContain('厳密な呼び出し形ではない');
})->with(['.bak', '.disabled']);

test('負のコントロール: ensure-test-db.php への言及が無いスクリプトを走査の空振りとして検出する', function (): void {
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', "#!/usr/bin/env bash\necho no-op\n");
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('空振り');
});

// ── 負のコントロール: 解決不能な形 (共通規約(b)) ──

test('負のコントロール: 対象トークンと同じ行に変数展開が同居する解決不能な呼び出しを fail-closed で違反にする', function (): void {
    $mixedSource = "#!/usr/bin/env bash\nglobal_test_lock_run php scripts/ci/ensure-test-db.php \$EXTRA_ARG\n";
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $mixedSource);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('解決不能');
});

test('保証範囲の限界: 変数に間接的に格納された呼び出し名は解決できない (別行の代入までは追わない)', function (): void {
    // $SCRIPT の中身を解決しないという保証範囲の限界そのものを固定する。
    // 対象トークンが完全一致で現れる行が無いため、走査は「言及なし (空振り)」を返す
    // (=このスクリプトを誤って安全と判定するわけではなく、判定を保留する形で fail-closed に倒れる)。
    $indirectSource = "#!/usr/bin/env bash\nSCRIPT=\"scripts/ci/ensure-test-db.php\"\nglobal_test_lock_run php \$SCRIPT\n";
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $indirectSource);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('空振りしている可能性');
});

test('負のコントロール: 行末の改行継続を含む解決不能な呼び出しを fail-closed で違反にする', function (): void {
    $source = "#!/usr/bin/env bash\nglobal_test_lock_run php scripts/ci/ensure-test-db.php \\\n";
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', $source);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('解決不能');
});
```

### テスト計画

- [x] 正例(実ファイル 2 本)+ 擬陽性負例 3 本(Round 1 指摘: echo 経由 / 行末コメント / ロック非経由)+ 共通規約(e)負例 4 本(打ち消し・独立した接頭辞・接尾辞 2 形の計 3 形を Round 2 で満たす)+ 解決不能負例 3 本(変数展開の同居・間接参照の保証範囲限界・改行継続)
- [x] 既存ケースは 1 つも削除・変更しない(既存の `str_contains` ベース実装と旧テストケースは本節の実装へ**置き換える**。旧実装は Round 1 で初めて追加されたものでありまだコミットされていないため、既存資産の削除には当たらない)

### リスク

- `run-browser-test.sh` の当該行は `global_test_lock_run php scripts/ci/ensure-test-db.php` の形で単独行に存在する(既に読み込み済みの現行コードで確認済み)。文言変更は不要。

---

## 7. `docs/template-divergence.md` の更新 (D30 の更新 + D33 の新規登録)

### 変更箇所

D30 の比較表(行「基点 DB のスキーマ更新」)・「揃え続ける不変条件」・「この登録が扱わない範囲」節・「関連」節。加えて、ファイル末尾へ **D33 を新規登録**する。

### 設計方針(Round 1 からの主要な変更)

Round 1 レビューは「正典より強い到達確認」と「専用非キャッシュ設定パス」を D30 (出自の記録・孤児回収の上積み) に混在させるべきではないと指摘した。**D30 には「出自の記録とスキーマ更新の実行順の相互作用」だけを残し、到達確認の強化と専用非キャッシュパスは新しい逸脱登録 D33 として分離する**。また「差分は独自 2 関数だけ」という主張を裏取りできる**三者 diff**(1. 正典からの移植 / 2. 既存 D30 由来の差分 / 3. 今回追加する schema 確認上の差分)を D33 に明記する。

### D30 の変更後

比較表の行を更新する(スキーマ更新の到達確認自体は D33 で扱うため、D30 の表からは削除する)。

```
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 追従済み (`devnotes/20260819-1056-ensure-test-db-schema-followup/`)。到達確認の強化と専用非キャッシュ設定パスは aicue:D33 として別登録する |
```

「揃え続ける不変条件と保証機構」のセルへ 1 文を追加する(D30 が扱うのは実行順の相互作用だけ)。

```
併せて、家系の裁定 AG-135 への追従で「出自の記録 (StampProvenance) はスキーマ更新
(UpdateSchema) より先に実行する」を不変条件へ加える (スキーマ更新の失敗時に
「ラベルの無い現役 DB」を残さないため)。`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の
`always plans the schema update last, after the provenance stamp` が固定する。
到達確認の基準そのもの・専用非キャッシュ設定パスの採用理由は aicue:D33 を参照
(本登録が扱うのは実行順の相互作用だけである)。
```

「この登録が扱わない範囲(遅れであって逸脱ではない)」節を、追従が完了した旨へ書き換える。

```markdown
### 追従の記録 (旧: この登録が扱わない範囲)

正典 HEAD の `ensure-test-db.php` が担う基点 DB のスキーマ更新 (家系の裁定 AG-135) に、
`devnotes/20260819-1056-ensure-test-db-schema-followup/` の設計で追従した
(オーナー決定 2026-08-19)。追従にあたり正典より強くした到達確認と、専用非キャッシュ
設定パスの採用は、D30 の上積み(出自記録・孤児回収)とは別の判断であるため
aicue:D33 として分離して登録した。

追従の実装は `tests/Architecture/BaseTestDatabaseSchemaTest.php` と
`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` が固定する。
`docs/worktree-isolation-strategy.md` の「既知のギャップ」から該当項を削除した。
```

「関連」節の検査・設計リストへ追加する。

```
- 検査: (既存 4 本に追加)
  `tests/Architecture/BaseTestDatabaseSchemaTest.php` /
  `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`
- 設計: (既存 2 件に追加)
  `devnotes/20260819-1056-ensure-test-db-schema-followup/`
```

「決めた日」「決めた人」「根拠」「状態」「見直し期限」の行は変更しない(D30 の登録そのものは元の逸脱=上積みについての登録であり、今回はその「扱わない範囲」を追従で埋めるだけで、登録自体の再判定条件には当たらない)。

### D33 の新規登録 (ファイル末尾へ追加)

```markdown
## D33 テスト DB のスキーマ到達確認を正典より強い基準にし、専用の非キャッシュ設定パスを使う

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/pgsql_test_conn.php` / `scripts/ci/ensure-test-db.php` |
| 業務要件起因の説明 | 正典の到達確認 (「migrations 表があり行が 1 件以上ある」) は、古い基点 DB に古い migrations 表が残っている状態を通してしまう。実装を必ず worktree で行う進め方 (AGENTS.md §worktree 運用ルール) は worktree ごとに基点 DB を新規作成するため、この見逃しを踏む頻度が正典の想定より高い |
| 揃え続ける不変条件と保証機構 | (1) 到達確認は `database/migrations` の全ファイル名が migrations 表に含まれることを要求する (`pgsqlTestSchemaUnappliedMigrations()`)。集合の一致は求めない (vendor パッケージ由来の migration が表に増えても許容する)。(2) スキーマ更新の子プロセスへ渡す設定キャッシュパスは Laravel の既定パスではなく ensure 専用の非既定パス (`pgsqlTestConfigCachePath()`) を使い、各 artisan 起動の直前にこのパスの残存を確認する。`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (到達確認の判定関数・専用パスの値・各失敗経路) と `tests/Architecture/BaseTestDatabaseSchemaTest.php` (B-2。同じ判定関数を共有する到達確認の実地観測) が固定する |
| 三者 diff (実装受け入れ条件) | 1. **正典からそのまま移植した部分**: `pgsqlTestDatabasePdo()` / `pgsqlTestArtisanEnv()` (専用パスの値を除く)/ `runTestDatabaseArtisan()` / migrate・migrate:status の起動順。2. **既存 D30 由来の差分**: 出自記録 (`COMMENT ON DATABASE`) との実行順の統合 (`testDatabaseEnsurePlan()` の 3 手順化)。3. **今回新たに追加する schema 確認上の差分**: `pgsqlTestMigrationFileNames()` / `pgsqlTestSchemaUnappliedMigrations()` の 2 関数、および `pgsqlTestConfigCachePath()` が返す専用非キャッシュパスの値そのもの(正典は既定パスを返す) |
| 再判定の条件 | 正典が同水準以上の到達確認(ファイル→表の包含判定)を採用したとき、または正典が専用非キャッシュパスと同等の TOCTOU 対策を採用したとき(その場合はこの登録を撤去し、正典実装へ揃え直す) |
| 決めた日 | 2026-08-19 |
| 決めた人 | 開発者 |
| 根拠 | 家系の裁定 AG-135 への追従設計レビュー(`devnotes/20260819-1056-ensure-test-db-schema-followup/detailed-review-round-1.md` の Critical 指摘) |
| 状態 | 還流候補(正典より強い基準なので、家系の機能台帳へ還流を提案してよい) |
| 見直し期限 | 次回 `php-test-pgsql-lane` の正典改訂時 |

### 保証しないもの

- この到達確認は「基点 DB の最終状態がスキーマ最新である」ことの確認であって、
  直前の migrate/migrate:status 子プロセスがその更新を行ったことの監査ではない
  (基点 DB が既に最新なら、子プロセスの環境変数解決が壊れていて別の DB を
  更新していても、この確認だけでは検出できない。dev DB 保護は名前の出所の一本化・
  起動直前の再検証・非継承の環境固定で成立させている)
- 専用非キャッシュパスの残存チェックは「多重起動が絶対に起きない」ことを前提にしない。
  `scripts/ci/setup-worktree.sh` はグローバルテストロックの**外**で本スクリプトを呼ぶため
  (worktree 作成そのものを壊さないための意図的な設計)、多重起動は理論上ゼロではない。
  このチェックが担うのは「専用パスが原因を問わず既に存在していたら、通常の
  `config:cache` はこの専用パスを絶対に書かないという前提が崩れているとみなして
  fail-closed で停止する」ことだけである

### 関連

- 実装: `scripts/ci/pgsql_test_conn.php` / `scripts/ci/ensure-test-db.php`
- 検査: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` /
  `tests/Architecture/BaseTestDatabaseSchemaTest.php`
- 背景: aicue:D30 (出自記録・孤児回収の上積み)
- 設計: `devnotes/20260819-1056-ensure-test-db-schema-followup/`
```

### テスト計画

- ドキュメント更新のみのためテスト対象なし。本文中の `tests/Architecture/BaseTestDatabaseSchemaTest.php` 等への参照は単なる関連リンクであり、**その実在を機械的に保証する仕組みは本設計に含まない**(施策3・4 のテストはそれぞれ自身の不変条件を固定するものであり、「D30/D33 の本文が指すファイルが実在すること」自体を検査する仕組みではない。テストファイルの追加漏れがあってもこの文書更新自体は失敗しない)。

### リスク

- OCR 対応など並行作業が同じ `docs/template-divergence.md` を触る可能性がある。マージ時に競合したら双方の行を残して解消する(AGENTS.md の規律どおり)。D30 の変更行と D33 の新規追加(ファイル末尾)は既存セクションと行番号が離れているため、他作業と衝突する可能性は低い。

---

## 8. `docs/worktree-isolation-strategy.md` の更新

### 変更箇所

「既知のギャップ」節の該当項の削除、および「テスト DB (pgsql)」まわりの本文への追記。

### 現行

「既知のギャップ」に以下がある(削除対象)。

```
- **正典の `scripts/ci/ensure-test-db.php` はスキーマ更新まで担う形になったが追従していない**。
  正典 (家系の裁定 AG-135) は基点 DB を「存在させる」だけでなく `migrate` まで走らせ、
  未適用が残っていないことと更新がその DB に当たったことまで確かめる。本アプリの
  `ensure-test-db.php` は CREATE と出自の記録までで、基点 DB のスキーマが古いまま残りうる
  (DB 系の trait を使わない Architecture のレーンは基点 DB をそのまま読むため、
  新しい worktree でだけ落ちる形の失敗になりうる)。これは意図的な逸脱ではないので
  `docs/template-divergence.md` では正当化していない (aicue:D30 の「この登録が扱わない範囲」)。
```

### 変更後

該当項を削除し、代わりに「なぜテスト DB を worktree ごとに分けるのか」節の直後へ新しい小節を追加する。**Round 1 の Suggestion を反映し、到達確認の保証範囲(監査ではない)を明記した。**

```markdown
### 基点テスト DB のスキーマ更新 (家系の裁定 AG-135)

`ensure-test-db.php` は base DB を「存在させる」だけでなく「スキーマを最新にする」ところまで
担う。DB 系 trait を使わないテストは並列実行の切替が起きず**base DB をそのまま読む**ため、
base DB のスキーマが古いままだと「新しい worktree でだけ落ちる」「実行順で結果が変わる」
失敗になる。実装を必ず worktree で行う進め方 (AGENTS.md §worktree 運用ルール) と組み合わさると
この欠陥を踏む頻度は高くなるため、`scripts/ci/ensure-test-db.php` は毎回無条件で
`migrate --force` を実行し、未適用が無いことと migration ファイルの適用状況を確認するまで
成功にしない (fail-closed)。`tests/Architecture/BaseTestDatabaseSchemaTest.php` が
「その場所で」観測する。

**保証範囲**: この確認は「基点 DB の最終状態がスキーマ最新である」ことの観測であって、
`ensure-test-db.php` の子プロセスがその更新を実際に行ったことの監査ではない。到達確認は
基点 DB へ直接つないだ最終状態しか見ないため、dev DB を誤って更新しないことの保証は
到達確認ではなく、名前の出所の一本化・起動直前の再検証・環境変数の非継承(aicue:D33)で
成立させている。

対象は base DB のみで worker DB (`_test_<token>`) には触らない (Laravel の並列実行と
`RefreshDatabase` が担う層)。使うのは `migrate` だけで、`migrate:fresh` 等の破壊的コマンドは
使わない (AGENTS.md 禁止事項 3。`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` の
引数列検証と負例が固定する)。

`setup-worktree.sh` の工程 [7/7] は今までどおり警告扱いで続行する (pgsql 非接続の環境でも
worktree 作成そのものを壊さないため)。テスト実行時に `run-test.sh` / `run-browser-test.sh` が
同じ ensure をやり直すので fail-closed の実効性は失われない。
```

「テスト DB (pgsql)」テーブル行の実装列にも一言足す。

```
| **テスト DB (pgsql)** | worktree ごとに別 DB (`<slug>_test_<worktree-hash>`) | `tests/Support/Ci/TestDatabaseEnv::workrootHash()` = worktree root realpath の sha1 先頭 8 桁。`scripts/ci/ensure-test-db.php` が冪等 CREATE し、スキーマも最新にする (家系の裁定 AG-135) |
```

「参考」節の記述は変更不要(既存リストが `scripts/ci/ensure-test-db.php` を既に指している)。

### テスト計画

- ドキュメント更新のみ。

### リスク

- なし。「既知のギャップ」の他 2 項目(worktree 規約の自動検証テスト不在 / `.env` 実供給の反映遅延)はそのまま残す(本件のスコープ外)。

---

## 9. `scripts/setup-worktree.sh` の文言更新

### 変更箇所

工程 [7/7] のコメントと echo 文言(行 374-385 付近)。

### 実装フェーズでの波及確認 (Round 1 Warning 対応)

`find` で `setup-worktree` を含む契約テストファイル (`*.contract.test.ts` 等) を確認したところ、本リポジトリには存在しなかった(`scripts/setup-worktree.sh` 自体のみ)。**したがって echo 文言を検査する既存の契約テストは無く、文言は恒久契約にしないため新規のテストも追加しない**。振る舞い(終了コード・制御フロー)は変更しないため、既存の Architecture / Unit テストへの影響もない。

### 現行コード

```bash
# === [7/7] pgsql test base DB を冪等 ensure ===
# worktree の base テスト DB を先に用意する。pgsql 非接続環境でも setup 全体を
# 壊さないよう warning 扱い (test 実行時に run-test.sh が再 ensure する)。
echo ">>> [7/7] ensure pgsql test base DB"
if [[ ! -f "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php" ]]; then
    echo "    warning: scripts/ci/ensure-test-db.php が worktree に無いため skip (test 実行時に再 ensure されます)" >&2
elif php "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php"; then
    echo "    ensure: OK"
else
    echo "    warning: ensure-test-db に失敗 (pgsql 非接続?)。test 実行時に再 ensure されます" >&2
fi
emit_timing "7-ensure-test-db"
```

### 変更後コード

**Round 1 の Warning(警告文が `composer test` のみを案内し browser lane に触れていない)を反映し、`composer test` / `composer test:browser` を併記する。**

```bash
# === [7/7] pgsql test base DB を冪等 ensure (スキーマ更新まで含む) ===
# worktree の base テスト DB を存在させ、スキーマを最新にするところまで用意する
# (家系の裁定 AG-135)。pgsql 非接続環境やスキーマ更新の失敗でも setup 全体を壊さないよう
# warning 扱いで続行する。テスト実行時に run-test.sh / run-browser-test.sh が同じ ensure を
# やり直すので fail-closed の実効性は失われない (テストは composer test / composer test:browser
# のいずれかから実行すること)。
echo ">>> [7/7] ensure pgsql test base DB (schema up to date)"
if [[ ! -f "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php" ]]; then
    echo "    warning: scripts/ci/ensure-test-db.php が worktree に無いため skip (test 実行時に再 ensure されます)" >&2
elif php "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php"; then
    echo "    ensure: OK (schema up to date)"
else
    echo "    warning: ensure-test-db に失敗しました (pgsql 非接続、またはスキーマ更新の失敗)。" >&2
    echo "    'composer test' または 'composer test:browser' から実行すれば同じ準備がやり直されます" >&2
fi
emit_timing "7-ensure-test-db"
```

### テスト計画

- 上記「実装フェーズでの波及確認」のとおり、恒久契約として検査する既存テストは無いため追加しない。制御フロー・終了コードは変えていない。

### リスク

- 文言変更のみで振る舞いは変えない。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `scripts/ci/pgsql_test_conn.php` / `ensure-test-db.php` / 関連テスト / 2 つの docs を一体で変更する必要があり、途中状態で他施策と混ざると「出自の記録とスキーマ更新の順序」のような不変条件が壊れて見えにくくなる。全 9 施策を 1 セッションで完結させる |
| 競合リスク | OCR 対応 (並行作業) が `docs/template-divergence.md` / `docs/worktree-isolation-strategy.md` を触る可能性がある。行番号が離れたセクションのみを変更するため衝突の可能性は低いが、競合時は双方の行を残す (AGENTS.md の規律) |

## 使命・禁止事項の最終確認

- 使命との整合性: テストの信頼性を土台から直すことで AI-CUE の機能開発速度を支える(直接のユーザー価値ではなく基盤改善)。
- 禁止事項: `migrate` のみを使用し `migrate:fresh` 等は使わない(禁止事項 3。引数列の直接検証 + ソース負例の二重で固定)。テストなしの実装完了を作らない(禁止事項 1。Architecture + Unit で固定)。PHPStan の widen は発生しない(禁止事項 2。対象外ファイルのみの変更で、既存 `app`/`config`/`database`/`routes` は無変更)。
- コーディングルール: PHPDoc の shape を全関数に明記。RefreshDatabase の個別 `DatabaseTransactions` は使用しない(該当なし)。テストファースト(施策4・5・6 を先に赤くしてから本体を書く)。
- **`exit()` の境界**: `ensureTestDatabaseSchemaUpdated()` は `exit()` を一切呼ばない。`exit()` を直接呼ぶのは `ensure-test-db.php` のトップレベル(既存の数か所)と `performTestDatabaseSchemaUpdate()` だけであり、いずれも main 境界に閉じている。
