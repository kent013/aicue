import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
import TakeStrip from "@/components/features/capture/TakeStrip.svelte";
import type { CaptureCut, CaptureTake } from "@/types/capture";

/*
 * TakeStrip: 採用・削除・DL 済み ACK。
 * - ボタンは事前条件で disabled にしない (押下時にサーバの 422 メッセージを表示。DESIGN.md)
 * - 採用テイクの DL 完了で download_ack_token を POST .../downloaded へ送る
 */

const fetchMock = vi.fn();

function makeTake(overrides: Partial<CaptureTake> = {}): CaptureTake {
    return {
        id: 10,
        client_take_id: "01ARZ3NDEKTSV4RRFFQ69G5FAV",
        status: "ready",
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

function makeCut(takes: CaptureTake[], adopted: number | null = null): CaptureCut {
    return {
        id: 3,
        type: "step",
        parent_cut_id: null,
        scene: "作業台を準備する",
        shot_type: "hiki",
        shooting_point: null,
        narration: "作業台の準備を行います",
        subtitle_primary: null,
        subtitle_secondary: "作業台を準備",
        adopted_take_id: adopted,
        adopted_ready_take_id: adopted,
        takes,
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
    vi.stubGlobal("open", vi.fn());
    document.cookie = "XSRF-TOKEN=test-token";
    // jsdom は HTMLMediaElement の再生系メソッドを未実装 (preview dialog の video 用)
    vi.spyOn(HTMLMediaElement.prototype, "play").mockResolvedValue(undefined);
    vi.spyOn(HTMLMediaElement.prototype, "pause").mockImplementation(() => undefined);
    vi.spyOn(HTMLMediaElement.prototype, "load").mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe("TakeStrip", () => {
    it("採用ボタン押下で POST .../adopt が飛び onChanged が呼ばれる", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-adopt-10"));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10/adopt");
        expect(fetchMock.mock.calls[0][1].method).toBe("POST");
    });

    it("削除ボタン押下では即 DELETE せず、確認ダイアログを表示する", async () => {
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-delete-10"));

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByTestId("take-delete-dialog")).toBeInTheDocument();
        expect(onChanged).not.toHaveBeenCalled();
    });

    it("確認ダイアログの『削除する』押下で DELETE .../takes/{id} が飛び onChanged が呼ばれる", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-delete-10"));
        const dialog = screen.getByTestId("take-delete-dialog");
        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
    });

    it("確認ダイアログのキャンセルでは DELETE が発火せずダイアログが閉じる", async () => {
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-delete-10"));
        const dialog = screen.getByTestId("take-delete-dialog");
        await fireEvent.click(within(dialog).getByRole("button", { name: "キャンセル" }));

        await waitFor(() => expect(fetchMock).not.toHaveBeenCalled());
        expect(onChanged).not.toHaveBeenCalled();
        expect(screen.queryByTestId("take-delete-dialog")).not.toBeInTheDocument();
    });

    it("DL 済みテイクの削除ボタンは disabled にせず、確認後 422 メッセージを表示する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "ダウンロード済みのテイクは削除できません。" }),
        );
        const onChanged = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ downloaded: true })]),
            onChanged,
        });

        const deleteButton = screen.getByTestId("take-delete-10");
        expect(deleteButton).not.toBeDisabled(); // 事前条件 disabled 禁止 (DESIGN.md)

        await fireEvent.click(deleteButton);
        const dialog = screen.getByTestId("take-delete-dialog");
        await fireEvent.click(within(dialog).getByRole("button", { name: "削除する" }));

        await waitFor(() =>
            expect(screen.getByTestId("take-strip-error").textContent).toContain(
                "ダウンロード済みのテイクは削除できません",
            ),
        );
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
        expect(fetchMock.mock.calls[0][1].method).toBe("DELETE");
        expect(onChanged).not.toHaveBeenCalled();
    });

    it("採用テイクの DL ボタンで playback_url を開き、ACK トークンを POST .../downloaded へ送る", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        const take = makeTake({
            playback_url: "https://s3.example.test/signed",
            download_ack_token: "sealed-ack-token",
        });
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([take], take.id),
            onChanged,
        });

        await fireEvent.click(screen.getByTestId("take-download-10"));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(window.open).toHaveBeenCalledWith("https://s3.example.test/signed", "_blank", "noopener");
        expect(fetchMock.mock.calls[0][0]).toBe(
            "/app/projects/1/manuals/2/cuts/3/takes/10/downloaded",
        );
        expect(JSON.parse(fetchMock.mock.calls[0][1].body)).toEqual({
            ack_token: "sealed-ack-token",
        });
    });

    it("ready テイクに再生ボタンを表示し、押下で preview dialog が開く (video src = playback URL)", async () => {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged: vi.fn(),
        });

        await fireEvent.click(screen.getByTestId("take-preview-10"));

        const video = await screen.findByTestId("take-preview-video");
        expect(video).toHaveAttribute("src", "/app/projects/1/manuals/2/cuts/3/takes/10/playback");
        expect(window.open).not.toHaveBeenCalled(); // preview は video element (DL の window.open とは別)
    });

    it("撮影 active 中は再生ボタン押下で dialog を開かずエラー表示する", async () => {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged: vi.fn(),
            captureActive: true,
            onRequestCameraRelease: vi.fn(),
            onCameraResume: vi.fn(),
        });

        await fireEvent.click(screen.getByTestId("take-preview-10"));

        expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument();
        expect(screen.getByTestId("take-strip-error").textContent).toContain(
            "撮影中はプレビューを再生できません",
        );
    });

    it("preview を開くと onRequestCameraRelease を呼ぶ (撮影待機 stream 解放)", async () => {
        const onRequestCameraRelease = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged: vi.fn(),
            captureActive: false,
            onRequestCameraRelease,
            onCameraResume: vi.fn(),
        });

        await fireEvent.click(screen.getByTestId("take-preview-10"));
        expect(onRequestCameraRelease).toHaveBeenCalledTimes(1);
    });

    it("preview の閉じるボタンで dialog が閉じ onCameraResume がちょうど 1 回呼ばれる", async () => {
        const onCameraResume = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged: vi.fn(),
            captureActive: false,
            onRequestCameraRelease: vi.fn(),
            onCameraResume,
        });

        await fireEvent.click(screen.getByTestId("take-preview-10"));
        await screen.findByTestId("take-preview-video");
        await fireEvent.click(screen.getByTestId("take-preview-close"));

        await waitFor(() =>
            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument(),
        );
        expect(onCameraResume).toHaveBeenCalledTimes(1);
    });

    it("preview から採用すると POST .../adopt が飛び、成功で dialog が閉じ onCameraResume が 1 回", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, {}));
        const onChanged = vi.fn();
        const onCameraResume = vi.fn();
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake()]),
            onChanged,
            captureActive: false,
            onRequestCameraRelease: vi.fn(),
            onCameraResume,
        });

        await fireEvent.click(screen.getByTestId("take-preview-10"));
        await screen.findByTestId("take-preview-video");
        await fireEvent.click(screen.getByTestId("take-preview-adopt"));

        await waitFor(() => expect(onChanged).toHaveBeenCalled());
        expect(fetchMock.mock.calls[0][0]).toBe("/app/projects/1/manuals/2/cuts/3/takes/10/adopt");
        await waitFor(() =>
            expect(screen.queryByTestId("take-preview-video")).not.toBeInTheDocument(),
        );
        expect(onCameraResume).toHaveBeenCalledTimes(1);
    });

    it("非 ready テイクには再生ボタンを出さず、理由の補助文言を表示する", () => {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ status: "processing" })]),
            onChanged: vi.fn(),
        });

        expect(screen.queryByTestId("take-preview-10")).not.toBeInTheDocument();
        expect(screen.getByTestId("take-not-ready-10")).toHaveTextContent(
            "アップロード処理中は再生できません",
        );
    });

    it("採用中バッジと DL 済みバッジを表示する", () => {
        const take = makeTake({ downloaded: true });
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([take], take.id),
            onChanged: vi.fn(),
        });

        expect(screen.getByTestId("take-adopted-10")).toBeInTheDocument();
        expect(screen.getByText("DL 済み")).toBeInTheDocument();
    });
});

