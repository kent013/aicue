import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

/*
 * Settings/Security 2FA セットアップ確認 (F-2-02 / T059)。
 * Fortify の ConfirmTwoFactorAuthentication は検証失敗を名前付き error bag
 * "confirmTwoFactorAuthentication" に投げる。client が同名の errorBag を指定しないと
 * Inertia が named bag をネストしたまま共有し confirmForm.errors.code が解決されず、
 * 誤コード時に無言失敗する。本テストは以下を回帰固定する:
 *   (a) 確認 POST に errorBag: "confirmTwoFactorAuthentication" が付く
 *   (b) レスポンスの errors 反映で入力直下にエラーが表示され Input が aria-invalid になる
 *   (c) 正コード成功で確認フォームが閉じ reset される
 *
 * useForm を reactiveUseForm フェイクへ差し替え「post の visit options 検証」と
 * 「named bag エラーからの表示」を分離して検証する。router.post / page は既存テスト同様モック。
 */

const { routerPostMock, pageState, addToastMock, holder } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings/security",
    },
    addToastMock: vi.fn(),
    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock },
    page: pageState,
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.form = form;
        return form;
    },
}));

vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

import Security from "@/pages/Settings/Security.svelte";

const fetchMock = vi.fn();

/** JSON レスポンス風オブジェクト (fetch mock 用) */
function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

/** 確認フロー描画に必要な fetch (QR / secret key / recent-auth / recovery codes) を stub する */
function stubFetchRoutes(): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        }
        if (url.includes("/user/two-factor-secret-key")) {
            return Promise.resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        // /user/two-factor-recovery-codes (成功 callback 後の showRecoveryCodes)
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });
}

/** Inertia visit options (第3引数) の検証対象部分 */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** router.post (enableTwoFactor) の第3引数を取り出す */
function lastRouterVisitOptions(): InertiaVisitOptions {
    const call = routerPostMock.mock.calls.at(-1);
    if (!call) throw new Error("router.post が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

function currentForm(): ReturnType<typeof reactiveUseForm> {
    if (!holder.form) throw new Error("confirmForm フェイクが未生成");
    return holder.form;
}

/** confirmForm.post の第2引数 (visit options) を取り出す */
function lastConfirmPostOptions(): InertiaVisitOptions {
    const call = currentForm().post.mock.calls.at(-1);
    if (!call) throw new Error("confirmForm.post が呼ばれていない");
    return call[1] as InertiaVisitOptions;
}

/**
 * 2FA 無効状態から確認フォームを表示させる。
 * 有効化ボタン押下 → router.post onSuccess で confirming=true にして QR/確認フォームを描画する。
 */
async function openConfirmForm(): Promise<void> {
    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalledWith(
            "/user/two-factor-authentication",
            {},
            expect.objectContaining({ preserveScroll: true }),
        );
    });
    lastRouterVisitOptions().onSuccess?.();
    await waitFor(() => {
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
    });
}

/** 認証コードを入力して確認フォームを submit する */
async function submitConfirm(code = "123456"): Promise<void> {
    await fireEvent.input(screen.getByLabelText("認証コード"), { target: { value: code } });
    await fireEvent.click(screen.getByRole("button", { name: "確認して有効化" }));
}

beforeEach(() => {
    holder.form = null;
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: false } },
    };
    stubFetchRoutes();
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerPostMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
});

describe("Settings/Security 2FA 確認 (F-2-02: 誤コードエラー表示)", () => {
    it("(a) 確認 POST に errorBag: confirmTwoFactorAuthentication を指定する", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm();

        expect(currentForm().post).toHaveBeenCalledWith(
            "/user/confirmed-two-factor-authentication",
            expect.objectContaining({ errorBag: "confirmTwoFactorAuthentication" }),
        );
    });

    it("(b) 誤コードのレスポンス errors 反映で入力直下にエラーを表示し Input を aria-invalid にする", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("000000");

        // Inertia がレスポンス受領後に form.errors を更新する挙動を模倣 (named bag からスコープ済み)
        currentForm().respondWithErrors({ code: "認証コードが無効です" });

        await waitFor(() => {
            expect(screen.getByText("認証コードが無効です")).toBeInTheDocument();
        });
        // 入力直下 (#two-factor-code-error) に文言が紐づく
        expect(screen.getByText("認証コードが無効です")).toHaveAttribute(
            "id",
            "two-factor-code-error",
        );
        // Input が error 状態 (赤枠 class は実装詳細のため aria-invalid で固定する)
        expect(screen.getByLabelText("認証コード")).toHaveAttribute("aria-invalid", "true");
    });

    it("(c) 正コード成功で確認フォームが閉じ reset される", async () => {
        render(Security, { props: {} });

        await openConfirmForm();
        await submitConfirm("123456");

        const form = currentForm();
        // 成功 callback を発火 (Inertia visit 成功時の onSuccess)
        lastConfirmPostOptions().onSuccess?.();

        await waitFor(() => {
            expect(screen.queryByLabelText("認証コード")).toBeNull();
        });
        expect(form.reset).toHaveBeenCalled();
        // 有効化ボタンに戻る (twoFactorEnabled は依然 false のため)
        expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
        // enrollment 素材 (TOTP secret) は画面から破棄される (残置時間を enrollment 中に限定する)
        expect(screen.queryByTestId("two-factor-setup-key")).toBeNull();
    });
});

