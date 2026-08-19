<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormField from "@/components/molecules/FormField.svelte";

    /**
     * SOP (手順書) の後付けアップロード (POST .../source-documents。Inertia multipart form)。
     * 追記型 immutable: アップロードは常に新しい行を追加する (差し替え = 最新が解析対象)。
     *
     * 受理形式・画像対応の出し分けは `AcceptedSourceDocumentTypes` (画像・スキャン SOP の
     * OCR 対応) をサーバ側の単一の情報源として渡された Props に従う
     * (フロント側で文字列を解析して画像対応可否を判定しない)。
     */
    interface Props {
        projectId: number;
        manualId: number;
        hasDocument: boolean;
        sourceDocumentAccept: string;
        imageSourceDocumentsEnabled: boolean;
    }

    let { projectId, manualId, hasDocument, sourceDocumentAccept, imageSourceDocumentsEnabled }: Props = $props();

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

<form novalidate onsubmit={submit} class="flex flex-col gap-3" data-testid="source-document-upload">
    <p class="text-caption text-text-secondary" data-testid="source-document-send-notice">
        アップロードした手順書は AI 解析のためファイル内容が外部の LLM provider に送信されます。
    </p>
    {#if imageSourceDocumentsEnabled}
        <p class="text-caption text-text-secondary" data-testid="source-document-image-notice">
            画像や、文字を読み取れないスキャン PDF では、紙面の見た目がそのまま送信されます。
            不要な個人情報や機密情報が写っていないか特に確認してください。
            画像は 1 手順書につき 1 枚までです (複数ページの手順書は PDF でアップロードしてください)。
        </p>
    {/if}
    <FormField
        label={hasDocument ? "手順書を差し替える" : "手順書 (SOP) をアップロード"}
        id="source-document"
        error={form.errors.document}
    >
        {#snippet children({ id, describedBy, invalid })}
            <input
                {id}
                type="file"
                accept={sourceDocumentAccept}
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
