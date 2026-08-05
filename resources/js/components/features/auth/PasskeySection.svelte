<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { KeyRound } from "@lucide/svelte";
    import { tick } from "svelte";
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

    /**
     * セキュリティ設定のパスキーカード。
     *
     * 契約:
     * - 登録 / 削除は **recent-auth 必須**。precheck は呼び出し側 (page) が持つ `guard` に委譲する
     *   (再認証モーダルはページに 1 つだけ置き、二重モーダルを作らない)。
     *   `guard` は precheck の結果を返す Promise であり、**precheck 区間も loading で覆う**
     *   (待ち時間中の連打で ceremony が多重起動し pending action が上書きされるのを塞ぐ)。
     * - 登録は ceremony (fetch) → **Inertia `router.post`** で送る (transport 契約)。
     *   成功 flash はサーバ (`back()->with('success')`) を単一の源とし client 楽観 toast を出さない。
     * - 削除は ConfirmDialog → `router.delete`。ログイン手段が 0 になる場合サーバは
     *   302 + `errors.login_method` を返すため、`loginMethodError` として受け取り明示表示する
     *   (**無言失敗にしない**)。
     * - **必須条件未充足でボタンを disabled にしない** (AGENTS.md 禁止事項 8)。
     *   非対応端末でも押せて、押下時にエラーを出す。
     * - **非フィールド起因の操作失敗は Alert** (DESIGN.md §Alert)。ceremony 失敗・端末非対応は
     *   押したその場に残る Alert に出す (Toast は画面外へ飛ぶ一時通知であり、押下直後に
     *   読ませたい失敗理由の提示先として使わない)。フィールド起因 (名前) だけが FormField。
     */
    interface Props {
        passkeys?: PasskeyListItem[];
        /** passkey での「ログイン」が許されるか (TOTP 有効時は false。再認証には使える) */
        passkeyLoginAvailable?: boolean;
        twoFactorEnabled?: boolean;
        /** EnsureLoginMethodRemains の拒否メッセージ ($page.props.errors.login_method) */
        loginMethodError?: string;
        /**
         * recent-auth precheck。fresh なら即実行、stale なら再認証モーダルを挟んで再開する。
         * 戻り値は実行した分岐 (precheck 区間を loading で覆うために待つ)。
         */
        guard: (action: () => void) => Promise<"fresh" | "stale" | "delegated">;
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

    /**
     * DESIGN.md §FormField: 押下時に出した client エラーは入力に追随させる
     * (stale invalid を残さない)。新規は「提示開始 boolean + $derived 文言」で書く。
     */
    let nameErrorShown = $state(false);
    /** サーバ由来 (422) のエラーは入力で消さない (DESIGN.md の例外規定) */
    let serverNameError = $state<string | null>(null);
    /** 非フィールド起因の操作失敗 (ceremony 失敗・端末非対応・登録 POST 失敗) */
    let operationError = $state("");

    const trimmedName = $derived(newPasskeyName.trim());
    const clientNameError = $derived(
        nameErrorShown && trimmedName === "" ? "パスキーの名前を入力してください。" : "",
    );
    const nameError = $derived(serverNameError ?? clientNameError);

    /** ceremony ～ POST 完了まで (削除側と同じ作法で onStart/onFinish が握る) */
    let registering = $state(false);
    /** precheck (/recent-auth/status) 実行中。ceremony/POST 中は registering が覆う */
    let prechecking = $state(false);
    const busy = $derived(prechecking || registering);

    /**
     * ceremony → POST。`registering` は ceremony 開始時に立て、
     * cancelled / unsupported / failed で終わったときだけ戻す
     * (`finally` で一律解除すると POST 完了前に解除され、連打で ceremony が多重に走る)。
     */
    async function startCeremonyAndPost(capturedName: string): Promise<void> {
        registering = true;

        // ceremony は outcome を返す契約だが、想定外の throw (ラッパの前提崩れ・拡張機能の割込み等)
        // でも loading を固定させない。**ボタンが押せないまま残ることが本施策で潰す詰みそのもの**。
        let outcome: Awaited<ReturnType<typeof createPasskeyCredential>>;
        try {
            outcome = await createPasskeyCredential();
        } catch {
            operationError = "パスキーの登録を開始できませんでした。時間をおいて再度お試しください。";
            registering = false;
            return;
        }

        if (outcome.status === "cancelled") {
            // キャンセルは失敗として騒がない (再試行導線を残す)
            registering = false;
            return;
        }
        if (outcome.status === "unsupported") {
            operationError = "このブラウザはパスキーに対応していません。";
            registering = false;
            return;
        }
        if (outcome.status === "failed") {
            operationError = outcome.message;
            registering = false;
            return;
        }

        router.post(
            "/user/passkeys",
            { name: capturedName, credential: outcome.value },
            {
                preserveScroll: true,
                onStart: () => {
                    registering = true;
                },
                onFinish: () => {
                    registering = false;
                },
                onSuccess: () => {
                    newPasskeyName = "";
                    nameErrorShown = false;
                },
                onError: (errors) => {
                    // フィールド起因は FormField へ、それ以外は Alert へ
                    const nameMessage = (errors as Record<string, unknown>).name;
                    serverNameError = typeof nameMessage === "string" ? nameMessage : null;
                    if (serverNameError === null) {
                        operationError =
                            "パスキーの登録に失敗しました。時間をおいて再度お試しください。";
                    }
                },
            },
        );
    }

    async function registerPasskey(): Promise<void> {
        if (busy) return;
        operationError = "";
        // 非対応端末でも押下できる (disabled にしない)。押した結果として理由を出す。
        if (!supported) {
            operationError =
                "このブラウザはパスキーに対応していません。パスワードまたはソーシャルログインをご利用ください。";
            return;
        }
        nameErrorShown = true;
        serverNameError = null;
        if (trimmedName === "") return; // 文言は $derived が出す

        // 押下時点の名前を確定させる (再認証モーダルを挟む間に入力欄が編集されても揺れない)
        const capturedName = trimmedName;

        prechecking = true;
        try {
            // fresh なら guard の中で action (ceremony → POST) が走り、registering が引き継ぐ。
            // stale / delegated ならモーダル側へ委譲されるので、ここで precheck を閉じてよい。
            await guard(() => void startCeremonyAndPost(capturedName));
        } finally {
            prechecking = false;
        }
    }

    /* ---- ログイン手段保持 guard の拒否 Alert にフォーカスを移す (見落とさせない) ----
       リカバリコード panel (Settings/Security) と同じ作法 (tabindex=-1 + bind:this + tick)。 */
    let loginMethodAlert = $state<HTMLDivElement | null>(null);
    let lastFocusedLoginMethodError = $state<string | undefined>(undefined);

    $effect(() => {
        const message = loginMethodError;
        if (message === undefined || message === lastFocusedLoginMethodError) return;
        lastFocusedLoginMethodError = message;
        void tick().then(() => loginMethodAlert?.focus());
    });

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
        void guard(() => {
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
            <div bind:this={loginMethodAlert} tabindex="-1">
                <Alert type="danger" title="削除できません" testId="passkey-login-method-error">
                    {loginMethodError}
                    このページの「ソーシャルログイン連携」から外部アカウントを連携するか、
                    下のフォームから別のパスキーを登録することもできます。
                    {#snippet action()}
                        <div class="flex flex-wrap gap-3">
                            <!--
                              遷移先 /settings は password 未設定ユーザーには「パスワードを設定」
                              フォームを出す (施策 7)。この Alert が出るのは「削除するとログイン手段が
                              0 になる」= password を持たないユーザーだけなので
                              (LoginMethodInventory の投影評価)、CTA は必ず踏破可能。
                            -->
                            <Button variant="ghost" href="/settings" testId="passkey-add-password">
                                パスワードを設定する
                            </Button>
                        </div>
                    {/snippet}
                </Alert>
            </div>
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
            {#if operationError}
                <Alert type="danger" testId="passkey-operation-error">{operationError}</Alert>
            {/if}
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
                <Button
                    onclick={() => void registerPasskey()}
                    loading={busy}
                    testId="register-passkey-button"
                >
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
