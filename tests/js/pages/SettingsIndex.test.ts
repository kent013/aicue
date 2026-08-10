import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen, waitFor, within } from "@testing-library/svelte";

/*
 * プロフィール設定画面 (T025: 唯一オーナーのアカウント削除ガード / T115: 退会時の課金ガード)。
 * - accountDeletionBlockers 非空 → 警告 + action 別の導線 (移譲 / 解約 / 切替つき解約) 描画
 * - 空 → 警告非表示
 * - errors.account (string / string[] 両対応) → danger Alert 表示
 * - 警告と errors.account の同時表示 (両立)
 * - 削除ボタンは常に有効 (AGENTS.md 禁止事項 8: disabled 不使用)
 * - 削除 (router.delete) の onError はダイアログを閉じる (押下後に理由が見える)
 */

const {
    pageState,
    routerDeleteMock,
    routerReloadMock,
    routerPostMock,
    routerVisitMock,
    formHolder,
    formSeed,
} = vi.hoisted(() => ({
    pageState: {
        props: {} as Record<string, unknown>,
        url: "/settings",
    },
    routerDeleteMock: vi.fn(),
    routerReloadMock: vi.fn(),
    routerPostMock: vi.fn(),
    routerVisitMock: vi.fn(),
    // useForm fake が捕捉する各 form の holder。初期データキーで二分岐する:
    //   "email" を持つ → profileForm (case 6 で put を検証)
    //   "current_password" を持つ → passwordForm (T042 S2 で put/errorBag を検証)
    formHolder: {
        profile: null as Record<string, unknown> | null,
        password: null as Record<string, unknown> | null,
        // パスワード**初回設定** form (施策 7)。初期データが password 1 キーのみで判別する
        passwordSetup: null as Record<string, unknown> | null,
    },
    // passwordForm の初期 errors シード。FormField は error があるときだけ
    // aria-describedby を生成するため、透過検証ケースだけがここに値を入れる。
    formSeed: { passwordErrors: {} as Record<string, string> },
    }));

vi.mock("@inertiajs/svelte", async (importOriginal) => {
    // password フォームは反応的 double を使う (clearErrors で errors が消える再描画、
    // processing=true で pending 文言が出る再描画を DOM で観測するため)。hoisting 制約は
    // async factory 内の dynamic import で回避する (既存 ManualsCreate.test と同じ helper)。
    const { reactiveUseForm } = await import("../support/reactiveUseForm.svelte");
    return {
        ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
        router: {
            delete: routerDeleteMock,
            reload: routerReloadMock,
            post: routerPostMock,
            visit: routerVisitMock,
        },
        page: pageState,
        // useForm を fake に差し替え、form を holder に記録する。
        //   "current_password" を持つ → passwordForm: 反応的 double
        //   "email" を持つ → profileForm: 最小 fake (put を spy)
        useForm: (initial: Record<string, unknown>) => {
            if ("current_password" in initial) {
                const form = reactiveUseForm(initial, { ...formSeed.passwordErrors });
                formHolder.password = form;
                return form;
            }
            const form: Record<string, unknown> = {
                ...initial,
                errors: {},
                processing: false,
                get: vi.fn(),
                post: vi.fn(),
                put: vi.fn(),
                patch: vi.fn(),
                delete: vi.fn(),
                submit: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };
            if ("email" in initial) {
                formHolder.profile = form;
            } else if ("password" in initial) {
                // password 1 キーのみ = 初回設定 form (変更 form は current_password を持つ)
                formHolder.passwordSetup = form;
            }
            return form;
        },
    };
});

// eslint-disable-next-line import/first
import Index from "@/pages/Settings/Index.svelte";

/**
 * 既定は **password 設定済み** (hasPassword: true)。パスワードカードは施策 7 で
 * `hasPassword` による 3 値出し分け (set / unset / unknown) になったため、
 * 変更フォームを見るケースは明示的に設定済みを渡す必要がある。
 * `setProps({ hasPassword: false })` で初回設定フォーム、
 * `setProps({ hasPassword: undefined })` で状態不明になる。
 */
