【アプリの使命 (North Star) — AGENTS.md より】

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する(撮影者・教える人のスキルに品質を依存させない)。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置(SECI)。

v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

【禁止事項 — AGENTS.md より】

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(本件は該当なし)
6. prompt 文字列のコード直書き(本件は該当なし)
7. 操作系 POST の応答での `redirect()->intended()`(本件は該当なし)
8. 必須条件未充足を理由にボタンを disabled にする UI(本件は該当なし)
9. Artifact の使用(本件は該当なし)

【思考原則 — 全議論に適用】

まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリケーション改善の詳細設計をレビューしてください。

【前提環境】
- PHP 8.4 + Laravel 12 + Svelte 5 + Inertia.js + TypeScript
- PHPStan level 10 (ただし対象は app/config/database/routes のみで scripts/tests は対象外)
- Pest テストフレームワーク
- DTO + JsonResource パターン
- 本件は scripts/ci/ (素の PHP スクリプト) と tests/ (Unit・Architecture) と docs/ のみを変更する。app/ 配下・route・DB スキーマの変更は一切無い

【レビュー観点】
1. コードの正確性(ロジックエラー、エッジケース、null 安全性)
2. 既存コードとの整合性(命名規約、パターン、API)
3. PHPStan level 10 適合性(型安全性、generics、Assert 使用) — ただし本件のファイルが解析対象外であることの主張が正しいか
4. テスト計画の網羅性(各施策に Pest テスト、RefreshDatabase グローバル適用に従う)
5. DTO/JsonResource パターンの遵守 — 本件は該当なし(HTTP レイヤに触れない)
6. Inertia Props vs API Response の使い分け — 本件は該当なし
7. 副作用・後退リスク
8. 波及変更の網羅性
9. セキュリティ(認可チェック、入力バリデーション、OWASP Top 10、AGENTS.md のセキュリティ不変条件) — 特に dev DB 保護の 4 重防御が本設計で弱まっていないか
10. DESIGN.md 準拠 — 本件は UI/frontend 変更を含まないため非該当
11. Atomic Design 準拠 — 本件は非該当

【本件固有の追加観点】
A. `AGENTS.md` 禁止事項 3(dev DB への破壊操作の禁止)を、スキーマ更新という新しい実行点(子プロセスの起動)がどう守っているか。子プロセスの環境変数の組み立て・dev DB 名の再検証のタイミングに穴が無いか
B. `AGENTS.md` §静的検査(gate)と走査器の共通規約「同じ PR で揃える 4 点」が、`GlobalTestLockInventoryTest.php` への追加ケースで本当に満たされているか(正例・負例/解決不能な形の fail-closed/母集団非空検査/docblock)
C. 正典(laravel-claude-template)の実装との差分が「aicue 独自の上積み(2 関数: `pgsqlTestMigrationFileNames` / `pgsqlTestSchemaUnappliedMigrations`)」に限定されているか、意図せぬ差分が紛れていないか
D. `tests/Unit/Ci/TestDatabaseProvenanceTest.php` の既存アサーション変更が、AGENTS.md の「テストなしの実装完了報告禁止」「後方互換の並走を残さない」の精神から見て正当か(契約の意図的な拡張であり、カバレッジの後退ではないか)
E. `ensure-test-db.php` の失敗時挙動 (fail-closed の 7 条件) に抜けや重複が無いか
F. 実装モード(standalone)の判断は妥当か

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

(devnotes/20260819-1056-ensure-test-db-schema-followup/detailed-design.md の全文を以下に貼り付ける)

# 詳細設計: テスト DB 準備スクリプトのスキーマ更新への追従 (家系の裁定 AG-135)

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

- **PHPStan level 10** 必須(`composer phpstan`)。ただし対象は `app` / `config` / `database` / `routes` のみで、`scripts` / `tests` は解析対象外(`phpstan.neon` で確認済み)。本件の変更は `scripts/ci/` と `tests/` に閉じるため、PHPStan level 10 の直接対象ではない。ただし読み手のためと正典との差分を上積みだけに保つため、PHPDoc の shape (`array<string, string>` / `array{status: int, output: string}` / `list<string>`) は必ず書く。
- **Pest** テストフレームワーク(`composer test`)。
- **RefreshDatabase** + `--parallel` 並列実行。Architecture lane は `tests/Pest.php` で `RefreshDatabase` を付けていない — 本件の核心的な前提(この lane が基点 DB をそのまま読む)。
- **テストデータは必ず Factory で生成** — 本件は Factory を使うモデルテストではない(スクリプト・純関数の Unit / Architecture テスト)ため非該当。
- **DTO + JsonResource** パターン — 本件は HTTP レイヤに触れないため非該当。
- PHP 8.4 + Laravel 12。

## 概念設計リファレンス

`devnotes/20260819-1056-ensure-test-db-schema-followup/conceptual-design.md` (Codex 概念レビュー Round 3 で APPROVED)。

判断の出所: 家系の裁定 AG-135(機能台帳 `php-test-pgsql-lane`、観測点 laravel-claude-template@ccf465a7)。オーナーが 2026-08-19 に「追従する」と決めた。aicue 側の分岐は aicue:T114 の上積みとして `docs/template-divergence.md` の D30 に登録済みで、D30 は本件を「扱わない範囲(遅れであって逸脱ではない)」として明示的に外している。本設計はこの遅れを解消する。

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| 1 | 接続 resolver へ正典の 3 関数 + アプリ独自の強化 2 関数を追加 | `scripts/ci/pgsql_test_conn.php` | High |
| 2 | `ensure-test-db.php` にスキーマ更新を追加 | `scripts/ci/ensure-test-db.php` | High |
| 3 | 基点 DB のスキーマ最新性を検査する Architecture テストを移植 | `tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規) | High |
| 4 | スキーマ更新の純関数を負例込みで固定する Unit テストを追加 | `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (新規) | High |
| 5 | plan の期待値を 3 手順へ更新 | `tests/Unit/Ci/TestDatabaseProvenanceTest.php` | High |
| 6 | ensure-test-db.php の呼び出しがグローバルテストロック配下であることを固定する gate ケースを追加 | `tests/Architecture/GlobalTestLockInventoryTest.php` | Medium |
| 7 | D30 の比較表・扱わない範囲を「追従済み」へ更新 | `docs/template-divergence.md` | Medium |
| 8 | 「既知のギャップ」の該当項を解消し本文へ記述を移す | `docs/worktree-isolation-strategy.md` | Medium |
| 9 | 工程 [7/7] の見出し・警告文言を更新 | `scripts/setup-worktree.sh` | Low |

---

## 1. 接続 resolver への関数追加

### 変更箇所

`scripts/ci/pgsql_test_conn.php` (現状 156 行)

### 波及変更

- TypeScript 型定義: なし
- API Resource/DTO: なし
- テストファイル: `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` (新規、施策4)、`tests/Unit/Ci/TestDatabaseProvenanceTest.php` (更新、施策5)、`tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規、施策3) が本ファイルの関数を直接使う

### 現行コード (該当部)

```php
/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
}

/**
 * @return list<TestDatabaseEnsureAction>
 *                                        $exists=false → [Create, StampProvenance] / $exists=true → [StampProvenance]
 */
