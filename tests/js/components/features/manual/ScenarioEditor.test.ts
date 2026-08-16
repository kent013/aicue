import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { get } from "svelte/store";
import ScenarioEditor from "@/components/features/manual/ScenarioEditor.svelte";
import { clearToasts, toasts } from "@/lib/stores/toast";
import type { ScenarioDocument } from "@/types/manual";

// parseHistorySnapshot の fail-safe テスト用 partial mock。
// 既定は real 実装へ委譲し (holder.real)、fail-safe テストのみ mockReturnValueOnce(null) で破損扱いにする。
const holder = vi.hoisted(() => ({
    mock: vi.fn(),
    real: undefined as
        | undefined
        | typeof import("@/lib/manual/scenario-history").parseHistorySnapshot,
}));
vi.mock("@/lib/manual/scenario-history", async (importOriginal) => {
    const actual = await importOriginal<typeof import("@/lib/manual/scenario-history")>();
    holder.real = actual.parseHistorySnapshot; // real を保持
    holder.mock.mockImplementation(actual.parseHistorySnapshot); // 既定 = real 委譲
    return { ...actual, parseHistorySnapshot: holder.mock }; // 他 export は実物
});

// router.reload (部分リロード) はテスト環境では実行できないためモックする。
// onSuccess をテスト側から呼び、サーバ最新 document の再取り込みを検証する。
const { routerReloadMock, routerOnMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
    routerOnMock: vi.fn((..._args: unknown[]) => () => {}),
}));

// 動画列が Inertia Link (Button href + inertia) を描画するため、
// router 以外の実 export (Link 等) は本物を残す
vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: {
        reload: routerReloadMock,
        on: routerOnMock,
    },
}));

interface ReloadOptions {
    only: string[];
    onSuccess: (page: { props: Record<string, unknown> }) => void;
    onError: () => void;
    onFinish: () => void;
}

/** routerReloadMock に渡された reload オプションを取り出す */
function lastReloadOptions(): ReloadOptions {
    const last = routerReloadMock.mock.calls[routerReloadMock.mock.calls.length - 1];
    if (!last?.[0]) throw new Error("router.reload が呼ばれていません");
    return last[0] as ReloadOptions;
}

