<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { Building2 } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /**
     * 識別名 (slug) は**任意**である (家系裁定 AG-039)。
     * 省略すると組織名から導出し、導出できない (日本語名など) 場合はサーバが
     * `org-{乱数}` を割り当てる。明示した値が予約語・使用済みなら 422 で返る
     * (黙って代替を作らない)。
     */
    const form = useForm({ name: "", slug: "" });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/organizations");
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="組織の作成"
            description="新しい組織を作成します。作成後にメンバーを招待できます。"
            icon={Building2}
            testId="organizations-create-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
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
                    <FormField
                        label="識別名"
                        id="organization-slug"
                        error={form.errors.slug}
                        help="URL に使われます (小文字英数字とハイフン)。空欄なら自動で決まります。"
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={form.slug}
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
        </PageContent>
    </PageContainer>
</AppLayout>
