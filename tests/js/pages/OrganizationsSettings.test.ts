import { afterEach, describe, expect, it, vi } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import { router } from "@inertiajs/svelte";
import Settings from "@/pages/Organizations/Settings.svelte";

const baseProps = {
    organization: {
        id: 1,
        name: "テスト組織",
        slug: "test-org",
        twoFactorRequired: false,
    },
    // 識別名の変更 (家系裁定 AG-046)。**表示のための早期情報**であり権威ではない
    slugRename: { remaining: 5, nextAvailableAt: null },
    // オーナー移譲 select 用の最小 shape (id/name)。email / 2FA は Admin/Users へ移設済み
    members: [
        { id: 1, name: "オーナー 太郎" },
        { id: 2, name: "メンバー 花子" },
    ],
    currentUserRole: "organization_owner",
    canManageApiKeys: true,
    usersUrl: "/organizations/test-org/manage/users",
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
                            passkeyAvailable: false,
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

    /*
     * **passkey-only ユーザーがこの画面で詰まないこと** (監査 F-1 の回帰)。
     * T106 では `passkeyAvailable` が本画面のモーダルへ未配線で、パスキーしか持たない
     * ユーザーには「手段 0 + 事実に反する文言」だけが出ていた。props 契約を `status` 1 本に
     * 統合したことで、サーバの status がそのままモーダルへ届く。
     * 残り 4 画面は tests/js/architecture/recent-auth-modal-call-site-inventory.test.ts が担保する。
     */
    it("passkey-only + stale なら再認証モーダルにパスキー導線が出る (詰まない)", async () => {
        vi.spyOn(router, "post").mockImplementation(() => {});
        vi.stubGlobal(
            "fetch",
            vi.fn().mockImplementation((input: RequestInfo | URL) => {
                if (String(input).includes("/recent-auth/status")) {
                    return Promise.resolve({
                        ok: true,
                        status: 200,
                        json: () =>
                            Promise.resolve({
                                recent: false,
                                passwordSet: false,
                                availableProviders: [],
                                passkeyAvailable: true,
                                canSatisfy: true,
                                confirmedAt: null,
                            }),
                    });
                }
                return Promise.reject(new Error(`unexpected fetch: ${String(input)}`));
            }),
        );
        // WebAuthn 対応ブラウザを偽装する (非対応端末では回復導線に倒れるのが正)
        Object.defineProperty(globalThis, "navigator", {
            configurable: true,
            value: { credentials: { create: vi.fn(), get: vi.fn() } },
        });
        Object.defineProperty(window, "PublicKeyCredential", {
            configurable: true,
            writable: true,
            value: function PublicKeyCredentialStub() {
                // instanceof 判定にのみ使う
            },
        });

        render(Settings, { props: baseProps });

        await fireEvent.change(screen.getByLabelText("移譲先のメンバー"), {
            target: { value: "2" },
        });
        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));

        await waitFor(() => {
            expect(screen.getByTestId("recent-auth-passkey")).toBeInTheDocument();
        });

        Object.defineProperty(window, "PublicKeyCredential", {
            configurable: true,
            writable: true,
            value: undefined,
        });
    });
});

