import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/svelte";
import VerifyEmail from "@/pages/Auth/VerifyEmail.svelte";

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { post: vi.fn(), visit: vi.fn() },
}));

/*
 * メール認証待ち画面。
 *
 * この画面から `onboarding.checkout` へ**進む**導線は出さない (bug-hunt F-2-01):
 * route は ['auth','verified'] 配下にあり未認証は必ず差し戻されるため、
 * 表示条件 (membership) と踏破条件 (verified) が食い違う恒常的に無効な CTA になる。
 * サーバが渡すのは URL ではなく「認証完了後に checkout へ着地するか」(continuesToCheckout)
 * だけで、画面はそれを**予告文**として出す。
 */

/** この画面に出てよいボタンの全集合 (ここに無いボタンが増えたら落とす)。 */
const ALLOWED_BUTTONS = ["認証メールを再送信", "ログアウト"];

const renderedButtonLabels = (): string[] =>
    screen.getAllByRole("button").map((b) => b.textContent?.trim() ?? "");

describe("Auth/VerifyEmail", () => {
    it("continuesToCheckout=true のとき認証後にプラン選択へ進む予告を出す", () => {
        render(VerifyEmail, { props: { appName: "My App", continuesToCheckout: true } });

        expect(screen.getByTestId("verify-email-checkout-note")).toBeInTheDocument();
    });

    it("continuesToCheckout=false のとき予告文を出さない (継続の無いユーザーに嘘をつかない)", () => {
        render(VerifyEmail, { props: { appName: "My App", continuesToCheckout: false } });

        expect(screen.queryByTestId("verify-email-checkout-note")).toBeNull();
    });

    it.each([true, false])(
        "continuesToCheckout=%s でも操作は再送信 / ログアウトの 2 つだけでリンクを出さない",
        (continuesToCheckout) => {
            render(VerifyEmail, { props: { appName: "My App", continuesToCheckout } });

            // 許可された 2 ボタンのラベルにのみ依存する (禁止したい CTA の testId / 文言には
            // 一切依存しない) ため、別実装の踏破不能 CTA が再混入しても検出できる。
            expect(renderedButtonLabels()).toEqual(ALLOWED_BUTTONS);
            expect(screen.queryAllByRole("link")).toHaveLength(0);
            // 旧実装 (「あとで認証する（プラン選択へ進む）」) の直接的な回帰ガード
            expect(screen.queryByTestId("verify-email-continue")).toBeNull();
        },
    );

    it("描画されるボタンは disabled にしない (DESIGN.md)", () => {
        const { container } = render(VerifyEmail, {
            props: { appName: "My App", continuesToCheckout: true },
        });

        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
    });
});
