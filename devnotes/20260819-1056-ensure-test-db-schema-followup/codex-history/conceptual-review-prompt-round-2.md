# Round 2: 指摘への対応と再レビュー依頼

Round 1 の指摘に対する対応は次のとおりです。概念設計を修正しました。再レビューをお願いします。

## [Critical] 到達確認が件数 1 件以上では最新性を証明できない → **対応した**

判定を包含関係へ強めた。ensure の成功条件を次にする。

1. 基点 DB に `migrations` 表が存在する
2. `database/migrations/*.php` の全ファイル名 (拡張子なし) が、その表の `migration` 列に含まれる
   (比較の向きは ファイル → 表。vendor のパッケージ由来の migration が表に増えうるので
   集合の一致は求めない)
3. `database/migrations` に 1 件もファイルが無い場合は異常として止める
   (空集合は包含を自明に満たすため)

判定は純関数へ切り出し、負例つきの単体テストで固定する。移植する Architecture テスト B-2 と
**同じ基準**にすることで、スクリプトと検査で判定がずれない形にした。
これは正典 (件数 1 件以上) より 1 段強いので、家系への還流候補として設計文書に明記した。

## [Warning] 同一基点 DB への migrate の競合 → **対応した (保証範囲の明記 + 検査の追加)**

- 基点 DB 名は worktree の realpath の hash から決まるので、別の worktree の呼び出し元は
  同じ DB を触らない。同一 worktree のテストレーン 2 本はどちらもグローバルテストロックの
  内側で ensure を呼ぶ。`setup-worktree.sh` が触るのはこれから作る worktree の DB である。
- 「レーンの ensure 呼び出しがロックの内側にある」ことは今は誰も検査していないので、
  既存の `tests/Architecture/GlobalTestLockInventoryTest.php` へケースと負のコントロールを足す
  (既存ケースは 1 つも消さない)。
- 残るのは「人が同一 worktree で手で直接叩き、同時にレーンを走らせる」場合だけで、
  ここに排他は持ち込まない (今必要でない防御は作らない)。保証しない範囲として明記した。
- 併せて「`vendor/bin/pest` を直接叩く経路は本準備の保証対象外」も明記した。

## [Warning] 期待効果の表現 → **対応した**

「ensure が正常終了した時点で基点 DB のスキーマ状態が決まる」を先頭に置き、
「初回テストが落ちなくなる」はその帰結として並べた。

## [Warning] setup-worktree.sh の警告扱い → **対応した (警告扱いそのものは据え置く)**

工程 [7/7] を止める形にすると DB を使わない作業のための worktree すら作れなくなるため、
警告で続行する挙動は変えない。文言を直し、失敗理由と
「テストは `composer test` (= `scripts/run-test.sh`) から実行すれば同じ準備がやり直される」
ことを書く。

## [Warning] 配列 shape の固定 → **対応した (PHPDoc。DTO は作らない)**

`array<string, string>` / `array{status: int, output: string}` / `list<string>` を必ず書く。
DTO を作らない理由: これらは `scripts/` 配下の素の PHP スクリプトで、`vendor/autoload.php` を
直接読んで走る。ここへ DTO を持ち込むと「テスト DB を用意するだけのスクリプト」が
アプリの型体系へ依存し始める。

**事実の訂正を 1 つ入れた**: 本アプリの `phpstan.neon` の解析対象は
`app` / `config` / `database` / `routes` であり、`scripts` と `tests` は含まれない。
したがって設計文書では「PHPStan level 10 が検査する」と主張していない。
解析対象を広げるのは本件のスコープ外とした。

## [Suggestion] 継承しない理由を docblock に残す → **対応した**

正典の docblock をそのまま持ち込む方針を実装方針へ明記した。

## [Suggestion] 使命への貢献の位置づけ / スコープの切り方 / enum で順序を閉じ込める方針

同意する。設計の変更は不要と判断した。

---

## 修正後の概念設計 (全文)

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
7. `tests/Architecture/GlobalTestLockInventoryTest.php` に、レーンのスクリプトが
   `ensure-test-db.php` をグローバルテストロックの内側で呼ぶことを固定するケースを足す。
8. 文書を 2 つ直す — `docs/template-divergence.md` の D30 (比較表と「扱わない範囲」節) と
   `docs/worktree-isolation-strategy.md` の「既知のギャップ」項。

### 正典より 1 段強める点 (還流候補)

正典の到達確認は「`migrations` 表があり、行が 1 件以上ある」で止まっている。これでは
**古い基点 DB に古い `migrations` 表が残っている**状態を通してしまい、子プロセスの接続先の
解決が壊れて別の DB が更新されたときに気付けない — 到達確認という名前の役割を果たさない。

本アプリでは判定を包含関係へ強める。すなわち「`migrations` 表が存在し、
`database/migrations` の全ファイル名がその表に含まれる」を成功条件にする。
比較の向きはファイル → 表で、集合の一致は求めない (vendor のパッケージ由来の migration が
表に増えうるため)。これは移植する Architecture テスト B-2 と**同じ基準**であり、
スクリプトと検査で判定がずれない形になる。`database/migrations` が 1 件も無いときは
異常として止める (空集合は包含を自明に満たしてしまうため)。

