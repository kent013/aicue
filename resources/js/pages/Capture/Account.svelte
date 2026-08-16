<script lang="ts">
    import { page, router } from "@inertiajs/svelte";
    import { ArrowLeft, LogOut, UserRound } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import PageHeader from "@/components/molecules/PageHeader.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    /**
     * 撮影 PWA のアカウント確認画面 (doc/05 §5.1 / §5.2)。
     * 表示名・ログイン ID (= メールアドレス)・所属組織を**省略なく**読み、ログアウトする。
     *
     * ページ固有 props は持たない。表示値はすべて HandleInertiaRequests の共有 props。
     * **auth.user.id は描画に使わない** — 内部 DB の主キーであり利用者にとって意味を持たない。
     * doc の言う「ユーザー ID」の実体はログイン ID = メールアドレスである。
     *
     * 変更操作 (表示名・メール・パスワード・2FA・退会) は一切持たない。それらは /settings の
     * 責務で、**この画面からリンクもしない** — /app へ戻る導線を持たない面への入口を
     * 新設しないため (概念設計 G3)。
     */
    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");
    // auth / currentOrganization はサーバ側の到達条件 (auth middleware /
    // resolveMemberCurrentOrganization) で非 null が保証されるが、型は nullable なので
    // 防御的に扱う。**偽の既定値は作らない** — 無ければその行を出さない。
    const user = $derived(shared.auth?.user ?? null);
    const organizationName = $derived(shared.currentOrganization?.name ?? null);

    // 送信中の状態 (必須条件未充足の disabled ではない。AGENTS.md 禁止事項 8)。
    // Button atom の `loading` に渡すと disabled={disabled || loading} で押下不可になる。
    let loggingOut = $state(false);

    /**
     * ログアウト。**Inertia visit (router.post) 一本**である必要がある
     * (AGENTS.md ドメイン規約 3 経路 C: clearHistory を含む Inertia page の適用が保証条件)。
     * このファイルに fetch / axios を書かないこと
     * (tests/js/architecture/logout-call-site-inventory.test.ts が機械で固定する)。
     *
     * `if (loggingOut) return;` は**多重防御**である。Button atom が loading 中は
     * disabled になるため DOM 経由ではここに再入しない (= テストで固定しない。
     * 到達不能な経路のテストを作らない)。プログラム的な再呼び出しに対する保険として残す。
     */
    function logout(): void {
        if (loggingOut) return;
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

<AppLayout {appName}>
    <PageContainer>
        <PageHeader title="アカウント" icon={UserRound} testId="capture-account-heading" />
        <PageContent>
            <div class="mb-4">
                <TextLink href="/app" testId="capture-account-back">
                    <ArrowLeft class="inline size-3" aria-hidden="true" />
                    撮影に戻る
                </TextLink>
            </div>

            {#if user}
                <Card>
                    <dl class="flex flex-col gap-4">
                        <div>
                            <dt class="text-caption text-text-secondary">表示名</dt>
                            <dd class="mt-1 text-body text-text" data-testid="capture-account-name">
                                {user.name}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-caption text-text-secondary">
                                ログイン ID (メールアドレス)
                            </dt>
                            <!-- 識別子は省略しない: truncate を使わず break-all で折り返す
                                 (親幅を超えて横スクロールを作らない)。DESIGN.md の役割マッピングに
                                 従い主要な識別値なので text-body (text-caption ではない)。 -->
                            <dd
                                class="mt-1 text-body break-all text-text"
                                data-testid="capture-account-email"
                            >
                                {user.email}
                            </dd>
                        </div>
                        {#if organizationName}
                            <div>
                                <dt class="text-caption text-text-secondary">所属組織</dt>
                                <dd
                                    class="mt-1 text-body break-all text-text"
                                    data-testid="capture-account-organization"
                                >
                                    {organizationName}
                                </dd>
                            </div>
                        {/if}
                    </dl>
                </Card>

                <p class="mt-3 text-caption text-text-secondary">
                    表示名・メールアドレスの変更は PC の個人設定から行います。
                </p>

                <div class="mt-6">
                    <Button
                        variant="danger-outline"
                        fullWidth
                        loading={loggingOut}
                        onclick={logout}
                        testId="capture-account-logout"
                    >
                        <LogOut class="size-4" aria-hidden="true" />
                        ログアウト
                    </Button>
                </div>
            {/if}
        </PageContent>
    </PageContainer>
</AppLayout>
