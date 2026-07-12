import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CaptureShow from "@/pages/Capture/Show.svelte";
import type { CaptureCut, CaptureManualDetail } from "@/types/capture";

/*
 * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
 * - 静的 canRecord=false は従来どおり file input のみ (notice なし) を保つ
 * - 録画実行時失敗 (getUserMedia reject) で recorder → file fallback + notice へ切替
 * - フォールバック経由のファイル選択が enqueue へ正しく引き渡される (contentType 正規化含む)
 * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
 */

const { routerReloadMock, enqueueMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
    enqueueMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { reload: routerReloadMock },
}));

// jsdom に indexedDB が無いため in-memory PendingStore に差し替える
vi.mock("@/lib/capture/idb", () => ({
    createIdbPendingStore: () => {
        const items = new Map<string, unknown>();
        return {
            put: async (item: { clientTakeId: string }) => {
                items.set(item.clientTakeId, item);
            },
            delete: async (id: string) => {
                items.delete(id);
            },
            list: async () => [...items.values()],
        };
    },
}));

// UploadQueue は enqueue spy 付き stub に差し替え (generateClientTakeId 等は本物を残す)
vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/capture/upload-queue")>()),
    UploadQueue: class {
        quotaMessage: string | null = null;
        enqueue = enqueueMock;
        async resume(): Promise<unknown[]> {
            return [];
        }
    },
}));

function makeCut(overrides: Partial<CaptureCut> = {}): CaptureCut {
    return {
        id: 101,
        type: "step",
        parent_cut_id: null,
        scene: "ネジを締める",
        shot_type: "hiki",
        shooting_point: "手元",
        narration: "ドライバーでネジを締めます",
        subtitle_primary: null,
        subtitle_secondary: "",
        adopted_take_id: null,
        takes: [],
        ...overrides,
    };
}

function makeManual(): CaptureManualDetail {
    return {
        id: 5,
        title: "ネジ締め作業",
        status: "ready",
        cuts: [makeCut()],
    };
}

const baseProps = {
    project: { id: 1, name: "現場A" },
    manual: makeManual(),
};

function stubCameraSupported(supported: boolean): void {
    if (supported) {
        vi.stubGlobal("MediaRecorder", {
            isTypeSupported: (type: string) => type === "video/webm",
        });
        vi.stubGlobal("navigator", {
            ...navigator,
            mediaDevices: { getUserMedia: getUserMediaMock },
        });
    } else {
        vi.stubGlobal("MediaRecorder", undefined);
    }
}

const getUserMediaMock = vi.fn<() => Promise<MediaStream>>();

beforeEach(() => {
    routerReloadMock.mockReset();
    enqueueMock.mockReset();
    enqueueMock.mockImplementation((item: { clientTakeId: string }) =>
        Promise.resolve({ status: "uploaded", clientTakeId: item.clientTakeId }),
    );
    getUserMediaMock.mockReset();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

async function selectCut(): Promise<void> {
    await fireEvent.click(screen.getByTestId("cut-row-101"));
}

describe("Capture/Show カメラフォールバック", () => {
    it("(a) 静的 canRecord=false は file input のみ (notice を出さない)", async () => {
        stubCameraSupported(false);

        render(CaptureShow, { props: baseProps });
        await selectCut();

        expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
        expect(screen.queryByTestId("camera-fallback-notice")).not.toBeInTheDocument();
        expect(screen.queryByTestId("camera-preview")).not.toBeInTheDocument();
    });

    it("(b) 録画実行時失敗 (NotAllowedError) で file fallback + notice へ切替", async () => {
        stubCameraSupported(true);
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));

        render(CaptureShow, { props: baseProps });
        await selectCut();
        // 最初は録画プレビューが出ている
        expect(screen.getByTestId("camera-preview")).toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(screen.getByTestId("camera-fallback-notice")).toHaveTextContent(
                "カメラ設定を確認して再読み込み",
            );
        });
        expect(screen.queryByTestId("camera-preview")).not.toBeInTheDocument();
        expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
    });

    it("(c) フォールバックからのファイル選択が enqueue へ引き渡され reload される", async () => {
        stubCameraSupported(true);
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));

        render(CaptureShow, { props: baseProps });
        await selectCut();
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => {
            expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
        });

        const file = new File(["data"], "take.mp4", { type: "video/mp4" });
        await fireEvent.change(screen.getByTestId("capture-file-input"), {
            target: { files: [file] },
        });

        await vi.waitFor(() => {
            expect(enqueueMock).toHaveBeenCalledTimes(1);
        });
        const arg = enqueueMock.mock.calls[0][0];
        expect(arg.cutId).toBe(101);
        expect(arg.blob).toBe(file);
        expect(arg.contentType).toBe("video/mp4");
        expect(arg.durationMs).toBeNull();
        expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
    });

    it("(e) permission_denied 以外 (device_missing) は汎用の切替 notice を出す", async () => {
        stubCameraSupported(true);
        getUserMediaMock.mockRejectedValue(new DOMException("no cam", "NotFoundError"));

        render(CaptureShow, { props: baseProps });
        await selectCut();
        await fireEvent.click(screen.getByTestId("start-recording"));

        await vi.waitFor(() => {
            expect(screen.getByTestId("camera-fallback-notice")).toHaveTextContent(
                "この端末ではカメラ録画を利用できないため、ファイル選択でのアップロードに切り替えました。",
            );
        });
        // permission_denied 用の「再読み込み」文言は出さない
        expect(screen.getByTestId("camera-fallback-notice")).not.toHaveTextContent(
            "再読み込み",
        );
    });

    it("(d) codecs 付き MIME は contentType が正規化される (video/webm;codecs=vp9 → video/webm)", async () => {
        stubCameraSupported(true);
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));

        render(CaptureShow, { props: baseProps });
        await selectCut();
        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => {
            expect(screen.getByTestId("capture-file-input")).toBeInTheDocument();
        });

        const file = new File(["data"], "take.webm", { type: "video/webm;codecs=vp9" });
        await fireEvent.change(screen.getByTestId("capture-file-input"), {
            target: { files: [file] },
        });

        await vi.waitFor(() => {
            expect(enqueueMock).toHaveBeenCalledTimes(1);
        });
        expect(enqueueMock.mock.calls[0][0].contentType).toBe("video/webm");
    });
});
