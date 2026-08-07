<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";

    /**
     * 自分宛の保留中招待の件数だけを出す誘導専用 notice (受諾 UI は持たない)。
     * 受諾は /notifications の「届いている招待」から行う。
     * 件数は shared props invitationInbox.pendingCount (親が渡す)。
     *
     * molecule に置くのは、atom (Link + Lucide icon) の組合せだけで状態も domain 操作も
     * 持たないため (NotificationBell と同じ位置づけ)。
     * ★DS: 色 / radius / typography は token 経由のみ。SVG 直書きを新設しない。
     */
    interface Props {
        pendingCount: number;
        testId?: string;
    }

    let { pendingCount, testId = "pending-invitations-notice" }: Props = $props();
</script>

{#if pendingCount > 0}
    <Link
        href="/notifications"
        class="flex items-center gap-2 rounded-md border border-border bg-primary-soft/40 px-4 py-2 text-body text-text hover:bg-primary-soft"
        data-testid={testId}
    >
        <Mail class="size-4 text-primary" aria-hidden="true" />
        あなた宛の招待が {pendingCount} 件あります
    </Link>
{/if}
