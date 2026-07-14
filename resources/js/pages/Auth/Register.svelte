<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import { providerLabel } from "@/lib/social";

    interface Props {
        appName?: string;
        socialProviders?: string[];
        invitationEmail?: string | null;
    }

    let { appName, socialProviders = [], invitationEmail = null }: Props = $props();

    // 招待リンク経由 (invitationEmail あり) は招待先 email を初期値にし、以降 readonly で固定する。
    // readonly は UX 上の "誘導" に過ぎない: devtools で外して別 email を POST しても、サーバの
    // MatchesInvitationEmail (active token がある間は招待 email 以外を 422) が真正性を強制する。
    // prefill + readonly は「正しい値を先に入れて手入力ミスを防ぐ」ためのものでセキュリティ境界ではない。
    const isInvited = $derived(invitationEmail != null && invitationEmail !== "");

    const form = useForm({
        name: "",
        email: invitationEmail ?? "",
        password: "",
        terms_accepted: false,
    });

    /**
     * SSO 登録で同意未チェック時に表示するクライアント側エラー。
     * 送信ボタンは disabled でブロックしない (DESIGN.md §Do's and Don'ts)。
     * メール登録側はサーバの terms_accepted エラーをそのまま表示する。
     */
    let ssoTermsError = $state<string | null>(null);

    const termsError = $derived(form.errors.terms_accepted ?? ssoTermsError);

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/register");
    }

    function handleTermsChange(): void {
        if (form.terms_accepted) {
            ssoTermsError = null;
        }
    }

    function handleSsoClick(event: MouseEvent): void {
        if (!form.terms_accepted) {
            event.preventDefault();
            ssoTermsError = "利用規約への同意が必要です。";
        }
    }

    const ssoHref = $derived((provider: string) =>
        form.terms_accepted
            ? `/auth/${provider}/redirect/register?terms_accepted=1`
            : `/auth/${provider}/redirect/register`,
    );
</script>

<AuthLayout title="アカウント登録" {appName}>
    <form onsubmit={submit} class="flex flex-col gap-4">
        <FormField label="名前" id="name" error={form.errors.name}>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="text"
                    bind:value={form.name}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="name"
                />
            {/snippet}
        </FormField>

        <FormField
            label="メールアドレス"
            id="email"
            error={form.errors.email}
            help={isInvited ? "招待されたメールアドレスで登録します。" : undefined}
        >
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="email"
                    bind:value={form.email}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="email"
                    readonly={isInvited}
                />
            {/snippet}
        </FormField>

        <FormField label="パスワード" id="password" error={form.errors.password}>
            {#snippet children({ id, describedBy, invalid })}
                <PasswordInput
                    {id}
                    bind:value={form.password}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="new-password"
                />
            {/snippet}
        </FormField>

        <Checkbox
            id="terms_accepted"
            bind:checked={form.terms_accepted}
            error={termsError}
            onchange={handleTermsChange}
            testId="terms-checkbox"
        >
            {#snippet label()}
                <TextLink href="/terms">利用規約</TextLink>と<TextLink href="/privacy"
                    >プライバシーポリシー</TextLink
                >に同意します
            {/snippet}
        </Checkbox>

        <Button type="submit" loading={form.processing} fullWidth>登録</Button>
    </form>

    {#if socialProviders.length > 0}
        <Divider label="または" class="my-6" />
        <div class="flex flex-col gap-3">
            {#each socialProviders as provider (provider)}
                <Button
                    variant="ghost"
                    href={ssoHref(provider)}
                    onclick={handleSsoClick}
                    fullWidth
                    testId={`sso-register-${provider}`}
                >
                    {providerLabel(provider)} で登録
                </Button>
            {/each}
        </div>
    {/if}

    {#snippet footer()}
        <p>
            すでにアカウントをお持ちの方は
            <TextLink href="/login">ログイン</TextLink>
        </p>
    {/snippet}
</AuthLayout>
