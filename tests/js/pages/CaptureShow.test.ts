import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CaptureShow from "@/pages/Capture/Show.svelte";
import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";

/*
 * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
 * - 静的 canRecord=false は従来どおり file input のみ (notice なし) を保つ
 * - 録画実行時失敗 (getUserMedia reject) で recorder → file fallback + notice へ切替
 * - フォールバック経由のファイル選択が enqueue へ正しく引き渡される (contentType 正規化含む)
 * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
 */

const { routerReloadMock, enqueueMock, autoDownloadRunMock, navigateToPanelMock } = vi.hoisted(
    () => ({
        routerReloadMock: vi.fn(),
        enqueueMock: vi.fn(),
        autoDownloadRunMock: vi.fn(),
        navigateToPanelMock: vi.fn(),
    }),
);

// 撮影パネルへのナビゲーション (F-1-03) は panel-navigation.ts が副作用ごと担い、
// その抑止契約は panel-navigation.test.ts が固定する。ここで固定するのは
// **ページ配線** = Show が navigateToPanelIfNeeded に何を渡しているか、だけ。
// jsdom の矩形 / focus / scrollIntoView 実装差に依存させないため spy に差し替える
// (実装の他の export は本物を残す)。
vi.mock("@/lib/capture/panel-navigation", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/capture/panel-navigation")>()),
    navigateToPanelIfNeeded: navigateToPanelMock,
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

// AdoptedTakeAutoDownloader は run spy 付き stub に差し替え。状態機械の厳密検証は
// auto-download.test.ts が担うため、本テストは Show 側の結線 (発火/reload) のみ検証する。
// stub には running ガードが無いので多重実行抑止は検証しない (二重ガード回避方針)。
vi.mock("@/lib/capture/auto-download", () => ({
    AdoptedTakeAutoDownloader: class {
        run = autoDownloadRunMock;
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

/** 採用済み・未 DL テイク (playback_url/ack_token 保持) を持つ manual */
function makeAdoptedManual(): CaptureManualDetail {
    const take: CaptureTake = {
        id: 900,
        client_take_id: "01J0ADOPT",
        status: "ready",
        size_bytes: 2048,
        duration_ms: 3000,
        comment: null,
        captured_at: "2026-07-11T00:00:00Z",
        sort_order: 0,
        downloaded: false,
        playback_url: "https://s3.example.test/take-900.mp4?sig=1",
        download_ack_token: "ack-900",
    };
    return {
        id: 5,
        title: "ネジ締め作業",
        status: "ready",
        cuts: [makeCut({ adopted_take_id: take.id, takes: [take] })],
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
    autoDownloadRunMock.mockReset();
    // 既定: 対象なし (changed=false)。個別ケースで override する
    autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });
    getUserMediaMock.mockReset();
    navigateToPanelMock.mockReset();
    navigateToPanelMock.mockReturnValue(false);
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

describe("Capture/Show 採用済みテイク自動 DL 結線 (T051)", () => {
    const adoptedProps = { project: { id: 1, name: "現場A" }, manual: makeAdoptedManual() };

    it("入室時に run(manual) が発火し、changed=true なら manual reload される", async () => {
        stubCameraSupported(false);
        autoDownloadRunMock.mockResolvedValue({ changed: true, hasPendingAck: false });

        render(CaptureShow, { props: adoptedProps });

        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
        });
        expect(autoDownloadRunMock).toHaveBeenCalledWith(adoptedProps.manual);
        await vi.waitFor(() => {
            expect(routerReloadMock).toHaveBeenCalledWith({ only: ["manual"] });
        });
    });

    it("changed=false のときは reload しない (DL 済み対象空 = 再発火抑止)", async () => {
        stubCameraSupported(false);
        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });

        render(CaptureShow, { props: adoptedProps });

        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
        });
        expect(routerReloadMock).not.toHaveBeenCalled();
    });

    it("online 復帰でも run が再度呼ばれる", async () => {
        stubCameraSupported(false);
        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });

        render(CaptureShow, { props: adoptedProps });
        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
        });

        await fireEvent(window, new Event("online"));

        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(2);
        });
    });

    it("online を連続 dispatch すると各回で run 起動要求が出る (結線責務のみ)", async () => {
        stubCameraSupported(false);
        autoDownloadRunMock.mockResolvedValue({ changed: false, hasPendingAck: false });

        render(CaptureShow, { props: adoptedProps });
        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(1);
        });

        await fireEvent(window, new Event("online"));
        await fireEvent(window, new Event("online"));

        await vi.waitFor(() => {
            expect(autoDownloadRunMock).toHaveBeenCalledTimes(3);
        });
    });

    it("自動 DL stub は録画フォールバックの enqueue 経路に干渉しない (a〜e 系の非回帰)", async () => {
        stubCameraSupported(true);
        getUserMediaMock.mockRejectedValue(new DOMException("denied", "NotAllowedError"));

        render(CaptureShow, { props: { project: { id: 1, name: "現場A" }, manual: makeManual() } });
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
    });
});

/*
 * 受入条件 4 (bug-hunt F-1-03) のうち **ページ配線** を固定する。
 *
 * 抑止そのもの (captureActive=true で focus / scrollIntoView が呼ばれない) は
 * panel-navigation.test.ts が担う。ここは「Show が captureActive を正しく渡しているか」
 * だけを見る。両方揃って初めて「録画中は視点とフォーカスを奪わない」が守られる
 * (helper だけでは、将来 Show が誤って false を渡しても緑のままになる)。
 */
describe("Capture/Show 撮影パネルへのナビゲーション配線 (F-1-03 受入条件 4)", () => {
    it("通常時は captureActive=false を渡す", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        await selectCut();

        await vi.waitFor(() => {
            expect(navigateToPanelMock).toHaveBeenCalled();
        });
        expect(navigateToPanelMock.mock.calls.at(-1)?.[0]).toMatchObject({ captureActive: false });
    });

    it("録画開始 (getUserMedia grant 待ち) の間は captureActive=true を渡す", async () => {
        stubCameraSupported(true);
        // grant 待ちを再現する: 解決しない Promise を返すと starting=true のまま留まる。
        // CameraRecorder の公開 active は `starting || resuming || phase !== "idle"` であり、
        // grant 窓も active に含める設計なので、ここで captureActive=true になるはず。
        getUserMediaMock.mockReturnValue(new Promise<MediaStream>(() => {}));

        render(CaptureShow, { props: baseProps });
        await selectCut();
        await fireEvent.click(screen.getByTestId("start-recording"));

        navigateToPanelMock.mockClear();
        await selectCut(); // 録画中に同じカットを選び直す

        await vi.waitFor(() => {
            expect(navigateToPanelMock).toHaveBeenCalled();
        });
        expect(navigateToPanelMock.mock.calls.at(-1)?.[0]).toMatchObject({ captureActive: true });
    });
});

describe("Capture/Show レイアウト overflow ガード (H13/F-1-3)", () => {
    it("グリッドは mobile 単一列 (grid-cols-1)、左右 pane が min-w-0 を持つ", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const grid = screen.getByTestId("capture-grid");
        expect(grid.className).toContain("grid-cols-1");

        expect(screen.getByTestId("capture-left-pane").className).toContain("min-w-0");
        expect(screen.getByTestId("capture-right-pane").className).toContain("min-w-0");
    });
});
