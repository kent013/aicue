# 対応マトリクス: conceptual-review Round 3

## [Warning] 観点5: 「誤申告の帰結は権限内の自傷にとどまる」は不正確 (画素数爆弾による可用性リスク)
- 判断: **対応する (断定を撤回し、緩和策を 1 つ足したうえで残余リスクを記録する)**
- 根拠: 指摘は正しい。画像は**小さいファイルで巨大な画素数を宣言できる**
  (圧縮された PNG / JPEG は数百 KB でも数万×数万画素を宣言できる)。ffmpeg のデコーダは
  フレームバッファを実画素数ぶん確保するため、`capture.max_still_bytes` (バイト数) の上限では
  この経路を止められない。時間の上限 (`Process::timeout()`) はメモリを止めない。
  OOM で worker プロセスが落ちると、`media` キューを共有する**他組織のサムネイル生成が遅延する**。
  これは静止画を受け入れることで**新しく開く**面であり、「自分のマニュアルだけ」ではない。
- 対応内容:
  1. 「権限内の自傷にとどまる」という断定を**削除**する。
  2. **緩和策を 1 つ足す**: 静止画を入力に取りうる ffmpeg 実行 2 か所
     (`FfmpegTakeThumbnailExtractor` / `FfmpegVideoComposer::planTakeStill()` の先頭フレーム抽出) に
     **`-max_alloc <bytes>`** を付ける (1 回の heap 確保の上限。既定 256 MiB 程度を config に置く)。
     上限を超えるデコードは ffmpeg が**非 0 終了**するので、既存の失敗経路
     (サムネイル: `TakeThumbnailExtractionException` → ジョブ失敗 / レンダ:
     `RenderCompositionException` → `failJob`) にそのまま収束する。**新しい失敗様式を作らない**。
  3. **既存の隔離契約に接続して明記する**: `Process::timeout()` (サムネイル 60 秒 / レンダ encode 600 秒・probe 60 秒)、
     `GenerateTakeThumbnailJob` の `tries=3` + backoff、`RunManualRender` の `tries=1`。
     タイムアウト時に `failJob` へ収束し、後続ジョブが処理可能であることをテストで固定する。
  4. **残余リスクを `docs/architecture.md` に記録する**: `-max_alloc` は
     **1 回の確保**の上限であって、プロセス全体の RSS 上限でも同時実行数の上限でもない。
     worker のメモリ cgroup 制限・同時実行数の制限は本リポジトリに無く、
     本施策でも新設しない (デプロイ基盤が無いため = `AGENTS.md` の
     「存在しない基盤のための機構を先回りして作らない」)。**未軽減として台帳に残す**。
  5. 同期 GET + ffprobe を採らない判断は Round 2 のまま維持する (Codex も受容済み)。

## [Suggestion] 観点 1 / 2 / 3 / 4 / 6 / 7
- 判断: 反映不要 (肯定的評価)
