# Round 3: Round 2 指摘への対応

Critical 1 件 (実効素材種別をレンダと尺ゲートで共有する) は対応しました。
Warning 5 件のうち 4 件を対応し、1 件 (Content-Type 申告とアップロード実体の一致検証) は
費用と脅威モデルの根拠を添えて**反論**し、非保証の明記と観測可能な契約のテスト化に置き換えています。

残っている Critical / Warning があれば指摘してください。無ければ全体判定を APPROVED としてください。

---

## 対応マトリクス

# 対応マトリクス: conceptual-review Round 2

## [Critical] 観点3: 実効素材種別が RenderPipeline にだけあり、尺ゲートと共有されていない
- 判断: **対応する**
- 根拠: 指摘のとおり。C5 (`cut=video` / `take=still`) でレンダは `TakeStill`、ゲートは
  `duration_ms ?? 60_000` になり、解消したはずの二重管理が別の形で残る。
- 対応内容:
  - `App\Services\Manual\EffectiveMaterialType::of(Cut $cut, Take $adoptedTake): MaterialType` を新設し、
    「**この判定式を書いてよいのはこの 1 ファイルだけ**」という単一化のかたち (T148 / T154 と同じ作法) を採る。
  - `RenderPipeline::clipSpecFor()` は `Still → TakeStill` / `Video → TakeVideo` を写す。
  - `RenderJobService::assertTotalSourceDurationWithinLimit()` は `Still` のとき
    `StillDisplayDuration::secondsFor($cut) * 1000`、`Video` のとき `duration_ms ?? 既定` を加算する。
  - 引数で採用テイクを受け取る形にする (relation を内部で読まない) ため、
    `AdoptedTakeReferenceInventory` の登録は増えない。呼び出し側 2 本はどちらも既に登録済みファイルである。
  - C5 のテストに**尺ゲートの計算値**を含める (マニフェストだけを見て終わりにしない)。

## [Warning] 観点2: Content-Type は申告であり、オブジェクト実体と一致する保証が無い
- 判断: **反論する (ただし非保証を明記し、観測可能な帰結をテストで固定する)**
- 根拠:
  1. **これは静止画対応が新しく作る穴ではない**。現在も `video/mp4` と申告して壊れたバイト列を PUT できる。
     HeadObject 三点照合 (size / Content-Type / checksum) は「申告どおりのバイト列が置かれたこと」を固定するが、
     形式そのものは今も検証していない。静止画の追加でこの性質は 1 ミリも変わらない。
  2. 提案されている実体検証は「既存 verifying 工程の強化」ではなく、**新しい同期外部 I/O の追加**である。
     現在の POST takes は S3 の **HeadObject 1 回**しか外部 I/O を持たない。実体検証には
     **オブジェクト本体の S3 GET + ffprobe/ffmpeg のプロセス実行**が要る。これを撮影 PWA の
     登録要求 (モバイル回線・オフライン復帰時に一括再送される経路) に同期で入れると、
     数十 MB のダウンロードとプロセス起動が登録のたびに走り、`registration_in_flight` (409) の
     窓が大きく開く。**費用と失敗様式に見合わない**。
  3. 誤申告の帰結は権限内の自傷である。編集権限を持つ利用者が自分のマニュアルのレンダを失敗させるだけで、
     cross-org にも他人のデータにも波及しない。現行設計は既にこの帰結を受容している
     (`FfmpegVideoComposer` の失敗 → `RenderCompositionException` → `failJob`)。
- 対応内容 (受容の明示と、観測可能な契約の固定):
  - `docs/architecture.md` の「保証しないもの」に
    「`takes.material_type` は**申告 Content-Type からの分類**であり、オブジェクト実体の形式を保証しない」
    と書く (誇張しない)。
  - 代わりに**観測可能な帰結**をテストで固定する: 形式の合わない素材が採用されたレンダは
    **失敗ジョブとして終わる** (壊れた mp4 を出さない / 走りっぱなしにならない)。
  - payload の `material_type` で分類結果を上書きできないことは、`['missing']` ルールの 422 テストで固定する
    (これは Round 1 で受け入れ済み)。

## [Warning] 観点3: `still_max_edge` / `still_jpeg_quality` を PHP config に置くとフロントへ渡す経路が要る
- 判断: **対応する (ただし props で渡すのではなく、置き場所ごと見直す)**
- 根拠: この 2 値は**サーバがまったく使わない**。サーバが強制するのは `max_still_bytes` (バイト数) だけである。
  使わない値を config に置いて Inertia props で往復させるのは、経路を増やして二重管理を作るだけである。
