# 対応マトリクス: design-review Round 2

## [Warning] 施策 2 に旧仕様 `playbackJobId` が残っている
- 判断: 対応する
- 根拠: 指摘のとおり。施策 3 で置換すると宣言しながら施策 2 の Controller 例と
  `RenderProps` に旧キーが残っており、実装者がどちらを正本か判断できない。
- 対応内容: 施策 2 の Controller 例・`RenderProps` を `playbackJob: RenderJobProps | null` に統一。
  旧キーは設計書のどこにも残さない (「施策 3 で置換」と注記のみ)。

## [Warning] 告知文が述語の意味を正確に表していない (非 ready ケース)
- 判断: 対応する (**設計上の正確性の問題**であり、文言の好みではない)
- 根拠: `TakeStatus` は `uploading / processing / ready / failed` の 4 値。述語は
  `adoptedTake === null || status !== Ready` なので、**採用済みだがアップロード中・処理中・失敗**の
  カットも missing に数える。「未撮影」「テイクが採用されていません」は嘘になりうる。
  「自然言語はテストしない」は不正確な断定を許す理由にならない (指摘に同意)。
- 対応内容: 事前告知を
  「**プレビューに黒背景の区間があります** / {n}/{total} 件のカットに、撮影・処理が完了した
  採用テイクがありません」に変更。事後説明も
  「{n} 件のカットに**使用できる採用テイクがない**ため、その区間が黒背景になっています」に変更。
  設計書に「述語の意味をそのまま言う」原則と `TakeStatus` の 4 値を明記。
  テスト名の「未撮影」表現も `missing` ベースへ改め、A-6 は uploading/processing/failed の
  3 状態で検証すると明示。

## [Warning] `playbackJob` は nullable なので再生ブロック全体の null 検査を設計例に明示せよ
- 判断: 対応する
- 対応内容: `{#if playbackJob !== null && !previewInFlight}` のブロック内に注記と `<video>` を
  置く形へ設計例を修正 (既存の表示条件も維持)。

## [Warning] 検証コマンドが AGENTS.md の VERIFICATION_COMMANDS と同期していない
- 判断: 対応する
- 対応内容: `pnpm typecheck:packages` / `pnpm build:packages` / `pnpm test:packages` を追加し、
  「AGENTS.md の VERIFICATION_COMMANDS 全量 + `composer test:browser` (UI 変更のため必須)」と明記。

## [Suggestion] 施策 1 / 4 / 5・Round 1 対応分は APPROVE
- 判断: 変更なし
