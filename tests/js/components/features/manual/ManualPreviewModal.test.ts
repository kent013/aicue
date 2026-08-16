import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import ManualPreviewModal from "@/components/features/manual/ManualPreviewModal.svelte";
import type { ManualListItem } from "@/types/manual";

/*
 * 動画一覧からの完成動画プレビュー (T189)。
 *
 * 固定する契約:
 * - src は行 props の id から組み立てた playback endpoint (同一オリジンのアプリ route)
 * - 再生制御はブラウザ標準の controls に委ねる (自前の再生 UI を持たない)
 * - preload="none" の**指定が付いている**こと (HTTP 要求が 0 件であることの証明ではない)
 * - 閉じている間 / サーバが不可と判断した行では <video> を描画しない
 */

function item(overrides: Partial<ManualListItem> = {}): ManualListItem {
    return {
        id: 2,
        title: "洗浄手順",
        progress: "completed",
        category: null,
        creator: null,
        created_at: "2026-07-10 13:00",
        updated_at: "2026-07-11 10:00",
        duration_ms: 185_000,
        current_finished_render_job_id: 9,
        deletable: true,
        ...overrides,
    };
}

describe("features/manual/ManualPreviewModal", () => {
    it("開いているとき playback endpoint を src に持つ video を描画する", async () => {
        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });

        const video = await screen.findByTestId("manual-preview-video");
        expect(video.getAttribute("src")).toBe("/projects/7/manuals/2/render-jobs/9/playback");
        expect(video).toHaveAttribute("controls");
    });

    it("video に preload=none の指定が付いている (先読みを抑制する指示)", async () => {
        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });

        const video = await screen.findByTestId("manual-preview-video");
        expect(video).toHaveAttribute("preload", "none");
    });

    it("見出しに対象行のタイトルが出る", async () => {
        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: true } });

        const modal = await screen.findByTestId("manual-preview-modal");
        expect(modal).toHaveTextContent("洗浄手順");
    });

    it("閉じているとき video を描画しない (署名 URL 要求を出さない)", () => {
        render(ManualPreviewModal, { props: { projectId: 7, manual: item(), open: false } });

        expect(screen.queryByTestId("manual-preview-video")).toBeNull();
    });

    it("受け取れない行 (id=null) では video を描画しない", async () => {
        render(ManualPreviewModal, {
            props: {
                projectId: 7,
                manual: item({ current_finished_render_job_id: null }),
                open: true,
            },
        });

        await screen.findByTestId("manual-preview-modal");
        expect(screen.queryByTestId("manual-preview-video")).toBeNull();
    });
});