- 対応内容: PHP config には置かず、**`resources/js/lib/capture/still-encode.ts` 1 モジュール**に
  長辺上限と JPEG 品質を定数として置き、シャッター経路とファイル正規化経路の**両方がそこから読む**
  (component に直書きしない)。サーバ側は `capture.max_still_bytes` だけを持ち、
  クライアント既定値がその上限に十分収まることを設計に書く。

## [Warning] 観点5: クランプは誤設定を黙って別の値に変える
- 判断: **対応する**
- 根拠: 指摘のとおり。`default_still_display_seconds` は `env()` を持たない固定値にする予定なので、
  クランプする理由がそもそも無い。
- 対応内容: **クランプをやめる**。`config()->integer()` で読むだけにし、
  「既定値が編集画面の入力範囲 (1〜60) に収まっていること」を config のテストで pin する
  (既存 `ConfigHardeningTest` と同じ、値そのものを固定する作法)。`env()` は付けない。

## [Warning] 観点5: 「`<img>` デコードで EXIF 向きが適用される」の保証が強すぎる
- 判断: **対応する**
- 対応内容:
  - 設計文の断定をやめ、「対象ブラウザでの**実機/Browser lane での確認事項**」に格下げする。
  - `docs/supported-browsers.md` の対象 (Chromium + WebKit の 2 レーン) で、
    向き情報付き JPEG の fixture を読み込ませ、出力の縦横が期待どおり入れ替わることを Browser テストで固定する。
  - 正規化に失敗した場合 (デコード不可 / `toBlob` が null) は**アップロードせずエラー表示**にする
    (黙って原本を送らない)。
  - 再エンコード後の JPEG は EXIF を持たないため、**サーバ側・ffmpeg 側で向きを解釈する必要が無い**
    ことは設計の帰結として書ける (こちらは断定してよい)。

## [Warning] 観点7: `ScenarioStepData.materialType` だけ `?string` のまま
- 判断: **対応する**
- 対応内容: `ScenarioStepData` / `ScenarioPointData` の `materialType` を `MaterialType|null` に狭め、
  `toArray()` で `?->value` にする。PHPStan level 10 回避のために `string` を維持することはしない。
  (`ScenarioStepInput` 側は既に `?MaterialType` なので、これで入出力の型が揃う。)

## [Suggestion] 使命整合 / 効果の限定 / 実装順序
- 判断: 反映不要 (肯定的評価)


---

## 修正後の概念設計 (全文)

# 概念設計: still-image-cut-capture (静止画カットの撮影と反映)

## 背景・課題

### ドキュメント側が要求していること

- `doc/02 §2.4` のデータモデル: 手順 (カット) は `素材登録状況`(未登録・動画登録済・**静止画登録済**) /
  `素材種別`(動画・**静止画**) / `静止画表示時間` を持つ。端末側ローカルデータにも `素材種別`(動画・静止画) がある。
- `doc/02 §2.2`: 「各カットの尺は『ナレーションの尺』と『素材の尺』を比較して自動決定される。
  静止画カットは表示秒数を指定でき、**ナレーション尺より短い場合はナレーション尺が優先**」。
- `doc/09 §尺算出(v1)`: `cut_length = material_ms`。静止画は `static_display_seconds*1000`。

### 実装側の現状 (実コードを読んで確認した事実)

| 層 | 現状 |
|---|---|
| スキーマ | `cuts.material_type` (enum `MaterialType: video\|still`) / `cuts.static_display_seconds` あり |
| シナリオ編集 | `ScenarioEditor.svelte` に「素材」Select (未指定/動画/静止画) と「静止表示秒数 (1〜60)」入力が**既にある** |
| 保存 | `UpdateScenarioRequest` → `ScenarioStepInput` → `ScenarioService::save()` で両列とも保存される |
| レンダ | `RenderPipeline::clipSpecFor()` が `material_type === Still` で `RenderClipSource::TakeStill` を出し、`FfmpegVideoComposer::planTakeStill()` が採用テイクの**先頭フレーム**を `static_display_seconds` 尺で保持する |
| 尺ゲート | `RenderJobService::assertTotalSourceDurationWithinLimit()` は still かつ `static_display_seconds !== null` のとき秒数×1000 を加算する |
| 撮影 | `TakeUploadService` の presign は `capture.allowed_video_content_types`(`video/mp4` `video/webm` `video/quicktime`) のみ。`extensionFor()` も 3 種のみ。PWA は `CameraRecorder`(MediaRecorder) とファイル選択 (`accept="video/*"`) だけ |
| takes | 素材種別を表す列を**持たない** (`video_path` / `duration_ms` / `thumbnail_path`) |

### つまり「欠けているもの」を正確に言う

