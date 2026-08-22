<script lang="ts">
    import { page } from "@inertiajs/svelte";
    import { MailX } from "@lucide/svelte";
    import Card from "@/components/atoms/Card.svelte";
    import TextLink from "@/components/atoms/TextLink.svelte";
    import type { SharedProps } from "@/lib/shared-props";

    // 無効招待 (取消済 / 受諾済 / 期限切れ / 不在) の専用説明ページ。
    // 無効理由は受け取らない (token オラクル防止 / CWE-203): どの無効理由でも同一画面を返す。
    // 組織名も出さない (組織が実在し招待が取り消された、という識別を許さない)。
    // 認証状態 (shared props の auth.user) のみで secondary 導線を出し分ける。
    const shared = $derived(page.props as unknown as SharedProps);
    const isAuthenticated = $derived(shared.auth?.user != null);
</script>

<div
    class="flex min-h-screen items-center justify-center bg-neutral px-4 py-10 text-text"
    data-testid="invitation-invalid"
>
    <Card padding="lg" class="w-full max-w-md text-center">
        <MailX class="mx-auto mb-4 h-12 w-12 text-warning" />
        <h1 class="mb-2 text-h2">この招待リンクは使用できません</h1>
        <p class="mb-6 text-body text-text-secondary">
            招待が無効になっているか、すでに使用済みか、期限が切れています。<br />
            招待を送った担当者に、新しい招待リンクの再送を依頼してください。
        </p>
        {#if isAuthenticated}
            <TextLink href="/go">ダッシュボードへ戻る</TextLink>
        {:else}
            <div class="flex justify-center gap-4">
                <TextLink href="/login">ログイン</TextLink>
                <TextLink href="/">トップへ</TextLink>
            </div>
        {/if}
    </Card>
</div>
