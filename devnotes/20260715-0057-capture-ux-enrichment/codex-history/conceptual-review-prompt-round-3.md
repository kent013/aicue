# 概念設計レビュー Round 3: capture-ux-enrichment

Round 2 の Critical 1 + Warning 3 + Suggestion に対応しました。

## Round 2 指摘への対応サマリー

1. **[Critical] acquire-then-swap が単一カメラ占有端末で恒常失敗** → 対応。
   **切替リカバリの段階方式**へ変更:
   1. acquire-then-swap を試す（同時利用可能端末はこれで完了）。
   2. 資源競合系失敗（NotReadableError/AbortError）なら旧 stream を stop してから新 facingMode を取得。
   3. 新 facingMode 取得も失敗なら旧 facingMode を再取得（現行カメラ復旧）+ rollback + inline エラー（CameraSwitchError）。
   4. 旧カメラ再取得も失敗した場合に**限り** onCameraUnavailable へ流す。
   保証は「必ず保持」ではなく「可能なら保持・必要なら復旧」。

2. **[Warning] hasMultipleVideoInputs を onMount 一度きり** → 対応。
   事前判定は **UI ヒントに留め**、切替可否の真実源にしない。初回カメラ取得成功後に再評価 + `devicechange` で更新。実行時の段階リカバリが最終防御。

3. **[Warning] supportsPauseResume が pause のみ** → 対応。
   `pause` と `resume` の両方を確認。実行時は `MediaRecorder.state` + pause/resume イベントで phase 確定、同期例外は recoverable 扱い。

4. **[Warning] セグメント境界をボタン押下時刻で記録** → 対応。
   境界は `recorder.onpause` / `recorder.onresume` イベントで開閉（実 pause/resume 時刻を performance.now() で記録）。onstop は recording 状態の未確定セグメントのみ加算、二重加算しない不変条件をテスト。

5. **[Suggestion] 過渡状態の boolean 散在回避** → 対応。
   過渡を独立 boolean で散在させず phase マシン + MediaRecorder イベントで遷移確定（詳細設計で固定）。

残る懸念があれば Critical/Warning で指摘してください。問題なければ APPROVED をお願いします。

---

## 修正後の概念設計（該当セクション抜粋）

## 改善アイデア

`CameraRecorder.svelte` に v1 採用の 4 機能を、既存の録画ロジック
（MediaRecorder / upload-queue / カメラ非対応フォールバック / preview 排他の
phase マシン）を壊さずに追加する。

- **一時停止 / 再開**: phase マシンに `paused` を追加。`recorder.pause()/resume()`。
  chunks は単一の録画に蓄積され `onstop` で 1 つの blob（＝同一テイク）になる。
  実録画時間は **`performance.now()` ベースのセグメント累積**で計測（pause 中を除外）し、
  これを唯一の source of truth として `onCaptured` の durationMs とタイマー表示の
  両方に使う。
  - **capability degrade**: `typeof MediaRecorder.prototype.pause === "function"`
    が false の端末では一時停止ボタン自体を出さない（録画→停止のみ）。判定は
    camera.ts の純関数 `supportsPauseResume()`（`pause` と `resume` の**両方**を確認）。
- **グリッド表示**: 新規 presentational コンポーネント `GridOverlay.svelte`
  （features/capture、SubtitleOverlay と同階層・同パターン）。三分割ガイド線を
  DS token（`bg-surface` + 透過）で描画。トグル既定 OFF。字幕 overlay と共存
  （両者 `pointer-events-none absolute inset-0`）。
- **カメラ反転**: `facingMode` state（`"environment" | "user"`、既定 environment）。
  idle 時のみトグル可（録画中 / 一時停止中は反転ボタンを描画しない＝phase 別の
  コントロール切替。既存の「idle→録画開始 / recording→停止」と同じ設計で、
  disabled 化ではない＝禁止事項 8 に非抵触）。
  - **切替リカバリの段階方式（非破壊 / Codex R1-Critical・R2-Critical）**: 単一カメラ占有端末で
    旧 stream 保持のまま新 getUserMedia が資源競合失敗するケースに備え、段階的に切替・復旧する:
    1. **acquire-then-swap**: 先に新 facingMode で getUserMedia を試行し、成功なら旧 stream を
       stop して差替える（同時利用可能端末はこれで完了）。
    2. **資源競合系失敗**（`NotReadableError` / `AbortError`）なら **旧 stream を stop してから**
       新 facingMode を取得する。
    3. 新 facingMode の取得も失敗したら **旧 facingMode を再取得**（現行カメラを復旧）し、
       facingMode を rollback + inline エラー表示（`CameraSwitchError`、recoverable）。
    4. **旧カメラの再取得にも失敗した場合に限り** `onCameraUnavailable` へ流す
       （ここまで来たら現行カメラも失われており、恒久フォールバックが正しい）。
    → 保証は「必ず保持」ではなく「可能なら保持・必要なら復旧」。recoverable な切替失敗は
    `onCameraUnavailable` に混ぜず `CameraSwitchError` としてローカル表示に閉じる。
  - **capability degrade（ヒントに留める / Codex R2）**: `enumerateDevices()` の `videoinput` が
    2 未満なら反転ボタンを出さない。ただしこれは **UI ヒント**であり切替可否の真実源にしない
    （権限取得前は enumerateDevices が不完全なため）。初回カメラ取得成功後に再評価 +
    `devicechange` イベントで更新。実行時の段階リカバリが最終防御。判定は `hasMultipleVideoInputs()`。
- **録画タイマー**: recording 中のみ `setInterval`（表示更新トリガーのみ）で mm:ss を更新、
  pause で停止、stop / destroy でクリア。**表示値は下記セグメント累積から派生**（setInterval を
  時間計測の真実源にしない＝バックグラウンド / 負荷でのズレを避ける）。
- **セグメント累積の境界（Codex R2）**: セグメント境界は **`recorder.onpause` / `recorder.onresume`
  イベント**で開閉する（ボタン押下時刻ではなく実 pause/resume 時刻を `performance.now()` で記録し
  遅延混入を避ける）。`onstop` では recording 状態の未確定セグメントのみ加算し、二重加算しない
  不変条件をテストで固定する。実行時の phase 確定は `MediaRecorder.state` と pause/resume
  イベントに基づき、同期例外は recoverable として扱う。過渡状態を独立 boolean で散在させず
  phase マシン + MediaRecorder イベントで遷移を確定する。

## 期待効果

- **撮り直し率の低下・詰み回避・テイク継続性の維持**（構図グリッド・前後カメラ・
  中断再開）→ 現場作業者の撮影負荷軽減（使命「専門知識ゼロでもマニュアル動画」への寄与）。
- pause を含む実録画時間の正確な計測（現状 `Date.now()-startedAt` の壁時計では
  pause 導入時に過大計上になる。`performance.now()` セグメント累積で take の
  `duration_ms` を正確化）。

## 型安全性の方針（Codex R1）