**「静止画カットのレンダが通らない」のではない。** 現状でも「素材 = 静止画」のカットに**動画テイク**を採用すれば、
先頭フレームが `static_display_seconds` 秒表示されるクリップとして正しく合成される。この経路は
`FfmpegVideoComposerTest` / `RenderManifestTest` で既にテストされている。

欠けているのは次の 4 点である。

1. **静止画そのもの (JPEG/PNG) を素材として登録する経路が無い**。presign が動画 Content-Type しか許さないため、
   現場で「これは 1 枚の写真で十分」という素材を、写真として撮って上げることができない。
   結果として撮影者は必ず動画を撮る必要があり、doc/02 の「端末ローカルデータの素材種別 = 静止画」が実現できない。
2. **takes が素材種別を持たない**。仮に画像を上げられるようにすると、`video` カットに画像テイクが載った瞬間に
   `planTakeVideo()`(ffprobe で尺を測る) と `<video>` プレビューが壊れる。「何が登録されているか」を
   型で持たないと、この破綻を防げない。
3. **`static_display_seconds` が未指定のときの既定値が、別概念の設定値を流用している**。
   `RenderPipeline::clipSpecFor()` は `?? config('manual.preview_placeholder_seconds')` で
   **「採用テイク欠落 cut のプレースホルダ尺 (3 秒)」**を静止画の表示秒に転用している。
   これは「別物の概念を『似ているから』で統合しない」(思考原則 4) に反しており、
   プレースホルダ尺を変えると完成動画の静止画尺まで黙って変わる。
   さらに `RenderJobService` の尺ゲートは `static_display_seconds === null` のとき
   **still でも `duration_ms ?? 60_000` に落ちる**ため、同じ「未指定の静止画」に対して
   ゲートとレンダで**別の尺**が使われている (二重管理)。
4. **素材登録状況 (未登録 / 動画登録済 / 静止画登録済) をどこにも出していない**。
   シナリオ編集の「動画」列は `テイク N 件` + `採用済み` バッジまでで、種別を言わない。

## 改善アイデア

**「カットの素材種別 (計画) を真実源に、撮影 UI・受け入れ検証・表示を一本の鎖で通す」**。

1. **cuts.material_type を撮影ナビの指示にする**。カットが `still` なら PWA はシャッター (静止画撮影) を出し、
   それ以外 (`video` / 未指定) は従来どおり録画を出す。「撮るべきものを AI が指示する」という使命そのものの延長で、
   撮影者に判断させない。
2. **takes に素材種別 (実体) を持たせる**。`cuts.material_type` は**計画**、`takes.material_type` は**実際に何が登録されたか**で、
   別概念だが値域は同じなので `MaterialType` enum を共有する。サーバが Content-Type から確定させ、payload からは受けない。
3. **受け入れは非対称**にする。
   - `still` カット: 画像 **も** 動画 **も** 受ける (動画は従来どおり先頭フレーム抽出。既存データと既存挙動を壊さない)
   - `video` / 未指定カット: **動画のみ**。画像は 422 で押下時にエラー表示 (禁止事項 8 の通り disabled にしない)
   この非対称は「実際に壊れる組み合わせだけを閉じる」ためであり、対称にする理由が無い。
4. **レンダのクリップ種別は「実体優先」で決める**。`cut.material_type === Still` **または**
   `take.material_type === Still` のいずれかが真なら `RenderClipSource::TakeStill` にする。
   入口 (presign) を閉じるだけでは穴が残るためである — **採用した後に編集者がシナリオ編集で
   カットの素材種別を `still` → `video` へ戻せる**ので、「video カットに画像テイクが採用済み」という
   状態は入口検証でも採用検証でも作れてしまう。画像を `planTakeVideo()` (ffprobe で尺を測る) に流すと必ず壊れる。
   実体優先にすれば、どの経路から不整合が入っても**画像が動画クリップとして合成される道が構造的に消える**。
   その上で**採用 API では拒否しない** (詰ませない。素材登録状況の表示で編集者が気づける)。
   入口だけ 422 で止めるのは「指示と違う素材で容量を消費させない」ためで、
   この非対称は既存の「render は 422 でブロック / preview はブロックしない」(ドメイン規約 12) と同じ作法である。
5. **「実効素材種別」と「静止画の表示秒」の 2 つの式を、それぞれ 1 クラスへ集約**する
   (T148 の `AdoptedReadyTakeCoverage` / T154 の `CurrentRenderArtifact` と同じ「単一化」の作法)。
   - `EffectiveMaterialType::of(Cut, Take): MaterialType` — 上の 4 の式を書いてよい唯一の場所。
   - `StillDisplayDuration::secondsFor(Cut): int` — `static_display_seconds` ?? `config('manual.default_still_display_seconds')`
     (新設・既定 5 秒)。
   **レンダ (`RenderPipeline::clipSpecFor`) と尺ゲート (`RenderJobService::assertTotalSourceDurationWithinLimit`) の
   両方が同じ 2 つを呼ぶ**。片方だけ実効判定を持つと、C5 でゲートが `duration_ms ?? 60_000`、
   レンダが 5 秒という**新しい二重管理**が生まれるためである。
   これで、いま存在する「未指定 still でゲート 60 秒 / レンダ 3 秒」の食い違いと、
   プレースホルダ尺の流用が同時に消える。
