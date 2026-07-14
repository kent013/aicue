# T056 実装レビュー (capture-ux-enrichment)

## アプリの使命 (North Star / AGENTS.md より)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」— 台本作成・撮影判断・編集の 3 ハードルを AI とナビ撮影で肩代わりする。撮影者・教える人のスキルに品質を依存させない。v1 スコープ: 字幕のみ(TTS 後回し) / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項 (AGENTS.md より、本件フロント関連の核)

1. テストなしの実装完了報告 (不変条件はテスト登録まで含めて実装済み)
2. 操作系 UI で必須条件未充足を理由にボタンを disabled にする (押下時にエラー表示 or 文脈非該当なら非表示。禁止事項8)
3. フロントは Svelte 5 runes + DS token/ramp のみ。hex 直書き (#RRGGBB) 禁止 (ds-purity テストが検出)。DESIGN.md が canonical
4. component 階層は atoms → molecules → organisms → features/{domain} → templates → pages の単方向 import のみ (atomic-import-graph テストが強制)
5. アイコンは @lucide/svelte のみ。SVG 直書き禁止 (svg-inline-allowlist テストが強制)

## 思考原則
まず仮説を立てろ。ユーザー視点で考えろ。先人の知恵 (Svelte/Web API 標準) を探せ。機能の名前に立ち返れ。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可 (worktree: /workspace/.claude/worktrees/tasks/T056)。

---

## system: レビュアーの役割

あなたは Laravel + Svelte アプリのコードレビュアー。本 PR は撮影 UX 拡充 (録画タイマー / 一時停止・再開 / グリッド / カメラ反転) のフロントエンド完結の実装。以下の観点でレビューせよ:

- **設計との一致性**: 下記詳細設計書の S1〜S7 の意図・不変条件を実装が満たしているか (特に: durationMs を実録画尺へ是正・pause 区間除外、stale イベントで timer を触らない R2-Critical、操作種別付き pending の交差 R3-2、タイムアウト handle 自己同定 R4-2、flip の副作用を段階4 まで遅延する R1-Critical、paused からの停止 R1-Critical、停止ボタン stopping 可視)
- **正確性**: 競合・レース・リーク (interval/timeout リーク)、イベント順序前提の破綻、型の網羅性
- **TypeScript 型安全**: any 逃げ・不正な narrowing がないか
- **テスト網羅性**: 各施策に Vitest があるか。異常系・回帰 (既存 20 ケース) が守られているか
- **DESIGN.md 準拠**: color/radius/typography は token 経由か。hex 直書きを増やしていないか。GridOverlay の bg-surface/40・record-timer の bg-text/70 text-surface 等は既存 SubtitleOverlay と同じ token 語彙か
- **Atomic Design 準拠**: 新規 GridOverlay.svelte は features/capture 層 (SubtitleOverlay と同層)。CameraRecorder → GridOverlay の features 内包 import は単方向か。アイコンは Lucide のみか
- **セキュリティ**: フロント完結だが、onCaptured シグネチャ不変・親呼び出し側の後方互換が保たれているか

出力形式: ファイルごとに判定、指摘を Critical / Warning / Suggestion に分類。最後に全体判定 **APPROVED** または **CHANGES_REQUESTED** を明記せよ。

---

## user: 詳細設計書
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
// pause/resume 要求の in-flight ガード。**boolean ではなく操作種別を保持**する（R3-2 反映）:
// stale な onpause が進行中の resume の pending を誤って解除する事故を防ぐため、
// 一致する操作のイベント/タイムアウトのみが pending を解除する。
type PauseResumeOperation = "pause" | "resume";
let pendingOperation: PauseResumeOperation | null = null;
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
// R2-Critical: タイマー操作は phase 条件の内側で行う。stale な onpause/onresume が
// stopping/idle で到着しても timer を触らない（durationMs 汚染・interval リークを防ぐ）。
// clearPauseResumePending() だけは stale イベントでも安全に実行してよい。
recorder.onpause = () => {
    if (pendingOperation === "pause") clearPauseResumePending(); // 一致する操作のみ解除（R3-2）
    if (phase !== "recording") return; // stale なら timer/phase を触らない
    stopTimer();                        // 経過計測を止める（累積は保持）
    setPhase("paused");
};
recorder.onresume = () => {
    if (pendingOperation === "resume") clearPauseResumePending(); // 一致する操作のみ解除（R3-2）
    if (phase !== "paused") return;     // stale なら timer/phase を触らない
    startTimer();                       // 経過計測を再開（累積へ加算）
    setPhase("recording");
};
```

要求ハンドラ（ボタン押下 = 要求。phase は同期で動かさずイベントで確定）:
```ts
// 一時停止要求（recording のみ）。pending 中（種別を問わず）は多重押下ガードで拒否。
function requestPause(): void {
    if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
    if (!supportsPauseResume()) return; // 未対応端末はボタン非表示のため通常到達しない
    pendingOperation = "pause";
    armPauseResumeTimeout("pause");
    try {
        recorder.pause();
    } catch {
        // InvalidStateError 等: pending を解除し recorder.state から phase を復旧
        clearPauseResumePending();
        recoverPhaseFromRecorderState();
    }
}

// 録画再開要求（paused のみ）
function requestResume(): void {
    if (phase !== "paused" || pendingOperation !== null || recorder === null) return;
    pendingOperation = "resume";
    armPauseResumeTimeout("resume");
    try {
        recorder.resume();
    } catch {
        clearPauseResumePending();
        recoverPhaseFromRecorderState();
    }
}

// イベント未到達の保険（R3-S: 解除条件 = onpause/onresume/onerror/onstop/タイムアウト）。
// 操作種別を渡し、**古いタイムアウトが後続操作の pending/handle を奪わない**よう二重防御する:
//  (1) handle 自己同定: 遅延実行された古い callback は `pauseResumeTimeout !== handle` で
//      早期 return し、新しい timeout の handle を null 化しない（R4-2 の handle 喪失防止）。
//  (2) 操作種別一致: 自分の操作がまだ pending のときだけ pending を解除して復旧する。
// これにより「操作ごとの世代 ID」を導入せずとも、同種操作の交差でも handle/pending を壊さない
// （requestPause/Resume は pendingOperation!==null で多重押下を弾き、arm は clear-before-arm の
//  ため生存 timeout は常に 1 つ。MediaRecorder の onpause/onresume はイベント順序保証を前提とする）。
function armPauseResumeTimeout(op: PauseResumeOperation): void {
    clearPauseResumeTimeout();
    const handle: ReturnType<typeof setTimeout> = setTimeout(() => {
        if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない（R4-2）
        pauseResumeTimeout = null;
        if (pendingOperation !== op) return;       // 自分の操作が解決/交代済みなら何もしない
        pendingOperation = null;
        recoverPhaseFromRecorderState(); // 遅延イベントが来ても phase は state 同期のみ（二重遷移しない）
    }, 2000);
    pauseResumeTimeout = handle;
}
function clearPauseResumeTimeout(): void {
    if (pauseResumeTimeout !== null) {
        clearTimeout(pauseResumeTimeout);
        pauseResumeTimeout = null;
    }
}
function clearPauseResumePending(): void {
    pendingOperation = null;
    clearPauseResumeTimeout();
}

// recorder.state を真実源に UI phase を同期（stopping 中は onstop に委ねるため触らない）
function recoverPhaseFromRecorderState(): void {
    if (recorder === null || phase === "stopping") return;
    const state = recorder.state; // "inactive" | "recording" | "paused"
    if (state === "inactive") {
        // R1-W フェイルセーフ: recording/paused 中に recorder が inactive（onstop 永久未達 UA
        // バグ等の異常系）を検出したら、復帰不能を防ぐため fatalStopCleanup で idle 復帰 + 資源解放。
        // recorder が死んでいる異常系のため F-03 委譲は妥当。onstop 正規終了とは競合しない
        //（正規終了時は既に stopping/idle でここに来ない）。
        fatalStopCleanup();
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
// 実録画尺（durationMs 用）。累積 + 現区間の経過（recording 中に stop されたケース）。
// R1-S: Math.max(0, …) で明示クランプ（防御的。performance.now 単調増加のため通常は非負）。
function recordedDurationMs(): number {
    const raw =
        timerHandle !== null
            ? accumulatedMs + (performance.now() - segmentStart)
            : accumulatedMs;
    return Math.max(0, raw);
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
    // R1-W: getSettings().facingMode が undefined の端末は「未検証扱い」で false を返し
    // 再取得経路（段階2〜）へ倒す（安全側。誤って同一 stream 維持で切替失敗を隠さない）。
    const applied = track.getSettings().facingMode;
    return applied === target;
}

// 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で初めて副作用（R3 + R1-critical）
// 副作用なしの acquireStream() を使い、onCameraUnavailable(F-03)/error の発火を段階4 まで遅延する。
async function reacquireWithFacing(target: FacingMode): Promise<void> {
    const previous = facingMode;
    releaseCamera(); // 旧 stream 停止（二重取得不可端末に対応。stream=null になる）
    facingMode = target;
    const forward = await acquireStream(); // 段階2: 副作用なし取得
    if (forward.kind === "ok") return;
    // 段階3: 旧 facingMode で再取得して復旧（flip 断念・元カメラ継続。onCameraUnavailable は呼ばない）
    facingMode = previous;
    const back = await acquireStream();
    if (back.kind === "ok") {
        error = "カメラを切り替えられませんでした。";
        return;
    }
    // 段階4: 両カメラ喪失。段階3 の classify(back) に対してのみ副作用を適用
    //（transient→error 表示 / unavailable→onCameraUnavailable(F-03) 委譲）。
    applyAcquireFailure(back);
}
```

**取得の副作用分離（R1-Critical 修正）**: 現行 `acquirePreviewStream` は内部で transient→error / unavailable→`onCameraUnavailable` の副作用を持つ。flip がこれを呼ぶと、新 facing が OverconstrainedError/NotFoundError（前面カメラ無し等）でも段階2 で F-03 に倒れ、旧カメラが生きているのに詰む。よって取得を副作用なしの低レベル関数に分離する:

```ts
// 副作用なしの取得（classify 結果を返すだけ。onCameraUnavailable/error を呼ばない）。
// 呼び出し前に stream=null であること（reacquire 前は releaseCamera 済み）。
async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
    try {
        stream ??= await navigator.mediaDevices.getUserMedia({
            video: videoConstraints(), // facingMode を反映（現行の "environment" 直書きを置換）
            audio: true,
        });
    } catch (cause) {
        return classifyGetUserMediaError(cause);
    }
    if (video) {
        video.srcObject = stream;
        await video.play().catch(() => undefined);
    }
    return { kind: "ok" };
}

// classify 失敗に既存の副作用ポリシーを適用（transient→error / unavailable→F-03 委譲）。
function applyAcquireFailure(result: CameraErrorClassification): void {
    if (result.kind === "transient") {
        error =
            "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
        return;
    }
    onCameraUnavailable(result.reason);
}

// 既存契約を維持するラッパ（startRecording / resumeAfterPreview は無改変で呼べる）。
async function acquirePreviewStream(): Promise<boolean> {
    const result = await acquireStream();
    if (result.kind === "ok") return true;
    applyAcquireFailure(result);
    return false;
}
```

これで既存 2 呼び出し（startRecording・resumeAfterPreview）の挙動は完全維持され（分類ロジック不変）、flip のみ副作用を段階4 まで遅延できる。`CameraErrorClassification` は既に `camera.ts` が export 済み。

> 不変条件: `acquireStream` は `stream ??=` のため、reacquire では releaseCamera() で `stream=null` にしてから呼ぶ（新 facingMode で確実に再取得）。関数コメントに明記する。

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

操作行（phase 分岐。禁止事項8: disabled を使わず文脈で出し分け）。**停止ボタンは recording/paused/stopping で常時可視**（R1-Critical: 既存「safeStop 多重クリック」テスト互換 = stopping 中も停止ボタンが在り、2 度目クリックは safeStop の phase ガードで no-op）:
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
    {:else}
        <!-- recording / paused / stopping 共通: 停止ボタンは常時可視（stopping では no-op） -->
        {#if phase === "recording" && canPauseResume}
            <!-- 一時停止（supportsPauseResume() 時のみ表示。未対応は非表示で start/stop のみ） -->
            <button type="button" ... aria-label="一時停止" onclick={requestPause}
                    data-testid="pause-recording">
                <Pause class="size-5" aria-hidden="true" />
            </button>
        {:else if phase === "paused"}
            <!-- 録画再開 -->
            <button type="button" ... aria-label="録画を再開" onclick={requestResume}
                    data-testid="resume-recording">
                <Play class="size-5" aria-hidden="true" />
            </button>
        {/if}
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

> **停止ボタンの可視方針（R1-Critical で確定）**: `phase !== "idle"`（= recording/paused/stopping）で `stop-recording` を常時表示する。これにより既存「safeStop 多重呼び出しで recorder.stop() が重複しない」テスト（stop ボタンを 2 回クリック）が壊れない（1 度目で stopping へ遷移しても停止ボタンは残り、2 度目クリックは safeStop の phase ガードで no-op）。既存の「idle 以外は停止ボタン表示」挙動と互換。

### safeStop の paused 対応（R1-Critical）

現行 `safeStop` は `if (phase !== "recording") return;`。**paused からも停止できる必要がある**。特に `recorder.onerror = () => safeStop()` は paused 中にも発火し得るため、paused 非対応だと停止不能になる（R1-Critical）:
```ts
function safeStop(): void {
    if (phase !== "recording" && phase !== "paused") return; // paused も停止可（onerror 経由含む）
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
`recorder.onerror = () => safeStop();`（現行のまま）は paused でも正しく停止へ倒れる。

### fatalStopCleanup の cleanup 拡張

現行 `fatalStopCleanup`（setPhase("idle") + releaseCamera + onCameraUnavailable("recorder_unsupported")）に、timer/in-flight の後始末を追加する:
```ts
function fatalStopCleanup(): void {
    resetTimer();
    clearPauseResumePending();
    setPhase("idle");
    releaseCamera();
    onCameraUnavailable("recorder_unsupported");
}
```
同様に `onstop` の `finally { setPhase("idle"); }` の直前で `resetTimer()` を呼ぶ（idle 到達時の interval リーク防止）。

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
- **既存 20 ケースの回帰**: 既存テストは getUserMedia の constraint 引数を検証しないため `acquireStream` の `videoConstraints()` 化は無影響。`Date.now()`→`performance.now()` は durationMs の typeof number 検証のみのため無影響。`acquirePreviewStream` は薄いラッパへ再構成するが、外形（成功 true / transient→error 表示 / unavailable→onCameraUnavailable）は完全維持のため既存の全 F-03/transient ケースは無改変で通る。停止ボタンは recording/paused/**stopping** で常時可視とし、既存「safeStop 多重呼び出し」テスト（stop ボタン 2 度クリック）が壊れないことを保証（下記「既存テスト互換」で確定）。

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
- **paused 中 onerror → 停止完了（R1-Critical）**: 録画→pause→`recorder.onerror()` 発火 → safeStop→onstop→`onCaptured` 呼出、phase idle 復帰（paused からも停止不能にならない）
- **遅延イベントの二重遷移なし（R1-W）**: fake timers で pause 要求 → onpause 未達のまま pauseResumeTimeout 経過（recover が recorder.state から同期）→ その後遅延 onpause を発火 → phase が不変（二重遷移しない）
- **stale イベントで timer 再起動しない（R2-Critical）**: 以下 4 ケースを追加検証:
  - pause 要求直後に stop（stopping/idle）→ その後 stale `onpause` 到着でも phase/timer 不変（stopTimer/setPhase を実行しない）
  - resume 要求直後に stop → その後 stale `onresume` 到着でも timer が再起動しない
  - idle 到達後の stale `onresume` で timer が更新されない（interval が復活しない）
  - 上記いずれのケースでも `onCaptured.durationMs` に停止処理中の待ち時間が混入しない
- **操作種別付き pending の交差（R3-2）**: 以下 3 ケースを追加検証:
  - pause の stale `onpause` が進行中の resume pending（pendingOperation="resume"）を解除しない
  - resume の stale `onresume` が進行中の pause pending を解除しない
  - 古い（pause の）タイムアウト発火が後続 resume の pending を解除しない（timeout 内の `pendingOperation !== op` ガード）
- **同種操作の交差で handle/pending を壊さない（R4-2）**: 古い pause タイムアウト（遅延実行）→ その間に新しい pause 要求が arm した後に古い callback が走っても、`pauseResumeTimeout !== handle` で早期 return し、新しい timeout handle を null 化せず・新しい pending を解除しない（新 pending と新 timeout が維持される）
- **inactive フェイルセーフ（R1-W）**: recording 中に recorder.state=inactive を作り recover 経路を踏ませ、fatalStopCleanup で idle 復帰 + onCameraUnavailable が呼ばれる
- **グリッドトグル**: `toggle-grid` クリックで `grid-overlay` 表示/非表示 + aria-pressed 反転 + `gridToggleLabel` 同期（連打で状態一致）。字幕が空でも disabled でない
- **停止ボタン stopping 可視（R1-Critical）**: 既存「safeStop 多重クリック」テストが green（stopping で `stop-recording` 可視 + 2 度目 no-op / stopCalls 不増）
- **カメラ反転（idle）**:
  - live stream 無し（初回・録画前）: `flip-camera` クリックで次回 getUserMedia の facingMode が "user"（録画開始で getUserMedia の constraint を検証）
  - live stream 有り + applyConstraints 成功（getSettings が target 返す）: 同一 stream 維持（getUserMedia 追加呼出なし）
  - `getSettings().facingMode === undefined`（R1-W）: 未検証扱いで再取得経路へ倒す
  - applyConstraints 不成立 → 再取得（releaseCamera + getUserMedia 再呼出、新 facingMode）
  - 新 facing のみ不可（OverconstrainedError）→ 旧 facingMode 復旧（元カメラ継続、error「切り替えられませんでした」、**onCameraUnavailable 呼ばれない**＝flip 初回失敗の非 F-03）
  - 両カメラ喪失（新=Overconstrained・旧=NotFound 等の恒久失敗）→ `onCameraUnavailable(reason)` 委譲（段階4 の F-03）
  - 両カメラ喪失（一時失敗）→ error 表示のみ、onCameraUnavailable 呼ばれない
- **録画タイマー**: fake timers で録画中 `record-timer` が "00:00"→時間経過で更新。pause で停止（進まない）、resume で再開。stop で消える
- **durationMs は pause 区間を除外（R1-W）**: fake timers で record(区間A)→pause→（壁時計だけ進む）→resume→record(区間B)→stop。`onCaptured` の durationMs が **区間A+区間B のみ**（pause 中の壁時計を含まない）であることを厳密検証
- **timer tick 遅延でも累積破綻しない（R1-W）**: interval tick を間引いても（performance.now 差分ベースのため）elapsed/durationMs が破綻しない
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

## user: 実装差分 (git diff HEAD -- resources/ tests/)

```diff
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 06d7b05..f3d2d59 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -1,10 +1,31 @@
 <script lang="ts">
     import { onDestroy } from "svelte";
-    import { Captions, CaptionsOff, Circle, Square } from "@lucide/svelte";
+    import {
+        Captions,
+        CaptionsOff,
+        Circle,
+        Grid3x3,
+        Pause,
+        Play,
+        Square,
+        SwitchCamera,
+        Timer,
+    } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
+    import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
     import SubtitleOverlay from "@/components/features/capture/SubtitleOverlay.svelte";
-    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
-    import type { CameraUnavailableReason } from "@/lib/capture/camera";
+    import {
+        classifyGetUserMediaError,
+        formatElapsed,
+        nextFacingMode,
+        preferredRecordingMimeType,
+        supportsPauseResume,
+    } from "@/lib/capture/camera";
+    import type {
+        CameraErrorClassification,
+        CameraUnavailableReason,
+        FacingMode,
+    } from "@/lib/capture/camera";
     import type { CaptureCut } from "@/types/capture";
 
     /**
@@ -13,12 +34,15 @@
      * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
      * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
      *
-     * 撮影 active の phase マシン (T050 / S4): idle / recording / stopping。
+     * 撮影 active の phase マシン (T050 / S4): idle / recording / paused / stopping。
      * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
      * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
      * 含めることで、取得中でも親の captureActive が true になり preview が開けない
      * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
      * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
+     *
+     * T056 (capture-ux-enrichment): 録画タイマー / 一時停止・再開 / グリッド / カメラ反転を追加。
+     * paused も非 idle のため active=true を維持し preview 排他を保つ。
      */
     interface Props {
         onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
@@ -39,17 +63,21 @@
         onCaptureActiveChange,
     }: Props = $props();
 
-    type Phase = "idle" | "recording" | "stopping";
+    // 単一ソース union (R2 反映: paused を追加)
+    type Phase = "idle" | "recording" | "paused" | "stopping";
 
     // 字幕オーバーレイの表示トグル (doc/05 §5.2)。v1 中核価値が字幕のため既定 ON。
     let showSubtitles = $state(true);
     const subtitleToggleLabel = $derived(showSubtitles ? "字幕を非表示" : "字幕を表示");
 
+    // グリッド overlay の表示トグル。字幕と違い構図補助は任意のため既定 OFF (doc/05 §5.2)。
+    let showGrid = $state(false);
+    const gridToggleLabel = $derived(showGrid ? "グリッドを非表示" : "グリッドを表示");
+
     let video: HTMLVideoElement | null = $state(null);
     let stream: MediaStream | null = null;
     let recorder: MediaRecorder | null = null;
     let chunks: Blob[] = [];
-    let startedAt = 0;
     let phase = $state<Phase>("idle");
     let error = $state<string | null>(null);
     /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
@@ -62,6 +90,29 @@
     let resuming = false;
     let resumePromise: Promise<void> | null = null;
 
+    // --- 一時停止/再開 (S4)。イベント基準・in-flight ガード・タイムアウト復旧 ---
+    // pause/resume 要求の in-flight ガード。**boolean ではなく操作種別を保持** する (R3-2 反映):
+    // stale な onpause が進行中の resume の pending を誤って解除する事故を防ぐため、
+    // 一致する操作のイベント/タイムアウトのみが pending を解除する。
+    type PauseResumeOperation = "pause" | "resume";
+    let pendingOperation: PauseResumeOperation | null = null;
+    // pause/resume イベント未到達検出のタイムアウト handle (R3-S)
+    let pauseResumeTimeout: ReturnType<typeof setTimeout> | null = null;
+    // 能力検査は module 初期化時に一度評価 (ボタンの出し分けに使う)
+    const canPauseResume = supportsPauseResume();
+
+    // --- 録画タイマー (S3)。performance.now() 累積・pause 対応 ---
+    let elapsedMs = $state(0);
+    let accumulatedMs = 0; // pause で確定した累積 (performance.now ベース)
+    let segmentStart = 0; // 現 recording 区間の開始 (performance.now())
+    let timerHandle: ReturnType<typeof setInterval> | null = null;
+    const elapsedLabel = $derived(formatElapsed(elapsedMs));
+    const showTimer = $derived(phase === "recording" || phase === "paused");
+
+    // --- カメラ反転 (S6)。idle 時のみ・段階的縮退 ---
+    let facingMode = $state<FacingMode>("environment");
+    let flipping = false; // flip 再入ガード
+
     // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
     // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
     function syncActive(): void {
@@ -78,29 +129,83 @@
         syncActive();
     }
 
-    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
-    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
-    async function acquirePreviewStream(): Promise<boolean> {
+    // --- 録画タイマー関数群 (S3) ---
+    // recording 区間の計測開始 (start / resume で呼ぶ)
+    function startTimer(): void {
+        if (timerHandle !== null) return; // 二重起動防止
+        segmentStart = performance.now();
+        timerHandle = setInterval(() => {
+            elapsedMs = accumulatedMs + (performance.now() - segmentStart);
+        }, 200);
+    }
+    // 計測停止 + 累積確定 (pause / stop / idle / destroy で呼ぶ)
+    function stopTimer(): void {
+        if (timerHandle !== null) {
+            accumulatedMs += performance.now() - segmentStart;
+            clearInterval(timerHandle);
+            timerHandle = null;
+        }
+        elapsedMs = accumulatedMs;
+    }
+    function resetTimer(): void {
+        if (timerHandle !== null) {
+            clearInterval(timerHandle);
+            timerHandle = null;
+        }
+        accumulatedMs = 0;
+        segmentStart = 0;
+        elapsedMs = 0;
+    }
+    // 実録画尺 (durationMs 用)。累積 + 現区間の経過 (recording 中に stop されたケース)。
+    // R1-S: Math.max(0, …) で明示クランプ (防御的。performance.now 単調増加のため通常は非負)。
+    function recordedDurationMs(): number {
+        const raw =
+            timerHandle !== null ? accumulatedMs + (performance.now() - segmentStart) : accumulatedMs;
+        return Math.max(0, raw);
+    }
+
+    // --- getUserMedia の制約を facingMode から組む (S6) ---
+    function videoConstraints(): MediaTrackConstraints {
+        return { facingMode };
+    }
+
+    // 副作用なしの取得 (classify 結果を返すだけ。onCameraUnavailable/error を呼ばない)。
+    // 呼び出し前に stream=null であること (reacquire 前は releaseCamera 済み)。stream ??= のため
+    // 既存 stream があれば再取得しない = flip の reacquire では releaseCamera() 後に呼ぶ。
+    async function acquireStream(): Promise<CameraErrorClassification | { kind: "ok" }> {
         try {
             stream ??= await navigator.mediaDevices.getUserMedia({
-                video: { facingMode: "environment" },
+                video: videoConstraints(), // facingMode を反映 (現行の "environment" 直書きを置換)
                 audio: true,
             });
         } catch (cause) {
-            const classified = classifyGetUserMediaError(cause);
-            if (classified.kind === "transient") {
-                error =
-                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
-                return false;
-            }
-            onCameraUnavailable(classified.reason);
-            return false;
+            return classifyGetUserMediaError(cause);
         }
         if (video) {
             video.srcObject = stream;
             await video.play().catch(() => undefined);
         }
-        return true;
+        return { kind: "ok" };
+    }
+
+    // classify 失敗に既存の副作用ポリシーを適用 (transient→error / unavailable→F-03 委譲)。
+    function applyAcquireFailure(result: CameraErrorClassification): void {
+        if (result.kind === "transient") {
+            error =
+                "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
+            return;
+        }
+        onCameraUnavailable(result.reason);
+    }
+
+    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
+    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
+    // 既存契約を維持するラッパ (startRecording / resumeAfterPreview は無改変で呼べる)。
+    async function acquirePreviewStream(): Promise<boolean> {
+        const result = await acquireStream();
+        if (result.kind === "ok") return true;
+        applyAcquireFailure(result);
+        return false;
     }
 
     async function startRecording(): Promise<void> {
@@ -132,11 +237,28 @@
             recorder.ondataavailable = (event) => {
                 if (event.data.size > 0) chunks.push(event.data);
             };
+            // 一時停止/再開はイベント基準で phase を確定する (S4)。要求ハンドラは phase を
+            // 同期で動かさず、MediaRecorder の onpause/onresume 到達で初めて遷移する。
+            // R2-Critical: タイマー操作は phase 条件の内側で行う。stale な onpause/onresume が
+            // stopping/idle で到着しても timer を触らない (durationMs 汚染・interval リークを防ぐ)。
+            recorder.onpause = () => {
+                if (pendingOperation === "pause") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
+                if (phase !== "recording") return; // stale なら timer/phase を触らない
+                stopTimer(); // 経過計測を止める (累積は保持)
+                setPhase("paused");
+            };
+            recorder.onresume = () => {
+                if (pendingOperation === "resume") clearPauseResumePending(); // 一致操作のみ解除 (R3-2)
+                if (phase !== "paused") return; // stale なら timer/phase を触らない
+                startTimer(); // 経過計測を再開 (累積へ加算)
+                setPhase("recording");
+            };
             // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
             recorder.onstop = async () => {
+                // 実録画尺 (pause 区間を除外)。resetTimer 前に確定する。
+                const durationMs = recordedDurationMs();
                 try {
                     const blob = new Blob(chunks, { type: mimeType });
-                    const durationMs = Date.now() - startedAt;
                     if (blob.size > 0) {
                         await onCaptured(blob, mimeType, durationMs);
                     }
@@ -144,6 +266,8 @@
                     // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
                     error = "撮影データの処理に失敗しました。もう一度お試しください。";
                 } finally {
+                    resetTimer(); // idle 到達時の interval リーク防止
+                    clearPauseResumePending();
                     setPhase("idle");
                 }
             };
@@ -151,13 +275,15 @@
             stream.getTracks().forEach((track) => {
                 track.onended = () => safeStop();
             });
-            startedAt = Date.now();
+            resetTimer();
+            startTimer(); // 従来の startedAt = Date.now() を置換 (R1: 実録画尺へ是正)
             try {
                 recorder.start();
             } catch {
                 // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
                 // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
                 recorder = null;
+                resetTimer();
                 releaseCamera();
                 onCameraUnavailable("recorder_unsupported");
                 return;
@@ -171,16 +297,89 @@
         }
     }
 
-    // 安全停止 (多重呼び出しガード)。recording 以外では no-op (stopping/idle で重複 stop しない)。
+    // 一時停止要求 (recording のみ)。pending 中 (種別を問わず) は多重押下ガードで拒否。
+    function requestPause(): void {
+        if (phase !== "recording" || pendingOperation !== null || recorder === null) return;
+        if (!supportsPauseResume()) return; // 未対応端末はボタン非表示のため通常到達しない
+        pendingOperation = "pause";
+        armPauseResumeTimeout("pause");
+        try {
+            recorder.pause();
+        } catch {
+            // InvalidStateError 等: pending を解除し recorder.state から phase を復旧
+            clearPauseResumePending();
+            recoverPhaseFromRecorderState();
+        }
+    }
+
+    // 録画再開要求 (paused のみ)
+    function requestResume(): void {
+        if (phase !== "paused" || pendingOperation !== null || recorder === null) return;
+        pendingOperation = "resume";
+        armPauseResumeTimeout("resume");
+        try {
+            recorder.resume();
+        } catch {
+            clearPauseResumePending();
+            recoverPhaseFromRecorderState();
+        }
+    }
+
+    // イベント未到達の保険 (R3-S: 解除条件 = onpause/onresume/onerror/onstop/タイムアウト)。
+    // 操作種別を渡し、**古いタイムアウトが後続操作の pending/handle を奪わない** よう二重防御する:
+    //  (1) handle 自己同定: 遅延実行された古い callback は `pauseResumeTimeout !== handle` で
+    //      早期 return し、新しい timeout の handle を null 化しない (R4-2 の handle 喪失防止)。
+    //  (2) 操作種別一致: 自分の操作がまだ pending のときだけ pending を解除して復旧する。
+    function armPauseResumeTimeout(op: PauseResumeOperation): void {
+        clearPauseResumeTimeout();
+        const handle: ReturnType<typeof setTimeout> = setTimeout(() => {
+            if (pauseResumeTimeout !== handle) return; // 古い callback は新 handle を触らない (R4-2)
+            pauseResumeTimeout = null;
+            if (pendingOperation !== op) return; // 自分の操作が解決/交代済みなら何もしない
+            pendingOperation = null;
+            recoverPhaseFromRecorderState(); // 遅延イベントが来ても phase は state 同期のみ
+        }, 2000);
+        pauseResumeTimeout = handle;
+    }
+    function clearPauseResumeTimeout(): void {
+        if (pauseResumeTimeout !== null) {
+            clearTimeout(pauseResumeTimeout);
+            pauseResumeTimeout = null;
+        }
+    }
+    function clearPauseResumePending(): void {
+        pendingOperation = null;
+        clearPauseResumeTimeout();
+    }
+
+    // recorder.state を真実源に UI phase を同期 (stopping 中は onstop に委ねるため触らない)
+    function recoverPhaseFromRecorderState(): void {
+        if (recorder === null || phase === "stopping") return;
+        const state = recorder.state; // "inactive" | "recording" | "paused"
+        if (state === "inactive") {
+            // R1-W フェイルセーフ: recording/paused 中に recorder が inactive (onstop 永久未達 UA
+            // バグ等の異常系) を検出したら、復帰不能を防ぐため fatalStopCleanup で idle 復帰 + 資源解放。
+            fatalStopCleanup();
+            return;
+        }
+        const nextPhase: Phase = state === "paused" ? "paused" : "recording";
+        if (state === "paused") stopTimer();
+        else startTimer();
+        if (phase !== nextPhase) setPhase(nextPhase);
+    }
+
+    // 安全停止 (多重呼び出しガード)。recording/paused 以外では no-op (stopping/idle で重複 stop しない)。
+    // paused からも停止できる必要がある (recorder.onerror は paused 中にも発火し得るため。R1-Critical)。
     function safeStop(): void {
-        if (phase !== "recording") return;
+        if (phase !== "recording" && phase !== "paused") return;
+        clearPauseResumePending();
         setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
         if (recorder === null) {
             fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
             return;
         }
         try {
-            recorder.stop(); // → recorder.onstop へ
+            recorder.stop(); // paused 状態でも stop() は onstop を発火し blob 確定
         } catch {
             fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
         }
@@ -188,6 +387,8 @@
 
     // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
     function fatalStopCleanup(): void {
+        resetTimer();
+        clearPauseResumePending();
         setPhase("idle");
         releaseCamera();
         onCameraUnavailable("recorder_unsupported");
@@ -198,6 +399,66 @@
         stream = null;
     }
 
+    // --- カメラ反転 (S6)。idle 時のみ機能。段階的縮退 (R2/R3) ---
+    async function flipCamera(): Promise<void> {
+        // idle 以外・取得中・flip 中は no-op (録画中の stream 再取得を避ける)
+        if (starting || resuming || flipping || phase !== "idle") return;
+        const target = nextFacingMode(facingMode);
+
+        // live stream 未保持 (録画前): state 更新のみ、次回 getUserMedia に反映
+        if (stream === null) {
+            facingMode = target;
+            return;
+        }
+        flipping = true;
+        try {
+            error = null;
+            const track = stream.getVideoTracks()[0] ?? null;
+            // 段階1: applyConstraints({exact}) + getSettings 検証 (同一 stream 維持)
+            if (track !== null && (await tryApplyFacing(track, target))) {
+                facingMode = target;
+                return;
+            }
+            // 段階2〜4: 再取得 (旧停止 → 新取得 → 失敗時旧復旧 → 完全喪失で classify)
+            await reacquireWithFacing(target);
+        } finally {
+            flipping = false;
+        }
+    }
+
+    // 段階1: exact 制約を適用し getSettings で実切替を検証 (R3: resolve≠実切替)
+    async function tryApplyFacing(track: MediaStreamTrack, target: FacingMode): Promise<boolean> {
+        try {
+            await track.applyConstraints({ facingMode: { exact: target } });
+        } catch {
+            return false;
+        }
+        // R1-W: getSettings().facingMode が undefined の端末は「未検証扱い」で false を返し
+        // 再取得経路 (段階2〜) へ倒す (安全側。誤って同一 stream 維持で切替失敗を隠さない)。
+        const applied = track.getSettings().facingMode;
+        return applied === target;
+    }
+
+    // 段階2〜4: 旧 stream 停止 → 新取得 → 失敗時旧復旧 → 完全喪失で初めて副作用 (R3 + R1-critical)
+    // 副作用なしの acquireStream() を使い、onCameraUnavailable(F-03)/error の発火を段階4 まで遅延する。
+    async function reacquireWithFacing(target: FacingMode): Promise<void> {
+        const previous = facingMode;
+        releaseCamera(); // 旧 stream 停止 (二重取得不可端末に対応。stream=null になる)
+        facingMode = target;
+        const forward = await acquireStream(); // 段階2: 副作用なし取得
+        if (forward.kind === "ok") return;
+        // 段階3: 旧 facingMode で再取得して復旧 (flip 断念・元カメラ継続。onCameraUnavailable は呼ばない)
+        facingMode = previous;
+        const back = await acquireStream();
+        if (back.kind === "ok") {
+            error = "カメラを切り替えられませんでした。";
+            return;
+        }
+        // 段階4: 両カメラ喪失。段階3 の classify(back) に対してのみ副作用を適用
+        // (transient→error 表示 / unavailable→onCameraUnavailable(F-03) 委譲)。
+        applyAcquireFailure(back);
+    }
+
     // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
     // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
     // 解放しない (Codex R1/R3-S4)。
@@ -226,7 +487,11 @@
         return resumePromise;
     }
 
-    onDestroy(releaseCamera);
+    onDestroy(() => {
+        resetTimer();
+        clearPauseResumeTimeout();
+        releaseCamera();
+    });
 </script>
 
 <div class="flex flex-col gap-3">
@@ -240,11 +505,24 @@
             class="aspect-video w-full rounded-md bg-surface object-cover"
             data-testid="camera-preview"
         ></video>
+        <!-- overlay の z 順 (DOM 順で映像 < grid < 字幕帯): グリッドは字幕より先 = 下層 -->
+        <GridOverlay visible={showGrid} />
         <SubtitleOverlay
             primary={subtitlePrimary}
             secondary={subtitleSecondary}
             visible={showSubtitles}
         />
+        {#if showTimer}
+            <!-- 録画タイマー (overlay 右上)。recording/paused 時のみ -->
+            <div
+                class="pointer-events-none absolute top-2 right-2 flex items-center gap-1 rounded-sm bg-text/70 px-2 py-1 text-caption text-surface"
+                data-testid="record-timer"
+            >
+                <Timer class="size-3.5" aria-hidden="true" />
+                <span aria-live="off">{elapsedLabel}</span>
+                {#if phase === "paused"}<span class="sr-only">一時停止中</span>{/if}
+            </div>
+        {/if}
     </div>
     <div class="flex items-center justify-center gap-3">
         {#if phase === "idle"}
@@ -252,12 +530,57 @@
                 <Circle class="size-4" aria-hidden="true" />
                 録画開始
             </Button>
+            <!-- カメラ反転 (idle のみ表示 = 文脈非該当時は非表示。disabled ではない) -->
+            <button
+                type="button"
+                class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                aria-label="カメラを切り替え"
+                onclick={flipCamera}
+                data-testid="flip-camera"
+            >
+                <SwitchCamera class="size-5" aria-hidden="true" />
+            </button>
         {:else}
+            <!-- recording / paused / stopping 共通: 停止ボタンは常時可視 (stopping では no-op) -->
+            {#if phase === "recording" && canPauseResume}
+                <!-- 一時停止 (supportsPauseResume() 時のみ表示。未対応は非表示で start/stop のみ) -->
+                <button
+                    type="button"
+                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                    aria-label="一時停止"
+                    onclick={requestPause}
+                    data-testid="pause-recording"
+                >
+                    <Pause class="size-5" aria-hidden="true" />
+                </button>
+            {:else if phase === "paused"}
+                <!-- 録画再開 -->
+                <button
+                    type="button"
+                    class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+                    aria-label="録画を再開"
+                    onclick={requestResume}
+                    data-testid="resume-recording"
+                >
+                    <Play class="size-5" aria-hidden="true" />
+                </button>
+            {/if}
             <Button variant="danger" onclick={safeStop} testId="stop-recording">
                 <Square class="size-4" aria-hidden="true" />
                 録画停止
             </Button>
         {/if}
+        <!-- グリッドトグル (常時表示・字幕トグルと並置。字幕が空でも disabled にしない = 禁止事項 8) -->
+        <button
+            type="button"
+            class="flex items-center rounded-sm p-2 text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
+            aria-label={gridToggleLabel}
+            aria-pressed={showGrid}
+            onclick={() => (showGrid = !showGrid)}
+            data-testid="toggle-grid"
+        >
+            <Grid3x3 class="size-5" aria-hidden="true" />
+        </button>
         <!-- 字幕トグル (録画ボタン右)。二値の pressed 状態は raw button + aria-pressed で表現
              (先例: molecules/PasswordInput.svelte)。字幕が空でも disabled にしない (禁止事項 8) -->
         <button
diff --git a/resources/js/components/features/capture/GridOverlay.svelte b/resources/js/components/features/capture/GridOverlay.svelte
new file mode 100644
index 0000000..d3a35e7
--- /dev/null
+++ b/resources/js/components/features/capture/GridOverlay.svelte
@@ -0,0 +1,21 @@
+<script lang="ts">
+    /**
+     * 撮影プレビューへ重畳する三分割グリッド (doc/05 §5.2 グリッド表示)。
+     * 構図補助の overlay で MediaRecorder が録る MediaStream には含まれない (焼込ではない)。
+     * z 順は映像 < grid < 字幕帯 (SubtitleOverlay)。字幕帯と重なっても字幕優先で可読。
+     */
+    interface Props {
+        visible: boolean;
+    }
+    let { visible }: Props = $props();
+</script>
+
+{#if visible}
+    <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="grid-overlay">
+        <!-- 三分割: 縦 2 本・横 2 本の細線。DS token surface の半透明で映像上に薄く表示 -->
+        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/40"></div>
+        <div class="absolute inset-y-0 left-2/3 w-px bg-surface/40"></div>
+        <div class="absolute inset-x-0 top-1/3 h-px bg-surface/40"></div>
+        <div class="absolute inset-x-0 top-2/3 h-px bg-surface/40"></div>
+    </div>
+{/if}
diff --git a/resources/js/lib/capture/camera.ts b/resources/js/lib/capture/camera.ts
index b857ee2..b8807a5 100644
--- a/resources/js/lib/capture/camera.ts
+++ b/resources/js/lib/capture/camera.ts
@@ -72,3 +72,37 @@ export function classifyGetUserMediaError(error: unknown): CameraErrorClassifica
             return { kind: "unavailable", reason: "unknown" };
     }
 }
+
+/** 前後カメラの facingMode (doc/05 §5.2 カメラ反転 in/out)。型の単一ソース。 */
+export type FacingMode = "environment" | "user";
+
+/** environment ⇄ user の反転。型の単一ソース化 + テスト容易性のため pure 関数化。 */
+export function nextFacingMode(mode: FacingMode): FacingMode {
+    return mode === "environment" ? "user" : "environment";
+}
+
+/**
+ * 経過ミリ秒を mm:ss へ整形 (録画タイマー表示用。doc/05 §5.2「00:00」)。
+ * 負値・NaN は 0 に丸め、60 分以上も mm が桁溢れして連続表示される (分を切り捨てない)。
+ */
+export function formatElapsed(ms: number): string {
+    const totalSeconds = Number.isFinite(ms) && ms > 0 ? Math.floor(ms / 1000) : 0;
+    const minutes = Math.floor(totalSeconds / 60);
+    const seconds = totalSeconds % 60;
+    const mm = String(minutes).padStart(2, "0");
+    const ss = String(seconds).padStart(2, "0");
+    return `${mm}:${ss}`;
+}
+
+/**
+ * MediaRecorder が pause()/resume() を提供するかの **存在確認のみ** (doc/05 §5.2 一時停止/再開)。
+ * 注意: これは API の存在確認であって正常動作の保証ではない。実行時の InvalidStateError や
+ * pause/resume イベント未到達への退行 (recorder.state からの phase 復旧) が最終防御。
+ */
+export function supportsPauseResume(): boolean {
+    return (
+        typeof window.MediaRecorder !== "undefined" &&
+        typeof window.MediaRecorder.prototype?.pause === "function" &&
+        typeof window.MediaRecorder.prototype?.resume === "function"
+    );
+}
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index 92dbe73..a3931cb 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -16,13 +16,22 @@ class FakeMediaRecorder {
     }
     static shouldThrowOnConstruct = false;
     static shouldThrowOnStart = false;
+    static shouldThrowOnPause = false;
     /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
     static autoStop = true;
+    /** false のとき pause()/resume() は onpause/onresume を自動発火せず、テストが手動で駆動する */
+    static autoPauseResume = true;
 
     ondataavailable: ((event: { data: Blob }) => void) | null = null;
     onstop: (() => void) | null = null;
     onerror: (() => void) | null = null;
+    onpause: (() => void) | null = null;
+    onresume: (() => void) | null = null;
     stopCalls = 0;
+    pauseCalls = 0;
+    resumeCalls = 0;
+    /** RecordingState 相当 (recoverPhaseFromRecorderState が参照する真実源) */
+    state: "inactive" | "recording" | "paused" = "inactive";
 
     constructor(
         public stream: unknown,
@@ -37,21 +46,47 @@ class FakeMediaRecorder {
         if (FakeMediaRecorder.shouldThrowOnStart) {
             throw new DOMException("invalid state", "InvalidStateError");
         }
+        this.state = "recording";
         // no-op (テストは stop() で明示的に onstop を駆動する)
     }
 
     stop(): void {
         this.stopCalls += 1;
+        this.state = "inactive";
         if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
         this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
         this.onstop?.();
     }
 
+    pause(): void {
+        if (FakeMediaRecorder.shouldThrowOnPause) {
+            throw new DOMException("invalid state", "InvalidStateError");
+        }
+        this.pauseCalls += 1;
+        this.state = "paused";
+        if (FakeMediaRecorder.autoPauseResume) this.onpause?.();
+    }
+
+    resume(): void {
+        this.resumeCalls += 1;
+        this.state = "recording";
+        if (FakeMediaRecorder.autoPauseResume) this.onresume?.();
+    }
+
     /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
     fireStop(): void {
+        this.state = "inactive";
         this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
         this.onstop?.();
     }
+
+    /** 手動モードで onpause/onresume を駆動する */
+    firePause(): void {
+        this.onpause?.();
+    }
+    fireResume(): void {
+        this.onresume?.();
+    }
 }
 
 /** 直近に構築された FakeMediaRecorder を捕捉する (onerror/onstop 手動駆動用) */
@@ -65,18 +100,31 @@ class TrackingFakeMediaRecorder extends FakeMediaRecorder {
 
 const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
-/** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
-function fakeStream(): {
+interface FakeTrack {
+    stop: ReturnType<typeof vi.fn>;
+    onended: (() => void) | null;
+    applyConstraints: ReturnType<typeof vi.fn>;
+    getSettings: ReturnType<typeof vi.fn>;
+}
+
+/** getTracks()/getVideoTracks() が stop spy 付き track を返す fake stream (解放・flip 検証用) */
+function fakeStream(facing: "environment" | "user" = "environment"): {
     stream: MediaStream;
     stop: ReturnType<typeof vi.fn>;
-    track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null };
+    track: FakeTrack;
 } {
     const stop = vi.fn();
-    const track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null } = {
+    const track: FakeTrack = {
         stop,
         onended: null,
+        // 既定は制約適用成功 + getSettings が要求 facingMode を返す (段階1 成功)
+        applyConstraints: vi.fn().mockResolvedValue(undefined),
+        getSettings: vi.fn(() => ({ facingMode: facing })),
     };
-    const stream = { getTracks: () => [track] } as unknown as MediaStream;
+    const stream = {
+        getTracks: () => [track],
+        getVideoTracks: () => [track],
+    } as unknown as MediaStream;
     return { stream, stop, track };
 }
 
@@ -84,7 +132,9 @@ beforeEach(() => {
     FakeMediaRecorder.supportedTypes = ["video/webm"];
     FakeMediaRecorder.shouldThrowOnConstruct = false;
     FakeMediaRecorder.shouldThrowOnStart = false;
+    FakeMediaRecorder.shouldThrowOnPause = false;
     FakeMediaRecorder.autoStop = true;
+    FakeMediaRecorder.autoPauseResume = true;
     lastRecorder = null;
     getUserMediaMock.mockReset();
     vi.stubGlobal("MediaRecorder", TrackingFakeMediaRecorder);
@@ -98,6 +148,7 @@ beforeEach(() => {
 
 afterEach(() => {
     cleanup();
+    vi.useRealTimers();
     vi.unstubAllGlobals();
     vi.restoreAllMocks();
 });
@@ -589,4 +640,352 @@ describe("CameraRecorder", () => {
         await ref.resumeAfterPreview();
         expect(getUserMediaMock).toHaveBeenCalledTimes(3);
     });
+
+    // ---- T056 / S3-S6: 録画タイマー・一時停止/再開・グリッド・カメラ反転 ----
+
+    /** 録画開始し phase=recording (stop ボタン出現) まで進める共通ヘルパ */
+    async function startAndRecord(props: Record<string, unknown> = {}): Promise<void> {
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), ...props },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+    }
+
+    it("一時停止/再開: pause→onpause で paused、resume→onresume で recording に戻る (イベント基準)", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        // 録画中は一時停止ボタンが出る (canPauseResume=true)
+        expect(screen.getByTestId("pause-recording")).toBeInTheDocument();
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+
+        // onpause 到達で paused: 再開ボタン + タイマーに「一時停止中」sr-only
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+        expect(lastRecorder?.pauseCalls).toBe(1);
+        expect(screen.queryByTestId("pause-recording")).not.toBeInTheDocument();
+        expect(screen.getByTestId("record-timer")).toHaveTextContent("一時停止中");
+
+        // 再開
+        await fireEvent.click(screen.getByTestId("resume-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("pause-recording")).toBeInTheDocument());
+        expect(lastRecorder?.resumeCalls).toBe(1);
+        expect(screen.queryByTestId("resume-recording")).not.toBeInTheDocument();
+    });
+
+    it("paused から停止すると onCaptured を 1 本呼び idle へ戻る", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn();
+        await startAndRecord({ onCaptured });
+
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        // idle へ復帰 (録画開始ボタン)
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(onCaptured).toHaveBeenCalledTimes(1);
+    });
+
+    it("多重押下ガード: pause 要求後 onpause 到達前に再クリックしても pause() は 1 回", async () => {
+        FakeMediaRecorder.autoPauseResume = false; // onpause を手動制御
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        expect(lastRecorder?.pauseCalls).toBe(1); // pending 中の 2 度目は弾く
+    });
+
+    it("能力未対応 (prototype に pause/resume 無し) は一時停止ボタンを表示しない", async () => {
+        class NoPauseRecorder {
+            static isTypeSupported(type: string): boolean {
+                return ["video/webm"].includes(type);
+            }
+            ondataavailable: ((event: { data: Blob }) => void) | null = null;
+            onstop: (() => void) | null = null;
+            onerror: (() => void) | null = null;
+            stopCalls = 0;
+            state = "inactive";
+            constructor(
+                public stream: unknown,
+                public options: { mimeType: string },
+            ) {}
+            start(): void {
+                this.state = "recording";
+            }
+            stop(): void {
+                this.stopCalls += 1;
+                this.state = "inactive";
+                this.ondataavailable?.({
+                    data: new Blob(["frame"], { type: this.options.mimeType }),
+                });
+                this.onstop?.();
+            }
+        }
+        vi.stubGlobal("MediaRecorder", NoPauseRecorder);
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured, onCameraUnavailable: vi.fn() } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        // 一時停止ボタンは非表示 (start/stop のみ)。録画→停止は成立する
+        expect(screen.queryByTestId("pause-recording")).not.toBeInTheDocument();
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
+    });
+
+    it("InvalidStateError: pause() が throw したら recorder.state から phase を復旧する (recording 維持)", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        FakeMediaRecorder.shouldThrowOnPause = true; // pause() は throw (state は recording のまま)
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+
+        // 復旧: recording のまま (再度 pause ボタンが押せる = 詰まない)
+        expect(screen.getByTestId("pause-recording")).toBeInTheDocument();
+        expect(screen.queryByTestId("resume-recording")).not.toBeInTheDocument();
+    });
+
+    it("paused 中の onerror でも safeStop→onstop で停止完了し idle へ戻る (R1-Critical)", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn();
+        await startAndRecord({ onCaptured });
+
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+
+        // paused 中に recorder.onerror が発火 → safeStop (paused も停止可)
+        lastRecorder?.onerror?.();
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(onCaptured).toHaveBeenCalledTimes(1); // idle 復帰 + テイク確定
+    });
+
+    it("タイムアウト復旧: onpause 未達でも 2s 後に recorder.state から paused へ同期し、遅延 onpause は二重遷移しない (R1-W)", async () => {
+        FakeMediaRecorder.autoPauseResume = false; // onpause を発火させない
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        // onpause 未達。recorder.state は paused。タイムアウト(2s)で recover が同期する
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument(), {
+            timeout: 3000,
+        });
+
+        // 遅延 onpause が後から到達しても phase は paused のまま (二重遷移しない)
+        lastRecorder?.firePause();
+        expect(screen.getByTestId("resume-recording")).toBeInTheDocument();
+        expect(screen.queryByTestId("pause-recording")).not.toBeInTheDocument();
+    });
+
+    it("stale onpause で timer/phase を触らない: pause 要求直後に stop→idle 後の onpause は no-op (R2-Critical)", async () => {
+        FakeMediaRecorder.autoPauseResume = false;
+        FakeMediaRecorder.autoStop = false;
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn();
+        await startAndRecord({ onCaptured });
+
+        // pause 要求 (pending=pause, onpause 未達 → phase は recording のまま)
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        // 続けて停止 (recording から stopping。clearPauseResumePending で pending 解除)
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        // onstop 到達で idle
+        lastRecorder?.fireStop();
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(onCaptured).toHaveBeenCalledTimes(1);
+
+        // idle 後に stale onpause が到着しても timer/phase を触らない (idle のまま)
+        lastRecorder?.firePause();
+        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+        expect(screen.queryByTestId("record-timer")).not.toBeInTheDocument();
+    });
+
+    it("操作種別付き pending の交差: resume pending 中の stale onpause は resume を解除しない (R3-2)", async () => {
+        FakeMediaRecorder.autoPauseResume = false;
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        // pause → onpause 手動発火で paused 確定 (pending 解除)
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        lastRecorder?.firePause();
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+
+        // resume 要求 (pending=resume, onresume 未達 → phase は paused のまま)
+        await fireEvent.click(screen.getByTestId("resume-recording"));
+        // stale onpause が到着: pendingOperation は "resume" のため解除されず、phase!=recording で no-op
+        lastRecorder?.firePause();
+        // onresume が到達すれば recording へ遷移できる (resume pending が生きている証拠)
+        lastRecorder?.fireResume();
+        await vi.waitFor(() => expect(screen.getByTestId("pause-recording")).toBeInTheDocument());
+    });
+
+    it("グリッドトグル: toggle-grid で grid-overlay 表示/非表示 + aria-pressed 反転 (字幕が空でも押下可)", async () => {
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
+        const toggle = screen.getByTestId("toggle-grid");
+        expect(toggle).not.toBeDisabled();
+        expect(toggle).toHaveAttribute("aria-pressed", "false");
+        expect(screen.queryByTestId("grid-overlay")).not.toBeInTheDocument();
+
+        await fireEvent.click(toggle);
+        expect(screen.getByTestId("grid-overlay")).toBeInTheDocument();
+        expect(toggle).toHaveAttribute("aria-pressed", "true");
+        expect(toggle).toHaveAttribute("aria-label", "グリッドを非表示");
+
+        await fireEvent.click(toggle);
+        expect(screen.queryByTestId("grid-overlay")).not.toBeInTheDocument();
+        expect(toggle).toHaveAttribute("aria-pressed", "false");
+        expect(toggle).toHaveAttribute("aria-label", "グリッドを表示");
+    });
+
+    it("録画タイマー: recording 中に経過が更新され、pause で停止し stop で消える", async () => {
+        let now = 1000;
+        vi.spyOn(performance, "now").mockImplementation(() => now);
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        await startAndRecord();
+
+        // 開始直後は 00:00
+        expect(screen.getByTestId("record-timer")).toHaveTextContent("00:00");
+
+        // 5 秒経過 → interval tick で 00:05
+        now = 6000;
+        await vi.waitFor(() =>
+            expect(screen.getByTestId("record-timer")).toHaveTextContent("00:05"),
+        );
+
+        // pause で計測停止 (壁時計だけ進めても表示は据え置き)
+        await fireEvent.click(screen.getByTestId("pause-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+        now = 60000;
+        await new Promise((r) => setTimeout(r, 250)); // interval 周期を跨ぐ
+        expect(screen.getByTestId("record-timer")).toHaveTextContent("00:05");
+
+        // stop でタイマーは消える
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() =>
+            expect(screen.queryByTestId("record-timer")).not.toBeInTheDocument(),
+        );
+    });
+
+    it("durationMs は pause 区間を除外する (実録画尺のみ、R1-W)", async () => {
+        let now = 1000;
+        vi.spyOn(performance, "now").mockImplementation(() => now);
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn();
+        await startAndRecord({ onCaptured }); // startTimer: segmentStart=1000
+
+        now = 3000; // 区間A = 2000ms
+        await fireEvent.click(screen.getByTestId("pause-recording")); // stopTimer: accumulated=2000
+        await vi.waitFor(() => expect(screen.getByTestId("resume-recording")).toBeInTheDocument());
+
+        now = 10000; // pause 中の壁時計 (7000ms) は除外されるべき
+        await fireEvent.click(screen.getByTestId("resume-recording")); // startTimer: segmentStart=10000
+        await vi.waitFor(() => expect(screen.getByTestId("pause-recording")).toBeInTheDocument());
+
+        now = 11500; // 区間B = 1500ms
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
+
+        const durationMs = onCaptured.mock.calls[0][2] as number;
+        expect(durationMs).toBe(3500); // 区間A(2000) + 区間B(1500)、pause の 7000 は含まない
+    });
+
+    it("カメラ反転 (録画前・live stream 無し): flip で次回 getUserMedia の facingMode が 'user'", async () => {
+        const first = fakeStream("user");
+        getUserMediaMock.mockResolvedValue(first.stream);
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
+        // 録画前は stream 未保持 → flip は state 更新のみ (getUserMedia を呼ばない)
+        await fireEvent.click(screen.getByTestId("flip-camera"));
+        expect(getUserMediaMock).not.toHaveBeenCalled();
+
+        // 録画開始時の getUserMedia constraint に facingMode:"user" が反映される
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(getUserMediaMock).toHaveBeenCalledTimes(1));
+        const constraint = (getUserMediaMock.mock.calls[0] as unknown[])[0] as {
+            video: MediaTrackConstraints;
+        };
+        expect(constraint.video).toMatchObject({ facingMode: "user" });
+    });
+
+    it("カメラ反転 (live stream + applyConstraints 成功): 同一 stream を維持し getUserMedia を再呼び出ししない", async () => {
+        const s = fakeStream("user"); // getSettings は target(user) を返す = 段階1 成功
+        getUserMediaMock.mockResolvedValue(s.stream);
+
+        // 録画→停止で idle かつ stream 保持状態を作る
+        await startAndRecord();
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1);
+
+        await fireEvent.click(screen.getByTestId("flip-camera"));
+        await vi.waitFor(() => expect(s.track.applyConstraints).toHaveBeenCalled());
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1); // 再取得なし
+        expect(s.stop).not.toHaveBeenCalled(); // stream 維持
+    });
+
+    it("カメラ反転 (getSettings().facingMode が undefined): 未検証扱いで再取得経路へ倒す (R1-W)", async () => {
+        const live = fakeStream();
+        live.track.getSettings = vi.fn(() => ({})); // facingMode を返さない端末
+        const reacquired = fakeStream();
+        getUserMediaMock
+            .mockResolvedValueOnce(live.stream) // 初回録画
+            .mockResolvedValueOnce(reacquired.stream); // flip 再取得
+
+        await startAndRecord();
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("flip-camera"));
+        // 段階1 は未検証で false → releaseCamera + getUserMedia 再取得
+        await vi.waitFor(() => expect(getUserMediaMock).toHaveBeenCalledTimes(2));
+        expect(live.stop).toHaveBeenCalled();
+    });
+
+    it("カメラ反転 (新 facing のみ不可): 旧 facingMode へ復旧し onCameraUnavailable を呼ばない", async () => {
+        const live = fakeStream();
+        live.track.applyConstraints = vi.fn().mockRejectedValue(
+            new DOMException("overconstrained", "OverconstrainedError"),
+        );
+        const recovered = fakeStream();
+        getUserMediaMock
+            .mockResolvedValueOnce(live.stream) // 初回録画
+            .mockRejectedValueOnce(new DOMException("no front", "OverconstrainedError")) // 新 facing 不可
+            .mockResolvedValueOnce(recovered.stream); // 旧 facing 復旧
+        const onCameraUnavailable = vi.fn();
+
+        await startAndRecord({ onCameraUnavailable });
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("flip-camera"));
+        await vi.waitFor(() =>
+            expect(screen.getByRole("alert")).toHaveTextContent("カメラを切り替えられませんでした"),
+        );
+        // 元カメラは復旧済み。F-03 (onCameraUnavailable) には倒さない
+        expect(onCameraUnavailable).not.toHaveBeenCalled();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(3);
+    });
+
+    it("カメラ反転 (両カメラ喪失・恒久失敗): onCameraUnavailable(reason) へ委譲する (段階4 F-03)", async () => {
+        const live = fakeStream();
+        live.track.applyConstraints = vi.fn().mockRejectedValue(
+            new DOMException("overconstrained", "OverconstrainedError"),
+        );
+        getUserMediaMock
+            .mockResolvedValueOnce(live.stream) // 初回録画
+            .mockRejectedValueOnce(new DOMException("no front", "OverconstrainedError")) // 新 facing 不可
+            .mockRejectedValueOnce(new DOMException("gone", "NotFoundError")); // 旧 facing も喪失
+        const onCameraUnavailable = vi.fn();
+
+        await startAndRecord({ onCameraUnavailable });
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("flip-camera"));
+        await vi.waitFor(() =>
+            expect(onCameraUnavailable).toHaveBeenCalledWith("device_missing"),
+        );
+    });
 });
