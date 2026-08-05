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

    it("非対応ブラウザで登録を押すと理由をトーストで出す (無言失敗にしない)", async () => {
        removePasskeySupport();
        render(Security, { props: {} });

        await fireEvent.click(screen.getByTestId("register-passkey-button"));

        expect(addToastMock).toHaveBeenCalledWith("error", expect.stringContaining("対応していません"));
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

    it("ceremony 失敗はエラーを出して POST しない", async () => {
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

        await waitFor(() => {
            expect(addToastMock).toHaveBeenCalledWith(
                "error",
                "パスキーの登録を開始できませんでした。",
            );
        });
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