6. **素材登録状況を「採用テイクの素材種別」から導く**。既存 `CutTakeSummaryData.adopted` に種別を足し、
   シナリオ編集の「動画」列に `未登録 / 動画登録済 / 静止画登録済` を出す。
   **新しい述語を作らない** — 「採用テイクがあるか」だけを見る (「採用済みかつ ready か」は
   ドメイン規約 12 により `AdoptedReadyTakeCoverage` の専権であり、そちらには触らない)。

## 期待効果

- **使命への貢献**: 「AI が撮るべきカットを設計し、PWA が撮影を指示する」の一部が、いま静止画だけ**指示できても撮れない**
  状態で切れている。この鎖を繋ぐことで、シナリオ生成が出した `material_type=still` が現場で意味を持つ。
  静止画で足りる手順 (完成状態の確認・銘板・注意ラベル等) を、動画を撮らずに 1 タップで済ませられる。
- **撮影負荷と保存容量の削減**: 静止画で足りるカットで数十 MB の動画を撮って上げる必要がなくなる。
  容量 Quota (`max_storage_bytes`) の消費とアップロード時間が直接減る。
  **レンダ時間の短縮は主張しない** — 静止画も同じく ffmpeg のクリップ 1 本として再エンコードされ、
  サムネイル生成も同様に走るため、短尺動画との差はケース依存である。
- **静止画尺の意味の是正**: 「プレビュー用プレースホルダ尺」を完成動画の静止画尺に流用している現状を解消し、
  尺ゲートとレンダの計算を一致させる (現在は未指定 still でゲート 60 秒 / レンダ 3 秒と食い違っている)。
- **素材登録状況の可視化**: doc/02 §2.4 が定義していて、実装にだけ無かった 3 値が編集者に見える。

## 実装方針（概要）

施策は 7 本。優先度 高 = これが無いと通しで動かない、中 = 通しでは動くが設計の意図が満たされない。

| # | 施策 | 優先度 | 主な変更先 |
|---|---|---|---|
| S1 | `takes.material_type` の新設 (migration / Model / Factory / 分類器) | 高 | `database/migrations`, `app/Models/Take.php`, `database/factories/TakeFactory.php`, `app/Support/Capture/*` |
| S2 | 静止画の presign 受け入れと整合検証 (config / FormRequest / TakeUploadService / TakeRegistrationService) | 高 | `config/capture.php`, `app/Http/Requests/Capture/*`, `app/Services/Capture/*` |
| S3 | 撮影 PWA の静止画撮影 (シャッター + ファイル選択の accept 切替) | 高 | `resources/js/components/features/capture/*`, `resources/js/pages/Capture/Show.svelte`, `types/capture.ts` |
| S4 | 静止画表示秒の単一解決点 (`StillDisplayDuration`) と既定値 config | 中 | `app/Services/Manual/*`, `config/manual.php` |
| S5 | 静止画テイクの表示 (`<img>` 出し分け・サムネイル seek=0) | 中 | `TakeStrip` / `TakePreviewDialog` / PC テイク選択画面, `TakeThumbnailPipeline` |
| S6 | 素材登録状況の表示 (未登録 / 動画登録済 / 静止画登録済) | 中 | `CutTakeSummaryData`, `ScenarioEditor.svelte`, `types/manual.ts` |
| S7 | 通しの回帰テストとドキュメント更新 | 高 | `tests/Feature/**`, `tests/Unit/**`, `docs/architecture.md` |

### レンダ側の入力契約 (S1〜S4 に共通する前提)

`FfmpegVideoComposer::planTakeStill()` は `-i {source} -frames:v 1 frame{n}.png` で**中間 PNG を 1 枚**作り、
それを `-loop 1 -t {秒}` でループさせる。この 1 段目は**入力が画像でも 1 枚の PNG を出す**ため、
動画テイク由来の still と画像テイク由来の still を**同じ経路**で扱える。
「画像なら中間 PNG 化を省く」最適化は**しない** (通る経路を 2 本にすると、片方だけ壊れる形を作る)。

その代わり名前を直す: `RenderClipSpec::$takeVideoPath` を **`$takeSourcePath` へ改名**する。
画像が入るスロットが「動画のパス」と名乗っているのは、機能の名前が役割を表していない状態である。
波及は `RenderPipeline` / `FfmpegVideoComposer` / 該当 Unit テストの 3 か所。

