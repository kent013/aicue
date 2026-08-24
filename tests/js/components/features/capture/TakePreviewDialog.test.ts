import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import TakePreviewDialog from "@/components/features/capture/TakePreviewDialog.svelte";
import type { CaptureCut, CaptureTake } from "@/types/capture";

/*
 * TakePreviewDialog: テイク生映像を native <video controls> で再生し、
 * cut 固定字幕を overlay で重ねる。字幕トグルと採用ボタンを同居させる。
 * close / 採用成功 / take 差し替えで video を完全 teardown する (資源解放)。
 */

function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
    return {
        id: 10,
        client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
        status: "ready",
        material_type: "video",
        size_bytes: 1024 * 1024,
        duration_ms: 4000,
        comment: null,
        captured_at: null,
        sort_order: 0,
        downloaded: false,
        has_thumbnail: false,
        playback_url: null,
        download_ack_token: null,
        ...overrides,
    };
}

function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
    return {
        id: 3,
        type: "step",
        parent_cut_id: null,
        scene: "作業台を準備する",
        shot_type: "hiki",
        shooting_point: null,
        narration: "作業台の準備を行います",
        subtitle_primary: "STEP 1",
        subtitle_secondary: "作業台を準備する",
        material_type: null,
        adopted_take_id: null,
        adopted_ready_take_id: null,
        takes: [],
        ...overrides,
    };
}

