import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Takes from "@/pages/Manuals/Takes.svelte";
import type { SelectableTake, TakeSelectionCut } from "@/types/manual";

/*
 * PC テイク選択・採用画面 (Manuals/Takes)。
 * - 採用中テイクは青枠 (border-primary) で区別する
 * - 採用できない状態のテイクでも押下は受け、押してからエラーを出す (disabled にしない)
 * - 削除は確認ダイアログを経てから DELETE を送る
 * - 字幕 / ナレーション原稿は初期オフの表示切替 (v1 は TTS 非実装 = 音は出さない)
 * - ローカル動画の追加は既存 presigned フロー (UploadQueue) を再利用する
 */

const { routerReloadMock, enqueueMock, memoryStore, storeDeleteSpy } = vi.hoisted(() => {
    const items = new Map<string, unknown>();
    const storeDeleteSpy = vi.fn();

    return {
        routerReloadMock: vi.fn(),
        enqueueMock: vi.fn(),
        storeDeleteSpy,
        memoryStore: {
            put: async (item: { clientTakeId: string }) => {
                items.set(item.clientTakeId, item);
            },
            delete: async (id: string) => {
                storeDeleteSpy(id);
                items.delete(id);
            },
            list: async () => [...items.values()],
        },
    };
});

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { reload: routerReloadMock },
}));

// UploadQueue は enqueue spy 付き stub に差し替える (HTTP 経路は upload-queue.test.ts が担う)。
// PendingStore は delete を観測できる memory 実装に差し替え、queued の Blob 破棄を固定する。
vi.mock("@/lib/capture/upload-queue", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/capture/upload-queue")>()),
    createMemoryPendingStore: () => memoryStore,
    UploadQueue: class {
        quotaMessage: string | null = null;
        enqueue = enqueueMock;
    },
}));

const fetchMock = vi.fn();

function take(overrides: Partial<SelectableTake> = {}): SelectableTake {
    return {
        id: 101,
        status: "ready",
        size_bytes: 2 * 1024 * 1024,
        duration_ms: 12_000,
        comment: null,
        captured_at: null,
        sort_order: 0,
        downloaded: false,
        has_thumbnail: false,
        ...overrides,
    };
}

const cut: TakeSelectionCut = {
    id: 34,
    type: "step",
    label: "手順1",
    scene: "工具を準備する",
    narration: "はじめに工具を準備します。",
    subtitle_primary: "トルク 12N・m",
    subtitle_secondary: "工具を準備する",
    adopted: null,
};

function baseProps(overrides: Record<string, unknown> = {}) {
    return {
        project: { id: 7, name: "現場A" },
        manual: { id: 12, title: "ネジ締め作業", status: "ready" as const },
        cut,
        takes: [take()],
        ...overrides,
    };
}