diff --git a/tests/js/components/features/capture/GridOverlay.test.ts b/tests/js/components/features/capture/GridOverlay.test.ts
new file mode 100644
index 0000000..7c729a1
--- /dev/null
+++ b/tests/js/components/features/capture/GridOverlay.test.ts
@@ -0,0 +1,36 @@
+import { afterEach, describe, expect, it } from "vitest";
+import { cleanup, render, screen } from "@testing-library/svelte";
+import GridOverlay from "@/components/features/capture/GridOverlay.svelte";
+
+/*
+ * GridOverlay: 撮影プレビューへ重畳する三分割グリッド (doc/05 §5.2)。
+ * 構図補助の装飾 overlay (aria-hidden)。visible で描画を切り替える。
+ */
+
+afterEach(() => {
+    cleanup();
+});
+
+describe("GridOverlay", () => {
+    it("visible=true で grid-overlay が描画される", () => {
+        render(GridOverlay, { props: { visible: true } });
+        expect(screen.getByTestId("grid-overlay")).toBeInTheDocument();
+    });
+
+    it("visible=false では描画されない", () => {
+        render(GridOverlay, { props: { visible: false } });
+        expect(screen.queryByTestId("grid-overlay")).not.toBeInTheDocument();
+    });
+
+    it("罫線が 4 本 (縦 2・横 2) 描画される", () => {
+        render(GridOverlay, { props: { visible: true } });
+        const overlay = screen.getByTestId("grid-overlay");
+        // 三分割線: overlay 直下の div (罫線) が 4 本
+        expect(overlay.querySelectorAll(":scope > div")).toHaveLength(4);
+    });
+
+    it("装飾 overlay として aria-hidden で読み上げ対象外", () => {
+        render(GridOverlay, { props: { visible: true } });
+        expect(screen.getByTestId("grid-overlay")).toHaveAttribute("aria-hidden", "true");
+    });
+});
diff --git a/tests/js/lib/capture/camera.test.ts b/tests/js/lib/capture/camera.test.ts
index 3ae7525..40cc03b 100644
--- a/tests/js/lib/capture/camera.test.ts
+++ b/tests/js/lib/capture/camera.test.ts
@@ -1,8 +1,11 @@
 import { afterEach, describe, expect, it, vi } from "vitest";
 import {
     classifyGetUserMediaError,
+    formatElapsed,
+    nextFacingMode,
     preferredRecordingMimeType,
     supportsMediaRecorder,
+    supportsPauseResume,
 } from "@/lib/capture/camera";
 
 /*
@@ -125,3 +128,48 @@ describe("classifyGetUserMediaError", () => {
         });
     });
 });
+
+describe("nextFacingMode", () => {
+    it("environment ⇄ user を双方向に反転する", () => {
+        expect(nextFacingMode("environment")).toBe("user");
+        expect(nextFacingMode("user")).toBe("environment");
+    });
+});
+
+describe("formatElapsed", () => {
+    it("経過ミリ秒を mm:ss へ整形する (秒切り捨て)", () => {
+        expect(formatElapsed(0)).toBe("00:00");
+        expect(formatElapsed(5000)).toBe("00:05");
+        expect(formatElapsed(65000)).toBe("01:05");
+        expect(formatElapsed(3599000)).toBe("59:59");
+    });
+
+    it("60 分以上は mm が桁溢れして連続表示される (分を切り捨てない)", () => {
+        expect(formatElapsed(3600000)).toBe("60:00");
+    });
+
+    it("負値・NaN は 00:00 に丸める", () => {
+        expect(formatElapsed(-1)).toBe("00:00");
+        expect(formatElapsed(Number.NaN)).toBe("00:00");
+        expect(formatElapsed(Number.POSITIVE_INFINITY)).toBe("00:00");
+    });
+});
+
+describe("supportsPauseResume", () => {
+    it("prototype に pause/resume を持つ MediaRecorder は true", () => {
+        vi.stubGlobal("MediaRecorder", {
+            prototype: { pause: () => undefined, resume: () => undefined },
+        });
+        expect(supportsPauseResume()).toBe(true);
+    });
+
+    it("pause/resume を持たない MediaRecorder は false", () => {
+        vi.stubGlobal("MediaRecorder", { prototype: {} });
+        expect(supportsPauseResume()).toBe(false);
+    });
+
+    it("MediaRecorder 自体が無ければ false", () => {
+        vi.stubGlobal("MediaRecorder", undefined);
+        expect(supportsPauseResume()).toBe(false);
+    });
+});
```

## user: テスト結果サマリー

- worktree 内: pnpm typecheck OK / pnpm lint OK / pnpm build OK
- capture 関連テスト: camera.test.ts + CameraRecorder.test.ts (42) + GridOverlay.test.ts 全 green
- 全 vitest スイート: 729 passed (ProjectsShow.test.ts の 1 件は本 PR と無関係の 5000ms タイムアウト flake、--testTimeout=30000 の単体実行で 31/31 green を確認)

## user: design system 参照 (触れた token)
- 既存 SubtitleOverlay.svelte が bg-text/70 text-surface を使用 (record-timer も同語彙)。GridOverlay は bg-surface/40 の罫線のみ。hex 直書きなし。
- atomic 構造: resources/js/components/features/capture/ に CameraRecorder / SubtitleOverlay / GridOverlay(新規) が同居。
