import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/svelte";
import Welcome from "@/pages/Welcome.svelte";
import type { LandingPageProps } from "@/types/marketing";

/*
 * LP (トップ)。North Star (SOP → AI カット設計 → PWA ナビ撮影 → 自動合成) の訴求と
 * 認証状態別 CTA、disabled 不使用 (DESIGN.md) を固定する。
 */

const guestPage: LandingPageProps = {
    signupGrantTickets: 10,
    contactUrl: "/contact?source=landing",
    contactIsExternal: false,
    isAuthenticated: false,
};

const baseProps = { appName: "AI-CUE", page: guestPage };

describe("Welcome (LP)", () => {
    it("hero 見出しと登録 CTA・仕組みリンクを描画する", () => {
        render(Welcome, { props: baseProps });

        expect(screen.getByRole("heading", { level: 1 })).toHaveTextContent(
            "動画マニュアルを、手順書から。",
        );
        expect(screen.getByTestId("hero-register")).toBeInTheDocument();
        expect(screen.getByRole("link", { name: /仕組みを見る/ })).toBeInTheDocument();
    });

    it("3 つの壁と 4 ステップ・料金 CTA (signup grant 枚数) を描画する", () => {
        render(Welcome, { props: baseProps });

        expect(screen.getByRole("heading", { name: "台本作成の壁" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "撮影判断の壁" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "編集の壁" })).toBeInTheDocument();

        expect(screen.getByRole("heading", { name: "手順書をアップロード" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "AI がカットを設計" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "スマホでナビ撮影" })).toBeInTheDocument();
        expect(screen.getByRole("heading", { name: "自動で動画に合成" })).toBeInTheDocument();

        expect(screen.getByTestId("landing-pricing-cta")).toHaveTextContent(
            "新規登録でチケット 10 枚が無料",
        );
        expect(screen.getByRole("link", { name: /料金プランを見る/ })).toBeInTheDocument();
    });

    it("未認証では登録 CTA、認証済みではダッシュボード CTA を出す", () => {
        const { unmount } = render(Welcome, { props: baseProps });
        expect(screen.getAllByRole("link", { name: "無料で始める" }).length).toBeGreaterThan(0);
        unmount();

        render(Welcome, {
            props: { ...baseProps, page: { ...guestPage, isAuthenticated: true } },
        });
        expect(screen.getAllByRole("link", { name: /ダッシュボードへ/ }).length).toBeGreaterThan(
            0,
        );
    });

    it("問い合わせリンクは内部宛先では同タブ、外部宛先では新規タブで開く", () => {
        const { unmount } = render(Welcome, { props: baseProps });
        const internal = screen.getByTestId("landing-contact-link");
        expect(new URL((internal as HTMLAnchorElement).href).pathname).toBe("/contact");
        expect(internal).not.toHaveAttribute("target");
        unmount();

        render(Welcome, {
            props: {
                ...baseProps,
                page: {
                    ...guestPage,
                    contactUrl: "https://forms.example.com/contact",
                    contactIsExternal: true,
                },
            },
        });
        const external = screen.getByTestId("landing-contact-link");
        expect(external).toHaveAttribute("target", "_blank");
        expect(external).toHaveAttribute("rel", "noopener noreferrer");
    });

    it("disabled 属性を持つ button が存在しない (DESIGN.md)", () => {
        const { container } = render(Welcome, { props: baseProps });

        expect(container.querySelectorAll("button[disabled]")).toHaveLength(0);
    });
});
