<script lang="ts">
    import { router, useForm } from "@inertiajs/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PasswordInput from "@/components/molecules/PasswordInput.svelte";
    import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";
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
     *   実装は molecules/RecentAuthRecoveryNotice に集約する (インラインモーダル側と共有。
     *   分けて持つと片方だけ旧作法 = guest 限定の /forgot-password 直リンクが残る)
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
                <!-- 非フィールド起因の操作失敗は Alert (DESIGN.md §Alert)。ceremony 失敗を
                     password 欄のフィールドエラーとして出さない -->
                <Alert type="danger" testId="confirm-passkey-error">{passkeyError}</Alert>
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
        <div class="mt-6">
            <RecentAuthRecoveryNotice variant="no-satisfier" />
        </div>
    {:else if !executableHere}
        <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
        <div class="mt-6">
            <RecentAuthRecoveryNotice variant="not-executable-here" />
        </div>
    {/if}

    {#snippet footer()}
        <p>
            <TextLink href="/go">この操作を中止してダッシュボードへ戻る</TextLink>
        </p>
    {/snippet}
</AuthLayout>
