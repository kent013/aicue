import { beforeEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";
import Users from "@/pages/Admin/Users.svelte";
import type { InvitationRow, MemberRow } from "@/types/admin";

// router.patch をモックして visit options (第3引数) を捕捉し、page は errors を
// 差し替え可能な可変オブジェクトにする (SettingsSecurity.test.ts の手法を踏襲)。
const { routerPatchMock, routerDeleteMock, routerPostMock, pageState } = vi.hoisted(() => ({
    routerPatchMock: vi.fn(),
    routerDeleteMock: vi.fn(),
    routerPostMock: vi.fn(),
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/manage/users",
    },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: { patch: routerPatchMock, delete: routerDeleteMock, post: routerPostMock },
    page: pageState,
}));

/** router.patch の第3引数 (visit options) の検証対象部分。自己参照キャストを避けて明示定義する */
interface InertiaVisitOptions {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: () => void;
    onFinish?: () => void;
}

/** 最後の router.patch 呼び出しの visit options (第3引数) を取り出す */
function lastPatchOptions(): InertiaVisitOptions {
    const call = routerPatchMock.mock.calls.at(-1);
    if (!call) throw new Error("router.patch が呼ばれていない");
    return call[2] as InertiaVisitOptions;
}

// Default Project 不在時にサーバが role error bag へ載せる文言 (拒否ケースの再現用)
const REJECT_MESSAGE = "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。";

beforeEach(() => {
    routerPatchMock.mockReset();
    routerDeleteMock.mockReset();
    routerPostMock.mockReset();
    pageState.props = { appName: "AI-CUE" };
});

const membersFixture: MemberRow[] = [
    {
        id: 1,
        name: "オーナー 太郎",
        email: "owner@example.com",
        roleState: "owner",
        roleLabel: "管理者（オーナー）",
        twoFactorStatus: "enabled",
        isSelf: true,
    },
    {
        id: 2,
        name: "編集 花子",
        email: "editor@example.com",
        roleState: "editor",
        roleLabel: "編集者",
        twoFactorStatus: "enabled",
        isSelf: false,
    },
    {
        // F-14 (モバイル横スクロール) の bug-hunt 実測の最悪幅構成を再現する行:
        // 2FA バッジ + 未割当バッジ + 2FA 解除ボタン + 未割当 select + 削除ボタンが同一行に揃う
        // (閲覧者は id=1 の owner なので canResetTwoFactor は unassigned でも真)
        id: 3,
        name: "未割当 次郎",
        email: "unassigned@example.com",
        roleState: "unassigned",
        roleLabel: "未割当",
        twoFactorStatus: "enabled",
        isSelf: false,
    },
    {
        id: 4,
        name: "撮影 四郎",
        email: "shooter@example.com",
        roleState: "shooter",
        roleLabel: "撮影者",
        twoFactorStatus: "disabled",
        isSelf: false,
    },
];

const invitationsFixture: InvitationRow[] = [
    {
        id: 10,
        email: "invited@example.com",
        // 招待は org ロールのみ (役割付き招待は AG-079 で撤去)
        role: "organization_member",
        roleLabel: "メンバー",
        expiresAt: "2026-07-18",
    },
];

const baseProps = {
    organizationSlug: "test-org",
    members: membersFixture,
    invitations: invitationsFixture,
    hasDefaultProject: true,
};

