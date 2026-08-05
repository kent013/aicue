import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Security from "@/pages/Settings/Security.svelte";

/*
 * セキュリティ設定のパスキーカード (T106 施策 6)。
 * - 非対応 / 作成不可の端末に理由を出す (ボタンは disabled にしない = AGENTS.md 禁止事項 8)
 * - 2FA 有効時は「ログインには使えないが再認証には使える」を明示する (誤認防止)
 * - 登録 / 削除は recent-auth precheck を通す (stale なら再認証モーダル)
 * - EnsureLoginMethodRemains の拒否 (errors.login_method) を画面に出す (無言失敗にしない)
 */

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

vi.mock("@/lib/stores/toast", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/stores/toast")>()),
    addToast: addToastMock,
}));

/*
 * 登録 ceremony はブラウザ API を要するため、ラッパの戻り値だけを差し替えて
 * **送信 payload の shape** を固定する (vendor の PasskeyRegistrationRequest は
 * `{ name, credential: {...} }` の nested 形を要求する。サーバ側の対の固定は
 * tests/Feature/Auth/PasskeyRouteAccessTest.php)。
 */
const { createPasskeyCredentialMock } = vi.hoisted(() => ({
    createPasskeyCredentialMock: vi.fn(),
}));

vi.mock("@/lib/passkeys", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
    createPasskeyCredential: createPasskeyCredentialMock,
}));

const CREDENTIAL_FIXTURE = {
    id: "cred-id",
    rawId: "cred-raw-id",
    type: "public-key",
    response: { clientDataJSON: "aaa", attestationObject: "bbb" },
};

const fetchMock = vi.fn();

function setPageProps(options: { twoFactor?: boolean; errors?: Record<string, string> } = {}): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", twoFactorEnabled: options.twoFactor ?? false } },
        errors: options.errors ?? {},
    };
}

/** WebAuthn 対応端末を偽装する */
function stubPasskeySupport(creatable = true): void {
    Object.defineProperty(globalThis, "navigator", {
        configurable: true,
        value: { credentials: { create: vi.fn(), get: vi.fn() } },
    });
    const publicKeyCredential = function PublicKeyCredentialStub() {
        // instanceof 判定にのみ使う
    } as unknown as typeof PublicKeyCredential;
    (
        publicKeyCredential as unknown as {
            isUserVerifyingPlatformAuthenticatorAvailable: () => Promise<boolean>;
        }
    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(creatable);
    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: publicKeyCredential,
    });
}

function removePasskeySupport(): void {
    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: undefined,
    });
}

function jsonResponse(ok: boolean, status: number, body: unknown): unknown {
    return { ok, status, json: () => Promise.resolve(body) };
}

function stubRecentAuth(recent: boolean, passkeyAvailable = false): void {
    fetchMock.mockImplementation((input: RequestInfo | URL) => {
        const url = String(input);
        if (url.includes("/recent-auth/status")) {
            return Promise.resolve(
                jsonResponse(true, 200, {
                    recent,
                    passwordSet: true,
                    availableProviders: [],
                    passkeyAvailable,
                    canSatisfy: true,
                    confirmedAt: recent ? 1 : null,
                }),
            );
        }
        return Promise.resolve(jsonResponse(false, 500, {}));
    });
}

const passkeys = [
    {
        id: 7,
        name: "現場用スマホ",
        authenticator: "iCloud Keychain",
        lastUsedAt: "2026-08-01T00:00:00+09:00",
        createdAt: "2026-07-01T00:00:00+09:00",
    },
];

beforeEach(() => {
    setPageProps();
    stubPasskeySupport();
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    removePasskeySupport();
    routerPostMock.mockReset();
    routerDeleteMock.mockReset();
    addToastMock.mockReset();
    fetchMock.mockReset();
    createPasskeyCredentialMock.mockReset();
});

