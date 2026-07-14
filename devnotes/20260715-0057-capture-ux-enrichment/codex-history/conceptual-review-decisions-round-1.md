# 対応マトリクス: conceptual-review Round 1

## [Critical] facingMode 切替失敗を恒久フォールバックに流さない
- 判断: 対応する
- 根拠: 「前面が使えないだけ」で背面撮影まで壊すのは非破壊前提に反する。指摘は正当。
- 対応内容: flip は「新 facingMode を先に取得成功 → 旧 stream を stop して差替え」の順に変更（acquire-then-swap）。失敗時は旧 stream を保持したまま facingMode を rollback し、inline エラー表示のみ。`onCameraUnavailable` には流さない。新エラー型 `CameraSwitchError`（recoverable）として分離。

## [Warning] pause/resume・前後切替の capability detection と degrade
- 判断: 対応する
- 根拠: 端末差で pause/resume 不安定・facingMode 無効はあり得る。UI を出したまま詰ませない。
- 対応内容: pause/resume は `typeof MediaRecorder.prototype.pause === "function"` を capability として判定し、非対応なら一時停止ボタン自体を出さない（録画→停止のみ）。前後カメラは `enumerateDevices()` の videoinput が 2 未満なら反転ボタンを出さない（degrade）+ 実行時 acquire 失敗は rollback（二重防御）。capability は camera.ts の純関数へ。

## [Warning] durationMs の source of truth を state 遷移ベース累積へ
- 判断: 対応する
- 根拠: setInterval はバックグラウンド / 負荷でずれる。durationMs は take の duration_ms になるため正確性が要る。
- 対応内容: source of truth は `performance.now()` ベースのセグメント累積（start/resume で segmentStart 記録、pause/stop で加算）。setInterval は「表示更新のトリガーのみ」で、表示値も累積から派生。onCaptured の durationMs も同累積。

## [Warning] paused 追加の既存 preview 排他・active 連携の非回帰観点
- 判断: 対応する
- 根拠: active の利用箇所（TakeStrip の preview 抑止）まで含めた回帰検証が必要。
- 対応内容: 非回帰観点として (a) paused 中は preview を開けない (b) paused も captureActive=true (c) stop は idle にのみ遷移 の 3 点を制約・テスト計画に明示。既存 releaseForPreview/resumeAfterPreview の early-return が `phase !== "idle"` で paused を含むことを確認済みとして記載。

## [Warning] 型安全性: phase / facingMode を union 固定 + 判定を純関数化
- 判断: 対応する
- 根拠: phase が 4 値に増え文字列比較散在は事故要因。exhaustiveness を効かせたい。
- 対応内容: `CapturePhase = "idle"|"recording"|"paused"|"stopping"`、`FacingMode = "environment"|"user"` を camera.ts の union に固定。`canPause/canResume/canStop/canSwitchCamera(phase)` を純関数化しユニットテスト対象に。

## [Suggestion] 反転ボタンが消えた理由を伝える / 期待効果の言い回し / 実装順序 / エラー型分離
- 判断: 一部対応
- 対応内容: 期待効果を「撮り直し率低下・詰み回避・テイク継続性維持」に寄せる。エラー型は上記 CameraSwitchError で分離済み。優先度（中核 = pause/resume・facingMode、補助 = grid・timer）を設計に明記。反転ボタンの「消えた理由」補足文言は詳細設計で任意対応（過剰 UI を避け、まず phase 別コントロールの一貫性で対処）。
