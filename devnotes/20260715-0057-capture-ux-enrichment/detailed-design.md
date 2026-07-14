# 詳細設計: capture-ux-enrichment（撮影UXの拡充 ※v1スコープ判定込み）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある**作業手順書(SOP)を起点に**、AI が撮るべきカットを設計した**動画シナリオ**を生成し、そのシナリオを**スマホ(PWA)でナビゲーション撮影**することで、専門知識ゼロの現場作業者でも**標準化されたマニュアル動画**を作れるようにする。

- 「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。
- 競合(tebiki)と異なり、標準作業を起点に AI が教材設計し撮影を指示する。
- 熟練者の暗黙知を動画マニュアルという形式知へ変換する装置（SECI）。
- **v1 スコープ**: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項

1. テストなしの実装完了報告。
2. PHPStan エラーの widen・baseline 化。
3. dev DB への破壊操作。
4. `response()->json()` の直書き（DTO / JsonResource / Inertia を使う。仕様固定 endpoint のみ例外）。
5. LLM 呼び出しの Prism 直呼び。
6. prompt 文字列のコード直書き。
7. 操作系 POST の応答での `redirect()->intended()`。
8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する。DESIGN.md）**。

### コーディングルール

- **PHPStan level 10** 必須（本件は PHP 変更なしだが CI は緑必須）。
- **Pest**（本件は PHP テスト対象なし）/ **Vitest**（本件の主テスト）。
- **RefreshDatabase** + `--parallel`（本件は DB 非関与）。
- **DTO + JsonResource** パターン（本件は API 変更なし）。
- フロントは **Svelte 5 runes + DS token/ramp のみ**（`DESIGN.md` canonical、ds-purity テストが検出）。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import。
  アイコンは `@lucide/svelte` のみ。
- 検証コマンド: `pnpm typecheck` / `pnpm lint` / `pnpm test` / `pnpm build`（全 green）。

## 概念設計リファレンス

- [conceptual-design.md](./conceptual-design.md)（Codex `gpt-5.4` レビュー Round 4 で APPROVED）
- 概念レビュー履歴: `conceptual-review-round-{1..4}.md` / `codex-history/`

## v1 スコープ判定の結論（設計の最初の作業・再掲）

doc/05 §5.2 が挙げる撮影補助のうち、doc/10 v1 スコープと技術的容易性・中核価値寄与で判定:

| # | 機能 | v1 判定 | 実装済み? |
|---|------|--------|-----------|
| 1 | 一時停止 / 再開（同一テイク継続） | **v1 採用** | 未実装 |
| 2 | グリッド表示 | **v1 採用** | 未実装 |
| 3 | カメラ反転（idle 限定） | **v1 採用** | 未実装 |
| 4 | 録画タイマー | **v1 採用** | 未実装 |
| 5 | 横持ち全画面 + 左右スワイプ手順移動 + 下部サムネイル即再生 | **out-of-scope（将来）** | — |

→ 1〜4 を実装。5 は大掛かりな撮影 UI 全面刷新のため out-of-scope（既存の縦持ち詳細画面内撮影で v1 撮影フローは成立済み）。「全て out-of-scope / 既実装」ではないため設計・実装を行う。

---

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | camera.ts に phase/facingMode 型・純関数・capability 判定を追加 | `resources/js/lib/capture/camera.ts` | 中核基盤 |
| S2 | 一時停止/再開（phase 拡張 + 過渡状態 + セグメント累積 duration） | `resources/js/components/features/capture/CameraRecorder.svelte` | 中核 |
| S3 | カメラ反転（切替リカバリ段階方式 + capability degrade） | `CameraRecorder.svelte` | 中核 |
| S4 | 録画タイマー（mm:ss、累積由来・setInterval は表示更新のみ） | `CameraRecorder.svelte` | 補助 |
| S5 | グリッド表示（GridOverlay + トグル、字幕 overlay と共存） | 新規 `GridOverlay.svelte` + `CameraRecorder.svelte` | 補助 |
| S6 | Vitest: 純関数 / phase 遷移 / 切替リカバリ / duration / grid / timer | `tests/js/lib/capture/camera.test.ts` / `tests/js/components/features/capture/CameraRecorder.test.ts` / 新規 `GridOverlay.test.ts` | テスト |

