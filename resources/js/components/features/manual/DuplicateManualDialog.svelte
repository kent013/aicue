<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { CategoryOption } from "@/types/manual";

    /**
     * マニュアル複製 (別名保存) ダイアログ。保存済みシナリオを新タイトル・カテゴリで複製する。
     * タイトルは「{元タイトル} のコピー」、カテゴリは元 category をプリフィルする。
     */
    interface Props {
        open: boolean;
        projectId: number;
        manualId: number;
        defaultTitle: string; // 「{元タイトル} のコピー」
        defaultCategory: number | null; // 元 category id (null = 未分類)
        categories: CategoryOption[];
    }

    let {
        open = $bindable(false),
        projectId,
        manualId,
        defaultTitle,
        defaultCategory,
        categories,
    }: Props = $props();

    // useForm はマウント時 1 回だけ初期化する (Manuals/Edit と同じ流儀。複製後は redirect で
    // 画面遷移するため props の再供給は起きない = 初期値のみ参照で足りる)。
    const form = useForm<{ title: string; category: string }>({
        title: defaultTitle,
        category: defaultCategory === null ? "" : String(defaultCategory),
    });

    // 送信本体。form 送信 (Enter) と footer ボタン onclick の双方から呼ぶ
    // (Button atom は form 属性を持たないため footer は onclick で発火させる)。
    function submit(): void {
        form
            .transform((data) => ({
                title: data.title,
                // category は Select 固定値 (option value=id 文字列 or "") のため Number 変換は安全 ('' のみ null)
                category: data.category === "" ? null : Number(data.category),
            }))
            .post(`/projects/${projectId}/manuals/${manualId}/duplicate`, {
                // 成功時は redirect で新 manual へ遷移するため onSuccess で閉じる必要はない
                onError: () => {
                    /* エラーは FormField 経由で表示 (ダイアログは開いたまま) */
                },
            });
    }

    function onFormSubmit(event: SubmitEvent): void {
        event.preventDefault();
        submit();
    }
</script>

<Modal bind:open title="動画マニュアルを複製" size="sm" processing={form.processing} testId="duplicate-manual-dialog">
    <form id="duplicate-manual-form" onsubmit={onFormSubmit} class="flex flex-col gap-4">
        <p class="text-caption text-text-secondary">
            シナリオ（カット）を引き継いだ新しい動画マニュアルを作成します。撮影データ・手順書（SOP）は引き継がれません。
        </p>
        <FormField label="タイトル" id="duplicate-title" error={form.errors.title} required>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={form.title}
                    error={invalid}
                    aria-describedby={describedBy}
                    oninput={() => {
                        if (form.errors.title) form.clearErrors("title");
                    }}
                />
            {/snippet}
        </FormField>
        <FormField label="カテゴリ" id="duplicate-category" error={form.errors.category}>
            {#snippet children({ id, describedBy, invalid })}
                <Select
                    {id}
                    bind:value={form.category}
                    error={invalid}
                    aria-describedby={describedBy}
                    testId="duplicate-category-select"
                >
                    <option value="">未分類</option>
                    {#each categories as category (category.id)}
                        <option value={String(category.id)}>{category.name}</option>
                    {/each}
                </Select>
            {/snippet}
        </FormField>
    </form>
    {#snippet footer()}
        <Button variant="ghost" onclick={() => (open = false)} disabled={form.processing}>キャンセル</Button>
        <Button
            variant="primary"
            loading={form.processing}
            onclick={submit}
            testId="duplicate-manual-confirm">複製する</Button
        >
    {/snippet}
</Modal>
