<script lang="ts">
    import type { Component } from "svelte";
    import { router } from "@inertiajs/svelte";
    import { Bell, FileSearch, Film, Mail, TicketMinus } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import type {
        InvitationReceivedPayload,
        ManualJobPayload,
        NotificationItem,
        TicketBalanceLowPayload,
    } from "@/types/notification";

    /**
     * 通知一覧の 1 行。type ごとにアイコン・文言を組み立てる。
     * 行クリック = POST /notifications/{id}/open (サーバが既読化 + 遷移先を解決する 303。
     * GET にしない = prefetch による意図しない既読化防止)。
     * 未知 type (enum⇔TS の一時的ドリフト) は汎用アイコン + rawType 表示の fallback。
     */
    interface Props {
        notification: NotificationItem;
    }

    let { notification }: Props = $props();

    let opening = $state(false);

    const unread = $derived(notification.read_at === null);

    // payload の判別は type discriminant + null 検査 (サーバ側で検証復元済み)
    const manualPayload = $derived(
        (notification.type === "manual_analyzed" || notification.type === "manual_rendered") &&
            notification.payload !== null
            ? (notification.payload as ManualJobPayload)
            : null,
    );
    const invitationPayload = $derived(
        notification.type === "invitation_received" && notification.payload !== null
            ? (notification.payload as InvitationReceivedPayload)
            : null,
    );
    const balancePayload = $derived(
        notification.type === "ticket_balance_low" && notification.payload !== null
            ? (notification.payload as TicketBalanceLowPayload)
            : null,
    );

    const icon = $derived.by<Component>(() => {
        switch (notification.type) {
            case "manual_analyzed":
                return FileSearch;
            case "manual_rendered":
                return Film;
            case "invitation_received":
                return Mail;
            case "ticket_balance_low":
                return TicketMinus;
            default:
                return Bell;
        }
    });

    const title = $derived.by<string>(() => {
        if (manualPayload) {
            const kind = notification.type === "manual_analyzed" ? "AI 解析" : "動画の書き出し";
            return manualPayload.succeeded
                ? `${kind}が完了しました`
                : `${kind}に失敗しました`;
        }
        if (invitationPayload) {
            return `${invitationPayload.organization_name} に招待されています`;
        }
        if (balancePayload) {
            return `チケット残高が残り ${balancePayload.balance} 枚になりました`;
        }
        // 未知 type / payload 復元失敗の fallback (rawType をそのまま出す)
        return notification.type;
    });

    const body = $derived.by<string | null>(() => {
        if (manualPayload) {
            return manualPayload.succeeded
                ? manualPayload.manual_title
                : `${manualPayload.manual_title}: ${manualPayload.error ?? "エラーが発生しました"}`;
        }
        if (invitationPayload) {
            return "メールの受諾リンクから参加してください";
        }
        if (balancePayload) {
            return `通知の目安 (${balancePayload.threshold} 枚) を下回りました。チケットを追加購入できます`;
        }
        return null;
    });

    const organizationName = $derived.by<string | null>(() => {
        if (manualPayload) return manualPayload.organization_name;
        if (invitationPayload) return invitationPayload.organization_name;
        if (balancePayload) return balancePayload.organization_name;
        return null;
    });

    /** 相対時刻 (分/時間/日)。7 日超は日付表示 */
    function relativeTime(iso: string): string {
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return "";
        const diffMs = Date.now() - date.getTime();
        const minutes = Math.floor(diffMs / 60_000);
        if (minutes < 1) return "たった今";
        if (minutes < 60) return `${minutes}分前`;
        const hours = Math.floor(minutes / 60);
        if (hours < 24) return `${hours}時間前`;
        const days = Math.floor(hours / 24);
        if (days <= 7) return `${days}日前`;
        return date.toLocaleDateString("ja-JP");
    }

    function open(): void {
        if (opening) return; // 連打ガード (disabled 属性ではなく送信ガード)
        router.post(
            `/notifications/${notification.id}/open`,
            {},
            {
                onStart: () => {
                    opening = true;
                },
                onFinish: () => {
                    opening = false;
                },
            },
        );
    }

    const Icon = $derived(icon);
</script>

<button
    type="button"
    onclick={open}
    class="flex w-full items-start gap-3 border-b border-border px-4 py-3 text-left
        hover:bg-neutral {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
    data-testid="notification-item"
    data-unread={unread}
>
    <span
        class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-md
            {unread ? 'bg-primary-soft text-primary' : 'bg-neutral text-text-secondary'}"
        aria-hidden="true"
    >
        <Icon class="size-4" />
    </span>
    <span class="min-w-0 flex-1">
        <span class="block text-body {unread ? 'font-medium' : ''} text-text">{title}</span>
        {#if body !== null}
            <span class="mt-0.5 block truncate text-caption text-text-secondary">{body}</span>
        {/if}
        <span class="mt-1 flex items-center gap-2">
            {#if organizationName !== null}
                <Badge tone="neutral" size="sm">{organizationName}</Badge>
            {/if}
            <span class="text-caption text-text-secondary">
                {relativeTime(notification.created_at)}
            </span>
        </span>
    </span>
    {#if unread}
        <span
            class="mt-2 inline-block size-2 shrink-0 rounded-sm bg-primary"
            aria-label="未読"
            data-testid="unread-dot"
        ></span>
    {/if}
</button>
