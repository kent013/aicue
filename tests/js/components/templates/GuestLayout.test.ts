import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { createRawSnippet } from "svelte";
import { cleanup, fireEvent, render, screen, within } from "@testing-library/svelte";
import { page } from "@inertiajs/svelte";
import GuestLayout from "@/components/templates/GuestLayout.svelte";
import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
import { addToast, clearToasts } from "@/lib/stores/toast";

/*
 * GuestLayout の狭幅ハンバーガー化 (T027)。nav 未指定でトグル・パネルが出ないこと、
 * nav 指定でトグルが出ることを固定する。実挙動 (Escape / パネル内リンク) の主検証は
 * Welcome.test.ts が担う。
 *
 * 併せて flash → toast の取り込みと消去境界 (T095 / DESIGN.md §Toast) を固定する:
 * 未認証面に着地する破壊的操作 (settings.account.destroy) の成功メッセージが表示され、
 * かつ認証済み文脈の toast (氏名・組織名を含みうる) を持ち越さないこと。
 */

const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));
const nav = createRawSnippet(() => ({
    render: () => `<a href="/pricing">料金プラン</a>`,
}));

function setPageProps(props: Record<string, unknown>): void {
    page.props = props as typeof page.props;
}

beforeEach(() => {
    clearToasts();
    resetFlashConsumption();
    setPageProps({});
});

afterEach(() => {
    cleanup();
    clearToasts();
    setPageProps({});
});

describe("GuestLayout", () => {
    it("nav を渡さないとハンバーガー・パネルを描画しない (Contact 相当)", () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children } });

        expect(screen.queryByTestId("guest-nav-toggle")).not.toBeInTheDocument();
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    });

    it("nav を渡すとトグルが出て、押下でパネルが開く", async () => {
        render(GuestLayout, { props: { appName: "AI-CUE", children, nav } });

        const toggle = screen.getByTestId("guest-nav-toggle");
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
        await fireEvent.click(toggle);
        const panel = screen.getByTestId("guest-nav-panel");
        expect(within(panel).getByRole("link", { name: "料金プラン" })).toBeInTheDocument();
    });

    it("flash.success を toast として描画する (未認証面に着地する破壊的操作のフィードバック)", async () => {
        setPageProps({
            flash: { success: "アカウントを削除しました", visitKey: "visit-1" },
        });

        render(GuestLayout, { props: { appName: "AI-CUE", children } });

        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "アカウントを削除しました",
        );
    });

    it("着地前から存在する toast は描画しない (認証文脈の持ち越し防止)", async () => {
        addToast("success", "「山田太郎」の 2 段階認証を解除しました");
        setPageProps({
            flash: { success: "アカウントを削除しました", visitKey: "visit-2" },
        });

        render(GuestLayout, { props: { appName: "AI-CUE", children } });

        // 当該 visit の flash は出る
        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "アカウントを削除しました",
        );
        // 認証済み画面で積まれた PII 入り toast は消えている
        expect(screen.queryByText("「山田太郎」の 2 段階認証を解除しました")).toBeNull();
    });

    it("再レンダー (props 更新) では clear が走らない (初期化時の 1 回のみ)", async () => {
        const { rerender } = render(GuestLayout, { props: { appName: "AI-CUE", children } });

        addToast("info", "レンダー後に積んだ通知");
        expect(await screen.findByTestId("toast-info")).toBeInTheDocument();

        await rerender({ appName: "別名", children });

        expect(screen.getByTestId("toast-info")).toHaveTextContent("レンダー後に積んだ通知");
    });
});