describe("Admin/Users", () => {
    it("メンバー一覧・招待中一覧・追加フォームを描画する", () => {
        render(Users, { props: baseProps });

        expect(screen.getByRole("heading", { name: "ユーザー管理" })).toBeInTheDocument();
        expect(screen.getByTestId("member-list")).toBeInTheDocument();
        expect(screen.getByText("owner@example.com")).toBeInTheDocument();
        expect(screen.getByText("editor@example.com")).toBeInTheDocument();
        expect(screen.getByTestId("invitation-list")).toBeInTheDocument();
        expect(screen.getByText("invited@example.com")).toBeInTheDocument();
        expect(screen.getByLabelText("メールアドレス")).toBeInTheDocument();
        expect(screen.getByTestId("invite-submit")).toBeInTheDocument();
    });

    it("owner 行と自分の行にはロール select を出さずラベル表示する", () => {
        render(Users, { props: baseProps });

        // id=1 は owner かつ self → select なし・roleLabel テキスト
        expect(screen.queryByTestId("member-role-1")).toBeNull();
        expect(screen.getByText("管理者（オーナー）")).toBeInTheDocument();
        // 他メンバーには select と削除ボタンが出る
        expect(screen.getByTestId("member-role-2")).toBeInTheDocument();
        expect(screen.getByTestId("remove-member-2")).toBeInTheDocument();
    });

    it("未割当行は警告バッジと空 option (未割当) を表示する", () => {
        render(Users, { props: baseProps });

        expect(screen.getByTestId("unassigned-3")).toBeInTheDocument();
        const select = screen.getByTestId("member-role-3");
        expect(select).toHaveValue("");
        expect(
            screen.getByRole("option", { name: "未割当（選択してください）" }),
        ).toBeInTheDocument();
    });

    it("ロール select の選択肢は遷移コマンド 3 値 (owner は選べない)", () => {
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2");
        const options = Array.from(select.querySelectorAll("option")).map(
            (option) => option.textContent,
        );
        expect(options).toEqual(["管理者", "編集者", "撮影者"]);
    });

    it("必須未充足でもボタンは disabled にしない (押下時エラー方針)", () => {
        render(Users, { props: baseProps });

        expect(screen.getByTestId("invite-submit")).not.toBeDisabled();
        expect(screen.getByTestId("member-role-2")).not.toBeDisabled();
        expect(screen.getByTestId("remove-member-2")).not.toBeDisabled();
    });

    it("2FA 設定済み・非同格メンバーには 2FA 解除ボタンを出す (owner 閲覧)", () => {
        render(Users, { props: baseProps });

        // editor/unassigned (enabled) → 出る / shooter (disabled) → 出ない / self → 出ない
        expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
        expect(screen.getByTestId("reset-two-factor-3")).toBeInTheDocument();
        expect(screen.queryByTestId("reset-two-factor-4")).toBeNull();
        expect(screen.queryByTestId("reset-two-factor-1")).toBeNull();
    });

    it("2FA 未確認 (pending) メンバーには解除ボタン・2FA バッジを出さない (owner 閲覧)", () => {
        // viewer=owner (id=1, isSelf) を明示。対象は role=editor に固定し role 条件を満たさせる。
        render(Users, {
            props: {
                ...baseProps,
                members: [
                    {
                        id: 1,
                        name: "オーナー 太郎",
                        email: "owner@example.com",
                        roleState: "owner",
                        roleLabel: "管理者（オーナー）",
                        twoFactorStatus: "enabled",
                        isSelf: true,
                    },
                    {
                        id: 2,
                        name: "確定 花子",
                        email: "enabled@example.com",
                        roleState: "editor",
                        roleLabel: "編集者",
                        twoFactorStatus: "enabled",
                        isSelf: false,
                    },
                    {
                        id: 5,
                        name: "設定中 五郎",
                        email: "pending@example.com",
                        roleState: "editor",
                        roleLabel: "編集者",
                        twoFactorStatus: "pending",
                        isSelf: false,
                    },
                ] satisfies MemberRow[],
            },
        });

        // enabled (id=2): 従来どおり解除ボタン表示（回帰しないことの対照）
        expect(screen.getByTestId("reset-two-factor-2")).toBeInTheDocument();
        // pending (id=5): 解除ボタン非表示（本バグの修正点）
        expect(screen.queryByTestId("reset-two-factor-5")).toBeNull();

        // pending 行には 2FA バッジも出ない（バッジと解除ボタンの意味論一致）。
        // 行スコープ: 対象メンバー固有の email から closest('li') を辿る（件数アサーションを避ける）
        const pendingRow = screen.getByText("pending@example.com").closest("li");
        expect(pendingRow).not.toBeNull();
        expect(within(pendingRow as HTMLElement).queryByText("2FA")).toBeNull();
        // enabled 行には 2FA バッジが出る（対照）
        const enabledRow = screen.getByText("enabled@example.com").closest("li");
        expect(within(enabledRow as HTMLElement).getByText("2FA")).toBeInTheDocument();
    });

    it("admin 閲覧者は同格 (admin) の 2FA 解除ボタンを出さない", () => {
        render(Users, {
            props: {
                ...baseProps,
                members: [
                    {
                        id: 1,
                        name: "管理 太郎",
                        email: "admin-self@example.com",
                        roleState: "admin",
                        roleLabel: "管理者",
                        twoFactorStatus: "enabled",
                        isSelf: true,
                    },
                    {
                        id: 2,
                        name: "別管理 花子",
                        email: "other-admin@example.com",
                        roleState: "admin",
                        roleLabel: "管理者",
                        twoFactorStatus: "enabled",
                        isSelf: false,
                    },
                    {
                        id: 3,
                        name: "撮影 三郎",
                        email: "shooter@example.com",
                        roleState: "shooter",
                        roleLabel: "撮影者",
                        twoFactorStatus: "enabled",
                        isSelf: false,
                    },
                ] satisfies MemberRow[],
            },
        });

        expect(screen.queryByTestId("reset-two-factor-2")).toBeNull();
        expect(screen.getByTestId("reset-two-factor-3")).toBeInTheDocument();
    });

    it("招待が空のときは EmptyState を表示する", () => {
        render(Users, { props: { ...baseProps, invitations: [] } });

        expect(screen.getByTestId("invitations-empty")).toBeInTheDocument();
        expect(screen.queryByTestId("invitation-list")).toBeNull();
    });

    it("project 不在時は案内文を出す。独自二次左メニュー(AdminMenuNav)は撤去済み (aigenba parity, T071)", () => {
        render(Users, {
            props: { ...baseProps, hasDefaultProject: false },
        });

        expect(screen.getByTestId("no-project-note")).toBeInTheDocument();
        // 旧 AdminMenuNav の nav 項目は撤去済み (カテゴリ管理はプロジェクト詳細から到達)
        expect(screen.queryByTestId("admin-nav-categories")).toBeNull();
        expect(screen.queryByTestId("admin-nav-users")).toBeNull();
    });

    it("project 不在時は projects.create への作成リンクを出す (href 正しい・注記文言維持)", () => {
        render(Users, {
            props: { ...baseProps, hasDefaultProject: false },
        });

        const link = screen.getByTestId("create-project-link");
        // Inertia Link は href を絶対 URL に正規化するため pathname で検証する
        const href = link.getAttribute("href") ?? "";
        expect(new URL(href, "http://localhost").pathname).toBe("/projects/create");
        // 既存注記の文言は維持
        expect(screen.getByTestId("no-project-note")).toHaveTextContent(
            "編集者・撮影者を割り当てるには、先にプロジェクトを作成してください。",
        );
    });

    it("project 在時は作成リンクを出さない", () => {
        render(Users, { props: { ...baseProps, hasDefaultProject: true } });

        expect(screen.queryByTestId("create-project-link")).toBeNull();
    });

    it("メンバー行はモバイル縦積みクラスを持ち、操作ブロックは flex-wrap を持つ (F-14)", () => {
        // jsdom はレイアウト計算をしないため、クラス不変条件を横スクロール回避のプロキシとして固定する。
        // 対象要素は data-testid 起点で特定し DOM 順序に依存しない。
        render(Users, { props: baseProps });

        const roleSelect = screen.getByTestId("member-role-3");
        const row = roleSelect.closest("li");
        expect(row).not.toBeNull();
        expect(row).toHaveClass("flex-col", "sm:flex-row", "sm:flex-wrap");
        // 行折り返しへ切替済み: justify-between へ逆戻りしていないこと (T042 S1)
        expect(row).not.toHaveClass("sm:justify-between");

        const actions = roleSelect.parentElement;
        expect(actions).not.toBeNull();
        expect(actions).toHaveClass("flex-wrap", "sm:ml-auto");

        // 名前/メール列は sm 以上で最小幅の床を持ち、過剰 truncate を防ぐ (T042 S1)
        const nameColumn = screen.getByText("unassigned@example.com").parentElement;
        expect(nameColumn).not.toBeNull();
        expect(nameColumn).toHaveClass("min-w-0", "sm:min-w-40");

        // bug-hunt 実測の最悪幅構成 (2FA バッジ + 未割当バッジ + 2FA 解除 + 未割当 select + 削除)
        // が同一行に揃っていることを固定する
        const rowScope = within(row as HTMLElement);
        expect(rowScope.getByText("2FA")).toBeInTheDocument();
        expect(rowScope.getByTestId("unassigned-3")).toBeInTheDocument();
        expect(rowScope.getByTestId("reset-two-factor-3")).toBeInTheDocument();
        expect(rowScope.getByTestId("remove-member-3")).toBeInTheDocument();
        expect(
            rowScope.getByRole("option", { name: "未割当（選択してください）" }),
        ).toBeInTheDocument();
    });

    it("招待行もモバイル縦積みクラスを持ち、右側ブロックは flex-wrap を持つ (F-14)", () => {
        render(Users, { props: baseProps });

        const revokeButton = screen.getByTestId("revoke-invitation-10");
        const row = revokeButton.closest("li");
        expect(row).not.toBeNull();
        expect(row).toHaveClass("flex-col", "sm:flex-row", "sm:flex-wrap");
        // 行折り返しへ切替済み: justify-between へ逆戻りしていないこと (T042 S1)
        expect(row).not.toHaveClass("sm:justify-between");

        const actions = revokeButton.parentElement;
        expect(actions).not.toBeNull();
        expect(actions).toHaveClass("flex-wrap", "sm:ml-auto");

        // 招待メール列は sm 以上で最小幅の床を持つ (T042 S1)
        const emailColumn = screen.getByText("invited@example.com");
        expect(emailColumn).toHaveClass("min-w-0", "truncate", "sm:min-w-40");
    });

    it("削除 ConfirmDialog はメンバー名入りの警告文言を持つ", async () => {
        const { component: _ } = render(Users, { props: baseProps });

        const removeButton = screen.getByTestId("remove-member-2");
        removeButton.click();
        // ConfirmDialog は open で描画される
        const dialog = await screen.findByTestId("remove-member-dialog");
        expect(dialog).toBeInTheDocument();
        expect(dialog.textContent).toContain("編集 花子");
        expect(dialog.textContent).toContain("削除しますか");
    });
});

