全体判定: **CHANGES_REQUESTED**

概念の方向性は North Star に合っています。特に「cut は計画、take は実体」と分け、PWA の撮影指示から受け入れ、レンダ、表示まで素材種別を通す設計は妥当です。ただし、保存・採用・レンダ境界での型と不変条件がまだ詰め切れておらず、このまま実装に入ると画像テイクが既存動画前提の経路へ混入するリスクがあります。

## 1. 使命との整合性

[Suggestion] 静止画カットを PWA で直接撮れるようにする改善は、「AI が撮るべきカットを設計し、スマホでナビゲーション撮影する」という使命に本質的に貢献します。

[Suggestion] 「撮影者が動画/静止画を現場判断で切り替えない」というスコープ判断も妥当です。品質を撮影者の判断に依存させない North Star と整合しています。

## 2. 禁止事項違反

[Warning] `takes.material_type` を `$fillable` 外にする方針は良いですが、「FormRequest の `missing` ルール」と書くだけでは不十分です。  
修正提案: `StoreCaptureTakeRequest` / presign 系 request の両方で、payload 由来の `material_type` を受けないことをテストで固定してください。Mass assignment だけでなく、DTO 変換層にも入り込まないことを Feature/Architecture 側で確認する必要があります。

[Warning] 静止画受け入れで `ValidationException` を Service から投げる設計は実現可能ですが、API 応答形式が DTO/JsonResource/Inertia の既存規約に沿うかが未記載です。  
修正提案: 422 の返却経路が既存の FormRequest エラー表示と同じ形になること、`response()->json()` を新設しないことを明記してください。

## 3. 実現可能性

[Critical] 静止画テイクを `TakeStill` としてレンダへ渡す際、現在の `RenderClipSource::TakeStill` が「動画テイクの先頭フレームを静止画化する」前提なら、画像ファイル入力を同じ経路で扱える保証が不足しています。  
修正提案: `RenderPipeline` / `FfmpegVideoComposer::planTakeStill()` の入力契約を明確化し、`takes.material_type=still` の場合は画像ファイルを still source として扱えることを Unit テストで固定してください。動画テイク由来の still と画像テイク由来の still を同じ `TakeStill` に載せるなら、manifest に必要な source path / content type / extension の差分も設計に含めるべきです。

[Warning] `video` / 未指定カット + 画像を 422 にする方針は妥当ですが、「採用」時の整合検証が未記載です。presign 時だけ閉じても、既存データ、管理画面アップロード、将来の経路で不整合 take が採用される可能性があります。  
修正提案: 採用処理でも `cut.material_type` と `take.material_type` の整合を検証してください。最低限、`video/null cut` に `still take` を採用できないことを Feature テストで固定する必要があります。

[Warning] 既存行をすべて `video` backfill する判断は現状事実と整合しますが、既存 object の content type と食い違った場合の検出方針がありません。  
修正提案: migration は `video` backfill でよいですが、運用上の前提として「既存 take はすべて動画」を architecture docs に残し、テスト fixture/factory も明示的に `video` を持つよう更新してください。

## 4. 期待効果の妥当性

[Suggestion] 容量削減、撮影負荷削減、静止画尺の意味是正は合理的に期待できます。

[Warning] レンダ時間削減は常に成立するとは限りません。静止画のサムネイル生成、JPEG 変換、ffmpeg 画像入力処理が追加されるため、短尺動画との差が小さいケースもあります。  
修正提案: 期待効果は「アップロード容量と撮影負荷の削減」を主効果にし、「レンダ時間削減」は副次的・ケース依存として弱めてください。

## 5. リスク

[Critical] `still` カットで画像も動画も受ける非対称設計は既存互換として妥当ですが、`takes.material_type` と `cuts.material_type` の組み合わせがレンダ・プレビュー・サムネイルで分岐増加します。ここを通しのテスト 1 本だけにすると漏れます。  
修正提案: 少なくとも以下を分けて固定してください。

- still cut + still take: 画像プレビュー、サムネイル、render manifest
- still cut + video take: 既存の先頭フレーム静止画化
- video cut + video take: 既存挙動維持
- video/null cut + still take: presign または採用で拒否

[Warning] PWA の canvas JPEG 化は iOS Safari 対応として現実的ですが、EXIF 向き・解像度・メモリ使用量への言及が不足しています。  
修正提案: canvas 出力サイズの上限、JPEG quality、縦横向きの扱いを設計に追加してください。巨大解像度のまま `toBlob` するとモバイルで落ちる可能性があります。

## 6. スコープの適切さ

[Warning] S1〜S7 は通しで必要ですが、1 PR のスコープとしてはやや大きいです。特に PWA 撮影、presign、takes schema、レンダ、サムネイル、編集画面表示まで含むため、回帰範囲が広いです。  
修正提案: 実装単位を分けるなら、同一ブランチ内でも以下の順で段階化してください。

1. サーバ側の型追加・受け入れ・レンダ整合
2. PWA 静止画撮影とアップロード
3. プレビュー/サムネイル/編集画面表示

ただし、ユーザーに見える完成報告は通しの Feature テストまで終えてからにしてください。

[Suggestion] TTS/ナレーション尺優先を先送りする判断は妥当です。v1 の `cut_length = material_ms` に従う説明も筋が通っています。

## 7. 型安全性

[Critical] DTO 追加範囲の記述が不足しています。`CaptureCutData`, `CaptureTakeData`, `SelectableTakeData`, `CutTakeSummaryData` に enum を足すだけでは、Svelte 側の union 型、nullable の扱い、既存 JSON shape の後方互換が不明です。  
修正提案: PHP DTO では `MaterialType` enum を文字列へ明示変換し、TypeScript では `'video' | 'still' | null` のどれを許すかを DTO ごとに固定してください。特に `cuts.material_type` は未指定があり得る一方、`takes.material_type` は NOT NULL なので、同じ型にしない方が安全です。

[Warning] `StillDisplayDuration::secondsFor(Cut $cut): int` は良い集約ですが、config 値の型検証が未記載です。  
修正提案: `config('manual.default_still_display_seconds')` を直接 int 前提で使わず、範囲 1〜60 の検証または fail-fast テストを追加してください。PHPStan level 10 を通すためにも、config 値を `is_int` 確認してから返す実装にしてください。

結論として、設計の核は承認可能ですが、**画像テイクが既存動画前提の経路へ混ざらない保証**と、**DTO/TypeScript の nullable/enum 境界**を補強してから実装に進むべきです。