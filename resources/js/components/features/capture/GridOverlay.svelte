<script lang="ts">
    /**
     * 撮影プレビューへ重畳する三分割グリッド (doc/05 §5.2 グリッド表示)。
     * 構図補助の overlay で MediaRecorder が録る MediaStream には含まれない (焼込ではない)。
     * z 順は映像 < grid < 字幕帯 (SubtitleOverlay)。字幕帯と重なっても字幕優先で可読。
     */
    interface Props {
        visible: boolean;
    }
    let { visible }: Props = $props();
</script>

{#if visible}
    <div class="pointer-events-none absolute inset-0" aria-hidden="true" data-testid="grid-overlay">
        <!-- 三分割: 縦 2 本・横 2 本の細線。DS token surface の半透明で映像上に薄く表示 -->
        <div class="absolute inset-y-0 left-1/3 w-px bg-surface/40"></div>
        <div class="absolute inset-y-0 left-2/3 w-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-1/3 h-px bg-surface/40"></div>
        <div class="absolute inset-x-0 top-2/3 h-px bg-surface/40"></div>
    </div>
{/if}
