<script lang="ts">
    import { onDestroy } from "svelte";
    import Button from "@/components/atoms/Button.svelte";

    /**
     * コピー付きコードブロック molecule (API キー・リカバリコード・CLI コマンド等の表示用)。
     *
     * コピー処理は component 内に内包する。Clipboard API が使えないとき (非対応環境・拒否・
     * フォーカス喪失など) は、**コード文字列を選択状態にして手動コピーへ進める道を残す**。
     * `<pre>` は中間 box なので rounded-md、コードは font-mono (DESIGN.md §Shapes / §Typography)。
     */
    interface Props {
        code: string;
        language?: string;
        testId?: string;
        class?: string;
    }

    let { code, language = "plaintext", testId, class: extraClass = "" }: Props = $props();

    /**
     * 表示状態。**boolean 2 本ではなく 4 値で持つ**
     * (「失敗したが選択はできた」と「選択もできなかった」を取り違えないため。
     *  boolean の組み合わせだと不能な状態が型の上で作れてしまう)。
     */
    type CopyStatus = "idle" | "copied" | "manual-selected" | "manual-unselected";

    let status = $state<CopyStatus>("idle");
    let codeEl = $state<HTMLElement | null>(null);
    let timeoutId: ReturnType<typeof setTimeout> | undefined;
    /**
     * この component が張った Range の複製。**「code の中か」ではなく「自分が作った Range と
     * 完全一致か」で所有を判定する** (利用者が同じ code 内を部分選択し直した場合に、
     * その選択を奪わないため)。
     */
    let ownedRange: Range | null = null;
    /** 連打・遅延解決の競合よけ。await 後に最新試行でなければ状態を触らない */
    let attemptId = 0;

    /**
     * コピー。段階は次のとおりで、**主役は 2 の「選択を残すこと」**である
     * (3 は選択のついでに試す legacy fallback にすぎない)。
     * 1. Clipboard API → 通れば成功
     * 2. コード文字列を選択状態にする (手動コピーへ進める状態を手元に残す)
     * 3. その選択で document.execCommand("copy") を試す (非推奨 API。通れば成功)
     * 4. 通らなければ案内を出す。**この案内は自動では消さない** (手動コピーには時間が要る)
     *
     * 不変条件: **この component が張った選択が残っているのは、手動コピーを促している間だけ**
     * (利用者が自分で選び直した選択は、成功後も破棄時も奪わない)。
     */
    async function copy(): Promise<void> {
        if (timeoutId) clearTimeout(timeoutId);
        const attempt = ++attemptId;
        // 前回の試行の痕跡を両方消す (所有 Selection と表示状態)
        clearOwnSelection();
        status = "idle";

        const written = await writeViaClipboardApi();
        // 遅延解決した古い試行・破棄後の解決は、新しい状態を上書きしない
        if (attempt !== attemptId) return;

        if (written) {
            markCopied();

            return;
        }

        const selected = selectCode();
        if (selected && tryLegacyCopy()) {
            // コピーできた以上、手動コピー用の選択は不要 = 自分が張った選択だけ畳む
            clearOwnSelection();
            markCopied();

            return;
        }

        status = selected ? "manual-selected" : "manual-unselected";
    }

    /** Clipboard API での書き込み。非対応環境・拒否・フォーカス喪失はすべて false */
    async function writeViaClipboardApi(): Promise<boolean> {
        try {
            if (!navigator.clipboard?.writeText) return false;
            await navigator.clipboard.writeText(code);

            return true;
        } catch {
            return false;
        }
    }

    /** コード文字列を選択状態にする (成否を返す)。**これが本命の受け皿** */
    function selectCode(): boolean {
        if (codeEl === null) return false;
        const selection = window.getSelection();
        if (selection === null) return false;

        // range の構築と所有記録用の複製までを、既存選択に触る前に済ませる
        // (ここで失敗しても利用者の選択を壊さない / 選択を張った後に cloneRange が投げて
        //  「所有していない選択だけが残る」状態を作らない)
        let range: Range;
        let owned: Range;
        try {
            range = document.createRange();
            range.selectNodeContents(codeEl);
            owned = range.cloneRange();
        } catch {
            return false;
        }

        try {
            selection.removeAllRanges();
            selection.addRange(range);
        } catch {
            // ここで投げると既存選択だけが失われる (稀。塞ぐには旧選択の退避機構が要るため受容)
            return false;
        }
        ownedRange = owned;

        return true;
    }

    /**
     * **この component が張った選択だけ**を畳む。利用者がその後に選択し直していたら触らない
     * (別要素でも、同じ code 内の部分選択でも奪わない = Range の境界 4 点で完全一致を見る)。
     * **例外を投げない** — これは後処理であり、ここでの失敗がコピー成功を覆してはならない。
     */
    function clearOwnSelection(): void {
        const expected = ownedRange;
        // 所有状態は Selection 操作より先に手放す (途中で投げても所有が残らない)
        ownedRange = null;
        if (expected === null) return;

        try {
            const selection = window.getSelection();
            if (selection === null || selection.rangeCount !== 1) return;

            const current = selection.getRangeAt(0);
            const isOwned =
                current.startContainer === expected.startContainer &&
                current.startOffset === expected.startOffset &&
                current.endContainer === expected.endContainer &&
                current.endOffset === expected.endOffset;

            if (isOwned) selection.removeAllRanges();
        } catch {
            // 後処理の失敗はコピーの成否に影響させない
        }
    }

    /**
     * 非推奨 API による最後の試行。**主役ではない** —
     * 選択状態をどのみち作るので、そのついでに試すだけの補助経路である。
     * これが通っても「Clipboard API の代替が保証された」わけではない
     * (execCommand も document のフォーカスを要求するため、フォーカス起因なら同じく失敗する)。
     */
    function tryLegacyCopy(): boolean {
        if (typeof document.execCommand !== "function") return false;
        try {
            return document.execCommand("copy");
        } catch {
            return false;
        }
    }

    /** 成功表示 (2 秒で消える。一過性の確認なので消えてよい) */
    function markCopied(): void {
        status = "copied";
        timeoutId = setTimeout(() => {
            status = "idle";
            timeoutId = undefined;
        }, 2000);
    }

    onDestroy(() => {
        // 保留中の試行を無効化する (破棄後に解決した writeText が markCopied を呼び、
        // 新しいタイマーを登録してしまうのを防ぐ)
        attemptId++;
        clearOwnSelection();
        if (timeoutId !== undefined) {
            clearTimeout(timeoutId);
            timeoutId = undefined;
        }
    });