**波及変更の全体像**: 本件は撮影 PWA フロントのみ。**バックエンド（API/ルート/DTO/JsonResource/Policy/Migration/Factory）・PC 側・型定義 `types/capture.ts` は変更なし**。親 `Capture/Show.svelte` と `TakeStrip.svelte` の配線（props / export メソッド）も**非破壊**（既存 export シグネチャ不変）。preview 排他で使う `onCaptureActiveChange(active)` の意味論（active=撮影中）は不変で、`paused`/`pausing`/`resuming` も active=true に包含されるため親の変更は不要。

---

## S1. camera.ts に型・純関数・capability 判定を追加

### 変更箇所
- ファイル: `resources/js/lib/capture/camera.ts`（末尾に追加。既存 export は無改変）

### 波及変更
- TypeScript 型定義: `camera.ts` 内に完結（`CaptureCut` 等は無関係）。
- API Resource/DTO: なし。
- テストファイル: `tests/js/lib/capture/camera.test.ts` に純関数・capability のケース追加（S6）。

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
    | "start"       // recorder.start() 成功
    | "pause"       // ユーザー一時停止要求 (recorder.pause() 発行)
    | "onpause"     // recorder.onpause 確定
    | "pauseFailed" // recorder.pause() 同期例外 (過渡の巻き戻し)
    | "resume"      // ユーザー再開要求 (recorder.resume() 発行)
    | "onresume"    // recorder.onresume 確定
    | "resumeFailed"// recorder.resume() 同期例外 (過渡の巻き戻し)
    | "stop"        // ユーザー停止要求 (recorder.stop() 発行)
    | "abort"       // 異常終了 (recorder.onerror / track.onended)。全 phase から stopping へ
    | "onstop";     // recorder.onstop 確定

/**
 * phase 遷移の単一真実源 (Codex 詳細R1/R2-Critical/Warning)。
 * 不正な (phase, event) は現 phase を維持 (no-op)。UA のイベント順前後や過渡中の
 * 再操作を構造的に無視する防波堤。stopping は終端優先 (onpause/onresume を無視)。
 * 過渡確定 (onpause/onresume) と巻き戻し (pauseFailed/resumeFailed) は **過渡 phase からのみ**
 * 受理する (重複イベントが recording/paused の副作用へ入るのを構造的に防ぐ。R2-Critical)。
 */
