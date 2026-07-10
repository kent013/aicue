<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { LoaderCircle } from "@lucide/svelte";
    import type { ButtonProps } from "./Button.types";
    import {
        ICON_ONLY_SIZE_CLASSES,
        ICON_ONLY_VARIANTS,
        SIZE_CLASSES,
        VARIANT_CLASSES,
    } from "./Button.types";

    let {
        variant = "primary",
        size = "md",
        fullWidth = false,
        loading = false,
        iconOnly = false,
        ariaLabel,
        href,
        inertia = false,
        target,
        rel,
        type = "button",
        disabled = false,
        onclick,
        testId,
        class: extraClass = "",
        children,
    }: ButtonProps = $props();

    $effect(() => {
        if (import.meta.env.DEV && iconOnly && !ICON_ONLY_VARIANTS.has(variant)) {
            console.warn(
                `[Button] iconOnly は ${[...ICON_ONLY_VARIANTS].join(" / ")} のみ許可です (variant=${variant})`,
            );
        }
    });

    const computedClass = $derived(
        [
            "inline-flex items-center justify-center gap-2 rounded-sm border font-medium",
            "transition-colors duration-150",
            "focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none",
            "disabled:cursor-not-allowed disabled:opacity-40",
            VARIANT_CLASSES[variant],
            iconOnly ? ICON_ONLY_SIZE_CLASSES[size] : SIZE_CLASSES[size],
            fullWidth ? "w-full" : "",
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );

    // target="_blank" には noopener noreferrer を自動補完する (tabnabbing 防止)
    const computedRel = $derived(
        target === "_blank"
            ? [...new Set([...(rel ?? "").split(" ").filter(Boolean), "noopener", "noreferrer"])].join(" ")
            : rel,
    );

    // anchor は disabled を持てないため、loading 中は click を抑止して誤遷移・二重実行を防ぐ。
    // pointer-events-none は使わない (ポインターを切ると click が親要素に抜けるため)。
    function handleAnchorClick(event: MouseEvent): void {
        if (loading) {
            event.preventDefault();
            event.stopPropagation();
            return;
        }
        onclick?.(event);
    }
</script>

{#snippet content()}
    {#if loading}
        <LoaderCircle class="size-4 animate-spin" aria-hidden="true" />
    {/if}
    {@render children?.()}
{/snippet}

{#if href !== undefined && inertia}
    <Link
        {href}
        class={computedClass}
        aria-label={ariaLabel}
        aria-disabled={loading || undefined}
        aria-busy={loading || undefined}
        tabindex={loading ? -1 : undefined}
        data-testid={testId}
        onclick={handleAnchorClick}
    >
        {@render content()}
    </Link>
{:else if href !== undefined}
    <a
        {href}
        {target}
        rel={computedRel}
        class={computedClass}
        aria-label={ariaLabel}
        aria-disabled={loading || undefined}
        aria-busy={loading || undefined}
        tabindex={loading ? -1 : undefined}
        data-testid={testId}
        onclick={handleAnchorClick}
    >
        {@render content()}
    </a>
{:else}
    <button
        {type}
        class={computedClass}
        disabled={disabled || loading}
        aria-label={ariaLabel}
        aria-busy={loading || undefined}
        data-testid={testId}
        {onclick}
    >
        {@render content()}
    </button>
{/if}
