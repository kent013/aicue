## Round 1 指摘への対応

以下の対応マトリクスのとおり修正しました。Critical 2 件のうち 1 件 (Quota 超過表示) は
「未設計」ではなく「既存の超過表示経路にそのまま乗る」ことをコードで確認したうえで、
設計書にその接続を明記する形で対応しています (新規 UI は作りません)。

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

---

## 修正後の概念設計 (全文)

# 概念設計: take-thumbnail-generation (テイクのサムネイル生成)

## 背景・課題

`takes.thumbnail_path` カラムは migration (`2026_07_10_000400_create_takes_table.php`) に存在し、
削除経路 (`Capture/CaptureTakeService::delete()` / `Manual/VideoManualService` / `Jobs/Capture/DeleteTakeObjectsJob`)
はすでに `thumbnail_path` を回収する前提で書かれている。しかし**値を書き込む経路がどこにも無く常に null**である。

この欠落により、次の 2 つの仕様が実現できていない:

- `doc/04_PCサイト機能仕様.md` L46「**動画列**: 登録済みテイクはサムネイル表示」
- `doc/05_スマホアプリ機能仕様.md` L51「録画後は**下部サムネイル**をタップして即再生確認」

現状の撮影 PWA (`features/capture/TakeStrip.svelte`) は、テイクを「テイク N / サイズ / 秒数」という
**文字だけの行**で並べている。横持ち全画面で 3〜5 本のテイクを撮り比べる現場作業者は、
どれがどのカットのどの撮り直しかを**再生ボタンを押して動画を開くまで判別できない**。
これは使命の「思考ゼロ・編集ゼロ」— 撮影判断を AI とナビが肩代わりする — に対して、
「採用するテイクを選ぶ」という最後の判断だけが視覚的手がかりゼロで残っている状態である。

なお `doc/09_詳細実装設計.md` L133 は「Take 作成でキュー投入 → ffmpeg で正規化 (H.264/mp4) +
サムネイル生成 → status=ready」と書いているが、現行実装は HeadObject 三点照合が通った時点で
`status=ready` を確定させており (正規化は未実装)、本設計もそこは変えない (後述「スコープ外」)。

## 改善アイデア

**テイク登録の確定と同じトランザクションでサムネイル生成ジョブを投入し、media queue の worker が
動画の先頭付近から 1 フレームを抽出して S3 へ置き、`takes.thumbnail_path` を埋める。**
撮影 PWA のテイク一覧は、既存のテイク動画 playback endpoint と同じ作法の
`GET .../takes/{take}/thumbnail` (302 → 署名 GET URL) で画像を表示する。

方向性の要点は 5 つ:

1. **テイクの ready を遅らせない**。サムネイルは補助的な表示情報であり、生成の成否が
   採用可否・レンダ可否に影響しない。生成失敗はテイクを failed にせず `thumbnail_path` を
   null のまま残し、UI はプレースホルダへ degrade する。
2. **キーは take の主キーから決定的に組む** —
   `projects/{project_id}/manuals/{manual_id}/cuts/{cut_id}/takes/thumbnails/{take_id}.jpg`。
   すべてサーバ側の整数と固定文字列で、**`video_path` の文字列加工を一切しない**
   (拡張子なし・複数ドット・既存名との衝突といった境界条件を持ち込まない)。
   衝突不能性は take 主キーの一意性 (bigserial・再利用なし) が担保する。
   重複配送で 2 つのワーカーが走っても**同じキーへ同じ意味の PUT** をするだけなので、
   敗者が勝者のオブジェクトを消す事故が構造的に起きない。
3. **結果の一回性は条件付き UPDATE** (`where thumbnail_path is null`) が担い、
   取り消せない外部副作用 (S3 PUT) の直前に**所有権の再検証 (preflight)** を置く
   (AGENTS.md ドメイン固有規約 6)。
4. **ffmpeg 実行は差し替え可能な境界の裏に置く** (`VideoComposer` インターフェースと同じ作法)。
   テストは実バイナリに依存しない。
5. **容量 Quota には計上する。ただし `takes.size_bytes` には触らない** (別カラム
   `takes.thumbnail_size_bytes` を足し、`StorageUsageService::bytesUsed()` の合計へ加える)。
   根拠は後述「設計判断」。

## 設計判断

