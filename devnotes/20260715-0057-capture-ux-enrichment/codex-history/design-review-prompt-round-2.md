# 詳細設計レビュー Round 2: capture-ux-enrichment

Round 1 の Critical 4 + Warning 3 + Suggestion に対応しました。

## Round 1 指摘への対応

1. **[Critical] S2 onpause/onresume/onstop の phase 競合** → 対応。
   phase 遷移を **`transition(phase, event)` 純関数**（camera.ts）に一元化。不正な (phase,event) は現 phase を維持（no-op）。`stopping` は終端優先で onpause/onresume を無視。イベントハンドラは `transition` 結果が想定 phase のときのみ副作用（closeSegment/segmentStart 再張り）を実行。

2. **[Critical] S3 段階3の facingMode 復旧が文脈依存** → 対応。
   `switchCamera()` 先頭で `const previousMode = facingMode` を固定し、復旧（段階3）は必ず `previousMode` を使う。`target = oppositeFacingMode(previousMode)` と明示分離。成功時のみ `facingMode = target`（swapStream 内）。

3. **[Critical] S3 classifyGetUserMediaError の二重評価** → 対応。
   `const classified = classifyGetUserMediaError(recoverCause)` を 1 回だけ評価。`runCameraSwitch()` は `CameraSwitchOutcome`（switched/recovered/unavailable）を返し、`applySwitchOutcome()` が責務分岐（recoverable は inline エラー、unavailable のみ onCameraUnavailable）。

4. **[Critical] S6 テスト期待の phase 混在** → 対応。
   テストを phase 別に明示（`paused: stop 可` / `pausing・resuming: pause/resume/stop 不可(no-op)` / `recording: pause・stop 可`）。ケース名を期待と一致。

5. **[Warning] S2/S4 performance.now のタブサスペンド復帰ジャンプ** → 対応（仕様明文化）。
   duration = active recording セグメントの壁時計和（performance.now 差）。背景継続録画では blob 実尺と一致。表示 tick のみの遅延であり duration 正確性に影響しないため再基準化は導入しない（複雑化回避）。

6. **[Warning] S3 hasMultipleVideoInputs のボタン描画条件** → 対応。
   描画条件を `canSwitchCamera(phase) && stream !== null && canFlipHint` に限定。canFlipHint は初回取得成功後 + `devicechange` で再評価（テストで固定）。描画時は常に押下可能（disabled にしない）。

7. **[Warning] S5 bg-surface/60 の視認性** → 対応。
   既存 `SubtitleOverlay`（`bg-text/70`）と同系の scrim トークン `bg-text/40` に統一。存在しない contrast トークンを新設しない。

8. **[Suggestion]** transition 純関数化・CameraSwitchOutcome 戻り値化・FakeMediaRecorder のイベント順注入をいずれも採用。

## 確認したい点

- `transition()` に pause/resume 同期例外用の cancel event を足さず、catch 内で `phase` 直接代入（recording/paused へ巻き戻し）+ syncActive とした点（最小変更）。これで問題ないか。
- 反転ボタンの「stream !== null 条件」により、初回録画前（live preview 未取得）は反転ボタンが出ないが、flip 対象の preview が無いため UX 上妥当と判断。異論があれば指摘を。

残る懸念があれば Critical/Warning で。問題なければ APPROVED をお願いします。

---

## 修正後の詳細設計（該当セクション抜粋）

