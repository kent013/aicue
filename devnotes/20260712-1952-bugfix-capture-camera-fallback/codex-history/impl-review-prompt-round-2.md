# impl-review Round 2: Round 1 Warning への対応報告

Round 1 の Warning 2 件をすべて対応しました (Suggestion のうち device_missing notice テストも採用)。
対応マトリクスと改訂差分を提示します。再レビューし全体判定を出してください。

## 対応マトリクス
# 対応マトリクス: impl-review Round 1

全体判定: CHANGES_REQUESTED (Warning 2 / Suggestion 3)

## [Warning] CameraRecorder: recorder.start() 例外が未捕捉で「詰みを作らない」要件を満たせない余地
- 判断: 対応する
- 根拠: 指摘どおり。start() は InvalidStateError 等を投げ得る。構築成功後の失敗でも
  フォールバックへ倒さないと詰む (§10.8-3)。
- 対応内容: `recorder.start()` を try/catch で包み、失敗時は `recorder = null; releaseCamera();
  onCameraUnavailable("recorder_unsupported"); return`。recording へは遷移しない。

## [Warning] テスト: 構築失敗時の stream 解放 (track.stop) を検証していない
- 判断: 対応する
- 対応内容: `fakeStream()` を `{ stream, stop }` へ変更し getTracks() が stop spy 付き track を返す構成に。
  構築失敗テストで `stop` が 1 回呼ばれることを assert。加えて start() 例外ケースの新規テストでも
  stop 呼び出し + 非録画状態を検証。

## [Suggestion] errorName に type guard 名を付ける
- 判断: 見送る。現状 strict で問題なく、命名だけの変更は YAGNI。

## [Suggestion] starting を $state 化して将来の「開始中」表示に備える
- 判断: 見送る。現要件では UI 反映不要。$state 化は無駄な reactivity を増やす。

## [Suggestion] device_missing の notice 文言分岐テストを足す
- 判断: 対応する (安価かつ notice 出し分けの退行検知を強化)
- 対応内容: CaptureShow に (e) を追加。NotFoundError で汎用 notice 文言が出て「再読み込み」文言を
  含まないことを検証。

## 改訂差分 (Round 1 → Round 2、該当ファイルのみ)
```diff
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index 7b6b65a..f2cfdc4 100644
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
@@ -21,43 +26,75 @@
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
+            try {
+                recorder.start();
+            } catch {
+                // start() の InvalidStateError 等 (UA 差異・状態競合)。構築成功後でも
+                // 詰ませないため stream を解放してフォールバックへ倒す (§10.8-3)
+                recorder = null;
+                releaseCamera();
+                onCameraUnavailable("recorder_unsupported");
+                return;
+            }
+            recording = true;
+        } finally {
+            starting = false;
+        }
     }
 
     function stopRecording(): void {
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
new file mode 100644
index 0000000..f9d1384
--- /dev/null
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -0,0 +1,213 @@
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
+    static shouldThrowOnStart = false;
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
+        if (FakeMediaRecorder.shouldThrowOnStart) {
+            throw new DOMException("invalid state", "InvalidStateError");
+        }
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
+/** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
+function fakeStream(): { stream: MediaStream; stop: ReturnType<typeof vi.fn> } {
+    const stop = vi.fn();
+    const stream = { getTracks: () => [{ stop }] } as unknown as MediaStream;
+    return { stream, stop };
+}
+
+beforeEach(() => {
+    FakeMediaRecorder.supportedTypes = ["video/webm"];
+    FakeMediaRecorder.shouldThrowOnConstruct = false;
+    FakeMediaRecorder.shouldThrowOnStart = false;
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
+        const { stream, stop } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+        FakeMediaRecorder.shouldThrowOnConstruct = true;
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("recorder_unsupported");
+        });
+        // 取得済み stream の track が解放されている (他タブ等でカメラを掴んだままにしない)
+        expect(stop).toHaveBeenCalledTimes(1);
+    });
+
+    it("recorder.start() 例外も stream を解放しフォールバックへ倒す (詰みを作らない)", async () => {
+        const { stream, stop } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+        FakeMediaRecorder.shouldThrowOnStart = true;
+        const onCameraUnavailable = vi.fn();
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(onCameraUnavailable).toHaveBeenCalledWith("recorder_unsupported");
+        });
+        expect(stop).toHaveBeenCalledTimes(1);
+        // 録画状態には遷移しない (録画開始ボタンのまま)
+        expect(screen.getByTestId("start-recording")).toBeInTheDocument();
+    });
+
+    it("成功パス: 録画開始→停止で onCaptured(blob, 'video/webm', durationMs) を呼ぶ", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
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
diff --git a/tests/js/pages/CaptureShow.test.ts b/tests/js/pages/CaptureShow.test.ts
new file mode 100644
index 0000000..47f5e17
--- /dev/null
+++ b/tests/js/pages/CaptureShow.test.ts
@@ -0,0 +1,216 @@
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
+    it("(e) permission_denied 以外 (device_missing) は汎用の切替 notice を出す", async () => {
+        stubCameraSupported(true);
+        getUserMediaMock.mockRejectedValue(new DOMException("no cam", "NotFoundError"));
+
+        render(CaptureShow, { props: baseProps });
+        await selectCut();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => {
+            expect(screen.getByTestId("camera-fallback-notice")).toHaveTextContent(
+                "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。",
+            );
+        });
+        // permission_denied 用の「再読み込み」文言は出さない
+        expect(screen.getByTestId("camera-fallback-notice")).not.toHaveTextContent(
+            "再読み込み",
+        );
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
- Vitest 全 68 files / 476 tests passed (CameraRecorder に start() 例外ケース +1、構築失敗ケースに track.stop 検証追加、CaptureShow に device_missing notice ケース +1)。
- typecheck / lint / build すべて green。

全体判定 (APPROVED / CHANGES_REQUESTED) を出してください。
