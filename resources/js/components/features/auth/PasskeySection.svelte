<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { KeyRound } from "@lucide/svelte";
    import Alert from "@/components/atoms/Alert.svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import {
        canCreatePasskey,
        createPasskeyCredential,
        isPasskeySupported,
        type PasskeyListItem,
    } from "@/lib/passkeys";
    import { addToast } from "@/lib/stores/toast";

    /**
     * セキュリティ設定のパスキーカード。
     *
     * 契約:
     * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
     *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
     * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
     *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
     * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
     *   302 + `errors.login_method` を返すため、`loginMethodError` として受け取り明示表示する
     *   (**無言失敗にしない**)。
     * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
     *   非対応端末でも押せて、押下時にエラーを出す。
     */
    interface Props {
        passkeys?: PasskeyListItem[];
        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
        passkeyLoginAvailable?: boolean;
        twoFactorEnabled?: boolean;
        /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
        loginMethodError?: string;
        /** recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する */
        guard: (action: () => void) => void;
    }

    let {
        passkeys = [],
        passkeyLoginAvailable = false,
        twoFactorEnabled = false,
        loginMethodError,
        guard,
    }: Props = $props();

    const supported = isPasskeySupported();
    let creatable = $state(false);
    void (async () => {
        creatable = await canCreatePasskey();
    })();

    let newPasskeyName = $state("");
    let nameError = $state("");
    let registering = $state(false);

    function registerPasskey(): void {
        if (registering) return;
        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
        if (!supported) {
            addToast(
                "error",
                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。",
            );
            return;
        }
        const name = newPasskeyName.trim();
        if (name === "") {
            nameError = "パスキーの名前を入力してください。";
            return;
        }
        nameError = "";

        guard(() => {
            void (async () => {
                registering = true;
                try {
                    const outcome = await createPasskeyCredential();
                    if (outcome.status === "cancelled") return;
                    if (outcome.status === "unsupported") {
                        addToast("error", "このブラウザはパスキーに対応していません。");
                        return;
                    }
                    if (outcome.status === "failed") {
                        addToast("error", outcome.message);
                        return;
                    }
                    router.post(
                        "/user/passkeys",
                        { name, credential: outcome.value },
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                newPasskeyName = "";
                            },
                            onError: () => {
                                addToast("error", "パスキーの登録に失敗しました。");
                            },
                        },
                    );
                } finally {
                    registering = false;
                }
            })();
        });
    }

    let deleteTarget = $state<PasskeyListItem | null>(null);
    let deleteDialogOpen = $state(false);
    let deleting = $state(false);

    function requestDelete(passkey: PasskeyListItem): void {
        deleteTarget = passkey;
        deleteDialogOpen = true;
    }

    function confirmDelete(): void {
        const target = deleteTarget;
        if (target === null) return;
        guard(() => {
            router.delete(`/user/passkeys/${target.id}`, {
                preserveScroll: true,
                onStart: () => {
                    deleting = true;
                },
                onFinish: () => {
                    deleting = false;
                    deleteDialogOpen = false;
                    deleteTarget = null;
                },
            });
        });
    }

    function formatDate(value: string | null): string {
        if (value === null) return "未使用";
        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? "不明" : parsed.toLocaleDateString("ja-JP");
    }
</script>

<Card padding="lg">
    <div class="flex items-center justify-between gap-4">
        <h2 class="text-h3">パスキー</h2>
        {#if passkeys.length > 0}
            <Badge tone="success" testId="passkey-count">{passkeys.length} 件登録済み</Badge>
        {:else}
            <Badge tone="neutral" testId="passkey-count">未登録</Badge>
        {/if}
    </div>
    <p class="mt-1 text-caption text-text-secondary">
        指紋・顔認証・端末のロック解除でログインできます。
    </p>

    <div class="mt-4 flex flex-col gap-4">
        {#if loginMethodError}
            <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
                {loginMethodError}
                {#snippet action()}
                    <div class="flex flex-wrap gap-3">
                        <Button variant="ghost" href="/settings" testId="passkey-add-password">
                            パスワードを設定する
                        </Button>
                    </div>
                {/snippet}
            </Alert>
        {/if}

        {#if !passkeyLoginAvailable && twoFactorEnabled}
            <!-- 誤認させない: 2FA 有効時はログインには使えないが再認証には使える -->
            <Alert type="info" testId="passkey-2fa-notice">
                2要素認証を有効にしているため、パスキーでのログインはできません。この画面での再認証にはご利用いただけます。
            </Alert>
        {/if}

        {#if !supported}
            <Alert type="warning" testId="passkey-unsupported">
                このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。
            </Alert>
        {:else if !creatable}
            <Alert type="warning" testId="passkey-not-creatable">
                この端末ではパスキーを作成できません。画面ロック（生体認証・PIN）を設定すると利用できます。
            </Alert>
        {/if}

        {#if passkeys.length > 0}
            <ul class="flex flex-col gap-3" data-testid="passkey-list">
                {#each passkeys as passkey (passkey.id)}
                    <li
                        class="flex items-center justify-between gap-4 rounded-md border border-border p-3"
                    >
                        <div class="flex min-w-0 items-center gap-3">
                            <KeyRound class="size-5 shrink-0 text-primary" aria-hidden="true" />
                            <div class="min-w-0">
                                <p class="truncate text-body">{passkey.name}</p>
                                <p class="text-caption text-text-secondary">
                                    {passkey.authenticator ?? "認証器不明"} ・ 最終利用 {formatDate(
                                        passkey.lastUsedAt,
                                    )}
                                </p>
                            </div>
                        </div>
                        <Button
                            variant="danger-ghost"
                            size="sm"
                            onclick={() => requestDelete(passkey)}
                            testId={`delete-passkey-${passkey.id}`}
                        >
                            削除
                        </Button>
                    </li>
                {/each}
            </ul>
        {/if}

        <div class="flex flex-col gap-3">
            <FormField label="パスキーの名前" id="passkey-name" error={nameError}>
                {#snippet children({ id, describedBy, invalid })}
                    <Input
                        {id}
                        type="text"
                        bind:value={newPasskeyName}
                        error={invalid}
                        aria-describedby={describedBy}
                        placeholder="例: 現場用スマホ"
                        testId="passkey-name-input"
                    />
                {/snippet}
            </FormField>
            <div>
                <Button onclick={registerPasskey} loading={registering} testId="register-passkey-button">
                    パスキーを登録
                </Button>
            </div>
        </div>
    </div>
</Card>

<ConfirmDialog
    bind:open={deleteDialogOpen}
    title="パスキーの削除"
    message={`パスキー「${deleteTarget?.name ?? ""}」を削除しますか？ この端末からはパスキーでログインできなくなります。`}
    confirmLabel="削除する"
    confirmVariant="danger"
    processing={deleting}
    onConfirm={confirmDelete}
    onCancel={() => {
        deleteTarget = null;
    }}
    testId="delete-passkey-dialog"
/>
