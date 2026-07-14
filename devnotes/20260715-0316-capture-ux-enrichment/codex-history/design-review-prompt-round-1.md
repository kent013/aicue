# 使命・禁止事項・思考原則（全レビューに適用）

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 撮影者・教える人のスキルに品質を依存させない。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項

1. テストなしの実装完了報告
2. PHPStan エラーの widen・baseline 化
3. dev DB への破壊操作
4. `response()->json()` の直書き
5. LLM 呼び出しの Prism 直呼び
6. prompt 文字列のコード直書き
7. 操作系 POST の応答での `redirect()->intended()`
8. 必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示。DESIGN.md）

## 思考原則

まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵（Laravel/Svelte）を探せ。機能の名前に立ち返れ。今必要なものだけ作れ（オーバーエンジニアリング禁止）。

## ツール使用制限

コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

# system: レビュアー役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript / PHPStan level 10 / Pest / DTO+JsonResource / Laratrust RBAC。**本件はフロントエンド完結**（サーバ変更なし）。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性。特に MediaRecorder pause/resume のイベント基準状態遷移、facingMode 段階的縮退、timer の累積計測とリーク）
2. 既存コードとの整合性（命名規約、パターン、既存 phase マシン・active 通知・preview 排他・F-03 フォールバックを壊さないか）
3. 型安全性（TypeScript / Svelte 5 runes。union 網羅、handle 型）
4. テスト計画の網羅性（各施策に Vitest。既存 20 ケースの回帰なし）
5. 副作用・後退リスク（durationMs の意味是正、stopping 中のボタン表示、overlay z 順）
6. 波及変更の網羅性（onCaptured シグネチャ不変、親 Show.svelte 無改変が妥当か）
7. DESIGN.md 準拠（DS token のみ、hex 直書きなし）
8. Atomic Design 準拠（GridOverlay の features/capture 配置、Lucide のみ、SVG 直書きなし）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類
- Critical/Warning には必ず修正案を添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

# user: 詳細設計書 + 関連現行コード

## 詳細設計書

（別添 detailed-design.md を以下に貼付）

---
# 詳細設計: capture-ux-enrichment（撮影UXの拡充）

## 使命・制約（絶対遵守）

### アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 撮影者・教える人のスキルに品質を依存させない。v1 スコープ: 字幕のみ / 撮影は PWA / 動画合成は自前 ffmpeg / 単一 Default Project。

### 禁止事項（本件に関係する核）

- テストなしの実装完了報告（各施策に Vitest 必須）
- 必須条件未充足を理由にボタンを disabled にする UI（禁止事項8。押下でエラー表示 or 文脈非該当なら非表示）
- （本件はフロント完結のため PHPStan/DTO/response()->json() 系は非該当だが、TypeScript/型安全は厳守）

### コーディングルール（本件で適用されるもの）

- フロントは **Svelte 5 runes** + **DS token/ramp のみ**（`DESIGN.md` が canonical、ds-purity テストが検出）。hex 直書き禁止。
- component 階層は `atoms → molecules → organisms → features/{domain} → templates → pages` の単方向 import（`tests/js/architecture/atomic-import-graph.test.ts` が強制）。本件の新規 `GridOverlay.svelte` は既存 `SubtitleOverlay.svelte` と同じ **features/capture** 層に置く（同層参照は CameraRecorder → GridOverlay の features 内包で成立）。
- アイコンは **`@lucide/svelte` のみ**（SVG 直書き禁止。`svg-inline-allowlist.test.ts` が強制）。
- 検証コマンド: `pnpm lint` / `pnpm typecheck` / `pnpm test` / `pnpm build`（全 green でコミット）。
- テストは Vitest + `@testing-library/svelte`（既存 `CameraRecorder.test.ts` の様式に従う）。

## 概念設計リファレンス

[conceptual-design.md](./conceptual-design.md)（Codex gpt-5.4 レビュー Round 4 で APPROVED）

## 施策一覧

| # | 施策名 | 変更ファイル | 優先度 |
|---|--------|------------|--------|
| S1 | camera.ts ヘルパ拡充（型・pure 関数・能力検査） | `resources/js/lib/capture/camera.ts` | core 前提 |
| S2 | GridOverlay.svelte 新規（三分割 overlay） | `resources/js/components/features/capture/GridOverlay.svelte`（新規） | core |
| S3 | CameraRecorder: 録画タイマー | `resources/js/components/features/capture/CameraRecorder.svelte` | core |
| S4 | CameraRecorder: 一時停止/再開（phase=paused, イベント基準） | 同上 | core |
| S5 | CameraRecorder: グリッドトグル配線 | 同上 | core |
| S6 | CameraRecorder: カメラ反転（guarded, 段階的縮退） | 同上 | guarded |
| S7 | テスト（camera.ts / GridOverlay / CameraRecorder 追記） | `tests/js/lib/capture/camera.test.ts`, `tests/js/components/features/capture/GridOverlay.test.ts`（新規）, `tests/js/components/features/capture/CameraRecorder.test.ts` | 必須 |

**波及変更の全体像**: 本件は**フロントエンド完結**。サーバ API・ルート・DTO・Inertia Props・PHP は一切変更しない。`onCaptured(blob, mimeType, durationMs)` の**シグネチャは不変**（durationMs は number のまま、意味のみ実録画尺へ是正）。親 `Capture/Show.svelte` の呼び出し側も無改変（新規 props はすべて optional で後方互換）。

---

## S1. camera.ts ヘルパ拡充

