import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, within } from "@testing-library/svelte";
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

    it("既定ではモバイルパネルを描画せずナビリンクが単一ヒットする", () => {
        render(Welcome, { props: baseProps });

        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
        // 広幅ナビのみ = 単一ヒット (hidden sm:flex は jsdom で DOM に残るが二重化しない)。
        // "ログイン" は nav 専用リンク (footer には無い) のため単一ヒットで固定できる。
        expect(screen.getByRole("link", { name: "ログイン" })).toBeInTheDocument();
    });

    it("ハンバーガー押下でモバイルパネルが開き aria-expanded が切り替わる", async () => {
        render(Welcome, { props: baseProps });

        const toggle = screen.getByTestId("guest-nav-toggle");
        expect(toggle).toHaveAttribute("aria-expanded", "false");
        await fireEvent.click(toggle);
        expect(toggle).toHaveAttribute("aria-expanded", "true");
        const panel = screen.getByTestId("guest-nav-panel");
        expect(within(panel).getByRole("link", { name: "ログイン" })).toBeInTheDocument();
    });

    it("Escape でモバイルパネルが閉じ、トグルにフォーカスが戻る", async () => {
        render(Welcome, { props: baseProps });

        const toggle = screen.getByTestId("guest-nav-toggle");
        await fireEvent.click(toggle);
        await fireEvent.keyDown(window, { key: "Escape" });
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
        // element bindable によるフォーカス復帰を回帰固定
        expect(toggle).toHaveFocus();
    });

    it("パネル内リンク押下でパネルが閉じる", async () => {
        render(Welcome, { props: baseProps });

        await fireEvent.click(screen.getByTestId("guest-nav-toggle"));
        const panel = screen.getByTestId("guest-nav-panel");
        await fireEvent.click(within(panel).getByRole("link", { name: "料金プラン" }));
        expect(screen.queryByTestId("guest-nav-panel")).not.toBeInTheDocument();
    });

    it("フッターに法的リンク3件 (利用規約→プライバシー→特商法) を href と順序どおり出す", () => {
        render(Welcome, { props: baseProps });

        // <footer> は contentinfo landmark。nav 側リンクと混ざらないよう footer に限定する。
        const footer = screen.getByRole("contentinfo");

        // (a) 法的3リンクを名前で個別取得し href を契約化。
        //     commerce は本バグ F-2-01 の主対象。terms/privacy も欠落検知のため個別検証する。
        expect(within(footer).getByRole("link", { name: "利用規約" })).toHaveAttribute(
            "href",
            "/terms",
        );
        expect(
            within(footer).getByRole("link", { name: "プライバシーポリシー" }),
        ).toHaveAttribute("href", "/privacy");
        expect(
            within(footer).getByRole("link", { name: "特定商取引法に基づく表記" }),
        ).toHaveAttribute("href", "/commerce-disclosure");

        // (b) 法的リンクだけを DOM 順で抽出し表示順を固定 (非法的リンクは filter で除外済み
        //     なので、料金プラン/お問い合わせ等の増減では壊れない = ノイズ耐性あり)。
        const legalHrefs = within(footer)
            .getAllByRole("link")
            .map((a) => a.getAttribute("href"))
            .filter((href) =>
                ["/terms", "/privacy", "/commerce-disclosure"].includes(href ?? ""),
            );
        expect(legalHrefs).toEqual(["/terms", "/privacy", "/commerce-disclosure"]);
    });
});