describe("Settings/Security パスキーカード", () => {
    it("非対応ブラウザでは理由を出すが登録ボタンは disabled にしない", () => {
        removePasskeySupport();
        render(Security, { props: {} });

        expect(screen.getByTestId("passkey-unsupported")).toBeInTheDocument();
        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
    });

    // 非フィールド起因の操作失敗は **Alert** (DESIGN.md §Alert)。押下直後に読ませたい失敗理由を
    // 画面外へ飛ぶ toast に出さない。
    it("非対応ブラウザで登録を押すと理由を Alert で出す (無言失敗にしない)", async () => {
        removePasskeySupport();
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        const alert = await screen.findByTestId("passkey-operation-error");
        expect(alert).toHaveTextContent("対応していません");
        expect(addToastMock).not.toHaveBeenCalled();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("プラットフォーム認証器が使えない端末には作成不可の理由を出す", async () => {
        stubPasskeySupport(false);
        render(Security, { props: {} });

        await waitFor(() => {
            expect(screen.getByTestId("passkey-not-creatable")).toBeInTheDocument();
        });
    });

    it("2FA 有効時は「ログイン不可・再認証は可」を明示する", () => {
        setPageProps({ twoFactor: true });
        render(Security, { props: { passkeyLoginAvailable: false } });

        expect(screen.getByTestId("passkey-2fa-notice")).toBeInTheDocument();
    });

    it("2FA 無効かつ passkeyLoginAvailable なら 2FA 注意書きを出さない", () => {
        render(Security, { props: { passkeyLoginAvailable: true } });

        expect(screen.queryByTestId("passkey-2fa-notice")).toBeNull();
    });

    it("登録済みパスキーを一覧表示する", () => {
        render(Security, { props: { passkeys } });

        expect(screen.getByTestId("passkey-list")).toBeInTheDocument();
        expect(screen.getByText("現場用スマホ")).toBeInTheDocument();
        expect(screen.getByTestId("passkey-count")).toHaveTextContent("1 件登録済み");
    });

    it("名前未入力の登録はエラー表示のみで ceremony を開始しない", async () => {
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it("削除は確認ダイアログを挟み、確認までは DELETE しない", async () => {
        stubRecentAuth(true);
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));

        // 一覧側にも同名が出るためダイアログ本体で照合する
        expect(screen.getByTestId("delete-passkey-dialog")).toHaveTextContent("現場用スマホ");
        expect(routerDeleteMock).not.toHaveBeenCalled();
    });

    it("確認後は recent-auth precheck を通して DELETE する", async () => {
        stubRecentAuth(true);
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(routerDeleteMock).toHaveBeenCalledWith(
                "/user/passkeys/7",
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("recent-auth が stale なら再認証モーダルを開き DELETE しない", async () => {
        stubRecentAuth(false);
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerDeleteMock).not.toHaveBeenCalled();
    });

    it("ログイン手段保持 guard の拒否メッセージを画面に出す (無言失敗にしない)", () => {
        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
        render(Security, { props: { passkeys } });

        const alert = screen.getByTestId("passkey-login-method-error");
        expect(alert).toBeInTheDocument();
        expect(alert).toHaveTextContent("ログインする手段がなくなります");
        // 回復導線 (別のログイン手段を追加する) を同画面に出す
        expect(screen.getByTestId("passkey-add-password")).toBeInTheDocument();
    });

    // passkey 導線の可否は **サーバの status が単一の源** (画面側で判定しない)
    it("status の passkeyAvailable が true なら再認証モーダルにパスキー導線が出る", async () => {
        stubRecentAuth(false, true);
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
        });
    });

    it("status の passkeyAvailable が false ならパスキー導線を出さない", async () => {
        stubRecentAuth(false, false);
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
    });
});

describe("パスキー登録の送信契約", () => {
    it("ceremony 成功時に { name, credential } の nested 形で POST する", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({
            status: "ok",
            value: CREDENTIAL_FIXTURE,
        });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/user/passkeys",
                { name: "現場用スマホ", credential: CREDENTIAL_FIXTURE },
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        // recent-auth precheck を経由している (登録も step-up 必須)
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("recent-auth が stale なら再認証モーダルを開き ceremony を開始しない", async () => {
        stubRecentAuth(false);
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(createPasskeyCredentialMock).not.toHaveBeenCalled();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("ceremony キャンセルは POST せず、エラートーストも出さない (騒がない)", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({ status: "cancelled" });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => {
            expect(createPasskeyCredentialMock).toHaveBeenCalled();
        });
        expect(routerPostMock).not.toHaveBeenCalled();
        expect(addToastMock).not.toHaveBeenCalled();
    });

    it("ceremony 失敗は Alert に理由を出して POST しない", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({
            status: "failed",
            message: "パスキーの登録を開始できませんでした。",
        });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        const alert = await screen.findByTestId("passkey-operation-error");
        expect(alert).toHaveTextContent("パスキーの登録を開始できませんでした。");
        expect(addToastMock).not.toHaveBeenCalled();
        expect(routerPostMock).not.toHaveBeenCalled();
    });
});

/*
 * **「アカウントには手段があるが、この端末では実行できない」を無言にしない**
 * (RecentAuthModal 側。confirm 画面側は ConfirmRecentAuthPasskey.test.ts)。
 */
describe("再認証モーダル: この端末では実行できない状態", () => {
    function stubPasskeyOnlyStatus(): void {
        fetchMock.mockImplementation((input: RequestInfo | URL) => {
            const url = String(input);
            if (url.includes("/recent-auth/status")) {
                return Promise.resolve(
                    jsonResponse(true, 200, {
                        recent: false,
                        passwordSet: false,
                        availableProviders: [],
                        passkeyAvailable: true,
                        canSatisfy: true,
                        confirmedAt: null,
                    }),
                );
            }
            return Promise.resolve(jsonResponse(false, 500, {}));
        });
    }

    it("passkey のみ + 非対応ブラウザなら理由を出す (無言の行き止まりにしない)", async () => {
        removePasskeySupport();
        stubPasskeyOnlyStatus();
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
    });

    it("対応ブラウザなら理由ではなくパスキー導線を出す", async () => {
        stubPasskeyOnlyStatus();
        render(Security, { props: { passkeys } });

        await fireEvent.click(screen.getByTestId("delete-passkey-7"));
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
        });
        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
    });
});

