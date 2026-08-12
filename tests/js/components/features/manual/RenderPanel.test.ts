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
    playbackJob: null,
    finishedJob: null,
    coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
    canManage: true,
};

/** 受け取れる完成動画 (サーバが published + download ability + 現行世代を判定済み) */
function finishedJobBody(overrides: Partial<RenderJobProps> = {}): RenderJobProps {
    return renderJobBody({
        id: 77,
        kind: "render",
        status: "succeeded",
        progress: 100,
        manual_status: "published",
        placeholder_cut_count: 0,
        ...overrides,
    });
}

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
        placeholder_cut_count: null,
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
        // T040: 起動失敗は完成動画への帰属を title で明示する
        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
            "完成動画の生成を開始できませんでした",
        );
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

    it("finishedJob があると完成動画プレイヤーと DL ボタンの両方が出る", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "published" as const,
                finishedJob: finishedJobBody(),
            },
        });

        expect(screen.getByTestId("final-video-block")).toBeInTheDocument();
        expect(screen.getByTestId("final-video")).toBeInTheDocument();
        expect(screen.getByTestId("download-button")).toHaveAttribute(
            "href",
            "/projects/1/manuals/5/download",
        );
    });

    it("完成動画プレイヤーの src は playback route を job id 込みで指す (再レンダで URL が変わる)", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "published" as const,
                finishedJob: finishedJobBody({ id: 91 }),
            },
        });

        expect(screen.getByTestId("final-video")).toHaveAttribute(
            "src",
            "/projects/1/manuals/5/render-jobs/91/playback",
        );
        expect(screen.getByTestId("final-video")).toHaveAttribute("preload", "none");
    });

    it("finishedJob があれば canManage=false でも完成動画ブロックは出る (表示条件は finishedJob だけ)", () => {
        // サーバが渡した成果物を UI が独自条件で隠さないことの固定。
        // 現行 props ではこの組合せは発生しない (props が download ability を評価済み) が、
        // component 単体の契約として作為的に与える。
        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "published" as const,
                canManage: false,
                finishedJob: finishedJobBody(),
            },
        });

        expect(screen.getByTestId("final-video-block")).toBeInTheDocument();
        expect(screen.getByTestId("download-button")).toBeInTheDocument();
    });

    it("finishedJob が null なら完成動画プレイヤーも DL ボタンも出ない (published でも)", () => {
        // 「押すと 404」の導線を UI から消したことの固定
        render(RenderPanel, {
            props: { ...baseProps, manualStatus: "published" as const, finishedJob: null },
        });

        expect(screen.queryByTestId("final-video-block")).not.toBeInTheDocument();
        expect(screen.queryByTestId("final-video")).not.toBeInTheDocument();
        expect(screen.queryByTestId("download-button")).not.toBeInTheDocument();
    });

    it("完成動画には黒背景の注記を出さない (placeholder_cut_count=0 / null の両方)", () => {
        // succeeded render の値契約は 0 (T148)。完成動画用の注記分岐は新設していない。
        const { unmount } = render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "published" as const,
                finishedJob: finishedJobBody({ placeholder_cut_count: 0 }),
            },
        });
        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
        unmount();

        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "published" as const,
                finishedJob: finishedJobBody({ placeholder_cut_count: null }),
            },
        });
        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
    });

    it("書き出し中 (rendering) は完成動画ブロックを描画しない", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                manualStatus: "rendering" as const,
                finishedJob: finishedJobBody(),
            },
        });

        expect(screen.getByTestId("render-progress")).toBeInTheDocument();
        expect(screen.queryByTestId("final-video-block")).not.toBeInTheDocument();
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
        // T040: ジョブ失敗も完成動画への帰属を title で明示する
        expect(screen.getByTestId("render-error")).toHaveTextContent("完成動画の生成に失敗しました");
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
        // T040: プレビューのジョブ失敗を title で帰属明示する
        expect(screen.getByTestId("preview-error")).toHaveTextContent("プレビューの生成に失敗しました");
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
            props: {
                ...baseProps,
                playbackJob: renderJobBody({ id: 33, kind: "preview", status: "succeeded" }),
            },
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

    // --- T040 F-1-2: 起動失敗 alert の source+phase 帰属 ---

    it("プレビュー起動 402 は preview-start-error に帰属し、完成動画欄には出さない", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(402, {
                code: "insufficient_tickets",
                message: "チケット残高が不足しています (必要: 1 / 残高: 0)。",
            }),
        );

        render(RenderPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("preview-button"));

        await waitFor(() => {
            expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
                "チケット残高が不足しています",
            );
        });
        expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
            "プレビューの生成を開始できませんでした",
        );
        // プレビュー起動 402 の購入導線は preview 側に出る
        expect(screen.getByTestId("preview-purchase-link")).toBeInTheDocument();
        expect(
            new URL(
                (screen.getByTestId("preview-purchase-link") as HTMLAnchorElement).href,
            ).pathname,
        ).toBe("/purchase-tickets");
        // 完成動画欄へ誤帰属しない
        expect(screen.queryByTestId("render-start-error")).not.toBeInTheDocument();
        expect(screen.queryByTestId("render-purchase-link")).not.toBeInTheDocument();
    });

    it("完成動画起動失敗とプレビュー起動失敗は別々に共存し、後発が先発を消さない", async () => {
        render(RenderPanel, { props: baseProps });

        // 完成動画起動を 422 で失敗させる
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "採用テイクが不足しています。" }),
        );
        await fireEvent.click(screen.getByTestId("render-button"));
        await waitFor(() => {
            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByText("生成する"));
        await waitFor(() => {
            expect(screen.getByTestId("render-start-error")).toHaveTextContent(
                "採用テイクが不足しています",
            );
        });

        // 続けてプレビュー起動を 422 で失敗させる
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "プレビューを開始できません。" }),
        );
        await fireEvent.click(screen.getByTestId("preview-button"));
        await waitFor(() => {
            expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
                "プレビューを開始できません",
            );
        });

        // 両方が別 title で共存する (後発が先発を上書きしない)
        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
            "完成動画の生成を開始できませんでした",
        );
        expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
            "プレビューの生成を開始できませんでした",
        );
    });

    it("片方の起動成功では該当 source の失敗のみ消え、もう片方の失敗表示は温存する", async () => {
        render(RenderPanel, { props: baseProps });

        // 先にプレビュー起動を 422 で失敗させる
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "プレビューを開始できません。" }),
        );
        await fireEvent.click(screen.getByTestId("preview-button"));
        await waitFor(() => {
            expect(screen.getByTestId("preview-start-error")).toBeInTheDocument();
        });

        // 続けて完成動画起動を成功 (201) させる
        fetchMock.mockResolvedValueOnce(jsonResponse(201, renderJobBody()));
        await fireEvent.click(screen.getByTestId("render-button"));
        await waitFor(() => {
            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByText("生成する"));
        await waitFor(() => {
            expect(screen.getByTestId("render-progress")).toBeInTheDocument();
        });

        // render 側は成功したが、preview 側の失敗表示は温存される (source 別クリア)
        expect(screen.queryByTestId("render-start-error")).not.toBeInTheDocument();
        expect(screen.getByTestId("preview-start-error")).toHaveTextContent(
            "プレビューを開始できません",
        );
    });

    it("プレビューのジョブ失敗と完成動画の起動失敗が別 title で並ぶ", async () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                previewJob: renderJobBody({
                    kind: "preview",
                    status: "failed",
                    error: "プレビュー合成に失敗しました。",
                    error_code: "internal",
                    manual_status: "ready",
                }),
            },
        });

        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, { message: "採用テイクが不足しています。" }),
        );
        await fireEvent.click(screen.getByTestId("render-button"));
        await waitFor(() => {
            expect(screen.getByTestId("render-dialog")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByText("生成する"));
        await waitFor(() => {
            expect(screen.getByTestId("render-start-error")).toBeInTheDocument();
        });

        expect(screen.getByTestId("preview-error")).toHaveTextContent(
            "プレビューの生成に失敗しました",
        );
        expect(screen.getByTestId("render-start-error")).toHaveTextContent(
            "完成動画の生成を開始できませんでした",
        );
    });

    // --- T148 (bug-hunt F-1-01): 事前告知 (coverage) と事後説明 (placeholder_cut_count) ---

    it("D-1: missing_count>0 でプレビュー近傍に事前告知を出す", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                coverage: { total_cuts: 4, missing_count: 3, missing_labels: ["手順2", "手順3", "手順4"] },
            },
        });

        const note = screen.getByTestId("preview-coverage-note");
        expect(note).toHaveTextContent("3");
        expect(note).toHaveTextContent("4");
        expect(note).toHaveTextContent("手順2、手順3、手順4");
        // 述語は「未撮影」ではなく「撮影・処理が完了した採用テイクがない」ことを言う
        expect(note).toHaveTextContent("撮影・処理が完了した採用テイクがありません");
    });

    it("D-2: missing_count>0 でもプレビュー生成ボタンは disabled にならない (禁止事項 8)", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                coverage: { total_cuts: 2, missing_count: 1, missing_labels: ["手順2"] },
            },
        });

        const previewButton = screen.getByTestId("preview-button");
        expect(previewButton).not.toBeDisabled();
        expect(previewButton).not.toHaveAttribute("aria-disabled", "true");
        const renderButton = screen.getByTestId("render-button");
        expect(renderButton).not.toBeDisabled();
        expect(renderButton).not.toHaveAttribute("aria-disabled", "true");
    });

    it("D-3: missing_count が 0 なら事前告知を出さない", () => {
        render(RenderPanel, { props: baseProps });

        expect(screen.queryByTestId("preview-coverage-note")).not.toBeInTheDocument();
    });

    it("D-4: playbackJob.placeholder_cut_count>0 なら動画の上に事後説明を出す", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                playbackJob: renderJobBody({
                    id: 33,
                    kind: "preview",
                    status: "succeeded",
                    placeholder_cut_count: 2,
                }),
            },
        });

        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("2");
        expect(screen.getByTestId("preview-video")).toBeInTheDocument();
    });

    it("D-5: placeholder_cut_count が null なら事後説明を出さない (0 と同一視しない)", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                playbackJob: renderJobBody({
                    id: 33,
                    kind: "preview",
                    status: "succeeded",
                    placeholder_cut_count: null,
                }),
            },
        });

        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
        expect(screen.getByTestId("preview-video")).toBeInTheDocument();
    });

    it("D-5b: placeholder_cut_count が 0 なら事後説明を出さない", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                playbackJob: renderJobBody({
                    id: 33,
                    kind: "preview",
                    status: "succeeded",
                    placeholder_cut_count: 0,
                }),
            },
        });

        expect(screen.queryByTestId("preview-placeholder-note")).not.toBeInTheDocument();
    });

    /*
     * 完成動画の直下に矛盾する注記が残る問題 (T159 / bug-hunt F-1-02)。
     *
     * 注記は**常に「生成時点で」**と書く (現在形にしない = 部分解消でも誤読を作らない)。
     * 黒背景の理由が**完全に解消**しているとき (placeholder_cut_count>0 かつ missing_count===0)
     * だけ、現在状態と再生成の案内を足す。
     * **「プレビューが古い」という一般命題は名乗らない** (シナリオ編集等では判定できないため)。
     */
    // 戻り値の型注釈は付けない (baseProps の null 型に狭まってしまうため。呼び出し側は render の props)
    function stalePreviewProps(missingCount: number, withFinished: boolean) {
        return {
            ...baseProps,
            coverage: {
                total_cuts: 20,
                missing_count: missingCount,
                missing_labels: missingCount > 0 ? ["手順 1"] : [],
            },
            playbackJob: renderJobBody({
                id: 33,
                kind: "preview",
                status: "succeeded",
                placeholder_cut_count: 20,
            }),
            finishedJob: withFinished ? finishedJobBody() : null,
        };
    }

    it.each([false, true])(
        "T159 契約 1: 部分解消 (finishedJob=%s) では生成時点の件数だけを書き、現在状態の文は出さない",
        (withFinished) => {
            render(RenderPanel, { props: stalePreviewProps(5, withFinished) });

            const note = screen.getByTestId("preview-placeholder-note");
            expect(note).toHaveTextContent("生成時点で 20 件");
            expect(note).not.toHaveTextContent("現在のシナリオでは未採用のカットはありません");
        },
    );

    it.each([false, true])(
        "T159 契約 2: 完全解消 (finishedJob=%s) では現在状態と再生成の案内を足す",
        (withFinished) => {
            render(RenderPanel, { props: stalePreviewProps(0, withFinished) });

            const note = screen.getByTestId("preview-placeholder-note");
            expect(note).toHaveTextContent("生成時点で 20 件");
            expect(note).toHaveTextContent("現在のシナリオでは未採用のカットはありません");
            expect(note).toHaveTextContent("プレビューを再生成");
        },
    );

    it("T159 契約 5: 現在形の断定文 (「〜ないため、〜黒背景になっています」) は残っていない", () => {
        render(RenderPanel, { props: stalePreviewProps(5, true) });

        expect(screen.getByTestId("preview-placeholder-note")).not.toHaveTextContent(
            "ないため、その区間が黒背景になっています",
        );
    });

    it("D-6: 事後説明と動画 URL は同一の playbackJob から出る (最新 preview が別世代でも)", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                // 最新 preview job は別世代 (失敗済み・件数 9)
                previewJob: renderJobBody({
                    id: 44,
                    kind: "preview",
                    status: "failed",
                    error: "書き出しに失敗しました。",
                    error_code: "internal",
                    manual_status: "ready",
                    placeholder_cut_count: 9,
                }),
                playbackJob: renderJobBody({
                    id: 33,
                    kind: "preview",
                    status: "succeeded",
                    placeholder_cut_count: 2,
                }),
            },
        });

        expect(screen.getByTestId("preview-video")).toHaveAttribute(
            "src",
            "/projects/1/manuals/5/render-jobs/33/playback",
        );
        const note = screen.getByTestId("preview-placeholder-note");
        expect(note).toHaveTextContent("2");
        expect(note).not.toHaveTextContent("9");
    });

    it("D-7: missing_labels が打ち切られているとき「ほか N 件」を出す", () => {
        render(RenderPanel, {
            props: {
                ...baseProps,
                coverage: {
                    total_cuts: 12,
                    missing_count: 11,
                    missing_labels: [
                        "手順2",
                        "手順3",
                        "手順4",
                        "手順5",
                        "手順6",
                        "手順7",
                        "手順8",
                        "手順9",
                        "手順10",
                        "手順11",
                    ],
                },
            },
        });

        expect(screen.getByTestId("preview-coverage-note")).toHaveTextContent("ほか 1 件");
    });

    it("D-8: preview 成功のポーリング応答で playbackJob が更新され事後説明も追随する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(
                200,
                renderJobBody({
                    id: 77,
                    kind: "preview",
                    status: "succeeded",
                    manual_status: "ready",
                    placeholder_cut_count: 5,
                }),
            ),
        );

        render(RenderPanel, {
            props: {
                ...baseProps,
                previewJob: renderJobBody({
                    id: 77,
                    kind: "preview",
                    status: "running",
                    manual_status: "ready",
                }),
            },
        });

        await waitFor(() => {
            expect(screen.getByTestId("preview-video")).toHaveAttribute(
                "src",
                "/projects/1/manuals/5/render-jobs/77/playback",
            );
        });
        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("5");
    });
});
