<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm({ name: "" });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/organizations");
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">組織の作成</h1>
    <p class="mt-1 text-caption text-text-secondary">
        新しい組織を作成します。作成後にメンバーを招待できます。
    </p>

    <div class="mt-6 max-w-2xl">
        <Card padding="lg">
            <form onsubmit={submit} class="flex flex-col gap-4">
                <FormField label="組織名" id="organization-name" error={form.errors.name} required>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={form.name}
                            error={invalid}
                            aria-describedby={describedBy}
                        />
                    {/snippet}
                </FormField>
                <div>
                    <Button type="submit" loading={form.processing}>作成</Button>
                </div>
            </form>
        </Card>
    </div>
</AppLayout>
