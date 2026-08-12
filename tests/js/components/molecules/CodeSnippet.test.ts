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

/**
 * document.execCommand を差し替える (jsdom は未実装 = undefined)。
 * 復元は afterEach の deleteProperty が行う。
 */
function stubExecCommand(impl: (command: string) => boolean): ReturnType<typeof vi.fn> {
    const spy = vi.fn(impl);
    Object.defineProperty(document, "execCommand", { value: spy, configurable: true });

    return spy;
}

/**
 * 現在の選択が指している文字列。jsdom の Selection.toString() は実装差があるため
 * Range 側から見る (どの環境でも Range#toString は範囲のテキストを返す)。
 */
function selectedText(): string {
    const selection = window.getSelection();

    return selection === null || selection.rangeCount === 0
        ? ""
        : selection.getRangeAt(0).toString();
}

/** 手動 deferred (保留中の writeText を作るため) */
function deferred(): {
    promise: Promise<void>;
    resolve: () => void;
    reject: (reason?: unknown) => void;
} {
    let resolve!: () => void;
    let reject!: (reason?: unknown) => void;
    const promise = new Promise<void>((res, rej) => {
        resolve = res;
        reject = rej;
    });

    return { promise, resolve, reject };
}

afterEach(() => {
    removeClipboard();
    // **spy の復元が先**。Selection の spy を張ったままだと、後始末の removeAllRanges 自体が
    // 契約 16 の throwing stub を踏んでテストを落とす。
    vi.restoreAllMocks();
    // Selection / execCommand は document グローバル = 明示的に戻さないと次テストを汚染する
    window.getSelection()?.removeAllRanges();
    Reflect.deleteProperty(document, "execCommand");
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
});

/*
 * コピー失敗時のフォールバック (T156 / bug-hunt F-3-01)。
 *
 * 契約の骨子:
 * - 失敗したら**コード文字列を選択状態にして手動コピーへ進める道を残す**のが本命。
 *   document.execCommand は「どのみち選択を作るのでついでに試す」補助にすぎない。
 * - **選択が残っているのは手動コピーを促している間だけ** (legacy 成功時・再試行の冒頭・
 *   component 破棄では、自分が張った選択だけを畳む)。
 * - 案内は自動で消さない (手動コピーには時間が要る)。成功表示が 2 秒で消えるのとは
 *   意図的に非対称。
 */