function jsonResponse(status: number, body: unknown = {}): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    fetchMock.mockResolvedValue(jsonResponse(200, { id: 34 }));
    // jsdom は Object URL API を持たないため、静的メソッドだけを差し替える
    // (URL 自体を stub すると Inertia Link の URL 構築が壊れる)
    Object.defineProperty(URL, "createObjectURL", {
        configurable: true,
        value: vi.fn(() => "blob:take"),
    });
    Object.defineProperty(URL, "revokeObjectURL", { configurable: true, value: vi.fn() });
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    enqueueMock.mockReset();
    routerReloadMock.mockReset();
    storeDeleteSpy.mockReset();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("Manuals/Takes — テイクの選択と採用", () => {
    it("カットのラベルと場面が見出しに出る", () => {
        render(Takes, { props: baseProps() });

        expect(screen.getByRole("heading", { name: "手順1 のテイク選択" })).toBeInTheDocument();
        expect(screen.getByText("工具を準備する")).toBeInTheDocument();
    });

    it("採用中テイクのタイルに青枠 (border-primary) が付き、非採用には付かない", () => {
        const adopted = take({ id: 101 });
        const other = take({ id: 202, sort_order: 1 });
        render(Takes, {
            props: baseProps({
                takes: [adopted, other],
                cut: { ...cut, adopted: { id: adopted.id, status: "ready" as const } },
            }),
        });

        expect(screen.getByTestId("take-tile-101").className).toContain("border-primary");
        expect(screen.getByTestId("take-tile-202").className).not.toContain("border-primary");
        expect(screen.getByTestId("take-adopted-101")).toHaveTextContent("採用中");
    });

    it("サムネイル未生成のテイクは状態タイル (テイク番号 + 状態) を描画する", () => {
        render(Takes, { props: baseProps({ takes: [take({ has_thumbnail: false })] }) });

        const tile = screen.getByTestId("take-thumbnail-101");
        expect(tile.tagName).toBe("DIV");
        expect(tile).toHaveTextContent("テイク 1");
        expect(tile).toHaveTextContent("使用できます");
    });

    it("サムネイル生成済みなら thumbnail endpoint の img を描画する", () => {
        render(Takes, { props: baseProps({ takes: [take({ has_thumbnail: true })] }) });

        const img = screen.getByTestId("take-thumbnail-101");
        expect(img.tagName).toBe("IMG");
        expect(img).toHaveAttribute(
            "src",
            "/app/projects/7/manuals/12/cuts/34/takes/101/thumbnail",
        );
    });

    it("ready のテイクは playback 経由の video を描画する (署名 URL を props に載せない)", () => {
        render(Takes, { props: baseProps() });

        expect(screen.getByTestId("take-preview-video")).toHaveAttribute(
            "src",
            "/app/projects/7/manuals/12/cuts/34/takes/101/playback",
        );
    });

    it("processing のテイクは video を描かず、採用押下でエラーを出す (要素は disabled でない)", async () => {
        render(Takes, { props: baseProps({ takes: [take({ status: "processing" })] }) });

        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
        expect(screen.getByTestId("take-not-playable")).toHaveTextContent("まだ再生できません");

        const button = screen.getByTestId("take-adopt");
        expect(button).not.toBeDisabled();
        await fireEvent.click(button);

        expect(await screen.findByTestId("take-preview-error")).toHaveTextContent(
            "「処理中」のテイクは採用できません。",
        );
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("採用成功で adopt へ POST し cut と takes だけを再取得する", async () => {
        render(Takes, { props: baseProps() });

        await fireEvent.click(screen.getByTestId("take-adopt"));

        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
        expect(fetchMock.mock.calls[0][0]).toBe(
            "/app/projects/7/manuals/12/cuts/34/takes/101/adopt",
        );
        expect(fetchMock.mock.calls[0][1].method).toBe("POST");
    });

    it("採用失敗 (409) はサーバ供給の文言をそのまま表示する", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(409, { code: "scenario_conflict", message: "書き出し中のため変更できません。" }),
        );
        render(Takes, { props: baseProps() });

        await fireEvent.click(screen.getByTestId("take-adopt"));

        expect(await screen.findByTestId("take-preview-error")).toHaveTextContent(
            "書き出し中のため変更できません。",
        );
        expect(routerReloadMock).not.toHaveBeenCalled();
    });

    it("書き出し中の manual は採用が 409 になることを押す前に告知する (ボタンは押せる)", () => {
        render(Takes, { props: baseProps({ manual: { id: 12, title: "x", status: "rendering" } }) });

        expect(screen.getByTestId("take-adopt-status-notice")).toHaveTextContent("書き出し中");
        expect(screen.getByTestId("take-adopt")).not.toBeDisabled();
    });

    it("削除は確認ダイアログを経てから DELETE を送る (復元不可の文言を含む)", async () => {
        render(Takes, { props: baseProps() });

        await fireEvent.click(screen.getByTestId("take-delete-101"));

        expect(await screen.findByText(/この操作は取り消せません/)).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();

        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/7/manuals/12/cuts/34/takes/101");
        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
    });

    it("DL 済みテイクは削除できない理由を押す前に説明する (ボタンは押せる)", () => {
        render(Takes, { props: baseProps({ takes: [take({ downloaded: true })] }) });

        expect(screen.getByTestId("take-downloaded-note-101")).toHaveTextContent(
            "ダウンロード済みのため削除できません。",
        );
        expect(screen.getByTestId("take-delete-101")).not.toBeDisabled();
    });

    it("DL 済みテイクの削除 422 はサーバ文言を表示する", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(422, { message: "ダウンロード済みのテイクは削除できません。" }),
        );
        render(Takes, { props: baseProps({ takes: [take({ downloaded: true })] }) });

        await fireEvent.click(screen.getByTestId("take-delete-101"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        expect(await screen.findByTestId("take-picker-error")).toHaveTextContent(
            "ダウンロード済みのテイクは削除できません。",
        );
    });

    it("テイクが 0 件でも詰まず、撮影/追加を促す案内を出す", () => {
        render(Takes, { props: baseProps({ takes: [] }) });

        expect(screen.getByTestId("take-picker-empty")).toBeInTheDocument();
        expect(screen.getByTestId("take-not-playable")).toHaveTextContent(
            "左の一覧からテイクを選ぶと再生できます。",
        );
    });
});

