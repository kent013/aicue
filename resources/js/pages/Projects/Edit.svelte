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
    import { FolderKanban } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { currentOrgUrl } from "@/lib/org-url";

    /** プロジェクト編集 (name / description)。所属 Team の変更 UI は出さない。 */
    interface Props {
        project: { id: number; name: string; description: string | null };
    }

    let { project }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 初期値として現在の値を取り込む (以後はフォーム側が真実)
    // svelte-ignore state_referenced_locally
    const form = useForm({ name: project.name, description: project.description ?? "" });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.patch(currentOrgUrl(`/projects/${project.id}`));
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="プロジェクトの編集"
            description={`${project.name} の名前と説明を変更します。`}
            icon={FolderKanban}
            testId="project-edit-heading"
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
                            />
                        {/snippet}
                    </FormField>
                    <div class="flex items-center gap-2">
                        <Button type="submit" loading={form.processing} testId="project-submit">
                            保存
                        </Button>
                        <Button variant="ghost" href={currentOrgUrl(`/projects/${project.id}`)} inertia>
                            キャンセル
                        </Button>
                    </div>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
