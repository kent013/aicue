import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";

/*
 * RecentAuthModal の props 契約 (施策 1)。
 *
 * `/recent-auth/status` の応答を **1 個の型 (status)** で受ける。field へ分解して手渡す形は
 * field が増えるたびに配線漏れを生む (T106 で passkeyAvailable を足した際、6 呼び出し中
 * 5 箇所が未配線のまま出荷され passkey-only ユーザーが 5 画面で詰んだ = 監査 F-1)。
 *
 * `status === null` は「状態不明」であり、空表示にも事実に反する文言にも倒さない。
 */

const { routerReloadMock, confirmWithPasskeyMock } = vi.hoisted(() => ({
    routerReloadMock: vi.fn(),
    confirmWithPasskeyMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: vi.fn(), visit: vi.fn(), reload: routerReloadMock },
}));

vi.mock("@/lib/passkeys", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@/lib/passkeys")>()),
    confirmWithPasskey: confirmWithPasskeyMock,
}));

import RecentAuthModal from "@/components/organisms/RecentAuthModal.svelte";
import type { RecentAuthStatus } from "@/lib/recent-auth";

function status(overrides: Partial<RecentAuthStatus> = {}): RecentAuthStatus {
    return {
        recent: false,
        passwordSet: false,
        availableProviders: [],
        passkeyAvailable: false,
        canSatisfy: false,
        confirmedAt: null,
        ...overrides,
    };
}

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
    routerReloadMock.mockReset();
    confirmWithPasskeyMock.mockReset();
});

afterEach(() => {
    cleanup();
    removePasskeySupport();
});

describe("RecentAuthModal props 契約", () => {
    it("status.passkeyAvailable=true + 対応ブラウザでパスキー導線を出す", async () => {
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passkeyAvailable: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        expect(await screen.findByTestId("recent-auth-passkey")).toBeInTheDocument();
    });

    it("status.passwordSet=true でパスワード再入力フォームを出す", async () => {
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passwordSet: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        expect(await screen.findByTestId("recent-auth-password-input")).toBeInTheDocument();
    });

    it("passkey のみ + 非対応ブラウザなら回復導線を出す (無言の行き止まりにしない)", async () => {
        removePasskeySupport();
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passkeyAvailable: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        expect(await screen.findByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
    });

    it("canSatisfy=false なら手段なしの回復導線を出す", async () => {
        render(RecentAuthModal, {
            props: { open: true, status: status({ canSatisfy: false }), onConfirmed: vi.fn() },
        });

        expect(await screen.findByTestId("recent-auth-recovery")).toBeInTheDocument();
    });

    it("回復導線は踏破不能な /forgot-password へリンクしない", async () => {
        render(RecentAuthModal, {
            props: { open: true, status: status({ canSatisfy: false }), onConfirmed: vi.fn() },
        });

        await screen.findByTestId("recent-auth-recovery");
        const hrefs = screen
            .queryAllByRole("link")
            .map((a) => (a as HTMLAnchorElement).getAttribute("href") ?? "");
        expect(hrefs).not.toContain("/forgot-password");
    });
});

describe("RecentAuthModal status=null (状態不明)", () => {
    it("取得失敗として明示し再読み込み導線を出す (空表示にしない)", async () => {
        render(RecentAuthModal, {
            props: { open: true, status: null, onConfirmed: vi.fn() },
        });

        expect(await screen.findByTestId("recent-auth-unknown")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "再読み込み" })).toBeInTheDocument();
    });

    it("password フォーム / SSO / パスキー / 回復 notice のいずれも出さない (誤った導線に倒さない)", async () => {
        render(RecentAuthModal, {
            props: { open: true, status: null, onConfirmed: vi.fn() },
        });

        await screen.findByTestId("recent-auth-unknown");
        expect(screen.queryByTestId("recent-auth-password-input")).toBeNull();
        expect(screen.queryByTestId("recent-auth-passkey")).toBeNull();
        expect(screen.queryByTestId("recent-auth-recovery")).toBeNull();
        expect(screen.queryByTestId("recent-auth-unsupported-here")).toBeNull();
    });
});

describe("RecentAuthModal エラー提示の分離 (施策 9)", () => {
    /*
     * password エラーと passkey ceremony エラーが同一 state を共有していたため、
     * **パスキー失敗が「現在のパスワード」欄のフィールドエラーとして表示**されていた
     * (原因と提示先が食い違う)。非フィールド起因は Alert に分離する。
     */
    it("passkey ceremony 失敗は Alert に出て、password フィールドを invalid にしない", async () => {
        confirmWithPasskeyMock.mockResolvedValue({ status: "failed", message: "ceremony に失敗" });
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passwordSet: true, passkeyAvailable: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        await fireEvent.click(await screen.findByTestId("recent-auth-passkey"));

        const alert = await screen.findByTestId("recent-auth-passkey-error");
        expect(alert).toHaveTextContent("ceremony に失敗");
        expect(screen.getByTestId("recent-auth-password-input")).not.toHaveAttribute(
            "aria-invalid",
            "true",
        );
    });

    it("パスワード誤りは FormField に出て、passkey の Alert を出さない", async () => {
        const fetchMock = vi.fn(() =>
            Promise.resolve({
                status: 422,
                json: () => Promise.resolve({ errors: { password: ["パスワードが正しくありません。"] } }),
            }),
        );
        vi.stubGlobal("fetch", fetchMock);
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passwordSet: true, passkeyAvailable: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        await fireEvent.input(await screen.findByTestId("recent-auth-password-input"), {
            target: { value: "wrong-password" },
        });
        await fireEvent.submit(
            screen.getByTestId("recent-auth-submit").closest("form") as HTMLFormElement,
        );

        await waitFor(() =>
            expect(screen.getByText("パスワードが正しくありません。")).toBeInTheDocument(),
        );
        expect(screen.queryByTestId("recent-auth-passkey-error")).toBeNull();
        vi.unstubAllGlobals();
    });

    it("キャンセルは Alert を出さない (騒がない)", async () => {
        confirmWithPasskeyMock.mockResolvedValue({ status: "cancelled" });
        render(RecentAuthModal, {
            props: {
                open: true,
                status: status({ passkeyAvailable: true, canSatisfy: true }),
                onConfirmed: vi.fn(),
            },
        });

        await fireEvent.click(await screen.findByTestId("recent-auth-passkey"));

        await waitFor(() => expect(confirmWithPasskeyMock).toHaveBeenCalled());
        expect(screen.queryByTestId("recent-auth-passkey-error")).toBeNull();
    });
});
