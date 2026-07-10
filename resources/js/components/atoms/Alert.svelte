<script lang="ts" module>
    export type AlertType = "success" | "warning" | "danger" | "info";
</script>

<script lang="ts">
    import type { Snippet } from "svelte";
    import { X } from "@lucide/svelte";

    /**
     * Alert atom。ページ内に常在するインライン通知ボックス (一時通知は Toast を使う)。
     *
     * 配色は DESIGN.md §Components Alert: ボーダーは状態色、本文は墨色 (text-text)、
     * 見出しは状態色、背景は surface。info は状態色 token を持たないため primary を流用する
     * (Toast と同じ規約)。テーマ色を alert の面塗りには使わない。
     *
     * a11y: danger のみ role=alert (aria-live=assertive、即時通知)。
     * success/warning/info は role=status (polite) で読み上げを邪魔しない。
     */
    interface Props {
        type: AlertType;
        /** 状態色の見出し行 (任意)。省略時は見出しを描画しない */
        title?: string;
        /** 本文 (必須) */
        children: Snippet;
        /** CTA ボタン等を本文の下 (mt-4) に描画するスロット */
        action?: Snippet;
        /** true + onDismiss で右上に閉じるボタンを描画する */
        dismissible?: boolean;
        onDismiss?: () => void;
        class?: string;
        testId?: string;
    }

    let {
        type,
        title,
        children,
        action,
        dismissible = false,
        onDismiss,
        class: extraClass = "",
        testId,
    }: Props = $props();

    // dynamic class 生成は Tailwind に静的検出されないため、type ごとの static class を map で持つ
    const TYPE_CLASSES = {
        success: { border: "border-success", title: "text-success" },
        warning: { border: "border-warning", title: "text-warning" },
        danger: { border: "border-danger", title: "text-danger" },
        info: { border: "border-primary", title: "text-primary" },
    } as const satisfies Record<AlertType, { border: string; title: string }>;

    const role = $derived(type === "danger" ? "alert" : "status");
    const ariaLive = $derived(type === "danger" ? "assertive" : "polite");

    // 中間 box (パネル) なので rounded-md (DESIGN.md §Shapes)
    const computedClass = $derived(
        ["rounded-md border bg-surface p-4", TYPE_CLASSES[type].border, extraClass]
            .filter(Boolean)
            .join(" "),
    );
</script>

<div class={computedClass} {role} aria-live={ariaLive} aria-atomic="true" data-testid={testId}>
    <div class="flex items-start gap-3">
        <div class="min-w-0 flex-1">
            {#if title}
                <h4 class="mb-1 text-h3 {TYPE_CLASSES[type].title}">{title}</h4>
            {/if}
            <div class="text-body text-text">
                {@render children()}
            </div>
            {#if action}
                <div class="mt-4">
                    {@render action()}
                </div>
            {/if}
        </div>
        {#if dismissible && onDismiss}
            <button
                type="button"
                class="shrink-0 rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                aria-label="閉じる"
                onclick={onDismiss}
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        {/if}
    </div>
</div>
