# 対応マトリクス: conceptual-review Round 3

## [Warning] applyConstraints resolve ≠ 実切替（ブラウザが制約を緩く解釈）
- 判断: 対応する
- 対応内容: applyConstraints は `{ facingMode: { exact: target } }` を試し、resolve 後に `track.getSettings().facingMode` が target と一致するか検証。一致=同一 stream 維持で終了。不一致/確認不能なら再取得経路へ進む。

## [Warning] 新旧両方の再取得失敗時に F-03 を常に呼ばないと撮影不能で取り残される
- 判断: 対応する（「flip 初回失敗の非 F-03」と「最終的カメラ喪失時の F-03」を分離）
- 根拠: flip の付加操作失敗を即 F-03 に流さないことと、stream を完全に失った状態で F-03 を禁止することは別問題。後者は撮影不能の詰み。
- 対応内容: flip の段階的縮退を修正:
  1. `applyConstraints({ facingMode: { exact }})` + `getSettings().facingMode` 検証。成功で終了（同一 stream 維持、以降 stream 有）。
  2. 不成立時のみ旧 stream 停止 → 新 facingMode 再取得。成功で差し替え終了。
  3. 新取得失敗 → **旧 facingMode で再取得して復旧**。成功なら flip 断念（元のカメラで撮影継続可、transient 表示のみ）。
  4. 旧 facingMode 再取得も失敗（= stream を完全に喪失）→ その reject を `classifyGetUserMediaError()` で分類。**恒久失敗なら `onCameraUnavailable(reason)`（F-03 へ委譲）、一時失敗なら transient 表示 + idle（再試行可能）**。
  - 要点: 「flip 自体の不成立（元カメラは生存）」は local に留め、「カメラ完全喪失」は既存 classify 経由で F-03/transient に正しく振り分ける。

## [Suggestion] イベント未到達検出（pause/resume）の条件を詳細設計で明確化
- 判断: 対応する（詳細設計で規定）
- 対応内容: 詳細設計に以下を明記予定:
  - pause/resume 要求時に in-flight フラグを立て、タイムアウト（例 1〜2s）で未到達を検出。
  - タイムアウト解除条件: onpause/onresume/onerror/onstop いずれか到達で clear。
  - 遅延イベント到達後の二重遷移防止: in-flight が既に解除済み（タイムアウト後復旧済み）なら遅延イベントは `recorder.state` 同期のみ（phase を再度動かさない）ようガード。
  - take 終了（onstop/idle/onDestroy）時に in-flight フラグ・タイムアウト・timer interval を一括 cleanup。