function setProps(extra: Record<string, unknown> = {}): void {
    pageState.props = {
        appName: "AI-CUE",
        auth: { user: { id: 1, name: "テスト太郎", email: "taro@example.com" } },
        hasPassword: true,
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
                        passkeyAvailable: false,
                        canSatisfy: true,
                        confirmedAt: 1,
                    }),
            }),
        ),
    );
}

/**
 * recent-auth precheck を stale (/recent-auth/status → recent:false) にし、
 * satisfier (/recent-auth/password) は 204 成功を返すスタブ (case 6 用)。
 */
function stubRecentAuthStaleThenConfirm(): void {
    vi.stubGlobal(
        "fetch",
        vi.fn((input: RequestInfo | URL) => {
            const url = typeof input === "string" ? input : input.toString();
            if (url.includes("/recent-auth/status")) {
                return Promise.resolve({
                    ok: true,
                    status: 200,
                    json: () =>
                        Promise.resolve({
                            recent: false,
                            passwordSet: true,
                            availableProviders: [],
                            passkeyAvailable: false,
                            canSatisfy: true,
                            confirmedAt: null,
                        }),
                });
            }
            if (url.includes("/recent-auth/password")) {
                return Promise.resolve({ status: 204 });
            }
            return Promise.reject(new Error(`unexpected fetch: ${url}`));
        }),
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
    formHolder.profile = null;
    formHolder.password = null;
    formHolder.passwordSetup = null;
    formSeed.passwordErrors = {};
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    routerDeleteMock.mockReset();
    routerReloadMock.mockReset();
    routerPostMock.mockReset();
    routerVisitMock.mockReset();
});