describe("mobile 375px レイアウト構造 (F-1-05)", () => {
    it("行コンテナは mobile で wrap し sm で 1 行復帰する", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const row = screen.getByTestId("take-item-10");
        expect(row.className).toContain("flex-wrap");
        expect(row.className).toContain("sm:flex-nowrap");
    });

    it("操作列は mobile full-width 右寄せ+wrap failsafe・sm で従来 1 行に戻る", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const actions = screen.getByTestId("take-actions-10");
        for (const c of ["w-full", "justify-end", "flex-wrap", "sm:w-auto", "sm:flex-nowrap", "sm:justify-start"]) {
            expect(actions.className).toContain(c);
        }
    });

    it("ラベル(バッジ)行は wrap・min-w-0 で段落ちできる", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = screen.getByTestId("take-label-10");
        expect(label.className).toContain("flex-wrap");
        expect(label.className).toContain("min-w-0");
    });

    it("採用中+DL済み 両バッジがラベル行内に収まる (重なりでなく段落ち構造)", () => {
        const take = makeTake({ downloaded: true });
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([take], take.id), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.getByTestId("take-adopted-10")).toBeInTheDocument();
        expect(label.getByText("DL 済み")).toBeInTheDocument();
    });

    it("最小ケース (未採用・未DL) ではバッジが混入しない", () => {
        render(TakeStrip, { projectId: 1, manualId: 2, cut: makeCut([makeTake()]), onChanged: vi.fn() });
        const label = within(screen.getByTestId("take-label-10"));
        expect(label.queryByTestId("take-adopted-10")).not.toBeInTheDocument();
        expect(label.queryByText("DL 済み")).not.toBeInTheDocument();
    });
});

