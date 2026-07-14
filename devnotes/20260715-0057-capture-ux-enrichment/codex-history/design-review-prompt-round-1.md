# 詳細設計レビュー依頼: capture-ux-enrichment

## アプリの使命（North Star）

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、そのシナリオをスマホ(PWA)でナビゲーション撮影することで、専門知識ゼロの現場作業者でも標準化されたマニュアル動画を作れるようにする。「思考ゼロ・編集ゼロ」。**v1 スコープ**: 字幕のみ / 撮影は PWA(同一オリジン・セッション認証) / 動画合成は自前 ffmpeg / 単一 Default Project。

## 禁止事項
1. テストなしの実装完了報告。2. PHPStan エラーの widen・baseline 化。3. dev DB への破壊操作。4. `response()->json()` 直書き。5. LLM の Prism 直呼び。6. prompt 文字列のコード直書き。7. 操作系 POST の `redirect()->intended()`。8. **必須条件未充足を理由にボタンを disabled にする UI（押下時にエラー表示する）**。

## 思考原則 — 全議論に適用
まず仮説を立てろ。データに真摯に向き合え。先人の知恵を探せ。機能の名前に立ち返れ。仕組みが機能していない段階で値を弄るな。

## ツール使用制限
コマンド実行・ファイル書き込みは一切行わず、提供されたテキストの分析に集中すること。ファイル読み込みは許可。

---

## あなたの役割

あなたは経験豊富な Web アプリケーションアーキテクトです。Laravel + Svelte アプリの詳細設計をレビューしてください。

【前提環境】PHP 8.4 + Laravel 12 + Svelte 5 (runes) + Inertia.js + TypeScript strict / PHPStan level 10 / Pest + Vitest / DTO + JsonResource / Laratrust RBAC。本件は**撮影 PWA フロントのみ**（PHP/API/DTO 変更なし）。

【レビュー観点】
1. コードの正確性（ロジックエラー、エッジケース、null 安全性、MediaRecorder / getUserMedia の状態機械）
2. 既存コードとの整合性（命名規約、パターン、export シグネチャ非破壊）
3. TypeScript strict 適合（union の exhaustiveness、null 安全）
4. テスト計画の網羅性（各施策に Vitest。既存テスト無回帰）
5. Inertia Props vs API の使い分け（本件は該当薄）
6. 副作用・後退リスク（既存の録画 / upload-queue / preview 排他 phase マシン / カメラ非対応フォールバックを壊さないか）
7. 波及変更の網羅性（型定義・親コンポーネント配線・テストが変更対象に含まれるか）
8. セキュリティ（本件はフロント撮影補助。認可・入力・tenant キー非関与の確認）
9. **DESIGN.md 準拠**（DS token 経由か、hex/raw palette を増やさないか。grid overlay の色）
10. **Atomic Design 準拠**（features/capture の責務分離、Lucide のみ、SVG 直書きなし）

【出力形式】
- 各施策ごとに判定: APPROVE / REQUEST_CHANGES
- 指摘は [Critical] [Warning] [Suggestion] で分類、Critical/Warning には必ず修正案
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語で出力

---

## 詳細設計書

（下記に detailed-design.md 全文を添付）

---

## 関連する現行コード（抜粋）

### resources/js/components/features/capture/CameraRecorder.svelte（現行・全文）
（下記添付）

### resources/js/lib/capture/camera.ts（現行・全文）
（下記添付）

### 親配線 resources/js/pages/Capture/Show.svelte（該当箇所）
- `<CameraRecorder bind:this={recorderRef} onCaptured=... onCameraUnavailable=... subtitlePrimary/secondary=... onCaptureActiveChange={(active) => (captureActive = active)} />`
- `TakeStrip` に `{captureActive}` / `onRequestCameraRelease={() => recorderRef?.releaseForPreview()}` / `onCameraResume={() => void recorderRef?.resumeAfterPreview()}` を渡す。
- 本設計はこの配線・export シグネチャを非破壊で拡張する（内部拡張のみ）。

### 既存テスト tests/js/components/features/capture/CameraRecorder.test.ts
- `FakeMediaRecorder` stub（start/stop → ondataavailable/onstop、autoStop フラグで手動駆動）。getUserMedia mock、fakeStream（getTracks() が stop spy 付き track）。本設計はこれを pause/resume/onpause/onresume/state と facingMode 別 getSettings に拡張する。

---