describe("Settings/Index 退会ガード (blocker と次の一手)", () => {
    it("transfer_ownership は各組織の設定リンクを描画する", () => {
        setProps({
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
                { name: "現場B", slug: "genba-b", actions: ["transfer_ownership"] },
            ],
        });
        render(Index, { props: {} });

        expect(screen.getByText("退会するには先に対応が必要です")).toBeInTheDocument();
        const linkA = screen.getAllByText("オーナーを移譲する")[0];
        expect(linkA.getAttribute("href")).toMatch(/\/organizations\/genba-a\/settings$/);
        const linkB = screen.getAllByText("オーナーを移譲する")[1];
        expect(linkB.getAttribute("href")).toMatch(/\/organizations\/genba-b\/settings$/);
        expect(screen.getByText("現場A")).toBeInTheDocument();
        expect(screen.getByText("現場B")).toBeInTheDocument();
    });

    it("open_billing は /billing への解約リンクを描画する", () => {
        setProps({
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["open_billing"] },
            ],
        });
        render(Index, { props: {} });

        const link = screen.getByText("サブスクリプションを解約する");
        expect(link.getAttribute("href")).toMatch(/\/billing$/);
    });

    it("switch_organization_then_open_billing は切替 → 成功時のみ /billing へ進む", async () => {
        setProps({
            accountDeletionBlockers: [
                {
                    name: "現場B",
                    slug: "genba-b",
                    actions: ["switch_organization_then_open_billing"],
                },
            ],
        });
        render(Index, { props: {} });

        await fireEvent.click(screen.getByTestId("switch-then-billing-button"));

        await waitFor(() => expect(routerPostMock).toHaveBeenCalledTimes(1));
        const call = routerPostMock.mock.calls.at(-1);
        expect(call?.[0]).toBe("/organizations/genba-b/switch");
        const options = call?.[2] as { onSuccess?: () => void; onError?: () => void };
        // 成功するまで /billing へは進まない
        expect(routerVisitMock).not.toHaveBeenCalled();
        options.onSuccess?.();
        expect(routerVisitMock).toHaveBeenCalledWith("/billing");
    });

    it("切替失敗 (onError) では /billing へ進まず失敗メッセージを出す", async () => {
        setProps({
            accountDeletionBlockers: [
                {
                    name: "現場B",
                    slug: "genba-b",
                    actions: ["switch_organization_then_open_billing"],
                },
            ],
        });
        render(Index, { props: {} });

        await fireEvent.click(screen.getByTestId("switch-then-billing-button"));
        await waitFor(() => expect(routerPostMock).toHaveBeenCalledTimes(1));
        const options = routerPostMock.mock.calls.at(-1)?.[2] as { onError?: () => void };
        options.onError?.();

        await waitFor(() =>
            expect(screen.getByTestId("switch-organization-error")).toBeInTheDocument(),
        );
        expect(routerVisitMock).not.toHaveBeenCalled();
    });

    it("accountDeletionBlockers が空なら警告を出さない", () => {
        setProps({ accountDeletionBlockers: [] });
        render(Index, { props: {} });

        expect(screen.queryByText("退会するには先に対応が必要です")).toBeNull();
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
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
            ],
            errors: { account: "削除できません" },
        });
        render(Index, { props: {} });

        expect(screen.getByText("退会するには先に対応が必要です")).toBeInTheDocument();
        expect(screen.getByText("削除できません")).toBeInTheDocument();
    });

    it("削除ボタンは常に有効 (disabled 不使用)", () => {
        setProps({
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
            ],
        });
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

describe("Settings/Index プロフィール更新の recent-auth precheck", () => {
    it("email 変更 + stale は precheck 段階で put せず、再認証後に 1 回だけ put する", async () => {
        stubRecentAuthStaleThenConfirm();
        render(Index, { props: {} });

        const profileForm = formHolder.profile;
        expect(profileForm).not.toBeNull();
        const putMock = profileForm?.put as ReturnType<typeof vi.fn>;

        // 名前と email を編集 (email 変更で precheck が発火する)
        await fireEvent.input(screen.getByLabelText("名前"), {
            target: { value: "新しい名前" },
        });
        await fireEvent.input(screen.getByLabelText("メールアドレス"), {
            target: { value: "new@example.com" },
        });

        // 保存 → email 変更 → precheck stale → 再認証モーダル
        const saveButton = screen.getByRole("button", { name: "保存" });
        const form = saveButton.closest("form");
        expect(form).not.toBeNull();
        await fireEvent.submit(form as HTMLFormElement);

        await waitFor(() =>
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument(),
        );
        // precheck 段階では put されない (二重送信回帰の捕捉)
        expect(putMock).not.toHaveBeenCalled();

        // モーダルで再認証 → 204 → onConfirmed → resumePendingAction → put
        await fireEvent.input(screen.getByTestId("recent-auth-password-input"), {
            target: { value: "password" },
        });
        await fireEvent.submit(
            screen.getByTestId("recent-auth-submit").closest("form") as HTMLFormElement,
        );

        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
        const call = putMock.mock.calls.at(-1);
        expect(call?.[0]).toBe("/user/profile-information");
        // 編集済み name/email が保持されたまま再送される
        expect(profileForm?.name).toBe("新しい名前");
        expect(profileForm?.email).toBe("new@example.com");
    });

    it("氏名のみ変更 (email 不変) は precheck を経ず直ちに put する", async () => {
        // status を叩いたら失敗させ、precheck が発火しないことを保証する
        vi.stubGlobal(
            "fetch",
            vi.fn(() => Promise.reject(new Error("precheck should not run"))),
        );
        render(Index, { props: {} });

        const profileForm = formHolder.profile;
        const putMock = profileForm?.put as ReturnType<typeof vi.fn>;

        await fireEvent.input(screen.getByLabelText("名前"), {
            target: { value: "氏名だけ変更" },
        });

        const saveButton = screen.getByRole("button", { name: "保存" });
        await fireEvent.submit(saveButton.closest("form") as HTMLFormElement);

        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
        expect(screen.queryByTestId("recent-auth-modal")).toBeNull();
    });
});

describe("Settings/Index パスワード変更フォームの表示トグル (T042 S2)", () => {
    /** ラベル文言からパスワード入力とその PasswordInput コンテナ (div.relative) を得る */
    function passwordField(label: string): { input: HTMLInputElement; container: HTMLElement } {
        const input = screen.getByLabelText(label) as HTMLInputElement;
        const container = input.parentElement as HTMLElement;
        expect(container).not.toBeNull();
        return { input, container };
    }

    it("現在/新しい パスワード入力が表示トグル付き (PasswordInput) で描画される", () => {
        render(Index, { props: {} });

        const current = passwordField("現在のパスワード");
        const next = passwordField("新しいパスワード");

        // 初期状態は伏字。各コンテナ内に表示トグルボタンが 1 個ずつ存在する
        expect(current.input.type).toBe("password");
        expect(next.input.type).toBe("password");
        expect(
            within(current.container).getByRole("button", { name: "パスワードを表示" }),
        ).toBeInTheDocument();
        expect(
            within(next.container).getByRole("button", { name: "パスワードを表示" }),
        ).toBeInTheDocument();
    });

    it("トグルで type が password↔text に切り替わり、2 フィールドは独立している", async () => {
        render(Index, { props: {} });

        const current = passwordField("現在のパスワード");
        const next = passwordField("新しいパスワード");

        // 現在のパスワードだけトグル → current は text、next は password のまま (相互干渉なし)
        await fireEvent.click(
            within(current.container).getByRole("button", { name: "パスワードを表示" }),
        );
        expect(current.input.type).toBe("text");
        expect(next.input.type).toBe("password");

        // もう一度押すと password に戻る
        await fireEvent.click(
            within(current.container).getByRole("button", { name: "パスワードを非表示" }),
        );
        expect(current.input.type).toBe("password");

        // 新しいパスワード側も独立して切り替わる
        await fireEvent.click(
            within(next.container).getByRole("button", { name: "パスワードを表示" }),
        );
        expect(next.input.type).toBe("text");
        expect(current.input.type).toBe("password");
    });

    it("autocomplete / aria-describedby が PasswordInput を透過して保持される", () => {
        // FormField は error があるときだけ aria-describedby を生成するため、
        // 透過を検証するには両フィールドにエラーを載せた状態で描画する。
        formSeed.passwordErrors = {
            current_password: "現在のパスワードが違います",
            password: "パスワードは8文字以上必要です",
        };
        render(Index, { props: {} });

        const current = passwordField("現在のパスワード");
        const next = passwordField("新しいパスワード");

        expect(current.input).toHaveAttribute("autocomplete", "current-password");
        expect(next.input).toHaveAttribute("autocomplete", "new-password");
        // FormField 由来の aria-describedby (error id) が rest props として透過している
        expect(current.input).toHaveAttribute("aria-describedby", "current-password-error");
        expect(next.input).toHaveAttribute("aria-describedby", "new-password-error");
    });

    it("送信配線 (put ルート + errorBag) と bind:value が維持される", async () => {
        render(Index, { props: {} });

        const passwordForm = formHolder.password;
        expect(passwordForm).not.toBeNull();
        const putMock = passwordForm?.put as ReturnType<typeof vi.fn>;

        const current = passwordField("現在のパスワード");
        const next = passwordField("新しいパスワード");

        await fireEvent.input(current.input, { target: { value: "old-secret" } });
        await fireEvent.input(next.input, { target: { value: "new-secret" } });

        // bind:value が PasswordInput 経由でも form に反映される
        expect(passwordForm?.current_password).toBe("old-secret");
        expect(passwordForm?.password).toBe("new-secret");

        const saveButton = screen.getByRole("button", { name: "パスワードを変更" });
        await fireEvent.submit(saveButton.closest("form") as HTMLFormElement);

        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
        const call = putMock.mock.calls.at(-1);
        expect(call?.[0]).toBe("/user/password");
        const options = call?.[1] as { errorBag?: string };
        expect(options.errorBag).toBe("updatePassword");
    });
});

describe("Settings/Index パスワード変更の pending / エラークリア (F-4-01)", () => {
    /** submit ボタンを含む form 要素を null 安全に取得し、submit の完了を待つ */
    async function submitPasswordForm(): Promise<void> {
        const submit = screen.getByRole("button", { name: /パスワードを変更|変更中…/ });
        const formEl = submit.closest("form");
        expect(formEl).not.toBeNull();
        await fireEvent.submit(formEl as HTMLFormElement);
    }

    it("送信すると前回のエラー文言が pending 中に画面から消える (clearErrors)", async () => {
        formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
        render(Index, { props: {} });

        // 前回の失敗エラーが初期表示されている
        expect(screen.getByText("現在のパスワードが違います")).toBeInTheDocument();

        // 送信 → submitPassword が clearErrors() → 反応的 errors が空になり文言が DOM から消える
        await submitPasswordForm();

        await waitFor(() =>
            expect(screen.queryByText("現在のパスワードが違います")).toBeNull(),
        );
        // 送信自体は継続している (put が 1 回呼ばれる)
        const passwordForm = formHolder.password;
        const clearMock = passwordForm?.clearErrors as ReturnType<typeof vi.fn>;
        expect(clearMock).toHaveBeenCalledTimes(1);
        // 本フォームが所有するフィールドに限定してクリアする (過剰クリア防止の意図を固定)
        expect(clearMock).toHaveBeenCalledWith("current_password", "password");
        expect(passwordForm?.put as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
    });

    it("clearErrors は put より前に呼ばれる (pending 前にエラーを消す)", async () => {
        formSeed.passwordErrors = { current_password: "現在のパスワードが違います" };
        render(Index, { props: {} });

        await submitPasswordForm();

        const form = formHolder.password;
        const clearMock = form?.clearErrors as ReturnType<typeof vi.fn>;
        const putMock = form?.put as ReturnType<typeof vi.fn>;
        // まず両方が確実に 1 回ずつ呼ばれたことを確認 (invocationCallOrder が undefined になる偽陽性を防ぐ)
        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));
        expect(clearMock).toHaveBeenCalledTimes(1);
        // その上で呼び出し順序を比較する
        expect(clearMock.mock.invocationCallOrder[0]).toBeLessThan(
            putMock.mock.invocationCallOrder[0],
        );
    });

    it("送信中は『変更中…』文言 + disabled + aria-busy を示す", async () => {
        render(Index, { props: {} });

        // 通常時は「パスワードを変更」
        expect(screen.getByRole("button", { name: "パスワードを変更" })).toBeInTheDocument();

        // processing=true に切替 (反応的 double)。tick 1 回依存でフレークしないよう waitFor で待つ
        const form = formHolder.password as { processing: boolean };
        form.processing = true;

        await waitFor(() =>
            expect(screen.getByRole("button", { name: "変更中…" })).toBeInTheDocument(),
        );
        const busyButton = screen.getByRole("button", { name: "変更中…" });
        expect(busyButton).toBeDisabled();
        expect(busyButton).toHaveAttribute("aria-busy", "true");
    });

    it("成功時はフォームを reset する (成功トーストはサーバ flash 経由 = 別テストで担保)", async () => {
        render(Index, { props: {} });
        const form = formHolder.password;
        const putMock = form?.put as ReturnType<typeof vi.fn>;

        await submitPasswordForm();
        await waitFor(() => expect(putMock).toHaveBeenCalledTimes(1));

        // put のオプションの onSuccess が reset を呼ぶ配線を検証
        const options = putMock.mock.calls.at(-1)?.[1] as { onSuccess?: () => void };
        options.onSuccess?.();
        expect(form?.reset as ReturnType<typeof vi.fn>).toHaveBeenCalledTimes(1);
    });
});

