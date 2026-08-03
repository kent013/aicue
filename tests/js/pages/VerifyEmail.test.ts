import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/svelte";
import VerifyEmail from "@/pages/Auth/VerifyEmail.svelte";

const { routerVisitMock } = vi.hoisted(() => ({ routerVisitMock: vi.fn() }));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { visit: routerVisitMock, post: vi.fn() },
}));

/*
 * メール認証待ち画面 (ソフトゲート)。
 * continueUrl は「登録由来の継続導線が実在するとき」だけサーバが非 null で渡す。
 * null のときに二次 CTA を出さない (= 継続先の無いボタンを出さない) ことを固定する。
 */
describe("Auth/VerifyEmail", () => {
    it("continueUrl が null なら二次 CTA を出さない", () => {
        render(VerifyEmail, { props: { appName: "My App" } });

        expect(screen.queryByTestId("verify-email-continue")).toBeNull();
        expect(screen.getByRole("button", { name: "認証メールを再送信" })).toBeInTheDocument();
    });

    it("continueUrl があるとき「あとで認証する」CTA を出す", () => {
        render(VerifyEmail, {
            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
        });

        expect(screen.getByTestId("verify-email-continue")).toBeInTheDocument();
    });

    it("CTA は disabled にせず押下可能 (DESIGN.md)", () => {
        const { container } = render(VerifyEmail, {
            props: { appName: "My App", continueUrl: "/onboarding/checkout" },
        });

        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
    });
});
