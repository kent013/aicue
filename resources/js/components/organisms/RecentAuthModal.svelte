<script lang="ts">
    import { ShieldCheck } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import FormError from "@/components/atoms/FormError.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Divider from "@/components/molecules/Divider.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import type { AvailableReauthProvider } from "@/lib/recent-auth";
    import { providerLabel } from "@/lib/social";

    /**
     * 機微操作 (API キー発行/失効・アカウント削除・オーナー移譲) の前に出す
     * 「同一画面の再認証 (step-up) モーダル」。
     * - password 設定済みユーザー: パスワード再入力 → POST /recent-auth/password (XHR=204 成功)。
     * - 再SSO 可能な provider (availableProviders): reauthUrl へフルリダイレクト。
     * - canSatisfy=false (再認証手段なし): 回復導線 (パスワードリセット) を案内する。
     * 認可の最終ゲートは各操作の recent-auth middleware (本モーダルは UX 補助)。
     */
    interface Props {
        open: boolean;
        passwordSet?: boolean;
        availableProviders?: AvailableReauthProvider[];
        canSatisfy?: boolean;
        /** password satisfier 成功時 (204)。呼び出し側が pending action を再開する */
        onConfirmed: () => void;
    }

    let {
        open = $bindable(false),
        passwordSet = false,
        availableProviders = [],
        canSatisfy = true,
        onConfirmed,
    }: Props = $props();

    let password = $state("");
    let error = $state("");
    let submitting = $state(false);

    // モーダルを閉じたら入力状態をリセットする (次回表示への持ち越し防止)
    $effect(() => {
        if (!open) {
            password = "";
            error = "";
            submitting = false;
        }
    });

    /** Laravel が発行する XSRF-TOKEN cookie (encrypted cookie 対応の URL エンコード済み値) */
    function csrfToken(): string {
        const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
        return match ? decodeURIComponent(match[1]) : "";
    }

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
            <form onsubmit={submitPassword} class="flex flex-col gap-3">
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

        {#if availableProviders.length > 0}
            {#if passwordSet}
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
        {/if}
    </div>
    {#snippet footer()}
        <Button variant="ghost" type="button" onclick={() => (open = false)}>キャンセル</Button>
    {/snippet}
</Modal>