/*
 * 施策 C (bug-hunt F-4-02 / a11y H14): 2FA enrollment に手動セットアップキーを出し、
 * QR にアクセシブルネームを与える。カメラ不可端末 / QR 非対応の認証アプリ /
 * スクリーンリーダー利用者が enrollment を完了できないことを防ぐ。
 */

/** 解決タイミングを外から制御する promise */
interface Deferred {
    promise: Promise<unknown>;
    resolve: (value: unknown) => void;
}

function createDeferred(): Deferred {
    let resolve: (value: unknown) => void = () => undefined;
    const promise = new Promise<unknown>((res) => {
        resolve = res;
    });

    return { promise, resolve };
}

/** enrollment 素材の fetch だけを保留させる stub (解決順序を検証するため) */
function stubDeferredEnrollmentFetch(): { qr: Deferred[]; secret: Deferred[] } {
    const qr: Deferred[] = [];
    const secret: Deferred[] = [];

    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            const deferred = createDeferred();
            qr.push(deferred);
            return deferred.promise;
        }
        if (url.includes("/user/two-factor-secret-key")) {
            const deferred = createDeferred();
            secret.push(deferred);
            return deferred.promise;
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });

    return { qr, secret };
}

/** enrollment 素材の応答を個別に差し替える (未指定は既定の成功応答) */
function stubEnrollmentFetch(overrides: { qr?: unknown; secret?: unknown }): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/user/two-factor-qr-code")) {
            return Promise.resolve(overrides.qr ?? jsonResponse(true, 200, { svg: "<svg></svg>" }));
        }
        if (url.includes("/user/two-factor-secret-key")) {
            return Promise.resolve(
                overrides.secret ?? jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }),
            );
        }
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent: true,
                    passwordSet: true,
                    availableProviders: [],
                    canSatisfy: true,
                    confirmedAt: 1,
                }),
            );
        }
        return Promise.resolve(jsonResponse(true, 200, ["code-a", "code-b"]));
    });
}

/** 有効化ボタンを押し、router.post の onSuccess を発火して confirming 状態にする */
async function startEnrollment(): Promise<void> {
    await fireEvent.click(screen.getByTestId("enable-two-factor-button"));
    await waitFor(() => {
        expect(routerPostMock).toHaveBeenCalled();
    });
    lastRouterVisitOptions().onSuccess?.();
}

