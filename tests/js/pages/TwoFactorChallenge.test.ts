import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
import TwoFactorChallenge from "@/pages/Auth/TwoFactorChallenge.svelte";

/*
 * 2要素認証チャレンジ画面。
 *
 * 認証コードもリカバリコードも手元に無いユーザーは、このままでは画面から抜けられない
 * (bug-hunt F-2-02 と同種の欠落)。チャレンジ中はまだ未ログイン (Fortify の login.id
 * セッション状態) なので `guest` middleware 配下の /login へ到達でき、そこが唯一の
 * 実際に踏破できる離脱先になる。
 */
const baseProps = { appName: "My App" };

/** Inertia Link は href を絶対 URL に正規化するため pathname で比較する。 */
const linkPathnames = (): string[] =>
    screen.getAllByRole("link").map((a) => new URL((a as HTMLAnchorElement).href).pathname);

/** タブ見出しと入力ラベルが同名のため、入力は tabpanel 内にスコープして探す。 */
const panelInput = (label: string): HTMLElement =>
    within(screen.getByRole("tabpanel")).getByLabelText(label);

describe("Auth/TwoFactorChallenge", () => {
    it("既定では認証コード入力を描画する", () => {
        render(TwoFactorChallenge, { props: baseProps });

        expect(screen.getByRole("heading", { name: "2要素認証" })).toBeInTheDocument();
        expect(panelInput("認証コード")).toBeInTheDocument();
        expect(screen.getByRole("button", { name: "認証する" })).toBeInTheDocument();
    });

    it("リカバリコードタブへ切り替えると入力が入れ替わる", async () => {
        render(TwoFactorChallenge, { props: baseProps });

        await fireEvent.click(screen.getByRole("tab", { name: "リカバリコード" }));

        expect(panelInput("リカバリコード")).toBeInTheDocument();
        expect(
            within(screen.getByRole("tabpanel")).queryByLabelText("認証コード"),
        ).toBeNull();
    });

    it("/login への離脱導線を描画する (どちらのコードも使えないユーザーの出口)", () => {
        render(TwoFactorChallenge, { props: baseProps });

        expect(linkPathnames()).toContain("/login");
    });
});
