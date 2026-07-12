# アプリの使命 (North Star)

**AI-CUE** は、現場に既にある作業手順書(SOP)を起点に、AI が撮るべきカットを設計した動画シナリオを生成し、スマホ(PWA)でナビゲーション撮影して標準化マニュアル動画を作れるようにする。撮影 PWA は制作フローの継続に必須で、カメラが使えない端末でも詰ませない (doc/10 §10.8-3 フォールバック必須)。

# 禁止事項

1. テストなしの実装完了報告
2. PHPStan/TS 型の widen
3. dev DB への破壊操作
4. response()->json() の直書き
5. LLM の Prism 直呼び / 6. prompt 直書き / 7. 操作系 POST の redirect()->intended()
8. 必須条件未充足を理由にボタンを disabled にする UI (押下時にエラー表示する。DESIGN.md)

【思考原則】まず仮説。データに真摯。先人の知恵。機能の名前に立ち返れ。
【ツール使用制限】コマンド実行・書き込み禁止、テキスト分析に集中。ファイル読み込みは許可。

---

# system: 実装レビュアー

あなたは経験豊富な Web アプリのコードレビュアーです。Svelte 5 (runes) + TypeScript strict の実装をレビューしてください。

【前提環境】Svelte 5 runes / TypeScript strict / Vitest (@testing-library/svelte) / DESIGN.md が design token の canonical source / Atomic Design (atoms→molecules→organisms→features→templates→pages 単方向) / アイコンは @lucide/svelte。バックエンド変更なし (PHP 差分なし)。

【レビュー観点】
1. 設計との一致性 (下記 detailed-design.md、施策1-4)
2. コードの正確性 (getUserMedia 失敗分類・再入ガード・stream 解放・エッジケース)
3. TS strict 型安全性 (判別可能 union / unknown からの絞り込み / unsafe cast なし)
4. テスト網羅性 (分類・親通知・分岐表示・enqueue 引き渡し・成功契約・再入)
5. セキュリティ/情報漏洩 (該当薄いが確認)
6. DESIGN.md 準拠 (token のみ・hex 直書きを増やさない)
7. Atomic Design 準拠 (階層逆流なし・SVG 直書きを増やさない)
8. 「詰みを作らない」要件 (分類不能は fallback 側へ倒す) の充足

【出力形式】
- ファイルごとに判定 (APPROVE / REQUEST_CHANGES)
- 指摘は [Critical] [Warning] [Suggestion]、Critical/Warning には修正案を必ず添える
- 全体判定: APPROVED / CHANGES_REQUESTED
- 日本語

---

# user

## 詳細設計書
リポジトリ内の `devnotes/20260712-1952-bugfix-capture-camera-fallback/detailed-design.md` を読んでください (APPROVED 済み)。4 施策:
- 施策1: camera.ts に CameraUnavailableReason union と classifyGetUserMediaError を追加 (既存 export は不変)
- 施策2: CameraRecorder に onCameraUnavailable 必須 prop を追加、恒久失敗は親へ委譲・一時失敗のみローカル表示、開始処理の再入ガード、MediaRecorder 構築失敗時の stream 解放
- 施策3: Show.svelte で showRecorder = canRecord && reason===null、fallbackNotice を reason で出し分け、実行時に file fallback へ切替
- 施策4: Vitest 3 ファイル (camera 分類 / CameraRecorder / CaptureShow)

## design system 参照
- 使用 token: 既存 `text-caption` / `text-text-secondary` / `text-danger` のみ。hex 直書き追加なし。
- 新規 atomic コンポーネントなし。既存 features/atoms を組み合わせるのみ。アイコン追加なし。notice は role="status" の p 要素。

