<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, Film } from "@lucide/svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";
    import TakePickerList from "@/components/features/manual/TakePickerList.svelte";
    import TakePreviewPanel from "@/components/features/manual/TakePreviewPanel.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { TakeSelectionPageProps } from "@/types/manual";

    /**
     * テイク選択・採用画面 (doc/04)。左 = テイク一覧、中央 = プレビュー + 採用。
     * 採用・削除・アップロードは capture.takes.* (撮影 PWA と共用の API 面) を叩き、
     * 成功したら partial reload で cut と takes を取り直す。
     */
    let { project, manual, cut, takes }: TakeSelectionPageProps = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    // 選択中テイク: 既定は採用テイク、無ければ先頭 (id で持ち、reload 後も追随させる)
    let selectedTakeId = $state<number | null>(null);
    const selectedTake = $derived(
        takes.find((take) => take.id === selectedTakeId) ??
            takes.find((take) => take.id === cut.adopted?.id) ??
            takes[0] ??
            null,
    );
    const selectedIndex = $derived(
        selectedTake === null ? null : takes.findIndex((take) => take.id === selectedTake.id),
    );

    /** 採用・削除・アップロード成功後の再取得 (cut と takes は別のトップレベル props) */
    function refresh(): void {
        router.reload({ only: ["cut", "takes"] });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection
            title={`${cut.label} のテイク選択`}
            description={cut.scene}
            icon={Film}
            testId="take-selection-heading"
        >
            <TextLink href={`/projects/${project.id}/manuals/${manual.id}/edit`}>
                <ArrowLeft class="inline size-3" aria-hidden="true" />
                シナリオ編集へ戻る
            </TextLink>
        </PageHeaderSection>
        <PageContent>
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-[20rem_minmax(0,1fr)]">
                <TakePickerList
                    {takes}
                    adoptedTakeId={cut.adopted?.id ?? null}
                    selectedTakeId={selectedTake?.id ?? null}
                    onSelect={(id) => (selectedTakeId = id)}
                    projectId={project.id}
                    manualId={manual.id}
                    cutId={cut.id}
                    onChanged={refresh}
                />
                <div class="flex min-w-0 flex-col gap-4">
                    <TakePreviewPanel
                        take={selectedTake}
                        takeIndex={selectedIndex}
                        {cut}
                        manualStatus={manual.status}
                        projectId={project.id}
                        manualId={manual.id}
                        onChanged={refresh}
                    />
                    <TakeFileUpload
                        projectId={project.id}
                        manualId={manual.id}
                        cutId={cut.id}
                        onUploaded={refresh}
                    />
                </div>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