/** router.on("before", ...) で登録された dirty 離脱ガードを取り出す */
function beforeGuard(): (event: { preventDefault: () => void }) => void {
    const call = routerOnMock.mock.calls.find(([name]) => name === "before");
    if (!call?.[1]) throw new Error('router.on("before") が登録されていません');
    return call[1] as (event: { preventDefault: () => void }) => void;
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

// 動画列 (takeSummaries) は既定で空 = 保存済み行でも「テイク 0 件」表示になる
const baseProps = { projectId: 1, manualId: 5, takeSummaries: [] };

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
    routerOnMock.mockClear();
    vi.stubGlobal("fetch", fetchMock);
    clearToasts();
    // jsdom は scrollIntoView 未実装。失敗フィードバックの知覚処理 (showFailure) が
    // 全失敗経路で呼ぶため、毎テスト新しい spy を注入する (呼び出し順/引数検証にも使う)
    Element.prototype.scrollIntoView = vi.fn();
    // parseHistorySnapshot mock を毎テスト既定 (real 委譲) へ復帰させ、fail-safe テストの
    // mockReturnValueOnce が他テストへ波及しないようにする
    if (holder.real) holder.mock.mockImplementation(holder.real);
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

// --- 並べ替え / 遅延構造操作の共有ヘルパ (T185 の D&D と T188 の安定キー解決で共有する) ---

let rectSpy: ReturnType<typeof vi.spyOn> | null = null;

/** 行の実測を data-reorder-index から固定値へ差し替える (top = index * 100, height = 100) */
function stubRowRects(): void {
    rectSpy = vi.spyOn(HTMLElement.prototype, "getBoundingClientRect").mockImplementation(
        function (this: HTMLElement): DOMRect {
            const raw = this.dataset.reorderIndex;
            const index = raw === undefined ? -1 : Number(raw);
            const top = index < 0 ? 0 : index * 100;
            const height = index < 0 ? 0 : 100;
            // 素の型アサーションを使わずに実体を作る (new DOMRect が top/bottom を導出する)
            return new DOMRect(0, top, 0, height);
        },
    );
}

/** stubRowRects() で差し替えた実測を元へ戻す */
function restoreRowRects(): void {
    rectSpy?.mockRestore();
    rectSpy = null;
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

async function grab(testId: string, clientY: number, pointerId = 1): Promise<void> {
    await fireEvent(screen.getByTestId(testId), pointerEvent("pointerdown", clientY, pointerId));
}

async function dragTo(clientY: number, pointerId = 1): Promise<void> {
    await fireEvent(window, pointerEvent("pointermove", clientY, pointerId));
}

async function drop(clientY: number, pointerId = 1): Promise<void> {
    await fireEvent(window, pointerEvent("pointerup", clientY, pointerId));
}

/** 掴む → 動かす → 落とす */
async function dragHandle(testId: string, startY: number, endY: number): Promise<void> {
    await grab(testId, startY);
    await dragTo(endY);
    await drop(endY);
}

/** 2 手順 × 2 急所 (急所の同一スコープ性を検証できる形) */
function makeDndDocument(): ScenarioDocument {
    const row = (id: number, scene: string) => ({
        id,
        scene,
        shot_type: "yori" as const,
        shooting_point: null,
        narration: "",
        subtitle_primary: null,
        subtitle_secondary: "",
        material_type: null,
        static_display_seconds: null,
    });
    return {
        scenario_version: 3,
        steps: [
            {
                ...row(11, "手順シーンA"),
                shot_type: "hiki",
                points: [row(21, "急所A-1"), row(22, "急所A-2")],
            },
            {
                ...row(12, "手順シーンB"),
                shot_type: "hiki",
                points: [row(23, "急所B-1"), row(24, "急所B-2")],
            },
        ],
    };
}

function renderDnd(): void {
    render(ScenarioEditor, { props: { ...baseProps, scenario: makeDndDocument() } });
}

/** 現在の手順の scene 値 (表示順) */
function stepScenes(): string[] {
    return screen
        .getAllByTestId(/^step-\d+-scene$/)
        .filter((el): el is HTMLInputElement => el instanceof HTMLInputElement)
        .map((el) => el.value);
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

    it("最新取得の部分リロード自体が失敗 (onError) したら汎用エラーを表示する", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
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
        options.onError();
        options.onFinish();

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toHaveTextContent(
                "最新シナリオの取得に失敗しました",
            );
        });
    });

    it("明示同意済みリロード中は dirty 離脱確認 (before ガード) をスキップする", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );
        const confirmSpy = vi.spyOn(window, "confirm").mockReturnValue(false);

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

        // リロード実行中 (onFinish 前): dirty でも confirm を出さず遷移も止めない
        const guard = beforeGuard();
        const preventDefault = vi.fn();
        guard({ preventDefault });
        expect(confirmSpy).not.toHaveBeenCalled();
        expect(preventDefault).not.toHaveBeenCalled();

        // リロード完了後: dirty のままなら通常どおり confirm で確認する
        lastReloadOptions().onFinish();
        guard({ preventDefault });
        expect(confirmSpy).toHaveBeenCalledTimes(1);
        expect(preventDefault).toHaveBeenCalledTimes(1);

        confirmSpy.mockRestore();
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

    // --- F-02 知覚可能性 (perceivability) の回帰テスト群 ---

    it("失敗フィードバックは操作点 (シナリオを更新ボタン) の直前に描画される", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "version_mismatch",
                message: "他の編集と競合しました。",
                current_version: 9,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-failure-region")).toBeInTheDocument();
        });
        const region = screen.getByTestId("scenario-failure-region");
        const submit = screen.getByTestId("scenario-submit");
        // region は submit より前方 (DOCUMENT_POSITION_FOLLOWING) かつ同一 section 配下
        const position = region.compareDocumentPosition(submit);
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING).toBeTruthy();
        expect(region.closest("section")).toBe(submit.closest("section"));
    });

    it("失敗表示は focus(preventScroll) → scrollIntoView の順で知覚させる (全 kind 共通)", async () => {
        const focusSpy = vi.spyOn(HTMLElement.prototype, "focus");
        const scrollMock = Element.prototype.scrollIntoView as ReturnType<typeof vi.fn>;

        // 3 分岐 (conflict=409 / forbidden=403 / generic=500) それぞれで順序と引数を検証する
        const cases: Array<{ status: number; body: unknown }> = [
            {
                status: 409,
                body: {
                    code: "scenario_conflict",
                    conflict_type: "version_mismatch",
                    message: "他の編集と競合しました。",
                    current_version: 9,
                },
            },
            { status: 403, body: {} },
            { status: 500, body: {} },
        ];

        for (const { status, body } of cases) {
            focusSpy.mockClear();
            scrollMock.mockClear();
            fetchMock.mockResolvedValueOnce(jsonResponse(status, body));

            const { unmount } = render(ScenarioEditor, {
                props: { ...baseProps, scenario: makeDocument() },
            });
            await fireEvent.click(screen.getByTestId("scenario-submit"));

            await waitFor(() => {
                expect(scrollMock).toHaveBeenCalledTimes(1);
            });
            expect(focusSpy).toHaveBeenCalledWith({ preventScroll: true });
            expect(scrollMock).toHaveBeenCalledWith({
                block: "nearest",
                inline: "nearest",
                behavior: "auto",
            });
            // focus が scrollIntoView より先に呼ばれる
            const focusOrder = Math.min(...focusSpy.mock.invocationCallOrder);
            expect(focusOrder).toBeLessThan(scrollMock.mock.invocationCallOrder[0]);
            unmount();
        }

        focusSpy.mockRestore();
    });

    it("409 (analyzing) はサーバ供給 message を表示し再取得 CTA を出さない", async () => {
        fetchMock.mockResolvedValueOnce(
            jsonResponse(409, {
                code: "scenario_conflict",
                conflict_type: "analyzing",
                message: "AI 解析中のため保存できません。完了後に再度お試しください。",
                current_version: 3,
            }),
        );

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-conflict-banner")).toHaveTextContent(
                "AI 解析中のため保存できません。完了後に再度お試しください。",
            );
        });
        // version_mismatch 以外はリロード導線を出さない (空 action 余白も出さない)
        expect(screen.queryByTestId("scenario-conflict-reload")).not.toBeInTheDocument();
    });

    it("403 は権限エラーの固定文言を表示し作業コピーを破棄しない", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(403, { message: "This action is unauthorized." }));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-forbidden-error")).toHaveTextContent(
                "この操作を行う権限がありません。ページを再読み込みして状態を確認してください。",
            );
        });
        // サーバ 403 body の英語文言は表示しない (内部状態を漏らさない)
        expect(
            screen.queryByText("This action is unauthorized."),
        ).not.toBeInTheDocument();
        // dirty (作業コピー) は保持
        expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
    });

    // --- T040 F-1-1: 保存成功のその場残留インジケータ (justSaved) ---

    it("保存成功後は「保存しました」インジケータを表示し dirty 表示は出さない", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await typeInto("step-0-scene", "手順シーンAX");
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
        });
        expect(screen.getByTestId("scenario-saved-indicator")).toHaveTextContent("保存しました");
        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
    });

    it("保存直後は dirty=false でも justSaved=true を維持する (意図せぬ消去が混入しない不変)", async () => {
        // dirty 算出変更に対する回帰の砦: applySaved 後もインジケータが残ることを固定する
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
    });

    it("保存成功後に編集で dirty に転じると保存インジケータが消え dirty 表示に切り替わる", async () => {
        fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
        });

        await typeInto("step-0-scene", "手順シーンAX");
        await waitFor(() => {
            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
    });

    it("409 競合後のサーバ最新取得 (reseed) では偽の保存インジケータを出さない", async () => {
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
            expect(screen.getByRole("button", { name: "破棄して最新を取得" })).toBeInTheDocument();
        });
        await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));

        const latest: ScenarioDocument = {
            scenario_version: 9,
            steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
        };
        lastReloadOptions().onSuccess({ props: { scenario: latest } });
        lastReloadOptions().onFinish();

        await waitFor(() => {
            expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
        });
        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
    });

    it("保存失敗 (generic) では保存インジケータを出さない", async () => {
        fetchMock.mockRejectedValueOnce(new TypeError("network error"));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("scenario-generic-error")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("scenario-saved-indicator")).not.toBeInTheDocument();
    });

    it("保存成功で失敗リージョンが消える", async () => {
        fetchMock
            .mockResolvedValueOnce(jsonResponse(403, {}))
            .mockResolvedValueOnce(jsonResponse(200, makeDocument()));

        render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.getByTestId("scenario-failure-region")).toBeInTheDocument();
        });
        // 保存完了で submit が再度有効になる (loading 中は disabled=多重送信ガード) のを待つ
        await waitFor(() => {
            expect(screen.getByTestId("scenario-submit")).not.toBeDisabled();
        });

        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => {
            expect(screen.queryByTestId("scenario-failure-region")).not.toBeInTheDocument();
        });
    });

    // --- T048: Undo/Redo (一つ戻る / 進む) ---

    describe("Undo/Redo", () => {
        /** フィールド編集セッションを模す: focusIn → input → focusOut (1 履歴エントリ) */
        async function editCell(testId: string, value: string): Promise<void> {
            const el = screen.getByTestId(testId);
            await fireEvent.focusIn(el);
            await fireEvent.input(el, { target: { value } });
            await fireEvent.focusOut(el);
        }

        /** keydown を window に dispatch し、defaultPrevented 判定用に event を返す */
        function dispatchKey(init: KeyboardEventInit): KeyboardEvent {
            const ev = new KeyboardEvent("keydown", { bubbles: true, cancelable: true, ...init });
            window.dispatchEvent(ev);
            return ev;
        }

        const undoBtn = (): HTMLElement => screen.getByTestId("scenario-undo");
        const redoBtn = (): HTMLElement => screen.getByTestId("scenario-redo");

        it("初期表示は dirty なし・Undo/Redo とも disabled (clientKey 二重採番の回帰検出)", () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
            expect(undoBtn()).toBeDisabled();
            expect(redoBtn()).toBeDisabled();
        });

        it("PUT payload に clientKey を含めない (保護キー混入防止)", async () => {
            fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));

            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");
            await fireEvent.click(screen.getByTestId("scenario-submit"));

            await waitFor(() => {
                expect(fetchMock).toHaveBeenCalledTimes(1);
            });
            const payload = lastPutPayload();
            expect(payload.steps[0]).not.toHaveProperty("clientKey");
            expect(
                (payload.steps[0].points as Array<Record<string, unknown>>)[0],
            ).not.toHaveProperty("clientKey");
        });

        it("セル編集 → Undo で前状態 → Redo で再適用", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await editCell("step-0-scene", "手順シーンAX");
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");

            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");

            await fireEvent.click(redoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
        });

        it("行追加 → Undo で消える → Redo で戻る", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await fireEvent.click(screen.getByTestId("scenario-add-step"));
            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();

            await fireEvent.click(undoBtn());
            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();

            await fireEvent.click(redoBtn());
            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();
        });

        it("手順削除 (確認ダイアログ) → Undo で配下急所ごと復活", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await fireEvent.click(screen.getByTestId("step-0-remove"));
            await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");
            expect(screen.queryByTestId("point-0-0-scene")).not.toBeInTheDocument();

            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所シーンA-1");
        });

        it("並べ替え → Undo で順序が戻る", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await fireEvent.click(screen.getByTestId("step-0-move-down"));
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンB");

            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
        });

        it("複数操作 (追加→編集→並べ替え) を 3 回 Undo で初期状態へ戻す", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await fireEvent.click(screen.getByTestId("scenario-add-step")); // 操作1
            await editCell("step-0-scene", "手順シーンAX"); // 操作2
            await fireEvent.click(screen.getByTestId("step-0-move-down")); // 操作3

            await fireEvent.click(undoBtn());
            await fireEvent.click(undoBtn());
            await fireEvent.click(undoBtn());

            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(screen.getByTestId("step-1-scene")).toHaveValue("手順シーンB");
            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
        });

        it("Undo 後に別セルを編集すると Redo がクリアされる", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await editCell("step-0-scene", "手順シーンAX");
            await fireEvent.click(undoBtn());
            expect(redoBtn()).not.toBeDisabled();

            await editCell("step-1-scene", "手順シーンBX");
            expect(redoBtn()).toBeDisabled();
        });

        it("保存成功後は履歴がリセットされ Undo が disabled になる", async () => {
            fetchMock.mockResolvedValueOnce(jsonResponse(200, { ...makeDocument(), scenario_version: 4 }));

            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");
            expect(undoBtn()).not.toBeDisabled();

            await fireEvent.click(screen.getByTestId("scenario-submit"));
            await waitFor(() => {
                expect(screen.getByTestId("scenario-saved-indicator")).toBeInTheDocument();
            });
            expect(undoBtn()).toBeDisabled();
            expect(redoBtn()).toBeDisabled();
        });

        it("409 → 明示リロード後は履歴がリセットされる", async () => {
            fetchMock.mockResolvedValueOnce(
                jsonResponse(409, {
                    code: "scenario_conflict",
                    conflict_type: "version_mismatch",
                    message: "他の編集と競合しました。",
                    current_version: 9,
                }),
            );

            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");
            expect(undoBtn()).not.toBeDisabled();

            await fireEvent.click(screen.getByTestId("scenario-submit"));
            await waitFor(() => {
                expect(screen.getByTestId("scenario-conflict-reload")).toBeInTheDocument();
            });
            await fireEvent.click(screen.getByTestId("scenario-conflict-reload"));
            await waitFor(() => {
                expect(screen.getByRole("button", { name: "破棄して最新を取得" })).toBeInTheDocument();
            });
            await fireEvent.click(screen.getByRole("button", { name: "破棄して最新を取得" }));

            const latest: ScenarioDocument = {
                scenario_version: 9,
                steps: [{ ...makeDocument().steps[0], scene: "サーバ最新シーン", points: [] }],
            };
            lastReloadOptions().onSuccess({ props: { scenario: latest } });
            lastReloadOptions().onFinish();

            await waitFor(() => {
                expect(screen.getByTestId("step-0-scene")).toHaveValue("サーバ最新シーン");
            });
            expect(undoBtn()).toBeDisabled();
            expect(redoBtn()).toBeDisabled();
        });

        it("ショートカット: 非編集要素に focus 時 Ctrl+Z / Cmd+Z で Undo", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");
            // editCell の focusOut 後、activeElement は body (非編集要素)
            expect(document.activeElement?.tagName).not.toBe("INPUT");

            const ctrl = dispatchKey({ ctrlKey: true, key: "z" });
            expect(ctrl.defaultPrevented).toBe(true);
            await waitFor(() => {
                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            });

            // Cmd+Z (mac) でも Undo が走る (別編集を積んで再検証)
            await editCell("step-0-scene", "手順シーンAY");
            dispatchKey({ metaKey: true, key: "z" });
            await waitFor(() => {
                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            });
        });

        it("ショートカット: 編集フィールド focus 中は native に委譲し app undo を走らせない", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");

            const input = screen.getByTestId("step-0-scene") as HTMLInputElement;
            input.focus();
            expect(document.activeElement).toBe(input);

            const ev = dispatchKey({ ctrlKey: true, key: "z" });
            // 編集フィールド内なので preventDefault されず (native 委譲)、app undo も走らない
            expect(ev.defaultPrevented).toBe(false);
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
        });

        it("ショートカット: IME 変換中 (isComposing) は無視する", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");

            const ev = dispatchKey({ ctrlKey: true, key: "z", isComposing: true });
            expect(ev.defaultPrevented).toBe(false);
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
        });

        it("ショートカット: Ctrl+Shift+Z で Redo", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");
            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");

            dispatchKey({ ctrlKey: true, shiftKey: true, key: "z" });
            await waitFor(() => {
                expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
            });
        });

        it("blur → 構造操作(click) で二重 push しない (1 編集 + 1 構造 = Undo 2 回で初期)", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await editCell("step-0-scene", "手順シーンAX"); // 1 エントリ
            await fireEvent.click(screen.getByTestId("scenario-add-step")); // 1 エントリ

            await fireEvent.click(undoBtn()); // 追加取消
            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
            await fireEvent.click(undoBtn()); // 編集取消
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(undoBtn()).toBeDisabled(); // これ以上戻れない (2 エントリのみ)
        });

        it("IME 順序1: focusout(composing) → compositionend で 1 エントリに確定", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            const section = screen.getByLabelText("シナリオ編集");
            const el = screen.getByTestId("step-0-scene");

            await fireEvent.focusIn(el);
            await fireEvent.compositionStart(section);
            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
            await fireEvent.focusOut(el); // composing 中: 保留 (中間文字列を積まない)
            await fireEvent.compositionEnd(section); // 確定で 1 エントリ

            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(undoBtn()).toBeDisabled(); // 1 エントリのみ
        });

        it("IME 順序2: focusout → 構造click → compositionend で テキスト1 + 構造1", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            const section = screen.getByLabelText("シナリオ編集");
            const el = screen.getByTestId("step-0-scene");

            await fireEvent.focusIn(el);
            await fireEvent.compositionStart(section);
            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
            await fireEvent.focusOut(el);
            await fireEvent.click(screen.getByTestId("scenario-add-step")); // composing 中: FIFO 保留
            await fireEvent.compositionEnd(section); // テキスト確定 → 構造実行 の順

            // 構造操作 (追加) が反映され、Undo で取消
            expect(screen.getByTestId("step-2-scene")).toBeInTheDocument();
            await fireEvent.click(undoBtn());
            expect(screen.queryByTestId("step-2-scene")).not.toBeInTheDocument();
            // テキスト編集も 1 エントリ残る
            await fireEvent.click(undoBtn());
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンA");
            expect(undoBtn()).toBeDisabled();
        });

        it("復元 fail-safe: 履歴破損時は steps 非破壊・履歴リセット・warning トースト", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            await editCell("step-0-scene", "手順シーンAX");

            // 次の parseHistorySnapshot 呼び出し (undo の restoreFrom) のみ破損扱いにする
            holder.mock.mockReturnValueOnce(null);
            await fireEvent.click(undoBtn());

            // steps は変えない (編集値のまま) + 履歴リセットで Undo/Redo disabled + warning トースト
            expect(screen.getByTestId("step-0-scene")).toHaveValue("手順シーンAX");
            expect(undoBtn()).toBeDisabled();
            expect(redoBtn()).toBeDisabled();
            expect(get(toasts).some((toast) => toast.type === "warning")).toBe(true);
        });

        it("canUndo は pending 編集を含む (focusout 前でも Undo が活性)", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });
            const el = screen.getByTestId("step-0-scene");

            await fireEvent.focusIn(el);
            await fireEvent.input(el, { target: { value: "手順シーンAX" } });
            // focusOut を発火せず (pending 編集) でも Undo は活性
            expect(undoBtn()).not.toBeDisabled();
        });

        it("reactivity: 構造操作直後に Undo 活性・dirty 表示が即時反映される", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await fireEvent.click(screen.getByTestId("scenario-add-step"));
            expect(undoBtn()).not.toBeDisabled();
            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
        });

        it("Undo で snapshot まで戻すと dirty 表示 (離脱警告) が解除される", async () => {
            render(ScenarioEditor, { props: { ...baseProps, scenario: makeDocument() } });

            await editCell("step-0-scene", "手順シーンAX");
            expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();

            await fireEvent.click(undoBtn());
            expect(screen.queryByTestId("scenario-dirty-indicator")).not.toBeInTheDocument();
        });
    });
});