describe("Admin/Users ロール変更フィードバック", () => {
    it("拒否時に対象行 Select が権威値へ戻る", async () => {
        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        expect(select).toHaveValue("editor");

        // 一方向 value 伝播では権威値 (editor) と乖離した DOM 選択 (admin) が残る
        await fireEvent.change(select, { target: { value: "admin" } });
        expect(routerPatchMock).toHaveBeenCalledTimes(1);

        const options = lastPatchOptions();
        options.onError?.();
        options.onFinish?.();

        // remount により権威値 editor へ復帰する
        await waitFor(() =>
            expect(screen.getByTestId("member-role-2")).toHaveValue("editor"),
        );
    });

    it("拒否時に対象行のみ invalid + combobox 直下にエラーが出る", async () => {
        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        await fireEvent.change(select, { target: { value: "admin" } });

        const options = lastPatchOptions();
        options.onError?.();
        options.onFinish?.();

        await waitFor(() => {
            const rejected = screen.getByTestId("member-role-2");
            expect(rejected).toHaveAttribute("aria-invalid", "true");
            expect(rejected).toHaveAttribute("aria-describedby", "role-error-2");
        });

        const error = screen.getByTestId("role-error-2");
        expect(error).toHaveTextContent(REJECT_MESSAGE);
        expect(error).toHaveAttribute("id", "role-error-2");
    });

    it("成功時は新ロールが props で反映され invalid / エラーが残らない", async () => {
        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
        const { rerender } = render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        await fireEvent.change(select, { target: { value: "admin" } });

        const options = lastPatchOptions();
        options.onSuccess?.();
        options.onFinish?.();

        // 成功相当の再取得 props (id=2 が admin へ) で再描画する
        await rerender({
            ...baseProps,
            members: membersFixture.map((member) =>
                member.id === 2
                    ? { ...member, roleState: "admin", roleLabel: "管理者" }
                    : member,
            ),
        });

        await waitFor(() =>
            expect(screen.getByTestId("member-role-2")).toHaveValue("admin"),
        );
        expect(screen.getByTestId("member-role-2")).not.toHaveAttribute("aria-invalid");
        expect(screen.queryByTestId("role-error-2")).toBeNull();
    });

    it("拒否時に失敗行以外は invalid にならずエラーも出ない", async () => {
        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        await fireEvent.change(select, { target: { value: "admin" } });

        const options = lastPatchOptions();
        options.onError?.();
        options.onFinish?.();

        await waitFor(() =>
            expect(screen.getByTestId("member-role-2")).toHaveAttribute(
                "aria-invalid",
                "true",
            ),
        );
        expect(screen.getByTestId("member-role-3")).not.toHaveAttribute("aria-invalid");
        expect(screen.getByTestId("member-role-4")).not.toHaveAttribute("aria-invalid");
        expect(screen.queryByTestId("role-error-3")).toBeNull();
        expect(screen.queryByTestId("role-error-4")).toBeNull();
    });

    it("in-flight 中は全ロール Select が disabled になり onFinish で解除される", async () => {
        pageState.props = { appName: "AI-CUE", errors: {} };
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        await fireEvent.change(select, { target: { value: "admin" } });

        // onFinish 未発火 (通信中) は全ロール Select が disabled
        await waitFor(() => {
            expect(screen.getByTestId("member-role-2")).toBeDisabled();
            expect(screen.getByTestId("member-role-3")).toBeDisabled();
            expect(screen.getByTestId("member-role-4")).toBeDisabled();
        });

        const options = lastPatchOptions();
        options.onSuccess?.();
        options.onFinish?.();

        await waitFor(() => {
            expect(screen.getByTestId("member-role-2")).not.toBeDisabled();
            expect(screen.getByTestId("member-role-3")).not.toBeDisabled();
            expect(screen.getByTestId("member-role-4")).not.toBeDisabled();
        });
    });

    it("拒否後にフォーカスが失敗行 Select へ復帰する", async () => {
        pageState.props = { appName: "AI-CUE", errors: { role: REJECT_MESSAGE } };
        render(Users, { props: baseProps });

        const select = screen.getByTestId("member-role-2") as HTMLSelectElement;
        await fireEvent.change(select, { target: { value: "admin" } });

        const options = lastPatchOptions();
        options.onError?.();
        // onFinish 前 (disabled 中) はまだフォーカス復帰していない
        expect(screen.getByTestId("member-role-2")).not.toHaveFocus();

        options.onFinish?.();
        await waitFor(() => expect(screen.getByTestId("member-role-2")).toHaveFocus());
    });
});
