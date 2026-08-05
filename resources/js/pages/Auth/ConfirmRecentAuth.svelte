<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";
    import { confirmPasskeyCredential, isPasskeySupported } from "@/lib/passkeys";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * recent-auth step-up の confirm 画面 (全画面フォールバック)。
     * recent-auth middleware が鮮度切れの通常遷移をここへ 302 する。確認成功後は
     * intended URL へ戻る (server 側 redirect()->intended)。
     * - password 設定済みユーザー: password 再入力フォーム (POST /recent-auth/password)
     * - 再SSO 可能な provider: reauthUrl (/auth/{provider}/redirect/step-up) で再認証
     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 (POST /passkeys/confirm、204)。
     *   **パスキーしか持たないユーザーをこの画面で詰ませない**ための導線
     * - canSatisfy=false: 回復手順 (ログアウト → guest としてパスワード再設定) を案内。
     *   /forgot-password へ直接リンクしない — Fortify が `guest` middleware 付きで登録しており
     *   ログイン済みの本画面ユーザーはフォームに到達できない (踏破不能 CTA。bug-hunt F-2-01 と同 species)
     */
    interface Props {
        appName?: string;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        /** パスキーで再認証できるか (サーバが単一の源) */
        passkeyAvailable?: boolean;
        canSatisfy?: boolean;
    }

    let {
        appName,
        passwordSet = false,
        availableProviders = [],
        passkeyAvailable = false,
        canSatisfy = true,
    }: Props = $props();

    const passkeySupported = isPasskeySupported();
    let passkeyError = $state("");
    let passkeyProcessing = $state(false);

    /**
     * **この端末で実行できる** satisfier があるか。
     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定)。パスキーしか無いユーザーが
     * WebAuthn 非対応ブラウザで開くと「手段はあるが、この端末では実行できない」=
     * 説明の無い行き止まりになるため、その状態を明示して回復導線を出す。
     */
    const executableHere = $derived(
        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
    );

    /**
     * パスキーで再認証する。
     *
     * ceremony 結果は **Inertia の router.post で送る** (fetch ではない)。
     * この画面は RequireRecentAuth の 302 fallback 着地であり、元 URL は
     * サーバの `url.intended` にしか無い。Inertia で送れば
     * PasskeyConfirmationResponse が `redirect()->intended()` を返し、元の操作画面へ戻る。
     */
    async function submitPasskey(): Promise<void> {
        if (passkeyProcessing) return;
        passkeyProcessing = true;
        passkeyError = "";
        try {
            const outcome = await confirmPasskeyCredential();
            if (outcome.status === "ok") {
                router.post(
                    "/passkeys/confirm",
                    { credential: outcome.value },
                    {
                        onError: () => {
                            passkeyError = "パスキーでの再認証に失敗しました。";
                        },
                    },
                );
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

    {#if passkeyAvailable && passkeySupported}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            {#if passkeyError}
                <FormError message={passkeyError} testId="confirm-passkey-error" />
            {/if}
            <Button
                variant="ghost"
                fullWidth
                loading={passkeyProcessing}
                onclick={() => void submitPasskey()}
                testId="confirm-passkey-button"
            >
                パスキーで再認証
            </Button>
        </div>
    {/if}

    {#if availableProviders.length > 0}
        <div class="mt-6 flex flex-col gap-3">
            {#if passwordSet || (passkeyAvailable && passkeySupported)}
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
    {:else if !executableHere}
        <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
        <div
            class="mt-6 flex flex-col gap-3 text-caption text-text-secondary"
            data-testid="confirm-unsupported-here"
        >
            <p>
                このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
                パスキーを登録した端末・ブラウザで開き直すと再認証できます。
                その端末が使えない場合は、いったんログアウトし、ログイン画面の
                「パスワードをお忘れの方」からパスワードを設定してください。
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
