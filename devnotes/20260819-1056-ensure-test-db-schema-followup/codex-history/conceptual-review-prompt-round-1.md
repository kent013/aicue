# アプリの使命 (North Star)

## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

# 禁止事項

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

# 概念設計: テスト DB 準備スクリプトのスキーマ更新への追従 (家系の裁定 AG-135)

## 背景・課題

### 判断の出所

- **家系の裁定 AG-135**: テンプレート正典 (laravel-claude-template) の
  `scripts/ci/ensure-test-db.php` は、基点テスト DB (`<slug>_test_<worktree-hash>`) を
  「存在させる」だけでなく「スキーマを最新にする」ところまで担う形になった。
  機能台帳 (lctl) の `php-test-pgsql-lane` セルに、正典の到達として記録されている
  (観測点 laravel-claude-template@b36000f / 実読は laravel-claude-template@ccf465a7)。
- **aicue は追従していない**。本アプリの `ensure-test-db.php` は CREATE と出自の記録までで、
  スキーマ更新を持たない。台帳の aicue セルは `update_pending` で、
  「正典が後から入れたスキーマ更新まで担う形 (AG-135) への追従の遅れが別にある」と記録済み。
- **追跡先は既に登録済み**: `docs/worktree-isolation-strategy.md` の「既知のギャップ」に
  2026-08-18 付で登録した (「正典の `scripts/ci/ensure-test-db.php` はスキーマ更新まで担う形に
  なったが追従していない」)。
- **逸脱登録簿の扱い**: aicue 側の `scripts/ci` 群は aicue:T114 の上積み
  (孤児 DB の分類と回収・出自の冪等記録・接続確認の拡張) で正典から分岐しており、
  `docs/template-divergence.md` に aicue:D30 として登録済み。D30 は本件を
  「この登録が扱わない範囲 (遅れであって逸脱ではない)」として明示的に外している。
- **オーナーの決定**: 2026-08-19、「追従する」と決めた。本設計はその決定を実装可能な形へ落とす。

### 何が壊れるのか (追従しないと起きること)

基点 DB のスキーマが古いまま残る。並列実行では DB を使う trait を持つテストのときだけ
worker DB へ切り替わるので、**DB の trait を使わない Architecture のレーンは基点 DB を
そのまま読む**。したがって次の形の失敗になる。

- 新しい worktree でだけ落ちる (基点 DB を作った時点の migration しか当たっていない)
- 実行順で結果が変わる (先に走ったテストが `RefreshDatabase` で基点 DB を作り直していれば通る)
- 正典側では、この形の環境依存の偽 green が実際に起きていた
  (台帳の記録: 追従によって job-execution-deduplication 側の偽 green が塞がれた)

本アプリはテスト DB 名を worktree の realpath の hash から作るため、**worktree を作るたびに
新しい基点 DB ができる**。実装を必ず worktree で行う進め方 (AGENTS.md §worktree 運用ルール) と
組み合わさると、この欠陥を踏む頻度は家系の中でも高い側になる。

## 改善アイデア

正典の形 — 「基点 DB を存在させ、スキーマを最新にするところまで `ensure-test-db.php` が担う」 —
を、aicue の分岐版 (D30 の上積み) の上へ取り込む。上積みは 1 つも削らない。

1. `scripts/ci/pgsql_test_conn.php` に、正典だけが持つ 3 つの関数を**同名・同挙動で**足す。
   - `pgsqlTestDatabasePdo()` — 指定した DB への PDO (更新後の到達確認用)
   - `pgsqlTestConfigCachePath()` — 設定キャッシュの既定パス (検査する場所と読む場所を 1 つの値に固定)
   - `pgsqlTestArtisanEnv()` — スキーマ更新の子プロセスへ渡す環境変数を**継承せず**組み立てる
2. `scripts/ci/ensure-test-db.php` に、正典の本体 (設定キャッシュ残存の検査 → スキーマ更新 →
   未適用の検査 → 到達確認) と、環境を継承しない artisan の起動 (`runTestDatabaseArtisan()`) を足す。
3. 実行順は **CREATE → 出自の記録 → スキーマ更新** とする。出自の記録を先に置くのは、
   スキーマ更新が失敗したときに「ラベルの無い現役 DB」を残さないためである
   (D30 が揃え続けると宣言した不変条件の 1 つ)。
