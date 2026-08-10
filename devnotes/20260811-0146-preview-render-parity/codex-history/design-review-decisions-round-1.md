# 対応マトリクス: design-review Round 1

## [Warning] 施策 3: `playbackJobId` だけでは事後説明が実再生動画と結び切れない
- 判断: **対応する (ただし提案より強い形で)**
- 根拠: 指摘は正しい。`playbackJobId` (最新 succeeded preview) と `previewJob` (最新 preview) は
  別クエリ・別世代になりうるため、説明の出所が動画の出所と一致しない。
  ただし提案どおり `playbackJob` を**足す**と `playbackJobId` と 2 つの出所が並走する
  (思考原則 3「後方互換の並走を残さない」に反する)。
- 対応内容: props の `playbackJobId: number | null` を **`playbackJob: RenderJobProps | null` へ
  置き換える** (追加ではなく置換)。RenderPanel のローカル state も `playbackJob` にし、
  動画 URL (`playbackJob.id`) と注記 (`playbackJob.placeholder_cut_count`) が
  **必ず同一オブジェクト**から出るようにする。ポーリングの preview 成功分岐も
  `playbackJob = body` に変更 (同一オブジェクト性が生成直後にも成り立つ)。
  → 施策 3 の「別世代なら黙る」条件分岐は**不要になり削除**する (穴ごと消える)。
  テスト D-6 は「注記は再生中の動画の job から出る」へ書き換える。

## [Warning] 施策 3: `RenderJobFactory` の既定 null だけでは app 生成後の契約を取り逃がす
- 判断: 対応する
- 根拠: `succeeded(string $outputPath)` state が既にあり、これが null のままだと
  「アプリが作った succeeded」と「本変更前から在る legacy 行」を fixture で区別できない。
- 対応内容: `succeeded(string $outputPath, int $placeholderCutCount = 0)` に変更し、
  **legacy 行専用の `legacySucceeded(string $outputPath)`** (= `placeholder_cut_count` を null)
  を別 state として追加する。UI の null 分岐テストは後者を使う。

## [Warning] テスト計画: Browser lane E-2 でクリックすると副作用に進みうる
- 判断: 対応する
- 根拠: Browser lane は実 ffmpeg / storage を持たない前提で走る。押下可能性の検証に
  クリックは不要 (`disabled` 属性の不在と可視性で足りる)。
- 対応内容: E-2 を「**クリックしない**。`disabled` 属性の不在・`aria-disabled` の不在・
  可視であることを assert する」に限定し、その旨を設計に明記する。

## [Warning] テスト B-1 は「manifest 由来であること」が核なので fixture を明示せよ
- 判断: 対応する
- 対応内容: B-1 の fixture を明示 —
  「n カット中 k カットが未採用 (または採用テイクが ready でない) の manual に対し
  preview job を作り `RenderPipeline::run()` を実行 → `render_jobs.placeholder_cut_count` が
  **k と一致**する」。あわせて `RenderManifest::placeholderCutCount()` の単体検証を
  `tests/Unit/Render/` 側に置き、clips からの導出であることを固定する。

## [Suggestion] 施策 1/2/4/5 は APPROVE
- 判断: 変更なし
