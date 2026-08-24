<script lang="ts">
    /**
     * 企業アカウントでのログインの入口。
     *
     * ★組織から配られた**識別名**を入れて開始するだけの画面である。
     *   外向き通信も DB の変更もここでは起きない (開始は次の GET 導線が行う)。
     * ★開始導線は **GET の anchor リンク**である (form POST にしない —
     *   CSP form-action がリダイレクト先の IdP に適用されてブロックされる)。
     * ★識別名が空でもボタンを押せる。押した時にエラーを表示する (禁止事項 8)。
     */
    import { page } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import { Building2 } from "@lucide/svelte";
    import type { SharedProps } from "@/lib/shared-props";

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    let connectionSlug = $state("");
    let localError = $state<string | null>(null);

    /** 識別名の書式 (サーバ側の登録規則と同じ形。ここでの判定は入力の手当てである)。 */
    const SLUG_PATTERN = /^[a-z0-9][a-z0-9-]*[a-z0-9]$/;

    function start(event: MouseEvent): void {
        const value = connectionSlug.trim();

        if (value === "") {
            event.preventDefault();
            localError = "組織から配られた識別名を入力してください。";
            return;
        }

        if (!SLUG_PATTERN.test(value)) {
            event.preventDefault();
            localError = "識別名は英小文字・数字・ハイフンで入力してください。";
            return;
        }

        localError = null;
    }

    const href = $derived(
        connectionSlug.trim() === ""
            ? "#"
            : `/enterprise/${encodeURIComponent(connectionSlug.trim())}/redirect`,
    );
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeader
            title="企業アカウントでログイン"
            description="組織から配られた識別名を入力すると、勤務先の ID プロバイダへ移動します。"
            icon={Building2}
            testId="enterprise-login-heading"
        />
        <PageContent>
            <Card padding="lg">
                <FormField label="識別名" id="enterprise-connection-slug" error={localError}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={connectionSlug}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="off"
                            testId="enterprise-connection-slug"
                        />
                    {/snippet}
                </FormField>

                <p class="mt-2 text-caption text-text-secondary">
                    識別名が分からない場合は、組織の管理者にお問い合わせください。
                </p>

                <div class="mt-4 flex items-center justify-between gap-4">
                    <TextLink href="/login" testId="enterprise-login-back">
                        通常のログインに戻る
                    </TextLink>
                    <!-- 開始は GET の anchor リンク (form POST にしない) -->
                    <Button {href} onclick={start} testId="enterprise-login-start">
                        次へ進む
                    </Button>
                </div>
            </Card>
        </PageContent>
    </PageContainer>
</AppLayout>
