## 使命 (North Star)

<!-- app-codex-review スキルはこのセクションをレビュー使命として参照する。 -->

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(OJT を撮って形式化する tebiki)と異なり、**標準作業を起点に AI が教材設計し撮影を指示する**（撮影者・教える人のスキルに品質を依存させない）。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。

> 詳細な設計は本リポジトリの `doc/`（01〜10）に置く。ドメインをテンプレート構造へどうマップしたかは `doc/08`〜`doc/10`（特に `doc/10 §10.8` に設計レビュー反映済み）。
> **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告(不変条件は対応する Architecture/Feature テストへの登録まで含めて「実装済み」)
2. PHPStan エラーの widen(型を緩めて黙らせる)・baseline 化
3. dev DB への破壊操作(`migrate:fresh` 等)をエージェント判断で実行すること
4. `response()->json()` の直書き(DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外)
5. LLM 呼び出しの Prism 直呼び(`app/Prompts/` の factory → 窓口 (`PromptDefense`) →
   実行単位 (`GuardedPrompt`) の**1 本道のみ**。`PromptGuardrailTest` が
   app/ routes/ database/ config/ bootstrap/ の 5 走査根で検出する)。
   **実行経路を持つ prompt factory は `LlmCallContextData` を必須引数で受け、
   `PromptDefense::load()` へ渡して帰属 (organization / subject) を付ける** — 付け忘れは
   PHPStan level 10 が落とす。帰属の対象を持たない見本 (`ExampleSummaryPrompt`) だけが
   `PromptDefense::loadUnattributed()` を使え、窓口 gate が**この 1 件を名指しで pin** する。
   併せて `PromptUntrustedInputContractTest` の inventory へ**帰属キーを空配列で exempt 登録**する
   (deny-by-default なので exempt にする操作がレビューで必ず見える)。
   欠けると `llm_call_logs.metadata_missing` になり組織別・対象別の費用が出せない
6. prompt 文字列のコード直書き(`resources/prompts/*.yaml` に置く)
7. 操作系 POST の応答での `redirect()->intended()`(ログイン直後フロー専用。
   招待送信等は `back()->with(...)` で完結させる)
8. 必須条件未充足を理由にボタンを disabled にする UI(押下時にエラー表示する。DESIGN.md)
9. Artifact の使用(Artifact ツールでの成果物公開を行わない。成果物はリポジトリ内のファイルとして出力する)

```
【思考原則 — 全議論に適用】
まず仮説を立てろ。何を検証したいのか、なぜそう考えるのか、どうなれば成功と判断するのかを明確にしてから手を動かせ。仮説なき改善はただの試行錯誤であり、結果から学ぶことができない。

データに真摯に向き合え。成果だけでなく、多様性の変化、構造の揺らぎ、想定外のパターン — 全てが判断材料になる。数値を見て即座に閾値を弄るな。何が起きているのかを理解し、なぜそうなったのかを考え、どの方向に進むべきかを判断してから手を動かせ。

先人の知恵を探せ。自分たちだけで登る必要はない。乗るべき巨人の肩があるなら乗れ。

機能の名前に立ち返れ。名前はその機能が果たすべき役割を示している。現在の設計がその役割を果たしているか、常に問え。

仕組みが機能していない段階で値を弄るな。閾値チューニングやフィールド追加は、設計の方向性が正しいと確認できてから行え。方向性が間違っているなら、値をいくら調整しても意味はない。設計そのものを見直せ。成果が出なければ早期に見切り、次の仮説へ進め。

【ツール使用制限】
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。
```

あなたはWebアプリケーション（Laravel + Svelte）の改善に関する概念設計レビュアーです。

【レビュー観点】
1. 使命との整合性: この改善はアプリの使命（North Star）に本質的に貢献するか
2. 禁止事項違反: 上記禁止事項に抵触していないか
3. 実現可能性: 技術的に実現可能か（Laravel 12 + Svelte 5 + Inertia.js）
4. 期待効果の妥当性: 主張している効果は合理的に期待できるか
5. リスク: 重大な副作用・後退の可能性はないか
6. スコープの適切さ: 過大または過小になっていないか
7. **型安全性**: DTO/JsonResourceパターンに沿っているか。PHPStan level 10を通せるか

【出力形式】
- 全体判定: APPROVED / CHANGES_REQUESTED
- 各観点ごとに [Critical] [Warning] [Suggestion] で分類して指摘
- Critical/Warning には修正提案を必ず添える
- 日本語で出力

---

## 概念設計

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
4. **静止画の表示秒の決定規則を 1 か所へ集約**する。編集者が `static_display_seconds` を指定でき、
   未指定なら `config('manual.default_still_display_seconds')` (新設・既定 5 秒) を使う。
   この解決式を持つクラスを 1 つ作り、`RenderPipeline` と `RenderJobService` の両方がそれを呼ぶ
   (現在の二重管理と、プレースホルダ尺の流用を同時に解消する)。
