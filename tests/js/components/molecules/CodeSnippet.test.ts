import { afterEach, describe, expect, it, vi } from "vitest";
import { act, fireEvent, render, screen } from "@testing-library/svelte";
import CodeSnippet from "@/components/molecules/CodeSnippet.svelte";

/** navigator.clipboard を書き換え可能な形で差し込む (jsdom 既定では未定義) */
function stubClipboard(writeText: (text: string) => Promise<void>): void {
    Object.defineProperty(window.navigator, "clipboard", {
        value: { writeText },
        configurable: true,
    });
}

function removeClipboard(): void {
    Object.defineProperty(window.navigator, "clipboard", {
        value: undefined,
        configurable: true,
    });
}

afterEach(() => {
    removeClipboard();
    vi.useRealTimers();
});

describe("CodeSnippet", () => {
    it("code を <pre><code> に描画し data-language を付ける", () => {
        render(CodeSnippet, {
            props: { code: "php artisan migrate", language: "shell", testId: "snippet" },
        });

        const pre = screen.getByTestId("snippet-body");
        expect(pre.tagName).toBe("PRE");
        expect(pre).toHaveAttribute("data-language", "shell");
        expect(pre).toHaveTextContent("php artisan migrate");
        expect(pre.className).toContain("font-mono");
    });

    it("コピー成功でクリップボードに書き込み「コピー完了」を表示する", async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        stubClipboard(writeText);
        render(CodeSnippet, { props: { code: "secret-token", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(writeText).toHaveBeenCalledWith("secret-token");
        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
    });

    it("コピー完了表示は 2 秒後に消える", async () => {
        // setTimeout の登録前に fake timer 化する (登録後だと advance が効かない)
        vi.useFakeTimers();
        stubClipboard(vi.fn().mockResolvedValue(undefined));
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        // clipboard resolve (microtask) 後の再描画を flush する
        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByText("コピー完了")).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2100);
        });
        expect(screen.queryByText("コピー完了")).toBeNull();
    });

    it("コピー失敗で「コピー失敗」を表示する", async () => {
        stubClipboard(vi.fn().mockRejectedValue(new Error("denied")));
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
    });

    it("clipboard API 非対応環境では「コピー失敗」を表示する", async () => {
        removeClipboard();
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByText("コピー失敗")).toBeInTheDocument();
    });
});
