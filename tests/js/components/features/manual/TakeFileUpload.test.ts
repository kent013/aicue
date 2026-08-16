import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import TakeFileUpload from "@/components/features/manual/TakeFileUpload.svelte";

/*
 * PC ローカル素材の追加アップロード。
 * カットの計画が静止画なら accept を image/* に切り替え、**必ず再エンコードしてから**送る
 * (寸法上限が効き EXIF が落ちる)。静止画に尺は無いので尺の事前チェックは通さない。
 * 正規化に失敗したら原本を送らない (upload-url を呼ばない = quota を消費しない)。
 */

const enqueueMock = vi.hoisted(() => vi.fn());
const deleteMock = vi.hoisted(() => vi.fn());
const normalizeStillFile = vi.hoisted(() => vi.fn());

vi.mock("@/lib/capture/upload-queue", () => ({
    createMemoryPendingStore: () => ({ delete: deleteMock }),
    generateClientTakeId: () => "01ARZ3NDEKTSV4RRFFQ69G5FAV",
    UploadQueue: class {
        enqueue = enqueueMock;
    },
}));

vi.mock("@/lib/capture/still-encode", async (importOriginal) => {
    const actual = await importOriginal<typeof import("@/lib/capture/still-encode")>();
    return { ...actual, normalizeStillFile };
});

const baseProps = { projectId: 1, manualId: 5, cutId: 11, onUploaded: () => undefined };

/** 動画の尺読み取り (readDurationMs) を決定的にする。jsdom は <video> のロードを実装しない */
function stubVideoMetadata(seconds: number): void {
    const createElement = document.createElement.bind(document);
    vi.spyOn(document, "createElement").mockImplementation(((tag: string) => {
        const element = createElement(tag);
        if (tag !== "video") return element;
        const video = element as HTMLVideoElement;
        Object.defineProperty(video, "duration", { configurable: true, get: () => seconds });
        Object.defineProperty(video, "src", {
            configurable: true,
            get: () => "",
            set: () => queueMicrotask(() => video.onloadedmetadata?.(new Event("loadedmetadata"))),
        });
        return video;
    }) as typeof document.createElement);
    vi.stubGlobal("URL", {
        ...URL,
        createObjectURL: () => "blob:stub",
        revokeObjectURL: () => undefined,
    });
}

async function selectFile(file: File): Promise<void> {
    const input = screen.getByTestId("take-file-input") as HTMLInputElement;
    Object.defineProperty(input, "files", { value: [file], configurable: true });
    await fireEvent.change(input);
}

beforeEach(() => {
    enqueueMock.mockReset();
    enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
        Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
    );
    deleteMock.mockReset();
    normalizeStillFile.mockReset();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("TakeFileUpload", () => {
    it("既定 (計画なし) は動画扱い: accept=video/* で、選んだファイルをそのまま enqueue する", async () => {
        stubVideoMetadata(20);
        render(TakeFileUpload, { props: baseProps });

        expect(screen.getByTestId("take-file-input")).toHaveAttribute("accept", "video/*");

        const file = new File(["mp4"], "a.mp4", { type: "video/mp4" });
        await selectFile(file);

        await vi.waitFor(() => expect(enqueueMock).toHaveBeenCalledTimes(1));
        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
            blob: file,
            contentType: "video/mp4",
        });
        expect(normalizeStillFile).not.toHaveBeenCalled();
    });

    it("material=still は accept=image/* で、正規化した blob を image/jpeg / 尺 null で送る", async () => {
        const normalized = new Blob(["jpeg"], { type: "image/jpeg" });
        normalizeStillFile.mockResolvedValue(normalized);
        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });

        expect(screen.getByTestId("take-file-input")).toHaveAttribute("accept", "image/*");

        await selectFile(new File(["png"], "a.png", { type: "image/png" }));

        await vi.waitFor(() => expect(enqueueMock).toHaveBeenCalledTimes(1));
        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
            blob: normalized,
            contentType: "image/jpeg",
            durationMs: null, // 画像に尺は無い (readDurationMs を通さない)
        });
    });

    it("正規化に失敗したら enqueue せずエラー表示する (原本を送らない)", async () => {
        normalizeStillFile.mockResolvedValue(null);
        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });

        await selectFile(new File(["png"], "a.png", { type: "image/png" }));

        await vi.waitFor(() => {
            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
                "画像を読み込めませんでした。別のファイルをお試しください。",
            );
        });
        expect(enqueueMock).not.toHaveBeenCalled();
    });

    it("still カットで動画を選ぶと enqueue しない (押下は受けてから理由を出す)", async () => {
        render(TakeFileUpload, { props: { ...baseProps, material: "still" } });

        await selectFile(new File(["mp4"], "a.mp4", { type: "video/mp4" }));

        await vi.waitFor(() => {
            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
                "画像ファイルを選択してください。",
            );
        });
        expect(enqueueMock).not.toHaveBeenCalled();
        expect(normalizeStillFile).not.toHaveBeenCalled();
    });

    it("video カットで画像を選ぶと enqueue しない (回帰)", async () => {
        render(TakeFileUpload, { props: { ...baseProps, material: "video" } });

        await selectFile(new File(["png"], "a.png", { type: "image/png" }));

        await vi.waitFor(() => {
            expect(screen.getByTestId("take-upload-error")).toHaveTextContent(
                "動画ファイルを選択してください。",
            );
        });
        expect(enqueueMock).not.toHaveBeenCalled();
    });
});
