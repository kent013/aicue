import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { tick } from "svelte";
import CaptureShow from "@/pages/Capture/Show.svelte";
import { LANDSCAPE_CAPTURE_MEDIA_QUERY } from "@/lib/capture/landscape-capture";
import type { CaptureCut, CaptureManualDetail, CaptureTake } from "@/types/capture";
import { VIDEO_MANUAL_STATUS_LABELS, type VideoManualStatus } from "@/types/manual";
import {
    FakeMediaRecorder,
    fakeStream,
    resetFakeMediaRecorder,
} from "../support/fake-media-recorder";

/*
 * 撮影ページ Capture/Show: F-03 実行時カメラフォールバック。
 * - 静的 canRecord=false は従来どおり file input のみ (notice なし) を保つ
 * - 録画実行時失敗 (getUserMedia reject) で recorder → file fallback + notice へ切替
 * - フォールバック経由のファイル選択が enqueue へ正しく引き渡される (contentType 正規化含む)
 * enqueue 後の HTTP 経路は upload-queue.test.ts が担うため、本テストは enqueue 引き渡しまで。
 */

const {
    routerReloadMock,
    enqueueMock,
    resumeMock,
    autoDownloadRunMock,
    navigateToPanelMock,
    pendingSeed,
} = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
    enqueueMock: vi.fn(),
    resumeMock: vi.fn(),
    autoDownloadRunMock: vi.fn(),
    navigateToPanelMock: vi.fn(),
    /** in-memory PendingStore の初期内容。UploadQueueBar の表示条件を作るために使う */
    pendingSeed: [] as { clientTakeId: string; blob: Blob }[],
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
        const items = new Map<string, unknown>(
            pendingSeed.map((item) => [item.clientTakeId, item]),
        );
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
    pendingSeed.length = 0;
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

/*
 * 横持ち全画面撮影の**ページ配線** (T186 施策 D)。
 *
 * 判定・スワイプ・移動判断そのものは landscape-capture.test.ts が、
 * バーの操作系列は CutSwipeBar.test.ts が固定する。ここで固定するのは
 * 「Show が全画面をどう組み立て、どの不変条件を守っているか」だけである。
 *
 * matchMedia の stub は本 describe 群の beforeEach でだけ入れて afterEach で戻す。
 * 既定は現行挙動と同じ (prefers-reduced-motion: false / 横持ち: false) にしてあり、
 * 上の既存テストは 1 件も書き換えていない。
 */

/** matchMedia stub の制御ハンドル。対象 query だけ真偽を切り替えられる。 */
interface LandscapeMatchMedia {
    set: (matches: boolean) => void;
}

function installLandscapeMatchMedia(initial: boolean): LandscapeMatchMedia {
    const handlers = new Set<(event: MediaQueryListEvent) => void>();
    let matches = initial;

    vi.stubGlobal("matchMedia", (query: string) => ({
        get matches() {
            return query === LANDSCAPE_CAPTURE_MEDIA_QUERY ? matches : false;
        },
        media: query,
        addEventListener: (type: string, handler: (event: MediaQueryListEvent) => void) => {
            if (type === "change" && query === LANDSCAPE_CAPTURE_MEDIA_QUERY) {
                handlers.add(handler);
            }
        },
        removeEventListener: (type: string, handler: (event: MediaQueryListEvent) => void) => {
            if (type === "change") handlers.delete(handler);
        },
    }));

    return {
        set: (next: boolean) => {
            matches = next;
            for (const handler of handlers) handler({ matches: next } as MediaQueryListEvent);
        },
    };
}

function makeLandscapeManual(count: number): CaptureManualDetail {
    return {
        id: 5,
        title: "ネジ締め作業",
        status: "ready",
        cuts: Array.from({ length: count }, (_, index) =>
            makeCut({ id: 101 + index, scene: `工程 ${index + 1}` }),
        ),
    };
}

function landscapeProps(count = 3): { project: { id: number; name: string }; manual: CaptureManualDetail } {
    return { project: { id: 1, name: "現場A" }, manual: makeLandscapeManual(count) };
}

/** 実 CameraRecorder を録画状態まで駆動できる stub 一式 (component は本物のまま使う) */
function stubCameraRecordable(): void {
    resetFakeMediaRecorder();
    vi.stubGlobal("MediaRecorder", FakeMediaRecorder);
    vi.stubGlobal("navigator", {
        ...navigator,
        mediaDevices: { getUserMedia: getUserMediaMock },
    });
    getUserMediaMock.mockResolvedValue(fakeStream().stream);
    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
}

function fullscreenState(): string | null {
    return screen.getByTestId("capture-right-pane").getAttribute("data-fullscreen");
}

