<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import { FolderKanban } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * プロジェクト一覧 (current org の全プロジェクト)。
     * teams_visible=false の既定では Team 概念を出さない (Default Team パターン)。
     */
    interface Project {
        id: number;
        name: string;
        description: string | null;
    }

    interface Props {
        projects: Project[];
        canCreate: boolean;
    }

    let { projects, canCreate }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
</script>

<AppLayout {appName}>
    <PageContent maxWidth="2xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h1 class="text-h2">プロジェクト</h1>
                <p class="mt-1 text-caption text-text-secondary">
                    この組織のプロジェクトの一覧です。
                </p>
            </div>
            {#if canCreate && projects.length > 0}
                <Button href="/projects/create" inertia testId="create-project-button">
                    新しいプロジェクト
                </Button>
            {/if}
        </div>

        {#if projects.length === 0}
            <div class="mt-6">
                <EmptyState
                    title="プロジェクトはまだありません"
                    description="最初のプロジェクトを作成して、リソースの管理を始めましょう。"
                    icon={FolderKanban}
                    cta={canCreate
                        ? { kind: "link", label: "プロジェクトを作成", href: "/projects/create" }
                        : undefined}
                    bordered
                    testId="projects-empty"
                />
            </div>
        {:else}
            <ul class="mt-6 flex flex-col gap-4" data-testid="project-list">
                {#each projects as project (project.id)}
                    <li>
                        <Card padding="lg">
                            <TextLink href={`/projects/${project.id}`} class="text-h3">
                                {project.name}
                            </TextLink>
                            {#if project.description}
                                <p class="mt-2 text-body text-text-secondary">
                                    {project.description}
                                </p>
                            {/if}
                        </Card>
                    </li>
                {/each}
            </ul>
        {/if}
    </PageContent>
</AppLayout>