### 判断 1: サムネイルを容量 Quota (`max_storage_bytes`) に計上するか → **計上する (別カラム)**

計上しない案の穴: `takes.size_bytes` の合計だけを `bytes_used` とする現行のままだと、
組織が実際に S3 に置いているバイト数と課金上の使用量が**恒常的に乖離**する。乖離幅は
「テイク 1 本あたり最大でサムネイル 1 枚」で有界だが、**比率は有界ではない** —
1 秒の極小テイク (数十 KB) を大量に登録すると、サムネイル (数十 KB) が動画と同オーダーになり、
最悪ケースで使用量の実測が上限の 2 倍近くまで膨らむ。「無視できるほど小さい」は
利用者が意図的に小さいテイクを並べたときに成り立たない。

計上する側の実現方法として、`takes.size_bytes` にサムネイル分を足し込むのは**採らない**。
`size_bytes` は「予約 (`take_upload_reservations.size_bytes`) と HeadObject の
ContentLength が三点照合で一致した確定値」であり、`StorageUsageService::occupiedBytes()` の
pending→used 読み取り順の不変条件は「予約に載っていた同じ数値が bytes_pending から
bytes_used へ移る」ことを前提にしている。ここへ事後の加算を混ぜると、その同一性が壊れ、
過少計上を防いでいる論拠 (「競合は二重計上 = 安全側に倒れる」) が読めなくなる。

したがって **`takes.thumbnail_size_bytes` (unsigned int / nullable) を足し、
`bytesUsed()` の集計に加える**。集計は**クエリを 1 本に保ったまま**
`sum(DB::raw('takes.size_bytes + COALESCE(takes.thumbnail_size_bytes, 0)'))` を `(int)` で受ける
(既存の `(int) …->sum('takes.size_bytes')` と同じ受け方で、戻り型の扱いを変えない)。
`occupiedBytes()` の overflow 合成と pending→used の読み取り順は**変更しない**。性質は正直に書く:

- サムネイルは**予約を経ない事後計上**である。生成時点で上限を超えることはありうる
  (上限の強制点は presigned URL 発行時であり、そこでは次のアップロードが拒否される)。
- テイク行の削除で列ごと消えるため、解放は自動的に整合する (削除経路の変更は不要)。
- `bytes_pending` 側には**一切現れない** (予約が無いため)。読み取り順の不変条件に影響しない。

**上限超過状態の見え方は既存経路にそのまま乗る (新しい表示を作らない)**:

- 課金ダッシュボードの `QuotaStatusDto::build()` が「使用量 > 上限」の**厳密超過**を
  `exceededLabels` に載せて表示する (「上限ちょうど」を警告にしない既存の決着はそのまま)。
- `DashboardService::billingSummary()` は使用量に `StorageUsageService::occupiedBytes()` を渡し、
  `storageUsagePercent` を 0〜100 に clamp しているため、超過しても表示は壊れない。
- 追加アップロードの拒否は `QuotaService::checkAddition` → `QuotaExceededException` →
  既存の 422 `quota_exceeded` ボディ (`types/capture.ts` の `QuotaExceededBody`) で既に成立している。

表示と拒否の両方が `StorageUsageService` を唯一の入力にしているため、`bytesUsed()` に
サムネイル分を足せば**追加の UI 実装なしで**両方へ反映される。新しい超過警告 UI は作らない
(既存の超過表示で足りるため。AGENTS.md 思考原則 2)。

**波及チェックリスト** (詳細設計で施策の表へ落とす):
migration (列追加) / `StorageUsageService::bytesUsed()` / `TakeFactory` /
削除経路 (行削除で自動解放のため**変更なし**であることの確認) / quota 表示 (変更なし) /
既存の Quota 系テスト (集計値の期待値)。

### 判断 2: 生成失敗時の扱い → **テイクは ready のまま / フォールバック表示 / バックフィルしない**

- ジョブは `$tries = 3` + backoff で再試行する (S3 / ffmpeg の一過性障害を吸収)。
- 最終的に失敗しても `takes.status` は触らない。サムネイルは採用・レンダの必須条件ではなく、
  ここで `failed` に落とすと「撮れているのに採用できないテイク」を作ってしまう。
