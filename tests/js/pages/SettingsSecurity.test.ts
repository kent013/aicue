import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Security from "@/pages/Settings/Security.svelte";

/*
 * セキュリティ設定画面 (F-10: リカバリコード再生成導線)。
 * - 2FA 有効時のみ再生成ボタンが出る (非権限者非表示)
 * - ConfirmDialog 経由でのみ POST される
 * - 再生成 / 表示は recent-auth precheck 込み (stale なら再認証モーダル、POST しない)
 * - POST 成功 → GET 成功: 新コード表示 (success トーストはサーバ flash 委譲。client では出さない)
 * - POST 成功 → GET 失敗: 旧コード非表示のまま error トースト + 再試行導線
 * - disabled 不使用 (AGENTS.md 禁止事項 8)
 */

// router.post をモックし、page は 2FA 状態を書き換えられる可変オブジェクトにする
const { routerPostMock, routerDeleteMock, pageState, addToastMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    routerDeleteMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, delete: routerDeleteMock },
    page: pageState,
}));

// addToast のみ差し替え、toasts store 等 (ToastContainer が使う) は実物を残す
vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

const fetchMock = vi.fn();

function setTwoFactor(enabled: boolean): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: enabled } },
    };
}

/** JSON レスポンス風オブジェクト (fetch mock 用) */
function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

/**
 * URL で分岐する fetch mock を張る。
 * - /recent-auth/status: recent-auth precheck (fresh/stale)
 * - /user/two-factor-recovery-codes: GET 新コード取得 (成功/失敗)
 */
function stubFetchRoutes({
    recent = true,
    codes = ["new-code-1", "new-code-2"],
    codesOk = true,
}: {
    recent?: boolean;
    codes?: string[];
    codesOk?: boolean;
} = {}): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent,
                    passwordSet: true,
                    availableProviders: [],
                    passkeyAvailable: false,
                    canSatisfy: true,
                    confirmedAt: recent ? 1 : null,
                }),
            );
        }
        if (url.includes("/recent-auth/password")) {
            return Promise.resolve(jsonResponse(true, 204, null));
        }
        return Promise.resolve(
            codesOk ? jsonResponse(true, 200, codes) : jsonResponse(false, 500, {}),
        );
    });
}

beforeEach(() => {
    setTwoFactor(true);
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    routerDeleteMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

/** router.post の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** Inertia visit options (第3引数) を取り出す */
function lastVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

/** 再生成ダイアログを開いて確定する (recent-auth precheck が挟まるため POST は async) */
async function confirmRegenerate(): Promise<void> {
    await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));
    await fireEvent.click(screen.getByRole("button", { name: "再生成する" }));
}

/** 無効化ダイアログを開いて確定する (recent-auth precheck が挟まるため DELETE は async) */
async function confirmDisable(): Promise<void> {
    await fireEvent.click(screen.getByTestId("disable-two-factor-button"));
    await fireEvent.click(screen.getByRole("button", { name: "無効化する" }));
}