4. 順序そのものを純関数 `testDatabaseEnsurePlan()` の返り値へ持たせる
   (既存の enum に `UpdateSchema` を足す)。実 DB を作らずに順序を検査できる形を保つ。
5. 正典の Architecture テスト `BaseTestDatabaseSchemaTest` を移植する
   (「基点 DB が実際に最新である」ことをその場所で観測する)。
6. `tests/Unit/Ci/` に負例を含む単体テストを足す (実 DB を作らない。既存の 4 本と同じ形)。
7. 文書を 2 つ直す — `docs/template-divergence.md` の D30 (比較表と「扱わない範囲」節) と
   `docs/worktree-isolation-strategy.md` の「既知のギャップ」項。

### 名前と実装を正典へ揃える理由

関数名・引数・docblock の骨格は正典に揃える。家系のキュレーターは md5 比較と実読で
リポジトリ間の差を見ており、**同じ意味の実装に別の名前を付けると、その差が毎回
「新しい分岐」として観測される**。上積み (COMMENT 系・enum・plan・stamp) だけが差分として
残る形にしておけば、次の巡回で「上積み以外は正典と一致」と 1 行で言える。

## 期待効果

- **使命への貢献**: 撮影ナビと動画合成の土台はテストの信頼性に乗っている。基点 DB が古いままだと
  「新しい worktree でだけ落ちる」「実行順で結果が変わる」失敗が出て、赤の原因究明に時間が溶ける。
  ここを塞ぐことは、AI-CUE の機能開発の速度を直接支える。
