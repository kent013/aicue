<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
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
     * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
     *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
     *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
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

    let loggingOut = $state(false);

    function submit(event: SubmitEvent): void {
        event.preventDefault();
        form.post("/recent-auth/password");
    }

    function logout(): void {
        router.post(
            "/logout",
            {},
            {
                onStart: () => {
                    loggingOut = true;
                },
                onFinish: () => {
                    loggingOut = false;
                },
            },
        );
    }
</script>

<AuthLayout title="本人確認" {appName}>
    <p class="mb-6 text-body text-text-secondary">
        この操作を続けるには、本人確認のためもう一度認証してください。
    </p>

    {#if passwordSet}
        <form novalidate onsubmit={submit} class="flex flex-col gap-4">
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
            <p>
                この操作を続けるための再認証手段が設定されていません。
                いったんログアウトし、ログイン画面の「パスワードをお忘れの方」から
                パスワードを設定すると再認証できるようになります。
            </p>
            <Button variant="ghost" onclick={logout} loading={loggingOut} fullWidth>
                ログアウトする
            </Button>
        </div>
    {/if}

    {#snippet footer()}
        <p>
            <TextLink href="/dashboard">この操作を中止してダッシュボードへ戻る</TextLink>
        </p>
    {/snippet}
</AuthLayout>
