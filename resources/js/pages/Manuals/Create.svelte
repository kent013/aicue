<script lang="ts">
    import { page, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import { BookOpen } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { CategoryOption } from "@/types/manual";

    /**
     * 動画マニュアル作成 (タイトル + カテゴリ + 任意の手順書アップロード)。
     * カテゴリの入力名は保護キー category_id と別名の `category` (id 値)。
     * 空選択 = 未分類 (null で送信)。document は multipart で任意送信。
     */
    interface Props {
        project: { id: number; name: string };
        categories: CategoryOption[];
    }

    let { project, categories }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const form = useForm<{ title: string; category: string; document: File | null }>({
        title: "",
        category: "",
        document: null,
    });

    function onFileChange(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        form.document = input.files?.[0] ?? null;
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.transform((data) => ({
            title: data.title,
            category: data.category === "" ? null : Number(data.category),
            document: data.document,
        })).post(`/projects/${project.id}/manuals`);
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="動画マニュアルの作成"
            description={`${project.name} に新しい動画マニュアルを作成します。`}
            icon={BookOpen}
            testId="manual-create-heading"
        />
        <PageContent>
            <Card padding="lg">
                <form novalidate onsubmit={submit} class="flex flex-col gap-4">
                    <FormField label="タイトル" id="manual-title" error={form.errors.title} required>
                        {#snippet children({ id, describedBy, invalid })}
                            <Input
                                {id}
                                type="text"
                                bind:value={form.title}
                                error={invalid}
                                aria-describedby={describedBy}
                                oninput={() => {
                                    // 入力し始めたらその場でタイトルエラーをクリア (次 submit を待たない)
                                    if (form.errors.title) form.clearErrors("title");
                                }}
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
                    <FormField
                        label="手順書 (SOP・任意)"
                        id="manual-document"
                        error={form.errors.document}
                        help="PDF / Excel / テキスト。アップロードすると AI 解析でシナリオを生成できます。"
                    >
                        {#snippet children({ id, describedBy, invalid })}
                            <input
                                {id}
                                type="file"
                                accept=".pdf,.xlsx,.xls,.txt"
                                onchange={onFileChange}
                                aria-describedby={describedBy}
                                aria-invalid={invalid}
                                class="block w-full text-body text-text file:mr-3 file:rounded-md file:border file:border-border file:bg-surface file:px-3 file:py-1.5 file:text-caption file:text-text"
                                data-testid="manual-document-input"
                            />
                        {/snippet}
                    </FormField>
                    <div class="flex items-center gap-2">
                        <Button type="submit" loading={form.processing} testId="manual-submit">
                            作成
                        </Button>
                        <Button variant="ghost" href={`/projects/${project.id}`} inertia>
                            キャンセル
                        </Button>
                    </div>
                </form>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
