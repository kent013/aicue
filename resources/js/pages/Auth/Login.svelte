<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import { isPasskeySupported, loginWithPasskey } from "@/lib/passkeys";
    import { providerLabel } from "@/lib/social";

    interface Props {
        appName?: string;
        socialProviders?: string[];
    }

    let { appName, socialProviders = [] }: Props = $props();

    const form = useForm({
        email: "",
        password: "",
        remember: false,
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/login");
    }

    /*
     * パスキーでのログイン。
     * ceremony は fetch 完結で、成功時にサーバから受け取った着地 URL へ遷移する
     * (Inertia のページ遷移とは無関係。transport 契約は詳細設計 施策 4-d)。
     * 2要素認証を有効にしているアカウントはサーバが拒否する (PasskeyLoginPolicy)。
     * 失敗しても**パスワード欄と SSO ボタンは残す** (回復導線を消さない)。
     */
    const passkeySupported = isPasskeySupported();
    let passkeyError = $state("");
    let passkeyProcessing = $state(false);

    async function submitPasskey(): Promise<void> {
        if (passkeyProcessing) return;
        passkeyProcessing = true;
        passkeyError = "";
        try {
            const outcome = await loginWithPasskey(form.remember);
            if (outcome.status === "ok") {
                window.location.assign(outcome.value.redirect);
                return;
            }
            // キャンセルは失敗として騒がない (再試行導線を残す)
            if (outcome.status === "cancelled") return;
            passkeyError =
                outcome.status === "unsupported"
                    ? "このブラウザはパスキーに対応していません。"
                    : outcome.message;
        } finally {
            passkeyProcessing = false;
        }
    }
</script>

<AuthLayout title="ログイン" {appName}>
    <form novalidate onsubmit={submit} class="flex flex-col gap-4">
        <FormField label="メールアドレス" id="email" error={form.errors.email}>
            {#snippet children({ id, describedBy, invalid })}
                <Input
                    {id}
                    type="email"
                    bind:value={form.email}
                    error={invalid}
                    aria-describedby={describedBy}
                    autocomplete="email"
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
                    autocomplete="current-password"
                />
            {/snippet}
        </FormField>

        <Checkbox id="remember" bind:checked={form.remember} label="ログイン状態を保持" />

        <Button type="submit" loading={form.processing} fullWidth>ログイン</Button>
    </form>

    {#if passkeySupported}
        <Divider label="または" class="my-6" />
        <div class="flex flex-col gap-2">
            {#if passkeyError}
                <Alert type="danger" testId="passkey-login-error">{passkeyError}</Alert>
            {/if}
            <Button
                variant="ghost"
                fullWidth
                loading={passkeyProcessing}
                onclick={() => void submitPasskey()}
                testId="passkey-login-button"
            >
                パスキーでログイン
            </Button>
            <p class="text-caption text-text-secondary">
                2要素認証を有効にしているアカウントでは、パスキーでログインできません。
            </p>
        </div>
    {/if}

    {#if socialProviders.length > 0}
        <Divider label="または" class="my-6" />
        <div class="flex flex-col gap-3">
            {#each socialProviders as provider (provider)}
                <Button
                    variant="ghost"
                    href={`/auth/${provider}/redirect/login`}
                    fullWidth
                    testId={`sso-login-${provider}`}
                >
                    {providerLabel(provider)} でログイン
                </Button>
            {/each}
        </div>
    {/if}

    {#snippet footer()}
        <p>
            アカウントをお持ちでない方は
            <TextLink href="/register">登録</TextLink>
        </p>
        <p class="mt-1">
            <TextLink href="/forgot-password">パスワードをお忘れの方</TextLink>
        </p>
    {/snippet}
</AuthLayout>
