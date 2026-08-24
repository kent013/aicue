import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Choose from "@/pages/Organizations/Choose.svelte";

/**
 * 組織を選ぶ画面 (家系裁定 AG-037)。
 *
 * 固定する契約:
 * - 所属組織の**リンク**を出す (切替 POST は無い = 状態を保存しない)
 * - 遷移先は target ("capture" / "dashboard") で決まり、**組織ごとの URL** になる
 * - **自動選択しない** (複数所属ならこの画面が出るのが仕様)
 */
const organizations = [
    { id: 1, name: "あ組織", slug: "a-genba" },
    { id: 2, name: "い組織", slug: "i-genba" },
];

/** Inertia の Link は jsdom で origin つき絶対 URL へ解決されるため path で比べる。 */
function pathOf(testId: string): string {
    const href = screen.getByTestId(testId).getAttribute("href") ?? "";
    return new URL(href, window.location.href).pathname;
}

describe("Organizations/Choose", () => {
    it("dashboard 入口: 各組織のダッシュボード URL へのリンクを出す", () => {
        render(Choose, { props: { target: "dashboard", organizations } });

        expect(pathOf("organization-choice-a-genba")).toBe("/organizations/a-genba/dashboard");
        expect(pathOf("organization-choice-i-genba")).toBe("/organizations/i-genba/dashboard");
    });

    it("capture 入口: 各組織の撮影 URL へのリンクを出す", () => {
        render(Choose, { props: { target: "capture", organizations } });

        expect(pathOf("organization-choice-a-genba")).toBe("/organizations/a-genba/app");
    });

    it("組織名を省略なく出す (どれを選ぶか判断できる)", () => {
        render(Choose, { props: { target: "dashboard", organizations } });

        expect(screen.getByText("あ組織")).toBeInTheDocument();
        expect(screen.getByText("い組織")).toBeInTheDocument();
    });

    it("ブックマークの案内を出す (毎回この画面を通らずに済むことを伝える)", () => {
        render(Choose, { props: { target: "dashboard", organizations } });

        expect(screen.getByText(/ブックマーク/)).toBeInTheDocument();
    });

    it("disabled 属性を一切持たない (必須未充足 disabled UI 禁止)", () => {
        render(Choose, { props: { target: "dashboard", organizations } });

        expect(screen.getByTestId("organization-choice-a-genba")).not.toHaveAttribute("disabled");
    });
});
