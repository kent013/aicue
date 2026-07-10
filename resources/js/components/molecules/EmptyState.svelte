<script lang="ts">
    import type { Component } from "svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * 空状態表示の molecule。リストやテーブルが空のとき、次の行動を案内する。
     *
     * - icon: Lucide component を受けて大きめ (size-10) に表示する (装飾なので aria-hidden)
     * - cta: discriminated union で遷移 (link) と操作 (action) を型安全に出し分ける
     *   (link は Button atom の anchor モード inertia、action は onclick)
     * - bordered: true で破線枠サーフェス (drop 領域や明示的な空 region 向け)
     */
    type Cta =
        | { kind: "link"; label: string; href: string }
        | { kind: "action"; label: string; onclick: () => void };

    interface Props {
        /** 見出し (任意)。省略時は見出しを描画しない (説明のみの空状態も吸収する) */
        title?: string;
        /** 説明文 (必須)。空状態には必ず説明文を付ける */
        description: string;
        /** Lucide アイコン component (@lucide/svelte) */
        icon?: Component;
        cta?: Cta;
        /** true で破線枠 (border-dashed) で囲む */
        bordered?: boolean;
        testId?: string;
    }

    let { title, description, icon, cta, bordered = false, testId }: Props = $props();

    // markup で component として描画するため大文字始まりの別名に束ねる
    const Icon = $derived(icon);
</script>

<div
    class="flex flex-col items-center justify-center px-6 py-16 text-center {bordered
        ? 'rounded-lg border border-dashed border-border'
        : ''}"
    data-testid={testId}
>
    {#if Icon}
        <Icon class="mb-4 size-10 text-text-secondary" aria-hidden="true" />
    {/if}
    {#if title}
        <h3 class="text-h3 text-text">{title}</h3>
    {/if}
    <p class="{title ? 'mt-2' : ''} max-w-sm text-body text-text-secondary">{description}</p>
    {#if cta}
        <div class="mt-6">
            {#if cta.kind === "link"}
                <Button variant="primary" href={cta.href} inertia>{cta.label}</Button>
            {:else}
                <Button variant="primary" onclick={cta.onclick}>{cta.label}</Button>
            {/if}
        </div>
    {/if}
</div>
