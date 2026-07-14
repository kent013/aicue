## Round 4: Critical (resuming race) への対応確認

Round 3 の Critical を受け、公開 active を **starting || resuming || phase !== "idle"** に統一し、全ガード (startRecording / releaseForPreview) と通知に resuming を織り込みました。対応マトリクスは以下です。

# 対応マトリクス: impl-review Round 3

Codex 全体判定: **CHANGES_REQUESTED**（Critical 1 / Warning 1）。

## [Critical] resuming 中の同型 race (S4)
- 判断: 対応する（指摘は正当。starting と対称の getUserMedia 窓）
- 根拠: preview close → `resumeAfterPreview()` の getUserMedia 待ちの間、公開 active が false のままだと
  preview 再オープン・録画開始が通り、camera stream の二重取得/漏洩や preview 背後での復帰が起こりうる。
- 対応内容: 公開 active を **`starting || resuming || phase !== "idle"`** に統一。
  - `syncActive()` の判定に `resuming` を追加。
  - `resumeAfterPreview()`: `resuming=true` 直後に `syncActive()`（active=true）、`finally` で
    `resuming=false; syncActive()`（active=false へ）。ガードに `starting` も追加。
  - `startRecording()` のアーリーリターンに `resuming` を追加（取得中の二重 getUserMedia を防止）。
  - `releaseForPreview()` のガードに `resuming` を追加（取得中 stream の横取り解放を防止）。

## [Warning] resume 窓の回帰テスト不足 (S4)
- 判断: 対応する
- 根拠: resume の grant 窓を固定するテストが無かった。
- 対応内容: テスト追加：
  - 「resume 取得中は active=true で、preview 解放も録画開始も抑止する」= 取得中に
    `onCaptureActiveChange(true)`・`releaseForPreview` no-op・`startRecording` が getUserMedia を
    増やさない・完了で active=false を検証。
  - 「resume が失敗しても active=false へ戻る（再試行可能）」を追加。


### 追加差分（Round 1 → 現在、CameraRecorder.svelte + test）

