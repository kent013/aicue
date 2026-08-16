# 受入確認記録: 画像ファイル選択経路の EXIF 向き (Browser lane)

**状態: 未実施** (2026-08-16 時点。T192 のマージ時点で 1 件も記録が無い)

## なぜ別建てなのか

概念設計 (`conceptual-design.md` S3) は、ファイル選択で選ばれた画像について次の非対称を明示している。

- **断定できること**: 再エンコード後の JPEG は **EXIF を持たない**ので、サーバ側・ffmpeg 側で
  向きを解釈する必要が無い。寸法上限も同時に効く。
- **断定しないこと**: 「`<img>` デコード時にブラウザが必ず EXIF 向きを適用する」とは書かない
  (デコード API とブラウザで差がある)。

したがって「向き付きの写真をファイル選択でアップロードしたとき、**見た目どおりの向き**で
完成動画に載るか」は、**ブラウザの画像デコード実装に依存する**事項であり、
jsdom の component テストでは原理的に確認できない
(`tests/js/lib/capture/still-encode.test.ts` は canvas を差し替えて契約を固定しており、
実デコーダの向き適用は 1 度も通っていない)。

## 実装側の現状 (確認対象)

- `resources/js/lib/capture/still-encode.ts` の `normalizeStillFile()` が
  `<img>` へデコード → canvas → JPEG 再エンコードを行う (長辺 1920 / q0.85)。
- 呼び出し元は 2 経路: 撮影 PWA の `CaptureFileFallback.svelte` と
  PC の `TakeFileUpload.svelte`。どちらも正規化に失敗したら**原本を送らずエラー表示**する。
- シャッター経路 (`CameraRecorder.svelte` の `shootStill`) はライブ映像のフレームを取るため
  **EXIF が存在せず、向きの問題は無い** (確認対象外)。

## 確認手順 (未実施)

Browser lane (Chromium + WebKit の 2 レーン契約) で行う。

1. EXIF Orientation = 6 (右 90 度回転指定) を持つ横長 JPEG の fixture を用意する
   (画素の並びは横長、表示は縦長になるもの)。
2. 静止画カット (`cuts.material_type = still`) を選び、
   - 撮影 PWA: カメラ非対応環境へ倒して `capture-file-input` から fixture を選ぶ
   - PC: テイク選択画面の `take-file-input` から fixture を選ぶ
3. アップロード要求のボディ (正規化後の JPEG) を取り、**縦横比が表示どおり**になっていることを
   確認する (画素の並びのままなら向きが適用されていない)。
4. Chromium / WebKit の**両方**で 2〜3 を行い、結果を下表へ記録する。

## 結果

| 日付 | ブラウザ | 経路 | 期待 | 実測 | 判定 |
|---|---|---|---|---|---|
| — | — | — | — | — | **未実施** |

## 引き継ぎ

T192 は**この確認を待たずにマージした** (自動テストは全レーン green、Codex 実装レビューは
Round 3 で APPROVED、コードへの未対応指摘は 0 件)。未達分は別 TODO が引き継ぐ。
