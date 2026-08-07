<script lang="ts">
    import { CircleAlert } from "@lucide/svelte";
    import Button from "@/components/atoms/Button.svelte";
    import Card from "@/components/atoms/Card.svelte";
    import type { ErrorScreenProps } from "@/types/error-screen";

    /**
     * Inertia XHR の 4xx/5xx 着地画面 (サーバの InertiaExceptionRenderer が描画する)。
     *
     * 契約:
     *  - **layout を import しない**。AppLayout/AuthLayout は page-shell-structure の
     *    構造契約が掛かり、GuestLayout は共有 prop appName を要求するが、
     *    例外は HandleInertiaRequests が走る前にも起きるため共有 props が無い場合がある
     *  - **戻り先は通常の <a> (Button の anchor モード、inertia prop なし)**。
     *    Link / router.visit は同じ document を保つため、419 の原因が古い CSRF token だと
     *    遷移後の POST で同じ 419 を踏み直す。document を作り直して初めて復旧する
     *  - **disabled な CTA を作らない** (禁止事項 8)。destinations は常に 1 件以上
     *  - title は svelte:head に書かない (サーバ SEO が唯一の SoT)
     */
    let { status, title, message, retryAfterSeconds, destinations }: ErrorScreenProps = $props();
</script>

<div class="flex min-h-screen items-center justify-center bg-neutral p-6 text-text">
    <Card padding="lg" class="w-full max-w-md text-center" testId="error-screen">
        <CircleAlert class="mx-auto h-12 w-12 text-text-secondary" />
        <p class="mt-4 text-caption text-text-secondary" data-testid="error-status">{status}</p>
        <h1 class="mt-1 text-h1">{title}</h1>
        <p class="mt-3 text-caption text-text-secondary">{message}</p>
        {#if retryAfterSeconds !== null}
            <p class="mt-2 text-caption text-text-secondary" data-testid="error-retry-after">
                約 {retryAfterSeconds} 秒後にもう一度お試しください。
            </p>
        {/if}
        <div class="mt-6 flex flex-col gap-2">
            {#each destinations as destination (destination.href)}
                <Button href={destination.href}>{destination.label}</Button>
            {/each}
        </div>
    </Card>
</div>