describe("サムネイル表示 (T183)", () => {
    it("has_thumbnail=false ではプレースホルダを出し <img> を描画しない (404 を出さない)", () => {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ has_thumbnail: false })]),
            onChanged: vi.fn(),
        });

        expect(screen.getByTestId("take-thumbnail-placeholder-10")).toBeInTheDocument();
        expect(screen.queryByTestId("take-thumbnail-10")).not.toBeInTheDocument();
    });

    it("has_thumbnail=true では配信 endpoint を src に持つ <img> を描画する", () => {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ has_thumbnail: true })]),
            onChanged: vi.fn(),
        });

        const img = screen.getByTestId("take-thumbnail-10");
        expect(img.getAttribute("src")).toBe(
            "/app/projects/1/manuals/2/cuts/3/takes/10/thumbnail",
        );
        // 行に「テイク N」の見出しがあるため画像は装飾 (alt="")
        expect(img.getAttribute("alt")).toBe("");
        expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
    });

    it("false → true への props 更新で同じ take の枠が画像へ置き換わる", async () => {
        const { rerender } = render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ has_thumbnail: false })]),
            onChanged: vi.fn(),
        });
        expect(screen.getByTestId("take-thumbnail-placeholder-10")).toBeInTheDocument();

        await rerender({
            projectId: 1,
            manualId: 2,
            cut: makeCut([makeTake({ has_thumbnail: true })]),
            onChanged: vi.fn(),
        });

        expect(screen.getByTestId("take-thumbnail-10")).toBeInTheDocument();
        expect(screen.queryByTestId("take-thumbnail-placeholder-10")).not.toBeInTheDocument();
    });
});

/*
 * 並べ替え (T185)。層 3 = 配線: 落としたら既存の PATCH 経路が期待どおりの position を出すか。
 * position は**最終 index** (移動後の全体配列での 0 始まり index)。サーバの reorderWithinCut が
 * 対象を除いた配列へ splice するため両者は一致する。
 */
