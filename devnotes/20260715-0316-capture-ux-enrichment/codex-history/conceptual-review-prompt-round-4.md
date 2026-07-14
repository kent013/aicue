# 概念設計レビュー Round 4（対応報告）

Round 3 の 2 Warning + 1 Suggestion に対応しました。

## 対応サマリー

1. **[Warning] applyConstraints resolve ≠ 実切替** → 対応。`applyConstraints({ facingMode: { exact: target } })` の resolve 後に `track.getSettings().facingMode === target` を検証。一致で同一 stream 維持終了、不一致/確認不能なら再取得経路へ。

2. **[Warning] カメラ完全喪失時は F-03 を呼ぶ（flip 初回失敗の非 F-03 と分離）** → 対応。flip の段階的縮退を修正:
   1. applyConstraints + getSettings 検証（成功で同一 stream 維持）。
   2. 不成立 → 旧 stream 停止 → 新 facingMode 再取得（成功で差し替え）。
   3. 新取得失敗 → 旧 facingMode で再取得復旧（成功なら flip 断念・元カメラ継続・transient のみ）。
   4. 旧再取得も失敗（stream 完全喪失）→ `classifyGetUserMediaError()` で分類し、恒久失敗なら `onCameraUnavailable(reason)`（F-03 委譲）、一時失敗なら transient + idle。
   - 「flip 自体の不成立（元カメラ生存）」は local、「カメラ完全喪失」は既存 classify 経由で F-03/transient に振り分け。

3. **[Suggestion] pause/resume のイベント未到達検出条件を詳細設計で明確化** → 詳細設計で規定する旨を対応マトリクスに記載（in-flight フラグ + タイムアウト解除条件 + 遅延到達後の二重遷移防止 + take 終了時 cleanup）。概念段階では方針として合意。

---

残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