/**
 * 祖先のどこかが inert で覆われているか。
 * Svelte 5 は `inert` を **DOM プロパティ**として設定する (属性セレクタでは引けない) ため、
 * `closest("[inert]")` ではなくプロパティを辿る。
 */
function hasInertAncestor(element: Element): boolean {
    for (let node: Element | null = element; node !== null; node = node.parentElement) {
        if (node instanceof HTMLElement && node.inert) return true;
    }

    return false;
}

describe("Capture/Show 横持ち全画面 (T186)", () => {
    let landscape: LandscapeMatchMedia;

    beforeEach(() => {
        landscape = installLandscapeMatchMedia(true);
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.documentElement.classList.remove("overflow-hidden");
    });

    it("横持ち条件が真なら全画面になる", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        expect(fullscreenState()).toBe("true");
    });

    it("縦持ち条件では従来どおり全画面にならない", () => {
        landscape.set(false);
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        expect(fullscreenState()).toBe("false");
        expect(screen.queryByTestId("cut-swipe-bar")).not.toBeInTheDocument();
    });

    it("初回描画の時点で既に全画面 (tick を挟まない同期 assertion)", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        // $effect で状態を入れる実装ならこの時点では "false" になる = 実装前に落ちる
        expect(fullscreenState()).toBe("true");
    });

    it("inline レイアウト固有の見出しが一度も DOM に現れない (ちらつきの直接検出)", async () => {
        stubCameraSupported(false);
        const seen: string[] = [];
        let addedElements = 0;
        // callback と takeRecords() の**両方が同じ処理を通る**ようにする。
        // 保留分を捨てると (forEach(() => undefined) 等)、追加が保留側に残ったケースを
        // 検査せずに通してしまう = 空振りになる。
        const collect = (records: MutationRecord[]): void => {
            for (const record of records) {
                for (const node of record.addedNodes) {
                    if (!(node instanceof Element)) continue;
                    addedElements += 1;
                    if (
                        node.matches('[data-testid="capture-recording-heading"]') ||
                        node.querySelector('[data-testid="capture-recording-heading"]') !== null
                    ) {
                        seen.push("capture-recording-heading");
                    }
                }
            }
        };
        const observer = new MutationObserver(collect);
        observer.observe(document.body, { childList: true, subtree: true });

        render(CaptureShow, { props: landscapeProps() });
        await tick();

        // callback は microtask 通知なので、保留分を回収 → microtask を 1 回進める →
        // もう一度回収、の順で取りこぼしを無くしてから切る
        collect(observer.takeRecords());
        await Promise.resolve();
        collect(observer.takeRecords());
        observer.disconnect();

        // 空振り防止: 観測そのものが動いていること (0 件なら「何も見ていないから緑」になる)
        expect(addedElements).toBeGreaterThan(0);
        expect(seen).toEqual([]);
    });

    it("カット未選択でも先頭カットが自動選択される", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 1");
        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("1 / 3");
    });

    it("「次のカット」でラベルが進み、末尾では告知が出てラベルが変わらない", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps(2) });

        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");

        await fireEvent.click(screen.getByTestId("cut-swipe-next"));
        expect(screen.getByTestId("cut-navigation-notice")).toHaveTextContent(
            "これが最後のカットです。",
        );
        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
    });

    it("先頭で「前のカット」は最初のカットである告知を出す", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));

        expect(screen.getByTestId("cut-navigation-notice")).toHaveTextContent(
            "これが最初のカットです。",
        );
    });

    it("カットを選び直すと古い告知が消える", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await fireEvent.click(screen.getByTestId("cut-swipe-previous"));
        expect(screen.getByTestId("cut-navigation-notice")).toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("cut-row-102"));

        expect(screen.queryByTestId("cut-navigation-notice")).not.toBeInTheDocument();
    });

    it("全画面 ⇄ inline の切替で camera-preview が同一 DOM ノードのまま (不変条件 1)", async () => {
        stubCameraSupported(true);
        render(CaptureShow, { props: landscapeProps() });

        const before = screen.getByTestId("camera-preview");
        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
        const after = screen.getByTestId("camera-preview");

        expect(after).toBe(before);
    });

    it("終了ボタンで inline へ戻り、再入路のボタンで全画面へ戻れる (ラッチと再入路)", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
        expect(fullscreenState()).toBe("false");

        await fireEvent.click(screen.getByTestId("enter-fullscreen-capture"));
        expect(fullscreenState()).toBe("true");
    });

    it("縦に戻すとラッチが解除され、再び横にすると自動で全画面へ入る", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
        expect(fullscreenState()).toBe("false");

        landscape.set(false);
        await tick();
        expect(fullscreenState()).toBe("false");

        landscape.set(true);
        await tick();
        expect(fullscreenState()).toBe("true");
    });

    it("upload-queue-bar は inline / fullscreen のどちらでもちょうど 1 件 (不変条件 2)", async () => {
        // 未送信テイクを用意しないと UploadQueueBar は 0 件のままで、
        // 二重描画を作っても「たまたま 0 件だから緑」になり検出力が無い。
        pendingSeed.push({
            clientTakeId: "01J0PENDING",
            blob: new Blob(["x".repeat(2048)], { type: "video/webm" }),
        });
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await vi.waitFor(() => {
            expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(1);
        });

        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));
        expect(screen.queryAllByTestId("upload-queue-bar")).toHaveLength(1);
    });

    it("全画面中は背景スクロールを止め、終了で必ず外す (不変条件 3)", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await vi.waitFor(() => {
            expect(document.documentElement.classList.contains("overflow-hidden")).toBe(true);
        });

        await fireEvent.click(screen.getByTestId("exit-fullscreen-capture"));

        await vi.waitFor(() => {
            expect(document.documentElement.classList.contains("overflow-hidden")).toBe(false);
        });
    });

    it("全画面中は撮影パネル見出しとテイク一覧を出さない", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        expect(screen.queryByTestId("capture-recording-heading")).not.toBeInTheDocument();
        expect(screen.queryByTestId("take-strip-101")).not.toBeInTheDocument();
    });

    it("カット 0 件では全画面にならず、再入路のボタンも出さない", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps(0) });

        expect(fullscreenState()).toBe("false");
        expect(screen.queryByTestId("enter-fullscreen-capture")).not.toBeInTheDocument();
    });

    it("全画面へ入った直後のフォーカスは全画面内の見出しにある (不変条件 6)", async () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        await vi.waitFor(() => {
            expect(document.activeElement).toBe(
                screen.getByTestId("capture-fullscreen-heading"),
            );
        });
    });

    it("全画面中は page 自身の背後コンテンツが inert で覆われる (AppLayout の chrome は覆わない)", () => {
        stubCameraSupported(false);
        render(CaptureShow, { props: landscapeProps() });

        expect(hasInertAncestor(screen.getByTestId("cut-row-101"))).toBe(true);
        expect(hasInertAncestor(screen.getByTestId("manual-detail-link"))).toBe(true);
        // 全画面そのものは inert の外にある (操作できないと詰む)
        expect(hasInertAncestor(screen.getByTestId("exit-fullscreen-capture"))).toBe(false);
    });

    it("選択中カットが消えても全画面の出口は残る (不変条件 5)", async () => {
        stubCameraSupported(false);
        const { rerender } = render(CaptureShow, { props: landscapeProps() });

        // 選択中カット (101) を含まない manual に差し替える
        await rerender({
            project: { id: 1, name: "現場A" },
            manual: {
                id: 5,
                title: "ネジ締め作業",
                status: "ready",
                cuts: [makeCut({ id: 201, scene: "別の工程" })],
            },
        });

        expect(fullscreenState()).toBe("true");
        expect(screen.getByTestId("exit-fullscreen-capture")).toBeInTheDocument();
    });
});