### 追加する型・関数（設計）
```ts
/** 撮影 phase マシン (過渡状態 pausing/resuming を含む。Codex R3)。 */
export type CapturePhase =
    | "idle"
    | "recording"
    | "pausing"   // recorder.pause() 発行〜onpause 確定までの過渡
    | "paused"
    | "resuming"  // recorder.resume() 発行〜onresume 確定までの過渡
    | "stopping";

/** カメラの前後 (getUserMedia facingMode)。 */
export type FacingMode = "environment" | "user";

/** 反転先 facingMode (exhaustive)。 */
export function oppositeFacingMode(mode: FacingMode): FacingMode {
    return mode === "environment" ? "user" : "environment";
}

/** phase 依存の可否判定 (UI 出し分け・操作ガードの単一真実源)。 */
export function canPause(phase: CapturePhase): boolean {
    return phase === "recording";
}
export function canResume(phase: CapturePhase): boolean {
    return phase === "paused";
}
/** 停止可能 = 録画中 or 一時停止中 (過渡状態からは操作を受けない)。 */
export function canStop(phase: CapturePhase): boolean {
    return phase === "recording" || phase === "paused";
}
/** カメラ切替は idle のみ (録画/一時停止/過渡ではテイクを分断しない)。 */
export function canSwitchCamera(phase: CapturePhase): boolean {
    return phase === "idle";
}

/** MediaRecorder.pause/resume 両対応か (capability degrade。両方を確認)。 */
export function supportsPauseResume(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof window.MediaRecorder.prototype?.pause === "function" &&
        typeof window.MediaRecorder.prototype?.resume === "function"
    );
}

/**
 * videoinput が 2 以上かの UI ヒント (切替可否の真実源にしない。Codex R2)。
 * 権限取得前は enumerateDevices が不完全なため、初回取得成功後の再評価が前提。
 */
export async function hasMultipleVideoInputs(): Promise<boolean> {
    if (typeof navigator.mediaDevices?.enumerateDevices !== "function") return false;
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        return devices.filter((d) => d.kind === "videoinput").length >= 2;
    } catch {
        return false;
    }
}

/** カメラ切替の recoverable 失敗 (恒久 onCameraUnavailable と型で分離。Codex R1)。 */
export type CameraSwitchOutcome =
    | { kind: "switched"; stream: MediaStream; facingMode: FacingMode }
    | { kind: "recovered" } // 切替失敗・旧カメラで撮影継続 (inline エラー)
    | { kind: "unavailable"; reason: CameraUnavailableReason }; // 現行カメラも喪失 → 恒久フォールバック

/** phase マシンのイベント (MediaRecorder / ユーザー操作)。 */
export type CaptureEvent =
    | "start"    // recorder.start() 成功
    | "pause"    // ユーザー一時停止要求 (recorder.pause() 発行)
    | "onpause"  // recorder.onpause 確定
    | "resume"   // ユーザー再開要求 (recorder.resume() 発行)
    | "onresume" // recorder.onresume 確定
    | "stop"     // ユーザー停止要求 (recorder.stop() 発行)
    | "onstop";  // recorder.onstop 確定

/**
 * phase 遷移の単一真実源 (Codex 詳細R1-Critical/Suggestion)。
 * 不正な (phase, event) は現 phase を維持 (no-op)。UA のイベント順前後や過渡中の
 * 再操作を構造的に無視する防波堤。stopping は終端優先 (onpause/onresume を無視)。
 */
export function transition(phase: CapturePhase, event: CaptureEvent): CapturePhase {
    switch (event) {
        case "start":    return phase === "idle" ? "recording" : phase;
        case "pause":    return phase === "recording" ? "pausing" : phase;
        case "onpause":  return phase === "pausing" || phase === "recording" ? "paused" : phase;
        case "resume":   return phase === "paused" ? "resuming" : phase;
        case "onresume": return phase === "resuming" ? "recording" : phase;
        // stop はユーザー要求。recording/paused からのみ stopping へ。過渡/終端では無視
        case "stop":     return phase === "recording" || phase === "paused" ? "stopping" : phase;
        case "onstop":   return "idle"; // 全 phase から idle へ収束 (終端)
    }
}
```

### PHPStan 適合チェック
- 該当なし（TS）。TypeScript strict: union は exhaustive、`?.` で null 安全。

### テスト計画（S6 に集約）
- `oppositeFacingMode` の双方向、`canPause/canResume/canStop/canSwitchCamera` を全 6 phase で表明。
- `supportsPauseResume` が pause/resume どちらか欠落で false。
- `hasMultipleVideoInputs` が videoinput 数 0/1/2 と enumerateDevices 例外で期待値。
- **`transition(phase, event)` の遷移表を網羅**: 正常遷移（idle→recording→pausing→paused→resuming→recording→stopping→idle）と、不正な (phase,event) が現 phase を維持すること（例: `transition("stopping","onresume")==="stopping"`、`transition("paused","pause")==="paused"`、`transition("pausing","stop")==="pausing"`）。

