/**
 * Tests for resources/js/lib/capture/panel-navigation.ts
 *
 * 公開契約 (詳細設計 施策 A / bug-hunt F-1-03):
 *   1 カラム (縦積み) のときだけ、カット選択で撮影パネルへ「視点」と「フォーカス」を運ぶ。
 *   2 カラムでは動かさない (デスクトップで勝手に画面が動くのは退行)。
 *   captureActive (録画中 / getUserMedia grant 待ちを含む) の間も動かさない。
 *
 * ここでは **副作用ごと** 固定する。述語だけを切り出すと「抑止条件が実際に focus /
 * scrollIntoView を止めているか」が page component の中でしか検証できず、回帰を防げない
 * (design-review R1 の指摘)。
 *
 * 負のコントロール: captureActive=true / 横並び / 要素 null では focus も scrollIntoView も
 * **1 度も呼ばれない**こと。
 */
import { beforeEach, describe, expect, it, vi } from "vitest";

import {
    isStackedLayout,
    navigateBackToList,
    navigateToPanelIfNeeded,
    prefersReducedMotion,
    scrollBehaviorFor,
} from "@/lib/capture/panel-navigation";

/** getBoundingClientRect だけを差し替えた最小の HTMLElement スタブ。 */
function elementWithRect(rect: { top: number; bottom: number }): HTMLElement {
    const el = document.createElement("div");
    el.getBoundingClientRect = (): DOMRect =>
        ({ top: rect.top, bottom: rect.bottom }) as DOMRect;
    return el;
}

/** focus / scrollIntoView を spy した見出し要素と、呼び出し順の記録。 */
function headingWithSpies(): {
    el: HTMLElement;
    focus: ReturnType<typeof vi.fn>;
    scrollIntoView: ReturnType<typeof vi.fn>;
    calls: string[];
} {
    const el = document.createElement("h2");
    const calls: string[] = [];
    const focus = vi.fn(() => calls.push("focus"));
    const scrollIntoView = vi.fn(() => calls.push("scrollIntoView"));
    el.focus = focus as unknown as HTMLElement["focus"];
    el.scrollIntoView = scrollIntoView as unknown as HTMLElement["scrollIntoView"];
    return { el, focus, scrollIntoView, calls };
}

const STACKED = { left: { top: 0, bottom: 400 }, right: { top: 400, bottom: 900 } };
const SIDE_BY_SIDE = { left: { top: 0, bottom: 400 }, right: { top: 0, bottom: 400 } };

describe("isStackedLayout", () => {
    it("右 pane が左 pane の下にあれば縦積みと判定する", () => {
        expect(
            isStackedLayout(
                { top: 0, bottom: 400 } as DOMRect,
                { top: 400, bottom: 900 } as DOMRect,
            ),
        ).toBe(true);
    });

    it("左右が並んでいれば縦積みではない", () => {
        expect(
            isStackedLayout({ top: 0, bottom: 400 } as DOMRect, { top: 0, bottom: 400 } as DOMRect),
        ).toBe(false);
    });

    it("許容差 4px の内側は縦積みとみなす (sub-pixel / border のズレ吸収)", () => {
        // right.top = left.bottom - 4 → 許容差ちょうどなので縦積み
        expect(
            isStackedLayout(
                { top: 0, bottom: 400 } as DOMRect,
                { top: 396, bottom: 900 } as DOMRect,
            ),
        ).toBe(true);
    });

    it("許容差 4px を超えて重なっていれば縦積みではない", () => {
        expect(
            isStackedLayout(
                { top: 0, bottom: 400 } as DOMRect,
                { top: 395, bottom: 900 } as DOMRect,
            ),
        ).toBe(false);
    });
});

describe("scrollBehaviorFor", () => {
    it("reduced-motion を望むなら smooth を使わない", () => {
        expect(scrollBehaviorFor(true)).toBe("auto");
    });

    it("そうでなければ smooth", () => {
        expect(scrollBehaviorFor(false)).toBe("smooth");
    });
});

