<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { ExternalLink } from "@lucide/svelte";
    import type { TextLinkProps } from "./TextLink.types";

    let {
        href,
        external = false,
        icon,
        onclick,
        class: extraClass = "",
        testId,
        children,
    }: TextLinkProps = $props();

    // 内部・外部・ボタンの 3 モードで共通のテキストリンク様式
    const BASE_CLASS =
        "text-primary underline decoration-primary/30 underline-offset-2 " +
        "hover:decoration-primary transition-colors duration-150";

    const computedClass = $derived(
        [
            BASE_CLASS,
            // 外部リンクは末尾アイコンを並べるため inline-flex
            external ? "inline-flex items-center gap-1" : "",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );

    // 外部リンクの末尾アイコン (icon prop で差し替え可、既定 ExternalLink)
    const IconComponent = $derived(icon ?? ExternalLink);
</script>

{#if href !== undefined && external}
    <!-- (b) 外部リンク: ネイティブ <a> + 別タブ + tabnabbing 防止 -->
    <a
        {href}
        target="_blank"
        rel="noopener noreferrer"
        class={computedClass}
        data-testid={testId}
        {onclick}
    >
        {@render children?.()}
        <IconComponent class="size-3.5 shrink-0" aria-hidden="true" />
    </a>
{:else if href !== undefined}
    <!-- (a) 内部リンク: Inertia Link で SPA 遷移 -->
    <Link {href} class={computedClass} data-testid={testId} {onclick}>
        {@render children?.()}
    </Link>
{:else}
    <!-- (c) ボタンモード: <a> にできない遷移トリガ用のリンク風 button -->
    <button type="button" class={computedClass} data-testid={testId} {onclick}>
        {@render children?.()}
    </button>
{/if}
