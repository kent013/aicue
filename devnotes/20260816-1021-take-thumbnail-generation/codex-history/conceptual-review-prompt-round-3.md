## Round 2 指摘への対応

Critical (非同期反映経路の欠落) は指摘を全面的に受け入れ、「判断 4」として設計に追加しました。
既存の `reloadManual()` を有界なスケジュールで再実行する形で、新しい endpoint は足していません。
Warning 6 件もすべて対応しています (集計の書き方は「既に PHPStan level 10 を通っている形」へ戻しました)。

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
`bytesUsed()` の集計に加える**。集計の書き方は**既に PHPStan level 10 を通っている形から外さない** —
join を共有する private builder を切り出し、`sum('takes.size_bytes')` と
`sum('takes.thumbnail_size_bytes')` を**それぞれ `(int)` で受けて** PHP 側で
`occupiedBytes()` と同じ overflow 安全な合成をする (生の SQL 式を `sum()` へ渡さない。
クエリは 1 本増えるが、型の扱いは既存と完全に同一で新しい型の不確実性を持ち込まない)。
`occupiedBytes()` の pending→used の読み取り順は**変更しない**
(サムネイル分は `bytes_pending` 側に対応物を持たないため、読み取り順の議論に影響しない)。
`bytesUsed()` が「動画 + サムネイル」を返すため、呼び出し元 3 者
(`TakeUploadService` / `DashboardService` / `BillingController`。いずれも `occupiedBytes()` 経由) は
**変更不要**である。性質は正直に書く:

- サムネイルは**予約を経ない事後計上**である。生成時点で上限を超えることはありうる
  (上限の強制点は presigned URL 発行時であり、そこでは次のアップロードが拒否される)。
- テイク行の削除で列ごと消えるため、解放は自動的に整合する (削除経路の変更は不要)。
- `bytes_pending` 側には**一切現れない** (予約が無いため)。読み取り順の不変条件に影響しない。
- 記録する値は「**自分が PUT したローカルファイルのサイズ**」である (S3 を読み直して照合しない)。
  重複配送で 2 つのワーカーが同じキーへ PUT した場合、DB の記録値と最終オブジェクトの
  バイト数が**数 KB ずれうる** (JPEG エンコードがワーカー間のバイナリ差で完全一致しない場合)。
  抽出パラメータ (seek / 寸法 / 品質) はすべて config に固定して決定性を運用契約とするが、
  「実測と恒常的に一致する」とは主張しない。この差は利用者が制御できず Quota 回避には使えない。

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

### 判断 4: 生成完了を撮影中の画面へ反映する経路 → **有界な再取得 (新しく現れたテイクだけを監視)**

サムネイルは**テイク登録の応答より後**に出来上がる。登録応答の `has_thumbnail` は必ず `false` であり、
これを放置すると「録画後に下部サムネイルで即確認」(doc/05) は成立せず、本タスクの主目的が
画面遷移するまで届かない。したがって反映経路は**本タスクの必須範囲**とする (スコープ外にしない)。

方式は**既存の再取得をそのまま使う**: `pages/Capture/Show.svelte` は既に
アップロード成功時と自動 DL 後に `reloadManual()` (`router.reload({ only: ["manual"] })`) を
呼んでいる。新しい endpoint も部分 props も足さず、これを**有界なスケジュールで数回追加実行**する。

- **監視対象は「このセッション中に新しく現れた take id」だけ**。初期スナップショットに居た
  テイクは監視しない。これにより **既存テイク (`thumbnail_path` が全件 null) では再取得が
  1 回も走らない** (無限に「まだ付いていないテイク」を追いかける事故を構造的に防ぐ)。
- **停止条件は 4 つ**: 監視集合が空になった (全部付いた) / 試行上限に達した /
  画面が非表示になった (`visibilitychange` で停止し、復帰時に残り試行だけ再開) / unmount。
- **間隔は固定配列** 2s → 4s → 8s → 15s の計 4 回 (合計 ~29 秒)。無制限ポーリングにしない。
- 判定は `resources/js/lib/capture/thumbnail-refresh.ts` の**純粋なスケジューラ**に閉じ込め、
  page は配線だけを持つ (`lib/capture/auto-download.ts` / `panel-navigation.ts` と同じ作法)。
  停止条件は Vitest (fake timers) で固定する。
- **受入条件**: 生成待ちの間は同じ枠にプレースホルダが出ており、生成完了後の再取得で
  **同じ枠が画像へ置き換わる**。上限内に終わらなければプレースホルダのまま残り、
  次回の入室で反映される (受容する劣化)。

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
8. 条件付き UPDATE (**`where id=? and status='ready' and thumbnail_path is null`**) で
   `thumbnail_path` と `thumbnail_size_bytes` を確定する。**preflight と同じ述語を条件へ再掲する**
   (検証と確定の述語を一致させ、検証後に状態が変わったテイクへ書き込まない)。
   0 行なら別ワーカーが先着、または状態が変わった = 何もしない
   (キーが決定的なので**オブジェクトを消してはならない**)。