## detailed-design.md 全文

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
```

### PHPStan 適合チェック
- 該当なし（TS）。TypeScript strict: union は exhaustive、`?.` で null 安全。

### テスト計画（S6 に集約）
- `oppositeFacingMode` の双方向、`canPause/canResume/canStop/canSwitchCamera` を全 6 phase で表明。
- `supportsPauseResume` が pause/resume どちらか欠落で false。
- `hasMultipleVideoInputs` が videoinput 数 0/1/2 と enumerateDevices 例外で期待値。

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
    type CapturePhase,
} from "@/lib/capture/camera";

let phase = $state<CapturePhase>("idle");
let previewResuming = false; // 旧 resuming (preview 再取得の再入ガード)
const pauseResumeSupported = supportsPauseResume(); // capability (mount 時定数)

// --- 実録画時間のセグメント累積 (source of truth。performance.now()) ---
let accumulatedMs = 0;        // 確定済みセグメントの合計
let segmentStart: number | null = null; // 現在 recording セグメントの開始 (null=計測停止中)

function elapsedMs(): number {
    return accumulatedMs + (segmentStart !== null ? performance.now() - segmentStart : 0);
}

function syncActive(): void {
    const active = starting || previewResuming || phase !== "idle";
    if (active !== lastActive) { lastActive = active; onCaptureActiveChange?.(active); }
}

// recorder.start() 直後: segmentStart を開始
// onpause: セグメント確定 (accumulatedMs += now - segmentStart; segmentStart=null)、phase=paused
// onresume: segmentStart = performance.now()、phase=recording
recorder.onpause = () => {
    if (segmentStart !== null) { accumulatedMs += performance.now() - segmentStart; segmentStart = null; }
    setPhase("paused");
};
recorder.onresume = () => {
    segmentStart = performance.now();
    setPhase("recording");
};
recorder.onstop = async () => {
    // 未確定 recording セグメントのみ加算 (onpause 済みなら segmentStart=null で二重加算しない)
    if (segmentStart !== null) { accumulatedMs += performance.now() - segmentStart; segmentStart = null; }
    try {
        const blob = new Blob(chunks, { type: mimeType });
        if (blob.size > 0) await onCaptured(blob, mimeType, Math.round(accumulatedMs));
    } catch {
        error = "撮影データの処理に失敗しました。もう一度お試しください。";
    } finally {
        setPhase("idle"); // 全 phase から idle へ収束
    }
};

// 一時停止 (recording→pausing→paused)。過渡中は再操作を拒否。
function pauseRecording(): void {
    if (!canPause(phase) || recorder === null) return;
    setPhase("pausing");
    try { recorder.pause(); } // 成功時 onpause で paused 確定
    catch { setPhase("recording"); error = "一時停止できませんでした。もう一度お試しください。"; } // 同期例外は前 phase へ
}
// 再開 (paused→resuming→recording)。
function resumeRecording(): void {
    if (!canResume(phase) || recorder === null) return;
    setPhase("resuming");
    try { recorder.resume(); } // 成功時 onresume で recording 確定
    catch { setPhase("paused"); error = "録画を再開できませんでした。もう一度お試しください。"; }
}

// 停止: recording/paused から可 (過渡状態からは受けない)
function safeStop(): void {
    if (!canStop(phase)) return; // pausing/resuming/stopping/idle は no-op
    setPhase("stopping");
    if (recorder === null) { fatalStopCleanup(); return; }
    try { recorder.stop(); } catch { fatalStopCleanup(); }
}
```
> `startRecording` の start 成功時に `accumulatedMs = 0; segmentStart = performance.now();` を初期化する。`recorder.start()` の直後は onpause/onresume 未発火なので recording として segmentStart を張る。

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

### テスト計画（S6）
- `pause → 停止`（`onCaptured` は 1 回・単一 blob = 同一テイク、durationMs は pause 中を除外）。
- `pause → resume → 停止`（2 セグメント合算、二重加算しない）。
- `pausing`/`resuming` 過渡中の pause/resume/stop 連打が no-op（recorder 呼び出し重複なし）。
- 同期例外時に前 phase へ戻る。
- `supportsPauseResume()===false` で一時停止ボタンが出ない（録画→停止のみ）。
- 既存の成功/失敗/フォールバック/preview 排他ケースが無回帰（active 通知の一元性を維持）。

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

