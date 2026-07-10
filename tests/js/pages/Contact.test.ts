import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import ContactIndex from "@/pages/Contact/Index.svelte";
import ContactThanks from "@/pages/Contact/Thanks.svelte";

const baseProps = {
    appName: "My App",
    types: [
        { value: "general", label: "一般的なお問い合わせ" },
        { value: "other", label: "その他" },
    ],
    source: null,
    recaptchaSiteKey: "",
    termsUrl: "/terms",
    privacyUrl: "/privacy",
};

describe("Contact/Index", () => {
    it("問い合わせフォーム (種別 / 氏名 / email / 会社名 / 内容 / 同意) を描画する", () => {
        render(ContactIndex, { props: baseProps });

        expect(screen.getByRole("heading", { name: "お問い合わせ" })).toBeInTheDocument();
        expect(screen.getByLabelText(/お問い合わせ種別/)).toBeInTheDocument();
        expect(screen.getByLabelText(/お名前/)).toBeInTheDocument();
        expect(screen.getByLabelText(/メールアドレス/)).toBeInTheDocument();
        expect(screen.getByLabelText(/会社・組織名/)).toBeInTheDocument();
        expect(screen.getByLabelText(/お問い合わせ内容/)).toBeInTheDocument();
        expect(screen.getByTestId("contact-consent")).toBeInTheDocument();
    });

    it("honeypot は不可視 (aria-hidden) で存在し、tabindex=-1 でフォーカスされない", () => {
        render(ContactIndex, { props: baseProps });

        const honeypot = screen.getByTestId("contact-honeypot");
        expect(honeypot).toBeInTheDocument();
        expect(honeypot.getAttribute("tabindex")).toBe("-1");
        expect(honeypot.closest('[aria-hidden="true"]')).not.toBeNull();
    });

    it("送信ボタンは未入力・同意未チェックでも disabled にしない (DESIGN.md §Do's and Don'ts)", () => {
        render(ContactIndex, { props: baseProps });

        const submit = screen.getByRole("button", { name: "送信する" });
        expect(submit.hasAttribute("disabled")).toBe(false);
    });
});

describe("Contact/Thanks", () => {
    it("受付完了メッセージとトップへの導線を描画する", () => {
        render(ContactThanks, { props: { appName: "My App" } });

        expect(screen.getByTestId("contact-thanks")).toBeInTheDocument();
        expect(
            screen.getByRole("heading", { name: "お問い合わせを受け付けました" }),
        ).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "トップへ戻る" })).toBeInTheDocument();
    });
});
