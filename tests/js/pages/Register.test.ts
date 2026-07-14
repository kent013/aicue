import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import { fireEvent } from "@testing-library/svelte";
import Register from "@/pages/Auth/Register.svelte";

describe("Auth/Register", () => {
    it("登録フォーム (name / email / password / 利用規約同意) を描画する", () => {
        render(Register, { props: { appName: "My App", socialProviders: [] } });

        expect(screen.getByRole("heading", { name: "アカウント登録" })).toBeInTheDocument();
        expect(screen.getByLabelText("名前")).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByLabelText("パスワード")).toBeInTheDocument();
        expect(screen.getByTestId("terms-checkbox")).toBeInTheDocument();
    });

    it("送信ボタンは未入力・同意未チェックでも disabled にしない (DESIGN.md §Do's and Don'ts)", () => {
        render(Register, { props: { appName: "My App", socialProviders: [] } });

        const submit = screen.getByRole("button", { name: "登録" });
        expect(submit.hasAttribute("disabled")).toBe(false);
    });

    it("SSO 登録は同意未チェックだと遷移を止めてエラーを表示する", async () => {
        render(Register, { props: { appName: "My App", socialProviders: ["google"] } });

        const ssoLink = screen.getByTestId("sso-register-google");
        expect(ssoLink.tagName).toBe("A");
        // 未チェック時は terms_accepted=1 を付けない
        expect(ssoLink.getAttribute("href")).toBe("/auth/google/redirect/register");

        await fireEvent.click(ssoLink);

        expect(screen.getByText("利用規約への同意が必要です。")).toBeInTheDocument();
    });

    it("同意チェック後は SSO リンクに terms_accepted=1 が付き、エラーが消える", async () => {
        render(Register, { props: { appName: "My App", socialProviders: ["google"] } });

        const ssoLink = screen.getByTestId("sso-register-google");
        await fireEvent.click(ssoLink);
        expect(screen.getByText("利用規約への同意が必要です。")).toBeInTheDocument();

        const checkbox = screen.getByTestId("terms-checkbox");
        await fireEvent.click(checkbox);

        expect(ssoLink.getAttribute("href")).toBe(
            "/auth/google/redirect/register?terms_accepted=1",
        );
        expect(screen.queryByText("利用規約への同意が必要です。")).toBeNull();
    });

    it("invitationEmail props あり → email 欄が readonly で招待 email を prefill し補足文言を表示する", () => {
        render(Register, {
            props: {
                appName: "My App",
                socialProviders: [],
                invitationEmail: "invited@example.com",
            },
        });

        const email = screen.getByLabelText("メールアドレス");
        expect(email).toHaveAttribute("readonly");
        expect(email).toHaveValue("invited@example.com");
        expect(screen.getByText("招待されたメールアドレスで登録します。")).toBeInTheDocument();
    });

    it("invitationEmail props なし → email 欄は readonly でなく空 (通常登録)", () => {
        render(Register, { props: { appName: "My App", socialProviders: [] } });

        const email = screen.getByLabelText("メールアドレス");
        expect(email.hasAttribute("readonly")).toBe(false);
        expect(email).toHaveValue("");
        expect(screen.queryByText("招待されたメールアドレスで登録します。")).toBeNull();
    });

    it("invitationEmail = null → email 欄は readonly でなく空 (回帰強化)", () => {
        render(Register, {
            props: { appName: "My App", socialProviders: [], invitationEmail: null },
        });

        const email = screen.getByLabelText("メールアドレス");
        expect(email.hasAttribute("readonly")).toBe(false);
        expect(email).toHaveValue("");
    });
});
