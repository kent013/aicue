# 対応マトリクス: conceptual-review Round 3

## [Warning] facingMode 文字列制約はブラウザが同一カメラを返し得る（切替不成立）
- 判断: 対応する
- 根拠: 緩い facingMode 指定は hint 扱いで無視され得る。取得成功でも実際は反転していない可能性。
- 対応内容: 切替時は `{ facingMode: { exact: target } }` を使い、取得後に `track.getSettings().facingMode`（無ければ deviceId 変化）で**切替成立を検証**。不成立は取得失敗と同じリカバリ経路（段階方式）へ流す。初回取得は従来どおり緩い `environment` 指定のまま。

## [Warning]/[型安全性] pause/resume 過渡状態の排他が不足（phase に pausing/resuming を追加）
- 判断: 対応する
- 根拠: `pause()` 呼出し〜`onpause` 確定まで phase=recording のままだと二重 pause・pause 直後 resume/stop を防げない。過渡を型で表現すべき。
- 対応内容: `CapturePhase` に `pausing` / `resuming` を追加し遷移を固定:
  - `recording → pausing → paused`（onpause 確定で paused）
  - `paused → resuming → recording`（onresume 確定で recording）
  - 同期例外時は遷移前 phase へ戻す
  - `pausing` / `resuming` 中は再操作・preview・カメラ切替を拒否
  - `onstop` は全 phase から最終的に `idle` へ収束
  - 既存の preview 再取得 boolean `resuming` と名称衝突するため、**preview 側は `previewResuming` にリネーム**し概念を分離（`active = starting || previewResuming || phase !== "idle"`）。可否判定 `canPause/canResume/canStop/canSwitchCamera` と exhaustive switch、phase 遷移テストに pausing/resuming を追加。
