<script lang="ts">
    import { ShieldCheck } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import { csrfToken } from "@/lib/csrf";
    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前に出す
     * 「同一画面の再認証 (step-up) モーダル」。
     * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
     * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
     * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
     * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
     */
    interface Props {
        open: boolean;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        canSatisfy?: boolean;
        /**
         * パスキーでの再認証を提示するか。**サーバの `/recent-auth/status` が単一の源**
         * (`RecentAuthStatus.passkeyAvailable`)。呼び出し側が独自に判定しない
         * — 画面ごとに判定を持つと passkey しか持たないユーザーが特定画面でだけ詰む。
         */
        passkeyAvailable?: boolean;
        /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
        onConfirmed: () => void;
    }

    let {
        open = $bindable(false),
        passwordSet = false,
        availableProviders = [],
        canSatisfy = true,
        passkeyAvailable = false,
        onConfirmed,
    }: Props = $props();

    const passkeySupported = isPasskeySupported();
    let passkeySubmitting = $state(false);

    /**
     * **この端末で実行できる** satisfier があるか。
     * `canSatisfy` は「アカウントに手段があるか」(サーバ判定) であり、
     * パスキーしか無いユーザーが WebAuthn 非対応ブラウザで開くと
     * 「手段はあるが、この端末では実行できない」= 説明の無い行き止まりになる。
     * その状態を明示的に表現して回復導線を出す。
     */
    const executableHere = $derived(
        passwordSet || availableProviders.length > 0 || (passkeyAvailable && passkeySupported),
    );

    async function submitPasskey(): Promise<void> {
        if (passkeySubmitting) return;
        passkeySubmitting = true;
        error = "";
        try {
            const outcome = await confirmWithPasskey();
            if (outcome.status === "ok") {
                open = false;
                onConfirmed();
                return;
            }
            // キャンセルは失敗として騒がない (再試行導線を残す)
            if (outcome.status === "cancelled") return;
            error =
                outcome.status === "unsupported"
                    ? "このブラウザはパスキーに対応していません。"
                    : outcome.message;
        } finally {
            passkeySubmitting = false;
        }
    }

    let password = $state("");
    let error = $state("");
    let submitting = $state(false);

    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
    $effect(() => {
        if (!open) {
            password = "";
            error = "";
            submitting = false;
            passkeySubmitting = false;
        }
    });

    async function submitPassword(event: SubmitEvent): Promise<void> {
        event.preventDefault();
        if (submitting) return;
        if (password === "") {
            error = "パスワードを入力してください。";
            return;
        }
        submitting = true;
        error = "";
        try {
            const res = await fetch("/recent-auth/password", {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    Accept: "application/json",
                    "X-XSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
                body: JSON.stringify({ password }),
            });
            // 成功は 204 No Content (opaqueredirect 依存を排除)
            if (res.status === 204) {
                open = false;
                onConfirmed();
                return;
            }
            if (res.status === 422) {
                const body = (await res.json().catch(() => null)) as {
                    errors?: { password?: string[] };
                } | null;
                error = body?.errors?.password?.[0] ?? "パスワードが正しくありません。";
                return;
            }
            error = "再認証に失敗しました。時間をおいて再度お試しください。";
        } catch {
            error = "通信エラーが発生しました。";
        } finally {
            submitting = false;
        }
    }
</script>

<Modal bind:open title="本人確認" size="sm" processing={submitting} testId="recent-auth-modal">
    <div class="flex flex-col gap-4">
        <div class="flex items-start gap-2 text-caption text-text-secondary">
            <ShieldCheck class="mt-0.5 size-5 shrink-0 text-primary" aria-hidden="true" />
            <p>セキュリティのため、この操作を続けるにはもう一度本人確認が必要です。</p>
        </div>

        {#if passwordSet}
            <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
                <FormField label="現在のパスワード" id="recent-auth-password" error={error}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="password"
                            bind:value={password}
                            error={invalid}
                            aria-describedby={describedBy}
                            autocomplete="current-password"
                            testId="recent-auth-password-input"
                        />
                    {/snippet}
                </FormField>
                <Button type="submit" loading={submitting} fullWidth testId="recent-auth-submit">
                    確認する
                </Button>
            </form>
        {:else}
            <FormError message={error} testId="recent-auth-error" />
        {/if}

        {#if passkeyAvailable && passkeySupported}
            {#if passwordSet}
                <Divider label="または" />
            {/if}
            <Button
                variant="ghost"
                fullWidth
                loading={passkeySubmitting}
                onclick={() => void submitPasskey()}
                testId="recent-auth-passkey"
            >
                パスキーで再認証
            </Button>
        {/if}

        {#if availableProviders.length > 0}
            {#if passwordSet || (passkeyAvailable && passkeySupported)}
                <Divider label="または" />
            {/if}
            <div class="flex flex-col gap-2">
                {#each availableProviders as provider (provider.provider)}
                    <Button
                        href={provider.reauthUrl}
                        variant="ghost"
                        fullWidth
                        testId={`recent-auth-sso-${provider.provider}`}
                    >
                        {providerLabel(provider.provider)}で再認証
                    </Button>
                {/each}
            </div>
        {/if}

        {#if !canSatisfy}
            <div class="flex flex-col gap-2 text-caption text-text-secondary" data-testid="recent-auth-recovery">
                <p>この操作を続けるための再認証手段が設定されていません。</p>
                <Button href="/forgot-password" variant="ghost" fullWidth>
                    パスワードを設定して再認証する
                </Button>
            </div>
        {:else if !executableHere}
            <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
            <div
                class="flex flex-col gap-2 text-caption text-text-secondary"
                data-testid="recent-auth-unsupported-here"
            >
                <p>
                    このアカウントの再認証手段はパスキーのみですが、このブラウザはパスキーに対応していません。
                    パスキーを登録した端末・ブラウザで開き直すと再認証できます。
                </p>
            </div>
        {/if}
    </div>
    {#snippet footer()}
        <Button variant="ghost" type="button" onclick={() => (open = false)}>キャンセル</Button>
    {/snippet}
</Modal>
