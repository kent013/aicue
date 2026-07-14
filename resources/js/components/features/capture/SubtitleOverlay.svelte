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
