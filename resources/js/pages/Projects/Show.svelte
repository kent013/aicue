<script lang="ts">
    import { page, router, useForm } from "@inertiajs/svelte";
    import { FolderKanban } from "@lucide/svelte";
    import Badge from "@/components/atoms/Badge.svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import Checkbox from "@/components/atoms/Checkbox.svelte";
    import Input from "@/components/atoms/Input.svelte";
    import Select from "@/components/atoms/Select.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import Textarea from "@/components/atoms/Textarea.svelte";
    import DangerZone from "@/components/molecules/DangerZone.svelte";
    import EmptyState from "@/components/molecules/EmptyState.svelte";
    import FormField from "@/components/molecules/FormField.svelte";
    import PageHeaderSection from "@/components/molecules/PageHeaderSection.svelte";
    import Pagination from "@/components/molecules/Pagination.svelte";
    import ConfirmDialog from "@/components/organisms/ConfirmDialog.svelte";
    import Modal from "@/components/organisms/Modal.svelte";
    import AppLayout from "@/components/templates/AppLayout.svelte";
    import PageContainer from "@/components/templates/PageContainer.svelte";
    import PageContent from "@/components/templates/PageContent.svelte";
    import type { SharedProps } from "@/lib/shared-props";
    import type {
        CategoryOption,
        ManualFilters,
        ManualListItem,
        PaginationMeta,
    } from "@/types/manual";
    import { STATUS_TONES, VIDEO_MANUAL_STATUS_LABELS } from "@/types/manual";

    /**
     * プロジェクト詳細。動画マニュアル一覧 (フィルタ + paginate)・カテゴリ管理・
     * Item 一覧を内包する (それぞれ専用 Index ページは持たない)。
     */
    interface Item {
        id: number;
        name: string;
        note: string | null;
    }

    interface Member {
        id: number;
        name: string;
        /** canViewMemberEmails=false のときは常に null (キー常在契約) */
        email: string | null;
        /** ProjectRole の value。暗黙メンバー (org 管理継承) は null */
        role: string | null;
        /** true = org owner/admin の管理継承 (pivot なし = 削除/ロール変更不可) */
        implicit: boolean;
    }

    interface AssignableUser {
        id: number;
        name: string;
    }

    interface Props {
        project: { id: number; name: string; description: string | null };
        items: Item[];
        canManage: boolean;
        /** 管理メニュー > ユーザー管理への導線を出すか (org owner/admin。単一根拠は Gate) */
        canManageMembers: boolean;
        members: Member[];
        canViewMemberEmails: boolean;
        assignableUsers: AssignableUser[];
        manuals: { data: ManualListItem[]; meta: PaginationMeta };
        categories: CategoryOption[];
        manualFilters: ManualFilters;
    }

    let {
        project,
        items,
        canManage,
        canManageMembers,
        members,
        canViewMemberEmails,
        assignableUsers,
        manuals,
        categories,
        manualFilters,
    }: Props = $props();

    const shared = $derived(page.props as unknown as SharedProps);
    const appName = $derived(shared.appName ?? "");

    /* ---- 動画マニュアル: フィルタ (GET クエリで manuals のみ部分更新) ---- */
    let filterCategory = $state(manualFilters.category ?? "");
    let filterStatus = $state(manualFilters.status ?? "");
    let filterQ = $state(manualFilters.q ?? "");
    let filterSort = $state<string>(manualFilters.sort ?? "");
    let filterMine = $state(manualFilters.mine);

    // 並べ替え option (空値 = 既定「新しい順(作成)」)。ManualSortOption の allowlist と対
    const MANUAL_SORT_OPTIONS: { value: string; label: string }[] = [
        { value: "", label: "新しい順（作成）" },
        { value: "updated_desc", label: "更新が新しい順" },
        { value: "updated_asc", label: "更新が古い順" },
        { value: "title_asc", label: "タイトル昇順" },
        { value: "title_desc", label: "タイトル降順" },
    ];

    function manualQuery(pageNumber?: number): Record<string, string | number> {
        const query: Record<string, string | number> = {};
        if (filterCategory !== "") query.category = filterCategory;
        if (filterStatus !== "") query.status = filterStatus;
        if (filterQ.trim() !== "") query.q = filterQ.trim();
        if (filterSort !== "") query.sort = filterSort;
        if (filterMine) query.mine = 1;
        // pageNumber 未指定 (フィルタ変更時) は page を載せない = 1 ページ目にリセットする
        if (pageNumber !== undefined && pageNumber > 1) query.page = pageNumber;
        return query;
    }

    function applyManualFilters(event?: SubmitEvent): void {
        event?.preventDefault();
        router.get(`/projects/${project.id}`, manualQuery(), {
            preserveState: true,
            preserveScroll: true,
            only: ["manuals", "manualFilters"],
        });
    }

    function changeManualPage(pageNumber: number): void {
        router.get(`/projects/${project.id}`, manualQuery(pageNumber), {
            preserveState: true,
            preserveScroll: true,
            only: ["manuals", "manualFilters"],
        });
    }

    /* ---- プロジェクトメンバー管理 ---- */
    // ProjectRole の value → 日本語ラベル (追加/変更 select の option 定数。サーバ enum に対応)
    const PROJECT_ROLE_OPTIONS: { value: string; label: string }[] = [
        { value: "project_admin", label: "編集者" },
        { value: "project_member", label: "撮影者" },
    ];

    /* メンバー追加 (store。assignableUsers から選択) */
    const memberForm = useForm({ user_id: "", role: "project_member" });
    // client precheck 専用の transient error。serverErrors (memberForm.errors) とは分離。
    let addMemberClientError = $state<string | null>(null);

    // precheck 合格条件 = 候補が選択済み。エラー条件はこの否定。
    const isAddMemberSelected = $derived(memberForm.user_id !== "");

    // 選択が入った時点で client error を連動クリア (過剰クリア防止)。serverErrors は対象外 = 非退行。
    $effect(() => {
        if (addMemberClientError !== null && isAddMemberSelected) {
            addMemberClientError = null;
        }
    });

    function submitAddMember(event: SubmitEvent): void {
        event.preventDefault();
        if (memberForm.processing) return; // 二重送信ガード
        // 候補未選択なら押下時エラー (disabled にしない = 禁止事項 8)
        if (memberForm.user_id === "") {
            addMemberClientError = "追加するメンバーを選択してください。";
            return;
        }
        memberForm.post(`/projects/${project.id}/members`, {
            preserveScroll: true,
            onSuccess: () => {
                memberForm.reset();
                // reset で user_id が空へ戻るため、直前の client error も揃えて解消する
                addMemberClientError = null;
            },
        });
    }

    /* ロール変更 (store 再実行 = upsert。Admin/Users の 1 セレクト流儀) */
    // 二重送信ガードは handler 早期 return で行う (Select に disabled を付けない = 禁止事項 8 回避)
    let changingRoleId = $state<number | null>(null);

    function changeMemberRole(member: Member, role: string): void {
        if (role === "" || changingRoleId !== null) return; // 二重送信ガード (disabled に頼らない)
        changingRoleId = member.id;
        router.post(
            `/projects/${project.id}/members`,
            { user_id: member.id, role },
            {
                preserveScroll: true,
                // 保存失敗時は表示がサーバと乖離するため props を再取得して再同期する
                // (非 optimistic。field/flash error は Inertia の errors 経由で表示)
                onError: () => {
                    router.reload({ only: ["members", "assignableUsers"] });
                },
                onFinish: () => {
                    changingRoleId = null;
                },
            },
        );
    }

    /* メンバー削除 (destroy。ConfirmDialog) */
    let removeMemberTarget = $state<Member | null>(null);
    let removeMemberDialogOpen = $state(false);
    let removingMember = $state(false);

    function openRemoveMemberDialog(member: Member): void {
        removeMemberTarget = member;
        removeMemberDialogOpen = true;
    }

    function removeMember(): void {
        if (removeMemberTarget === null || removingMember) return;
        router.delete(`/projects/${project.id}/members/${removeMemberTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removingMember = true;
            },
            onFinish: () => {
                removingMember = false;
                removeMemberDialogOpen = false;
            },
        });
    }

    /* ---- Item 追加 ---- */
    const addForm = useForm({ name: "", note: "" });

    function submitAdd(event: SubmitEvent): void {
        event.preventDefault();
        addForm.post(`/projects/${project.id}/items`, {
            preserveScroll: true,
            onSuccess: () => {
                addForm.reset();
            },
        });
    }

    /* ---- Item 編集 (Modal) ---- */
    let editTarget = $state<Item | null>(null);
    let editModalOpen = $state(false);
    const editForm = useForm({ name: "", note: "" });

    function openEditModal(item: Item): void {
        editTarget = item;
        editForm.name = item.name;
        editForm.note = item.note ?? "";
        editForm.clearErrors();
        editModalOpen = true;
    }

    function submitEdit(event: SubmitEvent): void {
        event.preventDefault();
        if (editTarget === null) return;
        editForm.patch(`/projects/${project.id}/items/${editTarget.id}`, {
            preserveScroll: true,
            onSuccess: () => {
                editModalOpen = false;
            },
        });
    }

    /* ---- Item 削除 ---- */
    let removeTarget = $state<Item | null>(null);
    let removeDialogOpen = $state(false);
    let removing = $state(false);

    function openRemoveDialog(item: Item): void {
        removeTarget = item;
        removeDialogOpen = true;
    }

    function removeItem(): void {
        if (removeTarget === null) return;
        router.delete(`/projects/${project.id}/items/${removeTarget.id}`, {
            preserveScroll: true,
            onStart: () => {
                removing = true;
            },
            onFinish: () => {
                removing = false;
                removeDialogOpen = false;
            },
        });
    }

    /* ---- プロジェクト削除 ---- */
    let deleteProjectDialogOpen = $state(false);
    let deletingProject = $state(false);

    function deleteProject(): void {
        router.delete(`/projects/${project.id}`, {
            onStart: () => {
                deletingProject = true;
            },
            onFinish: () => {
                deletingProject = false;
                deleteProjectDialogOpen = false;
            },
        });
    }
</script>

<AppLayout {appName}>
    <PageContainer>
        <PageHeaderSection
            title={project.name}
            description={project.description ?? undefined}
            icon={FolderKanban}
            testId="project-show-heading"
        >
            {#if canManage}
                <Button
                    variant="ghost"
                    href={`/projects/${project.id}/edit`}
                    inertia
                    testId="edit-project-button"
                >
                    編集
                </Button>
                <!-- カテゴリ管理 (project-scoped)。CategoryPolicy::viewAny ≡ project update = canManage -->
                <Button
                    variant="ghost"
                    href={`/projects/${project.id}/categories`}
                    inertia
                    testId="manage-categories-link"
                >
                    カテゴリ管理
                </Button>
            {/if}
        </PageHeaderSection>
        <PageContent>
            <div class="mt-6 flex flex-col gap-10">
            <Card padding="lg">
                <div class="flex items-start justify-between gap-4">
                    <h2 class="text-h3">動画マニュアル</h2>
                    {#if canManage}
                        <Button
                            href={`/projects/${project.id}/manuals/create`}
                            inertia
                            testId="create-manual-button"
                        >
                            新規作成
                        </Button>
                    {/if}
                </div>

                <!-- フィルタ (カテゴリ / 状態 / キーワード)。GET クエリで manuals のみ部分更新する -->
                <form
                    novalidate
                    onsubmit={applyManualFilters}
                    class="mt-4 flex flex-wrap items-end gap-3"
                    data-testid="manual-filter-form"
                >
                    <div class="flex flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-category">
                            カテゴリ
                        </label>
                        <Select
                            id="manual-filter-category"
                            bind:value={filterCategory}
                            onchange={() => applyManualFilters()}
                            testId="manual-filter-category"
                        >
                            <option value="">すべて</option>
                            <option value="uncategorized">未分類</option>
                            {#each categories as category (category.id)}
                                <option value={String(category.id)}>{category.name}</option>
                            {/each}
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-status">
                            状態
                        </label>
                        <Select
                            id="manual-filter-status"
                            bind:value={filterStatus}
                            onchange={() => applyManualFilters()}
                            testId="manual-filter-status"
                        >
                            <option value="">すべて</option>
                            {#each Object.entries(VIDEO_MANUAL_STATUS_LABELS) as [value, label] (value)}
                                <option {value}>{label}</option>
                            {/each}
                        </Select>
                    </div>
                    <div class="flex flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-sort">
                            並べ替え
                        </label>
                        <Select
                            id="manual-filter-sort"
                            bind:value={filterSort}
                            onchange={() => applyManualFilters()}
                            testId="manual-filter-sort"
                        >
                            {#each MANUAL_SORT_OPTIONS as option (option.value)}
                                <option value={option.value}>{option.label}</option>
                            {/each}
                        </Select>
                    </div>
                    <div class="flex min-w-40 grow flex-col gap-1">
                        <label class="text-caption text-text-secondary" for="manual-filter-q">
                            キーワード
                        </label>
                        <Input
                            id="manual-filter-q"
                            type="search"
                            bind:value={filterQ}
                            testId="manual-filter-q"
                        />
                    </div>
                    <Checkbox
                        id="manual-filter-mine"
                        bind:checked={filterMine}
                        label="自分の作成分のみ"
                        onchange={() => applyManualFilters()}
                        testId="manual-filter-mine"
                    />
                    <Button type="submit" variant="ghost" testId="manual-filter-submit">検索</Button>
                </form>

                {#if manuals.data.length === 0}
                    <EmptyState
                        title="動画マニュアルはまだありません"
                        description="SOP を起点に動画マニュアルを作成すると、ここに表示されます。"
                        testId="manuals-empty"
                    />
                {:else}
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="manual-list">
                        {#each manuals.data as manual (manual.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <TextLink
                                        href={`/projects/${project.id}/manuals/${manual.id}`}
                                        testId={`manual-link-${manual.id}`}
                                    >
                                        {manual.title}
                                    </TextLink>
                                    <p class="mt-1 text-caption text-text-secondary">
                                        {manual.category?.name ?? "未分類"} ・ {manual.creator?.name ??
                                            "不明"} ・ 更新 {manual.updated_at}
                                    </p>
                                </div>
                                <Badge
                                    tone={STATUS_TONES[manual.status]}
                                    testId={`manual-status-${manual.id}`}
                                >
                                    {VIDEO_MANUAL_STATUS_LABELS[manual.status]}
                                </Badge>
                            </li>
                        {/each}
                    </ul>
                    <div class="mt-4">
                        <Pagination
                            currentPage={manuals.meta.current_page}
                            totalPages={manuals.meta.last_page}
                            onChange={changeManualPage}
                            testId="manuals-pagination"
                        />
                    </div>
                {/if}
            </Card>

            {#if canManage || canManageMembers}
                <Card padding="lg">
                    <h2 class="text-h3">管理メニュー</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        管理者向けの管理画面への導線です。権限のある項目のみ表示されます。
                    </p>
                    <ul class="mt-4 flex flex-col gap-2">
                        {#if canManage}
                            <li>
                                <TextLink
                                    href={`/projects/${project.id}/categories`}
                                    testId="link-manage-categories"
                                >
                                    カテゴリ管理
                                </TextLink>
                            </li>
                        {/if}
                        {#if canManageMembers}
                            <li>
                                <TextLink href="/manage/users" testId="link-manage-users">
                                    ユーザー管理
                                </TextLink>
                            </li>
                        {/if}
                    </ul>
                </Card>
            {/if}

            {#if canManage}
                <Card padding="lg">
                    <h2 class="text-h3">プロジェクトメンバー</h2>
                    <p class="mt-1 text-caption text-text-secondary">
                        編集者・撮影者を割り当てます。組織の管理者はプロジェクト未所属でも管理できます。
                    </p>

                    {#if members.length === 0}
                        <EmptyState
                            title="メンバーはまだいません"
                            description="組織メンバーをこのプロジェクトに割り当てると、ここに表示されます。"
                            testId="project-members-empty"
                        />
                    {:else}
                        <ul
                            class="mt-4 flex flex-col divide-y divide-border"
                            data-testid="project-member-list"
                        >
                            {#each members as member (member.id)}
                                <li
                                    class="flex items-center justify-between gap-4 py-3"
                                    data-testid={`project-member-${member.id}`}
                                >
                                    <div class="min-w-0">
                                        <p class="truncate text-body">{member.name}</p>
                                        {#if canViewMemberEmails && member.email}
                                            <p class="truncate text-caption text-text-secondary">
                                                {member.email}
                                            </p>
                                        {/if}
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        {#if member.implicit}
                                            <!-- 暗黙メンバー: org 管理継承。pivot なし = ロール変更/削除不可 -->
                                            <Badge
                                                tone="neutral"
                                                testId={`project-member-implicit-${member.id}`}
                                            >
                                                管理者（組織）
                                            </Badge>
                                        {:else}
                                            <!-- disabled は付けない (禁止事項 8)。二重送信は
                                                 changeMemberRole の handler 早期 return でガードする -->
                                            <Select
                                                value={member.role ?? ""}
                                                aria-label={`${member.name} のロール`}
                                                onchange={(event) =>
                                                    changeMemberRole(member, event.currentTarget.value)}
                                                testId={`project-member-role-${member.id}`}
                                            >
                                                {#each PROJECT_ROLE_OPTIONS as option (option.value)}
                                                    <option value={option.value}>{option.label}</option>
                                                {/each}
                                            </Select>
                                            <Button
                                                variant="danger-ghost"
                                                size="sm"
                                                onclick={() => openRemoveMemberDialog(member)}
                                                testId={`project-member-remove-${member.id}`}
                                            >
                                                削除
                                            </Button>
                                        {/if}
                                    </div>
                                </li>
                            {/each}
                        </ul>
                    {/if}

                    <!-- 追加フォーム -->
                    <form
                        novalidate
                        onsubmit={submitAddMember}
                        class="mt-6 flex flex-col gap-4"
                        data-testid="project-member-add-form"
                    >
                        {#if assignableUsers.length === 0}
                            <p
                                class="text-caption text-text-secondary"
                                data-testid="project-member-no-candidates"
                            >
                                アサインできる組織メンバーがいません。
                                {#if canManageMembers}
                                    <TextLink href="/manage/users">ユーザー管理</TextLink>から招待・確認できます。
                                {/if}
                            </p>
                        {/if}
                        <FormField
                            label="メンバー"
                            id="project-member-user"
                            error={addMemberClientError ?? memberForm.errors.user_id}
                        >
                            {#snippet children({ id, describedBy, invalid })}
                                <Select
                                    {id}
                                    bind:value={memberForm.user_id}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                >
                                    <option value="">選択してください</option>
                                    {#each assignableUsers as candidate (candidate.id)}
                                        <option value={String(candidate.id)}>{candidate.name}</option>
                                    {/each}
                                </Select>
                            {/snippet}
                        </FormField>
                        <FormField label="ロール" id="project-member-role" error={memberForm.errors.role}>
                            {#snippet children({ id, describedBy, invalid })}
                                <Select
                                    {id}
                                    bind:value={memberForm.role}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                >
                                    {#each PROJECT_ROLE_OPTIONS as option (option.value)}
                                        <option value={option.value}>{option.label}</option>
                                    {/each}
                                </Select>
                            {/snippet}
                        </FormField>
                        <div>
                            <Button
                                type="submit"
                                loading={memberForm.processing}
                                testId="project-member-submit"
                            >
                                メンバーを追加
                            </Button>
                        </div>
                    </form>
                </Card>
            {/if}

            <Card padding="lg">
                <h2 class="text-h3">アイテム</h2>
                {#if items.length === 0}
                    <EmptyState
                        title="アイテムはまだありません"
                        description="このプロジェクトにアイテムを追加すると、ここに表示されます。"
                        testId="items-empty"
                    />
                {:else}
                    <ul class="mt-4 flex flex-col divide-y divide-border" data-testid="item-list">
                        {#each items as item (item.id)}
                            <li class="flex items-center justify-between gap-4 py-3">
                                <div class="min-w-0">
                                    <p class="truncate text-body">{item.name}</p>
                                    {#if item.note}
                                        <p class="truncate text-caption text-text-secondary">
                                            {item.note}
                                        </p>
                                    {/if}
                                </div>
                                {#if canManage}
                                    <div class="flex shrink-0 items-center gap-2">
                                        <Button
                                            variant="ghost"
                                            size="sm"
                                            onclick={() => openEditModal(item)}
                                            testId={`edit-item-${item.id}`}
                                        >
                                            編集
                                        </Button>
                                        <Button
                                            variant="danger-ghost"
                                            size="sm"
                                            onclick={() => openRemoveDialog(item)}
                                            testId={`remove-item-${item.id}`}
                                        >
                                            削除
                                        </Button>
                                    </div>
                                {/if}
                            </li>
                        {/each}
                    </ul>
                {/if}
            </Card>

            {#if canManage}
                <Card padding="lg">
                    <h2 class="text-h3">アイテムを追加</h2>
                    <form novalidate onsubmit={submitAdd} class="mt-4 flex flex-col gap-4">
                        <FormField label="名前" id="item-name" error={addForm.errors.name} required>
                            {#snippet children({ id, describedBy, invalid })}
                                <Input
                                    {id}
                                    type="text"
                                    bind:value={addForm.name}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                />
                            {/snippet}
                        </FormField>
                        <FormField label="メモ" id="item-note" error={addForm.errors.note}>
                            {#snippet children({ id, describedBy, invalid })}
                                <Textarea
                                    {id}
                                    rows={2}
                                    bind:value={addForm.note}
                                    error={invalid}
                                    aria-describedby={describedBy}
                                />
                            {/snippet}
                        </FormField>
                        <div>
                            <Button type="submit" loading={addForm.processing} testId="item-submit">
                                追加
                            </Button>
                        </div>
                    </form>
                </Card>

                <DangerZone
                    title="プロジェクトの削除"
                    description="このプロジェクトと配下のすべてのアイテムを削除します。この操作は取り消せません。"
                >
                    <Button
                        variant="danger-outline"
                        onclick={() => (deleteProjectDialogOpen = true)}
                        testId="delete-project-button"
                    >
                        プロジェクトを削除
                    </Button>
                </DangerZone>
            {/if}
        </div>

        <Modal
            bind:open={editModalOpen}
            title="アイテムを編集"
            processing={editForm.processing}
            testId="edit-item-modal"
        >
            <form novalidate onsubmit={submitEdit} class="flex flex-col gap-4">
                <FormField label="名前" id="edit-item-name" error={editForm.errors.name} required>
                    {#snippet children({ id, describedBy, invalid })}
                        <Input
                            {id}
                            type="text"
                            bind:value={editForm.name}
                            error={invalid}
                            aria-describedby={describedBy}
                        />
                    {/snippet}
                </FormField>
                <FormField label="メモ" id="edit-item-note" error={editForm.errors.note}>
                    {#snippet children({ id, describedBy, invalid })}
                        <Textarea
                            {id}
                            rows={2}
                            bind:value={editForm.note}
                            error={invalid}
                            aria-describedby={describedBy}
                        />
                    {/snippet}
                </FormField>
                <div class="flex items-center justify-end gap-2">
                    <Button
                        variant="ghost"
                        onclick={() => (editModalOpen = false)}
                        disabled={editForm.processing}
                    >
                        キャンセル
                    </Button>
                    <Button type="submit" loading={editForm.processing} testId="edit-item-submit">
                        保存
                    </Button>
                </div>
            </form>
        </Modal>

        <ConfirmDialog
            bind:open={removeDialogOpen}
            title="アイテム削除"
            message={`「${removeTarget?.name ?? ""}」を削除しますか？ この操作は取り消せません。`}
            confirmLabel="削除する"
            confirmVariant="danger"
            processing={removing}
            onConfirm={removeItem}
            testId="remove-item-dialog"
        />

        <ConfirmDialog
            bind:open={removeMemberDialogOpen}
            title="メンバー削除"
            message={`「${removeMemberTarget?.name ?? ""}」をこのプロジェクトから外しますか？ 組織のメンバーシップは維持されます。`}
            confirmLabel="削除する"
            confirmVariant="danger"
            processing={removingMember}
            onConfirm={removeMember}
            testId="project-member-remove-dialog"
        />

        <ConfirmDialog
            bind:open={deleteProjectDialogOpen}
            title="プロジェクト削除"
            message={`プロジェクト「${project.name}」と配下のすべてのアイテムを削除しますか？ この操作は取り消せません。`}
            confirmLabel="削除する"
            confirmVariant="danger"
            processing={deletingProject}
            onConfirm={deleteProject}
            testId="delete-project-dialog"
        />
    </PageContent>
    </PageContainer>
</AppLayout>
