レビュー仮説: 実装は施策 0〜7 を満たしており、主な失敗可能性は「静的 gate が実運用の接続 pin を実際には保証していない」点にある、と見て確認しました。

**app/Jobs/Manual/RunManualAnalysis.php**
判定: OK  
指摘なし。コメント更新は `database=600s` への変更と整合しています。

**app/Jobs/Manual/RunManualRender.php**
判定: OK  
指摘なし。コメント更新は `database=600s` への変更と整合しています。

**config/queue.php**
判定: OK  
指摘なし。`database.retry_after=600` のリテラル化は設計どおりで、env 上書き禁止 test も入っています。

**mprocs.yaml**
判定: OK  
指摘なし。4 接続すべてで接続名明示 + `--timeout < retry_after` になっています。`pail` の例外登録も妥当です。

**scripts/bug-hunt-shard.sh**
判定: OK  
指摘なし。接続別 timeout、`set -u` 下の未定義防止、self-test の drift 検査はいずれも設計に沿っています。

**tests/Architecture/QueueWorkerLeaseInvariantTest.php**
判定: OK  
指摘なし。mprocs / bug-hunt の規則 1 gate と `DB_QUEUE_RETRY_AFTER` env 後退検出は実効性があります。

**tests/Architecture/QueuedJobLeaseInventoryTest.php**
判定: 要修正  
[Warning] 接続 pin の検査が「queued class 内に `$this->onConnection('literal')` が 1 件ある」ことまでしか見ておらず、その呼び出しが dispatch 前に必ず実行されることを保証していません。例えば `RunManualAnalysis` 相当の job で `onConnection()` を未使用メソッドへ移しても、目録値と literal が一致すれば gate は通りますが、実行時は既定接続に流れ、規則 2 の比較対象が空洞化します。  
該当: `jobLeaseConnectionDeclarationSites()` がメソッド名/位置を保持していない点、および「目録の接続宣言がソースと一致する」test。少なくとも `__construct` 内の `$this->onConnection('literal')` に限定する、または dispatch 前に必ず pin される形だけを許可する検査に強めるべきです。

**tests/Feature/Queue/WorkerTimeoutTransitionTest.php**
判定: OK  
指摘なし。`failed_jobs` ではなく `JobFailed` イベントを観測する逸脱は、Worker 層だけを叩く前提では妥当です。

**tests/Support/Queue/TriesOnceProbeJob.php**
判定: OK  
指摘なし。

**tests/Support/Queue/TriesThriceProbeJob.php**
判定: OK  
指摘なし。

**tests/Support/QueueLeaseConfig.php**
判定: OK  
指摘なし。`config()` ではなく `config/queue.php` の直接 require にした判断は gate の目的と合っています。

**docs/architecture.md**
判定: OK  
指摘なし。規則 1/2、採用値、本番 supervisor は CI 対象外である点、Laravel major 更新時の再確認条件が明文化されています。

CHANGES_REQUESTED