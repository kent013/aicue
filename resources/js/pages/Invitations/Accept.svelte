<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
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
    <div class="mx-auto mt-8 max-w-md">
        <Card padding="lg">
            <h1 class="text-h2">組織への招待</h1>
            <p class="mt-3 text-body">
                「{organizationName}」に招待されています。受諾するとこの組織のメンバーになります。
            </p>
            <form onsubmit={submit} class="mt-6">
                <Button type="submit" loading={form.processing} testId="accept-invitation-button">
                    招待を受諾する
                </Button>
            </form>
        </Card>
    </div>
</AppLayout>
