<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
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
    <h1 class="text-h2">プロジェクトの作成</h1>
    <p class="mt-1 text-caption text-text-secondary">
        新しいプロジェクトを作成します。
    </p>

    <div class="mt-6 max-w-2xl">
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
                        作成
                    </Button>
                    <Button variant="ghost" href="/projects" inertia>キャンセル</Button>
                </div>
            </form>
        </Card>
    </div>
</AppLayout>
