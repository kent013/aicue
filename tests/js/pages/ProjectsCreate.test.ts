import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

/*
 * プロジェクト作成フォームの stale-invalid (T157 / bug-hunt F-1-01)。
 *
 * 固定する契約: 必須エラーが出た後に入力すると、そのフィールドのエラーが**その場で消える**。
 * 消し方は既存 9 箇所と同じ `form.clearErrors(field)` = **値そのものを消す**ので、
 * 同じ文言が次の応答で再設定されれば必ず再表示される (再表示の契機を別に作らない)。
 *
 * useForm は reactiveUseForm (errors が $state) に差し替える。
 * plain object だと clearErrors の削除が再描画に繋がらず、契約 0 を観測できない。
 */

let form: ReturnType<typeof reactiveUseForm<{ name: string; description: string }>>;

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    page: { props: { appName: "AI-CUE" } },
    useForm: () => form,
}));

const { default: ProjectsCreate } = await import("@/pages/Projects/Create.svelte");

function renderWithErrors(errors: Record<string, string>): void {
    form = reactiveUseForm({ name: "", description: "" }, errors);
    render(ProjectsCreate);
}

afterEach(() => {
    cleanup();
});

describe("Projects/Create の stale-invalid (T157)", () => {
    it("契約 0: エラー表示中に入力すると、その文言が DOM から消える", async () => {
        renderWithErrors({ name: "プロジェクト名は必須項目です。" });
        expect(screen.getByText("プロジェクト名は必須項目です。")).toBeInTheDocument();

        await fireEvent.input(screen.getByLabelText(/プロジェクト名/), {
            target: { value: "現場A" },
        });

        expect(screen.queryByText("プロジェクト名は必須項目です。")).toBeNull();
    });

    it("契約 1: name の入力で clearErrors が 'name' 引数つきで呼ばれる", async () => {
        renderWithErrors({ name: "プロジェクト名は必須項目です。" });

        await fireEvent.input(screen.getByLabelText(/プロジェクト名/), {
            target: { value: "現場A" },
        });

        expect(form.clearErrors).toHaveBeenCalledWith("name");
    });

    it("契約 2: description の入力で clearErrors が 'description' 引数つきで呼ばれる", async () => {
        renderWithErrors({ description: "説明が長すぎます。" });

        await fireEvent.input(screen.getByLabelText(/説明/), {
            target: { value: "説明文" },
        });

        expect(form.clearErrors).toHaveBeenCalledWith("description");
    });

    it("契約 3: name の入力は description のエラーを消さない (引数なし clearErrors を使わない)", async () => {
        renderWithErrors({
            name: "プロジェクト名は必須項目です。",
            description: "説明が長すぎます。",
        });

        await fireEvent.input(screen.getByLabelText(/プロジェクト名/), {
            target: { value: "現場A" },
        });

        expect(form.clearErrors).toHaveBeenCalledWith("name");
        expect(form.clearErrors).not.toHaveBeenCalledWith();
        expect(form.clearErrors).not.toHaveBeenCalledWith("description");
        // 表示も残っている
        expect(screen.getByText("説明が長すぎます。")).toBeInTheDocument();
    });

    it("契約 4: エラーが無いときは clearErrors を呼ばない", async () => {
        renderWithErrors({});

        await fireEvent.input(screen.getByLabelText(/プロジェクト名/), {
            target: { value: "現場A" },
        });

        expect(form.clearErrors).not.toHaveBeenCalled();
    });
});
