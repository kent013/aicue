<script lang="ts">
    import { Check, MapPin, Video } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import { buildCutLabels } from "@/lib/capture/cut-labels";
    import type { CaptureCut } from "@/types/capture";

    /**
     * シナリオカットのナビゲーション (doc/10 §10.1)。
     * 手順 N / 急所ラベルは cuts の走査で派生する (行タップで撮影パネルを開く)。
     */
    interface Props {
        cuts: CaptureCut[];
        selectedCutId: number | null;
        onSelect: (cutId: number) => void;
    }

    let { cuts, selectedCutId, onSelect }: Props = $props();

    /**
     * 手順番号ラベル (step は連番、point は親 step の番号 + 枝番)。
     * 導出規則は lib/capture/cut-labels.ts が唯一の正本 (撮影パネル見出し・
     * テイクプレビューの aria-label と共有するため)。
     */
    const labels = $derived(buildCutLabels(cuts));
</script>

<ul class="divide-y divide-border" data-testid="cut-navigator">
    {#each cuts as cut (cut.id)}
        <li>
            <button
                type="button"
                class={[
                    "flex w-full items-center gap-3 px-3 py-3 text-left transition-colors hover:bg-neutral",
                    selectedCutId === cut.id && "bg-neutral",
                ]}
                onclick={() => onSelect(cut.id)}
                data-testid={`cut-row-${cut.id}`}
            >
                <div class="min-w-0 flex-1">
                    <p class="text-caption text-text-secondary">
                        {labels[cut.id]}
                        <span class="ml-1">{cut.shot_type === "hiki" ? "引き" : "寄り"}</span>
                    </p>
                    <p class="truncate text-body">{cut.scene}</p>
                    {#if cut.shooting_point}
                        <p class="flex min-w-0 items-center gap-1 text-caption text-text-secondary">
                            <MapPin class="size-3 shrink-0" aria-hidden="true" />
                            <span class="min-w-0 flex-1 truncate">{cut.shooting_point}</span>
                        </p>
                    {/if}
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    {#if cut.takes.length > 0}
                        <Badge tone="neutral">
                            <Video class="size-3" aria-hidden="true" />
                            {cut.takes.length}
                        </Badge>
                    {/if}
                    {#if cut.adopted_take_id !== null}
                        <Badge tone="success" testId={`cut-adopted-${cut.id}`}>
                            <Check class="size-3" aria-hidden="true" />
                            採用済
                        </Badge>
                    {/if}
                </div>
            </button>
        </li>
    {/each}
</ul>
