# 対応マトリクス: impl-review Round 1

Codex 全体判定: **APPROVED**（Critical 0 / Warning 2 / Suggestion 複数）。

## [Warning] CameraRecorder.releaseForPreview の starting 中 race (S4)
- 判断: 対応する
- 根拠: `startRecording()` の getUserMedia grant 待ち中は `starting=true` だが phase はまだ "idle"。
  この窓で preview が開くと取得中 stream を横取り解放しうる（狭いが実在の race）。ガード強化は
  副作用のない純粋な安全側変更。
- 対応内容: `releaseForPreview()` のガードを `if (starting || phase !== "idle") return;` に強化。
  回帰テスト「開始処理中は no-op（取得中 stream を横取り解放しない）」を追加（CameraRecorder.test.ts）。

## [Warning] TakeStrip.adoptFromPreview の error===null 依存 (S3)
- 判断: 見送る
- 根拠: 既存 `run()` は成功時に必ず `error=null`、失敗時に `error` を設定する単一経路であり、
  現状の契約に依存は閉じている。boolean 戻り値化は adopt/run の共通ヘルパ全体の signature 変更に波及し、
  「今必要なものだけ作る」に反する。テスト（採用成功で dialog close + onCameraResume 1 回 / 失敗で開いたまま）で
  挙動は固定済み。
- 対応内容: 変更なし。

## [Suggestion] 各種（トークン運用・teardown・テスト網羅の肯定的評価）
- 判断: 対応不要（肯定的評価）
- 根拠: S1〜S5 の設計一致・DESIGN.md/Atomic 準拠・テスト網羅を APPROVED として確認済み。
