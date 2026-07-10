<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption } from "@/types/manual";

    /**
     * 動画マニュアルのメタデータ編集 (タイトル / カテゴリ)。
     * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
     * 空選択 = 未分類 (null で送信 = dissociate)。
     */
    interface Props {
        project: { id: number; name: string };
        manual: { id: number; title: string; category: number | null };
        categories: CategoryOption[];
    }

    let { project, manual, categories }: Props = $props();

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
        タイトルとカテゴリを変更できます。
    </p>

    <div class="mt-6 max-w-2xl">
        <Card padding="lg">
            <form onsubmit={submit} class="flex flex-col gap-4">
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
                        保存
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
</AppLayout>
