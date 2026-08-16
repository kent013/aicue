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