- **具体的な改善見込み**:
  - 新規 worktree での初回テストが、基点 DB のスキーマ不整合で落ちなくなる
  - Architecture のレーンが読む基点 DB の状態が、実行順に依らず決まる
  - 家系台帳の aicue セルから「追従の遅れ」が消え、残る差分が D30 の上積みだけになる

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `scripts/ci/pgsql_test_conn.php` | 正典の 3 関数を追加 (`pgsqlTestDatabasePdo` / `pgsqlTestConfigCachePath` / `pgsqlTestArtisanEnv`)。既存の enum に `UpdateSchema` を追加し、`testDatabaseEnsurePlan()` の返り値を 3 手順へ拡張。artisan の引数列を返す純関数と、到達確認の判定を行う純関数を追加 |
| `scripts/ci/ensure-test-db.php` | 設定キャッシュ残存の検査 (fail-closed)、環境を継承しない artisan 起動 (`runTestDatabaseArtisan`)、スキーマ更新・未適用の検査・到達確認を追加 |
| `tests/Architecture/BaseTestDatabaseSchemaTest.php` | 新規 (正典から移植)。基点 DB に migrations 表があること / `database/migrations` の全ファイルが適用済みであること / 本テスト自身に `RefreshDatabase` が付いていないこと |
| `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 新規。環境の組み立て・引数列・到達確認の判定を負例込みで固定する (実 DB を作らない) |
| `tests/Unit/Ci/TestDatabaseProvenanceTest.php` | plan の期待値を 3 手順へ更新 (契約を意図的に変えたための更新。既存の意図「両分岐とも出自を記録する」は残す) |
| `docs/template-divergence.md` (D30) | 比較表の「基点 DB のスキーマ更新」行を更新。「扱わない範囲」節を「追従済み」へ書き換え。揃え続ける不変条件に「出自の記録はスキーマ更新より先」を追加 |
| `docs/worktree-isolation-strategy.md` | 「既知のギャップ」の該当項を削除し、本文側 (テスト DB の節) へ「基点 DB のスキーマ更新まで担う」を書く |
| `scripts/setup-worktree.sh` | 呼び出しは変えない。工程 [7/7] の見出しと警告文言だけ「スキーマ更新まで含む」に直す |

### migrate を走らせる対象と契機 (正典の形を正とする)

- **対象は基点 DB だけ**。worker DB (`..._test_<token>`) には触らない
  (Laravel の並列実行と `RefreshDatabase` が担う層であり、ここで二重に持たない)。
- **契機は `ensure-test-db.php` の実行のたび、無条件**。「未適用があるか」を見て分岐しない —
  `migrate` 自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
  同じ判定を二重に持つことになる (正典の判断をそのまま採る)。
- 呼び出し元は現状のまま 4 箇所: `scripts/run-test.sh` / `scripts/run-browser-test.sh` /
  `scripts/setup-worktree.sh` / CI (前 2 者を経由)。**新しい呼び出し元は作らない**。
- 使うのは `migrate` だけである。`migrate:fresh` / `migrate:refresh` / `migrate:rollback` /
  `db:wipe` は使わない (AGENTS.md 禁止事項 3)。この点は単体テストの負例で固定する。

### 失敗時の挙動 (fail-closed)

`ensure-test-db.php` は次のいずれでも標準エラーへ理由を書いて終了コード 1 で止まる。

1. 設定キャッシュ (`bootstrap/cache/config.php`) が残っている
   (残っていると子プロセスへ渡す環境変数が無視され、接続先が別 DB になりうる)
2. 保守用 DB への接続に失敗した (既存の挙動)
3. スキーマ更新が失敗した
4. 更新後も未適用の migration が残っている
5. 更新後の確認接続に失敗した、または更新がその DB に当たっていない

**出自の記録だけは今までどおり best-effort** で、失敗しても続行する (D30 の判断。
権限差で偽赤を増やさないため)。スキーマ更新は fail-closed、出自の記録は best-effort —
この非対称は意図であり、両方の理由をコードの docblock に残す。

**`setup-worktree.sh` の工程 [7/7] は今までどおり警告扱いで続行する**。pgsql が非接続の環境でも
worktree 作成そのものを壊さないためで、テスト実行時に `run-test.sh` が同じ ensure を
やり直すので fail-closed の実効性は失われない。ここを fail-closed へ変えると、
DB を使わない作業のための worktree すら作れなくなる。

### dev DB への到達を防ぐ保護

スキーマ更新は「子プロセスを起動する」という新しい実行点を持ち込む。この devcontainer の
shell には開発 DB 名が export されているため、素直に環境を継承すると更新が開発 DB へ当たる
(AGENTS.md 禁止事項 3)。正典の 4 重の保護をそのまま採る。

1. **名前の出所** — 基点名は `TestDatabaseEnv::pgsqlBaseDatabase()` の 1 か所だけが決める
2. **名前の検査** — 許可一覧との一致と開発 DB 名の拒否を、更新の直前に再確認する
3. **子プロセスの環境** — 継承せず許可リストで組み立て、接続先 DB 名を算出した基点名で固定する
4. **到達確認** — 更新後に基点 DB へ直接つなぎ、更新がその DB に当たったことを確かめる

D30 が揃え続けると宣言した不変条件は 1 つも壊れない。

- **DROP の実行点 1 本** — 追加するのは `migrate` だけで、DROP を実行しない
  (`migrate:fresh` 等を使わないことを単体テストの負例で固定する)
- **開発 DB の拒否** — 既存の `isDevDatabase()` / `isAllowedTestDatabase()` をそのまま共有し、
  更新の直前に再確認する経路を 1 つ増やす (弱めない)
- **worktree 単位の DB 名** — 名前の決め方は変えない

## 制約・前提

- 本アプリのテストレーンは pgsql 一本 (`phpunit.xml` の `<server force>` と
  `tests/bootstrap.php` の注入)。Architecture のレーンは `tests/Pest.php` で
  `RefreshDatabase` を付けていない (ファイル走査中心) — 正典と同じ前提が成り立つので、
  正典の Architecture テストがそのまま移植できる。
- テストは `--parallel --processes=4` で走る。基点 DB の更新は
  グローバルテストロックの内側 (`run-test.sh`) で行われるので、同一マシン上の
  他レーンと競合しない。
- 設計・実装とも dev DB へ触らない。単体テストは実 DB を作らず、
  既存の `tests/Unit/Ci/` と同じ形 (実行境界へ callable を注入する / 純関数を直接呼ぶ) を採る。
- 実行時間は増える (artisan の起動が 2 回)。正典の実測は「何もしないとき約 0.53 秒 /
  空の DB から全適用で約 0.66 秒」。本アプリでも実測し、docblock に記録する。

## スコープ外

- **孤児テスト DB の回収経路 (D30 の上積み) の作り直し**。今回は上積みの上へ正典を重ねるだけで、
  分類・承認・DROP の形には触らない。
- **`drop-test-db.php` の変更**。DROP の実行点は 1 本のまま据え置く。
- **worker DB のスキーマ管理**。Laravel の並列実行と `RefreshDatabase` の層であり、ここで持たない。
- **並列テストのデータベース側の資源上限 (1 トランザクションあたりのロック数など) の固定**。
  家系の別リポジトリ (motivation) が持ち込んだ層で、本件とは別の主題である。
- **スキーマ更新に実行時間の見張りを持たせること**。子プロセスが DB のロック待ちで止まれば
  本スクリプトも止まる (既存のテスト入口も同じで、待ちの仕掛けは持ち込まない)。
  接続の待ちだけは既存の PDO の 10 秒が効く。
- **家系台帳への書き戻し**。本設計は読み取りのみを行った。実装後の報告は
  実装側の作業として、その場の規律に従って行う。
- **TODO への登録**。本スキルの責務ではない。

---

## 参考: 正典と本アプリの現行コード (実読済み)

正典 scripts/ci/ensure-test-db.php の冒頭 docblock (抜粋) と本体の流れ:

```
/*
 * pgsql テストの基点 DB (`<slug>_test_<worktree-hash>`) を「存在させ、スキーマを最新にする」
 * ところまでを担う (lctl 裁定 AG-135)。Laravel の ParallelTesting は基点名に `_test_<token>` を
 * 付した worker DB を作るが、DB 系 trait を使わない Architecture lane は **基点 DB を
 * そのまま読む**ため、基点 DB のスキーマが古いと「新規 worktree でだけ落ちる」
 * 「実行順で結果が変わる」という再現しにくい失敗になる。
 * run-test.sh / CI / setup-worktree.sh が test 前に本スクリプトを呼ぶ。
 *
 * dev-DB 保護 (4 重。AGENTS.md 禁止事項 3):
 *   1. 名前の出所 — 基点名は TestDatabaseEnv::pgsqlBaseDatabase() の 1 か所だけが決める
 *   2. 名前の検査 — allowlist 一致 + dev 名 deny を更新の直前に再確認する
 *   3. 子プロセスの環境 — 継承せず許可リストで組み立て、DB_DATABASE を算出した基点名で固定する
 *   4. 到達確認 — 更新後に基点 DB へ直接つなぎ、更新がその DB に当たったことを確かめる
 *
 * 接続失敗は CI / local いずれも明示エラー + exit 1 (偽グリーンを許さない)。
 *
 * 保証しないこと: スキーマ更新に実行時間の見張りを持たない (子プロセスが DB のロック待ちで
 * 止まれば本スクリプトも止まる。既存のテスト入口も同じで、待ちの仕掛けは持ち込まない)。
 * 接続の待ちだけは PDO の ATTR_TIMEOUT 10 秒が効く。
 */
