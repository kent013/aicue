# 対応マトリクス: design-review Round 1

全体判定: CHANGES_REQUESTED (Critical 3 / Warning 5 / Suggestion 5)

## [Critical] 施策1: DB_USERNAME=bughunt の固定注入は env 側と乖離すると worker 即死
- 判断: 反論する（現設計を維持）
- 根拠: `DB_USERNAME=bughunt` の固定注入は本スクリプトの既存流儀そのもの
  （`artisan_for_shard` L205、serve 起動ブロック L715 が同一の固定注入）。乖離は
  provision 冒頭 L626 の `[[ "$(env_file_get DB_USERNAME)" == "bughunt" ]] || die
  "${ENV_FILE} の DB_USERNAME は bughunt 固定"` が fail-fast で排除しており、
  「guard は通るが接続失敗」になる前に provision 自体が停止する。さらに
  `guard_bughunt_runtime` は user==bughunt を dev DB 防御の hard-deny 判定軸にしている
  （bughunt role は dev DB へ CONNECT 不可という PostgreSQL 権限設計とペア）。
  worker だけ `env_file_get DB_USERNAME` 参照に変えると「env ファイル書き換えで任意 role の
  worker が走る」余地を作り、非交渉要件をむしろ弱める。
- 対応内容: 詳細設計に上記根拠を「設計判断の根拠」として明記（施策 1 補足）。
  self-test への「env 側ユーザー名を使っている」検査は追加しない（固定注入が正）。

## [Critical] 施策3: --drop-db 時の待機が pgrep -f で全 shard 横断判定
- 判断: 対応する
- 根拠: 指摘どおり。他 shard の正常 worker に引きずられ無駄待機・誤判定する。
- 対応内容: 全 shard 横断の pgrep を廃止。worker 停止を `stop_shard_workers`（新設、
  起動失敗ロールバックと共通経路）に集約し、shard + connection ローカルの
  `worker_alive` が false になるまで（最大 2s）待機する方式へ変更。
  タイムアウト時は warning ログのみ（dropdb 失敗時はエラーで顕在化）。

## [Critical] 施策4: BUGHUNT_SKIP_WORKER_CHECK seam を本体に入れるのは危険
- 判断: 対応する
- 根拠: 指摘どおり。運用経路の誤設定で worker 検査が飛び F-01 再発を見逃す。
- 対応内容: seam を全廃。self-test [v] はサブシェル内で `worker_alive` 関数を stub
  （`return 0` / `return 1`）して keepdb-check を検証する方式へ変更
  （`fg_run_keepdb_ok` / `fg_run_keepdb_dead`）。実物 `worker_alive` は (y4) で独立検証。

## [Warning] 施策1: worker_alive の単一 grep パターンは引数順序変化で偽陰性化
- 判断: 対応する
- 対応内容: artisan / queue:listen / `" ${conn} "` / `--env=bughunt.local` を独立の grep で
  照合する実装に変更（施策 1 変更後コード反映）。

## [Warning] 施策2: manifest key にハイフンを含むと shell eval 前提の消費側で壊れる
- 判断: 対応する
- 根拠: 現消費側は python (JSON) のみだが、正規化コストはゼロに近く将来の消費側を縛らない。
- 対応内容: `worker_pid_${conn//-/_}`（underscore 正規化）に変更。

## [Warning] 施策3: group kill 後の wait/再確認がなく dropdb と race
- 判断: 対応する
- 対応内容: 上記 Critical 対応（stop_shard_workers の shard ローカル待機）に統合。

## [Warning] 施策4: エラーメッセージにどの pidfile が不一致かを含める
- 判断: 対応する
- 対応内容: die メッセージに `$(worker_pidfile ...)` を埋め込み。

## [Warning] 施策5: drift check の fail 文言を「依存未導入による検査不能」と明確化
- 判断: 対応する
- 対応内容: `__php_failed__` 時の文言を「drift check 実行不能: vendor/autoload.php または
  config/queue.php を PHP 評価できない (依存未導入なら composer install 後に再実行)」へ変更。

## [Suggestion] 施策1: BUGHUNT_WORKER_CONNECTIONS コメントに「順序は不問 (sort 比較)」明記
- 判断: 対応する（コメント追記）

## [Suggestion] 施策2: start_shard_workers 失敗時に起動済み worker の軽いロールバック
- 判断: 対応する
- 対応内容: fail-fast の die 前に `stop_shard_workers "${shard}"` を呼び、
  起動済み worker をその場で回収（teardown 依存を減らす）。

## [Suggestion] 施策5: `|| continue` の復活防止検査を追加
- 判断: 対応する
- 対応内容: (y3) に `cmd_teardown` の定義へ `'${pidfile}" ]] || continue'` が
  復活していないことの検査を追加。

## [Suggestion] 施策6: example 同期方針を README/運用ドキュメントにも追記
- 判断: 見送る
- 根拠: 本修正のスコープ（worker/キュー配線）外。`.env` 両ファイルのコメント同期で足りる。
  ドキュメント横断整備は app-update-docs の定期実行に委ねる。

## [Suggestion] 施策3: continue 除去の構造是正は妥当
- 判断: 対応済み（設計どおり）
