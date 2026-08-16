<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { BookOpen, Camera } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
    import DuplicateManualDialog from "@/components/features/manual/DuplicateManualDialog.svelte";
    import RenderPanel from "@/components/features/manual/RenderPanel.svelte";
    import ScenarioReportPanel from "@/components/features/manual/ScenarioReportPanel.svelte";
    import SourceDocumentUpload from "@/components/features/manual/SourceDocumentUpload.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { AnalysisProps, CategoryOption, RenderProps, VideoManualStatus } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS, isCaptureNavigable } from "@/types/manual";

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
        render: RenderProps;
        canManage: boolean;
        categories: CategoryOption[];
    }

    let { project, manual, analysis, render, canManage, categories }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 撮影ナビ (capture.manuals.show) への文脈リンクを出してよい状態か
    const captureNavigable = $derived(isCaptureNavigable(manual.status));

    /* ---- 複製 (別名保存) ---- */
    let duplicateDialogOpen = $state(false);

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
    <PageContainer>
        <PageHeaderSection
            title={manual.title}
            icon={BookOpen}
            testId="manual-title"
            breadcrumbs={[
                { label: project.name, href: `/projects/${project.id}` },
                { label: manual.title },
            ]}
        >
            {#if captureNavigable}
                <!-- canManage 内外を問わず表示 (撮影者=project_member も撮影ナビ view 可) -->
                <Button
                    variant="primary"
                    href={`/app/projects/${project.id}/manuals/${manual.id}`}
                    inertia
                    testId="capture-manual-link"
                >
                    <Camera class="size-4" aria-hidden="true" />
                    この手順書を撮影する
                </Button>
            {/if}
            {#if canManage}
                <Button
                    variant="ghost"
                    onclick={() => (duplicateDialogOpen = true)}
                    testId="duplicate-manual-button"
                >
                    複製
                </Button>
                <Button
                    variant="ghost"
                    href={`/projects/${project.id}/manuals/${manual.id}/edit`}
                    inertia
                    testId="edit-manual-button"
                >
                    編集
                </Button>
            {/if}
        </PageHeaderSection>
        <PageContent>
            <div class="flex items-center gap-3">
                <Badge tone={STATUS_TONES[manual.status]} testId="manual-status">
                    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                </Badge>
                <span class="text-caption text-text-secondary" data-testid="manual-category">
                    {manual.category?.name ?? "未分類"}
                </span>
                <span class="text-caption text-text-secondary">{manual.created_at}</span>
            </div>

            <div class="mt-6 flex flex-col gap-10">
            <AnalysisPanel
                projectId={project.id}
                manualId={manual.id}
                manualStatus={manual.status}
                job={analysis.job}
                hasDocument={analysis.hasDocument}
                {canManage}
            />

            {#if analysis.report}
                <ScenarioReportPanel
                    projectId={project.id}
                    manualId={manual.id}
                    report={analysis.report}
                    {canManage}
                />
            {/if}

            <RenderPanel
                projectId={project.id}
                manualId={manual.id}
                manualStatus={manual.status}
                job={render.job}
                previewJob={render.previewJob}
                playbackJob={render.playbackJob}
                finishedJob={render.finishedJob}
                coverage={render.coverage}
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

        {#if canManage}
            <DuplicateManualDialog
                bind:open={duplicateDialogOpen}
                projectId={project.id}
                manualId={manual.id}
                defaultTitle={`${manual.title} のコピー`}
                defaultCategory={manual.category?.id ?? null}
                {categories}
            />
        {/if}

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
        </PageContent>
    </PageContainer>
</AppLayout>