```

本体 (要点のみ。helper `runTestDatabaseArtisan()` は proc_open の配列形で shell を通さず、
出力を取るときは pipe ではなく一時ファイル 2 本 (stdout/stderr 別) へ落とし finally で消す):

```php
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);
Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

// 設定キャッシュが残っていると子プロセスへ渡す環境変数が無視されて dev DB を見に行きうる。
$configCache = pgsqlTestConfigCachePath($projectRoot);
if (is_file($configCache)) { /* stderr へ理由 + 'php artisan config:clear' の案内 */ exit(1); }

try { $pdo = pgsqlTestMaintenancePdo($projectRoot); } catch (Throwable $e) { /* stderr */ exit(1); }

// 不在なら CREATE (存在すればそのまま)
...

// --- スキーマ更新 (毎回無条件) ---
// 更新の処理自体が「未適用のものだけ当てる」条件分岐なので、有無を見て分岐すると
// 同じ判定を二重に持つことになる。実測コストは何もしないとき約 0.53 秒 /
// 空の DB から全適用で約 0.66 秒。
$env = pgsqlTestArtisanEnv($projectRoot, $base);
$migrate = runTestDatabaseArtisan($projectRoot, ['migrate', '--force', '--no-interaction'], $env, false);
if ($migrate['status'] !== 0) { /* stderr */ exit(1); }