function testDatabaseEnsurePlan(bool $exists): array
{
    return $exists
        ? [TestDatabaseEnsureAction::StampProvenance]
        : [TestDatabaseEnsureAction::Create, TestDatabaseEnsureAction::StampProvenance];
}
```

### 変更後コード

冒頭の docblock を、責務が「存在させる」から「存在させ、スキーマを最新にする」へ広がったことが分かるよう更新する(正典 `laravel-claude-template@ccf465a7` の docblock に揃える。二重管理の語り分けは後述)。

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

enum とその計画関数を拡張する。

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

正典由来の 3 関数を、正典 `laravel-claude-template@ccf465a7` と同名・同挙動で追加する(名前と実装を揃える理由は概念設計「名前と実装を正典へ揃える理由」節)。

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
 * Laravel の設定キャッシュの既定パス。
 *
 * ensure-test-db.php はこの値で残存を検査し、**同じ値を子プロセスの APP_CONFIG_CACHE として
 * 明示的に渡す**。検査する場所と読まれる場所を 1 つの値に固定するための関数である。
 */
function pgsqlTestConfigCachePath(string $projectRoot): string
{
    return $projectRoot.'/bootstrap/cache/config.php';
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

さらに、**正典より 1 段強い到達確認**を成り立たせるための、aicue 独自の 2 つの純関数を追加する。正典は「migrations 表があり行が 1 件以上ある」で到達確認を止めているが、これでは古い基点 DB に古い migrations 表が残っている状態を通してしまう。本アプリは「migrations 表が存在し、`database/migrations` の全ファイル名がその表に含まれる」を成功条件にする。この 2 関数は `ensure-test-db.php` (施策2) と `tests/Architecture/BaseTestDatabaseSchemaTest.php` の B-2 (施策3) の両方から**同じ関数を呼ぶ**ことで、スクリプトと検査の判定がずれない形にする(正典より強い還流候補)。

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

### リスク

- 正典と関数名を揃えることで、家系キュレーターの md5 比較・実読による「新しい分岐」の誤検出を防ぐ。一方で `pgsqlTestMigrationFileNames` / `pgsqlTestSchemaUnappliedMigrations` の 2 関数は aicue 独自(正典に無い)なので、D30 の比較表に明記する(施策7)。

---

## 2. `ensure-test-db.php` へのスキーマ更新の追加

### 変更箇所

`scripts/ci/ensure-test-db.php` (現状 62 行)

### 波及変更

- テストファイル: 施策3・4・5・6 が対象。他に波及なし。

### 現行コード

(冒頭で全文読み込み済み。§背景の Read 結果を参照。責務は CREATE + StampProvenance までで、スキーマ更新を持たない。)

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
 * run-test.sh / run-browser-test.sh / setup-worktree.sh / CI が test 前に本スクリプトを呼ぶ。
 *
 * dev-DB 保護 (4 重。AGENTS.md 禁止事項 3):
 *   1. 名前の出所 — 基点名は TestDatabaseEnv::pgsqlBaseDatabase() の 1 か所だけが決める
 *   2. 名前の検査 — allowlist 一致 + dev 名 deny を CREATE / スキーマ更新の直前に再確認する
 *   3. 子プロセスの環境 — 継承せず許可リストで組み立て、DB_DATABASE を算出した基点名で固定する
 *      (この devcontainer の shell には dev DB 名が export されており、素直に継承すると
 *      スキーマ更新が dev DB に当たる)
 *   4. 到達確認 — 更新後に基点 DB へ直接つなぎ、database/migrations の全ファイルが
 *      適用済みであることまで確かめる (正典より 1 段強い基準。下記参照)
 *
 * 到達確認は正典より強い: 正典 (laravel-claude-template) は「migrations 表があり
 * 行が 1 件以上ある」で止まるが、それでは古い基点 DB に古い migrations 表が残っている
 * 状態を通してしまう。本スクリプトは pgsqlTestSchemaUnappliedMigrations() で
 * 「migrations 表が存在し、database/migrations の全ファイル名がその表に含まれる」を
 * 成功条件にする。tests/Architecture/BaseTestDatabaseSchemaTest.php の B-2 と
 * 同じ関数を共有しており、スクリプトと検査で判定がずれない。
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

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/pgsql_test_conn.php';

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
 * base DB のスキーマ更新 (UpdateSchema action の本体)。
 *
 * 失敗時は理由を stderr へ書いて exit(1) する (fail-closed)。個々の判定
 * (env の組み立て・未適用ファイルの差分) は pgsql_test_conn.php の純関数へ出してあり、
 * 単体テストはそちらの純関数を対象にする (本関数自体は実 DB / 実子プロセスに触れるため
 * 単体テストの対象にしない)。
 */
function ensureTestDatabaseSchemaUpdated(string $projectRoot, string $base): void
{
    $env = pgsqlTestArtisanEnv($projectRoot, $base);
    $where = "db={$base} host={$env['DB_HOST']}:{$env['DB_PORT']}";

    // 更新自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
    // 同じ判定を二重に持つことになる (毎回無条件で実行する)。
    $migrate = runTestDatabaseArtisan($projectRoot, ['migrate', '--force', '--no-interaction'], $env, false);
    if ($migrate['status'] !== 0) {
        fwrite(STDERR, "ensure-test-db: スキーマ更新に失敗しました ({$where}, exit={$migrate['status']})\n");
        exit(1);
    }

    // 未適用が残っていないことを artisan 自身の判定で確かめる。
    // 値を渡したときだけその値が終了コードになる (値を渡さない形は未適用があっても 0 を返す)。
    $pending = runTestDatabaseArtisan($projectRoot, ['migrate:status', '--pending=1'], $env, true);
    if ($pending['status'] !== 0) {
        fwrite(STDERR, "ensure-test-db: 未適用の migration が残っています ({$where})\n");
        fwrite(STDERR, $pending['output']);
        exit(1);
    }

    // 別経路の到達確認: 子プロセスの環境変数の解決が壊れていても気付けるよう、
    // 基点 DB へ直接つないで database/migrations の全ファイルが適用済みであることを確かめる。
    $files = glob($projectRoot.'/database/migrations/*.php');
    Assert::isArray($files);
    if ($files === []) {
        fwrite(STDERR, "ensure-test-db: database/migrations にファイルがありません (到達確認が空振りするため中止)\n");
        exit(1);
    }
    $expected = pgsqlTestMigrationFileNames($files);

    try {
        $check = pgsqlTestDatabasePdo($projectRoot, $base);
        $table = $check->query("SELECT to_regclass('public.migrations')")->fetchColumn();
        if ($table === null || $table === false) {
            fwrite(STDERR, "ensure-test-db: 更新後も migrations 表がありません ({$where})\n");
            exit(1);
        }
        /** @var list<string> $applied */
        $applied = $check->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        fwrite(STDERR, "ensure-test-db: 更新後の確認接続に失敗しました ({$where}): {$e->getMessage()}\n");
        exit(1);
    }

    $unapplied = pgsqlTestSchemaUnappliedMigrations($applied, $expected);
    if ($unapplied !== []) {
        fwrite(STDERR, "ensure-test-db: 更新後も未適用の migration ファイルが残っています ({$where}): "
            .implode(', ', $unapplied)."\n");
        exit(1);
    }

    fwrite(STDERR, 'ensure-test-db: schema up to date: '.$base.' ('.count($applied)." migrations)\n");
}

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE / スキーマ更新の直前に再確認)。
Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

// 設定キャッシュが残っていると、子プロセスへ渡す環境変数が無視されて dev DB を見に行きうる。
// 検出したら停止する (fail-closed。run-test.sh / run-browser-test.sh は本スクリプトの前に
// config:clear を実行しているが、直接実行や別経路も同じ保護を持つよう二重に検査する)。
$configCache = pgsqlTestConfigCachePath($projectRoot);
if (is_file($configCache)) {
    fwrite(STDERR, "ensure-test-db: 設定キャッシュが残っています: {$configCache}\n");
    fwrite(STDERR, "  'php artisan config:clear' を実行してから再実行してください (キャッシュがあると DB 指定が効きません)\n");
    exit(1);
}

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
        TestDatabaseEnsureAction::UpdateSchema => ensureTestDatabaseSchemaUpdated($projectRoot, $base),
    };
}

fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

### PHPStan 適合チェック

- [x] 戻り値の型明示(`void` / `array{status: int, output: string}`)
- [x] `Assert::isArray` / `Assert::string` で null 安全
- [x] `scripts/` は PHPStan level 10 の対象外(誇張しない。上記どおり明記)

### テスト計画

- [x] バグ修正ではなく機能追加のため、先に施策4・5 のテストを赤くしてから本体を書く(テストファースト)
- [x] 既存テスト `tests/Unit/Ci/TestDatabaseProvenanceTest.php` の更新(施策5)
- [x] 新規テスト `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php`(施策4)
- [x] 新規 Architecture テスト `tests/Architecture/BaseTestDatabaseSchemaTest.php`(施策3)
- [x] 個別の `DatabaseTransactions` は使っていない

### リスク

- **実行時間の増加**: artisan 起動が最大 2 回(migrate + migrate:status)増える。正典の実測は「何もしないとき約 0.53 秒 / 空の DB から全適用で約 0.66 秒」。実装フェーズで aicue でも実測し、docblock に記録する(概念設計の制約・前提に明記済み)。
- **`exit()` を関数内から呼ぶ設計**: `ensureTestDatabaseSchemaUpdated()` は失敗時に自身の中で `exit(1)` する。これは同スクリプトの `main` レベルで直接 `exit()` している既存コードと同じスタイルであり、単体テストの対象を「実 DB に触れない純関数(env 組み立て・ファイル名抽出・差分判定)」に絞ることで、`exit()` を含む関数自体を単体テストしようとしない設計にしている(概念設計の「実行境界へ callable を注入する / 純関数を直接呼ぶ」という既存 Unit/Ci の形をそのまま踏襲)。

---

## 3. `BaseTestDatabaseSchemaTest.php` の移植

### 変更箇所

`tests/Architecture/BaseTestDatabaseSchemaTest.php` (新規)

### 波及変更

なし(新規テストファイルのみ)。

### 変更後コード

正典 `laravel-claude-template@ccf465a7` の `tests/Architecture/BaseTestDatabaseSchemaTest.php` を移植し、B-2 の到達確認を aicue 共有の `pgsqlTestMigrationFileNames` / `pgsqlTestSchemaUnappliedMigrations` を使う形に変える(正典は自前で `array_diff` を書いているが、aicue はスクリプト側と同じ関数を呼ぶことで判定のずれを構造的に防ぐ)。

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
| ■ B-2 の到達確認基準は scripts/ci/ensure-test-db.php と同じ関数
|   (pgsqlTestMigrationFileNames / pgsqlTestSchemaUnappliedMigrations) を使う。
|   スクリプトと検査で判定がずれると「準備は成功したのにこのテストだけ落ちる」逆転が起き得るため、
|   計算を 1 か所 (pgsql_test_conn.php) に寄せてある。
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

### 変更後コード

```php
<?php

declare(strict_types=1);

require_once __DIR__.'/../../../scripts/ci/pgsql_test_conn.php';

/*
 * ensure-test-db.php のスキーマ更新まわりの純関数を固定する Unit テスト。
 *
 * 固定する不変条件:
 *   1. pgsqlTestArtisanEnv() は環境を継承せず組み立てる (固定キーが常に勝つ / 許可した
 *      3 キーだけ継承する / DB_URL は空で固定する)
 *   2. pgsqlTestConfigCachePath() は projectRoot からの一意な固定パスを返す
 *   3. pgsqlTestMigrationFileNames() はパスから拡張子・ディレクトリを取り除く
 *   4. pgsqlTestSchemaUnappliedMigrations() は「ファイル -> 表」の包含判定であり、
 *      表側だけ余分にあっても (vendor パッケージ由来) 合格になる一方、
 *      ファイル側にあって表に無いものは 1 件でも検出する
 *   5. ensure-test-db.php のソースが migrate:fresh / migrate:refresh / migrate:rollback /
 *      db:wipe を使っていない (AGENTS.md 禁止事項 3。負例)
 *
 * 本テストは実 DB を作らず、実子プロセスも起動しない (純関数の入出力とソース走査のみ)。
 */