/*
 * パスワードカードの 3 値出し分け (施策 7)。
 *
 * password 未設定ユーザーに `current_password` 必須の変更フォームを出すと**必ず失敗する**
 * (カード丸ごとが踏破不能 = 監査 F-2 と同 species)。初回設定フォームへ切り替える。
 * prop 欠落 (状態不明) を false に倒すと、設定済みユーザーに初回設定フォームを出す
 * = 「状態不明を誤った UI に倒す」の再演になるため 3 値で扱う。
 */
describe("Settings/Index パスワードカードの出し分け (施策 7)", () => {
    /** recent-auth precheck を fresh に固定する (初回設定も step-up 必須) */
    function stubFresh(): void {
        stubRecentAuthFresh();
    }

    it("hasPassword=false なら初回設定フォームを出し「現在のパスワード」欄を描画しない", () => {
        setProps({ hasPassword: false });
        render(Index, { props: {} });

        expect(screen.getByRole("heading", { name: "パスワードを設定" })).toBeInTheDocument();
        expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
        expect(screen.getByLabelText("新しいパスワード")).toBeInTheDocument();
    });

    it("hasPassword=true なら従来どおり変更フォーム (現在のパスワード欄あり)", () => {
        setProps({ hasPassword: true });
        render(Index, { props: {} });

        expect(screen.getByRole("heading", { name: "パスワード変更" })).toBeInTheDocument();
        expect(screen.getByLabelText("現在のパスワード")).toBeInTheDocument();
        expect(screen.queryByTestId("set-password-button")).toBeNull();
    });

    it("hasPassword 欠落 (状態不明) はどちらのフォームも出さず再読み込み導線を出す", async () => {
        setProps({ hasPassword: undefined });
        render(Index, { props: {} });

        expect(screen.getByTestId("password-state-unknown")).toBeInTheDocument();
        expect(screen.queryByLabelText("現在のパスワード")).toBeNull();
        expect(screen.queryByTestId("set-password-button")).toBeNull();

        await fireEvent.click(screen.getByRole("button", { name: "再読み込み" }));
        expect(routerReloadMock).toHaveBeenCalledTimes(1);
    });

    it("設定ボタンは常に活性 (必須条件未充足でも disabled にしない)", () => {
        setProps({ hasPassword: false });
        render(Index, { props: {} });

        expect(screen.getByTestId("set-password-button")).not.toBeDisabled();
    });

    it("fresh なら /settings/password へ 1 回だけ post する", async () => {
        setProps({ hasPassword: false });
        stubFresh();
        render(Index, { props: {} });

        await fireEvent.input(screen.getByLabelText("新しいパスワード"), {
            target: { value: "Str0ng-Passphrase!" },
        });
        await fireEvent.submit(
            screen.getByTestId("set-password-button").closest("form") as HTMLFormElement,
        );

        const postMock = formHolder.passwordSetup?.post as ReturnType<typeof vi.fn>;
        await waitFor(() => expect(postMock).toHaveBeenCalledTimes(1));
        expect(postMock.mock.calls.at(-1)?.[0]).toBe("/settings/password");
    });

    it("stale なら post せず再認証モーダルを開く (precheck)", async () => {
        setProps({ hasPassword: false });
        stubRecentAuthStaleThenConfirm();
        render(Index, { props: {} });

        await fireEvent.input(screen.getByLabelText("新しいパスワード"), {
            target: { value: "Str0ng-Passphrase!" },
        });
        await fireEvent.submit(
            screen.getByTestId("set-password-button").closest("form") as HTMLFormElement,
        );

        await waitFor(() =>
            expect(screen.getByTestId("recent-auth-modal")).toBeInTheDocument(),
        );
        expect(formHolder.passwordSetup?.post as ReturnType<typeof vi.fn>).not.toHaveBeenCalled();
    });
});