manifest に take の素材種別は**載せない**。composer は素材種別で分岐しないため、載せても使われない情報になる。

### S1: takes の素材種別

- migration: `takes.material_type` を `string` で追加。**既存行は `video` で backfill** し、その後 NOT NULL 化する
  (既存テイクはすべて動画である)。Model の `$fillable` **外** (サーバ確定値) で `casts` に `MaterialType::class`。
- 値の確定は `TakeRegistrationService::finalize()` が予約行の `content_type` から行い、`forceFill` で
  **INSERT 時に明示代入**する (ドメイン規約 1(ii) / 2 と同じ理由: DB default に依存すると migration の default 変更で
  黙って意味が変わり、`save()` 直後の in-memory instance も欠落する)。
- Content-Type → `MaterialType` の写像は **1 クラス 1 メソッド**に閉じる (`match` の網羅)。
  この写像を他所で書き直さないこと。
- `TakeFactory` は既定 `material_type => 'video'` を**明示的に持ち**、`still()` state を足す
  (既存 Factory はすべて動画テイクを作る = 既存テストの意味が変わらない)。
- **既存行はすべて動画である**という前提を `docs/architecture.md` に記録する
  (`allowed_video_content_types` しか presign を通っていないため、S3 上の実体と backfill は一致する)。

### S2: 受け入れ

- `config/capture.php` に `allowed_still_content_types = ['image/jpeg', 'image/png']` と
  `max_still_bytes` を追加。**WebP と HEIC は入れない** (前者は ffmpeg ビルド依存、後者は Safari の既定形式だが
  ffmpeg の既定ビルドで読めないことがある。canvas 経由で JPEG に変換して送るため必要にならない)。
- `StoreTakeUploadUrlRequest` の `content_type` は 動画 ∪ 静止画 の allowlist へ。
  `size_bytes` の上限は種別で切り替える (静止画に 500 MiB を許す必要が無い)。
- **整合検証は Service 側** (`TakeUploadService::issue()` のロック済み cut 再解決の直後) で行う。
  FormRequest は cut を知らないため。`video`/未指定カット + 画像 Content-Type → `ValidationException` (422)。
- `extensionFor()` に `image/jpeg → jpg` / `image/png → png` を追加。
- `StoreCaptureTakeRequest.duration_ms` は静止画では意味を持たない。**登録時にサーバが破棄する**
  (画像テイクの `duration_ms` は常に `null`)。クライアントの申告に依存させない。
- 両 FormRequest に `'material_type' => ['missing']` を足す (payload に存在するだけで 422。
  既存の `video_path` / `size_bytes` / `status` と同じ作法)。
- **422 の返却経路は新設しない**。`TakeUploadService::issue()` は既に manual 状態 guard で
  `ValidationException` を投げており、静止画の不整合も同じ形で返す。`response()->json()` は 1 行も足さない。

### S3: 撮影 PWA

- `CaptureCutData` に cut の `material_type` を足す (PWA が撮影 UI を出し分けるため)。
- `CameraRecorder.svelte` に `mode: "video" | "still"` props を足す。
  **MediaRecorder の phase マシンには一切触れない** — `still` では `phase` は `idle` のまま、
  録画開始ボタンの位置にシャッターボタンを出す。押下時に既存の `<video>` 要素の現在フレームを
  `canvas.drawImage` → `canvas.toBlob('image/jpeg')` で 1 枚取り出し、既存の `onCaptured(blob, 'image/jpeg', null)` へ流す。
  (`ImageCapture` API は iOS Safari が未対応。撮影 PWA の主戦場は iOS Safari であるため canvas 経路を採る。)
  stream 取得・カメラ反転・グリッド・字幕 overlay・カメラ喪失時のフォールバック委譲は**そのまま共用**する
  (別 component に切ると二重管理になる。T186 の `layout` props と同じ「表示と経路を 1 本足すだけ」の作法)。
- **出力の寸法と品質は `resources/js/lib/capture/still-encode.ts` の 1 モジュールに置く**
  (長辺上限 1920 / JPEG 品質 0.85)。**PHP config には置かない** — サーバはこの 2 値をまったく使わず、
  サーバが強制するのは `capture.max_still_bytes` (バイト数) だけである。
  使わない値を config に置いて Inertia props で往復させると、経路を増やして二重管理を作る。
  シャッター経路とファイル正規化経路の**両方がこのモジュールから読む** (component に直書きしない)。
  クライアント既定値 (長辺 1920 / q0.85) の出力は通常 1 MB 未満で、`max_still_bytes` に十分収まる。
  `video.videoWidth/videoHeight` から長辺上限へ縮小してから `toBlob` する
  (端末の実解像度そのままで `toBlob` するとモバイルでメモリ不足になりうる)。
  ライブ映像のフレームには EXIF が無いため、シャッター経路に**向きの問題は無い**。