describe("Manuals/Takes — 字幕 / ナレーション原稿の表示切替", () => {
    it("初期状態では字幕 overlay もナレーション原稿も出ていない", () => {
        render(Takes, { props: baseProps() });

        expect(screen.queryByTestId("subtitle-overlay")).not.toBeInTheDocument();
        expect(screen.queryByTestId("narration-script")).not.toBeInTheDocument();
    });

    it("「字幕を表示」で overlay の primary / secondary が出る", async () => {
        render(Takes, { props: baseProps() });

        await fireEvent.click(screen.getByTestId("toggle-subtitles"));

        expect(await screen.findByTestId("subtitle-overlay")).toBeInTheDocument();
        expect(screen.getByTestId("subtitle-primary")).toHaveTextContent("トルク 12N・m");
        expect(screen.getByTestId("subtitle-secondary")).toHaveTextContent("工具を準備する");
    });

    it("「ナレーション原稿を表示」で原稿テキストが出る (音声は再生しない)", async () => {
        render(Takes, { props: baseProps() });

        // v1 は TTS 非実装。ラベルは「原稿」であって「再生」ではない
        expect(screen.getByText("ナレーション原稿を表示")).toBeInTheDocument();
        expect(screen.queryByText(/ナレーションを再生/)).not.toBeInTheDocument();
        expect(screen.queryByRole("button", { name: /音声/ })).not.toBeInTheDocument();

        await fireEvent.click(screen.getByTestId("toggle-narration-script"));

        expect(await screen.findByTestId("narration-script")).toHaveTextContent(
            "はじめに工具を準備します。",
        );
    });
});

