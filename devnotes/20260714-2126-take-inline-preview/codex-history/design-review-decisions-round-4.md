# 対応マトリクス: design-review Round 4

Codex 判定: CHANGES_REQUESTED（S1/S3 APPROVE。S2 Warning / S4 Critical / S5）

## [Critical] S4: 外部通知が `phase==="recording"` だと stopping 中に false 通知され preview 解禁 → 停止処理中に playback と MediaRecorder が同居
- 判断: 対応する（核心的な整合バグ）
- 根拠: TakeStrip の解禁条件と CameraRecorder の解放拒否条件（phase!==idle）がズレる。正しい。
- 対応内容: 外部排他通知を **`phase !== "idle"`（recording と stopping を含む「撮影 active」）** に変更。
  プロップ名を意味に合わせ **`onCaptureActiveChange` へ改名**し、TakeStrip 側も `captureActive`
  （旧 `recordingInProgress`）に統一。エラー文言は「撮影中はプレビューを再生できません…」。

## [Warning] S4: async onstop 内 onCaptured reject で未処理 rejection
- 判断: 対応する
- 対応内容: `onstop` を `try { await finalize } catch { 既存エラー表示経路へ } finally { setPhase("idle") }`。

## [Warning] S2: cleanup が可変 `video` 参照で take 差し替え時に新要素を teardown し得る／破棄後 no-op
- 判断: 対応する
- 対応内容: effect 実行時の要素をローカル `const target = video` に固定して cleanup で teardown。
  `video` は `HTMLVideoElement | undefined` 型。

## [Critical/Warning] S5: 追加テスト
- 判断: 対応する
- 対応内容: (a) recording→stopping で `onCaptureActiveChange(false)` が発火せず idle 遷移でのみ false、
  (b) stopping 中は TakeStrip が preview を開かない、(c) take 差し替え時に旧 video のみ teardown・新 video の src は保持、
  (d) onCaptured reject が既存エラー処理へ渡り未処理 rejection にならない。