</script>

<div class={["relative", extraClass].filter(Boolean).join(" ")} data-testid={testId}>
    <!-- pr-24 でコピー UI 分の余白を確保する -->
    <pre
        data-testid={testId ? `${testId}-body` : undefined}
        data-language={language}
        class="overflow-x-auto rounded-md border border-border bg-neutral p-4 pr-24 text-caption font-mono text-text"><code
        bind:this={codeEl}>{code}</code></pre>
    <div class="absolute top-2 right-2 flex items-center gap-2">
        {#if status === "copied"}
            <span role="status" class="text-caption text-success">コピー完了</span>
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
    {#if status === "manual-selected" || status === "manual-unselected"}
        <!-- 案内はブロック下部の通常フローに出す (ボタン横の absolute 領域に長文を置くと破綻する)。
             文面は**実際にやったことをそのまま言う** = 選択できたときだけ「選択した」と書く。 -->
        <p
            role="status"
            class="mt-2 text-caption text-danger"
            data-testid={testId ? `${testId}-manual-copy` : undefined}
        >
            {status === "manual-selected"
                ? "コピーできませんでした。上のテキストを選択したので、⌘C / Ctrl+C (スマートフォンでは端末のコピー操作) でコピーしてください。"
                : "コピーできませんでした。上のテキストを選択して手動でコピーしてください。"}
        </p>
    {/if}
</div>