describe("Settings/Security リカバリコード再生成 (F-10)", () => {
    it("2FA 有効時に再生成ボタンが表示され、disabled ではない", () => {
        render(Security, { props: {} });

        const button = screen.getByTestId("regenerate-recovery-codes-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("2FA 無効時は再生成ボタンを表示しない (有効化ボタンのみ)", () => {
        setTwoFactor(false);
        render(Security, { props: {} });

        expect(screen.queryByTestId("regenerate-recovery-codes-button")).toBeNull();
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
    });

    it("再生成ボタン押下で確認ダイアログが開き、確認までは POST しない", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("regenerate-recovery-codes-button"));

        expect(
            screen.getByText(/既存のリカバリコードは直ちにすべて失効します/),
        ).toBeInTheDocument();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("ダイアログ確認で recent-auth precheck 後に POST /user/two-factor-recovery-codes が発火する", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmRegenerate();

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/two-factor-recovery-codes",
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        // precheck (/recent-auth/status) を経由している
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("recent-auth が stale なら再認証モーダルを開き、POST しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmRegenerate();

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerPostMock).not.toHaveBeenCalled();
        // stale 時にコード GET も発火していない (precheck のみ)
        const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
    });

    it("stale → モーダルで再認証成功 (204) すると保留していた POST を再開する", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/two-factor-recovery-codes",
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });
    });

    it("POST 成功 → GET 成功で新コードを表示する (success トーストはサーバ flash 委譲。client では出さない)", async () => {
        stubFetchRoutes({ recent: true, codes: ["new-code-1", "new-code-2"], codesOk: true });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("new-code-1");
        });
        // 成功 toast はサーバ flash (RecoveryCodesGeneratedResponse) が単一の源。
        // client 楽観 toast は出さない (二重発火 F-L1 の解消)。
        expect(addToastMock).not.toHaveBeenCalledWith("success", expect.anything());
    });

    it("POST 成功 → GET 失敗では旧コードを残さず error トースト + 再試行導線に戻る", async () => {
        stubFetchRoutes({ recent: true, codesOk: false });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        lastVisitOptions().onSuccess?.();

        await waitFor(() => {
            expect(addToastMock).toHaveBeenCalledWith(
                "error",
                expect.stringContaining("再生成されました"),
            );
        });
        expect(addToastMock).toHaveBeenCalledWith(
            "error",
            expect.stringContaining("表示取得に失敗"),
        );
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        expect(screen.getByTestId("show-recovery-codes-button")).toBeInTheDocument();
    });

    it("POST 実行中 (onStart〜onFinish) は確認ボタンが processing (aria-busy) になる", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmRegenerate();
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });

        const options = lastVisitOptions();
        options.onStart?.();
        await waitFor(() => {
            // Button atom は loading 中 aria-busy を立てる (二重送信抑止の回帰固定)
            expect(screen.getByRole("button", { name: "再生成する" })).toHaveAttribute(
                "aria-busy",
                "true",
            );
        });

        options.onFinish?.();
        await waitFor(() => {
            expect(screen.getByRole("button", { name: "再生成する" })).not.toHaveAttribute(
                "aria-busy",
            );
        });
    });
});

describe("Settings/Security リカバリコード表示 (recent-auth precheck)", () => {
    it("fresh なら「リカバリコードを表示」でコード一覧を取得して表示する", async () => {
        stubFetchRoutes({ recent: true, codes: ["code-a", "code-b"] });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recovery-codes")).toHaveTextContent("code-a");
        });
    });

    it("stale なら再認証モーダルを開き、コードを取得しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("show-recovery-codes-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recovery-codes")).toBeNull();
        // /user/two-factor-recovery-codes への GET は発火していない (status のみ)
        const requestedUrls = fetchMock.mock.calls.map((call) => String(call[0]));
        expect(requestedUrls).not.toContain("/user/two-factor-recovery-codes");
    });
});

