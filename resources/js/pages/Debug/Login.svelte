<script lang="ts">
    import { router } from "@inertiajs/svelte";
    import { ChevronRight } from "@lucide/svelte";
    import Input from "@/components/atoms/Input.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import AuthLayout from "@/components/templates/AuthLayout.svelte";

    /**
     * local 専用デバッグログイン。ユーザー一覧からワンクリックで
     * 任意ユーザーとしてログインする (LocalOnly middleware の背後でのみ表示される)。
     */
    interface DebugUser {
        id: number;
        name: string;
        email: string;
    }

    interface Props {
        appName?: string;
        users: DebugUser[];
    }

    let { appName, users }: Props = $props();

    let filter = $state("");
    let submitting = $state(false);

    const filtered = $derived(
        users.filter((user) => {
            const query = filter.trim().toLowerCase();
            if (query === "") return true;
            return (
                user.name.toLowerCase().includes(query) ||
                user.email.toLowerCase().includes(query)
            );
        }),
    );

    function loginAs(userId: number): void {
        if (submitting) return;
        router.post(
            `/debug/login/${userId}`,
            {},
            {
                onStart: () => {
                    submitting = true;
                },
                onFinish: () => {
                    submitting = false;
                },
            },
        );
    }
</script>

<AuthLayout title="Debug Login" {appName}>
    <p class="mb-4 text-caption font-medium text-danger">LOCAL 環境専用 — 本番では無効です</p>

    <Input
        id="debug-filter"
        type="search"
        bind:value={filter}
        placeholder="名前・メール・組織で絞り込み"
        class="mb-4"
        testId="debug-login-filter"
    />

    {#if users.length === 0}
        <p class="text-center text-caption text-text-secondary">ユーザーが存在しません。seeder 等でユーザーを作成してください。</p>
    {:else if filtered.length === 0}
        <p class="text-center text-caption text-text-secondary">該当するユーザーが見つかりません。</p>
    {:else}
        <ul class="max-h-[60vh] space-y-2 overflow-y-auto">
            {#each filtered as user (user.id)}
                <li>
                    <button
                        type="button"
                        onclick={() => loginAs(user.id)}
                        disabled={submitting}
                        class="flex w-full cursor-pointer items-center justify-between rounded-md border border-border bg-surface p-4 text-left transition-colors hover:bg-primary-soft disabled:cursor-not-allowed disabled:opacity-50"
                        data-testid={`debug-login-user-${user.id}`}
                    >
                        <span class="min-w-0 flex-1">
                            <span class="block truncate font-medium text-text">{user.name}</span>
                            <span class="block truncate text-caption text-text-secondary">{user.email}</span>
                        </span>
                        <span class="ml-3 flex shrink-0 items-center gap-2 text-caption text-text-secondary">
                            ID: {user.id}
                            <ChevronRight class="size-4" />
                        </span>
                    </button>
                </li>
            {/each}
        </ul>
    {/if}

    {#snippet footer()}
        <TextLink href="/login">通常のログインページへ</TextLink>
    {/snippet}
</AuthLayout>