- `thumbnail_path` が null のテイクは UI がプレースホルダ (Lucide アイコン + DS token の枠) を出す。
  **既存テイク (全件 null) はこの経路でそのまま表示される** = 追加実装なしでフォールバックが効く。
- **過去分の一括バックフィルは本タスクのスコープ外**とする。既存テイクは PC 面・PWA 面とも
  プレースホルダのままで機能上の詰みが無く、バックフィルは「全テイクの動画を S3 から
  引き直して ffmpeg にかける」一括処理で、有界化・優先度・コストの設計が別途要る
  (AGENTS.md 思考原則 2)。必要になった時点で別 TODO として起票する。
- **滞留回収 (`work:recover-stuck`) の系列も足さない**。サムネイルには「進まないまま残る
  ジョブ行」が存在せず (状態は takes の 1 列だけ)、欠けたままが**受容可能な劣化状態**である。
  回収系列を 6 本目として足すのは、進まないと業務が詰む処理を前へ進めるという
  ドメイン固有規約 14 の目的から外れる。

### 判断 3: 配信方式 → **既存 playback endpoint と同じ 302 リダイレクト**

`TakeStrip.svelte` は既にテイク動画を `/app/.../takes/{take}/playback` という**アプリの route URL**で
参照している (署名 URL を props に載せているのは採用テイクの DL 用 1 本だけ)。同じ作法で
`GET /app/.../takes/{take}/thumbnail` を足し、`<img src>` に route URL を渡す。

props に全テイク分の署名 URL を載せる案は採らない — 一覧に載るテイク数だけ URL が増え、
TTL 切れの扱い (再取得のために画面全体を再読込) が必要になり、Inertia の履歴に短命な
署名付き URL が量産される。route 経由なら失効の概念が UI に漏れない。

DTO には**論理値 `has_thumbnail` だけ**を足す (パスも URL も出さない)。UI はこの 1 つで
`<img>` とプレースホルダを出し分け、存在しない画像への 404 リクエストを出さない。

## 実装方針 (概要)

### 1. ジョブ投入 (テイク登録の確定 tx 内)

`Capture/TakeRegistrationService::finalize()` の tx 内、Take の `save()` 直後で
`GenerateTakeThumbnailJob::dispatch($take->id)` する (キュー投入の原子性 = ドメイン固有規約 11。
`afterCommit` は使わない)。冪等再送の分岐 (`resolveDuplicate()`) では投入しない
(既存テイクは既に投入済み)。payload は take の主キーのみ (payload 不信任)。

### 2. ジョブ本体 (`App\Jobs\Capture\GenerateTakeThumbnailJob`, connection `database-media`)

処理順:

1. Take を主キーで取得。不在なら no-op で終了。
2. `thumbnail_path !== null` なら no-op (再配送の冪等ショートカット)。
3. `status !== ready` なら no-op。
4. work dir 作成 → `TakeObjectStorage::downloadToLocal()` で動画を取得 (S3 GET = 冪等な読み取り。
   preflight 不要)。
5. `TakeThumbnailExtractor::extract()` で 1 フレームを JPEG 化 (ローカル CPU。preflight 不要)。
6. **preflight**: take 行が実在し `status=ready` かつ `thumbnail_path` が null であることを再検証。
   失われていれば PUT せず降りる (`JobOwnershipLostException` と同じ観測語彙で 1 行ログ)。
   **この検証と PUT の間に自前の書き込みを挟まない**。
7. `TakeObjectStorage::upload()` で決定的キー
   (`projects/{project_id}/manuals/{manual_id}/cuts/{cut_id}/takes/thumbnails/{take_id}.jpg`) へ PUT。
8. 条件付き UPDATE (`where id=? and thumbnail_path is null`) で `thumbnail_path` と
   `thumbnail_size_bytes` を確定。0 行なら別ワーカーが先着 = 何もしない
   (キーが決定的なので**オブジェクトを消してはならない**)。
9. `finally` で work dir を削除。

### 3. ffmpeg 境界

- `App\Services\Capture\TakeThumbnailExtractor` (interface) と
  `App\Services\Capture\FfmpegTakeThumbnailExtractor` (実装) を作り `AppServiceProvider` で bind する
  (`VideoComposer` → `FfmpegVideoComposer` と同じ作法)。
