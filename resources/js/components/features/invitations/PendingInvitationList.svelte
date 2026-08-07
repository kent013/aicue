<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { Mail } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import type { PendingInvitation } from "@/types/invitation";

    /**
     * 自分宛の受諾可能な招待の一覧 (受諾ボタン付き)。
     * 受諾 = POST /invitations/{id}/accept-in-app (サーバが 302 で dashboard へ着地させる)。
     *
     * ★禁止事項 8 との関係: 「必須条件未充足を理由に disabled にする」ことはしない
     *   (前提条件で押せないボタンを作らない)。in-flight 中は既存 Button atom の
     *   `loading` (= disabled + aria-busy) を使う — これは二重送信防止であって
     *   必須条件未充足による無効化ではなく、同画面の招待送信ボタン
     *   (`loading={inviteForm.processing}`) と同じ既存流儀である。
     *   加えてハンドラ側でも in-flight 中の再入を無視する (二重の送信ガード)。
     * ★DS: 色 / radius / typography は token 経由のみ (hex 直書き・独自 radius を増やさない)。
     *   アイコンは @lucide/svelte の Mail のみ (SVG 直書きを新設しない)。
     *   Card の入れ子を作らない (この component 自身が 1 枚の Card)。
     */
    interface Props {
        invitations: PendingInvitation[];
    }

    let { invitations }: Props = $props();

    let acceptingId = $state<number | null>(null);

    function accept(invitation: PendingInvitation): void {
        if (acceptingId !== null) return; // in-flight 中の再入を無視 (disabled ではない)
        acceptingId = invitation.id;
        router.post(
            `/invitations/${invitation.id}/accept-in-app`,
            {},
            {
                onFinish: () => {
                    acceptingId = null;
                },
            },
        );
    }
</script>

{#if invitations.length > 0}
    <Card padding="lg" testId="pending-invitation-list">
        <h2 class="text-h3">届いている招待</h2>
        <p class="mt-1 text-caption text-text-secondary">
            あなた宛の招待です。参加すると、その組織のメンバーになります。
        </p>
        <ul class="mt-4 divide-y divide-border">
            {#each invitations as invitation (invitation.id)}
                <li
                    class="flex flex-wrap items-center gap-3 py-3"
                    data-testid={`pending-invitation-${invitation.id}`}
                >
                    <span
                        class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-primary-soft text-primary"
                        aria-hidden="true"
                    >
                        <Mail class="size-4" />
                    </span>
                    <p class="min-w-0 grow truncate text-body text-text">
                        {invitation.organizationName}
                    </p>
                    <Badge tone="neutral" size="sm">{invitation.roleLabel}</Badge>
                    <span class="text-caption text-text-secondary">期限 {invitation.expiresAt}</span>
                    <Button
                        onclick={() => accept(invitation)}
                        loading={acceptingId === invitation.id}
                        testId={`accept-invitation-${invitation.id}`}
                    >
                        参加する
                    </Button>
                </li>
            {/each}
        </ul>
    </Card>
{/if}
