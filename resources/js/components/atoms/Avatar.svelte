<script lang="ts">
    /**
     * Avatar atom。src があれば画像、無ければ name の先頭 1 文字 (大文字化) をイニシャル表示する。
     * アバターは真に円形な UI のため rounded-full を使う (DESIGN.md §Shapes の ramp 外例外。
     * tests/js/support/ds-purity.ts の FILE_SCOPED_ALLOWLIST で管理)。
     */

    type AvatarSize = "sm" | "md" | "lg";

    interface Props {
        /** 表示名。src 無し時のイニシャル算出と alt の既定に使う */
        name: string;
        /** 画像 URL。指定時は <img>、無指定時はイニシャル */
        src?: string;
        /** 画像の alt。未指定なら name */
        alt?: string;
        size?: AvatarSize;
        class?: string;
        testId?: string;
    }

    let { name, src, alt, size = "md", class: extraClass = "", testId }: Props = $props();

    const SIZE_CLASSES = {
        sm: "size-7 text-caption",
        md: "size-10 text-body",
        lg: "size-14 text-h3",
    } as const satisfies Record<AvatarSize, string>;

    const computedClass = $derived(
        [
            "inline-flex shrink-0 items-center justify-center overflow-hidden rounded-full",
            "bg-neutral font-medium text-text-secondary select-none",
            SIZE_CLASSES[size],
            extraClass,
        ]
            .filter(Boolean)
            .join(" "),
    );

    // イニシャルは先頭 1 文字を大文字化 (サロゲートペアも 1 文字として扱う)
    const initial = $derived([...name.trim()][0]?.toUpperCase() ?? "");
</script>

{#if src !== undefined}
    <img
        {src}
        alt={alt ?? name}
        class="{computedClass} object-cover"
        data-testid={testId}
    />
{:else}
    <span class={computedClass} aria-label={alt ?? name} data-testid={testId}>
        {initial}
    </span>
{/if}