### リスク
- `MediaRecorder.prototype` へのアクセスは jsdom stub でも安全（optional chaining）。低リスク。

---

## S2. 一時停止/再開（phase 拡張 + 過渡状態 + セグメント累積 duration）

--- S2 変更後コード + テスト ---
## S2. 一時停止/再開（phase 拡張 + 過渡状態 + セグメント累積 duration）

### 変更箇所
- ファイル: `resources/js/components/features/capture/CameraRecorder.svelte`
  - `type Phase` を `camera.ts` の `CapturePhase` import に置換（`pausing`/`paused`/`resuming` 追加）。
  - 既存 `resuming` boolean（preview 再取得）を **`previewResuming` にリネーム**（phase の `resuming` と分離。Codex R3）。
  - `active = starting || previewResuming || phase !== "idle"` に更新。
  - duration 計測を `Date.now()-startedAt` から **`performance.now()` セグメント累積**へ変更。
  - `recorder.onpause` / `recorder.onresume` を配線し過渡→確定へ遷移。
  - `safeStop` の guard を `canStop(phase)` に変更（recording/paused から停止可）。
  - 録画中/一時停止中の UI に一時停止/再開ボタンを追加（`supportsPauseResume()` かつ `canPause/canResume` で出し分け）。

### 波及変更
- TypeScript 型定義: `Phase` ローカル型を撤去し `CapturePhase` を使用（後方互換の並走を残さない＝AGENTS 思考原則 3）。
- API Resource/DTO: なし。
- テストファイル: `CameraRecorder.test.ts` に pause/resume・duration のケース追加（S6）。既存ケースは無改変。

### 現行コード（要点・抜粋）
```ts
type Phase = "idle" | "recording" | "stopping";
let phase = $state<Phase>("idle");
let resuming = false; // preview 再取得の再入ガード
let startedAt = 0;
function syncActive(): void {
    const active = starting || resuming || phase !== "idle";
    ...
}
recorder.onstop = async () => {
    const blob = new Blob(chunks, { type: mimeType });
    const durationMs = Date.now() - startedAt;
    ...
};
function safeStop(): void {
    if (phase !== "recording") return;
    setPhase("stopping");
    ...
}
```

