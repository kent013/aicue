# 実査ブリーフ: プレビューと完成動画生成の判断基準を揃える (F-1-01)

> bug-hunt run `20260811-003230` の finding F-1-01 (**High**) に対応する。
> 統合レポート: `devnotes/20260811-003230-bug-hunt/report.md`
> シャード詳細: `devnotes/20260811-003230-bug-hunt/shard-1/shard-report.md#F-1-01`

## 実ブラウザで確認された症状 (再現済み)

67 カットのうち **1 カットだけテイクを採用**した状態で「プレビュー生成」を押すと、
**約 201 秒の全編黒画面の動画が警告なしで生成完了**する (ナレーション・字幕は乗る)。

一方、姉妹機能の **完成動画生成 (`projects.manuals.render`) は同じ状態を 422 で明示ブロック**し、
未採用カットを列挙して知らせる。**同じ前提条件に対して片方は止め、片方は黙って壊れた成果物を出す**。

- species: `validation_gap:manual_preview:create:self`
- oracle: `consistency_with_sibling_render_validation`
- 証跡: サーバ生成 mp4 を **ffprobe + フレーム抽出**で確認済み。
  **ブラウザ側のコーデック問題ではない** (Chromium の H.264 再生制限は別事象として finding から除外されている)。
  `devnotes/20260811-003230-bug-hunt/shard-1/screenshots/F-1-01-render-blocks-preview-does-not.png` /
  `F-1-01-preview-blackframe-t30s.png`

## 阻害されているユーザージョブ

**制作途中で仕上がりを確認する**。プレビューは本来そのための機能で、チケットも消費しない (無料) 設計である。
黒画面を見たユーザーが「アプリか AI が壊れている」と受け取るのは自然で、**中核体験の信頼を損なう**。

## 設計で決めるべきこと

1. **プレビューをブロックするのか、警告に留めるのか**。
   プレビューは「途中経過を見る」機能なので、**未撮影があること自体は正常な状態**である。
   render と同じく 422 で止めると、プレビュー本来の用途 (途中で確認する) が使えなくなる恐れがある。
   一方、黙って黒画面を出すのは現状のとおり有害。**第三の道 (生成はするが事前に知らせる) が妥当か**を判断する。
2. **どこで判定するか**。`render` 側の既存の検証ロジックを再利用できるか、
   それとも preview 固有の判定が要るか。**判断基準を 1 箇所に置く**のが要点 (2 箇所に散ると再び乖離する)。
3. **黒画面そのものの扱い**。`FfmpegVideoComposer` は素材未撮影時に黒背景プレースホルダを入れる設計になっている。
   これは**意図的な仕様**なので、プレースホルダ自体を消すのではなく
   「プレースホルダが大半を占めることをユーザーが事前に知る」形にするのが筋と思われる。ただし設計者が判断してよい。
4. **再発防止**。「preview と render で判断基準が乖離しない」ことを機械で守れるか。
   守れるなら Architecture テストで固定する。守れないなら「保証しないもの」に書く。

## 読むべき現行コード

- `app/Services/Manual/RenderPipeline.php` (scenario_version 固定 → DL → compose → upload → published)
- `app/Services/Render/FfmpegVideoComposer.php` (黒背景プレースホルダの実装)
- `projects.manuals.preview` / `projects.manuals.render` の Controller と FormRequest
- `resources/js/components/features/manual/RenderPanel.svelte` (プレビュー/完成の UI)
- `app/Models/Cut.php` の `adopted_take_id`

## やらないこと

- **黒背景プレースホルダの仕組みそのものを消さない** (未撮影カットがあっても合成できるのは意図的な設計)。
- 品質評価 (生成された動画の中身が良いか) は扱わない。
