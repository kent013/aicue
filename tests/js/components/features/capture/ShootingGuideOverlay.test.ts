import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import ShootingGuideOverlay from "@/components/features/capture/ShootingGuideOverlay.svelte";

/*
 * 撮影ガイド (撮影方法 = cuts.shooting_point) の透過オーバーレイ (T186 施策 C)。
 * 表示可否は親が決めるため、本 component は非 null の text だけを受ける。
 *
 * レーンの非交差 (上下の字幕帯と重ならない) は jsdom がレイアウトを持たないため
 * ここでは固定できない。Browser レーンが矩形を実測して固定する
 * (できない検査を component テストに書かない)。
 */

afterEach(() => {
    cleanup();
});

describe("ShootingGuideOverlay", () => {
    it("受け取った text をそのまま描画する", () => {
        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });

        expect(screen.getByTestId("shooting-guide-overlay")).toHaveTextContent("手元を寄りで撮る");
    });

    it("前後の空白を含む文字列も書き換えずに描画する (trim は親の空判定専用)", () => {
        render(ShootingGuideOverlay, { props: { text: "  手元を寄りで撮る  " } });

        expect(
            screen.getByTestId("shooting-guide-overlay").querySelector("span")?.textContent,
        ).toBe("  手元を寄りで撮る  ");
    });

    /*
     * line-clamp-* は display: -webkit-box を敷くため flex と同居できない。
     * 同じ要素に両方付けると生成 CSS の順序次第でどちらかが効かなくなり、
     * 長い撮影ガイドが帯からはみ出して字幕帯と交差しうる (jsdom では見た目を測れないので
     * 「どの要素に付いているか」を構造として固定する)。
     */
    it("行数制限はテキスト要素側にあり、flex を敷いた要素には無い", () => {
        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });

        const overlay = screen.getByTestId("shooting-guide-overlay");
        const panel = overlay.querySelector("p");
        const textEl = overlay.querySelector("span");

        expect(panel?.className).toContain("flex");
        expect(panel?.className).not.toContain("line-clamp");
        expect(textEl?.className).toContain("line-clamp-2");
        expect(textEl?.className).not.toContain("flex");
    });

    it("pointer-events-none を持つ (映像上の操作を邪魔しない)", () => {
        render(ShootingGuideOverlay, { props: { text: "手元を寄りで撮る" } });

        expect(screen.getByTestId("shooting-guide-overlay").className).toContain(
            "pointer-events-none",
        );
    });
});
