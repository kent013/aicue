# 概念設計レビュー Round 4: capture-ux-enrichment

Round 3 の Warning 2 点に対応しました。

1. **[Warning] facingMode 切替不成立の検証** → 対応。
   切替時は `{ facingMode: { exact: target } }` を使い、取得後に `track.getSettings().facingMode`（無ければ deviceId 変化）で切替成立を検証。不成立は取得失敗と同じリカバリ経路（段階方式）へ流す。初回取得は緩い `environment` のまま。

2. **[Warning]/[型安全性] pause/resume 過渡状態を phase union へ** → 対応。
   `CapturePhase = idle | recording | pausing | paused | resuming | stopping` に過渡状態を追加。
   - `recording → pausing → paused`（onpause 確定）
   - `paused → resuming → recording`（onresume 確定）
   - 同期例外時は遷移前 phase へ戻す
   - pausing / resuming 中は再操作・preview・カメラ切替を拒否
   - onstop は全 phase から idle へ収束
   - 既存の preview 再取得 boolean `resuming` は phase の `resuming` と衝突するため **`previewResuming` にリネーム**（`active = starting || previewResuming || phase !== "idle"`）。可否判定と exhaustive switch、phase 遷移テストに pausing/resuming を追加。

残る懸念があれば Critical/Warning で指摘してください。問題なければ APPROVED をお願いします。

---

## 修正後の該当セクション抜粋

### 一時停止 / 再開
phase マシンに `paused` と過渡状態 `pausing` / `resuming` を追加（`recording → pausing → paused` / `paused → resuming → recording`）。`recorder.pause()/resume()` を呼び、`onpause`/`onresume` イベントで過渡→確定へ遷移。同期例外時は遷移前 phase へ戻す。pausing/resuming 中は再操作・preview・カメラ切替を拒否。onstop は全 phase から idle へ収束。chunks は単一録画に蓄積され onstop で 1 blob（同一テイク）。

### カメラ反転（切替リカバリ段階方式）
1. acquire-then-swap: `{ facingMode: { exact: target } }` を試行 → `track.getSettings().facingMode`（無ければ deviceId 変化）で切替成立を検証 → 成功なら旧 stream stop で差替え。不成立は取得失敗と同じ経路へ。
2. 資源競合系失敗（NotReadableError/AbortError）なら旧 stream stop 後に新 facingMode 取得。
3. 新 facingMode 取得も失敗なら旧 facingMode 再取得（現行カメラ復旧）+ rollback + inline エラー（CameraSwitchError）。
4. 旧カメラ再取得も失敗した場合に限り onCameraUnavailable。

### 型
`CapturePhase = idle | recording | pausing | paused | resuming | stopping`、`FacingMode = environment | user`。preview 再取得 boolean は `previewResuming` にリネーム。`canPause/canResume/canStop/canSwitchCamera` + exhaustive switch + phase 遷移テストに pausing/resuming を追加。
