# 対応マトリクス: design-review Round 2

## [Warning] S8: 「孤児の後始末」テストの失敗地点と後始末経路が一致していない
- 判断: **対応する (指摘が正しい。現行コードで確認済み)**
- 根拠: `RenderPipeline::run()` を読み直した。制御フローは
  `startJob → buildManifest → downloadSources → compose → updateProgress → assertStillOwned
   → storage->upload → $uploadedKey = ... → finalize` で、`finally` の削除条件は `$uploadedKey !== null` である。
  - **ffprobe の非数値出力は `compose()` の中で失敗する**。この時点で `upload()` は未実行、
    `$uploadedKey` は `null` のままなので、`finally` の削除は**走らない**。
    したがって同じテストで「S3 の孤児削除」は検証できない。
  - **`upload()` 自身が途中で例外を投げた場合も `$uploadedKey` は `null`** である
    (代入は `upload()` の**次の行**)。よって「部分アップロードを必ず消す」は現行構造から導けない。
- 対応内容: 失敗地点ごとにテスト契約を**3 本に分ける**。
  1. **compose 失敗 (今回の題材)**: `upload()` が**呼ばれない**こと + `render_jobs.status = failed` +
     `output_path === null` + `video_manuals.status` が `rendering` に残らない + 後続ジョブが処理可能。
  2. **孤児削除 (既存契約の回帰。静止画とは独立)**: `upload()` 成功後に `finalize()` が
     失敗する状況を作り、`$uploadedKey` が `finally` で `delete()` されることを固定する。
     **これは本施策が作る挙動ではない**ので、既存テストが同等のものを持っていれば新設しない
     (実装時に確認し、無ければ 1 件足す)。
  3. **`upload()` 途中失敗の部分オブジェクト**: **保証しない**。
     `TakeObjectStorage`/`RenderObjectStorage` は Flysystem 経由の PUT であり、
     S3 の単一 PUT は原子的だが**マルチパートの中断が部分オブジェクトを残さない保証は
     アプリ側で持っていない**。設計から「部分アップロードも既存経路で削除する」という記述を**削除**し、
     `docs/architecture.md` の**未軽減リスク**として記録する
     (`-max_alloc` の残余と同じ扱い。誇張しない)。

## [Suggestion] S6: `imageFailed` のリセットを `{#key}` 依存で書かない
- 判断: **対応する**
- 根拠: 指摘のとおり。`{#key}` は DOM を作り直すが、`<script>` の `$state` は再生成されない。
- 対応内容: `take.id` (と `playbackUrl`) の変化を `$effect` で監視して `imageFailed = false` に戻す、と
  設計へ明記する。テスト計画には既に「テイクを切り替えると失敗状態がリセットされる」が入っている。

## [Suggestion] S8: 「後続ジョブが処理可能」の重複記載 (Round 3 で指摘)
- 判断: **対応する**
- 対応内容: 重複していた 1 行を削除した (判定には影響しない編集ミス)。
