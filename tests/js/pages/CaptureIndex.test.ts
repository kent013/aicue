import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { router } from "@inertiajs/svelte";
import CaptureIndex from "@/pages/Capture/Index.svelte";
import type { CaptureManualSummary } from "@/types/capture";

/*
 * 撮影 PWA 一覧 Capture/Index: 自作フィルタ (mine トグル) の GET クエリと
 * カードの作成者名 (null 時「不明」) 表示を固定する。
 */

function makeSummary(overrides: Partial<CaptureManualSummary> = {}): CaptureManualSummary {
    return {
        id: 1,
        title: "ネジ締め作業",
        status: "ready",
        category_id: 1,
        category_name: "準備作業",
        cuts_total: 3,
        cuts_adopted: 1,
        cuts_with_takes: 2,
        updated_at: "2026-07-11T09:00:00+09:00",
        creator_name: "編集 花子",
        ...overrides,
    };
}

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manuals: [makeSummary()],
    categories: [{ id: 1, name: "準備作業" }],
    filters: { category: null, q: null, mine: false },
};

describe("Capture/Index 自作フィルタ・作成者表示", () => {
    afterEach(() => {
        vi.restoreAllMocks();
    });

    it("カードに作成者名と更新日を描画する", () => {
        render(CaptureIndex, { props: baseProps });

        expect(screen.getByText(/編集 花子 ・ 更新/)).toBeInTheDocument();
    });

    it("creator_name が null のときは「不明」を表示する", () => {
        render(CaptureIndex, {
            props: { ...baseProps, manuals: [makeSummary({ creator_name: null })] },
        });

        expect(screen.getByText(/不明 ・ 更新/)).toBeInTheDocument();
    });

    it("自作トグルで GET クエリに mine=1 が載る", async () => {
        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
        render(CaptureIndex, { props: baseProps });

        await fireEvent.click(screen.getByTestId("capture-mine"));

        expect(getSpy).toHaveBeenCalledTimes(1);
        expect(getSpy.mock.calls[0][0]).toBe("/app/projects/1/manuals");
        expect(getSpy.mock.calls[0][1]).toEqual({ mine: "1" });
    });

    it("filters.mine=true は props からトグル状態を復元する", () => {
        render(CaptureIndex, {
            props: { ...baseProps, filters: { category: null, q: null, mine: true } },
        });

        expect((screen.getByTestId("capture-mine") as HTMLInputElement).checked).toBe(true);
    });
});
