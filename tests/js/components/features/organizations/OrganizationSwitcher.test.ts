import { afterEach, describe, expect, it, vi } from "vitest";
import { cleanup, fireEvent, render, screen } from "@testing-library/svelte";
import { tick } from "svelte";
import OrganizationSwitcher from "@/components/features/organizations/OrganizationSwitcher.svelte";
import type { CurrentOrganization, OrganizationSummary } from "@/lib/shared-props";

// router.post をモックし Link は原物を使う (AppLayout.test.ts パターン準拠)
const { routerMock } = vi.hoisted(() => ({
    routerMock: { post: vi.fn() },
}));

vi.mock("@inertiajs/svelte", async (importOriginal) => ({
    ...(await importOriginal<typeof import("@inertiajs/svelte")>()),
    router: routerMock,
}));

function currentOrg(overrides: Partial<CurrentOrganization> = {}): CurrentOrganization {
    return {
        id: 1,
        name: "現在組織",
        slug: "current-org",
        role: "organization_owner",
        canManageMembers: true,
        canManageApiKeys: true,
        ...overrides,
    };
}

function org(id: number, name: string, isPersonal = false): OrganizationSummary {
    return { id, name, isPersonal };
}

/** トリガーを押してパネルを開く ($effect の click-outside 登録まで待つ) */
async function openPanel(): Promise<void> {
    await fireEvent.click(screen.getByTestId("org-switcher-trigger"));
    await tick();
}

afterEach(() => {
    cleanup();
    routerMock.post.mockReset();
});

