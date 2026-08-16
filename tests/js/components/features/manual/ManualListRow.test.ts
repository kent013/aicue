import { describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";
import ManualListRow from "@/components/features/manual/ManualListRow.svelte";
import type { ManualListItem } from "@/types/manual";

/*
 * 動画マニュアル一覧の 1 行 (T182)。
 *
 * 固定する契約:
 * - 再生時間はサーバの duration_ms をそのまま整形する (未確定は「—」)
 * - プレビュー / DL / 削除の導線はサーバが決めた current_finished_render_job_id / deletable
 *   **だけ**で出し分ける (UI 側で published や権限を再判定しない)
 * - プレビューと DL は同じ props 1 本で出し分ける (再生条件は download と完全同一 = T154)
 * - DL は**通常 anchor** (非 Inertia 遷移)。Inertia の Link へ退行したら赤くなる
 * - どの導線も disabled を持たない (禁止事項 8 の回帰封じ)
 *
 * Inertia の `Link` は素の <a href> として描画され判別できる属性を持たないため、
 * 既存のスタブ (tests/js/support/InertiaLinkStub.svelte) へ差し替えて
 * 「描画されたら印が残る」状態で検証する。
 */
vi.mock("@inertiajs/svelte", async () => ({
    Link: (await import("../../../support/InertiaLinkStub.svelte")).default,
}));

function manualItem(overrides: Partial<ManualListItem> = {}): ManualListItem {
    return {
        id: 1,
        title: "ネジ締め作業",
        status: "published",
        category: { id: 1, name: "準備作業" },
        creator: { id: 2, name: "編集 花子" },
        created_at: "2026-07-10 12:00",
        updated_at: "2026-07-11 09:00",
        duration_ms: 185_000,
        current_finished_render_job_id: 9,
        deletable: true,
        ...overrides,
    };
}

function renderRow(
    overrides: Partial<ManualListItem> = {},
    onRequestPreview = vi.fn(),
    onRequestDelete = vi.fn(),
) {
    render(ManualListRow, {
        props: { projectId: 7, manual: manualItem(overrides), onRequestPreview, onRequestDelete },
    });

    return { onRequestPreview, onRequestDelete };
}

describe("features/manual/ManualListRow", () => {
    it("再生時間・状態バッジ・カテゴリ / 作成者 / 更新日を描画する", () => {
        renderRow();

        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("3:05");
        expect(screen.getByTestId("manual-status-1")).toHaveTextContent("公開済み");
        expect(screen.getByText(/準備作業 ・ 編集 花子 ・ 更新 2026-07-11 09:00/)).toBeInTheDocument();
    });

    it("duration_ms が null のときは「—」を表示する (0:00 と書かない)", () => {
        renderRow({ duration_ms: null });

        expect(screen.getByTestId("manual-duration-1")).toHaveTextContent("—");
    });

    it("受け取れる行では DL リンクを download endpoint へ出す", () => {
        renderRow();

        const link = screen.getByTestId("manual-download-1");
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/projects/7/manuals/1/download",
        );
    });

    it("DL は通常 anchor である (Inertia Link へ退行したら赤くなる)", () => {
        renderRow();

        const link = screen.getByTestId("manual-download-1");
        expect(link.tagName).toBe("A");
        // Inertia Link スタブが描画されるのはタイトルリンクの 1 本だけ (DL は素の <a>)
        expect(screen.getAllByTestId("inertia-link-stub")).toHaveLength(1);
    });

    it("current_finished_render_job_id=null のときプレビュー / DL のどちらも出さない (押せないボタンを置かない)", () => {
        renderRow({ current_finished_render_job_id: null });

        expect(screen.queryByTestId("manual-preview-1")).toBeNull();
        expect(screen.queryByTestId("manual-download-1")).toBeNull();
    });

    it("受け取れる行ではプレビューを押すとその行で onRequestPreview が呼ばれる", async () => {
        const { onRequestPreview } = renderRow();

        await fireEvent.click(screen.getByTestId("manual-preview-1"));

        expect(onRequestPreview).toHaveBeenCalledTimes(1);
        expect(onRequestPreview.mock.calls[0][0]).toMatchObject({ id: 1, title: "ネジ締め作業" });
    });

    it("deletable=true のとき削除ボタンを出し、押すとその行で onRequestDelete が呼ばれる", async () => {
        const { onRequestDelete } = renderRow();

        await fireEvent.click(screen.getByTestId("manual-remove-1"));

        expect(onRequestDelete).toHaveBeenCalledTimes(1);
        expect(onRequestDelete.mock.calls[0][0]).toMatchObject({ id: 1, title: "ネジ締め作業" });
    });

    it("deletable=false のとき削除ボタンを出さない", () => {
        renderRow({ deletable: false });

        expect(screen.queryByTestId("manual-remove-1")).toBeNull();
    });

    it("プレビュー / DL / 削除のどれも disabled を持たない (禁止事項 8)", () => {
        // fixture の既定に依存させない。「3 つとも出ている状態」をこのテスト自身が宣言する
        renderRow({ current_finished_render_job_id: 9, deletable: true });

        for (const testId of ["manual-preview-1", "manual-download-1", "manual-remove-1"]) {
            expect(screen.getByTestId(testId)).not.toBeDisabled();
        }
    });

    it("長いタイトルでも省略スタイルが当たっている (jsdom は実寸を計算しないためスタイル契約まで)", () => {
        renderRow({ title: "あ".repeat(200) });

        const title = screen.getAllByTestId("inertia-link-stub")[0];
        expect(title.getAttribute("class") ?? "").toContain("truncate");
        expect(title.closest("div")?.getAttribute("class") ?? "").toContain("min-w-0");
    });
});
