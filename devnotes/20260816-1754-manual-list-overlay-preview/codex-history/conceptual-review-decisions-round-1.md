# 対応マトリクス: conceptual-review Round 1

Codex 全体判定: **APPROVED** (Round 1)。Critical は 0 件。Warning 3 件・Suggestion 4 件。
APPROVED のため合議ループはここで終了し、Warning は**概念設計に反映してから** Phase 2 へ進む。

## [Warning] 禁止事項 8 の退行 (不可の行でボタンだけ disabled にする実装が入りやすい)
- 判断: **対応する**
- 根拠: 現行 `ManualListRow` は「サーバが可と判断した行にだけ導線を出す」形で既に固定されており、
  プレビュー導線も同じ形にしないと 2 通りの流儀が並ぶ。機械で赤くする方が確実。
- 対応内容: 概念設計の「制約・前提」に、Vitest で
  「`current_finished_render_job_id === null` のときプレビュー / DL の要素が DOM に存在しない」
  「どちらの導線も `disabled` 属性を持たない」を固定する旨を明記した (詳細設計のテスト計画に落とす)。

## [Warning] `finished_render_job_id` という名前が曖昧 (「完了ジョブが存在する」とだけ読める)
- 判断: **対応する**
- 根拠: 指摘のとおり。この値の意味は「完了した job があるか」ではなく
  **「いま受け取れる完成動画 (現行世代・published・download ability) の render job id」**である。
  名前は機能が果たす役割を示すべき (思考原則)。
- 対応内容: props 名を **`current_finished_render_job_id`** (PHP プロパティ
  `currentFinishedRenderJobId`) に変更した。`current` が「現行世代であること」、
  `finished` が「完成動画 (kind=render の成果物)」を指す。
  併せて PHPDoc / TS コメントに「**非 null は旧 `downloadable === true` と完全に同値**
  (download endpoint が 302 を返す条件と 1 対 1)」を明記する旨を概念設計へ書いた。

## [Warning] DTO / Controller PHPDoc / TS 型 / Svelte props の同期漏れが主リスク
- 判断: **対応する**
- 根拠: 置換 (rename) は「片方だけ残る」形の事故が起きうる。既存の
  `ManualRowDownloadableParityTest` が endpoint との整合を behavioral に固定している資産があるので、
  同じ場所で新 props の整合も固定するのが自然。
- 対応内容: 概念設計のテスト方針に次を追加した (詳細設計で具体化する):
  1. Feature: 行 props に **`downloadable` キーが存在しないこと**を固定 (旧キーの残置を赤くする)
  2. Feature: `current_finished_render_job_id` が「published × 現行世代 × output_path あり ×
     download ability」で id、欠けたら null (撮影者 = null) を固定
  3. Feature: 非 null の行の id は playback endpoint が 302 を返す id と一致し、
     旧世代 id 直叩きは 404 になることを固定 (parity テストを拡張)

## [Suggestion] 使命との整合 / 実現可能性 / 期待効果 / スコープの妥当性 (いずれも肯定)
- 判断: **反映不要 (同意のみ)**
- 根拠: 指摘ではなく現行方針の追認。設計変更なし。
