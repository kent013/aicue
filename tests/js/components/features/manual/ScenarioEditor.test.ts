import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { get } from "svelte/store";
import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
import { clearToasts, toasts } from "@/lib/stores/toast";
import type { ScenarioDocument } from "@/types/manual";

// router.reload (部分リロード) はテスト環境では実行できないためモックする。
// onSuccess をテスト側から呼び、サーバ最新 document の再取り込みを検証する。
const { routerReloadMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", () => ({
    router: {
        reload: routerReloadMock,
        on: vi.fn(() => () => {}),
    },
}));

/** routerReloadMock に渡された reload オプションを取り出す */
function lastReloadOptions(): {
    only: string[];
    onSuccess: (page: { props: Record<string, unknown> }) => void;
    onFinish: () => void;
} {
    const last = routerReloadMock.mock.calls[routerReloadMock.mock.calls.length - 1];
    if (!last?.[0]) throw new Error("router.reload が呼ばれていません");
    return last[0] as {
        only: string[];
        onSuccess: (page: { props: Record<string, unknown> }) => void;
        onFinish: () => void;
    };
}

/*
 * シナリオエディタ (document 一括保存)。
 * - 保存 payload に parent_cut_id / sort_order / type を含めない (サーバ導出)
 * - 409 / 401 / ネットワーク断でも作業コピーを破棄しない
 * - 419 は cookie 再取得後 1 回だけ自動リトライ
 */

function makeDocument(): ScenarioDocument {
    return {
        scenario_version: 3,
        steps: [
            {
                id: 11,
                scene: "手順シーンA",
                shot_type: "hiki",
                shooting_point: null,
                narration: "ナレーションA",
                subtitle_primary: null,
                subtitle_secondary: "字幕A",
                material_type: null,
                static_display_seconds: null,
                points: [
                    {
                        id: 21,
                        scene: "急所シーンA-1",
                        shot_type: "yori",
                        shooting_point: "手元",
                        narration: "急所ナレーション",
                        subtitle_primary: null,
                        subtitle_secondary: "急所字幕",
                        material_type: null,
                        static_display_seconds: null,
                    },
                ],
            },
            {
                id: 12,
                scene: "手順シーンB",
                shot_type: "hiki",
                shooting_point: null,
                narration: "ナレーションB",
                subtitle_primary: null,
                subtitle_secondary: "字幕B",
                material_type: null,
                static_display_seconds: null,
                points: [],
            },
        ],
    };
}

const baseProps = { projectId: 1, manualId: 5 };

/** fetch Response の最小スタブ */
function jsonResponse(status: number, body: unknown): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.resolve(body),
    } as unknown as Response;
}

/** JSON として読めない応答 (破損 body) */
function brokenResponse(status: number): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: () => Promise.reject(new Error("broken")),
    } as unknown as Response;
}

const fetchMock = vi.fn<(input: RequestInfo | URL, init?: RequestInit) => Promise<Response>>();

