<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { FolderPlus } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * プロジェクト作成。Team 選択は出さない (Default Team パターン:
     * 所属はサーバ側の ProjectService が組織の Default Team に自動割当する)。
     */
    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm({ name: "", description: "" });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/projects");
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プロジェクトの作成"
            description="新しいプロジェクトを作成します。"
            icon={FolderPlus}
            testId="project-create-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                    <FormField label="プロジェクト名" id="project-name" error={form.errors.name} required>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={form.name}
                                error={invalid}
                                aria-describedby={describedBy}
                                oninput={() => {
                                    // 入力し始めたらその場でエラーを消す (次 submit を待たない)。
                                    // **値そのものを消す**ので、同じ文言が再び返れば必ず再表示される。
                                    if (form.errors.name) form.clearErrors("name");
                                }}
                            />
                        {/snippet}
                    </FormField>
                    <FormField label="説明" id="project-description" error={form.errors.description}>
                        {#snippet children({ id, describedBy, invalid })}
                            <Textarea
                                {id}
                                bind:value={form.description}
                                error={invalid}
                                aria-describedby={describedBy}
                                oninput={() => {
                                    // フィールド単位で消す (引数なし clearErrors は使わない =
                                    // 片方の編集で他方のエラーを消さない)
                                    if (form.errors.description) form.clearErrors("description");
                                }}
                            />
                        {/snippet}
                    </FormField>
                    <div class="flex items-center gap-2">
                        <Button type="submit" loading={form.processing} testId="project-submit">
                            作成
                        </Button>
                        <Button variant="ghost" href="/projects" inertia>キャンセル</Button>
                    </div>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
