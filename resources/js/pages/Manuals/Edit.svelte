<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption, ScenarioDocument, VideoManualStatus } from "@/types/manual";

    /**
     * 動画マニュアルの編集 (基本情報 + シナリオ)。
     * 保存単位は完全分離: 「基本情報を保存」(メタ PATCH = Inertia form) と
     * 「シナリオを更新」(document PUT = ScenarioEditor 内の XHR) を独立セクションで表示する。
     * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
     * 空選択 = 未分類 (null で送信 = dissociate)。
     */
    interface Props {
        project: { id: number; name: string };
        manual: {
            id: number;
            title: string;
            category: number | null;
            status: VideoManualStatus;
        };
        categories: CategoryOption[];
        scenario: ScenarioDocument;
    }

    let { project, manual, categories, scenario }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm({
        title: manual.title,
        category: manual.category === null ? "" : String(manual.category),
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.transform((data) => ({
            title: data.title,
            category: data.category === "" ? null : Number(data.category),
        })).patch(`/projects/${project.id}/manuals/${manual.id}`);
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">動画マニュアルの編集</h1>
    <p class="mt-1 text-caption text-text-secondary">
        基本情報とシナリオ (撮影台本) を編集できます。
    </p>

    <div class="mt-6 max-w-2xl">
        <Card padding="lg">
            <h2 class="text-h3">基本情報</h2>
            <form onsubmit={submit} class="mt-4 flex flex-col gap-4">
                <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={form.title}
                            error={invalid}
                            aria-describedby={describedBy}
                        />
                    {/snippet}
                </FormField>
                <FormField label="カテゴリ" id="manual-category" error={form.errors.category}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Select
                            {id}
                            bind:value={form.category}
                            error={invalid}
                            aria-describedby={describedBy}
                            testId="manual-category-select"
                        >
                            <option value="">未分類</option>
                            {#each categories as category (category.id)}
                                <option value={String(category.id)}>{category.name}</option>
                            {/each}
                        </Select>
                    {/snippet}
                </FormField>
                <div class="flex items-center gap-2">
                    <Button type="submit" loading={form.processing} testId="manual-submit">
                        基本情報を保存
                    </Button>
                    <Button
                        variant="ghost"
                        href={`/projects/${project.id}/manuals/${manual.id}`}
                        inertia
                    >
                        キャンセル
                    </Button>
                </div>
            </form>
        </Card>
    </div>

    <div class="mt-8 max-w-4xl">
        <h2 class="text-h3">シナリオ</h2>
        <p class="mt-1 text-caption text-text-secondary">
            手順と急所のカットを編集し、「シナリオを更新」でまとめて保存します。
        </p>
        <ScenarioEditor {scenario} projectId={project.id} manualId={manual.id} />
    </div>
</AppLayout>
