# Round 3: Round 2 指摘への対応 (最終確認)

残っていた S8 の Warning (失敗地点と後始末経路の不一致) と、S6 の Suggestion
(`imageFailed` のリセットを `{#key}` に頼らない) を対応しました。
全文の再送は行わず、変更した箇所だけを示します。

## 対応マトリクス

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


---

## 変更後の該当箇所 (詳細設計より抜粋)

### S8 のテスト契約 (失敗地点ごとに分離)

#### 誤申告 (申告 Content-Type と実体の不一致) の扱い — 2 方向で非対称

**この非対称を取り違えない**:

| 誤申告の向き | 起きること | 判定 |
|---|---|---|
| `image/jpeg` と申告して**動画**バイト列を置く | `material_type=still` → `planTakeStill()` の `-frames:v 1` が**動画からも 1 枚出す** = **C2 とまったく同じ経路で成功する** | **害が無い**。テストの題材にしない |
| `video/mp4` と申告して**画像**バイト列を置く | `material_type=video` → `planTakeVideo()` → `probeDurationMs()` の ffprobe が `format=duration` を数値で返せない → `RenderCompositionException` | **失敗ジョブになる**。これが固定すべき挙動 |

- [ ] **デコード不能・尺不明の素材は failed job になる**: `video/mp4` 申告 + 画像バイト列のテイクを
      採用したレンダが `failed` で終わる。ffmpeg / ffprobe は `Process::fake()` で非 0 終了 (または
      非数値出力) を返させる (**実バイナリに依存しない**)
- [ ] **壊れた成果物を出さない**: 失敗ジョブの `output_path` が null のまま /
      `video_manuals.status` が `rendering` に残らない
- [ ] **後続ジョブが処理可能**: 失敗ジョブの後に別の render job が正常に完了できる
- [ ] **後続ジョブが処理可能**: 失敗ジョブの後に別の render job が正常に完了できる

**失敗地点ごとにテスト契約を分ける (現行の制御フローを読んで確定)**。
`RenderPipeline::run()` は
`startJob → buildManifest → downloadSources → compose → updateProgress → assertStillOwned
→ storage->upload → $uploadedKey = ... → finalize` の順で、`finally` の削除条件は
`$uploadedKey !== null` である。したがって:

| 失敗地点 | `$uploadedKey` | 固定する内容 |
|---|---|---|
| `compose()` (今回の題材 = ffprobe が尺を返さない) | **null のまま** | `upload()` が**呼ばれない** / `status=failed` / `output_path === null` / `video_manuals.status` が `rendering` に残らない |
| `finalize()` (既存契約の回帰) | 非 null | `finally` で `delete($uploadedKey)` が呼ばれる |
| `upload()` の途中 | **null のまま** | **保証しない** (下記) |

- [ ] compose 失敗のテストで**「孤児削除」を期待しない** (この地点では `upload()` 自体が未実行のため
      検証できない)。孤児削除は `finalize()` 失敗の**別テスト**で固定する
      (**本施策が作る挙動ではない**ので、既存テストが同等のものを持っていれば新設しない。実装時に確認する)
- [ ] **`upload()` 途中失敗の部分オブジェクトは保証しない**。`$uploadedKey` への代入は
      `upload()` の**次の行**にあるため、途中失敗した PUT は `finally` の削除対象にならない。
      これは本施策が作る問題ではなく現行構造の性質であり、`docs/architecture.md` に
      **未軽減リスクとして記録する** (`-max_alloc` の残余と同じ扱い。誇張しない)
- [ ] Quota: 静止画の presign → 登録 → `bytes_pending` 解放 → `bytes_used` 加算 の 1 巡



### S6 の imageFailed リセット

`imageFailed` は `$state(false)`。**`{#key}` に頼ってリセットしない** —
`{#key}` は DOM を作り直すが `<script>` の `$state` は再生成されないため、前のテイクの失敗が残る。
`take.id` (と `playbackUrl`) の変化を `$effect` で監視して明示的に `false` へ戻す。



### docs/architecture.md へ足す非保証 (追加分)

   - **レンダ成果物の `upload()` が途中で失敗したときの部分オブジェクトは削除されない**。
     `RenderPipeline::run()` の `$uploadedKey` への代入は `upload()` の**次の行**にあり、
     `finally` の後始末は「アップロードが完了したが succeeded に到達しなかった」場合しか拾わない。
     これは本施策が作る問題ではなく現行構造の性質である (**未軽減**)。
   - migration の backfill は「既存テイクは全件動画」という前提に立つ。根拠は
     presign が `allowed_video_content_types` しか通していないこと。


---

他の節に変更はありません。この 2 点で解消していれば、全体判定を APPROVED としてください。
