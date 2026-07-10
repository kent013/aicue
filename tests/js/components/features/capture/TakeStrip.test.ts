import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
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
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    vi.unstubAllGlobals();
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

    it("DL 済みテイクの削除ボタンは disabled にせず、押下時に 422 メッセージを表示する", async () => {
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

        await waitFor(() =>
            expect(screen.getByTestId("take-strip-error").textContent).toContain(
                "ダウンロード済みのテイクは削除できません",
            ),
        );
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