- 実装は `Process` facade + `config('manual.render_ffmpeg_binary')` で
  `-ss {seek} -i in -frames:v 1 -vf scale=... -q:v N out.jpg` 相当を実行する
  (`FfmpegVideoComposer` の still frame extract が参考実装)。
- **安全境界を明文化する** (利用者がアップロードした動画を入力に取るため):
  - 引数は**配列**で渡す (シェル連結なし)。入力・出力ともサーバ生成のファイル名だけを渡し、
    利用者由来の文字列は 1 つも引数に入らない。
  - 実行は work dir 固定 + `timeout` 付き。`-nostdin` で標準入力待ちに落ちない。
  - **`-protocol_whitelist file` を明示**して、細工されたファイルが外部参照を含む形式として
    probe された場合でもローカルファイル以外へ到達しないようにする。
  - 出力ファイルの**実在と非空**を検査し、失敗は専用例外へ分類する
    (ffmpeg の標準エラー出力は先頭のみ切り出してログへ載せる)。
  - **観測事実**: 既存の `FfmpegVideoComposer` は `-protocol_whitelist` を持っていない。
    入力の素性 (利用者が PUT した S3 オブジェクト) は同じであり、これは本タスクで
    新設する側だけ強くする形になる。**既存 render 経路の後追いは本タスクでは行わない**
    (concat demuxer への影響検証が別途要るため)。別タスク候補として記録する。
- **抽出位置**: `config('capture.thumbnail_seek_seconds')` (既定 1.0 秒) から 1 フレーム。
  先頭 0 秒は黒画面になりやすいため。尺が足りず 1 フレームも出力されなかった場合のみ、
  **seek=0 で 1 回だけ再試行**する (2 段。これ以上の探索はしない)。
- Feature テストは container swap で fake 実装を注入し、Unit テストは `Process::fake()` で
  コマンド構造を固定する (実バイナリに依存しない)。
- S3 側は既存の fake 作法 (`FakeTakeObjectStorage` / `Storage::fake('s3')`) に載せる。

### 4. S3 到達境界

`TakeObjectStorage` に `downloadToLocal()` / `upload()` を足し、面分類 (`S3OperationSurface::Bulk`)
を `S3SurfaceInventory` へ登録する。署名 GET URL は **`temporaryThumbnailUrl()` を新設**する (中身は動画側と同じ署名 GET URL 生成だが、
`playback` = 再生の語を静止画へ流用すると public API の名前が嘘になるため分ける)。
面分類は `NoObjectRequest` で登録する。
`FakeTakeObjectStorage` に対応する override を足す。

### 5. 配信 route と UI

- `GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail`
  (`capture.takes.thumbnail`)。`playback` と同じ 3 層 (テナント境界 404 → `Gate::authorize('preview')`
  → 状態 404) で、`thumbnail_path === null` も 404。応答は 302 + `Cache-Control: no-store, private`。
- `CaptureTakeData` / `CaptureTakeResource` / `types/capture.ts` に `has_thumbnail: boolean` を追加。
- `TakeStrip.svelte` の各行の先頭にサムネイル枠を置く (`has_thumbnail` が false ならアイコンの
  プレースホルダ)。DS token のみ・Lucide のみ。

### 6. 目録への登録 (deny-by-default の gate 群)

- `JobExecutionDedupInventoryTest`: **保証側**として登録
  (`JobDedupGuarantee::ConditionalStatusUpdate` + `ExternalCallKind::ObjectStoragePut` の preflight)。
- `QueuedJobLeaseInventoryTest`: `database-media` として登録。
- `NestedRouteDefenseInventory`: 新 route の 4 parameter を `playback` と同じ分類で登録。
- `S3SurfaceInventory`: 新規 public メソッド 2 件を面分類。
- `DirectFetchInventory`: ジョブが payload の take id で行を引き直す箇所を
  `DirectFetchJustificationEntry::queuePayload()` として登録する
  (key = `Jobs/Capture/GenerateTakeThumbnailJob.php#handle#Take.find:$this->takeId#1`、
  `enqueuedBy = App\Services\Capture\TakeRegistrationService::finalize`)。
  分類根拠は「take id はテナント検証済みの登録 tx がサーバ採番した主キーで HTTP 入力を経由せず、
  worker 側は再水和してから所有権と状態を検査する」。既存の
  `RenderPipeline#run#RenderJob.findOrFail` / `DeleteRenderOutputsJob#handle#RenderJob.find` と同型。
