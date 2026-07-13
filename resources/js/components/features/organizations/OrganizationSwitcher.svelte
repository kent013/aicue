<script lang="ts">
    import { Link, router } from "@inertiajs/svelte";
    import {
        Check,
        ChevronsUpDown,
        CreditCard,
        KeyRound,
        Plus,
        Settings,
        Tag,
        Users,
    } from "@lucide/svelte";
    import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";

    /**
     * 組織スイッチャー兼組織メニュー (disclosure パターン)。
     * 現在組織を表示するトリガー + 展開パネルで「組織切替」と「組織設定/メンバー/API キー/
     * 請求/料金」への恒常導線を提供する (North Star: 組織横断運用の到達導線を回復)。
     *
     * 純粋・テスト容易にするため shared prop は親 (AppLayout) が読んで props で渡す。
     * cross-org 防御は backend (currentOrganizationProp の isMemberOf + Policy 評価) が担い、
     * ここは受け取った権限フラグでリンクを出し分けるだけ (二重判定しない)。
     *
     * a11y: disclosure セマンティクス (aria-expanded/aria-controls。role=menu は付けない)。
     * Escape / outside pointerdown / focusout の 3 経路で閉じる。禁止事項 8 に従い現在組織項目や
     * リンクを disabled にしない (現在組織は非対話行、他は遷移)。
     *
     * 設計との差分 (意図的): 詳細設計 S3 は「内部は atoms(Button) を合成」と記したが、トリガー/
     * 切替行は Button atom の variant スタイル (枠線・padding ramp) と噛み合わない menu-item 表現が
     * 必要なため native <button> を採用する。Button atom は単機能ボタン用で、id/aria-expanded/
     * aria-controls を要する disclosure トリガーには過剰。DS token は同一 (rounded-md/border-border/
     * bg-surface/text-body)。Lucide のみ・SVG 直書きなしは維持する。
     */
    interface Props {
        /** 現在の組織 (未所属/未設定時は null → 「組織を作成」フォールバック) */
        currentOrganization: CurrentOrganization | null;
        /** 所属組織一覧 (切替候補。id で switch) */
        organizations: OrganizationSummary[];
    }

    let { currentOrganization, organizations }: Props = $props();

    let open = $state(false);
    let root = $state<HTMLDivElement | null>(null);
    let trigger = $state<HTMLButtonElement | null>(null);

    const triggerLabel = $derived(currentOrganization?.name ?? "組織を選択");
    // 切替候補セクションは 2 組織以上のときのみ (1 組織なら切替不要)
    const showSwitchSection = $derived(organizations.length > 1);

    function close(): void {
        open = false;
    }

    function toggle(): void {
        open = !open;
    }

    function switchTo(id: number): void {
        // Ziggy 未導入のため文字列パス直書きが既存標準 (cf. Admin/Users.svelte)。
        router.post(`/organizations/${id}/switch`);
        close();
    }

    // focusout: Tab 等でルート外へ focus が抜けたら閉じる (focus 系は静的要素でも a11y 上許容)
    function onFocusOut(event: FocusEvent): void {
        const next = event.relatedTarget;
        if (next instanceof Node && root?.contains(next)) {
            return;
        }
        close();
    }

    // open の間だけ document へ pointerdown / keydown を張り、outside クリックと Escape で閉じる。
    // keydown を静的な wrapper div に載せると a11y_no_static_element_interactions になるため
    // document スコープに寄せる (disclosure の open 中のみ有効化)。
    $effect(() => {
        if (!open) {
            return;
        }
        function onPointerDown(event: PointerEvent): void {
            const target = event.target;
            if (target instanceof Node && root?.contains(target)) {
                return;
            }
            close();
        }
        function onKeydown(event: KeyboardEvent): void {
            if (event.key === "Escape") {
                close();
                trigger?.focus();
            }
        }
        document.addEventListener("pointerdown", onPointerDown);
        document.addEventListener("keydown", onKeydown);
        return () => {
            document.removeEventListener("pointerdown", onPointerDown);
            document.removeEventListener("keydown", onKeydown);
        };
    });
</script>

<div class="relative shrink-0" bind:this={root} onfocusout={onFocusOut}>
    <button
        type="button"
        id="org-switcher-trigger"
        class="inline-flex shrink-0 items-center gap-2 rounded-md border border-border
            bg-surface px-3 py-1.5 text-body text-text hover:bg-neutral"
        aria-expanded={open}
        aria-controls="org-switcher-panel"
        onclick={toggle}
        bind:this={trigger}
        data-testid="org-switcher-trigger"
    >
        <span class="max-w-40 truncate">{triggerLabel}</span>
        <ChevronsUpDown class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
    </button>

    {#if open}
        <div
            id="org-switcher-panel"
            class="absolute right-0 z-20 mt-1 w-64 rounded-md border border-border bg-surface py-1"
            aria-labelledby="org-switcher-trigger"
        >
            {#if currentOrganization != null}
                {#if showSwitchSection}
                    <p class="px-3 py-1 text-caption text-text-secondary">組織を切り替え</p>
                    {#each organizations as org (org.id)}
                        {#if org.id === currentOrganization.id}
                            <div
                                class="flex items-center gap-2 px-3 py-2 text-body text-text"
                                aria-current="true"
                                data-testid="org-current-{org.id}"
                            >
                                <Check
                                    class="size-4 shrink-0 text-primary"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
                                {#if org.isPersonal}
                                    <span class="text-caption text-text-secondary">個人</span>
                                {/if}
                                <span class="text-caption text-text-secondary">現在の組織</span>
                            </div>
                        {:else}
                            <button
                                type="button"
                                class="flex w-full items-center gap-2 px-3 py-2 text-left
                                    text-body text-text hover:bg-neutral"
                                onclick={() => switchTo(org.id)}
                                data-testid="org-switch-{org.id}"
                            >
                                <span class="size-4 shrink-0" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate">{org.name}</span>
                                {#if org.isPersonal}
                                    <span class="text-caption text-text-secondary">個人</span>
                                {/if}
                            </button>
                        {/if}
                    {/each}
                    <div class="my-1 border-t border-border" role="separator"></div>
                {/if}

                <Link
                    href={`/organizations/${currentOrganization.slug}/settings`}
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-settings"
                >
                    <Settings class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    組織設定
                </Link>
                {#if currentOrganization.canManageMembers}
                    <Link
                        href="/manage/users"
                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                        onclick={close}
                        data-testid="org-link-members"
                    >
                        <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                        メンバー管理
                    </Link>
                {/if}
                {#if currentOrganization.canManageApiKeys}
                    <Link
                        href={`/organizations/${currentOrganization.slug}/api-keys`}
                        class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                        onclick={close}
                        data-testid="org-link-api-keys"
                    >
                        <KeyRound class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                        API キー
                    </Link>
                {/if}
                <Link
                    href="/billing"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-billing"
                >
                    <CreditCard class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    請求
                </Link>
                <Link
                    href="/pricing"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-pricing"
                >
                    <Tag class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    料金
                </Link>
            {:else}
                <Link
                    href="/organizations/create"
                    class="flex items-center gap-2 px-3 py-2 text-body text-text hover:bg-neutral"
                    onclick={close}
                    data-testid="org-link-create"
                >
                    <Plus class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    組織を作成
                </Link>
            {/if}
        </div>
    {/if}
</div>
