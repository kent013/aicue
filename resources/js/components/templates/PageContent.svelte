<script lang="ts" module>
    /** PageContent の max-width 段 (union → 静的 Record で Tailwind class 解決)。 */
    export type PageContentMaxWidth =
        | "sm"
        | "md"
        | "lg"
        | "xl"
        | "2xl"
        | "3xl"
        | "4xl"
        | "5xl"
        | "6xl"
        | "7xl";
</script>

<script lang="ts">
    import type { Snippet } from "svelte";

    /**
     * PageContent — 認証ページ本文の中央寄せ + max-width 制御を一元所有する layout primitive
     * (参照アプリ aigenba の PageContent 準拠)。
     *
     * 幅の責務はここに集約し、AppLayout の <main> は padding のみを担う (nested max-w を作らない)。
     * 認証ページは本文ルート (見出し含む) をこれで包み、内側の重複 max-w-* は置かない。
     * maxWidth は必須 prop (指定漏れは型エラー)。任意 class 直渡しを禁じ、union → 静的 Record で解決する
     * (ds-purity 適合 / Tailwind class 消失防止)。
     *
     * 運用規約: 認証ページ本文の標準幅は "2xl"。例外 (md/3xl/4xl/7xl 等) は各ページで理由をもって指定する。
     */
    interface Props {
        maxWidth: PageContentMaxWidth;
        /** 既定 "page-content"。DOM 契約を固定化しないため任意化。 */
        testId?: string;
        children: Snippet;
    }

    let { maxWidth, testId = "page-content", children }: Props = $props();

    const MAX_W: Record<PageContentMaxWidth, string> = {
        sm: "max-w-sm",
        md: "max-w-md",
        lg: "max-w-lg",
        xl: "max-w-xl",
        "2xl": "max-w-2xl",
        "3xl": "max-w-3xl",
        "4xl": "max-w-4xl",
        "5xl": "max-w-5xl",
        "6xl": "max-w-6xl",
        "7xl": "max-w-7xl",
    };
</script>

<div class="mx-auto w-full {MAX_W[maxWidth]}" data-testid={testId}>
    {@render children()}
</div>
