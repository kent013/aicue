import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
import type { AnalysisJobProps } from "@/types/manual";

// router.reload はテスト環境では実行できないためモックする
const { routerReloadMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", () => ({
    router: {
        reload: routerReloadMock,
    },
}));

/*
 * AI 解析パネル:
 * - draft + document で解析ボタン → POST /analyze (fetch)
 * - 402/422 はサーバの message を表示 (ボタンは disabled にしない)
 * - analyzing 中は進捗 + step ラベル
 * - failed はエラー表示 + 再実行可能
 */

const fetchMock = vi.fn();

const baseProps = {
    projectId: 1,
    manualId: 5,
    manualStatus: "draft" as const,
    job: null,
    hasDocument: true,
    canManage: true,
};

function jsonResponse(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { "Content-Type": "application/json" },
    });
}

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
    document.cookie = "XSRF-TOKEN=test-token";
});

afterEach(() => {
    cleanup();
    fetchMock.mockReset();
    routerReloadMock.mockReset();
    vi.unstubAllGlobals();
});

describe("AnalysisPanel", () => {
    it("draft + document で解析ボタンを表示し、押下で POST /analyze が飛ぶ", async () => {
        const job: AnalysisJobProps = {
            id: 9,
            status: "queued",
            step: null,
            progress: null,
            error: null,
            manual_status: "analyzing",
        };
        fetchMock.mockResolvedValueOnce(jsonResponse(201, job));

        render(AnalysisPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("analyze-button"));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/projects/1/manuals/5/analyze");
        expect(init.method).toBe("POST");
        expect((init.headers as Record<string, string>)["X-XSRF-TOKEN"]).toBe("test-token");

        // 201 応答で analyzing 表示へ切り替わる
        await waitFor(() => {
            expect(screen.getByTestId("analysis-progress")).toBeInTheDocument();
        });
    });

    it("402 (残高不足) はサーバの message を表示し、ボタンは押せるまま", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(402, {
                code: "insufficient_tickets",
                message: "チケット残高が不足しています (必要: 1 / 残高: 0)。",
            }),
        );

        render(AnalysisPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("analyze-button"));

        await waitFor(() => {
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "チケット残高が不足しています",
            );
        });
        expect(screen.getByTestId("analyze-button")).not.toBeDisabled();
    });

    it("422 (手順書なし) もサーバの message を表示する (disabled にしない)", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(422, {
                message: "手順書をアップロードしてください。",
                errors: { document: ["手順書をアップロードしてください。"] },
            }),
        );

        render(AnalysisPanel, { props: { ...baseProps, hasDocument: false } });
        await fireEvent.click(screen.getByTestId("analyze-button"));

        await waitFor(() => {
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "手順書をアップロードしてください",
            );
        });
        expect(screen.getByTestId("analyze-button")).not.toBeDisabled();
    });

    it("analyzing 中は step ラベルと progress を表示し、解析ボタンは出さない", () => {
        fetchMock.mockResolvedValue(
            jsonResponse(200, {
                id: 9,
                status: "running",
                step: "decompose",
                progress: 35,
                error: null,
                manual_status: "analyzing",
            }),
        );

        render(AnalysisPanel, {
            props: {
                ...baseProps,
                manualStatus: "analyzing" as const,
                job: {
                    id: 9,
                    status: "running",
                    step: "decompose",
                    progress: 35,
                    error: null,
                    manual_status: "analyzing",
                } satisfies AnalysisJobProps,
            },
        });

        expect(screen.getByTestId("analysis-step-label")).toHaveTextContent("作業を分解中");
        expect(screen.queryByTestId("analyze-button")).toBeNull();
        expect(screen.getByRole("progressbar").getAttribute("aria-valuenow")).toBe("35");
    });

    it("failed job はエラーを表示し、再実行 (解析ボタン) できる", () => {
        render(AnalysisPanel, {
            props: {
                ...baseProps,
                manualStatus: "draft" as const,
                job: {
                    id: 9,
                    status: "failed",
                    step: "extract",
                    progress: 10,
                    error: "テキストを抽出できません。画像・スキャンの手順書は現在未対応です。",
                    manual_status: "draft",
                } satisfies AnalysisJobProps,
            },
        });

        expect(screen.getByTestId("analysis-error")).toHaveTextContent("テキストを抽出できません");
        expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
    });

    it("canManage=false は解析ボタンを出さない (進捗表示のみ)", () => {
        render(AnalysisPanel, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("analyze-button")).toBeNull();
    });

    it("running 応答で currentJob を更新しても再購読されず、ポーリングは 2.5 秒間隔を保つ", async () => {
        vi.useFakeTimers();
        const runningJob: AnalysisJobProps = {
            id: 9,
            status: "running",
            step: "decompose",
            progress: 35,
            error: null,
            manual_status: "analyzing",
        };
        // 毎回新しいオブジェクトを返す (同一 id の running 更新で effect が再購読されないことの検証)
        fetchMock.mockImplementation(() =>
            Promise.resolve(jsonResponse(200, { ...runningJob })),
        );

        const { unmount } = render(AnalysisPanel, {
            props: {
                ...baseProps,
                manualStatus: "analyzing" as const,
                job: runningJob,
            },
        });

        try {
            // effect 起動直後の即時 poll で 1 回
            await vi.advanceTimersByTimeAsync(0);
            expect(fetchMock).toHaveBeenCalledTimes(1);

            // 応答で currentJob が更新されても、interval 発火前に再ポーリングされない
            // (effect が currentJob を反応的に読むとここでタイトループになる回帰の検出)
            await vi.advanceTimersByTimeAsync(1000);
            expect(fetchMock).toHaveBeenCalledTimes(1);

            // 2.5 秒経過で 2 回目、さらに 2.5 秒で 3 回目
            await vi.advanceTimersByTimeAsync(1500);
            expect(fetchMock).toHaveBeenCalledTimes(2);
            await vi.advanceTimersByTimeAsync(2500);
            expect(fetchMock).toHaveBeenCalledTimes(3);
        } finally {
            unmount();
            vi.useRealTimers();
        }
    });

    it("ready からの起動は確認ダイアログを挟む (既存シナリオ置換の警告)", async () => {
        render(AnalysisPanel, { props: { ...baseProps, manualStatus: "ready" as const } });
        await fireEvent.click(screen.getByTestId("analyze-button"));

        // fetch はまだ飛ばず、確認ダイアログが開く
        expect(fetchMock).not.toHaveBeenCalled();
        await waitFor(() => {
            expect(screen.getByTestId("reanalyze-dialog")).toBeInTheDocument();
        });
    });
});
