<script lang="ts">
    import { Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * MediaRecorder 非対応環境 (iOS Safari 等) のフォールバック撮影 (概念設計 D9)。
     * OS ネイティブのカメラ/ファイル選択を capture 属性で起動する。
     */
    interface Props {
        onCaptured: (file: File) => void;
    }

    let { onCaptured }: Props = $props();
    let input: HTMLInputElement | null = $state(null);
    let error = $state<string | null>(null);

    function handleChange(): void {
        error = null;
        const file = input?.files?.[0];
        if (!file) return;
        if (!file.type.startsWith("video/")) {
            error = "動画ファイルを選択してください。";
            return;
        }
        onCaptured(file);
        if (input) input.value = "";
    }
</script>

<div class="flex flex-col items-center gap-3 py-6">
    <input
        bind:this={input}
        type="file"
        accept="video/*"
        capture="environment"
        class="hidden"
        onchange={handleChange}
        data-testid="capture-file-input"
    />
    <Button variant="primary" onclick={() => input?.click()} testId="capture-file-button">
        <Video class="size-5" aria-hidden="true" />
        カメラで撮影 / 動画を選択
    </Button>
    <p class="text-caption text-text-secondary">
        この端末ではカメラアプリで撮影し、動画を選択してアップロードします。
    </p>
    {#if error}
        <p class="text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
