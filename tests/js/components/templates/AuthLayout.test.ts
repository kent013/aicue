import { afterEach, beforeEach, describe, expect, it } from "vitest";
import { createRawSnippet } from "svelte";
import { cleanup, render, screen } from "@testing-library/svelte";
import { page } from "@inertiajs/svelte";
import AuthLayout from "@/components/templates/AuthLayout.svelte";
import { resetFlashConsumption } from "@/lib/stores/flash-to-toast";
import { addToast, clearToasts } from "@/lib/stores/toast";

/*
 * AuthLayout の flash 取り込みと toast の消去境界 (T095 / DESIGN.md §Toast)。
 * 未認証 layout は「初期化時に既存 toast を破棄 → 当該 visit の flash を消費」の順で動く
 * (認証済み文脈の toast は氏名・組織名を含みうるため未認証面へ持ち越さない)。
 */

const children = createRawSnippet(() => ({ render: () => `<p>content</p>` }));

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

describe("templates/AuthLayout", () => {
    it("flash.success を toast として描画する", async () => {
        setPageProps({ flash: { success: "パスワードを再設定しました", visitKey: "visit-1" } });

        render(AuthLayout, { props: { title: "ログイン", children } });

        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "パスワードを再設定しました",
        );
    });

    it("着地前から存在する toast は描画しない (認証文脈の持ち越し防止)", async () => {
        addToast("success", "「アクメ社」に切り替えました");
        setPageProps({ flash: { success: "パスワードを再設定しました", visitKey: "visit-2" } });

        render(AuthLayout, { props: { title: "ログイン", children } });

        expect(await screen.findByTestId("toast-success")).toHaveTextContent(
            "パスワードを再設定しました",
        );
        expect(screen.queryByText("「アクメ社」に切り替えました")).toBeNull();
    });

    it("再レンダー (props 更新) では clear が走らない (初期化時の 1 回のみ)", async () => {
        const { rerender } = render(AuthLayout, { props: { title: "ログイン", children } });

        addToast("info", "レンダー後に積んだ通知");
        expect(await screen.findByTestId("toast-info")).toBeInTheDocument();

        await rerender({ title: "パスワード再設定", children });

        expect(screen.getByTestId("toast-info")).toHaveTextContent("レンダー後に積んだ通知");
    });
});
