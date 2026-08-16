import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CaptureFileFallback from "@/components/features/capture/CaptureFileFallback.svelte";

/*
 * MediaRecorder 非対応環境のフォールバック撮影。
 * カットの計画が静止画なら accept を image/* に切り替え、選ばれた画像は**必ず再エンコード**
 * してから親へ渡す (寸法上限が効き EXIF が落ちる)。正規化に失敗したら原本は送らない。
 */

const normalizeStillFile = vi.hoisted(() => vi.fn());

vi.mock("@/lib/capture/still-encode", async (importOriginal) => {
    const actual = await importOriginal<typeof import("@/lib/capture/still-encode")>();
    return { ...actual, normalizeStillFile };
});

/** file input に File を差し込んで change を発火する */
async function selectFile(file: File): Promise<void> {
    const input = screen.getByTestId("capture-file-input") as HTMLInputElement;
    Object.defineProperty(input, "files", { value: [file], configurable: true });
    await fireEvent.change(input);
}

beforeEach(() => {
    normalizeStillFile.mockReset();
});

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
});

describe("CaptureFileFallback", () => {
    it("既定 (video) は accept が video/* で、選んだファイルをそのまま渡す", async () => {
        const onCaptured = vi.fn();
        render(CaptureFileFallback, { props: { onCaptured } });

        expect(screen.getByTestId("capture-file-input")).toHaveAttribute("accept", "video/*");

        const file = new File(["mp4"], "a.mp4", { type: "video/mp4" });
        await selectFile(file);

        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
        expect(onCaptured.mock.calls[0][0]).toBe(file); // 動画は再エンコードしない
        expect(onCaptured.mock.calls[0][1]).toBe("video/mp4");
        expect(normalizeStillFile).not.toHaveBeenCalled();
    });

    it("material=still は accept が image/* になり、正規化した blob を image/jpeg で渡す", async () => {
        const normalized = new Blob(["jpeg"], { type: "image/jpeg" });
        normalizeStillFile.mockResolvedValue(normalized);
        const onCaptured = vi.fn();
        render(CaptureFileFallback, { props: { material: "still", onCaptured } });

        expect(screen.getByTestId("capture-file-input")).toHaveAttribute("accept", "image/*");

        const file = new File(["png"], "a.png", { type: "image/png" });
        await selectFile(file);

        await vi.waitFor(() => expect(onCaptured).toHaveBeenCalledTimes(1));
        expect(onCaptured).toHaveBeenCalledWith(normalized, "image/jpeg");
    });

    it("正規化に失敗したら原本を送らずエラー表示する", async () => {
        normalizeStillFile.mockResolvedValue(null);
        const onCaptured = vi.fn();
        render(CaptureFileFallback, { props: { material: "still", onCaptured } });

        await selectFile(new File(["png"], "a.png", { type: "image/png" }));

        await vi.waitFor(() => {
            expect(screen.getByRole("alert")).toHaveTextContent(
                "画像を読み込めませんでした。別のファイルをお試しください。",
            );
        });
        expect(onCaptured).not.toHaveBeenCalled();
    });

    it("still で動画を選ぶとエラー (押下は受けてから理由を出す)", async () => {
        const onCaptured = vi.fn();
        render(CaptureFileFallback, { props: { material: "still", onCaptured } });

        await selectFile(new File(["mp4"], "a.mp4", { type: "video/mp4" }));

        await vi.waitFor(() => {
            expect(screen.getByRole("alert")).toHaveTextContent("画像ファイルを選択してください。");
        });
        expect(onCaptured).not.toHaveBeenCalled();
        expect(normalizeStillFile).not.toHaveBeenCalled();
    });

    it("video で画像を選ぶとエラー (回帰)", async () => {
        const onCaptured = vi.fn();
        render(CaptureFileFallback, { props: { onCaptured } });

        await selectFile(new File(["png"], "a.png", { type: "image/png" }));

        await vi.waitFor(() => {
            expect(screen.getByRole("alert")).toHaveTextContent("動画ファイルを選択してください。");
        });
        expect(onCaptured).not.toHaveBeenCalled();
    });
});