この 1 点だけは正典より強い。家系への還流候補として扱う。

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
  - `ensure-test-db.php` が正常終了した時点で、基点 DB のスキーマ状態が決まる
    (`database/migrations` の全ファイルが適用済みであることが確認されている)
  - Architecture のレーンが読む基点 DB の中身が、実行順に依らなくなる
  - 新規 worktree での初回テストが、基点 DB のスキーマ不整合で落ちなくなる
  - 家系台帳の aicue セルから「追従の遅れ」が消え、残る差分が D30 の上積みだけになる

## 実装方針（概要）

| 対象 | 変更 |
|---|---|
| `scripts/ci/pgsql_test_conn.php` | 正典の 3 関数を追加 (`pgsqlTestDatabasePdo` / `pgsqlTestConfigCachePath` / `pgsqlTestArtisanEnv`)。既存の enum に `UpdateSchema` を追加し、`testDatabaseEnsurePlan()` の返り値を 3 手順へ拡張。artisan の引数列を返す純関数と、到達確認の判定を行う純関数を追加 |
| `scripts/ci/ensure-test-db.php` | 設定キャッシュ残存の検査 (fail-closed)、環境を継承しない artisan 起動 (`runTestDatabaseArtisan`)、スキーマ更新・未適用の検査・到達確認を追加 |
| `tests/Architecture/BaseTestDatabaseSchemaTest.php` | 新規 (正典から移植)。基点 DB に migrations 表があること / `database/migrations` の全ファイルが適用済みであること / 本テスト自身に `RefreshDatabase` が付いていないこと |
| `tests/Unit/Ci/TestDatabaseSchemaUpdateTest.php` | 新規。環境の組み立て・引数列・到達確認の判定を負例込みで固定する (実 DB を作らない) |
| `tests/Unit/Ci/TestDatabaseProvenanceTest.php` | plan の期待値を 3 手順へ更新 (契約を意図的に変えたための更新。既存の意図「両分岐とも出自を記録する」は残す) |
| `tests/Architecture/GlobalTestLockInventoryTest.php` | ケース追加。レーンのスクリプトが `ensure-test-db.php` を `global_test_lock_run` 経由で呼ぶこと + 負のコントロール (既存ケースは 1 つも消さない) |
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
4. 更新後も未適用の migration が残っている (`migrate:status --pending=1`)
5. 更新後の確認接続に失敗した
6. 基点 DB に `migrations` 表が無い、または `database/migrations` のファイルのうち
   その表に無いものが 1 件でもある (到達確認)
7. `database/migrations` に 1 件もファイルが無い (包含の判定が自明に成立してしまうため)

**出自の記録だけは今までどおり best-effort** で、失敗しても続行する (D30 の判断。
権限差で偽赤を増やさないため)。スキーマ更新は fail-closed、出自の記録は best-effort —
この非対称は意図であり、両方の理由をコードの docblock に残す。

**`setup-worktree.sh` の工程 [7/7] は今までどおり警告扱いで続行する**。pgsql が非接続の環境でも
worktree 作成そのものを壊さないためで、テスト実行時に `run-test.sh` が同じ ensure を
やり直すので fail-closed の実効性は失われない。ここを fail-closed へ変えると、
DB を使わない作業のための worktree すら作れなくなる。
ただし警告の文言は直す — 失敗理由をそのまま見せ、「テストは `composer test`
(= `scripts/run-test.sh`) から実行すれば同じ準備がやり直される」ことを書く。

### 保証する範囲と保証しない範囲

- **保証する**: 4 つの呼び出し元のうちテストの 2 レーン (`run-test.sh` /
  `run-browser-test.sh`) は、どちらもグローバルテストロックを取ってから ensure を呼ぶ。
  基点 DB 名は worktree の realpath から決まるので、**別の worktree の呼び出し元が
  同じ DB を触ることはない**。`setup-worktree.sh` が触るのはこれから作る worktree の DB である。
  「レーンの ensure 呼び出しがロックの内側にある」ことは
  `tests/Architecture/GlobalTestLockInventoryTest.php` へケースを足して固定する。
- **保証しない**: 人が同一 worktree で `php scripts/ci/ensure-test-db.php` を手で直接叩き、
  同時にテストレーンを走らせた場合。ここに排他は持ち込まない (今必要でない防御は作らない)。
- **保証しない**: `vendor/bin/pest` や `php artisan test` を直接叩く経路。準備を通らないので
  基点 DB が最新である保証は無い (移植する Architecture テストがその場で赤くなる)。

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
- **型の固定は PHPDoc で行う**。追加・変更する関数には `array<string, string>` /
  `array{status: int, output: string}` / `list<string>` の shape を必ず書く。
  ただし本アプリの `phpstan.neon` の解析対象は `app` / `config` / `database` / `routes` で、
  `scripts` と `tests` は含まれない。したがって「PHPStan level 10 が検査する」とは主張しない
  (解析対象を広げるのは本件のスコープ外)。shape を書くのは読み手のためと、
  正典との差分を上積みだけに保つためである。
- 追加する関数は正典の docblock をそのまま持ち込む。特に「子プロセスの環境を継承しない理由」
  (この devcontainer の shell に開発 DB 名が export されている) は落とさない。

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
