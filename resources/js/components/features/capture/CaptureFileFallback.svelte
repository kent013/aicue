<script lang="ts">
    import { Camera, Video } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import { normalizeStillFile, STILL_CONTENT_TYPE } from "@/lib/capture/still-encode";

    /**
     * MediaRecorder 非対応環境 (iOS Safari 等) のフォールバック撮影 (概念設計 D9)。
     * OS ネイティブのカメラ/ファイル選択を capture 属性で起動する。
     *
     * カットの計画が静止画なら画像を選ばせ、**必ず再エンコードしてから**親へ渡す
     * (寸法上限が効き、EXIF が落ちる)。正規化に失敗したときは原本を送らない。
     */
    interface Props {
        /** cut の計画。still なら画像を選ばせ、正規化してから親へ渡す */
        material?: "video" | "still";
        onCaptured: (blob: Blob, contentType: string) => void | Promise<void>;
    }

    let { material = "video", onCaptured }: Props = $props();
    let input: HTMLInputElement | null = $state(null);
    let error = $state<string | null>(null);

    const isStill = $derived(material === "still");
    const accept = $derived(isStill ? "image/*" : "video/*");

    async function handleChange(): Promise<void> {
        error = null;
        const file = input?.files?.[0];
        try {
            if (!file) return;
            const expected = isStill ? "image/" : "video/";
            if (!file.type.startsWith(expected)) {
                error = isStill
                    ? "画像ファイルを選択してください。"
                    : "動画ファイルを選択してください。";
                return;
            }
            if (!isStill) {
                await onCaptured(file, file.type.split(";")[0]);
                return;
            }
            // 静止画は必ず再エンコードして送る (寸法上限 + EXIF を落とす)
            const normalized = await normalizeStillFile(file);
            if (normalized === null) {
                error = "画像を読み込めませんでした。別のファイルをお試しください。";
                return; // 原本は送らない
            }
            await onCaptured(normalized, STILL_CONTENT_TYPE);
        } finally {
            if (input) input.value = "";
        }
    }
</script>

<div class="flex flex-col items-center gap-3 py-6">
    <input
        bind:this={input}
        type="file"
        {accept}
        capture="environment"
        class="hidden"
        onchange={handleChange}
        data-testid="capture-file-input"
    />
    <Button variant="primary" onclick={() => input?.click()} testId="capture-file-button">
        {#if isStill}
            <Camera class="size-5" aria-hidden="true" />
            カメラで撮影 / 画像を選択
        {:else}
            <Video class="size-5" aria-hidden="true" />
            カメラで撮影 / 動画を選択
        {/if}
    </Button>
    <p class="text-caption text-text-secondary">
        {isStill
            ? "この端末ではカメラアプリで撮影し、画像を選択してアップロードします。"
            : "この端末ではカメラアプリで撮影し、動画を選択してアップロードします。"}
    </p>
    {#if error}
        <p class="text-caption text-danger" role="alert">{error}</p>
    {/if}
</div>
