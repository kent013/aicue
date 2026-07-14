# 対応マトリクス: conceptual-review Round 2

## [Critical] acquire-then-swap は単一カメラ占有端末で恒常失敗し得る
- 判断: 対応する
- 根拠: モバイルは同時カメラ利用不可の端末があり、旧 stream 保持のまま新 getUserMedia が資源競合で失敗する。指摘は正当。
- 対応内容: **切替リカバリの段階方式**へ変更。(1) acquire-then-swap を試す → (2) 資源競合系失敗（NotReadableError/AbortError）なら旧 stream を stop してから新 facingMode を取得 → (3) 新 facingMode 取得も失敗なら旧 facingMode を再取得（現行カメラ復旧）→ (4) 旧カメラ再取得も失敗した場合に**限り** `onCameraUnavailable` へ流す。「必ず保持」ではなく「可能なら保持・必要なら復旧」。

## [Warning] hasMultipleVideoInputs を onMount 一度きりは不正確
- 判断: 対応する
- 根拠: 権限取得前の enumerateDevices は videoinput ラベル/件数が不完全で、反転可能端末でもボタンを永久に隠しうる。
- 対応内容: 事前判定は **UI ヒントに留め**、切替可否の真実源にしない。初回カメラ取得成功後に再評価 + `devicechange` イベントで更新。実行時のリカバリ段階方式が最終防御。

## [Warning] supportsPauseResume が pause のみ確認
- 判断: 対応する
- 対応内容: `pause` と `resume` の**両方**を確認。実行時は `MediaRecorder.state` と `pause`/`resume` イベントで phase を確定し、同期例外は recoverable として扱う。

## [Warning] セグメント境界をボタン押下時刻で記録すると遅延が混入
- 判断: 対応する
- 根拠: 実際の MediaRecorder pause/resume と押下時刻に遅延差があり duration に混入する。
- 対応内容: セグメント境界は `recorder.onpause` / `recorder.onresume` イベントで開閉（`performance.now()` を各イベントで記録）。`onstop` では recording 状態の未確定セグメントのみ加算し、二重加算しない不変条件をテストで固定。

## [Suggestion] 過渡状態を boolean で散在させない / 遷移競合を固定
- 判断: 対応する（詳細設計で固定）
- 対応内容: `pause requested` 等の過渡を独立 boolean で散在させず、phase マシン（既存 starting/resuming を含む）と MediaRecorder イベントで遷移を確定する方針を詳細設計で明記。