export function transition(phase: CapturePhase, event: CaptureEvent): CapturePhase {
    switch (event) {
        case "start":        return phase === "idle" ? "recording" : phase;
        case "pause":        return phase === "recording" ? "pausing" : phase;
        case "onpause":      return phase === "pausing" ? "paused" : phase;       // 過渡からのみ
        case "pauseFailed":  return phase === "pausing" ? "recording" : phase;    // 過渡巻き戻し
        case "resume":       return phase === "paused" ? "resuming" : phase;
        case "onresume":     return phase === "resuming" ? "recording" : phase;   // 過渡からのみ
        case "resumeFailed": return phase === "resuming" ? "paused" : phase;      // 過渡巻き戻し
        // stop はユーザー要求。recording/paused からのみ stopping へ。過渡/終端では無視
        case "stop":         return phase === "recording" || phase === "paused" ? "stopping" : phase;
        // abort は異常終了。idle 以外の全 phase (過渡含む) から stopping へ (詰み回避。R3-Critical)
        case "abort":        return phase === "idle" ? "idle" : "stopping";
        case "onstop":       return "idle"; // 全 phase から idle へ収束 (終端)
    }
}
```

### PHPStan 適合チェック
- 該当なし（TS）。TypeScript strict: union は exhaustive、`?.` で null 安全。

### テスト計画（S6 に集約）
- `oppositeFacingMode` の双方向、`canPause/canResume/canStop/canSwitchCamera` を全 6 phase で表明。
- `supportsPauseResume` が pause/resume どちらか欠落で false。
- `hasMultipleVideoInputs` が videoinput 数 0/1/2 と enumerateDevices 例外で期待値。
- **`transition(phase, event)` の遷移表を網羅**: 正常遷移（idle→recording→pausing→paused→resuming→recording→stopping→idle）、巻き戻し（pausing→pauseFailed→recording / resuming→resumeFailed→paused）、および不正な (phase,event) が現 phase を維持すること。**特に重複イベントの防波堤**: `transition("recording","onresume")==="recording"`（副作用なし）、`transition("paused","onpause")==="paused"`、`transition("stopping","onresume")==="stopping"`、`transition("stopping","onpause")==="stopping"`。**abort**: `transition("pausing","abort")==="stopping"`、`transition("resuming","abort")==="stopping"`、`transition("recording","abort")==="stopping"`、`transition("idle","abort")==="idle"`。

### リスク
- `MediaRecorder.prototype` へのアクセスは jsdom stub でも安全（optional chaining）。低リスク。

---

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
  - **`switching` 排他（Codex 詳細R2-Critical）**: `startRecording` / `releaseForPreview` / `resumeAfterPreview` の early-return 条件に `switching` を追加し、`active` にも `switching` を含める（S3 のカメラ切替中は録画開始・preview 解放・復帰を抑止）。
  - **異常終了の abort 経路（Codex 詳細R3-Critical）**: `recorder.onerror` / `track.onended` を `safeStop` から `handleAbort` に置換（過渡 phase 中の異常終了でも `stopping→idle` へ収束し active を解除）。
  - **セッションオブジェクト分離（Codex 詳細R4/R5-Critical）**: `RecordingSession` オブジェクト（id/recorder/mimeType/chunks/accumulatedMs/segmentStart/finalized）+ `finalizeSession()` 一度限り finalizer。全 recorder ハンドラ・handleAbort は生成時 `session` を closure 保持し `session !== activeSession` で遅延イベントを no-op 化。`chunks`/duration/`mimeType` を session に閉じ新旧を物理分離。`onDestroy` に `clearFinalizeWatchdog()` を追加。

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

// --- 録画セッションオブジェクト (Codex 詳細R4/R5-Critical) ---
// MediaRecorder は error/inactive 後も遅延 dataavailable/stop を配送し得る。chunks/duration/mimeType を
// **セッションオブジェクトに閉じ**、全ハンドラが生成時の session を closure で保持することで、新旧録画の
// 状態を物理的に分離する。finalizer も activeSession に一致する session のみ処理する。
interface RecordingSession {
    id: number;
    recorder: MediaRecorder;
    mimeType: string;
    chunks: Blob[];
    // duration の source of truth: active recording セグメントの壁時計和 (performance.now 差)。
    // 背景継続録画では blob 実尺と一致。表示 tick のみ tab サスペンドで遅延し得るが duration に影響なし
    // (再基準化は導入しない=複雑化回避。Codex 詳細R1-Warning)。
    accumulatedMs: number;
    segmentStart: number | null; // 現 recording セグメント開始 (null=計測停止中)
    finalized: boolean;          // 一度限り finalize フラグ
}
let sessionSeq = 0;
let activeSession = $state<RecordingSession | null>(null); // 現在の録画セッション (idle 時も直近を保持=タイマー凍結表示用)
let finalizeWatchdog: ReturnType<typeof setTimeout> | null = null;
const FINALIZE_WATCHDOG_MS = 500; // inactive abort 時、遅延 onstop を待つ猶予

function elapsedMs(s: RecordingSession): number {
    return s.accumulatedMs + (s.segmentStart !== null ? performance.now() - s.segmentStart : 0);
}
// recording セグメントを閉じる (二重加算しない。segmentStart=null で冪等)
function closeSegment(s: RecordingSession): void {
    if (s.segmentStart !== null) { s.accumulatedMs += performance.now() - s.segmentStart; s.segmentStart = null; }
}

// phase 遷移は transition() 純関数に一元化。event を渡し結果 phase を setPhase する。
function dispatch(event: CaptureEvent): CapturePhase {
    const next = transition(phase, event);
    if (next !== phase) setPhase(next);
    return next;
}

// active に switching を含める (カメラ切替中も排他。Codex 詳細R2-Critical)。switching は S3 で宣言。
function syncActive(): void {
    const active = starting || previewResuming || switching || phase !== "idle";
    if (active !== lastActive) { lastActive = active; onCaptureActiveChange?.(active); }
}

function clearFinalizeWatchdog(): void {
    if (finalizeWatchdog !== null) { clearTimeout(finalizeWatchdog); finalizeWatchdog = null; }
}

// --- 一度限り finalizer (onstop と abort fallback を集約。Codex 詳細R4/R5) ---
// stale (activeSession 不一致) / 二重 (finalized) は no-op。active 解除 (dispatch onstop) はここでのみ。
// mimeType/chunks/accumulatedMs は session から参照 (外側ローカルに依存しない=Codex R5-Critical)。
async function finalizeSession(s: RecordingSession): Promise<void> {
    if (s !== activeSession || s.finalized) return; // stale/dup → no-op
    s.finalized = true;
    clearFinalizeWatchdog();
    closeSegment(s); stopTimer();
    try {
        const blob = new Blob(s.chunks, { type: s.mimeType });
        if (blob.size > 0) await onCaptured(blob, s.mimeType, Math.round(s.accumulatedMs));
    } catch {
        error = "撮影データの処理に失敗しました。もう一度お試しください。";
    } finally {
        dispatch("onstop"); // 全 phase から idle へ収束
    }
}

// --- startRecording 内: session を生成し全ハンドラを closure で束ねる (抜粋) ---
// recorder 構築成功後:
//   const session: RecordingSession = { id: ++sessionSeq, recorder, mimeType, chunks: [],
//       accumulatedMs: 0, segmentStart: null, finalized: false };
//   activeSession = session; // 旧 session は以後 stale (全ハンドラ・finalizer が no-op)
// 各ハンドラは session を closure 参照し、activeSession 不一致の遅延イベントを無視する:
recorder.ondataavailable = (event) => {
    if (session !== activeSession) return;             // 旧 session の遅延 chunk を無視
    if (event.data.size > 0) session.chunks.push(event.data);
};
recorder.onpause = () => {
    if (session !== activeSession || phase !== "pausing") return; // 現 session かつ pausing のみ
    closeSegment(session); dispatch("onpause"); stopTimer();
};
recorder.onresume = () => {
    if (session !== activeSession || phase !== "resuming") return; // 現 session かつ resuming のみ
    session.segmentStart = performance.now(); dispatch("onresume"); startTimer();
};
recorder.onstop = () => { void finalizeSession(session); };
recorder.onerror = () => handleAbort(session);                       // ★ session を渡す (Codex R5)
stream.getTracks().forEach((track) => { track.onended = () => handleAbort(session); }); // ★
// recorder.start() 成功後: session.segmentStart = performance.now(); dispatch("start"); startTimer();

// 一時停止 (recording→pausing→paused)。canPause で UI ガード + transition で状態ガード。
function pauseRecording(): void {
    if (!canPause(phase) || activeSession === null) return;
    dispatch("pause"); // → pausing
    try { activeSession.recorder.pause(); } // 成功時 onpause で paused 確定
    catch { dispatch("pauseFailed"); error = "一時停止できませんでした。もう一度お試しください。"; } // pausing→recording
}
// 再開 (paused→resuming→recording)。
function resumeRecording(): void {
    if (!canResume(phase) || activeSession === null) return;
    dispatch("resume"); // → resuming
    try { activeSession.recorder.resume(); } // 成功時 onresume で recording 確定
    catch { dispatch("resumeFailed"); error = "録画を再開できませんでした。もう一度お試しください。"; } // resuming→paused
}

// 停止: recording/paused から可 (過渡/終端では transition が no-op)。
function safeStop(): void {
    if (!canStop(phase)) return; // pausing/resuming/stopping/idle は no-op
    dispatch("stop"); // → stopping
    if (activeSession === null) { fatalStopCleanup(); return; }
    try { activeSession.recorder.stop(); } catch { fatalStopCleanup(); }
}

// 異常終了 (recorder.onerror / track.onended)。ユーザー stop と分離し、過渡 phase でも収束させる。
// session 引数で世代分離 (Codex 詳細R5-Critical): 旧 recorder の遅延 error が新 session を停止しない。
function handleAbort(session: RecordingSession): void {
    if (session !== activeSession || session.finalized) return; // 旧 session / 確定済みは無視
    if (phase === "idle") return;
    dispatch("abort"); // → stopping (全 phase から)
    if (session.recorder.state !== "inactive") {
        try { session.recorder.stop(); return; } catch { /* → watchdog fallback */ }
    }
    // recorder 既に inactive / stop 不能: 遅延 onstop を待ちつつ watchdog で保険 finalize (Codex R4)。
    // 遅延 onstop が先に来れば finalizeSession が idempotent に確定し watchdog は no-op。
    clearFinalizeWatchdog();
    finalizeWatchdog = setTimeout(() => { void finalizeSession(session); }, FINALIZE_WATCHDOG_MS);
}
```
> **配線変更**: 既存の `recorder.onerror = () => safeStop();` と `track.onended = () => safeStop();` を
> **`() => handleAbort(session)`** に置換（過渡 phase 中の異常終了でも収束・世代分離）。正常 recording 中の
> 異常終了は従来同様 recorder.stop()→onstop→finalizeSession で部分テイクを救出（`state !== "inactive"` 経路）。
> **世代分離**: 全ハンドラ・finalizer・handleAbort は生成時 `session` を closure 保持し `session !== activeSession`
> で遅延イベントを no-op 化。`chunks`/`accumulatedMs`/`mimeType` は session オブジェクトに閉じ、新旧で物理分離。
> **onDestroy**: 既存の `onDestroy(releaseCamera)` に加え `clearFinalizeWatchdog()` を行う（watchdog リーク防止）。
> **start 初期化 + 世代更新**: `startRecording` で recorder 構築成功後に `session` を生成し `activeSession = session`。
> `recorder.start()` 成功で `session.segmentStart = performance.now(); dispatch("start")`（onpause/onresume 未発火なので
> recording として segmentStart を張る）。以後、旧 session の遅延イベントは全経路で no-op。
> **タイマー表示**: `displayMs` は `activeSession ? elapsedMs(activeSession) : 0` から派生（S4）。
> **同期例外の巻き戻し**: pause/resume の同期例外時は `dispatch("pauseFailed")` / `dispatch("resumeFailed")` で過渡 phase を直前 phase（recording/paused）へ戻す（状態機械外の遷移経路を残さない。Codex 詳細R2-Warning）。
> **canPause/canResume の UI ガードと transition の状態ガードは二重防御**（UI は canX で出し分け、実遷移は transition が受理判定）。

