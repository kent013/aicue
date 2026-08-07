import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Button from "@/components/atoms/Button.svelte";
import ErrorPage from "@/pages/Error.svelte";
import type { ErrorScreenProps } from "@/types/error-screen";

/*
 * Inertia の `Link` は素の <a href> として描画され、判別できる属性を持たない。
 * そのため「Inertia Link ではない」を描画結果 (tagName / data-*) だけで検証すると空振りする。
 * `Link` をスタブへ差し替え、**描画されたら印が残る**状態にして退行を確実に検出する
 * (Codex impl-review R1 [Critical])。Error.svelte が transitively import する
 * `@inertiajs/svelte` の使用箇所は Button の anchor モード分岐だけなので全置換で足りる。
 */
vi.mock("@inertiajs/svelte", async () => ({
    Link: (await import("../support/InertiaLinkStub.svelte")).default,
}));

const baseProps: ErrorScreenProps = {
    status: 404,
    title: "ページが見つかりません",
    message: "お探しのページは存在しないか、移動された可能性があります。",
    retryAfterSeconds: null,
    destinations: [
        { label: "ログインへ", href: "/login" },
        { label: "トップへ", href: "/" },
    ],
};

describe("pages/Error", () => {
    it("status / title / message / 戻り先を描画する", () => {
        render(ErrorPage, { props: baseProps });

        expect(screen.getByTestId("error-screen")).toBeInTheDocument();
        expect(screen.getByTestId("error-status")).toHaveTextContent("404");
        expect(screen.getByRole("heading", { name: "ページが見つかりません" })).toBeInTheDocument();
        expect(screen.getByText(baseProps.message)).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "ログインへ" })).toBeInTheDocument();
        expect(screen.getByRole("link", { name: "トップへ" })).toBeInTheDocument();
    });

    it("retryAfterSeconds が null なら待ち時間を描画しない", () => {
        render(ErrorPage, { props: baseProps });

        expect(screen.queryByTestId("error-retry-after")).toBeNull();
    });

    it("retryAfterSeconds があれば秒数を描画する", () => {
        render(ErrorPage, {
            props: { ...baseProps, status: 429, retryAfterSeconds: 30 },
        });

        expect(screen.getByTestId("error-retry-after")).toHaveTextContent("30");
    });

    it("戻り先が通常の <a href> で描画される (Inertia Link を使わない)", () => {
        // 419 の原因が古い CSRF token のとき、SPA 遷移では同じ document を保つため
        // 遷移後の POST で同じ 419 を踏み直す。document を作り直して初めて復旧する。
        render(ErrorPage, { props: baseProps });

        expect(screen.queryByTestId("inertia-link-stub")).toBeNull();

        const link = screen.getByRole("link", { name: "ログインへ" });
        expect(link.tagName).toBe("A");
        expect(link.getAttribute("href")).toBe("/login");
    });

    it("負のコントロール: Inertia 遷移にすればスタブが描画される (上の検査が空振りでない証拠)", () => {
        render(Button, { props: { href: "/login", inertia: true } });

        expect(screen.getByTestId("inertia-link-stub")).toBeInTheDocument();
    });

    it("disabled な CTA を作らない", () => {
        render(ErrorPage, { props: baseProps });

        for (const link of screen.getAllByRole("link")) {
            expect(link.getAttribute("aria-disabled")).toBeNull();
            expect(link.getAttribute("tabindex")).toBeNull();
        }
    });
});
