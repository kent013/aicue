# 対応マトリクス: impl-review Round 2

Codex 全体判定: **CHANGES_REQUESTED**（Critical 1 / Warning 1）。

## [Critical] starting ガードだけでは排他契約を満たさない (S4)
- 判断: 対応する（指摘は正当）
- 根拠: `starting=true` の間 `captureActive` は false のままなので TakeStrip は preview を開けてしまい、
  getUserMedia 完了後に背後で録画が始まる → preview と MediaRecorder が同居する。ガードのみでは不十分。
- 対応内容: 公開 active を **`starting || phase !== "idle"`** に一元化した。
  - `syncActive()` を新設し、starting/phase を変える全箇所から呼ぶ（通知の単一化）。
  - `startRecording` 冒頭 `starting=true; syncActive()` で開始押下時点に active=true を通知
    → 親 captureActive=true → grant 窓でも preview を開けない（根本解決）。
  - `finally { starting=false; syncActive() }`: 成功時は phase=recording で true 維持（重複通知なし）、
    失敗/恒久失敗時は phase=idle で false へ戻す。
  - `setPhase` は phase 変更後 `syncActive()` に委譲（recording/stopping/idle 遷移も一元管理）。
  - `releaseForPreview` の `starting ||` ガードは二重防御として維持。

## [Warning] 追加テストが競合を固定していた (S4)
- 判断: 対応する
- 根拠: 旧テストは「stream を解放しない」だけを見て、preview が開いた状態で録画継続を成功扱いしていた。
- 対応内容: テストを刷新：
  - 「開始処理中は active=true を通知し preview 用解放を拒否する」= getUserMedia pending 中に
    `onCaptureActiveChange(true)` が飛ぶこと、recording 遷移で true を重複通知しないことを検証。
  - 「開始が失敗 (getUserMedia reject) すると active が true→false に戻る」を追加。
  - 既存の start/stop・stopping 維持テストは録画確立 (stop ボタン出現) を待つよう調整。
