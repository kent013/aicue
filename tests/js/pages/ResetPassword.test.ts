import { describe, expect, it, vi } from "vitest";
import { render, screen, waitFor } from "@testing-library/svelte";
import { reactiveUseForm } from "../support/reactiveUseForm.svelte";

/*
 * パスワードリセット画面。
 *
 * 期限切れ・使用済みリンクを踏むと errors.email が出るだけの「同じエラーが出続ける行き止まり」に
 * なりうる (bug-hunt F-2-02)。**エラーの有無にかかわらず**別の入口へ抜けられる導線
 * (/forgot-password で新しいリンクを取り直す / /login へ戻る) を footer に出すことを固定する。
 * どちらも guest 状態のこのユーザーが実際に踏破できる先である。
 *
 * トークン無効時の errors 反映は reactiveUseForm フェイクで模倣する (サーバ応答を待たない)。
 */
const { holder } = vi.hoisted(() => ({
    holder: { form: null as ReturnType<typeof reactiveUseForm> | null },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    useForm: (init: Record<string, unknown>) => {
        const form = reactiveUseForm(init);
        holder.form = form;
        return form;
    },
}));

import ResetPassword from "@/pages/Auth/ResetPassword.svelte";

const baseProps = { appName: "My App", token: "tok-123", email: "user@example.com" };

/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
const linkPathnames = (): string[] =>
    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);

describe("Auth/ResetPassword", () => {
    it("リセットフォーム (メールアドレス / 新しいパスワード / 送信ボタン) を描画する", () => {
        render(ResetPassword, { props: baseProps });

        expect(screen.getByRole("heading", { name: "パスワードリセット" })).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByLabelText("新しいパスワード")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "パスワードをリセット" })).toBeInTheDocument();
    });

    it("/forgot-password と /login への離脱導線を描画する", () => {
        render(ResetPassword, { props: baseProps });

        expect(linkPathnames()).toEqual(expect.arrayContaining(["/forgot-password", "/login"]));
    });

    it("トークン無効のエラーが出ても離脱導線が消えない (行き止まりにしない)", async () => {
        render(ResetPassword, { props: baseProps });

        holder.form?.respondWithErrors({
            email: "このパスワードリセットトークンは無効です。",
        });

        await waitFor(() => {
            expect(
                screen.getByText("このパスワードリセットトークンは無効です。"),
            ).toBeInTheDocument();
        });
        expect(linkPathnames()).toEqual(expect.arrayContaining(["/forgot-password", "/login"]));
    });
});
