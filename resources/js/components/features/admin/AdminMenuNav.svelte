<script lang="ts">
    import { Tags, Users } from "@lucide/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";

    /**
     * 管理メニューのサイドナビ (doc/04 §4.2。モック PC_管理メニュー 02/10)。
     * URL は null 許容必須指定 (optional にしない = undefined 混入を型で拒否)。
     * null の項目は非表示 (can 連動をサーバが URL null で表現。撮影者には項目が出ない)。
     */
    interface Props {
        active: "users" | "categories";
        usersUrl: string | null;
        categoriesUrl: string | null;
    }

    let { active, usersUrl, categoriesUrl }: Props = $props();
</script>

<Card padding="lg">
    <h2 class="text-h3">管理メニュー</h2>
    <nav aria-label="管理メニュー" class="mt-4">
        <ul class="flex flex-col gap-3">
            {#if usersUrl !== null}
                <li class="flex items-center gap-2">
                    <Users class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    {#if active === "users"}
                        <span class="text-body font-medium" aria-current="page">ユーザー管理</span>
                    {:else}
                        <TextLink href={usersUrl} testId="admin-nav-users">ユーザー管理</TextLink>
                    {/if}
                </li>
            {/if}
            {#if categoriesUrl !== null}
                <li class="flex items-center gap-2">
                    <Tags class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
                    {#if active === "categories"}
                        <span class="text-body font-medium" aria-current="page">カテゴリ管理</span>
                    {:else}
                        <TextLink href={categoriesUrl} testId="admin-nav-categories">
                            カテゴリ管理
                        </TextLink>
                    {/if}
                </li>
            {/if}
        </ul>
    </nav>
</Card>