beforeEach(() => {
    // jsdom は HTMLMediaElement の再生系メソッドを未実装
    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe("TakePreviewDialog", () => {
    it("open + take で video を表示し src に playbackUrl を使う", async () => {
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/organizations/test-org/app/projects/1/manuals/2/cuts/3/takes/10/playback",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        const video = await screen.findByTestId("take-preview-video");
        expect(video).toHaveAttribute("src", "/organizations/test-org/app/projects/1/manuals/2/cuts/3/takes/10/playback");
    });

    it("初回 open 後も video の src が残る (誤 teardown しない)", async () => {
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed/take-10",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        const video = await screen.findByTestId("take-preview-video");
        // effect flush 後も src が残っている (初回 mount で cleanup を走らせない)
        await waitFor(() => expect(video).toHaveAttribute("src", "/signed/take-10"));
    });

    it("字幕は初期 ON で overlay を表示し、トグルで非表示になる", async () => {
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        expect(screen.getByTestId("take-preview-subtitle-primary")).toHaveTextContent("STEP 1");
        expect(screen.getByTestId("take-preview-subtitle-secondary")).toHaveTextContent(
            "作業台を準備する",
        );

        await fireEvent.click(screen.getByTestId("take-preview-subtitle-toggle"));

        expect(screen.queryByTestId("take-preview-subtitle-primary")).not.toBeInTheDocument();
        expect(screen.queryByTestId("take-preview-subtitle-secondary")).not.toBeInTheDocument();
    });

    it("字幕 overlay は aria-live=off (読み上げ事故防止)", async () => {
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        expect(screen.getByTestId("take-preview-subtitle-secondary")).toHaveAttribute(
            "aria-live",
            "off",
        );
    });

    it("採用ボタンで onAdopt を呼ぶ", async () => {
        const onAdopt = vi.fn();
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed",
            adopting: false,
            error: null,
            onAdopt,
            onClose: vi.fn(),
        });

        await fireEvent.click(screen.getByTestId("take-preview-adopt"));
        expect(onAdopt).toHaveBeenCalledTimes(1);
    });

    it("採用エラーを role=alert で表示する", () => {
        render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed",
            adopting: false,
            error: "採用に失敗しました。",
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        expect(screen.getByTestId("take-preview-error")).toHaveTextContent("採用に失敗しました。");
    });

    it("close 遷移 (open true→false) で video を teardown し onClose をちょうど 1 回呼ぶ", async () => {
        const onClose = vi.fn();
        const { rerender } = render(TakePreviewDialog, {
            open: true,
            take: makeTake(),
            cut: makeCut(),
            playbackUrl: "/signed",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose,
        });

        await screen.findByTestId("take-preview-video");
        const pause = vi.spyOn(HTMLMediaElement.prototype, "pause");

        await rerender({ open: false });

        await waitFor(() => expect(onClose).toHaveBeenCalledTimes(1));
        // 要素が DOM から外れ、cleanup で pause される (資源解放)
        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
        expect(pause).toHaveBeenCalled();
    });

    it("mount 時 open=false では onClose を発火させない", async () => {
        const onClose = vi.fn();
        render(TakePreviewDialog, {
            open: false,
            take: null,
            cut: makeCut(),
            playbackUrl: null,
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose,
        });

        await waitFor(() => expect(onClose).not.toHaveBeenCalled());
    });

    it("take 差し替えで新 take の src に更新される ({#key} で要素再生成)", async () => {
        const { rerender } = render(TakePreviewDialog, {
            open: true,
            take: makeTake({ id: 10 }),
            cut: makeCut(),
            playbackUrl: "/signed/take-10",
            adopting: false,
            error: null,
            onAdopt: vi.fn(),
            onClose: vi.fn(),
        });

        const first = await screen.findByTestId("take-preview-video");
        expect(first).toHaveAttribute("src", "/signed/take-10");

        await rerender({ take: makeTake({ id: 20 }), playbackUrl: "/signed/take-20" });

        await waitFor(() =>
            expect(screen.getByTestId("take-preview-video")).toHaveAttribute(
                "src",
                "/signed/take-20",
            ),
        );
    });
    /*
     * 静止画テイクは <video> ではなく <img> で出す。素材種別は**申告 Content-Type からの分類**
     * であって実体の形式を保証しないため、読み込み失敗の受け皿を必ず置く
     * (「何も出ない」状態を作らない)。<video> 側には足さない = 非対称は意図的。
     */
    describe("静止画テイク", () => {
        function renderStill(id = 10) {
            return render(TakePreviewDialog, {
                open: true,
                take: makeTake({ id, material_type: "still" }),
                cut: makeCut(),
                cutLabel: "手順1",
                playbackUrl: `/signed/take-${id}`,
                adopting: false,
                error: null,
                onAdopt: vi.fn(),
                onClose: vi.fn(),
            });
        }

        it("still は <img> を出し <video> を出さない", async () => {
            renderStill();

            const image = await screen.findByTestId("take-preview-image");
            expect(image).toHaveAttribute("src", "/signed/take-10");
            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
        });

        it("video は従来どおり <video> (回帰)", async () => {
            render(TakePreviewDialog, {
                open: true,
                take: makeTake(),
                cut: makeCut(),
                cutLabel: "手順1",
                playbackUrl: "/signed/take-10",
                adopting: false,
                error: null,
                onAdopt: vi.fn(),
                onClose: vi.fn(),
            });

            expect(await screen.findByTestId("take-preview-video")).toBeInTheDocument();
            expect(screen.queryByTestId("take-preview-image")).not.toBeInTheDocument();
        });

        it("読み込み失敗で受け皿に差し替わる", async () => {
            renderStill();

            await fireEvent.error(await screen.findByTestId("take-preview-image"));

            await waitFor(() =>
                expect(screen.getByTestId("take-preview-unavailable")).toHaveTextContent(
                    "このテイクはプレビューできません。",
                ),
            );
        });

        it("テイクを切り替えると失敗状態がリセットされる", async () => {
            const { rerender } = renderStill(10);
            await fireEvent.error(await screen.findByTestId("take-preview-image"));
            await screen.findByTestId("take-preview-unavailable");

            await rerender({
                take: makeTake({ id: 20, material_type: "still" }),
                playbackUrl: "/signed/take-20",
            });

            await waitFor(() =>
                expect(screen.getByTestId("take-preview-image")).toHaveAttribute(
                    "src",
                    "/signed/take-20",
                ),
            );
        });
    });
});
