# 実装ノート: bughunt-queue-worker (T018 / F-01)

## 本セッションで実施した検証
- `bash -n scripts/bug-hunt-shard.sh` (syntax) OK
- `scripts/bug-hunt-shard.sh self-test` 全 pass (既存 [a]-[x] 回帰 + 新規 [y])
  - [y2] drift check: config/queue.php を PHP 実評価し database-analysis/render/media の
    3 connection が BUGHUNT_WORKER_CONNECTIONS と一致
  - [y6a-d] stop_shard_workers 機能検査: 正常停止 / 停止不能時 pidfile 保持+rc=1 /
    stale 削除 / 所有確認不能時 pidfile 保持+rc=1
- 回帰ゲート: pint --test / phpstan (No errors) / pnpm typecheck / lint / build 全 green

## 実機確認 (未実施。運用時に1回実施すること)
real Postgres + `BUGHUNT_ORCHESTRATOR=1` の bughunt 環境 (worktree 内) で:

1. `BUGHUNT_ORCHESTRATOR=1 scripts/bug-hunt-shard.sh provision --shard 0 --run-id <ts>`
   → `pgrep -f "queue:listen database-"` が 3 プロセス
2. セッション認証で analyze をトリガー → `jobs` テーブルのレコードが消費され
   `analysis_jobs.status` が `queued` に滞留せず終端 (completed/failed) へ遷移
   (LLM fake 未配線なら failed + UI エラーで F-01 解消の確認としては十分)
3. wrapper 経由 `reseed` 実行後も worker が生存し以降のジョブを処理すること
4. `keepdb-check --shard 0` pass → worker を手動 kill すると fail
5. `teardown --run-id <ts> --drop-db` 後に `pgrep -f "queue:listen"` が 0 件、
   各 worker pgid の `kill -0 -- -<pgid>` が失敗 (process group 全体の消滅)

## 障害時の確認点
- worker が起動しない: `${TMP_BASE}/worker-{shard}-{connection}.log` を確認
- teardown が非ゼロ終了: warning が指す pidfile を確認し、`ps -o pgid= -p <pid>` で
  group 残留を調べてから手動 kill → 再 teardown
- keepdb-check reuse 中止: worker が死んでいる → 再 provision (queued 滞留 F-01 再発防止)