describe("Settings/Security 2FA enrollment 素材 (F-4-02: 手動セットアップキー / H14: QR の a11y)", () => {
    it("有効化開始でセットアップキーを取得し画面に表示する", async () => {
        render(Security, { props: {} });

        await openConfirmForm();

        expect(fetchMock).toHaveBeenCalledWith(
            "/user/two-factor-secret-key",
            expect.objectContaining({ headers: { Accept: "application/json" } }),
        );
        await waitFor(() => {
            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
                "ABCDEFGH12345678",
            );
        });
    });

    it("QR の wrapper に role=img とアクセシブルネームがある", async () => {
        render(Security, { props: {} });

        await openConfirmForm();

        await waitFor(() => {
            expect(
                screen.getByRole("img", { name: "2 要素認証の設定用 QR コード" }),
            ).toBeInTheDocument();
        });
    });

    it("QR 取得失敗でもセットアップキーで継続できる", async () => {
        stubEnrollmentFetch({ qr: jsonResponse(false, 500, null) });
        render(Security, { props: {} });

        await openConfirmForm();

        await waitFor(() => {
            expect(screen.getByTestId("qr-unavailable")).toBeInTheDocument();
        });
        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
            "ABCDEFGH12345678",
        );
        // 認証コード入力は残る = enrollment を続行できる
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("セットアップキーが不正 shape でも QR で継続できる (不正 shape = 取得失敗と同経路)", async () => {
        stubEnrollmentFetch({ secret: jsonResponse(true, 200, {}) });
        render(Security, { props: {} });

        await openConfirmForm();

        await waitFor(() => {
            expect(screen.getByTestId("setup-key-unavailable")).toBeInTheDocument();
        });
        expect(screen.getByTestId("two-factor-qr")).toBeInTheDocument();
        expect(screen.getByLabelText("認証コード")).toBeInTheDocument();
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("両方失敗したときは再試行導線を出し、押下で再取得する", async () => {
        stubEnrollmentFetch({
            qr: jsonResponse(false, 500, null),
            secret: jsonResponse(false, 500, null),
        });
        render(Security, { props: {} });

        await openConfirmForm();

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-error")).toBeInTheDocument();
        });
        const retry = screen.getByTestId("retry-enrollment-assets-button");
        expect(retry).not.toBeDisabled(); // 禁止事項 8: 条件未充足で disabled にしない

        // 再試行で取得できるようにしてから押す
        stubEnrollmentFetch({});
        await fireEvent.click(retry);

        await waitFor(() => {
            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
                "ABCDEFGH12345678",
            );
        });
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();
    });

    it("取得中は失敗文言を出さない", async () => {
        const deferred = stubDeferredEnrollmentFetch();
        render(Security, { props: {} });

        await startEnrollment();

        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("qr-unavailable")).toBeNull();
        expect(screen.queryByTestId("setup-key-unavailable")).toBeNull();
        expect(screen.queryByTestId("enrollment-assets-error")).toBeNull();

        deferred.qr[0].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        deferred.secret[0].resolve(jsonResponse(true, 200, { secretKey: "ABCDEFGH12345678" }));

        await waitFor(() => {
            expect(screen.queryByTestId("enrollment-assets-loading")).toBeNull();
        });
        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
            "ABCDEFGH12345678",
        );
    });

    it("後着優先: 古い取得が後から解決しても新しいセットアップキーを上書きしない", async () => {
        // 旧取得が後勝ちすると、サーバが持つ新しい secret とは違うキーを認証アプリへ登録させてしまい
        // enrollment が必ず失敗する。観測可能な順序 (reset → 新取得の表示 → 旧取得の解決) で固定する。
        const deferred = stubDeferredEnrollmentFetch();
        render(Security, { props: {} });

        // (1) 有効化 → 取得 1 は保留のまま
        await startEnrollment();
        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
        });

        // (2) confirm 成功で enrollment 素材を破棄 (世代が進む)
        await submitConfirm("123456");
        lastConfirmPostOptions().onSuccess?.();
        await waitFor(() => {
            expect(screen.getByTestId("enable-two-factor-button")).toBeInTheDocument();
        });

        // (3) 再度有効化 → 取得 2 を開始 (古い run が loading を握っていないことの固定も兼ねる)
        await startEnrollment();
        await waitFor(() => {
            expect(screen.getByTestId("enrollment-assets-loading")).toBeInTheDocument();
        });

        // (4) 取得 2 を NEWKEY で解決して画面に出す
        deferred.qr[1].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        deferred.secret[1].resolve(jsonResponse(true, 200, { secretKey: "NEWKEY0987654321" }));
        await waitFor(() => {
            expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
                "NEWKEY0987654321",
            );
        });

        // (5) その後で取得 1 を OLDKEY で解決 → 画面は NEWKEY のまま
        deferred.qr[0].resolve(jsonResponse(true, 200, { svg: "<svg></svg>" }));
        deferred.secret[0].resolve(jsonResponse(true, 200, { secretKey: "OLDKEY1234567890" }));
        // マクロタスク境界まで進めて、旧取得の promise チェーンと Svelte の反映を完全に流す
        // (「出ないこと」の検証なので waitFor では待ちきれない)
        await new Promise((resolve) => setTimeout(resolve, 0));

        expect(screen.getByTestId("two-factor-setup-key-body")).toHaveTextContent(
            "NEWKEY0987654321",
        );
        expect(screen.queryByText("OLDKEY1234567890")).toBeNull();
    });
});
