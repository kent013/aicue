import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Login from "@/pages/Auth/Login.svelte";

/*
 * ログイン画面のパスキー導線 (T106 施策 6)。
 * - 非対応ブラウザではボタン自体を出さない (押しても何もできない導線を出さない)
 * - 失敗時もパスワード欄と SSO ボタンを残す (回復導線を消さない)
 */

const fetchMock = vi.fn();

function stubPasskeySupport(): void {
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
    ).isUserVerifyingPlatformAuthenticatorAvailable = () => Promise.resolve(true);
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

beforeEach(() => {
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    removePasskeySupport();
    fetchMock.mockReset();
});

describe("Auth/Login パスキー導線", () => {
    it("非対応ブラウザではパスキーボタンを出さない", () => {
        removePasskeySupport();
        render(Login, { props: { appName: "My App", socialProviders: [] } });

        expect(screen.queryByTestId("passkey-login-button")).toBeNull();
    });

    it("対応ブラウザではボタンと 2FA の但し書きを出す", () => {
        stubPasskeySupport();
        render(Login, { props: { appName: "My App", socialProviders: [] } });

        const button = screen.getByTestId("passkey-login-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
        expect(
            screen.getByText(/2要素認証を有効にしているアカウントでは、パスキーでログインできません。/),
        ).toBeInTheDocument();
    });

    it("失敗してもパスワード欄と SSO ボタンを残す (回復導線を消さない)", async () => {
        stubPasskeySupport();
        fetchMock.mockResolvedValue({ ok: false, status: 500, json: () => Promise.resolve({}) });

        render(Login, { props: { appName: "My App", socialProviders: ["google"] } });

        await fireEvent.click(screen.getByTestId("passkey-login-button"));

        await waitFor(() => {
            expect(screen.getByTestId("passkey-login-error")).toBeInTheDocument();
        });
        expect(screen.getByLabelText("パスワード")).toBeInTheDocument();
        expect(screen.getByTestId("sso-login-google")).toBeInTheDocument();
    });
});
