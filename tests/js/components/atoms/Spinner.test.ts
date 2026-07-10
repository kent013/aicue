import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Spinner from "@/components/atoms/Spinner.svelte";
import { THEME_PATTERNS, UNIVERSAL_PATTERNS } from "../../support/ds-purity";

describe("Spinner", () => {
    it("既定で aria-hidden の装飾扱い (role なし) + size-5 (md) + animate-spin", () => {
        render(Spinner, { props: { testId: "spinner" } });

        const spinner = screen.getByTestId("spinner");
        expect(spinner.getAttribute("aria-hidden")).toBe("true");
        expect(spinner.getAttribute("role")).toBeNull();

        const svg = spinner.querySelector("svg");
        expect(svg).not.toBeNull();
        const svgClass = svg?.getAttribute("class") ?? "";
        expect(svgClass).toContain("animate-spin");
        expect(svgClass).toContain("size-5");
    });

    it("size のクラスが適用される (sm=size-4 / lg=size-8 / xl=size-12)", () => {
        for (const [size, expected] of [
            ["sm", "size-4"],
            ["lg", "size-8"],
            ["xl", "size-12"],
        ] as const) {
            const { unmount } = render(Spinner, { props: { size, testId: `spinner-${size}` } });
            const svgClass =
                screen.getByTestId(`spinner-${size}`).querySelector("svg")?.getAttribute("class") ?? "";
            expect(svgClass).toContain(expected);
            unmount();
        }
    });

    it("label 指定時は role=status + sr-only テキストで読み上げる", () => {
        render(Spinner, { props: { label: "読み込み中", testId: "spinner" } });

        const spinner = screen.getByTestId("spinner");
        expect(spinner.getAttribute("role")).toBe("status");
        expect(spinner.getAttribute("aria-hidden")).toBeNull();

        const srOnly = spinner.querySelector(".sr-only");
        expect(srOnly?.textContent).toBe("読み込み中");
    });

    it("色クラスを持たず currentColor を継承する (text-* 色指定なし)", () => {
        render(Spinner, { props: { testId: "spinner" } });

        const spinner = screen.getByTestId("spinner");
        const svgClass = spinner.querySelector("svg")?.getAttribute("class") ?? "";
        expect(`${spinner.className} ${svgClass}`).not.toMatch(/\btext-(?!caption|body)/);
    });

    it("描画クラスが DS-pure (禁止パターンを含まない)", () => {
        render(Spinner, { props: { label: "読み込み中", testId: "spinner" } });

        const spinner = screen.getByTestId("spinner");
        const svgClass = spinner.querySelector("svg")?.getAttribute("class") ?? "";
        const allClasses = `${spinner.className} ${svgClass}`;
        for (const [pattern] of [...UNIVERSAL_PATTERNS, ...THEME_PATTERNS]) {
            expect(allClasses).not.toMatch(pattern);
        }
    });
});
