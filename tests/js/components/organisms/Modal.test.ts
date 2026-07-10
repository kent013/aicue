import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import Modal from "@/components/organisms/Modal.svelte";
import { SIZE_CLASSES } from "@/components/organisms/Modal.types";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

const bodySnippet = createRawSnippet(() => ({
    render: () => "<p>本文テキスト</p>",
}));

describe("Modal", () => {
    it("open=true で title と本文が描画される (portal 先は document.body)", async () => {
        render(Modal, {
            props: { open: true, title: "設定の変更", children: bodySnippet, testId: "modal" },
        });

        expect(await screen.findByText("設定の変更")).toBeInTheDocument();
        expect(screen.getByText("本文テキスト")).toBeInTheDocument();
        expect(screen.getByTestId("modal")).toBeInTheDocument();
    });

    it("open=false では何も描画されない", () => {
        render(Modal, { props: { open: false, title: "非表示", testId: "modal" } });

        expect(screen.queryByText("非表示")).not.toBeInTheDocument();
        expect(screen.queryByTestId("modal")).not.toBeInTheDocument();
    });

    it("dialog role と aria-labelledby (title) が配線される", async () => {
        render(Modal, { props: { open: true, title: "アクセシブル名" } });

        const dialog = await screen.findByRole("dialog");
        const labelledBy = dialog.getAttribute("aria-labelledby") ?? "";
        expect(labelledBy).not.toBe("");
        expect(document.getElementById(labelledBy)?.textContent).toBe("アクセシブル名");
    });

    it("閉じる X ボタンがあり、processing 中は disabled になる", async () => {
        render(Modal, { props: { open: true, title: "処理中", processing: true } });

        const closeButton = await screen.findByRole("button", { name: "閉じる" });
        expect(closeButton).toBeDisabled();
    });

    it("size に応じた最大幅クラスが適用される (既定 md=max-w-lg)", async () => {
        render(Modal, { props: { open: true, title: "サイズ", testId: "modal" } });

        const content = await screen.findByTestId("modal");
        expect(content.className).toContain(SIZE_CLASSES.md);
    });

    it("footer snippet が描画される", async () => {
        const footer = createRawSnippet(() => ({
            render: () => "<span>アクション行</span>",
        }));
        render(Modal, { props: { open: true, title: "フッター", footer } });

        expect(await screen.findByText("アクション行")).toBeInTheDocument();
    });

    it("SIZE_CLASSES が DS-pure (禁止パターンを含まない)", () => {
        const allClasses = Object.values(SIZE_CLASSES).join(" ");

        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