describe("テイクの並べ替え (T185)", () => {
    /** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
    function stubRowRects(): void {
        vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(function (
            this: HTMLElement,
        ): DOMRect {
            const raw = this.dataset.reorderIndex;
            const index = raw === undefined ? -1 : Number(raw);
            const top = index < 0 ? 0 : index * 100;
            const height = index < 0 ? 0 : 100;
            // 素の型アサーションを使わずに実体を作る (new DOMRect が top/bottom を導出する)
            return new DOMRect(0, top, 0, height);
        });
    }

    function pointerEvent(type: string, clientY: number, pointerId = 1): PointerEvent {
        return new PointerEvent(type, {
            bubbles: true,
            cancelable: true,
            pointerId,
            clientY,
            button: 0,
            pointerType: "touch",
        });
    }

    /** 3 テイク (id 10 / 11 / 12) */
    function threeTakes(): CaptureTake[] {
        return [makeTake({ id: 10 }), makeTake({ id: 11 }), makeTake({ id: 12 })];
    }

    function renderStrip(onChanged = vi.fn()): { onChanged: ReturnType<typeof vi.fn> } {
        render(TakeStrip, {
            projectId: 1,
            manualId: 2,
            cut: makeCut(threeTakes()),
            cutLabel: "手順 1",
            onChanged,
        });
        return { onChanged };
    }

    /** ハンドルを掴んで pointerY まで動かし drop する */
    async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
        await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", startY));
        await fireEvent(window, pointerEvent("pointermove", endY));
        await fireEvent(window, pointerEvent("pointerup", endY));
    }

    /** 直近の PATCH の URL と body */
    function lastPatch(): { url: string; body: unknown } {
        const call = fetchMock.mock.calls.filter((c) => c[1]?.method === "PATCH").at(-1);
        if (!call) throw new Error("PATCH リクエストがありません");
        return { url: String(call[0]), body: JSON.parse(String(call[1].body)) as unknown };
    }

    beforeEach(() => {
        stubRowRects();
    });

    it("1 番目のテイクを 3 番目へ落とすと position: 2 の PATCH が飛び、親が再取得する", async () => {
        fetchMock.mockResolvedValue(jsonResponse(200, {}));
        const { onChanged } = renderStrip();

        // 掴んだ行 index 0 → 最終行の中点 (250) より下 = 挿入 index 3 → 最終 index 2
        await dragHandle("take-drag-10", 50, 260);

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
        expect(lastPatch().body).toEqual({ position: 2 });
        // 楽観更新はせずサーバ権威。成功したら親が最新を取り直す
        await waitFor(() => expect(onChanged).toHaveBeenCalled());
    });

    it("3 番目のテイクを 1 番目へ落とすと position: 0 の PATCH が飛ぶ", async () => {
        fetchMock.mockResolvedValue(jsonResponse(200, {}));
        renderStrip();

        await dragHandle("take-drag-12", 250, 10);

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
        expect(lastPatch().body).toEqual({ position: 0 });
    });

    it("位置が変わらない drop では通信しない", async () => {
        renderStrip();

        // 掴んだ行 index 0 の直後の隙間 (挿入 index 1) → 最終 index 0 = from
        await dragHandle("take-drag-10", 50, 120);

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("ドラッグ中の Escape では通信しない", async () => {
        renderStrip();

        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
        await fireEvent(window, pointerEvent("pointermove", 260));
        await fireEvent.keyDown(window, { key: "Escape" });
        await fireEvent(window, pointerEvent("pointerup", 260));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("ドラッグ中の pointercancel では通信しない", async () => {
        renderStrip();

        await fireEvent(screen.getByTestId("take-drag-10"), pointerEvent("pointerdown", 50));
        await fireEvent(window, pointerEvent("pointermove", 260));
        await fireEvent(window, pointerEvent("pointercancel", 260));

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("ハンドル上の ArrowDown は ▼ と同じ 1 段移動の PATCH を出す", async () => {
        fetchMock.mockResolvedValue(jsonResponse(200, {}));
        renderStrip();

        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowDown" });

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/10");
        expect(lastPatch().body).toEqual({ position: 1 });
    });

    it("ハンドル上の ArrowUp は ▲ と同じ 1 段移動の PATCH を出す", async () => {
        fetchMock.mockResolvedValue(jsonResponse(200, {}));
        renderStrip();

        await fireEvent.keyDown(screen.getByTestId("take-drag-12"), { key: "ArrowUp" });

        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(lastPatch().url).toBe("/app/projects/1/manuals/2/cuts/3/takes/12");
        expect(lastPatch().body).toEqual({ position: 1 });
    });

    it.each([
        ["先頭で ▲", "take-up-10", "take-adopt-10", "これ以上、上へは移動できません"],
        ["末尾で ▼", "take-down-12", "take-adopt-12", "これ以上、下へは移動できません"],
    ])(
        "%s は通信せず・busy にせず・再取得せず、理由を告知する",
        async (_label, testId, adoptTestId, message) => {
            const { onChanged } = renderStrip();

            await fireEvent.click(screen.getByTestId(testId));

            expect(fetchMock).not.toHaveBeenCalled();
            expect(onChanged).not.toHaveBeenCalled();
            // busy は**操作した行**で見る (別の行を見ると空振りする)
            expect(screen.getByTestId(adoptTestId)).not.toHaveAttribute("aria-busy");
            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(message);
        },
    );

    it("端のハンドル操作 (ArrowUp) も同じく通信せず理由を告知する", async () => {
        renderStrip();

        await fireEvent.keyDown(screen.getByTestId("take-drag-10"), { key: "ArrowUp" });

        expect(fetchMock).not.toHaveBeenCalled();
        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
            "これ以上、上へは移動できません",
        );
    });

    it("成功した並べ替えは aria-live で告知する", async () => {
        fetchMock.mockResolvedValue(jsonResponse(200, {}));
        renderStrip();

        await dragHandle("take-drag-10", 50, 260);

        await waitFor(() =>
            expect(screen.getByTestId("take-reorder-status")).toHaveTextContent(
                "テイク 1 を 3 番目に移動しました",
            ),
        );
    });

    it("PATCH が 422 ならサーバ文言を role=alert に出し、告知はしない", async () => {
        fetchMock.mockResolvedValue(jsonResponse(422, { message: "処理中のため並べ替えできません" }));
        renderStrip();

        await dragHandle("take-drag-10", 50, 260);

        await waitFor(() =>
            expect(screen.getByTestId("take-strip-error")).toHaveTextContent(
                "処理中のため並べ替えできません",
            ),
        );
        expect(screen.getByTestId("take-reorder-status")).toHaveTextContent("");
    });

    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
        renderStrip();

        for (const id of ["take-drag-10", "take-drag-11", "take-drag-12"]) {
            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
        }
    });
});