## 実装差分 (git diff)
```diff
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 7b6b65a..f65b3e2 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -2,17 +2,22 @@
     import { onDestroy } from "svelte";
     import { Circle, Square } from "@lucide/svelte";
     import Button from "@/components/atoms/Button.svelte";
-    import { preferredRecordingMimeType } from "@/lib/capture/camera";
+    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
+    import type { CameraUnavailableReason } from "@/lib/capture/camera";
 
     /**
      * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
-     * カメラ不許可・録画失敗は押下時にエラー表示する (disabled 禁止)。
+     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
+     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
+     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
      */
     interface Props {
         onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
+        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
+        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
     }
 
-    let { onCaptured }: Props = $props();
+    let { onCaptured, onCameraUnavailable }: Props = $props();
 
     let video: HTMLVideoElement | null = $state(null);
     let stream: MediaStream | null = null;
@@ -21,43 +26,66 @@
     let startedAt = 0;
     let recording = $state(false);
     let error = $state<string | null>(null);
+    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
+    let starting = false;
 
     async function startRecording(): Promise<void> {
-        error = null;
-        const mimeType = preferredRecordingMimeType();
-        if (mimeType === null) {
-            error = "この端末では録画できません。ファイル選択をご利用ください。";
-            return;
-        }
+        if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
+        starting = true;
         try {
-            stream ??= await navigator.mediaDevices.getUserMedia({
-                video: { facingMode: "environment" },
-                audio: true,
-            });
-        } catch {
-            error = "カメラを利用できません。ブラウザのカメラ許可を確認してください。";
-            return;
-        }
-        if (video) {
-            video.srcObject = stream;
-            await video.play().catch(() => undefined);
-        }
-        chunks = [];
-        recorder = new MediaRecorder(stream, { mimeType });
-        recorder.ondataavailable = (event) => {
-            if (event.data.size > 0) chunks.push(event.data);
-        };
-        recorder.onstop = () => {
-            const blob = new Blob(chunks, { type: mimeType });
-            const durationMs = Date.now() - startedAt;
-            recording = false;
-            if (blob.size > 0) {
-                onCaptured(blob, mimeType, durationMs);
+            error = null;
+            const mimeType = preferredRecordingMimeType();
+            if (mimeType === null) {
+                // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
+                onCameraUnavailable("mime_unsupported");
+                return;
+            }
+            try {
+                stream ??= await navigator.mediaDevices.getUserMedia({
+                    video: { facingMode: "environment" },
+                    audio: true,
+                });
+            } catch (cause) {
+                const classified = classifyGetUserMediaError(cause);
+                if (classified.kind === "transient") {
+                    // 一時系 (NotReadableError/AbortError): 再試行可能のままエラー表示
+                    error =
+                        "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
+                    return;
+                }
+                onCameraUnavailable(classified.reason);
+                return;
             }
-        };
-        startedAt = Date.now();
-        recorder.start();
-        recording = true;
+            if (video) {
+                video.srcObject = stream;
+                await video.play().catch(() => undefined);
+            }
+            chunks = [];
+            try {
+                recorder = new MediaRecorder(stream, { mimeType });
+            } catch {
+                // NotSupportedError 等: 取得済み stream を解放してからフォールバックへ
+                releaseCamera();
+                onCameraUnavailable("recorder_unsupported");
+                return;
+            }
+            recorder.ondataavailable = (event) => {
+                if (event.data.size > 0) chunks.push(event.data);
+            };
+            recorder.onstop = () => {
+                const blob = new Blob(chunks, { type: mimeType });
+                const durationMs = Date.now() - startedAt;
+                recording = false;
+                if (blob.size > 0) {
+                    onCaptured(blob, mimeType, durationMs);
+                }
+            };
+            startedAt = Date.now();
+            recorder.start();
+            recording = true;
+        } finally {
+            starting = false;
+        }
     }
 
     function stopRecording(): void {
diff --git a/resources/js/lib/capture/camera.ts b/resources/js/lib/capture/camera.ts
index 5ea7734..b857ee2 100644
--- a/resources/js/lib/capture/camera.ts
+++ b/resources/js/lib/capture/camera.ts
@@ -20,3 +20,55 @@ export function preferredRecordingMimeType(): string | null {
     }
     return null;
 }
+
+/**
+ * カメラが実行時に使えない理由 (F-03 対応。判別可能 union で保持し、
+ * UI 文言の出し分け・将来の計測に使う)。
+ * Permissions-Policy 拒否は NotAllowedError として観測されユーザー拒否と
+ * 機械的に区別できないため permission_denied に含める。
+ */
+export type CameraUnavailableReason =
+    | "permission_denied" // NotAllowedError / SecurityError (ユーザー拒否・Permissions-Policy 拒否)
+    | "device_missing" // NotFoundError / OverconstrainedError (カメラ無し・制約不一致)
+    | "mime_unsupported" // preferredRecordingMimeType() === null
+    | "recorder_unsupported" // new MediaRecorder() の失敗 (NotSupportedError 等)
+    | "unknown"; // 分類不能 (詰み回避のためフォールバック側に倒す)
+
+/** getUserMedia() 失敗の分類結果。transient は再試行で回復し得る失敗 */
+export type CameraErrorClassification =
+    | { kind: "unavailable"; reason: CameraUnavailableReason }
+    | { kind: "transient" };
+
+/** reject 値から DOMException 名を安全に取り出す (ブラウザは任意値を reject し得る) */
+function errorName(error: unknown): string | null {
+    if (error instanceof DOMException) return error.name;
+    // OverconstrainedError 等、実装により DOMException を継承しないオブジェクトに備える
+    if (typeof error === "object" && error !== null && "name" in error) {
+        const name = (error as { name: unknown }).name;
+        return typeof name === "string" ? name : null;
+    }
+    return null;
+}
+
+/**
+ * getUserMedia() の reject 値を分類する (W3C Media Capture の DOMException name ベース)。
+ * - 恒久系 (権限拒否・デバイス無し) → unavailable: フォールバックへ切替
+ * - 一時系 (デバイス使用中・中断) → transient: エラー表示 + 再試行可能のまま
+ * - 分類不能 → unavailable/unknown: §10.8-3 の「詰みを作らない」要件に従い
+ *   フォールバック側に倒す (誤フォールバックでもテイク投入は継続できる)
+ */
+export function classifyGetUserMediaError(error: unknown): CameraErrorClassification {
+    switch (errorName(error)) {
+        case "NotAllowedError":
+        case "SecurityError":
+            return { kind: "unavailable", reason: "permission_denied" };
+        case "NotFoundError":
+        case "OverconstrainedError":
+            return { kind: "unavailable", reason: "device_missing" };
+        case "NotReadableError":
+        case "AbortError":
+            return { kind: "transient" };
+        default:
+            return { kind: "unavailable", reason: "unknown" };
+    }
+}
diff --git a/resources/js/pages/Capture/Show.svelte b/resources/js/pages/Capture/Show.svelte
index 5d66e20..81ee4f6 100644
--- a/resources/js/pages/Capture/Show.svelte
+++ b/resources/js/pages/Capture/Show.svelte
@@ -10,6 +10,7 @@
     import UploadQueueBar from "@/components/features/capture/UploadQueueBar.svelte";
     import AppLayout from "@/components/templates/AppLayout.svelte";
     import { supportsMediaRecorder } from "@/lib/capture/camera";
+    import type { CameraUnavailableReason } from "@/lib/capture/camera";
     import { createIdbPendingStore } from "@/lib/capture/idb";
     import { generateClientTakeId, UploadQueue } from "@/lib/capture/upload-queue";
     import type { PendingStore } from "@/lib/capture/upload-queue";
@@ -33,7 +34,19 @@
 
     let selectedCutId = $state<number | null>(null);
     const selectedCut = $derived(manual.cuts.find((cut) => cut.id === selectedCutId) ?? null);
+    // 静的 feature-detect (従来) + 実行時失敗による上書き (F-03: doc/10 §10.8-3)
     const canRecord = typeof window !== "undefined" && supportsMediaRecorder();
+    let cameraUnavailableReason = $state<CameraUnavailableReason | null>(null);
+    const showRecorder = $derived(canRecord && cameraUnavailableReason === null);
+    // 実行時フォールバックの説明文 (reason で出し分け。静的 feature-detect 由来は
+    // CaptureFileFallback 既存の説明文だけで足りるため notice なし)
+    const fallbackNotice = $derived.by(() => {
+        if (cameraUnavailableReason === null) return null;
+        if (cameraUnavailableReason === "permission_denied") {
+            return "カメラを利用できないため、ファイル選択でのアップロードに切り替えました。カメラで撮影する場合はブラウザまたは端末・組織のカメラ設定を確認して再読み込みしてください。";
+        }
+        return "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。";
+    });
 
     /* ---- アップロードキュー ---- */
     const store: PendingStore = createIdbPendingStore();
@@ -165,12 +178,22 @@
                     {/if}
                 </div>
 
-                {#if canRecord}
+                {#if showRecorder}
                     <CameraRecorder
                         onCaptured={(blob, mimeType, durationMs) =>
                             handleCaptured(blob, mimeType, durationMs)}
+                        onCameraUnavailable={(reason) => (cameraUnavailableReason = reason)}
                     />
                 {:else}
+                    {#if fallbackNotice !== null}
+                        <p
+                            class="text-caption text-text-secondary"
+                            role="status"
+                            data-testid="camera-fallback-notice"
+                        >
+                            {fallbackNotice}
+                        </p>
+                    {/if}
                     <CaptureFileFallback
                         onCaptured={(file) => handleCaptured(file, file.type, null)}
                     />
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
new file mode 100644
index 0000000..3074637
--- /dev/null
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -0,0 +1,185 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import CameraRecorder from "@/components/features/capture/CameraRecorder.svelte";
+
+/*
+ * CameraRecorder: 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は
+ * onCameraUnavailable(reason) で親へ委譲し、一時失敗のみローカルにエラー表示する (F-03)。
+ * 成功パスは onCaptured(blob, mimeType, durationMs) の契約を保つ。
+ */
+
+/** 手動発火できる最小 MediaRecorder stub (start/stop → ondataavailable/onstop) */
+class FakeMediaRecorder {
+    static supportedTypes: string[] = ["video/webm"];
+    static isTypeSupported(type: string): boolean {
+        return FakeMediaRecorder.supportedTypes.includes(type);
+    }
+    static shouldThrowOnConstruct = false;
+
+    ondataavailable: ((event: { data: Blob }) => void) | null = null;
+    onstop: (() => void) | null = null;
+
+    constructor(
+        public stream: unknown,
+        public options: { mimeType: string },
+    ) {
+        if (FakeMediaRecorder.shouldThrowOnConstruct) {
+            throw new DOMException("unsupported", "NotSupportedError");
+        }
+    }
+
+    start(): void {
+        // no-op (テストは stop() で明示的に onstop を駆動する)
+    }
+
+    stop(): void {
+        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
+        this.onstop?.();
+    }
+}
+
+const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
+
+function fakeStream(): MediaStream {
+    return { getTracks: () => [] } as unknown as MediaStream;
+}
+
+beforeEach(() => {
+    FakeMediaRecorder.supportedTypes = ["video/webm"];
+    FakeMediaRecorder.shouldThrowOnConstruct = false;
+    getUserMediaMock.mockReset();
+    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
+    vi.stubGlobal("navigator", {
+        ...navigator,
+        mediaDevices: { getUserMedia: getUserMediaMock },
+    });
+    // jsdom は HTMLMediaElement.play 未実装
+    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+    vi.restoreAllMocks();
+});
+
+describe("CameraRecorder", () => {
+    it("権限拒否 (NotAllowedError) は onCameraUnavailable('permission_denied') を呼びローカルエラーを出さない", async () => {
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+        const onCaptured = vi.fn();
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured, onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
+        });
+        expect(screen.queryByRole("alert")).not.toBeInTheDocument();
+        expect(onCaptured).not.toHaveBeenCalled();
+    });
+
+    it("デバイス無し (NotFoundError) は onCameraUnavailable('device_missing')", async () => {
+        getUserMediaMock.mockRejectedValue(new DOMException("no cam", "NotFoundError"));
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("device_missing");
+        });
+    });
+
+    it("一時失敗 (NotReadableError) は親へ委譲せず、再試行可能なエラー表示を残す", async () => {
+        getUserMediaMock.mockRejectedValue(new DOMException("busy", "NotReadableError"));
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(screen.getByRole("alert")).toHaveTextContent(
+                "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。",
+            );
+        });
+        expect(onCameraUnavailable).not.toHaveBeenCalled();
+        // 再試行可能: 録画開始ボタンが残る
+        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+    });
+
+    it("録画 MIME 非対応は onCameraUnavailable('mime_unsupported')", async () => {
+        FakeMediaRecorder.supportedTypes = [];
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("mime_unsupported");
+        });
+        expect(getUserMediaMock).not.toHaveBeenCalled();
+    });
+
+    it("MediaRecorder 構築失敗は stream を解放し onCameraUnavailable('recorder_unsupported')", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream());
+        FakeMediaRecorder.shouldThrowOnConstruct = true;
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("recorder_unsupported");
+        });
+    });
+
+    it("成功パス: 録画開始→停止で onCaptured(blob, 'video/webm', durationMs) を呼ぶ", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream());
+        const onCaptured = vi.fn();
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured, onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        // 録画中に切り替わり、停止ボタンが出る
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
+        });
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCaptured).toHaveBeenCalledTimes(1);
+        });
+        const [blob, mimeType, durationMs] = onCaptured.mock.calls[0];
+        expect(blob).toBeInstanceOf(Blob);
+        expect((blob as Blob).size).toBeGreaterThan(0);
+        expect(mimeType).toBe("video/webm");
+        expect(typeof durationMs).toBe("number");
+        expect(onCameraUnavailable).not.toHaveBeenCalled();
+    });
+
+    it("開始処理中の 2 連打は再入せず getUserMedia を 1 回だけ呼ぶ", async () => {
+        let rejectStart: ((reason: unknown) => void) | undefined;
+        getUserMediaMock.mockImplementation(
+            () =>
+                new Promise<MediaStream>((_resolve, reject) => {
+                    rejectStart = reject;
+                }),
+        );
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        // getUserMedia が pending の間に 2 連打
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1);
+
+        // 未解決 Promise をテスト間に残さないよう reject して処理を完了させる
+        rejectStart?.(new DOMException("denied", "NotAllowedError"));
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
+        });
+    });
+});
diff --git a/tests/js/lib/capture/camera.test.ts b/tests/js/lib/capture/camera.test.ts
index 1e2c677..3ae7525 100644
--- a/tests/js/lib/capture/camera.test.ts
+++ b/tests/js/lib/capture/camera.test.ts
@@ -1,5 +1,9 @@
 import { afterEach, describe, expect, it, vi } from "vitest";
-import { preferredRecordingMimeType, supportsMediaRecorder } from "@/lib/capture/camera";
+import {
+    classifyGetUserMediaError,
+    preferredRecordingMimeType,
+    supportsMediaRecorder,
+} from "@/lib/capture/camera";
 
 /*
  * カメラ対応判定: MediaRecorder + getUserMedia + isTypeSupported の 3 条件が
@@ -72,3 +76,52 @@ describe("preferredRecordingMimeType", () => {
         expect(preferredRecordingMimeType()).toBeNull();
     });
 });
+
+describe("classifyGetUserMediaError", () => {
+    it("NotAllowedError / SecurityError は permission_denied (unavailable)", () => {
+        expect(classifyGetUserMediaError(new DOMException("denied", "NotAllowedError"))).toEqual({
+            kind: "unavailable",
+            reason: "permission_denied",
+        });
+        expect(classifyGetUserMediaError(new DOMException("", "SecurityError"))).toEqual({
+            kind: "unavailable",
+            reason: "permission_denied",
+        });
+    });
+
+    it("NotFoundError / OverconstrainedError は device_missing (DOMException 非継承オブジェクトも)", () => {
+        expect(classifyGetUserMediaError(new DOMException("", "NotFoundError"))).toEqual({
+            kind: "unavailable",
+            reason: "device_missing",
+        });
+        // OverconstrainedError は実装により DOMException を継承しないため name プロパティのみで判定
+        expect(classifyGetUserMediaError({ name: "OverconstrainedError" })).toEqual({
+            kind: "unavailable",
+            reason: "device_missing",
+        });
+    });
+
+    it("NotReadableError / AbortError は transient (再試行可能)", () => {
+        expect(classifyGetUserMediaError(new DOMException("", "NotReadableError"))).toEqual({
+            kind: "transient",
+        });
+        expect(classifyGetUserMediaError(new DOMException("", "AbortError"))).toEqual({
+            kind: "transient",
+        });
+    });
+
+    it("分類不能 (通常 Error / 文字列 / null) は unknown (フォールバック側へ倒す)", () => {
+        expect(classifyGetUserMediaError(new Error("boom"))).toEqual({
+            kind: "unavailable",
+            reason: "unknown",
+        });
+        expect(classifyGetUserMediaError("boom")).toEqual({
+            kind: "unavailable",
+            reason: "unknown",
+        });
+        expect(classifyGetUserMediaError(null)).toEqual({
+            kind: "unavailable",
+            reason: "unknown",
+        });
+    });
+});
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
new file mode 100644
index 0000000..2dc2c00
--- /dev/null
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -0,0 +1,197 @@
+import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
+import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
+import CaptureShow from "@/pages/Capture/Show.svelte";
+import type { CaptureCut, CaptureManualDetail } from "@/types/capture";
+
+/*
+ * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
+ * - 静的 canRecord=false は従来どおり file input のみ (notice なし) を保つ
+ * - 録画実行時失敗 (getUserMedia reject) で recorder → file fallback + notice へ切替
+ * - フォールバック経由のファイル選択が enqueue へ正しく引き渡される (contentType 正規化含む)
+ * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
+ */
+
+const { routerReloadMock, enqueueMock } = vi.hoisted(() => ({
+    routerReloadMock: vi.fn(),
+    enqueueMock: vi.fn(),
+}));
+
+vi.mock("@inertiajs/svelte", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
+    router: { reload: routerReloadMock },
+}));
+
+// jsdom に indexedDB が無いため in-memory PendingStore に差し替える
+vi.mock("@/lib/capture/idb", () => ({
+    createIdbPendingStore: () => {
+        const items = new Map<string, unknown>();
+        return {
+            put: async (item: { clientTakeId: string }) => {
+                items.set(item.clientTakeId, item);
+            },
+            delete: async (id: string) => {
+                items.delete(id);
+            },
+            list: async () => [...items.values()],
+        };
+    },
+}));
+
+// UploadQueue は enqueue spy 付き stub に差し替え (generateClientTakeId 等は本物を残す)
+vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
+    ...(await importOriginal<typeof import("@/lib/capture/upload-queue")>()),
+    UploadQueue: class {
+        quotaMessage: string | null = null;
+        enqueue = enqueueMock;
+        async resume(): Promise<unknown[]> {
+            return [];
+        }
+    },
+}));
+
+function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
+    return {
+        id: 101,
+        type: "step",
+        parent_cut_id: null,
+        scene: "ネジを締める",
+        shot_type: "hiki",
+        shooting_point: "手元",
+        narration: "ドライバーでネジを締めます",
+        subtitle_primary: null,
+        subtitle_secondary: "",
+        adopted_take_id: null,
+        takes: [],
+        ...overrides,
+    };
+}
+
+function makeManual(): CaptureManualDetail {
+    return {
+        id: 5,
+        title: "ネジ締め作業",
+        status: "ready",
+        cuts: [makeCut()],
+    };
+}
+
+const baseProps = {
+    project: { id: 1, name: "現場A" },
+    manual: makeManual(),
+};
+
+function stubCameraSupported(supported: boolean): void {
+    if (supported) {
+        vi.stubGlobal("MediaRecorder", {
+            isTypeSupported: (type: string) => type === "video/webm",
+        });
+        vi.stubGlobal("navigator", {
+            ...navigator,
+            mediaDevices: { getUserMedia: getUserMediaMock },
+        });
+    } else {
+        vi.stubGlobal("MediaRecorder", undefined);
+    }
+}
+
+const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
+
+beforeEach(() => {
+    routerReloadMock.mockReset();
+    enqueueMock.mockReset();
+    enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
+        Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
+    );
+    getUserMediaMock.mockReset();
+});
+
+afterEach(() => {
+    cleanup();
+    vi.unstubAllGlobals();
+});
+
+async function selectCut(): Promise<void> {
+    await fireEvent.click(screen.getByTestId("cut-row-101"));
+}
+
+describe("Capture/Show カメラフォールバック", () => {
+    it("(a) 静的 canRecord=false は file input のみ (notice を出さない)", async () => {
+        stubCameraSupported(false);
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+
+        expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
+        expect(screen.queryByTestId("camera-fallback-notice")).not.toBeInTheDocument();
+        expect(screen.queryByTestId("camera-preview")).not.toBeInTheDocument();
+    });
+
+    it("(b) 録画実行時失敗 (NotAllowedError) で file fallback + notice へ切替", async () => {
+        stubCameraSupported(true);
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        // 最初は録画プレビューが出ている
+        expect(screen.getByTestId("camera-preview")).toBeInTheDocument();
+
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("camera-fallback-notice")).toHaveTextContent(
+                "カメラ設定を確認して再読み込み",
+            );
+        });
+        expect(screen.queryByTestId("camera-preview")).not.toBeInTheDocument();
+        expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
+    });
+
+    it("(c) フォールバックからのファイル選択が enqueue へ引き渡され reload される", async () => {
+        stubCameraSupported(true);
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
+        });
+
+        const file = new File(["data"], "take.mp4", { type: "video/mp4" });
+        await fireEvent.change(screen.getByTestId("capture-file-input"), {
+            target: { files: [file] },
+        });
+
+        await vi.waitFor(() => {
+            expect(enqueueMock).toHaveBeenCalledTimes(1);
+        });
+        const arg = enqueueMock.mock.calls[0][0];
+        expect(arg.cutId).toBe(101);
+        expect(arg.blob).toBe(file);
+        expect(arg.contentType).toBe("video/mp4");
+        expect(arg.durationMs).toBeNull();
+        expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
+    });
+
+    it("(d) codecs 付き MIME は contentType が正規化される (video/webm;codecs=vp9 → video/webm)", async () => {
+        stubCameraSupported(true);
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
+        });
+
+        const file = new File(["data"], "take.webm", { type: "video/webm;codecs=vp9" });
+        await fireEvent.change(screen.getByTestId("capture-file-input"), {
+            target: { files: [file] },
+        });
+
+        await vi.waitFor(() => {
+            expect(enqueueMock).toHaveBeenCalledTimes(1);
+        });
+        expect(enqueueMock.mock.calls[0][0].contentType).toBe("video/webm");
+    });
+});
```

## テスト結果
- Vitest: 全 68 files / 474 tests passed。新規: camera.test.ts に classifyGetUserMediaError 4 ケース群、CameraRecorder.test.ts 7 ケース (permission_denied/device_missing/transient/mime_unsupported/recorder_unsupported/成功契約/再入ガード)、CaptureShow.test.ts 4 ケース (静的false回帰/実行時切替/enqueue引き渡し/contentType正規化)。
- typecheck (tsc --noEmit) / lint (eslint) / build: すべて green。バックエンド変更なしのため composer/phpstan は対象外。

全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
