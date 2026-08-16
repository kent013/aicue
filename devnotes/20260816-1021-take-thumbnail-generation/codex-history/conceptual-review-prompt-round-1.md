## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. 型安全性: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

【本リポジトリの前提（既存実装。レビュー時の事実として扱ってよい）】
- 撮影 PWA のテイク登録は `POST /app/projects/{project}/manuals/{manual}/cuts/{cut}/takes`。
  presigned PUT + 署名チケット + HeadObject 三点照合 + 予約 CAS (pending→verifying→completed) で確定し、
  確定時に `takes.status=ready` を明示代入する (`app/Services/Capture/TakeRegistrationService.php`)。
- 容量 Quota は `Billing/QuotaService::checkAddition` + `Capture/StorageUsageService::occupiedBytes`
  (bytes_used = takes.size_bytes の org 合計 / bytes_pending = 予約の合計) で presigned URL 発行時に判定する。
- S3 操作は `Capture/TakeObjectStorage` / `Render/RenderObjectStorage` の 2 adapter に集約され、
  public メソッドは面分類 (NoObjectRequest / BoundedControl / Bulk) の目録登録が必須。
- ffmpeg は `Render/FfmpegVideoComposer` が `Process` facade 経由で実行し、`VideoComposer` interface で
  差し替え可能 (テストは container swap と `Process::fake()`)。
- キューは connection 別 (`database-media` retry_after=300 / `database-render` / `database-analysis`)。
  ShouldQueue 実装は全数が 2 つの目録 (dedup / lease) へ登録必須。
- 削除経路 (`CaptureTakeService::delete` / `VideoManualService` / `DeleteTakeObjectsJob`) は既に
  `thumbnail_path` を回収する実装になっている。

---

## 概念設計

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
2. **キーは決定的に導出する** (`video_path` の拡張子を `-thumb.jpg` へ置換)。重複配送で
   2 つのワーカーが走っても**同じキーへ同じ意味の PUT** をするだけなので、敗者が勝者の
   オブジェクトを消す事故が構造的に起きない。
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
`bytesUsed()` の集計に加える**。性質は正直に書く:

- サムネイルは**予約を経ない事後計上**である。生成時点で上限を超えることはありうる
  (上限の強制点は presigned URL 発行時であり、そこでは次のアップロードが拒否される)。
- テイク行の削除で列ごと消えるため、解放は自動的に整合する (削除経路の変更は不要)。
- `bytes_pending` 側には**一切現れない** (予約が無いため)。読み取り順の不変条件に影響しない。

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
7. `TakeObjectStorage::upload()` で決定的キーへ PUT。
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
- Feature テストは container swap で fake 実装を注入し、Unit テストは `Process::fake()` で
  コマンド構造を固定する (実バイナリに依存しない)。
- S3 側は既存の fake 作法 (`FakeTakeObjectStorage` / `Storage::fake('s3')`) に載せる。

### 4. S3 到達境界

`TakeObjectStorage` に `downloadToLocal()` / `upload()` を足し、面分類 (`S3OperationSurface::Bulk`)
を `S3SurfaceInventory` へ登録する。署名 GET URL は既存 `temporaryPlaybackUrl()` を再利用する
(署名文字列の生成だけで、動画とサムネイルで作法が変わらないため専用メソッドを作らない)。
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
- bug-hunt 目録 (`inventory/annotations.toml` へ 1 行足して再生成)。

## 期待効果

- **使命への貢献**: 撮り比べたテイクを見た目で判別できるようになり、「どれを採用するか」の
  判断からも読解負荷が減る (思考ゼロ)。横持ち撮影 (doc/05) の「録画後は下部サムネイルで即確認」
  という設計意図が初めて成立する。
- **仕様の欠落解消**: doc/04 の動画列サムネイル表示に必要なサーバ側の資産 (生成・保存・配信) が
  揃う (PC 面の描画は別タスク)。
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