describe("Organizations/Settings オーナー移譲の client error 自動解消 (T044)", () => {
    // 自分 (id:1) + 有効候補 2 人 (A id:2 / B id:3)。page 未モック (myId=null) のため
    // 3 人全員が候補になるが、A→B の切替で $effect のクリア分岐を通せればよい。
    const multiCandidateProps = {
        ...baseProps,
        members: [
            { id: 1, name: "オーナー 太郎" },
            { id: 2, name: "候補 A" },
            { id: 3, name: "候補 B" },
        ],
    };

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
                            passkeyAvailable: false,
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

    it("空選択で押下→エラー表示後、有効候補を選ぶと client error と aria-invalid が自動解消する", async () => {
        render(Settings, { props: multiCandidateProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));

        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        const select = screen.getByLabelText("移譲先のメンバー");
        expect(select).toHaveAttribute("aria-invalid", "true");

        await fireEvent.change(select, { target: { value: "2" } });

        await waitFor(() => {
            expect(
                screen.queryByText("移譲先のメンバーを選択してください。"),
            ).toBeNull();
        });
        expect(select).not.toHaveAttribute("aria-invalid");
    });

    it("無効値のまま (空選択維持) なら client error は残留する (過剰クリア防止)", async () => {
        render(Settings, { props: multiCandidateProps });

        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();

        // 選択を空のまま保持 (isValidTransferTarget=false) → $effect はクリアしない
        const select = screen.getByLabelText("移譲先のメンバー");
        await fireEvent.change(select, { target: { value: "" } });

        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        expect(select).toHaveAttribute("aria-invalid", "true");
    });

    it("client error の自動クリアは serverErrors を破壊せず、背後のサーバエラーが再表示される (非退行)", async () => {
        // router.post を onError 呼び出しに差し替える。useForm 内部 onError が
        // form.clearErrors().setError(errors) を実行し、実 transferForm.errors.user_id に載る。
        const serverMsg = "サーバ由来: 対象は組織メンバーではありません";
        vi.spyOn(router, "post").mockImplementation(
            (_url, _data, opts) => {
                (opts as { onError?: (e: Record<string, string>) => void } | undefined)?.onError?.(
                    { user_id: serverMsg },
                );
            },
        );
        stubRecentAuthStatus(true);
        render(Settings, { props: multiCandidateProps });

        const select = screen.getByLabelText("移譲先のメンバー");

        // 1. 有効候補 A を選択 → 確認ダイアログ → 確定 → サーバエラー表示 (client error は null)
        await fireEvent.change(select, { target: { value: "2" } });
        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
        await fireEvent.click(screen.getByRole("button", { name: "移譲する" }));
        await waitFor(() => {
            expect(screen.getByText(serverMsg)).toBeInTheDocument();
        });

        // 2. 空選択に戻して送信 → client error がサーバエラーを一時的に覆う
        await fireEvent.change(select, { target: { value: "" } });
        await fireEvent.click(screen.getByTestId("transfer-ownership-button"));
        expect(screen.getByText("移譲先のメンバーを選択してください。")).toBeInTheDocument();
        expect(screen.queryByText(serverMsg)).toBeNull();

        // 3. 有効候補 B を選択 → $effect がクリア分岐を通り client error=null → サーバエラー再表示
        await fireEvent.change(select, { target: { value: "3" } });
        await waitFor(() => {
            expect(
                screen.queryByText("移譲先のメンバーを選択してください。"),
            ).toBeNull();
        });
        expect(screen.getByText(serverMsg)).toBeInTheDocument();
    });
});

describe("Organizations/Settings の識別名 (家系裁定 AG-046)", () => {
    it("識別名フォームを描画し、残り回数を案内する", () => {
        render(Settings, { props: baseProps });

        expect(screen.getByLabelText("識別名")).toBeInTheDocument();
        expect(screen.getByText(/30 日あたり 5 回まで変更できます/)).toBeInTheDocument();
    });

    it("回数上限でもボタンを disabled にしない (押下時にサーバがエラーを返す)", () => {
        render(Settings, {
            props: {
                ...baseProps,
                slugRename: { remaining: 0, nextAvailableAt: "2026-09-30T00:00:00+09:00" },
            },
        });

        const submit = screen.getByTestId("organization-slug-submit");
        expect(submit).not.toBeDisabled();
    });

    it("送信は確認ダイアログを挟む (URL が丸ごと変わる破壊的操作のため)", async () => {
        render(Settings, { props: baseProps });

        await fireEvent.submit(screen.getByTestId("organization-slug-submit").closest("form")!);

        await waitFor(() =>
            expect(screen.getByTestId("organization-slug-dialog")).toBeInTheDocument(),
        );
    });
});
