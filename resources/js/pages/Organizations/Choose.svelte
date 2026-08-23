<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import { Building2 } from "@lucide/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import OrganizationChoiceCard from "@/components/molecules/OrganizationChoiceCard.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { orgUrl } from "@/lib/org-url";
    import type { SharedProps } from "@/lib/shared-props";
    import type { OrganizationChoosePageProps } from "@/types/organization";

    /**
     * 組織を選ぶ画面 (家系裁定 AG-037)。
     *
     * ★**状態を一切保存しない**。選ぶ = その組織の URL へ移動することであり、
     *   切替 endpoint も保持列も存在しない。
     * ★複数所属で**自動選択しない** (自動選択は保持列の再発明である)。
     * ★組織付きの URL をブックマークすれば次からこの画面を通らないことを案内する。
     */
    let { target, organizations }: OrganizationChoosePageProps = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    const targetLabel = $derived(target === "capture" ? "撮影アプリ" : "ダッシュボード");
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="組織を選択"
            description={`どの組織の${targetLabel}を開くか選んでください。`}
            icon={Building2}
            testId="organization-choose-heading"
        />
        <PageContent>
            <Card padding="lg">
                <ul class="flex flex-col gap-2" data-testid="organization-choice-list">
                    {#each organizations as organization (organization.id)}
                        <li>
                            <OrganizationChoiceCard
                                name={organization.name}
                                href={orgUrl(organization.slug, target === "capture" ? "/app" : "/dashboard")}
                                testId={`organization-choice-${organization.slug}`}
                            />
                        </li>
                    {/each}
                </ul>
                <p class="mt-4 text-caption text-text-secondary">
                    よく使う組織の URL をブックマークしておくと、次からこの画面を通らずに開けます。
                </p>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
