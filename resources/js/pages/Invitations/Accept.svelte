<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import { UserPlus } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    interface Props {
        // 不一致時はサーバが null で渡す (payload から組織名を落とす = 非受信者へ開示しない)
        organizationName: string | null;
        token: string;
        recipientEmailMatches: boolean;
    }

    let { organizationName, token, recipientEmailMatches }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 一致時のみ組織名を含む description。不一致時は組織名に触れない (payload でも null)
    const description = $derived(
        recipientEmailMatches
            ? `「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`
            : "この招待は別のメールアドレス宛に送信されています。",
    );

    const form = useForm({ token });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/invitations/accept");
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="組織への招待"
            {description}
            icon={UserPlus}
            testId="accept-invitation-heading"
        />
        <PageContent>
            <Card padding="lg">
                {#if recipientEmailMatches}
                    <form novalidate onsubmit={submit}>
                        <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                            招待を受諾する
                        </Button>
                    </form>
                {:else}
                    <p class="text-body" data-testid="accept-invitation-mismatch">
                        招待メールを受け取ったアドレスでログインし直してください。画面右上のメニューから
                        ログアウトし、招待メールのリンクをもう一度開いてください。
                    </p>
                {/if}
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
