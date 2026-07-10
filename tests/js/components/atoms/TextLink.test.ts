import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { createRawSnippet } from "svelte";
import { ExternalLink } from "@lucide/svelte";
import TextLink from "@/components/atoms/TextLink.svelte";
import type { TextLinkProps } from "@/components/atoms/TextLink.types";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

const children = createRawSnippet(() => ({
    render: () => "<span>リンク</span>",
}));

describe("TextLink", () => {
    it("href のみで SPA 遷移用の <a> (Inertia Link) を描画する", () => {
        render(TextLink, { props: { href: "/dashboard", children, testId: "link" } });

        const link = screen.getByTestId("link");
        expect(link.tagName).toBe("A");
        // Inertia Link は jsdom 上で href を絶対 URL に正規化するため path で検証する
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/dashboard",
        );
        // 内部リンクは別タブ・外部アイコンを持たない
        expect(link.getAttribute("target")).toBeNull();
        expect(link.querySelector("svg")).toBeNull();
    });

    it("external で target=_blank + rel=noopener noreferrer + 末尾アイコン", () => {
        render(TextLink, {
            props: { href: "https://example.com", external: true, children, testId: "link" },
        });

        const link = screen.getByTestId("link");
        expect(link.tagName).toBe("A");
        expect(link).toHaveAttribute("target", "_blank");
        const rel = link.getAttribute("rel") ?? "";
        expect(rel).toContain("noopener");
        expect(rel).toContain("noreferrer");

        const icon = link.querySelector("svg");
        expect(icon).not.toBeNull();
        expect(icon?.getAttribute("aria-hidden")).toBe("true");
        // アイコンは末尾 (children の後) に位置する
        expect(link.lastElementChild?.tagName.toLowerCase()).toBe("svg");
    });

    it("onclick のみでリンク風 <button type=button> を描画し click を発火する", async () => {
        const onclick = vi.fn();
        render(TextLink, { props: { onclick, children, testId: "link" } });

        const button = screen.getByTestId("link");
        expect(button.tagName).toBe("BUTTON");
        expect(button).toHaveAttribute("type", "button");
        await fireEvent.click(button);
        expect(onclick).toHaveBeenCalledTimes(1);
    });

    it("共通のテキストリンク様式 (text-primary + underline) を持つ", () => {
        render(TextLink, { props: { href: "/x", children, testId: "link" } });

        const className = screen.getByTestId("link").className;
        expect(className).toContain("text-primary");
        expect(className).toContain("underline");
        expect(className).toContain("decoration-primary/30");
        expect(className).toContain("underline-offset-2");
    });

    it("icon は external モードのみ指定できる (型レベル)", () => {
        // @ts-expect-error 内部リンクでは icon を指定できない
        const _internalIcon: TextLinkProps = { href: "/x", icon: ExternalLink };
        // @ts-expect-error ボタンモードでは href も external も持てない
        const _buttonExternal: TextLinkProps = { onclick: () => {}, external: true };
        expect(true).toBe(true);
    });

    it("描画クラスが DS-pure (禁止パターンを含まない)", () => {
        render(TextLink, {
            props: { href: "https://example.com", external: true, children, testId: "link" },
        });

        const link = screen.getByTestId("link");
        const svgClass = link.querySelector("svg")?.getAttribute("class") ?? "";
        const allClasses = `${link.className} ${svgClass}`;
        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