/*
 * ドラッグ&ドロップ並べ替え (T185)。層 3 = 配線:
 * 落としたら既存の保存経路 (payloadSteps の配列順) / 履歴 / dirty 判定が期待どおり動くか。
 * 意味論 (どこに落ちたら何番目か) は tests/js/lib/dnd/list-reorder.test.ts が持つ。
 */
describe("ドラッグ&ドロップ並べ替え (T185)", () => {
    beforeEach(() => {
        stubRowRects();
    });

    afterEach(() => {
        restoreRowRects();
    });

    it("手順のハンドルをドラッグすると順序が入れ替わり、保存 payload の並びも変わる", async () => {
        const saved: ScenarioDocument = { ...makeDndDocument(), scenario_version: 4 };
        fetchMock.mockResolvedValueOnce(jsonResponse(200, saved));
        renderDnd();

        // 手順 1 を掴んで手順 2 の中点 (150) より下へ落とす → 挿入 index 2 → 最終 index 1
        await dragHandle("step-0-drag-handle", 50, 160);

        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);

        await fireEvent.click(screen.getByTestId("scenario-submit"));
        await waitFor(() => expect(fetchMock).toHaveBeenCalled());
        expect(lastPutPayload().steps.map((step) => step.id)).toEqual([12, 11]);
    });

    it("D&D の直後は未保存の変更として表示される", async () => {
        renderDnd();

        await dragHandle("step-0-drag-handle", 50, 160);

        expect(screen.getByTestId("scenario-dirty-indicator")).toBeInTheDocument();
    });

    it("D&D の直後に『元に戻す』で元の順序へ戻る", async () => {
        renderDnd();

        await dragHandle("step-0-drag-handle", 50, 160);
        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);

        await fireEvent.click(screen.getByTestId("scenario-undo"));

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
    });

    it("成功した並べ替えは aria-live で告知する", async () => {
        renderDnd();

        await dragHandle("step-0-drag-handle", 50, 160);

        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
            "手順 1 を 2 番目に移動しました",
        );
    });

    it("急所の D&D は同じ手順の中だけで完結する", async () => {
        renderDnd();

        await dragHandle("point-0-0-drag-handle", 50, 160);

        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
        // 別手順の急所は無変更 (closest による絞り込みが効いている)
        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
    });

    it("ドラッグ中に Escape を押すと順序が変わらない", async () => {
        renderDnd();

        await grab("step-0-drag-handle", 50);
        await dragTo(160);
        await fireEvent.keyDown(window, { key: "Escape" });
        await drop(160);

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");
    });

    it("2 本目の指は 1 本目のドラッグ対象をすり替えない (同一 controller)", async () => {
        renderDnd();

        // 手順 A の急所を pointerId=1 で掴んで動かす
        await grab("point-0-0-drag-handle", 50, 1);
        await dragTo(160, 1);
        // その最中に手順 B の急所ハンドルを別の指 (pointerId=2) で押す
        await grab("point-1-0-drag-handle", 50, 2);
        // 1 本目を drop する
        await drop(160, 1);

        // 手順 A の急所だけが動き、手順 B は無変更
        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所B-2");
    });

    it("手順ドラッグ中は急所ドラッグが始まらない (controller またぎの排他)", async () => {
        renderDnd();

        await grab("step-0-drag-handle", 50, 1);
        await dragTo(160, 1);
        // 急所ハンドルを別の指で押し、急所の drop 相当まで出しても始まらない
        await grab("point-1-0-drag-handle", 50, 2);
        await dragTo(160, 2);
        await drop(160, 2);

        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所B-1");
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]); // 1 本目はまだ drop していない

        await drop(160, 1);

        // 1 本目は掴んだとおりの行が期待どおりの位置へ動く
        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
    });

    it("急所ドラッグ中は手順ドラッグが始まらない (逆向き)", async () => {
        renderDnd();

        await grab("point-0-0-drag-handle", 50, 1);
        await dragTo(160, 1);
        await grab("step-0-drag-handle", 50, 2);
        await dragTo(160, 2);
        await drop(160, 2);

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);

        await drop(160, 1);

        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
    });

    it("IME 変換中に確定した D&D は compositionend まで順序も告知も変わらない", async () => {
        renderDnd();

        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
        await dragHandle("step-0-drag-handle", 50, 160);

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent("");

        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));

        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
            "手順 1 を 2 番目に移動しました",
        );
    });

    it("IME 変換中に手順の並べ替えと急所の並べ替えを続けて確定しても、掴んだ手順の急所が動く", async () => {
        renderDnd();

        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
        // (1) 手順 1 (手順シーンA) を 2 番目へ
        await dragHandle("step-0-drag-handle", 50, 160);
        // (2) その手順シーンA の急所 1 を 2 番目へ (この時点では手順シーンA はまだ index 0)
        await dragHandle("point-0-0-drag-handle", 50, 160);

        // どちらも compositionend まで保留される
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);

        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));

        // (1) が先に効いて並びが変わっても、(2) は**掴んだ手順シーンA の急所**に適用される。
        // 数値 index を持ち回っていると手順シーンB の急所が入れ替わってしまう。
        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所B-1");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所B-2");
        expect(screen.getByTestId("point-1-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-1-1-scene")).toHaveValue("急所A-1");
    });

    it("ハンドル上の ArrowDown / ArrowUp で 1 段移動する", async () => {
        renderDnd();

        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowDown" });
        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);

        await fireEvent.keyDown(screen.getByTestId("step-1-drag-handle"), { key: "ArrowUp" });
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
    });

    it("急所ハンドル上の ArrowDown でも 1 段移動する", async () => {
        renderDnd();

        await fireEvent.keyDown(screen.getByTestId("point-0-0-drag-handle"), { key: "ArrowDown" });

        expect(screen.getByTestId("point-0-0-scene")).toHaveValue("急所A-2");
        expect(screen.getByTestId("point-0-1-scene")).toHaveValue("急所A-1");
    });

    it("先頭行の ArrowUp は順序を変えず、理由を告知する (disabled にしない)", async () => {
        renderDnd();

        await fireEvent.keyDown(screen.getByTestId("step-0-drag-handle"), { key: "ArrowUp" });

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
            "これ以上、上へは移動できません",
        );
    });

    it("末尾行の ▼ ボタンも順序を変えず理由を告知する", async () => {
        renderDnd();

        await fireEvent.click(screen.getByTestId("step-1-move-down"));

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
        expect(screen.getByTestId("scenario-reorder-status")).toHaveTextContent(
            "これ以上、下へは移動できません",
        );
    });

    it("ハンドルは disabled 属性を持たない (禁止事項 8)", () => {
        renderDnd();

        for (const id of [
            "step-0-drag-handle",
            "step-1-drag-handle",
            "point-0-0-drag-handle",
            "point-1-1-drag-handle",
        ]) {
            expect(screen.getByTestId(id)).not.toHaveAttribute("disabled");
        }
    });
});

