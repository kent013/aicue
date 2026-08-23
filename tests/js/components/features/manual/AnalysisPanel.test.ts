import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import AnalysisPanel from "@/components/features/manual/AnalysisPanel.svelte";
import type { AnalysisJobProps } from "@/types/manual";

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
        expect(url).toBe("/organizations/test-org/projects/1/manuals/5/analyze");
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
        // T007: 残高不足 (code 厳格一致) では購入導線を併記する
        expect(screen.getByTestId("analysis-purchase-link")).toBeInTheDocument();
        expect(
            new URL(
                (screen.getByTestId("analysis-purchase-link") as HTMLAnchorElement).href,
            ).pathname,
        ).toBe("/organizations/test-org/billing/purchase-tickets");
    });

    it("insufficient_tickets 以外の 402/409 では購入導線を出さない (誤表示防止)", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(409, {
                code: "analysis_conflict",
                message: "解析が進行中です。",
            }),
        );

        render(AnalysisPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("analyze-button"));

        await waitFor(() => {
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "解析が進行中です。",
            );
        });
        expect(screen.queryByTestId("analysis-purchase-link")).toBeNull();
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

    it("SOP アップロード成功 (hasDocument false→true) で 422 の残留 alert が消える", async () => {
        // bug-hunt F-H2 再現: 422 の start error が SOP アップロード成功後も残留しないこと
        fetchMock.mockResolvedValue(
            jsonResponse(422, {
                message: "手順書をアップロードしてください。",
                errors: { document: ["手順書をアップロードしてください。"] },
            }),
        );
        const { rerender } = render(AnalysisPanel, {
            props: { ...baseProps, hasDocument: false },
        });
        await fireEvent.click(screen.getByTestId("analyze-button"));
        await waitFor(() =>
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "手順書をアップロードしてください",
            ),
        );
        // SOP アップロード成功 = Inertia が hasDocument=true で Show を再描画
        await rerender({ ...baseProps, hasDocument: true });
        await waitFor(() => expect(screen.queryByTestId("analysis-start-error")).toBeNull());
        // 非干渉: 購入リンク等の他 overlay を巻き込んで表示していない
        expect(screen.queryByTestId("analysis-purchase-link")).toBeNull();
    });

    it("402 (残高不足) は他 props 更新後も消えない (missing_document 以外は保持)", async () => {
        fetchMock.mockResolvedValue(
            jsonResponse(402, {
                code: "insufficient_tickets",
                message: "チケット残高が不足しています。",
            }),
        );
        // 402 は手順書ありの文脈で発生する → hasDocument:true 開始 (baseProps 既定)
        const { rerender } = render(AnalysisPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("analyze-button"));
        await waitFor(() =>
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "チケット残高が不足",
            ),
        );
        // 別 props (manualStatus) が更新されても start error は保持される
        await rerender({ ...baseProps, manualStatus: "ready" as const });
        expect(screen.getByTestId("analysis-start-error")).toBeInTheDocument();
    });

    it("非退行: failed job (server-truth) は hasDocument false→true でも維持される", async () => {
        const failedJob: AnalysisJobProps = {
            id: 9,
            status: "failed",
            step: "extract",
            progress: 10,
            error: "テキストを抽出できません。画像・スキャンの手順書は現在未対応です。",
            manual_status: "draft",
        };
        const { rerender } = render(AnalysisPanel, {
            props: { ...baseProps, hasDocument: false, manualStatus: "draft" as const, job: failedJob },
        });
        expect(screen.getByTestId("analysis-error")).toHaveTextContent("テキストを抽出できません");
        // overlay 破棄は start-error のみ対象。failedJob は currentJob 由来なので残る
        await rerender({
            ...baseProps,
            hasDocument: true,
            manualStatus: "draft" as const,
            job: failedJob,
        });
        expect(screen.getByTestId("analysis-error")).toBeInTheDocument();
    });

    it("解析要求中に hasDocument が true になり、遅延 422 が来ても alert は残らない", async () => {
        let resolveFetch!: (r: Response) => void;
        fetchMock.mockReturnValue(
            new Promise<Response>((r) => {
                resolveFetch = r;
            }),
        );
        const { rerender } = render(AnalysisPanel, {
            props: { ...baseProps, hasDocument: false },
        });
        await fireEvent.click(screen.getByTestId("analyze-button"));
        // 応答が返る前に SOP アップロード完了 → Inertia が hasDocument=true で再描画
        await rerender({ ...baseProps, hasDocument: true });
        // 遅延していた 422 がここで解決 (分類は hadDocumentAtStart=false → missing_document)
        resolveFetch(
            jsonResponse(422, {
                message: "手順書をアップロードしてください。",
                errors: { document: ["手順書をアップロードしてください。"] },
            }),
        );
        // level-triggered effect が hasDocument=true && missing_document を検知して即破棄
        await waitFor(() => expect(screen.queryByTestId("analysis-start-error")).toBeNull());
    });

    it("422 でも解析要求時に手順書があれば (hadDocumentAtStart=true) generic 扱いで破棄されない", async () => {
        // 分類仕様の固定: missing_document は「要求時に手順書なし」限定。要求時 true の 422 は
        // errors.document を含んでいても generic とし、hasDocument=true でも自動破棄しない。
        fetchMock.mockResolvedValue(
            jsonResponse(422, {
                message: "手順書をアップロードしてください。",
                errors: { document: ["手順書をアップロードしてください。"] },
            }),
        );
        // baseProps は hasDocument:true
        const { rerender } = render(AnalysisPanel, { props: baseProps });
        await fireEvent.click(screen.getByTestId("analyze-button"));
        await waitFor(() =>
            expect(screen.getByTestId("analysis-start-error")).toHaveTextContent(
                "手順書をアップロードしてください",
            ),
        );
        // hasDocument は true のまま (別 props 更新) → generic なので破棄されない
        await rerender({ ...baseProps, manualStatus: "ready" as const });
        expect(screen.getByTestId("analysis-start-error")).toBeInTheDocument();
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

    it("ポーリングが 401 を受けたら停止し、セッション失効の案内を表示する", async () => {
        vi.useFakeTimers();
        fetchMock.mockImplementation(() =>
            Promise.resolve(jsonResponse(401, { message: "Unauthenticated." })),
        );

        const { unmount } = render(AnalysisPanel, {
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

        try {
            await vi.advanceTimersByTimeAsync(0);
            expect(fetchMock).toHaveBeenCalledTimes(1);
            expect(screen.getByTestId("analysis-session-expired")).toHaveTextContent(
                "セッションの有効期限が切れました",
            );

            // 停止後は interval が発火しても再ポーリングしない
            await vi.advanceTimersByTimeAsync(10_000);
            expect(fetchMock).toHaveBeenCalledTimes(1);
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

    describe("シナリオ説明文の status × document 分岐 (F-1-03)", () => {
        it("draft + document なし: 未アップロード案内を表示し、解析ボタンも表示", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "draft" as const, hasDocument: false },
            });
            expect(
                screen.getByText(/手順書 \(SOP\) をアップロードすると/),
            ).toBeInTheDocument();
            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        });

        it("draft + document あり: 生成可能案内を表示し、解析ボタンも表示", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "draft" as const, hasDocument: true },
            });
            expect(
                screen.getByText(/アップロード済みの手順書から AI がシナリオを生成できます/),
            ).toBeInTheDocument();
            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        });

        it("ready + document あり: シナリオ確認 + 再解析注記を表示し、解析ボタンも表示 (既存不変)", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "ready" as const, hasDocument: true },
            });
            expect(
                screen.getByText(/手順書から生成したシナリオを編集画面で確認できます。再解析すると/),
            ).toBeInTheDocument();
            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        });

        it("ready + document なし: 確定相優先でシナリオ有り文言を表示 (未生成案内は出さない)", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "ready" as const, hasDocument: false },
            });
            expect(screen.getByText(/編集画面で確認できます/)).toBeInTheDocument();
            expect(screen.queryByText(/アップロードすると/)).toBeNull();
            expect(screen.queryByText(/シナリオを生成できます/)).toBeNull();
            // ready は解析可能状態なのでボタンは表示
            expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        });

        it("rendering + document あり: 生成済み文言を表示し、解析ボタンは非表示", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "rendering" as const, hasDocument: true },
            });
            expect(
                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
            ).toBeInTheDocument();
            expect(screen.queryByText(/アップロードすると/)).toBeNull();
            expect(screen.queryByText(/シナリオを生成できます/)).toBeNull();
            expect(screen.queryByTestId("analyze-button")).toBeNull();
        });

        it("rendering + document なし: 確定相優先でシナリオ有り文言を表示し、解析ボタンは非表示 (再発耐性)", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "rendering" as const, hasDocument: false },
            });
            expect(
                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
            ).toBeInTheDocument();
            expect(screen.queryByText(/手順書 \(SOP\) をアップロードすると/)).toBeNull();
            expect(screen.queryByText(/シナリオを生成できます/)).toBeNull();
            expect(screen.queryByTestId("analyze-button")).toBeNull();
        });

        it("published + document あり: 生成済み文言を表示し、解析ボタンは非表示 (F-1-03)", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "published" as const, hasDocument: true },
            });
            expect(
                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
            ).toBeInTheDocument();
            expect(screen.queryByText(/手順書 \(SOP\) をアップロードすると/)).toBeNull();
            expect(
                screen.queryByText(/アップロード済みの手順書から AI がシナリオを生成できます/),
            ).toBeNull();
            expect(screen.queryByTestId("analyze-button")).toBeNull();
        });

        it("published + document なし: 確定相優先でシナリオ有り文言を表示し、解析ボタンは非表示 (再発耐性)", () => {
            render(AnalysisPanel, {
                props: { ...baseProps, manualStatus: "published" as const, hasDocument: false },
            });
            expect(
                screen.getByText(/生成済みのシナリオは編集画面で確認できます/),
            ).toBeInTheDocument();
            expect(screen.queryByText(/手順書 \(SOP\) をアップロードすると/)).toBeNull();
            expect(screen.queryByTestId("analyze-button")).toBeNull();
        });
    });
});
