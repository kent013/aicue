import { afterEach, describe, expect, it } from "vitest";
import { cleanup, render, screen } from "@testing-library/svelte";
import Dashboard from "@/pages/Dashboard.svelte";
import type { BillingSummary, DashboardData } from "@/types/dashboard";

/**
 * ダッシュボードの表示分岐 (state / ロール / 空状態 / 課金 callout) を固定する。
 * shared props (未読数等) は page store 未設定でも安全に fallback する。
 */

function billingData(overrides: Partial<BillingSummary> = {}): BillingSummary {
    return {
        ticket_balance: 10,
        is_low_balance: false,
        storage_used_bytes: 250 * 1024 * 1024,
        storage_limit_bytes: 1024 * 1024 * 1024,
        storage_usage_percent: 25,
        has_active_subscription: true,
        ...overrides,
    };
}

function dashboardData(overrides: Partial<DashboardData> = {}): DashboardData {
    return {
        state: "ready",
        role: "editor",
        can_create_project: false,
        organization_name: "テスト組織",
        project: { id: 1, name: "テストプロジェクト" },
        in_progress: [],
        recent_manuals: [],
        shooting_targets: [],
        billing: billingData(),
        ...overrides,
    };
}

function fullData(): DashboardData {
    return dashboardData({
        in_progress: [
            {
                manual_id: 10,
                title: "解析中マニュアル",
                manual_status: "analyzing",
                job_status: "running",
                progress: 40,
                job_updated_at: "2026-07-12 00:30",
            },
            {
                manual_id: 11,
                title: "準備中マニュアル",
                manual_status: "rendering",
                job_status: null,
                progress: null,
                job_updated_at: null,
            },
        ],
        recent_manuals: [
            {
                id: 20,
                title: "最近のマニュアル",
                status: "ready",
                category_name: "準備作業",
                updated_at: "2026-07-11 12:00",
            },
        ],
        shooting_targets: [
            { manual_id: 30, title: "撮影対象マニュアル", cuts_count: 4, pending_cuts_count: 2 },
        ],
    });
}

afterEach(() => {
    cleanup();
});

