import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor } from "@testing-library/svelte";

/*
 * プロフィール設定画面 (T025: 唯一オーナーのアカウント削除ガード)。
 * - soleOwnedOrganizations 非空 → 警告 + 各組織の /organizations/{slug}/settings リンク描画
 * - 空 → 警告非表示
 * - errors.account (string / string[] 両対応) → danger Alert 表示
 * - 警告と errors.account の同時表示 (両立)
 * - 削除ボタンは常に有効 (AGENTS.md 禁止事項 8: disabled 不使用)
 * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
 */

const { pageState, routerDeleteMock } = vi.hoisted(() => ({
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings",
    },
    routerDeleteMock: vi.fn(),
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { delete: routerDeleteMock },
    page: pageState,
}));

// eslint-disable-next-line import/first
import Index from "@/pages/Settings/Index.svelte";

function setProps(extra: Record<string, unknown> = {}): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", email: "taro@example.com" } },
        ...extra,
    };
}

/** recent-auth precheck (/recent-auth/status) を fresh 応答でスタブする */
function stubRecentAuthFresh(): void {
    vi.stubGlobal(
        "fetch",
        vi.fn(() =>
            Promise.resolve({
                ok: true,
                status: 200,
                json: () =>
                    Promise.resolve({
                        recent: true,
                        passwordSet: true,
                        availableProviders: [],
                        canSatisfy: true,
                        confirmedAt: 1,
                    }),
            }),
        ),
    );
}

/** router.delete 第2引数 (visit options) の onError を取り出す */
interface DeleteVisitOptions {
    onError?: () => void;
    onStart?: () => void;
    onFinish?: () => void;
}

beforeEach(() => {
    setProps();
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerDeleteMock.mockReset();
});

describe("Settings/Index 唯一オーナー削除ガード", () => {
    it("soleOwnedOrganizations 非空で警告と各組織の設定リンクを描画する", () => {
        setProps({
            soleOwnedOrganizations: [
                { name: "現場A", slug: "genba-a" },
                { name: "現場B", slug: "genba-b" },
            ],
        });
        render(Index, { props: {} });

        expect(screen.getByText("オーナー移譲が必要です")).toBeInTheDocument();
        const linkA = screen.getByText("現場A の設定へ");
        expect(linkA.getAttribute("href")).toMatch(/\/organizations\/genba-a\/settings$/);
        const linkB = screen.getByText("現場B の設定へ");
        expect(linkB.getAttribute("href")).toMatch(/\/organizations\/genba-b\/settings$/);
    });

    it("soleOwnedOrganizations が空なら警告を出さない", () => {
        setProps({ soleOwnedOrganizations: [] });
        render(Index, { props: {} });

        expect(screen.queryByText("オーナー移譲が必要です")).toBeNull();
    });

    it("errors.account (string) を danger Alert で表示する", () => {
        setProps({ errors: { account: "次の組織のオーナーであるため削除できません: 現場A" } });
        render(Index, { props: {} });

        expect(
            screen.getByText("次の組織のオーナーであるため削除できません: 現場A"),
        ).toBeInTheDocument();
    });

    it("errors.account (string[]) の先頭要素を表示する", () => {
        setProps({ errors: { account: ["最初のエラー", "二番目"] } });
        render(Index, { props: {} });

        expect(screen.getByText("最初のエラー")).toBeInTheDocument();
        expect(screen.queryByText("二番目")).toBeNull();
    });

    it("警告と errors.account を同時に表示できる", () => {
        setProps({
            soleOwnedOrganizations: [{ name: "現場A", slug: "genba-a" }],
            errors: { account: "削除できません" },
        });
        render(Index, { props: {} });

        expect(screen.getByText("オーナー移譲が必要です")).toBeInTheDocument();
        expect(screen.getByText("削除できません")).toBeInTheDocument();
    });

    it("削除ボタンは常に有効 (disabled 不使用)", () => {
        setProps({ soleOwnedOrganizations: [{ name: "現場A", slug: "genba-a" }] });
        render(Index, { props: {} });

        const button = screen.getByTestId("delete-account-button");
        expect(button).toBeInTheDocument();
        expect(button).not.toBeDisabled();
    });

    it("削除の onError はダイアログを閉じ (ブロック時に Alert を露出できる)", async () => {
        stubRecentAuthFresh();
        render(Index, { props: {} });

        // 削除ボタン → 確認ダイアログ → 確定 (recent-auth precheck fresh → router.delete)
        await fireEvent.click(screen.getByTestId("delete-account-button"));
        expect(screen.getByTestId("delete-account-dialog")).toBeInTheDocument();
        await fireEvent.click(screen.getByRole("button", { name: "削除する" }));

        // router.delete が /settings/account に onError 付きで呼ばれる
        await waitFor(() => expect(routerDeleteMock).toHaveBeenCalled());
        const call = routerDeleteMock.mock.calls.at(-1);
        expect(call?.[0]).toBe("/settings/account");
        const options = call?.[1] as DeleteVisitOptions;
        expect(typeof options.onError).toBe("function");

        // onError 発火 → 確認ダイアログが閉じる
        options.onError?.();
        await waitFor(() =>
            expect(screen.queryByTestId("delete-account-dialog")).toBeNull(),
        );
    });
});