### 状態遷移表（テストで固定）
```
idle --start--> recording
recording --pause()--> pausing --onpause--> paused
paused --resume()--> resuming --onresume--> recording
recording --stop()--> stopping --onstop--> idle
paused   --stop()--> stopping --onstop--> idle
pausing/resuming: 再操作(pause/resume/stop)・preview・カメラ切替を拒否 (canX が false)
同期例外(pause/resume throw): pauseFailed/resumeFailed で直前 phase へ戻す (state machine 経由)
track.onended / recorder.onerror --> handleAbort (abort event: idle 以外の全 phase → stopping → onstop → idle)
  - recording/paused の異常終了: recorder.stop() で部分テイク救出
  - pausing/resuming の異常終了: recorder active なら stop()、inactive なら releaseCamera + onstop で idle 収束
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
- **異常終了の abort 収束（Codex 詳細R3-Critical）**: `pausing → recorder.onerror → idle`（active=false・再撮影可能）、`resuming → track.onended → idle`。recorder が active なら stop()→onstop で救出。inactive なら watchdog（fake timers で `FINALIZE_WATCHDOG_MS` 経過）で finalize → idle 収束。異常終了後に遅延した `onpause`/`onresume` が届いても phase=stopping/idle で巻き戻さない（source phase ガード）。
- **セッションオブジェクト分離（Codex 詳細R4/R5-Critical）**: inactive abort → watchdog finalize → 新録画開始（`activeSession` 更新）後に、旧 recorder の遅延 `onstop`/`ondataavailable`/`onerror`/`track.onended` が発火しても no-op（新 session の chunks を汚さない・新 phase を idle へ戻さない・新 recorder を停止しない・onCaptured を二重発火しない）。`finalizeSession` と `handleAbort` の `session !== activeSession || finalized` ガードを表明。旧 `onerror` が新 session を停止しないケースを追加。
- **無回帰**: 既存の成功/失敗/フォールバック/preview 排他ケースが無改変で通る（active 通知の一元性・`previewResuming` リネーム後も同一挙動）。recording 中の onerror/track.onended は従来同様 recorder.stop()→onstop で部分テイク救出。

### リスク
- MediaRecorder の pause/resume は iOS Safari で挙動差がある。capability degrade（非対応で非表示）+ 同期例外の前 phase 復帰でフェイルセーフ。
- `previewResuming` リネームは参照箇所を全置換（onstop/preview 系のみ）。テストの内部参照はないため後方互換の並走不要。

---

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
let switching = false;           // 切替中の再入ガード (active に含める。startRecording/releaseForPreview/resumeAfterPreview も switching で早期 return)

// 既存メソッドの early-return に switching を追加 (Codex 詳細R2-Critical):
//   startRecording:      if (starting || previewResuming || switching || phase !== "idle") return;
//   releaseForPreview:   if (starting || previewResuming || switching || phase !== "idle") return;
//   resumeAfterPreview:  if (previewResuming) return ...; if (!wasActiveBeforePreview || starting || switching || phase !== "idle") return ...;

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
    syncActive(); // 切替開始時点で active=true (getUserMedia 待機中も録画開始/preview を抑止)
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
    // 2) 旧 stream を止めてから target を取得 (段階2でも成立検証。Codex 詳細R2-Warning)
    releaseCamera();
    try {
        const next = await tryGetCamera(target, true);
        // prevDeviceId は解放済みだが、getSettings().facingMode が target と一致するかで判定
        if (switchSucceeded(next, target, prevDeviceId)) return { kind: "switched", stream: next, facingMode: target };
        next.getTracks().forEach((t) => t.stop()); // 不成立: 破棄して段階3へ
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
- 段階 2（1 が NotReadableError → 旧 stop 後 target 取得 + **成立検証** で switched）。
- 段階 2 不成立（取得成功だが getSettings が target と不一致 → stop して段階3へ）。
- 段階 3（1,2 失敗 → `previousMode` で旧 facingMode 復旧 + `role="alert"` inline エラー、`onCameraUnavailable` を呼ばない）。
- 段階 4（旧カメラ復旧も失敗 → `onCameraUnavailable` を呼ぶ、classify は 1 回評価）。
- `getSettings().facingMode` が target と不一致（切替不成立）で段階1からリカバリへ流れる。
- **switching 排他**: switchCamera の getUserMedia 待機中に `onCaptureActiveChange(true)` が発火し、`startRecording` / `releaseForPreview` が no-op（getUserMedia を増やさない）。切替完了で active=false へ戻る。
- 反転ボタンが `idle && stream!==null && canFlipHint` のときのみ描画（録画中は非表示・live preview 無しで非表示・単一カメラで非表示）。
- **canFlipHint 再評価**: 初回カメラ取得成功後に `hasMultipleVideoInputs()` で再評価され、videoinput≥2 で反転ボタンが出現する。`devicechange` イベントで再評価される（addEventListener/removeEventListener のライフサイクルも表明）。
- 反転ボタンを disabled にしない（禁止事項 8）。

### リスク
- exact 指定は一部端末で OverconstrainedError を返す → 段階 2/3 でリカバリ。旧カメラ復旧まで多重 getUserMedia が走るが、いずれも直列・再入ガードで保護。
- 切替中に preview/録画開始が来ても `switching`/`canSwitchCamera` と既存 `starting` ガードで抑止。

---

## S4. 録画タイマー（mm:ss、累積由来）

### 変更箇所
- ファイル: `CameraRecorder.svelte`
  - `displayMs = $state(0)`（表示専用）+ `setInterval` は recording 中のみ 250ms 間隔で `displayMs = elapsedMs()` を更新。
  - pause/stop/destroy で interval クリア。paused は displayMs を最後の値で凍結。
  - mm:ss フォーマットは純関数 `formatElapsed(ms)`（camera.ts、テスト対象）。

### 波及変更
- テストファイル: `CameraRecorder.test.ts`（表示）+ `camera.test.ts`（`formatElapsed`）。

### 変更後コード（設計）
```ts
// camera.ts
export function formatElapsed(ms: number): string {
    const totalSec = Math.max(0, Math.floor(ms / 1000));
    const mm = String(Math.floor(totalSec / 60)).padStart(2, "0");
    const ss = String(totalSec % 60).padStart(2, "0");
    return `${mm}:${ss}`;
}
```
```ts
// CameraRecorder.svelte
let displayMs = $state(0);
let timerId: ReturnType<typeof setInterval> | null = null;
function refreshDisplay(): void { displayMs = activeSession ? elapsedMs(activeSession) : 0; }
function startTimer(): void { stopTimer(); timerId = setInterval(refreshDisplay, 250); }
function stopTimer(): void { if (timerId !== null) { clearInterval(timerId); timerId = null; } refreshDisplay(); }
// start/onresume で startTimer、onpause/onstop/onDestroy で stopTimer (最後に凍結値を refreshDisplay)
```
> **真実源は `elapsedMs()`（performance.now 累積）**。setInterval はあくまで再描画トリガーで、時間そのものの計測には使わない（Codex R2）。

### PHPStan 適合チェック
- TS: `timerId` の null ガード、`ReturnType<typeof setInterval>` で環境差吸収。

### テスト計画（S6）
- `formatElapsed`: 0→"00:00"、65_000→"01:05"、負値→"00:00"、3_599_000→"59:59"。
- recording でタイマー要素が表示、idle で非表示。pause 中は値が進まない（fake timers で表明）。
- onDestroy で interval がクリアされる（リーク防止）。

### リスク
- fake timers と `performance.now` の併用でテストが不安定になりうる → `performance.now` を vi でスタブし決定的にする。

---

## S5. グリッド表示（GridOverlay + トグル）

### 変更箇所
- 新規ファイル: `resources/js/components/features/capture/GridOverlay.svelte`（無状態 presentational）。
- `CameraRecorder.svelte`: `showGrid = $state(false)` + トグルボタン（Lucide `Grid3x3`）+ `<GridOverlay visible={showGrid} />` を字幕 overlay と並置。

### 波及変更
- Atomic Design: `GridOverlay` は features/capture 配下（SubtitleOverlay と同階層・同パターン）。atom/molecule を逆流しない。
- svg-inline-allowlist: SVG 直書きなし（線は div/border の DS token）。
- テストファイル: 新規 `tests/js/components/features/capture/GridOverlay.test.ts` + `CameraRecorder.test.ts` にトグル配線。

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
- グリッド線の視認性は背景動画次第。既存 `SubtitleOverlay` の `bg-text/70` と同系の scrim トークンに合わせ（`bg-text/40`）、確立済みのカメラ上 overlay パターンに整合させる。存在しない contrast トークンを新設しない（DESIGN.md 準拠）。低リスク。

---

## S6. テスト（Vitest）

### 変更箇所
- `tests/js/lib/capture/camera.test.ts`（純関数・capability・formatElapsed）。
- `tests/js/components/features/capture/CameraRecorder.test.ts`（pause/resume・duration・切替リカバリ・timer・grid）。既存ケースは無改変（後方互換確認ケースを維持）。
- 新規 `tests/js/components/features/capture/GridOverlay.test.ts`。

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

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 単一コンポーネント（`CameraRecorder.svelte`）+ 補助 lib（`camera.ts`）+ 小さな新規 presentational（`GridOverlay.svelte`）の追加。既存 API/DTO/ルート/PC 側に波及せず、他 TODO と競合しにくい。既存テストを壊さず追記で完結する。 |
| 競合リスク | 低。撮影 PWA の `CameraRecorder` に閉じる。T047（字幕 overlay）/ T050（preview 排他）と同一ファイルを触るが、いずれも**追加**であり `onCaptureActiveChange` の意味論・export シグネチャは不変。`previewResuming` リネームは同一ファイル内に閉じる。 |

## 使命・禁止事項チェック（最終）

- **使命寄与**: 撮り直し削減・詰み回避・テイク継続性の維持で「専門知識ゼロの現場作業者がマニュアル動画を作れる」に寄与。out-of-scope（横持ち全画面刷新）を切り、v1 中核に集中（オーバーエンジニアリング回避＝思考原則 2）。
- **禁止事項 8**: capability 非対応・phase 上操作不能は「ボタン非表示」で対応（disabled UI を作らない）。操作可能時のトグル/ボタンは常に押下可能。
- **後方互換の並走を残さない（思考原則 3）**: `Phase` ローカル型→`CapturePhase`、`resuming`→`previewResuming` を同一 PR で置換。
- **テスト必須**: 全施策に Vitest（S6）。既存テストの削除・上書きなし。
- **セキュリティ**: 認可・入力・tenant キー・SSRF・課金いずれも本件のフロント撮影補助 UI は非関与（API/DTO 変更なし）。セキュリティ不変条件への影響なし。