### 変更箇所
- ファイル: `resources/js/lib/capture/camera.ts`（末尾に追加。既存 export は無改変）

### 波及変更
- TypeScript 型定義: `FacingMode` を新規 export。`CameraRecorder.svelte` が import。
- API Resource/DTO: なし
- テストファイル: `tests/js/lib/capture/camera.test.ts` に formatElapsed / nextFacingMode / supportsPauseResume のケース追加

### 追加コード
```ts
/** 前後カメラの facingMode（doc/05 §5.2 カメラ反転 in/out）。型の単一ソース。 */
export type FacingMode = "environment" | "user";

/** environment ⇄ user の反転。型の単一ソース化 + テスト容易性のため pure 関数化。 */
export function nextFacingMode(mode: FacingMode): FacingMode {
    return mode === "environment" ? "user" : "environment";
}

/**
 * 経過ミリ秒を mm:ss へ整形（録画タイマー表示用。doc/05 §5.2「00:00」）。
 * 負値・NaN は 0 に丸め、60 分以上も mm が桁溢れして連続表示される（分を切り捨てない）。
 */
export function formatElapsed(ms: number): string {
    const totalSeconds = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;
    const minutes = Math.floor(totalSeconds / 60);
    const seconds = totalSeconds % 60;
    const mm = String(minutes).padStart(2, "0");
    const ss = String(seconds).padStart(2, "0");
    return `${mm}:${ss}`;
}

/**
 * MediaRecorder が pause()/resume() を提供するかの **存在確認のみ**（doc/05 §5.2 一時停止/再開）。
 * 注意: これは API の存在確認であって正常動作の保証ではない。実行時の InvalidStateError や
 * pause/resume イベント未到達への退行（recorder.state からの phase 復旧）が最終防御。
 */
export function supportsPauseResume(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof window.MediaRecorder.prototype?.pause === "function" &&
        typeof window.MediaRecorder.prototype?.resume === "function"
    );
}
```

### 型安全チェック
- [x] `FacingMode` は string literal union（`facingMode` state / getUserMedia constraint の単一ソース）
- [x] `formatElapsed` は `number → string` の pure。NaN/負値の防御あり
- [x] `supportsPauseResume` は `boolean` を返す。`?.` で prototype 欠如環境に安全

### テスト計画
- [x] `nextFacingMode("environment") === "user"` / `nextFacingMode("user") === "environment"`
- [x] `formatElapsed`: 0→"00:00", 5000→"00:05", 65000→"01:05", 3599000→"59:59", 3600000→"60:00", -1→"00:00", NaN→"00:00"
- [x] `supportsPauseResume`: prototype に pause/resume がある stub で true、無い stub で false

### リスク
- 低。既存 export に触れないため回帰なし。

---

## S2. GridOverlay.svelte（新規・三分割 overlay）

### 変更箇所
- ファイル: `resources/js/components/features/capture/GridOverlay.svelte`（新規）

### 波及変更
- 参照元: `CameraRecorder.svelte`（features/capture 内包 = atomic import graph 準拠）
- テストファイル: `tests/js/components/features/capture/GridOverlay.test.ts`（新規）

### 実装（SubtitleOverlay.svelte を先例に踏襲）
```svelte
<script lang="ts">
    /**
     * 撮影プレビューへ重畳する三分割グリッド（doc/05 §5.2 グリッド表示）。
     * 構図補助の overlay で MediaRecorder が録る MediaStream には含まれない（焼込ではない）。
     * z 順は映像 < grid < 字幕帯（SubtitleOverlay）。字幕帯と重なっても字幕優先で可読。
     */
    interface Props {
        visible: boolean;
    }
    let { visible }: Props = $props();
</script>

{#if visible}
    <div
        class="pointer-events-none absolute inset-0"
        aria-hidden="true"
        data-testid="grid-overlay"
    >
        <!-- 三分割: 縦 2 本・横 2 本の細線。DS token surface の半透明で映像上に薄く表示 -->
        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/40"></div>
        <div class="absolute inset-y-0 left-2/3 w-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-1/3 h-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-2/3 h-px bg-surface/40"></div>
    </div>
{/if}
```

### 型安全チェック
- [x] Props は `{ visible: boolean }` のみ。runes（`$props`）準拠
- [x] DS token（`bg-surface`）のみ使用、hex 直書きなし（ds-purity）
- [x] `aria-hidden="true"`（装飾 overlay。字幕と違い読み上げ不要）

### 波及・配置チェック（Atomic Design）
- [x] features/capture 層に配置（SubtitleOverlay と同層）
- [x] Lucide/SVG 直書きなし（div 罫線のみ）

### テスト計画
- [x] `visible=true` で `grid-overlay` が描画される
- [x] `visible=false` で描画されない
- [x] 罫線 4 本（縦 2・横 2）が存在する

### リスク
- 低。純表示コンポーネント。字幕帯との z 順は DOM 順で grid を先に置き字幕を後に置く（CameraRecorder のマークアップ順で担保）。

---

## S3〜S6. CameraRecorder.svelte 変更

現行の phase マシン（idle/recording/stopping）・active 通知（syncActive）・preview 排他（releaseForPreview/resumeAfterPreview）・字幕トグル・F-03 フォールバックは**保持**し、以下を追加する。

### 変更箇所
- ファイル: `resources/js/components/features/capture/CameraRecorder.svelte`

