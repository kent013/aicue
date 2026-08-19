# 対応マトリクス: conceptual-review Round 1

## [Critical] 到達確認が「migrations 表があり件数 1 件以上」では基点 DB の最新性を証明できない

- 判断: **対応する**
- 根拠: 指摘のとおりである。件数だけの確認は「古い基点 DB に古い migrations 表が残っている」
  状態を通してしまい、第 4 の保護 (到達確認) が名前どおりの役割を果たさない。
  正典は件数 1 件以上で止めているが、同じ判定を Architecture の検査 (B-2) が
  包含関係で行っている。**スクリプトと検査で判定の基準が違うのは弱い方が実効の上限になる**ので、
  aicue では ensure 側も包含関係へ強める。
- 対応内容: 概念設計の「失敗時の挙動」と「dev DB への到達を防ぐ保護」を次の形に直した。
  - 到達確認は「`migrations` 表が存在し、`database/migrations` の全ファイル名が
    その表に含まれること」へ強める (比較の向きは ファイル → 表 の包含。
    vendor パッケージ由来の migration が表に増えうるため集合の一致は求めない)
  - `database/migrations` が空のときは異常として止める (空集合が包含を自明に満たすため)
  - 判定は純関数へ切り出し、負例つきの単体テストで固定する
  - 正典より 1 段強い形になるので、家系への還流候補として設計文書に明記する

## [Warning] 同一の基点 DB に対する `migrate` の競合が 4 つの呼び出し元すべてで排除されていない

- 判断: **対応する** (保証範囲の明記 + 検査の追加。ensure 自身にロックは持たせない)
- 根拠: 基点 DB 名は worktree の realpath の hash から決まるので、**別の worktree の
  呼び出し元は同じ DB を触らない**。同一 worktree の 2 つのテストレーン
  (`run-test.sh` / `run-browser-test.sh`) はどちらもグローバルテストロックの内側で
  ensure を呼ぶ。`setup-worktree.sh` が触るのはこれから作る worktree の DB である。
  残るのは「人が手で `php scripts/ci/ensure-test-db.php` を直接叩く」場合だけで、
  ここに排他を足すのは今必要な防御ではない (思考原則 2)。
  ただし「レーンの ensure 呼び出しがロックの内側にある」ことは今は誰も検査していないので、
  ここは固定する価値がある。
- 対応内容:
  - 概念設計に「保証する範囲と保証しない範囲」を節として明記した
  - `tests/Architecture/GlobalTestLockInventoryTest.php` へ
    「レーンのスクリプトが `ensure-test-db.php` を呼ぶときは `global_test_lock_run` 経由である」
    ケースと、その負のコントロールを追加する (既存ケースは 1 つも消さない)

## [Warning] 期待効果「初回テストが落ちなくなる」は到達確認を強めて初めて言える

- 判断: **対応する**
- 根拠: Critical の対応で成立する。表現も保証範囲に合わせて直すべきである。
- 対応内容: 期待効果を「ensure が正常終了した時点で基点 DB のスキーマ状態が決まる」
  という言い方へ直した。

## [Warning] `setup-worktree.sh` の警告扱いのままだと、直後に Architecture のレーンだけ直接叩くと古い基点 DB を読む

- 判断: **対応する** (警告扱いそのものは据え置く)
- 根拠: 工程 [7/7] を止める形にすると、DB を使わない作業のための worktree すら作れなくなる。
  一方で「警告のあとに何をすれば直るのか」が読み手に伝わっていないのは指摘のとおりである。
- 対応内容: 警告の文言に失敗理由と「テストは `composer test` (= `scripts/run-test.sh`) から
  実行すれば同じ準備がやり直される」ことを書く。
  併せて「`vendor/bin/pest` を直接叩く経路は本準備の保証対象外」を文書へ明記する。

## [Warning] `pgsqlTestArtisanEnv()` / `runTestDatabaseArtisan()` の配列 shape が曖昧

- 判断: **対応する** (PHPDoc で shape を固定。DTO は作らない)
- 根拠: 正典が既に `array<string, string>` と `array{status: int, output: string}` の
  PHPDoc を持っており、揃えれば済む。これらは `scripts/` 配下の素の PHP スクリプトで、
  Laravel のクラス階層に属さない (`vendor/autoload.php` を直接読む) ため、
  ここへ DTO を持ち込むと「テスト DB を用意するだけのスクリプト」が
  アプリの型体系へ依存し始める。今必要ではない。
- 対応内容: 追加・変更するすべての関数に PHPDoc の shape を付ける方針を明記した。
  併せて**事実として**、本アプリの `phpstan.neon` の解析対象は `app` / `config` /
  `database` / `routes` であり `scripts` と `tests` は含まれないことを制約へ書いた
  (level 10 を通ると主張しない。解析対象を広げるのは本件のスコープ外)。

## [Suggestion] 使命への貢献は間接的である / スコープの切り方は妥当 / enum で順序を閉じ込める方針は良い

- 判断: 見送る (指摘に同意。設計の変更は不要)
- 根拠: いずれも現設計の記述と一致する。

## [Suggestion] 継承しない理由を docblock に残す

- 判断: **対応する**
- 対応内容: 正典の docblock (継承しない理由 = この devcontainer の shell に開発 DB 名が
  export されている) をそのまま持ち込む方針を実装方針へ明記した。
