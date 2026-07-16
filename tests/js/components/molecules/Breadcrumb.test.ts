import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import Breadcrumb from "@/components/molecules/Breadcrumb.svelte";

afterEach(() => cleanup());

describe("molecules/Breadcrumb", () => {
    it("href あり=リンク / href 無し=現在位置 span", () => {
        render(Breadcrumb, {
            props: { items: [{ label: "親", href: "/parent" }, { label: "現在" }] },
        });
        const link = screen.getByRole("link", { name: "親" });
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe("/parent");
        expect(screen.getByText("現在").tagName).toBe("SPAN");
    });
});
