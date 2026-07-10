<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import { useForm } from "@inertiajs/svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import { providerLabel } from "@/lib/social";
    import { addToast } from "@/lib/stores/toast";

    interface Props {
        socialProviders?: string[];
        linkedProviders?: string[];
    }

    let { socialProviders = [], linkedProviders = [] }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    const twoFactorEnabled = $derived(shared.auth?.user?.twoFactorEnabled ?? false);

    /* ----------------------------------------------------------------
     * 2FA 管理
     * 未有効 → 有効化開始 (POST) → QR + コード確認 (confirming)
     * → リカバリコード表示 → 有効。無効化は ConfirmDialog 経由。
     * 注: Fortify の password.confirm は撤去済み (generic recent-auth へ統一)。
     * 2FA エンドポイントへの recent-auth 配線はアプリ側の課題
     * (config/fortify.php の TODO(template) 参照)。
     * ---------------------------------------------------------------- */

    /** QR 確認待ち (有効化開始済みだが未確認) */
    let confirming = $state(false);
    let enabling = $state(false);
    let qrSvg = $state<string | null>(null);
    let recoveryCodes = $state<string[]>([]);
    let loadingRecoveryCodes = $state(false);

    const confirmForm = useForm({
        code: "",
    });

    async function fetchJson<T>(url: string): Promise<T> {
        const response = await fetch(url, {
            headers: { Accept: "application/json" },
        });
        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }
        return (await response.json()) as T;
    }

    async function loadQrCode(): Promise<void> {
        try {
            const data = await fetchJson<{ svg: string }>("/user/two-factor-qr-code");
            qrSvg = data.svg;
        } catch {
            addToast("error", "QR コードの取得に失敗しました。再読み込みしてください。");
        }
    }

    async function loadRecoveryCodes(): Promise<void> {
        loadingRecoveryCodes = true;
        try {
            recoveryCodes = await fetchJson<string[]>("/user/two-factor-recovery-codes");
        } catch {
            addToast("error", "リカバリコードの取得に失敗しました。");
        } finally {
            loadingRecoveryCodes = false;
        }
    }

    function enableTwoFactor(): void {
        router.post(
            "/user/two-factor-authentication",
            {},
            {
                preserveScroll: true,
                onStart: () => {
                    enabling = true;
                },
                onSuccess: () => {
                    confirming = true;
                    void loadQrCode();
                },
                onFinish: () => {
                    enabling = false;
                },
            },
        );
    }

    function confirmTwoFactor(event: SubmitEvent): void {
        event.preventDefault();
        confirmForm.post("/user/confirmed-two-factor-authentication", {
            preserveScroll: true,
            onSuccess: () => {
                confirming = false;
                qrSvg = null;
                confirmForm.reset();
                void loadRecoveryCodes();
            },
        });
    }

    let disableDialogOpen = $state(false);
    let disabling = $state(false);

    function disableTwoFactor(): void {
        router.delete("/user/two-factor-authentication", {
            preserveScroll: true,
            onStart: () => {
                disabling = true;
            },
            onSuccess: () => {
                disableDialogOpen = false;
                confirming = false;
                qrSvg = null;
                recoveryCodes = [];
            },
            onFinish: () => {
                disabling = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <h1 class="text-h2">設定</h1>

    <nav aria-label="設定メニュー" class="mt-4 flex gap-4 border-b border-border pb-2">
        <TextLink href="/settings">プロフィール</TextLink>
        <TextLink href="/settings/security">セキュリティ</TextLink>
    </nav>

    <div class="mt-6 flex max-w-2xl flex-col gap-10">
        <Card padding="lg">
            <div class="flex items-center justify-between gap-4">
                <h2 class="text-h3">2要素認証</h2>
                {#if twoFactorEnabled}
                    <Badge tone="success">有効</Badge>
                {:else}
                    <Badge tone="neutral">無効</Badge>
                {/if}
            </div>
            <p class="mt-1 text-caption text-text-secondary">
                認証アプリのワンタイムコードでログインを保護します。
            </p>

            {#if twoFactorEnabled}
                <div class="mt-4 flex flex-col gap-4">
                    {#if recoveryCodes.length > 0}
                        <div class="rounded-md border border-border bg-neutral p-4">
                            <p class="text-caption text-text-secondary">
                                リカバリコードは安全な場所に保管してください。各コードは一度だけ使えます。
                            </p>
                            <ul
                                class="mt-2 grid grid-cols-2 gap-1 text-body font-mono"
                                data-testid="recovery-codes"
                            >
                                {#each recoveryCodes as code (code)}
                                    <li>{code}</li>
                                {/each}
                            </ul>
                        </div>
                    {:else}
                        <div>
                            <Button
                                variant="ghost"
                                onclick={() => void loadRecoveryCodes()}
                                loading={loadingRecoveryCodes}
                            >
                                リカバリコードを表示
                            </Button>
                        </div>
                    {/if}
                    <div>
                        <Button
                            variant="danger-outline"
                            onclick={() => {
                                disableDialogOpen = true;
                            }}
                            testId="disable-two-factor-button"
                        >
                            2要素認証を無効化
                        </Button>
                    </div>
                </div>
            {:else if confirming}
                <div class="mt-4 flex flex-col gap-4">
                    <p class="text-body text-text-secondary">
                        認証アプリで以下の QR コードを読み取り、表示されたコードを入力して設定を完了してください。
                    </p>
                    {#if qrSvg}
                        <!-- QR はサーバ提供の SVG をそのまま描画する -->
                        <div class="self-start rounded-md border border-border bg-surface p-4">
                            <!-- eslint-disable-next-line svelte/no-at-html-tags -->
                            {@html qrSvg}
                        </div>
                    {/if}
                    <form onsubmit={confirmTwoFactor} class="flex flex-col gap-4">
                        <FormField
                            label="認証コード"
                            id="two-factor-code"
                            error={confirmForm.errors.code}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="text"
                                    inputmode="numeric"
                                    bind:value={confirmForm.code}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                    autocomplete="one-time-code"
                                />
                            {/snippet}
                        </FormField>
                        <div>
                            <Button type="submit" loading={confirmForm.processing}>
                                確認して有効化
                            </Button>
                        </div>
                    </form>
                </div>
            {:else}
                <div class="mt-4">
                    <Button
                        onclick={enableTwoFactor}
                        loading={enabling}
                        testId="enable-two-factor-button"
                    >
                        有効化
                    </Button>
                </div>
            {/if}
        </Card>

        <Card padding="lg">
            <h2 class="text-h3">ソーシャルログイン連携</h2>
            <p class="mt-1 text-caption text-text-secondary">
                外部アカウントを連携すると、そのアカウントでもログインできます。
            </p>
            <ul class="mt-4 flex flex-col gap-3">
                {#each socialProviders as provider (provider)}
                    <li
                        class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
                    >
                        <span class="text-body">{providerLabel(provider)}</span>
                        {#if linkedProviders.includes(provider)}
                            <Badge tone="success" testId={`linked-${provider}`}>連携済み</Badge>
                        {:else}
                            <Button
                                variant="ghost"
                                size="sm"
                                href={`/auth/${provider}/redirect/link`}
                                testId={`link-${provider}`}
                            >
                                連携する
                            </Button>
                        {/if}
                    </li>
                {/each}
            </ul>
        </Card>
    </div>

    <ConfirmDialog
        bind:open={disableDialogOpen}
        title="2要素認証の無効化"
        message="2要素認証を無効化しますか？ リカバリコードも無効になります。"
        confirmLabel="無効化する"
        confirmVariant="danger"
        processing={disabling}
        onConfirm={disableTwoFactor}
        testId="disable-two-factor-dialog"
    />
</AppLayout>
