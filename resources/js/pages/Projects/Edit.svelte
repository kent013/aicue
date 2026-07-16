<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";

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
        form.patch(`/projects/${project.id}`);
    }
</script>

<AppLayout {appName}>
    <PageContent maxWidth="2xl">
        <h1 class="text-h2">プロジェクトの編集</h1>
        <p class="mt-1 text-caption text-text-secondary">
            {project.name} の名前と説明を変更します。
        </p>

        <div class="mt-6">
            <Card padding="lg">
                <form onsubmit={submit} class="flex flex-col gap-4">
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
                        <Button variant="ghost" href={`/projects/${project.id}`} inertia>
                            キャンセル
                        </Button>
                    </div>
                </form>
            </Card>
        </div>
    </PageContent>
</AppLayout>
