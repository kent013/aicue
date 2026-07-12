# 対応マトリクス: design-review Round 2

全体判定: CHANGES_REQUESTED (Warning 3 / Suggestion 4。Critical なし。
Round 1 の DB_USERNAME 固定注入 Critical は Codex が反論を認め撤回)

## [Warning] 施策1/3: 終了待ちが master 単体の worker_alive 判定で、group 内の子の DB 接続残留と dropdb が race
- 判断: 対応する
- 根拠: 指摘どおり。master 消滅後も終了処理中の `queue:work --once` 子が接続を持ちうる。
- 対応内容: `stop_shard_workers` の待機条件を `kill -0 -- "-${wpid}"`（process group 全体の
  存在確認。cmdline 照合済みの自所有 group に対する signal 0 で安全）が失敗するまでに変更。
  warning 文言も group 消滅基準へ更新。teardown 側コメント・施策 3 補足も同期。

## [Warning] 施策2: 補足説明が改訂コードと不整合 (manifest key のハイフン表記 / teardown 回収の記述)
- 判断: 対応する
- 対応内容: 補足を `worker_pid_database_analysis`（underscore 正規化）と
  「起動失敗時は start_shard_workers 内で stop_shard_workers による即時回収 → die」に更新。

## [Suggestion] 施策1: /proc cmdline の存在確認→読み出し間の race は静かに return 1
- 判断: 対応する
- 対応内容: `worker_alive` の tr 読み出しに `2>/dev/null || true` を付け、空なら return 1。

## [Suggestion] 施策2: manifest underscore 正規化と起動失敗ロールバックの構造検査を self-test に追加
- 判断: 対応する
- 対応内容: (y3) に `cmd_provision` の `conn//-/_` 検査と `start_shard_workers` の
  `stop_shard_workers` 参照検査を追加。

## [Suggestion] 施策5: stop_shard_workers の group 生存確認の構造検査を追加
- 判断: 対応する
- 対応内容: (y3) に `kill -0 -- "-` の grep 検査を追加。

## [Suggestion] 施策5: 実機 teardown 確認で各 PGID の process group 不在も確認
- 判断: 対応する
- 対応内容: テスト計画（全体）の実機確認に「控えた各 pgid について `kill -0 -- -<pgid>` が
  失敗すること」を追加。