9. `finally` で work dir を削除する。work dir は take id で決定的な名前にし、
   **開始時にも削除**する (強制終了で残った作業ファイルが再試行で自己修復する)。

**時間予算の連鎖**:
`ffmpeg` の Process timeout **60 秒** (`capture.thumbnail_ffmpeg_timeout_seconds`)
< ジョブ `$timeout` **180 秒** < media worker `--timeout=240` < `database-media` の `retry_after` **300 秒**。
ジョブ側を worker より短く取り、強制終了より先に自前の例外経路と `finally` へ入る余地を残す
(それでも SIGALRM で `finally` に入れない場合があることは「保証しないもの」に書く)。

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
- **出力の寸法と品質を config で固定する**: `scale=640:640:force_original_aspect_ratio=decrease`
  (**両辺に上限**を置き、巨大入力から巨大 JPEG を作らない) + `-q:v 5`。
  値は `capture.thumbnail_max_edge` / `capture.thumbnail_jpeg_quality` として持つ。
  同一入力・同一バイナリなら出力が決定的であることを実装側の前提とする (判断 1 の末尾と対)。
- **抽出位置**: `config('capture.thumbnail_seek_seconds')` (既定 1.0 秒) から 1 フレーム。
  先頭 0 秒は黒画面になりやすいため。尺が足りず 1 フレームも出力されなかった場合のみ、
  **seek=0 で 1 回だけ再試行**する (2 段。これ以上の探索はしない)。
- Feature テストは container swap で fake 実装を注入し、Unit テストは `Process::fake()` で
  コマンド構造を固定する (実バイナリに依存しない)。
- S3 側は既存の fake 作法 (`FakeTakeObjectStorage` / `Storage::fake('s3')`) に載せる。

### 4. S3 到達境界

`TakeObjectStorage` に **public メソッドを 3 つ新設**し、`S3SurfaceInventory` へ面分類つきで登録する
(既存メソッドの変更は無い):

| 新設メソッド | 面分類 | 用途 |
|---|---|---|
| `downloadToLocal(string $path, string $localPath): void` | `Bulk` | 動画本文の取得 (転送量がサイズに比例) |
| `upload(string $localPath, string $path, string $contentType): void` | `Bulk` | サムネイル本文の PUT |
| `temporaryThumbnailUrl(string $path): string` | `NoObjectRequest` | 署名 GET URL の文字列生成のみ |

署名 URL は既存の `temporaryPlaybackUrl()` を流用せず専用メソッドを新設する
(`playback` = 再生の語を静止画へ流用すると public API の名前が嘘になるため)。
`FakeTakeObjectStorage` にも 3 つの override を足す。
`FakeTakeObjectStorage` に対応する override を足す。

### 5. 配信 route と UI

- `GET /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes/{take}/thumbnail`
  (`capture.takes.thumbnail`)。`playback` と同じ 3 層 (テナント境界 404 → `Gate::authorize('preview')`
  → 状態 404) で、`thumbnail_path === null` も 404。応答は 302 + `Cache-Control: no-store, private`。
- `CaptureTakeData` / `CaptureTakeResource` / `types/capture.ts` に `has_thumbnail: boolean` を追加。
- `TakeStrip.svelte` の各行の先頭にサムネイル枠を置く (`has_thumbnail` が false ならアイコンの
  プレースホルダ)。DS token のみ・Lucide のみ。
- `pages/Capture/Show.svelte` に判断 4 の有界再取得を配線し、判定は
  `lib/capture/thumbnail-refresh.ts` (純粋なスケジューラ) に閉じ込める。

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
- **課金の正直さ**: サムネイル分も容量 Quota の集計に載る (実測と完全一致するとは主張しない。後述)。
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
- **記録バイト数と実オブジェクトの完全一致を保証しない**。重複配送で 2 つのワーカーが
  同じキーへ PUT した場合、DB の値と最終オブジェクトが数 KB ずれうる (判断 1 末尾)。
- **撮影中の反映は有界時間内だけ**。判断 4 のスケジュール (計 4 回 / ~29 秒) を超えて生成が
  かかった場合、その場ではプレースホルダのまま残る (次回入室で反映される)。
  画面が非表示の間は再取得しない。
- **作業ディレクトリの残骸をゼロにしない**。SIGALRM 等で `finally` に入れなかった場合、
  worker のローカルに work dir が残る (名前は take id で決定的なので、再試行時の開始削除で
  自己修復するが、再試行が来なければ残る)。
- **「サムネイルが必ず付く」ことを保証しない**。生成は best-effort であり、
  失敗しても take は `ready` のままである (判断 2)。
