import { describe, it, expect, beforeEach } from "vitest";
import { render, cleanup, screen } from "@testing-library/svelte";
import EmailVerificationBanner from "@/components/features/auth/EmailVerificationBanner.svelte";

// 未認証ソフトゲートのバナー。warning Alert + title + 再送ボタン + 変更リンクを描画する。

describe("EmailVerificationBanner", () => {
    beforeEach(() => cleanup());

    it("warning トーン (border-warning) の Alert を未認証タイトルで描画する", () => {
        render(EmailVerificationBanner);

        const banner = screen.getByTestId("email-verification-banner");
        expect(banner).not.toBeNull();
        // DESIGN.md §Alert: warning は border-warning (token 迂回しない)
        expect(banner.className).toContain("border-warning");
        expect(screen.getByText("メールアドレスが未認証です")).not.toBeNull();
    });

    it("認証メール再送ボタンを描画する", () => {
        render(EmailVerificationBanner);
        expect(screen.getByTestId("email-verification-banner-resend")).not.toBeNull();
    });

    it("/settings を指すメールアドレス変更リンクを描画する", () => {
        render(EmailVerificationBanner);

        const link = screen.getByText("メールアドレスを変更");
        expect(link.tagName).toBe("A");
        // Inertia Link は jsdom 上で href を絶対 URL に正規化するため pathname で検証する
        expect(new URL(link.getAttribute("href") ?? "", "http://localhost").pathname).toBe(
            "/settings",
        );
    });

    it("testId を Alert と再送ボタンへ伝播する", () => {
        render(EmailVerificationBanner, { props: { testId: "banner-x" } });
        expect(screen.getByTestId("banner-x")).not.toBeNull();
        expect(screen.getByTestId("banner-x-resend")).not.toBeNull();
    });
});
