<script lang="ts">
    import { Link } from "@inertiajs/svelte";
    import { Building2, ChevronRight } from "@lucide/svelte";

    /**
     * 組織を 1 件選ぶカード (遷移リンク)。
     *
     * ★molecule = atom (Link + Lucide icon) の組合せだけで、状態も domain 操作も持たない
     *   (NotificationBell と同じ位置づけ)。遷移先 URL は**親が渡す**。
     * ★DS: 色 / radius / typography は token 経由のみ。SVG 直書きを新設しない。
     */
    interface Props {
        name: string;
        href: string;
        testId?: string;
    }

    let { name, href, testId }: Props = $props();
</script>

<Link
    {href}
    class="flex items-center gap-3 rounded-md border border-border px-4 py-3 text-body text-text
        transition-colors hover:bg-neutral"
    data-testid={testId}
>
    <Building2 class="size-5 shrink-0 text-primary" aria-hidden="true" />
    <span class="flex-1 truncate">{name}</span>
    <ChevronRight class="size-4 shrink-0 text-text-secondary" aria-hidden="true" />
</Link>
