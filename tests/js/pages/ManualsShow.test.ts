import { describe, expect, it } from "vitest";
import { fireEvent, render, screen, waitFor } from "@testing-library/svelte";
import Show from "@/pages/Manuals/Show.svelte";
import type { VideoManualStatus } from "@/types/manual";

const baseProps = {
    project: { id: 1, name: "サンプルプロジェクト" },
    manual: {
        id: 5,
        title: "ネジ締め作業",
        status: "draft" as VideoManualStatus,
        category: { id: 2, name: "仕上げ" },
        created_at: "2026-07-10 12:00",
    },
    analysis: { job: null, hasDocument: false },
    render: { job: null, previewJob: null, playbackJobId: null },
    canManage: true,
    categories: [
        { id: 1, name: "準備作業" },
        { id: 2, name: "仕上げ" },
    ],
};

describe("Manuals/Show", () => {
    it("タイトル・状態バッジ・カテゴリ・作成日時を描画する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("manual-title")).toHaveTextContent("ネジ締め作業");
        expect(screen.getByTestId("manual-status")).toHaveTextContent("下書き");
        expect(screen.getByTestId("manual-category")).toHaveTextContent("仕上げ");
        expect(screen.getByText("2026-07-10 12:00")).toBeInTheDocument();
    });

    it("未分類 (category=null) は「未分類」を表示する", () => {
        render(Show, {
            props: { ...baseProps, manual: { ...baseProps.manual, category: null } },
        });

        expect(screen.getByTestId("manual-category")).toHaveTextContent("未分類");
    });

    it("canManage=true なら複製・編集・削除導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("duplicate-manual-button")).toBeInTheDocument();
        expect(screen.getByTestId("edit-manual-button").getAttribute("href")).toMatch(
            /\/projects\/1\/manuals\/5\/edit$/,
        );
        expect(screen.getByTestId("delete-manual-button")).toBeInTheDocument();
    });

    it("canManage=false なら複製・編集・削除導線を表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("duplicate-manual-button")).toBeNull();
        expect(screen.queryByTestId("edit-manual-button")).toBeNull();
        expect(screen.queryByTestId("delete-manual-button")).toBeNull();
    });

    it("複製ボタン押下でダイアログが開き、タイトルは『{元タイトル} のコピー』・カテゴリは元 category をプリフィルする", async () => {
        render(Show, { props: baseProps });

        await fireEvent.click(screen.getByTestId("duplicate-manual-button"));

        await waitFor(() => {
            expect(screen.getByTestId("duplicate-manual-dialog")).toBeInTheDocument();
        });
        const title = screen.getByLabelText(/タイトル/) as HTMLInputElement;
        expect(title.value).toBe("ネジ締め作業 のコピー");
        const category = screen.getByTestId("duplicate-category-select") as HTMLSelectElement;
        expect(category.value).toBe("2");
        // 送信ボタンは必須未充足でも disabled にしない (禁止事項8)
        expect(screen.getByTestId("duplicate-manual-confirm")).not.toBeDisabled();
    });

    it("canManage=true (draft) は AI 解析ボタンと手順書アップロード導線を表示する", () => {
        render(Show, { props: baseProps });

        expect(screen.getByTestId("analyze-button")).toBeInTheDocument();
        expect(screen.getByTestId("source-document-upload")).toBeInTheDocument();
    });

    it("canManage=false は解析ボタン・アップロード導線を表示しない", () => {
        render(Show, { props: { ...baseProps, canManage: false } });

        expect(screen.queryByTestId("analyze-button")).toBeNull();
        expect(screen.queryByTestId("source-document-upload")).toBeNull();
    });

    it("ready 状態では撮影ナビへの導線を表示し href が撮影ナビを厳密に指す", () => {
        render(Show, {
            props: { ...baseProps, manual: { ...baseProps.manual, status: "ready" } },
        });

        // Inertia Link は jsdom で origin 付き絶対 URL に解決される。
        // path 全体を start/end 固定で照合し prefix / suffix / クエリ変化を検知する。
        expect(screen.getByTestId("capture-manual-link").getAttribute("href")).toMatch(
            /^https?:\/\/[^/]+\/app\/projects\/1\/manuals\/5$/,
        );
    });

    it("published 状態でも撮影導線を表示する", () => {
        render(Show, {
            props: { ...baseProps, manual: { ...baseProps.manual, status: "published" } },
        });

        expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
    });

    it("ready 状態なら canManage=false (撮影者) でも撮影導線を表示する", () => {
        render(Show, {
            props: {
                ...baseProps,
                canManage: false,
                manual: { ...baseProps.manual, status: "ready" },
            },
        });

        expect(screen.getByTestId("capture-manual-link")).toBeInTheDocument();
    });

    it("draft 状態では撮影導線を表示しない", () => {
        render(Show, { props: baseProps });

        expect(screen.queryByTestId("capture-manual-link")).toBeNull();
    });

    it("analyzing 中は進捗を表示し、アップロード導線は出さない (draft/ready のみ)", () => {
        render(Show, {
            props: {
                ...baseProps,
                manual: { ...baseProps.manual, status: "analyzing" as VideoManualStatus },
                analysis: {
                    job: {
                        id: 9,
                        status: "running" as const,
                        step: "extract" as const,
                        progress: 10,
                        error: null,
                        manual_status: "analyzing" as VideoManualStatus,
                    },
                    hasDocument: true,
                },
            },
        });

        expect(screen.queryByTestId("source-document-upload")).toBeNull();
        expect(screen.getByTestId("analysis-progress")).toBeInTheDocument();
    });
});
