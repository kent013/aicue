# 対応マトリクス: conceptual-review Round 2

## [Critical] 非同期生成の結果が PWA に反映される経路が無い (録画直後は has_thumbnail=false のまま)
- 判断: **対応する (スコープに含める)**
- 根拠: 指摘のとおり。テイク登録応答の時点で `thumbnail_path` は必ず null であり、
  現状の `pages/Capture/Show.svelte` はアップロード成功時に `reloadManual()`
  (`router.reload({ only: ["manual"] })`) を **1 回だけ**呼ぶ。生成完了はその後なので、
  画面を離れて戻るまでサムネイルは出ない = doc/05 の「録画後に下部サムネイルで即確認」が成立しない。
  スコープ外にはできないという指摘も受け入れる。
- 対応内容: **有界な再取得**を設計へ追加した。
  - 既存の `reloadManual()` をそのまま使う (新しい endpoint も部分 props も足さない)。
  - 監視対象は「**このセッション中に新しく現れた take id**」だけに限定する。
    初期スナップショットに居たテイクは監視しない = **既存テイク (thumbnail_path が全件 null) では
    再取得が 1 回も走らない** (フォールバック表示のまま静止する)。
  - 停止条件は 4 つ: 監視集合が空になった (全部サムネイルが付いた) / 試行上限に達した /
    画面が非表示になった (`visibilitychange` で停止し、復帰時に残試行だけ再開) / unmount。
  - 間隔は固定配列 (2s → 4s → 8s → 15s の 4 回、合計 ~29 秒) で無制限ポーリングにしない。
  - 判定ロジックは `resources/js/lib/capture/thumbnail-refresh.ts` の純粋なスケジューラへ出し、
    Vitest (fake timers) で停止条件を固定する (`lib/capture/auto-download.ts` と同じ作法)。
  - 期限内に生成が終わらなかった場合はプレースホルダのまま残る (再入室で反映される)。
    これは**受容する劣化**として「保証しないもの」へ明記した。

## [Warning] S3 目録の件数が本文内で不一致 (3 メソッド新設なのに「2 件」と書いている)
- 判断: **対応する**
- 対応内容: 新設 3 メソッドを面分類つきで明示列挙した
  (`downloadToLocal` = Bulk / `upload` = Bulk / `temporaryThumbnailUrl` = NoObjectRequest)。
  既存メソッドの変更は無いことも明記。

## [Warning] ffmpeg の Process timeout とジョブ $timeout の階層が未確定
- 判断: **対応する**
- 対応内容: 時間予算の連鎖を明記した —
  **ffmpeg 60 秒 (`capture.thumbnail_ffmpeg_timeout_seconds`) < ジョブ `$timeout` 180 秒
  < media worker `--timeout=240` < `retry_after` 300 秒**。
  ジョブ側を worker より短くして、強制終了より先に自前の例外経路 (と `finally`) へ入る余地を残す。
  併せて出力の寸法を**両辺とも固定上限**にした
  (`scale=640:640:force_original_aspect_ratio=decrease` + `-q:v 5` = 巨大入力から巨大 JPEG を作らない)。
  work dir は take id で決定的にし、**開始時にも削除**することで強制終了時の残骸が
  再試行で自己修復するようにした (それでも残る場合があることは「保証しないもの」に明記)。

## [Warning] 「即確認」という表現は非同期反映が実装されて初めて成立する
- 判断: **対応する**
- 対応内容: 受入条件を「生成待ちはプレースホルダ、完了後に同じ枠が画像へ置き換わる
  (有界再取得の範囲内で)」と書き下し、期待効果の文言もそれに合わせた。

## [Warning] 最終の条件付き UPDATE が preflight の条件を引き継いでいない
- 判断: **対応する**
- 根拠: 指摘のとおり。`where thumbnail_path is null` だけでは、preflight 後に
  他経路が状態を変えたテイク (ready でないもの) へ書き込める。
- 対応内容: 最終 UPDATE の条件を
  `where id = ? and status = 'ready' and thumbnail_path is null` に変更した
  (preflight と同じ述語を条件付き UPDATE へ再掲する = 検証と確定の述語を一致させる)。

## [Warning] 重複ワーカー間で S3 の実体と thumbnail_size_bytes がずれうる
- 判断: **対応する (指摘の 3 案のうち「決定性を運用契約にする」+ 主張を弱める、を採る)**
- 根拠: 条件付き PUT / PUT 所有権の状態機械はどちらも新しい機構であり、
  ずれの大きさ (JPEG 1 枚のエンコード差 = 高々数 KB) に対して釣り合わない (思考原則 2)。
  一方で「実測と恒常的に一致する」という強い主張は確かに成り立たない。
- 対応内容:
  - 抽出パラメータ (seek / scale / quality) をすべて config に固定し、
    同一入力・同一バイナリなら出力が決定的であることを実装側の前提として明記した。
  - 記録するのは「**自分が PUT したローカルファイルのサイズ**」であると明記
    (S3 を読み直して整合を取ることはしない)。
  - 「保証しないもの」に、重複配送時に DB の記録値と最終オブジェクトのバイト数が
    数 KB ずれうること、その差は利用者が制御できず Quota 回避に使えないことを追記した。
  - 期待効果の文言を「実測と一致する」から「サムネイル分も計上される」に弱めた。

## [Warning] `sum(DB::raw(...))` が PHPStan level 10 を通る保証が概念段階では無い
- 判断: **対応する (集計の書き方そのものを、既に通っている形へ戻す)**
- 根拠: 指摘のとおり、通ることを前提に設計を書くべきでない。`(int) …->sum('takes.size_bytes')` は
  現に level 10 を通っている**実在のパターン**なので、そこから外れない形にすればリスクはゼロになる。
- 対応内容: 1 本のクエリに拘らず、**同じ join を共有する private builder** を切り出して
  `sum('takes.size_bytes')` と `sum('takes.thumbnail_size_bytes')` の 2 回に分け、
  PHP 側で `occupiedBytes()` と同じ overflow 安全な合成を行う形に変更した
  (クエリは 1 本増えるが、型の扱いは既存と完全に同一)。
  `bytesUsed()` が「動画 + サムネイル」を返すため、
  `TakeUploadService` / `DashboardService` / `BillingController` の 3 呼び出し元は**変更不要**である
  (3 者とも `occupiedBytes()` 経由。`BillingController` も `occupiedBytes()` を呼んでいることを確認済み)。

## [Suggestion] direct fetch 目録 / キー導出 / protocol whitelist の分離判断は妥当
- 判断: 見送る (変更不要)
