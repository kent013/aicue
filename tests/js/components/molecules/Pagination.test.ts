import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Pagination from "@/components/molecules/Pagination.svelte";

/** 描画されているページ番号ボタンのラベル一覧を返す */
function pageLabels(): string[] {
    return screen
        .getAllByRole("button")
        .filter((b) => !b.hasAttribute("aria-label"))
        .map((b) => b.textContent?.trim() ?? "");
}

describe("Pagination", () => {
    it("nav に accessible name (既定: ページネーション) が付く", () => {
        render(Pagination, { props: { currentPage: 1, totalPages: 3, onChange: vi.fn() } });

        expect(screen.getByRole("navigation", { name: "ページネーション" })).toBeInTheDocument();
    });

    it("ariaLabel を上書きできる", () => {
        render(Pagination, {
            props: { currentPage: 1, totalPages: 3, onChange: vi.fn(), ariaLabel: "検索結果" },
        });

        expect(screen.getByRole("navigation", { name: "検索結果" })).toBeInTheDocument();
    });

    it("totalPages ≤ 7 は全ページ番号を表示する", () => {
        render(Pagination, { props: { currentPage: 3, totalPages: 7, onChange: vi.fn() } });

        expect(pageLabels()).toEqual(["1", "2", "3", "4", "5", "6", "7"]);
        expect(screen.queryByText("…")).toBeNull();
    });

    it("超過時は先頭・末尾 + 現在±1 の窓 + 省略記号を表示する (中央)", () => {
        render(Pagination, { props: { currentPage: 5, totalPages: 10, onChange: vi.fn() } });

        expect(pageLabels()).toEqual(["1", "4", "5", "6", "10"]);
        const ellipses = screen.getAllByText("…");
        expect(ellipses).toHaveLength(2);
        for (const e of ellipses) {
            expect(e).toHaveAttribute("aria-hidden", "true");
        }
    });

    it("窓が先頭/末尾に隣接するとき省略記号を出さない側がある", () => {
        render(Pagination, { props: { currentPage: 2, totalPages: 10, onChange: vi.fn() } });

        expect(pageLabels()).toEqual(["1", "2", "3", "10"]);
        expect(screen.getAllByText("…")).toHaveLength(1);
    });

    it("現在ページに aria-current=page と bg-primary が付く", () => {
        render(Pagination, { props: { currentPage: 2, totalPages: 5, onChange: vi.fn() } });

        const current = screen.getByRole("button", { name: "2" });
        expect(current).toHaveAttribute("aria-current", "page");
        expect(current.className).toContain("bg-primary");
        expect(current.className).toContain("text-neutral");
        expect(screen.getByRole("button", { name: "3" })).not.toHaveAttribute("aria-current");
    });

    it("ページ番号クリックで onChange が呼ばれる / 現在ページでは呼ばれない", async () => {
        const onChange = vi.fn();
        render(Pagination, { props: { currentPage: 2, totalPages: 5, onChange } });

        await fireEvent.click(screen.getByRole("button", { name: "4" }));
        expect(onChange).toHaveBeenCalledWith(4);

        await fireEvent.click(screen.getByRole("button", { name: "2" }));
        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it("前へ/次へで currentPage ± 1 が渡る", async () => {
        const onChange = vi.fn();
        render(Pagination, { props: { currentPage: 3, totalPages: 5, onChange } });

        await fireEvent.click(screen.getByRole("button", { name: "前のページ" }));
        expect(onChange).toHaveBeenLastCalledWith(2);

        await fireEvent.click(screen.getByRole("button", { name: "次のページ" }));
        expect(onChange).toHaveBeenLastCalledWith(4);
    });

    it("先頭ページで前へが disabled / 末尾ページで次へが disabled", () => {
        const { unmount } = render(Pagination, {
            props: { currentPage: 1, totalPages: 5, onChange: vi.fn() },
        });
        expect(screen.getByRole("button", { name: "前のページ" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "次のページ" })).not.toBeDisabled();
        unmount();

        render(Pagination, { props: { currentPage: 5, totalPages: 5, onChange: vi.fn() } });
        expect(screen.getByRole("button", { name: "前のページ" })).not.toBeDisabled();
        expect(screen.getByRole("button", { name: "次のページ" })).toBeDisabled();
    });

    it("totalPages ≤ 1 はページ番号を表示しない", () => {
        render(Pagination, { props: { currentPage: 1, totalPages: 1, onChange: vi.fn() } });

        expect(pageLabels()).toEqual([]);
        expect(screen.getByRole("button", { name: "前のページ" })).toBeDisabled();
        expect(screen.getByRole("button", { name: "次のページ" })).toBeDisabled();
    });
});
