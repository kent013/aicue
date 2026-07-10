import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import Tabs from "@/components/molecules/Tabs.svelte";

const tabs = [
    { id: "general", label: "一般" },
    { id: "members", label: "メンバー" },
    { id: "billing", label: "請求" },
];

const tabsWithDisabled = [
    { id: "a", label: "A" },
    { id: "b", label: "B", disabled: true },
    { id: "c", label: "C" },
];

describe("Tabs", () => {
    it("tablist と tab を描画し、idBase で aria-controls/id を配線する", () => {
        render(Tabs, { props: { tabs, active: "general", idBase: "settings" } });

        expect(screen.getByRole("tablist")).toBeInTheDocument();
        const tabEls = screen.getAllByRole("tab");
        expect(tabEls).toHaveLength(3);
        expect(tabEls[0]).toHaveAttribute("id", "settings-tab-general");
        expect(tabEls[0]).toHaveAttribute("aria-controls", "settings-panel-general");
        expect(tabEls[1]).toHaveAttribute("id", "settings-tab-members");
        expect(tabEls[1]).toHaveAttribute("aria-controls", "settings-panel-members");
    });

    it("active タブのみ aria-selected=true かつ tabindex=0 (roving tabindex)", () => {
        render(Tabs, { props: { tabs, active: "members", idBase: "settings" } });

        const tabEls = screen.getAllByRole("tab");
        expect(tabEls[0]).toHaveAttribute("aria-selected", "false");
        expect(tabEls[1]).toHaveAttribute("aria-selected", "true");
        expect(tabEls[0]).toHaveAttribute("tabindex", "-1");
        expect(tabEls[1]).toHaveAttribute("tabindex", "0");
        expect(tabEls[2]).toHaveAttribute("tabindex", "-1");
    });

    it("クリックで選択され onChange が呼ばれる", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "general", onChange, idBase: "settings" } });

        await fireEvent.click(screen.getByRole("tab", { name: "請求" }));
        expect(onChange).toHaveBeenCalledWith("billing");
        expect(screen.getByRole("tab", { name: "請求" })).toHaveAttribute(
            "aria-selected",
            "true",
        );
    });

    it("現在の active タブをクリックしても onChange は呼ばれない", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "general", onChange, idBase: "settings" } });

        await fireEvent.click(screen.getByRole("tab", { name: "一般" }));
        expect(onChange).not.toHaveBeenCalled();
    });

    it("ArrowRight で次のタブへ移動し、フォーカスも移る", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "general", onChange, idBase: "settings" } });

        await fireEvent.keyDown(screen.getByRole("tab", { name: "一般" }), {
            key: "ArrowRight",
        });
        expect(onChange).toHaveBeenCalledWith("members");
        const next = screen.getByRole("tab", { name: "メンバー" });
        expect(next).toHaveAttribute("aria-selected", "true");
        expect(document.activeElement).toBe(next);
    });

    it("ArrowLeft で前のタブへ移動する", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "members", onChange, idBase: "settings" } });

        await fireEvent.keyDown(screen.getByRole("tab", { name: "メンバー" }), {
            key: "ArrowLeft",
        });
        expect(onChange).toHaveBeenCalledWith("general");
    });

    it("端ではラップしない (先頭で ArrowLeft / 末尾で ArrowRight は無変化)", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "general", onChange, idBase: "settings" } });

        await fireEvent.keyDown(screen.getByRole("tab", { name: "一般" }), {
            key: "ArrowLeft",
        });
        expect(onChange).not.toHaveBeenCalled();

        await fireEvent.keyDown(screen.getByRole("tab", { name: "一般" }), { key: "End" });
        expect(onChange).toHaveBeenLastCalledWith("billing");
        await fireEvent.keyDown(screen.getByRole("tab", { name: "請求" }), {
            key: "ArrowRight",
        });
        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it("Home/End で先頭/末尾の enabled タブへ移動する", async () => {
        const onChange = vi.fn();
        render(Tabs, { props: { tabs, active: "members", onChange, idBase: "settings" } });

        await fireEvent.keyDown(screen.getByRole("tab", { name: "メンバー" }), { key: "End" });
        expect(onChange).toHaveBeenLastCalledWith("billing");

        await fireEvent.keyDown(screen.getByRole("tab", { name: "請求" }), { key: "Home" });
        expect(onChange).toHaveBeenLastCalledWith("general");
    });

    it("disabled タブは矢印キー移動でスキップされる", async () => {
        const onChange = vi.fn();
        render(Tabs, {
            props: { tabs: tabsWithDisabled, active: "a", onChange, idBase: "x" },
        });

        await fireEvent.keyDown(screen.getByRole("tab", { name: "A" }), {
            key: "ArrowRight",
        });
        expect(onChange).toHaveBeenCalledWith("c");
    });

    it("disabled タブはクリックしても選択されない", async () => {
        const onChange = vi.fn();
        render(Tabs, {
            props: { tabs: tabsWithDisabled, active: "a", onChange, idBase: "x" },
        });

        const disabledTab = screen.getByRole("tab", { name: "B" });
        expect(disabledTab).toBeDisabled();
        expect(disabledTab).toHaveAttribute("tabindex", "-1");
        await fireEvent.click(disabledTab);
        expect(onChange).not.toHaveBeenCalled();
    });

    it("active/inactive/disabled のスタイルが適用される", () => {
        render(Tabs, { props: { tabs: tabsWithDisabled, active: "a", idBase: "x" } });

        expect(screen.getByRole("tab", { name: "A" }).className).toContain("border-primary");
        expect(screen.getByRole("tab", { name: "A" }).className).toContain("text-primary");
        expect(screen.getByRole("tab", { name: "C" }).className).toContain(
            "text-text-secondary",
        );
        expect(screen.getByRole("tab", { name: "B" }).className).toContain(
            "text-border-strong",
        );
        expect(screen.getByRole("tab", { name: "B" }).className).toContain(
            "cursor-not-allowed",
        );
    });
});