5. **素材登録状況を「採用テイクの素材種別」から導く**。既存 `CutTakeSummaryData.adopted` に種別を足し、
   シナリオ編集の「動画」列に `未登録 / 動画登録済 / 静止画登録済` を出す。
   **新しい述語を作らない** — 「採用テイクがあるか」だけを見る (「採用済みかつ ready か」は
   ドメイン規約 12 により `AdoptedReadyTakeCoverage` の専権であり、そちらには触らない)。

## 期待効果

- **使命への貢献**: 「AI が撮るべきカットを設計し、PWA が撮影を指示する」の一部が、いま静止画だけ**指示できても撮れない**
  状態で切れている。この鎖を繋ぐことで、シナリオ生成が出した `material_type=still` が現場で意味を持つ。
  静止画で足りる手順 (完成状態の確認・銘板・注意ラベル等) を、動画を撮らずに 1 タップで済ませられる。
- **容量とレンダ時間の削減**: 静止画で足りるカットで数十 MB の動画を撮って上げる必要がなくなる。
  容量 Quota (`max_storage_bytes`) の消費が直接減る。
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

### S1: takes の素材種別

- migration: `takes.material_type` を `string` で追加。**既存行は `video` で backfill** し、その後 NOT NULL 化する
  (既存テイクはすべて動画である)。Model の `$fillable` **外** (サーバ確定値) で `casts` に `MaterialType::class`。
- 値の確定は `TakeRegistrationService::finalize()` が予約行の `content_type` から行い、`forceFill` で
  **INSERT 時に明示代入**する (ドメイン規約 1(ii) / 2 と同じ理由: DB default に依存すると migration の default 変更で
  黙って意味が変わり、`save()` 直後の in-memory instance も欠落する)。
- Content-Type → `MaterialType` の写像は **1 クラス 1 メソッド**に閉じる (`match` の網羅)。
  この写像を他所で書き直さないこと。

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

### S3: 撮影 PWA

- `CaptureCutData` に cut の `material_type` を足す (PWA が撮影 UI を出し分けるため)。
- `CameraRecorder.svelte` に `mode: "video" | "still"` props を足す。
  **MediaRecorder の phase マシンには一切触れない** — `still` では `phase` は `idle` のまま、
  録画開始ボタンの位置にシャッターボタンを出す。押下時に既存の `<video>` 要素の現在フレームを
  `canvas.drawImage` → `canvas.toBlob('image/jpeg')` で 1 枚取り出し、既存の `onCaptured(blob, 'image/jpeg', null)` へ流す。
  (`ImageCapture` API は iOS Safari が未対応。撮影 PWA の主戦場は iOS Safari であるため canvas 経路を採る。)
  stream 取得・カメラ反転・グリッド・字幕 overlay・カメラ喪失時のフォールバック委譲は**そのまま共用**する
  (別 component に切ると二重管理になる。T186 の `layout` props と同じ「表示と経路を 1 本足すだけ」の作法)。
- `CaptureFileFallback` / PC の `TakeFileUpload` の `accept` を cut の素材種別で切り替える。
- アップロードキュー (`UploadQueue`) は **無改造**で再利用する (`contentType` を渡す口が既にある)。

### S4: 静止画表示秒の単一解決点

- `config/manual.php` に `default_still_display_seconds => 5` を追加。
- `App\Services\Manual\StillDisplayDuration::secondsFor(Cut $cut): int` を新設し、
  `RenderPipeline::clipSpecFor()` と `RenderJobService::assertTotalSourceDurationWithinLimit()` の両方が呼ぶ。
  `preview_placeholder_seconds` の流用を撤去する (**後方互換の並走を残さない** = 思考原則 3)。
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
- **PHPStan level 10**: 新しい列は enum cast + `@property` 宣言。分類器は `match` の網羅で `never` 分岐を作らない。
- **フロント**: Svelte 5 runes + DS token。アイコンは `@lucide/svelte` (`Camera` / `Image` を使う)。
  component 階層は `features/capture` の中で閉じる。
- **禁止事項 8**: 「素材が静止画のカットでは録画ボタンを disabled」といった作りにしない。
  文脈非該当のボタンは**出さない** (既存の「カメラ反転は idle のときだけ表示」と同じ作法)。

## スコープ外

- **TTS とナレーション尺による尺の上書き** (上記のとおり v1 スコープ外。再検討条件を記録して先送り)。
- **撮影者が現場判断で「動画/静止画」を切り替える UI**。doc/04 は素材種別を編集者側の属性として定義しており、
  現場での切り替えはユースケース未定義である。カットの `material_type` を真実源にする。
- **既存の動画テイクを静止画へ変換する機能** / **静止画の切り抜き・回転・トリム** (doc/04 で「今回はユースケース未定義」)。
- **複数枚の静止画をスライドショーにする**。1 カット 1 素材の現行モデルを変えない。
- **HEIC / WebP の受け入れ**。canvas で JPEG 化して送るため不要。
- **サムネイルの容量を Quota の予約対象にすること**。現状どおり事後計上のまま (既存の受容済み挙動を変えない)。

