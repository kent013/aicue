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
