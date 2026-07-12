<script lang="ts">
    import { onDestroy } from "svelte";
    import { Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { classifyGetUserMediaError, preferredRecordingMimeType } from "@/lib/capture/camera";
    import type { CameraUnavailableReason } from "@/lib/capture/camera";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * 録画不能な恒久失敗 (権限拒否・デバイス無し・API 不適合) は onCameraUnavailable で
     * 親に通知し、親がファイル選択フォールバックへ切り替える (doc/10 §10.8-3、F-03)。
     * 一時的失敗 (デバイス使用中等) のみローカルにエラー表示し再試行可能のまま残す。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
        /** カメラが恒久的に使えないと判明したときの通知 (親がフォールバックへ切替) */
        onCameraUnavailable: (reason: CameraUnavailableReason) => void;
    }

    let { onCaptured, onCameraUnavailable }: Props = $props();

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
    let recording = $state(false);
    let error = $state<string | null>(null);
    /** 開始処理中の再入ガード (getUserMedia 待ち中の多重クリック防止。UI disabled は使わない) */
    let starting = false;

    async function startRecording(): Promise<void> {
        if (starting || recording) return; // 再入防止 (アーリーリターン。規約: disabled 禁止)
        starting = true;
        try {
            error = null;
            const mimeType = preferredRecordingMimeType();
            if (mimeType === null) {
                // 恒久系: ローカル表示はせず親へ委譲 (責務の二重化回避)
                onCameraUnavailable("mime_unsupported");
                return;
            }
            try {
                stream ??= await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: "environment" },
                    audio: true,
                });
            } catch (cause) {
                const classified = classifyGetUserMediaError(cause);
                if (classified.kind === "transient") {
                    // 一時系 (NotReadableError/AbortError): 再試行可能のままエラー表示
                    error =
                        "カメラを起動できませんでした。他のアプリがカメラを使用していないか確認し、もう一度お試しください。";
                    return;
                }
                onCameraUnavailable(classified.reason);
                return;
            }
            if (video) {
                video.srcObject = stream;
                await video.play().catch(() => undefined);
            }
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
            recorder.onstop = () => {
                const blob = new Blob(chunks, { type: mimeType });
                const durationMs = Date.now() - startedAt;
                recording = false;
                if (blob.size > 0) {
                    onCaptured(blob, mimeType, durationMs);
                }
            };
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
            recording = true;
        } finally {
            starting = false;
        }
    }

    function stopRecording(): void {
        recorder?.stop();
    }

    function releaseCamera(): void {
        stream?.getTracks().forEach((track) => track.stop());
        stream = null;
    }

    onDestroy(releaseCamera);
</script>

<div class="flex flex-col gap-3">
    <!-- svelte-ignore a11y_media_has_caption -->
    <video
        bind:this={video}
        autoplay
        playsinline
        muted
        class="aspect-video w-full rounded-md bg-surface object-cover"
        data-testid="camera-preview"
    ></video>
    <div class="flex items-center justify-center gap-3">
        {#if recording}
            <Button variant="danger" onclick={stopRecording} testId="stop-recording">
                <Square class="size-4" aria-hidden="true" />
                録画停止
            </Button>
        {:else}
            <Button variant="primary" onclick={startRecording} testId="start-recording">
                <Circle class="size-4" aria-hidden="true" />
                録画開始
            </Button>
        {/if}
    </div>
    {#if error}
        <p class="text-center text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
