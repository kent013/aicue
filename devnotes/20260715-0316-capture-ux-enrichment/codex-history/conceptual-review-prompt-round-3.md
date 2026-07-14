# 概念設計レビュー Round 3（対応報告）

Round 2 の 2 Warning + 1 Suggestion に対応しました。

## 対応サマリー

1. **[Warning] flip: カメラ二重取得不可端末** → 対応。「旧 stream 維持のまま新取得」前提を撤回し、3 段の段階的縮退に変更:
   1. まず既存 video track の `applyConstraints({ facingMode })`（同一 stream 維持=二重取得不要）。成功で終了。
   2. 不成立時のみ旧 stream 停止 → 新 facingMode 再取得。失敗時は**旧 facingMode で再取得して復旧**。
   3. 復旧も失敗した場合のみ transient 表示 + idle 復帰。いずれも F-03（onCameraUnavailable）へは倒さない。

2. **[Warning] pause/resume の phase 確定をイベント基準に** → 対応。押下は「要求」に留め、phase 確定は `recorder.onpause`/`recorder.onresume` イベント到達で行う。in-flight フラグ（pausing/resuming）で多重押下ガード。`onerror`/予期しない `onstop`/イベント未到達時は `recorder.state`（inactive→idle / paused→paused / recording→recording）から UI phase を復旧。MediaRecorder イベント経由の遷移を単一 phase マシンに集約。

3. **[Suggestion] supportsPauseResume() は存在確認にすぎない旨を明記** → 対応。命名/コメントで「API 存在確認であって正常動作保証ではない。実行時失敗への退行が最終防御」と明記。実行時失敗時は phase を recorder.state から復旧し以降その take は従来 start/stop 挙動に倒す。

## 改訂後の該当箇所（抜粋）

### カメラ反転（guarded）
applyConstraints 優先 → 再取得（旧 facingMode 復旧付き）→ transient のみ、の 3 段縮退。二重取得前提を撤回。

### 一時停止/再開（core）
phase 確定は onpause/onresume イベント基準。in-flight ガード + recorder.state からの復旧。supportsPauseResume は存在確認のみで実行時退行が最終防御。

---

残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