```diff
diff --git a/resources/js/components/features/capture/CameraRecorder.svelte b/resources/js/components/features/capture/CameraRecorder.svelte
index f2cfdc4..d55d525 100644
--- a/resources/js/components/features/capture/CameraRecorder.svelte
+++ b/resources/js/components/features/capture/CameraRecorder.svelte
@@ -10,28 +10,90 @@
      * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
      * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
      * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
+     *
+     * 撮影 active の phase マシン (T050 / S4): idle / recording / stopping。
+     * 外部へ公開する排他状態 active は **starting || resuming || phase !== "idle"**。
+     * getUserMedia grant 待ちの 2 窓 (録画開始 = starting / preview 復帰 = resuming) も active に
+     * 含めることで、取得中でも親の captureActive が true になり preview が開けない
+     * (preview と MediaRecorder の同居・stream 二重取得を根本から防ぐ。Codex R2/R3-S4)。
+     * これにより preview 解禁条件 (親: !captureActive) と camera 解放拒否条件が一致する。
      */
     interface Props {
-        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
+        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void | Promise<void>;
         /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
         onCameraUnavailable: (reason: CameraUnavailableReason) => void;
+        /** 撮影 active (phase !== "idle") の変化通知。preview 排他制御に使う (T050) */
+        onCaptureActiveChange?: (active: boolean) => void;
     }
 
-    let { onCaptured, onCameraUnavailable }: Props = $props();
+    let { onCaptured, onCameraUnavailable, onCaptureActiveChange }: Props = $props();
+
+    type Phase = "idle" | "recording" | "stopping";
 
     let video: HTMLVideoElement | null = $state(null);
     let stream: MediaStream | null = null;
     let recorder: MediaRecorder | null = null;
     let chunks: Blob[] = [];
     let startedAt = 0;
-    let recording = $state(false);
+    let phase = $state<Phase>("idle");
     let error = $state<string | null>(null);
     /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
     let starting = false;
+    /** 直近に外部通知した active 値 (starting || resuming || phase !== "idle" の変化検出用) */
+    let lastActive = false;
+    /** preview 解放前に live だったか (復帰要否) */
+    let wasActiveBeforePreview = false;
+    /** resumeAfterPreview の再入ガード (多重 close/open で getUserMedia を二重発火させない) */
+    let resuming = false;
+    let resumePromise: Promise<void> | null = null;
+
+    // 公開 active (starting || resuming || phase !== "idle") の変化時のみ 1 回通知する。
+    // starting / resuming / phase を変えた箇所は必ず本関数を呼ぶ (通知の一元管理)。
+    function syncActive(): void {
+        const active = starting || resuming || phase !== "idle";
+        if (active !== lastActive) {
+            lastActive = active;
+            onCaptureActiveChange?.(active);
+        }
+    }
+
+    // phase 遷移は単一 setter を通す。active 通知は syncActive に一元化する。
+    function setPhase(next: Phase): void {
+        phase = next;
+        syncActive();
+    }
+
+    // getUserMedia + video.srcObject 設定 (録画開始と preview 復帰で共用)。
+    // 成功 = true。失敗時は既存の classify → onCameraUnavailable / transient error 表示を踏襲。
+    async function acquirePreviewStream(): Promise<boolean> {
+        try {
+            stream ??= await navigator.mediaDevices.getUserMedia({
+                video: { facingMode: "environment" },
+                audio: true,
+            });
+        } catch (cause) {
+            const classified = classifyGetUserMediaError(cause);
+            if (classified.kind === "transient") {
+                error =
+                    "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
+                return false;
+            }
+            onCameraUnavailable(classified.reason);
+            return false;
+        }
+        if (video) {
+            video.srcObject = stream;
+            await video.play().catch(() => undefined);
+        }
+        return true;
+    }
 
     async function startRecording(): Promise<void> {
-        if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
+        // 再入防止 (アーリーリターン。規約: disabled 禁止)。preview 復帰の取得中 (resuming) も拒否
+        // し getUserMedia 二重取得を防ぐ。
+        if (starting || resuming || phase !== "idle") return;
         starting = true;
+        syncActive(); // 開始押下時点で active=true (grant 窓でも preview を開けない)
         try {
             error = null;
             const mimeType = preferredRecordingMimeType();
@@ -40,26 +102,9 @@
                 onCameraUnavailable("mime_unsupported");
                 return;
             }
-            try {
-                stream ??= await navigator.mediaDevices.getUserMedia({
-                    video: { facingMode: "environment" },
-                    audio: true,
-                });
-            } catch (cause) {
-                const classified = classifyGetUserMediaError(cause);
-                if (classified.kind === "transient") {
-                    // 一時系 (NotReadableError/AbortError): 再試行可能のままエラー表示
-                    error =
-                        "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
-                    return;
-                }
-                onCameraUnavailable(classified.reason);
-                return;
-            }
-            if (video) {
-                video.srcObject = stream;
-                await video.play().catch(() => undefined);
-            }
+            const acquired = await acquirePreviewStream();
+            if (!acquired) return;
+            if (stream === null) return; // 型絞り込み (acquired=true なら実質非 null)
             chunks = [];
             try {
                 recorder = new MediaRecorder(stream, { mimeType });
@@ -72,14 +117,25 @@
             recorder.ondataavailable = (event) => {
                 if (event.data.size > 0) chunks.push(event.data);
             };
-            recorder.onstop = () => {
-                const blob = new Blob(chunks, { type: mimeType });
-                const durationMs = Date.now() - startedAt;
-                recording = false;
-                if (blob.size > 0) {
-                    onCaptured(blob, mimeType, durationMs);
+            // 唯一の正常終了点 (idle への遷移)。onCaptured の reject/throw でも終了通知を保証する。
+            recorder.onstop = async () => {
+                try {
+                    const blob = new Blob(chunks, { type: mimeType });
+                    const durationMs = Date.now() - startedAt;
+                    if (blob.size > 0) {
+                        await onCaptured(blob, mimeType, durationMs);
+                    }
+                } catch {
+                    // 既存のローカルエラー表示経路へ渡す (未処理 rejection にしない)
+                    error = "撮影データの処理に失敗しました。もう一度お試しください。";
+                } finally {
+                    setPhase("idle");
                 }
             };
+            recorder.onerror = () => safeStop();
+            stream.getTracks().forEach((track) => {
+                track.onended = () => safeStop();
+            });
             startedAt = Date.now();
             try {
                 recorder.start();
@@ -91,14 +147,35 @@
                 onCameraUnavailable("recorder_unsupported");
                 return;
             }
-            recording = true;
+            setPhase("recording");
         } finally {
             starting = false;
+            // 開始成功時: phase=recording のため active は true 維持 (重複通知しない)。
+            // 開始失敗/恒久失敗時: phase=idle のため active=false へ戻す。
+            syncActive();
         }
     }
 
-    function stopRecording(): void {
-        recorder?.stop();
+    // 安全停止 (多重呼び出しガード)。recording 以外では no-op (stopping/idle で重複 stop しない)。
+    function safeStop(): void {
+        if (phase !== "recording") return;
+        setPhase("stopping"); // active は true のまま維持 (idle 遷移で初めて false)
+        if (recorder === null) {
+            fatalStopCleanup(); // 不整合: stopping 固定を防ぐ
+            return;
+        }
+        try {
+            recorder.stop(); // → recorder.onstop へ
+        } catch {
+            fatalStopCleanup(); // 停止不能時: UI 復旧不能を防ぐ
+        }
+    }
+
+    // stop() が投げた等の致命時: 資源解放 + idle へ (active=true 残置による復旧不能を防ぐ)
+    function fatalStopCleanup(): void {
+        setPhase("idle");
+        releaseCamera();
+        onCameraUnavailable("recorder_unsupported");
     }
 
     function releaseCamera(): void {
@@ -106,6 +183,34 @@
         stream = null;
     }
 
+    // preview を開く間に呼ばれる。録画中/停止処理中は no-op (録画データを守る = 暗黙終了しない)。
+    // 取得中 (starting: 録画開始 / resuming: preview 復帰) も拒否し、取得中の stream を横から
+    // 解放しない (Codex R1/R3-S4)。
+    export function releaseForPreview(): void {
+        if (starting || resuming || phase !== "idle") return; // recording/stopping/取得中で解放拒否
+        wasActiveBeforePreview = stream !== null; // 復帰要否を記録
+        releaseCamera();
+    }
+
+    // preview close 後に呼ばれる。解放前に live だった時のみ再取得。多重 close/open を再入防止。
+    export function resumeAfterPreview(): Promise<void> {
+        if (resuming) return resumePromise ?? Promise.resolve(); // in-flight 共有
+        if (!wasActiveBeforePreview || starting || phase !== "idle") return Promise.resolve();
+        resuming = true;
+        syncActive(); // 復帰取得中も active=true (grant 窓で preview 再オープン・録画開始を抑止)
+        // 取得成功後にのみ wasActiveBeforePreview を false 化 (失敗時は true のまま=再試行可能)
+        resumePromise = acquirePreviewStream()
+            .then((ok) => {
+                if (ok) wasActiveBeforePreview = false;
+            })
+            .finally(() => {
+                resuming = false;
+                resumePromise = null;
+                syncActive(); // 取得完了で active=false へ戻す (phase は idle のまま)
+            });
+        return resumePromise;
+    }
+
     onDestroy(releaseCamera);
 </script>
 
@@ -120,16 +225,16 @@
         data-testid="camera-preview"
     ></video>
     <div class="flex items-center justify-center gap-3">
-        {#if recording}
-            <Button variant="danger" onclick={stopRecording} testId="stop-recording">
-                <Square class="size-4" aria-hidden="true" />
-                録画停止
-            </Button>
-        {:else}
+        {#if phase === "idle"}
             <Button variant="primary" onclick={startRecording} testId="start-recording">
                 <Circle class="size-4" aria-hidden="true" />
                 録画開始
             </Button>
+        {:else}
+            <Button variant="danger" onclick={safeStop} testId="stop-recording">
+                <Square class="size-4" aria-hidden="true" />
+                録画停止
+            </Button>
         {/if}
     </div>
     {#if error}
diff --git a/tests/js/components/features/capture/CameraRecorder.test.ts b/tests/js/components/features/capture/CameraRecorder.test.ts
index f9d1384..c61d7da 100644
--- a/tests/js/components/features/capture/CameraRecorder.test.ts
+++ b/tests/js/components/features/capture/CameraRecorder.test.ts
@@ -16,9 +16,13 @@ class FakeMediaRecorder {
     }
     static shouldThrowOnConstruct = false;
     static shouldThrowOnStart = false;
+    /** false のとき stop() は onstop を自動発火せず、テストが手動で駆動する (stopping 観測用) */
+    static autoStop = true;
 
     ondataavailable: ((event: { data: Blob }) => void) | null = null;
     onstop: (() => void) | null = null;
+    onerror: (() => void) | null = null;
+    stopCalls = 0;
 
     constructor(
         public stream: unknown,
@@ -37,26 +41,53 @@ class FakeMediaRecorder {
     }
 
     stop(): void {
+        this.stopCalls += 1;
+        if (!FakeMediaRecorder.autoStop) return; // 手動駆動モード
         this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
         this.onstop?.();
     }
+
+    /** 手動モードで onstop を駆動する (blob 生成 → onstop) */
+    fireStop(): void {
+        this.ondataavailable?.({ data: new Blob(["frame"], { type: this.options.mimeType }) });
+        this.onstop?.();
+    }
+}
+
+/** 直近に構築された FakeMediaRecorder を捕捉する (onerror/onstop 手動駆動用) */
+let lastRecorder: FakeMediaRecorder | null = null;
+class TrackingFakeMediaRecorder extends FakeMediaRecorder {
+    constructor(stream: unknown, options: { mimeType: string }) {
+        super(stream, options);
+        lastRecorder = this;
+    }
 }
 
 const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();
 
 /** getTracks() が stop spy 付き track を返す fake stream (解放検証用) */
-function fakeStream(): { stream: MediaStream; stop: ReturnType<typeof vi.fn> } {
+function fakeStream(): {
+    stream: MediaStream;
+    stop: ReturnType<typeof vi.fn>;
+    track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null };
+} {
     const stop = vi.fn();
-    const stream = { getTracks: () => [{ stop }] } as unknown as MediaStream;
-    return { stream, stop };
+    const track: { stop: ReturnType<typeof vi.fn>; onended: (() => void) | null } = {
+        stop,
+        onended: null,
+    };
+    const stream = { getTracks: () => [track] } as unknown as MediaStream;
+    return { stream, stop, track };
 }
 
 beforeEach(() => {
     FakeMediaRecorder.supportedTypes = ["video/webm"];
     FakeMediaRecorder.shouldThrowOnConstruct = false;
     FakeMediaRecorder.shouldThrowOnStart = false;
+    FakeMediaRecorder.autoStop = true;
+    lastRecorder = null;
     getUserMediaMock.mockReset();
-    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
+    vi.stubGlobal("MediaRecorder", TrackingFakeMediaRecorder);
     vi.stubGlobal("navigator", {
         ...navigator,
         mediaDevices: { getUserMedia: getUserMediaMock },
@@ -210,4 +241,277 @@ describe("CameraRecorder", () => {
             expect(onCameraUnavailable).toHaveBeenCalledWith("permission_denied");
         });
     });
+
+    // ---- T050 / S4: 撮影 active phase マシン + preview 解放/復帰 ----
+
+    it("onCaptureActiveChange は start で true / idle 到達で false を通知する", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(true));
+        // 録画確立 (stop ボタン出現) を待ってから停止する
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(false));
+    });
+
+    it("recording→stopping では false を発火せず、idle 到達で初めて false (stopping 中は active 維持)", async () => {
+        FakeMediaRecorder.autoStop = false; // stop() で onstop を自動発火させず stopping を観測する
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(true));
+        // 録画確立 (stop ボタン出現) を待つ
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        // 停止要求 → stopping (まだ idle でない = false を出さない)
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        expect(lastRecorder?.stopCalls).toBe(1);
+        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false);
+
+        // onstop 到達で初めて idle → false
+        lastRecorder?.fireStop();
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+    });
+
+    it("track.onended は safeStop→recorder.stop() を呼び、onstop(idle) でのみ false を出す", async () => {
+        FakeMediaRecorder.autoStop = false;
+        const { stream, track } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(track.onended).not.toBeNull());
+
+        track.onended?.(); // デバイス側で track 終了 → safeStop
+        expect(lastRecorder?.stopCalls).toBe(1);
+        expect(onCaptureActiveChange).not.toHaveBeenCalledWith(false); // stopping 中はまだ
+
+        lastRecorder?.fireStop();
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+    });
+
+    it("onstop の onCaptured が reject しても idle に戻り、未処理 rejection にせずローカルエラー表示する", async () => {
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+        const onCaptured = vi.fn().mockRejectedValue(new Error("upload failed"));
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured, onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+
+        // idle へ戻り録画開始ボタンが復帰 (撮影状態が解除される)
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+        expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false);
+        expect(screen.getByRole("alert")).toHaveTextContent("撮影データの処理に失敗しました");
+    });
+
+    it("safeStop 多重呼び出しで recorder.stop() が重複しない (phase ガード)", async () => {
+        FakeMediaRecorder.autoStop = false;
+        getUserMediaMock.mockResolvedValue(fakeStream().stream);
+
+        render(CameraRecorder, { props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() } });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        expect(lastRecorder?.stopCalls).toBe(1); // stopping 中の 2 度目は no-op
+    });
+
+    it("releaseForPreview: 待機中 (idle かつ stream あり) は stream を解放し、resumeAfterPreview で再取得", async () => {
+        const first = fakeStream();
+        const second = fakeStream();
+        getUserMediaMock.mockResolvedValueOnce(first.stream).mockResolvedValueOnce(second.stream);
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        // 待機 stream を得るため一度録画開始→停止で idle かつ stream 保持状態を作る
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        // preview を開く: idle かつ stream 保持 → 解放される
+        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
+        expect(first.stop).toHaveBeenCalled();
+
+        // preview close: 解放前 live だったので再取得する
+        await (component as unknown as { resumeAfterPreview: () => Promise<void> }).resumeAfterPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+    });
+
+    it("開始処理中 (getUserMedia grant 待ち) は active=true を通知し、preview 用解放を拒否する", async () => {
+        let resolveStart: ((stream: MediaStream) => void) | undefined;
+        const pending = fakeStream();
+        getUserMediaMock.mockImplementation(
+            () =>
+                new Promise<MediaStream>((resolve) => {
+                    resolveStart = resolve;
+                }),
+        );
+        const onCaptureActiveChange = vi.fn();
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        // getUserMedia が pending の間 (starting=true, phase=idle)
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        // active=true が即通知される → 親の captureActive=true → TakeStrip は preview を開かない
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenCalledWith(true));
+
+        // 万一 releaseForPreview が呼ばれても取得中 stream を横取り解放しない (二重防御)
+        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
+        resolveStart?.(pending.stream);
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        expect(pending.stop).not.toHaveBeenCalled();
+        // recording への遷移で true を重複通知しない (starting→recording は同一 active)
+        expect(onCaptureActiveChange.mock.calls.filter(([a]) => a === true)).toHaveLength(1);
+    });
+
+    it("開始が失敗 (getUserMedia reject) すると active が true→false に戻る", async () => {
+        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));
+        const onCaptureActiveChange = vi.fn();
+
+        render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+        expect(onCaptureActiveChange).toHaveBeenNthCalledWith(1, true); // 開始押下で一旦 active
+    });
+
+    it("releaseForPreview: 録画中は no-op (録画データを暗黙終了しない)", async () => {
+        FakeMediaRecorder.autoStop = false;
+        const { stream, stop } = fakeStream();
+        getUserMediaMock.mockResolvedValue(stream);
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+
+        (component as unknown as { releaseForPreview: () => void }).releaseForPreview();
+        expect(stop).not.toHaveBeenCalled(); // 解放しない
+        expect(lastRecorder?.stopCalls).toBe(0); // 録画終了処理も呼ばない
+    });
+
+    it("resume 取得中 (getUserMedia grant 待ち) は active=true で、preview 解放も録画開始も抑止する", async () => {
+        const recordStream = fakeStream();
+        let resolveResume: ((stream: MediaStream) => void) | undefined;
+        const resumeStream = fakeStream();
+        getUserMediaMock
+            .mockResolvedValueOnce(recordStream.stream) // 初回録画
+            .mockImplementationOnce(
+                () =>
+                    new Promise<MediaStream>((resolve) => {
+                        resolveResume = resolve;
+                    }),
+            );
+        const onCaptureActiveChange = vi.fn();
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        const ref = component as unknown as {
+            releaseForPreview: () => void;
+            resumeAfterPreview: () => Promise<void>;
+        };
+        // 録画→停止で idle かつ stream 保持状態を作る
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        // preview open → release (idle, stream 保持 → wasActiveBeforePreview=true)
+        ref.releaseForPreview();
+        // preview close → resume (getUserMedia は pending)
+        const resumePromise = ref.resumeAfterPreview();
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(true));
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2); // 録画 + resume の 2 回
+
+        // resume 取得中: 再度 releaseForPreview は no-op / 録画開始も getUserMedia を増やさない
+        ref.releaseForPreview();
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2); // 二重取得しない
+
+        // resume 完了で active=false へ戻る
+        resolveResume?.(resumeStream.stream);
+        await resumePromise;
+        await vi.waitFor(() => expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false));
+    });
+
+    it("resume が失敗しても active=false へ戻る (再試行可能)", async () => {
+        const recordStream = fakeStream();
+        getUserMediaMock
+            .mockResolvedValueOnce(recordStream.stream)
+            .mockRejectedValueOnce(new DOMException("busy", "NotReadableError"));
+        const onCaptureActiveChange = vi.fn();
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn(), onCaptureActiveChange },
+        });
+        const ref = component as unknown as {
+            releaseForPreview: () => void;
+            resumeAfterPreview: () => Promise<void>;
+        };
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        onCaptureActiveChange.mockClear();
+        ref.releaseForPreview();
+        await ref.resumeAfterPreview(); // 取得失敗 (transient)
+
+        // active は true→false を経て最終 false (取得失敗でも撮影状態を残さない)
+        expect(onCaptureActiveChange).toHaveBeenCalledWith(true);
+        expect(onCaptureActiveChange).toHaveBeenLastCalledWith(false);
+    });
+
+    it("resumeAfterPreview: 多重呼び出しで getUserMedia が二重発火しない / 失敗後は再試行できる", async () => {
+        const first = fakeStream();
+        getUserMediaMock.mockResolvedValueOnce(first.stream); // 初回録画で live に
+
+        const { component } = render(CameraRecorder, {
+            props: { onCaptured: vi.fn(), onCameraUnavailable: vi.fn() },
+        });
+        await fireEvent.click(screen.getByTestId("start-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("stop-recording")).toBeInTheDocument());
+        await fireEvent.click(screen.getByTestId("stop-recording"));
+        await vi.waitFor(() => expect(screen.getByTestId("start-recording")).toBeInTheDocument());
+
+        const ref = component as unknown as { releaseForPreview: () => void; resumeAfterPreview: () => Promise<void> };
+        ref.releaseForPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(1); // 録画開始の 1 回のみ
+
+        // 復帰取得が一時失敗 → wasActiveBeforePreview を保持 (再試行可能)
+        getUserMediaMock.mockRejectedValueOnce(new DOMException("busy", "NotReadableError"));
+        // 多重 close/open を模して 2 連続呼び出し (再入ガードで getUserMedia は 1 回)
+        await Promise.all([ref.resumeAfterPreview(), ref.resumeAfterPreview()]);
+        expect(getUserMediaMock).toHaveBeenCalledTimes(2);
+
+        // 再試行が成功する (wasActiveBeforePreview が false 化していない)
+        getUserMediaMock.mockResolvedValueOnce(fakeStream().stream);
+        await ref.resumeAfterPreview();
+        expect(getUserMediaMock).toHaveBeenCalledTimes(3);
+    });
 });

```

starting と resuming の 2 つの getUserMedia 窓が同一の排他境界 (公開 active) に含まれ、preview と MediaRecorder の同居・stream 二重取得が防がれているか確認し、最終判定 (APPROVED / CHANGES_REQUESTED) を明示してください。全 JS テスト 592 green, phpstan/pint/typecheck/lint/build も green です。
