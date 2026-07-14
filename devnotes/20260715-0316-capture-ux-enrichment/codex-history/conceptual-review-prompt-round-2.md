# 概念設計レビュー Round 2（対応報告）

Round 1 の指摘への対応です。全 Warning に対応し、概念設計を改訂しました。

## 対応サマリー

1. **[Warning] pause/resume 能力差分の前提化** → 対応。`camera.ts` に `supportsPauseResume()`（`MediaRecorder.prototype.pause/resume` の typeof 検査）を追加。未対応端末は一時停止ボタン**非表示**で従来 start/stop に退行（disabled にしない）。`pause()/resume()` の `InvalidStateError` は try/catch で phase を元に戻す（握りつぶさない）。

2. **[Warning] カメラ反転の rollback** → 対応。「新 stream 取得**成功後**に旧 stream を停止・差し替え、失敗時は旧 stream 維持 + transient 表示のみ（`onCameraUnavailable` を呼ばず F-03 に倒さない）」に変更。`acquirePreviewStream`（`stream ??=`）とは別の flip 専用取得経路を分離。

3. **[Warning] durationMs の意味変更を後方互換扱いしない** → 対応。消費側を実棚卸し: 唯一の実消費は `Capture/Show.svelte#handleCaptured → upload-queue.enqueue({durationMs}) → POST の duration_ms`（doc/10 `takes.duration_ms`=実録画尺メタ）。wall-clock 依存なし。累積録画時間の方が「実録画尺」に**より正確**なので「後方互換」ではなく「**意味の是正**」と表現を訂正。型は number 不変。

4. **[Warning] v1 内優先度の層別** → 対応。`core: 1 pause/resume・2 grid・4 timer / guarded: 3 camera flip`。3 は rollback + transient 限定で guarded。

5. **[Warning] Phase union の網羅性** → 対応。`type Phase = "idle" | "recording" | "paused" | "stopping"` を単一ソース化し UI 文言・ボタン表示・active 算出・ハンドラ条件を従属させる。

6. **[Warning] timer/facingMode の型** → 対応。`type FacingMode = "environment" | "user"`、timer handle は `ReturnType<typeof setInterval>`、`formatElapsed(ms): string` は pure function。

7. **[Suggestion] 単調増加時計** → 採用。累積計測を `performance.now()` ベースに。

8. **[Suggestion] grid/字幕の z 順・線仕様** → 採用。映像 < grid < 字幕帯、罫線は DS token 半透明細線。

9. **[Suggestion] 効果を観測可能指標へ** → 期待効果の文言を「再撮影率低下・分断減少・途中離脱低下（観測可能な仮説）」に修正（計測基盤の追加はスコープ外）。

## 改訂後の該当箇所（抜粋）

### v1 内優先度
- core: 1 一時停止/再開・2 グリッド・4 タイマー
- guarded: 3 カメラ反転（idle 時のみ・新 stream 成功後差し替え・失敗時旧 stream 維持・F-03 に流さない）

### 一時停止/再開（core）
`type Phase = "idle" | "recording" | "paused" | "stopping"` 単一ソース。`supportsPauseResume()` 未対応時は一時停止ボタン非表示。`InvalidStateError` は phase を元に戻す。

### カメラ反転（guarded）
`type FacingMode = "environment" | "user"`。idle のみ。新 stream 取得成功後に旧 stream 停止・差し替え、失敗時旧 stream 維持 + transient 表示のみ。

### 録画タイマー（core）
`performance.now()` 累積（pause 除外）。interval handle は `ReturnType<typeof setInterval>`、recording 中のみ稼働、pause/idle/onDestroy で clear。`formatElapsed(ms): string` は pure。

### durationMs
`performance.now()` 累積録画時間。単一消費（upload-queue→takes.duration_ms メタ）棚卸し済み、wall-clock 依存なし、意味の是正、型 number 不変。

---

上記対応で v1 スコープ判定と設計方針が妥当か、残 Critical/Warning がないか判定してください。全体判定（APPROVED / CHANGES_REQUESTED）を明示してください。