### 波及変更
- Props: 変更なし（`onCaptured` / `onCameraUnavailable` / `subtitlePrimary` / `subtitleSecondary` / `onCaptureActiveChange`）。新規 props は追加しない（grid/timer/facingMode/pause は内部状態）。
- `Capture/Show.svelte`: 無改変（呼び出し側は変わらない）
- export メソッド（`releaseForPreview` / `resumeAfterPreview`）: シグネチャ不変
- テストファイル: `tests/js/components/features/capture/CameraRecorder.test.ts` に追記（既存ケースは無改変）

### S4-a. Phase union 拡張と in-flight 状態

```ts
// 単一ソース union（R2 反映: paused を追加）
type Phase = "idle" | "recording" | "paused" | "stopping";

let phase = $state<Phase>("idle");
// pause/resume 要求の in-flight ガード（R2/R3: 多重押下防止・イベント基準遷移）
let pauseResumePending = false;
// pause/resume イベント未到達検出のタイムアウト handle（R3-S）
let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;
```

`active` の算出は不変（paused は非 idle なので active=true → preview 排他を保持）:
```ts
const active = starting || resuming || phase !== "idle";
```

### S4-b. pause/resume（イベント基準・in-flight ガード・タイムアウト復旧）

`startRecording` 内の recorder 構築時に onpause/onresume ハンドラを配線する:
```ts
recorder.onpause = () => {
    clearPauseResumePending();
    stopTimer();                // 経過計測を止める（累積は保持）
    if (phase === "recording") setPhase("paused");
};
recorder.onresume = () => {
    clearPauseResumePending();
    startTimer();               // 経過計測を再開（累積へ加算）
    if (phase === "paused") setPhase("recording");
};
```

要求ハンドラ（ボタン押下 = 要求。phase は同期で動かさずイベントで確定）:
```ts
// 一時停止要求（recording のみ）
function requestPause(): void {
    if (phase !== "recording" || pauseResumePending || recorder === null) return;
    if (!supportsPauseResume()) return; // 未対応端末はボタン非表示のため通常到達しない
    pauseResumePending = true;
    armPauseResumeTimeout();
    try {
        recorder.pause();
    } catch {
        // InvalidStateError 等: recorder.state から phase を復旧
        recoverPhaseFromRecorderState();
    }
}

// 録画再開要求（paused のみ）
function requestResume(): void {
    if (phase !== "paused" || pauseResumePending || recorder === null) return;
    pauseResumePending = true;
    armPauseResumeTimeout();
    try {
        recorder.resume();
    } catch {
        recoverPhaseFromRecorderState();
    }
}

// イベント未到達の保険（R3-S: 解除条件 = onpause/onresume/onerror/onstop/タイムアウト）
function armPauseResumeTimeout(): void {
    clearPauseResumeTimeout();
    pauseResumeTimeout = setTimeout(() => {
        pauseResumeTimeout = null;
        pauseResumePending = false;
        recoverPhaseFromRecorderState(); // 遅延イベントが来ても phase は state 同期のみ（二重遷移しない）
    }, 2000);
}
function clearPauseResumeTimeout(): void {
    if (pauseResumeTimeout !== null) {
        clearTimeout(pauseResumeTimeout);
        pauseResumeTimeout = null;
    }
}
function clearPauseResumePending(): void {
    pauseResumePending = false;
    clearPauseResumeTimeout();
}

// recorder.state を真実源に UI phase を同期（stopping 中は onstop に委ねるため触らない）
function recoverPhaseFromRecorderState(): void {
    if (recorder === null || phase === "stopping") return;
    const state = recorder.state; // "inactive" | "recording" | "paused"
    if (state === "inactive") {
        // onstop 未発火の異常系。timer 停止のみ行い idle へ倒さない（onstop が正規終了点）
        stopTimer();
        return;
    }
    const nextPhase: Phase = state === "paused" ? "paused" : "recording";
    if (state === "paused") stopTimer();
    else startTimer();
    if (phase !== nextPhase) setPhase(nextPhase);
}
```

**遅延イベント後の二重遷移防止（R3-S）**: onpause/onresume は「phase が対応状態なら遷移」と条件付き（`if (phase === "recording")` 等）。タイムアウト後に recoverPhaseFromRecorderState が既に phase を state 同期済みなら、遅延到達した onpause/onresume は phase が既に一致し no-op。clearPauseResumePending は冪等。

### S3. 録画タイマー（performance.now() 累積・pause 対応）

```ts
import { formatElapsed } from "@/lib/capture/camera";

let elapsedMs = $state(0);
let accumulatedMs = 0;          // pause で確定した累積（performance.now ベース）
let segmentStart = 0;           // 現 recording 区間の開始（performance.now()）
let timerHandle: ReturnType<typeof setInterval> | null = null;
const elapsedLabel = $derived(formatElapsed(elapsedMs));
const showTimer = $derived(phase === "recording" || phase === "paused");

// recording 区間の計測開始（start / resume で呼ぶ）
function startTimer(): void {
    if (timerHandle !== null) return; // 二重起動防止
    segmentStart = performance.now();
    timerHandle = setInterval(() => {
        elapsedMs = accumulatedMs + (performance.now() - segmentStart);
    }, 200);
}
// 計測停止 + 累積確定（pause / stop / idle / destroy で呼ぶ）
function stopTimer(): void {
    if (timerHandle !== null) {
        accumulatedMs += performance.now() - segmentStart;
        clearInterval(timerHandle);
        timerHandle = null;
    }
    elapsedMs = accumulatedMs;
}
function resetTimer(): void {
    if (timerHandle !== null) {
        clearInterval(timerHandle);
        timerHandle = null;
    }
    accumulatedMs = 0;
    segmentStart = 0;
    elapsedMs = 0;
}
// 実録画尺（durationMs 用）。累積 + 現区間の経過（recording 中に stop されたケース）
function recordedDurationMs(): number {
    return timerHandle !== null
        ? accumulatedMs + (performance.now() - segmentStart)
        : accumulatedMs;
}
```

