# 対応マトリクス: design-review Round 4

## [Critical] inactive 即時収束と遅延 onstop/dataavailable が新録画セッションと競合
- 判断: 対応する（世代分離の堅牢性）
- 根拠: MediaRecorder は error/inactive 後も遅延 dataavailable/stop を配送し得る。共有 `chunks`/`accumulatedMs` を新録画が初期化した後に旧 recorder の遅延イベントが発火すると、新セッションの状態で onCaptured したり phase を idle へ戻す。
- 対応内容: **録画セッション世代 + 一度限り finalizer**:
  - `sessionId`（start ごとに +1）と `finalizedSession`。全 recorder ハンドラ（ondataavailable/onpause/onresume/onstop/onerror/track.onended）は生成時の `session` を closure で束ね、`session !== sessionId` の遅延イベントは no-op。
  - `finalizeSession(session)` に onstop と abort fallback を集約（`session !== sessionId || finalizedSession === session` で stale/二重を no-op）。closeSegment・stopTimer・onCaptured・`dispatch("onstop")`（active 解除）をここでのみ行う。
  - `chunks` / `accumulatedMs` / `segmentStart` は startRecording で sessionId 更新と同時に初期化。
  - **abort の inactive 経路は watchdog**（`FINALIZE_WATCHDOG_MS`）で保険 finalize（遅延 onstop が先に来れば idempotent に finalize、来なければ watchdog が finalize して active を必ず解除。Codex R4 の「inactive 時は通常の onstop を待ち短い watchdog で fallback」に沿う）。watchdog timer は onDestroy でクリア。
- S6 追加: 「inactive abort 後、旧 onstop より先に新録画を開始 → 旧 onstop が新セッションを変更しない」「遅延した旧 dataavailable が新 chunks を汚さない」「watchdog で active 解除」。
