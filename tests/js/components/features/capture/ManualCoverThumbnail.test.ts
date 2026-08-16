import { describe, expect, it } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import ManualCoverThumbnail from "@/components/features/capture/ManualCoverThumbnail.svelte";

/*
 * T198: 撮影一覧カードの代表サムネイル。
 *
 * 表示するか否かはサーバが決めており、この component は「与えられた URL を出す /
 * 出せなければ同寸法のプレースホルダを描く」だけを持つ。分岐は data-state で見る
 * (枠と data-testid は 2 分岐で同じ = レイアウトを跳ねさせないため)。
 */

const SRC = "/app/projects/1/manuals/1/cuts/7/takes/9/thumbnail";

describe("ManualCoverThumbnail", () => {
    it("src が非 null なら画像を描き lazy 読み込みにする", () => {
        render(ManualCoverThumbnail, { props: { src: SRC, testId: "cover" } });

        const element = screen.getByTestId("cover");
        expect(element.dataset.state).toBe("image");
        expect(element.tagName).toBe("IMG");
        expect(element.getAttribute("src")).toBe(SRC);
        expect(element.getAttribute("loading")).toBe("lazy");
    });

    it("src が null ならプレースホルダを描く", () => {
        render(ManualCoverThumbnail, { props: { src: null, testId: "cover" } });

        const element = screen.getByTestId("cover");
        expect(element.dataset.state).toBe("placeholder");
        expect(element.tagName).not.toBe("IMG");
    });

    it("読み込みに失敗したらプレースホルダへ落ちる (壊れた画像アイコンを出さない)", async () => {
        render(ManualCoverThumbnail, { props: { src: SRC, testId: "cover" } });

        await fireEvent.error(screen.getByTestId("cover"));

        expect(screen.getByTestId("cover").dataset.state).toBe("placeholder");
    });

    it("src を差し替えると再び画像を描く (失敗の記憶が新しい URL に及ばない)", async () => {
        const { rerender } = render(ManualCoverThumbnail, {
            props: { src: SRC, testId: "cover" },
        });

        await fireEvent.error(screen.getByTestId("cover"));
        expect(screen.getByTestId("cover").dataset.state).toBe("placeholder");

        const next = "/app/projects/1/manuals/1/cuts/8/takes/10/thumbnail";
        await rerender({ src: next, testId: "cover" });

        const element = screen.getByTestId("cover");
        expect(element.dataset.state).toBe("image");
        expect(element.getAttribute("src")).toBe(next);
    });
});