// ── pgsqlTestArtisanEnv(): 環境を継承しない子プロセス env ──

it('does not leak arbitrary environment variables into the child process env', function (): void {
    putenv('SOME_SECRET=leaked');
    $env = pgsqlTestArtisanEnv(__DIR__, 'app_test_8af22c44');
    putenv('SOME_SECRET');

    expect($env)->not->toHaveKey('SOME_SECRET');
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

// ── pgsqlTestConfigCachePath(): 検査する場所と読む場所を 1 つの値に固定する ──

it('returns a fixed config cache path derived from the project root', function (): void {
    expect(pgsqlTestConfigCachePath('/workspace'))->toBe('/workspace/bootstrap/cache/config.php');
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

// ── T-負例: 破壊的コマンドを使っていないこと (AGENTS.md 禁止事項 3) ──

it('never invokes migrate:fresh, migrate:refresh, migrate:rollback, or db:wipe', function (): void {
    $source = file_get_contents(__DIR__.'/../../../scripts/ci/ensure-test-db.php');
    expect($source)->toBeString();

    foreach (['migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'db:wipe'] as $forbidden) {
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
```

### PHPStan 適合チェック

- 対象外(`tests/` は `phpstan.neon` の解析対象外)。

### テスト計画

- [x] 再現テストではなく新規機能のテスト(先に赤くしてから `pgsql_test_conn.php` の本体を書く)
- [x] 負例(破壊的コマンド不使用・判定関数の空振り検出)を含む
- [x] 実 DB を作らない・実子プロセスを起動しない

### リスク

- なし(純関数とソース走査のみ)。

---

## 5. `TestDatabaseProvenanceTest.php` の更新

### 変更箇所

`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の T-C2-17 ブロック(3 ケース)

### 変更理由

`testDatabaseEnsurePlan()` の契約を「2 手順」から「3 手順」へ意図的に拡張したための更新である。既存の意図(両分岐とも出自を記録する = 冪等)は変えず、そこへ「両分岐ともスキーマ更新も行う」を追加する。これはテスト削除ではなく、変更した契約に追随する必須の更新である(先に本ファイルを新しい期待値へ書き換えて赤くし、施策1・2 の実装で緑にする = テストファースト)。

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

### 波及理由 (AGENTS.md §静的検査 gate と走査器の共通規約)

`ensure-test-db.php` の呼び出しが `global_test_lock_run` 経由であることを固定するのは「判定条件・走査対象の変更」に当たるため、概念設計 Round 2 の Codex 指摘のとおり「同じ PR で揃える 4 点」が発火する。以下のとおり揃える。

1. **正例・負例**: 正例は既存の `run-test.sh` / `run-browser-test.sh` の呼び方が通ること。負例は合成入力(ロックを通さない呼び出し形)。テストファースト。
2. **解決できない形を落とす**: `global_test_lock_run` から始まる形でもコメント行でもない `ensure-test-db.php` を含む行は、解釈できない呼び出し形として違反にする(無言で候補から外さない)。
3. **走査の空振り検査**: 対象スクリプトに `ensure-test-db.php` への言及が 1 件も無ければ、それ自体を違反として報告する(綴りを変えられて判定が空振りする事故を防ぐ)。
4. **docblock**: 走査対象(`run-test.sh` / `run-browser-test.sh`)と対象外(`setup-worktree.sh` はロックの外で呼ぶのが仕様。CI の workflow から直に叩く形は見ない)を明記する。

5 条のうち (a) クラス名・名前参照の解決は非適用(シェル文字列の正規表現走査のため)。(e) 語彙一致の否定形も非適用(判定は呼び出し形の肯定一致で行う)。

### 変更後コード (追加分)

```php
/**
 * ensure-test-db.php を呼ぶスクリプト。この 2 レーンだけが対象で、
 * setup-worktree.sh はロックの外で呼ぶのが仕様のため対象外にする
 * (docs/worktree-isolation-strategy.md の [7/7] 参照)。
 * CI の workflow から scripts/ci/ensure-test-db.php を直に叩く形は運用していないため見ない。
 */
const GLOBAL_TEST_LOCK_ENSURE_TEST_DB_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
];

/**
 * ensure-test-db.php の呼び出し行だけを対象に、global_test_lock_run 経由であることを
 * 検査する (純関数)。
 *
 * 基点 DB のスキーマ更新はここで初めて実 DB へ触れる (家系の裁定 AG-135)。
 * グローバルテストロックの外で呼ばれると、同一マシン上の別レーンの基点 DB 更新と
 * 競合しうる (Postgres の DDL 自体は個々に安全でも、artisan の子プロセスが
 * 同時に走ることは想定していない)。
 *
 * 解釈できない呼び出し形 (global_test_lock_run から始まらずコメント行でもない行に
 * ensure-test-db.php が現れる) は fail-closed で違反にする。
 *
 * @return list<string> 違反一覧 (空 = 合格。母集団が空 = 走査の空振りも違反として返す)
 */
function globalTestLockEnsureTestDbViolations(string $path, string $source): array
{
    $violations = [];
    $mentioned = false;

    foreach (preg_split('/\R/u', globalTestLockCodeLines($source)) ?: [] as $rawLine) {
        $line = trim($rawLine);
        if (! str_contains($line, 'ensure-test-db.php')) {
            continue;
        }
        $mentioned = true;
        if (! str_starts_with($line, 'global_test_lock_run')) {
            $violations[] = "{$path} の ensure-test-db.php 呼び出しが global_test_lock_run 経由ではない: {$line}";
        }
    }

    if (! $mentioned) {
        $violations[] = "{$path} に ensure-test-db.php への言及が無い (走査が空振りしている可能性)";
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
    expect(implode("\n", $violations))->toContain('global_test_lock_run 経由ではない');
});

test('負のコントロール: ensure-test-db.php への言及が無いスクリプトを走査の空振りとして検出する', function (): void {
    $violations = globalTestLockEnsureTestDbViolations('fixture.sh', "#!/usr/bin/env bash\necho no-op\n");
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('空振り');
});
```

### テスト計画

- [x] 正例(実ファイル 2 本)+ 負例 2 本(ロック外呼び出し / 言及ゼロの空振り検出)
- [x] 既存ケースは 1 つも削除・変更しない(追加のみ)

### リスク

- `run-browser-test.sh` の当該行はコメント2行の直後に `global_test_lock_run php scripts/ci/ensure-test-db.php` の形で存在する(既に読み込み済みの現行コードで確認済み)。文言変更は不要。

---

## 7. `docs/template-divergence.md` (D30) の更新

### 変更箇所

D30 の比較表(行「基点 DB のスキーマ更新」)・「揃え続ける不変条件」・「この登録が扱わない範囲」節・「関連」節。

### 現行

```
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 持たない。本登録の対象外 (下の「この登録が扱わない範囲」) |
```

および「この登録が扱わない範囲(遅れであって逸脱ではない)」節がスキーマ更新の不在を説明している。

### 変更後

比較表の行を更新する。

```
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 追従済み。到達確認は正典より強い基準にしている (下記) |
| 到達確認の基準 | migrations 表があり行が 1 件以上ある | `database/migrations` の全ファイル名が migrations 表に含まれる (還流候補) |
```

「揃え続ける不変条件と保証機構」のセルへ 1 文を追加する。

```
併せて、家系の裁定 AG-135 への追従で「出自の記録 (StampProvenance) はスキーマ更新
(UpdateSchema) より先に実行する」を不変条件へ加える (スキーマ更新の失敗時に
「ラベルの無い現役 DB」を残さないため)。`tests/Unit/Ci/TestDatabaseProvenanceTest.php` の
`always plans the schema update last, after the provenance stamp` が固定する。
```

「この登録が扱わない範囲(遅れであって逸脱ではない)」節を、追従が完了した旨へ書き換える。

```markdown
### 追従の記録 (旧: この登録が扱わない範囲)

正典 HEAD の `ensure-test-db.php` が担う基点 DB のスキーマ更新 (家系の裁定 AG-135) に、
`devnotes/20260819-1056-ensure-test-db-schema-followup/` の設計で追従した
(オーナー決定 2026-08-19)。追従にあたり、到達確認だけは正典より強い基準にしている
(「migrations 表があり行が 1 件以上ある」ではなく「database/migrations の全ファイルが
migrations 表に含まれる」)。これは還流候補として扱う。

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

### テスト計画

- ドキュメント更新のみのためテスト対象なし。ただし本文が指す先(`BaseTestDatabaseSchemaTest.php` 等)が実在することは施策3・4 のテストが担保する。

### リスク

- OCR 対応など並行作業が同じ `docs/template-divergence.md` を触る可能性がある。マージ時に競合したら双方の行を残して解消する(AGENTS.md の規律どおり)。D30 は本設計の対象と行番号が離れた既存セクションなので、他作業と衝突する可能性は低い。

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

該当項を削除し、代わりに「なぜテスト DB を worktree ごとに分けるのか」節の直後へ新しい小節を追加する。

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

対象は base DB のみで worker DB (`_test_<token>`) には触らない (Laravel の並列実行と
`RefreshDatabase` が担う層)。使うのは `migrate` だけで、`migrate:fresh` 等の破壊的コマンドは
使わない (AGENTS.md 禁止事項 3。`tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` の負例が固定する)。

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

```bash
# === [7/7] pgsql test base DB を冪等 ensure (スキーマ更新まで含む) ===
# worktree の base テスト DB を存在させ、スキーマを最新にするところまで用意する
# (家系の裁定 AG-135)。pgsql 非接続環境やスキーマ更新の失敗でも setup 全体を壊さないよう
# warning 扱いで続行する。テスト実行時に run-test.sh / run-browser-test.sh が同じ ensure を
# やり直すので fail-closed の実効性は失われない (テストは必ず composer test から実行すること)。
echo ">>> [7/7] ensure pgsql test base DB (schema up to date)"
if [[ ! -f "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php" ]]; then
    echo "    warning: scripts/ci/ensure-test-db.php が worktree に無いため skip (test 実行時に再 ensure されます)" >&2
elif php "${WORKTREE_DIR}/scripts/ci/ensure-test-db.php"; then
    echo "    ensure: OK (schema up to date)"
else
    echo "    warning: ensure-test-db に失敗しました (pgsql 非接続、またはスキーマ更新の失敗)。" >&2
    echo "    テストは 'composer test' (= scripts/run-test.sh) から実行すれば同じ準備がやり直されます" >&2
fi
emit_timing "7-ensure-test-db"
```

### テスト計画

- 本ファイルは `scripts/setup-worktree.contract.test.ts` 等の契約テストの対象になっている場合、echo 文言の変更が既存アサーションの文字列一致を壊さないか実装フェーズで確認する(文言変更が唯一の変更点であり、制御フロー・終了コードは変えていない)。

### リスク

- 文言変更のみで振る舞いは変えない。契約テストが文言の完全一致を検査している場合のみ実装フェーズで追随が必要。

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | standalone |
| 判断根拠 | `scripts/ci/pgsql_test_conn.php` / `ensure-test-db.php` / 関連テスト / 2 つの docs を一体で変更する必要があり、途中状態で他施策と混ざると「出自の記録とスキーマ更新の順序」のような不変条件が壊れて見えにくくなる。全 9 施策を 1 セッションで完結させる |
| 競合リスク | OCR 対応 (並行作業) が `docs/template-divergence.md` / `docs/worktree-isolation-strategy.md` を触る可能性がある。行番号が離れたセクションのみを変更するため衝突の可能性は低いが、競合時は双方の行を残す (AGENTS.md の規律) |

## 使命・禁止事項の最終確認

- 使命との整合性: テストの信頼性を土台から直すことで AI-CUE の機能開発速度を支える(直接のユーザー価値ではなく基盤改善)。
- 禁止事項: `migrate` のみを使用し `migrate:fresh` 等は使わない(禁止事項 3。負例で固定)。テストなしの実装完了を作らない(禁止事項 1。Architecture + Unit で固定)。PHPStan の widen は発生しない(禁止事項 2。対象外ファイルのみの変更で、既存 `app`/`config`/`database`/`routes` は無変更)。
- コーディングルール: PHPDoc の shape を全関数に明記。RefreshDatabase の個別 `DatabaseTransactions` は使用しない(該当なし)。テストファースト(施策4・5・6 を先に赤くしてから本体を書く)。

---

## 関連する現行コード (変更前)

### scripts/ci/ensure-test-db.php (現行・変更前)
```php
<?php

declare(strict_types=1);

/*
 * scripts/ci/ensure-test-db.php
 *
 * pgsql テストの base DB (`<slug>_test_<worktree-hash>`) を不在時のみ冪等 CREATE する。
 * Laravel の ParallelTesting が base に `_test_<token>` を付した per-worker DB を作るが、
 * base DB 自体は事前に存在している必要があるため、run-test.sh / CI が test 前に本スクリプトを呼ぶ。
 *
 * dev-DB 保護:
 *   - base 名は TestDatabaseEnv::pgsqlBaseDatabase() (= 唯一のソース)。CREATE 前に
 *     isAllowedTestDatabase() 再検証 + isDevDatabase() deny で二重防御。
 *   - 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
 */

use Tests\Support\Ci\TestDatabaseEnv;
use Webmozart\Assert\Assert;

require __DIR__.'/../../vendor/autoload.php';
require __DIR__.'/pgsql_test_conn.php';

$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

// dev-DB 二重防御 (pgsqlBaseDatabase 内でも検査済だが、CREATE 直前に再確認)。
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
// 孤児 sweep (drop-test-db.php --orphans) の**分類材料**であって guard ではない。
// 既存 DB でも必ず通す = 冪等 (ここを通さないと「ラベルの無い現役 DB」が生まれる)。
$provenance = realpath($projectRoot);
Assert::string($provenance, "projectRoot must resolve to a real path: {$projectRoot}");

foreach (testDatabaseEnsurePlan($exists) as $action) {
    match ($action) {
        TestDatabaseEnsureAction::Create => $pdo->exec(pgsqlCreateDatabaseSql($base)),
        TestDatabaseEnsureAction::StampProvenance => pgsqlStampProvenance(
            static fn (string $sql): mixed => $pdo->exec($sql),
            pgsqlCommentDatabaseSql($pdo, $base, $provenance),
        ),
    };
}

fwrite(STDERR, $exists
    ? "ensure-test-db: base DB already exists: {$base}\n"
    : "ensure-test-db: created base DB: {$base}\n");
exit(0);
```

### scripts/ci/pgsql_test_conn.php (現行・変更前)
```php
<?php

declare(strict_types=1);

/*
 * scripts/ci/pgsql_test_conn.php
 *
 * ensure-test-db.php / drop-test-db.php 共有の接続 resolver。
 * 両スクリプトが本ファイルを require し、同一の接続値・同一の maintenance PDO を使うことで
 * 「ensure は作るが drop は別 PostgreSQL を見て回収しない」ズレ (stale DB) を構造的に排除する。
 *
 * 接続値の解決はテスト lane (APP_ENV=testing) と同一の優先順位:
 *   shell env (docker-compose の export が最優先) → .env.testing → 固定 default
 *   (127.0.0.1:5432 postgres/postgres = .env.testing の既定値と同一)。
 * これにより phpunit 本体と ensure/drop が必ず同じ PostgreSQL を見る。
 *
 * maintenance DB は固定で `postgres` (CREATE/DROP DATABASE は TX 内不可なので
 * autocommit 接続を maintenance DB に張る)。実テスト base 名は
 * TestDatabaseEnv::pgsqlBaseDatabase() が決める (本ファイルは名前を決めない)。
 */

/**
 * テスト lane と同一優先順位で DB 接続値を解決する。
 *
 * @return array{host: string, port: string, username: string, password: string}
 */
function pgsqlTestConnValues(string $projectRoot): array
{
    // shell env を尊重しつつ .env.testing で補完する (Laravel testing lane と同じ immutable 挙動)
    if (is_file($projectRoot.'/.env.testing') && class_exists(Dotenv\Dotenv::class)) {
        Dotenv\Dotenv::createImmutable($projectRoot, '.env.testing')->safeLoad();
    }

    $env = static function (string $key, string $default): string {
        $v = $_SERVER[$key] ?? $_ENV[$key] ?? getenv($key);

        return is_string($v) && $v !== '' ? $v : $default;
    };

    return [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => $env('DB_PORT', '5432'),
        'username' => $env('DB_USERNAME', 'postgres'),
        'password' => $env('DB_PASSWORD', 'postgres'),
    ];
}

/**
 * maintenance DB (`postgres`) への autocommit PDO を返す。
 * CREATE/DROP DATABASE は TX 内で実行不可のため maintenance DB へ張る。
 */
function pgsqlTestMaintenancePdo(string $projectRoot): PDO
{
    $c = pgsqlTestConnValues($projectRoot);
    $dsn = "pgsql:host={$c['host']};port={$c['port']};dbname=postgres";

    return new PDO($dsn, $c['username'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

/**
 * 識別子 (DB 名) を PostgreSQL の二重引用符でクォートする。
 * DB 名は allowlist 正規表現で検証済みのものだけを渡す前提 (二重防御)。
 */
function pgsqlQuoteIdentifier(string $name): string
{
    return '"'.str_replace('"', '""', $name).'"';
}

/**
 * base DB 不在時のみ実行する CREATE DATABASE 文を生成する (冪等は呼び出し側で pg_database 確認)。
 * base 名は TestDatabaseEnv::pgsqlBaseDatabase() (allowlist 準拠) からのみ渡される前提。
 */
function pgsqlCreateDatabaseSql(string $name): string
{
    return 'CREATE DATABASE '.pgsqlQuoteIdentifier($name);
}

/**
 * allowlist 検証済み DB 名に対する DROP 文を生成する。WITH (FORCE) で接続中でも落とす。
 */
function pgsqlDropDatabaseSql(string $name): string
{
    return 'DROP DATABASE IF EXISTS '.pgsqlQuoteIdentifier($name).' WITH (FORCE)';
}

/**
 * allowlist 検証済み DB 名に、出自 (worktree の realpath) を記録する COMMENT 文を生成する。
 *
 * 孤児 sweep (`drop-test-db.php --orphans`) が「削除済み worktree の残骸」と
 * 「同一 PostgreSQL を共有する**別クローンの生存 DB**」を区別するための**分類材料**。
 * **信頼境界ではない** (誰でも書き換えられるため単独では guard にならない)。
 * 識別子は pgsqlQuoteIdentifier、リテラルは PDO::quote で組み立てる (独自連結はしない。
 * provenance path に `'` が含まれうる)。非破壊 DDL なので ensure 側から実行してよい。
 */
function pgsqlCommentDatabaseSql(PDO $pdo, string $name, string $provenance): string
{
    return 'COMMENT ON DATABASE '.pgsqlQuoteIdentifier($name).' IS '.$pdo->quote($provenance);
}

/** ensure が行う操作。SQL 生成はしない (クォート責務は既存の SQL ビルダに残す)。 */
enum TestDatabaseEnsureAction
{
    case Create;
    case StampProvenance;
}

/**
 * ensure が実行すべき action 列を返す (純関数。PDO にも SQL にも触れない)。
 *
 * **両分岐とも StampProvenance を含む**のが契約: 既存 DB のときに省くと
 * 「ラベルの無い現役 DB」が生まれ、将来の孤児 sweep の分類材料が欠ける (= 冪等にする)。
 *
 * @return list<TestDatabaseEnsureAction>
 *                                        $exists=false → [Create, StampProvenance] / $exists=true → [StampProvenance]
 */
function testDatabaseEnsurePlan(bool $exists): array
{
    return $exists
        ? [TestDatabaseEnsureAction::StampProvenance]
        : [TestDatabaseEnsureAction::Create, TestDatabaseEnsureAction::StampProvenance];
}

/**
 * provenance ラベルを **best-effort** で実行する。`$exec` を注入するので PDO 無しでテストできる。
 *
 * fail-closed にしない理由: comment は分類材料であって必須ではない。ここで落とすと
 * 権限設定の差でテスト実行そのものが止まり、**偽赤を増やす**。
 * 「ラベルの無い DB がフラグ 1 つで一括 DROP される」危険の方は
 * `--include-hash` の明示指定制 (一括フラグを用意しない) で構造的に潰してある。
 *
 * 例外だけでなく **`$exec` の戻り値 `false`** も失敗として扱う
 * (`PDO::exec()` は ERRMODE 次第で例外ではなく false を返す)。
 *
 * @param  callable(string): mixed  $exec
 * @return bool 成功したか (失敗時は false + stderr へ warning。例外は伝播させない)
 */
function pgsqlStampProvenance(callable $exec, string $sql): bool
{
    try {
        if ($exec($sql) === false) {
            fwrite(STDERR, "ensure-test-db: provenance コメントの記録に失敗 (best-effort / 続行)\n");

            return false;
        }

        return true;
    } catch (Throwable $e) {
        fwrite(STDERR, "ensure-test-db: provenance コメントの記録に失敗 (best-effort / 続行): {$e->getMessage()}\n");

        return false;
    }
}
```

### tests/Unit/Ci/TestDatabaseProvenanceTest.php (現行・変更前)
```php
<?php

declare(strict_types=1);

require_once __DIR__.'/../../../scripts/ci/pgsql_test_conn.php';

/*
 * ensure-test-db.php が付ける provenance ラベル (COMMENT ON DATABASE) の Unit テスト。
 *
 * 固定する不変条件:
 *   1. ensure の plan は **作成時・既存時の両方**で StampProvenance を含む (= 冪等。
 *      ここを片方だけにすると「ラベルの無い現役 DB」が生まれ、孤児 sweep の分類材料が欠ける)
 *   2. COMMENT 文のリテラルは **PDO::quote() 経由**で組み立てる (独自連結しない。
 *      provenance の realpath に `'` が含まれうる)
 *   3. ラベル付与は **best-effort**。失敗しても例外を伝播させずテスト実行を止めない
 *      (権限差で偽赤を増やさない。危険の本体「ラベル無し DB の一括 DROP」は
 *       --include-hash の明示指定制で潰してある)
 *
 * 本テストは実 DB を作らない (SQL 文字列の生成と callable の注入のみ)。
 */

// ── T-C2-17: plan は両分岐とも StampProvenance を含む ──

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

// ── T-C2-17b: 識別子 / リテラルのクォート ──

it('quotes the identifier and delegates the literal to PDO::quote', function (): void {
    $pdo = new PDO('sqlite::memory:');

    expect(pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', '/workspace'))
        ->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
});

it('escapes single quotes in the provenance path via PDO::quote (no manual concatenation)', function (): void {
    $pdo = new PDO('sqlite::memory:');

    $sql = pgsqlCommentDatabaseSql($pdo, 'app_test_8af22c44', "/home/o'brien/repo");

    expect($sql)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/home/o\'\'brien/repo\'')
        // 生の `'` が閉じられないまま残っていないこと (クォート漏れの回帰検出)
        ->and(substr_count($sql, "'") % 2)->toBe(0);
});

it('quotes a double quote inside the identifier', function (): void {
    $pdo = new PDO('sqlite::memory:');

    expect(pgsqlCommentDatabaseSql($pdo, 'weird"name', '/workspace'))
        ->toStartWith('COMMENT ON DATABASE "weird""name" IS ');
});

// ── T-C2-18 / T-C2-18b: best-effort な stamp ──

it('returns true and passes the COMMENT statement through when the exec succeeds', function (): void {
    $seen = null;
    $result = pgsqlStampProvenance(function (string $sql) use (&$seen): int {
        $seen = $sql;

        return 1;
    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');

    expect($result)->toBeTrue()
        ->and($seen)->toBe('COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');
});

it('treats a false return value as a failure (PDO::exec can return false instead of throwing)', function (): void {
    $result = pgsqlStampProvenance(
        static fn (string $sql): bool => false,
        'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'',
    );

    expect($result)->toBeFalse();
});

it('swallows exec failures so that a permission difference cannot break the test lane', function (): void {
    $result = pgsqlStampProvenance(static function (string $sql): never {
        throw new RuntimeException('permission denied for database');
    }, 'COMMENT ON DATABASE "app_test_8af22c44" IS \'/workspace\'');

    expect($result)->toBeFalse();
});
```

### tests/Architecture/GlobalTestLockInventoryTest.php (現行・変更前・全文)
```php
<?php

declare(strict_types=1);

/*
 * Architecture invariant: 全テストレーンがグローバルテストロックを経由すること。
 *
 * 背景 (SoT = devnotes/20260804-2319-global-test-lock/conceptual-design.md):
 * 複数 worktree の並行実装でテストレーンが同時に走ると、PostgreSQL サーバ・実ブラウザ・
 * CPU/メモリを奪い合い、Browser lane の machine-wide な playwright 掃除が他レーンの
 * run-server を巻き込む。旧実装は worktree-local な flock (cross-worktree 排他ゼロ) かつ
 * flock -n (待たずに即エラー) だったため、これを scripts/global-test-lock.sh へ一本化した。
 *
 * worktree-local flock を「残さず削除する」判断が安全なのは、公式 entrypoint を
 * **全て確実に包めている場合に限る**。よって本テストは deny-by-default の inventory とする:
 * composer.json / package.json の test 系スクリプトは、明示 exemption に無い限り
 * ロック経由でなければ fail する (新レーン追加時に落ちて気づける)。
 *
 * 並行挙動そのものは scripts/verify-global-test-lock.sh (層 1) が検証する。
 * **本テストから層 1 を実行してはならない**: 本テストは composer test の内側
 * = グローバルロック保持中に走るため、自分自身と競合する。
 */

/** watch / 対話用途のため意図的にラップしない script と、その理由。 */
const GLOBAL_TEST_LOCK_EXEMPT = [
    'test:ui' => 'vitest --ui (常駐 UI サーバ)。無期限にロックを保持するため対象外',
    'test:watch' => 'vitest --watch (常駐 watch)。同上',
];

/** ロック経由と認められる呼び出し先 (これ自身がライブラリを source していることも検査する)。 */
const GLOBAL_TEST_LOCK_LANE_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * 構造検査の対象スクリプト = lane スクリプト 3 本 + 汎用ラッパ。
 * ラッパを対象外にすると、将来 `exec "$@"` へ戻されても層 2 は
 * 「存在し実行可能」だけで通過してしまう (ロックが即解放される致命的回帰を見逃す)。
 * ライブラリ本体 (scripts/global-test-lock.sh) は対象外 —
 * trap / exec fd リダイレクトを**正当に持つ唯一のファイル**だから。
 */
const GLOBAL_TEST_LOCK_GUARDED_SCRIPTS = [
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
    'scripts/with-global-test-lock.sh',
];

/**
 * JSON の scripts セクションを「script 名 => コマンド文字列」へ正規化する (純関数)。
 * composer.json は配列形式を採るため、改行連結して 1 文字列にする。
 *
 * @return array<string, string>
 */
function globalTestLockScriptsFromJson(string $json): array
{
    /** @var mixed $decoded */
    $decoded = json_decode($json, true);
    if (! is_array($decoded)) {
        return [];
    }

    /** @var mixed $scripts */
    $scripts = $decoded['scripts'] ?? null;
    if (! is_array($scripts)) {
        return [];
    }

    $normalized = [];
    /** @var mixed $command */
    foreach ($scripts as $name => $command) {
        $lines = is_array($command) ? $command : [$command];
        /** @var array<array-key, mixed> $lines */
        $normalized[(string) $name] = implode("\n", array_map(
            static fn (mixed $line): string => is_scalar($line) ? (string) $line : '',
            $lines,
        ));
    }

    return $normalized;
}

/**
 * composer.json / package.json の test 系 script が全てロック経由かを検査する (純関数)。
 *
 * @param  array<string, string>  $scripts  script 名 => コマンド文字列 (配列形式は改行連結済み)
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneViolations(array $scripts): array
{
    $violations = [];

    foreach ($scripts as $name => $command) {
        if ($name !== 'test' && ! str_starts_with($name, 'test:')) {
            continue;
        }
        if (array_key_exists($name, GLOBAL_TEST_LOCK_EXEMPT)) {
            continue;
        }
        // 部分一致で通すと `with-global-test-lock.sh true && unlocked-test` のような
        // 「ラッパ名は含むが実体は無ロック」が素通りする。
        // **最終行 (= 実際に走るコマンド) が公式入口そのものであること**を要求し、
        // 同一行のシェル演算子で別コマンドを繋ぐことを禁止する。
        $lines = array_values(array_filter(
            array_map(trim(...), preg_split('/\R/u', $command) ?: []),
            static fn (string $l): bool => $l !== '',
        ));
        $last = $lines === [] ? '' : $lines[count($lines) - 1];

        if (preg_match('/(&&|\|\||;|(?<!\|)\|(?!\|))/', $last) === 1) {
            $violations[] = "script '{$name}' がロック配下のコマンドをシェル演算子で連結している: {$last}";

            continue;
        }

        $entrypoints = array_merge(['scripts/with-global-test-lock.sh'], GLOBAL_TEST_LOCK_LANE_SCRIPTS);
        $viaEntrypoint = false;
        foreach ($entrypoints as $entrypoint) {
            if (preg_match('#^bash\s+'.preg_quote($entrypoint, '#').'(?:\s|$)#', $last) === 1) {
                $viaEntrypoint = true;
                break;
            }
        }
        if (! $viaEntrypoint) {
            $violations[] = "script '{$name}' がグローバルテストロックを経由していない: {$last}";
        }
    }

    return $violations;
}

/**
 * shell ソースから **実行行だけ** を取り出す (純関数)。
 *
 * 全ての静的検査はこの結果を単一の解析入力として使う。変更後スクリプトは
 * 「旧 worktree-local な test.lock を廃止した」「flock -n をやめた」といった説明を
 * **コメントに書く**ため、生ソースを検査すると正しい実装が偽赤になる。
 *
 * 行頭 (空白を除く) が `#` の行だけを落とす。行末コメントの除去はしない —
 * `'#'` のような引用符内の `#` を壊してコードを誤って削るリスクの方が大きい。
 */
function globalTestLockCodeLines(string $source): string
{
    // `/u` は必須: 非 UTF-8 モードの `\R` はバイト 0x85 (NEL) にも一致し、日本語コメントを
    // 文字途中で分断して「コメント断片がコードとして漏出する」(PcreUnicodeModifierGateTest)。
    $lines = preg_split('/\R/u', $source) ?: [];
    $code = array_filter(
        $lines,
        static fn (string $line): bool => preg_match('/^\s*#/', $line) !== 1,
    );

    return implode("\n", $code);
}

/**
 * `CI` 環境変数の参照禁止を検査する対象 = ロック機構の全ファイル (ライブラリ本体を含む)。
 *
 * 「CI では素通り」の分岐は、**正しさが最も要求される場所に、ローカルでは一度も
 * 実行されないコードパス**を増やす。CI が検証しているものと開発者が走らせるものを
 * 同一に保つため、ロック機構は CI を特別扱いしない (概念設計 §CI の扱い)。
 */
const GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS = [
    'scripts/global-test-lock.sh',
    'scripts/with-global-test-lock.sh',
    'scripts/run-test.sh',
    'scripts/run-browser-test.sh',
    'scripts/run-vitest.sh',
];

/**
 * ロック機構が `CI` 環境変数を **参照していない** ことを検査する (純関数)。
 *
 * 契約は「分岐していないこと」ではなく「**参照していないこと**」= deny-by-default。
 * 分岐だけを狙うと `flag=$CI` → `if [ "$flag" ]` のような 2 段構えを取りこぼすし、
 * そもそもロック機構が CI を読む正当な用途が 1 つも無いため、参照自体を禁じる方が
 * 契約として単純である (安全側の偽陽性は許容する)。
 *
 * **保証範囲**: 検出するのは shell の **通常の直接参照** (変数展開 / `-v` / `printenv` /
 * `env | grep`)。`declare -p CI` や変数名を組み立てる間接参照まで意味論的に完全検出は
 * しない (それは静的検査の射程外)。回帰防止としてはこれで十分 —
 * CI バイパスを足す人が意図的に難読化して書く前提は取らない。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockCiReferenceViolations(string $path, string $source): array
{
    $code = globalTestLockCodeLines($source);

    // 参照の書き方は複数あるので、bash で実際に CI を読める形を網羅する。
    $patterns = [
        '/\$\{?CI\b/',                     // $CI / ${CI} / ${CI:-} / ${CI+x}
        '/(?:\[\[|\btest\b|\[)[^\n]*\s-v\s+["\']?CI["\']?/', // [[ -v CI ]] / test -v CI
        '/\bprintenv\b[^\n]*\bCI\b/',      // printenv CI
        '/\benv\b[^\n|]*\|[^\n]*\bCI\b/',  // env | grep CI
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $code) === 1) {
            return ["{$path} が CI 環境変数を参照している (CI を特別扱いしない = バイパス分岐を作らない)"];
        }
    }

    return [];
}

/**
 * lane スクリプト / ラッパ本体が契約を守っているかを検査する (純関数)。
 *
 * @return list<string> 違反一覧 (空 = 合格)
 */
function globalTestLockLaneScriptViolations(string $path, string $source): array
{
    $violations = [];
    $code = globalTestLockCodeLines($source);

    if (! str_contains($code, 'global-test-lock.sh')) {
        $violations[] = "{$path} が scripts/global-test-lock.sh を source していない";
    }
    // 旧 worktree-local ロックの残存 (後方互換の並走) を禁止する。
    if (str_contains($code, 'storage/framework/testing/test.lock')) {
        $violations[] = "{$path} に旧 worktree-local な test.lock が残っている";
    }
    if (preg_match('/app-vitest-/', $code) === 1) {
        $violations[] = "{$path} に旧 workspace-hash ロック (app-vitest-*) が残っている";
    }
    if (preg_match('/\bflock\s+-n\b/', $code) === 1) {
        $violations[] = "{$path} に flock -n (非ブロッキング取得) が残っている";
    }
    // 自己バイパスの禁止。
    if (preg_match('/GLOBAL_TEST_LOCK_DIR=/', $code) === 1) {
        $violations[] = "{$path} が GLOBAL_TEST_LOCK_DIR を設定している (自己バイパス禁止)";
    }
    // exec はロック fd を閉じてロックを即解放するため、ロック配下では使わない。
    // ただし `exec 3<>...` のような **fd リダイレクト形は正当** なので除外する
    // (run-browser-test.sh の /dev/tcp guard が使う)。
    if (preg_match('/^\s*exec\s+(?!\d*[<>])/m', $code) === 1) {
        $violations[] = "{$path} が exec を使っている (fd 7 が閉じてロックが即解放される)";
    }
    // EXIT trap の所有者はライブラリ 1 箇所。lane が自前で張ると _gtl_cleanup を
    // 上書きしてロックが解放されなくなる (逆順なら lane 側が消される)。
    // 後始末は global_test_lock_on_exit へ登録する。
    if (preg_match('/^\s*trap\b[^\n]*\bEXIT\b/m', $code) === 1) {
        $violations[] = "{$path} が自前で trap ... EXIT を張っている (global_test_lock_on_exit を使うこと)";
    }
    // ラッパ / lane は必ず acquire → run の順で公開 API を **実際に呼ぶ** こと。
    // str_contains ではコメント/文字列だけでも通ってしまうため、呼び出し形を正規表現で見る。
    $acquireAt = preg_match('/^\s*global_test_lock_acquire\b/m', $code, $mA, PREG_OFFSET_CAPTURE) === 1
        ? $mA[0][1]
        : null;
    $runAt = preg_match('/^\s*global_test_lock_run\b/m', $code, $mR, PREG_OFFSET_CAPTURE) === 1
        ? $mR[0][1]
        : null;

    if ($acquireAt === null) {
        $violations[] = "{$path} が global_test_lock_acquire を呼んでいない";
    }
    if ($runAt === null) {
        $violations[] = "{$path} が global_test_lock_run を呼んでいない";
    }
    if ($acquireAt !== null && $runAt !== null && $acquireAt > $runAt) {
        $violations[] = "{$path} が global_test_lock_run を acquire より前に呼んでいる";
    }

    return $violations;
}

test('scripts/global-test-lock.sh と with-global-test-lock.sh が存在し実行可能であること', function (): void {
    foreach (['scripts/global-test-lock.sh', 'scripts/with-global-test-lock.sh'] as $rel) {
        $path = base_path($rel);
        expect(file_exists($path))->toBeTrue("{$rel} が見つからない");
        expect(is_executable($path))->toBeTrue("{$rel} に実行権が無い");
    }
});

test('scripts/verify-global-test-lock.sh が存在し実行可能であること', function (): void {
    // 層 1 (並行挙動スイート) の存在だけを固定する。**実行はしない** —
    // 本テストはグローバルロック保持中に走るため、起動すると自己競合する。
    $path = base_path('scripts/verify-global-test-lock.sh');
    expect(file_exists($path))->toBeTrue('scripts/verify-global-test-lock.sh が見つからない');
    expect(is_executable($path))->toBeTrue('scripts/verify-global-test-lock.sh に実行権が無い');
});

test('composer.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('composer.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('composer.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('package.json の test 系 script が全てグローバルテストロック経由であること', function (): void {
    $json = file_get_contents(base_path('package.json'));
    expect($json)->toBeString();
    /** @var string $json */
    $scripts = globalTestLockScriptsFromJson($json);
    expect($scripts)->not->toBe([]);
    expect(array_key_exists('test', $scripts))->toBeTrue('package.json に test script が無い');
    expect(globalTestLockLaneViolations($scripts))->toBe([]);
});

test('lane スクリプトとラッパが契約 (source / 旧ロック不在 / flock -n 不在 / exec 不在 / 自前 EXIT trap 不在 / acquire+run 使用) を守ること', function (): void {
    foreach (GLOBAL_TEST_LOCK_GUARDED_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockLaneScriptViolations($rel, $source))->toBe([]);
    }
});

test('ロック機構が CI 環境変数を参照しないこと (CI バイパス禁止)', function (): void {
    foreach (GLOBAL_TEST_LOCK_NO_CI_REFERENCE_SCRIPTS as $rel) {
        $source = file_get_contents(base_path($rel));
        expect($source)->toBeString();
        /** @var string $source */
        expect(globalTestLockCiReferenceViolations($rel, $source))->toBe([]);
    }
});

/*
 * 負のコントロール (実ファイルは書き換えない):
 * gate が「壊れた状態」を実際に検出することを fixture で確認する。空振り gate を green にしないため。
 */
test('負のコントロール: 未ラップの新レーンを検出する', function (): void {
    $violations = globalTestLockLaneViolations(['test:e2e' => 'pnpm exec playwright test']);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test:e2e');
});

test('負のコントロール: ラッパ名を含むだけの偽装 (演算子連結) を検出する', function (): void {
    $violations = globalTestLockLaneViolations([
        'test:e2e' => 'bash scripts/with-global-test-lock.sh true && pnpm exec playwright test',
    ]);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('連結');
});

test('負のコントロール: 旧 worktree-local ロックへ戻した lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    LOCK_FILE="storage/framework/testing/test.lock"
    exec 9>"$LOCK_FILE"
    flock -n 9 || exit 1
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('test.lock');
    expect(implode("\n", $violations))->toContain('flock -n');
});

test('負のコントロール: exec を復活させたラッパを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "$*"
    exec "$@"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('exec');
});

test('負のコントロール: 自前 EXIT trap を張った lane スクリプトを検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    global_test_lock_acquire "lane"
    trap cleanup_orphan_playwright EXIT
    global_test_lock_run vendor/bin/pest
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect($violations)->not->toBe([]);
    expect(implode("\n", $violations))->toContain('trap');
});

test('負のコントロール: exec の fd リダイレクト形は違反にしない', function (): void {
    $ok = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    (exec 3<>"/dev/tcp/127.0.0.1/8010") 2>/dev/null || true
    global_test_lock_acquire "lane"
    global_test_lock_run vendor/bin/pest
    SH;
    expect(globalTestLockLaneScriptViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: CI 環境変数の参照を書き方によらず検出する', function (): void {
    // 「${CI} だけ見る」実装だと素通りする形を含めて固定する (Codex impl-review Round 2 の指摘)。
    $broken = [
        'expansion' => '        if [ "${CI:-}" = "true" ]; then exec "$@"; fi',
        'bracket-v' => '        if [[ -v CI ]]; then return 0; fi',
        'test-v' => '        if test -v CI; then return 0; fi',
        'printenv' => '        if [ "$(printenv CI)" = "true" ]; then return 0; fi',
        'env-grep' => '        if env | grep -q "^CI="; then return 0; fi',
        'indirect' => '        flag=$CI',
    ];
    foreach ($broken as $label => $line) {
        $violations = globalTestLockCiReferenceViolations('fixture.sh', "#!/usr/bin/env bash\n{$line}\n");
        expect($violations)->not->toBe([], "CI 参照 ({$label}) を検出できていない");
        expect(implode("\n", $violations))->toContain('CI 環境変数を参照している');
    }

    // コメント内の説明は違反にしない (実装が方針を説明できないと困るため)。
    $ok = <<<'SH'
    #!/usr/bin/env bash
    # CI バイパス分岐は作らない (${CI} で素通りさせない / printenv CI も見ない)
    global_test_lock_acquire "lane"
    SH;
    expect(globalTestLockCiReferenceViolations('fixture.sh', $ok))->toBe([]);
});

test('負のコントロール: 自己バイパス (GLOBAL_TEST_LOCK_DIR 設定) と acquire/run の順序違反を検出する', function (): void {
    $broken = <<<'SH'
    #!/usr/bin/env bash
    . "$(dirname "$0")/global-test-lock.sh"
    GLOBAL_TEST_LOCK_DIR=/tmp/bypass
    global_test_lock_run vendor/bin/pest
    global_test_lock_acquire "lane"
    SH;
    $violations = globalTestLockLaneScriptViolations('fixture.sh', $broken);
    expect(implode("\n", $violations))->toContain('自己バイパス');
    expect(implode("\n", $violations))->toContain('acquire より前');
});
```

### scripts/run-test.sh (現行・全文)
```bash
#!/usr/bin/env bash
#
# scripts/run-test.sh — composer test の pgsql 経路。グローバルテストロック配下で
# ensure (base DB 冪等 CREATE) → artisan test --parallel を実行する。
#
# 排他は scripts/global-test-lock.sh に一本化した (旧 worktree-local な
# storage/framework/testing/ 配下のロックは廃止)。グローバルロックのスコープ
# (同一 UID・同一マシン) は worktree-local のスコープを厳密に包含するため、
# 内側のロックは 1 つも新しい事象を防がない (後方互換の並走を残さない)。
#
# 待ち方も変わった: 先行レーンがいる場合は **待つ** (旧実装は非ブロッキング取得で
# 即エラー終了していた)。待機中は 30 秒ごとに保持者の身元が stderr に出る。

set -euo pipefail
cd "$(dirname "$0")/.."

# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test"

# 以降、ロック配下の実行は必ず global_test_lock_run を通す
# (fd 7 の非継承と、孫まで含めたプロセスグループの刈り取りを一箇所に集約するため)。
global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証 (dev DB hard-deny + allowlist) は tests/bootstrap.php の
# 単一点ガード + ensure-test-db.php 内の二重防御が担う。
global_test_lock_run php scripts/ci/ensure-test-db.php

global_test_lock_run php artisan test --parallel --processes=4 "$@"
```

### scripts/run-browser-test.sh (該当部のみ抜粋: ensure-test-db.php 呼び出し周辺)
```bash
# --- グローバルテストロック (旧 worktree-local ロックを置き換え) ---
# shellcheck source=scripts/global-test-lock.sh
. "$(pwd)/scripts/global-test-lock.sh"
global_test_lock_acquire "composer test:browser"

# orphan 化した playwright run-server (pest-plugin-browser 同梱 Playwright) を掃除する。
#
# **@playwright/cli は対象外にする**: bug-hunt が使うのは @playwright/cli であり、
# 別プロセス名前空間である。pgrep のパターンは既存のまま維持し (正のマッチを弱めない)、
# cmdline に "@playwright/" を含むプロセスを明示除外することで、こちらの掃除が
# bug-hunt のブラウザを巻き込む経路 (方向 1) を構造的に塞ぐ。
cleanup_orphan_playwright() {
    local pid ppid args
    for pid in $(pgrep -f "playwright/cli.js run-server" 2>/dev/null || true); do
        args="$(ps -o args= -p "${pid}" 2>/dev/null || true)"
        case "${args}" in
            *"@playwright/"*) continue ;;   # bug-hunt の @playwright/cli は触らない
        esac
        ppid="$(ps -o ppid= -p "${pid}" 2>/dev/null | tr -d ' ' || true)"
        if [ "${ppid}" = "1" ]; then
            kill "${pid}" 2>/dev/null || true
        fi
    done
}

# 起動時の掃除は従来どおり。EXIT trap は **自前で張らず** ライブラリへ登録する。
#
# `trap cleanup_orphan_playwright EXIT` を自前で張ると、acquire 前なら acquire 側の
# `trap '_gtl_cleanup' EXIT` に上書きされ、acquire 後ならこちらが `_gtl_cleanup` を消して
# **ロックが永久に解放されなくなる**。EXIT trap の所有者はライブラリ 1 箇所に固定する。
# 登録したフックは **ロックを保持したまま** 実行される (次のレーンが入る前に掃除を終える)。
cleanup_orphan_playwright
global_test_lock_on_exit cleanup_orphan_playwright

# pest-plugin-browser が失敗時 screenshot を書く場所 (vendor 側で固定)。
SCREENSHOT_DIR="tests/Browser/Screenshots"
# レーン別に退避した証跡の置き場 (CI の失敗時アップロード対象)。
ARTIFACT_DIR="storage/browser-test-artifacts"

# レーンの証跡を退避する。
#
# **必要な理由**: pest-plugin-browser は **起動のたびに** tests/Browser/Screenshots を
# 丸ごと消す。本レーンは chromium → webkit の 2 回 pest を起動するので、退避しないと
# **先に失敗した chromium の証跡が webkit の起動で消える**。
collect_lane_artifacts() {
    local lane="$1"
    [ -d "${SCREENSHOT_DIR}" ] || return 0
    [ -n "$(ls -A "${SCREENSHOT_DIR}" 2>/dev/null)" ] || return 0
    # **退避の失敗でレーンの結果を上書きしない**。set -euo pipefail 下では mkdir / cp が
    # 落ちるとスクリプトごと終了し、テスト本体の終了コードが失われる。証跡は診断の補助で
    # あって合否ではないので、**退避先の作成と複製の両方**を受けて警告 1 行で続行する
    # (黙って握り潰さない)。
    if ! mkdir -p "${ARTIFACT_DIR}/${lane}"; then
        echo "WARNING: ${lane} レーンの証跡退避先を作成できませんでした (${ARTIFACT_DIR}/${lane})" >&2
        return 0
    fi
    if ! cp -R "${SCREENSHOT_DIR}/." "${ARTIFACT_DIR}/${lane}/"; then
        echo "WARNING: ${lane} レーンの証跡を退避できませんでした (${SCREENSHOT_DIR})" >&2
    fi
    return 0
}

global_test_lock_run php artisan config:clear --ansi

# worktree 固有の base テスト DB (<slug>_test_<worktree-hash>) を冪等に用意する。
# DB 名の安全検証は tests/bootstrap.php の単一点ガードが担う (run-test.sh と同じ)。
global_test_lock_run php scripts/ci/ensure-test-db.php

# 既定 (PROCESSES=1) では pest の parallel runner を使わない。
# 1 プロセスは直列と等価である一方、`--parallel --processes=1` で Browser lane を
# 走らせると **全テスト成功でも終了コードが 1 になる** ケースを実測した
```

### scripts/setup-worktree.sh (工程 [7/7] 抜粋)
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

### docs/template-divergence.md D30 (現行・全文)
## D30 テスト DB の作成と回収に出自の記録と孤児の分類を上積みする

| 行 | 内容 |
|---|---|
| 対象パス | `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` / `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` / `tests/Support/Ci/TestDatabaseCandidate.php` / `tests/Support/Ci/TestDatabaseClassification.php` / `tests/Support/Ci/TestDatabaseDecision.php` |
| 業務要件起因の説明 | 実装を必ず worktree で行う進め方のため、テスト DB 名を worktree の realpath の hash から作っている。worktree が検証なしで強制撤去されると hash を再現できず、引数なしの回収では二度と落とせない孤児 DB が積み上がる (2026-08-05 の監査時点で 17 個 / 221.9 MB) |
| 揃え続ける不変条件と保証機構 | 孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流すること、dev DB の拒否と allowlist の再検査が `TestDatabaseEnv` の既存実装を共有すること、テスト DB 名が worktree の realpath から決まること。`tests/Unit/Ci/DropTestDbScriptTest.php` (`--orphans --apply` の削除も通常の回収と同じ guard ループ `dropTestDbDropAll()` を通り、そこへ dev DB と allowlist 外の名前が到達しない) と `tests/Unit/Ci/TestDatabaseClassificationTest.php` (分類の優先順位と確認用の値の照合) と `tests/Unit/Ci/TestDatabaseProvenanceTest.php` (出自の記録が冪等で best-effort) と `tests/Unit/Ci/TestDatabaseEnvTest.php` (名前が worktree ごとに変わり同じ worktree では変わらない) が固定する |
| 再判定の条件 | 正典が同じ回収経路を取り込んだとき。または実装を worktree で行う進め方をやめてテスト DB 名が worktree に依存しなくなったとき |
| 決めた日 | 2026-08-05 |
| 決めた人 | 開発者 |
| 根拠 | T114 |
| 状態 | 恒久 |
| 見直し期限 | — |

| 観点 | テンプレート | 本アプリ |
|---|---|---|
| 基点 DB の作成 | 不在なら CREATE する | 同じ |
| 出自の記録 | 持たない | `COMMENT ON DATABASE` へ worktree の realpath を作成時・既存時の両方で記録する (非破壊 DDL。付与失敗は無視する) |
| 回収の入口 | 引数なしの 1 経路だけ (現 worktree の基点と worker DB) | それに加えて `--orphans` の列挙と `--apply` |
| 孤児の扱い | 経路が無い (hash を再現できないので落とせない) | SELECT だけで `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順に分類し dry-run で列挙する |
| 削除の決め方 | 名前の一致で自動 | 分類だけでは決めない。`--include-hash` で人が 1 つずつ名指しし、`--confirm` の値を lock 取得後に再計算して照合する |
| DROP DDL の実行点 | `drop-test-db.php` の 1 本 | 同じ (`--orphans` は入口を足すだけ) |
| 基点 DB のスキーマ更新 | 正典 HEAD は `migrate` まで担う (家系の裁定 AG-135) | 持たない。本登録の対象外 (下の「この登録が扱わない範囲」) |

### なぜ正当な差分か (logic-driven)

本アプリの実装は必ず worktree で行う (AGENTS.md §worktree 運用ルール)。テスト DB 名は
`TestDatabaseEnv::workrootHash()` = worktree root の realpath の sha1 先頭 8 桁から作るので、
**worktree が消えると名前を再現できない**。teardown が `doc/reference/` の NFC/NFD 問題で
常時失敗していた時期に `git worktree remove --force` での迂回が常態化し、
回収経路を通らない孤児 DB が単調増加した (2026-08-05 の監査時点で 17 個 / 221.9 MB)。

テンプレートの `drop-test-db.php` は「今いる worktree の基点と worker DB を落とす」だけなので、
この事象に手が届かない。届かせるには DB 自身に出自を持たせるしかなく、
非破壊の `COMMENT ON DATABASE` を選んだ。分類は SELECT だけで行い、DROP DDL の実行点は
1 本のまま据え置いた — **危険な操作の入口を増やさずに、判断材料だけを増やす**形である。

### 揃えている不変条件 (これは保証し続ける)

> 「孤児の回収も `drop-test-db.php` の中の同じ DROP の境界へ合流する。dev DB の拒否
> (`isDevDatabase()`) と allowlist の再検査 (`isAllowedTestDatabase()`) と DROP 文の組み立て
> (`pgsqlDropDatabaseSql()`) は既存実装をそのまま共有する」

- 分類の優先順位は `Protected` `Live` `Foreign` `Orphan` `Unlabeled` の順で、
  **`Live` が `Foreign` や `Orphan` より先**である。出自のコメントを細工しても生存 DB は落とせない
- 削除可否を分類だけで決めない。`Orphan` も `Unlabeled` も `--include-hash` で
  人が 1 つずつ名指ししない限り 1 件も落ちない (一括の指定は意図的に用意していない)
- `--apply` は確認用の値を `.claude/worktrees/.setup.lock` の取得後に再計算して照合する
  (指紋ではなく lock 下のスナップショット照合)
- 合流を固定しているのは `tests/Unit/Ci/DropTestDbScriptTest.php` の次のケースである。
  `--apply` の削除は `dropTestDbDropAll()` (通常の回収と同じ guard ループ) を必ず通り、
  その結果から終了コードが決まる (`wires the drop outcome into the --apply exit code end to end`)。
  承認済みの一覧に dev DB が紛れても実行境界へは 1 件も到達しない
  (`exits non-zero from --apply if a dev database somehow reached the approved target list`)。
  実行境界へ何が渡るかを見るケース群 (`never passes the dev database to the SQL executor` ほか 2 件) は
  この 1 本の guard ループを対象にしている

### この登録が扱わない範囲 (遅れであって逸脱ではない)

正典 HEAD の `ensure-test-db.php` は基点 DB を「存在させる」だけでなく「スキーマを最新にする」
ところまで担う (家系の裁定 AG-135)。本アプリの `ensure-test-db.php` は CREATE と出自の記録までで、
これを持たない。**これは意図的な逸脱ではなく追従の遅れ**なので、本登録では正当化しない。
追跡先は `docs/worktree-isolation-strategy.md` の「既知のギャップ」である。

### 保証しないもの

- 出自の記録は best-effort である。付与に失敗した DB は `Unlabeled` に落ち、
  `--include-hash` で人が名指ししない限り 1 件も回収されない
  (回収経路があることは「孤児が自動で片づく」ことを意味しない)
- 排他が閉じるのは**同一クローンの協調スクリプト間**の競合だけである。
  別クローンとの競合は `Foreign` の分類と `--protect-hash` と人の承認の 3 段で扱う
- 「`--apply` を LLM が実行しない」は運用契約であり、機械では強制していない
- **リポジトリ全体で DROP の実行点が 1 本であることを走査する検査は持たない**。
  上の不変条件が言っているのは「孤児の回収経路が既存の境界へ合流している」ことだけで、
  別のファイルに新しい DROP の実行点が増えたことは検出できない

### 関連

- 実装: `scripts/ci/drop-test-db.php` / `scripts/ci/ensure-test-db.php` /
  `scripts/ci/pgsql_test_conn.php` / `tests/Support/Ci/TestDatabaseEnv.php` /
  `tests/Support/Ci/TestDatabaseCandidate.php` /
  `tests/Support/Ci/TestDatabaseClassification.php` /
  `tests/Support/Ci/TestDatabaseDecision.php`
- 検査: `tests/Unit/Ci/DropTestDbScriptTest.php` /
  `tests/Unit/Ci/TestDatabaseClassificationTest.php` /
  `tests/Unit/Ci/TestDatabaseProvenanceTest.php` /
  `tests/Unit/Ci/TestDatabaseEnvTest.php`
- 背景: `docs/worktree-isolation-strategy.md` の「孤児テスト DB の回収」と「既知のギャップ」
- 設計: `devnotes/20260805-2017-todo-T114/` /
  `devnotes/20260818-1755-template-divergence-ledger-ci-db-and-launcher/`


### docs/worktree-isolation-strategy.md 既知のギャップ節 (現行・全文)
## 既知のギャップ

- **worktree 規約の自動検証テストが無い**。setup / teardown / AGENTS.md の記述がずれても
  `composer test` では落ちない (参照実装の aigenba は `WorktreeRuleInvariantTest` で
  regex 固定している)。導入するなら「ブランチ名固定」「teardown がブランチを触らない」
  「install 系 2 層規則」あたりが pin 対象になる。
- `.env` は親から**実供給**するため、親の `.env` を後から変えても worktree には反映されない
  (worktree ごとに直す)。供給時に mode を 0600 に確定するのは**新規 worktree だけ**で、
  既存 worktree の秘密ファイルと親側の権限はそのまま残る。
- **正典の `scripts/ci/ensure-test-db.php` はスキーマ更新まで担う形になったが追従していない**。
  正典 (家系の裁定 AG-135) は基点 DB を「存在させる」だけでなく `migrate` まで走らせ、
  未適用が残っていないことと更新がその DB に当たったことまで確かめる。本アプリの
  `ensure-test-db.php` は CREATE と出自の記録までで、基点 DB のスキーマが古いまま残りうる
  (DB 系の trait を使わない Architecture のレーンは基点 DB をそのまま読むため、
  新しい worktree でだけ落ちる形の失敗になりうる)。これは意図的な逸脱ではないので
  `docs/template-divergence.md` では正当化していない (aicue:D30 の「この登録が扱わない範囲」)。