/*
 * 録画中のカット移動抑止を**ページ配線として**固定する。
 *
 * CameraRecorder は stub へ差し替えない — 実際の onCaptureActiveChange 経路を通らないと
 * 配線ミスを検出できないため。stub 一式は tests/js/support/fake-media-recorder.ts と共有する。
 */
describe("Capture/Show 全画面での録画中カット移動抑止 (T186)", () => {
    beforeEach(() => {
        installLandscapeMatchMedia(true);
        stubCameraRecordable();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.documentElement.classList.remove("overflow-hidden");
    });

    it("録画中は移動せずエラーを出し、停止後は移動できるようになる", async () => {
        render(CaptureShow, { props: landscapeProps(2) });

        await fireEvent.click(screen.getByTestId("start-recording"));
        await vi.waitFor(() => {
            expect(screen.getByTestId("stop-recording")).toBeInTheDocument();
        });

        await fireEvent.click(screen.getByTestId("cut-swipe-next"));

        expect(screen.getByTestId("cut-navigation-error")).toHaveTextContent(
            "録画中はカットを移動できません。録画を停止してから移動してください。",
        );
        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 1");

        await fireEvent.click(screen.getByTestId("stop-recording"));
        await vi.waitFor(() => {
            expect(screen.getByTestId("start-recording")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("cut-navigation-error")).not.toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("cut-swipe-next"));

        expect(screen.getByTestId("cut-swipe-label")).toHaveTextContent("手順 2");
    });
});