- `startRecording` 成功時: `resetTimer()` → `startTimer()`（従来の `startedAt = Date.now()` を置換）。
- `onstop`: `durationMs = recordedDurationMs()`（**wall-clock の `Date.now() - startedAt` を置換**。R1: 意味を実録画尺へ是正）。その後 `resetTimer()`。
- `setPhase("idle")` 到達時・`onDestroy`・`fatalStopCleanup`: `resetTimer()` を必ず呼ぶ（interval リーク防止）。
- `startedAt` 変数は撤去（recordedDurationMs に置換）。

### S6. カメラ反転（guarded・段階的縮退・R3 反映）

```ts
import { nextFacingMode, type FacingMode } from "@/lib/capture/camera";

let facingMode = $state<FacingMode>("environment");
let flipping = false; // flip 再入ガード

// getUserMedia の制約を facingMode から組む（既存 acquirePreviewStream も参照）
function videoConstraints(): MediaTrackConstraints {
    return { facingMode };
}

// カメラ反転（idle 時のみ機能）。段階的縮退（R2/R3）:
async function flipCamera(): Promise<void> {
    // idle 以外・取得中・flip 中は no-op（録画中の stream 再取得を避ける）
    if (starting || resuming || flipping || phase !== "idle") return;
    const target = nextFacingMode(facingMode);

    // live stream 未保持（録画前）: state 更新のみ、次回 getUserMedia に反映
    if (stream === null) {
        facingMode = target;
        return;
    }
    flipping = true;
    try {
        error = null;
        const track = stream.getVideoTracks()[0] ?? null;
        // 段階1: applyConstraints({exact}) + getSettings 検証（同一 stream 維持）
        if (track !== null && (await tryApplyFacing(track, target))) {
            facingMode = target;
            return;
        }
        // 段階2〜4: 再取得（旧停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify）
        await reacquireWithFacing(target);
    } finally {
        flipping = false;
    }
}

// 段階1: exact 制約を適用し getSettings で実切替を検証（R3: resolve≠実切替）
async function tryApplyFacing(track: MediaStreamTrack, target: FacingMode): Promise<boolean> {
    try {
        await track.applyConstraints({ facingMode: { exact: target } });
    } catch {
        return false;
    }
    const applied = track.getSettings().facingMode;
    return applied === target;
}

// 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify（R3）
async function reacquireWithFacing(target: FacingMode): Promise<void> {
    const previous = facingMode;
    releaseCamera(); // 旧 stream 停止（二重取得不可端末に対応）
    facingMode = target;
    if (await acquirePreviewStream()) return; // 段階2成功
    // 段階3: 旧 facingMode で再取得して復旧（flip 断念・元カメラ継続）
    facingMode = previous;
    // acquirePreviewStream は stream===null のとき再取得する（stream は releaseCamera で null）
    if (await acquirePreviewStream()) {
        error = "カメラを切り替えられませんでした。";
        return;
    }
    // 段階4: 完全喪失。acquirePreviewStream 内で既に classify → transient 表示 or
    // onCameraUnavailable(F-03) 委譲が行われている（下記 acquirePreviewStream の分岐を再利用）。
}
```

**acquirePreviewStream の facingMode 反映**: 現行の `facingMode: "environment"` 直書きを `videoConstraints()` に差し替える。それ以外の分類ロジック（transient/onCameraUnavailable）は不変。段階4 の「完全喪失」は acquirePreviewStream が既存の classify 経由で transient 表示 or F-03 委譲するため、reacquireWithFacing 側で追加処理は不要（flip 初回失敗の非 F-03 と最終喪失の F-03 委譲が自然に分離される: 段階2/3 では復旧を試み、段階3 も失敗して初めて段階4 の classify に到達）。

> 注意: `acquirePreviewStream` は `stream ??= await getUserMedia(...)` のため、reacquire では releaseCamera() で `stream=null` にしてから呼ぶ（新 facingMode で確実に再取得）。この不変条件（reacquire 前に必ず release）を関数コメントに明記する。

### S5. グリッドトグル配線

```ts
import { Grid3x3, Pause, Play, SwitchCamera, Timer } from "@lucide/svelte";

let showGrid = $state(false); // 既定 OFF（字幕と違い構図補助は任意）
const gridToggleLabel = $derived(showGrid ? "グリッドを非表示" : "グリッドを表示");
```

### マークアップ変更（overlay 順と操作行）

overlay の z 順（DOM 順で映像 < grid < 字幕帯）:
```svelte
<div class="relative">
    <video ...></video>
    <GridOverlay visible={showGrid} />           <!-- 字幕より先 = 下層 -->
    <SubtitleOverlay ... visible={showSubtitles} />
    {#if showTimer}
        <!-- 録画タイマー（overlay 右上）。recording/paused 時のみ -->
        <div
            class="pointer-events-none absolute right-2 top-2 flex items-center gap-1 rounded-sm bg-text/70 px-2 py-1 text-caption text-surface"
            data-testid="record-timer"
        >
            <Timer class="size-3.5" aria-hidden="true" />
            <span aria-live="off">{elapsedLabel}</span>
            {#if phase === "paused"}<span class="sr-only">一時停止中</span>{/if}
        </div>
    {/if}
</div>
```

