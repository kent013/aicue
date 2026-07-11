import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import RenderPanel from "@/components/features/manual/RenderPanel.svelte";
import type { RenderJobProps } from "@/types/manual";

// router.reload はテスト環境では実行できないためモックする
const { routerReloadMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
}));

// Link (TextLink 経由) は実物を使い、router のみ差し替える
vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        reload: routerReloadMock,
    },
}));

/*
 * レンダパネル:
 * - ready + canManage で「完成動画を生成」(確認ダイアログ) と「プレビュー生成」
 * - 402/409/422 はサーバの message を表示 (ボタンは disabled にしない)
 * - rendering 中は進捗 + step ラベル / published は DL リンク
 * - preview failed + error_code=scenario_version_changed は「作り直す」CTA
 */

const fetchMock = vi.fn();

const baseProps = {
    projectId: 1,
    manualId: 5,
    manualStatus: "ready" as const,
    job: null,
    previewJob: null,
    playbackJobId: null,
    canManage: true,
};

function renderJobBody(overrides: Partial<RenderJobProps> = {}): RenderJobProps {
    return {
        id: 9,
        kind: "render",
        status: "queued",
        step: null,
        progress: null,
        error: null,
        error_code: null,
        manual_status: "rendering",
        ...overrides,
    };
}

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

describe("RenderPanel", () => {
    it("ready + canManage で生成ボタンを表示し、確認ダイアログ経由で POST /render が飛ぶ", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(201, renderJobBody()));

        render(RenderPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("render-button"));

        // fetch はまだ飛ばず、確認ダイアログが開く (チケット消費の警告)
        expect(fetchMock).not.toHaveBeenCalled();
        await waitFor(() => {
            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
        });

        await fireEvent.click(screen.getByText("生成する"));
        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [url, init] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/projects/1/manuals/5/render");
        expect(init.method).toBe("POST");
        expect((init.headers as Record<string, string>)["X-XSRF-TOKEN"]).toBe("test-token");

        // 201 応答で rendering 進捗表示へ切り替わる
        await waitFor(() => {
            expect(screen.getByTestId("render-progress")).toBeInTheDocument();
        });
    });

    it("プレビュー生成は確認ダイアログなしで POST /preview が飛ぶ", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(201, renderJobBody({ kind: "preview", manual_status: "ready" })),
        );

        render(RenderPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("preview-button"));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/projects/1/manuals/5/preview");

        // preview の in-flight 表示
        await waitFor(() => {
            expect(screen.getByTestId("preview-progress")).toBeInTheDocument();
        });
    });

    it("402 (残高不足) はサーバの message を表示し、ボタンは押せるまま", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(402, {
                code: "insufficient_tickets",
                message: "チケット残高が不足しています (必要: 3 / 残高: 0)。",
            }),
        );

        render(RenderPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("render-button"));
        await waitFor(() => {
            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByText("生成する"));

        await waitFor(() => {
            expect(screen.getByTestId("render-start-error")).toHaveTextContent(
                "チケット残高が不足しています",
            );
        });
        // 押下可能なまま (disabled にしない)
        expect(screen.getByTestId("render-button")).toBeInTheDocument();
        // T007: 残高不足 (code 厳格一致) では購入導線を併記する
        expect(screen.getByTestId("render-purchase-link")).toBeInTheDocument();
        expect(
            new URL(
                (screen.getByTestId("render-purchase-link") as HTMLAnchorElement).href,
            ).pathname,
        ).toBe("/purchase-tickets");
    });

    it("rendering 中は step ラベルと progress を表示する", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "rendering" as const,
                job: renderJobBody({ status: "running", step: "compose", progress: 42 }),
            },
        });

        expect(screen.getByTestId("render-progress")).toBeInTheDocument();
        expect(screen.getByTestId("render-step-label")).toHaveTextContent("カットを合成中");
    });

    it("published + canManage はダウンロード導線を表示する", () => {
        render(RenderPanel, {
            props: { ...baseProps, manualStatus: "published" as const },
        });

        const link = screen.getByTestId("download-button");
        expect(link).toHaveAttribute("href", "/projects/1/manuals/5/download");
    });

    it("render failed はエラーを表示する", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                job: renderJobBody({
                    status: "failed",
                    error: "書き出しに失敗しました。時間をおいて再実行してください。",
                    error_code: "internal",
                    manual_status: "ready",
                }),
            },
        });

        expect(screen.getByTestId("render-error")).toHaveTextContent("書き出しに失敗しました");
    });

    it("preview failed + scenario_version_changed は「作り直す」CTA を表示する", async () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                previewJob: renderJobBody({
                    kind: "preview",
                    status: "failed",
                    error: "編集中にシナリオが変更されたため、プレビューを作り直してください。",
                    error_code: "scenario_version_changed",
                    manual_status: "ready",
                }),
            },
        });

        expect(screen.getByTestId("preview-error")).toHaveTextContent("シナリオが変更された");
        fetchMock.mockResolvedValueOnce(
            jsonResponse(201, renderJobBody({ kind: "preview", manual_status: "ready" })),
        );
        await fireEvent.click(screen.getByTestId("preview-retry-button"));
        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalled();
        });
        const [url] = fetchMock.mock.calls[0] as [string, RequestInit];
        expect(url).toBe("/projects/1/manuals/5/preview");
    });

    it("再生可能な preview があれば <video> を playback route で表示する", () => {
        render(RenderPanel, {
            props: { ...baseProps, playbackJobId: 33 },
        });

        const video = screen.getByTestId("preview-video");
        expect(video).toHaveAttribute("src", "/projects/1/manuals/5/render-jobs/33/playback");
    });

    it("succeeded だが manual=ready (編集済み) は再生成の案内を表示する", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                job: renderJobBody({ status: "succeeded", progress: 100, manual_status: "ready" }),
            },
        });

        expect(screen.getByTestId("render-regenerate-note")).toHaveTextContent("再生成");
    });

    it("canManage=false はボタンを表示しない (進捗のみ)", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                canManage: false,
                manualStatus: "rendering" as const,
                job: renderJobBody({ status: "running", step: "concat", progress: 90 }),
            },
        });

        expect(screen.queryByTestId("render-button")).not.toBeInTheDocument();
        expect(screen.queryByTestId("preview-button")).not.toBeInTheDocument();
        expect(screen.getByTestId("render-progress")).toBeInTheDocument();
    });
});
