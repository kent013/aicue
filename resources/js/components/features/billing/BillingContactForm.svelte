<script lang="ts">
    import { page as inertiaPage, router } from "@inertiajs/svelte";
    import { Receipt } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import type { BillingContactShape } from "@/types/billing";

    /**
     * BillingContactForm — 請求先情報 (メール / 宛名) の更新セクション (P9)。
     *
     * 請求書・支払い失敗などの請求通知の宛先になる。未設定の間は組織オーナーのメールへ
     * 送られるため、その旨を help に出す (fallbackEmail はサーバ確定値)。
     *
     * **ボタンは未入力でも disabled にしない** (AGENTS.md 禁止事項 #8) — 押下してサーバの
     * validation 文言を表示する。in-flight の多重送信抑止は Button の loading で表現する。
     */
    interface Props {
        billingContact: BillingContactShape;
        /** 更新 PATCH 先 (billing.contact.update) */
        updateUrl: string;
        canManage: boolean;
    }

    let { billingContact, updateUrl, canManage }: Props = $props();

    let emailText = $state(billingContact.email ?? "");
    let nameText = $state(billingContact.name ?? "");
    let submitting = $state(false);

    const serverErrors = $derived(
        (inertiaPage.props.errors ?? {}) as Record<string, string>,
    );
    const emailError = $derived(!submitting ? (serverErrors.billing_contact_email ?? null) : null);
    const nameError = $derived(!submitting ? (serverErrors.billing_contact_name ?? null) : null);

    const helpText = $derived(
        billingContact.email === null && billingContact.fallbackEmail !== null
            ? `未設定のため、現在は組織オーナー (${billingContact.fallbackEmail}) 宛に送信しています。`
            : "請求書・お支払いに関するご連絡をこのメールアドレスへお送りします。",
    );

    function submit(): void {
        if (submitting) return; // 多重送信ガード (disabled にはしない)
        router.patch(
            updateUrl,
            { billing_contact_email: emailText, billing_contact_name: nameText },
            {
                preserveScroll: true,
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    }
</script>

<Card padding="lg" testId="billing-contact-card">
    <div class="flex items-center gap-2">
        <Receipt class="size-5 text-text-secondary" aria-hidden="true" />
        <h2 class="text-h3">請求先情報</h2>
    </div>

    {#if canManage}
        <form
            class="mt-4 flex flex-col gap-4"
            data-testid="billing-contact-form"
            onsubmit={(event) => {
                event.preventDefault();
                submit();
            }}
        >
            <FormField
                label="請求先メールアドレス"
                id="billing-contact-email"
                required
                error={emailError}
                help={helpText}
            >
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="email"
                        bind:value={emailText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-email-input"
                    />
                {/snippet}
            </FormField>

            <FormField label="宛名 (任意)" id="billing-contact-name" error={nameError}>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        bind:value={nameText}
                        error={invalid}
                        aria-describedby={describedBy}
                        testId="billing-contact-name-input"
                    />
                {/snippet}
            </FormField>

            <div>
                <Button type="submit" loading={submitting} testId="billing-contact-submit">
                    請求先情報を保存
                </Button>
            </div>
        </form>
    {:else}
        <dl class="mt-4 grid gap-4 md:grid-cols-2">
            <div>
                <dt class="text-caption text-text-secondary">請求先メールアドレス</dt>
                <dd class="mt-1 text-body text-text" data-testid="billing-contact-email-readonly">
                    {billingContact.email ?? "未設定"}
                </dd>
            </div>
            <div>
                <dt class="text-caption text-text-secondary">宛名</dt>
                <dd class="mt-1 text-body text-text" data-testid="billing-contact-name-readonly">
                    {billingContact.name ?? "未設定"}
                </dd>
            </div>
        </dl>
        <p class="mt-4 text-caption text-text-secondary">
            請求先情報の変更には組織の管理者権限が必要です。
        </p>
    {/if}
</Card>