describe("prefersReducedMotion", () => {
    const original = window.matchMedia;

    beforeEach(() => {
        window.matchMedia = original;
    });

    it("matchMedia が無い環境では安全側 (true = アニメーションしない) に倒れる", () => {
        // @ts-expect-error 非対応環境の再現
        window.matchMedia = undefined;
        expect(prefersReducedMotion()).toBe(true);
    });

    it("matchMedia の結果をそのまま返す", () => {
        window.matchMedia = vi.fn().mockReturnValue({ matches: true }) as unknown as typeof matchMedia;
        expect(prefersReducedMotion()).toBe(true);

        window.matchMedia = vi
            .fn()
            .mockReturnValue({ matches: false }) as unknown as typeof matchMedia;
        expect(prefersReducedMotion()).toBe(false);
    });
});

describe("navigateToPanelIfNeeded", () => {
    it("縦積み かつ 非 captureActive なら focus → scrollIntoView の順で運ぶ", () => {
        const heading = headingWithSpies();

        const moved = navigateToPanelIfNeeded({
            captureActive: false,
            leftEl: elementWithRect(STACKED.left),
            rightEl: elementWithRect(STACKED.right),
            headingEl: heading.el,
            reducedMotion: false,
        });

        expect(moved).toBe(true);
        // focus() 自体が暗黙スクロールを起こすため preventScroll してから scrollIntoView する
        expect(heading.focus).toHaveBeenCalledWith({ preventScroll: true });
        expect(heading.scrollIntoView).toHaveBeenCalledWith({ behavior: "smooth", block: "start" });
        // 順序も契約 (逆にすると二重移動になる)
        expect(heading.calls).toEqual(["focus", "scrollIntoView"]);
    });

    it("reducedMotion なら behavior が auto になる", () => {
        const heading = headingWithSpies();

        navigateToPanelIfNeeded({
            captureActive: false,
            leftEl: elementWithRect(STACKED.left),
            rightEl: elementWithRect(STACKED.right),
            headingEl: heading.el,
            reducedMotion: true,
        });

        expect(heading.scrollIntoView).toHaveBeenCalledWith({ behavior: "auto", block: "start" });
    });

    it("captureActive の間は視点もフォーカスも奪わない (録画中 / grant 待ち)", () => {
        const heading = headingWithSpies();

        const moved = navigateToPanelIfNeeded({
            captureActive: true,
            leftEl: elementWithRect(STACKED.left),
            rightEl: elementWithRect(STACKED.right),
            headingEl: heading.el,
            reducedMotion: false,
        });

        expect(moved).toBe(false);
        expect(heading.focus).not.toHaveBeenCalled();
        expect(heading.scrollIntoView).not.toHaveBeenCalled();
    });

    it("2 カラム (横並び) では動かさない", () => {
        const heading = headingWithSpies();

        const moved = navigateToPanelIfNeeded({
            captureActive: false,
            leftEl: elementWithRect(SIDE_BY_SIDE.left),
            rightEl: elementWithRect(SIDE_BY_SIDE.right),
            headingEl: heading.el,
            reducedMotion: false,
        });

        expect(moved).toBe(false);
        expect(heading.focus).not.toHaveBeenCalled();
        expect(heading.scrollIntoView).not.toHaveBeenCalled();
    });

    it.each([
        ["leftEl", { leftEl: null }],
        ["rightEl", { rightEl: null }],
        ["headingEl", { headingEl: null }],
    ])("%s が null なら何もしない", (_label, override) => {
        const heading = headingWithSpies();

        const moved = navigateToPanelIfNeeded({
            captureActive: false,
            leftEl: elementWithRect(STACKED.left),
            rightEl: elementWithRect(STACKED.right),
            headingEl: heading.el,
            reducedMotion: false,
            ...override,
        });

        expect(moved).toBe(false);
        expect(heading.focus).not.toHaveBeenCalled();
        expect(heading.scrollIntoView).not.toHaveBeenCalled();
    });
});

describe("navigateBackToList", () => {
    it("focus → scrollIntoView の順で一覧側へ戻す", () => {
        const heading = headingWithSpies();

        expect(navigateBackToList(heading.el, false)).toBe(true);
        expect(heading.focus).toHaveBeenCalledWith({ preventScroll: true });
        expect(heading.calls).toEqual(["focus", "scrollIntoView"]);
    });

    it("要素が無ければ何もしない", () => {
        expect(navigateBackToList(null, false)).toBe(false);
    });
});