- `CaptureFileFallback` / PC の `TakeFileUpload` の `accept` を cut の素材種別で切り替える。
  **ファイル選択で画像を選んだ経路には EXIF 向きの問題がある**ため、この経路も
  `<img>` へデコード → canvas → JPEG で**再エンコードしてから**送る。
  - 断定できること: 再エンコード後の JPEG は **EXIF を持たない**ので、サーバ側・ffmpeg 側で
    向きを解釈する必要が無い。寸法上限も同時に効く。
  - **断定しないこと**: 「`<img>` デコード時にブラウザが必ず EXIF 向きを適用する」とは書かない
    (デコード API とブラウザで差がある)。向きが正しく反映されることは
    **Browser lane (Chromium + WebKit の 2 レーン契約) で向き付き fixture を使って確認する事項**として扱う。
  - 正規化に失敗した場合 (デコード不可 / `toBlob` が `null`) は**アップロードせずエラー表示**にする
    (黙って原本を送らない)。
- アップロードキュー (`UploadQueue`) は **無改造**で再利用する (`contentType` を渡す口が既にある)。

### S4: 静止画表示秒の単一解決点

- `config/manual.php` に `default_still_display_seconds => 5` を追加。
- `App\Services\Manual\EffectiveMaterialType::of(Cut $cut, Take $adoptedTake): MaterialType` を新設する。
  **採用テイクは引数で受ける** (クラス内で `adoptedTake` relation を読まない) ので、
  `AdoptedTakeReferenceInventory` の登録件数は増えない (呼び出し側 2 ファイルはどちらも登録済み)。
  この式を他所に書かないことを、既存の単一化 gate と同じ deny-by-default の目録で守る。
- `App\Services\Manual\StillDisplayDuration::secondsFor(Cut $cut): int` を新設する。
  値は `config()->integer()` で読む (PHPStan level 10 で int 確定)。
  **クランプはしない** — 異常値を黙って別の値に変えると設定ミスが隠れるためである。
  `env()` も持たせない (環境ごとに変えてよい運用値ではない)。既定値が編集画面の入力範囲 (1〜60) に
  収まっていることを config のテストで pin する (`ConfigHardeningTest` と同じ、値を固定する作法)。
- 呼び出し側は 2 本:
  - `RenderPipeline::clipSpecFor()`: `Still → RenderClipSource::TakeStill` + `stillDisplaySeconds = secondsFor($cut)` /
    `Video → TakeVideo` + `stillDisplaySeconds = null`
  - `RenderJobService::assertTotalSourceDurationWithinLimit()`: `Still → secondsFor($cut) * 1000` /
    `Video → $take->duration_ms ?? config('manual.render_default_take_duration_ms')`
- `preview_placeholder_seconds` の流用を撤去する (**後方互換の並走を残さない** = 思考原則 3)。
  同 config は本来の用途 (採用テイク欠落 cut のプレースホルダ尺) のまま残る。
- 編集画面は「静止表示秒数 (1〜60)」の説明に既定値を書く (未入力なら N 秒、という事実を押す前に伝える)。

#### doc/02 §2.2 の「ナレーション尺優先」との関係 (明示的な先送り)

doc/02 §2.2 は「静止画の表示秒数がナレーション尺より短ければナレーション尺を優先する」と定める。
**v1 ではこの規則を実装しない。** 理由は次の 2 つで、どちらも v1 スコープの帰結である。

- v1 は **字幕のみで TTS を後回し**にしている (`AGENTS.md` v1 スコープ)。音声トラックは採用テイクの生音だけで、
  ナレーション文には**再生時間という属性が存在しない**。比較対象が無いものと比較する規則は書けない。
- `doc/09` の v1 尺算出も `cut_length = material_ms` / 静止画は `static_display_seconds*1000` であり、
  ナレーション尺を式に含めていない。実装は doc/09 側 (v1 の確定仕様) に従う。

文字数から尺を推定する代用実装は**作らない** (思考原則 2「今必要なものだけ作る」)。
**再検討の条件**は「TTS を導入してナレーション音声の実尺が確定したとき」であり、そのとき
`StillDisplayDuration` が唯一の解決点になっているので、比較規則の追加はこのクラス 1 か所で済む。
この先送りは設計の記録として `docs/architecture.md` に残す。

### S5: 静止画テイクの表示とサムネイル

