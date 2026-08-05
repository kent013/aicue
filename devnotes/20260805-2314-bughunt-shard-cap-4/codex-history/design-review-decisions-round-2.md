# 対応マトリクス: design-review Round 2

全体判定: **CHANGES_REQUESTED** (Critical 0 / Warning 4)。
Round 1 の Critical 2 件は「閉じている」と確認された。残り 4 Warning は全件対応。

## [Warning] 施策 1: prefix 検証位置と Architecture テストの契約が矛盾
- 判断: **対応する**
- 根拠: 指摘の通り。設計は `SHARD_DB_RE` を定数ブロックで組み立てた後に検証を置いており、
  施策 5 の「`SHARD_DB_RE` 埋め込み**前**に検証」という構造テストと食い違う。
  このままだと構造テストが赤くなるか、実態を見ない弱いテストになる。
- 対応内容: `die()` 定義の直後に **prefix 検証 → `SHARD_DB_RE` 代入**の順で並べる形に統一した。
  定数ブロックからは `SHARD_DB_RE` の代入を移動する (`SHARD_RE` はここに残す)。
  「どちらでも可」と書いていた曖昧な但し書きは削除した。

## [Warning] 施策 3: 散文検出に偽陰性 (`2/4/6` / `N=6` / `parallel=8` が通過)
- 判断: **対応する**
- 根拠: 妥当。現行パターンは旧表記の完全一致 (`2/4/6/8`) しか見ておらず、
  「再び腐るのを deny-by-default で止める」恒久契約としては不足。
- 対応内容: 検出パターンに**割り当て文脈限定**の 3 本を追加した
  (`--parallel` を含む行の cap 超過数値 / `N=<cap 超過>` / `parallel` 近傍の cap 超過数値)。
  数字一般の無差別検出はしない (偽陽性が増えるため)。
  負のコントロールに `2/4/6` と `--parallel=8` を追加した。

## [Warning] 施策 5: `cap-defense-ok` が無制限の gate bypass になっている
- 判断: **対応する**
- 根拠: 妥当。マーカーを貼れば割り当ての誤記も黙らせられ、「レビュー時の目視」は機械保証ではない。
  deny-by-default を名乗る以上、bypass 自体に制約が要る。
- 対応内容: マーカーに 3 段の制約を入れた。
  (a) マーカーを使ってよいファイルの **allowlist** (`CAP_DEFENSE_MARKER_FILES`)。allowlist 外は違反。
  (b) マーカー行が**割り当て文脈** (`--parallel` / `2/4/6/8` 等) を含む場合はマーカーを無効化し違反にする。
  (c) マーカー行に**守りの語** (`denylist` / `guard` / `残留` / `防御` / `検出`) が 1 つも無ければ違反。
  負のコントロールを 3 本追加した。

## [Warning] 施策 5: defense surface テストの判定が抽象的で「8 まで維持」を保証していない
- 判断: **対応する**
- 根拠: 妥当。「空でない」だけでは値の維持を固定できない。
- 対応内容: defense surface テストを**値の直接固定**に具体化した
  (`DEV_DB_DENYLIST` が `bug_hunt_5`..`bug_hunt_8` を保持 /
  `DetectsBughuntDatabase` が `_[1-8]` を保持 / `run-browser-test.sh` が `8018` を保持)。
  既存 `TestDatabaseEnvTest` との役割分担 (あちらは denylist 全体の一致、こちらは
  「cap より広いこと」の意図固定) をテスト docblock に明記する。