// 未適用が残っていないことを確かめる。値を渡したときだけその値が終了コードになる
// (値を渡さない形は未適用があっても 0 を返す。実測)。
$pending = runTestDatabaseArtisan($projectRoot, ['migrate:status', '--pending=1'], $env, true);
if ($pending['status'] !== 0) { /* stderr + $pending['output'] */ exit(1); }

// 別経路の到達確認: 子プロセスの環境変数の解決が壊れていても気付けるよう、
// 基点 DB へ直接つないで更新の痕跡を確かめる (artisan の判断に相乗りしない)。
$check = pgsqlTestDatabasePdo($projectRoot, $base);
$table = $check->query("SELECT to_regclass('public.migrations')")->fetchColumn();
$applied = ($table === null || $table === false) ? 0 : (int) $check->query('SELECT count(*) FROM migrations')->fetchColumn();
if ($applied < 1) { /* stderr */ exit(1); }
exit(0);
```

正典 scripts/ci/pgsql_test_conn.php の追加 3 関数:

```php
function pgsqlTestDatabasePdo(string $projectRoot, string $database): PDO   // 到達確認用
function pgsqlTestConfigCachePath(string $projectRoot): string              // bootstrap/cache/config.php
function pgsqlTestArtisanEnv(string $projectRoot, string $database): array
// PATH / HOME / TMPDIR だけ引き継ぎ、以下を固定値で上書き:
//   APP_ENV=testing / APP_CONFIG_CACHE=pgsqlTestConfigCachePath() /
//   DB_CONNECTION=pgsql / DB_URL='' / DB_HOST,DB_PORT,DB_USERNAME,DB_PASSWORD=接続値 /
//   DB_DATABASE=$database / CACHE_STORE=array
```

正典 tests/Architecture/BaseTestDatabaseSchemaTest.php は 3 ケース:
B-0 本テストに RefreshDatabase が適用されていない (検査の前提) /
B-1 基点テスト DB に migrations 表が存在する /
B-2 database/migrations の全ファイルが基点テスト DB に適用済みである
(比較の向きは包含 (ファイル -> 表)。vendor パッケージの migration が表に増えうるため)。

---

aicue の現行 scripts/ci/ensure-test-db.php (全文):

```php
$projectRoot = dirname(__DIR__, 2);
$base = TestDatabaseEnv::pgsqlBaseDatabase($projectRoot);

Assert::false(TestDatabaseEnv::isDevDatabase($base), "refusing to ensure dev DB: {$base}");
Assert::true(TestDatabaseEnv::isAllowedTestDatabase($base), "computed base name not allowlisted: {$base}");

try { $pdo = pgsqlTestMaintenancePdo($projectRoot); } catch (Throwable $e) { /* stderr */ exit(1); }

$stmt = $pdo->prepare('SELECT 1 FROM pg_database WHERE datname = :name');
$stmt->execute(['name' => $base]);
$exists = $stmt->fetchColumn() !== false;

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

fwrite(STDERR, $exists ? "...already exists..." : "...created...");
exit(0);
```

aicue の現行 pgsql_test_conn.php が持つ上積み (正典に無い):
`pgsqlCommentDatabaseSql()` / `enum TestDatabaseEnsureAction {Create, StampProvenance}` /
`testDatabaseEnsurePlan(bool $exists): list<TestDatabaseEnsureAction>` (純関数) /
`pgsqlStampProvenance(callable $exec, string $sql): bool` (best-effort)。

aicue の tests/Pest.php は Feature / Unit にだけ RefreshDatabase を付け、
Architecture は TestCase のみ (+ 外向き HTTP の既定拒否とキャッシュ guard) で走る。
テストは `php artisan test --parallel --processes=4`。
`scripts/run-test.sh` はグローバルテストロックを取り、`artisan config:clear` →
`ensure-test-db.php` → 並列テストの順で走る。
`scripts/setup-worktree.sh` の工程 [7/7] は ensure を呼ぶが失敗しても警告で続行する。
