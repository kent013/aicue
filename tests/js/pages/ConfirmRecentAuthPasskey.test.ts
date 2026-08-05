import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";

/*
 * confirm 画面 (302 fallback 着地) のパスキー導線。
 *
 * **パスキーしか持たないユーザーをこの画面で詰ませない**ことが目的。
 * 送信は fetch ではなく **Inertia の router.post** で行う — 元 URL はサーバの
 * `url.intended` にしか無く、PasskeyConfirmationResponse の
 * `redirect()->intended()` 分岐に乗せる必要があるため。
 */

const { routerPostMock, confirmPasskeyCredentialMock } = vi.hoisted(() => ({
    routerPostMock: vi.fn(),
    confirmPasskeyCredentialMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, visit: vi.fn() },
}));

vi.mock("@/lib/passkeys", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
    confirmPasskeyCredential: confirmPasskeyCredentialMock,
}));

import ConfirmRecentAuth from "@/pages/Auth/ConfirmRecentAuth.svelte";

const CREDENTIAL_FIXTURE = {
    id: "cred-id",
    rawId: "cred-raw-id",
    type: "public-key",
    response: { clientDataJSON: "aaa", authenticatorData: "bbb", signature: "ccc", userHandle: null },
};

function stubPasskeySupport(): void {
    Object.defineProperty(globalThis, "navigator", {
        configurable: true,
        value: { credentials: { create: vi.fn(), get: vi.fn() } },
    });
    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: function PublicKeyCredentialStub() {
            // instanceof 判定にのみ使う
        },
    });
}

function removePasskeySupport(): void {
    Object.defineProperty(window, "PublicKeyCredential", {
        configurable: true,
        writable: true,
        value: undefined,
    });
}

beforeEach(() => {
    stubPasskeySupport();
});

afterEach(() => {
    cleanup();
    removePasskeySupport();
    routerPostMock.mockReset();
    confirmPasskeyCredentialMock.mockReset();
});

describe("Auth/ConfirmRecentAuth パスキー導線", () => {
    it("passkeyAvailable=false ならパスキーボタンを出さない", () => {
        render(ConfirmRecentAuth, {
            props: { passwordSet: true, passkeyAvailable: false, canSatisfy: true },
        });

        expect(screen.queryByTestId("confirm-passkey-button")).toBeNull();
    });

    it("パスキーしか無いユーザーでもパスキーで再認証できる (詰まない)", async () => {
        confirmPasskeyCredentialMock.mockResolvedValue({ status: "ok", value: CREDENTIAL_FIXTURE });

        render(ConfirmRecentAuth, {
            props: { passwordSet: false, passkeyAvailable: true, canSatisfy: true },
        });

        // 「再認証手段が設定されていません」の行き止まり表示は出ない
        expect(screen.queryByText(/再認証手段が設定されていません/)).toBeNull();

        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));

        await waitFor(() => {
            expect(routerPostMock).toHaveBeenCalledWith(
                "/passkeys/confirm",
                { credential: CREDENTIAL_FIXTURE },
                expect.anything(),
            );
        });
    });

    it("ceremony 失敗は同画面にエラーを出し POST しない (回復導線を残す)", async () => {
        confirmPasskeyCredentialMock.mockResolvedValue({
            status: "failed",
            message: "パスキーの認証を開始できませんでした。",
        });

        render(ConfirmRecentAuth, {
            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
        });

        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));

        await waitFor(() => {
            expect(screen.getByTestId("confirm-passkey-error")).toHaveTextContent(
                "パスキーの認証を開始できませんでした。",
            );
        });
        expect(routerPostMock).not.toHaveBeenCalled();
        // パスワード欄は残る
        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
    });

    it("キャンセルはエラーを出さず POST もしない (騒がない)", async () => {
        confirmPasskeyCredentialMock.mockResolvedValue({ status: "cancelled" });

        render(ConfirmRecentAuth, {
            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
        });

        await fireEvent.click(screen.getByTestId("confirm-passkey-button"));

        await waitFor(() => {
            expect(confirmPasskeyCredentialMock).toHaveBeenCalled();
        });
        expect(screen.queryByTestId("confirm-passkey-error")).toBeNull();
        expect(routerPostMock).not.toHaveBeenCalled();
    });

    it("非対応ブラウザではパスキーボタンを出さない", () => {
        removePasskeySupport();
        render(ConfirmRecentAuth, {
            props: { passwordSet: true, passkeyAvailable: true, canSatisfy: true },
        });

        expect(screen.queryByTestId("confirm-passkey-button")).toBeNull();
    });
});

/*
 * **「アカウントには手段があるが、この端末では実行できない」を無言にしない**。
 *
 * `canSatisfy` はサーバ判定 (アカウント側の能力) であり、WebAuthn の feature detection は
 * クライアント側にしか無い。passkey しか持たないユーザーが非対応ブラウザで開くと
 * 「password 欄も SSO も passkey ボタンも出ないが canSatisfy=true なので回復案内も出ない」
 * という説明の無い行き止まりになる。
 */
describe("Auth/ConfirmRecentAuth この端末では実行できない状態", () => {
    it("passkey のみ + 非対応ブラウザなら理由と回復導線を出す", () => {
        removePasskeySupport();

        render(ConfirmRecentAuth, {
            props: {
                passwordSet: false,
                availableProviders: [],
                passkeyAvailable: true,
                canSatisfy: true,
            },
        });

        expect(screen.getByTestId("confirm-unsupported-here")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
    });

    it("対応ブラウザなら「この端末では実行できない」案内を出さない", () => {
        render(ConfirmRecentAuth, {
            props: {
                passwordSet: false,
                availableProviders: [],
                passkeyAvailable: true,
                canSatisfy: true,
            },
        });

        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
        expect(screen.getByTestId("confirm-passkey-button")).toBeInTheDocument();
    });

    it("password があれば非対応ブラウザでも案内を出さない (実行可能な手段が残る)", () => {
        removePasskeySupport();

        render(ConfirmRecentAuth, {
            props: {
                passwordSet: true,
                availableProviders: [],
                passkeyAvailable: true,
                canSatisfy: true,
            },
        });

        expect(screen.queryByTestId("confirm-unsupported-here")).toBeNull();
        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
    });
});
