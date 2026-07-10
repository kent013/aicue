<script lang="ts">
    import { onDestroy } from "svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * コピー付きコードブロック molecule (API キー・リカバリコード・CLI コマンド等の表示用)。
     *
     * コピー処理は component 内に内包する (navigator.clipboard 直呼び。
     * 非対応環境・拒否時は「コピー失敗」を表示して手動コピーを促す)。
     * `<pre>` は中間 box なので rounded-md、コードは font-mono (DESIGN.md §Shapes / §Typography)。
     */
    interface Props {
        code: string;
        language?: string;
        testId?: string;
        class?: string;
    }

    let { code, language = "plaintext", testId, class: extraClass = "" }: Props = $props();

    let copied = $state(false);
    let failed = $state(false);
    let timeoutId: ReturnType<typeof setTimeout> | undefined;

    async function copy(): Promise<void> {
        if (timeoutId) clearTimeout(timeoutId);
        try {
            // clipboard 非対応環境 (insecure context 等) は writeText が無いため失敗扱い
            if (!navigator.clipboard?.writeText) {
                throw new Error("clipboard unavailable");
            }
            await navigator.clipboard.writeText(code);
            copied = true;
            failed = false;
        } catch {
            copied = false;
            failed = true;
        }
        timeoutId = setTimeout(() => {
            copied = false;
            failed = false;
        }, 2000);
    }

    onDestroy(() => {
        if (timeoutId) clearTimeout(timeoutId);
    });
</script>

<div class={["relative", extraClass].filter(Boolean).join(" ")} data-testid={testId}>
    <!-- pr-24 でコピー UI 分の余白を確保する -->
    <pre
        data-testid={testId ? `${testId}-body` : undefined}
        data-language={language}
        class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
        >{code}</code></pre>
    <div class="absolute top-2 right-2 flex items-center gap-2">
        {#if copied}
            <span role="status" class="text-caption text-success">コピー完了</span>
        {:else if failed}
            <span role="status" class="text-caption text-danger">コピー失敗</span>
        {/if}
        <Button
            variant="neutral"
            size="sm"
            onclick={copy}
            testId={testId ? `${testId}-copy` : undefined}
        >
            コピー
        </Button>
    </div>
</div>
