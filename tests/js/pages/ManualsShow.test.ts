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
    render: {
        job: null,
        previewJob: null,
        playbackJob: null,
        finishedJob: null,
        coverage: { total_cuts: 1, missing_count: 0, missing_labels: [] },
    },
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

    // --- T148 (bug-hunt F-1-01): render props の配線 ---

    it("D-9: render.coverage と render.playbackJob が RenderPanel へ渡る", () => {
        render(Show, {
            props: {
                ...baseProps,
                manual: { ...baseProps.manual, status: "ready" as VideoManualStatus },
                render: {
                    job: null,
                    previewJob: null,
                    playbackJob: {
                        id: 33,
                        kind: "preview" as const,
                        status: "succeeded" as const,
                        step: null,
                        progress: 100,
                        error: null,
                        error_code: null,
                        manual_status: "ready" as VideoManualStatus,
                        placeholder_cut_count: 2,
                    },
                    finishedJob: null,
                    coverage: { total_cuts: 3, missing_count: 2, missing_labels: ["手順2", "手順3"] },
                },
            },
        });

        // coverage は事前告知へ、playbackJob は動画 URL と事後説明へ流れる
        expect(screen.getByTestId("preview-coverage-note")).toHaveTextContent("手順2、手順3");
        expect(screen.getByTestId("preview-video")).toHaveAttribute(
            "src",
            "/projects/1/manuals/5/render-jobs/33/playback",
        );
        expect(screen.getByTestId("preview-placeholder-note")).toHaveTextContent("2");
    });

    // --- T154: 完成動画の props 配線 ---

    it("D-10: render.finishedJob が RenderPanel へそのまま渡る", () => {
        render(Show, {
            props: {
                ...baseProps,
                manual: { ...baseProps.manual, status: "published" as VideoManualStatus },
                render: {
                    ...baseProps.render,
                    finishedJob: {
                        id: 44,
                        kind: "render" as const,
                        status: "succeeded" as const,
                        step: null,
                        progress: 100,
                        error: null,
                        error_code: null,
                        manual_status: "published" as VideoManualStatus,
                        placeholder_cut_count: 0,
                    },
                },
            },
        });

        expect(screen.getByTestId("final-video")).toHaveAttribute(
            "src",
            "/projects/1/manuals/5/render-jobs/44/playback",
        );
        expect(screen.getByTestId("download-button")).toBeInTheDocument();
    });
});