describe("Settings/Security 2FA 無効化 (recent-auth precheck)", () => {
    it("fresh なら DELETE /user/two-factor-authentication が exactly once 発火する", async () => {
        stubFetchRoutes({ recent: true });
        render(Security, { props: {} });

        await confirmDisable();

        await waitFor(() => {
            expect(routerDeleteMock).toHaveBeenCalledWith(
                "/user/two-factor-authentication",
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("stale なら再認証モーダルを開き確認ダイアログを閉じ、DELETE しない (二重モーダル回避)", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmDisable();

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerDeleteMock).not.toHaveBeenCalled();
        // 二重モーダル回避: 無効化確認ダイアログ (disable-two-factor-dialog) は閉じている
        expect(screen.queryByTestId("disable-two-factor-dialog")).toBeNull();
    });

    it("stale → 再認証キャンセルで pending を破棄し、後続の別操作 resume でも DELETE しない", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        // 1. disable を stale で開始 → 再認証モーダル表示
        await confirmDisable();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        // 2. 再認証をキャンセル (open=false) → $effect が pendingAction を破棄
        await fireEvent.click(screen.getByRole("button", { name: "キャンセル" }));
        await waitFor(() => {
            expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
        });

        // 3. 別操作 (再生成) を stale → 再認証成功させても disable closure は resume されない
        await confirmRegenerate();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        // regenerate (POST) は resume されるが、破棄された disable (DELETE) は一度も発火しない
        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalled();
        });
        expect(routerDeleteMock).not.toHaveBeenCalled();
    });

    it("stale → 再認証成功で保留していた DELETE を exactly once 再開する", async () => {
        stubFetchRoutes({ recent: false });
        render(Security, { props: {} });

        await confirmDisable();
        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        await waitFor(() => {
            expect(routerDeleteMock).toHaveBeenCalledWith(
                "/user/two-factor-authentication",
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(routerDeleteMock).toHaveBeenCalledTimes(1);
    });
});

/*
 * T124: enrollment (有効化開始 + 素材取得) の step-up precheck と 409 再開。
 *
 * サーバ側で POST /user/two-factor-authentication と GET /user/two-factor-{qr-code,secret-key} が
 * recent-auth 必須になったため、
 *  (a) 有効化ボタンは precheck を通す (stale なら POST せずモーダル)
 *  (b) 素材の 409 は「取得失敗」ではなく step-up 要求として扱い、1 回だけ自動再開する
 *  (c) status が取れない (delegated) ときは **再取得しない** (409 → status 失敗 → 再取得 の
 *      無限ループを構造的に不能にする)
 * を固定する。
 */

/** enrollment 素材 1 本の応答指定 */
type FieldStub = { kind: "ok"; body: unknown } | { kind: "error"; status: number; body: unknown };

const RECENT_AUTH_409: FieldStub = {
    kind: "error",
    status: 409,
    body: {
        code: "recent_auth_required",
        message: "この操作には直近の再認証が必要です。",
        redirect: "/recent-auth/confirm",
    },
};

interface EnrollmentStubState {
    /** /recent-auth/status の応答 (null = HTTP 500 で status が取れない) */
    recent: boolean | null;
    qr: FieldStub;
    secret: FieldStub;
}

function fieldResponse(stub: FieldStub): unknown {
    return stub.kind === "ok"
        ? jsonResponse(true, 200, stub.body)
        : jsonResponse(false, stub.status, stub.body);
}

/**
 * enrollment 用 fetch mock。**可変 state** を返し、テスト側が途中で応答を差し替えられる
 * (mock 実装は state を毎回読むため、差し替えは即座に効く)。
 */
function stubEnrollmentFetch(initial: Partial<EnrollmentStubState> = {}): EnrollmentStubState {
    const state: EnrollmentStubState = {
        recent: true,
        qr: { kind: "ok", body: { svg: "<svg></svg>" } },
        secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
        ...initial,
    };

    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/recent-auth/status")) {
            if (state.recent === null) {
                return Promise.resolve(jsonResponse(false, 500, {}));
            }
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: state.recent,
                    passwordSet: true,
                    availableProviders: [],
                    passkeyAvailable: false,
                    canSatisfy: true,
                    confirmedAt: state.recent ? 1 : null,
                }),
            );
        }
        if (url.includes("/recent-auth/password")) {
            return Promise.resolve(jsonResponse(true, 204, null));
        }
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(fieldResponse(state.qr));
        }
        if (url.includes("/user/two-factor-secret-key")) {
            return Promise.resolve(fieldResponse(state.secret));
        }
        return Promise.resolve(jsonResponse(true, 200, []));
    });

    return state;
}

/** 指定 URL 片を含む fetch 呼び出し回数 */
function fetchCallCount(fragment: string): number {
    return fetchMock.mock.calls.filter((call) => String(call[0]).includes(fragment)).length;
}

/**
 * 2FA 未設定状態で描画し、有効化 → enable POST 成功 (onSuccess) まで進めて enrollment に入る。
 *
 * ★有効化ボタン自身の precheck は **常に fresh** で通す (ここは素材取得側の検証が目的)。
 *   呼び出し側が指定した `state.recent` は POST 成立後に復元し、
 *   素材の 409 を受けた precheck から効かせる。
 * ★onSuccess の直前に fetch 呼び出し履歴を消す (以降の回数が素材取得だけを数える)。
 */
async function enterEnrollment(state: EnrollmentStubState): Promise<void> {
    const recentForAssets = state.recent;
    state.recent = true;

    setTwoFactor(false);
    render(Security, { props: {} });

    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-authentication",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });

    state.recent = recentForAssets;
    fetchMock.mockClear();
    lastVisitOptions().onSuccess?.();
}

