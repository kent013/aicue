# 対応マトリクス: conceptual-review Round 1

## [Critical] payload 由来 ID の direct fetch が ModelDirectFetchInvariantTest に抵触しうる
- 判断: **対応する**
- 根拠: 実コードで確認。`tests/Support/Security/DirectFetchInventory.php` は
  `app/**` + `routes/*.php` を母集団に、クラス起点の主キー同一性クエリを deny-by-default で分類させる。
  既存ジョブ (`DeleteRenderOutputsJob#handle#RenderJob.find:$this->renderJobId#1`、
  `RenderPipeline#run#RenderJob.findOrFail:$renderJobId#1` 等) はすべて
  `DirectFetchJustificationEntry::queuePayload($reason, enqueuedBy: …)` で登録済みであり、
  新ジョブも同じ登録が必須である。指摘は正しい。
- 対応内容: 概念設計「目録への登録」に `DirectFetchInventory` への `queuePayload` 登録を追加した
  (key = `Jobs/Capture/GenerateTakeThumbnailJob.php#handle#Take.find:$this->takeId#1`、
  `enqueuedBy = App\Services\Capture\TakeRegistrationService::finalize`)。
  併せて「take id はテナント検証済み tx がサーバ採番した値で HTTP 入力を経由しない」という
  分類根拠を設計本文へ明記した。

## [Critical] Quota 事後計上により上限超過状態が正常に発生する。その表示・説明が未設計
- 判断: **対応する (ただし新規 UI は作らない。既存経路に乗ることを明記する)**
- 根拠: 実コードで確認。上限超過は**すでに一級の表示状態として設計済み**である。
  - `App\DataTransferObjects\Billing\QuotaStatusDto::build()` が
    `storageUsedBytes > 上限` の厳密超過を `exceededLabels` に載せ、課金ダッシュボードが表示する。
    docblock は「上限ちょうどは警告に含めない / 判定は生のバイトで行う」と既に決着済み。
  - `Dashboard/DashboardService::billingSummary()` は
    `StorageUsageService::occupiedBytes()` を使用量として渡し、`storageUsagePercent` を
    0〜100 に clamp している (超過しても表示が壊れない)。
  - 追加アップロードの拒否は `QuotaService::checkAddition` → `QuotaExceededException` →
    既存の 422 `quota_exceeded` ボディ (`types/capture.ts` の `QuotaExceededBody`) で既に成立している。
  したがって「未設計」ではなく「**既存の超過表示経路にそのまま乗る**」が正しい。ただし
  設計書がそれを書いていなかったのは事実なので、波及として明記する。
- 対応内容: 「判断 1」に、超過状態の表示・拒否がどこで既に成立しているかと、
  両方が `StorageUsageService` を単一の入力にしているため**新しい表示を足さずに**
  サムネイル分が自動的に反映されることを追記した。新規 UI はスコープ外と明記。

## [Warning] doc/04 の欠落解消は「サーバ側資産が揃う」に限定して表現すべき
- 判断: **対応する**
- 対応内容: 期待効果の表現を「PC 面の描画は別タスクであり、doc/04 の仕様充足そのものは未達」と読める形へ修正した。

## [Warning] `temporaryPlaybackUrl()` をサムネイルに再利用するのは責務と名前がずれる
- 判断: **対応する**
- 根拠: 指摘のとおり。`playback` は「再生」の語であり静止画には嘘になる。面分類は
  どちらも `NoObjectRequest` で登録コストも小さい。
- 対応内容: `TakeObjectStorage::temporaryThumbnailUrl()` を新設し (内部は同じ署名 GET URL 生成)、
  `S3SurfaceInventory` に `NoObjectRequest` として登録する方針へ変更した。

## [Warning] ffmpeg にユーザーアップロード動画を渡す境界設計が不足 (argv / timeout / 出力検査 / protocol)
- 判断: **対応する (ただし既存 render 経路の後追い修正は本タスクでしない)**
- 根拠: 前半 (argv 配列・timeout・作業ディレクトリ・出力検査・失敗分類) は
  `FfmpegVideoComposer` が既に満たしている作法で、新実装も揃えるべきである。
  後半 (protocol whitelist) は実コードを確認したところ **`FfmpegVideoComposer` は
  `-protocol_whitelist` を持っていない**。入力はどちらも「利用者が PUT した S3 オブジェクトを
  ローカルへ落としたファイル」で脅威は同一である。新経路にだけ足すと片側だけ固まるが、
  それでも**新設する経路を弱い側に合わせる理由はない**ので新経路には付ける。
  既存 render 経路を同じ PR で直すのはスコープ外 (レンダの concat demuxer 経路への影響検証が要る)。
- 対応内容: 安全境界を明文化 (argv 配列 / shell 連結なし / サーバ生成のファイル名のみ /
  work dir 固定 / timeout / `-nostdin` / `-protocol_whitelist file` / 出力の実在と非空の検査 /
  非 0 終了は専用例外) し、**既存 render 経路に同じ whitelist が無いことを観測事実として記録**した
  (本タスクでは直さない。別タスク候補として明記)。

## [Warning] 「見た目で判別できる」は先頭付近 1 フレームでは弱い (黒画面等)
- 判断: **対応する**
- 対応内容: 期待効果を「判別の手がかりを与える」に弱め、
  抽出位置を `capture.thumbnail_seek_seconds` (既定 1.0 秒) とし、
  尺が足りず 1 フレームも出なかった場合に seek=0 で 1 回だけ再試行する 2 段構えを設計へ明記した。
  「代表性は保証しない」は「保証しないもの」に既にある。

## [Warning] 決定的キーの導出規則が曖昧 (拡張子なし・複数ドット・既存 -thumb.jpg 名)
- 判断: **対応する (導出方法そのものを変更)**
- 根拠: 指摘のとおり `video_path` の文字列加工は境界条件を持ち込む。
  そもそも take の主キーが一意で再利用されない (bigserial) ため、文字列加工は不要である。
- 対応内容: キーを
  `projects/{project_id}/manuals/{manual_id}/cuts/{cut_id}/takes/thumbnails/{take_id}.jpg`
  へ変更した (すべてサーバ側の整数と固定文字列。文字列解析ゼロ・衝突は主キーの一意性が保証)。

## [Warning] `thumbnail_size_bytes` 追加は blast radius が広い。チェックリスト化せよ
- 判断: **対応する**
- 対応内容: 概念設計に「波及チェックリスト」を追加 (migration / 集計 / 削除経路 / quota 表示 /
  Factory / 既存テスト)。詳細設計で施策単位の表へ落とす。

## [Warning] 集計の型 (PHPStan level 10)
- 判断: **対応する**
- 対応内容: `bytesUsed()` は**1 本のクエリのまま**
  `sum(DB::raw('takes.size_bytes + COALESCE(takes.thumbnail_size_bytes, 0)'))` を `(int)` で受ける形にする
  (既存 `(int) …->sum('takes.size_bytes')` と同じ受け方で、戻り型の扱いを変えない) と明記した。
  `occupiedBytes()` の overflow 合成と pending→used の読み取り順は変更しない。

## [Suggestion] 使命整合 / スコープ判断は妥当
- 判断: 見送る (変更不要)