/*
 * IME 変換中に積まれた構造操作 (削除・追加) が、実行時点の並びに影響されず
 * **押したときの対象**へ当たることを固定する (T188)。
 * 数値 index を closure が捕捉していると、先行する遅延操作で添字がずれ、
 * 別の行を消す / 別の手順へ急所を足す / 範囲外アクセスで drain が中断する。
 */
describe("IME 変換中の構造操作は安定キーで解決する (T188)", () => {
    beforeEach(() => {
        stubRowRects();
    });

    afterEach(() => {
        restoreRowRects();
    });

    const undoBtn = (): HTMLElement => screen.getByTestId("scenario-undo");

    /** IME 変換を開始する (以降の構造操作は compositionend まで保留される) */
    async function startComposition(): Promise<void> {
        await fireEvent.compositionStart(screen.getByTestId("step-0-scene"));
    }

    /** 変換を確定して保留中の構造操作を発行順に実行させる */
    async function endComposition(): Promise<void> {
        await fireEvent.compositionEnd(screen.getByTestId("step-0-scene"));
    }

    /** 手順の削除 (確認ダイアログの「削除する」まで) */
    async function confirmRemoveStep(stepIndex: number): Promise<void> {
        await fireEvent.click(screen.getByTestId(`step-${stepIndex}-remove`));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));
    }

    /** 指定手順の急所の scene 値 (表示順) */
    function pointScenes(stepIndex: number): string[] {
        return screen
            .queryAllByTestId(new RegExp(`^point-${stepIndex}-\\d+-scene$`))
            .filter((el): el is HTMLInputElement => el instanceof HTMLInputElement)
            .map((el) => el.value);
    }

    it("変換中に「並べ替え → 手順削除」を続けて確定すると、確定した手順が消える", async () => {
        renderDnd();
        await startComposition();

        await dragHandle("step-0-drag-handle", 50, 160); // 手順A を 2 番目へ (保留)
        await confirmRemoveStep(1); // 手順B の削除を確定 (保留)

        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]); // まだ何も起きない

        await endComposition();

        // 並べ替えが先に効いても、消えるのは押した手順B である
        // (数値 index を持ち回っていると index 1 = 手順A が消える)
        expect(stepScenes()).toEqual(["手順シーンA"]);
    });

    it("変換中に「並べ替え → 急所追加」を続けて確定すると、押した手順に急所が付く", async () => {
        renderDnd();
        await startComposition();

        await dragHandle("step-0-drag-handle", 50, 160); // 手順A を 2 番目へ (保留)
        await fireEvent.click(screen.getByTestId("step-1-add-point")); // 手順B に急所追加 (保留)

        await endComposition();

        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
        expect(pointScenes(0)).toEqual(["急所B-1", "急所B-2", ""]); // 押した手順B に付く
        expect(pointScenes(1)).toEqual(["急所A-1", "急所A-2"]); // 手順A は無変更
    });

    it("変換中に「並べ替え → 急所削除」を続けて確定すると、掴んだ手順の急所が消える", async () => {
        renderDnd();
        await startComposition();

        await dragHandle("step-0-drag-handle", 50, 160); // 手順A を 2 番目へ (保留)
        await fireEvent.click(screen.getByTestId("point-0-0-remove")); // 手順A の急所 1 を削除 (保留)

        await endComposition();

        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);
        expect(pointScenes(0)).toEqual(["急所B-1", "急所B-2"]); // 手順B は無変更
        expect(pointScenes(1)).toEqual(["急所A-2"]); // 掴んだ手順A の急所 1 が消える
    });

    it("遅延中に対象手順が消えていたら、急所追加は何も起こさず後続の遅延操作も走る", async () => {
        renderDnd();
        await startComposition();

        await confirmRemoveStep(0); // 手順A の削除 (保留)
        await fireEvent.click(screen.getByTestId("step-0-add-point")); // 消える手順A へ追加 (保留)
        // 後続が確かに走ることを見るため、手順B への追加を 2 回積む
        // (1 回だけだと、壊れた実装が「消えた手順の分を手順B へ足した」場合と区別できない)
        await fireEvent.click(screen.getByTestId("step-1-add-point"));
        await fireEvent.click(screen.getByTestId("step-1-add-point"));

        await endComposition();

        expect(stepScenes()).toEqual(["手順シーンB"]);
        // 手順A への追加は no-op。手順B への 2 回は両方とも届く (drain が中断していない)
        expect(pointScenes(0)).toEqual(["急所B-1", "急所B-2", "", ""]);
    });

    it("削除ダイアログを開いている間に遅延中の並べ替えが確定しても、開いたときの手順が消える", async () => {
        renderDnd();
        await startComposition();

        await dragHandle("step-0-drag-handle", 50, 160); // 手順A を 2 番目へ (保留)
        await fireEvent.click(screen.getByTestId("step-1-remove")); // 手順B のダイアログを開く

        await endComposition(); // 並べ替えだけ実行され、手順B は index 0 になる

        expect(stepScenes()).toEqual(["手順シーンB", "手順シーンA"]);

        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        // ダイアログを開いたときの手順B が消える (数値 index なら手順A が消える)
        expect(stepScenes()).toEqual(["手順シーンA"]);
    });

    it("対象が既に消えている手順削除は、末尾の手順を巻き添えにしない", async () => {
        renderDnd();
        await startComposition();

        await confirmRemoveStep(0); // 手順A の削除 (保留)
        await confirmRemoveStep(0); // 同じ手順A の削除をもう 1 度 (保留)

        await endComposition();

        // 2 回目は解決できず no-op。splice(-1, 1) で末尾の手順B を巻き添えにしない
        expect(stepScenes()).toEqual(["手順シーンB"]);

        await fireEvent.click(undoBtn());

        // 誤変異が無いので履歴は 1 エントリ = Undo 1 回で初期状態へ戻る
        expect(stepScenes()).toEqual(["手順シーンA", "手順シーンB"]);
        expect(undoBtn()).toBeDisabled();
    });

    it("対象が既に消えている急所削除は、末尾の急所を巻き添えにしない", async () => {
        renderDnd();
        await startComposition();

        await fireEvent.click(screen.getByTestId("point-0-0-remove")); // 急所A-1 の削除 (保留)
        await fireEvent.click(screen.getByTestId("point-0-0-remove")); // 同じ急所をもう 1 度 (保留)

        await endComposition();

        // 2 回目は no-op。splice(-1, 1) で末尾の急所A-2 を巻き添えにしない
        expect(pointScenes(0)).toEqual(["急所A-2"]);

        await fireEvent.click(undoBtn());

        expect(pointScenes(0)).toEqual(["急所A-1", "急所A-2"]);
        expect(undoBtn()).toBeDisabled();
    });

    it("親手順が消えた後の急所削除は no-op で、後続の遅延操作は正しい手順へ届く", async () => {
        renderDnd();
        await startComposition();

        await confirmRemoveStep(0); // 手順A の削除 (保留)
        await fireEvent.click(screen.getByTestId("point-0-0-remove")); // 手順A の急所 1 を削除 (保留)
        await fireEvent.click(screen.getByTestId("step-1-add-point")); // 手順B に急所追加 (保留)

        await endComposition();

        expect(stepScenes()).toEqual(["手順シーンB"]);
        // 親が解決できない急所削除は何もせず、後続の追加は手順B へ届く
        // (親の未検出をガードしないと steps[-1] が undefined で TypeError になり追加が失われる)
        expect(pointScenes(0)).toEqual(["急所B-1", "急所B-2", ""]);
    });
});