describe("Settings/Security 有効化開始の step-up precheck (T124)", () => {
    it("stale なら再認証モーダルを開き、enable を POST しない", async () => {
        stubEnrollmentFetch({ recent: false });
        setTwoFactor(false);
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("fresh なら enable を POST する (負のコントロール)", async () => {
        stubEnrollmentFetch({ recent: true });
        setTwoFactor(false);
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("enable-two-factor-button"));

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/two-factor-authentication",
                {},
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
    });
});

describe("Settings/Security enrollment 素材の 409 (step-up) 処理 (T124)", () => {
    it("素材取得が両方 409 でも再認証モーダルの起動は 1 回だけ (取得失敗にも畳まない)", async () => {
        const state = stubEnrollmentFetch({
            recent: false,
            qr: RECENT_AUTH_409,
            secret: RECENT_AUTH_409,
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(screen.getAllByTestId("recent-auth-modal")).toHaveLength(1);
        // status の再取得は 1 回だけ (409 を受けた precheck)
        expect(fetchCallCount("/recent-auth/status")).toBe(1);
        // 409 を「取得失敗」に畳んでいない
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("片方だけ 409 でも再認証モーダルへ倒す (部分的鮮度切れの一貫性)", async () => {
        const state = stubEnrollmentFetch({
            recent: false,
            qr: RECENT_AUTH_409,
            secret: { kind: "ok", body: { secretKey: "SETUPKEY123" } },
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("409 以外の失敗 (500) は従来どおり取得失敗 Alert を出し、モーダルを開かない", async () => {
        // 通常エラーを step-up へ誤分類しないことの負のコントロール
        const state = stubEnrollmentFetch({
            qr: { kind: "error", status: 500, body: {} },
            secret: { kind: "error", status: 500, body: {} },
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
    });

    it("500 で取得失敗した後に再試行して 409 になったら取得失敗 Alert を残さない", async () => {
        // ★状態の混在回帰 (Codex impl-review R1 [Warning])。
        //   409 分岐が enrollmentAssetsFailed を触らないと「再認証が必要です」と
        //   「設定情報を取得できませんでした」が同時に出て、原因と対処が食い違う。
        const state = stubEnrollmentFetch({
            qr: { kind: "error", status: 500, body: {} },
            secret: { kind: "error", status: 500, body: {} },
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
        });

        // 再試行したら今度は step-up 要求 (409)。status も取れない = blocked へ倒れる
        state.recent = null;
        state.qr = RECENT_AUTH_409;
        state.secret = RECENT_AUTH_409;
        await fireEvent.click(screen.getByTestId("retry-enrollment-assets-button"));

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("素材が 409 かつ /recent-auth/status が 500 のとき再取得ループしない", async () => {
        // ★delegated ループ回帰。この設計の中心的な安全性テスト。
        const state = stubEnrollmentFetch({
            recent: null,
            qr: RECENT_AUTH_409,
            secret: RECENT_AUTH_409,
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
        });

        // 素材 2 本 + status 1 本で停止する (4 回目以降が発火しない)
        expect(fetchCallCount("/user/two-factor-qr-code")).toBe(1);
        expect(fetchCallCount("/user/two-factor-secret-key")).toBe(1);
        expect(fetchCallCount("/recent-auth/status")).toBe(1);
        expect(fetchMock.mock.calls).toHaveLength(3);

        // 追加の tick を与えても増えない (非同期ループの取りこぼし検出)
        await new Promise((resolve) => setTimeout(resolve, 30));
        expect(fetchMock.mock.calls).toHaveLength(3);

        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
    });

    it("step-up 不能 Alert の再試行ボタンは自動再開の上限を戻して再取得する", async () => {
        const state = stubEnrollmentFetch({
            recent: null,
            qr: RECENT_AUTH_409,
            secret: RECENT_AUTH_409,
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-step-up-blocked")).toBeInTheDocument();
        });

        // 人間の操作でループを切る = 上限が「詰み」にならないことの確認
        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };
        await fireEvent.click(screen.getByTestId("retry-enrollment-step-up-button"));

        await waitFor(() => {
            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
        });
        expect(screen.queryByTestId("enrollment-step-up-blocked")).toBeNull();
    });

    it("再認証成立後に素材取得が再開され QR とセットアップキーが表示される", async () => {
        const state = stubEnrollmentFetch({
            recent: false,
            qr: RECENT_AUTH_409,
            secret: RECENT_AUTH_409,
        });
        await enterEnrollment(state);

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });

        // 再認証が成立したので素材は返るようになる
        state.qr = { kind: "ok", body: { svg: "<svg></svg>" } };
        state.secret = { kind: "ok", body: { secretKey: "RESUMEDKEY" } };

        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "current-password" },
        });
        await fireEvent.click(screen.getByTestId("recent-auth-submit"));

        await waitFor(() => {
            expect(screen.getByTestId("two-factor-setup-key")).toHaveTextContent("RESUMEDKEY");
        });
        expect(screen.getByTestId("two-factor-qr")).toBeInTheDocument();
    });
});