- **サムネイル生成の対象に静止画テイクを含める** (T183 の対象を広げる)。理由:
  - 含めないと `has_thumbnail=false` になり、テイク一覧が画像だけプレースホルダになる (種別で見た目が割れる)。
  - 代わりに原本をそのまま一覧に貼る案は**採らない**。原本は数 MB あり、一覧に N 枚並べると転送量が跳ねる。
    サムネイル (長辺 640 / JPEG) を通す方が一覧の目的に合う。
  - 既存の `FfmpegTakeThumbnailExtractor` は入力が画像でも動く。ただし既定 seek は 1000ms で、
    画像入力では 1 回目が空振りして 0ms で再試行する分の無駄がある。**静止画テイクは seek=0 で 1 回**にする。
- `CaptureTakeData` / `SelectableTakeData` に take の `material_type` を足し、
  プレビューを `<video controls>` / `<img>` で出し分ける。
- 自動 DL (`AdoptedTakeAutoDownloader`) は blob を保存するだけなので**変更不要**。

### S6: 素材登録状況

- `CutTakeSummaryData` の `adopted` に `material_type` を足す (既に `AdoptedTakeReferenceInventory` へ登録済みのファイルであり、
  新しく `adoptedTake` を参照するファイルは増やさない)。
- シナリオ編集の「動画」列に `未登録` / `動画登録済` / `静止画登録済` を出す。
  判定は `adopted === null` かどうかと `adopted.material_type` だけ。
  **`AdoptedReadyTakeCoverage` の述語 (ready 判定) を再実装しない** (ドメイン規約 12)。

### S7: テストとドキュメント

- ffmpeg は**実バイナリに依存させない**。レンダは既存どおり `Process::fake()`、
  オブジェクトストレージは `FakeTakeObjectStorage` / `FakeRenderObjectStorage` を使う (既存の作法をそのまま踏襲)。
- **cut × take の組み合わせを 1 本にまとめない**。5 ケースを個別に固定する:

  | # | cut.material_type | take.material_type | 期待 |
  |---|---|---|---|
  | C1 | still | still | presign 通過 / 登録 / `TakeStill` + `stillDisplaySeconds` 非 null / サムネイル生成 / `<img>` プレビュー |
  | C2 | still | video | **既存挙動そのまま** (先頭フレーム抽出 / `<video>` プレビュー) |
  | C3 | video | video | **既存挙動そのまま** (`TakeVideo`) |
  | C4 | video または未指定 | still を上げようとする | presign が 422 (容量を消費しない) |
  | C5 | video (採用後に still→video へ編集) | still | **レンダも尺ゲートも実体優先で `Still` 扱い** = 壊れない |

  C5 は入口検証では作れない状態なので、**シナリオ編集で素材種別を戻す**操作を含む Feature テストで固定する。
  C5 では**マニフェストだけでなく尺ゲートの計算値**まで見る
  (`assertTotalSourceDurationWithinLimit` が `duration_ms ?? 60_000` ではなく `secondsFor()*1000` を足すこと)。
- **誤申告の帰結**も固定する: 形式の合わない素材が採用されたレンダは
  **失敗ジョブとして終わる** (壊れた mp4 を出さない / 走りっぱなしにならない)。これは
  「Content-Type は申告であって実体の保証ではない」という非保証に対する観測可能な契約である。
- 通しの Feature テスト: 「still カット → 画像 presign → 登録 → 採用 → render マニフェストが
  `TakeStill` + `stillDisplaySeconds` 非 null」まで。`FfmpegVideoComposer` の Assert を満たす経路であることを固定する。
- `docs/architecture.md` §撮影 PWA / §レンダ に、素材種別の 2 層 (計画 = cut / 実体 = take)、
  非対称な受け入れ規則、ナレーション尺優先の先送りと再検討条件を書く。

## 制約・前提

- **容量 Quota の規約 (AGENTS.md ドメイン規約 2)**: 静止画も**まったく同じ**予約経路を通る。
  `pending`(INSERT 時に明示代入) → `verifying`(CAS) → `completed`/`released`(CAS)。新しい予約状態も別経路も作らない。
  presign 時の `checkAddition` / `occupiedBytes` (pending→used の読み取り順) はそのまま。
- **シナリオ整合の共有ロック規約 (ドメイン規約 1)**: 本施策は `cuts` を書かない
  (`static_display_seconds` の編集は既存 `ScenarioService::save()` のまま)。新しい書き込み経路は増えない。
- **冪等**: `(cut_id, client_take_id)` UNIQUE と チケットの claims 全一致検証はそのまま。素材種別は
  予約行の `content_type` から導くので、チケット偽装で種別を差し替えることはできない。
