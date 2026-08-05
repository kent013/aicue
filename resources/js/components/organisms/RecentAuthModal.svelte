<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { ShieldCheck } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import { csrfToken } from "@/lib/csrf";
    import { confirmWithPasskey, isPasskeySupported } from "@/lib/passkeys";
    import type { RecentAuthStatus } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前に出す
     * 「同一画面の再認証 (step-up) モーダル」。
     * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
     * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
     * - パスキー登録済み (passkeyAvailable): WebAuthn 検証 → POST /passkeys/confirm (204)。
     *   TOTP 有効ユーザーでも **再認証には使える** (PasskeyLoginPolicy が縛るのはログインのみ)。
     * - canSatisfy=false (再認証手段なし): 回復導線 (RecentAuthRecoveryNotice) を案内する。
     * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
     *
     * **契約: `/recent-auth/status` の応答 (RecentAuthStatus) を分解せず 1 個の型で受ける**。
     * field を prop に分解して手渡す形は、field が増えるたびに配線漏れを生む
     * (T106 で passkeyAvailable を足した際、6 呼び出し中 5 箇所が未配線のまま出荷され、
     *  passkey-only ユーザーが 5 画面で詰んだ)。呼び出し側が独自に status を組み立てないこと。
     * 強制は tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts
     * (deny-by-default)。pnpm typecheck は tsc --noEmit で .svelte テンプレートを
     * 型検査しないため、型宣言だけでは配線漏れを止められない。
     *
     * status === null は「状態不明」(呼び出し側の実装ミス)。空表示や事実に反する文言を
     * 出さず、取得失敗として明示し再読み込み導線を出す。
     */
    interface Props {
        open: boolean;
        /** /recent-auth/status の応答。null = 状態不明 (通常経路では発生しない) */
        status: RecentAuthStatus | null;
        /** satisfier 成功時。呼び出し側が pending action を再開する */
        onConfirmed: () => void;
    }

    let { open = $bindable(false), status, onConfirmed }: Props = $props();

    const passwordSet = $derived(status?.passwordSet ?? false);
    const availableProviders = $derived(status?.availableProviders ?? []);
    const canSatisfy = $derived(status?.canSatisfy ?? false);
    const passkeyAvailable = $derived(status?.passkeyAvailable ?? false);

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

    let password = $state("");
    /** FormField (フィールド起因のエラー) */
    let passwordError = $state("");
    /**
     * Alert (非フィールド起因の操作失敗)。DESIGN.md §Alert の規約どおり、WebAuthn ceremony の
     * 失敗を password フィールドのエラーとして出さない (状態を共有すると、パスキー失敗が
     * 「現在のパスワード」欄の赤字として現れる = 原因と提示先が食い違う)。
     */
    let passkeyError = $state("");
    let submitting = $state(false);

    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
    $effect(() => {
        if (!open) {
            password = "";
            passwordError = "";
            passkeyError = "";
            submitting = false;
            passkeySubmitting = false;
        }
    });

    async function submitPasskey(): Promise<void> {
        if (passkeySubmitting) return;
        passkeySubmitting = true;
        passkeyError = "";
        try {
            const outcome = await confirmWithPasskey();
            if (outcome.status === "ok") {
                open = false;
                onConfirmed();
                return;
            }
            // キャンセルは失敗として騒がない (再試行導線を残す)
            if (outcome.status === "cancelled") return;
            passkeyError =
                outcome.status === "unsupported"
                    ? "このブラウザはパスキーに対応していません。"
                    : outcome.message;
        } finally {
            passkeySubmitting = false;
        }
    }

    async function submitPassword(event: SubmitEvent): Promise<void> {
        event.preventDefault();
        if (submitting) return;
        if (password === "") {
            passwordError = "パスワードを入力してください。";
            return;
        }
        submitting = true;
        passwordError = "";
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
                passwordError = body?.errors?.password?.[0] ?? "パスワードが正しくありません。";
                return;
            }
            passwordError = "再認証に失敗しました。時間をおいて再度お試しください。";
        } catch {
            passwordError = "通信エラーが発生しました。";
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

        {#if status === null}
            <div
                class="flex flex-col gap-2 text-caption text-text-secondary"
                data-testid="recent-auth-unknown"
            >
                <p>再認証の状態を取得できませんでした。ページを再読み込みしてお試しください。</p>
                <Button variant="ghost" fullWidth onclick={() => router.reload()}>再読み込み</Button>
            </div>
        {:else}
            {#if passwordSet}
                <form novalidate onsubmit={submitPassword} class="flex flex-col gap-3">
                    <FormField label="現在のパスワード" id="recent-auth-password" error={passwordError}>
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
            {/if}

            {#if passkeyAvailable && passkeySupported}
                {#if passwordSet}
                    <Divider label="または" />
                {/if}
                {#if passkeyError}
                    <Alert type="danger" testId="recent-auth-passkey-error">{passkeyError}</Alert>
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
                <RecentAuthRecoveryNotice variant="no-satisfier" />
            {:else if !executableHere}
                <!-- アカウントには手段があるが、この端末では実行できない (パスキー非対応ブラウザ) -->
                <RecentAuthRecoveryNotice variant="not-executable-here" />
            {/if}
        {/if}
    </div>
    {#snippet footer()}
        <Button variant="ghost" type="button" onclick={() => (open = false)}>キャンセル</Button>
    {/snippet}
</Modal>
