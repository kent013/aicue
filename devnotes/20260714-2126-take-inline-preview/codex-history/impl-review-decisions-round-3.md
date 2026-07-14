# 対応マトリクス: impl-review Round 3

Codex 全体判定: **CHANGES_REQUESTED**（Critical 1 / Warning 1）。

## [Critical] resuming 中の同型 race (S4)
- 判断: 対応する（指摘は正当。starting と対称の getUserMedia 窓）
- 根拠: preview close → `resumeAfterPreview()` の getUserMedia 待ちの間、公開 active が false のままだと
  preview 再オープン・録画開始が通り、camera stream の二重取得/漏洩や preview 背後での復帰が起こりうる。
- 対応内容: 公開 active を **`starting || resuming || phase !== "idle"`** に統一。
  - `syncActive()` の判定に `resuming` を追加。
  - `resumeAfterPreview()`: `resuming=true` 直後に `syncActive()`（active=true）、`finally` で
    `resuming=false; syncActive()`（active=false へ）。ガードに `starting` も追加。
  - `startRecording()` のアーリーリターンに `resuming` を追加（取得中の二重 getUserMedia を防止）。
  - `releaseForPreview()` のガードに `resuming` を追加（取得中 stream の横取り解放を防止）。

## [Warning] resume 窓の回帰テスト不足 (S4)
- 判断: 対応する
- 根拠: resume の grant 窓を固定するテストが無かった。
- 対応内容: テスト追加：
  - 「resume 取得中は active=true で、preview 解放も録画開始も抑止する」= 取得中に
    `onCaptureActiveChange(true)`・`releaseForPreview` no-op・`startRecording` が getUserMedia を
    増やさない・完了で active=false を検証。
  - 「resume が失敗しても active=false へ戻る（再試行可能）」を追加。
