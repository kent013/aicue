import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";

/*
 * 再認証が成立しないユーザーの回復導線 (施策 5)。
 *
 * 全画面 confirm とインラインモーダルの**唯一の実装**であることが要点。
 * `/forgot-password` は Fortify が guest middleware 付きで登録しており、ログイン済みの
 * 本 UI 利用者はフォームに到達できない = 踏破不能 CTA (監査 F-2a)。
 * 踏破できる回復手順は「ログアウトしてから guest としてリセット」だけ。
 */

const { routerPostMock } = vi.hoisted(() => ({ routerPostMock: vi.fn() }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, visit: vi.fn(), reload: vi.fn() },
}));

import RecentAuthRecoveryNotice from "@/components/molecules/RecentAuthRecoveryNotice.svelte";

const linkHrefs = (): string[] =>
    screen.queryAllByRole("link").map((a) => (a as HTMLAnchorElement).getAttribute("href") ?? "");

beforeEach(() => {
    routerPostMock.mockReset();
});

afterEach(() => {
    cleanup();
});

describe("RecentAuthRecoveryNotice", () => {
    it("variant=no-satisfier は手段なしの理由とログアウト導線を出す", () => {
        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });

        expect(screen.getByTestId("recent-auth-recovery")).toBeInTheDocument();
        expect(screen.getByTestId("recent-auth-recovery")).toHaveTextContent(
            "再認証手段が設定されていません",
        );
        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
    });

    it("variant=not-executable-here はこの端末で実行できない理由とログアウト導線を出す", () => {
        render(RecentAuthRecoveryNotice, { props: { variant: "not-executable-here" } });

        expect(screen.getByTestId("recent-auth-unsupported-here")).toBeInTheDocument();
        expect(screen.getByTestId("recent-auth-unsupported-here")).toHaveTextContent(
            "このブラウザはパスキーに対応していません",
        );
        expect(screen.getByRole("button", { name: "ログアウトする" })).toBeInTheDocument();
    });

    it("踏破不能な /forgot-password へリンクしない (両 variant)", () => {
        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });
        expect(linkHrefs()).not.toContain("/forgot-password");
        cleanup();

        render(RecentAuthRecoveryNotice, { props: { variant: "not-executable-here" } });
        expect(linkHrefs()).not.toContain("/forgot-password");
    });

    it("ログアウトは Inertia visit (router.post) で 1 回だけ送る", async () => {
        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });

        await fireEvent.click(screen.getByRole("button", { name: "ログアウトする" }));

        expect(routerPostMock).toHaveBeenCalledTimes(1);
        expect(routerPostMock).toHaveBeenCalledWith("/logout", {}, expect.anything());
    });

    it("送信中の連打では 2 回目を送らない (二重送信ガード)", async () => {
        // onStart を呼ぶだけで onFinish を呼ばない = 送信中のまま
        routerPostMock.mockImplementation(
            (_url: string, _data: unknown, options: { onStart?: () => void }) => {
                options.onStart?.();
            },
        );
        render(RecentAuthRecoveryNotice, { props: { variant: "no-satisfier" } });

        const button = screen.getByRole("button", { name: "ログアウトする" });
        await fireEvent.click(button);
        await fireEvent.click(button);

        expect(routerPostMock).toHaveBeenCalledTimes(1);
    });
});
