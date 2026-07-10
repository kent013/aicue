import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Avatar from "@/components/atoms/Avatar.svelte";
import {
    stripAllowlisted,
    THEME_PATTERNS,
    UNIVERSAL_PATTERNS,
} from "../../support/ds-purity";

describe("Avatar", () => {
    it("src 無しで name の先頭 1 文字をイニシャル表示する", () => {
        render(Avatar, { props: { name: "田中太郎", testId: "avatar" } });

        const avatar = screen.getByTestId("avatar");
        expect(avatar.tagName).toBe("SPAN");
        expect(avatar.textContent?.trim()).toBe("田");
        expect(avatar.getAttribute("aria-label")).toBe("田中太郎");
    });

    it("英字イニシャルは大文字化する", () => {
        render(Avatar, { props: { name: "alice", testId: "avatar" } });

        expect(screen.getByTestId("avatar").textContent?.trim()).toBe("A");
    });

    it("src 指定で <img> を描画し alt の既定は name", () => {
        render(Avatar, {
            props: { name: "田中太郎", src: "/avatar.png", testId: "avatar" },
        });

        const avatar = screen.getByTestId("avatar");
        expect(avatar.tagName).toBe("IMG");
        expect(avatar).toHaveAttribute("src", "/avatar.png");
        expect(avatar).toHaveAttribute("alt", "田中太郎");
        expect(avatar.className).toContain("object-cover");
    });

    it("真円 (rounded-full) + bg-neutral + text-text-secondary + font-medium", () => {
        render(Avatar, { props: { name: "A", testId: "avatar" } });

        const className = screen.getByTestId("avatar").className;
        expect(className).toContain("rounded-full");
        expect(className).toContain("bg-neutral");
        expect(className).toContain("text-text-secondary");
        expect(className).toContain("font-medium");
    });

    it("size のクラスが適用される (sm/md/lg)", () => {
        for (const [size, sizeClass, rampClass] of [
            ["sm", "size-7", "text-caption"],
            ["md", "size-10", "text-body"],
            ["lg", "size-14", "text-h3"],
        ] as const) {
            const { unmount } = render(Avatar, {
                props: { name: "A", size, testId: `avatar-${size}` },
            });
            const className = screen.getByTestId(`avatar-${size}`).className;
            expect(className).toContain(sizeClass);
            expect(className).toContain(rampClass);
            unmount();
        }
    });

    it("描画クラスが DS-pure (rounded-full は allowlist 済みエントリで許可)", () => {
        render(Avatar, { props: { name: "A", testId: "avatar" } });

        // rounded-full は FILE_SCOPED_ALLOWLIST (truly_circular_ui) で許可されるため除去して検証
        const allClasses = stripAllowlisted(
            "components/atoms/Avatar.svelte",
            screen.getByTestId("avatar").className,
        );
        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