// カメラ反転 (段階リカバリ)。idle のみ。
async function switchCamera(): Promise<void> {
    if (!canSwitchCamera(phase) || switching) return;
    switching = true;
    error = null;
    const target = oppositeFacingMode(facingMode);
    const prevDeviceId = stream?.getVideoTracks()[0]?.getSettings().deviceId ?? null;
    try {
        // 1) acquire-then-swap: exact 指定で取得 → 成立検証 → 旧 stream stop
        try {
            const next = await navigator.mediaDevices.getUserMedia({
                video: { facingMode: { exact: target } }, audio: true,
            });
            if (switchSucceeded(next, target, prevDeviceId)) {
                swapStream(next, target); return;
            }
            next.getTracks().forEach((t) => t.stop()); // 不成立: 破棄してリカバリへ
            throw new DOMException("facing not switched", "OverconstrainedError");
        } catch (cause) {
            const cls = classifyGetUserMediaError(cause);
            // 2) 資源競合系 or 不成立: 旧 stream を止めてから target 取得
            releaseCamera();
            try {
                const next = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { exact: target } }, audio: true,
                });
                swapStream(next, target); return;
            } catch {
                // 3) 旧 facingMode を再取得 (現行カメラ復旧)
                try {
                    stream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode }, audio: true,
                    });
                    if (video) { video.srcObject = stream; await video.play().catch(() => undefined); }
                    error = "カメラを切り替えできませんでした。現在のカメラで続行します。"; // CameraSwitchError (recoverable)
                    return;
                } catch (recoverCause) {
                    // 4) 旧カメラも喪失 → 恒久フォールバック
                    releaseCamera();
                    onCameraUnavailable(classifyGetUserMediaError(recoverCause).kind === "unavailable"
                        ? classifyGetUserMediaError(recoverCause).reason : "unknown");
                }
            }
        }
    } finally { switching = false; syncActive(); }
}

function swapStream(next: MediaStream, mode: FacingMode): void {
    releaseCamera();          // 旧 stream stop
    stream = next; facingMode = mode;
    if (video) { video.srcObject = stream; void video.play().catch(() => undefined); }
    void refreshFlipHint();
}
```
> `devicechange` リスナ（onMount で addEventListener → onDestroy で removeEventListener）で `refreshFlipHint()`。

### PHPStan 適合チェック
- TS: `getVideoTracks()[0]?` null 安全、`getSettings()` optional、`FacingMode` exact 制約は型 `ConstrainDOMString`。

### テスト計画（S6）
- 段階 1 成功（exact 取得 + 成立検証で swap、旧 stream stop）。
- 段階 2（1 が NotReadableError → 旧 stop 後 target 取得成功）。
- 段階 3（1,2 失敗 → 旧 facingMode 復旧 + `role="alert"` inline エラー、`onCameraUnavailable` を呼ばない）。
- 段階 4（旧カメラ復旧も失敗 → `onCameraUnavailable` を呼ぶ）。
- `getSettings().facingMode` が target と不一致（切替不成立）でリカバリへ流れる。
- 反転ボタンが idle かつ `canFlipHint===true` のときのみ描画（録画中は非表示、単一カメラで非表示）。
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
function startTimer(): void { stopTimer(); timerId = setInterval(() => { displayMs = elapsedMs(); }, 250); }
function stopTimer(): void { if (timerId !== null) { clearInterval(timerId); timerId = null; } }
// start/onresume で startTimer、onpause/onstop/onDestroy で stopTimer + displayMs=elapsedMs() で凍結値更新
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
        <!-- 三分割線は DS token (bg-surface + 透過) の薄線。hex/raw palette 不使用 -->
        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/60"></div>
        <div class="absolute inset-y-0 left-2/3 w-px bg-surface/60"></div>
        <div class="absolute inset-x-0 top-1/3 h-px bg-surface/60"></div>
        <div class="absolute inset-x-0 top-2/3 h-px bg-surface/60"></div>
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
- `bg-surface/60` の視認性は明暗テーマ差あり。DESIGN.md の Elevation（影なし・明度差）に沿うため hairline で最小限。低リスク。

---

## S6. テスト（Vitest）

### 変更箇所
- `tests/js/lib/capture/camera.test.ts`（純関数・capability・formatElapsed）。
- `tests/js/components/features/capture/CameraRecorder.test.ts`（pause/resume・duration・切替リカバリ・timer・grid）。既存ケースは無改変（後方互換確認ケースを維持）。
- 新規 `tests/js/components/features/capture/GridOverlay.test.ts`。

### テスト方針
- 既存の `FakeMediaRecorder` stub を拡張（`pause()/resume()` と `onpause/onresume`、`state` を追加。`autoStop` 同様に手動駆動フラグ）。
- `getUserMedia` mock を facingMode 別に返し分け（`getVideoTracks()[0].getSettings()` を持つ fake track）。
- `performance.now` を vi でスタブし duration を決定的に検証。
- fake timers（`vi.useFakeTimers()`）で timer 表示・interval クリークを検証。
- **個別の DatabaseTransactions 不使用**（フロントテストのため DB 非関与。該当なし）。

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

## 現行 CameraRecorder.svelte 全文
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

## 現行 camera.ts 全文
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
