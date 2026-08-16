# 対応マトリクス: conceptual-review Round 1

## [Critical] 観点3: 画像ファイル入力を `TakeStill` 経路で扱える保証が不足 / manifest の source path・content type の差分
- 判断: **対応する (ただし分岐は増やさない方向で)**
- 根拠: 現行 `FfmpegVideoComposer::planTakeStill()` は `-i {source} -frames:v 1 frame{n}.png` で中間 PNG を作ってから
  `-loop 1 -t {秒}` する。この 1 段目は**入力が画像でも 1 枚の PNG を出す**ため、動画テイク由来と画像テイク由来を
  同じ経路で扱える。よって「画像なら中間 PNG 化を省く」最適化は**やらない** (通る経路を 2 本にする方が危険)。
- 対応内容:
  - 「入力契約 = 1 枚目のフレームを取り出せるあらゆる入力」を設計に明記し、`planTakeStill()` の docblock を直す。
  - `RenderClipSpec::$takeVideoPath` を **`$takeSourcePath` へ改名**する (名前が「動画のパス」と言っているのに
    画像が入る = 機能の名前に立ち返れ)。波及は `RenderPipeline` / `FfmpegVideoComposer` / Unit テスト。
  - manifest に take の素材種別は**載せない**。載せても composer が分岐しないため (載せると使われない情報になる)。
  - Unit テスト: 画像キー (`.jpg`) を source に持つ `TakeStill` clip で、動画キーのときと**同一の引数列**が組まれることを固定。

## [Critical] 観点5: cut × take の組み合わせ分岐をテスト 1 本にすると漏れる
- 判断: **対応する**
- 根拠: 指摘のとおり。4 組み合わせは意味がそれぞれ違う。
- 対応内容: 4 ケースを個別に固定する計画へ書き換え。あわせて**5 つ目の穴**を自分で見つけたので下に書く
  (「採用後に cut.material_type を編集で video へ戻す」経路)。

## [Critical] 観点7: DTO / TypeScript の enum・nullable 境界が未確定
- 判断: **対応する**
- 根拠: 指摘のとおり。cut は未指定あり (nullable)、take は NOT NULL で、同じ型にすると
  「未指定の take」という存在しない状態を型が許してしまう。
- 対応内容: DTO ごとに型を確定して表で書く。
  - `CaptureCutData.material_type`: `MaterialType|null` → TS `"video" | "still" | null`
  - `CaptureTakeData.material_type` / `SelectableTakeData.material_type`: `MaterialType` → TS `"video" | "still"`
  - `CutTakeSummaryData.adopted.material_type`: `MaterialType` → TS `"video" | "still"` (`adopted` 自体が nullable)
  - PHP 側は `->value` で明示的に文字列化する (enum をそのまま JSON へ流さない)。

## [Warning] 観点2: `material_type` を payload から受けないことをテストで固定せよ
- 判断: **対応する**
- 対応内容: `StoreTakeUploadUrlRequest` / `StoreCaptureTakeRequest` の両方に `'material_type' => ['missing']` を足し、
  422 になることを Feature テストで固定する (既存の `video_path` / `size_bytes` の `missing` と同じ作法)。

## [Warning] 観点2: 422 の返却経路が既存規約に沿うか未記載
- 判断: **対応する (記述の追加のみ)**
- 根拠: `TakeUploadService::issue()` は既に manual 状態 guard で `ValidationException` を投げており、
  新設ではなく**既存経路と同じ形**である。`response()->json()` は 1 行も足さない。
- 対応内容: 制約節に明記。

## [Warning] 観点3: 採用時の整合検証が無い
- 判断: **一部反論し、より根本の穴を塞ぐ**
- 根拠: 採用時に閉じても穴は残る。**採用した後に編集者がシナリオ編集で `material_type` を `still` → `video` に
  変更できる**ため、「video カットに still テイクが採用済み」という状態は採用検証では防げない。
  ここでレンダが壊れる (`planTakeVideo` が ffprobe で尺を測るため、画像入力では尺が取れない)。
- 対応内容: **レンダのクリップ種別の決定を「実体優先」にする** —
  `cut.material_type === Still` **または** `take.material_type === Still` のいずれかが真なら `TakeStill`。
  画像を動画クリップとして合成する道が構造的に消えるので、以後どの経路から不整合が入っても壊れない。
  この上で採用 API では**拒否しない** (詰ませない。禁止事項 8 の精神。素材登録状況の表示で編集者が気づける)。
  入口 (presign) だけは 422 で止める — 指示と違う素材で容量を消費させないため。
  この非対称は既存の「render は 422 でブロック / preview はブロックしない」(ドメイン規約 12) と同じ作法である。

## [Warning] 観点3: 既存 take の backfill 前提を docs と factory に残せ
- 判断: **対応する**
- 対応内容: migration は `video` backfill → NOT NULL 化。`TakeFactory` は既定 `video` を**明示的に**持ち、
  `still()` state を足す。前提は `docs/architecture.md` に記録。

## [Warning] 観点4: レンダ時間削減の主張が過大
- 判断: **対応する**
- 対応内容: 期待効果から「レンダ時間」を落とし、主効果を「撮影負荷」「アップロード容量 / 保存容量」に限定する。

## [Warning] 観点5: canvas JPEG 化の解像度・quality・EXIF 向き・メモリ
- 判断: **対応する**
- 対応内容:
  - 出力の長辺上限と JPEG quality を `config/capture.php` に置く (既定 長辺 1920 / quality 0.85)。
  - ライブ映像フレームには EXIF が無いのでシャッター経路に向きの問題は無い。
  - **ファイル選択で画像を選んだ経路にだけ EXIF 向きの問題がある**ため、その経路も
    `<img>` デコード → canvas → JPEG で**正規化してから**送る (向きの正規化と寸法上限が同時に効く)。
    結果としてサーバへ届く画像は常に「向き正規化済み・寸法上限内の JPEG」1 種類になる。

## [Warning] 観点6: スコープが 1 PR としてやや大きい
- 判断: **対応する (実装順序として明記)**
- 対応内容: 実装モードを incremental とし、(1) サーバ (schema / 受け入れ / レンダ) →
  (2) PWA 撮影 → (3) 表示・サムネイル の順で積む。完了報告は通しテストまで終えてから行う。

## [Warning] 観点7: config 値の型・範囲検証
- 判断: **対応する**
- 対応内容: `StillDisplayDuration` は `config()->integer()` で読み (PHPStan level 10 で int 確定)、
  **1〜60 にクランプ**して返す。既定値が範囲内であることを config のテストで pin する。

## [Suggestion] 使命整合 / TTS 先送り / 効果の一部
- 判断: 反映不要 (肯定的評価)