describe("features/organizations/OrganizationSwitcher", () => {
    it("トリガーに現在組織名を表示する", () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg({ name: "アクメ社" }), organizations: [] },
        });

        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("アクメ社");
    });

    it("トリガー押下でパネルが開き aria-expanded が false↔true する", async () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg(), organizations: [] },
        });
        const trigger = screen.getByTestId("org-switcher-trigger");
        expect(trigger).toHaveAttribute("aria-expanded", "false");

        await openPanel();

        expect(trigger).toHaveAttribute("aria-expanded", "true");
        expect(document.getElementById("org-switcher-panel")).not.toBeNull();
    });

    it("2 組織以上なら他組織の切替ボタンを描画し、押下で /organizations/{id}/switch を POST する", async () => {
        render(OrganizationSwitcher, {
            props: {
                currentOrganization: currentOrg({ id: 1 }),
                organizations: [org(1, "現在組織"), org(2, "別組織")],
            },
        });
        await openPanel();

        await fireEvent.click(screen.getByTestId("org-switch-2"));

        expect(routerMock.post).toHaveBeenCalledTimes(1);
        expect(routerMock.post.mock.calls[0][0]).toBe("/organizations/2/switch");
    });

    it("現在組織行は切替ボタンにならない (aria-current + ラベル、押下で POST しない)", async () => {
        render(OrganizationSwitcher, {
            props: {
                currentOrganization: currentOrg({ id: 1 }),
                organizations: [org(1, "現在組織"), org(2, "別組織")],
            },
        });
        await openPanel();

        const currentRow = screen.getByTestId("org-current-1");
        expect(currentRow).toHaveAttribute("aria-current", "true");
        expect(currentRow).toHaveTextContent("現在の組織");
        // 現在組織は切替ボタンとして描画されない
        expect(screen.queryByTestId("org-switch-1")).toBeNull();

        await fireEvent.click(currentRow);
        expect(routerMock.post).not.toHaveBeenCalled();
    });

    it("1 組織のみなら切替セクションを描画しない", async () => {
        render(OrganizationSwitcher, {
            props: {
                currentOrganization: currentOrg({ id: 1 }),
                organizations: [org(1, "現在組織")],
            },
        });
        await openPanel();

        expect(screen.queryByTestId("org-current-1")).toBeNull();
        expect(screen.queryByTestId("org-switch-1")).toBeNull();
        // 管理リンクは出る
        expect(screen.getByTestId("org-link-settings")).toBeInTheDocument();
    });

    it("権限フラグでメンバー管理 / API キーリンクを出し分ける", async () => {
        render(OrganizationSwitcher, {
            props: {
                currentOrganization: currentOrg({
                    slug: "acme",
                    canManageMembers: false,
                    canManageApiKeys: false,
                }),
                organizations: [],
            },
        });
        await openPanel();

        expect(screen.queryByTestId("org-link-members")).toBeNull();
        expect(screen.queryByTestId("org-link-api-keys")).toBeNull();
        // 常時表示のリンク
        expect(screen.getByTestId("org-link-billing")).toBeInTheDocument();
        expect(screen.getByTestId("org-link-pricing")).toBeInTheDocument();
        const settingsHref = screen.getByTestId("org-link-settings").getAttribute("href") ?? "";
        expect(new URL(settingsHref, "http://localhost").pathname).toBe(
            "/organizations/acme/settings",
        );
    });

    it("権限フラグ true でメンバー管理 / API キーリンクを表示する", async () => {
        render(OrganizationSwitcher, {
            props: {
                currentOrganization: currentOrg({
                    slug: "acme",
                    canManageMembers: true,
                    canManageApiKeys: true,
                }),
                organizations: [],
            },
        });
        await openPanel();

        expect(screen.getByTestId("org-link-members")).toBeInTheDocument();
        const apiHref = screen.getByTestId("org-link-api-keys").getAttribute("href") ?? "";
        expect(new URL(apiHref, "http://localhost").pathname).toBe("/organizations/acme/api-keys");
    });

    it("currentOrganization=null なら組織を作成のみ表示し切替/管理リンクは出さない", async () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: null, organizations: [] },
        });
        expect(screen.getByTestId("org-switcher-trigger")).toHaveTextContent("組織を選択");

        await openPanel();

        const createHref = screen.getByTestId("org-link-create").getAttribute("href") ?? "";
        expect(new URL(createHref, "http://localhost").pathname).toBe("/organizations/create");
        expect(screen.queryByTestId("org-link-settings")).toBeNull();
        expect(screen.queryByTestId("org-link-billing")).toBeNull();
    });

    it("Escape でパネルを閉じ、トリガーへ focus を復帰する", async () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg(), organizations: [] },
        });
        await openPanel();
        expect(document.getElementById("org-switcher-panel")).not.toBeNull();

        // 実装は open 中のみ document に keydown を張るため、発火対象も document に合わせる
        await fireEvent.keyDown(document, { key: "Escape" });

        expect(document.getElementById("org-switcher-panel")).toBeNull();
        // S3 a11y 要件: Escape 後はトリガーへ focus 復帰する
        expect(screen.getByTestId("org-switcher-trigger")).toHaveFocus();
    });

    it("ルート外の pointerdown でパネルを閉じる", async () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg(), organizations: [] },
        });
        await openPanel();
        expect(document.getElementById("org-switcher-panel")).not.toBeNull();

        await fireEvent.pointerDown(document.body);
        await tick();

        expect(document.getElementById("org-switcher-panel")).toBeNull();
    });

    it("focusout でルート外へ抜けたらパネルを閉じる", async () => {
        const { container } = render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg(), organizations: [] },
        });
        await openPanel();
        expect(document.getElementById("org-switcher-panel")).not.toBeNull();

        await fireEvent.focusOut(container.firstElementChild as Element, {
            relatedTarget: document.body,
        });

        expect(document.getElementById("org-switcher-panel")).toBeNull();
    });

    it("トリガーは disabled 属性を持たず、パネルは aria-labelledby でトリガーに紐づく (a11y)", async () => {
        render(OrganizationSwitcher, {
            props: { currentOrganization: currentOrg(), organizations: [] },
        });
        expect(screen.getByTestId("org-switcher-trigger")).not.toBeDisabled();

        await openPanel();

        expect(document.getElementById("org-switcher-panel")).toHaveAttribute(
            "aria-labelledby",
            "org-switcher-trigger",
        );
    });
});