/*
 * 動画列のサムネイル表示条件 (T190)。
 * サーバが 404 を返す状態 (非 ready / サムネイル未生成) へは最初から URL を張らない。
 * サムネイルが出ないケースでも導線 (テイクを選択 / ファイルの選択) は常に押せる。
 */
describe("動画列のサムネイル表示条件 (T190)", () => {
    /** 採用テイクの要約 (step id=11 のカット) */
    function summary(
        adopted: { id: number; status: "ready" | "processing"; has_thumbnail: boolean } | null,
    ) {
        return [{ cut_id: 11, takes_count: 2, adopted }];
    }

    function renderWith(takeSummaries: ReturnType<typeof summary>) {
        return render(ScenarioEditor, {
            props: { ...baseProps, scenario: makeDocument(), takeSummaries },
        });
    }

    it("採用テイクが ready かつサムネイル生成済みならサムネイルが出る", () => {
        renderWith(summary({ id: 9, status: "ready", has_thumbnail: true }));

        expect(screen.getByTestId("video-cell-preview-step-0")).toBeInTheDocument();
        expect(screen.getByTestId("video-cell-preview-step-0-image")).toHaveAttribute(
            "src",
            "/app/projects/1/manuals/5/cuts/11/takes/9/thumbnail",
        );
    });

    it("採用テイクが無いカットにはサムネイルが出ない", () => {
        renderWith(summary(null));

        expect(screen.queryByTestId("video-cell-preview-step-0")).toBeNull();
    });

    it("サムネイル未生成の採用テイクにはサムネイルが出ない", () => {
        const { container } = renderWith(summary({ id: 9, status: "ready", has_thumbnail: false }));

        expect(screen.queryByTestId("video-cell-preview-step-0")).toBeNull();
        // 404 になる URL を 1 つも張らない (属性へ直接問い合わせる)
        expect(container.querySelector('img[src*="/thumbnail"]')).toBeNull();
        expect(container.querySelector('video[src*="/playback"]')).toBeNull();
    });

    it("非 ready の採用テイクにはサムネイルが出ない", () => {
        const { container } = renderWith(
            summary({ id: 9, status: "processing", has_thumbnail: true }),
        );

        expect(screen.queryByTestId("video-cell-preview-step-0")).toBeNull();
        expect(container.querySelector('img[src*="/thumbnail"]')).toBeNull();
        expect(container.querySelector('video[src*="/playback"]')).toBeNull();
    });

    it("サムネイルが出ないケースでも「テイクを選択」は押せる (条件未充足で操作を塞がない)", () => {
        renderWith(summary({ id: 9, status: "processing", has_thumbnail: false }));

        const links = screen.getAllByTestId("video-cell-link");
        expect(links[0]).toHaveTextContent("テイクを選択");
        expect(links[0]).not.toHaveAttribute("disabled");
        expect(links[0].getAttribute("href")).toMatch(
            /^https?:\/\/[^/]+\/projects\/1\/manuals\/5\/cuts\/11\/takes$/,
        );
    });

    it("採用済みバッジとテイク件数は従来どおり出る", () => {
        renderWith(summary({ id: 9, status: "ready", has_thumbnail: true }));

        expect(screen.getAllByTestId("video-cell-count")[0]).toHaveTextContent("テイク 2 件");
        expect(screen.getAllByTestId("video-cell-adopted")[0]).toHaveTextContent("採用済み");
    });
});
