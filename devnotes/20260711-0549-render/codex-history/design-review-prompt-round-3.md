Round 2 の全指摘（Critical 1 + Warning 2 + Suggestion 1）に対応しました。
対応内容と改訂後の該当箇所を示します。再レビューと最終判定をお願いします。

## 対応マトリクス（Round 2 指摘 → 対応）

1. [Critical] ASS 改行の正規化順の論理矛盾 → **対応**。順序を修正案どおりに固定:
   ```
   1. 入力中のリテラル \N / \n / \h を無効化 (バックスラッシュ → 全角 ＼)
   2. 改行の正規化: CRLF / 単独 CR → LF
   3. 正規化済み LF → \N (ASS 改行。以降の手順はこの \N に触らない)
   4. { } → 全角 ｛ ｝ (override tag 注入の無効化)
   5. 不可視制御文字の包括除去 (C0/C1 + zero-width。LF は手順 3 で消費済み)
   6. 長さ上限 (1 行 100 / 総 500。切り詰め + 構造化ログ)
   7. BOM なし UTF-8 固定
   ```
   テストに「実改行とリテラル `\N` が同一入力に共存し、実改行だけが ASS 改行として残る」を追加

2. [Warning] S3 削除を DB tx（行ロック保持）内で実行 → **対応**。handle を 3 段に分離:
   ```php
   // 段 1: 検証 (ロックなし読み取り。冪等 guard・最新世代・prefix。
   //        判定が古くなっても段 3 の CAS が守る)
   $job = RenderJob::query()->find($this->renderJobId);
   if ($job === null || $job->output_path === null) { return; }
   if ($this->isLatestSucceededOfKind($job)) { return; }
   /* prefix 検証。不一致は Log::warning + return */
   $pathToDelete = $job->output_path;
   // 段 2: S3 削除 (tx 外。存在しないキーは no-op = 冪等)
   $storage->delete($pathToDelete);
   // 段 3: CAS で NULL 化 (検証時の値と一致する行のみ = 最新世代の誤 NULL 化防止。
   //        ここで失敗しても再実行・reconcile が冪等に収束)
   RenderJob::query()->whereKey($job->id)
       ->where('output_path', $pathToDelete)->update(['output_path' => null]);
   ```
   ロック順表の当該行も「行ロックなし（読み取り検証 → tx 外 S3 削除 → CAS update）」に更新

3. [Warning] 2 境界のテスト追加 → **対応**。施策 6/9/15 に追加:
   - ASS: 実改行とリテラル \N の共存で実改行のみ残る
   - 削除 job: S3 削除中に DB トランザクション/行ロックを保持しない
     （fake storage 内で transactionLevel=0 を検証）
   - 削除 job: CAS 不一致で NULL 化しない / 段 3 前クラッシュ相当から再実行で収束

4. [Suggestion] 「単一真実源」の表現 → **対応**。「正本 = docs/architecture.md、
   RenderPipeline docblock は参考転記（正本への参照リンク付き・乖離時は正本優先）」に修正

【出力形式】（Round 1 と同じ）
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力