操作行（phase 分岐。禁止事項8: disabled を使わず文脈で出し分け）:
```svelte
<div class="flex items-center justify-center gap-3">
    {#if phase === "idle"}
        <Button variant="primary" onclick={startRecording} testId="start-recording">
            <Circle class="size-4" aria-hidden="true" /> 録画開始
        </Button>
        <!-- カメラ反転（idle のみ表示 = 文脈非該当時は非表示。disabled ではない） -->
        <button type="button" ... aria-label="カメラを切り替え" onclick={flipCamera}
                data-testid="flip-camera">
            <SwitchCamera class="size-5" aria-hidden="true" />
        </button>
    {:else if phase === "recording"}
        <!-- 一時停止（supportsPauseResume() 時のみ表示。未対応は非表示で start/stop のみ） -->
        {#if canPauseResume}
            <button type="button" ... aria-label="一時停止" onclick={requestPause}
                    data-testid="pause-recording">
                <Pause class="size-5" aria-hidden="true" />
            </button>
        {/if}
        <Button variant="danger" onclick={safeStop} testId="stop-recording">
            <Square class="size-4" aria-hidden="true" /> 録画停止
        </Button>
    {:else if phase === "paused"}
        <button type="button" ... aria-label="録画を再開" onclick={requestResume}
                data-testid="resume-recording">
            <Play class="size-5" aria-hidden="true" />
        </button>
        <Button variant="danger" onclick={safeStop} testId="stop-recording">
            <Square class="size-4" aria-hidden="true" /> 録画停止
        </Button>
    {/if}
    <!-- グリッドトグル（常時表示・字幕トグルと並置。字幕が空でも disabled にしない） -->
    <button type="button" ... aria-label={gridToggleLabel} aria-pressed={showGrid}
            onclick={() => (showGrid = !showGrid)} data-testid="toggle-grid">
        <Grid3x3 class="size-5" aria-hidden="true" />
    </button>
    <!-- 既存の字幕トグル（無改変） -->
    <button ... data-testid="toggle-subtitles"> ... </button>
</div>
```

`canPauseResume` は module 初期化時に一度評価:
```ts
const canPauseResume = supportsPauseResume();
```

> stopping phase 中はボタン行が空になる（既存挙動: idle 以外は stop ボタン表示だったのを phase 別に分割）。stopping は短時間の遷移状態のため操作ボタンは出さず、grid/字幕トグルのみ残す。これは既存の「stopping でも stop ボタンを出していた」挙動からの変更点。停止処理中の二重 stop は safeStop の phase ガードで元々 no-op のため、停止ボタンを stopping で消しても機能後退はない。

### safeStop の paused 対応

現行 `safeStop` は `if (phase !== "recording") return;`。paused からも停止できる必要がある:
```ts
function safeStop(): void {
    if (phase !== "recording" && phase !== "paused") return; // paused も停止可
    clearPauseResumePending();
    setPhase("stopping");
    if (recorder === null) { fatalStopCleanup(); return; }
    try {
        recorder.stop(); // paused 状態でも stop() は onstop を発火し blob 確定
    } catch {
        fatalStopCleanup();
    }
}
```

### onDestroy / cleanup

```ts
onDestroy(() => {
    resetTimer();
    clearPauseResumeTimeout();
    releaseCamera();
});
```
（現行は `onDestroy(releaseCamera)`。timer/timeout の cleanup を追加）

### 型安全チェック（CameraRecorder）
- [x] `Phase` union に paused を追加、全分岐（マークアップ・active・safeStop・recover）が型に従属
- [x] `FacingMode` を camera.ts から import（単一ソース）
- [x] timer/timeout handle は `ReturnType<typeof setInterval>` / `ReturnType<typeof setTimeout>`（number 直書きしない）
- [x] `recorder.state` は lib.dom.d.ts の `RecordingState`（"inactive"|"recording"|"paused"）で網羅
- [x] `track.getSettings().facingMode` は `string | undefined`、target(`FacingMode`) との比較で narrowing

### テスト計画（S7 に集約）
下記 S7 参照。

### リスク
- **既存 20 ケースの回帰**: 既存テストは getUserMedia の constraint 引数を検証しないため `videoConstraints()` 化は無影響。`Date.now()`→`performance.now()` は durationMs の typeof number 検証のみのため無影響。stopping 中の停止ボタン非表示化は既存テスト（safeStop 多重 no-op は phase ガードで担保、`stopCalls` 検証は stop ボタン経由で 1 回のまま）に影響しないか要確認 → 既存「safeStop 多重呼び出し」テストは stop ボタンを 2 回クリックするが、1 回目で stopping へ遷移し停止ボタンが消えるため 2 回目の getByTestId が失敗する懸念。**対策**: 該当既存テストの 2 回目クリックは autoStop=false で stopping 中に停止ボタンが消える前提と齟齬 → **既存テストを壊さないため、stopping 中も停止ボタンを残す**（`phase === "recording" || phase === "paused" || phase === "stopping"` で停止ボタン表示、ただし stopping では safeStop が no-op）。この方針に修正する（下記「既存テスト互換」参照）。

