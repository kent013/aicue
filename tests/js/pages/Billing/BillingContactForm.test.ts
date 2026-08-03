import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import BillingContactForm from "@/components/features/billing/BillingContactForm.svelte";
import type { BillingContactShape } from "@/types/billing";

const { routerPatchMock, pageState } = vi.hoisted(() => ({
    routerPatchMock: vi.fn(),
    pageState: { props: {} as Record<string, unknown> },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { patch: routerPatchMock },
    page: pageState,
}));

/*
 * P9: 請求先情報フォーム。
 * - 未入力でも submit は disabled にしない (AGENTS.md 禁止事項 #8)。押下でサーバ文言を出す。
 * - 未設定時は「実際の宛先は owner email」であることをサーバ確定値で示す。
 */

const baseContact: BillingContactShape = {
    email: null,
    name: null,
    fallbackEmail: "owner@example.test",
};

afterEach(() => {
    cleanup();
    routerPatchMock.mockReset();
    pageState.props = {};
});

describe("BillingContactForm", () => {
    it("未入力でも submit は enabled のまま押下でき、PATCH が飛ぶ", async () => {
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        const submit = screen.getByTestId("billing-contact-submit");
        expect(submit).not.toBeDisabled();

        await fireEvent.click(submit);

        expect(routerPatchMock).toHaveBeenCalledTimes(1);
        const [url, payload] = routerPatchMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe("/billing/contact");
        expect(payload).toEqual({ billing_contact_email: "", billing_contact_name: "" });
    });

    /*
     * F-3-02: native constraint validation に検証を奪われない (DESIGN.md §Do's and Don'ts)。
     * `fireEvent.submit` は submit イベントを直接発火するため native のブロックを素通りする
     * (既存テストがこのバグを見逃した理由)。ここでは **submit ボタンの click** で辿るため
     * jsdom の constraint validation を実際に踏む — 実装前は 3 本目が red になった。
     * ただしブラウザ差は残るので、構造 (`novalidate` 属性) でも併せて固定する。
     * 全 form の網羅は tests/js/architecture/form-novalidate.test.ts が担う。
     */
    it("form は novalidate を持つ (検証はサーバ日本語文言に一本化する)", () => {
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        const form = screen.getByTestId("billing-contact-form") as HTMLFormElement;
        expect(form.noValidate).toBe(true);
    });

    it("email 入力は type=email のまま (モバイルキーボード等の入力補助を落とさない)", () => {
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        expect(screen.getByTestId("billing-contact-email-input")).toHaveAttribute("type", "email");
    });

    it("不正な形式の email でも submit で PATCH が飛ぶ (native にブロックされない)", async () => {
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "not-an-email" },
        });
        await fireEvent.click(screen.getByTestId("billing-contact-submit"));

        expect(routerPatchMock).toHaveBeenCalledTimes(1);
        const [, payload] = routerPatchMock.mock.calls[0] as [string, Record<string, unknown>];
        expect(payload).toEqual({
            billing_contact_email: "not-an-email",
            billing_contact_name: "",
        });
    });

    it("サーバ 422 の errors.billing_contact_email を表示する", () => {
        pageState.props = {
            errors: { billing_contact_email: "請求先メールアドレスは、有効なメールアドレス形式で指定してください。" },
        };
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        expect(
            screen.getByText("請求先メールアドレスは、有効なメールアドレス形式で指定してください。"),
        ).toBeInTheDocument();
    });

    it("未設定のときは owner email が実際の宛先であることを示す", () => {
        render(BillingContactForm, {
            props: { billingContact: baseContact, updateUrl: "/billing/contact", canManage: true },
        });

        expect(screen.getByText(/owner@example\.test/)).toBeInTheDocument();
    });

    it("canManage=false では読み取り専用表示になる (フォームを出さない)", () => {
        render(BillingContactForm, {
            props: {
                billingContact: { email: "billing@example.test", name: "経理部", fallbackEmail: null },
                updateUrl: "/billing/contact",
                canManage: false,
            },
        });

        expect(screen.queryByTestId("billing-contact-form")).toBeNull();
        expect(screen.getByTestId("billing-contact-email-readonly")).toHaveTextContent(
            "billing@example.test",
        );
        expect(screen.getByTestId("billing-contact-name-readonly")).toHaveTextContent("経理部");
    });
});
