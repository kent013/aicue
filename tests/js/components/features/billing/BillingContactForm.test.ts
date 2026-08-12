import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { tick } from "svelte";

/*
 * 請求先情報フォームの stale-invalid (T157 / bug-hunt F-3-02)。
 *
 * このフォームのエラーは `useForm` ではなく **page props の errors** から来る (値を消せない)。
 * そこで「編集されたら隠す」フラグを持ち、**このフォーム自身の router.patch の結果**
 * (onError / onSuccess) で解除する。
 *
 * 解除契機を page props のオブジェクト同一性や router の finish にしないのが要点で、
 * どちらも**無関係な visit で古いエラーを復活させる** (設計の棄却理由)。
 * さらに「送信中にさらに編集された」フィールドは解除しない (編集世代)。
 *
 * callback 順序: 入力 → submit → onStart → (送信中の追加入力) → onError | onSuccess → onFinish
 */

const { patchMock, pageStore } = vi.hoisted(() => {
    const patchMock = vi.fn();
    const pageStore = { props: { errors: {} as Record<string, string> } };

    return { patchMock, pageStore };
});

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    page: pageStore,
    router: { patch: patchMock },
}));

const { default: BillingContactForm } = await import(
    "@/components/features/billing/BillingContactForm.svelte"
);

const EMAIL_ERROR = "請求先メールアドレスの形式が正しくありません。";
const NAME_ERROR = "宛名が長すぎます。";

function renderForm(errors: Record<string, string> = {}): void {
    pageStore.props.errors = errors;
    render(BillingContactForm, {
        props: {
            billingContact: { email: "old@example.test", name: "旧宛名", fallbackEmail: null },
            updateUrl: "/billing/contact",
            canManage: true,
        },
    });
}

/** 直近の router.patch に渡された options (callback を任意順で呼ぶため) */
function lastPatchOptions(): {
    onStart?: () => void;
    onError?: () => void;
    onSuccess?: () => void;
    onFinish?: () => void;
} {
    const call = patchMock.mock.calls.at(-1);
    expect(call).toBeDefined();

    return (call as unknown[])[2] as ReturnType<typeof lastPatchOptions>;
}

async function submitForm(): Promise<void> {
    await fireEvent.submit(screen.getByTestId("billing-contact-form"));
}

afterEach(() => {
    cleanup();
    patchMock.mockReset();
    pageStore.props.errors = {};
});

describe("BillingContactForm の stale-invalid (T157)", () => {
    it("契約 5: page props のエラーは表示される (現状維持)", () => {
        renderForm({ billing_contact_email: EMAIL_ERROR });

        expect(screen.getByText(EMAIL_ERROR)).toBeInTheDocument();
    });

    it("契約 6: email に入力するとエラー表示が消える", async () => {
        renderForm({ billing_contact_email: EMAIL_ERROR });

        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "new@example.test" },
        });

        expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
    });

    it("契約 7: email の入力では name のエラーは消えない (フィールド独立)", async () => {
        renderForm({ billing_contact_email: EMAIL_ERROR, billing_contact_name: NAME_ERROR });

        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "new@example.test" },
        });

        expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
        expect(screen.getByText(NAME_ERROR)).toBeInTheDocument();
    });

    it.each(["onError", "onSuccess"] as const)(
        "契約 8/9: 送信して %s が返ると、同じ文言でもエラーが再表示される",
        async (callbackName) => {
            renderForm({ billing_contact_email: EMAIL_ERROR });

            await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
                target: { value: "new@example.test" },
            });
            expect(screen.queryByText(EMAIL_ERROR)).toBeNull();

            await submitForm();
            const options = lastPatchOptions();
            options.onStart?.();
            // page props のエラーは据え置く (実運用では成功応答で消えるが、
            // 「抑制が解けたか」を観測するための約束事)
            options[callbackName]?.();
            options.onFinish?.();
            await tick(); // callback を直接呼んだので DOM 反映を待つ

            expect(screen.getByText(EMAIL_ERROR)).toBeInTheDocument();
        },
    );

    it("契約 10: onFinish だけでは再表示されない (キャンセル・通信失敗で復活させない)", async () => {
        renderForm({ billing_contact_email: EMAIL_ERROR });

        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "new@example.test" },
        });

        await submitForm();
        const options = lastPatchOptions();
        options.onStart?.();
        options.onFinish?.(); // onError / onSuccess は呼ばれない
        await tick();

        expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
    });

    it.each(["onError", "onSuccess"] as const)(
        "契約 11: 送信中にさらに編集したフィールドは %s でも解除されない (編集世代)",
        async (callbackName) => {
            renderForm({ billing_contact_email: EMAIL_ERROR });

            await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
                target: { value: "new@example.test" },
            });
            await submitForm();
            const options = lastPatchOptions();
            options.onStart?.();

            // 応答が返る前にさらに編集する (= 返ってくるのは古い値への検証結果)
            await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
                target: { value: "newer@example.test" },
            });

            options[callbackName]?.();
            options.onFinish?.();
            await tick();

            expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
        },
    );

    it("契約 12: 送信中に email だけ編集した場合、name の抑制は解除される", async () => {
        renderForm({ billing_contact_email: EMAIL_ERROR, billing_contact_name: NAME_ERROR });

        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "new@example.test" },
        });
        await fireEvent.input(screen.getByTestId("billing-contact-name-input"), {
            target: { value: "新宛名" },
        });
        expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
        expect(screen.queryByText(NAME_ERROR)).toBeNull();

        await submitForm();
        const options = lastPatchOptions();
        options.onStart?.();
        // 送信中に email だけ編集する
        await fireEvent.input(screen.getByTestId("billing-contact-email-input"), {
            target: { value: "newest@example.test" },
        });
        options.onError?.();
        options.onFinish?.();
        await tick();

        // email は編集世代が動いたので抑制継続、name は動いていないので解除される
        expect(screen.queryByText(EMAIL_ERROR)).toBeNull();
        expect(screen.getByText(NAME_ERROR)).toBeInTheDocument();
    });
});