### 変更後コード（設計）
```ts
import {
    canPause, canResume, canStop, supportsPauseResume,
    transition, type CapturePhase, type CaptureEvent,
} from "@/lib/capture/camera";

let phase = $state<CapturePhase>("idle");
let previewResuming = false; // 旧 resuming (preview 再取得の再入ガード)
const pauseResumeSupported = supportsPauseResume(); // capability (mount 時定数)

// --- 実録画時間のセグメント累積 (source of truth。performance.now()) ---
// duration の定義: active な recording セグメントの壁時計和 (performance.now 差)。
// 背景でも録画継続する UA では blob の実尺と一致する。表示 tick のみが tab サスペンドで
// 遅延し得るが duration 正確性には影響しない (再基準化は導入しない=複雑化回避。Codex 詳細R1-Warning)。
let accumulatedMs = 0;        // 確定済みセグメントの合計
let segmentStart: number | null = null; // 現在 recording セグメントの開始 (null=計測停止中)

function elapsedMs(): number {
    return accumulatedMs + (segmentStart !== null ? performance.now() - segmentStart : 0);
}
// recording セグメントを閉じる (二重加算しない。segmentStart=null で冪等)
function closeSegment(): void {
    if (segmentStart !== null) { accumulatedMs += performance.now() - segmentStart; segmentStart = null; }
}

// phase 遷移は transition() 純関数に一元化。event を渡し結果 phase を setPhase する。
// setPhase は active 通知 (syncActive) を内包。no-op 遷移 (現 phase 維持) でも安全。
function dispatch(event: CaptureEvent): CapturePhase {
    const next = transition(phase, event);
    if (next !== phase) setPhase(next);
    return next;
}

function syncActive(): void {
    const active = starting || previewResuming || phase !== "idle";
    if (active !== lastActive) { lastActive = active; onCaptureActiveChange?.(active); }
}

// --- MediaRecorder イベント配線 (過渡イベントは transition() が想定 phase のみ受理) ---
// onpause: pausing/recording でのみ paused 確定。stopping/idle では transition が現 phase 維持 (無視)。
recorder.onpause = () => {
    if (transition(phase, "onpause") === "paused") { closeSegment(); dispatch("onpause"); stopTimer(); }
};
// onresume: resuming でのみ recording 確定。stopping では無視 (終端優先)。
recorder.onresume = () => {
    if (transition(phase, "onresume") === "recording") { segmentStart = performance.now(); dispatch("onresume"); startTimer(); }
};
recorder.onstop = async () => {
    closeSegment(); // 未確定 recording セグメントのみ加算 (onpause 済みなら no-op)
    stopTimer();
    try {
        const blob = new Blob(chunks, { type: mimeType });
        if (blob.size > 0) await onCaptured(blob, mimeType, Math.round(accumulatedMs));
    } catch {
        error = "撮影データの処理に失敗しました。もう一度お試しください。";
    } finally {
        dispatch("onstop"); // 全 phase から idle へ収束
    }
};

// 一時停止 (recording→pausing→paused)。canPause で UI ガード + transition で状態ガード。
function pauseRecording(): void {
    if (!canPause(phase) || recorder === null) return;
    dispatch("pause"); // → pausing
    try { recorder.pause(); } // 成功時 onpause で paused 確定
    catch { phase = "recording"; syncActive(); error = "一時停止できませんでした。もう一度お試しください。"; } // 同期例外: pausing→recording へ巻き戻し
}
// 再開 (paused→resuming→recording)。
function resumeRecording(): void {
    if (!canResume(phase) || recorder === null) return;
    dispatch("resume"); // → resuming
    try { recorder.resume(); } // 成功時 onresume で recording 確定
    catch { phase = "paused"; syncActive(); error = "録画を再開できませんでした。もう一度お試しください。"; } // 同期例外: resuming→paused へ巻き戻し
}

// 停止: recording/paused から可 (過渡/終端では transition が no-op)。
function safeStop(): void {
    if (!canStop(phase)) return; // pausing/resuming/stopping/idle は no-op
    dispatch("stop"); // → stopping
    if (recorder === null) { fatalStopCleanup(); return; }
    try { recorder.stop(); } catch { fatalStopCleanup(); }
}
```
> **start 初期化**: `startRecording` の `recorder.start()` 成功時に `accumulatedMs = 0; segmentStart = performance.now();` を初期化し `dispatch("start")`。`recorder.start()` 直後は onpause/onresume 未発火なので recording として segmentStart を張る。
> **同期例外の巻き戻し**: pause/resume の同期例外時は過渡 phase を直前 phase（recording/paused）へ戻す（`phase` を直接代入 + syncActive。`transition` に cancel event を増やさず最小変更）。
> **canPause/canResume の UI ガードと transition の状態ガードは二重防御**（UI は canX で出し分け、実遷移は transition が受理判定）。

### 状態遷移表（テストで固定）
```
idle --start--> recording
recording --pause()--> pausing --onpause--> paused
paused --resume()--> resuming --onresume--> recording
recording --stop()--> stopping --onstop--> idle
paused   --stop()--> stopping --onstop--> idle
pausing/resuming: 再操作(pause/resume/stop)・preview・カメラ切替を拒否 (canX が false)
同期例外(pause/resume throw): 直前 phase へ戻す
track.onended / recorder.onerror --> safeStop (canStop の phase のみ作用)
```

### PHPStan 適合チェック
- TS: `CapturePhase` exhaustive、`segmentStart: number | null` の null 安全、`recorder` null ガード。

