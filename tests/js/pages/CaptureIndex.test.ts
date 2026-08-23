import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import { router } from "@inertiajs/svelte";
import CaptureIndex from "@/pages/Capture/Index.svelte";
import { MANUAL_SEARCH_PLACEHOLDER } from "@/lib/manual/search";
import type { CaptureManualSummary } from "@/types/capture";

/*
 * 撮影 PWA 一覧 Capture/Index: 自作フィルタ (mine トグル) の GET クエリと
 * カードの作成者名 (null 時「不明」) 表示を固定する。
 */

function makeSummary(overrides: Partial<CaptureManualSummary> = {}): CaptureManualSummary {
    return {
        id: 1,
        title: "ネジ締め作業",
        category_id: 1,
        category_name: "準備作業",
        cuts_total: 3,
        cuts_adopted: 1,
        cuts_with_takes: 2,
        updated_at: "2026-07-11T09:00:00+09:00",
        creator_name: "編集 花子",
        cover: null,
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

    /*
     * T197: 撮影 PWA の進捗語彙 (撮影完了 / 撮影中 / 未撮影) は PC 一覧の 3 値
     * (作成済 / 作成中 / 未着手) とは**別の量**なので寄せない。判定の境界も現行のまま固定する。
     */
    it("撮影進捗バッジは撮影語彙のまま (判定境界も現行どおり)", () => {
        const cases = [
            { counts: { cuts_total: 0, cuts_adopted: 0, cuts_with_takes: 0 }, label: "未撮影" },
            // 構造上生じない不整合 (take は cut に属する)。生じたら現行の三項式どおり「撮影中」
            { counts: { cuts_total: 0, cuts_adopted: 0, cuts_with_takes: 1 }, label: "撮影中" },
            { counts: { cuts_total: 3, cuts_adopted: 1, cuts_with_takes: 2 }, label: "撮影中" },
            { counts: { cuts_total: 3, cuts_adopted: 3, cuts_with_takes: 3 }, label: "撮影完了" },
        ];

        for (const { counts, label } of cases) {
            const { unmount } = render(CaptureIndex, {
                props: { ...baseProps, manuals: [makeSummary(counts)] },
            });

            expect(screen.getByText(label)).toBeInTheDocument();
            // PC 一覧の語彙へ寄せていないこと (別の量なので統合しない)
            for (const pcLabel of ["未着手", "作成中", "作成済"]) {
                expect(screen.queryByText(pcLabel)).toBeNull();
            }
            unmount();
        }
    });

    it("自作トグルで GET クエリに mine=1 が載る", async () => {
        const getSpy = vi.spyOn(router, "get").mockImplementation(() => {});
        render(CaptureIndex, { props: baseProps });

        await fireEvent.click(screen.getByTestId("capture-mine"));

        expect(getSpy).toHaveBeenCalledTimes(1);
        expect(getSpy.mock.calls[0][0]).toBe("/organizations/test-org/app/projects/1/manuals");
        expect(getSpy.mock.calls[0][1]).toEqual({ mine: "1" });
    });

    it("filters.mine=true は props からトグル状態を復元する", () => {
        render(CaptureIndex, {
            props: { ...baseProps, filters: { category: null, q: null, mine: true } },
        });

        expect((screen.getByTestId("capture-mine") as HTMLInputElement).checked).toBe(true);
    });

    /*
     * T198: 代表サムネイル。URL は take-endpoints の規則ただ 1 つで組み立てる
     * (リテラル比較が落ちたら URL 規則の破壊的変更であり、落ちるのが正しい)。
     */
    it("cover が非 null なら代表サムネイルの URL を組み立てて描く", () => {
        render(CaptureIndex, {
            props: {
                ...baseProps,
                manuals: [makeSummary({ cover: { cut_id: 7, take_id: 9 } })],
            },
        });

        const element = screen.getByTestId("capture-cover-1");
        expect(element.dataset.state).toBe("image");
        expect(element.getAttribute("src")).toBe(
            "/organizations/test-org/app/projects/1/manuals/1/cuts/7/takes/9/thumbnail",
        );
    });

    /*
     * T199: 撮影 PWA からアカウント確認画面への入口。共有端末で「今このアプリに
     * ログインしているのは誰か」を確かめる導線がここにしか無いので、リンク先を固定する。
     */
    it("見出しにアカウント確認画面への導線がある", () => {
        render(CaptureIndex, { props: baseProps });

        // Inertia の Link は jsdom で href を絶対 URL に解決するため末尾一致で固定する
        expect(screen.getByTestId("capture-account-link").getAttribute("href")).toMatch(
            /\/app\/account$/,
        );
    });

    it("cover が null ならプレースホルダを描く", () => {
        render(CaptureIndex, { props: baseProps });

        expect(screen.getByTestId("capture-cover-1").dataset.state).toBe("placeholder");
    });
    /*
     * T202: 検索欄の説明文言は PC 一覧と共有の定数 (MANUAL_SEARCH_PLACEHOLDER) を使う。
     * 文字列リテラルを写さず定数と比較するので、片方の画面だけ文言を直したら赤くなる。
     */
    it("検索欄の placeholder は共有定数を使う", () => {
        render(CaptureIndex, { props: baseProps });

        expect(screen.getByTestId("capture-search").getAttribute("placeholder")).toBe(
            MANUAL_SEARCH_PLACEHOLDER,
        );
    });
});
