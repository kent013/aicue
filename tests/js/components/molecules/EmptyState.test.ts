import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { Inbox } from "@lucide/svelte";
import EmptyState from "@/components/molecules/EmptyState.svelte";

describe("EmptyState", () => {
    it("title と description を描画する", () => {
        render(EmptyState, {
            props: {
                title: "データがありません",
                description: "最初の項目を作成してください",
            },
        });

        expect(screen.getByRole("heading", { level: 3 })).toHaveTextContent("データがありません");
        expect(screen.getByText("最初の項目を作成してください")).toBeInTheDocument();
    });

    it("title 省略時は見出しを描画しない (description のみで成立する)", () => {
        render(EmptyState, {
            props: { description: "説明のみの空状態です", testId: "empty" },
        });

        expect(screen.queryByRole("heading")).toBeNull();
        expect(screen.getByText("説明のみの空状態です")).toBeInTheDocument();
    });

    it("icon 指定時に Lucide アイコンを size-10 で描画する", () => {
        render(EmptyState, {
            props: { title: "空です", description: "説明", icon: Inbox, testId: "empty" },
        });

        const svg = screen.getByTestId("empty").querySelector("svg");
        expect(svg).not.toBeNull();
        expect(svg?.getAttribute("class") ?? "").toContain("size-10");
        expect(svg).toHaveAttribute("aria-hidden", "true");
    });

    it("cta kind=link で href 付きのリンクを描画する", () => {
        render(EmptyState, {
            props: {
                title: "空です",
                description: "説明",
                cta: { kind: "link", label: "新規作成", href: "/items/create" },
            },
        });

        const link = screen.getByRole("link", { name: "新規作成" });
        // Inertia Link は jsdom 上で絶対 URL に正規化されるため path 部分で検証する
        expect(link.getAttribute("href") ?? "").toMatch(/\/items\/create$/);
    });

    it("cta kind=action でクリック時に onclick が呼ばれる", async () => {
        const onclick = vi.fn();
        render(EmptyState, {
            props: {
                title: "空です",
                description: "説明",
                cta: { kind: "action", label: "再読み込み", onclick },
            },
        });

        await fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
        expect(onclick).toHaveBeenCalledTimes(1);
    });

    it("cta 省略時は CTA を描画しない", () => {
        render(EmptyState, { props: { title: "空です", description: "説明" } });

        expect(screen.queryByRole("button")).toBeNull();
        expect(screen.queryByRole("link")).toBeNull();
    });

    it("bordered=true で破線枠クラスが付く", () => {
        render(EmptyState, {
            props: { title: "空です", description: "説明", bordered: true, testId: "empty" },
        });

        const root = screen.getByTestId("empty");
        expect(root.className).toContain("border-dashed");
        expect(root.className).toContain("border-border");
        expect(root.className).toContain("rounded-lg");
    });

    it("bordered 既定 (false) では枠クラスが付かない", () => {
        render(EmptyState, { props: { title: "空です", description: "説明", testId: "empty" } });

        expect(screen.getByTestId("empty").className).not.toContain("border-dashed");
    });
});