- **保護キー**: `takes.material_type` は payload から受けない (`$fillable` 外 + FormRequest の `missing` ルール)。
- **保証しないもの (誇張しない)**: `takes.material_type` は**申告 Content-Type からの分類**であり、
  S3 に置かれたバイト列の実際の形式を保証しない。HeadObject 三点照合 (size / Content-Type / checksum) が
  固定するのは「申告どおりのバイト列が置かれたこと」までで、形式そのものは**今も検証していない**
  (これは静止画対応が新しく作る穴ではない — 現在も `video/mp4` と申告して壊れたバイト列を PUT できる)。
  同期の実体検証 (オブジェクト本体の S3 GET + ffprobe) は**採らない**: 登録要求は撮影 PWA が
  オフライン復帰時に一括再送する経路であり、数十 MB のダウンロードとプロセス起動を同期で挟むと
  `registration_in_flight` (409) の窓が大きく開く。費用と失敗様式に見合わない。
  誤申告の帰結は**権限内の自傷** (自分のマニュアルのレンダが失敗する) にとどまり、
  現行設計は既に同じ帰結を受容している (`RenderCompositionException` → `failJob`)。
  この非保証は `docs/architecture.md` に明記する。
- **PHPStan level 10**: 新しい列は enum cast + `@property` 宣言。分類器は `match` の網羅で `never` 分岐を作らない。
- **フロント**: Svelte 5 runes + DS token。アイコンは `@lucide/svelte` (`Camera` / `Image` を使う)。
  component 階層は `features/capture` の中で閉じる。
- **禁止事項 8**: 「素材が静止画のカットでは録画ボタンを disabled」といった作りにしない。
  文脈非該当のボタンは**出さない** (既存の「カメラ反転は idle のときだけ表示」と同じ作法)。

### 型の境界 (DTO / TypeScript)

cut は「未指定」がありうる**計画**で、take は「実際に何が届いたか」なので NOT NULL である。
**同じ型にしない** (同じ型にすると「未指定の take」という存在しない状態を型が許してしまう)。

| DTO | PHP 側の型 | JSON へ出す形 | TypeScript |
|---|---|---|---|
| `CaptureCutData.material_type` | `MaterialType\|null` | `?->value` | `"video" \| "still" \| null` |
| `CaptureTakeData.material_type` | `MaterialType` | `->value` | `"video" \| "still"` |
| `SelectableTakeData.material_type` | `MaterialType` | `->value` | `"video" \| "still"` |
| `CutTakeSummaryData.adopted.material_type` | `MaterialType` | `->value` | `"video" \| "still"` (`adopted` 自体は nullable) |
| `ScenarioStepData.materialType` / `ScenarioPointData.materialType` (既存) | **`?string` → `MaterialType\|null` に狭める** | `?->value` | 変更なし (`"video" \| "still" \| null`) |

enum をそのまま JSON へ流さず、DTO の `toArray()` で `->value` に明示変換する (既存 DTO と同じ作法)。
既存の `ScenarioStepData` / `ScenarioPointData` だけ `?string` のままにしない
(同じ `cuts.material_type` を表す DTO が広い型のままだと、今回足す型付き境界と不整合になる。
入力側 `ScenarioStepInput` は既に `?MaterialType` なので、これで入出力の型が揃う)。
**PHPStan を通すために `string` を維持する、という判断はしない。**

## 実装の積み方

回帰範囲が広いので、同一ブランチ内で次の順に積む (**incremental**)。

1. **サーバ側**: `takes.material_type` / 分類器 / presign の受け入れと 422 / 登録時の明示代入 /
   レンダの実体優先と `StillDisplayDuration` (ここまでで C1〜C5 のサーバ側テストが緑になる)
2. **撮影 PWA**: シャッター / ファイル選択の正規化 / cut 素材種別に応じた出し分け
3. **表示**: `<img>` 出し分け / サムネイルの seek / 素材登録状況バッジ

完了報告は**通しの Feature テストまで緑になってから**行う (途中段階では報告しない)。

## スコープ外

- **TTS とナレーション尺による尺の上書き** (上記のとおり v1 スコープ外。再検討条件を記録して先送り)。
- **撮影者が現場判断で「動画/静止画」を切り替える UI**。doc/04 は素材種別を編集者側の属性として定義しており、
  現場での切り替えはユースケース未定義である。カットの `material_type` を真実源にする。
- **既存の動画テイクを静止画へ変換する機能** / **静止画の切り抜き・回転・トリム** (doc/04 で「今回はユースケース未定義」)。
- **複数枚の静止画をスライドショーにする**。1 カット 1 素材の現行モデルを変えない。
- **HEIC / WebP の受け入れ**。canvas で JPEG 化して送るため不要。
- **サムネイルの容量を Quota の予約対象にすること**。現状どおり事後計上のまま (既存の受容済み挙動を変えない)。