/*
 * 名前エラーの canonical 形 (施策 10。DESIGN.md §FormField)。
 * 押下時に代入するだけだと、その後の入力でエラーが消えず stale invalid が残る。
 * 「提示開始 boolean + $derived 文言」にして入力へ追随させる。
 */
describe("パスキー名エラーの入力追随 (施策 10)", () => {
    it("空で押下 → 文言が出る → 1 文字入力で消える → 再び空にすると戻る", async () => {
        render(Security, { props: {} });
        const input = screen.getByTestId("passkey-name-input");

        await fireEvent.click(screen.getByTestId("register-passkey-button"));
        expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument();

        await fireEvent.input(input, { target: { value: "あ" } });
        await waitFor(() =>
            expect(screen.queryByText("パスキーの名前を入力してください。")).toBeNull(),
        );

        await fireEvent.input(input, { target: { value: "" } });
        await waitFor(() =>
            expect(screen.getByText("パスキーの名前を入力してください。")).toBeInTheDocument(),
        );
    });

    it("サーバ 422 の errors.name は FormField に出る (汎用トーストに潰さない)", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
        const options = routerPostMock.mock.calls.at(-1)?.[2] as {
            onError?: (errors: Record<string, string>) => void;
        };
        options.onError?.({ name: "その名前は既に使われています。" });

        await waitFor(() =>
            expect(screen.getByText("その名前は既に使われています。")).toBeInTheDocument(),
        );
        // フィールド起因なので Alert (非フィールド起因) には出さない
        expect(screen.queryByTestId("passkey-operation-error")).toBeNull();
        expect(addToastMock).not.toHaveBeenCalled();
    });

    it("フィールドに紐づかないサーバエラーは Alert に出る", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
        const options = routerPostMock.mock.calls.at(-1)?.[2] as {
            onError?: (errors: Record<string, string>) => void;
        };
        options.onError?.({ credential: "不正な credential です。" });

        expect(await screen.findByTestId("passkey-operation-error")).toBeInTheDocument();
    });
});

