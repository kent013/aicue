import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { router } from "@inertiajs/svelte";
import Settings from "@/pages/Organizations/Settings.svelte";

const baseProps = {
    organization: {
        id: 1,
        name: "テスト組織",
        slug: "test-org",
        isPersonal: false,
        twoFactorRequired: false,
    },
    // オーナー移譲 select 用の最小 shape (id/name)。email / 2FA は Admin/Users へ移設済み
    members: [
        { id: 1, name: "オーナー 太郎" },
        { id: 2, name: "メンバー 花子" },
    ],
    currentUserRole: "organization_owner",
    canManageApiKeys: true,
    usersUrl: "/manage/users",
};

describe("Organizations/Settings", () => {
    it("組織名フォームを描画する", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByRole("heading", { name: "組織設定" })).toBeInTheDocument();
        expect(screen.getByLabelText("組織名")).toBeInTheDocument();
    });

    it("メンバー管理 UI は存在しない (Admin/Users へ移設済み = 旧 UI 並走の回帰封じ)", () => {
        render(Settings, { props: baseProps });

        expect(screen.queryByTestId("member-list")).toBeNull();
        expect(screen.queryByTestId("invite-submit")).toBeNull();
        expect(screen.queryByRole("heading", { name: "メンバーを招待" })).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
        expect(screen.queryByTestId("member-role-2")).toBeNull();
        expect(screen.queryByTestId("remove-member-2")).toBeNull();
    });

    it("manageMembers 権限者にはユーザー管理画面への導線リンクを出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-manage-users");
        expect(link).toBeInTheDocument();
        expect(link.getAttribute("href")).toMatch(/\/manage\/users$/);
    });

    it("usersUrl が null (権限なし) ならユーザー管理導線を出さない", () => {
        render(Settings, {
            props: {
                ...baseProps,
                currentUserRole: "organization_member",
                canManageApiKeys: false,
                usersUrl: null,
            },
        });

        expect(screen.queryByTestId("link-manage-users")).toBeNull();
        expect(screen.queryByLabelText("組織名")).toBeNull();
        expect(screen.queryByTestId("link-api-keys")).toBeNull();
        expect(screen.queryByTestId("toggle-two-factor-requirement")).toBeNull();
    });

    it("owner には 2FA 必須化トグルが表示される", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("toggle-two-factor-requirement")).toBeInTheDocument();
    });

    it("オーナー移譲 select は members (id/name) で動く", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByTestId("transfer-ownership-button")).toBeInTheDocument();
        expect(screen.getByLabelText("移譲先のメンバー")).toBeInTheDocument();
        expect(screen.getByRole("option", { name: "メンバー 花子" })).toBeInTheDocument();
    });

    it("canManageApiKeys の場合は API キー / 接続セッション管理画面への導線を出す", () => {
        render(Settings, { props: baseProps });

        const link = screen.getByTestId("link-api-keys");
        expect(link).toBeInTheDocument();
        // Inertia Link は jsdom で絶対 URL に解決するため末尾一致で検証する
        expect(link.getAttribute("href")).toMatch(/\/organizations\/test-org\/api-keys$/);
    });
});

describe("Organizations/Settings オーナー移譲の常時表示 (F-12)", () => {
    // 自分 (id:1) しかいない組織 = 移譲候補 0 人。
    // 実運用では members に自分が必ず含まれる (controller は全メンバーを返す) が、
    // 本テストは page 未モックで myId=null のため members: [] で候補 0 人を表現する
    // (どちらでも transferCandidates.length === 0 の同一分岐)。
    const soloProps = { ...baseProps, members: [] };

    it("候補 0 人でもオーナーにはセクションと案内文が表示される", () => {
        render(Settings, { props: soloProps });

        expect(screen.getByRole("heading", { name: "オーナー移譲" })).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toBeInTheDocument();
        expect(screen.getByTestId("transfer-no-candidates")).toHaveTextContent("ユーザー管理");
        const button = screen.getByTestId("transfer-ownership-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("候補 0 人で押下すると確認ダイアログを開かずエラーを表示する", async () => {
        render(Settings, { props: soloProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(
            screen.getByText(
                "移譲先にできるメンバーがいません。先にメンバーを招待してください。",
            ),
        ).toBeInTheDocument();
        // ConfirmDialog (Modal) は開いていない
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("未選択のまま押下すると確認ダイアログを開かず選択エラーを表示する", async () => {
        render(Settings, { props: baseProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        expect(screen.queryByRole("button", { name: "移譲する" })).toBeNull();
    });

    it("非オーナーにはオーナー移譲セクションを表示しない", () => {
        render(Settings, {
            props: { ...baseProps, currentUserRole: "organization_admin" },
        });

        expect(screen.queryByTestId("transfer-ownership-button")).toBeNull();
        expect(screen.queryByRole("heading", { name: "オーナー移譲" })).toBeNull();
    });
});

describe("Organizations/Settings オーナー移譲の確定フロー (F-12)", () => {
    /**
     * useForm (実物) の post は内部で router.post に委譲するため、router.post を spy して
     * URL / verb / payload を固定する。recent-auth precheck (fetch /recent-auth/status) は
     * URL 分岐の fetch stub で fresh/stale を切り替える。
     */
    function stubRecentAuthStatus(recent: boolean): ReturnType<typeof vi.fn> {
        const fetchMock = vi.fn().mockImplementation((input: RequestInfo | URL) => {
            if (String(input).includes("/recent-auth/status")) {
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            recent,
                            passwordSet: true,
                            availableProviders: [],
                            canSatisfy: true,
                            confirmedAt: recent ? 1 : null,
                        }),
                });
            }
            return Promise.reject(new Error(`unexpected fetch: ${String(input)}`));
        });
        vi.stubGlobal("fetch", fetchMock);
        return fetchMock;
    }

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    it("有効候補を選択→確認ダイアログ→確定で transferForm.post が正しい URL に発火する (precheck 込み)", async () => {
        const routerPostSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        const fetchMock = stubRecentAuthStatus(true);
        render(Settings, { props: baseProps });

        // page 未モック (myId=null) のため候補は全メンバー。花子 (id:2) を選択する
        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
            target: { value: "2" },
        });
        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        // 確認ダイアログが開く (確定までは POST しない)
        const confirmButton = screen.getByRole("button", { name: "移譲する" });
        expect(confirmButton).toBeInTheDocument();
        expect(routerPostSpy).not.toHaveBeenCalled();

        await fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(routerPostSpy).toHaveBeenCalledWith(
                "/organizations/test-org/transfer-ownership",
                expect.objectContaining({ user_id: "2" }),
                expect.objectContaining({ preserveScroll: true }),
            );
        });
        // recent-auth precheck (/recent-auth/status) を経由している
        expect(fetchMock).toHaveBeenCalledWith("/recent-auth/status", expect.anything());
    });

    it("recent-auth が stale なら再認証モーダルを開き、POST しない", async () => {
        const routerPostSpy = vi.spyOn(router, "post").mockImplementation(() => {});
        stubRecentAuthStatus(false);
        render(Settings, { props: baseProps });

        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
            target: { value: "2" },
        });
        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument();
        });
        expect(routerPostSpy).not.toHaveBeenCalled();
    });
});