describe("CodeSnippet コピー失敗時のフォールバック (T156)", () => {
    /** Clipboard API が必ず失敗する状態にする */
    function stubFailingClipboard(): void {
        stubClipboard(vi.fn().mockRejectedValue(new Error("denied")));
    }

    it("契約 1: Clipboard 失敗 → 選択 → legacy コピー成功で「コピー完了」", async () => {
        stubFailingClipboard();
        let selectionAtCall = "";
        const execCommand = stubExecCommand(() => {
            // **呼ばれた時点で**選択が code を指していること (順序まで固定する)
            selectionAtCall = selectedText();

            return true;
        });
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(execCommand).toHaveBeenCalledWith("copy");
        expect(selectionAtCall).toBe("abc-123");
        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
    });

    it("契約 2: legacy コピー成功時は選択を残さない", async () => {
        stubFailingClipboard();
        stubExecCommand(() => true);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });

        expect(selectedText()).toBe("");
    });

    it("契約 3: legacy も失敗したら案内を出し、選択は残す", async () => {
        stubFailingClipboard();
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        const notice = await screen.findByTestId("snippet-manual-copy");
        expect(notice.textContent).toContain("選択したので");
        expect(selectedText()).toBe("abc-123");
    });

    it("契約 4: 案内は 2 秒経っても消えない (成功表示との非対称)", async () => {
        vi.useFakeTimers();
        stubFailingClipboard();
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByTestId("snippet-manual-copy")).toBeInTheDocument();

        await act(async () => {
            await vi.advanceTimersByTimeAsync(2100);
        });
        expect(screen.getByTestId("snippet-manual-copy")).toBeInTheDocument();
    });

    it("契約 5: clipboard API 非対応環境でも同じ段階を通る", async () => {
        removeClipboard();
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
        expect(selectedText()).toBe("abc-123");
    });

    it("契約 6: document.execCommand が未定義でも例外を投げず案内へ落ちる", async () => {
        stubFailingClipboard();
        // execCommand は stub しない (jsdom 既定 = 未定義)
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
    });

    it("契約 7: document.execCommand が例外を投げても案内へ落ちる", async () => {
        stubFailingClipboard();
        stubExecCommand(() => {
            throw new Error("execCommand exploded");
        });
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
    });

    it("契約 8: 案内が出た後に成功すると案内が消え、選択も残らない", async () => {
        const writeText = vi
            .fn()
            .mockRejectedValueOnce(new Error("denied"))
            .mockResolvedValueOnce(undefined);
        stubClipboard(writeText);
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
        expect(selectedText()).toBe("");
    });

    it("契約 9: 再試行中は古い案内も古い選択も残らない", async () => {
        const pending = deferred();
        const writeText = vi
            .fn()
            .mockRejectedValueOnce(new Error("denied"))
            .mockReturnValueOnce(pending.promise);
        stubClipboard(writeText);
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();

        // 2 回目は解決を保留する = await 中の状態を観測する
        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
        expect(selectedText()).toBe("");

        // 未処理の Promise を残さない
        pending.resolve();
        await act(async () => {
            await pending.promise;
        });
    });

    it("契約 10: 遅延解決した古い試行は新しい結果を上書きしない", async () => {
        const slow = deferred();
        const writeText = vi
            .fn()
            .mockReturnValueOnce(slow.promise)
            .mockResolvedValueOnce(undefined);
        stubClipboard(writeText);
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        // 1 回目 (保留) → 2 回目 (即成功)
        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });
        expect(screen.getByText("コピー完了")).toBeInTheDocument();

        // 後から 1 回目が失敗しても、新しい結果を覆さない
        slow.reject(new Error("late failure"));
        await act(async () => {
            await slow.promise.catch(() => undefined);
        });

        expect(screen.getByText("コピー完了")).toBeInTheDocument();
        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
    });

    it("契約 11: unmount で自分が張った選択を畳む", async () => {
        stubFailingClipboard();
        stubExecCommand(() => false);
        const { unmount } = render(CodeSnippet, {
            props: { code: "abc-123", testId: "snippet" },
        });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();
        expect(selectedText()).toBe("abc-123");

        // **結果 (selectedText が空) だけでは実装の有無を区別できない**。jsdom は DOM から
        // 取り除かれたノードを指す live range を自分で畳むため、破棄時の解除を実装から
        // 外しても空になる (mutation で実測済み)。よって「解除を試みたこと」も併せて見る。
        const selection = window.getSelection();
        expect(selection).not.toBeNull();
        const removeAllRanges = vi.spyOn(selection as Selection, "removeAllRanges");

        unmount();

        expect(removeAllRanges).toHaveBeenCalled();
        expect(selectedText()).toBe("");
    });

    it("契約 12: 利用者が別要素を選択していたら unmount で奪わない", async () => {
        // 「奪わない」= unmount 時に removeAllRanges を呼ばないことも併せて見る。
        // **破棄時の所有判定が意味を持つのは、選択が削除される subtree の外にあるときだけ**である
        // (subtree の内側にある選択は、削除によって所有 range と同じ 1 点へ畳まれるため
        //  区別できない。ただしその選択は削除で失われており、区別する必要も無い。実測は
        //  devnotes/20260812-0845-todo-T156/implementation-notes.md)。
        stubFailingClipboard();
        stubExecCommand(() => false);
        const { unmount } = render(CodeSnippet, {
            props: { code: "abc-123", testId: "snippet" },
        });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();

        // component の unmount に巻き込まれないよう body 直下に置く
        const outside = document.createElement("p");
        outside.textContent = "利用者が選んだ別のテキスト";
        document.body.appendChild(outside);
        const range = document.createRange();
        range.selectNodeContents(outside);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(range);

        const removeAllRanges = vi.spyOn(selection as Selection, "removeAllRanges");

        unmount();

        expect(removeAllRanges).not.toHaveBeenCalled();
        expect(selectedText()).toBe("利用者が選んだ別のテキスト");
        outside.remove();
    });

    it("契約 13: 利用者が同じ code 内を部分選択し直していたら奪わない", async () => {
        // 観測点は **unmount ではなく再試行**である。jsdom は DOM から取り除かれたノードを指す
        // live range を畳むため、unmount 後の選択は実装の正否と無関係に空になる
        // (= unmount では本契約を観測できない)。DOM が残る再試行の冒頭で見る。
        const writeText = vi
            .fn()
            .mockRejectedValueOnce(new Error("denied"))
            .mockResolvedValueOnce(undefined);
        stubClipboard(writeText);
        stubExecCommand(() => false);
        render(CodeSnippet, { props: { code: "abc-123", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        expect(await screen.findByTestId("snippet-manual-copy")).toBeInTheDocument();

        // 同じ code の一部だけを選び直す (contains 判定だと奪われてしまうケース)
        const textNode = screen.getByTestId("snippet-body").querySelector("code")?.firstChild;
        expect(textNode).not.toBeNull();
        const partial = document.createRange();
        partial.setStart(textNode as Node, 0);
        partial.setEnd(textNode as Node, 3);
        const selection = window.getSelection();
        selection?.removeAllRanges();
        selection?.addRange(partial);
        expect(selectedText()).toBe("abc");

        // 再試行 (成功) 。冒頭の clearOwnSelection が利用者の部分選択を奪ってはならない
        await fireEvent.click(screen.getByTestId("snippet-copy"));
        await act(async () => {
            await Promise.resolve();
        });

        expect(selectedText()).toBe("abc");
    });

    it("契約 14: 選択できなかった場合は「選択したので」と書かない", async () => {
        stubFailingClipboard();
        stubExecCommand(() => false);
        vi.spyOn(window, "getSelection").mockReturnValue(null);
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        const notice = await screen.findByTestId("snippet-manual-copy");
        expect(notice.textContent).not.toContain("選択したので");
        expect(notice.textContent).toContain("選択して手動でコピー");
    });

    it("契約 15: 保留中に unmount したら、その後の解決でタイマーを登録しない", async () => {
        const pending = deferred();
        stubClipboard(vi.fn().mockReturnValue(pending.promise));
        const setTimeoutSpy = vi.spyOn(globalThis, "setTimeout");
        const { unmount } = render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));
        unmount();

        // Svelte / テスト環境も内部でタイマーを使いうるので、総数ではなく**差分**で見る
        const countBeforeResolve = setTimeoutSpy.mock.calls.length;
        pending.resolve();
        await act(async () => {
            await pending.promise;
        });

        expect(setTimeoutSpy).toHaveBeenCalledTimes(countBeforeResolve);
    });

    it("契約 16: 選択解除が例外を投げても legacy 成功表示は覆らない", async () => {
        stubFailingClipboard();
        stubExecCommand(() => true);
        const selection = window.getSelection();
        expect(selection).not.toBeNull();
        let removeCalls = 0;
        vi.spyOn(selection as Selection, "removeAllRanges").mockImplementation(function (
            this: Selection,
        ): void {
            removeCalls += 1;
            // 1 回目 (selectCode) は成功、2 回目 (clearOwnSelection) だけ throw させる
            if (removeCalls >= 2) throw new Error("removeAllRanges exploded");
        });
        render(CodeSnippet, { props: { code: "abc", testId: "snippet" } });

        await fireEvent.click(screen.getByTestId("snippet-copy"));

        expect(await screen.findByText("コピー完了")).toBeInTheDocument();
        expect(screen.queryByTestId("snippet-manual-copy")).toBeNull();
    });
});
