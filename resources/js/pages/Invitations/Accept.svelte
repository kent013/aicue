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
        organizationName: string;
        token: string;
    }

    let { organizationName, token }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

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
            description={`「${organizationName}」に招待されています。受諾するとこの組織のメンバーになります。`}
            icon={UserPlus}
            testId="accept-invitation-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit}>
                    <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                        招待を受諾する
                    </Button>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