### テスト計画（S6）— phase 別に期待を明示（Codex 詳細R1-Critical）
- **paused から stop 可**: `pause → (onpause) → stop`（`onCaptured` 1 回・単一 blob = 同一テイク、durationMs は pause 中を除外）。
- **pause → resume → stop**: 2 セグメント合算、`closeSegment` 冪等で二重加算しない。
- **pausing は再操作不可**: `pause` 発行後 onpause 未確定（phase=pausing）で `pause/resume/stop` を呼んでも recorder メソッドが重複発火しない（`canPause/canResume/canStop` が false・transition が no-op）。
- **resuming も再操作不可**: 同様に phase=resuming で no-op。
- **UA イベント順の防波堤**: `stopping` 中に `onresume`/`onpause` が発火しても phase は `stopping` のまま（`transition("stopping", "onresume")==="stopping"`）→ その後 `onstop` で idle。
- **同期例外の巻き戻し**: `recorder.pause()` throw で phase が recording に戻り alert 表示。`recorder.resume()` throw で paused に戻る。
- **capability degrade**: `supportsPauseResume()===false` で一時停止ボタンが出ない（録画→停止のみ）。
- **無回帰**: 既存の成功/失敗/フォールバック/preview 排他ケースが無改変で通る（active 通知の一元性・`previewResuming` リネーム後も同一挙動）。

### リスク
- MediaRecorder の pause/resume は iOS Safari で挙動差がある。capability degrade（非対応で非表示）+ 同期例外の前 phase 復帰でフェイルセーフ。
- `previewResuming` リネームは参照箇所を全置換（onstop/preview 系のみ）。テストの内部参照はないため後方互換の並走不要。

---

## S3. カメラ反転（切替リカバリ段階方式 + capability degrade）

--- S3 ---
## S3. カメラ反転（切替リカバリ段階方式 + capability degrade）

### 変更箇所
- ファイル: `CameraRecorder.svelte`
  - `facingMode = $state<FacingMode>("environment")`。
  - `acquirePreviewStream()` を facingMode 対応に拡張（初回/preview 復帰は緩い facingMode、切替時は exact + 検証）。
  - `switchCamera()` を追加（段階リカバリ）。idle のみ（`canSwitchCamera(phase)`）。
  - `canFlipHint = $state(false)`（`hasMultipleVideoInputs()` の UI ヒント。初回取得成功後 + `devicechange` で再評価）。
  - 反転ボタンを idle かつ `canFlipHint` のときのみ描画。

### 波及変更
- TypeScript 型定義: `FacingMode` / `CameraSwitchOutcome`（S1）。
- API Resource/DTO: なし。
- テストファイル: `CameraRecorder.test.ts` に切替リカバリのケース追加（S6）。

