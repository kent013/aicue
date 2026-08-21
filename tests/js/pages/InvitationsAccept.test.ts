import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";

/*
 * 招待受諾画面の宛先 email 照合分岐 (F-2-02)。
 *
 * recipientEmailMatches prop で表示を切り替える:
 *  - true:  受諾ボタン (accept-invitation-button) を出し、description に組織名を含める
 *  - false: 受諾ボタン/フォームを出さず、案内文 (accept-invitation-mismatch) を出し、
 *           description は「別のメールアドレス宛」で組織名を含めない (DOM 表示契約)
 *
 * ここが担保するのは **DOM 表示** の分岐のみ。payload 層の非開示 (不一致時は organizationName を
 * サーバが null で渡す) は Feature テスト側 (InvitationTest T3) が担保する (責務分離)。
 */

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    page: { props: { appName: "AI-CUE" } },
    useForm: () => ({ token: "", processing: false, post: vi.fn() }),
}));

const { default: InvitationsAccept } = await import("@/pages/Invitations/Accept.svelte");

const ORG_NAME = "秘匿対象組織";

afterEach(() => {
    cleanup();
});

describe("Invitations/Accept の宛先 email 照合", () => {
    it("一致時: 受諾ボタンを表示し、不一致案内は出さず、description に組織名を含む", () => {
        render(InvitationsAccept, {
            props: { organizationName: ORG_NAME, token: "tok", recipientEmailMatches: true },
        });

        expect(screen.getByTestId("accept-invitation-button")).toBeInTheDocument();
        expect(screen.queryByTestId("accept-invitation-mismatch")).toBeNull();
        expect(screen.getByText(new RegExp(ORG_NAME))).toBeInTheDocument();
    });

    it("不一致時: 受諾ボタン/フォームを出さず案内文を表示し、description に組織名を含まない", () => {
        // サーバは不一致時 organizationName を null で渡す (payload から組織名を落とす)
        render(InvitationsAccept, {
            props: { organizationName: null, token: "tok", recipientEmailMatches: false },
        });

        expect(screen.queryByTestId("accept-invitation-button")).toBeNull();
        expect(screen.getByTestId("accept-invitation-mismatch")).toBeInTheDocument();
        expect(screen.getByText("この招待は別のメールアドレス宛に送信されています。")).toBeInTheDocument();
        // 非受信者への開示面を増やさない: 組織名を画面に出さない
        expect(screen.queryByText(new RegExp(ORG_NAME))).toBeNull();
    });
});
