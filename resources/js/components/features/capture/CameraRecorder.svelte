<script lang="ts">
    import { onDestroy } from "svelte";
    import { Circle, Square } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { preferredRecordingMimeType } from "@/lib/capture/camera";

    /**
     * MediaRecorder による録画 (概念設計 D9)。停止時に blob を親へ渡す。
     * カメラ不許可・録画失敗は押下時にエラー表示する (disabled 禁止)。
     */
    interface Props {
        onCaptured: (blob: Blob, mimeType: string, durationMs: number) => void;
    }

    let { onCaptured }: Props = $props();

    let video: HTMLVideoElement | null = $state(null);
    let stream: MediaStream | null = null;
    let recorder: MediaRecorder | null = null;
    let chunks: Blob[] = [];
    let startedAt = 0;
    let recording = $state(false);
    let error = $state<string | null>(null);

    async function startRecording(): Promise<void> {
        error = null;
        const mimeType = preferredRecordingMimeType();
        if (mimeType === null) {
            error = "この端末では録画できません。ファイル選択をご利用ください。";
            return;
        }
        try {
            stream ??= await navigator.mediaDevices.getUserMedia({
                video: { facingMode: "environment" },
                audio: true,
            });
        } catch {
            error = "カメラを利用できません。ブラウザのカメラ許可を確認してください。";
            return;
        }
        if (video) {
            video.srcObject = stream;
            await video.play().catch(() => undefined);
        }
        chunks = [];
        recorder = new MediaRecorder(stream, { mimeType });
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
        recorder.start();
        recording = true;
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
