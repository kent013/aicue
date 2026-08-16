import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import CaptureShow from "@/pages/Capture/Show.svelte";
import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";
import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manual";

/*
 * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
 * - 静的 canRecord=false は従来どおり file input のみ (notice なし) を保つ
 * - 録画実行時失敗 (getUserMedia reject) で recorder → file fallback + notice へ切替
 * - フォールバック経由のファイル選択が enqueue へ正しく引き渡される (contentType 正規化含む)
 * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
 */

const { routerReloadMock, enqueueMock, resumeMock, autoDownloadRunMock, navigateToPanelMock } =
    vi.hoisted(() => ({
        routerReloadMock: vi.fn(),
        enqueueMock: vi.fn(),
        resumeMock: vi.fn(),
        autoDownloadRunMock: vi.fn(),
        navigateToPanelMock: vi.fn(),
    }));

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
        resume = resumeMock;
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
        has_thumbnail: false,
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
    // reload は Inertia の onFinish で解決する契約。既定では即座に完了させる
    // (single-flight の in-flight が張り付いたままにならないようにする)
    routerReloadMock.mockImplementation((options: { onFinish?: () => void }) => {
        options.onFinish?.();
    });
    enqueueMock.mockReset();
    resumeMock.mockReset();
    resumeMock.mockResolvedValue([]);
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
        expect(routerReloadMock).toHaveBeenCalledWith({
            only: ["manual"],
            onFinish: expect.any(Function),
        });
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
            expect(routerReloadMock).toHaveBeenCalledWith({
                only: ["manual"],
                onFinish: expect.any(Function),
            });
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

/*
 * 撮影 PWA → PC 側マニュアル詳細の復路 (T155)。
 * 往路 (Manuals/Show の「この手順書を撮影する」) と対になる導線で、
 * **この画面へ到達できた利用者に対しては追加の status / ability 条件で出し分けない**。
 * 根拠と保証範囲は docs/architecture.md §撮影 PWA の運用契約。
 */

// status の全数は「型で全数が保証されている写像」のキーから採る。
// VIDEO_MANUAL_STATUS_LABELS は Record<VideoManualStatus, string> なので、status が増えたら
// 写像側がコンパイルエラーになり、直すと本 dataset も自動で増える (二重管理をつくらない)。
const ALL_STATUSES = Object.keys(VIDEO_MANUAL_STATUS_LABELS) as VideoManualStatus[];

// Inertia の Link は href を絶対 URL へ正規化して描画する (jsdom では
// http://localhost:3000/... になる)。origin に依存させないため origin から先だけを比較する
// (query / hash も含める = 余計なパラメータが付いたら落ちる)。
function pathOf(element: Element): string {
    const url = new URL(element.getAttribute("href") ?? "", window.location.href);

    return `${url.pathname}${url.search}${url.hash}`;
}

describe("Capture/Show マニュアル詳細への復路 (T155)", () => {
    it("ヘッダーに PC 側マニュアル詳細へのリンクを出す", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        // 利用者が認識する名前 (accessible name) で取る。getByRole の name に文字列を渡すと
        // **完全一致**になるため、名前に余計な文字列が混ざった場合もここで落ちる
        // (ByRoleOptions に exact は無い = 既定で完全一致)。
        const link = screen.getByRole("link", { name: "マニュアル詳細へ" });
        expect(pathOf(link)).toBe("/projects/1/manuals/5");
        // アイコンが名前を汚さないことは**別契約**として明示的に見る
        // (Lucide の svg は title を持たないので、aria-hidden を外しても名前は変わらない =
        //  名前の検査だけでは aria-hidden の消失を検出できないため)
        expect(link.querySelector("svg")).toHaveAttribute("aria-hidden", "true");
    });

    it("既存の「一覧へ戻る」(撮影 PWA 一覧) は置き換えず、その後ろに併置する", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: baseProps });

        const back = screen.getByRole("link", { name: "一覧へ戻る" });
        const detail = screen.getByRole("link", { name: "マニュアル詳細へ" });

        expect(pathOf(back)).toBe("/app/projects/1/manuals");
        // この実装は tabindex も CSS order も使わないので DOM 順がタブ順になる。
        // 既存要素を動かさないことを固定する
        expect(back.compareDocumentPosition(detail) & Node.DOCUMENT_POSITION_FOLLOWING).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
    });

    it.each(ALL_STATUSES)(
        "status=%s でも復路は消えない (往路の isCaptureNavigable を流用していないこと)",
        (status) => {
            stubCameraSupported(false);
            render(CaptureShow, {
                props: { ...baseProps, manual: { ...makeManual(), status } },
            });

            expect(screen.getByRole("link", { name: "マニュアル詳細へ" })).toBeTruthy();
        },
    );
});

/*
 * サムネイル反映の**ページ配線** (T183 / S10)。
 *
 * 有界性・停止条件そのものは thumbnail-refresh.test.ts が固定する。
 * ここで固定するのは **reload の回数** — uploaded が何件あっても single-flight で 1 回、
 * uploaded が 0 件なら 0 回 — だけである。
 *
 * ★ **保証しないもの (誇張しない)**: 「uploaded の outcome が**すべて** watch へ渡ること」は
 *   本テストでは固定していない。scheduler は page が直接 new する具象で観測点が無く、
 *   ループが先頭 1 件だけに変わっても本テストは緑のままである。ここを固定するには
 *   scheduler を差し替え可能な collaborator にする必要があり、今それを作らない
 *   (AGENTS.md 思考原則 2)。
 */
describe("Capture/Show サムネイル反映の配線 (T183)", () => {
    it("キュー再開で uploaded が複数でも reload は 1 回だけ通る (single-flight)", async () => {
        stubCameraSupported(false);
        resumeMock.mockResolvedValue([
            { status: "uploaded", clientTakeId: "q1" },
            { status: "uploaded", clientTakeId: "q2" },
            { status: "queued", clientTakeId: "q3", reason: "offline" },
        ]);

        render(CaptureShow, { props: baseProps });
        await fireEvent(window, new Event("online"));

        await vi.waitFor(() => {
            expect(resumeMock).toHaveBeenCalled();
        });
        await vi.waitFor(() => {
            expect(routerReloadMock).toHaveBeenCalledTimes(1);
        });
    });

    it("uploaded が 1 件も無いキュー再開では reload しない", async () => {
        stubCameraSupported(false);
        resumeMock.mockResolvedValue([
            { status: "queued", clientTakeId: "q1", reason: "offline" },
            { status: "quota_exceeded", clientTakeId: "q2", message: "上限です" },
        ]);

        render(CaptureShow, { props: baseProps });
        await fireEvent(window, new Event("online"));

        await vi.waitFor(() => {
            expect(resumeMock).toHaveBeenCalled();
        });
        expect(routerReloadMock).not.toHaveBeenCalled();
    });
});