/*
 * 登録フローの多重起動ガード (施策 11)。
 * 現行は router.post を await していないため ceremony 直後に loading が解け、連打で
 * ceremony が多重に走る。precheck (/recent-auth/status) の待ち時間も無防備だった。
 */
describe("パスキー登録フローの多重起動ガード (施策 11)", () => {
    it("POST 中は登録ボタンが loading のまま (onFinish まで解除しない)", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
        // onStart だけ呼び onFinish は呼ばない = POST 継続中
        routerPostMock.mockImplementation(
            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
                options.onStart?.();
            },
        );
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() =>
            expect(screen.getByTestId("register-passkey-button")).toHaveAttribute(
                "aria-busy",
                "true",
            ),
        );
    });

    it("precheck の解決待ち中に連打しても ceremony は 1 回しか始まらない", async () => {
        // /recent-auth/status を保留させ、precheck 区間を開いたままにする
        // 制御端を object に持つ (直接の局所変数だと TS が callback 内代入を追えず never に潰れる)
        const pending: { resolve: (value: unknown) => void } = { resolve: () => {} };
        fetchMock.mockImplementation((input: RequestInfo | URL) => {
            if (String(input).includes("/recent-auth/status")) {
                return new Promise((resolve) => {
                    pending.resolve = resolve;
                });
            }
            return Promise.resolve(jsonResponse(false, 500, {}));
        });
        createPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        const button = screen.getByTestId("register-passkey-button");
        await fireEvent.click(button);
        await fireEvent.click(button);
        await fireEvent.click(button);

        expect(createPasskeyCredentialMock).not.toHaveBeenCalled();

        pending.resolve(
            jsonResponse(true, 200, {
                recent: true,
                passwordSet: true,
                availableProviders: [],
                passkeyAvailable: false,
                canSatisfy: true,
                confirmedAt: 1,
            }),
        );

        await waitFor(() => expect(createPasskeyCredentialMock).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(routerPostMock).toHaveBeenCalledTimes(1));
    });

    it("ceremony が throw しても Alert を出して loading が固まらない", async () => {
        stubRecentAuth(true);
        createPasskeyCredentialMock.mockRejectedValue(new Error("unexpected"));
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        expect(await screen.findByTestId("passkey-operation-error")).toBeInTheDocument();
        await waitFor(() =>
            expect(screen.getByTestId("register-passkey-button")).not.toHaveAttribute(
                "aria-busy",
                "true",
            ),
        );
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("stale でモーダルへ委譲した後にキャンセルしても登録ボタンが固まらない", async () => {
        stubRecentAuth(false);
        render(Security, { props: {} });

        await fireEvent.input(screen.getByTestId("passkey-name-input"), {
            target: { value: "現場用スマホ" },
        });
        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        await waitFor(() => expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument());
        await fireEvent.click(screen.getByRole("button", { name: "キャンセル" }));

        await waitFor(() =>
            expect(screen.getByTestId("register-passkey-button")).not.toHaveAttribute(
                "aria-busy",
                "true",
            ),
        );
        expect(screen.getByTestId("register-passkey-button")).not.toBeDisabled();
    });
});

/*
 * 踏破可能な CTA (施策 8)。この Alert が出るのは「削除するとログイン手段が 0 になる」=
 * password を持たないユーザーだけなので、/settings は必ず初回設定フォームを出す。
 */
describe("ログイン手段保持 guard の CTA 踏破可能性 (施策 8)", () => {
    it("CTA の遷移先は /settings (password 未設定なら初回設定フォームが出る)", () => {
        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
        render(Security, { props: { passkeys } });

        const cta = screen.getByTestId("passkey-add-password");
        expect(new URL((cta as HTMLAnchorElement).href).pathname).toBe("/settings");
    });

    it("拒否 Alert が現れたらフォーカスを移す (見落とさせない)", async () => {
        setPageProps({ errors: { login_method: "この操作を行うと、ログインする手段がなくなります。" } });
        render(Security, { props: { passkeys } });

        await waitFor(() => {
            const alert = screen.getByTestId("passkey-login-method-error");
            expect(alert.closest('[tabindex="-1"]')).toBe(document.activeElement);
        });
    });
});
