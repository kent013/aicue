import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen } from "@testing-library/svelte";

/*
 * recent-auth step-up の confirm 画面。
 *
 * 2 つの行き止まりを潰す:
 *  (1) step-up を満たせない / 満たしたくないユーザーが操作を中止して抜けられない
 *      → footer に /dashboard への離脱導線 (本画面のユーザーは auth+verified 済みで到達可)。
 *      intended URL へは戻さない (満たさず戻っても middleware が再びここへ送り返すだけ)。
 *  (2) canSatisfy=false の「パスワードを設定して再認証する」→ /forgot-password は
 *      Fortify が `guest` middleware 付きで登録しており、ログイン済みの本画面ユーザーは
 *      フォームに到達できない (F-2-01 と同 species の踏破不能 CTA)。
 *      実際に踏破できる回復手順は「ログアウトしてから guest としてリセットする」だけなので、
 *      CTA はログアウトに差し替える (ラベルは実際の着地と一致させる)。
 */
const { routerPostMock } = vi.hoisted(() => ({ routerPostMock: vi.fn() }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: routerPostMock, visit: vi.fn() },
}));

import ConfirmRecentAuth from "@/pages/Auth/ConfirmRecentAuth.svelte";

/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
const linkPathnames = (): string[] =>
    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);

beforeEach(() => {
    routerPostMock.mockClear();
});

describe("Auth/ConfirmRecentAuth", () => {
    it("passwordSet=true でパスワード再入力フォームと /dashboard への中止導線を出す", () => {
        render(ConfirmRecentAuth, {
            props: { appName: "My App", passwordSet: true, canSatisfy: true },
        });

        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
        expect(linkPathnames()).toContain("/dashboard");
    });

    it("canSatisfy=false でも /dashboard への中止導線を出す", () => {
        render(ConfirmRecentAuth, {
            props: {
                appName: "My App",
                passwordSet: false,
                availableProviders: [],
                canSatisfy: false,
            },
        });

        expect(linkPathnames()).toContain("/dashboard");
    });

    it("canSatisfy=false で /forgot-password へのリンクを出さない (ログイン済みでは踏破不能)", () => {
        render(ConfirmRecentAuth, {
            props: {
                appName: "My App",
                passwordSet: false,
                availableProviders: [],
                canSatisfy: false,
            },
        });

        expect(linkPathnames()).not.toContain("/forgot-password");
        expect(screen.queryByRole("button", { name: "パスワードを設定して再認証する" })).toBeNull();
    });

    it("canSatisfy=false ではログアウトボタンを出し、押下で POST /logout する", async () => {
        render(ConfirmRecentAuth, {
            props: {
                appName: "My App",
                passwordSet: false,
                availableProviders: [],
                canSatisfy: false,
            },
        });

        await fireEvent.click(screen.getByRole("button", { name: "ログアウトする" }));

        expect(routerPostMock).toHaveBeenCalledWith("/logout", {}, expect.anything());
    });
});