### 変更後コード（設計）
```ts
import {
    canSwitchCamera, oppositeFacingMode, hasMultipleVideoInputs,
    classifyGetUserMediaError, type FacingMode,
} from "@/lib/capture/camera";

let facingMode = $state<FacingMode>("environment");
let canFlipHint = $state(false); // UI ヒント (真実源ではない)
let switching = false;           // 切替中の再入ガード

// 初回/preview 復帰: 緩い facingMode 指定 (既存挙動を踏襲)
async function acquirePreviewStream(): Promise<boolean> {
    try {
        stream ??= await navigator.mediaDevices.getUserMedia({
            video: { facingMode }, audio: true,
        });
    } catch (cause) { /* 既存の classify → transient/unavailable 分岐 */ return false; }
    if (video) { video.srcObject = stream; await video.play().catch(() => undefined); }
    void refreshFlipHint(); // 取得成功後にヒント再評価 (Codex R2)
    return true;
}

async function refreshFlipHint(): Promise<void> { canFlipHint = await hasMultipleVideoInputs(); }

// getSettings().facingMode で切替成立を検証 (無ければ deviceId 変化で判定)。
function switchSucceeded(newStream: MediaStream, target: FacingMode, prevDeviceId: string | null): boolean {
    const track = newStream.getVideoTracks()[0];
    const settings = track?.getSettings();
    if (settings?.facingMode) return settings.facingMode === target;
    return (settings?.deviceId ?? null) !== prevDeviceId; // facingMode 非提供端末のフォールバック判定
}

// getUserMedia を facingMode 指定で 1 回試行する薄いヘルパ (exact 指定)。
async function tryGetCamera(mode: FacingMode, exact: boolean): Promise<MediaStream> {
    return navigator.mediaDevices.getUserMedia({
        video: exact ? { facingMode: { exact: mode } } : { facingMode: mode },
        audio: true,
    });
}

// カメラ反転 (段階リカバリ)。idle かつ live stream ありのみ。戻り値で責務を明示。
async function switchCamera(): Promise<void> {
    if (!canSwitchCamera(phase) || switching || stream === null) return;
    switching = true;
    error = null;
    const previousMode = facingMode; // ★復旧は必ずこれを使う (段階3。Codex 詳細R1-Critical)
    const target = oppositeFacingMode(previousMode);
    const prevDeviceId = stream.getVideoTracks()[0]?.getSettings().deviceId ?? null;
    try {
        const outcome = await runCameraSwitch(target, prevDeviceId, previousMode);
        applySwitchOutcome(outcome);
    } finally { switching = false; syncActive(); }
}

// 段階リカバリの本体 (副作用は stream 差替え/停止のみ。判定結果を CameraSwitchOutcome で返す)。
async function runCameraSwitch(
    target: FacingMode, prevDeviceId: string | null, previousMode: FacingMode,
): Promise<CameraSwitchOutcome> {
    // 1) acquire-then-swap: exact 取得 → 成立検証 → 成功なら旧 stream stop
    try {
        const next = await tryGetCamera(target, true);
        if (switchSucceeded(next, target, prevDeviceId)) return { kind: "switched", stream: next, facingMode: target };
        next.getTracks().forEach((t) => t.stop()); // 不成立: 破棄してリカバリへ
    } catch { /* 資源競合等 → 段階2へ */ }
    // 2) 旧 stream を止めてから target を取得
    releaseCamera();
    try {
        const next = await tryGetCamera(target, true);
        return { kind: "switched", stream: next, facingMode: target };
    } catch { /* → 段階3へ */ }
    // 3) 旧 facingMode を再取得 (現行カメラ復旧)
    try {
        stream = await tryGetCamera(previousMode, false);
        if (video) { video.srcObject = stream; await video.play().catch(() => undefined); }
        return { kind: "recovered" };
    } catch (recoverCause) {
        // 4) 旧カメラも喪失 → 恒久フォールバック (classify は 1 回だけ評価)
        releaseCamera();
        const classified = classifyGetUserMediaError(recoverCause);
        const reason = classified.kind === "unavailable" ? classified.reason : "unknown";
        return { kind: "unavailable", reason };
    }
}

function applySwitchOutcome(outcome: CameraSwitchOutcome): void {
    switch (outcome.kind) {
        case "switched": swapStream(outcome.stream, outcome.facingMode); return;
        case "recovered":
            error = "カメラを切り替えできませんでした。現在のカメラで続行します。"; return; // recoverable
        case "unavailable": onCameraUnavailable(outcome.reason); return; // 恒久フォールバック
    }
}

function swapStream(next: MediaStream, mode: FacingMode): void {
    releaseCamera();          // 旧 stream stop
    stream = next; facingMode = mode;
    if (video) { video.srcObject = stream; void video.play().catch(() => undefined); }
    void refreshFlipHint();
}
```
> `devicechange` リスナ（onMount で addEventListener → onDestroy で removeEventListener）で `refreshFlipHint()`。
> **反転ボタンの描画条件**: `canSwitchCamera(phase) && stream !== null && canFlipHint`（live preview が無い時は flip 対象が無いので出さない。Codex 詳細R1-Warning）。描画される時は常に押下可能（disabled にしない＝禁止事項 8）。

### PHPStan 適合チェック
- TS: `getVideoTracks()[0]?` null 安全、`getSettings()` optional、`FacingMode` exact 制約は型 `ConstrainDOMString`。

### テスト計画（S6）
- 段階 1 成功（exact 取得 + 成立検証で swap、旧 stream stop）。
- 段階 2（1 が NotReadableError → 旧 stop 後 target 取得成功）。
- 段階 3（1,2 失敗 → 旧 facingMode 復旧 + `role="alert"` inline エラー、`onCameraUnavailable` を呼ばない）。
- 段階 4（旧カメラ復旧も失敗 → `onCameraUnavailable` を呼ぶ）。
- `getSettings().facingMode` が target と不一致（切替不成立）でリカバリへ流れる。
- 反転ボタンが `idle && stream!==null && canFlipHint` のときのみ描画（録画中は非表示・live preview 無しで非表示・単一カメラで非表示）。
- **canFlipHint 再評価**: 初回カメラ取得成功後に `hasMultipleVideoInputs()` で再評価され、videoinput≥2 で反転ボタンが出現する。`devicechange` イベントで再評価される（addEventListener/removeEventListener のライフサイクルも表明）。
- 反転ボタンを disabled にしない（禁止事項 8）。

