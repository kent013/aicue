<script lang="ts">
    import { tick, type Component } from "svelte";
    import { router } from "@inertiajs/svelte";
    import { Bell, Check, FileSearch, Film, Mail, TicketMinus, UserRoundX } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import { addToast } from "@/lib/stores/toast";
    import type {
        AccountDeletionRequestedPayload,
        InvitationReceivedPayload,
        ManualJobPayload,
        NotificationItem,
        TicketBalanceLowPayload,
    } from "@/types/notification";

    /**
     * 通知一覧の 1 行。type ごとにアイコン・文言を組み立てる。
     * 行クリック = POST /notifications/{id}/open (サーバが既読化 + 遷移先を解決する 303。
     * GET にしない = prefetch による意図しない既読化防止)。
     * 右上の個別「既読」ボタン = POST /notifications/{id}/read (遷移せず 1 件だけ既読化。
     * back() 完結)。未読行のみ表示 (禁止事項#8: disabled にせず表示/非表示で制御)。
     * 未知 type (enum⇔TS の一時的ドリフト) は汎用アイコン + rawType 表示の fallback。
     */
    interface Props {
        notification: NotificationItem;
    }

    let { notification }: Props = $props();

    let opening = $state(false); // open (行クリック) の in-flight
    let reading = $state(false); // read (個別既読) の in-flight
    let optimisticallyRead = $state(false); // 楽観既読 (単調・未読→既読方向のみ。onError で復帰)
    let contentButton = $state<HTMLButtonElement | null>(null); // 既読成功時のフォーカス移動先

    // read_at (prop = source of truth) を最優先。楽観 state は「未読→既読」方向のみ足す
    // (read-all 等が prop.read_at を確定すれば楽観 state に関わらず既読表示となり乖離しない)。
    const unread = $derived(notification.read_at === null && !optimisticallyRead);
    // 既読ボタンの表示条件を明示 derived で分離。未読の間、または in-flight 中
    // (楽観既読で unread=false になっても aria-busy を見せる) は DOM に残す。
    const showReadButton = $derived(unread || reading);

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

    const deletionPayload = $derived(
        notification.type === "account_deletion_requested" && notification.payload !== null
            ? (notification.payload as AccountDeletionRequestedPayload)
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
            case "account_deletion_requested":
                return UserRoundX;
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
        if (deletionPayload) {
            return "退会のお手続きを受け付けました";
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
            return "クリックすると、届いている招待から参加できます";
        }
        if (balancePayload) {
            return `通知の目安 (${balancePayload.threshold} 枚) を下回りました。チケットを追加購入できます`;
        }
        if (deletionPayload) {
            return `${formatDate(deletionPayload.purge_after)} に削除されます。設定画面からいつでも取り消せます`;
        }
        return null;
    });

    const organizationName = $derived.by<string | null>(() => {
        if (manualPayload) return manualPayload.organization_name;
        if (invitationPayload) return invitationPayload.organization_name;
        if (balancePayload) return balancePayload.organization_name;
        return null;
    });

    /** ISO8601 を「YYYY年M月D日」表記へ。解釈できない値は空文字 (fallback 描画に倒す) */
    function formatDate(iso: string): string {
        const date = new Date(iso);
        if (Number.isNaN(date.getTime())) return "";
        return date.toLocaleDateString("ja-JP", { year: "numeric", month: "long", day: "numeric" });
    }

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
        if (opening || reading) return; // read/open in-flight ガード (disabled ではなく送信ガード)
        opening = true; // router.post 前に同期設定 (onStart 待ちの競合窓を閉じる)
        router.post(
            `/notifications/${notification.id}/open`,
            {},
            {
                onFinish: () => {
                    opening = false;
                },
            },
        );
    }

    /**
     * 個別既読化。遷移せず 1 件だけ既読にする (read route は back() 完結)。
     * ガード通過直後・router.post 前に reading=true を同期設定して二重送信窓を閉じる。
     */
    async function markRead(event: MouseEvent): Promise<void> {
        event.stopPropagation(); // 兄弟要素だが将来 wrapper に click を置く変更への防御
        if (reading || opening || !unread) return; // read/open in-flight ガード + 既読には無反応
        reading = true;
        router.post(
            `/notifications/${notification.id}/read`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    optimisticallyRead = true; // 楽観既読 (サーバ back() 再読込が prop を確定)
                },
                onError: () => {
                    optimisticallyRead = false; // defensive reset (単調前提が崩れても未読へ戻す)
                    addToast("error", "既読にできませんでした。再試行してください。");
                },
                onFinish: async () => {
                    reading = false;
                    // 成功でボタンが DOM から消える場合、DOM 確定 (tick) を待って
                    // 行の open ボタンへフォーカスを移す (フォーカスロスト防止)
                    if (optimisticallyRead) {
                        await tick();
                        contentButton?.focus();
                    }
                },
            },
        );
    }

    const Icon = $derived(icon);
</script>

<div
    class="relative flex items-stretch border-b border-border
        {unread ? 'bg-primary-soft/40' : 'bg-surface'}"
    data-testid="notification-item-row"
>
    <!-- 主操作: open (行の hit area を保持)。右端は既読ボタン用に pr-12 を常時確保 -->
    <button
        type="button"
        onclick={open}
        bind:this={contentButton}
        class="flex min-w-0 flex-1 items-start gap-3 px-4 py-3 pr-12 text-left hover:bg-neutral"
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
                {#if unread}
                    <span
                        class="inline-block size-2 shrink-0 rounded-sm bg-primary"
                        aria-label="未読"
                        data-testid="unread-dot"
                    ></span>
                {/if}
            </span>
        </span>
    </button>
    <!-- 副操作: 個別既読 (遷移しない)。未読 or in-flight のとき右上に絶対配置 -->
    {#if showReadButton}
        <button
            type="button"
            onclick={(e) => markRead(e)}
            aria-label={reading ? "既読処理中" : "既読にする"}
            aria-busy={reading}
            class="absolute top-2 right-2 inline-flex size-8 items-center justify-center
                rounded-md text-text-secondary hover:bg-neutral hover:text-text"
            data-testid="notification-read-button"
        >
            <Check class="size-4" />
        </button>
    {/if}
</div>
