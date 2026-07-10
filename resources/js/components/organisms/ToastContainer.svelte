<script lang="ts">
    import { onDestroy } from "svelte";
    import { CircleCheck, CircleX, Info, TriangleAlert, X } from "@lucide/svelte";
    import { clearToasts, dismissToast, toasts, type ToastType } from "@/lib/stores/toast";

    // toast ストアは singleton。本 component はアプリで 1 箇所のみ mount すること
    // (複数 mount すると同一 toast が重複描画される)。

    /** type 別アイコン (Lucide) */
    const TYPE_ICONS = {
        success: CircleCheck,
        info: Info,
        warning: TriangleAlert,
        error: CircleX,
    } as const satisfies Record<ToastType, unknown>;

    /** type 別の border / アイコン色 (info は primary を流用する) */
    const TYPE_CLASSES = {
        success: { border: "border-success", icon: "text-success" },
        info: { border: "border-primary", icon: "text-primary" },
        warning: { border: "border-warning", icon: "text-warning" },
        error: { border: "border-danger", icon: "text-danger" },
    } as const satisfies Record<ToastType, { border: string; icon: string }>;

    // unmount 時 (SPA 全体破棄等の稀ケース) に残存タイマーを解除する (リーク防止)
    onDestroy(() => clearToasts());
</script>

<!-- 上部中央 fixed。複数 toast は縦 stack 表示する -->
<div class="pointer-events-none fixed top-6 left-1/2 z-50 flex -translate-x-1/2 flex-col items-center gap-2">
    {#each $toasts as toast (toast.id)}
        {@const TypeIcon = TYPE_ICONS[toast.type]}
        <!-- error は即時性が要るため role=alert (aria-live: assertive 相当)、他は role=status -->
        <div
            role={toast.type === "error" ? "alert" : "status"}
            class="pointer-events-auto flex items-center gap-2 rounded-md border bg-surface px-4 py-2 text-body text-text {TYPE_CLASSES[toast.type].border}"
            data-testid="toast-{toast.type}"
        >
            <TypeIcon class="size-4 shrink-0 {TYPE_CLASSES[toast.type].icon}" aria-hidden="true" />
            <span>{toast.message}</span>
            <button
                type="button"
                class="shrink-0 rounded-sm text-text-secondary transition-colors duration-150 hover:text-text focus-visible:ring-3 focus-visible:ring-primary/35 focus-visible:outline-none"
                aria-label="閉じる"
                onclick={() => dismissToast(toast.id)}
            >
                <X class="size-4" aria-hidden="true" />
            </button>
        </div>
    {/each}
</div>
