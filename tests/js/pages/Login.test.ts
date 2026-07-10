import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Login from "@/pages/Auth/Login.svelte";

describe("Auth/Login", () => {
    it("ログインフォーム (email / password / remember / submit) を描画する", () => {
        render(Login, { props: { appName: "My App", socialProviders: [] } });

        expect(screen.getByRole("heading", { name: "ログイン" })).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByLabelText("パスワード")).toBeInTheDocument();
        expect(screen.getByLabelText("ログイン状態を保持")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "ログイン" })).toBeInTheDocument();
    });

    it("SSO ボタンは GET anchor (<a href>) で SSO 開始ルートを指す (form POST にしない)", () => {
        render(Login, { props: { appName: "My App", socialProviders: ["google"] } });

        const link = screen.getByTestId("sso-login-google");
        expect(link.tagName).toBe("A");
        expect(link.getAttribute("href")).toBe("/auth/google/redirect/login");
        // anchor なので form submit (POST) ではない
        expect(link.closest("form")).toBeNull();
    });

    it("register / forgot-password への導線を表示する", () => {
        render(Login, { props: { appName: "My App", socialProviders: [] } });

        // Inertia Link は href を絶対 URL に正規化するため pathname で比較する
        const registerLink = screen.getByRole("link", { name: "登録" }) as HTMLAnchorElement;
        const forgotLink = screen.getByRole("link", {
            name: "パスワードをお忘れの方",
        }) as HTMLAnchorElement;
        expect(new URL(registerLink.href).pathname).toBe("/register");
        expect(new URL(forgotLink.href).pathname).toBe("/forgot-password");
    });
});