describe("Manuals/Takes — PC ローカル動画のアップロード", () => {
    /** metadata 読み取りの結果を差し替える (jsdom は video の loadedmetadata を発火しない) */
    function stubVideoMetadata(outcome: number | "error" | "silent"): void {
        const createElement = document.createElement.bind(document);
        vi.spyOn(document, "createElement").mockImplementation(((tag: string) => {
            const element = createElement(tag);
            if (tag !== "video") return element;
            const video = element as HTMLVideoElement;
            Object.defineProperty(video, "duration", {
                configurable: true,
                get: () => (typeof outcome === "number" ? outcome : NaN),
            });
            Object.defineProperty(video, "src", {
                configurable: true,
                get: () => "",
                set: () => {
                    if (outcome === "silent") return; // timeout 経路
                    queueMicrotask(() => {
                        if (outcome === "error") {
                            video.onerror?.(new Event("error"));
                            return;
                        }
                        video.onloadedmetadata?.(new Event("loadedmetadata"));
                    });
                },
            });
            return video;
        }) as typeof document.createElement);
    }

    function videoFile(type = "video/mp4"): File {
        return new File(["bytes"], "take.mp4", { type });
    }

    async function selectFile(file: File): Promise<HTMLInputElement> {
        const input = screen.getByTestId("take-file-input") as HTMLInputElement;
        Object.defineProperty(input, "files", { configurable: true, value: [file] });
        await fireEvent.change(input);

        return input;
    }

    it("動画以外のファイルはエラー文言を出し、アップロードを開始しない", async () => {
        render(Takes, { props: baseProps() });

        const input = await selectFile(new File(["x"], "memo.txt", { type: "text/plain" }));

        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
            "動画ファイルを選択してください。",
        );
        expect(enqueueMock).not.toHaveBeenCalled();
        expect(input.value).toBe("");
    });

    it("61 秒の動画は事前チェックで止まり upload-url を呼ばない (quota を消費しない)", async () => {
        stubVideoMetadata(61);
        render(Takes, { props: baseProps() });

        await selectFile(videoFile());

        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
            "動画の長さが 1 分を超えています。",
        );
        expect(enqueueMock).not.toHaveBeenCalled();
    });

    it("尺を読めない (metadata error) ファイルは事前チェックを飛ばしてアップロードに進む", async () => {
        stubVideoMetadata("error");
        enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
        render(Takes, { props: baseProps() });

        await selectFile(videoFile());

        await waitFor(() => expect(enqueueMock).toHaveBeenCalled());
        expect(enqueueMock.mock.calls[0][0].durationMs).toBeNull();
        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
    });

    it("尺を読めない (timeout) ファイルも事前チェックを飛ばして進む", async () => {
        vi.useFakeTimers();
        try {
            stubVideoMetadata("silent");
            enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
            render(Takes, { props: baseProps() });

            const input = screen.getByTestId("take-file-input") as HTMLInputElement;
            Object.defineProperty(input, "files", { configurable: true, value: [videoFile()] });
            void fireEvent.change(input);

            await vi.advanceTimersByTimeAsync(3_000);

            expect(enqueueMock).toHaveBeenCalled();
            expect(enqueueMock.mock.calls[0][0].durationMs).toBeNull();
        } finally {
            vi.useRealTimers();
        }
    });

    it("アップロード成功で cut と takes を再取得し、input が空に戻る", async () => {
        stubVideoMetadata(20);
        enqueueMock.mockResolvedValue({ status: "uploaded", clientTakeId: "A" });
        render(Takes, { props: baseProps() });

        const input = await selectFile(videoFile());

        await waitFor(() => expect(routerReloadMock).toHaveBeenCalledWith({ only: ["cut", "takes"] }));
        expect(enqueueMock.mock.calls[0][0]).toMatchObject({
            projectId: 7,
            manualId: 12,
            cutId: 34,
            durationMs: 20_000,
            contentType: "video/mp4",
        });
        await waitFor(() => expect(input.value).toBe(""));
    });

    it("422 quota_exceeded はサーバ文言をそのまま表示する", async () => {
        stubVideoMetadata(20);
        enqueueMock.mockResolvedValue({
            status: "quota_exceeded",
            clientTakeId: "A",
            message: "保存容量の上限に達しています。",
        });
        render(Takes, { props: baseProps() });

        await selectFile(videoFile());

        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
            "保存容量の上限に達しています。",
        );
        expect(routerReloadMock).not.toHaveBeenCalled();
    });

    it("queued (オフライン等) は Blob を捨ててから理由を出す", async () => {
        stubVideoMetadata(20);
        enqueueMock.mockResolvedValue({ status: "queued", clientTakeId: "A", reason: "offline" });
        render(Takes, { props: baseProps() });

        await selectFile(videoFile());

        await waitFor(() => expect(storeDeleteSpy).toHaveBeenCalledWith("A"));
        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
            "アップロードできませんでした。",
        );
        expect(await memoryStore.list()).toEqual([]);
    });

    it("enqueue が throw しても無反応にならず、input が空に戻り Blob も残らない", async () => {
        stubVideoMetadata(20);
        enqueueMock.mockRejectedValue(new Error("network down"));
        render(Takes, { props: baseProps() });

        const input = await selectFile(videoFile());

        expect(await screen.findByTestId("take-upload-error")).toHaveTextContent(
            "アップロードできませんでした。",
        );
        await waitFor(() => expect(input.value).toBe(""));
        expect(await memoryStore.list()).toEqual([]);
    });
});