- bug-hunt 目録 (`inventory/annotations.toml` へ 1 行足して再生成)。

## 期待効果

- **使命への貢献**: 撮り比べたテイクに**視覚的な判別の手がかり**が加わり、「どれを採用するか」の
  判断で毎回動画を開く必要が減る (思考ゼロ)。横持ち撮影 (doc/05) の「録画後は下部サムネイルで即確認」
  という設計意図が初めて成立する。**1 フレームで必ず見分けられるとは主張しない**
  (似た構図の撮り直しは区別できない)。
- **サーバ側資産が揃う**: doc/04 の動画列サムネイル表示に必要な生成・保存・配信が揃う。
  **doc/04 の仕様充足そのものは未達**である (PC 面の描画は別タスク)。
- **課金の正直さ**: 実際に置いているバイト数が容量 Quota の集計に載る。
- **既存契約の非破壊**: 予約 CAS / 三点照合 / `size_bytes` の意味 / 削除経路はいずれも変更なし。

## 制約・前提

- **AGENTS.md ドメイン固有規約 6** (ジョブの重複実行と結果の一回性): 保証側として目録登録し、
  S3 PUT の直前に preflight を置く。入口の排他 (`ShouldBeUnique`) は使わない
  (規約 11 = 業務 tx 内 dispatch と両立しない)。
- **AGENTS.md ドメイン固有規約 11** (キュー投入の原子性): 登録 tx の内側で dispatch。
- **AGENTS.md ドメイン固有規約 1**: 本ジョブは `cuts` / `video_manuals.status` /
  `scenario_version` を**書かない**ため、VideoManual 行ロックは取らない。単一行の条件付き
  UPDATE で足り、バックグラウンドジョブが新しいロック順序の辺を作らないことを優先する。
- **セキュリティ不変条件 2 / 10**: 新 route は既存 `scopeBindings` group の中に置き、
  テナント境界 404 を認可より前に閉じる。
- **テストレーンの外部 HTTP 出口は既定拒否**: S3 は `Storage::fake` / fake adapter、
  ffmpeg は container swap と `Process::fake()` で実バイナリ・実ネットワークに触れない。
- **キューの運用契約**: `database-media` (queue=media, retry_after=300) は
  `docs/architecture.md` §撮影 PWA に worker 必須登録済み。ジョブの `$timeout` は
  worker の `--timeout=240` と同値 (`retry_after` 未満 = 規則 2)。

## スコープ外

- **既存テイクの一括バックフィル** (判断 2 のとおり。プレースホルダで degrade する)。
- **PC 面 (doc/04 のシナリオ編集画面 動画列) の描画**。サーバ側 (生成・保存・配信) までを
  本タスクとし、PC 側 UI は別タスクとする。
- **動画の正規化 (H.264/mp4 変換)** (doc/09 L133 の前半)。現行どおり登録時点で `ready` を確定させる。
- **サムネイルの差し替え・任意フレーム選択 UI**。
- **滞留回収系列の追加 / 孤児サムネイルの reconcile コマンド** (残余窓は「保証しないもの」として明記する)。
- **PWA 一覧 (doc/05 L31 のシナリオカード) のサムネイル**。テイク単位ではなくマニュアル単位の
  代表画像であり、別の概念 (AGENTS.md 思考原則 4)。

## 保証しないもの (誇張しない)

- **孤児オブジェクトはゼロにならない**。preflight と PUT の間、および PUT と条件付き UPDATE の
  間でワーカーが落ちると、どのカラムからも参照されないサムネイルが S3 に残る
  (再試行が同じ決定的キーへ PUT して列を埋めれば自己修復するが、全試行が尽きた場合は残る)。
  回収コマンドは作らない。
- **順序は「PUT してから列を埋める」**。逆順にすると実体の無いキーを指す列ができ、
  署名 URL が壊れて UI に見える形で失敗するため。孤児は見えない側の失敗を選んだ結果である。
- **サムネイルの画質・代表性は保証しない**。先頭付近の 1 フレームであり、
  「そのテイクを最もよく表す絵」であることは主張しない。
- **容量 Quota の上限超過を防がない**。サムネイルは予約を経ない事後計上であり、
  上限の強制点は presigned URL の発行時のままである。
