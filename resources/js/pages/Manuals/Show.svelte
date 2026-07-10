<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
    import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { AnalysisProps, VideoManualStatus } from "@/types/manual";
    import { VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * 動画マニュアル詳細 (メタデータ + AI 解析パネル)。撮影者も閲覧可
     * (編集操作・解析起動は canManage のみ。撮影者には進捗表示のみ)。
     */
    interface Props {
        project: { id: number; name: string };
        manual: {
            id: number;
            title: string;
            status: VideoManualStatus;
            category: { id: number; name: string } | null;
            created_at: string;
        };
        analysis: AnalysisProps;
        canManage: boolean;
    }

    let { project, manual, analysis, canManage }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const STATUS_TONES: Record<
        VideoManualStatus,
        "primary" | "tertiary" | "success" | "warning" | "neutral"
    > = {
        draft: "neutral",
        analyzing: "tertiary",
        ready: "success",
        rendering: "warning",
        published: "primary",
    };

    /* ---- 削除 ---- */
    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

    function deleteManual(): void {
        router.delete(`/projects/${project.id}/manuals/${manual.id}`, {
            onStart: () => {
                deleting = true;
            },
            onFinish: () => {
                deleting = false;
                deleteDialogOpen = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-caption text-text-secondary">
                <TextLink href={`/projects/${project.id}`}>{project.name}</TextLink>
            </p>
            <h1 class="mt-1 truncate text-h2" data-testid="manual-title">{manual.title}</h1>
            <div class="mt-2 flex items-center gap-3">
                <Badge tone={STATUS_TONES[manual.status]} testId="manual-status">
                    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                </Badge>
                <span class="text-caption text-text-secondary" data-testid="manual-category">
                    {manual.category?.name ?? "未分類"}
                </span>
                <span class="text-caption text-text-secondary">{manual.created_at}</span>
            </div>
        </div>
        {#if canManage}
            <Button
                variant="ghost"
                href={`/projects/${project.id}/manuals/${manual.id}/edit`}
                inertia
                testId="edit-manual-button"
            >
                編集
            </Button>
        {/if}
    </div>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <AnalysisPanel
            projectId={project.id}
            manualId={manual.id}
            manualStatus={manual.status}
            job={analysis.job}
            hasDocument={analysis.hasDocument}
            {canManage}
        />

        {#if canManage && (manual.status === "draft" || manual.status === "ready")}
            <Card padding="lg">
                <h2 class="text-h3">手順書 (SOP)</h2>
                <p class="mt-2 text-caption text-text-secondary">
                    PDF / Excel / テキストの手順書をアップロードできます。差し替えた場合は最新のファイルが解析対象になります。
                </p>
                <div class="mt-4">
                    <SourceDocumentUpload
                        projectId={project.id}
                        manualId={manual.id}
                        hasDocument={analysis.hasDocument}
                    />
                </div>
            </Card>
        {/if}

        {#if canManage}
            <DangerZone
                title="動画マニュアルの削除"
                description="この動画マニュアルと配下のすべてのデータを削除します。この操作は取り消せません。"
            >
                <Button
                    variant="danger-outline"
                    onclick={() => (deleteDialogOpen = true)}
                    testId="delete-manual-button"
                >
                    動画マニュアルを削除
                </Button>
            </DangerZone>
        {/if}
    </div>

    <ConfirmDialog
        bind:open={deleteDialogOpen}
        title="動画マニュアル削除"
        message={`「${manual.title}」を削除しますか？ この操作は取り消せません。`}
        confirmLabel="削除する"
        confirmVariant="danger"
        processing={deleting}
        onConfirm={deleteManual}
        testId="delete-manual-dialog"
    />
</AppLayout>
