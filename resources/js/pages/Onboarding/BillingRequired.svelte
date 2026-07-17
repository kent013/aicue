<script lang="ts">
    import { page as inertiaPage } from "@inertiajs/svelte";
    import { Clock } from "@lucide/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type { BillingRequiredShape, OnboardingOrganizationShape } from "@/types/onboarding";

    /**
     * 課金手続き待ちの説明画面 (課金権限を持たないメンバーの着地先)。
     *
     * 403 で突き放さず、組織管理者の連絡先と問い合わせ導線を提示して
     * 「行き先のない詰み」を回避する (owner 不在 org では連絡先が null になりうる)。
     */
    interface Props {
        organization: OnboardingOrganizationShape;
        pageData: BillingRequiredShape;
    }

    let { organization, pageData }: Props = $props();

    const shared = $derived(inertiaPage.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="課金手続き中です"
            icon={Clock}
            testId="billing-required-heading"
        />
        <PageContent>
            <div class="flex flex-col gap-6" data-testid="onboarding-billing-required">
                <p class="text-body text-text-secondary" data-testid="billing-required-message">
                    <strong class="text-text">{organization.name}</strong>
                    はまだ有料プランの契約が完了していません。 組織管理者が課金手続きを完了するのをお待ちください。
                </p>

                {#if pageData.ownerName !== null}
                    <Card padding="lg" testId="billing-required-owner">
                        <p class="text-caption text-text-secondary">組織管理者</p>
                        <p class="mt-1 text-body text-text">{pageData.ownerName}</p>
                        {#if pageData.ownerEmail !== null}
                            <p class="text-caption text-text-secondary">
                                <a
                                    href={`mailto:${pageData.ownerEmail}`}
                                    class="text-primary underline"
                                    data-testid="billing-required-owner-email"
                                >
                                    {pageData.ownerEmail}
                                </a>
                            </p>
                        {/if}
                    </Card>
                {/if}

                <p class="text-caption text-text-secondary">
                    <!-- contactUrl は内部 path / 外部 URL / mailto のいずれにもなりうる (ContactUrl が
                         解決する) ため、素の <a> で全パターンを同じ扱いにする (Welcome と同規約)。 -->
                    ご不明点は <a
                        href={pageData.contactUrl}
                        class="text-primary underline"
                        data-testid="billing-required-contact-link">お問い合わせ</a
                    > ください。
                </p>
            </div>
        </PageContent>
    </PageContainer>
</AppLayout>
