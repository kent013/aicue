<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormField from "@/components/molecules/FormField.svelte";

    /**
     * SOP (手順書) の後付けアップロード (POST .../source-documents。Inertia multipart form)。
     * 追記型 immutable: アップロードは常に新しい行を追加する (差し替え = 最新が解析対象)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        hasDocument: boolean;
    }

    let { projectId, manualId, hasDocument }: Props = $props();

    const form = useForm<{ document: File | null }>({ document: null });

    function onFileChange(event: Event): void {
        const input = event.currentTarget as HTMLInputElement;
        form.document = input.files?.[0] ?? null;
    }

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post(`/projects/${projectId}/manuals/${manualId}/source-documents`, {
            onSuccess: () => form.reset(),
        });
    }
</script>

<form onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
    <FormField
        label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
        id="source-document"
        error={form.errors.document}
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
                data-testid="source-document-input"
            />
        {/snippet}
    </FormField>
    <div>
        <Button type="submit" variant="secondary" loading={form.processing} testId="source-document-submit">
            アップロード
        </Button>
    </div>
</form>
