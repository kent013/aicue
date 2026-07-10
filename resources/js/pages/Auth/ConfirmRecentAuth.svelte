<script lang="ts">
    import { useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * recent-auth step-up の confirm 画面 (全画面フォールバック)。
     * recent-auth middleware が鮮度切れの通常遷移をここへ 302 する。確認成功後は
     * intended URL へ戻る (server 側 redirect()->intended)。
     * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
     * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
     * - canSatisfy=false: 回復導線 (パスワードリセット) を案内
     */
    interface Props {
        appName?: string;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        canSatisfy?: boolean;
    }

    let {
        appName,
        passwordSet = false,
        availableProviders = [],
        canSatisfy = true,
    }: Props = $props();

    const form = useForm({
        password: "",
    });

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/recent-auth/password");
    }
</script>

<AuthLayout title="本人確認" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        この操作を続けるには、本人確認のためもう一度認証してください。
    </p>

    {#if passwordSet}
        <form onsubmit={submit} class="flex flex-col gap-4">
            <FormField label="現在のパスワード" id="password" error={form.errors.password}>
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

            <Button type="submit" loading={form.processing} fullWidth>確認する</Button>
        </form>
    {:else}
        <p class="mb-4 text-body text-text-secondary">
            パスワードが設定されていないため、ソーシャルアカウントで再認証してください。
        </p>
        <FormError message={form.errors.password} />
    {/if}

    {#if availableProviders.length > 0}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            {#each availableProviders as provider (provider.provider)}
                <Button href={provider.reauthUrl} variant="ghost" fullWidth>
                    {providerLabel(provider.provider)}で再認証
                </Button>
            {/each}
        </div>
    {/if}

    {#if !canSatisfy}
        <div class="mt-6 flex flex-col gap-3 text-caption text-text-secondary">
            <p>この操作を続けるための再認証手段が設定されていません。パスワードを設定すると再認証できます。</p>
            <Button href="/forgot-password" variant="ghost" fullWidth>
                パスワードを設定して再認証する
            </Button>
        </div>
    {/if}
</AuthLayout>