### 既存テスト互換（重要）
既存テスト「safeStop 多重呼び出しで recorder.stop() が重複しない」は停止ボタンを 2 回クリックする。停止ボタンは recording/paused/**stopping** で表示し続ける（stopping では safeStop が phase ガードで no-op）。よって操作行の分岐は:
- idle: 録画開始 + カメラ反転
- recording/paused/stopping: 停止ボタン常時表示。加えて recording で一時停止（canPauseResume 時）、paused で再開ボタン。
- grid/字幕トグルは全 phase で表示。

これにより既存テストの「stopping 中の 2 度目クリック→no-op」が成立する。

---

## S7. テスト

### tests/js/lib/capture/camera.test.ts（追記）
- `nextFacingMode` 双方向
- `formatElapsed`: 0/5000/65000/3599000/3600000/負値/NaN
- `supportsPauseResume`: prototype に pause/resume を持つ/持たない MediaRecorder stub で true/false

### tests/js/components/features/capture/GridOverlay.test.ts（新規）
- `visible=true` で `grid-overlay` 描画、罫線 4 本
- `visible=false` で非描画

### tests/js/components/features/capture/CameraRecorder.test.ts（追記。既存ケース無改変）

FakeMediaRecorder を拡張（`state`, `pause()`, `resume()`, `onpause`, `onresume` を追加。prototype に pause/resume を持たせ supportsPauseResume=true にする）。既存の TrackingFakeMediaRecorder はそのまま利用。

- **一時停止/再開（イベント基準）**:
  - 録画開始 → `pause-recording` クリック → FakeMediaRecorder.pause() 呼出 + `onpause` 発火 → phase=paused（`resume-recording` 表示・`record-timer` に「一時停止中」sr-only）
  - `resume-recording` クリック → resume() + `onresume` → phase=recording（`pause-recording` 復帰）
  - paused から `stop-recording` → onstop → `onCaptured` 呼出（同一テイク 1 本）
- **多重押下ガード**: pause 要求後 onpause 到達前に再クリックしても pause() は 1 回
- **能力未対応**: supportsPauseResume=false（prototype に pause/resume 無し）の stub で `pause-recording` が**非表示**（start/stop のみ）。既存の録画→停止フローは成立
- **InvalidStateError 復旧**: pause() が throw → recorder.state から phase 復旧（recording のまま）
- **グリッドトグル**: `toggle-grid` クリックで `grid-overlay` 表示/非表示 + aria-pressed 反転。字幕が空でも disabled でない
- **カメラ反転（idle）**:
  - live stream 無し（初回・録画前）: `flip-camera` クリックで次回 getUserMedia の facingMode が "user"（録画開始で getUserMedia の constraint を検証）
  - live stream 有り + applyConstraints 成功（getSettings が target 返す）: 同一 stream 維持（getUserMedia 追加呼出なし）
  - applyConstraints 不成立 → 再取得（releaseCamera + getUserMedia 再呼出）
  - 再取得失敗 → 旧 facingMode 復旧（元 stream 相当を再取得、error 表示「切り替えられませんでした」、onCameraUnavailable 呼ばれない）
- **録画タイマー**: fake timers で録画中 `record-timer` が "00:00"→時間経過で更新。pause で停止（進まない）、resume で再開。stop で消える
- **既存 20 ケース**: 無改変で green（回帰確認）

### テスト規約チェック
- [x] Vitest + @testing-library/svelte（既存様式）
- [x] fake timers 使用時は afterEach で restore
- [x] DatabaseTransactions 等は無関係（フロントのみ）

---

## 実装モード

| 項目 | 内容 |
|------|------|
| 推奨モード | **incremental** |
| 判断根拠 | 既存 `CameraRecorder.svelte` / `camera.ts` への追加改修が中心で、単一 feature 領域（features/capture）に閉じる。新規ファイルは GridOverlay と GridOverlay テストのみ。他 TODO との競合可能性が低い。段階的に S1（camera.ts）→ S2（GridOverlay）→ S3-S6（CameraRecorder）→ S7（テスト）と積み上げられる |
| 競合リスク | 低。capture 領域を触る他施策が並走する場合のみ CameraRecorder.svelte で競合。その場合も追加中心のため解消容易 |

## 使命・禁止事項 最終チェック
- [x] 全施策が使命（撮影者スキルに品質を依存させない）に寄与
- [x] 禁止事項8: disabled を使わず、文脈非該当（カメラ反転=idle のみ、一時停止=能力対応時のみ）は非表示、トグルは常時押下可
- [x] テスト必須: 全施策に Vitest（S7）
- [x] DS token のみ / Lucide のみ / features 層単方向 import / SVG 直書きなし
- [x] フロント完結で API/DTO/PHPStan 非該当（onCaptured シグネチャ不変）

---

## 関連する現行コード（抜粋）

### resources/js/components/features/capture/CameraRecorder.svelte（現行全文）
```svelte
<script lang="ts">
    import { onDestroy } from "svelte";
    import { Captions, CaptionsOff, Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";
    import type { CaptureCut } from "@/types/capture";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     *
     * 撮影 active の phase マシン (T050 / S4): idle / recording / stopping。
     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
        /** 選択中カットの字幕 (撮影ガイド overlay 用。焼込ではない)。既定は空 (字幕なし) */
        subtitlePrimary?: CaptureCut["subtitle_primary"];
        subtitleSecondary?: CaptureCut["subtitle_secondary"];
        /** 撮影 active (starting || resuming || phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
        onCaptureActiveChange?: (active: boolean) => void;
    }

    let {
        onCaptured,
        onCameraUnavailable,
        subtitlePrimary = null,
        subtitleSecondary = "",
        onCaptureActiveChange,
    }: Props = $props();

    type Phase = "idle" | "recording" | "stopping";

    // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
    let showSubtitles = $state(true);
    const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
    let phase = $state<Phase>("idle");
    let error = $state<string | null>(null);
    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
    let starting = false;
    /** 直近に外部通知した active 値 (starting || resuming || phase !== "idle" の変化検出用) */
    let lastActive = false;
    /** preview 解放前に live だったか (復帰要否) */
    let wasActiveBeforePreview = false;
    /** resumeAfterPreview の再入ガード (多重 close/open で getUserMedia を二重発火させない) */
    let resuming = false;
    let resumePromise: Promise<void> | null = null;

    // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
    // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
    function syncActive(): void {
        const active = starting || resuming || phase !== "idle";
        if (active !== lastActive) {
            lastActive = active;
            onCaptureActiveChange?.(active);
        }
    }

    // phase 遷移は単一 setter を通す。active 通知は syncActive に一元化する。
    function setPhase(next: Phase): void {
        phase = next;
        syncActive();
    }

    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
    async function acquirePreviewStream(): Promise<boolean> {
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch (cause) {
            const classified = classifyGetUserMediaError(cause);
            if (classified.kind === "transient") {
                error =
                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
                return false;
            }
            onCameraUnavailable(classified.reason);
            return false;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        return true;
    }

    async function startRecording(): Promise<void> {
        // 再入防止 (アーリーリターン。規約: disabled 禁止)。preview 復帰の取得中 (resuming) も拒否
        // し getUserMedia 二重取得を防ぐ。
        if (starting || resuming || phase !== "idle") return;
        starting = true;
        syncActive(); // 開始押下時点で active=true (grant 窓でも preview を開けない)
        try {
            error = null;
            const mimeType = preferredRecordingMimeType();
            if (mimeType === null) {
                // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
                onCameraUnavailable("mime_unsupported");
                return;
            }
            const acquired = await acquirePreviewStream();
            if (!acquired) return;
            if (stream === null) return; // 型絞り込み (acquired=true なら実質非 null)
            chunks = [];
            try {
                recorder = new MediaRecorder(stream, { mimeType });
            } catch {
                // NotSupportedError 等: 取得済み stream を解放してからフォールバックへ
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            recorder.ondataavailable = (event) => {
                if (event.data.size > 0) chunks.push(event.data);
            };
            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
            recorder.onstop = async () => {
                try {
                    const blob = new Blob(chunks, { type: mimeType });
                    const durationMs = Date.now() - startedAt;
                    if (blob.size > 0) {
                        await onCaptured(blob, mimeType, durationMs);
                    }
                } catch {
                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
                } finally {
                    setPhase("idle");
                }
            };
            recorder.onerror = () => safeStop();
            stream.getTracks().forEach((track) => {
                track.onended = () => safeStop();
            });
            startedAt = Date.now();
            try {
                recorder.start();
            } catch {
                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                recorder = null;
                releaseCamera();
                onCameraUnavailable("recorder_unsupported");
                return;
            }
            setPhase("recording");
        } finally {
            starting = false;
            // 開始成功時: phase=recording のため active は true 維持 (重複通知しない)。
            // 開始失敗/恒久失敗時: phase=idle のため active=false へ戻す。
            syncActive();
        }
    }

    // 安全停止 (多重呼び出しガード)。recording 以外では no-op (stopping/idle で重複 stop しない)。
    function safeStop(): void {
        if (phase !== "recording") return;
        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
        if (recorder === null) {
            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
            return;
        }
        try {
            recorder.stop(); // → recorder.onstop へ
        } catch {
            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
        }
    }

    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
    function fatalStopCleanup(): void {
        setPhase("idle");
        releaseCamera();
        onCameraUnavailable("recorder_unsupported");
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
    // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
    // 解放しない (Codex R1/R3-S4)。
    export function releaseForPreview(): void {
        if (starting || resuming || phase !== "idle") return; // recording/stopping/取得中で解放拒否
        wasActiveBeforePreview = stream !== null; // 復帰要否を記録
        releaseCamera();
    }

    // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止。
    export function resumeAfterPreview(): Promise<void> {
        if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
        if (!wasActiveBeforePreview || starting || phase !== "idle") return Promise.resolve();
        resuming = true;
        syncActive(); // 復帰取得中も active=true (grant 窓で preview 再オープン・録画開始を抑止)
        // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能)
        resumePromise = acquirePreviewStream()
            .then((ok) => {
                if (ok) wasActiveBeforePreview = false;
            })
            .finally(() => {
                resuming = false;
                resumePromise = null;
                syncActive(); // 取得完了で active=false へ戻す (phase は idle のまま)
            });
        return resumePromise;
    }

    onDestroy(releaseCamera);
</script>

<div class="flex flex-col gap-3">
    <div class="relative">
        <!-- svelte-ignore a11y_media_has_caption -->
        <video
            bind:this={video}
            autoplay
            playsinline
            muted
            class="aspect-video w-full rounded-md bg-surface object-cover"
            data-testid="camera-preview"
        ></video>
        <SubtitleOverlay
            primary={subtitlePrimary}
            secondary={subtitleSecondary}
            visible={showSubtitles}
        />
    </div>
    <div class="flex items-center justify-center gap-3">
        {#if phase === "idle"}
            <Button variant="primary" onclick={startRecording} testId="start-recording">
                <Circle class="size-4" aria-hidden="true" />
                録画開始
            </Button>
        {:else}
            <Button variant="danger" onclick={safeStop} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {/if}
        <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
             (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
        <button
            type="button"
            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
            aria-label={subtitleToggleLabel}
            aria-pressed={showSubtitles}
            onclick={() => (showSubtitles = !showSubtitles)}
            data-testid="toggle-subtitles"
        >
            {#if showSubtitles}
                <Captions class="size-5" aria-hidden="true" />
            {:else}
                <CaptionsOff class="size-5" aria-hidden="true" />
            {/if}
        </button>
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
```

### resources/js/lib/capture/camera.ts（現行全文）
```ts
/**
 * カメラ対応判定 (doc/10 §10.8-3: MediaRecorder 非対応環境では
 * <input type="file" capture> フォールバックを必ず提供する)。
 */
export function supportsMediaRecorder(): boolean {
    return (
        typeof window.MediaRecorder !== "undefined" &&
        typeof navigator.mediaDevices?.getUserMedia === "function" &&
        ["video/mp4", "video/webm"].some(
            (type) => window.MediaRecorder.isTypeSupported?.(type) ?? false,
        )
    );
}

/** 録画に使う MIME type (mp4 優先。どちらも不可なら null) */
export function preferredRecordingMimeType(): string | null {
    if (typeof window.MediaRecorder === "undefined") return null;
    for (const type of ["video/mp4", "video/webm"]) {
        if (window.MediaRecorder.isTypeSupported?.(type)) return type;
    }
    return null;
}

/**
 * カメラが実行時に使えない理由 (F-03 対応。判別可能 union で保持し、
 * UI 文言の出し分け・将来の計測に使う)。
 * Permissions-Policy 拒否は NotAllowedError として観測されユーザー拒否と
 * 機械的に区別できないため permission_denied に含める。
 */
export type CameraUnavailableReason =
    | "permission_denied" // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
    | "device_missing" // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
    | "mime_unsupported" // preferredRecordingMimeType() === null
    | "recorder_unsupported" // new MediaRecorder() の失敗 (NotSupportedError 等)
    | "unknown"; // 分類不能 (詰み回避のためフォールバック側に倒す)

/** getUserMedia() 失敗の分類結果。transient は再試行で回復し得る失敗 */
export type CameraErrorClassification =
    | { kind: "unavailable"; reason: CameraUnavailableReason }
    | { kind: "transient" };

/** reject 値から DOMException 名を安全に取り出す (ブラウザは任意値を reject し得る) */
function errorName(error: unknown): string | null {
    if (error instanceof DOMException) return error.name;
    // OverconstrainedError 等、実装により DOMException を継承しないオブジェクトに備える
    if (typeof error === "object" && error !== null && "name" in error) {
        const name = (error as { name: unknown }).name;
        return typeof name === "string" ? name : null;
    }
    return null;
}

/**
 * getUserMedia() の reject 値を分類する (W3C Media Capture の DOMException name ベース)。
 * - 恒久系 (権限拒否・デバイス無し) → unavailable: フォールバックへ切替
 * - 一時系 (デバイス使用中・中断) → transient: エラー表示 + 再試行可能のまま
 * - 分類不能 → unavailable/unknown: §10.8-3 の「詰みを作らない」要件に従い
 *   フォールバック側に倒す (誤フォールバックでもテイク投入は継続できる)
 */
export function classifyGetUserMediaError(error: unknown): CameraErrorClassification {
    switch (errorName(error)) {
        case "NotAllowedError":
        case "SecurityError":
            return { kind: "unavailable", reason: "permission_denied" };
        case "NotFoundError":
        case "OverconstrainedError":
            return { kind: "unavailable", reason: "device_missing" };
        case "NotReadableError":
        case "AbortError":
            return { kind: "transient" };
        default:
            return { kind: "unavailable", reason: "unknown" };
    }
}
```

### resources/js/components/features/capture/SubtitleOverlay.svelte（先例）
```svelte
<script lang="ts">
    import type { CaptureCut } from "@/types/capture";

    /**
     * 撮影中カメラプレビューへ重畳する字幕ガイド (doc/05 §5.2 の字幕重畳要件)。
     * 焼込ではなく撮影ガイド overlay: MediaRecorder が録る MediaStream には含まれない。
     * primary=上部帯 (名称・数値) / secondary=下部メイン。位置は AssSubtitleWriter (ASS) と一致。
     * 位置・占有領域の確認用であり全文確認用ではない (長文は line-clamp で省略)。
     */
    interface Props {
        primary: CaptureCut["subtitle_primary"];
        secondary: CaptureCut["subtitle_secondary"];
        visible: boolean;
    }

    let { primary, secondary, visible }: Props = $props();

    // trim は「空判定」のみに使う。描画には元文字列をそのまま使う (内容を書き換えない)。
    // secondary は型上 string だが将来の props 契約変更に備え防御的に nullish 合体する。
    const hasPrimary = $derived((primary ?? "").trim() !== "");
    const hasSecondary = $derived((secondary ?? "").trim() !== "");
    const shown = $derived(visible && (hasPrimary || hasSecondary));
</script>

{#if shown}
    <div
        class="pointer-events-none absolute inset-0 flex flex-col justify-between p-3"
        data-testid="subtitle-overlay"
    >
        <div class="flex justify-center">
            {#if hasPrimary}
                <p
                    class="line-clamp-2 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
                    data-testid="subtitle-primary"
                >
                    {primary}
                </p>
            {/if}
        </div>
        <div class="flex justify-center">
            {#if hasSecondary}
                <p
                    class="line-clamp-3 max-w-[90%] rounded-sm bg-text/70 px-3 py-1 text-center text-body whitespace-pre-line text-surface"
                    data-testid="subtitle-secondary"
                >
                    {secondary}
                </p>
            {/if}
        </div>
    </div>
{/if}
```