beforeEach(() => {
    fetchMock.mockReset();
    routerReloadMock.mockReset();
    vi.stubGlobal("fetch", fetchMock);
    clearToasts();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

/** 直近の PUT リクエスト body を取り出す */
function lastPutPayload(): { expected_version: number; steps: Array<Record<string, unknown>> } {
    const calls = fetchMock.mock.calls.filter(([, init]) => init?.method === "PUT");
    const last = calls[calls.length - 1];
    if (!last?.[1]?.body) throw new Error("PUT リクエストがありません");
    return JSON.parse(String(last[1].body)) as {
        expected_version: number;
        steps: Array<Record<string, unknown>>;
    };
}

/** セルに値を入力する */
async function typeInto(testId: string, value: string): Promise<void> {
    await fireEvent.input(screen.getByTestId(testId), { target: { value } });
}

describe("ScenarioEditor", () => {
    it("空シナリオは EmptyState を表示し、最初の手順を追加できる", async () => {
        render(ScenarioEditor, {
            props: { ...baseProps, scenario: { scenario_version: 0, steps: [] } },
        });

        expect(screen.getByTestId("scenario-empty-state")).toBeInTheDocument();

        await fireEvent.click(screen.getByRole("button", { name: "最初の手順を追加" }));

        expect(screen.queryByTestId("scenario-empty-state")).not.toBeInTheDocument();
        expect(screen.getByTestId("step-0-scene")).toHaveValue("");
        // 行追加で dirty (未保存の変更) 表示
        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
    });

    it("既存シナリオを描画し、編集していない間は dirty 表示なし", () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所シーンA-1");
        expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
    });

    it("セル編集で dirty になり、元へ戻すと dirty が消える (正規化比較)", async () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        await typeInto("step-0-scene", "手順シーンAX");
        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();

        await typeInto("step-0-scene", "手順シーンA");
        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
    });

    it("急所を追加できる (行内の急所を追加ボタン)", async () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        await fireEvent.click(screen.getByTestId("step-1-add-point"));

        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("");
    });

    it("手順の削除は確認ダイアログを経由し、配下の急所ごと消える", async () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        await fireEvent.click(screen.getByTestId("step-0-remove"));
        // ダイアログにテイクも消える旨の説明がある
        await waitFor(() => {
            expect(screen.getByText(/登録済みのテイク/)).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        // 手順A が消え、手順B が繰り上がる
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
        expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
    });

    it("急所の削除はダイアログなしで行える", async () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        await fireEvent.click(screen.getByTestId("point-0-0-remove"));

        expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();
    });

    it("▲▼ で同一スコープ内の並べ替えができる", async () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

        await fireEvent.click(screen.getByTestId("step-1-move-up"));

        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
        expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンA");
    });

    it("保存成功: payload にサーバ導出キーを含めず、応答の version を取り込む", async () => {
        const saved: ScenarioDocument = { ...makeDocument(), scenario_version: 4 };
        fetchMock.mockResolvedValueOnce(jsonResponse(200, saved));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(1);
        });

        const [url, init] = fetchMock.mock.calls[0];
        expect(String(url)).toBe("/projects/1/manuals/5/scenario");
        expect(init?.method).toBe("PUT");
        const payload = lastPutPayload();
        expect(payload.expected_version).toBe(3);
        expect(payload.steps[0]).not.toHaveProperty("sort_order");
        expect(payload.steps[0]).not.toHaveProperty("type");
        expect(payload.steps[0]).not.toHaveProperty("parent_cut_id");
        expect(payload.steps[0].points).toBeInstanceOf(Array);

        // 応答取り込みで dirty が消え、成功トーストが出る
        await waitFor(() => {
            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
        });
        expect(get(toasts).some((toast) => toast.type === "success")).toBe(true);

        // 次回保存は新 version を使う
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...saved, scenario_version: 5 }));
        await typeInto("step-0-scene", "手順シーンAY");
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(lastPutPayload().expected_version).toBe(4);
        });
    });

    it("保存中の再押下は no-op (fetch は 1 回のみ)", async () => {
        let resolveFetch: ((res: Response) => void) | undefined;
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    resolveFetch = resolve;
                }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        expect(fetchMock).toHaveBeenCalledTimes(1);
        resolveFetch?.(jsonResponse(200, makeDocument()));
        await waitFor(() => {
            expect(get(toasts).length).toBeGreaterThan(0);
        });
    });

    it("409 は conflict バナーを表示し作業コピーを保持する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-conflict-banner")).toBeInTheDocument();
        });
        // 作業コピー保持 (編集内容が消えていない)
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
        // version_mismatch のみ「サーバの最新を取得」導線がある
        expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
    });

    it("409 後の「サーバの最新を取得」で作業コピーがサーバ最新 document に置換される", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
        });

        // 明示同意 (ConfirmDialog) を経て部分リロードが発火する
        await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
        await waitFor(() => {
            expect(
                screen.getByRole("button", { name: "破棄して最新を取得" }),
            ).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));

        expect(routerReloadMock).toHaveBeenCalledTimes(1);
        const options = lastReloadOptions();
        expect(options.only).toEqual(["scenario", "manual"]);

        // サーバ最新 (version 9・別内容) を返す部分リロード成功をシミュレート
        const latest: ScenarioDocument = {
            scenario_version: 9,
            steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
        };
        options.onSuccess({ props: { scenario: latest } });
        options.onFinish();

        // 作業コピーが最新で再 seed される (編集内容破棄・バナー消滅・dirty なし)
        await waitFor(() => {
            expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
        });
        expect(screen.queryByTestId("scenario-conflict-banner")).not.toBeInTheDocument();
        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();

        // 以後の保存は最新 version を expected_version に使う (無限 409 ループに陥らない)
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...latest, scenario_version: 10 }));
        await typeInto("step-0-scene", "再編集シーン");
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(lastPutPayload().expected_version).toBe(9);
        });
    });

    it("最新取得の応答 shape が不正なら汎用エラーを表示し作業コピーを保持する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
        await waitFor(() => {
            expect(
                screen.getByRole("button", { name: "破棄して最新を取得" }),
            ).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));

        const options = lastReloadOptions();
        options.onSuccess({ props: { scenario: { unexpected: true } } });
        options.onFinish();

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "最新シナリオの取得に失敗しました",
            );
        });
        // 再 seed されず作業コピーは保持されたまま
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
    });

    it("409 (rendering) はリロード導線なしのバナーを表示する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "rendering",
                message: "書き出し中です。",
                current_version: 3,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-conflict-banner")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("scenario-conflict-reload")).not.toBeInTheDocument();
    });

    it("419 は cookie 再取得後 1 回だけ自動リトライする", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
            .mockResolvedValueOnce(jsonResponse(200, "")) // 回復 GET
            .mockResolvedValueOnce(jsonResponse(200, makeDocument())); // PUT #2 (リトライ)

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledTimes(3);
        });
        const putCalls = fetchMock.mock.calls.filter(([, init]) => init?.method === "PUT");
        expect(putCalls).toHaveLength(2);
    });

    it("419 が続く場合は 2 回目でセッション失効メッセージ (多重リトライしない)", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
            .mockResolvedValueOnce(jsonResponse(200, "")) // 回復 GET
            .mockResolvedValueOnce(jsonResponse(419, {})); // PUT #2 も 419

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "セッションが切れました",
            );
        });
        expect(fetchMock).toHaveBeenCalledTimes(3);
    });

    it("401 はセッション失効メッセージを表示し作業コピーを保持する", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(401, {}));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "セッションが切れました",
            );
        });
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
    });

    it("422 は行別セルにエラーを表示する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(422, {
                message: "invalid",
                errors: {
                    "steps.0.scene": ["シーンは必須です。"],
                    "steps.0.points.0.subtitle_primary": ["字幕①は100文字までです。"],
                },
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByText("シーンは必須です。")).toBeInTheDocument();
        });
        expect(screen.getByText("字幕①は100文字までです。")).toBeInTheDocument();
    });

    it("422 の body が期待外 shape なら汎用エラーへフォールバックする", async () => {
        fetchMock.mockResolvedValueOnce(brokenResponse(422));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "保存に失敗しました",
            );
        });
    });

    it("成功応答の shape が不正なら汎用エラーへフォールバックする", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { unexpected: true }));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "保存結果の取得に失敗しました",
            );
        });
    });

    it("PUT の reject (ネットワーク断) は作業コピーを保持し汎用エラーを表示する", async () => {
        fetchMock.mockRejectedValueOnce(new TypeError("network error"));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "通信に失敗しました",
            );
        });
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
    });

    it("419 回復 GET の reject も汎用エラーで止まる (多重 retry なし)", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(419, {})) // PUT #1
            .mockRejectedValueOnce(new TypeError("network error")); // 回復 GET reject

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "通信に失敗しました",
            );
        });
        expect(fetchMock).toHaveBeenCalledTimes(2);
    });

    it("失敗後の再保存成功で旧エラーが消える", async () => {
        fetchMock
            .mockRejectedValueOnce(new TypeError("network error"))
            .mockResolvedValueOnce(jsonResponse(200, makeDocument()));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toBeInTheDocument();
        });

        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.queryByTestId("scenario-generic-error")).not.toBeInTheDocument();
        });
    });

    it("保存ボタンは disabled にしない", () => {
        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
    });
});