### リスク
- exact 指定は一部端末で OverconstrainedError を返す → 段階 2/3 でリカバリ。旧カメラ復旧まで多重 getUserMedia が走るが、いずれも直列・再入ガードで保護。
- 切替中に preview/録画開始が来ても `switching`/`canSwitchCamera` と既存 `starting` ガードで抑止。

---

## S4. 録画タイマー（mm:ss、累積由来）

--- S5 GridOverlay + S6 テスト方針 ---
### GridOverlay.svelte（設計）
```svelte
<script lang="ts">
    /** 三分割グリッドの撮影ガイド overlay (doc/05 §5.2)。焼込ではなく overlay。
        字幕 overlay と共存 (両者 pointer-events-none absolute inset-0)。 */
    interface Props { visible: boolean; }
    let { visible }: Props = $props();
</script>
{#if visible}
    <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="grid-overlay">
        <!-- 三分割線は既存 SubtitleOverlay と同じ scrim 系トークン bg-text + 透過。
             hex/raw palette 不使用。SubtitleOverlay(bg-text/70) と同じカメラ上 overlay の確立パターン -->
        <div class="absolute inset-y-0 left-1/3 w-px bg-text/40"></div>
        <div class="absolute inset-y-0 left-2/3 w-px bg-text/40"></div>
        <div class="absolute inset-x-0 top-1/3 h-px bg-text/40"></div>
        <div class="absolute inset-x-0 top-2/3 h-px bg-text/40"></div>
    </div>
{/if}
```
> 配置は既存の字幕 overlay と同じ `relative` 親（`<video>` を包む div）内。字幕（`justify-between`）と線グリッドは視覚的に競合しない（線は薄い hairline）。

### CameraRecorder のトグル（設計）
- 字幕トグル（既存）と同様に **raw button + `aria-pressed`**（disabled にしない＝禁止事項 8）。
- 既定 OFF（`showGrid=false`）。Lucide `Grid3x3`。`aria-label` は「グリッドを表示/非表示」。

### PHPStan 適合チェック
- TS: props `visible: boolean` のみ。

### テスト計画（S6）
- `GridOverlay`: `visible=false` で非描画、`true` で 4 本の線を含む overlay 描画。
- `CameraRecorder`: グリッドトグルの `aria-pressed` 遷移、既定 OFF、字幕 overlay と同時表示できる（相互排他でない）。

### リスク
### テスト方針
- 既存の `FakeMediaRecorder` stub を拡張（`pause()/resume()` と `onpause/onresume`、`state` を追加。`autoStop` と同様に **onpause/onresume/onstop を手動発火できる API**（`firePause()/fireResume()/fireStop()`）で **UA のイベント順前後を注入**して防波堤（transition の no-op）を回帰検証する。既存 stub の後方互換を保つ）。
- `getUserMedia` mock を facingMode 別に返し分け（`getVideoTracks()[0].getSettings()` に `facingMode`/`deviceId` を持つ fake track）。段階リカバリの各段で resolve/reject を差し込む。
- `navigator.mediaDevices.enumerateDevices` / `addEventListener("devicechange")` を stub し `canFlipHint` 再評価を検証。
- `transition()` は純関数として `camera.test.ts` で遷移表を網羅（UI 非依存）。
- `performance.now` を vi でスタブし duration を決定的に検証。
- fake timers（`vi.useFakeTimers()`）で timer 表示・interval クリーンアップを検証。
- **個別の DatabaseTransactions 不使用**（フロントテストのため DB 非関与。該当なし）。既存テストの削除・上書きなし（追記のみ）。

### 完了条件
- `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build` 全 green。
- 既存 `CameraRecorder.test.ts` / `camera.test.ts` / `CaptureShow.test.ts` が無回帰。

---

## 実装モード