describe("Dashboard", () => {
    it("ready: スタットタイルが値どおり描画される", () => {
        render(Dashboard, { props: { dashboard: fullData() } });

        expect(screen.getByTestId("stat-tickets")).toHaveTextContent("10");
        expect(screen.getByTestId("stat-storage")).toHaveTextContent("25%");
        expect(screen.getByTestId("stat-storage")).toHaveTextContent("250.0 MB / 1.0 GB");
        expect(screen.getByTestId("stat-unread")).toHaveTextContent("0");
        expect(screen.getByTestId("stat-inprogress")).toHaveTextContent("2");
    });

    it("容量 limit null は「無制限」を表示する", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    billing: billingData({
                        storage_limit_bytes: null,
                        storage_usage_percent: null,
                    }),
                }),
            },
        });

        expect(screen.getByTestId("stat-storage")).toHaveTextContent("無制限");
    });

    it("進行中ジョブ行: progress bar / 最終更新 / 詳細導線 href が正しい", () => {
        render(Dashboard, { props: { dashboard: fullData() } });

        const items = screen.getAllByTestId("inprogress-item");
        expect(items).toHaveLength(2);

        const bar = screen.getByTestId("inprogress-bar");
        expect(bar).toHaveAttribute("aria-valuenow", "40");
        expect(items[0]).toHaveTextContent("最終更新 2026-07-12 00:30");
        expect(items[0]).toHaveTextContent("解析中");

        // job/progress null の行は progressbar を描画せず「準備中」表示
        expect(items[1]).toHaveTextContent("準備中");
        expect(items[1].querySelector('[role="progressbar"]')).toBeNull();

        const links = screen.getAllByTestId("inprogress-detail-link");
        expect(links[0].getAttribute("href")).toMatch(/\/projects\/1\/manuals\/10$/);
    });

    it("role=editor: 新規作成・カテゴリ管理・編集導線が描画される", () => {
        render(Dashboard, { props: { dashboard: fullData() } });

        expect(screen.getByTestId("qa-create-manual").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/create$/,
        );
        expect(screen.getByTestId("qa-categories").getAttribute("href")).toMatch(
            /\/projects\/1\/categories$/,
        );
        expect(screen.getByTestId("qa-capture").getAttribute("href")).toMatch(/\/app$/);
        expect(screen.getByTestId("recent-edit-link").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/20\/edit$/,
        );
    });

    it("role=shooter: 編集者専用導線が存在せず、撮影対象が最近のマニュアルより先頭", () => {
        const data = fullData();
        data.role = "shooter";
        render(Dashboard, { props: { dashboard: data } });

        expect(screen.queryByTestId("qa-create-manual")).toBeNull();
        expect(screen.queryByTestId("qa-categories")).toBeNull();
        expect(screen.queryByTestId("recent-edit-link")).toBeNull();
        expect(screen.getByTestId("qa-capture")).toBeInTheDocument();
        expect(screen.getByTestId("shoot-button").getAttribute("href")).toMatch(
            /\/app\/projects\/1\/manuals\/30$/,
        );

        // DOM 順: shooting-card が recent-card より前
        const shooting = screen.getByTestId("shooting-card");
        const recent = screen.getByTestId("recent-card");
        expect(
            shooting.compareDocumentPosition(recent) & Node.DOCUMENT_POSITION_FOLLOWING,
        ).toBeTruthy();
    });

    it("role=viewer: クイックアクション自体が非描画", () => {
        const data = fullData();
        data.role = "viewer";
        render(Dashboard, { props: { dashboard: data } });

        expect(screen.queryByTestId("quick-actions")).toBeNull();
        expect(screen.queryByTestId("recent-edit-link")).toBeNull();
    });

    it("空状態: no_organization は組織作成 CTA", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    state: "no_organization",
                    role: null,
                    organization_name: null,
                    project: null,
                    billing: null,
                }),
            },
        });

        expect(screen.getByTestId("dashboard-setup-org")).toBeInTheDocument();
        expect(screen.getByText("組織を作成")).toBeInTheDocument();
    });

    it("空状態: no_project (can_create_project=true) はプロジェクト作成 CTA", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    state: "no_project",
                    role: null,
                    can_create_project: true,
                    project: null,
                }),
            },
        });

        expect(screen.getByTestId("dashboard-setup-project")).toBeInTheDocument();
        expect(screen.getByText("プロジェクトを作成")).toBeInTheDocument();
    });

    it("空状態: no_project (can_create_project=false) は組織名入りの案内文 (CTA 非描画)", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    state: "no_project",
                    role: null,
                    can_create_project: false,
                    organization_name: "依頼先組織",
                    project: null,
                }),
            },
        });

        const guidance = screen.getByTestId("no-project-guidance");
        expect(guidance).toHaveTextContent("依頼先組織");
        expect(guidance).toHaveTextContent("プロジェクト作成を依頼してください");
        expect(screen.queryByText("プロジェクトを作成")).toBeNull();
    });

    it("空状態: manual 0 件は editor に作成 CTA / shooter に案内文", () => {
        render(Dashboard, { props: { dashboard: dashboardData() } });
        expect(screen.getByTestId("recent-empty")).toHaveTextContent("最初のマニュアルを作成");
        expect(screen.getByTestId("shooting-empty")).toHaveTextContent(
            "撮影対象はまだありません",
        );
        cleanup();

        render(Dashboard, { props: { dashboard: dashboardData({ role: "shooter" }) } });
        expect(screen.getByTestId("recent-empty")).toHaveTextContent(
            "編集者が作成すると、ここに表示されます",
        );
    });

    it("is_low_balance=true で購入導線が出る", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    billing: billingData({ ticket_balance: 2, is_low_balance: true }),
                }),
            },
        });

        expect(screen.getByTestId("purchase-link").getAttribute("href")).toMatch(
            /\/purchase-tickets$/,
        );
        expect(screen.getByTestId("stat-tickets")).toHaveTextContent("残高が少なくなっています");
    });

    it("has_active_subscription=false で billing callout が出る", () => {
        render(Dashboard, {
            props: {
                dashboard: dashboardData({
                    billing: billingData({ has_active_subscription: false }),
                }),
            },
        });

        const callout = screen.getByTestId("billing-callout");
        expect(callout).toBeInTheDocument();
        expect(screen.getByText("プランを見る").getAttribute("href")).toMatch(/\/billing$/);
    });

    it("disabled 属性を持つ要素が 1 つも存在しない", () => {
        const { container } = render(Dashboard, { props: { dashboard: fullData() } });

        expect(container.querySelectorAll("[disabled]")).toHaveLength(0);
    });

    it("page-local の設定/ログアウトを持たない (AppLayout 常設ナビに一本化)", () => {
        // テスト環境は page store 未設定 = auth なしのため AppLayout の常設ナビは描画されない。
        // 旧実装の page-local headerActions snippet が残っていれば auth なしでも描画されるため、
        // どちらも null であることが旧実装残存を確実に検出する (F-08 の回帰固定)。
        // logout POST は AppLayout の単一ハンドラの責務であり、Dashboard 内のイベントから
        // router.post("/logout") を直接呼ばない。
        render(Dashboard, { props: { dashboard: fullData() } });

        expect(screen.queryByRole("link", { name: "設定" })).toBeNull();
        expect(screen.queryByRole("button", { name: "ログアウト" })).toBeNull();
    });
});