/*
 * T142: 猶予期間つき退会 (凍結方式) の UI 契約。
 * - 未予約: 主導線は「N 日後に削除 (取り消せます)」、即時削除は副導線として併存
 * - 予約中: バナー + 取消ボタンだけを出し、削除ボタン群は出さない
 *   (サーバ側で settings.account.destroy が凍結遮断されることと UI を一致させる)
 */
describe("Settings/Index 退会予約 (猶予期間つき削除)", () => {
    it("未予約時は予約が主導線で、即時削除が副導線として併存する", () => {
        setProps({
            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
            accountDeletionGraceDays: 30,
        });
        render(Index, { props: {} });

        const request = screen.getByTestId("request-deletion-button");
        const immediate = screen.getByTestId("delete-account-button");
        expect(request).toHaveTextContent("30日後に削除 (取り消せます)");
        expect(immediate).toHaveTextContent("今すぐ完全に削除する (取り消せません)");

        // 主導線が先に現れる (視覚的優先度を口約束にしない)
        expect(request.compareDocumentPosition(immediate) & Node.DOCUMENT_POSITION_FOLLOWING)
            .toBeTruthy();
        // 予約バナーは出ない
        expect(screen.queryByTestId("deletion-request-banner")).toBeNull();
    });

    it("予約中はバナーと取消ボタンが出て、削除ボタン群は出ない", () => {
        setProps({
            accountDeletionState: {
                requestedAt: "2026-08-10T09:00:00+09:00",
                purgeAfter: "2026-09-09T09:00:00+09:00",
                graceDays: 30,
            },
            accountDeletionGraceDays: 30,
        });
        render(Index, { props: {} });

        expect(screen.getByTestId("deletion-request-banner")).toBeInTheDocument();
        expect(screen.getByTestId("cancel-deletion-request-button")).toBeInTheDocument();
        expect(screen.getByTestId("deletion-request-purge-after")).toHaveTextContent("2026");
        expect(screen.queryByTestId("request-deletion-button")).toBeNull();
        expect(screen.queryByTestId("delete-account-button")).toBeNull();
    });

    it("取消は step-up precheck を挟まずに DELETE する (救済経路に関門を置かない)", async () => {
        setProps({
            accountDeletionState: {
                requestedAt: "2026-08-10T09:00:00+09:00",
                purgeAfter: "2026-09-09T09:00:00+09:00",
                graceDays: 30,
            },
        });
        const fetchSpy = vi.fn();
        vi.stubGlobal("fetch", fetchSpy);
        render(Index, { props: {} });

        await fireEvent.click(screen.getByTestId("cancel-deletion-request-button"));

        await waitFor(() => expect(routerDeleteMock).toHaveBeenCalled());
        expect(routerDeleteMock.mock.calls.at(-1)?.[0]).toBe("/settings/account/deletion-request");
        // recent-auth の precheck (fetch) を一切踏まない
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    it("予約は recent-auth precheck を通ってから POST する", async () => {
        setProps({
            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
            accountDeletionGraceDays: 30,
        });
        stubRecentAuthFresh();
        render(Index, { props: {} });

        await fireEvent.click(screen.getByTestId("request-deletion-button"));

        await waitFor(() => expect(routerPostMock).toHaveBeenCalled());
        expect(routerPostMock.mock.calls.at(-1)?.[0]).toBe("/settings/account/deletion-request");
    });

    it("ブロッカーがあっても予約ボタンは disabled にならない (禁止事項 8)", () => {
        setProps({
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
            ],
            accountDeletionState: { requestedAt: null, purgeAfter: null, graceDays: null },
            accountDeletionGraceDays: 30,
        });
        render(Index, { props: {} });

        expect(screen.getByTestId("request-deletion-button")).not.toBeDisabled();
        expect(screen.getByTestId("delete-account-button")).not.toBeDisabled();
    });

    it("予約中でもブロッカーの次の一手は表示され続ける (解消導線を隠さない)", () => {
        setProps({
            accountDeletionBlockers: [
                { name: "現場A", slug: "genba-a", actions: ["transfer_ownership"] },
            ],
            accountDeletionState: {
                requestedAt: "2026-08-10T09:00:00+09:00",
                purgeAfter: "2026-09-09T09:00:00+09:00",
                graceDays: 30,
            },
        });
        render(Index, { props: {} });

        expect(screen.getByText("退会するには先に対応が必要です")).toBeInTheDocument();
        expect(screen.getByText("オーナーを移譲する")).toBeInTheDocument();
        expect(screen.getByTestId("cancel-deletion-request-button")).toBeInTheDocument();
    });
});
