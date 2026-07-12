**レビュー結果**

- `scripts/bug-hunt-shard.sh`（S1〜S5）: **APPROVE**
- `.env.bughunt.local.example`（S6）: **APPROVE**

**指摘事項**

- [Suggestion] 実機未検証（real Postgres + `BUGHUNT_ORCHESTRATOR=1`）は、今回の差分の性質上「必須ブロッカー」ではありません。  
  修正案: マージ後に軽量な運用確認手順を `devnotes/.../README` 等へ1回分だけ明記（`provision --shard 0` → queue投入 → `teardown --drop-db`）し、障害時の確認点（`worker-*.log` / pidfile残留）を残すと運用事故を減らせます。

---

**観点別評価（要点）**

- 設計一致性（S1〜S6）  
  - S1: `worker_alive` cmdline照合、`start_shard_workers` の `setsid + queue:listen + pid==pgid 検証 + 失敗ロールバック`、`stop_shard_workers` の TERM→待機→KILL→再確認、所有確認不能時 pidfile保持+rc=1 を満たす。  
  - S2: provision に worker起動導線追加、manifest の key underscore 正規化あり。  
  - S3: teardown で worker停止を serve より前へ移動、`workers_stopped` で dropdb 抑止、最終 `teardown_rc` 非ゼロ化あり。  
  - S4: keepdb-check に無条件 worker生存確認あり（seamなし）。  
  - S5: self-test [y]/[v] は構造・機能の両面をカバーし、stubは subshell ローカルで本体汚染なし。  
  - S6: コメント整合は適切。

- 正確性  
  - process group kill 前提（pid==pgid）を起動時/停止時の両方で検証しており、意図通り fail-closed。  
  - 停止不能・所有確認不能時に pidfile を保持するため、追跡可能性を維持。  
  - teardown の dropdb 抑止条件が worker停止結果に連動しており、設計意図通り。

- race/競合  
  - `/proc` 読み出し race は `worker_alive` 側で安全に false 扱い、停止側で「存在するが照合不能」は保持+失敗へ倒すため安全側。  
  - pid再利用誤判定を cmdline照合で回避。  
  - dropdb 競合は「group消滅確認後のみ成功扱い」に寄せており妥当。

- 冪等性・fail-closed  
  - 起動失敗時ロールバック、keepdb-check fail-closed、dryrun不起動（pidfile非生成）を self-test で検証済み。  
  - 旧 `|| continue` 回帰防止も構造検査に組み込み済みで良い。

- dev DB防御  
  - `env -i` 隔離、`DB_USERNAME=bughunt` 明示注入、`guard_bughunt_runtime` 実行を確認。  
  - 非交渉要件を弱める変更は見当たりません。

- self-test妥当性  
  - drift を PHP実評価で見ている点は強い。  
  - y6a-d で停止系の重要分岐（成功/停止不能/stale/所有確認不能）を押さえており十分。  
  - 本体 seam を増やさず subshell stub で実現している点も適切。

---

**全体判定**

- **APPROVED**